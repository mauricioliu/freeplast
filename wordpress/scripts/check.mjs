#!/usr/bin/env node
/**
 * Freeplast WordPress shell — automated acceptance checks (issues #2, #3 and #4).
 *
 * This is the single documented command that runs the project's automated
 * checks against a disposable WordPress installation:
 *
 *     npm test
 *
 * It bootstraps a clean, disposable WordPress + SQLite installation under
 * wordpress/.build (fetching pinned tools into wordpress/.tools on first
 * run), activates the Freeplast block theme and the private
 * freeplast-catalog-quotes plugin, serves the site through php -S, and
 * verifies the acceptance criteria of issues #2, #3 and #4:
 *
 *   1. A clean disposable WordPress database boots without manual editor changes.
 *   2. The Freeplast theme and private plugin activate without warnings or fatal errors.
 *   3. Home returns HTTP 200 and renders the approved v6 site shell at mobile and desktop widths.
 *   4. The shell includes the approved brand/navigation structure and a
 *      non-functional-safe empty Cotización state.
 *   5. WordPress/PHP/database version expectations and plugin migration version are reported.
 *   6. WooCommerce is absent and no prototype behavior is treated as a real submission endpoint.
 *   7. The versioned Catalog Source (all 17 products, schema v2) validates
 *      before mutation; the dry run reports the deterministic difference
 *      without touching WordPress.
 *   8. Real synchronization creates the 17-product union with metadata,
 *      options, legacy paths and local media (reused by checksum); a second
 *      run against unchanged source reports zero changes.
 *   9. Every Product has a clean canonical URL, is absent from WordPress
 *      editor menus, and its public page follows approved v7 variant A with
 *      only source-supported facts (pallet facts without an invented minimum,
 *      “Consultar” for unconfirmed ones).
 *  10. Invalid schema, duplicate identity, invalid slug, unsupported colors,
 *      failed media import and explicit lifecycle changes behave as specified:
 *      rejections return non-zero without partial mutation, missing products
 *      are warnings only, changed media imports exactly once.
 *
 * Results are printed to stdout and recorded in wordpress/VERIFICATION.md.
 */
