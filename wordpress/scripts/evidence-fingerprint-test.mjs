import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, copyFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
export function runEvidenceFingerprintTests() {
  const script = fileURLToPath(new URL('../docs/journey-a-evidence/fingerprint.sh', import.meta.url));
  const root = resolve(dirname(script), '../../..');
  const run = (args = [], options = {}) => spawnSync('bash', [script, ...args], { encoding: 'utf8', ...options });
  const a = run(), b = run();
  assert.equal(a.status, 0, a.stderr); assert.equal(a.stdout, b.stdout); let checks = 1;
  assert.match(a.stdout, /fields\.js: 1\.0\.4/); checks++;
  for (const path of ['woocommerce/loop/loop-start.php', 'inc/catalog.php', 'wordpress/data/products.json', 'scripts/native-chrome-test.php', 'woocommerce/global/breadcrumb.php']) assert.ok(a.stdout.includes(path), path);
  checks++;
  assert.match(a.stdout, /\[native-probes\]/); checks++;
  assert.equal(run(['--help', '--unexpected']).status, 2); checks++;
  const tmp = mkdtempSync(join(tmpdir(), 'fp-evidence-'));
  try {
    const copy = join(tmp, 'wordpress/docs/journey-a-evidence/fingerprint.sh');
    mkdirSync(resolve(copy, '..'), { recursive: true }); copyFileSync(script, copy);
    const missing = spawnSync('bash', [copy], { encoding: 'utf8', env: { ...process.env, GIT_DIR: join(root, '.git'), GIT_WORK_TREE: tmp, GIT_OPTIONAL_LOCKS: '0' } });
    assert.equal(missing.status, 1); assert.equal(missing.stdout, ''); checks++;
  } finally { rmSync(tmp, { recursive: true, force: true }); }
  console.log(`evidence fingerprint: ${checks} offline checks passed (stable, complete input families, versions, fail-closed)`);
  return checks;
}
