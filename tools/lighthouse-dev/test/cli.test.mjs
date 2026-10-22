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
    for (const args of [['--help'], ['-h'], ['--help', '--json'], ['--version'], ['-v'], ['-V'], ['measure'], ['--url', 'http://invalid'], ['--help', '--bad'], ['--version', '--bad'], ['doctor', '--help'], ['measure', '--help'], ['doctor', '--url', 'http://invalid'], ['measure', '--label', '../escape'], ...['list', 'show', 'calibrate', 'baseline-set', 'compare'].map(c => [c, '--help'])]) {
      const result = spawnSync(process.execPath, ['--permission', `--allow-fs-read=${cli}`, `--allow-fs-read=${pkg}`, cli, ...args], {
        cwd, env: { ...process.env, HOME: cwd }, encoding: 'utf8', timeout: 5000,
      });
      const invalid = args.includes('--url') || args.includes('--bad') || args.includes('../escape') || args.length === 1 && args[0] === 'measure';
      assert.equal(result.status, invalid ? 2 : 0, result.stderr);
      assert.equal(result.stderr, '');
      if (args.length === 1 && ['--version', '-v', '-V'].includes(args[0])) assert.equal(result.stdout, '0.1.0\n');
      if (args.includes('--json')) assert.match(JSON.parse(result.stdout).description, /deterministic/);
      assert.deepEqual(await readdir(cwd), []);
    }
  } finally {
    await rm(cwd, { recursive: true });
  }
});

test('new CLI validates flags and required arguments before imports or effects', () => {
  for (const args of [['compare'], ['show'], ['calibrate', '--a', 'a'], ['baseline', 'set', '--run', 'a'], ['list', '--run', 'a'], ['compare', '--baseline', 'a', '--candidate', 'b', '--bad'], ['show', '--run', '../escape'], ['calibrate', '--a', 'a', '--b', 'b', '--focal', 'mobile:/unknown/'], ['show', '--run', 'a', '--run', 'b']]) {
    const result = spawnSync(process.execPath, ['--permission', `--allow-fs-read=${cli}`, `--allow-fs-read=${pkg}`, cli, ...args, '--json'], { encoding: 'utf8' });
    assert.equal(result.status, 2, result.stdout); assert.equal(JSON.parse(result.stdout).error, 'usage');
  }
  for (const args of [[], ['list'], ['--json']]) {
    const result = spawnSync(process.execPath, ['--permission', `--allow-fs-read=${cli}`, `--allow-fs-read=${pkg}`, cli, ...args], { encoding: 'utf8' });
    assert.equal(result.status, 1); assert.match(result.stdout, /ERR_ACCESS_DENIED/);
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
