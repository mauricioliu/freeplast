import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, mkdir, readFile, writeFile, readdir, lstat, rm, symlink } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { distribution, loadRun, calibrationData, evaluate, calibrate, compare, baselineSet, list, show, cases, thresholds, exitCode } from '../src/compare.mjs';
import { schedule, instrumentHash } from '../src/measure.mjs';
import { sha256 } from '../src/preflight.mjs';
import { save, manifest } from '../src/store.mjs';
import { profiles, auditConfig } from '../src/config.mjs';
import { origin, routes, measureMode, measureUrl, activeMode } from '../src/policy.mjs';
import { requestFor, runPlan } from './budget-fixture.mjs';
import { summarize, metricIds, categoryIds } from '../src/report.mjs';

const hash = sha256('synthetic'), stamp = '2026-01-01T00:00:00.000Z';
import { pngWith } from './png-fixture.mjs';
async function root(t) { const dir = await mkdtemp(join(tmpdir(), 'lh-comparison-')); t.after(() => rm(dir, { recursive: true, force: true })); return dir; }
async function fixture(root, id, change = () => {}, { slot = 2, captures = false } = {}) {
  const dir = join(root, id); await mkdir(dir, { mode: 0o700 });
  const files = [{ path: 'synthetic.css', mode: 0o644, bytes: 1, sha256: hash }];
  const source = { head: 'synthetic', branch: 'synthetic', status: [], indexHash: hash, files, sourceHash: sha256(JSON.stringify(files)) }, headers = { 'cache-control': 'no-store' };
  const pageMode = { ...activeMode(), markers: 2 };
  const run = { schema: 3, plan: runPlan, id, label: 'synthetic', status: 'complete', startedAt: stamp, deadline: '2099-01-01T00:45:00.000Z', finishedAt: '2099-01-01T00:01:00.000Z', slot, captureAllowance: captures ? 6 : 0, schedule: schedule(), audits: [], artifacts: [], cache: 'browser-cold-new-profile; server-unchanged', retries: 0, loadBefore: {}, loadAfter: {}, sourceAfter: source,
    environment: { pins: JSON.parse(await readFile(new URL('../toolchain.json', import.meta.url))), instrument: await instrumentHash(), configHash: sha256(JSON.stringify(auditConfig)), policyVersion: 2, sanitizerVersion: 2, mode: activeMode(), origin, routes, addresses: ['127.0.0.1'], identity: { schema: 1, origin, checkout: 'synthetic', sourceHash: source.sourceHash, verifiedAt: stamp, operator: 'synthetic', runtimeId: 'synthetic', wordpress: '1', plugins: {} }, source, pages: routes.map(path => ({ path, contentHash: hash, headers, mode: pageMode })), styleHash: hash, hardware: { hostname: 'synthetic', platform: 'linux', release: 'synthetic', arch: 'x64', cpus: [{ model: 'synthetic' }], memory: 1 }, storage: {}, load: {} } };
  for (const [i, item] of schedule().entries()) {
    const lhr = { lighthouseVersion: run.environment.pins.lighthouse, finalDisplayedUrl: measureUrl(origin, item.path), finalUrl: measureUrl(origin, item.path), configSettings: profiles[item.profile], categories: {}, audits: {} };
    for (const m of metricIds) lhr.audits[m] = { id: m, numericValue: m === 'cumulative-layout-shift' ? .01 : 1000, score: 1, scoreDisplayMode: 'numeric' };
    for (const c of categoryIds) { const auditId = c === 'seo' ? 'is-crawlable' : `check-${c}`; lhr.categories[c] = { score: .9, auditRefs: [{ id: auditId }] }; lhr.audits[auditId] = { id: auditId, score: c === 'seo' ? 0 : 1, scoreDisplayMode: 'binary' }; }
    const row = { ...item, status: 'complete', startedAt: stamp, finishedAt: stamp, requestedUrl: lhr.finalUrl, finalUrl: lhr.finalUrl, preflight: { contentHash: hash, headers, url: lhr.finalUrl, mode: pageMode }, loadBefore: {}, loadAfter: {}, settings: lhr.configSettings, security: { version: 2, active: true, guardedThroughShutdown: true, rendererProof: { allTargetsGuarded: true, networkEnabled: true, fetchEnabled: true, httpsContinued: 0, websocketCreations: 0 }, proxy: { version: 2, loopbackBypass: false }, documents: [{ type: 'Document', url: lhr.finalUrl, status: 200 }], targets: [{ type: 'page', guarded: true }] }, launch: { sandbox: true, cache: 'new-profile; browser-cold; server-unchanged' } };
    change(lhr, row, run, i); row.summary = summarize(lhr); run.audits.push(row);
    const stem = `${String(i + 1).padStart(2, '0')}-${item.profile}`;
    run.artifacts.push(await save(root, join(dir, stem + '.json'), lhr)); run.artifacts.push(await save(root, join(dir, stem + '.html'), '<html>synthetic only</html>'));
    if (captures && item.round === 1) {
      const geo = profiles[item.profile].screenEmulation;
      const snap = { innerWidth: geo.width, innerHeight: geo.height, deviceScaleFactor: geo.deviceScaleFactor, visualWidth: geo.width, visualHeight: geo.height, visualScale: 1, userAgent: profiles[item.profile].emulatedUserAgent };
      const pixels = { width: Math.round(geo.width * geo.deviceScaleFactor), height: Math.round(geo.height * geo.deviceScaleFactor) };
      const ref = await save(root, join(dir, `${stem}.capture.png`), pngWith(pixels.width, pixels.height));
      row.capture = { ok: true, format: 'png', source: 'owned-audit-visit', at: stamp, geometry: { width: geo.width, height: geo.height, deviceScaleFactor: geo.deviceScaleFactor }, observed: [snap, snap], pixels, bytes: ref.bytes, sha256: ref.sha256 };
      run.artifacts.push(ref);
    }
  }
  for (const name of ['summary.json', 'summary.md', 'events.json']) run.artifacts.push(await save(root, join(dir, name), '{}'));
  run.request = requestFor(run.environment, run.captureAllowance);
  await manifest(root, dir, run); return run;
}
async function alter(root, id, fn) { const path = join(root, id, 'manifest.json'), data = JSON.parse(await readFile(path)); fn(data); await writeFile(path, JSON.stringify(data), { mode: 0o600 }); }
async function snapshot(root) { const out = []; for (const name of (await readdir(root)).sort()) { const path = join(root, name), st = await lstat(path); out.push([name, st.mode, st.mtimeMs, st.isDirectory() ? await snapshot(path) : sha256(await readFile(path))]); } return out; }

