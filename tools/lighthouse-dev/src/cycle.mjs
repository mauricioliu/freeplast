import { execFile } from 'node:child_process';
import { promisify, isDeepStrictEqual as equal } from 'node:util';
import { constants } from 'node:fs';
import { lstat, readdir, readFile, realpath, open, unlink } from 'node:fs/promises';
import { join, resolve, dirname, relative } from 'node:path';
import { tmpdir } from 'node:os';
import { stateRoot, lock, privateDirectory, save, readPrivate } from './store.mjs';
import { sha256 } from './preflight.mjs';
import { deny } from './policy.mjs';
import { fingerprint } from './fingerprint.mjs';
import { compare, loadRun } from './compare.mjs';
const exec = promisify(execFile);
const requireThat = (ok, code) => { if (!ok) deny(code); };
const text = x => typeof x === 'string' && x.trim().length > 0 && x.length <= 4000;
const git = async (repo, args) => (await exec('git', ['-C', repo, ...args], { maxBuffer: 8 * 1024 ** 2, env: { ...process.env, GIT_OPTIONAL_LOCKS: '0' } })).stdout;
const pathsValid = paths => Array.isArray(paths) && paths.length > 0 && paths.length <= 100 && new Set(paths).size === paths.length && paths.every(p => typeof p === 'string' && p.length < 500 && !p.startsWith('/') && p.split('/').every(s => s && s !== '.' && s !== '..' && s !== '.git'));
function schema(value, required) {
  requireThat(value && typeof value === 'object' && !Array.isArray(value) && Object.keys(value).length === required.length && required.every(k => Object.hasOwn(value, k)), 'cycle-schema');
}
async function fixture(repo) {
  requireThat(typeof repo === 'string', 'fixture-required');
  repo = resolve(repo);
  const rel = relative(await realpath(tmpdir()), repo);
  requireThat(/^lh-cycle-[^/]+$/.test(rel) && await realpath(repo) === repo, 'fixture-required');
  requireThat((await lstat(join(repo, '.git'))).isDirectory() && !(await lstat(join(repo, '.git'))).isSymbolicLink(), 'fixture-git-required');
  requireThat((await git(repo, ['rev-parse', '--show-toplevel'])).trim() === repo, 'fixture-git-required');
  return repo;
}
async function snapshot(repo, mode = 'fixture') {
  if (mode === 'read-only') {
    requireThat(await realpath(repo) === repo && (await git(repo, ['rev-parse', '--show-toplevel'])).trim() === repo, 'cycle-repo');
    requireThat(!(await git(repo, ['ls-files', '-u'])).trim(), 'cycle-conflict');
    for (const marker of ['MERGE_HEAD', 'CHERRY_PICK_HEAD', 'REVERT_HEAD', 'rebase-merge', 'rebase-apply']) requireThat(!await exists((await git(repo, ['rev-parse', '--path-format=absolute', '--git-path', marker])).trim()), 'cycle-conflict');
    const indexPath = (await git(repo, ['rev-parse', '--path-format=absolute', '--git-path', 'index'])).trim();
    const index = await lstat(indexPath);
    requireThat(index.isFile() && !index.isSymbolicLink() && index.nlink === 1, 'cycle-index-type');
    const source = await fingerprint(repo, { maxBytes: 64 * 1024 ** 2, maxFiles: 10000 });
    return { head: source.head, branch: source.branch, index: sha256(await readFile(indexPath)), entries: await git(repo, ['ls-files', '--stage', '-z']), status: source.status,
      files: Object.fromEntries(source.files.map(f => [f.path, { mode: f.mode, sha256: f.sha256 }])) };
  }
  await fixture(repo);
  requireThat(!(await git(repo, ['ls-files', '-u'])).trim(), 'cycle-conflict');
  for (const marker of ['MERGE_HEAD', 'CHERRY_PICK_HEAD', 'REVERT_HEAD', 'rebase-merge', 'rebase-apply']) requireThat(!await exists(join(repo, '.git', marker)), 'cycle-conflict');
  const index = await lstat(join(repo, '.git/index'));
  requireThat(index.isFile() && !index.isSymbolicLink() && index.nlink === 1, 'cycle-index-type');
  const files = {};
  let size = 0;
  async function walk(dir, prefix = '') {
    for (const name of (await readdir(dir)).sort()) {
      if (!prefix && name === '.git') continue;
      const p = prefix ? `${prefix}/${name}` : name, full = join(dir, name), st = await lstat(full);
      requireThat(!st.isSymbolicLink(), 'cycle-symlink');
      if (st.isDirectory()) await walk(full, p);
      else {
        requireThat(st.isFile() && st.nlink === 1, 'cycle-file-type');
        size += st.size; requireThat(size <= 2 * 1024 ** 2, 'cycle-fixture-size');
        const bytes = await readFile(full);
        files[p] = { mode: st.mode & 0o777, sha256: sha256(bytes), data: bytes.toString('base64') };
      }
    }
  }
  await walk(repo);
  return { head: (await git(repo, ['rev-parse', 'HEAD'])).trim(), branch: (await git(repo, ['branch', '--show-current'])).trim(), index: sha256(await readFile(join(repo, '.git/index'))), entries: await git(repo, ['ls-files', '--stage', '-z']), files };
}
function validateSnapshot(value, mode = 'fixture') {
  schema(value, ['head', 'branch', 'index', 'entries', 'files', ...(mode === 'read-only' ? ['status'] : [])]);
  requireThat(value.files && typeof value.files === 'object', 'cycle-snapshot');
  for (const [path, file] of Object.entries(value.files)) {
    schema(file, ['mode', 'sha256', ...(mode === 'read-only' ? [] : ['data'])]);
    requireThat(pathsValid([path]) && Number.isInteger(file.mode) && file.mode >= 0 && file.mode <= 0o777 && /^[a-f0-9]{64}$/.test(file.sha256), 'cycle-snapshot');
    if (mode !== 'read-only') {
      requireThat(typeof file.data === 'string', 'cycle-snapshot');
      const data = Buffer.from(file.data, 'base64');
      requireThat(data.toString('base64') === file.data && sha256(data) === file.sha256, 'cycle-preimage-integrity');
    }
  }
}
function outsideOwned(pre, post, paths) {
  const status = state => (state.status || []).filter(s => !/^.{2} /.test(s) || !paths.includes(s.slice(3)));
  return equal(status(pre), status(post)) && pre.head === post.head && pre.branch === post.branch && pre.index === post.index && pre.entries === post.entries && [...new Set([...Object.keys(pre.files), ...Object.keys(post.files)])].filter(p => !paths.includes(p)).every(p => equal(pre.files[p], post.files[p]));
}
async function evidence(root, path) {
  requireThat(pathsValid([path]), 'evidence-path');
  const full = join(root, path);
  requireThat(await realpath(dirname(full)) === dirname(full), 'evidence-symlink');
  const fd = await open(full, constants.O_RDONLY | constants.O_NOFOLLOW);
  try {
    const st = await fd.stat();
    requireThat(st.isFile() && st.nlink === 1 && st.uid === process.getuid() && !(st.mode & 0o077) && st.size > 0 && st.size <= 2 * 1024 ** 2, 'evidence-not-private');
    const data = await fd.readFile();
    return { path, bytes: data.length, sha256: sha256(data) };
  } finally { await fd.close(); }
}
async function exists(path) { try { await lstat(path); return true; } catch (e) { if (e.code === 'ENOENT') return false; throw e; } }
async function readRecord(dir, name) { return readPrivate(join(dir, name + '.json')); }
async function record(root, dir, name, data) { return save(root, join(dir, name + '.json'), data); }
async function active(dir, discard = false) {
  const budget = await readRecord(dir, 'budget');
  requireThat(budget.iterations === 1 && Number.isFinite(budget.started) && budget.deadline === budget.started + 90 * 60 * 1000 && Date.now() >= budget.started && (discard || Date.now() <= budget.deadline), 'cycle-budget-exhausted');
  requireThat(!await exists(join(dir, 'decision.json')) && !await exists(join(dir, 'recovery-start.json')), 'cycle-closed-or-uncertain');
  return budget;
}
export function acceptance(input, verdict) {
  schema(input, ['decision', 'learning', 'functional', 'visual']);
  requireThat(['KEEP', 'DISCARD'].includes(input.decision) && text(input.learning), 'cycle-decision');
  schema(input.functional, ['passed', 'evidence']);
  schema(input.visual, ['status', 'reviewer', 'evidence']);
  requireThat(typeof input.functional.passed === 'boolean' && text(input.functional.evidence), 'functional-evidence-required');
  requireThat(['approved', 'no-visual-change', 'not-reviewed', 'rejected'].includes(input.visual.status) && text(input.visual.evidence) && typeof input.visual.reviewer === 'string', 'visual-evidence-required');
  if (input.decision === 'KEEP') requireThat(verdict.status === 'improvement' && input.functional.passed && ['approved', 'no-visual-change'].includes(input.visual.status) && text(input.visual.reviewer), 'keep-not-approved');
  return input;
}
// One durable cycle per private state root. No reset, retry, hook, or patch executor.
export async function cycle(action, input = null, root = stateRoot, afterFile = async () => {}) {
  requireThat(['begin', 'post', 'decide', 'restore', 'show'].includes(action), 'cycle-action');
  const dir = join(root, 'manual-cycle');
  if (action === 'show') {
    requireThat(input === null, 'cycle-schema');
    if (await exists(dir)) await privateDirectory(dir);
    const result = {};
    for (const name of ['budget', 'begin', 'post', 'decision', 'recovery-start', 'recovery-result']) if (await exists(join(dir, name + '.json'))) result[name] = await readRecord(dir, name);
    return { status: 'cycle-state', ...result };
  }
  const fd = await lock(root);
  try {
    await privateDirectory(dir);
    if (action === 'begin') {
      schema(input, ['repo', 'hypothesis', 'paths', 'baseline', 'calibration', 'acceptance', ...(Object.hasOwn(input, 'mode') ? ['mode'] : [])]);
      const mode = input.mode ?? 'fixture';
      requireThat(['fixture', 'read-only'].includes(mode), 'cycle-mode');
      requireThat(text(input.repo) && text(input.hypothesis) && text(input.acceptance) && pathsValid(input.paths) && text(input.baseline) && text(input.calibration), 'cycle-input');
      if (mode === 'read-only') requireThat(input.paths.every(p => /^wordpress\/wp-content\/themes\/freeplast\/.+\.(?:php|css|js|mjs|html|svg|png|jpe?g|gif|webp|avif|ico|woff2?|ttf|otf|mp4|webm)$/i.test(p) && !p.split('/').some(s => /^(?:uploads|vendor|credentials|secrets|\.env)(?:\.|$)/i.test(s))), 'cycle-owned-path');
      const repo = mode === 'fixture' ? await fixture(input.repo) : await realpath(input.repo);
      requireThat(!await exists(join(dir, 'budget.json')), 'cycle-already-consumed');
      const started = Date.now();
      await record(root, dir, 'budget', { iterations: 1, started, deadline: started + 90 * 60 * 1000 });
      const pre = await snapshot(repo, mode);
      for (const p of input.paths) requireThat((await lstat(dirname(join(repo, p)))).isDirectory(), 'owned-parent-required');
      const source = await fingerprint(repo, mode === 'read-only' ? { maxBytes: 64 * 1024 ** 2, maxFiles: 10000 } : {});
      const baseline = await loadRun(input.baseline, root);
      requireThat(equal(baseline.run.environment.source, source), 'cycle-baseline-source');
      const check = await compare(input.baseline, input.baseline, input.calibration, root);
      requireThat(check.status !== 'incompatible', 'cycle-baseline-incompatible');
      requireThat(equal(await snapshot(repo, mode), pre), 'cycle-later-drift');
      await record(root, dir, 'begin', { ...input, mode, repo, pre, source });
      return { status: 'cycle-started', deadline: started + 90 * 60 * 1000 };
    }
    if (action !== 'restore') await active(dir, action === 'decide' && input?.acceptance?.decision === 'DISCARD');
    const begin = await readRecord(dir, 'begin');
    requireThat(pathsValid(begin.paths), 'cycle-input');
    const mode = begin.mode ?? 'fixture';
    requireThat(['fixture', 'read-only'].includes(mode), 'cycle-mode');
    if (action === 'restore') requireThat(mode === 'fixture', 'read-only-cycle-no-restoration');
    validateSnapshot(begin.pre, mode);
    if (action === 'post') {
      schema(input, ['tests']); requireThat(text(input.tests), 'test-evidence-required');
      const post = await snapshot(begin.repo, mode);
      requireThat(outsideOwned(begin.pre, post, begin.paths), 'cycle-unowned-drift');
      const source = await fingerprint(begin.repo, mode === 'read-only' ? { maxBytes: 64 * 1024 ** 2, maxFiles: 10000 } : {}), tests = await evidence(root, input.tests);
      requireThat(equal(await snapshot(begin.repo, mode), post), 'cycle-later-drift');
      await record(root, dir, 'post', { post, source, tests, at: Date.now() });
      return { status: 'cycle-post-recorded' };
    }
    const post = await readRecord(dir, 'post');
    validateSnapshot(post.post, mode);
    requireThat(equal(await snapshot(begin.repo, mode), post.post), 'cycle-later-drift');
    if (action === 'decide') {
      schema(input, ['candidate', 'acceptance']);
      const intervention = { kind: 'DECLARED', paths: begin.paths, before: begin.source, after: post.source };
      requireThat(input.candidate === null || text(input.candidate), 'cycle-candidate');
      const verdict = input.candidate === null ? { status: 'inconclusive', reasons: ['candidate-not-collected'] } : await compare(begin.baseline, input.candidate, begin.calibration, root, intervention);
      const a = await loadRun(begin.baseline, root), b = input.candidate === null ? null : await loadRun(input.candidate, root);
      if (b) requireThat(Date.parse(b.run.startedAt) >= post.at && Date.parse(b.run.finishedAt) <= Date.now(), 'cycle-candidate-time');
      acceptance(input.acceptance, verdict);
      const links = { functional: await evidence(root, input.acceptance.functional.evidence), visual: await evidence(root, input.acceptance.visual.evidence) };
      requireThat(equal(await evidence(root, post.tests.path), post.tests), 'test-evidence-drift');
      requireThat(equal(await snapshot(begin.repo, mode), post.post), 'cycle-later-drift');
      await record(root, dir, 'decision', { ...input.acceptance, evidence: links, verdict, baseline: { id: a.id, hash: a.hash }, candidate: b ? { id: b.id, hash: b.hash } : null, intervention, at: Date.now() });
      return { status: 'cycle-decided', decision: input.acceptance.decision, verdict: verdict.status };
    }
    requireThat(input === null, 'cycle-schema');
    const decision = await readRecord(dir, 'decision');
    requireThat(decision.decision === 'DISCARD', 'discard-decision-required');
    requireThat(!await exists(join(dir, 'recovery-start.json')), 'recovery-already-attempted');
    requireThat(outsideOwned(begin.pre, post.post, begin.paths), 'cycle-unowned-drift');
    await record(root, dir, 'recovery-start', { at: Date.now(), status: 'uncertain-until-complete' });
    const expected = structuredClone(post.post), receipts = [];
    try {
      for (const [i, p] of begin.paths.entries()) {
        requireThat(equal(await snapshot(begin.repo), expected), 'cycle-later-drift');
        const before = begin.pre.files[p] ?? null, after = post.post.files[p] ?? null;
        const receipt = { path: p, before: before?.sha256 ?? null, after: after?.sha256 ?? null };
        await record(root, dir, `restore-${i}-pending`, receipt);
        const target = join(begin.repo, p);
        if (!equal(before, after)) {
          if (before) {
            const out = await open(target, constants.O_WRONLY | constants.O_NOFOLLOW | (after ? 0 : constants.O_CREAT | constants.O_EXCL), before.mode);
            try {
              const st = await out.stat();
              requireThat(st.isFile() && st.nlink === 1 && (after ? sha256(await readFile(target)) === after.sha256 && (st.mode & 0o777) === after.mode : st.size === 0), 'cycle-later-drift');
              await out.truncate(0); await out.writeFile(Buffer.from(before.data, 'base64')); await out.chmod(before.mode); await out.sync();
            } finally { await out.close(); }
          } else if (after) await unlink(target);
        }
        if (before) expected.files[p] = before; else delete expected.files[p];
        await afterFile(i);
        requireThat(equal(await snapshot(begin.repo), expected), 'recovery-verification');
        await record(root, dir, `restore-${i}-done`, receipt); receipts.push(receipt);
      }
      requireThat(equal(await snapshot(begin.repo), begin.pre), 'recovery-verification');
      await record(root, dir, 'recovery-result', { status: 'restored', receipts });
      return { status: 'restored', receipts };
    } catch (e) {
      await record(root, dir, 'recovery-result', { status: 'uncertain', completed: receipts, error: e.code || 'recovery-failed' });
      throw e;
    }
  } finally { await fd.close(); }
}
