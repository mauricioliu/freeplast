#!/usr/bin/env node
// No server, browser, device or remote calls. Clone only the disposable fixture.
import { cpSync, existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir, homedir } from 'node:os';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
const root = fileURLToPath(new URL('..', import.meta.url));
if (process.argv.length > 2) throw Error('No arguments supported. Run node wordpress/scripts/catalog-photos-native-test.mjs');
const source = join(root, '.build/wp');
if (!existsSync(join(source, 'wp-content/database/.ht.sqlite'))) throw Error('Existing disposable SQLite fixture required. No download or bootstrap is attempted.');
const temp = mkdtempSync(join(tmpdir(), 'freeplast-photo-test-'));
try {
  const wp = join(temp, 'wp');
  cpSync(source, wp, { recursive: true, dereference: true });
  const configPath = join(wp, 'wp-config.php');
  let config = readFileSync(configPath, 'utf8');
  if (!config.includes("'WP_ENVIRONMENT_TYPE', 'local'") || !config.includes("'DISABLE_WP_CRON', true")) throw Error('Fixture is not the known cron-disabled local config.');
  if (/define\s*\(\s*['"](?:FQDBDIR|DB_DIR|DB_FILE)['"]/.test(config)) throw Error('Refusing a fixture with an external database path.');
  config = config.replace('<?php', "<?php\ndefine('FP_PHOTO_FIXTURE', true);\ndefine('WP_HTTP_BLOCK_EXTERNAL', true);");
  config = config.replace(/define\( 'WP_(HOME|SITEURL)', '[^']+' \);/g, (_, key) => `define( 'WP_${key}', 'http://mliu:8091' );`);
  writeFileSync(configPath, config);
  writeFileSync(join(wp, 'wp-content/mu-plugins/000-photo-fixture-safety.php'), `<?php
add_filter('pre_http_request', static fn()=>new WP_Error('blocked', 'Photo fixture has no network'), PHP_INT_MIN);
add_filter('pre_wp_mail', '__return_true', PHP_INT_MIN);
`);
  cpSync(join(root, 'wp-content/themes/freeplast'), join(wp, 'wp-content/themes/freeplast'), { recursive: true });
  const result = spawnSync(join(root, '.tools/php/php'), [join(root, '.tools/cache/wp-cli.phar'), '--path=' + wp,
    'eval-file', resolve(root, 'scripts/catalog-photos-native-test.php')], { encoding: 'utf8', timeout: 180000 });
  if (result.status !== 0) throw Error(result.stdout + '\n' + result.stderr);
  process.stdout.write(result.stdout);
  const migration = resolve(root, 'scripts/migrate-catalog-photos-release.php');
  const runMigration = (mode, input='') => spawnSync(join(root, '.tools/php/php'), [join(root, '.tools/cache/wp-cli.phar'), '--path='+wp, 'eval-file', migration, mode, 'photo-fixture'], {encoding:'utf8', input, timeout:180000});
  const captured = runMigration('capture');
  if(captured.status!==0) throw Error(captured.stdout+captured.stderr);
  const baseline = JSON.parse(captured.stdout);
  if(baseline.products.length!==17) throw Error('Migration capture lacks 17 products');
  if(runMigration('apply',captured.stdout).status===0) throw Error('Migration applied without owned maintenance');
  const guard = spawnSync(join(root,'.tools/php/php'), [join(homedir(),'.agents/skills/wp-release/scripts/maintenance.php'),'hold','photo-fixture'], {cwd:wp,encoding:'utf8'});
  if(guard.status!==0) throw Error(guard.stderr);
  for(const mode of ['apply','verify','apply','verify']) {
    const result=runMigration(mode,captured.stdout);
    if(result.status!==0) throw Error('Sealed migration '+mode+' failed: '+result.stdout+result.stderr);
  }
  const wrong=JSON.stringify({...baseline,release:'foreign-release'});
  if(runMigration('apply',wrong).status===0) throw Error('Migration accepted a foreign baseline');
  console.log('sealed_photo_checks: 7 (capture, no guard rejected, apply, verify, repeat no-op, verify, foreign baseline rejected)');
  const importer = resolve(root, 'scripts/import-catalog-photos.php');
  const invoke = args => spawnSync(join(root, '.tools/php/php'), [join(root, '.tools/cache/wp-cli.phar'), '--path=' + wp, 'eval-file', importer, ...args], { encoding: 'utf8', timeout: 30000 });
  for (const [args, status, text] of [
    [['help'], 0, 'default: read-only plan'],
    [['version'], 0, '1.0.0'],
    [['unknown-command'], 2, 'unknown command'],
    [['apply'], 2, 'incorrect arguments'],
  ]) {
    const probe = invoke(args);
    if (probe.status !== status || !probe.stdout.includes(text)) throw Error('Importer CLI contract failed: ' + args.join(' ') + '\n' + probe.stdout + probe.stderr);
  }
  writeFileSync(configPath, config.replace("'WP_ENVIRONMENT_TYPE', 'local'", "'WP_ENVIRONMENT_TYPE', 'staging'"));
  const blocked = invoke(['apply', join(root, 'data/catalog-photos/manifest.json'), '0'.repeat(64), 'http://mliu:8091']);
  if (blocked.status !== 1 || !blocked.stdout.includes('Live import blocked')) throw Error('Live import guard failed: ' + blocked.stdout + blocked.stderr);
  console.log('photo_cli_checks: 5 (help, version, invalid command, incomplete apply, live import blocked)');
} finally { rmSync(temp, { recursive: true, force: true }); }
