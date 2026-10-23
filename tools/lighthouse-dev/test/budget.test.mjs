import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, rm, readFile, mkdir, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { initializeBudget, reserveMeasurement, reserveAudit, finishMeasurement, reconcileMeasurement, reserveDoctor, plan } from '../src/budget.mjs';
import { save } from '../src/store.mjs';
import { sha256 } from '../src/preflight.mjs';
import { allocationInput, requestFor } from './budget-fixture.mjs';
const now = plan.started + 1000;
async function setup(t) {
  const root = await mkdtemp(join(tmpdir(), 'lh-budget-v3-')); t.after(() => rm(root, { recursive: true, force: true }));
  await initializeBudget(allocationInput, root, plan.started); return root;
}
async function recovery(root, reservation) {
  const evidence = {};
  for (const kind of ['cause', 'repair', 'tests', 'quiescence']) {
    const ref = await save(root, join(root, kind + '.json'), { synthetic: true, kind, result: 'verified by fixture' });
    evidence[kind] = { path: ref.path, sha256: ref.sha256 };
  }
  let resultHash = null; try { resultHash = sha256(await readFile(reservation.result)); } catch (e) { if (e.code !== 'ENOENT') throw e; }
  return { planId: plan.id, planHash: plan.hash, slot: reservation.slot, operator: 'fixture', technicalFailure: true, effectsSafe: true, forbiddenContacts: 0, ownedProcessesQuiescent: true, reservationHash: sha256(await readFile(reservation.path)), resultHash, evidence, next: { ...requestFor(), purpose: 'technical-retry', generation: 2, compatibility: { ...requestFor().compatibility, instrument: 'd'.repeat(64) } } };
}
test('technical recovery preserves failure bytes, charges partial series, binds next repaired generation', async t => {
  const root = await setup(t), r = await reserveMeasurement(root, now, requestFor());
  await reserveAudit(root, r, 1, true, now);
  await finishMeasurement(root, r, 'incomplete', 'capture-integrity');
  const old = await readFile(r.result), input = await recovery(root, r);
  await assert.rejects(reserveMeasurement(root, now, requestFor()), { code: 'prior-measurement-incomplete-or-uncertain' });
  await reconcileMeasurement(input, root, now);
  await assert.rejects(reserveMeasurement(root, now, requestFor()), { code: 'recovery-request-mismatch' });
  const next = await reserveMeasurement(root, now, input.next);
  assert.equal(next.slot, 2); assert.equal(next.auditsReserved, 18);
  assert.deepEqual(await readFile(r.result), old);
  await assert.rejects(reconcileMeasurement(input, root, now), { code: 'recovery-not-technical' });
});
test('crash pending receipt requires custodied safe recovery; missing/stale proof cannot reopen', async t => {
  const root = await setup(t), r = await reserveMeasurement(root, now, requestFor()), input = await recovery(root, r);
  for (const change of [{ effectsSafe: false }, { forbiddenContacts: 1 }, { ownedProcessesQuiescent: false }, { technicalFailure: false }, { resultHash: 'a'.repeat(64) }, { planHash: '0'.repeat(64) }]) await assert.rejects(reconcileMeasurement({ ...input, ...change }, root, now));
  await writeFile(join(root, 'tests.json'), '{}', { mode: 0o600 });
  await assert.rejects(reconcileMeasurement(input, root, now), { code: 'recovery-evidence-drift' });
  input.evidence.tests.sha256 = sha256(await readFile(join(root, 'tests.json')));
  await reconcileMeasurement(input, root, now);
  assert.equal((await reserveMeasurement(root, now, input.next)).slot, 2);
});
test('successful or forbidden-contact outcomes cannot be reconciled for nicer scores', async t => {
  for (const [status, failure] of [['complete', null], ['incomplete', 'forbidden-contact'], ['incomplete', 'uncertain-effect']]) {
    const root = await setup(t), r = await reserveMeasurement(root, now, requestFor());
    await finishMeasurement(root, r, status, failure);
    await assert.rejects(reconcileMeasurement(await recovery(root, r), root, now));
  }
});
test('capture ceiling 96 and audit starts survive replay; eighteen capture series are supported', async t => {
  const root = await setup(t);
  for (let i = 0; i < 6; i++) {
    const request = { ...requestFor(), captures: i === 5 ? 6 : 18, generation: i + 1, compatibility: { ...requestFor().compatibility, sourceHash: String(i).padStart(64, '0') } };
    const r = await reserveMeasurement(root, now, request);
    await reserveAudit(root, r, 1, true, now);
    await assert.rejects(reserveAudit(root, r, 1, true, now), { code: 'EEXIST' });
    await assert.rejects(reserveAudit(root, r, 19, false, now), { code: 'audit-budget-exhausted' });
    await finishMeasurement(root, r, 'complete');
  }
  await assert.rejects(reserveMeasurement(root, now, { ...requestFor(), generation: 7 }), { code: 'measurement-budget-exhausted' });
});
test('doctor starts charge before effects, never renew across process-style calls or deadline', async t => {
  const root = await setup(t);
  for (let i = 0; i < 10; i++) assert.match(await reserveDoctor(root, now), new RegExp(`doctor-result-${i + 1}\\.json$`));
  await assert.rejects(reserveDoctor(root, now), { code: 'doctor-budget-exhausted' });
  await assert.rejects(reserveDoctor(root, plan.deadline), { code: 'measurement-budget-exhausted' });
});
test('schema2 history and receipts remain byte-identical; source/config generation drift cannot mix', async t => {
  const root = await mkdtemp(join(tmpdir(), 'lh-history-v3-')); t.after(() => rm(root, { recursive: true, force: true }));
  await mkdir(join(root, 'measurement-budget-v2'), { mode: 0o700 });
  const old = new Map();
  for (const path of ['measurement-budget-v2/allocation.json', 'identity.json', 'security-gate.json']) { const data = JSON.stringify({ historical: path }); old.set(path, data); await writeFile(join(root, path), data, { mode: 0o600 }); }
  await initializeBudget(allocationInput, root, now);
  for (const [path, data] of old) assert.equal(await readFile(join(root, path), 'utf8'), data);
  const r = await reserveMeasurement(root, now, requestFor()); await finishMeasurement(root, r, 'complete');
  await assert.rejects(reserveMeasurement(root, now, requestFor()), { code: 'series-already-attempted' });
  await assert.rejects(reserveMeasurement(root, now, { ...requestFor(), purpose: 'baseline-a2', compatibility: { ...requestFor().compatibility, configHash: 'd'.repeat(64) } }), { code: 'generation-incompatible' });
  await assert.rejects(reserveMeasurement(root, now, { ...requestFor(), generation: 2 }), { code: 'generation-unchanged' });
  await assert.rejects(reserveMeasurement(root, now, { ...requestFor(), hypothesis: 5 }), { code: 'series-purpose-invalid' });
});
