import assert from 'node:assert/strict';
import { mkdtemp, readFile, readlink, lstat, readdir } from 'node:fs/promises';
import { networkInterfaces } from 'node:os';
import { join } from 'node:path';
import { execFileSync } from 'node:child_process';
import { generateReport } from 'lighthouse';
import { validateLhr } from '../src/report.mjs';
import { runWorker } from '../src/lifecycle.mjs';
import { preflight, sha256 } from '../src/preflight.mjs';
import { stateRoot, privateDirectory, save, toolchain, lock } from '../src/store.mjs';
import { instrumentHash, securityOracleHash } from '../src/measure.mjs';
import { plan } from '../src/budget.mjs';
import { measureMode, measureUrl } from '../src/policy.mjs';
import { pngSize, captureConsistency } from '../src/capture.mjs';
import { fixtureAdapter, fixtureHtml } from './fixture-adapter.mjs';
import { assertHttpsDenial } from './security-oracle.mjs';
import { assetVectors, expectedStops, staticRequests } from './security-vectors.mjs';
import { profiles } from '../src/config.mjs';
import { recordFailure } from '../src/failure.mjs';
import { proxyUrlVectors } from './proxy-vectors.mjs';
import { wooBootstrapFixture, busyWooFixture } from './woo-bootstrap-fixture.mjs';

