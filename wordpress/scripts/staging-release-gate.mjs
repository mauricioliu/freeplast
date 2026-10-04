#!/usr/bin/env node
/** Full release gate in a fresh disposable clone; never reuses a live/local WP DB. */
import { spawnSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, writeFileSync, symlinkSync, rmSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
if (process.argv.length > 2) {
  if (process.argv.length === 3 && process.argv[2] === '--help') {
    console.log('Run: node wordpress/scripts/staging-release-gate.mjs\nRuns committed-source npm test, role migration regressions and photo tests in a fresh private clone.\nUses existing node_modules and wordpress/.tools; FREEPLAST_RELEASE_TEST_PORT defaults to 8098.');
    process.exit(0);
  }
  if (process.argv.length === 3 && ['--version','-v','-V'].includes(process.argv[2])) { console.log('1.0.0'); process.exit(0); }
  console.log('error: unknown arguments\nhelp: node wordpress/scripts/staging-release-gate.mjs --help'); process.exit(2);
}
const port = process.env.FREEPLAST_RELEASE_TEST_PORT || '8098';
if (!/^\d{4,5}$/.test(port) || +port > 65535 || +port < 1024) throw Error('Invalid isolated test port');
for (const path of ['node_modules', 'wordpress/.tools/php/php']) if (!existsSync(join(root,path))) throw Error(`Missing existing dependency: ${path}`);
const dirty = spawnSync('git', ['status','--porcelain'], {cwd:root, encoding:'utf8'});
if (dirty.status !== 0 || dirty.stdout.trim()) throw Error('Release gate requires all source changes committed (never stash/reset automatically)');
const work = mkdtempSync(join(tmpdir(), 'freeplast-release-gate-'));
const repo = join(work, 'repo');
const ini = join(work, 'ini'); mkdirSync(ini);
writeFileSync(join(ini,'no-mail.ini'), 'sendmail_path=/usr/bin/false\n');
const env = {...process.env, FREEPLAST_TEST_URL:`http://127.0.0.1:${port}`, PHP_INI_SCAN_DIR:ini};
delete env.FREEPLAST_SKIP_STACK;
// Force the pinned toolchain rather than inheriting another project's PHP.
delete env.PHP_BINARY;
function run(bin,args,cwd=repo) {
  const r=spawnSync(bin,args,{cwd,env,stdio:'inherit'});
  if (r.status !== 0) throw Error(`${bin} gate failed (${r.status ?? r.error?.code})`);
}
try {
  run('git',['clone','--quiet','--shared','--no-hardlinks',root,repo],root);
  symlinkSync(join(root,'node_modules'),join(repo,'node_modules'),'dir');
  symlinkSync(join(root,'wordpress/.tools'),join(repo,'wordpress/.tools'),'dir');
  const php = join(repo,'wordpress/.tools/php/php');
  for (const file of ['verify-staging-release.php','staging-release-fingerprint.php','staging-role-migration/run.php','staging-role-migration/role-policy.php']) {
    run(php,['-l',`wordpress/scripts/${file}`]);
  }
  // Offline native-JS tests read Woo's pinned checkout bundle before the stack
  // harness runs, so even a clean checkout must be provisioned first.
  run(process.execPath,['wordpress/scripts/bootstrap.mjs']);
  run('npm',['test']); // Includes the role-migration regressions.
  run('npm',['run','test:photos']);
  console.log('release_gate: passed (fresh native stack, role migration, photos; no real mail)');
} finally {
  // Only the private temp directory created above; the harness owns server teardown.
  rmSync(work,{recursive:true,force:true});
}
