import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, readdir, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import { lighthouseConfig } from '../src/config.mjs';

const cli = fileURLToPath(new URL('../bin/freeplast-lh.mjs', import.meta.url));
const pkg = fileURLToPath(new URL('../package.json', import.meta.url));

test('CLI probes reject effects and unsupported input before loading dependencies', async () => {
  const cwd = await mkdtemp(join(tmpdir(), 'freeplast-lh-cli-'));
  try {
    for (const args of [[], ['--help'], ['-h'], ['--json'], ['--version'], ['-v'], ['-V'], ['measure'], ['--url', 'http://invalid'], ['--help', '--bad'], ['--version', '--bad']]) {
      const result = spawnSync(process.execPath, ['--permission', `--allow-fs-read=${cli}`, `--allow-fs-read=${pkg}`, cli, ...args], {
        cwd, env: { ...process.env, HOME: cwd }, encoding: 'utf8', timeout: 5000,
      });
      const invalid = args.some(arg => ['measure', '--url', '--bad'].includes(arg));
      assert.equal(result.status, invalid ? 2 : 0, result.stderr);
      assert.equal(result.stderr, '');
      if (args.length === 1 && ['--version', '-v', '-V'].includes(args[0])) assert.equal(result.stdout, '0.1.0\n');
      if (args.includes('--json')) assert.equal(JSON.parse(result.stdout).status, 'not ready for DEV measurement');
      assert.deepEqual(await readdir(cwd), []);
    }
  } finally {
    await rm(cwd, { recursive: true });
  }
});

test('profiles are explicit, separate, and reject unknown names', () => {
  const mobile = lighthouseConfig('mobile');
  const desktop = lighthouseConfig('desktop');
  assert.equal(mobile.settings.screenEmulation.width, 412);
  assert.equal(desktop.settings.screenEmulation.width, 1440);
  assert.equal(mobile.settings.throttling.cpuSlowdownMultiplier, 4);
  assert.equal(desktop.settings.throttling.cpuSlowdownMultiplier, 1);
  assert.equal(mobile.settings.disableStorageReset, false);
  mobile.settings.onlyCategories.pop();
  assert.equal(lighthouseConfig('mobile').settings.onlyCategories.length, 4);
  assert.throws(() => lighthouseConfig('__proto__'), /Unknown profile/);
});
