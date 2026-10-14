import assert from 'node:assert/strict';
import { chmodSync, existsSync, mkdtempSync, mkdirSync, readFileSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
const here = dirname(fileURLToPath(import.meta.url));
const temp = mkdtempSync(join(tmpdir(), 'fp-local-test-'));
const state = join(temp, 'state');
const calls = join(temp, 'calls');
const php = process.env.PHP_BINARY || join(here, '../.tools/php/php');
let count = 0;
function check(ok, message) { assert.ok(ok, message); count++; }
try {
  const bin = join(temp, 'bin'); mkdirSync(bin);
  writeFileSync(join(bin, 'docker'), `#!/usr/bin/env node
const fs = require('node:fs');
const a = process.argv.slice(2);
fs.appendFileSync(process.env.FP_TEST_CALLS, JSON.stringify(a)+'\\n');
if (a[0]==='context') console.log(JSON.stringify(process.env.FP_TEST_ENDPOINT || 'unix:///var/run/docker.sock'));
else if (a[0]==='info' && process.env.FP_TEST_ERROR) { console.log('stdout cause: synthetic startup failure'); console.error(process.env.FP_TEST_ERROR); process.exitCode=1; }
else if (a[0]==='info') console.log('test-only');
else if (a[0]==='volume') { console.error('Error: no such volume'); process.exitCode=1; }
else if (a.includes('ps')) console.log('[]');
else if (process.env.FP_TEST_LIFECYCLE === '1' && (a.includes('stop') || a.includes('up'))) console.log('mock lifecycle only');
else if (!a.includes('config')) { console.error('test refuses mutations'); process.exitCode=90; }
`, { mode: 0o755 });
  const env = { ...process.env, PATH: bin + ':' + process.env.PATH, FREEPLAST_LOCAL_STATE: state, FREEPLAST_LOCAL_PROJECT: 'freeplast-local-test', FREEPLAST_LOCAL_PORT: '8131', FREEPLAST_LOCAL_URL: 'http://mliu:8131', FP_TEST_CALLS: calls };
  delete env.DOCKER_HOST;
  function cli(args, changes = {}) { return spawnSync(process.execPath, [join(here, 'local.mjs'), ...args], { encoding: 'utf8', env: { ...env, ...changes } }); }
  for (const args of [['bogus'], ['up', '--execut'], ['status', '--execute'], ['init', '--cache'], ['reset', '--unknown'], ['up', 'extra']]) {
    check(cli(args).status === 2, 'reject unknown input before dependencies: ' + args);
  }
  check(!existsSync(calls), 'invalid input never calls Docker');
  check(cli(['--version']).stdout.trim() === '0.1.0', 'version fast path');
  check(cli([]).stdout.includes('not initialized'), 'explicit initial empty state');
  check(cli(['access']).stdout.includes('tailscale serve --bg --http=8131'), 'access only proposes targeted endpoint');
  check(!existsSync(calls), 'access never mutates Tailscale or calls Docker');
  for (const changes of [{ FREEPLAST_LOCAL_PROJECT: 'freeplast-wordpress' }, { FREEPLAST_LOCAL_URL: 'https://freeplast.mliu.site' }, { FREEPLAST_LOCAL_PORT: '8091' }, { FREEPLAST_LOCAL_STATE: join(here, '.private') }]) {
    check(cli(['plan'], changes).status === 2, 'reject unsafe scope');
  }
  check(cli(['plan']).status === 0, 'plan validates compose');
  check(JSON.parse(readFileSync(calls, 'utf8').trim()).includes('--quiet'), 'plan invokes config only');
  check(!existsSync(state), 'plan creates no state');
  check(cli(['init', '--cache', temp, '--execute']).status === 1, 'missing/hash-invalid archives rejected');
  check(!existsSync(state), 'failed init leaves no state');
  const cache = process.env.FREEPLAST_LOCAL_TEST_CACHE;
  if (cache) {
    check(cli(['init', '--cache', cache]).status === 0 && !existsSync(state), 'init dry run does not write');
    check(cli(['init', '--cache', cache, '--execute']).status === 0, 'init stages checked vendor and secrets');
    check((statSync(state).mode & 0o777) === 0o700, 'private parent protects readable secret binds');
    for (const name of ['db-password', 'db-root-password', 'admin-password']) {
      check((statSync(join(state, name)).mode & 0o777) === 0o444, name + ' readable inside bind by uid33');
    }
    check(cli(['init', '--cache', cache, '--execute']).stdout.includes('already initialized'), 'init idempotent');
    const password = readFileSync(join(state, 'admin-password'), 'utf8');
    check(!cli(['doctor']).stdout.includes(password), 'doctor never prints secret');
    check(cli(['doctor']).status === 0, 'doctor validates ownership and file modes');
    const diagnostic = cli(['doctor'], { FP_TEST_ERROR: `Local capture host rejected. password=${password} token=other-secret Authorization: Bearer private-token` });
    check(diagnostic.status === 1 && diagnostic.stdout.includes('Local capture host rejected.') && diagnostic.stdout.includes('stdout cause'), 'failed command preserves stderr and stdout evidence');
    check(!diagnostic.stdout.includes(password) && !diagnostic.stdout.includes('other-secret') && !diagnostic.stdout.includes('private-token'), 'diagnostic redacts known secrets and credential fields');
    const bounded = cli(['doctor'], { FP_TEST_ERROR: 'x'.repeat(6000) + '\\nfinal cause' });
    check(bounded.stdout.length < 3200 && bounded.stdout.includes('truncated') && bounded.stdout.includes('final cause'), 'diagnostic output bounded without losing final cause');
    check(cli(['up']).stdout.includes('plan'), 'up requires explicit execute');
    check(cli(['seed']).stdout.includes('plan'), 'seed requires explicit execute');
    check(cli(['stop']).stdout.includes('plan'), 'manual stop still requires explicit execute');
    check(cli(['stop', '--execute'], { FP_TEST_LIFECYCLE: '1' }).status === 0, 'manual stop succeeds against mock');
    check(JSON.parse(readFileSync(calls, 'utf8').trim().split('\n').at(-1)).at(-1) === 'stop', 'manual stop uses compose stop, preserving stopped state and volumes');
    check(cli(['up', '--execute'], { FP_TEST_LIFECYCLE: '1' }).status === 0, 'explicit up resumes against mock');
    const resumed = JSON.parse(readFileSync(calls, 'utf8').trim().split('\n').at(-1));
    check(resumed.includes('up') && resumed.slice(-2).join(',') === 'db,wordpress', 'resume starts only persistent services, never seeds');
    check(cli(['reset', '--execute']).status === 2, 'destructive reset needs exact project confirmation');
    check(cli(['reset', '--confirm', 'freeplast-local-test']).stdout.includes('plan'), 'confirmed reset still needs execute');
    check(cli(['doctor'], { FP_TEST_ENDPOINT: 'ssh://production' }).status === 1, 'refuse remote context');
    check(cli(['doctor'], { DOCKER_HOST: 'tcp://remote:2375' }).status === 1, 'refuse remote Docker override');
    chmodSync(join(state, 'db-password'), 0o600);
    check(cli(['doctor']).status === 1, 'doctor rejects uid33-unreadable file mode');
    chmodSync(join(state, 'db-password'), 0o444);
    check(cli(['doctor'], { FREEPLAST_LOCAL_URL: 'http://localhost:8131' }).status === 1, 'ownership includes canonical URL');
  } else console.log('local init permission integration: skipped (set FREEPLAST_LOCAL_TEST_CACHE to checked local archives)');
  const config = readFileSync(join(here, 'compose.yaml'), 'utf8');
  for (const role of ['db', 'wordpress', 'cli']) {
    const service = config.split(`  ${role}:\n`)[1].split(/\n(?:  [a-z_]+:|[a-z_]+:)/)[0];
    check(role === 'cli' ? !service.includes('restart:') : service.includes('restart: unless-stopped'), role + ' restart policy recovers running services but respects manual stop');
  }
  check(/networks:\n  local:\n    internal: true/.test(config), 'database network remains internal');
  check(/wordpress:[\s\S]*?networks: \[local, preview\]/.test(config), 'web service joins publishing-capable preview bridge');
  check(/preview:\n    driver: bridge\n    internal: false\n    enable_ipv6: false\n    driver_opts:\n      com\.docker\.network\.bridge\.enable_ip_masquerade: "false"/.test(config), 'preview bridge supports publishing without IPv4 masquerade or IPv6');
  for (const role of ['db', 'cli']) {
    const service = config.split(`  ${role}:\n`)[1].split(/\n(?:  [a-z_]+:|[a-z_]+:)/)[0];
    check(service.includes('networks: [local]') && !service.includes('preview'), role + ' stays off preview bridge');
  }
  check(config.includes('127.0.0.1:${FREEPLAST_LOCAL_PORT:-8131}:80'), 'loopback-only publication');
  check(config.includes('create_host_path: false'), 'missing safety/source binds fail closed');
  check(!config.includes('.build') && !config.includes('external: true'), 'persistent volumes independent from disposable/other stacks');
  for (const file of ['config.php', 'verify-files.php', 'safety.php', 'seed.php', 'runtime-check.php', 'safety-test.php', 'renderer-test.php']) {
    const result = spawnSync(php, ['-l', join(here, file)], { encoding: 'utf8' });
    check(result.status === 0, file + ': ' + result.stdout + result.stderr);
  }
  for (const [host, expected] of [['evil.example:8131', 'Local host rejected.'], ['mliu:8131', 'Local containment missing']]) {
    const code = `putenv('FREEPLAST_LOCAL_URL=http://mliu:8131'); $_SERVER['HTTP_HOST']=$argv[1]; $s=file_get_contents($argv[2]); $s=str_replace("PHP_SAPI !== 'cli'", 'true', $s); try { eval(substr($s,5)); } catch (RuntimeException $e) { echo $e->getMessage(); }`;
    const result = spawnSync(php, ['-r', code, host, join(here, 'config.php')], { encoding: 'utf8' });
    check(result.status === 0 && result.stdout.includes(expected), 'request Host and missing-containment startup gates: ' + host);
  }
  const recipe = readFileSync(join(here, 'README.md'), 'utf8');
  check(!/cli eval-file/.test(recipe) && (recipe.match(/cli wp eval-file/g) || []).length === 9, 'official CLI image recipes invoke explicit wp executable');
  for (const mode of ['allowed', 'uninstalled', 'bad-host', 'bad-dir']) {
    const result = spawnSync(php, [join(here, 'safety-test.php'), mode], { encoding: 'utf8' });
    check(result.status === 0, result.stdout + result.stderr);
  }
  const renderer = spawnSync(php, [join(here, 'renderer-test.php')], { encoding: 'utf8' });
  check(renderer.status === 0, renderer.stdout + renderer.stderr);
  for (const name of ['start.sh', 'bootstrap.sh']) check(spawnSync('sh', ['-n', join(here, name)]).status === 0, name + ' syntax');
  console.log(`local environment: ${count} offline checks passed; no services, network requests or devices started`);
} finally { rmSync(temp, { recursive: true, force: true }); }
