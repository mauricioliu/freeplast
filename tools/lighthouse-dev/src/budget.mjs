import { mkdir, readdir, lstat } from 'node:fs/promises';
import { join } from 'node:path';
import { stateRoot, lock, privateDirectory, save, readPrivate } from './store.mjs';
import { deny } from './policy.mjs';

async function exists(path) {
  try { await lstat(path); return true; } catch (e) { if (e.code === 'ENOENT') return false; throw e; }
}
// No renewal/reset: old runs, including failed pre-upgrade attempts, require a separate authorization path.
export async function initializeBudget(root = stateRoot, now = Date.now()) {
  const fd = await lock(root);
  try {
    const dir = join(root, 'measurement-budget');
    if (await exists(dir)) deny('measurement-budget-already-exists');
    for (const name of await readdir(root)) {
      const path = join(root, name), st = await lstat(path);
      if (st.isSymbolicLink()) deny('state-symlink');
      if (st.isDirectory() && await exists(join(path, 'manifest.json'))) deny('historical-measurement-state');
    }
    if (await exists(join(root, 'manual-cycle'))) deny('existing-cycle-requires-authorization');
    await mkdir(dir, { mode: 0o700 });
    await save(root, join(dir, 'allocation.json'), { schema: 1, started: now, deadline: now + 90 * 60 * 1000, maxAudits: 36 });
    return { status: 'measurement-budget-initialized', deadline: new Date(now + 90 * 60 * 1000).toISOString(), audits: 36 };
  } finally { await fd.close(); }
}
// Caller holds the permanent runner lock throughout reservation and collection.
export async function reserveMeasurement(root, now) {
  const dir = join(root, 'measurement-budget');
  if (!await exists(dir)) deny('measurement-budget-required');
  await privateDirectory(dir);
  const a = await readPrivate(join(dir, 'allocation.json'));
  if (a.schema !== 1 || a.maxAudits !== 36 || !Number.isFinite(a.started) || a.deadline !== a.started + 90 * 60 * 1000 || now < a.started || now >= a.deadline) deny('measurement-budget-exhausted');
  let deadline = a.deadline;
  const cycleDir = join(root, 'manual-cycle');
  if (await exists(cycleDir)) {
    await privateDirectory(cycleDir);
    const c = await readPrivate(join(cycleDir, 'budget.json'));
    if (c.iterations !== 1 || !Number.isFinite(c.started) || c.deadline !== c.started + 90 * 60 * 1000 || now < c.started || now >= c.deadline || await exists(join(cycleDir, 'decision.json')) || await exists(join(cycleDir, 'recovery-start.json'))) deny('cycle-budget-exhausted');
    deadline = Math.min(deadline, c.deadline);
  }
  for (const slot of [1, 2]) {
    const reservation = join(dir, `series-${slot}.json`), result = join(dir, `result-${slot}.json`);
    if (await exists(reservation)) {
      if (!await exists(result) || (await readPrivate(result)).status !== 'complete') deny('prior-measurement-incomplete-or-uncertain');
      continue;
    }
    if (now + 120000 > deadline) deny('measurement-budget-exhausted');
    await save(root, reservation, { schema: 1, slot, auditsReserved: 18, started: now });
    return { result, deadline };
  }
  deny('measurement-budget-exhausted');
}
export async function finishMeasurement(root, reservation, status) {
  await save(root, reservation.result, { status: status === 'complete' ? 'complete' : 'incomplete' });
}
