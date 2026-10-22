import { spawn } from 'node:child_process';
import { rm, mkdir, writeFile, readFile } from 'node:fs/promises';
import { join } from 'node:path';
import puppeteer from 'puppeteer-core';
import lighthouse, { generateReport } from 'lighthouse';
import { lighthouseConfig, timezone } from './config.mjs';
import { installGuard } from './guard.mjs';
import { validateLhr, sanitize, summarize } from './report.mjs';
import { publicIdentity, sha256 } from './preflight.mjs';
import { SafetyError } from './policy.mjs';
import { filteringProxy } from './proxy.mjs';

export async function audit({ policy, address, url, profile: name, pins, profileDir: profile }, signal) {
  if (!profile) throw new SafetyError('owned-profile-required');
  let browser, processHandle, guard, exit, proxy;
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
      '--disable-features=NetworkTimeServiceQuerying,AutofillServerCommunication,kPreconnectToSearch,kSearchEnginePreconnect2', '--disable-component-update', '--disable-domain-reliability',
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
    const [page] = await browser.pages();
    if (!page || page.url() !== 'about:blank') throw new SafetyError('browser-not-empty');
    guard = await installGuard(browser, page, policy, () => proxy.arm());
    await page.emulateTimezone(timezone);
    await page.setExtraHTTPHeaders({ 'Accept-Language': 'es', ...(policy.fixture && policy.fixtureToken ? { 'x-freeplast-fixture-run': policy.fixtureToken } : {}) });
    // Proxy remains offline until the guard authorizes the first document request.
    const aborted = new Promise((_, reject) => {
      if (signal?.aborted) reject(new SafetyError('interrupted'));
      else signal?.addEventListener('abort', () => reject(new SafetyError('interrupted')), { once: true });
    });
    const result = await Promise.race([lighthouse(url, { logLevel: 'silent' }, lighthouseConfig(name), page), guard.failed, proxy.failed, aborted]);
    const security = await guard.verify();
    publicIdentity(await page.content(), { 'cache-control': 'no-store' });
    validateLhr(result?.lhr, policy);
    const lhr = sanitize(result.lhr);
    validateLhr(lhr, policy);
    const html = generateReport(lhr, 'html');
    if (typeof html !== 'string' || !html.includes('<html')) throw new SafetyError('html-report');
    guard.beginShutdown();
    let closeTimer;
    try { await Promise.race([browser.close(), new Promise((_, reject) => { closeTimer = setTimeout(() => reject(new SafetyError('browser-close-timeout')), 2000); })]); }
    finally { clearTimeout(closeTimer); }
    await exit;
    browser = undefined;
    if (processHandle.exitCode !== 0 || signal?.aborted) throw new SafetyError('browser-shutdown');
    security.rendererProof = guard.assertHealthy();
    security.proxy = proxy.verify(security.rendererProof);
    security.guardedThroughShutdown = true;
    return { lhr, html, summary: summarize(lhr), security, settings: lhr.configSettings, launch: { args: args.map(x => x.startsWith('--user-data-dir=') ? '--user-data-dir=<private-new-profile>' : x.startsWith('--proxy-server=') ? '--proxy-server=<owned-ephemeral-loopback>' : x), sandbox: true, cache: 'new-profile; browser-cold; server-unchanged' } };
  } finally {
    guard?.close();
    if (browser) await Promise.race([browser.close().catch(() => {}), new Promise(resolve => setTimeout(resolve, 2000))]);
    if (processHandle && processHandle.exitCode === null && processHandle.signalCode === null) processHandle.kill('SIGKILL');
    if (exit) await exit;
    await proxy?.close();
    await rm(profile, { recursive: true, force: true });
  }
}