test('capture sidecars: valid series loads; missing/tampered/extra captures never approve', async t => {
  const r = await root(t); await fixture(r, 'cap', () => {}, { slot: 1, captures: true });
  const loaded = await loadRun('cap', r);
  assert.equal(loaded.run.captureAllowance, 6);
  assert.ok(loaded.run.audits.filter(x => x.round === 1).every(x => x.capture));
  await fixture(r, 'capgone', () => {}, { slot: 1, captures: true });
  await rm(join(r, 'capgone/01-mobile.capture.png'));
  await assert.rejects(loadRun('capgone', r));
  await fixture(r, 'captamper', () => {}, { slot: 1, captures: true });
  await alter(r, 'captamper', run => { for (const row of run.audits) if (row.round === 1) row.capture.sha256 = '0'.repeat(64); });
  await assert.rejects(loadRun('captamper', r), { code: 'capture-missing' });
  await fixture(r, 'capmeta', () => {}, { slot: 1, captures: true });
  await alter(r, 'capmeta', run => { for (const row of run.audits) if (row.round === 1) row.capture.source = 'elsewhere'; });
  await assert.rejects(loadRun('capmeta', r), { code: 'capture-missing' });
  await fixture(r, 'capdrop', () => {}, { slot: 1, captures: true });
  await alter(r, 'capdrop', run => { for (const row of run.audits) if (row.round === 1) delete row.capture; });
  await assert.rejects(loadRun('capdrop', r), { code: 'capture-missing' });
});

test('distribution keeps raw values; odd/even median, range, MAD; rejects missing/invalid', () => {
  assert.deepEqual(distribution([5, 1, 3]), { values: [5, 1, 3], n: 3, median: 3, min: 1, max: 5, range: 4, mad: 2 });
  assert.equal(distribution([4, 1, 3, 2]).median, 2.5);
  for (const values of [[], [NaN], [null], [-1], [Infinity]]) assert.throws(() => distribution(values));
  assert.deepEqual(['improvement', 'regression', 'inconclusive', 'incompatible'].map(exitCode), [0, 3, 4, 5]);
});

