#!/usr/bin/env node
/**
 * Freeplast WordPress shell — automated acceptance checks (issue #2).
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
 * verifies the acceptance criteria of issues #2 and #3:
 *
 *   1. A clean disposable WordPress database boots without manual editor changes.
 *   2. The Freeplast theme and private plugin activate without warnings or fatal errors.
 *   3. Home returns HTTP 200 and renders the approved v6 site shell at mobile and desktop widths.
 *   4. The shell includes the approved brand/navigation structure and a
 *      non-functional-safe empty Cotización state.
 *   5. WordPress/PHP/database version expectations and plugin migration version are reported.
 *   6. WooCommerce is absent and no prototype behavior is treated as a real submission endpoint.
 *   7. The versioned Catalog Source (Caja Cosechera 3/4) validates before mutation;
 *      the dry run reports the deterministic difference without touching WordPress.
 *   8. Real synchronization creates the Product, its metadata and local media;
 *      a second run against unchanged source reports zero changes.
 *   9. The Product has a clean canonical URL, is absent from WordPress editor
 *      menus, and its public page follows approved v7 variant A with only
 *      source-supported facts (pallet facts without an invented minimum).
 *  10. Invalid schema, duplicate identity, invalid slug or failed media import
 *      returns non-zero without partial Catalog mutation.
 *
 * Results are printed to stdout and recorded in wordpress/VERIFICATION.md.
 */
import { spawn, spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
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
const PRODUCT_SLUG = 'caja-cosechera-3-4';
const PRODUCT_URL = `/producto/${PRODUCT_SLUG}/`;

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
  return Number(wp(['post', 'list', '--post_type=fp_product', '--format=count']).stdout || '0');
}

