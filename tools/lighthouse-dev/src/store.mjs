import { constants } from 'node:fs';
import { mkdir, lstat, readdir, open, rename, statfs, readFile } from 'node:fs/promises';
import { userInfo } from 'node:os';
import { join, resolve, parse, dirname } from 'node:path';
import { spawn } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { deny } from './policy.mjs';
import { sha256 } from './preflight.mjs';

export const stateRoot = join(userInfo().homedir, '.local/state/freeplast-lighthouse-dev');
export const byteLimit = 4 * 1024 ** 3;
export async function privateDirectory(path) {
  path = resolve(path);
  let part = parse(path).root;
  for (const segment of path.slice(part.length).split('/')) {
    part = join(part, segment);
    try { await mkdir(part, { mode: 0o700 }); } catch (e) { if (e.code !== 'EEXIST') throw e; }
    const st = await lstat(part);
    if (!st.isDirectory() || st.isSymbolicLink() || (st.mode & 0o022) && part !== '/tmp') deny('unsafe-state-path');
  }
  const st = await lstat(path);
  if (st.uid !== process.getuid() || (st.mode & 0o077)) deny('state-not-private');
  return path;
}

export async function usage(path, allowLinks = false) {
  let size = 0;
  for (const name of await readdir(path)) {
    const p = join(path, name), st = await lstat(p);
    if (st.isSymbolicLink()) { if (allowLinks) { size += st.size; continue; } deny('state-symlink'); }
    if (st.isDirectory()) size += await usage(p, allowLinks);
    else if (st.isFile()) size += st.size;
    else deny('state-file-type');
  }
  return size;
}
export async function capacity(root, additional = 0) {
  const used = await usage(root), fs = await statfs(root);
  if (used + additional > byteLimit || fs.bavail * fs.bsize < additional + 64 * 1024 ** 2) deny('storage-budget');
  return { used, limit: byteLimit, free: fs.bavail * fs.bsize };
}
export async function save(root, path, value) {
  const data = typeof value === 'string' ? value : Buffer.isBuffer(value) ? value : Buffer.from(JSON.stringify(value, null, 2) + '\n');
  await capacity(root, Buffer.byteLength(data));
  const fd = await open(path, constants.O_WRONLY | constants.O_CREAT | constants.O_EXCL | constants.O_NOFOLLOW, 0o600);
  try { await fd.writeFile(data); await fd.sync(); } finally { await fd.close(); }
  const parent = await open(dirname(path), 'r');
  try { await parent.sync(); } finally { await parent.close(); }
  return { path: path.slice(root.length + 1), bytes: Buffer.byteLength(data), sha256: sha256(data) };
}
export async function manifest(root, dir, value) {
  const temp = join(dir, `.manifest-${randomUUID()}`);
  await save(root, temp, value);
  await rename(temp, join(dir, 'manifest.json'));
  const fd = await open(dir, 'r');
  try { await fd.sync(); } finally { await fd.close(); }
}
export async function readPrivate(path) {
  const fd = await open(path, constants.O_RDONLY | constants.O_NOFOLLOW);
  try {
    const st = await fd.stat();
    if (!st.isFile() || st.uid !== process.getuid() || (st.mode & 0o077) || st.nlink !== 1 || st.size > 8 * 1024 ** 2) deny('unsafe-private-file');
    return JSON.parse(await fd.readFile('utf8'));
  } finally { await fd.close(); }
}
export async function lock(root = stateRoot) {
  await privateDirectory(root);
  const fd = await open(join(root, 'runner.lock'), constants.O_RDWR | constants.O_CREAT | constants.O_NOFOLLOW, 0o600);
  const st = await fd.stat();
  if (!st.isFile() || st.uid !== process.getuid() || st.nlink !== 1 || (st.mode & 0o077)) { await fd.close(); deny('unsafe-lock'); }
  const status = await new Promise((resolve, reject) => {
    const child = spawn('/usr/bin/flock', ['-n', '-E', '6', '3'], { stdio: ['ignore', 'ignore', 'ignore', fd.fd] });
    child.once('error', reject); child.once('exit', resolve);
  }).catch(async e => { await fd.close(); throw e; });
  if (status !== 0) { await fd.close(); deny(status === 6 ? 'busy' : 'lock-failed'); }
  // flock and this descriptor share the open-file description. Never unlink the inode.
  return fd;
}

export async function toolchain() {
  const pins = JSON.parse(await readFile(new URL('../toolchain.json', import.meta.url)));
  if (process.versions.node !== pins.node || sha256(await readFile(pins.browser.path)) !== pins.browser.sha256) deny('toolchain-drift');
  for (const name of ['lighthouse', 'puppeteer-core']) {
    const pkg = JSON.parse(await readFile(new URL(`../node_modules/${name}/package.json`, import.meta.url)));
    if (pkg.version !== pins[name]) deny('dependency-drift');
  }
  if (await usage(fileURLToPath(new URL('../node_modules/', import.meta.url)), true) > byteLimit) deny('toolchain-budget');
  return pins;
}
