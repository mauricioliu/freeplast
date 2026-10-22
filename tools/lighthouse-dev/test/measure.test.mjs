import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, rm, readdir, stat } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { collect, schedule, instrumentHash } from '../src/measure.mjs';
import { readPrivate } from '../src/store.mjs';
import { SafetyError, origin, routes } from '../src/policy.mjs';

async function fixture(overrides = {}) {
  const root = await mkdtemp(join(tmpdir(), 'lh-collection-'));
  const environment = { source: { sourceHash: 'unchanged' }, addresses: ['127.0.0.1'], pages: routes.map(path => ({ path, contentHash: 'same', headers: {} })) };
  let active = 0, maximum = 0, audits = 0, pauses = 0;
  const config = {
    root, label: 'synthetic', inspectRun: async () => environment, currentSource: async () => environment.source, currentAddresses: async () => environment.addresses,
    preflightRun: async () => ({ contentHash: 'same', headers: {} }),
    auditRun: async item => { active++; maximum = Math.max(maximum, active); audits++; await new Promise(resolve => setTimeout(resolve, 1)); active--; return { lhr: { finalDisplayedUrl: origin + item.path }, html: '<html>synthetic</html>', summary: {}, security: { active: true }, settings: {}, launch: {} }; },
    pause: async ms => { assert.equal(ms, 5000); pauses++; }, ...overrides,
  };
  try {
    const result = await collect(config);
    const run = await readPrivate(join(result.evidence, 'manifest.json'));
    return { result, run, maximum, audits, pauses, permissions: await Promise.all((await readdir(result.evidence)).map(async name => (await stat(join(result.evidence, name))).mode & 0o777)) };
  } finally { await rm(root, { recursive: true, force: true }); }
}
test('fixed 18-run sequence is fully sequential; reports/checksums/private summaries', async () => {
  const { result, run, maximum, audits, pauses, permissions } = await fixture();
  assert.equal(result.status, 'complete'); assert.equal(audits, 18); assert.equal(maximum, 1); assert.equal(pauses, 17);
  assert.deepEqual(run.audits.map(({ round, profile, path }) => ({ round, profile, path })), schedule());
  assert.equal(run.artifacts.length, 39);
  assert.ok(run.artifacts.every(x => /^[a-f0-9]{64}$/.test(x.sha256)));
  assert.ok(permissions.every(x => x === 0o600));
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
    { now: (() => { let calls = 0; return () => calls++ ? 45 * 60 * 1000 : 0; })() },
  ]) {
    const { result, audits } = await fixture(change);
    assert.equal(result.status, 'incomplete'); assert.equal(audits, 0);
  }
});
test('successful subprocess without report cannot yield successful collection', async () => {
  const { result, run } = await fixture({ auditRun: async () => ({}) });
  assert.equal(result.status, 'incomplete'); assert.equal(run.audits[0].status, 'incomplete');
});