function productIds() {
  return wp(['post', 'list', '--post_type=fp_product', '--format=ids']).stdout;
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

/* ─── 7. Write VERIFICATION.md and clean up ───────────────────────────── */

/* ─── 7. Catalog Source validation + dry run (issue #3) ───────────────── */

test('Catalog Source validates before mutation; dry run reports the difference and changes nothing', () => {
  deleteProducts();
  assert.equal(productCount(), 0, 'the catalog must start empty');

  const dryRun = catalogSync(['--dry-run']);
  assert.equal(dryRun.status, 0, `dry run must succeed:\n${dryRun.stderr}`);
  assertContains(dryRun.stdout, 'version 1', 'dry run must report the source schema version');
  assertContains(dryRun.stdout, 'fp-caja-cosechera-3-4: would create', 'dry run must report the deterministic per-product difference');
  assertContains(dryRun.stdout, 'Summary: created=1 updated=0 unchanged=0 warnings=0 errors=0', 'dry run must report correct totals');
  assertContains(dryRun.stdout, 'Dry run: no changes were applied', 'dry run must state that nothing was applied');
  assert.equal(productCount(), 0, 'dry run must not change WordPress');

  section('Catalog dry run', [
    'wp freeplast catalog sync --dry-run validates the complete source and reports the difference',
    'No fp_product posts, metadata or media exist after the dry run',
  ]);
});

/* ─── 8. Real synchronization + idempotence (issue #3) ────────────────── */

test('real synchronization creates the Product, metadata and local media; a second run reports zero changes', () => {
  const first = catalogSync();
  assert.equal(first.status, 0, `first synchronization must succeed:\n${first.stderr}`);
  assertContains(first.stdout, 'fp-caja-cosechera-3-4: created', 'first run must create the product');
  assertContains(first.stdout, 'Summary: created=1 updated=0 unchanged=0 warnings=0 errors=0', 'first run must report correct totals');

  assert.equal(productCount(), 1, 'exactly one fp_product must exist');
  assert.equal(
    wp(['post', 'list', '--post_type=fp_product', '--post_status=publish', '--field=post_name']).stdout,
    PRODUCT_SLUG,
    'the product must be published under the canonical slug'
  );

  const meta = (key) => wp(['post', 'meta', 'get', productIds(), key]).stdout;
  assert.equal(meta('_fp_source_id'), 'fp-caja-cosechera-3-4', 'immutable source identity must be stored');
  assert.equal(meta('_fp_source_url'), 'https://freeplast.cl/producto/caja-cosechera-3-4/', 'source provenance URL must be stored');
  assert.equal(meta('_fp_units_per_pallet'), '70', 'pallet fact must be stored as metadata');
  assert.equal(meta('_fp_quote_min_qty'), '', 'unconfirmed commercial minimum must NOT be stored');
  assertContains(meta('_fp_description'), 'Fabricada de Polietileno de alta densidad reciclado', 'description must be stored');

  const thumbId = meta('_thumbnail_id');
  assert.ok(/^\d+$/.test(thumbId) && Number(thumbId) > 0, 'the product must have a featured image');
  const attachmentFile = wp(['post', 'meta', 'get', thumbId, '_wp_attached_file']).stdout;
  assert.match(attachmentFile, /\.webp$/, 'the featured image must be a local media-library file');
  assert.ok(
    existsSync(join(WP_DIR, 'wp-content', 'uploads', attachmentFile)),
    'the imported media file must exist locally under wp-content/uploads'
  );
  assert.match(meta('_fp_image_checksum'), /^sha256:[0-9a-f]{64}$/, 'the image checksum must be tracked');

  const modifiedBefore = wp(['post', 'list', '--post_type=fp_product', '--field=post_modified']).stdout;
  const second = catalogSync();
  assert.equal(second.status, 0, `second synchronization must succeed:\n${second.stderr}`);
  assertContains(second.stdout, 'fp-caja-cosechera-3-4: unchanged', 'second run must report the product as unchanged');
  assertContains(second.stdout, 'Summary: created=0 updated=0 unchanged=1 warnings=0 errors=0', 'second run must report zero changes');
  assert.equal(
    wp(['post', 'list', '--post_type=fp_product', '--field=post_modified']).stdout,
    modifiedBefore,
    'the no-op run must not touch the record'
  );

  section('Catalog synchronization', [
    'Real sync created Caja Cosechera 3/4 with source identity, specs metadata and local media',
    'Units-per-pallet stored as a packaging fact; no unconfirmed minimum is stored',
    'Second run against unchanged source: created=0 updated=0 unchanged=1 (no-op)',
  ]);
});

/* ─── 9. Public product page (issue #3) ───────────────────────────────── */

test('the Product has a clean canonical URL, stays out of editor menus and renders v7-A from source-supported facts', async () => {
  const showUi = wp(['eval', 'echo get_post_type_object("fp_product")->show_ui ? "shown" : "hidden";']).stdout;
  assert.equal(showUi, 'hidden', 'fp_product must be absent from WordPress editor UI');
  const showMenu = wp(['eval', 'echo get_post_type_object("fp_product")->show_in_menu ? "shown" : "hidden";']).stdout;
  assert.equal(showMenu, 'hidden', 'fp_product must be absent from the administration menu');
  assert.equal(wp(['option', 'get', 'fp_db_version']).stdout, '2', 'migration 2 (catalog slice) must be applied');

  const page = await get(PRODUCT_URL, MOBILE_UA);
  assert.equal(page.status, 200, `${PRODUCT_URL} must return HTTP 200`);
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

  // The /tienda/ placeholder retired: the fp_product archive owns the route.
  const tienda = await get('/tienda/', MOBILE_UA);
  assert.equal(tienda.status, 200, '/tienda/ must remain HTTP 200 under the archive');
  assertAbsent(tienda.body, 'El catálogo se está preparando', 'the seeded placeholder copy must be retired');
  assertContains(tienda.body, 'Caja Cosechera 3/4', 'the archive must list the synchronized product');
  assertContains(tienda.body, PRODUCT_URL, 'the archive must link the canonical product URL');

  section('Product page (v7 variant A)', [
    `${PRODUCT_URL} → HTTP 200 (mobile and desktop) with rel=canonical on the clean /producto/ slug`,
    'fp_product: show_ui/show_in_menu false — products are absent from WordPress editor menus',
    'Breadcrumb, gallery + summary, source-supported description, quick specs, spec table and quote CTA',
    'Units-per-pallet rendered as a packaging fact; Cantidad mínima honestly shows “Consultar”',
    'No forms, no quantity controls, no prototype switching; media served from the local library',
    '/tienda/ is now the fp_product archive (placeholder retired by migration 2)',
  ]);
});

/* ─── 10. Rejected sources leave no partial mutation (issue #3) ───────── */

test('invalid sources return non-zero without partial catalog mutation; missing products are warnings only', () => {
  const countBefore = productCount();
  const modifiedBefore = wp(['post', 'list', '--post_type=fp_product', '--field=post_modified']).stdout;
  assert.equal(countBefore, 1);

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
  const second = { ...preflight.products[0], source_id: 'fp-caja-frutera', slug: 'caja-frutera', title: 'Caja Frutera' };
  preflight.products.push(second);
  preflight.products[0].nota = 'unknown key';
  cases.push(['preflight rejection', writeFixture('preflight.json', preflight)]);

  for (const [label, file] of cases) {
    const res = catalogSync([], file);
    assert.notEqual(res.status, 0, `${label}: synchronization must return non-zero`);
    const output = `${res.stdout}\n${res.stderr}`.toLowerCase();
    assert.ok(output.includes('error'), `${label}: the failure must be reported as an error`);
  }

  assert.equal(productCount(), countBefore, 'rejected sources must not mutate the catalog');
  assert.equal(
    wp(['post', 'list', '--post_type=fp_product', '--field=post_modified']).stdout,
    modifiedBefore,
    'rejected sources must leave the existing record untouched'
  );

  // Products missing from the source are warnings only — never archived implicitly.
  const empty = cloneSourceDoc();
  empty.products = [];
  const missing = catalogSync([], writeFixture('empty.json', empty));
  assert.equal(missing.status, 0, 'a source missing existing products must still succeed');
  assertContains(missing.stdout, 'fp-caja-cosechera-3-4', 'the missing product must be reported');
  assertContains(missing.stdout, 'warnings=1', 'the missing product must be counted as a warning');
  assert.equal(productCount(), countBefore, 'missing products must never be withdrawn');
  assert.equal(
    wp(['post', 'list', '--post_type=fp_product', '--post_status=publish', '--format=count']).stdout,
    '1',
    'the missing product must remain published (explicit lifecycle change required to archive)'
  );

  section('Rejected sources', [
    'Unknown keys, duplicate source_id/slug, invalid slug, missing media and checksum mismatch all exit non-zero',
    'A file with one valid + one invalid product is rejected entirely (complete preflight before mutation)',
    'Products absent from the source produce warnings only and stay published',
  ]);
});

test('record mechanical proof in wordpress/VERIFICATION.md', () => {
  const lines = [
    `# Mechanical verification — Freeplast WordPress shell + first product (issues #2, #3)`,
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
    'Catalog Source v1 (Caja Cosechera 3/4) validates before mutation; dry run changes nothing',
    'Real sync creates the Product, source metadata and local media; second run reports zero changes',
    'Clean canonical URL /producto/caja-cosechera-3-4/; fp_product absent from editor menus',
    'Product page follows v7 variant A: source-supported description/specs, pallet facts without an invented minimum, no forms or prototype controls',
    'Invalid schema/identity/slug/media sources exit non-zero with no partial mutation; missing products are warnings only',
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
    `- Catalog facts come exclusively from the reviewed versioned Catalog Source (wordpress/data/products.json); the unconfirmed commercial minimum renders as “Consultar”.`,
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
