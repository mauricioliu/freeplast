import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, mkdir, writeFile, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { execFileSync, spawnSync } from 'node:child_process';
import { formatOutput, homeView } from '../src/output.mjs';
import { initializeBudget, reserveMeasurement, finishMeasurement } from '../src/budget.mjs';
import { lock } from '../src/store.mjs';
import { fingerprint } from '../src/fingerprint.mjs';
import { cycle } from '../src/cycle.mjs';
import { calibrate } from '../src/compare.mjs';
import { report } from './cycle-fixture.mjs';

async function temp(t, prefix = 'lh-correction-') {
  const dir = await mkdtemp(join(tmpdir(), prefix)); t.after(() => rm(dir, { recursive: true, force: true })); return dir;
}
const git = (repo, ...args) => execFileSync('git', ['-C', repo, ...args], { encoding: 'utf8' });
const theme = 'wordpress/wp-content/themes/freeplast/';
async function repo(t) {
  const dir = await temp(t); await mkdir(join(dir, theme), { recursive: true });
  await writeFile(join(dir, theme, 'style.css'), 'synthetic');
  git(dir, 'init', '-q'); git(dir, 'add', '.'); git(dir, '-c', 'user.name=Fixture', '-c', 'user.email=fixture@invalid', 'commit', '-qm', 'synthetic'); return dir;
}

test('default output uses flat TOON scalars for nonempty collections and nested details', () => {
  const values = { count: 1, runs: [{ id: 'one', status: 'complete' }], cases: { 'mobile:/': { median: 100 } } };
  const output = formatOutput(values);
  const rows = Object.fromEntries(output.split('\n').map(line => { const at = line.indexOf(': '); return [line.slice(0, at), JSON.parse(line.slice(at + 2))]; }));
  const home = homeView(values, '/home/fixture/tool.mjs', '/home/fixture', 'Measure DEV');
  assert.equal(home.bin, '~/tool.mjs'); assert.equal(home.description, 'Measure DEV'); assert.match(home.help, /node "\/home\/fixture\/tool.mjs" show --run <id>/);
  assert.equal(rows.count, 1); assert.equal(typeof rows.runs, 'string'); assert.match(rows.runs, /one/); assert.equal(typeof rows.cases, 'string');
  const long = formatOutput({ cases: { text: 'a'.repeat(1000) } }); assert.ok(long.length < 260); assert.match(long, /--json for full details/);
  assert.equal(JSON.stringify(values), '{"count":1,"runs":[{"id":"one","status":"complete"}],"cases":{"mobile:/":{"median":100}}}');
});

