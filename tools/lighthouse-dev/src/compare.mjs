import { constants } from 'node:fs';
import { open, lstat, readdir, readFile, rename } from 'node:fs/promises';
import { join, resolve, parse } from 'node:path';
import { randomUUID } from 'node:crypto';
import { isDeepStrictEqual as equal } from 'node:util';
import { stateRoot, readPrivate, save, lock } from './store.mjs';
import { schedule, instrumentHash } from './measure.mjs';
import { sha256 } from './preflight.mjs';
import { origin, routes, policy, deny } from './policy.mjs';
import { validateLhr, summarize, metricIds, categoryIds } from './report.mjs';
import { profiles } from './config.mjs';

export const cases = ['mobile', 'desktop'].flatMap(profile => routes.map(path => `${profile}:${path}`));
export const thresholds = Object.freeze({
  'largest-contentful-paint': [200, .1], 'first-contentful-paint': [150, .1],
  'speed-index': [150, .1], 'total-blocking-time': [50, .15], 'cumulative-layout-shift': [.01, 0],
});
const idPattern = /^[a-zA-Z0-9][a-zA-Z0-9_-]{0,119}$/;
const hashPattern = /^[a-f0-9]{64}$/;
const key = row => `${row.profile}:${row.path}`;
const finite = n => typeof n === 'number' && Number.isFinite(n) && n >= 0;
function requireThat(ok, reason = 'invalid-record') { if (!ok) deny(reason); }
function keys(value, required, optional = []) {
  requireThat(value && typeof value === 'object' && !Array.isArray(value));
  requireThat(required.every(k => Object.hasOwn(value, k)) && Object.keys(value).every(k => [...required, ...optional].includes(k)), 'invalid-schema');
}
function id(value) { requireThat(typeof value === 'string' && idPattern.test(value), 'invalid-id'); return value; }
function sourceSchema(source) {
  keys(source, ['head', 'branch', 'status', 'indexHash', 'files', 'sourceHash']);
  requireThat(typeof source.head === 'string' && typeof source.branch === 'string' && Array.isArray(source.status) && source.status.every(x => typeof x === 'string') && hashPattern.test(source.indexHash) && Array.isArray(source.files) && source.files.length > 0, 'source-schema');
  for (const file of source.files) {
    keys(file, ['path', 'mode', 'bytes', 'sha256']);
    requireThat(typeof file.path === 'string' && !file.path.startsWith('/') && !file.path.split('/').includes('..') && Number.isInteger(file.mode) && file.mode >= 0 && file.mode <= 0o777 && Number.isSafeInteger(file.bytes) && file.bytes >= 0 && hashPattern.test(file.sha256), 'source-schema');
  }
  requireThat(new Set(source.files.map(f => f.path)).size === source.files.length && source.sourceHash === sha256(JSON.stringify(source.files)), 'source-integrity');
}
function identitySchema(identity) {
  keys(identity, ['schema', 'origin', 'checkout', 'sourceHash', 'verifiedAt', 'operator', 'runtimeId', 'wordpress', 'plugins']);
  requireThat(identity.schema === 1 && ['origin', 'checkout', 'operator', 'runtimeId', 'wordpress'].every(k => typeof identity[k] === 'string' && identity[k].length > 0) && Number.isFinite(Date.parse(identity.verifiedAt)) && identity.plugins && typeof identity.plugins === 'object' && !Array.isArray(identity.plugins) && Object.values(identity.plugins).every(x => typeof x === 'string'), 'identity-schema');
}
// Queries must never mkdir/chmod/lock; inspect every ancestor without following links.
async function directory(path) {
  path = resolve(path);
  let part = parse(path).root;
  for (const segment of path.slice(part.length).split('/')) {
    part = join(part, segment);
    const st = await lstat(part);
    requireThat(st.isDirectory() && !st.isSymbolicLink() && (!(st.mode & 0o022) || part === '/tmp'), 'unsafe-state-path');
  }
  const st = await lstat(path);
  requireThat(st.uid === process.getuid() && !(st.mode & 0o077), 'state-not-private');
}
async function bytes(path) {
  const fd = await open(path, constants.O_RDONLY | constants.O_NOFOLLOW);
  try {
    const st = await fd.stat();
    requireThat(st.isFile() && st.uid === process.getuid() && !(st.mode & 0o077) && st.nlink === 1 && st.size <= 32 * 1024 ** 2, 'unsafe-private-file');
    return await fd.readFile();
  } finally { await fd.close(); }
}
export function distribution(values) {
  requireThat(values.length > 0 && values.every(finite), 'invalid-values');
  const median = xs => { const s = [...xs].sort((a, b) => a - b), m = Math.floor(s.length / 2); return s.length % 2 ? s[m] : (s[m - 1] + s[m]) / 2; };
  const mid = median(values), min = Math.min(...values), max = Math.max(...values);
  return { values, n: values.length, median: mid, min, max, range: max - min, mad: median(values.map(n => Math.abs(n - mid))) };
}
function distributions(rows) {
  return Object.fromEntries(cases.map(c => {
    const samples = rows.filter(row => key(row) === c);
    return [c, {
      metrics: Object.fromEntries(metricIds.map(m => [m, distribution(samples.map(r => r.summary.metrics[m]))])),
      scores: Object.fromEntries(categoryIds.map(m => [m, distribution(samples.map(r => r.summary.scores[m]))])),
      audits: samples.map(r => r.summary.audits),
      ttfb: samples.map(r => r.summary.ttfb),
      lcpDetails: { unavailable: 'DOM removed by privacy sanitizer; no stable diagnostic schema in evaluator v1' },
    }];
  }));
}
export async function loadRun(runId, root = stateRoot) {
  id(runId); await directory(root); const dir = join(root, runId); await directory(dir);
  const raw = await bytes(join(dir, 'manifest.json'));
  let run; try { run = JSON.parse(raw); } catch { deny('invalid-json'); }
  keys(run, ['schema', 'id', 'label', 'status', 'startedAt', 'deadline', 'schedule', 'audits', 'artifacts', 'cache', 'retries', 'loadBefore', 'environment', 'sourceAfter', 'finishedAt', 'loadAfter']);
  requireThat(run.schema === 1 && run.id === runId && run.status === 'complete', 'incomplete-run');
  requireThat([run.startedAt, run.finishedAt, run.deadline].every(t => typeof t === 'string' && Number.isFinite(Date.parse(t))) && Date.parse(run.finishedAt) >= Date.parse(run.startedAt) && Date.parse(run.finishedAt) <= Date.parse(run.deadline), 'invalid-run-time');
  requireThat(equal(run.schedule, schedule()) && Array.isArray(run.audits) && run.audits.length === 18 && run.retries === 0, 'incomplete-schedule');
  requireThat(typeof run.cache === 'string' && run.cache.length > 0);
  const env = run.environment;
  keys(env, ['pins', 'instrument', 'configHash', 'policyVersion', 'sanitizerVersion', 'origin', 'routes', 'addresses', 'identity', 'source', 'pages', 'styleHash', 'hardware', 'storage', 'load']);
  requireThat(hashPattern.test(env.instrument) && hashPattern.test(env.configHash) && hashPattern.test(env.styleHash));
  sourceSchema(env.source); identitySchema(env.identity);
  keys(env.hardware, ['hostname', 'platform', 'release', 'arch', 'cpus', 'memory']);
  requireThat(['hostname', 'platform', 'release', 'arch'].every(k => typeof env.hardware[k] === 'string' && env.hardware[k].length > 0) && Array.isArray(env.hardware.cpus) && env.hardware.cpus.length > 0 && env.hardware.cpus.every(c => typeof c.model === 'string') && finite(env.hardware.memory) && env.hardware.memory > 0, 'hardware-schema');
  requireThat(equal(run.sourceAfter, env.source) && hashPattern.test(env.source?.sourceHash), 'source-drift');
  requireThat(env.identity?.sourceHash === env.source.sourceHash && env.identity.origin === env.origin && typeof env.identity.runtimeId === 'string', 'identity-mismatch');
  requireThat(env.pins?.lighthouse && env.pins.node && env.pins['puppeteer-core'] && hashPattern.test(env.pins.browser?.sha256), 'missing-pins');
  requireThat(Array.isArray(env.pages) && env.pages.length === 3 && Array.isArray(env.routes) && env.routes.length === 3 && Array.isArray(env.addresses) && env.addresses.length > 0, 'missing-environment');
  const expected = run.audits.flatMap((_, i) => { const stem = `${String(i + 1).padStart(2, '0')}-${schedule()[i].profile}`; return [`${stem}.json`, `${stem}.html`]; }).concat(['summary.json', 'summary.md', 'events.json']);
  requireThat(Array.isArray(run.artifacts) && run.artifacts.length === expected.length, 'missing-artifacts');
  const reports = new Map();
  for (let i = 0; i < expected.length; i++) {
    const ref = run.artifacts[i]; keys(ref, ['path', 'bytes', 'sha256']);
    requireThat(ref.path === `${runId}/${expected[i]}` && Number.isSafeInteger(ref.bytes) && ref.bytes > 0 && hashPattern.test(ref.sha256), 'invalid-artifact-reference');
    const data = await bytes(join(dir, expected[i]));
    requireThat(data.length === ref.bytes && sha256(data) === ref.sha256, 'artifact-integrity');
    if (i < 36 && i % 2 === 0) { try { reports.set(i / 2, JSON.parse(data)); } catch { deny('invalid-json'); } }
    if (i < 36 && i % 2 === 1) requireThat(data.includes('<html'), 'missing-html');
  }
  for (let i = 0; i < 18; i++) {
    const row = run.audits[i], item = schedule()[i];
    keys(row, ['round', 'profile', 'path', 'status', 'startedAt', 'requestedUrl', 'preflight', 'loadBefore', 'finalUrl', 'summary', 'settings', 'security', 'launch', 'loadAfter', 'finishedAt']);
    requireThat(equal({ round: row.round, profile: row.profile, path: row.path }, item) && row.status === 'complete', 'audit-identity');
    requireThat(row.requestedUrl === env.origin + item.path && row.finalUrl === row.requestedUrl, 'audit-url');
    const page = env.pages.find(p => p.path === row.path);
    requireThat(page && env.pages.filter(p => p.path === row.path).length === 1 && hashPattern.test(page.contentHash) && row.preflight.contentHash === page.contentHash && equal(row.preflight.headers, page.headers) && row.preflight.url === row.finalUrl, 'public-content-drift');
    requireThat(/no-store/i.test(page.headers?.['cache-control'] || ''), 'cache-policy');
    const s = row.security, proof = s?.rendererProof;
    requireThat(s?.version === 2 && s.active === true && s.guardedThroughShutdown === true && proof?.allTargetsGuarded === true && proof.networkEnabled === true && proof.fetchEnabled === true && proof.httpsContinued === 0 && proof.websocketCreations === 0 && s.proxy?.version === 2 && s.proxy.loopbackBypass === false && Array.isArray(s.documents) && s.documents.length > 0 && Array.isArray(s.targets) && s.targets.length > 0 && s.targets.every(t => t.guarded === true), 'security-unproven');
    requireThat(s.documents.every(d => d.type === 'Document' && env.routes.some(p => d.url === env.origin + p) && d.status >= 200 && d.status < 400), 'security-document');
    requireThat(row.launch.sandbox === true && row.launch.cache === 'new-profile; browser-cold; server-unchanged', 'launch-policy');
    const lhr = reports.get(i);
    // Validate against recorded origin first; unsupported origins remain incompatible, never improvement.
    validateLhr(lhr, { ...policy(), origin: env.origin, routes: env.routes });
    requireThat(lhr.finalDisplayedUrl === row.finalUrl && lhr.lighthouseVersion === env.pins.lighthouse && equal(lhr.configSettings, row.settings), 'report-identity');
    requireThat(Object.entries(profiles[item.profile]).every(([k, v]) => equal(row.settings[k], v)), 'resolved-settings');
    requireThat([row.startedAt, row.finishedAt].every(t => typeof t === 'string' && Number.isFinite(Date.parse(t))) && Date.parse(row.finishedAt) >= Date.parse(row.startedAt), 'invalid-audit-time');
    requireThat(equal(summarize(lhr), row.summary), 'summary-integrity');
    if (Object.hasOwn(row.summary.ttfb, 'value')) requireThat(finite(row.summary.ttfb.value), 'invalid-ttfb');
    for (const category of categoryIds) {
      const refs = lhr.categories[category].auditRefs;
      requireThat(new Set(refs.map(r => r.id)).size === refs.length, 'duplicate-audit-ref');
      for (const ref of refs) {
        const audit = lhr.audits[ref.id];
        requireThat(typeof ref.id === 'string' && /^[a-z0-9-]+$/.test(ref.id) && ['binary', 'numeric', 'metricSavings', 'manual', 'informative', 'notApplicable'].includes(audit.scoreDisplayMode), 'invalid-audit');
        const unscored = ['manual', 'informative', 'notApplicable'].includes(audit.scoreDisplayMode);
        requireThat(unscored ? audit.score === null : finite(audit.score) && audit.score <= 1, 'invalid-audit-score');
        if (audit.scoreDisplayMode === 'binary') requireThat(audit.score === 0 || audit.score === 1, 'invalid-binary-score');
      }
    }
    requireThat(lhr.audits['is-crawlable']?.score === 0 && row.summary.noindex === 'intentional; is-crawlable failure retained', 'robots-unproven');
  }
  return { id: runId, hash: sha256(raw), run, cases: distributions(run.audits) };
}
function declaredSource(a, b, intervention) {
  if (!intervention || intervention.kind !== 'DECLARED' || !Array.isArray(intervention.paths) || !intervention.paths.length) return false;
  const { paths, before, after } = intervention;
  if (!paths.every(p => typeof p === 'string' && p.startsWith('wordpress/wp-content/themes/freeplast/') && !p.split('/').some(s => !s || s === '.' || s === '..'))) return false;
  const x = a.run.environment.source, y = b.run.environment.source;
  if (!equal(x, before) || !equal(y, after) || x.head !== y.head || x.branch !== y.branch || x.indexHash !== y.indexHash) return false;
  const outside = source => source.files.filter(f => !paths.includes(f.path));
  const status = source => source.status.filter(s => !/^.{2} /.test(s) || !paths.includes(s.slice(3)));
  return equal(outside(x), outside(y)) && equal(status(x), status(y));
}
function compatibility(a, b, intervention = null) {
  const reasons = [], x = a.run, y = b.run;
  for (const field of ['cache']) if (!equal(x[field], y[field])) reasons.push(field);
  const declared = declaredSource(a, b, intervention);
  const style = 'wordpress/wp-content/themes/freeplast/style.css';
  const declaredStyle = declared && intervention.paths.includes(style) && [x, y].every(run => run.environment.styleHash === run.environment.source.files.find(f => f.path === style)?.sha256);
  for (const field of ['pins', 'instrument', 'configHash', 'policyVersion', 'sanitizerVersion', 'origin', 'routes', 'addresses', 'source', 'pages', 'styleHash', 'hardware']) if (!(declared && field === 'source') && !(declaredStyle && field === 'styleHash') && !equal(x.environment[field], y.environment[field])) reasons.push(field);
  for (const field of ['schema', 'origin', 'checkout', 'sourceHash', 'runtimeId', 'wordpress', 'plugins']) if (!(declared && field === 'sourceHash') && !equal(x.environment.identity[field], y.environment.identity[field])) reasons.push(`identity.${field}`);
  for (let i = 0; i < 18; i++) if (!equal(x.audits[i].settings, y.audits[i].settings) || !equal(x.audits[i].launch, y.audits[i].launch)) reasons.push(`audit-settings:${i + 1}`);
  return reasons;
}
async function current(run) {
  const e = run.run.environment, reasons = [];
  const pins = JSON.parse(await readFile(new URL('../toolchain.json', import.meta.url)));
  if (!equal(e.pins, pins)) reasons.push('current-pins');
  if (e.instrument !== await instrumentHash()) reasons.push('current-instrument');
  if (e.configHash !== sha256(JSON.stringify(profiles))) reasons.push('current-config');
  if (e.origin !== origin || !equal(e.routes, routes)) reasons.push('current-cases');
  if (e.policyVersion !== 1 || e.sanitizerVersion !== 2 || run.run.cache !== 'browser-cold-new-profile; server-unchanged') reasons.push('current-protocol');
  return reasons;
}
export function calibrationData(a, b, focal = null) {
  requireThat(a.id !== b.id, 'distinct-aa-runs-required');
  requireThat(focal === null || cases.includes(focal), 'invalid-focal-case');
  const reasons = compatibility(a, b);
  if (reasons.length) return { status: 'incompatible', reasons };
  const noise = {}, unstable = [];
  for (const c of cases) {
    noise[c] = {};
    for (const m of metricIds) {
      const x = a.cases[c].metrics[m], y = b.cases[c].metrics[m], [abs, rel] = thresholds[m];
      noise[c][m] = Math.abs(x.median - y.median) + 2 * Math.max(x.mad, y.mad);
      if (['largest-contentful-paint', 'first-contentful-paint', 'speed-index'].includes(m) &&
        (x.range > Math.max(2 * abs, .25 * x.median) || y.range > Math.max(2 * abs, .25 * y.median) || Math.abs(x.median - y.median) > Math.max(abs, rel * x.median))) unstable.push(`${c}:${m}`);
    }
    const tx = a.cases[c].ttfb.map(x => x.value), ty = b.cases[c].ttfb.map(x => x.value);
    noise[c].ttfb = tx.every(finite) && ty.every(finite) ? Math.abs(distribution(tx).median - distribution(ty).median) + 2 * Math.max(distribution(tx).mad, distribution(ty).mad) : null;
    if (categoryIds.slice(1).some(category => !equal(a.cases[c].scores[category].values, b.cases[c].scores[category].values))) unstable.push(`${c}:score-instability`);
    if (!equal(a.cases[c].audits, b.cases[c].audits) || !a.cases[c].audits.every(row => equal(row, a.cases[c].audits[0])) || !b.cases[c].audits.every(row => equal(row, b.cases[c].audits[0]))) unstable.push(`${c}:audit-instability`);
  }
  return { status: unstable.length ? 'inconclusive' : 'calibrated', reasons: unstable, focal, noise };
}
export function evaluate(a, b, calibration = null, intervention = null) {
  const incompatible = compatibility(a, b, intervention);
  if (incompatible.length) return { status: 'incompatible', reasons: incompatible };
  const result = { status: 'inconclusive', reasons: [], cases: {}, acceptance: 'numerical evidence only; functional tests and human content/design approval required; TBT is not INP' };
  let regression = false, uncertain = false, gain = false;
  for (const c of cases) {
    const changes = {}, guardrails = [];
    for (const m of metricIds) {
      const x = a.cases[c].metrics[m], y = b.cases[c].metrics[m], [abs, rel] = thresholds[m];
      const noise = calibration?.noise[c][m] ?? null, threshold = Math.max(abs, rel * x.median, noise ?? 0), delta = y.median - x.median;
      const redline = m === 'cumulative-layout-shift' && x.median <= .1 && y.median > .1;
      const state = delta > threshold || redline ? 'regression' : -delta > threshold ? 'improvement' : 'within-threshold';
      if (state === 'regression') regression = true;
      if (state === 'improvement' && m === 'largest-contentful-paint' && calibration?.focal === c) gain = true;
      if (['largest-contentful-paint', 'first-contentful-paint', 'speed-index'].includes(m) && y.range > Math.max(2 * abs, .25 * y.median)) { uncertain = true; guardrails.push(`candidate-noise:${m}`); }
      changes[m] = { baseline: x, candidate: y, delta, relativeDelta: x.median === 0 ? null : delta / x.median, threshold, noise, state };
    }
    for (const category of categoryIds.slice(1)) {
      const x = a.cases[c], y = b.cases[c];
      if (y.scores[category].median < x.scores[category].median) { regression = true; guardrails.push(`score-drop:${category}`); }
      else if (y.scores[category].values.some((v, i) => v < x.scores[category].values[i])) { uncertain = true; guardrails.push(`intermittent-score-drop:${category}`); }
      const base = x.audits.map(row => row[category]), candidate = y.audits.map(row => row[category]);
      const ids = new Set(base.flat().map(r => r.id));
      for (const sample of candidate) if (!equal([...ids].sort(), sample.map(r => r.id).sort())) { uncertain = true; guardrails.push(`audit-coverage:${category}`); }
      for (const auditId of new Set([...ids, ...candidate.flat().map(r => r.id)])) {
        const old = base.map(row => row.find(r => r.id === auditId));
        const next = candidate.map(row => row.find(r => r.id === auditId));
        const worse = next.map((n, i) => n && ((n.score !== null && n.score < 1 && (!old[i] || old[i].score === null || n.score < old[i].score))));
        if (worse.some(Boolean)) {
          guardrails.push(`audit-worse:${category}:${auditId}`);
          if (worse.every(Boolean)) regression = true; else uncertain = true;
        }
        if (old.some((o, i) => o && next[i] && o.mode !== next[i].mode)) { uncertain = true; guardrails.push(`audit-mode:${category}:${auditId}`); }
      }
    }
    const tx = a.cases[c].ttfb.map(x => x.value), ty = b.cases[c].ttfb.map(x => x.value);
    let ttfb = { unavailable: 'not exposed in every baseline/candidate sample' };
    if (tx.every(finite) && ty.every(finite)) {
      const x = distribution(tx), y = distribution(ty), noise = calibration?.noise[c].ttfb;
      const threshold = Math.max(100, .15 * x.median, noise ?? 0);
      ttfb = { baseline: x, candidate: y, delta: y.median - x.median, threshold, calibrated: finite(noise), warning: y.median - x.median > threshold };
    }
    result.cases[c] = { ttfb, metrics: changes, scores: { baseline: a.cases[c].scores, candidate: b.cases[c].scores }, guardrails, diagnostics: { baseline: { ttfb: a.cases[c].ttfb, lcpDetails: a.cases[c].lcpDetails }, candidate: { ttfb: b.cases[c].ttfb, lcpDetails: b.cases[c].lcpDetails } } };
  }
  if (!calibration) result.reasons.push('valid-calibration-required');
  if (!calibration?.focal) result.reasons.push('no-preselected-focal-case');
  if (!gain) result.reasons.push('no-focal-gain');
  if (uncertain) result.reasons.push('noise-or-audit-change-needs-review');
  result.status = !calibration ? 'inconclusive' : regression ? 'regression' : gain && !uncertain ? 'improvement' : 'inconclusive';
  return result;
}
export async function calibrate(aId, bId, focal = null, root = stateRoot) {
  const fd = await lock(root);
  try {
    const a = await loadRun(aId, root), b = await loadRun(bId, root);
    const reasons = [...await current(a), ...await current(b)];
    if (reasons.length) return { status: 'incompatible', reasons };
    const data = calibrationData(a, b, focal);
    if (data.status !== 'calibrated') return data;
    const payload = { schema: 1, createdAt: new Date().toISOString(), evaluator: await instrumentHash(), a: { id: a.id, hash: a.hash }, b: { id: b.id, hash: b.hash }, ...data };
    const calibration = { ...payload, id: 'cal-' + sha256(JSON.stringify(payload)) };
    await save(root, join(root, calibration.id + '.json'), calibration);
    return calibration;
  } finally { await fd.close(); }
}
async function loadCalibration(calId, baseline, root) {
  requireThat(/^cal-[a-f0-9]{64}$/.test(calId), 'invalid-calibration-id');
  await directory(root);
  const cal = await readPrivate(join(root, calId + '.json'));
  keys(cal, ['schema', 'id', 'createdAt', 'evaluator', 'a', 'b', 'status', 'reasons', 'focal', 'noise']);
  requireThat(typeof cal.createdAt === 'string' && Number.isFinite(Date.parse(cal.createdAt)), 'invalid-calibration-time');
  keys(cal.a, ['id', 'hash']); keys(cal.b, ['id', 'hash']);
  const { id: storedId, ...payload } = cal;
  requireThat(storedId === 'cal-' + sha256(JSON.stringify(payload)), 'calibration-integrity');
  requireThat(cal.schema === 1 && cal.id === calId && cal.status === 'calibrated' && cal.evaluator === await instrumentHash(), 'invalid-calibration');
  const a = await loadRun(cal.a.id, root), b = await loadRun(cal.b.id, root);
  requireThat(a.hash === cal.a.hash && b.hash === cal.b.hash && [a.id, b.id].includes(baseline.id), 'calibration-reference');
  const expected = calibrationData(a, b, cal.focal);
  requireThat(equal(expected, { status: cal.status, reasons: cal.reasons, focal: cal.focal, noise: cal.noise }), 'calibration-integrity');
  return cal;
}
export async function compare(baselineId, candidateId, calId = null, root = stateRoot, intervention = null) {
  const a = await loadRun(baselineId, root), b = await loadRun(candidateId, root);
  const reasons = [...await current(a), ...await current(b), ...compatibility(a, b, intervention)];
  if (reasons.length) return { status: 'incompatible', reasons: [...new Set(reasons)] };
  const cal = calId ? await loadCalibration(calId, a, root) : null;
  if (cal && ![cal.a.id, cal.b.id].includes(b.id) && Date.parse(b.run.startedAt) <= Date.parse(cal.createdAt)) return { status: 'incompatible', reasons: ['candidate-predates-frozen-calibration'] };
  return evaluate(a, b, cal, intervention);
}
export async function baselineSet(runId, root = stateRoot) {
  const fd = await lock(root);
  try {
    const run = await loadRun(runId, root), reasons = await current(run);
    if (reasons.length) return { status: 'incompatible', reasons };
    const value = { schema: 1, run: run.id, hash: run.hash, evaluator: await instrumentHash() };
    const temp = join(root, '.baseline-' + randomUUID());
    await save(root, temp, value); await rename(temp, join(root, 'baseline.json'));
    const dir = await open(root, 'r'); try { await dir.sync(); } finally { await dir.close(); }
    return { status: 'baseline-set', ...value };
  } finally { await fd.close(); }
}
export async function list(root = stateRoot) {
  try { await directory(root); } catch (e) { if (e.code === 'ENOENT') return { count: 0, runs: [] }; throw e; }
  const runs = [];
  for (const name of (await readdir(root)).sort()) {
    if (!idPattern.test(name) || !(await lstat(join(root, name))).isDirectory()) continue;
    try { await lstat(join(root, name, 'manifest.json')); } catch (e) { if (e.code === 'ENOENT') continue; throw e; }
    try { const run = await loadRun(name, root); runs.push({ id: name, status: 'complete', hash: run.hash }); }
    catch { runs.push({ id: name, status: 'invalid-or-incomplete' }); }
  }
  return { count: runs.length, runs };
}
export async function show(runId, root = stateRoot) {
  const run = await loadRun(runId, root);
  return { id: run.id, hash: run.hash, status: 'complete', cases: run.cases, compatibility: await current(run), limitation: 'Laboratory only. TBT is not INP. Functional/visual approval not measured. noindex retained.' };
}
export const exitCode = status => ({ regression: 3, inconclusive: 4, incompatible: 5 }[status] || 0);
