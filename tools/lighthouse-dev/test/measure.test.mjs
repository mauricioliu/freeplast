import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, rm, readdir, stat } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { collect, schedule, instrumentHash } from '../src/measure.mjs';
import { allocationInput, requestFor } from './budget-fixture.mjs';
import { initializeBudget, allowance, plan } from '../src/budget.mjs';
import { readPrivate } from '../src/store.mjs';
import { profiles } from '../src/config.mjs';
import { SafetyError, origin, routes, measureMode, measureUrl, activeMode } from '../src/policy.mjs';

import { pngWith } from './png-fixture.mjs';
const captureFor = item => {
  const { width, height, deviceScaleFactor } = profiles[item.profile].screenEmulation;
  const snap = { innerWidth: width, innerHeight: height, deviceScaleFactor, visualWidth: width, visualHeight: height, visualScale: 1, userAgent: profiles[item.profile].emulatedUserAgent };
  const pixels = { width: Math.round(width * deviceScaleFactor), height: Math.round(height * deviceScaleFactor) };
  return { png: pngWith(pixels.width, pixels.height).toString('base64'), format: 'png', source: 'owned-audit-visit', at: new Date().toISOString(), geometry: { width, height, deviceScaleFactor }, observed: [snap, snap], pixels };
};
async function fixture(overrides = {}) {
  const root = await mkdtemp(join(tmpdir(), 'lh-collection-'));
  const environment = { instrument: 'a'.repeat(64), configHash: 'b'.repeat(64), mode: activeMode(), source: { sourceHash: 'c'.repeat(64) }, addresses: ['127.0.0.1'], pages: routes.map(path => ({ path, contentHash: 'same', headers: {}, mode: { ...activeMode(), markers: 2 } })) };
  let active = 0, maximum = 0, audits = 0, pauses = 0, captureFlags = [];
  const config = {
    root, label: 'synthetic', request: requestFor(), now: () => plan.started,
    inspectRun: async () => environment, currentSource: async () => environment.source, currentAddresses: async () => environment.addresses,
    preflightRun: async () => ({ contentHash: 'same', headers: {}, mode: { ...activeMode(), markers: 2 } }),
    auditRun: async (item, env, fd, budget) => {
      active++; maximum = Math.max(maximum, active); audits++; captureFlags.push(budget.capture);
      await new Promise(resolve => setTimeout(resolve, 1)); active--;
      return { lhr: { finalDisplayedUrl: measureUrl(origin, item.path) }, html: '<html>synthetic</html>', summary: {}, security: { active: true }, settings: {}, launch: {}, ...(budget.capture ? { capture: captureFor(item) } : {}) };
    },
    pause: async ms => { assert.equal(ms, 5000); pauses++; }, ...overrides,
  };
  try {
    await initializeBudget(allocationInput, root, plan.started);
    const result = await collect(config);
    const run = await readPrivate(join(result.evidence, 'manifest.json'));
    const needle = Buffer.from(pngWith(412 * 2, 823 * 2).toString('base64'));
    const { readFile } = await import('node:fs/promises');
    // Privacy: raw capture pixels must exist only in .capture.png sidecars.
    const leaks = [];
    for (const ref of run.artifacts.filter(x => !x.path.endsWith('.capture.png'))) {
      const data = await readFile(join(result.evidence, ref.path.split('/').slice(1).join('/')));
      if (data.includes(needle)) leaks.push(ref.path);
    }
    return { result, run, maximum, audits, pauses, captureFlags, captureLeaks: leaks, permissions: await Promise.all((await readdir(result.evidence)).map(async name => (await stat(join(result.evidence, name))).mode & 0o777)) };
  } finally { await rm(root, { recursive: true, force: true }); }
}
test('fixed 18-run sequence is fully sequential; reports/checksums/private summaries and captures', async () => {
  const { result, run, maximum, audits, pauses, captureFlags, captureLeaks, permissions } = await fixture();
  assert.equal(result.status, 'complete'); assert.equal(audits, 18); assert.equal(maximum, 1); assert.equal(pauses, 17);
  assert.deepEqual(run.audits.map(({ round, profile, path }) => ({ round, profile, path })), schedule());
  assert.equal(run.slot, 1); assert.equal(run.captureAllowance, 6);
  assert.deepEqual(captureFlags, schedule().map(item => item.round === 1));
  assert.ok(run.audits.every(row => row.requestedUrl === measureUrl(origin, row.path)));
  assert.ok(run.environment.pages.every(page => page.mode.active === true && page.mode.markers > 0));
  assert.deepEqual(run.environment.mode, activeMode());
  assert.equal(run.artifacts.length, 45); // 18x(json+html) + 6 captures + 3 summaries
  assert.ok(run.artifacts.every(x => /^[a-f0-9]{64}$/.test(x.sha256)));
  const captures = run.artifacts.filter(x => x.path.endsWith('.capture.png'));
  assert.equal(captures.length, 6);
  assert.ok(run.audits.filter(x => x.round === 1).every((row, i) => row.capture && row.capture.sha256 === captures[i].sha256 && row.capture.source === 'owned-audit-visit'));
  assert.ok(run.audits.filter(x => x.round !== 1).every(row => !row.capture));
  assert.ok(permissions.every(x => x === 0o600));
  assert.deepEqual(captureLeaks, []);
  assert.equal(await instrumentHash(), await instrumentHash());
});
test('first failure stops; no retries; incomplete audit and existing evidence retained', async () => {
  let calls = 0;
  const { result, run } = await fixture({ auditRun: async () => { calls++; throw new SafetyError('synthetic-failure'); } });
  assert.equal(result.status, 'incomplete'); assert.equal(result.error, 'synthetic-failure'); assert.equal(calls, 1);
  assert.equal(run.audits.length, 1); assert.equal(run.audits[0].status, 'incomplete');
  assert.equal(run.artifacts.length, 3);
});
test('source/DNS/content drift, abort and deadline invalidate without audit', async () => {
  for (const change of [
    { currentSource: async () => ({ changed: true }) },
    { currentAddresses: async () => ['127.0.0.2'] },
    { preflightRun: async () => ({ contentHash: 'changed', headers: {} }) },
    { signal: AbortSignal.abort() },
    { now: (() => { let calls = 0; return () => plan.started + (calls++ ? 45 * 60 * 1000 : 0); })() },
  ]) {
    const { result, audits } = await fixture(change);
    assert.equal(result.status, 'incomplete'); assert.equal(audits, 0);
  }
});
test('failed preflight preserves safe hashes and mismatch fields without body or headers', async () => {
  for (const kind of ['body', 'headers']) {
    const { run, audits } = await fixture({ preflightRun: async () => ({ contentHash: kind === 'body' ? 'changed' : 'same', headers: kind === 'headers' ? { 'cache-control': 'SECRET_HEADER' } : {}, html: 'SECRET_BODY' }) });
    assert.equal(audits, 0); assert.equal(run.failure, 'public-content-drift');
    assert.equal(run.failedPreflight.path, '/'); assert.equal(run.failedPreflight.profile, 'mobile');
    assert.ok(run.failedPreflight.mismatchKeys.includes(kind === 'body' ? 'contentHash' : 'headers.cache-control'));
    assert.match(run.failedPreflight.observed.headersHash, /^[a-f0-9]{64}$/);
    assert.doesNotMatch(JSON.stringify(run), /SECRET_/);
  }
});
test('successful subprocess without report cannot yield successful collection', async () => {
  const { result, run } = await fixture({ auditRun: async () => ({}) });
  assert.equal(result.status, 'incomplete'); assert.equal(run.audits[0].status, 'incomplete');
});
test('expected but missing/invalid capture fails the run; never silent success', async () => {
  const missing = await fixture({ auditRun: async (item, env, fd, budget) => ({ lhr: { finalDisplayedUrl: measureUrl(origin, item.path) }, html: '<html>synthetic</html>', summary: {}, security: { active: true }, settings: {}, launch: {} }) });
  assert.equal(missing.result.status, 'incomplete'); assert.equal(missing.run.failure, 'capture-missing');
  assert.equal(missing.run.audits[0].status, 'incomplete');
  const invalid = await fixture({ auditRun: async (item, env, fd, budget) => ({ lhr: { finalDisplayedUrl: measureUrl(origin, item.path) }, html: '<html>synthetic</html>', summary: {}, security: { active: true }, settings: {}, launch: {}, ...(budget.capture ? { capture: { ...captureFor(item), png: Buffer.from('notpng').toString('base64') } } : {}) }) });
  assert.equal(invalid.result.status, 'incomplete'); assert.equal(invalid.run.failure, 'capture-missing');
  const shifted = () => { const c = captureFor(schedule()[0]); const bad = { ...c.observed[0], innerWidth: c.observed[0].innerWidth + 40, visualWidth: c.observed[0].visualWidth + 40 }; return { ...c, observed: [bad] }; };
  const inconsistent = await fixture({ auditRun: async (item, env, fd, budget) => ({ lhr: { finalDisplayedUrl: measureUrl(origin, item.path) }, html: '<html>synthetic</html>', summary: {}, security: { active: true }, settings: {}, launch: {}, ...(budget.capture ? { capture: shifted() } : {}) }) });
  assert.equal(inconsistent.result.status, 'incomplete'); assert.equal(inconsistent.run.failure, 'capture-missing');
});