test('complete 18 audit identity, all distributions and honest diagnostics; queries read-only', async t => {
  const r = await root(t); await fixture(r, 'a'); const before = await snapshot(r);
  const result = await show('a', r); assert.equal(Object.keys(result.cases).length, 6);
  for (const c of cases) { assert.equal(result.cases[c].metrics['largest-contentful-paint'].n, 3); assert.equal(Object.keys(result.cases[c].scores).length, 4); assert.ok(result.cases[c].ttfb.every(x => x.unavailable)); assert.ok(result.cases[c].lcpDetails.unavailable); }
  assert.equal((await list(r)).count, 1); assert.deepEqual(await snapshot(r), before);
  const absent = join(r, 'absent'); assert.deepEqual(await list(absent), { count: 0, runs: [] }); await assert.rejects(lstat(absent), { code: 'ENOENT' });
});

test('strict schema, hashes, reports, security, missing runs and refs reject', async t => {
  const r = await root(t);
  const mutations = [
    x => { x.schema = 9; }, x => { x.extra = true; }, x => { x.status = 'incomplete'; }, x => x.audits.pop(),
    x => { x.audits[1].round = 3; }, x => { x.artifacts[0].sha256 = '0'.repeat(64); }, x => x.artifacts.pop(),
    x => { x.artifacts[0].path = '../escape'; }, x => { x.audits[0].summary.metrics['largest-contentful-paint'] = 0; },
    x => { x.audits[0].security.active = false; }, x => { x.audits[0].security.rendererProof.websocketCreations = 1; },
    x => { x.audits[0].preflight.contentHash = '0'.repeat(64); }, x => { x.sourceAfter = {}; },
    x => { x.environment.source.files[0].bytes++; }, x => { delete x.environment.identity.wordpress; },
    x => { x.audits[0].settings = {}; }, x => { x.audits[0].finalUrl = origin + '/tienda/'; },
    x => { x.environment.mode = { id: 'fp-measure', query: '?fp-measure=1', version: 1, active: false }; }, x => { x.environment.mode = undefined; },
    x => { x.environment.pages[0].mode.markers = 0; }, x => { x.slot = 14; }, x => { x.captureAllowance = 5; },
  ];
  for (const [i, mutation] of mutations.entries()) { const id = 'bad' + i; await fixture(r, id); await alter(r, id, mutation); await assert.rejects(loadRun(id, r)); }
  await assert.rejects(loadRun('missing', r)); await assert.rejects(loadRun('../escape', r));
  await fixture(r, 'missing-file'); await rm(join(r, 'missing-file/01-mobile.html')); await assert.rejects(loadRun('missing-file', r));
  await fixture(r, 'missing-ref', lhr => { lhr.categories.accessibility.auditRefs.push({ id: 'missing' }); lhr.audits.missing = { score: 1, scoreDisplayMode: 'binary' }; });
  const path = join(r, 'missing-ref/01-mobile.json'), lhr = JSON.parse(await readFile(path)); delete lhr.audits.missing;
  const data = JSON.stringify(lhr); await writeFile(path, data); await alter(r, 'missing-ref', run => { run.artifacts[0].sha256 = sha256(data); run.artifacts[0].bytes = Buffer.byteLength(data); });
  await assert.rejects(loadRun('missing-ref', r));
  for (const [name, change] of [['null', lhr => { lhr.audits['largest-contentful-paint'].numericValue = null; }], ['runtime', lhr => { lhr.runtimeError = {}; }], ['robots', lhr => { lhr.audits['is-crawlable'].score = 1; }], ['error', lhr => { lhr.audits['check-accessibility'].scoreDisplayMode = 'error'; }]]) {
    await fixture(r, name, change); await assert.rejects(loadRun(name, r));
  }
});

