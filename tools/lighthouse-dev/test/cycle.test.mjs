import test from 'node:test';
import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { mkdtemp, mkdir, readFile, writeFile, rm, symlink, chmod } from 'node:fs/promises';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { cycle, acceptance } from '../src/cycle.mjs';
import { fingerprint } from '../src/fingerprint.mjs';
import { calibrate, compare } from '../src/compare.mjs';
import { report } from './cycle-fixture.mjs';
const exec = promisify(execFile), theme = 'wordpress/wp-content/themes/freeplast/';
const git = (repo, ...args) => exec('git', ['-C', repo, ...args]);
const approval = (decision = 'DISCARD') => ({ decision, learning: 'Synthetic change tested; no real application claim.', functional: { passed: true, evidence: 'tests.json' }, visual: { status: 'no-visual-change', reviewer: 'synthetic-human-fixture', evidence: 'visual.json' } });
async function setup(t, paths = [theme + 'style.css']) {
  const repo = await mkdtemp(join(tmpdir(), 'lh-cycle-')), root = await mkdtemp(join(tmpdir(), 'lh-cycle-state-'));
  t.after(() => Promise.all([repo, root].map(p => rm(p, { recursive: true, force: true }))));
  await mkdir(join(repo, theme), { recursive: true });
  for (const [p, value] of [[theme + 'style.css', 'HEAD'], [theme + 'delete.css', 'delete HEAD'], ['outside.txt', 'outside HEAD']]) await writeFile(join(repo, p), value);
  await git(repo, 'init', '-q'); await git(repo, 'add', '.'); await git(repo, '-c', 'user.name=Fixture', '-c', 'user.email=fixture@invalid', 'commit', '-qm', 'synthetic');
  await writeFile(join(repo, theme + 'style.css'), 'INDEX'); await git(repo, 'add', '.');
  await writeFile(join(repo, theme + 'style.css'), 'DIRTY PREIMAGE');
  await writeFile(join(repo, 'outside.txt'), 'unrelated dirty');
  await writeFile(join(repo, 'untracked.txt'), 'preexisting untracked');
  await writeFile(join(root, 'tests.json'), '{"synthetic":"functional assertions only"}', { mode: 0o600 });
  await writeFile(join(root, 'visual.json'), '{"synthetic":"not actual human approval; test input only"}', { mode: 0o600 });
  const source = await fingerprint(repo);
  await report(root, 'base', source); await report(root, 'aa', source);
  const cal = await calibrate('base', 'aa', 'mobile:/', root);
  const input = { repo, hypothesis: 'One synthetic CSS intervention reduces LCP.', paths, baseline: 'base', calibration: cal.id, acceptance: 'LCP gain; tests; attributed visual/content review.' };
  const index = await readFile(join(repo, '.git/index'));
  await cycle('begin', input, root);
  return { repo, root, input, source, index };
}
async function postDiscard(s) {
  await cycle('post', { tests: 'tests.json' }, s.root);
  await cycle('decide', { candidate: null, acceptance: approval() }, s.root);
}

test('exact dirty preimages, additions/deletions/modes and owned untracked restored; index and unrelated work preserved', async t => {
  const s = await setup(t, [theme + 'style.css', theme + 'delete.css', theme + 'new.css', 'untracked.txt']);
  await writeFile(join(s.repo, theme + 'style.css'), 'candidate'); await chmod(join(s.repo, theme + 'style.css'), 0o755);
  await rm(join(s.repo, theme + 'delete.css')); await writeFile(join(s.repo, theme + 'new.css'), 'new'); await writeFile(join(s.repo, 'untracked.txt'), 'owned candidate');
  await postDiscard(s);
  assert.equal((await cycle('restore', null, s.root)).status, 'restored');
  assert.equal(await readFile(join(s.repo, theme + 'style.css'), 'utf8'), 'DIRTY PREIMAGE');
  assert.equal(await readFile(join(s.repo, theme + 'delete.css'), 'utf8'), 'delete HEAD');
  await assert.rejects(readFile(join(s.repo, theme + 'new.css')), { code: 'ENOENT' });
  assert.equal(await readFile(join(s.repo, 'outside.txt'), 'utf8'), 'unrelated dirty');
  assert.equal(await readFile(join(s.repo, 'untracked.txt'), 'utf8'), 'preexisting untracked');
  assert.deepEqual(await readFile(join(s.repo, '.git/index')), s.index);
  assert.deepEqual(await fingerprint(s.repo), s.source);
  const state = await cycle('show', null, s.root); assert.equal(state['recovery-result'].receipts.length, 4);
  await assert.rejects(cycle('restore', null, s.root)); await assert.rejects(cycle('begin', s.input, s.root), { code: 'cycle-already-consumed' });
});

