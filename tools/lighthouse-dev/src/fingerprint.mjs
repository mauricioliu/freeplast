import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { readFile, lstat, readdir } from 'node:fs/promises';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { cpus, totalmem, loadavg, freemem, platform, release, arch, hostname } from 'node:os';
import { sha256 } from './preflight.mjs';
import { deny } from './policy.mjs';
const exec = promisify(execFile);
export const checkout = fileURLToPath(new URL('../../../', import.meta.url));
export async function fingerprint(root = checkout, limits = {}) {
  const git = async args => (await exec('git', ['-C', root, ...args], { encoding: 'utf8', maxBuffer: 8 * 1024 ** 2, env: { ...process.env, GIT_OPTIONAL_LOCKS: '0' } })).stdout;
  const files = [];
  let bytes = 0;
  async function walk(path) {
    const full = join(root, path);
    let st;
    try { st = await lstat(full); } catch (e) { if (e.code === 'ENOENT') return; throw e; }
    if (st.isSymbolicLink()) deny('source-symlink');
    if (st.isDirectory()) {
      for (const name of (await readdir(full)).sort()) {
        if (['node_modules', '.git', 'vendor', 'uploads'].includes(name.toLowerCase())) continue;
        await walk(join(path, name));
      }
    } else if (/\.(?:mjs|js|json|php|css|html|svg|png|jpe?g|gif|webp|avif|ico|woff2?|ttf|otf|mp4|webm|yaml|ini|sh|md)$/i.test(path) && !/(?:^|\/)(?:\.env|credentials|secrets)(?:\.|\/|$)/i.test(path)) {
      bytes += st.size;
      if (!st.isFile() || bytes > (limits.maxBytes ?? Infinity) || files.length >= (limits.maxFiles ?? Infinity)) deny('source-size-or-type');
      files.push({ path, mode: st.mode & 0o777, bytes: st.size, sha256: sha256(await readFile(full)) });
    }
  }
  for (const path of ['tools/lighthouse-dev', 'wordpress/wp-content/themes/freeplast', 'wordpress/wp-content/plugins', 'wordpress/local', 'package.json', 'package-lock.json']) await walk(path);
  const index = await git(['ls-files', '--stage', '-z']);
  return {
    head: (await git(['rev-parse', 'HEAD'])).trim(), branch: (await git(['branch', '--show-current'])).trim(),
    status: (await git(['status', '--porcelain=v1', '-z', '--untracked-files=all'])).split('\0').filter(Boolean),
    indexHash: sha256(index), files, sourceHash: sha256(JSON.stringify(files)),
  };
}
export const hardware = () => ({ hostname: hostname(), platform: platform(), release: release(), arch: arch(), cpus: cpus().map(({ model }) => ({ model })), memory: totalmem() });
export const load = () => ({ at: new Date().toISOString(), load: loadavg(), freeMemory: freemem() });
