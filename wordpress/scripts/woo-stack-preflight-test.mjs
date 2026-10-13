import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

// Offline regression (no server, no bootstrap, fixture only): the stack harness
// must REFUSE to run when a crashed previous run left the disposable
// fpw-stack-guard-off.php mu-plugin installed (ventas guards REMOVED), before
// any port contact, bootstrap or spawn — and must never remove it silently.
// Source-extracted like bootstrap-onboarding-test.mjs, so the shipped harness
// code itself is exercised.
export function runStackPreflightTests() {
  const source = readFileSync(new URL('./woo-stack-harness.mjs', import.meta.url), 'utf8');
  const block = source.match(/const staleGuardOff[\s\S]*?\n  \}/)?.[0];
  assert.ok(block, 'stale guard-off preflight block present in the harness source');
  const run = (exists) => new Function('existsSync', 'join', 'WP_DIR', block)
    (() => exists, (...parts) => parts.join('/'), '/disposable/wp');
  assert.throws(
    () => run(true),
    (e) => e instanceof Error && e.message.includes('fpw-stack-guard-off.php') && e.message.includes('Refusing'),
    'a stale guard-off remnant must abort the harness with an explicit refusal');
  assert.doesNotThrow(() => run(false), 'a clean stack must pass the preflight untouched');
  const preflight = source.indexOf('/* -1.');
  const portCheck = source.indexOf('/* 0. The port');
  const bootstrap = source.indexOf("sh(process.execPath, [join(HERE, 'bootstrap.mjs')]");
  assert.ok(
    preflight !== -1 && portCheck !== -1 && bootstrap !== -1 && preflight < portCheck && portCheck < bootstrap,
    'the refusal runs before the port probe and before any bootstrap');
  console.log('stack preflight: 4 offline fixture checks passed (stale guard-off remnant refusal)');
  return 4;
}
