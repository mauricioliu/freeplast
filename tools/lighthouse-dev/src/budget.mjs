import { mkdir, readdir, lstat, readFile, open } from 'node:fs/promises';
import { join } from 'node:path';
import { isDeepStrictEqual as equal } from 'node:util';
import { stateRoot, lock, privateDirectory, save, readPrivate } from './store.mjs';
import { sha256 } from './preflight.mjs';
import { deny } from './policy.mjs';

export const plan = Object.freeze({ id: 'plan-mvl2p6uk-wka1', hash: '78a966b9c01b4097a6bfbb48ef4b0957212b4b6ad618577dbe6388b74e37cf9f', started: Date.parse('2026-10-23T17:27:07Z'), deadline: Date.parse('2026-10-24T05:27:07Z') });
export const allowance = Object.freeze({ schema: 3, maxAudits: 240, series: 13, seriesAudits: 18, maxCaptures: 96, maxDoctors: 10, hypotheses: 4, seriesWindowMs: 45 * 60 * 1000, auditBudgetMs: 120000 });
const hash = x => typeof x === 'string' && /^[a-f0-9]{64}$/.test(x);
const operator = x => typeof x === 'string' && /^[\w.@-]{1,100}$/.test(x);
const dirFor = root => join(root, 'measurement-budget-v3-' + plan.id);
async function exists(path) { try { await lstat(path); return true; } catch (e) { if (e.code === 'ENOENT') return false; throw e; } }
async function allocation(root, now) {
  const dir = dirFor(root);
  if (!await exists(dir)) deny('measurement-budget-required');
  await privateDirectory(dir);
  const a = await readPrivate(join(dir, 'allocation.json'));
  if (!equal(a.allowance, allowance) || !equal(a.plan, plan) || !operator(a.operator)) deny('measurement-budget-invalid');
  if (!Number.isFinite(now) || now < plan.started || now >= plan.deadline) deny('measurement-budget-exhausted');
  return dir;
}
// New versioned allocation only; all legacy allocations/receipts/runs remain untouched.
export async function initializeBudget({ planId, planHash, operator: who, deadline }, root = stateRoot, now = Date.now()) {
  if (planId !== plan.id || planHash !== plan.hash) deny('allocation-plan-invalid');
  if (!operator(who)) deny('allocation-operator-invalid');
  if (Date.parse(deadline) !== plan.deadline || now < plan.started || now >= plan.deadline) deny('allocation-deadline-invalid');
  const fd = await lock(root);
  try {
    const dir = dirFor(root);
    if (await exists(dir)) deny('measurement-budget-already-exists');
    for (const name of await readdir(root)) if ((await lstat(join(root, name))).isSymbolicLink()) deny('state-symlink');
    await mkdir(dir, { mode: 0o700 });
    await save(root, join(dir, 'allocation.json'), { allowance, plan, operator: who, installedAt: now });
    const parent = await open(root, 'r');
    try { await parent.sync(); } finally { await parent.close(); }
    return { status: 'measurement-budget-initialized', plan, allowance };
  } finally { await fd.close(); }
}
export function checkSeriesRequest(r) {
  if (!r || Object.keys(r).sort().join(',') !== 'captures,compatibility,generation,hypothesis,purpose' || !['baseline-a1', 'baseline-a2', 'candidate', 'technical-retry', 'rebaseline-a1', 'rebaseline-a2', 'reserve'].includes(r.purpose)
    || !Number.isInteger(r.generation) || r.generation < 1 || ![0, 6, 18].includes(r.captures)
    || !r.compatibility || !['instrument', 'configHash', 'sourceHash'].every(k => hash(r.compatibility[k]))
    || Object.keys(r.compatibility).length !== 3 || !Number.isInteger(r.hypothesis) || r.hypothesis < 0 || r.hypothesis > allowance.hypotheses
    || (r.purpose === 'candidate' && r.hypothesis === 0)) deny('series-purpose-invalid');
}
async function prior(root, dir) {
  const rows = [];
  for (let slot = 1; slot <= allowance.series; slot++) {
    const path = join(dir, `series-${slot}.json`);
    if (!await exists(path)) {
      // Gaps cannot be used to reset consumed capacity.
      if ((await readdir(dir)).some(n => /^series-\d+\.json$/.test(n) && Number(n.match(/\d+/)[0]) > slot)) deny('measurement-budget-invalid');
      break;
    }
    const r = await readPrivate(path); checkSeriesRequest(r.request);
    if (r.schema !== 3 || r.planId !== plan.id || r.slot !== slot || r.auditsReserved !== 18 || r.started < plan.started || r.deadline !== Math.min(r.started + allowance.seriesWindowMs, plan.deadline)) deny('measurement-budget-invalid');
    const resultPath = join(dir, `result-${slot}.json`);
    const result = await exists(resultPath) ? await readPrivate(resultPath) : null;
    if (result && (result.reservationHash !== sha256(await readFile(path)) || !['complete', 'incomplete'].includes(result.status))) deny('measurement-budget-invalid');
    const recoveryPath = join(dir, `recovery-${slot}.json`);
    const recovery = await exists(recoveryPath) ? await readPrivate(recoveryPath) : null;
    if (result?.status !== 'complete') {
      if (!recovery) deny('prior-measurement-incomplete-or-uncertain');
      if (recovery.reservationHash !== sha256(await readFile(path)) || recovery.resultHash !== (result ? sha256(await readFile(resultPath)) : null)) deny('recovery-evidence-drift');
      await checkRecoveryEvidence(root, recovery);
    }
    rows.push({ ...r, result, recovery });
  }
  return rows;
}
// Caller owns the permanent lock. Reservations charge all 18 slots, even partials.
export async function reserveMeasurement(root, now, request) {
  const dir = await allocation(root, now); checkSeriesRequest(request);
  const rows = await prior(root, dir), last = rows.at(-1);
  if (rows.length >= allowance.series || (rows.length + 1) * 18 > allowance.maxAudits || rows.reduce((n, r) => n + r.request.captures, 0) + request.captures > allowance.maxCaptures || now + allowance.auditBudgetMs > plan.deadline) deny('measurement-budget-exhausted');
  if (last?.result?.status !== 'complete' && last) {
    if (request.purpose !== 'technical-retry' || !equal(request, last.recovery.next)) deny('recovery-request-mismatch');
  } else if (request.purpose === 'technical-retry') deny('recovery-required');
  if (request.purpose === 'candidate' && rows.some(r => r.request.purpose === 'candidate' && r.request.hypothesis === request.hypothesis)) deny('hypothesis-already-attempted');
  const generation = rows.filter(r => r.request.generation === request.generation);
  if (generation.some(r => ['instrument', 'configHash'].some(k => r.request.compatibility[k] !== request.compatibility[k]))) deny('generation-incompatible');
  if (last && (request.generation < last.request.generation || request.generation > last.request.generation + 1)) deny('generation-incompatible');
  if (!last && request.generation !== 1) deny('generation-incompatible');
  if (last && request.generation !== last.request.generation && equal(request.compatibility, last.request.compatibility)) deny('generation-unchanged');
  if (request.purpose !== 'technical-retry' && generation.some(r => r.request.purpose === request.purpose && r.request.hypothesis === request.hypothesis)) deny('series-already-attempted');
  const slot = rows.length + 1, path = join(dir, `series-${slot}.json`);
  const reservation = { schema: 3, planId: plan.id, slot, request, auditsReserved: 18, started: now, deadline: Math.min(now + allowance.seriesWindowMs, plan.deadline) };
  await save(root, path, reservation);
  return { ...reservation, path, result: join(dir, `result-${slot}.json`), captures: request.captures };
}
export async function reserveAudit(root, reservation, number, capture, now) {
  if (!Number.isInteger(number) || number < 1 || number > 18 || now < reservation.started || now + allowance.auditBudgetMs > reservation.deadline) deny('audit-budget-exhausted');
  const expectedCapture = reservation.captures === 18 || reservation.captures === 6 && number <= 6;
  if (capture !== expectedCapture) deny('capture-budget-exhausted');
  await save(root, join(dirFor(root), `audit-${reservation.slot}-${number}.json`), { planId: plan.id, slot: reservation.slot, number, capture, started: now });
}
export async function finishMeasurement(root, reservation, status, failure = null) {
  await save(root, reservation.result, { status: status === 'complete' ? 'complete' : 'incomplete', failure, reservationHash: sha256(await readFile(reservation.path)) });
}
async function checkRecoveryEvidence(root, r) {
  const fields = ['planId', 'planHash', 'slot', 'operator', 'technicalFailure', 'effectsSafe', 'forbiddenContacts', 'ownedProcessesQuiescent', 'reservationHash', 'resultHash', 'evidence', 'next', 'reconciledAt'];
  if (!r || Object.keys(r).some(k => !fields.includes(k))) deny('recovery-invalid');
  if (!operator(r.operator) || r.planId !== plan.id || r.planHash !== plan.hash || r.effectsSafe !== true || r.forbiddenContacts !== 0 || r.ownedProcessesQuiescent !== true || r.technicalFailure !== true
    || !hash(r.reservationHash) || !(r.resultHash === null || hash(r.resultHash)) || !r.evidence || Object.keys(r.evidence).sort().join(',') !== 'cause,quiescence,repair,tests' || !['cause', 'repair', 'tests', 'quiescence'].every(k => r.evidence[k])) deny('recovery-invalid');
  checkSeriesRequest(r.next);
  if (r.next.purpose !== 'technical-retry') deny('recovery-invalid');
  for (const ref of Object.values(r.evidence)) {
    if (!ref || Object.keys(ref).sort().join(',') !== 'path,sha256' || typeof ref.path !== 'string' || !/^[\w/-]+\.json$/.test(ref.path) || ref.path.startsWith('/') || ref.path.split('/').includes('..') || !hash(ref.sha256)) deny('recovery-invalid');
    await privateDirectory(join(root, ref.path, '..'));
    await readPrivate(join(root, ref.path));
    if (sha256(await readFile(join(root, ref.path))) !== ref.sha256) deny('recovery-evidence-drift');
  }
}
// Explicit custodied technical recovery; does not alter result or refund capacity.
export async function reconcileMeasurement(input, root = stateRoot, now = Date.now()) {
  const fd = await lock(root);
  try {
    const dir = await allocation(root, now);
    if (!input || !Number.isInteger(input.slot) || input.slot < 1 || input.slot > allowance.series) deny('recovery-invalid');
    await checkRecoveryEvidence(root, input);
    const path = join(dir, `series-${input.slot}.json`), resultPath = join(dir, `result-${input.slot}.json`);
    const result = await exists(resultPath) ? await readPrivate(resultPath) : null;
    if (result?.status === 'complete' || await exists(join(dir, `series-${input.slot + 1}.json`))) deny('recovery-not-technical');
    if (input.reservationHash !== sha256(await readFile(path)) || input.resultHash !== (result ? sha256(await readFile(resultPath)) : null)) deny('recovery-evidence-drift');
    // Explicit contact/uncertainty outcomes cannot be replayed by an attestation.
    if (result?.failure && /forbidden-contact|uncertain-effect|escaped/.test(result.failure)) deny('recovery-unsafe');
    await save(root, join(dir, `recovery-${input.slot}.json`), { ...input, reconciledAt: now });
    return { status: 'technical-recovery-recorded', slot: input.slot, next: input.next };
  } finally { await fd.close(); }
}
export async function reserveDoctor(root, now) {
  const dir = await allocation(root, now);
  for (let slot = 1; slot <= allowance.maxDoctors; slot++) {
    const path = join(dir, `doctor-${slot}.json`);
    if (await exists(path)) continue;
    await save(root, path, { planId: plan.id, slot, started: now });
    return join(dir, `doctor-result-${slot}.json`);
  }
  deny('doctor-budget-exhausted');
}
