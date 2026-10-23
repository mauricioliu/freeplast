import { mkdir, readFile } from 'node:fs/promises';
import { join } from 'node:path';
import { schedule, instrumentHash } from '../src/measure.mjs';
import { sha256 } from '../src/preflight.mjs';
import { save, manifest } from '../src/store.mjs';
import { profiles } from '../src/config.mjs';
import { origin, routes, measureUrl, activeMode } from '../src/policy.mjs';
import { summarize, metricIds, categoryIds } from '../src/report.mjs';
import { requestFor, runPlan } from './budget-fixture.mjs';

// Synthetic reports only: no browser, network, receipt activation, or application.
export async function report(root, id, source, improved = false) {
  const dir = join(root, id); await mkdir(dir, { mode: 0o700 });
  const hash = sha256('synthetic'), stamp = new Date().toISOString(), headers = { 'cache-control': 'no-store' };
  const pageMode = { ...activeMode(), markers: 2 };
  const run = { schema: 3, plan: runPlan, id, label: 'synthetic', status: 'complete', startedAt: stamp, deadline: new Date(Date.now() + 60000).toISOString(), finishedAt: stamp, slot: 2, captureAllowance: 0, schedule: schedule(), audits: [], artifacts: [], cache: 'browser-cold-new-profile; server-unchanged', retries: 0, loadBefore: {}, loadAfter: {}, sourceAfter: source,
    environment: { pins: JSON.parse(await readFile(new URL('../toolchain.json', import.meta.url))), instrument: await instrumentHash(), configHash: sha256(JSON.stringify(profiles)), policyVersion: 2, sanitizerVersion: 2, mode: activeMode(), origin, routes, addresses: ['127.0.0.1'], identity: { schema: 1, origin, checkout: 'synthetic', sourceHash: source.sourceHash, verifiedAt: stamp, operator: 'synthetic', runtimeId: 'synthetic', wordpress: '1', plugins: {} }, source, pages: routes.map(path => ({ path, contentHash: hash, headers, mode: pageMode })), styleHash: source.files.find(f => f.path === 'wordpress/wp-content/themes/freeplast/style.css')?.sha256 ?? hash, hardware: { hostname: 'synthetic', platform: 'linux', release: 'synthetic', arch: 'x64', cpus: [{ model: 'synthetic' }], memory: 1 }, storage: {}, load: {} } };
  for (const [i, item] of schedule().entries()) {
    const lhr = { lighthouseVersion: run.environment.pins.lighthouse, finalDisplayedUrl: measureUrl(origin, item.path), finalUrl: measureUrl(origin, item.path), configSettings: profiles[item.profile], categories: {}, audits: {} };
    for (const m of metricIds) lhr.audits[m] = { id: m, numericValue: m === 'cumulative-layout-shift' ? .01 : improved && m === 'largest-contentful-paint' ? 700 : 1000, score: 1, scoreDisplayMode: 'numeric' };
    for (const c of categoryIds) { const id = c === 'seo' ? 'is-crawlable' : `check-${c}`; lhr.categories[c] = { score: .9, auditRefs: [{ id }] }; lhr.audits[id] = { id, score: c === 'seo' ? 0 : 1, scoreDisplayMode: 'binary' }; }
    const row = { ...item, status: 'complete', startedAt: stamp, finishedAt: stamp, requestedUrl: lhr.finalUrl, finalUrl: lhr.finalUrl, preflight: { contentHash: hash, headers, url: lhr.finalUrl, mode: pageMode }, loadBefore: {}, loadAfter: {}, settings: lhr.configSettings, security: { version: 2, active: true, guardedThroughShutdown: true, rendererProof: { allTargetsGuarded: true, networkEnabled: true, fetchEnabled: true, httpsContinued: 0, websocketCreations: 0 }, proxy: { version: 2, loopbackBypass: false }, documents: [{ type: 'Document', url: lhr.finalUrl, status: 200 }], targets: [{ type: 'page', guarded: true }] }, launch: { sandbox: true, cache: 'new-profile; browser-cold; server-unchanged' }, summary: summarize(lhr) };
    run.audits.push(row);
    const stem = `${String(i + 1).padStart(2, '0')}-${item.profile}`;
    run.artifacts.push(await save(root, join(dir, stem + '.json'), lhr)); run.artifacts.push(await save(root, join(dir, stem + '.html'), '<html>synthetic only</html>'));
  }
  for (const name of ['summary.json', 'summary.md', 'events.json']) run.artifacts.push(await save(root, join(dir, name), '{}'));
  run.request = requestFor(run.environment, 0);
  await manifest(root, dir, run);
}