test('later owned, unrelated, index, untracked and symlink drift block before recovery writes', async t => {
  for (const change of ['owned', 'outside', 'index', 'untracked', 'symlink']) {
    const s = await setup(t); await writeFile(join(s.repo, theme + 'style.css'), 'candidate'); await postDiscard(s);
    if (change === 'owned') await writeFile(join(s.repo, theme + 'style.css'), 'later human work');
    if (change === 'outside') await writeFile(join(s.repo, 'outside.txt'), 'later outside work');
    if (change === 'index') await git(s.repo, 'add', theme + 'style.css');
    if (change === 'untracked') await writeFile(join(s.repo, 'new-untracked'), 'later');
    if (change === 'symlink') { await rm(join(s.repo, theme + 'style.css')); await symlink(join(s.repo, 'outside.txt'), join(s.repo, theme + 'style.css')); }
    await assert.rejects(cycle('restore', null, s.root));
    assert.equal((await cycle('show', null, s.root))['recovery-start'], undefined);
    if (change !== 'symlink') assert.equal(await readFile(join(s.repo, theme + 'style.css'), 'utf8'), change === 'owned' ? 'later human work' : 'candidate');
  }
});

test('unowned changes cannot be adopted by post; conflicts stop', async t => {
  const s = await setup(t); await writeFile(join(s.repo, 'outside.txt'), 'unowned');
  await assert.rejects(cycle('post', { tests: 'fixture' }, s.root), { code: 'cycle-unowned-drift' });
  await writeFile(join(s.repo, 'outside.txt'), 'unrelated dirty');
  const oid = (await git(s.repo, 'rev-parse', 'HEAD:' + theme + 'style.css')).stdout.trim();
  await new Promise((resolve, reject) => {
    const child = execFile('git', ['-C', s.repo, 'update-index', '--index-info'], error => error ? reject(error) : resolve());
    child.stdin.end(`0 ${'0'.repeat(40)}\t${theme}style.css\n100644 ${oid} 1\t${theme}style.css\n100644 ${oid} 2\t${theme}style.css\n`);
  });
  await assert.rejects(cycle('post', { tests: 'fixture' }, s.root), { code: 'cycle-conflict' });
});

test('partial restoration receipts remain uncertain and restart cannot replay', async t => {
  const s = await setup(t, [theme + 'style.css', theme + 'delete.css']);
  await writeFile(join(s.repo, theme + 'style.css'), 'candidate'); await rm(join(s.repo, theme + 'delete.css')); await postDiscard(s);
  await assert.rejects(cycle('restore', null, s.root, async () => { throw new Error('synthetic interruption after first write'); }));
  assert.equal(await readFile(join(s.repo, theme + 'style.css'), 'utf8'), 'DIRTY PREIMAGE');
  await assert.rejects(readFile(join(s.repo, theme + 'delete.css')));
  const state = await cycle('show', null, s.root); assert.equal(state['recovery-result'].status, 'uncertain');
  assert.ok(await readFile(join(s.root, 'manual-cycle/restore-0-pending.json')));
  await assert.rejects(cycle('restore', null, s.root));
});

test('durable 90 minute/max-one budget survives fresh process; no reset command', async t => {
  const s = await setup(t), path = join(s.root, 'manual-cycle/budget.json');
  const b = JSON.parse(await readFile(path)); b.started = Date.now() - 91 * 60 * 1000; b.deadline = b.started + 90 * 60 * 1000; await writeFile(path, JSON.stringify(b));
  const url = new URL('../src/cycle.mjs', import.meta.url).href;
  const { stdout } = await exec(process.execPath, ['--input-type=module', '-e', `import {cycle} from ${JSON.stringify(url)}; try { await cycle('post',{tests:'fixture'},${JSON.stringify(s.root)}); process.exit(2); } catch(e) { console.log(e.code); }`]);
  assert.equal(stdout.trim(), 'cycle-budget-exhausted');
  await assert.rejects(cycle('begin', s.input, s.root), { code: 'cycle-already-consumed' });
  await assert.rejects(cycle('reset', null, s.root), { code: 'cycle-action' });
  assert.deepEqual(JSON.parse(await readFile(path)), b);
});

test('DECLARED binds exact owned source; unrelated source/config/content changes remain incompatible', async t => {
  const s = await setup(t); await writeFile(join(s.repo, theme + 'style.css'), 'candidate');
  await cycle('post', { tests: 'tests.json' }, s.root);
  const after = await fingerprint(s.repo); await report(s.root, 'candidate', after, true);
  const intervention = { kind: 'DECLARED', paths: s.input.paths, before: s.source, after };
  assert.equal((await compare('base', 'candidate', s.input.calibration, s.root)).status, 'incompatible');
  assert.equal((await compare('base', 'candidate', s.input.calibration, s.root, intervention)).status, 'improvement');
  const bad = approval('KEEP'); bad.visual.status = 'not-reviewed';
  await assert.rejects(cycle('decide', { candidate: 'candidate', acceptance: bad }, s.root), { code: 'keep-not-approved' });
  assert.equal((await cycle('decide', { candidate: 'candidate', acceptance: approval('KEEP') }, s.root)).decision, 'KEEP');
  await assert.rejects(cycle('restore', null, s.root), { code: 'discard-decision-required' });
  await writeFile(join(s.repo, theme + 'unrelated.css'), 'unrelated');
  const changed = await fingerprint(s.repo); await report(s.root, 'unrelated', changed, true);
  assert.equal((await compare('base', 'unrelated', s.input.calibration, s.root, { ...intervention, after: changed })).status, 'incompatible');
  const path = join(s.root, 'candidate/manifest.json'), original = JSON.parse(await readFile(path));
  for (const field of ['configHash', 'styleHash', 'pages']) {
    const run = structuredClone(original);
    if (field === 'pages') run.environment.pages[0].headers.extra = 'changed'; else run.environment[field] = '0'.repeat(64);
    await writeFile(path, JSON.stringify(run));
    if (field === 'pages') await assert.rejects(compare('base', 'candidate', s.input.calibration, s.root, intervention));
    else assert.equal((await compare('base', 'candidate', s.input.calibration, s.root, intervention)).status, 'incompatible');
  }
});

