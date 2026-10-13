#!/usr/bin/env node
import { chmodSync, copyFileSync, existsSync, lstatSync, mkdirSync, readFileSync, realpathSync, renameSync, rmSync, writeFileSync } from 'node:fs';
import { createHash, randomBytes } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { dirname, isAbsolute, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { homedir } from 'node:os';

const here = dirname(fileURLToPath(import.meta.url));
const checkout = realpathSync(join(here, '../..'));
const commands = {
  status: [], doctor: [], plan: [], init: ['--execute', '--cache'], up: ['--execute'],
  seed: ['--execute'], stop: ['--execute'], logs: [], capture: ['--execute'],
  reset: ['--execute', '--confirm'], access: [],
};
const help = 'npm run local -- <status|doctor|plan|init|up|seed|stop|logs|capture|reset|access> [--execute]; init: --cache <directory>; reset: --confirm <project>';
function output(data) { for (const [key, value] of Object.entries(data)) console.log(`${key}: ${JSON.stringify(value)}`); }
function fail(message, code = 1, evidence) { const error = new Error(message); error.exitCode = code; error.evidence = evidence; throw error; }
function sanitize(text, env) {
  for (const name of ['db-password', 'db-root-password', 'admin-password']) {
    const path = join(env.FREEPLAST_LOCAL_STATE, name);
    if (existsSync(path) && !lstatSync(path).isSymbolicLink() && lstatSync(path).isFile()) {
      const secret = readFileSync(path, 'utf8').trim();
      if (secret) text = text.split(secret).join('[REDACTED]');
    }
  }
  return text.replace(/(https?:\/\/)[^\s/@]+:[^\s/@]+@/gi, '$1[REDACTED]@')
    .replace(/((?:password|passwd|token|secret|api[_-]?key)\s*[=:]\s*)(?:"[^"\n]*"|'[^'\n]*'|[^\s,;]+)/gi, '$1[REDACTED]')
    .replace(/(authorization\s*:\s*)[^\r\n]+/gi, '$1[REDACTED]')
    .replace(/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/g, '');
}
function run(args, env, { optional = false } = {}) {
  const result = spawnSync('docker', args, { env, encoding: 'utf8', maxBuffer: 8 * 1024 * 1024, timeout: 300000 });
  if (result.error || result.status !== 0) {
    if (optional && /no such volume/i.test(result.stderr || '')) return null;
    const evidence = sanitize([result.error?.message, result.stdout, result.stderr].filter(Boolean).join('\n'), env);
    fail(`Local container command failed (${args[0]} ${args[1] || ''}); inspect local logs/status. No automatic retry.`, 1,
      (evidence.length > 2400 ? '[truncated; last 2400 characters]\n' : '') + (evidence.slice(-2400) || `exit=${result.status}; signal=${result.signal}`));
  }
  return result.stdout.trim();
}
function privateState(state) {
  if (!existsSync(state) || lstatSync(state).isSymbolicLink() || (lstatSync(state).mode & 0o077) || lstatSync(state).uid !== process.getuid()) {
    fail('State must be an owned, non-symlink directory mode 0700. Run local init with a new private path.');
  }
}
function inside(path, root) { return path === root || path.startsWith(root + '/'); }
function main() {
  const argv = process.argv.slice(2);
  if (argv.length === 1 && ['--version', '-v', '-V'].includes(argv[0])) { console.log('0.1.0'); return; }
  const command = argv[0]?.startsWith('--') || !argv.length ? 'status' : argv.shift();
  if (!Object.hasOwn(commands, command)) fail(`Unknown command ${command}. ${help}`, 2);
  const flags = {};
  for (let i = 0; i < argv.length; i++) {
    const flag = argv[i];
    if (flag !== '--help' && !commands[command].includes(flag)) fail(`Unknown flag ${flag} for ${command}; valid: --help ${commands[command].join(' ')}`, 2);
    if (Object.hasOwn(flags, flag)) fail(`Duplicate flag ${flag}`, 2);
    flags[flag] = ['--cache', '--confirm'].includes(flag) ? argv[++i] : true;
    if (!flags[flag] || (typeof flags[flag] === 'string' && flags[flag].startsWith('--'))) fail(`Value required for ${flag}`, 2);
  }
  if (flags['--help']) { output({ command, flags: ['--help', ...commands[command]].join(' '), help }); return; }
  const project = process.env.FREEPLAST_LOCAL_PROJECT || 'freeplast-local';
  if (!/^freeplast-local(?:-[a-z0-9-]+)?$/.test(project)) fail('Project must be freeplast-local or freeplast-local-<suffix>.', 2);
  const port = process.env.FREEPLAST_LOCAL_PORT || '8131';
  if (!/^[1-9][0-9]{3,4}$/.test(port) || +port < 1024 || +port > 65535 || ['8091', '8121'].includes(port)) fail('Use an unused port 1024..65535, not existing proxy ports 8091/8121.', 2);
  const url = process.env.FREEPLAST_LOCAL_URL || `http://mliu:${port}`;
  if (![`http://mliu:${port}`, `http://localhost:${port}`, `http://127.0.0.1:${port}`].includes(url)) fail('URL must use http and mliu/localhost/127.0.0.1 with the configured port.', 2);
  const requestedState = process.env.FREEPLAST_LOCAL_STATE || join(homedir(), '.local/state', project);
  if (!isAbsolute(requestedState)) fail('FREEPLAST_LOCAL_STATE must be absolute.', 2);
  const state = resolve(requestedState);
  const existingParent = (() => { let p = state; while (!existsSync(p)) p = dirname(p); return p; })();
  if (realpathSync(existingParent) !== existingParent || inside(state, checkout) || inside(state, '/var/www')) fail('State must be outside checkout/webroot and must not traverse symlinks.', 2);
  const env = { ...process.env, FREEPLAST_LOCAL_STATE: state, FREEPLAST_LOCAL_PROJECT: project, FREEPLAST_LOCAL_PORT: port, FREEPLAST_LOCAL_URL: url, FREEPLAST_LOCAL_CHECKOUT: checkout, COMPOSE_DISABLE_ENV_FILE: '1' };
  delete env.COMPOSE_FILE; delete env.COMPOSE_PROFILES; delete env.COMPOSE_PROJECT_NAME; delete env.COMPOSE_ENV_FILES;
  const base = ['compose', '--env-file', '/dev/null', '-p', project, '-f', join(here, 'compose.yaml')];
  const compose = args => run([...base, ...args], env);
  const binding = { checkout, project, port, url };
  const deps = JSON.parse(readFileSync(join(here, '../woo-dependencies.json'), 'utf8'));
  function verifyVendor(dir, staged = false) {
    for (const [slug, dep] of Object.entries(deps)) {
      const path = join(dir, staged ? `${slug}.zip` : `${slug}-${dep.version}.zip`);
      if (!existsSync(path) || createHash('sha256').update(readFileSync(path)).digest('hex') !== dep.sha256) fail(`Missing or altered pinned archive ${path}; provide checked repository tool cache with init --cache.`);
    }
    if (staged && readFileSync(join(dir, 'dependencies.json'), 'utf8') !== JSON.stringify(deps)) fail('Staged dependency manifest differs from repository pins; investigate before resetting.');
  }
  function initialized() {
    privateState(state);
    if (!existsSync(join(state, 'owner.json')) || readFileSync(join(state, 'owner.json'), 'utf8') !== JSON.stringify(binding)) fail('State belongs to another checkout/project/URL or initialization is incomplete. Use its checkout/settings or a new project and state path.');
    for (const name of ['db-password', 'db-root-password', 'admin-password']) {
      const path = join(state, name);
      if (!existsSync(path) || lstatSync(path).isSymbolicLink() || (lstatSync(path).mode & 0o777) !== 0o444 || !/^[a-f0-9]{48}$/.test(readFileSync(path, 'utf8'))) fail('Private secret file invalid; expected mode 0444 under private 0700 state directory.');
    }
    const vendor = join(state, 'vendor');
    if ((lstatSync(vendor).mode & 0o777) !== 0o755 || lstatSync(vendor).isSymbolicLink()) fail('Vendor bind must be a real directory mode 0755 inside private state.');
    for (const name of ['dependencies.json', ...Object.keys(deps).map(slug => `${slug}.zip`)]) {
      const file = lstatSync(join(vendor, name));
      if (file.isSymbolicLink() || (file.mode & 0o777) !== 0o444) fail('Vendor files must be regular read-only bind inputs mode 0444.');
    }
    verifyVendor(vendor, true);
  }
  function localDaemon() {
    const endpoint = run(['context', 'inspect', '--format', '{{json .Endpoints.docker.Host}}'], env);
    if (process.env.DOCKER_HOST || !endpoint.startsWith('"unix://')) fail('Only a local Unix container endpoint is allowed; unset DOCKER_HOST and select a local context.');
    run(['info', '--format', '{{.ServerVersion}}'], env);
    for (const suffix of ['db_data', 'wp_data', 'capture']) {
      const labels = run(['volume', 'inspect', `${project}_${suffix}`, '--format', '{{json .Labels}}'], env, { optional: true });
      if (labels === null) continue;
      const value = JSON.parse(labels) || {};
      if (value['site.freeplast.local.state'] !== state || value['site.freeplast.local.checkout'] !== checkout) fail('Existing local volume ownership differs; refusing to reuse/delete it.');
    }
  }
  if (command === 'access') {
    output({ url, scope: 'Tailnet only; CLI never changes Serve or Funnel', inspect: 'tailscale serve status', proposal: `After separate authorization: tailscale serve --bg --http=${port} http://127.0.0.1:${port}`, warning: 'Preserve existing Serve endpoints. Verify PC and phone; use mliu URL for shared sessions/links.' }); return;
  }
  if (command === 'plan') {
    compose(['--profile', 'tools', 'config', '--quiet']);
    output({ status: 'ok', project, state, url, checkout, changes: 'init stages checked zips and private credentials; up starts isolated services; seed creates synthetic data; stop preserves volumes', help: 'npm run local -- init --execute; npm run local -- up --execute; npm run local -- seed --execute' }); return;
  }
  if (command === 'init') {
    const cache = resolve(flags['--cache'] || join(here, '../.tools/cache'));
    verifyVendor(cache);
    if (existsSync(state)) { initialized(); output({ status: 'already initialized', state }); return; }
    if (!flags['--execute']) { output({ status: 'plan', action: 'create private state and checked vendor copies', state, cache, help: 'Repeat init with --execute' }); return; }
    const temp = state + '.init-' + randomBytes(6).toString('hex');
    mkdirSync(dirname(state), { recursive: true });
    mkdirSync(temp, { mode: 0o700 });
    try {
      mkdirSync(join(temp, 'vendor'), { mode: 0o755 });
      chmodSync(join(temp, 'vendor'), 0o755);
      for (const name of ['db-password', 'db-root-password', 'admin-password']) {
        writeFileSync(join(temp, name), randomBytes(24).toString('hex'), { mode: 0o444, flag: 'wx' });
        chmodSync(join(temp, name), 0o444);
      }
      for (const [slug, dep] of Object.entries(deps)) { copyFileSync(join(cache, `${slug}-${dep.version}.zip`), join(temp, 'vendor', `${slug}.zip`)); chmodSync(join(temp, 'vendor', `${slug}.zip`), 0o444); }
      writeFileSync(join(temp, 'vendor/dependencies.json'), JSON.stringify(deps), { mode: 0o444 });
      chmodSync(join(temp, 'vendor/dependencies.json'), 0o444);
      writeFileSync(join(temp, 'owner.json'), JSON.stringify(binding), { mode: 0o600 });
      renameSync(temp, state);
    } finally { rmSync(temp, { force: true, recursive: true }); }
    output({ status: 'initialized', state, admin: 'local-admin', password_file: join(state, 'admin-password'), help: 'npm run local -- plan; npm run local -- up --execute' }); return;
  }
  if (command === 'status' && !existsSync(state)) {
    output({ bin: fileURLToPath(import.meta.url).replace(homedir(), '~'), description: 'Isolated persistent Freeplast local site', status: 'not initialized', project, state, help: 'npm run local -- init --help' }); return;
  }
  initialized();
  if (command === 'reset' && flags['--confirm'] !== project) fail(`Reset deletes ONLY ${project} database/site/capture volumes. Required: --confirm ${project}`, 2);
  if (commands[command].includes('--execute') && !flags['--execute']) {
    output({ status: 'plan', action: command, project, state, help: `npm run local -- ${command} --execute${command === 'reset' ? ` --confirm ${project}` : ''}` }); return;
  }
  localDaemon();
  compose(['--profile', 'tools', 'config', '--quiet']);
  if (command === 'doctor') output({ status: 'ok', project, secrets: '0444 files under owned 0700 parent; bind-readable by uid33 and database users', vendor: 'repository hashes verified', scope: 'configuration and ownership only; runtime HTTP/mail checks require runtime-check.php' });
  if (command === 'status') output({ status: 'ok', project, url, services: compose(['ps', '--all', '--format', 'json']) || '0 containers', help: 'npm run local -- logs; npm run local -- access' });
  if (command === 'up') {
    compose(['up', '-d', '--wait', '--wait-timeout', '180', 'db', 'wordpress']);
    output({ status: 'running', url, help: 'npm run local -- seed --execute (first run); npm run local -- status' });
  }
  if (command === 'seed') {
    compose(['run', '--rm', '-T', '--no-deps', '--entrypoint', '/bin/sh', 'cli', '/opt/freeplast/bootstrap.sh']);
    output({ status: 'seeded', url, admin: 'local-admin', password_file: join(state, 'admin-password'), help: 'npm run local -- capture --execute' });
  }
  if (command === 'stop' || command === 'reset') {
    compose(command === 'reset' ? ['down', '--volumes'] : ['stop']);
    output({ status: command === 'reset' ? 'volumes deleted; credentials/vendor retained' : 'stopped; data preserved', project, help: 'npm run local -- up --execute; after reset run seed --execute' });
  }
  if (command === 'logs') {
    const text = sanitize(compose(['logs', '--no-color', '--tail', '100']), env);
    output({ logs: text.slice(-12000) || '0 log lines', help: 'Last 100 lines per service, at most 12000 characters. Capture evidence: npm run local -- capture --execute' });
  }
  if (command === 'capture') {
    const destination = join(state, 'capture-' + Date.now());
    mkdirSync(destination, { mode: 0o700 });
    compose(['cp', 'wordpress:/var/freeplast/mail/.', destination]);
    output({ status: 'exported private mail/PDF evidence', path: destination });
  }
}
try { main(); } catch (error) { output({ error: error.message, ...(error.evidence ? { evidence: error.evidence } : {}), help }); process.exitCode = error.exitCode || 1; }