test('explicit calibration frozen; A/A no gain; compare preserves baseline; improvement requires focal', async t => {
  const r = await root(t); await fixture(r, 'a'); await fixture(r, 'aa');
  assert.equal((await compare('a', 'aa', null, r)).status, 'inconclusive');
  const defaultCal = await calibrate('a', 'aa', null, r); assert.equal(defaultCal.status, 'calibrated');
  assert.equal((await compare('a', 'aa', defaultCal.id, r)).status, 'inconclusive');
  const cal = await calibrate('a', 'aa', 'mobile:/', r);
  await baselineSet('a', r); const baseline = await readFile(join(r, 'baseline.json'));
  await fixture(r, 'better', (lhr, row, run) => { run.startedAt = '2099-01-01T00:00:00.000Z'; if (row.profile === 'mobile' && row.path === '/') lhr.audits['largest-contentful-paint'].numericValue = 700; });
  assert.equal((await compare('a', 'better', cal.id, r)).status, 'improvement');
  assert.equal((await compare('a', 'better', defaultCal.id, r)).status, 'inconclusive');
  await fixture(r, 'worse', (lhr, row, run) => { run.startedAt = '2099-01-01T00:00:00.000Z'; lhr.audits['largest-contentful-paint'].numericValue = 1400; });
  assert.equal((await compare('a', 'worse', null, r)).status, 'inconclusive');
  const before = await snapshot(r); assert.equal((await compare('a', 'worse', cal.id, r)).status, 'regression'); assert.deepEqual(await snapshot(r), before);
  assert.deepEqual(await readFile(join(r, 'baseline.json')), baseline);
  const calPath = join(r, cal.id + '.json'), record = JSON.parse(await readFile(calPath)); record.noise['mobile:/']['largest-contentful-paint'] = 9999; await writeFile(calPath, JSON.stringify(record));
  await assert.rejects(compare('a', 'better', cal.id, r), { code: 'calibration-integrity' });
  await assert.rejects(compare('a', 'better', 'cal-' + '0'.repeat(64), r));
  await alter(r, 'a', run => { run.audits.pop(); });
  await assert.rejects(baselineSet('a', r)); assert.deepEqual(await readFile(join(r, 'baseline.json')), baseline);
});

test('symlinks, changed calibration references, malformed calibration and missing runs fail closed', async t => {
  const r = await root(t); await fixture(r, 'a'); await fixture(r, 'aa');
  const cal = await calibrate('a', 'aa', null, r);
  await symlink(join(r, 'a'), join(r, 'alias')); await assert.rejects(loadRun('alias', r));
  const html = join(r, 'a/01-mobile.html'); await rm(html); await symlink(join(r, 'a/02-mobile.html'), html); await assert.rejects(loadRun('a', r));
  await assert.rejects(compare('aa', 'aa', cal.id, r));
  await rm(html); await writeFile(html, '<html>synthetic only</html>', { mode: 0o600 });
  await alter(r, 'a', run => { run.label = 'changed-after-calibration'; });
  await assert.rejects(compare('aa', 'aa', cal.id, r), { code: 'calibration-reference' });
  const path = join(r, cal.id + '.json'); await writeFile(path, '{}'); await assert.rejects(compare('aa', 'aa', cal.id, r), { code: 'invalid-schema' });
  await rm(join(r, 'a/manifest.json')); await assert.rejects(loadRun('a', r));
});

test('optional TTFB diagnostics retain values, freeze noise, warn without claiming INP', async t => {
  const r = await root(t);
  for (const id of ['a', 'aa']) await fixture(r, id, lhr => { lhr.audits['server-response-time'] = { numericValue: 100 }; });
  const a = await loadRun('a', r), b = await loadRun('aa', r), cal = calibrationData(a, b, 'mobile:/');
  assert.equal(cal.noise['mobile:/'].ttfb, 0);
  b.cases['mobile:/'].ttfb = [301, 301, 301].map(value => ({ value, auditId: 'server-response-time' }));
  const result = evaluate(a, b, cal); assert.equal(result.cases['mobile:/'].ttfb.warning, true); assert.match(result.acceptance, /TBT is not INP/);
});

