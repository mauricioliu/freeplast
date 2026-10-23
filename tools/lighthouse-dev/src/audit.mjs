import http from 'node:http';
import { spawn } from 'node:child_process';
import { rm, mkdir, writeFile, readFile } from 'node:fs/promises';
import { join } from 'node:path';
import puppeteer from 'puppeteer-core';
import lighthouse, { generateReport } from 'lighthouse';
import { lighthouseConfig, timezone, profiles, auditConfig } from './config.mjs';
import { installGuard } from './guard.mjs';
import { pngSize, captureConsistency, captureRequest, observeExpression } from './capture.mjs';
import { validateLhr, sanitize, summarize } from './report.mjs';
import { publicIdentity, sha256 } from './preflight.mjs';
import { SafetyError } from './policy.mjs';
import { filteringProxy } from './proxy.mjs';
import { failureEvidence } from './failure.mjs';

export async function audit({ policy, address, url, profile: name, pins, profileDir: profile, capture: captureWanted = false }, signal) {
  if (!profile) throw new SafetyError('owned-profile-required');
  let browser, processHandle, guard, exit, proxy, auditFailure, page;
  try {
    if (sha256(await readFile(pins.browser.path)) !== pins.browser.sha256) throw new SafetyError('browser-hash');
    proxy = await filteringProxy(policy, address);
    await mkdir(join(profile, 'Default'), { mode: 0o700 });
    await writeFile(join(profile, 'Default/Preferences'), JSON.stringify({ net: { network_prediction_options: 2 }, signin: { allowed: false }, default_search_provider: { enabled: false } }), { mode: 0o600, flag: 'wx' });
    const mapping = [`MAP ${new URL(policy.origin).hostname} ${address}`, ...Object.entries(policy.hosts).map(([host, ip]) => `MAP ${host} ${ip}`), 'MAP * ~NOTFOUND', 'EXCLUDE 127.0.0.1'];
    const args = await puppeteer.defaultArgs({ headless: true, userDataDir: profile, args: [
      '--remote-debugging-port=0', '--remote-debugging-address=127.0.0.1', `--proxy-server=${proxy.url}`, '--proxy-bypass-list=<-loopback>', '--lang=es',
      `--host-resolver-rules=${mapping.join(', ')}`, '--disable-quic', '--disable-extensions',
      '--force-webrtc-ip-handling-policy=disable_non_proxied_udp',
      `--disable-features=${auditConfig.disabledFeatures.join(',')}`, '--disable-component-update', '--disable-domain-reliability',
    ] });
    if (args.some(x => /no-sandbox|disable-setuid-sandbox/.test(x))) throw new SafetyError('sandbox-disabled');
    processHandle = spawn(pins.browser.path, args, {
      detached: false, stdio: ['ignore', 'ignore', 'pipe'],
      env: { PATH: '/usr/bin:/bin', HOME: profile, TMPDIR: profile, LANG: 'es_CL.UTF-8', TZ: timezone },
    });
    exit = new Promise(resolve => processHandle.once('exit', resolve));
    const endpoint = await new Promise((resolve, reject) => {
      let buffer = '';
      const timer = setTimeout(() => reject(new SafetyError('browser-launch-timeout')), 15000);
      const abort = () => { clearTimeout(timer); reject(new SafetyError('interrupted')); };
      signal?.addEventListener('abort', abort, { once: true });
      processHandle.once('error', () => { clearTimeout(timer); reject(new SafetyError('browser-launch')); });
      processHandle.once('exit', () => { clearTimeout(timer); reject(new SafetyError('browser-exited')); });
      processHandle.stderr.on('data', chunk => {
        buffer = (buffer + chunk.toString()).slice(-4096);
        const match = buffer.match(/DevTools listening on (ws:\/\/127\.0\.0\.1:\d+\/devtools\/browser\/[\w-]+)/);
        if (match) { clearTimeout(timer); signal?.removeEventListener('abort', abort); resolve(match[1]); }
      });
    });
    browser = await puppeteer.connect({ browserWSEndpoint: endpoint });
    if (!(await browser.version()).endsWith('/' + pins.browser.version)) throw new SafetyError('browser-version');
    const launchSession = await browser.target().createCDPSession();
    const observedArgs = (await launchSession.send('Browser.getBrowserCommandLine')).arguments;
    await launchSession.detach();
    const featureArgs = args.filter(arg => arg.startsWith('--disable-features='));
    if (featureArgs.length !== 1 || !auditConfig.disabledFeatures.every(feature => featureArgs[0].slice('--disable-features='.length).split(',').includes(feature))
      || JSON.stringify(observedArgs.filter(arg => arg.startsWith('--disable-features='))) !== JSON.stringify(featureArgs)
      || !observedArgs.includes(`--user-data-dir=${profile}`)) throw new SafetyError('browser-launch-config');
    const launchProof = { generation: auditConfig.generation, disabledFeatures: featureArgs[0].slice('--disable-features='.length).split(','), ownedProfile: true };
    [page] = await browser.pages();
    if (!page || page.url() !== 'about:blank') throw new SafetyError('browser-not-empty');
    let probe;
    guard = await installGuard(browser, page, policy, () => {
      proxy.arm();
      if (policy.fixture && policy.fixtureProxyProbe && !probe) {
        probe = (async () => { for (const path of policy.fixtureProxyProbe) await new Promise((resolve, reject) => http.get(proxy.url, { path }, response => { response.resume(); response.once('end', () => response.statusCode === 403 ? resolve() : reject(new SafetyError('fixture-probe-status'))); }).on('error', () => reject(new SafetyError('fixture-probe-failed')))); })();
        probe.catch(() => {});
      }
    }, proxy);
    await page.emulateTimezone(timezone);
    await page.setExtraHTTPHeaders({ 'Accept-Language': 'es', ...(policy.fixture && policy.fixtureToken ? { 'x-freeplast-fixture-run': policy.fixtureToken } : {}) });
    // Proxy remains offline until the guard authorizes the first document request.
    const aborted = new Promise((_, reject) => {
      if (signal?.aborted) reject(new SafetyError('interrupted'));
      else signal?.addEventListener('abort', () => reject(new SafetyError('interrupted')), { once: true });
    });
    const result = await Promise.race([lighthouse(url, { logLevel: 'silent' }, lighthouseConfig(name), page), guard.failed, proxy.failed, aborted]);
    if (probe) await probe;
    const security = await guard.verify();
    publicIdentity(await page.content(), { 'cache-control': 'no-store' });
    validateLhr(result?.lhr, policy);
    const lhr = sanitize(result.lhr);
    validateLhr(lhr, policy);
    const html = generateReport(lhr, 'html');
    if (typeof html !== 'string' || !html.includes('<html')) throw new SafetyError('html-report');
    // Private capture from the already-owned audit visit: no extra navigation,
    // request or interaction. Raw pixels go only to a separate private sidecar;
    // they are never embedded in the sanitized LHR/HTML. The capture context is
    // RE-ASSERTED after Lighthouse: native probes observed mismatched default
    // capture surfaces. Their teardown timing was not established as the cause.
    const fixtureState = policy.fixture ? await page.evaluate(() => ({ bootstrapLoaded: document.body.dataset.fpFixtureLoaded === 'yes', nativeEmptyState: document.body.dataset.fpFixtureNativeState === 'true', externalScripts: document.querySelectorAll('script[src]').length, persistedPageShows: window.fpFixturePersistedShows ?? null })) : undefined;
    let capture;
    if (captureWanted) {
      const { width, height, deviceScaleFactor } = profiles[name].screenEmulation;
      const mobile = profiles[name].formFactor === 'mobile';
      const cdp = await page.createCDPSession();
      try {
        await cdp.send('Network.setUserAgentOverride', { userAgent: profiles[name].emulatedUserAgent });
        await cdp.send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor, mobile });
        await Promise.race([page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))), new Promise((_, reject) => setTimeout(() => reject(new SafetyError('capture-viewport')), 5000))]);
        let captureTimer;
        const captureTimeout = new Promise((_, reject) => { captureTimer = setTimeout(() => reject(new SafetyError('capture-timeout')), 10000); });
        // Observation AND capture run on this same override-owning session: puppeteer's
        // primary-session capture ignores the override (native probe 2: configured page
        // state confirmed, surface still the default 800x600 window).
        const observed = () => Promise.race([cdp.send('Runtime.evaluate', { expression: observeExpression, returnByValue: true }).then(r => r?.result?.value), aborted, new Promise((_, reject) => setTimeout(() => reject(new SafetyError('capture-viewport')), 5000))]);
        let data, before, after;
        try {
          before = await observed();
          const shot = await Promise.race([cdp.send('Page.captureScreenshot', captureRequest({ width, height, deviceScaleFactor })), aborted, captureTimeout]);
          after = await observed();
          data = shot?.data;
        } finally { clearTimeout(captureTimer); }
        const bytes = Buffer.from(typeof data === 'string' ? data : '', 'base64');
        if (!data || bytes.length < 8 || bytes.subarray(0, 8).toString('hex') !== '89504e470d0a1a0a') throw new SafetyError('capture-failed');
        const pixels = pngSize(bytes);
        if (![before, after].every(s => s?.userAgent === profiles[name].emulatedUserAgent)) {
          const e = new SafetyError('capture-profile'); e.diagnostic = { pixels, observed: [before, after], expectedUserAgent: profiles[name].emulatedUserAgent }; throw e;
        }
        try { captureConsistency({ pixels, observed: [before, after], geometry: { width, height, deviceScaleFactor } }); } catch (e) { e.diagnostic = { pixels, observed: [before, after], geometry: { width, height, deviceScaleFactor } }; throw e; }
        capture = { png: bytes.toString('base64'), format: 'png', source: 'owned-audit-visit', method: 'same-session-clip-page-origin', at: new Date().toISOString(), geometry: { width, height, deviceScaleFactor }, clip: captureRequest({ width, height, deviceScaleFactor }).clip, observed: [before, after], pixels };
      } finally { await cdp.detach().catch(() => {}); }
    }
    guard.beginShutdown();
    let closeTimer;
    try { await Promise.race([browser.close(), new Promise((_, reject) => { closeTimer = setTimeout(() => reject(new SafetyError('browser-close-timeout')), 2000); })]); }
    finally { clearTimeout(closeTimer); }
    await exit;
    browser = undefined;
    if (processHandle.exitCode !== 0 || signal?.aborted) throw new SafetyError('browser-shutdown');
    await proxy.close();
    security.rendererProof = guard.assertHealthy();
    proxy.revokeAfterExit(processHandle, security.resources);
    security.proxy = proxy.verify(security.rendererProof);
    proxy.assertPrivate(JSON.stringify(lhr), html);
    security.guardedThroughShutdown = true;
    return { lhr, html, ...(fixtureState ? { fixtureState } : {}), ...(capture ? { capture } : {}), summary: summarize(lhr), security, settings: lhr.configSettings, launch: { observed: launchProof, args: args.map(x => x.startsWith('--user-data-dir=') ? '--user-data-dir=<private-new-profile>' : x.startsWith('--proxy-server=') ? '--proxy-server=<owned-ephemeral-loopback>' : x), sandbox: true, cache: 'new-profile; browser-cold; server-unchanged' } };
  } catch (error) {
    auditFailure = error instanceof SafetyError ? error : new SafetyError('audit-failed');
    if (policy.fixture && page) {
      try {
        const fixtureState = await page.evaluate(() => ({ bootstrapLoaded: document.body.dataset.fpFixtureLoaded === 'yes', cartStorageEvents: window.fpFixtureCartStorageEvents ?? null, seedPresentBeforeNative: window.fpFixtureSeedPresent ?? null, persistedPageShows: window.fpFixturePersistedShows ?? null }));
        auditFailure.diagnostic = { ...auditFailure.diagnostic, fixtureState };
      } catch { /* Failed fixture observation remains unavailable, never inferred. */ }
    }
    throw auditFailure;
  } finally {
    const cleanup = { browserExitObserved: false, profileRemoved: false, proxyClosed: false, guardClosed: false };
    guard?.beginShutdown();
    try {
      if (browser) await Promise.race([browser.close().catch(() => {}), new Promise(resolve => setTimeout(resolve, 2000))]);
      if (processHandle && processHandle.exitCode === null && processHandle.signalCode === null) processHandle.kill('SIGKILL');
      if (exit) await exit;
      cleanup.browserExitObserved = Boolean(processHandle && (processHandle.exitCode !== null || processHandle.signalCode !== null));
    } catch { auditFailure ||= new SafetyError('cleanup-failed'); }
    try { await proxy?.close(); cleanup.proxyClosed = Boolean(proxy); } catch { auditFailure ||= new SafetyError('cleanup-failed'); }
    const guardState = guard?.snapshot();
    try { guard?.close(); cleanup.guardClosed = Boolean(guard); } catch { auditFailure ||= new SafetyError('cleanup-failed'); }
    try { await rm(profile, { recursive: true, force: true }); cleanup.profileRemoved = true; } catch { auditFailure ||= new SafetyError('cleanup-failed'); }
    if (auditFailure) {
      auditFailure.evidence = failureEvidence({ diagnostic: auditFailure.diagnostic, proxy: proxy?.snapshot(), guard: guardState, cleanup });
      throw auditFailure;
    }
  }
}
