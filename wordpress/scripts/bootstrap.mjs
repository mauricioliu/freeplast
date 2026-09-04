#!/usr/bin/env node
/**
 * Provision the disposable Freeplast WordPress installation (issue #2).
 *
 * Creates a clean WordPress + SQLite site under wordpress/.build/wp:
 *
 *   1. extracts pinned WordPress core (tools fetched by fetch-tools.sh),
 *   2. copies the repository's theme and plugin into wp-content,
 *   3. installs the sqlite-database-integration drop-in,
 *   4. writes wp-config.php, installs WordPress, activates the theme and
 *      the private plugin, and disables search-engine visibility.
 *
 * Idempotent when the build already exists (wordpress/.build/.provisioned.json).
 * Pass --fresh (or set FREEPLAST_KEEP_BUILD=0 after wiping) to rebuild.
 */
import { execFileSync } from 'node:child_process';
import { copyFileSync, cpSync, existsSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const WORDPRESS_DIR = dirname(HERE);
const BUILD_DIR = join(WORDPRESS_DIR, '.build');
const WP_DIR = join(BUILD_DIR, 'wp');
const TOOLS_DIR = join(WORDPRESS_DIR, '.tools');
const CACHE = join(TOOLS_DIR, 'cache');
const PHP = join(TOOLS_DIR, 'php', 'php');
const WPCLI = join(CACHE, 'wp-cli.phar');
const SITE_URL = process.env.FREEPLAST_TEST_URL || 'http://127.0.0.1:8091';

function sh(cmd, args, opts = {}) {
  return execFileSync(cmd, args, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], ...opts });
}

function wp(args) {
  return sh(PHP, [WPCLI, ...args, `--url=${SITE_URL}`, '--quiet'], { cwd: WP_DIR }).trim();
}

console.log('Bootstrapping disposable Freeplast WordPress…');

/* 1. Tools */
if (!existsSync(PHP) || !existsSync(WPCLI)) {
  console.log('  tools missing — fetching pinned toolchain');
  sh('bash', [join(HERE, 'fetch-tools.sh')], { stdio: 'inherit' });
}

/* 2. Clean WordPress core */
if (existsSync(join(BUILD_DIR, '.provisioned.json')) && !process.argv.includes('--fresh')) {
  console.log('  existing disposable installation found — re-syncing wp-content only');
  syncContent();
  reactivate();
  console.log('  disposable installation ready (existing build)');
  process.exit(0);
}

rmSync(BUILD_DIR, { recursive: true, force: true });
mkdirSync(WP_DIR, { recursive: true });
console.log('  extracting WordPress core…');
execFileSync('tar', ['-xzf', join(CACHE, 'wordpress.tar.gz'), '-C', WP_DIR, '--strip-components=1']);

/* 3. Theme + plugin from the repository */
syncContent();

/* 4. SQLite drop-in */
const sqliteSrc = join(CACHE, 'sqlite-database-integration');
if (!existsSync(sqliteSrc)) {
  execFileSync('unzip', ['-q', '-o', join(CACHE, 'sqlite-plugin.zip'), '-d', CACHE]);
}
cpSync(sqliteSrc, join(WP_DIR, 'wp-content', 'plugins', 'sqlite-database-integration'), { recursive: true });
copyFileSync(join(sqliteSrc, 'db.copy'), join(WP_DIR, 'wp-content', 'db.php'));

/* 5. wp-config.php */
const adminPassword = 'freeplast-' + createHash('sha256').update(String(Date.now())).digest('hex').slice(0, 12);
writeFileSync(
  join(WP_DIR, 'wp-config.php'),
  `<?php
define( 'DB_NAME', 'freeplast_disposable' );
define( 'DB_USER', 'db' );
define( 'DB_PASSWORD', 'db' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'fp_';

define( 'WP_HOME', '${SITE_URL}' );
define( 'WP_SITEURL', '${SITE_URL}' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'DISALLOW_FILE_EDIT', true );
/* Honor the Nginx-forwarded HTTPS scheme — mirrors the staging Compose
   WORDPRESS_CONFIG_EXTRA — so the checks exercise the same TLS seam the
   deployment uses (the basket cookie Secure flag follows the request
   scheme, issue #19). */
if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] ) {
  $_SERVER['HTTPS'] = 'on';
}
/* Deterministic scheduling for the disposable check: WP-Cron's loopback
   would fire scheduled events (notification delivery) at unpredictable
   moments mid-test. The check drives scheduled work explicitly; staging
   runs system cron (see wordpress/BUILD-DECISIONS.md). */
define( 'DISABLE_WP_CRON', true );
define( 'WP_CACHE', false );
define( 'AUTOSAVE_INTERVAL', 3600 );

/* That's all, stop editing! Happy publishing. */
if ( ! defined( 'ABSPATH' ) ) {
  define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
`
);

/* 6. Install, theme, plugin, noindex */
console.log('  installing WordPress (locale en_US — see BUILD-DECISIONS.md)…');
wp(['core', 'install', '--title=Freeplast', '--admin_user=freeplast', `--admin_password=${adminPassword}`, '--admin_email=admin@freeplast.local', '--skip-email']);
wp(['rewrite', 'structure', '/%postname%/']);
reactivate();
wp(['option', 'update', 'blog_public', '0']);

/* 7. Marker */
const wpVersion = wp(['core', 'version']);
const phpVersion = sh(PHP, ['-r', 'echo PHP_VERSION;']).trim();
writeFileSync(
  join(BUILD_DIR, '.provisioned.json'),
  JSON.stringify({ wpVersion, phpVersion, siteUrl: SITE_URL, provisionedAt: new Date().toISOString(), adminUser: 'freeplast', adminPassword }, null, 2)
);
mkdirSync(join(WP_DIR, 'wp-content', 'database'), { recursive: true });
console.log(`  disposable WordPress ${wpVersion} ready at ${SITE_URL} (admin credentials in .build/.provisioned.json)`);
console.log('  theme+plugin: freeplast / freeplast-catalog-quotes (active, noindex on)');

function syncContent() {
  const content = join(WP_DIR, 'wp-content');
  mkdirSync(join(content, 'themes'), { recursive: true });
  mkdirSync(join(content, 'plugins'), { recursive: true });
  rmSync(join(content, 'themes', 'freeplast'), { recursive: true, force: true });
  rmSync(join(content, 'plugins', 'freeplast-catalog-quotes'), { recursive: true, force: true });
  cpSync(join(WORDPRESS_DIR, 'wp-content', 'themes', 'freeplast'), join(content, 'themes', 'freeplast'), { recursive: true });
  cpSync(join(WORDPRESS_DIR, 'wp-content', 'plugins', 'freeplast-catalog-quotes'), join(content, 'plugins', 'freeplast-catalog-quotes'), { recursive: true });
}

function reactivate() {
  wp(['theme', 'activate', 'freeplast']);
  wp(['plugin', 'activate', 'freeplast-catalog-quotes']);
}