test('uncatchable process exit leaves pending receipt; new process never replays uncertain recovery', async t => {
  const s = await setup(t, [theme + 'style.css', theme + 'delete.css']);
  await writeFile(join(s.repo, theme + 'style.css'), 'candidate'); await rm(join(s.repo, theme + 'delete.css')); await postDiscard(s);
  const url = new URL('../src/cycle.mjs', import.meta.url).href;
  await assert.rejects(exec(process.execPath, ['--input-type=module', '-e', `import {cycle} from ${JSON.stringify(url)}; await cycle('restore',null,${JSON.stringify(s.root)},async()=>process.exit(99));`]), e => e.code === 99);
  const state = await cycle('show', null, s.root);
  assert.equal(state['recovery-start'].status, 'uncertain-until-complete'); assert.equal(state['recovery-result'], undefined);
  assert.ok(await readFile(join(s.root, 'manual-cycle/restore-0-pending.json')));
  await assert.rejects(cycle('restore', null, s.root));
  await assert.rejects(readFile(join(s.repo, theme + 'delete.css')));
});

test('evidence drift and damaged preimage fail closed; timeout allows explicit discard but never KEEP', async t => {
  const s = await setup(t); await writeFile(join(s.repo, theme + 'style.css'), 'candidate');
  await cycle('post', { tests: 'tests.json' }, s.root);
  await writeFile(join(s.root, 'tests.json'), 'changed evidence');
  await assert.rejects(cycle('decide', { candidate: null, acceptance: approval() }, s.root), { code: 'test-evidence-drift' });
  await writeFile(join(s.root, 'tests.json'), '{"synthetic":"functional assertions only"}');
  const budgetPath = join(s.root, 'manual-cycle/budget.json');
  const budget = JSON.parse(await readFile(budgetPath)); budget.started = Date.now() - 91 * 60 * 1000; budget.deadline = budget.started + 90 * 60 * 1000; await writeFile(budgetPath, JSON.stringify(budget));
  await assert.rejects(cycle('decide', { candidate: null, acceptance: approval('KEEP') }, s.root), { code: 'cycle-budget-exhausted' });
  await cycle('decide', { candidate: null, acceptance: approval() }, s.root);
  const path = join(s.root, 'manual-cycle/begin.json'), begin = JSON.parse(await readFile(path)); begin.pre.files[theme + 'style.css'].data = Buffer.from('corrupted').toString('base64'); await writeFile(path, JSON.stringify(begin));
  await assert.rejects(cycle('restore', null, s.root), { code: 'cycle-preimage-integrity' });
  assert.equal(await readFile(join(s.repo, theme + 'style.css'), 'utf8'), 'candidate');
  assert.equal((await cycle('show', null, s.root))['recovery-start'], undefined);
});

test('KEEP requires numerical gain, functional proof and attributed human review; no-visual-change explicit only', () => {
  const good = approval('KEEP'); assert.equal(acceptance(good, { status: 'improvement' }).decision, 'KEEP');
  for (const status of ['regression', 'inconclusive', 'incompatible']) assert.throws(() => acceptance(good, { status }));
  for (const mutate of [x => { x.functional.passed = false; }, x => { x.functional.evidence = ''; }, x => { x.visual.reviewer = ''; }, x => { x.visual.status = 'not-reviewed'; }, x => { x.visual.status = 'rejected'; }, x => { x.learning = ''; }]) {
    const bad = structuredClone(good); mutate(bad); assert.throws(() => acceptance(bad, { status: 'improvement' }));
  }
});

test('CLI rejects invalid cycle arguments before input reading or effects', async () => {
  const cli = new URL('../bin/freeplast-lh.mjs', import.meta.url).pathname;
  const help = await exec(process.execPath, [cli, 'cycle', '--help']); assert.match(help.stdout, /begin\|post\|decide\|restore\|show/);
  for (const args of [['--action', 'reset'], ['--action', 'begin'], ['--action', 'restore', '--input', '/missing'], ['--action', 'post', '--bogus']]) {
    await assert.rejects(exec(process.execPath, [cli, 'cycle', ...args]), e => e.code === 2 && e.stdout.includes('usage'));
  }
});