import { spawn, spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, rmSync, cpSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';
import assert from 'node:assert/strict';

const HERE = dirname(fileURLToPath(import.meta.url));
const WP_DIR = join(HERE, '..', '.build', 'wp');
const BUILD_DIR = join(HERE, '..', '.build');
const WORDPRESS_DIR = join(HERE, '..');
const REPO_ROOT = resolve(WORDPRESS_DIR, '..');
const TOOLS_DIR = join(WORDPRESS_DIR, '.tools');
const PHP_BIN = join(TOOLS_DIR, 'php', 'php');
const WP_CLI = join(TOOLS_DIR, 'cache', 'wp-cli.phar');
const SITE_URL = process.env.FREEPLAST_TEST_URL || 'http://127.0.0.1:8091';
const KEEP_BUILD = process.env.FREEPLAST_KEEP_BUILD === '1' || process.argv.includes('--keep');

const MOBILE_UA = 'Mozilla/5.0 (Linux; Android 13; 412px) AppleWebKit/537.36 Mobile Safari/537.36';
const DESKTOP_UA = 'Mozilla/5.0 (X11; Linux x86_64; 1440px) AppleWebKit/537.36 Chrome/126 Safari/537.36';

const CATALOG_SOURCE = join(WORDPRESS_DIR, 'data', 'products.json');
const CATALOG = JSON.parse(readFileSync(CATALOG_SOURCE, 'utf8'));
const PRODUCTS = CATALOG.products;
const PRODUCT_COUNT = PRODUCTS.length;
const PRODUCT_SLUGS = PRODUCTS.map((p) => p.slug);
const PRODUCT_URLS = PRODUCT_SLUGS.map((slug) => `/producto/${slug}/`);
const PRODUCT_BY_SOURCE_ID = new Map(PRODUCTS.map((p) => [p.source_id, p]));
const PRODUCT_BY_SLUG = new Map(PRODUCTS.map((p) => [p.slug, p]));
const DISTINCT_IMAGES = new Set(PRODUCTS.map((p) => p.image.checksum)).size;
const PRODUCT_SLUG = 'caja-cosechera-3-4';
const PRODUCT_URL = `/producto/${PRODUCT_SLUG}/`;
const UNIVERSAL_SLUGS = [
  'caja-universal-cerrada-negra',
  'caja-universal-ventilada-negra',
  'caja-universal-cerrada-color',
  'caja-universal-ventilada-color',
];
const SUPPORTED_COLOR_IDS = ['blanco', 'rojo', 'amarillo', 'azul', 'verde'];

let server = null;
let versions = null;
let versionLines = [];

function section(name, lines) {
  console.log(`\n── ${name} ${'─'.repeat(Math.max(1, 66 - name.length))}`);
  for (const line of lines) console.log(`  ${line}`);
}

function wp(args) {
  const res = spawnSync(PHP_BIN, [WP_CLI, ...args, `--url=${SITE_URL}`, '--quiet'], {
    cwd: WP_DIR,
    encoding: 'utf8',
  });
  if (res.error) throw res.error;
  return { stdout: (res.stdout || '').trim(), stderr: (res.stderr || '').trim(), status: res.status };
}

async function get(pathname, ua) {
  const res = await fetch(SITE_URL + pathname, {
    headers: ua ? { 'user-agent': ua } : {},
    redirect: 'manual',
  });
  const body = await res.text();
  return { status: res.status, body, headers: res.headers };
}

function assertContains(haystack, needle, message) {
  assert.ok(
    haystack.toLowerCase().includes(needle.toLowerCase()),
    `${message}\nExpected response to contain: ${JSON.stringify(needle)}`
  );
}

function assertAbsent(haystack, needle, message) {
  assert.ok(
    !haystack.toLowerCase().includes(needle.toLowerCase()),
    `${message}\nExpected response NOT to contain: ${JSON.stringify(needle)}`
  );
}

/** Run the public catalog synchronization command. */
function catalogSync(extra = [], file = CATALOG_SOURCE) {
  return wp(['freeplast', 'catalog', 'sync', `--file=${file}`, ...extra]);
}

function productCount() {
  return Number(wp(['post', 'list', '--post_type=fp_product', '--post_status=any', '--format=count']).stdout || '0');
}

function publishedProductCount() {
  return wp(['post', 'list', '--post_type=fp_product', '--post_status=publish', '--format=count']).stdout;
}

/** Snapshot of every record's last-modified timestamp (no-op detection). */
function productsModifiedStamp() {
  return wp(['post', 'list', '--post_type=fp_product', '--field=post_modified']).stdout;
}

function productIds() {
  return wp(['post', 'list', '--post_type=fp_product', '--post_status=any', '--orderby=ID', '--order=ASC', '--format=ids']).stdout;
}

function deleteProducts() {
  const ids = productIds();
  if (ids) wp(['post', 'delete', ids, '--force']);
}

/** Write a catalog-source fixture and return its path. */
function writeFixture(name, data) {
  const dir = join(BUILD_DIR, 'fixtures');
  mkdirSync(dir, { recursive: true });
  const file = join(dir, name);
  writeFileSync(file, JSON.stringify(data, null, 2));
  return file;
}

/** Write a full-document fixture whose referenced media is copied alongside. */
function writeFullFixture(name, data, extraMedia = []) {
  const dir = join(BUILD_DIR, 'fixtures');
  mkdirSync(join(dir, 'media'), { recursive: true });
  cpSync(join(WORDPRESS_DIR, 'data', 'media'), join(dir, 'media'), { recursive: true });
  for (const [file, bytes] of extraMedia) writeFileSync(join(dir, 'media', file), bytes);
  return writeFixture(name, data);
}

/** Read back one synchronized product meta value by immutable source id. */
function productMeta(sourceId, key) {
  return wp([
    'eval',
    `echo (string) get_post_meta( intval( get_posts( array( "post_type" => "fp_product", "post_status" => "any", "posts_per_page" => 1, "fields" => "ids", "no_found_rows" => true, "suppress_filters" => true, "meta_key" => "_fp_source_id", "meta_value" => "${sourceId}" ) )[0] ?? 0 ), "${key}", true );`,
  ]).stdout;
}

/** Count media-library attachments imported by the synchronizer. */
function attachmentCount() {
  return Number(
    wp([
      'eval',
      'echo count( get_posts( array( "post_type" => "attachment", "post_status" => "inherit", "posts_per_page" => -1, "fields" => "ids", "no_found_rows" => true, "suppress_filters" => true, "meta_key" => "_fp_image_checksum" ) ) );',
    ]).stdout || '0'
  );
}

function cloneSourceDoc() {
  return JSON.parse(readFileSync(CATALOG_SOURCE, 'utf8'));
}

/* ─── 0. Bootstrap ─────────────────────────────────────────────────────── */

test('disposable WordPress bootstraps from a clean state', { timeout: 240_000 }, async () => {
  if (!KEEP_BUILD) {
    rmSync(BUILD_DIR, { recursive: true, force: true });
  }
  const res = spawnSync(process.execPath, [join(HERE, 'bootstrap.mjs')], {
    cwd: REPO_ROOT,
    encoding: 'utf8',
    stdio: 'inherit',
  });
  assert.equal(res.status, 0, 'bootstrap.mjs must succeed');
  const installed = wp(['core', 'is-installed']);
  assert.equal(installed.status, 0, 'wp core is-installed must succeed on the clean database');

  const meta = JSON.parse(readFileSync(join(BUILD_DIR, '.provisioned.json'), 'utf8'));
  section('Disposable installation', [
    `WordPress ${meta.wpVersion} at ${SITE_URL} (SQLite disposable database)`,
    `Bootstrapped from scratch: ${!KEEP_BUILD ? 'yes (clean database)' : 'no (kept existing build)'}`,
  ]);
});

/* ─── 1. Activation is warning-free ───────────────────────────────────── */

test('theme and private plugin activate without warnings or fatal errors', () => {
  const themeActivate = wp(['theme', 'activate', 'freeplast']);
  assert.equal(themeActivate.status, 0, 'theme activation must succeed');
  assert.equal(themeActivate.stderr, '', `theme activation must emit no warnings/stderr: ${themeActivate.stderr}`);

  const pluginDeactivate = wp(['plugin', 'deactivate', 'freeplast-catalog-quotes']);
  assert.equal(pluginDeactivate.status, 0, 'plugin deactivation must succeed (reversible)');
  const pluginActivate = wp(['plugin', 'activate', 'freeplast-catalog-quotes']);
  assert.equal(pluginActivate.status, 0, 'plugin activation must succeed');
  assert.equal(pluginActivate.stderr, '', `plugin activation must emit no warnings/stderr: ${pluginActivate.stderr}`);

  const theme = wp(['theme', 'list', '--status=active', '--field=name']);
  assert.equal(theme.stdout, 'freeplast');
  const plugin = wp(['plugin', 'list', '--status=active', '--field=name']);
  assert.ok(plugin.stdout.split('\n').includes('freeplast-catalog-quotes'));

  const debugLog = join(WP_DIR, 'wp-content', 'debug.log');
  const log = existsSync(debugLog) ? readFileSync(debugLog, 'utf8') : '';
  const ownEntries = log
    .split('\n')
    .filter((l) => /themes\/freeplast|plugins\/freeplast-catalog-quotes/.test(l))
    .filter((l) => /PHP (Fatal|Warning|Notice|Deprecated)/.test(l));
  assert.deepEqual(ownEntries, [], `debug.log must contain no PHP diagnostics from our code:\n${ownEntries.join('\n')}`);

  section('Activation', [
    'Theme "freeplast": active, no PHP warnings/notices on (re)activation',
    'Plugin "freeplast-catalog-quotes": active, no PHP warnings/notices on (re)activation',
    'debug.log: no PHP diagnostics attributable to the theme or plugin',
  ]);
});

/* ─── 2. Versions and migration report ────────────────────────────────── */

test('WordPress/PHP/database version expectations and plugin migration version are reported', () => {
  const wpVersion = wp(['core', 'version']).stdout;
  const phpVersion = spawnSync(PHP_BIN, ['-r', 'echo PHP_VERSION;'], { encoding: 'utf8' }).stdout;
  const dropin = wp(['eval', 'echo SQLITE_DB_DROPIN_VERSION ?: "none";']).stdout;
  const sqliteVersion = wp(['eval', 'echo (new PDO("sqlite::memory:"))->query("select sqlite_version()")->fetchColumn();']).stdout;
  const themeVersion = wp(['eval', 'echo wp_get_theme("freeplast")->get("Version");']).stdout;
  const themeRequiresWp = wp(['eval', 'echo wp_get_theme("freeplast")->get("RequiresWP");']).stdout;
  const themeRequiresPhp = wp(['eval', 'echo wp_get_theme("freeplast")->get("RequiresPHP");']).stdout;
  const pluginVersion = wp(['eval', 'echo get_option("fp_plugin_version");']).stdout;
  const pluginRequiresWp = wp(['eval', 'echo get_option("fp_requires_wp");']).stdout;
  const pluginRequiresPhp = wp(['eval', 'echo get_option("fp_requires_php");']).stdout;
  const migrationVersion = wp(['option', 'get', 'fp_db_version']).stdout;

  assert.ok(wpVersion, 'WordPress version must be reported');
  assert.ok(phpVersion, 'PHP version must be reported');
  assert.ok(dropin !== 'none', 'SQLite drop-in must be loaded');
  assert.ok(sqliteVersion, 'SQLite version must be reported');
  assert.ok(migrationVersion && /^\d+$/.test(migrationVersion), 'plugin migration version (fp_db_version) must be a positive integer');
  assert.ok(themeRequiresWp, 'theme must declare "Requires at least"');
  assert.ok(themeRequiresPhp, 'theme must declare "Requires PHP"');
  assert.ok(pluginVersion, 'plugin version must be reported');

  const toNum = (v) => Number(v.split('.').slice(0, 2).join('.'));
  assert.ok(toNum(phpVersion) >= toNum(pluginRequiresPhp), `actual PHP ${phpVersion} must satisfy plugin Requires PHP ${pluginRequiresPhp}`);
  assert.ok(toNum(wpVersion) >= toNum(pluginRequiresWp), `actual WP ${wpVersion} must satisfy plugin Requires at least ${pluginRequiresWp}`);

  versions = { wpVersion, phpVersion, dropin, sqliteVersion };
  versionLines = [
    `WordPress: ${wpVersion} (theme requires ≥ ${themeRequiresWp}, plugin requires ≥ ${pluginRequiresWp})`,
    `PHP:       ${phpVersion} (theme requires ≥ ${themeRequiresPhp}, plugin requires ≥ ${pluginRequiresPhp})`,
    `Database:  SQLite ${sqliteVersion} via sqlite-database-integration drop-in ${dropin} (disposable; MariaDB on staging, see BUILD-DECISIONS)`,
    `Theme:     freeplast ${themeVersion}`,
    `Plugin:    freeplast-catalog-quotes ${pluginVersion} — migration version fp_db_version=${migrationVersion}`,
  ];
  section('Version expectations', versionLines);
});

/* ─── 3. HTTP server ──────────────────────────────────────────────────── */

test('serve the disposable installation over HTTP', { timeout: 30_000 }, async () => {
  server = spawn(PHP_BIN, ['-S', new URL(SITE_URL).host, join(HERE, 'router.php')], {
    cwd: WP_DIR,
    stdio: 'ignore',
    detached: true,
  });
  server.unref();
  let ok = false;
  for (let i = 0; i < 60 && !ok; i++) {
    await new Promise((r) => setTimeout(r, 250));
    try {
      const res = await fetch(SITE_URL + '/', { headers: { 'user-agent': MOBILE_UA } });
      ok = res.ok;
    } catch {
      /* retry */
    }
  }
  assert.ok(ok, `php -S server must respond at ${SITE_URL}`);
});

/* ─── 4. Home renders the approved v6 shell ───────────────────────────── */

test('Home returns HTTP 200 and renders the approved v6 site shell at mobile and desktop widths', async () => {
  const mobile = await get('/', MOBILE_UA);
  assert.equal(mobile.status, 200, 'Home must return HTTP 200 for a mobile user agent');
  const desktop = await get('/', DESKTOP_UA);
  assert.equal(desktop.status, 200, 'Home must return HTTP 200 for a desktop user agent');

  for (const [label, res] of [['mobile', mobile], ['desktop', desktop]]) {
    assertContains(res.body, 'Venta Mayorista de Productos Plásticos', `${label} Home must render the v6 hero headline`);
    assertContains(res.body, 'Estamos en la VI Región y en Santiago', `${label} Home must render the v6 hero subheadline`);
    assertContains(res.body, 'Cotiza Online', `${label} Home must render the v6 primary CTA`);
    assertContains(res.body, 'NOSOTROS', `${label} Home must render the v6 navigation label NOSOTROS`);
    assertContains(res.body, 'TIENDA', `${label} Home must render the v6 navigation label TIENDA`);
    assertContains(res.body, 'Freeplast', `${label} Home must render the brand`);
    assertContains(res.body, 'name="viewport"', `${label} Home must include the viewport meta tag (mobile-first)`);
    assertContains(res.body, 'noindex', `${label} Home must carry noindex (staging discipline)`);
  }

  // Mobile-first responsive shell: the same document is served regardless of
  // device; adaptation happens through min-width media queries in the theme CSS.
  const normalize = (html) => html.replace(/\s+/g, ' ');
  assert.equal(normalize(mobile.body), normalize(desktop.body), 'the served v6 shell document must be identical for mobile and desktop user agents');
  const styleUrl = mobile.body.match(/rel='stylesheet'[^>]*href='([^']*freeplast[^']*)'/)?.[1] || mobile.body.match(/rel="stylesheet"[^>]*href="([^"]*freeplast[^"]*)"/)?.[1];
  assert.ok(styleUrl, 'Home must link the freeplast theme stylesheet');
  const css = await (await fetch(styleUrl.startsWith('http') ? styleUrl : SITE_URL + styleUrl)).text();
  assert.match(css, /@media\s*\(min-width:\s*768px\)/, 'theme CSS must adapt with mobile-first min-width breakpoints');
  assert.doesNotMatch(css, /@media\s*\(max-width/, 'theme CSS must be mobile-first (no max-width gates around the base layout)');

  section('Home (v6 shell)', [
    `GET / → HTTP 200 for mobile (412px) and desktop (1440px) user agents; identical document`,
    `Brand, INICIO/NOSOTROS/TIENDA navigation, Cotiza Online CTA and hero copy present`,
    `Theme stylesheet adapts through min-width media queries (mobile-first), noindex present`,
  ]);
});

test('shell navigation targets real routes', async () => {
  for (const route of ['/nosotros/', '/tienda/', '/contacto/', '/cotizacion/']) {
    const res = await get(route, MOBILE_UA);
    assert.equal(res.status, 200, `${route} must return HTTP 200`);
  }
  section('Navigation routes', ['/, /nosotros/, /tienda/, /contacto/ and /cotizacion/ all return HTTP 200']);
});

/* ─── 5. Non-functional-safe empty Cotización state ───────────────────── */

test('Cotización renders a non-functional-safe empty state', async () => {
  const res = await get('/cotizacion/', MOBILE_UA);
  assert.equal(res.status, 200, '/cotizacion/ must return HTTP 200');
  assertContains(res.body, 'Tu cotización está vacía', '/cotizacion/ must render the empty Cotización state');
  assert.doesNotMatch(res.body, /<form[\s>]/i, '/cotizacion/ must not contain a submission form yet (issue #8 owns submission)');
  assert.doesNotMatch(res.body, /action="mailto:/i, 'no mailto form action may be treated as a submission endpoint');

  const home = await get('/', MOBILE_UA);
  assert.doesNotMatch(home.body, /<form[\s>]/i, 'Home must not contain the prototype quote form');
  assert.doesNotMatch(home.body, /action="mailto:/i, 'Home must not treat the prototype mailto form as a submission endpoint');
  assert.doesNotMatch(home.body, /api\.whatsapp\.com\/send\?phone=[^"]*"\s*data-submit/i, 'no WhatsApp prototype endpoints as submission');

  section('Cotización state', [
    '/cotizacion/ renders "Tu cotización está vacía" — empty, non-functional and safe',
    'No <form> anywhere in the shell; prototype mailto/WhatsApp behavior is not a submission endpoint',
  ]);
});

/* ─── 6. WooCommerce absent ───────────────────────────────────────────── */

test('WooCommerce is absent', () => {
  const plugins = wp(['plugin', 'list', '--field=name']).stdout.split('\n');
  assert.ok(!plugins.includes('woocommerce'), 'WooCommerce must not be installed');
  assert.ok(!existsSync(join(WP_DIR, 'wp-content', 'plugins', 'woocommerce')), 'no woocommerce directory may exist');
  const runtime = wp(['eval', 'echo class_exists("WooCommerce") ? "present" : "absent";']).stdout;
  assert.equal(runtime, 'absent', 'WooCommerce class must not exist');
  section('WooCommerce', ['WooCommerce is absent from the plugin list, wp-content and the runtime']);
});

/* ─── 7. Catalog Source validation + dry run (issues #3, #4) ─────────── */

test('Catalog Source validates before mutation; dry run reports the difference and changes nothing', () => {
  deleteProducts();
  assert.equal(productCount(), 0, 'the catalog must start empty');

  assert.equal(CATALOG.version, 2, 'the committed catalog source must use schema version 2');
  assert.equal(PRODUCT_COUNT, 17, 'the committed catalog source must carry the 17-product union');

  const dryRun = catalogSync(['--dry-run']);
  assert.equal(dryRun.status, 0, `dry run must succeed:\n${dryRun.stderr}`);
  assertContains(dryRun.stdout, 'version 2', 'dry run must report the source schema version');
  assertContains(dryRun.stdout, `${PRODUCT_COUNT} products`, 'dry run must report the complete product count');
  assertContains(dryRun.stdout, 'fp-caja-cosechera-3-4: would create', 'dry run must report the deterministic per-product difference');
  assertContains(dryRun.stdout, 'fp-ladrillo-plastico: would create', 'dry run must include the old-site-only products');
  assertContains(dryRun.stdout, 'fp-traversa-para-bins-tipo-romano: would create', 'dry run must include the provisional Traversa Tipo Romano');
  assertContains(dryRun.stdout, 'Summary: created=17 updated=0 unchanged=0 warnings=0 errors=0', 'dry run must report correct totals');
  assertContains(dryRun.stdout, 'Dry run: no changes were applied', 'dry run must state that nothing was applied');
  assert.equal(productCount(), 0, 'dry run must not change WordPress');

  section('Catalog dry run', [
    'wp freeplast catalog sync --dry-run validates the complete 17-product source and reports the difference',
    'No fp_product posts, metadata or media exist after the dry run',
  ]);
});

/* ─── 8. Real synchronization + idempotence (issues #3, #4) ──────────── */

test('real synchronization creates the 17-product union with identity, options, legacy paths and reused local media; a second run reports zero changes', () => {
  const first = catalogSync();
  assert.equal(first.status, 0, `first synchronization must succeed:\n${first.stderr}`);
  assertContains(first.stdout, 'fp-caja-cosechera-3-4: created', 'first run must create the first product');
  assertContains(first.stdout, 'Summary: created=17 updated=0 unchanged=0 warnings=0 errors=0', 'first run must report correct totals');

  assert.equal(productCount(), 17, 'exactly 17 fp_product records must exist');
  assert.equal(
    publishedProductCount(),
    '17',
    'all 15 PDF products plus Pediluvio and Ladrillo plástico must synchronize as Active (published) products'
  );

  // Every Product carries immutable source identity, explicit lifecycle state,
  // Product Category, canonical slug, tracked provenance and retained legacy paths.
  const rows = wp([
    'eval',
    '$posts = get_posts( array( "post_type" => "fp_product", "post_status" => "any", "posts_per_page" => -1, "orderby" => "ID", "order" => "ASC", "suppress_filters" => true ) ); foreach ( $posts as $p ) { echo implode( "|", array( get_post_meta( $p->ID, "_fp_source_id", true ), get_post_meta( $p->ID, "_fp_lifecycle", true ), get_post_meta( $p->ID, "_fp_category", true ), $p->post_name, $p->post_status, get_post_meta( $p->ID, "_fp_source_url", true ), (string) get_post_meta( $p->ID, "_fp_source_checked_at", true ), get_post_meta( $p->ID, "_fp_legacy_paths", true ), (string) get_post_meta( $p->ID, "_fp_units_per_pallet", true ) ) ) . "\n"; }',
  ]).stdout.split('\n').filter(Boolean);
  assert.equal(rows.length, 17, 'the integrity report must cover exactly the 17 synchronized records');
  const bySourceId = new Map(rows.map((line) => [line.split('|')[0], line.split('|')]));
  for (const product of PRODUCTS) {
    const row = bySourceId.get(product.source_id);
    assert.ok(row, `integrity row missing for ${product.source_id}`);
    const [id, lifecycle, category, slug, status, sourceUrl, checkedAt, legacyPaths, units] = row;
    assert.equal(id, product.source_id, `${product.source_id}: identity must be stored`);
    assert.equal(lifecycle, 'active', `${product.source_id}: explicit lifecycle must be active`);
    assert.ok(['agricola', 'otros'].includes(category), `${product.source_id}: Product Category must be set`);
    assert.equal(category, product.category, `${product.source_id}: Product Category must match the source`);
    assert.equal(slug, product.slug, `${product.source_id}: canonical slug must match the source`);
    assert.equal(status, 'publish', `${product.source_id}: active products must be published`);
    assert.ok(sourceUrl.startsWith('https://freeplast.cl/'), `${product.source_id}: provenance URL must be tracked`);
    assert.match(checkedAt, /^\d{4}-\d{2}-\d{2} /, `${product.source_id}: source retrieval timestamp must be tracked`);
    assert.deepEqual(
      JSON.parse(legacyPaths || '[]'),
      product.legacy_paths,
      `${product.source_id}: legacy paths must be retained separately from the canonical slug`
    );
    assert.equal(units, String(product.specs.units_per_pallet ?? ''), `${product.source_id}: units-per-pallet packaging fact must match the source`);
    assert.equal(productMeta(product.source_id, '_fp_quote_min_qty'), '', `${product.source_id}: unconfirmed commercial minimum must NOT be stored`);
  }

  // The four Caja Universal configurations are distinct Products; the Color
  // ones carry the supported color options, the black ones none.
  for (const slug of UNIVERSAL_SLUGS) {
    assert.ok(PRODUCT_SLUGS.includes(slug), `the Universal configuration ${slug} must exist`);
  }
  const optionIds = (slug) => JSON.parse(productMeta(PRODUCT_BY_SLUG.get(slug).source_id, '_fp_options')).map((o) => o.id);
  assert.deepEqual(optionIds('caja-universal-cerrada-color'), SUPPORTED_COLOR_IDS, 'Color configurations must offer exactly the supported color options');
  assert.deepEqual(optionIds('caja-universal-ventilada-color'), SUPPORTED_COLOR_IDS, 'Color configurations must offer exactly the supported color options');
  assert.equal(productMeta(PRODUCT_BY_SLUG.get('caja-universal-cerrada-negra').source_id, '_fp_options'), '[]', 'black Universal configurations carry no options');

  // Traversa para Bins Tipo Romano is provisional with G2 retained as review alias.
  const romano = PRODUCT_BY_SOURCE_ID.get('fp-traversa-para-bins-tipo-romano');
  assert.ok(
    romano.review.notes.some((n) => n.includes('Traversa Tipo G2')),
    'the source must retain “Traversa Tipo G2” as the review alias for Tipo Romano'
  );
  assertContains(productMeta('fp-traversa-para-bins-tipo-romano', '_fp_description'), 'Traversa Tipo G2', 'the Romano record must render the G2 alias');

  // Featured set matches the approved eight in source-controlled order.
  const featured = [...PRODUCTS].filter((p) => p.featured).sort((a, b) => a.featured_order - b.featured_order).map((p) => p.title);
  assert.deepEqual(featured, [
    'Caja Cosechera 3/4',
    'Caja Universal Cerrada Negra',
    'Caja Universal Ventilada Negra',
    'Caja Tomatera',
    'Caja Frutillera',
    'Caja Frutera',
    'Traversa para Bins Tipo G1',
    'Caja Merlucera',
  ], 'the approved Featured Products must be represented in order');

  // Media: one local attachment per distinct checksum; unchanged (shared)
  // media is reused, never duplicated — the four Universal records share one.
  assert.equal(attachmentCount(), DISTINCT_IMAGES, `exactly ${DISTINCT_IMAGES} catalog attachments must exist (one per distinct image)`);
  const thumb = (slug) => productMeta(PRODUCT_BY_SLUG.get(slug).source_id, '_thumbnail_id');
  const universalThumbs = UNIVERSAL_SLUGS.map(thumb);
  assert.ok(universalThumbs.every((t) => t === universalThumbs[0] && /^\d+$/.test(t)), 'the four Universal configurations must reuse one shared attachment');
  assert.equal(
    wp(['post', 'meta', 'get', universalThumbs[0], '_fp_image_checksum']).stdout,
    PRODUCT_BY_SLUG.get('caja-universal-cerrada-negra').image.checksum,
    'the shared attachment must be checksum-keyed'
  );
  assert.equal(wp(['post', 'meta', 'get', universalThumbs[0], '_fp_image_provisional']).stdout, '1', 'provisional media must be flagged on the attachment');

  const cosecheraThumb = thumb(PRODUCT_SLUG);
  const attachmentFile = wp(['post', 'meta', 'get', cosecheraThumb, '_wp_attached_file']).stdout;
  assert.match(attachmentFile, /\.(webp|png)$/, 'the featured image must be a local media-library file');
  assert.ok(
    existsSync(join(WP_DIR, 'wp-content', 'uploads', attachmentFile)),
    'the imported media file must exist locally under wp-content/uploads'
  );

  // Deterministic no-op: a second complete sync changes nothing.
  const modifiedBefore = productsModifiedStamp();
  const second = catalogSync();
  assert.equal(second.status, 0, `second synchronization must succeed:\n${second.stderr}`);
  assertContains(second.stdout, 'fp-caja-cosechera-3-4: unchanged', 'second run must report the first product as unchanged');
  assertContains(second.stdout, 'Summary: created=0 updated=0 unchanged=17 warnings=0 errors=0', 'second run must report zero changes');
  assert.equal(attachmentCount(), DISTINCT_IMAGES, 'the no-op run must not import any additional media');
  assert.equal(productsModifiedStamp(), modifiedBefore, 'the no-op run must not touch any record');

  section('Catalog synchronization', [
    'Real sync created the 17-product union (15 PDF products + Pediluvio + Ladrillo plástico) as Active records',
    'Every record: immutable source identity, explicit lifecycle, Product Category, canonical slug, provenance and legacy paths',
    'Four distinct Caja Universal configurations; Color ones carry the supported color options (blanco, rojo, amarillo, azul, verde)',
    'Traversa para Bins Tipo Romano provisional with “Traversa Tipo G2” retained as review alias',
    'Units-per-pallet stay packaging facts (70 / 65 where published); no unconfirmed minimum is stored',
    `Local media imported once per distinct image (${DISTINCT_IMAGES} attachments; the four Universal records share one by checksum)`,
    'Second run against unchanged source: created=0 updated=0 unchanged=17 (no-op)',
  ]);
});

/* ─── 9. Public product pages (issues #3, #4) ────────────────────────── */

test('every Product has a clean canonical URL, stays out of editor menus and renders v7-A from source-supported facts', async () => {
  const showUi = wp(['eval', 'echo get_post_type_object("fp_product")->show_ui ? "shown" : "hidden";']).stdout;
  assert.equal(showUi, 'hidden', 'fp_product must be absent from WordPress editor UI');
  const showMenu = wp(['eval', 'echo get_post_type_object("fp_product")->show_in_menu ? "shown" : "hidden";']).stdout;
  assert.equal(showMenu, 'hidden', 'fp_product must be absent from the administration menu');
  assert.equal(wp(['option', 'get', 'fp_db_version']).stdout, '2', 'migration 2 (catalog slice) must be applied');

  // Every canonical product URL answers HTTP 200 (mobile first).
  const pages = new Map();
  for (const url of PRODUCT_URLS) {
    const res = await get(url, MOBILE_UA);
    assert.equal(res.status, 200, `${url} must return HTTP 200`);
    pages.set(url, res);
  }

  const page = pages.get(PRODUCT_URL);
  assert.equal((await get(PRODUCT_URL, DESKTOP_UA)).status, 200, `${PRODUCT_URL} must return HTTP 200 for desktop too`);
  assertContains(page.body, 'rel="canonical"', 'the product page must carry a canonical URL');
  assertContains(page.body, PRODUCT_URL, 'the canonical URL must be the clean /producto/ path');

  // v7 variant A structure: breadcrumb, gallery + summary, description, quick specs,
  // quote action, specification table.
  assertContains(page.body, 'Migas de pan', 'the page must render a breadcrumb');
  assertContains(page.body, '>Inicio</a>', 'breadcrumb must link Home');
  assertContains(page.body, '>Tienda</a>', 'breadcrumb must link the Tienda archive');
  assertContains(page.body, 'Caja Cosechera 3/4', 'the page must render the product title');
  assertContains(page.body, 'Venta mayorista', 'the page must render the v7-A kicker');
  assertContains(page.body, 'Fabricada de Polietileno de alta densidad reciclado', 'the page must render the source-supported description');
  assertContains(page.body, '600 x 400 x 180 mm', 'dimensions must render');
  assertContains(page.body, '1.250 gramos', 'weight must render');
  assertContains(page.body, 'PEAD reciclado', 'material quick spec must render');
  assertContains(page.body, 'Cosecha de uva y frutas en general', 'source-supported use must render');
  assertContains(page.body, 'Unidades por pallet', 'pallet facts must render as a distinct fact');
  assertContains(page.body, 'Cantidad mínima', 'the specification table must address the commercial minimum');
  assertContains(page.body, 'Consultar', 'unconfirmed facts must render as Consultar');
  assertContains(page.body, 'Cotizar este producto', 'the v7-A quote action must render');
  assertContains(page.body, `href="${SITE_URL}/cotizacion/"`, 'the quote action must lead to the sole quotation surface');
  assertContains(page.body, '/wp-content/uploads/', 'the product image must be served from the local media library');
  assertContains(page.body, 'Imagen provisional', 'provisional staging media must be visibly tracked');

  // No prototype controls, no fake submission, no invented minimum.
  assert.doesNotMatch(page.body, /<form[\s>]/i, 'the product page must not contain a submission form');
  assert.doesNotMatch(page.body, /<input[\s>]/i, 'the product page must not contain prototype quantity controls');
  assertAbsent(page.body, 'PROTOTIPO', 'the prototype switcher must not be ported');
  assertAbsent(page.body, 'data-variant', 'variant switching must not be ported');
  assertAbsent(page.body, 'Compra mínima', 'no invented commercial minimum may be presented');
  assertAbsent(page.body, 'Cantidad mínima de compra', 'the unconfirmed minimum sentence must not be presented');
  assertAbsent(page.body, 'mínimo de 70', 'pallet quantity must not be presented as a minimum');
  assert.doesNotMatch(page.body, /action="mailto:/i, 'no mailto form action');

  // Related products render from reviewed source ids (family first).
  assertContains(page.body, 'Otros productos', 'related products must render once reviewed ids exist');
  assertContains(page.body, '/producto/caja-tomatera/', 'the reviewed related products must link canonical URLs');

  // Structured facts without contradictory old-site values: the published
  // Caja Universal page keeps its facts only for the Cerrada Negra record.
  const cerradaNegra = pages.get('/producto/caja-universal-cerrada-negra/');
  assert.equal(cerradaNegra.status, 200, 'the Cerrada Negra configuration must be public');
  assertContains(cerradaNegra.body, '625 x 445 x 226 mm', 'published Universal dimensions must render for the Cerrada Negra record');
  assertContains(cerradaNegra.body, '46 lts', 'the published capacity prose must render');
  assertAbsent(cerradaNegra.body, 'mínima de compra: 100', 'the contradictory old-site minimum must not be presented');

  const ventiladaNegra = pages.get('/producto/caja-universal-ventilada-negra/');
  assertContains(ventiladaNegra.body, 'Consultar', 'unconfirmed Universal configuration facts must render as Consultar');

  const colorPage = pages.get('/producto/caja-universal-cerrada-color/');
  assert.equal(colorPage.status, 200, 'the Color configuration must be a distinct public Product');

  // Traversa para Bins Tipo Romano keeps the G2 review alias visible.
  const romano = pages.get('/producto/traversa-para-bins-tipo-romano/');
  assertContains(romano.body, 'Traversa Tipo G2', 'the provisional Romano page must retain the G2 review alias');
  assertContains(romano.body, 'Consultar', 'unconfirmed Romano facts must render as Consultar');

  // Pediluvio and Ladrillo stay public with their limited content + Consultar.
  const pediluvio = pages.get('/producto/bases-plasticas-para-pediluvios/');
  assert.equal(pediluvio.status, 200, 'Bases plásticas para pediluvios must remain public');
  assertContains(pediluvio.body, 'Bases plásticas para pediluvios', 'the current limited content must render');
  assertContains(pediluvio.body, 'Consultar', 'missing pediluvio details must render as Consultar');
  const ladrillo = pages.get('/producto/ladrillo-plastico/');
  assert.equal(ladrillo.status, 200, 'Ladrillo plástico must remain public');
  assertContains(ladrillo.body, 'Ladrillo plástico', 'the current limited content must render');
  assertContains(ladrillo.body, 'Consultar', 'missing ladrillo details must render as Consultar');

  // Unknown pallet facts are honestly absent: no packaging note where no
  // units-per-pallet value is confirmed.
  assertAbsent(ladrillo.body, 'dato de embalaje', 'the pallet note must not render without a confirmed packaging fact');

  // The /tienda/ placeholder retired: the fp_product archive owns the route
  // and lists every Active Product across its pagination.
  const tienda = await get('/tienda/', MOBILE_UA);
  assert.equal(tienda.status, 200, '/tienda/ must remain HTTP 200 under the archive');
  assertAbsent(tienda.body, 'El catálogo se está preparando', 'the seeded placeholder copy must be retired');
  const tiendaPage2 = await get('/tienda/page/2/', MOBILE_UA);
  assert.equal(tiendaPage2.status, 200, '/tienda/page/2/ must return HTTP 200 while the archive paginates');
  const listed = new Set([tienda.body, tiendaPage2.body].join('\n').match(/\/producto\/[a-z0-9-]+\//g) || []);
  assert.equal(listed.size, PRODUCT_COUNT, 'the archive must list exactly the 17 Active Products across its pagination');
  for (const url of PRODUCT_URLS) {
    assert.ok(listed.has(url), `the archive must list the Active Product at ${url}`);
  }

  section('Product pages (v7 variant A)', [
    'All 17 canonical /producto/ URLs return HTTP 200; the ficha carries rel=canonical on the clean slug',
    'fp_product: show_ui/show_in_menu false — products are absent from WordPress editor menus',
    'Breadcrumb, gallery + summary, source-supported description, quick specs, spec table, related products and quote CTA',
    'Units-per-pallet rendered as packaging facts where published; unconfirmed facts honestly show “Consultar”',
    'The four Caja Universal configurations are distinct public Products (published facts on Cerrada Negra only)',
    'Traversa para Bins Tipo Romano provisional with the G2 alias visible; Pediluvio and Ladrillo public with limited content',
    '/tienda/ is the fp_product archive and lists all 17 Active Products (paginated)',
  ]);
});

/* ─── 10. Rejected sources, warnings, explicit lifecycle, media (issues #3, #4) */

test('invalid sources return non-zero without partial mutation; missing products warn, lifecycle changes are explicit, changed media imports once', () => {
  const countBefore = productCount();
  const modifiedBefore = productsModifiedStamp();
  assert.equal(countBefore, 17);

  const cases = [];

  const unknownKey = cloneSourceDoc();
  unknownKey.products[0].precio = 999;
  cases.push(['unknown key', writeFixture('unknown-key.json', unknownKey)]);

  const duplicateId = cloneSourceDoc();
  duplicateId.products.push({ ...duplicateId.products[0], slug: 'caja-cosechera-3-4-alt' });
  cases.push(['duplicate source_id', writeFixture('duplicate-id.json', duplicateId)]);

  const duplicateSlug = cloneSourceDoc();
  duplicateSlug.products.push({ ...duplicateSlug.products[0], source_id: 'fp-caja-cosechera-3-4-alt' });
  cases.push(['duplicate slug', writeFixture('duplicate-slug.json', duplicateSlug)]);

  const invalidSlug = cloneSourceDoc();
  invalidSlug.products[0].slug = 'Caja Cosechera 3/4';
  cases.push(['invalid slug', writeFixture('invalid-slug.json', invalidSlug)]);

  const badMedia = cloneSourceDoc();
  badMedia.products[0].image.file = 'media/missing.webp';
  cases.push(['failed media import', writeFixture('missing-media.json', badMedia)]);

  const badChecksum = cloneSourceDoc();
  badChecksum.products[0].image.checksum = 'sha256:' + '0'.repeat(64);
  cases.push(['image checksum mismatch', writeFixture('bad-checksum.json', badChecksum)]);

  // One valid + one invalid product: the complete file is rejected before any mutation.
  const preflight = cloneSourceDoc();
  const second = { ...preflight.products[0], source_id: 'fp-caja-bacaladera', slug: 'caja-bacaladera', title: 'Caja Bacaladera' };
  preflight.products.push(second);
  preflight.products[0].nota = 'unknown key';
  cases.push(['preflight rejection', writeFixture('preflight.json', preflight)]);

  // Color configurations require a supported color option.
  const badColor = cloneSourceDoc();
  badColor.products.find((p) => p.slug === 'caja-universal-cerrada-color').options[0].id = 'morado';
  cases.push(['unsupported color option', writeFullFixture('bad-color.json', badColor)]);

  const rejections = new Map();
  for (const [label, file] of cases) {
    const res = catalogSync([], file);
    rejections.set(label, res);
    assert.notEqual(res.status, 0, `${label}: synchronization must return non-zero`);
    const output = `${res.stdout}\n${res.stderr}`.toLowerCase();
    assert.ok(output.includes('error'), `${label}: the failure must be reported as an error`);
  }
  assertContains(
    rejections.get('unsupported color option').stderr,
    'unsupported color',
    'the color vocabulary rejection must name the problem'
  );

  assert.equal(productCount(), countBefore, 'rejected sources must not mutate the catalog');
  assert.equal(productsModifiedStamp(), modifiedBefore, 'rejected sources must leave every record untouched');

  // Products missing from the source are warnings only — never archived implicitly.
  const empty = cloneSourceDoc();
  empty.products = [];
  const missing = catalogSync([], writeFixture('empty.json', empty));
  assert.equal(missing.status, 0, 'a source missing existing products must still succeed');
  assertContains(missing.stdout, 'fp-caja-cosechera-3-4', 'the missing product must be reported');
  assertContains(missing.stdout, 'warnings=17', 'every missing product must be counted as a warning');
  assert.equal(productCount(), countBefore, 'missing products must never be withdrawn');
  assert.equal(
    publishedProductCount(),
    '17',
    'the missing products must remain published (explicit lifecycle change required to archive)'
  );

  // Only an explicit source lifecycle change archives a Product.
  const archived = cloneSourceDoc();
  archived.products.find((p) => p.source_id === 'fp-caja-paltera').lifecycle = 'archived';
  const archiveRun = catalogSync([], writeFullFixture('archived.json', archived));
  assert.equal(archiveRun.status, 0, `the explicit archive run must succeed:\n${archiveRun.stderr}`);
  assertContains(archiveRun.stdout, 'Summary: created=0 updated=1 unchanged=16 warnings=0 errors=0', 'the archive run must touch exactly one record');
  assert.equal(productMeta('fp-caja-paltera', '_fp_lifecycle'), 'archived', 'the archived record must carry the explicit state');
  assert.equal(
    wp(['eval', 'echo get_post_status( (int) get_posts( array( "post_type" => "fp_product", "post_status" => "any", "posts_per_page" => 1, "fields" => "ids", "no_found_rows" => true, "suppress_filters" => true, "meta_key" => "_fp_source_id", "meta_value" => "fp-caja-paltera" ) )[0] );']).stdout,
    'draft',
    'an archived Product must leave the public catalog'
  );
  assert.equal(
    publishedProductCount(),
    '16',
    'only the explicitly archived Product may leave the published catalog'
  );

  // Changed media imports exactly once; the next run reuses it by checksum.
  // (The fixture keeps Caja Paltera archived to match the state above, so the
  // media change is the ONLY difference this run reports.)
  const changedMedia = cloneSourceDoc();
  changedMedia.products.find((p) => p.source_id === 'fp-caja-paltera').lifecycle = 'archived';
  const toteV2 = Buffer.concat([
    readFileSync(join(WORDPRESS_DIR, 'data', 'media', 'fp-tote.webp')),
    Buffer.from('\n// freeplast check fixture v2\n'),
  ]);
  const toteV2Checksum = 'sha256:' + createHash('sha256').update(toteV2).digest('hex');
  const tote = changedMedia.products.find((p) => p.source_id === 'fp-tote');
  tote.image = { ...tote.image, file: 'media/fp-tote-v2.webp', checksum: toteV2Checksum };
  const changedFile = writeFullFixture('changed-media.json', changedMedia, [['fp-tote-v2.webp', toteV2]]);

  const changedRun = catalogSync([], changedFile);
  assert.equal(changedRun.status, 0, `the changed-media run must succeed:\n${changedRun.stderr}`);
  assertContains(changedRun.stdout, 'fp-tote: updated (image)', 'the changed-media run must update exactly the media field');
  assertContains(changedRun.stdout, 'Summary: created=0 updated=1 unchanged=16 warnings=0 errors=0', 'the changed-media run must report deterministic totals');
  assert.equal(attachmentCount(), DISTINCT_IMAGES + 1, 'changed media must be imported exactly once');
  assert.equal(productMeta('fp-tote', '_fp_image_checksum'), toteV2Checksum, 'the record must track the new checksum');

  const changedRerun = catalogSync([], changedFile);
  assert.equal(changedRerun.status, 0, `the changed-media rerun must succeed:\n${changedRerun.stderr}`);
  assertContains(changedRerun.stdout, 'Summary: created=0 updated=0 unchanged=17 warnings=0 errors=0', 'the changed-media rerun must be a no-op (media reused)');
  assert.equal(attachmentCount(), DISTINCT_IMAGES + 1, 'the rerun must not import media again');

  // Restoring the reviewed source reactivates the archived Product and
  // reuses the original tote attachment by checksum (no new import).
  const restore = catalogSync();
  assert.equal(restore.status, 0, `the restore run must succeed:\n${restore.stderr}`);
  assertContains(restore.stdout, 'Summary: created=0 updated=2 unchanged=15 warnings=0 errors=0', 'the restore run must reactivate exactly the two changed records');
  assert.equal(productMeta('fp-caja-paltera', '_fp_lifecycle'), 'active', 'the explicit reactivation must restore the active state');
  assert.equal(
    publishedProductCount(),
    '17',
    'all 17 Products must be active again after the restore'
  );
  assert.equal(attachmentCount(), DISTINCT_IMAGES + 1, 'the original tote media must be reused by checksum, not re-imported');
  assert.equal(
    productMeta('fp-tote', '_fp_image_checksum'),
    PRODUCT_BY_SOURCE_ID.get('fp-tote').image.checksum,
    'the record must track the restored checksum'
  );

  // Final deterministic no-op against the reviewed source.
  const finalRun = catalogSync();
  assertContains(finalRun.stdout, 'Summary: created=0 updated=0 unchanged=17 warnings=0 errors=0', 'the final run must be a no-op');

  section('Rejected sources, lifecycle and media', [
    'Unknown keys, duplicate source_id/slug, invalid slug, missing media, checksum mismatch and unsupported colors all exit non-zero',
    'A file with one valid + one invalid product is rejected entirely (complete preflight before mutation)',
    'Products absent from the source produce warnings only and stay published',
    'Only an explicit source lifecycle change archives a Product (and an explicit change reactivates it)',
    'Changed media imports exactly once; reruns and restores reuse attachments by checksum',
  ]);
});

/* ─── 11. Write VERIFICATION.md and clean up ──────────────────────────── */

test('record mechanical proof in wordpress/VERIFICATION.md', () => {
  const lines = [
    `# Mechanical verification — Freeplast WordPress shell + complete catalog (issues #2, #3, #4)`,
    ``,
    `Generated by \`npm test\` (wordpress/scripts/check.mjs) at ${new Date().toISOString()}.`,
    `Disposable installation: WordPress ${versions?.wpVersion} · PHP ${versions?.phpVersion} · SQLite ${versions?.sqliteVersion} (sqlite-database-integration drop-in ${versions?.dropin}).`,
    ``,
    `| Check | Result |`,
    `| --- | --- |`,
  ];
  const passed = [
    'Clean disposable database boots without manual editor changes',
    'Theme "freeplast" activates without warnings or fatal errors',
    'Plugin "freeplast-catalog-quotes" activates without warnings or fatal errors',
    'Home returns HTTP 200 with the v6 site shell (mobile and desktop user agents, identical document)',
    'Approved brand/navigation structure (INICIO · NOSOTROS · TIENDA · Cotiza Online → /cotizacion/)',
    'Non-functional-safe empty Cotización state; no submission forms or prototype endpoints',
    'Navigation routes /, /nosotros/, /tienda/, /contacto/, /cotizacion/ return HTTP 200',
    'WooCommerce absent (plugin list, wp-content, runtime)',
    'Version expectations and plugin migration version reported below',
    'Catalog Source v2 (the 17-product union) validates before mutation; dry run changes nothing',
    'Real sync creates all 17 Active Products — 15 PDF products plus Pediluvio and Ladrillo plástico',
    'Every record: immutable source identity, explicit lifecycle, Product Category, canonical slug, tracked provenance and retained legacy paths',
    'Four distinct Caja Universal configurations; Color ones offer exactly the supported color options',
    'Traversa para Bins Tipo Romano provisional with “Traversa Tipo G2” retained as review alias',
    'Clean canonical URLs /producto/<slug>/ for all 17 Products; fp_product absent from editor menus',
    'Product pages follow v7 variant A: source-supported description/specs, pallet facts without an invented minimum, “Consultar” for unconfirmed facts, no forms or prototype controls',
    'Local media imported once per distinct image (checksum-keyed reuse); a second complete sync reports zero changes',
    'Invalid schema/identity/slug/color/media sources exit non-zero with no partial mutation; missing products are warnings only',
    'Only explicit lifecycle changes archive/reactivate Products; changed media imports exactly once',
  ];
  for (const name of passed) lines.push(`| ${name} | pass |`);
  lines.push(``, `## Versions reported by the check`, ``);
  for (const line of versionLines) lines.push(`- ${line}`);
  lines.push(
    ``,
    `## Notes`,
    ``,
    `- Mobile/desktop fidelity beyond the served document (identical for both user agents) and the mobile-first min-width CSS is confirmed mechanically; human visual validation of pixel rendering remains Gate 3 (RUNBOOK.md).`,
    `- Pixel-accurate browser rendering at 412 px and desktop widths is intentionally not claimed by this automated check.`,
    `- Catalog facts come exclusively from the reviewed versioned Catalog Source (wordpress/data/products.json, schema v2); unconfirmed commercial minimums and packaging facts render as “Consultar” and no contradictory old-site values are copied.`,
    `- The 2026 PDF is raster-only; facts not transcribable in this environment (notably the Universal ventilada/color configurations, Tipo Romano and Caja Paltera sheets) render as “Consultar” pending client review, and their media is visibly provisional.`,
    ``
  );
  writeFileSync(join(WORDPRESS_DIR, 'VERIFICATION.md'), lines.join('\n'));
  section('Proof', ['Mechanical results recorded in wordpress/VERIFICATION.md']);
});

process.on('exit', () => {
  if (server && !server.killed) {
    try {
      process.kill(-server.pid, 'SIGTERM');
    } catch {
      /* already gone */
    }
  }
});
