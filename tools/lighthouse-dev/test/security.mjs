import assert from 'node:assert/strict';
import { mkdtemp, readFile, readlink } from 'node:fs/promises';
import { join } from 'node:path';
import { generateReport } from 'lighthouse';
import { validateLhr } from '../src/report.mjs';
import { runWorker } from '../src/lifecycle.mjs';
import { preflight, sha256 } from '../src/preflight.mjs';
import { stateRoot, privateDirectory, save, toolchain, lock } from '../src/store.mjs';
import { instrumentHash } from '../src/measure.mjs';
import { fixtureAdapter, fixtureHtml } from './fixture-adapter.mjs';
import { assertHttpsDenial } from './security-oracle.mjs';

process.umask(0o077);
assert.equal(process.argv.length, 2);
const root = await privateDirectory(join(stateRoot, 'ticket-2-evidence'));
const evidence = await mkdtemp(join(root, 'security-'));
const pins = await toolchain();
const fd = await lock();
const context = { uid: process.getuid(), gid: process.getgid(), netNamespace: await readlink('/proc/self/ns/net'), userNamespace: await readlink('/proc/self/ns/user'), uidMap: (await readFile('/proc/self/uid_map', 'utf8')).trim(), instrument: await instrumentHash(), fixtureSource: sha256(await readFile(new URL(import.meta.url))), startedAt: new Date().toISOString() };
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
  ['POST', () => ({ body: fixtureHtml('<script>fetch("/",{method:"POST",body:"synthetic"})</script>') })],
  ['commercial-GET', () => ({ body: fixtureHtml('<script>fetch("/?add-to-cart=1")</script>') })],
  ['websocket', ({ deniedOrigin }) => ({ body: fixtureHtml(`<script>new WebSocket(${JSON.stringify(deniedOrigin.replace('http:', 'ws:') + '/')})</script>`) })],
  ['wss', ({ deniedTLSOrigin }) => ({ body: fixtureHtml(`<script>new WebSocket(${JSON.stringify(deniedTLSOrigin.replace('https:', 'wss:') + '/')})</script>`) })],
  ['https-fetch', ({ deniedTLSOrigin }) => ({ body: fixtureHtml(`<script>fetch(${JSON.stringify(deniedTLSOrigin + '/')})</script>`) })],
  ['https-image', ({ deniedTLSOrigin }) => ({ body: fixtureHtml(`<img alt="TLS" src="${deniedTLSOrigin}/asset.png">`) })],
  ['accounts-fetch', () => ({ body: fixtureHtml('<script>fetch("https://accounts.google.com/fixture")</script>') })],
  ['accounts-image', () => ({ body: fixtureHtml('<img alt="Same host, renderer resource" src="https://accounts.google.com/fixture.png">') })],
  ['accounts-wss', () => ({ body: fixtureHtml('<script>new WebSocket("wss://accounts.google.com/fixture")</script>') })],
  ['frame-https', ({ req, deniedTLSOrigin }) => ({ body: fixtureHtml(req.url === '/tienda/' ? `<script>fetch(${JSON.stringify(deniedTLSOrigin + '/')})</script>` : '<iframe title="Guarded same-origin child" src="/tienda/"></iframe>') })],
  ['worker', () => ({ body: fixtureHtml('<script>new Worker("/worker.js")</script>') })],
  ['clean', () => ({ body: fixtureHtml('<p>Only public synthetic content.</p>') })],
  ['clean-desktop', () => ({ body: fixtureHtml('<p>Only public synthetic content.</p>') })],
];
const results = [];
try {
  const selected = process.env.LH_FIXTURE_CASE ? cases.filter(([name]) => process.env.LH_FIXTURE_CASE.split(',').includes(name)) : cases;
  assert.ok(selected.length, 'Unknown synthetic fixture case');
  for (const [name, render] of selected) {
    const fixture = await fixtureAdapter(render);
    const row = { name, status: 'incomplete' };
    results.push(row);
    try {
      if (['302', '307'].includes(name)) {
        await assert.rejects(preflight(fixture.policy, '/', '127.0.0.1'), /forbidden-host/);
        assert.equal(fixture.denied.length, 0);
      }
      let result, error;
      try { result = await runWorker({ policy: fixture.policy, address: '127.0.0.1', url: fixture.origin + '/', profile: name === 'clean-desktop' ? 'desktop' : 'mobile', pins }, fd.fd, { timeout: 30000 }); }
      catch (e) { error = e.code || 'unclassified'; row.diagnostic = e.diagnostic || null; }
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
      assert.ok(fixture.hits.some(x => x.path === '/' && x.markerMatches), `${name}: audited fixture was never reached`);
      assert.equal(fixture.denied.length, 0, `${name}: forbidden first request escaped`);
      assert.equal(fixture.hits.filter(x => x.method !== 'GET' || x.path.includes('add-to-cart')).length, 0, `${name}: mutation escaped`);
      if (name.startsWith('clean')) {
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
          const expected = wsTarget ? ['websocket'] : { popup: ['popup'], POST: ['method'], 'commercial-GET': ['commercial-endpoint'], worker: ['worker-target'] }[name] || ['forbidden-host'];
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
  if (results.length === cases.length && results.every(x => x.status === 'passed' && x.deniedConnections === 0 && x.deniedTLSConnections === 0)) {
    await save(root, join(evidence, 'gate-candidate.json'), { status: 'passed', instrument: await instrumentHash(), deniedRequests: 0, evidence, context, testedAt: new Date().toISOString() });
  }
  console.log(`evidence: ${evidence}`);
}