test('CLI names offending flags and missing arguments with local guidance; help gives examples', () => {
  const cli = new URL('../bin/freeplast-lh.mjs', import.meta.url).pathname;
  for (const [args, message] of [[['show', '--bad'], /--bad/], [['show'], /--run/], [['show', '--run'], /--run/], [['budget-init'], /--confirm/]]) {
    const r = spawnSync(process.execPath, [cli, ...args, '--json'], { encoding: 'utf8' });
    assert.equal(r.status, 2); const out = JSON.parse(r.stdout); assert.match(out.message, message); assert.match(out.help, /valid flags:/); assert.doesNotMatch(out.help, /doctor \[/);
  }
  for (const command of ['show', 'cycle', 'budget-init']) {
    const r = spawnSync(process.execPath, [cli, command, '--help', '--json'], { encoding: 'utf8' }); assert.equal(r.status, 0); assert.match(JSON.parse(r.stdout).usage, /examples:/);
  }
});

test('measurement allowance requires explicit initialization; third series/restart cannot replenish', async t => {
  const root = await temp(t), now = Date.now();
  await assert.rejects(reserveMeasurement(root, now), { code: 'measurement-budget-required' });
  await initializeBudget(root, now);
  for (let i = 0; i < 2; i++) {
    const fd = await lock(root);
    try { const r = await reserveMeasurement(root, now + 1); await finishMeasurement(root, r, 'complete'); } finally { await fd.close(); }
  }
  const module = new URL('../src/budget.mjs', import.meta.url).href, store = new URL('../src/store.mjs', import.meta.url).href;
  const result = spawnSync(process.execPath, ['--input-type=module', '-e', `import {reserveMeasurement} from ${JSON.stringify(module)}; import {lock} from ${JSON.stringify(store)}; const fd=await lock(${JSON.stringify(root)}); try{await reserveMeasurement(${JSON.stringify(root)},Date.now());process.exitCode=2}catch(e){console.log(e.code)}finally{await fd.close()}`], { encoding: 'utf8' });
  assert.equal(result.status, 0); assert.match(result.stdout, /measurement-budget-exhausted/);
  await assert.rejects(initializeBudget(root), { code: 'measurement-budget-already-exists' });
});

test('historical failed runs, uncertain reservations, failed attempts and expired cycles never renew', async t => {
  const historical = await temp(t); await mkdir(join(historical, 'old-a1')); await writeFile(join(historical, 'old-a1/manifest.json'), '{"status":"incomplete"}');
  await assert.rejects(initializeBudget(historical), { code: 'historical-measurement-state' });
  assert.equal(await readFile(join(historical, 'old-a1/manifest.json'), 'utf8'), '{"status":"incomplete"}');
  for (const status of [null, 'incomplete']) {
    const root = await temp(t), now = Date.now(); await initializeBudget(root, now);
    const r = await reserveMeasurement(root, now + 1); if (status) await finishMeasurement(root, r, status);
    await assert.rejects(reserveMeasurement(root, now + 2), { code: 'prior-measurement-incomplete-or-uncertain' });
  }
  const root = await temp(t), now = Date.now(); await initializeBudget(root, now);
  await assert.rejects(reserveMeasurement(root, now + 90 * 60000), { code: 'measurement-budget-exhausted' });
  await mkdir(join(root, 'manual-cycle'), { mode: 0o700 });
  await writeFile(join(root, 'manual-cycle/budget.json'), JSON.stringify({ iterations: 1, started: now - 91 * 60000, deadline: now - 60000 }), { mode: 0o600 });
  await assert.rejects(reserveMeasurement(root, now), { code: 'cycle-budget-exhausted' });
});

test('dirty/untracked supported asset bytes and case variants change fingerprint with identical Git status', async t => {
  const dir = await repo(t);
  for (const extension of ['avif', 'gif', 'ico', 'ttf', 'otf', 'mp4', 'webm', 'AVIF']) {
    const path = join(dir, theme, 'image.' + extension); await writeFile(path, 'one');
    const before = await fingerprint(dir); await writeFile(path, 'two'); const after = await fingerprint(dir);
    assert.deepEqual(before.status, after.status); assert.notEqual(before.sourceHash, after.sourceHash);
    git(dir, 'add', '.'); git(dir, '-c', 'user.name=Fixture', '-c', 'user.email=fixture@invalid', 'commit', '-qm', 'asset');
    await writeFile(path, 'dirty1'); const dirty = await fingerprint(dir); await writeFile(path, 'dirty2'); const changed = await fingerprint(dir);
    assert.deepEqual(dirty.status, changed.status); assert.notEqual(dirty.sourceHash, changed.sourceHash);
  }
});

test('read-only cycle records ordinary and linked worktrees without preimages or restoration', async t => {
  for (const linked of [false, true]) {
    const main = await repo(t); let dir = main;
    if (linked) { dir = join(await temp(t), 'linked'); git(main, 'worktree', 'add', '-qb', 'fixture-linked', dir); }
    const root = await temp(t), source = await fingerprint(dir), path = theme + 'style.css';
    await report(root, 'base', source); await report(root, 'aa', source); const cal = await calibrate('base', 'aa', 'mobile:/', root);
    await writeFile(join(root, 'tests.json'), 'synthetic test evidence', { mode: 0o600 });
    const input = { mode: 'read-only', repo: dir, hypothesis: 'Synthetic CSS', paths: [path], baseline: 'base', calibration: cal.id, acceptance: 'Functional plus human approval' };
    await assert.rejects(cycle('begin', { ...input, paths: ['commercial/private.json'] }, root), { code: 'cycle-owned-path' });
    await cycle('begin', input, root);
    await writeFile(join(dir, theme, 'unowned.css'), 'later unrelated work');
    await assert.rejects(cycle('post', { tests: 'tests.json' }, root), { code: 'cycle-unowned-drift' });
    await rm(join(dir, theme, 'unowned.css'));
    await assert.rejects(fingerprint(dir, { maxBytes: 1, maxFiles: 1 }), { code: 'source-size-or-type' });
    const begin = JSON.parse(await readFile(join(root, 'manual-cycle/begin.json')));
    assert.equal(begin.mode, 'read-only'); assert.equal(begin.pre.files[path].data, undefined);
    await writeFile(join(dir, path), 'synthetic candidate'); await cycle('post', { tests: 'tests.json' }, root);
    await report(root, 'candidate', await fingerprint(dir), true);
    const acceptance = { decision: 'KEEP', learning: 'Synthetic only', functional: { passed: true, evidence: 'tests.json' }, visual: { status: 'no-visual-change', reviewer: 'synthetic', evidence: 'tests.json' } };
    assert.equal((await cycle('decide', { candidate: 'candidate', acceptance }, root)).decision, 'KEEP');
    await assert.rejects(cycle('restore', null, root), { code: 'read-only-cycle-no-restoration' });
    assert.equal(await readFile(join(dir, path), 'utf8'), 'synthetic candidate');
  }
});
