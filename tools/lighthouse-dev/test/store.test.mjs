import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, rm, stat, symlink, chmod, writeFile, readdir } from 'node:fs/promises';
import { tmpdir, userInfo } from 'node:os';
import { join } from 'node:path';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { lock, privateDirectory, save, manifest, readPrivate, capacity, byteLimit, stateRoot } from '../src/store.mjs';

const moduleUrl = new URL('../src/store.mjs', import.meta.url).href;
async function contender(root, home) {
  const child = spawn(process.execPath, ['--input-type=module', '-e', `import {lock} from ${JSON.stringify(moduleUrl)}; try { const fd=await lock(${JSON.stringify(root)}); await fd.close(); process.exit(0); } catch(e) { process.exit(e.code==='busy'?6:1); }`], { cwd: '/', env: { ...process.env, HOME: home }, stdio: 'ignore' });
  return (await once(child, 'exit'))[0];
}
test('kernel lock excludes other processes/output directories and releases after SIGKILL', async () => {
  const root = await mkdtemp(join(tmpdir(), 'lh-lock-'));
  let holder;
  try {
    const fd = await lock(root);
    const inode = (await stat(join(root, 'runner.lock'))).ino;
    assert.equal(await contender(root, '/tmp/different-output-home'), 6);
    await fd.close();
    assert.equal(await contender(root, '/tmp/other-output-home'), 0);
    holder = spawn(process.execPath, ['--input-type=module', '-e', `import {lock} from ${JSON.stringify(moduleUrl)}; const fd=await lock(${JSON.stringify(root)}); console.log('ready'); setInterval(()=>{},1000);`], { stdio: ['ignore', 'pipe', 'ignore'] });
    await once(holder.stdout, 'data');
    assert.equal(await contender(root, '/tmp'), 6);
    holder.kill('SIGKILL'); await once(holder, 'exit');
    assert.equal(await contender(root, '/tmp'), 0);
    assert.equal((await stat(join(root, 'runner.lock'))).ino, inode);
    assert.equal(stateRoot, join(userInfo().homedir, '.local/state/freeplast-lighthouse-dev'));
  } finally { holder?.kill('SIGKILL'); await rm(root, { recursive: true, force: true }); }
});
test('private atomic state, immutable evidence, capacity and symlink rejection', async () => {
  const root = await mkdtemp(join(tmpdir(), 'lh-state-'));
  try {
    await privateDirectory(root);
    const file = join(root, 'report.json');
    await save(root, file, { safe: true });
    assert.equal((await stat(file)).mode & 0o777, 0o600);
    assert.deepEqual(await readPrivate(file), { safe: true });
    await assert.rejects(save(root, file, 'overwrite'));
    await manifest(root, root, { status: 'incomplete' });
    await manifest(root, root, { status: 'complete' });
    assert.equal((await readPrivate(join(root, 'manifest.json'))).status, 'complete');
    assert.equal((await readdir(root)).filter(x => x.startsWith('.manifest-')).length, 0);
    await assert.rejects(capacity(root, byteLimit), /storage-budget/);
    await symlink(file, join(root, 'link'));
    await assert.rejects(readPrivate(join(root, 'link')));
    await assert.rejects(capacity(root), /state-symlink/);
    await chmod(root, 0o755);
    await assert.rejects(privateDirectory(root), /state-not-private/);
  } finally { await rm(root, { recursive: true, force: true }); }
});
test('unsafe lock inode is rejected, never unlinked', async () => {
  const root = await mkdtemp(join(tmpdir(), 'lh-lock-unsafe-'));
  try {
    await writeFile(join(root, 'target'), 'preserve');
    await symlink(join(root, 'target'), join(root, 'runner.lock'));
    await assert.rejects(lock(root));
    assert.ok((await stat(join(root, 'target'))).size);
  } finally { await rm(root, { recursive: true, force: true }); }
});
