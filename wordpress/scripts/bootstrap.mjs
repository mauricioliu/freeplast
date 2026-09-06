#!/usr/bin/env node
/**
 * Provision the disposable Freeplast WordPress + WooCommerce installation
 * (issue #2; Woo-era replacement per ADR-0001, issue #1 follow-ups #24/#27).
 *
 * Creates a clean WordPress + SQLite site under wordpress/.build/wp:
 *
 *   1. extracts pinned WordPress core (tools fetched by fetch-tools.sh),
 *   2. copies the repository's theme and the freeplast-woo adapter into wp-content,
 *   3. installs the sqlite-database-integration drop-in,
 *   4. installs and activates the pinned WooCommerce + Quotes for WooCommerce
 *      zips (woo-dependencies.json, hash-verified), creates the Woo pages
 *      and two featured test products,
 *   5. writes wp-config.php, installs WordPress, activates the theme and the
 *      adapter, and disables search-engine visibility.
 *
 * Idempotent when the build already exists (wordpress/.build/.provisioned.json):
 * wp-content is re-synced and the active components re-checked. Pass --fresh
 * to rebuild from scratch.
 */
import { execFileSync } from 'node:child_process';
import { copyFileSync, cpSync, existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
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

console.log('Bootstrapping disposable Freeplast WordPress + WooCommerce…');

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

/* 3. Theme + adapter from the repository */
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
wp(['theme', 'activate', 'freeplast']);
wp(['option', 'update', 'blog_public', '0']);
installWoo();
wp(['plugin', 'activate', 'freeplast-woo']);  // after Woo: the adapter declares Requires Plugins

/* 7. Marker */
const wpVersion = wp(['core', 'version']);
const phpVersion = sh(PHP, ['-r', 'echo PHP_VERSION;']).trim();
writeFileSync(
  join(BUILD_DIR, '.provisioned.json'),
  JSON.stringify({ wpVersion, phpVersion, siteUrl: SITE_URL, provisionedAt: new Date().toISOString(), adminUser: 'freeplast', adminPassword }, null, 2)
);
mkdirSync(join(WP_DIR, 'wp-content', 'database'), { recursive: true });
console.log(`  disposable WordPress ${wpVersion} + WooCommerce ready at ${SITE_URL} (admin credentials in .build/.provisioned.json)`);
console.log('  theme+adapter: freeplast / freeplast-woo (active, noindex on)');

function syncContent() {
  const content = join(WP_DIR, 'wp-content');
  mkdirSync(join(content, 'themes'), { recursive: true });
  mkdirSync(join(content, 'plugins'), { recursive: true });
  rmSync(join(content, 'themes', 'freeplast'), { recursive: true, force: true });
  rmSync(join(content, 'plugins', 'freeplast-woo'), { recursive: true, force: true });
  cpSync(join(WORDPRESS_DIR, 'wp-content', 'themes', 'freeplast'), join(content, 'themes', 'freeplast'), { recursive: true });
  cpSync(join(WORDPRESS_DIR, 'wp-content', 'plugins', 'freeplast-woo'), join(content, 'plugins', 'freeplast-woo'), { recursive: true });
}

/** The pinned Woo zips, hash-verified against woo-dependencies.json (downloaded once into .tools/cache). */
function pinnedWooZip(slug) {
  const deps = JSON.parse(readFileSync(join(WORDPRESS_DIR, 'woo-dependencies.json'), 'utf8'));
  const dep = deps[slug];
  if (!dep) throw Error(`woo-dependencies.json has no entry for ${slug}`);
  const dest = join(CACHE, `${slug}-${dep.version}.zip`);
  if (!existsSync(dest) || createHash('sha256').update(readFileSync(dest)).digest('hex') !== dep.sha256) {
    console.log(`  downloading ${slug} ${dep.version} (pinned)…`);
    sh('curl', ['-fsSL', '--retry', '3', '-o', `${dest}.part`, dep.url]);
    const digest = createHash('sha256').update(readFileSync(`${dest}.part`)).digest('hex');
    if (digest !== dep.sha256) throw Error(`${slug} zip hash mismatch: ${digest}`);
    execFileSync('mv', ['-f', `${dest}.part`, dest]);
  }
  return dest;
}

function installWoo() {
  for (const slug of ['woocommerce', 'quotes-for-woocommerce']) {
    const zip = pinnedWooZip(slug);
    if (wp(['plugin', 'list', '--format=csv', '--fields=name']).split('\n').includes(slug)) {
      wp(['plugin', 'activate', slug]);
    } else {
      wp(['plugin', 'install', zip, '--activate']);
    }
  }
  // Cart + checkout pages. install_pages creates the block checkout; staging deliberately
  // runs the CLASSIC checkout (ADR-0001: the quotes extension's address options are not
  // equivalent in Checkout Blocks), so the pages get the classic shortcodes.
  wp(['wc', 'tool', 'run', 'install_pages', '--user=1']);
  // Woo 11.x "coming soon" mode replaces store-page content for logged-out visitors and
  // its onboarding flows can enable it on a living stack (observed mid-run): pinned off
  // so the public pages under regression are always the real cart/checkout surfaces.
  wp(['option', 'update', 'woocommerce_coming_soon', 'no']);
  wp(['option', 'update', 'woocommerce_store_pages_only', 'no']);
  wp(['option', 'update', 'woocommerce_private_link', 'no']);
  const classicShortcodes = {
    woocommerce_cart_page_id: '[woocommerce_cart]',
    woocommerce_checkout_page_id: '[woocommerce_checkout]',
  };
  for (const [option, shortcode] of Object.entries(classicShortcodes)) {
    const id = wp(['option', 'get', option]);
    if (id) wp(['post', 'update', id, `--post_content=<!-- wp:shortcode -->${shortcode}<!-- /wp:shortcode -->`]);
  }
  // Two featured products (price 0 is the documented technical value enabling native purchasability),
  // each with the quotes extension's per-product flag (qwc_enable_quotes=on) so the checkout takes
  // the quotes gateway and the request stays pending — as on staging.
  for (const name of ['Caja Cosechera 3/4 (prueba)', 'Caja Universal (prueba)']) {
    const out = sh(PHP, [WPCLI, 'wc', 'product', 'create', `--name=${name}`, '--type=simple', '--regular_price=0', '--featured=1', '--user=1', `--url=${SITE_URL}`, '--quiet', '--porcelain'], { cwd: WP_DIR });
    wp(['post', 'meta', 'update', out.trim(), 'qwc_enable_quotes', 'on']);
  }
  wp(['cache', 'flush']);
}

function reactivate() {
  wp(['theme', 'activate', 'freeplast']);
  const active = wp(['plugin', 'list', '--status=active', '--format=csv', '--fields=name']).split('\n');
  if (active.includes('woocommerce') && active.includes('quotes-for-woocommerce')) {
    wp(['plugin', 'activate', 'freeplast-woo']);
  }
}