process.umask(0o077);
assert.equal(process.argv.length, 2);
assert.equal(process.getuid(), 1000); assert.equal(process.getgid(), 1000);
assert.deepEqual(Object.keys(networkInterfaces()), ['lo']);
const processStatus = await readFile('/proc/self/status', 'utf8');
for (const key of ['CapInh', 'CapPrm', 'CapEff', 'CapBnd', 'CapAmb']) assert.match(processStatus, new RegExp(`^${key}:\\s+0000000000000000$`, 'm'));
assert.match(processStatus, /^NoNewPrivs:\s+1$/m);
const root = await privateDirectory(join(stateRoot, 'plan-mvl2p6uk-wka1-evidence'));
const evidence = await mkdtemp(join(root, 'security-'));
const pins = await toolchain();
const fd = await lock();
const context = { planId: plan.id, planHash: plan.hash, oracleHash: await securityOracleHash(), uid: process.getuid(), gid: process.getgid(), netNamespace: await readlink('/proc/self/ns/net'), userNamespace: await readlink('/proc/self/ns/user'), uidMap: (await readFile('/proc/self/uid_map', 'utf8')).trim(), instrument: await instrumentHash(), fixtureSource: sha256(await readFile(new URL(import.meta.url))), startedAt: new Date().toISOString() };
await save(root, join(evidence, 'context.json'), context);
const cases = [
  ['302', ({ deniedOrigin }) => ({ status: 302, headers: { Location: deniedOrigin + '/' } })],
  ['307', ({ deniedOrigin }) => ({ status: 307, headers: { Location: deniedOrigin + '/' } })],
  ['meta', ({ deniedOrigin }) => ({ body: fixtureHtml(`<meta http-equiv="refresh" content="0;url=${deniedOrigin}/">`) })],
  ['javascript', ({ deniedOrigin }) => ({ body: fixtureHtml(`<script>location.href=${JSON.stringify(deniedOrigin + '/')}</script>`) })],
  ['late-javascript', ({ deniedOrigin }) => ({ body: fixtureHtml(`<script>setTimeout(()=>location.href=${JSON.stringify(deniedOrigin + '/')},2500)</script>`) })],
  ['popup', ({ deniedOrigin }) => ({ body: fixtureHtml(`<script>window.open(${JSON.stringify(deniedOrigin + '/')})</script>`) })],
  ['frame', ({ deniedOrigin }) => ({ body: fixtureHtml(`<iframe title="Denied" src="${deniedOrigin}/"></iframe>`) })],
  ['image', ({ deniedOrigin }) => ({ body: fixtureHtml(`<img alt="Denied" src="${deniedOrigin}/asset.png">`) })],
  ['woo-bootstrap-cold', wooBootstrapFixture()],
  ['woo-prepared', wooBootstrapFixture(true)],
  ['woo-prepared-desktop', wooBootstrapFixture(true)],
  ['woo-prepared-no-capture', busyWooFixture()],
  ['woo-prepared-no-capture-desktop', busyWooFixture()],
  ['woo-prepared-proxy-probe', wooBootstrapFixture(true)],
  ['woo-prepared-proxy-probe-desktop', wooBootstrapFixture(true)],
  ['POST', () => ({ body: fixtureHtml('<script>fetch("/",{method:"POST",body:"synthetic"})</script>') })],
  ['commercial-GET', () => ({ body: fixtureHtml('<script>fetch("/?add-to-cart=1")</script>') })],
  ['websocket', ({ deniedOrigin }) => ({ body: fixtureHtml(`<script>new WebSocket(${JSON.stringify(deniedOrigin.replace('http:', 'ws:') + '/')})</script>`) })],
  ['wss', ({ deniedTLSOrigin }) => ({ body: fixtureHtml(`<script>new WebSocket(${JSON.stringify(deniedTLSOrigin.replace('https:', 'wss:') + '/')})</script>`) })],
  ['https-fetch', ({ deniedTLSOrigin }) => ({ body: fixtureHtml(`<script>fetch(${JSON.stringify(deniedTLSOrigin + '/')})</script>`) })],
  ['https-image', ({ deniedTLSOrigin }) => ({ body: fixtureHtml(`<img alt="TLS" src="${deniedTLSOrigin}/asset.png">`) })],
  ['accounts-fetch', () => ({ body: fixtureHtml('<script>fetch("https://accounts.google.com/fixture")</script>') })],
  ['accounts-image', () => ({ body: fixtureHtml('<img alt="Same host, renderer resource" src="https://accounts.google.com/fixture.png">') })],
  ['accounts-wss', () => ({ body: fixtureHtml('<script>new WebSocket("wss://accounts.google.com/fixture")</script>') })],
  ['frame-https', ({ req, deniedTLSOrigin }) => ({ body: fixtureHtml(req.url === '/tienda/' + measureMode.query ? `<script>fetch(${JSON.stringify(deniedTLSOrigin + '/')})</script>` : `<iframe title="Guarded same-origin child" src="${measureUrl('', '/tienda/')}"></iframe>`) })],
  ['worker', () => ({ body: fixtureHtml('<script>new Worker("/worker.js")</script>') })],
  // Shipped same-origin static sources with commercial names must load (GET, ver-only);
  // uploads/root-level lookalikes and mutator queries on exempt assets must not.
  ['static-source-assets', ({ req }) => staticRequests.includes(req.url) ? { headers: { 'Content-Type': 'application/javascript' }, body: '/* synthetic static resource */' } : { body: fixtureHtml(staticRequests.map(path => `<script src="${path}"></script>`).join('')) }],
  ...assetVectors.map(v => [v.name, () => ({ body: fixtureHtml(v.type === 'Image' ? `<img alt="Denied" src="${v.path}">` : `<script src="${v.path}"></script>`) })]),
  ['clean', () => ({ body: fixtureHtml('<p>Only public synthetic content.</p>') })],
  ['clean-desktop', () => ({ body: fixtureHtml('<p>Only public synthetic content.</p>') })],
];
const cleanCases = new Set(['clean', 'clean-desktop', 'static-source-assets', 'woo-prepared', 'woo-prepared-desktop', 'woo-prepared-proxy-probe', 'woo-prepared-proxy-probe-desktop', 'woo-prepared-no-capture', 'woo-prepared-no-capture-desktop']);
const positive = name => name.startsWith('clean') || name.startsWith('woo-prepared');
const results = [];
try {
  const selected = process.env.LH_FIXTURE_CASE ? cases.filter(([name]) => process.env.LH_FIXTURE_CASE.split(',').includes(name)) : [...cases.filter(([name]) => positive(name)), ...cases.filter(([name]) => !positive(name))];
  assert.ok(selected.length, 'Unknown synthetic fixture case');
  for (const [name, render] of selected) {
    const fixture = await fixtureAdapter(render);
    if (name.includes('proxy-probe')) fixture.policy.fixtureProxyProbe = proxyUrlVectors.filter(v => v.name === 'origin-form').map(v => v.raw);
    const row = { name, status: 'incomplete' };
    results.push(row);
    try {
      if (['302', '307'].includes(name)) {
        await assert.rejects(preflight(fixture.policy, '/', '127.0.0.1', undefined, { mode: true }), /forbidden-host/);
        assert.equal(fixture.denied.length, 0);
      }
      let result, error;
      try { result = await runWorker({ policy: fixture.policy, address: '127.0.0.1', url: measureUrl(fixture.origin, '/'), profile: name.endsWith('-desktop') ? 'desktop' : 'mobile', pins, capture: positive(name) && !name.includes('no-capture') }, fd.fd, { timeout: 30000 }); }
      catch (e) {
        error = e.code || 'unclassified'; row.diagnostic = e.diagnostic || null;
        const failedRun = { status: 'incomplete', failure: error, audits: [{ status: 'incomplete' }] };
        recordFailure(failedRun, e);
        await save(root, join(evidence, `${name}-failed-manifest.json`), failedRun);
        const persisted = JSON.parse(await readFile(join(evidence, `${name}-failed-manifest.json`), 'utf8'));
        assert.deepEqual(persisted.audits[0].failureEvidence, failedRun.failureEvidence);
        row.failureEvidence = persisted.failureEvidence;
        if (name === 'POST' || name === 'woo-bootstrap-cold') {
          assert.equal(row.failureEvidence.diagnostic.method, 'POST');
          assert.equal(row.failureEvidence.diagnostic.continuationAttempted, false);
          assert.equal(row.failureEvidence.diagnostic.failRequestAcknowledged, true);
          assert.equal(row.failureEvidence.proxy.nonReadOnlyUpstreamRequests, 0);
          assert.equal(row.failureEvidence.proxy.activeRequests, 0);
          assert.equal(row.failureEvidence.cleanup.browserExitObserved, true);
          assert.equal(row.failureEvidence.cleanup.proxyClosed, true);
          assert.equal(row.failureEvidence.worker.exitObserved, true);
          assert.equal(row.failureEvidence.worker.processGroupAbsent, true);
          assert.equal(row.failureEvidence.worker.profileRemoved, true);
          if (name === 'woo-bootstrap-cold') {
            assert.equal(row.failureEvidence.diagnostic.target.action, 'get_refreshed_fragments');
            assert.equal(row.failureEvidence.diagnostic.target.path, '/');
            assert.ok(fixture.hits.some(h => h.path === '/wp-content/plugins/woocommerce/assets/js/frontend/cart-fragments.min.js'));
          }
        }
      }
      row.error = error || null;
      row.deniedRequests = fixture.denied;
      row.deniedConnections = fixture.deniedConnections;
      row.deniedTLSConnections = fixture.deniedTLSConnections;
      row.runMarker = fixture.runMarker;
      row.tlsContacts = fixture.tlsContacts;
      row.deniedContacts = fixture.deniedContacts;
      row.requests = fixture.hits;
      assert.equal(fixture.deniedTLSConnections, 0, `${name}: forbidden TLS TCP contact escaped`);
      assert.equal(fixture.deniedConnections, 0, `${name}: forbidden TCP contact escaped`);
      assert.ok(fixture.hits.some(x => x.path === '/' + measureMode.query && x.markerMatches), `${name}: audited fixture was never reached`);
      assert.equal(fixture.denied.length, 0, `${name}: forbidden first request escaped`);
      assert.equal(fixture.hits.filter(x => x.method !== 'GET' || /[?&]add-to-cart=/.test(x.path)).length, 0, `${name}: mutation escaped`);
      if (name === 'static-source-assets')
        assert.ok(staticRequests.every(path => fixture.hits.some(x => x.path === path)), 'shipped static sources were not fetched');
      if (cleanCases.has(name)) {
        assert.ok(result, `clean fixture failed: ${error}`);
        assert.ok(result.security.active);
        assert.ok(result.security.guardedThroughShutdown);
        assert.equal(result.security.rendererProof.allTargetsGuarded, true);
        assert.equal(result.security.rendererProof.httpsContinued, 0);
        assert.equal(result.security.rendererProof.websocketCreations, 0);
        assert.ok(result.security.rendererProof.httpContinued > 0);
        assert.ok(result.security.proxy.tunnelDenials.every(x => x.upstreamConnections === 0));
        validateLhr(result.lhr, fixture.policy);
        assert.equal(result.html, generateReport(result.lhr, 'html'));
        assert.equal(result.security.proxy.upstreamRequests, result.security.proxy.consumedAuthorizations + result.security.proxy.authenticatedAuxiliaryRequests);
        assert.equal(result.security.proxy.authorizationClaims, result.security.rendererProof.httpRequestsAuthorized);
        assert.equal(result.security.proxy.pendingAuthorizations, 0);
        assert.equal(result.security.proxy.authorizationClaims, result.security.proxy.consumedAuthorizations + result.security.proxy.cancelledAuthorizations);
        assert.ok(fixture.hits.every(hit => hit.controlHeaderSeen === false));
        if (name.includes('proxy-probe')) {
          assert.equal(result.security.proxy.unownedDenials, 1);
          const sample = result.security.proxy.unownedSamples[0];
          assert.equal(sample.urlShape.form, 'origin-form'); assert.equal(sample.urlShape.host, 'proxy');
          assert.equal(sample.target.origin, 'invalid'); assert.equal(sample.ownedRequest, false);
          row.ownership = result.security.proxy;
        }
        await save(root, join(evidence, `${name}.json`), result.lhr);
        await save(root, join(evidence, `${name}.html`), result.html);
        await save(root, join(evidence, `${name}-security.json`), result.security);
        const json = await readFile(join(evidence, `${name}.json`), 'utf8');
        const html = await readFile(join(evidence, `${name}.html`), 'utf8');
        const persisted = JSON.parse(json);
        validateLhr(persisted, fixture.policy);
        assert.equal(html, generateReport(persisted, 'html'), 'persisted JSON/HTML must match byte-for-byte');
        assert.equal(persisted.audits['third-party-cookies'].id, 'third-party-cookies');
        assert.ok(persisted.categories['best-practices'].auditRefs.some(ref => ref.id === 'third-party-cookies'));
        assert.equal(persisted.audits['is-crawlable'].score, 0);
        if (name.startsWith('woo-prepared')) {
          assert.deepEqual(result.fixtureState, { bootstrapLoaded: true, nativeEmptyState: true, externalScripts: 3, persistedPageShows: 0 });
          row.bootstrap = result.fixtureState;
          assert.equal(persisted.audits['robots-txt'].score, 1, 'original Lighthouse auxiliary fetch must load valid robots.txt');
          assert.equal(result.security.proxy.authenticatedAuxiliaryRequests, 1);
          assert.ok(fixture.hits.some(hit => hit.path === '/robots.txt'));
          assert.equal(persisted.audits['bf-cache'].score, 0, 'real BFCache lab limitation must remain in full report');
          assert.ok(persisted.audits['bf-cache'].details, 'BFCache failure details retained');
          assert.ok(result.launch.observed.disabledFeatures.includes('BackForwardCache'));
          assert.equal(result.launch.observed.ownedProfile, true);
          row.launch = result.launch;
          row.bfCache = persisted.audits['bf-cache'];
        }
        if (name.includes('no-capture')) {
          assert.equal(result.capture, undefined);
          assert.equal(result.security.lifecycle.captureWanted, false);
          assert.equal(result.security.lifecycle.handlersSettled, true);
          assert.equal(result.security.lifecycle.phase, 'owned-shutdown');
          for (let i = 0; i < 32; i++) assert.ok(result.security.resources.some(resource => resource.url === `${fixture.origin}/busy-${i}.svg` && resource.type === 'Image' && resource.status === 200));
          row.noCapture = { ...result.security.lifecycle, busyImages: 32, rendererProof: result.security.rendererProof };
        }
        if (positive(name) && !name.includes('no-capture')) {
          // Native capture path: sidecar PNG from the owned visit whose pixel size
          // matches an observed renderer viewport state; raw pixels never in LHR/HTML.
          const shot = Buffer.from(result.capture?.png ?? '', 'base64');
          const pixels = pngSize(shot);
          captureConsistency({ ...result.capture, pixels });
          const profileName = name.endsWith('-desktop') ? 'desktop' : 'mobile';
          const profile = profiles[profileName];
          assert.ok(fixture.hits.filter(h => h.path === '/' + measureMode.query && h.markerMatches).every(h => h.userAgentProfile === profileName), 'audited requests must use configured UA');
          assert.equal(result.settings.formFactor, profile.formFactor);
          assert.equal(result.settings.emulatedUserAgent, profile.emulatedUserAgent);
          assert.ok(result.capture.observed.every(s => s.userAgent === profile.emulatedUserAgent));
          assert.deepEqual(pixels, result.capture.pixels);
          assert.equal(result.capture.source, 'owned-audit-visit');
          assert.ok(!JSON.stringify(result.lhr).includes(result.capture.png) && !result.html.includes(result.capture.png), 'raw pixels leaked into report');
          const capturePath = join(evidence, `${name}-capture.png`);
          await save(root, capturePath, shot);
          const decoded = execFileSync('/usr/bin/magick', [capturePath, '-format', '%[pixel:p{2,2}]|%[fx:standard_deviation]', 'info:'], { encoding: 'utf8', timeout: 10000 }).trim().split('|');
          assert.ok(['srgb(18,52,86)', 'srgba(18,52,86,1)'].includes(decoded[0]), 'decoded origin pixel must match synthetic fixture sentinel');
          assert.ok(Number(decoded[1]) > 0.001, 'capture must contain nonuniform fixture content');
          row.decodedContent = { sentinel: decoded[0], standardDeviation: Number(decoded[1]), decoder: 'ImageMagick', synthetic: true };
          row.capture = { format: 'png', bytes: shot.length, sha256: sha256(shot), pixels, observed: result.capture.observed, geometry: result.capture.geometry, source: result.capture.source, at: result.capture.at };
        }
        row.reportValidation = { complete: true, htmlMatches: true, noindexScore: 0, jsonSha256: sha256(json), htmlSha256: sha256(html) };
      } else {
        const httpsCase = {
          'https-fetch': { url: fixture.deniedTLSOrigin + '/', resourceType: 'XHR', allowPreflight: true },
          'https-image': { url: fixture.deniedTLSOrigin + '/asset.png', resourceType: 'Image' },
          'accounts-fetch': { url: 'https://accounts.google.com/fixture', resourceType: 'XHR', allowPreflight: true },
          'accounts-image': { url: 'https://accounts.google.com/fixture.png', resourceType: 'Image' },
          'frame-https': { url: fixture.deniedTLSOrigin + '/', resourceType: 'XHR', allowPreflight: true },
        }[name];
        const wsTarget = { websocket: fixture.deniedOrigin.replace('http:', 'ws:') + '/', wss: fixture.deniedTLSOrigin.replace('https:', 'wss:') + '/', 'accounts-wss': 'wss://accounts.google.com/fixture' }[name];
        if (httpsCase) row.provenanceCheck = assertHttpsDenial(error, row.diagnostic, httpsCase);
        else {
          const expected = wsTarget ? ['websocket'] : expectedStops[name] || ['forbidden-host'];
          assert.ok(expected.includes(error), `${name}: expected case-specific safety stop, got ${error}`);
        }
        if (wsTarget) {
          assert.equal(row.diagnostic?.source, 'renderer-websocket');
          assert.equal(row.diagnostic?.sessionKnown, true);
          assert.equal(row.diagnostic?.syntheticTarget, wsTarget);
          assert.ok(row.diagnostic.websocketCreations > 0);
        }
        if (['popup', 'worker'].includes(name)) {
          assert.equal(row.diagnostic?.source, 'paused-target');
          assert.equal(row.diagnostic?.waitingForDebugger, true);
        }
      }
      row.status = 'passed';
      console.log(`${name}: passed; denied destination requests=0; reason=${row.error || 'none'}`);
    } finally { await fixture.close(); }
  }
} finally {
  await fd.close();
  await save(root, join(evidence, 'results.json'), { results, pins, context, finished: new Date().toISOString() });
  assert.equal((await lstat(evidence)).mode & 0o777, 0o700);
  for (const name of await readdir(evidence)) {
    const st = await lstat(join(evidence, name));
    assert.ok(st.isFile() && !st.isSymbolicLink() && st.nlink === 1 && st.uid === process.getuid());
    assert.equal(st.mode & 0o777, 0o600);
  }
  if (!process.env.LH_FIXTURE_CASE && results.length === cases.length && results.every(x => x.status === 'passed' && x.deniedConnections === 0 && x.deniedTLSConnections === 0)) {
    assert.equal(await instrumentHash(), context.instrument, 'runtime changed during matrix');
    assert.equal(await securityOracleHash(), context.oracleHash, 'oracle changed during matrix');
    await save(root, join(evidence, 'gate-candidate.json'), { status: 'passed', planId: plan.id, planHash: plan.hash, oracleHash: context.oracleHash, instrument: context.instrument, deniedRequests: 0, evidence, context, testedAt: new Date().toISOString() });
  }
  console.log(`evidence: ${evidence}`);
}