test('noise, zero baseline, exact thresholds, relative floor, frozen noise floor', async t => {
  const r = await root(t); await fixture(r, 'a'); await fixture(r, 'aa');
  const a = await loadRun('a', r), b = await loadRun('aa', r), cal = calibrationData(a, b, 'mobile:/');
  for (const m of metricIds) {
    const candidate = structuredClone(b), baseline = structuredClone(a), abs = thresholds[m][0];
    baseline.cases['mobile:/'].metrics[m] = distribution([0, 0, 0]); candidate.cases['mobile:/'].metrics[m] = distribution([abs, abs, abs]);
    let result = evaluate(baseline, candidate, cal); assert.equal(result.cases['mobile:/'].metrics[m].relativeDelta, null); assert.equal(result.cases['mobile:/'].metrics[m].state, 'within-threshold');
    candidate.cases['mobile:/'].metrics[m] = distribution([abs + .001, abs + .001, abs + .001]); assert.equal(evaluate(baseline, candidate, cal).status, 'regression');
  }
  const candidate = structuredClone(b); candidate.cases['mobile:/'].metrics['largest-contentful-paint'] = distribution([800, 800, 800]); assert.equal(evaluate(a, candidate, cal).status, 'inconclusive');
  candidate.cases['mobile:/'].metrics['largest-contentful-paint'] = distribution([799, 799, 799]); assert.equal(evaluate(a, candidate, cal).status, 'improvement');
  cal.noise['mobile:/']['largest-contentful-paint'] = 300; assert.equal(evaluate(a, candidate, cal).status, 'inconclusive');
  const large = structuredClone(a); large.cases['mobile:/'].metrics['largest-contentful-paint'] = distribution([10000, 10000, 10000]); candidate.cases['mobile:/'].metrics['largest-contentful-paint'] = distribution([9000, 9000, 9000]); assert.equal(evaluate(large, candidate, cal).status, 'inconclusive');
  b.cases['mobile:/'].metrics['largest-contentful-paint'] = distribution([500, 1000, 1500]); assert.equal(calibrationData(a, b).status, 'inconclusive');
  b.cases['mobile:/'].metrics['largest-contentful-paint'] = distribution([1201, 1201, 1201]); assert.equal(calibrationData(a, b).status, 'inconclusive');
});

test('version/config/cache/origin/source incompatibilities and candidate before calibration', async t => {
  const r = await root(t); await fixture(r, 'a'); await fixture(r, 'aa'); const a = await loadRun('a', r), b = await loadRun('aa', r);
  for (const field of ['pins', 'instrument', 'configHash', 'origin', 'source', 'hardware', 'pages', 'mode']) { const x = structuredClone(b); x.run.environment[field] = 'different'; assert.equal(evaluate(a, x).status, 'incompatible', field); }
  const x = structuredClone(b); x.run.cache = 'warm'; assert.equal(evaluate(a, x).status, 'incompatible');
  await fixture(r, 'old', lhr => { lhr.audits['largest-contentful-paint'].numericValue = 700; }); const cal = await calibrate('a', 'aa', 'mobile:/', r);
  assert.equal((await compare('a', 'old', cal.id, r)).status, 'incompatible');
  await alter(r, 'aa', x => { x.environment.instrument = '0'.repeat(64); x.request.compatibility.instrument = x.environment.instrument; }); assert.equal((await compare('a', 'aa', null, r)).status, 'incompatible');
});

test('new failed audits hidden by same score, intermittent failures, CLS redline, no cross-case averaging', async t => {
  const r = await root(t); await fixture(r, 'a'); await fixture(r, 'aa'); const a = await loadRun('a', r), b = await loadRun('aa', r), cal = calibrationData(a, b, 'mobile:/');
  for (const category of categoryIds.slice(1)) {
    const x = structuredClone(b); x.cases['mobile:/'].metrics['largest-contentful-paint'] = distribution([600, 600, 600]);
    for (const sample of x.cases['mobile:/'].audits) sample[category].push({ id: 'new-failure', score: 0, mode: 'binary' });
    assert.equal(evaluate(a, x, cal).status, 'regression', category);
  }
  const intermittent = structuredClone(b); intermittent.cases['mobile:/'].audits[0].accessibility[0].score = 0; assert.equal(evaluate(a, intermittent, cal).status, 'inconclusive');
  const redline = structuredClone(b); a.cases['mobile:/'].metrics['cumulative-layout-shift'] = distribution([.1, .1, .1]); redline.cases['mobile:/'].metrics['cumulative-layout-shift'] = distribution([.101, .101, .101]); assert.equal(evaluate(a, redline, cal).status, 'regression');
  const mixed = structuredClone(b); mixed.cases['mobile:/'].metrics['largest-contentful-paint'] = distribution([100, 100, 100]); mixed.cases['desktop:/'].metrics['largest-contentful-paint'] = distribution([1300, 1300, 1300]); assert.equal(evaluate(a, mixed, cal).status, 'regression');
});
