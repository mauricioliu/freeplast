import test from 'node:test';
import assert from 'node:assert/strict';
import puppeteer from 'puppeteer-core';
import { ChromeLauncher } from '../node_modules/puppeteer-core/lib/puppeteer/node/ChromeLauncher.js';
import { smokeBrowserArgs, auditConfig, lighthouseConfig } from '../src/config.mjs';

test('owned audit BFCache flag merges with Puppeteer defaults; all default audits retained', async () => {
  const args = await puppeteer.defaultArgs({ headless: true, userDataDir: '/unused/profile', args: [`--disable-features=${auditConfig.disabledFeatures.join(',')}`] });
  const flags = args.filter(x => x.startsWith('--disable-features='));
  assert.equal(flags.length, 1);
  const features = flags[0].split('=')[1].split(',');
  for (const feature of auditConfig.disabledFeatures) assert.ok(features.includes(feature));
  for (const flag of (await puppeteer.defaultArgs()).filter(x => x.startsWith('--disable-features='))) for (const feature of flag.split('=')[1].split(',')) assert.ok(features.includes(feature));
  for (const profile of ['mobile', 'desktop']) {
    const config = lighthouseConfig(profile);
    assert.equal(config.extends, 'lighthouse:default');
    assert.equal(config.settings.skipAudits, undefined);
    assert.equal(config.settings.onlyAudits, undefined);
    assert.equal(config.settings.disableStorageReset, false);
  }
  assert.equal(auditConfig.generation, 6);
});

test('smoke launch arguments enable ephemeral loopback CDP without disabling sandbox', async () => {
  // Compute only: explicit paths prevent profile creation; no process is launched.
  const launcher = new ChromeLauncher(puppeteer);
  const { args } = await launcher.computeLaunchArguments({
    executablePath: '/unused/chromium', userDataDir: '/unused/profile',
    headless: true, args: [...smokeBrowserArgs],
  });
  assert.ok(args.includes('--remote-debugging-address=127.0.0.1'));
  assert.ok(args.includes('--remote-debugging-port=0'), 'address flag must not suppress CDP transport');
  assert.ok(!args.includes('--no-sandbox'));
  assert.ok(!args.includes('--disable-setuid-sandbox'));
  const loggedArgs = await puppeteer.defaultArgs({ headless: true, args: [...smokeBrowserArgs] });
  assert.ok(Array.isArray(loggedArgs));
  assert.ok(loggedArgs.includes('--remote-debugging-port=0'));
});
