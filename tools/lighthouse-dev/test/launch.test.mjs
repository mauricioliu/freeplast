import test from 'node:test';
import assert from 'node:assert/strict';
import puppeteer from 'puppeteer-core';
import { ChromeLauncher } from '../node_modules/puppeteer-core/lib/puppeteer/node/ChromeLauncher.js';
import { smokeBrowserArgs } from '../src/config.mjs';

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
