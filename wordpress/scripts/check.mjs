#!/usr/bin/env node
/**
 * Freeplast WordPress shell — automated acceptance checks (issues #2–#12).
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
 * verifies the acceptance criteria of issues #2 through #12:
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
 *  11. The customer-facing discovery journey renders from the synchronized
 *      Catalog: Home's approved eight Featured Products in source-controlled
 *      order, the full Tienda grid with quotation actions, URL-backed
 *      Todos/Agrícola/Otros filters, search over Products and standard pages
 *      with a clear no-result state, reviewed related-product order, and
 *      Archived Products absent from every discovery surface.
 *  12. The Quote Basket is a persistent, secure, anonymous session: the
 *      Agregar a cotización quantity chooser (cards + product page) adds the
 *      first synchronized Product through an authoritative nonce-guarded
 *      admin-post operation, the browser keeps only an opaque
 *      Secure/HttpOnly/SameSite=Lax cookie (never basket data, only its
 *      sha256 hash stored server-side), the header counts distinct lines
 *      (Cotización (n)) regardless of unit quantity, the mini basket shows
 *      Product/quantity and a route to the full Cotización view across
 *      refreshes, and invalid nonce/session/Product/quantity mutate nothing
 *      and return recoverable messages (JSON for the JS enhancement).
 *  13. The complete v6 content and navigation experience is governed by a
 *      frozen design contract (wordpress/design/): every navigation entry
 *      point reaches its approved destination, Home keeps the concise v6
 *      composition (Nosotros + contact sections, eight Featured Products,
 *      a Quote Basket summary/CTA — never a second submission form),
 *      Nosotros renders editable mission/vision page content, Contacto
 *      renders the current contact surface with one CTA into Cotización
 *      (no Inquiry record), Política de privacidad discloses
 *      collection/submission without a consent checkbox, search/404 stay
 *      usable, the header count and mini basket stay accurate on every
 *      route, templates parse without block recovery, and the theme
 *      contains no Catalog or Quote Request business logic.
 *
 * Results are printed to stdout and recorded in wordpress/VERIFICATION.md.
 */
import { spawn, spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, readdirSync, rmSync, statSync, cpSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { request as httpRequest } from 'node:http';
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
const FEATURED_SLUGS = [...PRODUCTS]
  .filter((p) => p.featured)
  .sort((a, b) => a.featured_order - b.featured_order)
  .map((p) => p.slug);
const CATEGORY_SLUGS = (category) => PRODUCTS.filter((p) => p.category === category).map((p) => p.slug);
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

async function get(pathname, ua, extraHeaders = {}) {
  const res = await fetch(SITE_URL + pathname, {
    headers: { ...(ua ? { 'user-agent': ua } : {}), ...extraHeaders },
    redirect: 'manual',
  });
  const body = await res.text();
  return { status: res.status, body, headers: res.headers };
}

/** Raw same-origin POST that does NOT follow redirects (PRG observation). */
function postForm(fields, extraHeaders = {}) {
  const site = new URL(SITE_URL);
  const data = Buffer.from(new URLSearchParams(fields).toString(), 'utf8');
  return new Promise((resolve, reject) => {
    const req = httpRequest(
      {
        host: site.hostname,
        port: site.port || 80,
        path: '/wp-admin/admin-post.php',
        method: 'POST',
        headers: {
          'content-type': 'application/x-www-form-urlencoded',
          'content-length': data.length,
          'user-agent': MOBILE_UA,
          ...extraHeaders,
        },
      },
      (res) => {
        let body = '';
        res.on('data', (chunk) => (body += chunk));
        res.on('end', () =>
          resolve({ status: res.statusCode, headers: res.headers, body, setCookies: [].concat(res.headers['set-cookie'] || []) })
        );
      }
    );
    req.on('error', reject);
    req.end(data);
  });
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

/** Slugs of every canonical /producto/<slug>/ link, in order of appearance. */
function productLinks(html) {
  return [...html.matchAll(/\/producto\/([a-z0-9-]+)\//g)].map((m) => m[1]);
}

/** Markup of the plugin-rendered <section> that carries the marker class. */
function pluginSection(html, marker, message) {
  const start = html.indexOf(marker);
  assert.ok(start !== -1, message);
  return html.slice(start, html.indexOf('</section>', start));
}

/** Occurrences of a literal substring. */
function countMatches(haystack, needle) {
  return haystack.split(needle).length - 1;
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

/** Server-side basket sessions: opaque-token hashes + stored lines + activity. */
function basketRows() {
  return JSON.parse(
    wp([
      'eval',
      // NB: no `AS lines` alias — "lines" is a reserved MySQL keyword and the
      // SQLite drop-in fails such queries silently (empty result set).
      'global $wpdb; echo wp_json_encode( $wpdb->get_results( "SELECT session_hash, basket_lines, created_at, last_activity FROM {$wpdb->prefix}basket_sessions", ARRAY_A ) );',
    ]).stdout || '[]'
  ).map((row) => ({ ...row, lines: row.basket_lines }));
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

  // Basket entry points elsewhere are quantity choosers, never quote-request forms.
  const home = await get('/', MOBILE_UA);
  assert.doesNotMatch(home.body, /action="mailto:/i, 'Home must not treat the prototype mailto form as a submission endpoint');
  assert.doesNotMatch(
    home.body,
    /api\.whatsapp\.com\/send\?phone=[^"]*"\s*data-submit/i,
    'no WhatsApp prototype endpoints as submission'
  );
  const formCount = (html) => (html.match(/<form[\s>]/gi) || []).length;
  const chooserCount = (html) => (html.match(/<form class="fpcq-basket-add"/g) || []).length;
  assert.equal(
    formCount(home.body),
    chooserCount(home.body),
    'every form on Home must be a basket quantity chooser — the quote-request form does not exist yet (issue #8)'
  );

  section('Cotización state', [
    '/cotizacion/ renders "Tu cotización está vacía" — empty, non-functional and safe',
    'No quote-request submission form anywhere; basket choosers are the only forms; prototype mailto/WhatsApp behavior is not a submission endpoint',
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
  const featured = FEATURED_SLUGS.map((slug) => PRODUCT_BY_SLUG.get(slug).title);
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
  assert.equal(wp(['option', 'get', 'fp_db_version']).stdout, '5', 'migration 5 (v6 content takeover) must be applied');

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
  // v7 variant A exposes its own quantity chooser (issue #6): an authoritative
  // POST form — never an unseen-quantity add or a fake submission.
  assertContains(page.body, 'class="fpcq-basket-add"', 'the product page must expose its own quantity chooser');
  assertContains(page.body, 'name="fp_quantity"', 'the chooser must let the buyer pick the quantity');
  assertContains(page.body, 'Agregar a cotización', 'the v7-A quote action must be the add-to-basket submit');
  assertContains(page.body, `name="fp_basket_nonce"`, 'the add operation must be nonce-guarded');
  assertContains(page.body, `href="${SITE_URL}/cotizacion/"`, 'the product page keeps a route to the sole quotation surface');
  assertContains(page.body, '/wp-content/uploads/', 'the product image must be served from the local media library');
  assertContains(page.body, 'Imagen provisional', 'provisional staging media must be visibly tracked');

  // No prototype controls, no fake quote-request submission, no invented minimum.
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
  // and the discovery grid lists every Active Product on one page.
  const tienda = await get('/tienda/', MOBILE_UA);
  assert.equal(tienda.status, 200, '/tienda/ must remain HTTP 200 under the archive');
  assertAbsent(tienda.body, 'El catálogo se está preparando', 'the seeded placeholder copy must be retired');
  const listed = new Set(productLinks(tienda.body));
  assert.equal(listed.size, PRODUCT_COUNT, 'the Tienda grid must list exactly the 17 Active Products on one page');
  for (const slug of PRODUCT_SLUGS) {
    assert.ok(listed.has(slug), `the grid must list the Active Product at /producto/${slug}/`);
  }

  section('Product pages (v7 variant A)', [
    'All 17 canonical /producto/ URLs return HTTP 200; the ficha carries rel=canonical on the clean slug',
    'fp_product: show_ui/show_in_menu false — products are absent from WordPress editor menus',
    'Breadcrumb, gallery + summary, source-supported description, quick specs, spec table, related products and quote CTA',
    'Units-per-pallet rendered as packaging facts where published; unconfirmed facts honestly show “Consultar”',
    'The four Caja Universal configurations are distinct public Products (published facts on Cerrada Negra only)',
    'Traversa para Bins Tipo Romano provisional with the G2 alias visible; Pediluvio and Ladrillo public with limited content',
    '/tienda/ is the fp_product archive and lists all 17 Active Products on one full grid page',
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

/* ─── 11. Catalog discovery journey (issue #5) ───────────────────────── */

test('the Catalog is discoverable: Home featured eight, full Tienda grid, category filters, search and related Products', { timeout: 120_000 }, async () => {
  // Home renders the approved eight Featured Products in source-controlled order.
  const home = await get('/', MOBILE_UA);
  assert.equal(home.status, 200, 'Home must return HTTP 200');
  const featuredSection = pluginSection(home.body, 'class="fpcq-featured"', 'Home must render the Featured Products section');
  const featuredOrder = productLinks(featuredSection);
  assert.deepEqual(featuredOrder, FEATURED_SLUGS, 'Home must render exactly the approved eight Featured Products in source-controlled order');
  assert.equal(countMatches(featuredSection, 'class="fpcq-card-cta"'), FEATURED_SLUGS.length, 'each Featured card must carry a quotation action');
  assert.equal(
    countMatches(featuredSection, '<form class="fpcq-basket-add"'),
    FEATURED_SLUGS.length,
    'each Featured card must open a quantity chooser'
  );
  assertContains(featuredSection, '>Cotizar</summary>', 'the card action opens a chooser instead of adding an unseen quantity');
  assertContains(featuredSection, 'name="fp_quantity"', 'the card chooser must let the buyer pick the quantity');
  assertContains(home.body, 'Ver todo el catálogo', 'Home must link into the full Tienda grid');
  assert.ok(!featuredOrder.includes('ladrillo-plastico'), 'non-featured Products must not appear in the Featured section');

  // Tienda lists all 17 Active Products on one page with usable quotation actions.
  const tienda = await get('/tienda/', MOBILE_UA);
  assert.equal(tienda.status, 200, '/tienda/ must return HTTP 200');
  assertContains(tienda.body, '<ul class="fpcq-cards"', 'the catalog grid must render as a semantic list');
  assert.equal(countMatches(tienda.body, 'class="fpcq-card-cta"'), PRODUCT_COUNT, 'every Active Product card must carry a quotation action');
  assert.equal(
    countMatches(tienda.body, '<form class="fpcq-basket-add"'),
    PRODUCT_COUNT,
    'every Active Product card must open a quantity chooser'
  );
  for (const slug of [PRODUCT_SLUG, 'caja-tomatera']) {
    assertContains(
      tienda.body,
      `name="fp_product" value="${PRODUCT_BY_SLUG.get(slug).source_id}"`,
      `the ${slug} chooser must address its own reviewed Product identity`
    );
  }

  // Todos / Agrícola / Otros filters: accessible controls with meaningful URLs.
  assertContains(tienda.body, 'Filtrar productos por categoría', 'the category filter must be an accessible labelled control group');
  assertContains(tienda.body, '>Todos</a>', 'the Todos view filter must be offered');
  assert.ok(tienda.body.includes(`href="${SITE_URL}/tienda/" aria-current="true"`), 'Todos must be the active filter on the unfiltered grid');
  const assertCategoryFilter = async (key) => {
    const res = await get(`/tienda/categoria/${key}/`, MOBILE_UA);
    assert.equal(res.status, 200, `/tienda/categoria/${key}/ must return HTTP 200`);
    assert.deepEqual(
      [...new Set(productLinks(res.body))],
      CATEGORY_SLUGS(key),
      `the ${key} category filter must list exactly the ${key} Products`
    );
    assert.ok(
      res.body.includes(`href="${SITE_URL}/tienda/categoria/${key}/" aria-current="true"`),
      'the active filter must be marked with aria-current'
    );
  };
  await assertCategoryFilter('agricola');
  await assertCategoryFilter('otros');
  assert.equal((await get('/tienda/categoria/inexistente/', MOBILE_UA)).status, 404, 'an unknown category must resolve as 404, not an empty grid');

  // Search includes Products and standard pages with a clear no-result behavior.
  const searchProduct = await get('/?s=tomatera', MOBILE_UA);
  assert.equal(searchProduct.status, 200, 'search must return HTTP 200');
  assertContains(searchProduct.body, 'Resultados de búsqueda', 'search results must render a clear heading');
  assertContains(searchProduct.body, `href="${SITE_URL}/producto/caja-tomatera/"`, 'search must include Products with canonical URLs');
  assertContains(searchProduct.body, 'class="fpcq-card-cta"', 'searched Products keep their quotation action');
  assertContains(searchProduct.body, 'role="search"', 'the search surface must expose a search form');
  const searchPage = await get('/?s=nosotros', MOBILE_UA);
  assert.equal(searchPage.status, 200, 'searching a page must return HTTP 200');
  assertContains(searchPage.body, 'Páginas', 'search results must present standard pages');
  assertContains(searchPage.body, `href="${SITE_URL}/nosotros/"`, 'the Nosotros page must be findable through search');
  const searchNone = await get('/?s=zzzz-sin-resultados', MOBILE_UA);
  assert.equal(searchNone.status, 200, 'a no-result search must return HTTP 200');
  assertContains(searchNone.body, 'No encontramos resultados', 'a clear no-result behavior must render');
  assertContains(searchNone.body, `href="${SITE_URL}/tienda/"`, 'the no-result state must recover into the catalog');
  assertAbsent(searchNone.body, 'fpcq-card-cta', 'no Product cards may render without results');

  // Related Products: up to three explicit reviewed ids in reviewed order.
  const cosechera = await get(PRODUCT_URL, MOBILE_UA);
  const relatedSection = pluginSection(cosechera.body, 'class="fpcq-related"', 'the product page must render its related Products');
  const relatedOrder = productLinks(relatedSection);
  assert.deepEqual(
    relatedOrder,
    PRODUCT_BY_SOURCE_ID.get('fp-caja-cosechera-3-4').related_ids.map((id) => PRODUCT_BY_SOURCE_ID.get(id).slug),
    'related Products must render the reviewed ids in reviewed order without runtime guessing'
  );
  assert.ok(relatedOrder.length <= 3, 'at most three related Products may render');

  // Archived Products leave discovery entirely, take no quotation actions and stop resolving.
  const archivedDoc = cloneSourceDoc();
  archivedDoc.products.find((p) => p.source_id === 'fp-caja-merlucera').lifecycle = 'archived';
  const archiveRun = catalogSync([], writeFullFixture('discovery-archived.json', archivedDoc));
  assert.equal(archiveRun.status, 0, `the discovery archive run must succeed:\n${archiveRun.stderr}`);
  assertContains(archiveRun.stdout, 'Summary: created=0 updated=1 unchanged=16 warnings=0 errors=0', 'archiving for discovery must touch exactly one record');

  const tiendaHidden = await get('/tienda/', MOBILE_UA);
  assertAbsent(tiendaHidden.body, '/producto/caja-merlucera/', 'an archived Product must leave the Tienda grid');
  assert.equal(countMatches(tiendaHidden.body, 'class="fpcq-card-cta"'), PRODUCT_COUNT - 1, 'the archived Product must take its quotation action with it');
  const homeHidden = await get('/', MOBILE_UA);
  assertAbsent(homeHidden.body, '/producto/caja-merlucera/', 'an archived Featured Product must leave the Home section');
  const categoryHidden = await get('/tienda/categoria/otros/', MOBILE_UA);
  assertAbsent(categoryHidden.body, '/producto/caja-merlucera/', 'an archived Product must leave the category filters');
  const searchHidden = await get('/?s=merlucera', MOBILE_UA);
  assertAbsent(searchHidden.body, 'class="fpcq-card-cta"', 'an archived Product must not appear in search');
  assertContains(searchHidden.body, 'No encontramos resultados', 'searching an archived Product must show the no-result state');
  assert.equal((await get('/producto/caja-merlucera/', MOBILE_UA)).status, 404, 'the archived Product URL must stop resolving');
  const relatedHidden = await get('/producto/caja-pollera/', MOBILE_UA);
  assertAbsent(relatedHidden.body, '/producto/caja-merlucera/', 'an archived Product must not render as a related Product');

  // Restoring the reviewed source returns the Product to discovery.
  const restoreRun = catalogSync();
  assert.equal(restoreRun.status, 0, `the discovery restore run must succeed:\n${restoreRun.stderr}`);
  assertContains(restoreRun.stdout, 'Summary: created=0 updated=1 unchanged=16 warnings=0 errors=0', 'the restore run must reactivate exactly one record');
  assert.equal(publishedProductCount(), '17', 'all 17 Products must be active again');

  section('Catalog discovery (issue #5)', [
    'Home renders the approved eight Featured Products in source-controlled order with quotation actions',
    '/tienda/ lists all 17 Active Products on one page; every card links its canonical URL and opens a quantity chooser',
    'Todos / Agrícola / Otros filters: labelled link controls, meaningful /tienda/categoria/<categoria>/ URLs, aria-current state; unknown categories 404',
    'Search finds Products (as cards with quotation actions) and standard pages, with a clear no-result state back into the catalog',
    'Related Products render up to three reviewed ids in reviewed order',
    'An archived Product disappears from Home, Tienda, categories, search and related lists, and its URL stops resolving',
  ]);
});

/* ─── 12. Quote Basket (issue #6) ─────────────────────────────────────── */

test('a guest can add Products to a persistent, secure Quote Basket', { timeout: 120_000 }, async () => {
  // Agregar a cotización opens a quantity chooser: the product page exposes its
  // own chooser (section 9) and every card one (section 11). Here the
  // authoritative POST flow is exercised end to end.
  const page = await get(PRODUCT_URL, MOBILE_UA);
  const nonce = page.body.match(/name="fp_basket_nonce" value="([a-f0-9]{10})"/)?.[1];
  assert.ok(nonce, 'the product-page chooser must carry a nonce');
  assertContains(page.body, `action="${SITE_URL}/wp-admin/admin-post.php"`, 'the add operation must POST to the authoritative handler');
  const cookieHeader = (token) => ({ cookie: `fpcq_basket=${token}` });
  const addFields = (over = {}) => ({
    action: 'fp_basket_add',
    fp_product: 'fp-caja-cosechera-3-4',
    fp_quantity: '5',
    fp_basket_nonce: nonce,
    _wp_http_referer: PRODUCT_URL,
    ...over,
  });

  // A valid positive whole-unit quantity adds Caja Cosechera 3/4 (POST-redirect-GET).
  const added = await postForm(addFields());
  assert.equal(added.status, 302, 'the add operation must answer the browser with a redirect');
  assert.ok((added.headers.location || '').includes('fpcq_notice=added'), 'a successful add redirects back with a confirmation');
  const [sessionCookie] = added.setCookies;
  assert.ok(sessionCookie, 'the browser must receive a session cookie');

  // The cookie is a random opaque 256-bit token: Secure, HttpOnly, SameSite=Lax,
  // 30-day expiry — and it carries no basket data whatsoever.
  const token = sessionCookie.match(/fpcq_basket=([0-9a-f]{64})/)?.[1];
  assert.ok(token, 'the cookie must carry the 64-hex-char opaque token');
  const cookieAttributes = sessionCookie.toLowerCase(); // PHP writes `secure` lowercase
  for (const attribute of ['secure', 'httponly', 'samesite=lax']) {
    assert.ok(cookieAttributes.includes(attribute), `the session cookie must be ${attribute}`);
  }
  assert.match(sessionCookie, /expires=/i, 'the cookie must outlive the visit (30-day persistence)');

  // Only a hash of the opaque token is persisted server-side.
  const rows = basketRows();
  assert.equal(rows.length, 1, 'exactly one server-side session must exist');
  assert.equal(rows[0].session_hash, createHash('sha256').update(token).digest('hex'), 'the stored value must be the sha256 of the opaque token');
  assert.notEqual(rows[0].session_hash, token, 'the opaque token itself must never be stored');
  assert.match(rows[0].created_at, /^\d{4}-\d{2}-\d{2} \d{2}:/, 'session creation must be tracked');
  assert.match(rows[0].last_activity, /^\d{4}-\d{2}-\d{2} \d{2}:/, 'session activity must be tracked');
  const stored = JSON.parse(rows[0].lines);
  assert.equal(stored.length, 1);
  assert.equal(stored[0].product, 'fp-caja-cosechera-3-4', 'the line must store Product identity');
  assert.equal(stored[0].quantity, 5, 'the line must store the whole-unit quantity');

  // The basket survives refresh and navigation: header count, mini basket, full view.
  const back = await get(added.headers.location.replace(SITE_URL, ''), MOBILE_UA, cookieHeader(token));
  assertContains(back.body, 'Cotización (1)', 'the header must display the distinct-line count');
  assertContains(back.body, 'se agregó a tu cotización', 'the confirmation must be visible after the redirect');
  assertContains(back.body, 'Caja Cosechera 3/4', 'the mini basket must show the Product');
  assertContains(back.body, '5 unidades', 'the mini basket must show the quantity');
  assertContains(back.body, `href="${SITE_URL}/cotizacion/"`, 'the mini basket must route to the full Cotización page');

  assertContains((await get('/', MOBILE_UA, cookieHeader(token))).body, 'Cotización (1)', 'the basket must survive navigation to Home');
  assertContains((await get('/tienda/', MOBILE_UA, cookieHeader(token))).body, 'Cotización (1)', 'the basket must survive navigation to Tienda');
  const cotizacion = await get('/cotizacion/', MOBILE_UA, cookieHeader(token));
  assertContains(cotizacion.body, 'Caja Cosechera 3/4', 'the full Cotización view must list the Product');
  assertContains(cotizacion.body, '5 unidades', 'the full view must show the quantity');

  // The header counts distinct lines, not units: re-adding the same Product
  // merges quantities into that one line.
  const merged = await postForm(addFields({ fp_quantity: '3', _wp_http_referer: '/tienda/' }), cookieHeader(token));
  assert.equal(merged.status, 302, 'the second add must succeed');
  const cotMerged = await get('/cotizacion/', MOBILE_UA, cookieHeader(token));
  assertContains(cotMerged.body, 'Cotización (1)', 'one distinct line keeps the header count at (1)');
  assertContains(cotMerged.body, '8 unidades', 're-adding the same Product merges quantities');
  assertAbsent(cotMerged.body, '5 unidades', 'the stale un-merged quantity must not linger');

  // Invalid nonce, session, Product and quantity mutate nothing, with recoverable messages.
  const basketSnapshot = () => basketRows().map((row) => `${row.session_hash}|${row.lines}`).join(';');
  const before = basketSnapshot();

  const badNonce = await postForm(addFields({ fp_basket_nonce: 'deadbeefdeadbeefdeadbeefdeadbeef' }), cookieHeader(token));
  assert.equal(badNonce.status, 302, 'an invalid nonce is recoverable (redirect), not a dead end');
  assert.ok(badNonce.headers.location.includes('fpcq_notice=nonce'), 'the invalid nonce must produce a message');
  assert.equal(basketSnapshot(), before, 'an invalid nonce must not mutate the basket');

  const staleToken = 'a'.repeat(64);
  const badSession = await postForm(addFields(), cookieHeader(staleToken));
  assert.equal(badSession.status, 302, 'an invalid session is recoverable (redirect), not a dead end');
  assert.ok(badSession.headers.location.includes('fpcq_notice=session'), 'the invalid session must produce a message');
  assert.ok(
    badSession.setCookies.some((cookie) => /fpcq_basket=(deleted;|;)/.test(cookie)),
    'the stale cookie must be cleared so the guest can simply retry'
  );
  assert.equal(basketSnapshot(), before, 'an invalid session must not create or mutate anything');

  const unknownProduct = await postForm(addFields({ fp_product: 'fp-no-existe' }), cookieHeader(token));
  assert.ok(unknownProduct.headers.location.includes('fpcq_notice=product'), 'an unknown Product must be rejected with a message');
  const merluceraId = wp([
    'eval',
    'echo get_posts( array( "post_type" => "fp_product", "post_status" => "any", "posts_per_page" => 1, "fields" => "ids", "no_found_rows" => true, "suppress_filters" => true, "meta_key" => "_fp_source_id", "meta_value" => "fp-caja-merlucera" ) )[0];',
  ]).stdout;
  wp(['post', 'update', merluceraId, '--post_status=draft']);
  const archivedProduct = await postForm(addFields({ fp_product: 'fp-caja-merlucera' }), cookieHeader(token));
  wp(['post', 'update', merluceraId, '--post_status=publish']);
  assert.ok(archivedProduct.headers.location.includes('fpcq_notice=product'), 'an archived (unpublished) Product must be rejected');
  assert.equal(basketSnapshot(), before, 'invalid Products must not mutate the basket');

  for (const quantity of ['0', '-4', '2.5', 'abc', '']) {
    const badQuantity = await postForm(addFields({ fp_quantity: quantity }), cookieHeader(token));
    assert.ok(
      badQuantity.headers.location.includes('fpcq_notice=quantity'),
      `the quantity ${JSON.stringify(quantity)} must be rejected with a message`
    );
  }
  assert.equal(basketSnapshot(), before, 'invalid quantities must not mutate the basket');

  // JavaScript enhancement: the same authoritative handler answers JSON state.
  const enhanced = await postForm(addFields({ fp_quantity: '5', fp_enhanced: '1' }), { ...cookieHeader(token), 'x-requested-with': 'fetch' });
  assert.equal(enhanced.status, 200, 'the enhanced flow answers in place (no redirect)');
  assert.ok((enhanced.headers['content-type'] || '').includes('application/json'), 'the enhanced flow answers JSON');
  const payload = JSON.parse(enhanced.body);
  assert.equal(payload.ok, true);
  assert.equal(payload.count, 1, 'the visible count still reflects distinct lines');
  assert.ok(payload.mini.includes('Caja Cosechera 3/4'), 'the mini basket payload names the Product');
  assert.ok(payload.message.includes('agregó'), 'the payload carries the confirmation message');

  // Each guest gets a distinct random token (no shared or predictable session id).
  const guestA = await postForm(addFields({ fp_quantity: '1' }));
  const guestB = await postForm(addFields({ fp_quantity: '1' }));
  const tokenA = guestA.setCookies[0].match(/fpcq_basket=([0-9a-f]{64})/)?.[1];
  const tokenB = guestB.setCookies[0].match(/fpcq_basket=([0-9a-f]{64})/)?.[1];
  assert.ok(tokenA && tokenB, 'each guest must receive an opaque session cookie');
  assert.notEqual(tokenA, tokenB, 'session tokens must be random per guest');
  assert.equal(basketRows().length, 3, 'each guest owns a separate server-side session');

  section('Quote Basket (issue #6)', [
    'Agregar a cotización opens a quantity chooser (cards + product page) — never an unseen-quantity add',
    'A positive whole-unit quantity adds Caja Cosechera 3/4 through the authoritative nonce-guarded admin-post operation (POST-redirect-GET)',
    'The cookie carries only a random 256-bit opaque token (Secure, HttpOnly, SameSite=Lax, 30 days); only its sha256 hash is stored server-side',
    'The basket survives refresh and navigation; the header counts distinct lines (Cotización (1)) regardless of unit quantity; re-adds merge into the line',
    'The mini basket shows Product, quantity and a route to the full Cotización view',
    'Invalid nonce, session, Product (unknown or archived) and quantity mutate nothing and return recoverable messages (stale cookies cleared for retry)',
    'The JavaScript enhancement receives JSON state and updates the visible count/mini basket; the server remains authoritative',
  ]);
});

/* ── 13. v6 content and navigation experience (issue #12) ───────────── */

test('the complete v6 content and navigation experience is governed, connected and honest', { timeout: 120_000 }, async () => {
  /* 13.1 — A frozen v6 design contract governs the presentation; the
     obsolete v5 rules are not used. */
  const designDir = join(WORDPRESS_DIR, 'design');
  const decisions = readFileSync(join(designDir, 'DECISIONS.md'), 'utf8');
  const tokens = JSON.parse(readFileSync(join(designDir, 'design-tokens.json'), 'utf8'));
  assert.equal(tokens.frozen, true, 'the design contract must be declared frozen');
  assert.equal(tokens.source.storefront.published, 'https://mliu.site/freeplast/v6/');
  assert.equal(tokens.source.productPage.published, 'https://mliu.site/freeplast/v7/?variant=A');
  assert.equal(tokens.color.roles.primaryAction, 'blue', 'the v6 contract makes blue the primary action color');
  assert.equal(tokens.typography.fontFamily, 'Manrope');
  assert.equal(tokens.obsolete.v5.status.includes('rejected'), true);

  // Rows of the approved-sources table have the shape
  //   | reference | `prototype file` | published url | `sha256` |
  // — capture every (file, hash) pair that must still match on disk.
  const recorded = [...decisions.matchAll(/\|\s*`([^`|]+)`\s*\|[^|]+\|\s*`([0-9a-f]{64})`\s*\|/g)];
  assert.ok(recorded.length >= 6, 'DECISIONS.md must freeze every approved prototype file by hash');
  for (const [, file, hash] of recorded) {
    const actual = createHash('sha256').update(readFileSync(join(REPO_ROOT, file))).digest('hex');
    assert.equal(actual, hash, `approved prototype ${file} must match its frozen SHA-256`);
  }

  // The theme implements the frozen tokens: the full v6 palette, Manrope and
  // the blue primary control (the old v5 green-rectangular CTA is gone).
  const themeDir = join(WORDPRESS_DIR, 'wp-content', 'themes', 'freeplast');
  const themeJson = readFileSync(join(themeDir, 'theme.json'), 'utf8');
  const themeCss = readFileSync(join(themeDir, 'style.css'), 'utf8');
  for (const [name, value] of Object.entries(tokens.color)) {
    if (typeof value === 'string' && /^#[0-9a-f]{6}$/i.test(value)) {
      assert.ok(themeJson.includes(value), `theme.json palette must carry the v6 ${name} token ${value}`);
      assert.ok(themeCss.includes(value), `theme stylesheet must use the v6 ${name} token ${value}`);
    }
  }
  assert.ok(themeCss.includes('Manrope'), 'the theme must set the frozen v6 typeface');
  const primaryControl = themeCss.match(/\.fp-btn,\n\.wp-block-button__link \{[^}]*\}/)?.[0] || '';
  assert.ok(primaryControl.includes('var(--fp-blue)'), 'the primary control must be the v6 blue button');
  assert.ok(!primaryControl.includes('var(--fp-green)'), 'the obsolete v5 green primary CTA must not be used');
  assert.ok(themeCss.includes('--fp-r-full: 9999px'), 'the v6 pill shape must be part of the token set');

  // Obsolete v5 rules are gone from the governing implementation docs.
  const target = readFileSync(join(REPO_ROOT, 'docs', 'agents', 'freeplast-wordpress', 'TARGET.md'), 'utf8');
  const runbook = readFileSync(join(REPO_ROOT, 'docs', 'agents', 'freeplast-wordpress', 'RUNBOOK.md'), 'utf8');
  for (const [name, doc] of [['TARGET.md', target], ['RUNBOOK.md', runbook]]) {
    // A sentence may mention v5 only to reject it; prescribing it is the failure.
    const prescribing = doc
      .split('\n')
      .filter((line) => /propuesta-editorial|Newsreader|Outfit/.test(line))
      .filter((line) => !/reject/i.test(line));
    assert.deepEqual(prescribing, [], `${name} must not prescribe the rejected v5 rules`);
  }

  /* 13.2 — Logo/Inicio, Nosotros, Tienda, Cotización count/CTA and Contacto
     navigate to their approved destinations. */
  const home = await get('/', MOBILE_UA);
  assert.equal(home.status, 200, 'Home must return HTTP 200');
  assertContains(home.body, 'class="fp-island-logo" href="/"', 'the logo must navigate Home');
  for (const [label, href] of [
    ['INICIO', '/'],
    ['NOSOTROS', '/nosotros/'],
    ['TIENDA', '/tienda/'],
    ['CONTACTO', '/contacto/'],
  ]) {
    assertContains(home.body, `href="${href}">${label}</a>`, `the ${label} navigation link must target ${href}`);
  }
  assertContains(home.body, '<a href="/cotizacion/"><span>COTIZA ONLINE</span></a>', 'the mobile sheet must route the Cotiza Online CTA to Cotización');
  assertContains(home.body, 'data-fpcq-basket-count', 'the header must render the Cotización count widget');
  assertContains(home.body, 'Cotización (0)', 'the header count must be accurate on a fresh visit');
  assertContains(home.body, 'Ver cotización completa', 'the mini basket must route to the full Cotización view');
  assertContains(home.body, 'href="/politica-de-privacidad/"', 'the footer must link the privacy disclosure');
  assertContains(home.body, 'href="/contacto/"', 'the footer must link the Contacto destination');
  for (const route of ['/', '/nosotros/', '/tienda/', '/cotizacion/', '/contacto/', '/politica-de-privacidad/']) {
    assert.equal((await get(route, DESKTOP_UA)).status, 200, `${route} must return HTTP 200 for a desktop user agent`);
  }

  /* 13.3 — Home retains the concise v6 composition: Nosotros + contact
     sections, the eight Featured Products, and a Quote Basket summary/CTA
     instead of a second submission form. */
  assertContains(home.body, 'Somos los mejores en el mercado del plástico', 'Home must retain the concise v6 Nosotros headline');
  assertContains(home.body, 'Comercializamos productos de excelente calidad', 'Home must retain the concise v6 Nosotros subheadline');
  assertContains(home.body, 'Nuestra Misión', 'Home must summarize the current mission');
  assertContains(home.body, 'Nuestra Visión', 'Home must summarize the current vision');
  assertContains(home.body, 'href="/nosotros/"', 'the concise section must link the standalone Nosotros page');
  assertContains(home.body, 'Nuestros Productos', 'Home must keep the Featured Products section');

  const quoteSection = home.body.slice(home.body.indexOf('fp-home-quote'), home.body.indexOf('fp-home-contact'));
  assert.ok(quoteSection.length > 0, 'Home must render the Cotiza Online basket section');
  assertContains(quoteSection, 'Cotiza Online', 'the basket section must keep the v6 Cotiza Online title');
  assertContains(quoteSection, 'cotización', 'the section must explain the shared Quote Basket');
  assertContains(quoteSection, 'href="/cotizacion/"', 'the section CTA must enter the shared basket');
  assertContains(quoteSection, 'href="/tienda/"', 'the section must offer the recovery path into Tienda');

  const contactSection = home.body.slice(home.body.indexOf('fp-home-contact'), home.body.indexOf('fp-foot'));
  assertContains(contactSection, 'tel:+56968444265', 'the concise contact section must offer the current phone');
  assertContains(contactSection, 'mailto:ventas@freeplast.cl', 'the concise contact section must offer the current email');
  assertContains(contactSection, 'api.whatsapp.com/send?phone=56968444265', 'the concise contact section must offer WhatsApp');
  assertContains(contactSection, 'maps.app.goo.gl', 'the concise contact section must offer the warehouse map');
  assertContains(contactSection, 'Lun a Vie 09:00 a 13:00 hrs y 14:00 a 18:00 hrs', 'the concise contact section must show the current hours');
  assertContains(contactSection, 'href="/contacto/"', 'the concise section must link the standalone Contacto page');

  const formCount = (html) => (html.match(/<form[\s>]/gi) || []).length;
  const chooserCount = (html) => (html.match(/<form class="fpcq-basket-add"/g) || []).length;
  assert.equal(formCount(home.body), chooserCount(home.body), 'Home must not carry a second submission form — basket choosers only');
  assert.doesNotMatch(home.body, /action="mailto:/i, 'no mailto form action');

  /* 13.4 — Nosotros renders the current mission and vision as editable
     WordPress page content (baseline layout from the theme, never from
     Site Editor overrides). */
  const nosotros = await get('/nosotros/', MOBILE_UA);
  assert.equal(nosotros.status, 200, '/nosotros/ must return HTTP 200');
  assertContains(nosotros.body, 'Nuestra Misión', 'the Nosotros page must render the current mission');
  assertContains(nosotros.body, 'Nuestra Visión', 'the Nosotros page must render the current vision');
  assertContains(nosotros.body, 'Promover una cultura de cuidado del medio ambiente', 'the mission must be page content');
  assertContains(nosotros.body, 'Ser la principal empresa comercializadora', 'the vision must be page content');
  const nosotrosId = wp(['eval', 'echo (int) ( get_option( "fp_shell_pages", array() )["nosotros"] ?? 0 );']).stdout;
  const originalContent = wp(['post', 'get', nosotrosId, '--field=post_content']).stdout;
  wp(['post', 'update', nosotrosId, '--post_content=<!-- wp:paragraph --><p>Edición de verificación de contenido editable.</p><!-- /wp:paragraph -->']);
  const edited = await get('/nosotros/', MOBILE_UA);
  assertContains(edited.body, 'Edición de verificación de contenido editable', 'editing the page content must change the rendered page');
  wp(['post', 'update', nosotrosId, `--post_content=${originalContent}`]);
  assertContains((await get('/nosotros/', MOBILE_UA)).body, 'Nuestra Misión', 'the original mission must render after restoring the content');

  /* 13.5 — Contacto renders the current contact surface with one CTA into
     Cotización and creates no Inquiry record. */
  const contacto = await get('/contacto/', MOBILE_UA);
  assert.equal(contacto.status, 200, '/contacto/ must return HTTP 200');
  assertContains(contacto.body, 'Visítanos', 'Contacto must render the warehouse section');
  assertContains(contacto.body, 'href="https://maps.app.goo.gl/QtGSdagB55W7rnRj7"', 'the warehouse/map must link the location');
  assertContains(contacto.body, 'href="tel:+56968444265"', 'Contacto must link the current phone');
  assertContains(contacto.body, 'href="https://api.whatsapp.com/send?phone=56968444265"', 'Contacto must link WhatsApp');
  assertContains(contacto.body, 'href="mailto:ventas@freeplast.cl"', 'Contacto must link the current email');
  assertContains(contacto.body, 'Lun a Vie 09:00 a 13:00 hrs y 14:00 a 18:00 hrs', 'Contacto must show the current hours');
  assert.equal(countMatches(contacto.body, '<div class="wp-block-button">'), 1, 'Contacto must carry exactly one CTA');
  assertContains(contacto.body, `href="${SITE_URL}/cotizacion/"`, 'the single CTA must enter the Quote Basket');
  assert.doesNotMatch(contacto.body, /<form[\s>]/i, 'Contacto must not create an Inquiry record — no form');
  assertAbsent(contacto.body, 'type="checkbox"', 'no acknowledgement checkbox on Contacto');

  /* 13.6 — Política de privacidad provides the basic collection/submission
     disclosure without a standalone acknowledgement checkbox. */
  const privacy = await get('/politica-de-privacidad/', MOBILE_UA);
  assert.equal(privacy.status, 200, '/politica-de-privacidad/ must return HTTP 200');
  assertContains(privacy.body, 'Qué información recopilamos', 'the disclosure must name what is collected');
  assertContains(privacy.body, 'Para qué la usamos', 'the disclosure must name the purpose');
  assertContains(privacy.body, 'ventas@freeplast.cl', 'the disclosure must name the recipient');
  assertContains(privacy.body, '30 días', 'the disclosure must describe the anonymous basket session');
  assert.doesNotMatch(privacy.body, /<form[\s>]/i, 'the privacy page must not render a form');
  assertAbsent(privacy.body, 'type="checkbox"', 'no standalone privacy acknowledgement checkbox');

  /* 13.7 — Search and 404 routes provide usable navigation and empty states. */
  const notFound = await get('/esta-ruta-no-existe/', MOBILE_UA);
  assert.equal(notFound.status, 404, 'an unknown URL must resolve as 404');
  assertContains(notFound.body, 'Página no encontrada', 'the 404 route must explain the situation');
  assertContains(notFound.body, 'role="search"', 'the 404 route must offer a search form');
  assertContains(notFound.body, 'href="/tienda/"', 'the 404 route must recover into the catalog');
  assertContains(notFound.body, 'href="/contacto/"', 'the 404 route must offer Contacto');
  const searchEmpty = await get('/?s=zzzz-sin-resultados-v6', MOBILE_UA);
  assertContains(searchEmpty.body, 'No encontramos resultados', 'search must keep its usable empty state');
  assertContains(searchEmpty.body, 'href="/tienda/"', 'the search empty state must recover into the catalog');

  /* 13.8 — Templates parse without block recovery. */
  const parseReport = JSON.parse(
    wp([
      'eval',
      '$theme = get_theme_file_path(); $out = array();' +
        'foreach ( array_merge( glob( $theme . "/templates/*.html" ), glob( $theme . "/parts/*.html" ) ) as $file ) {' +
        '$content = file_get_contents( $file ); $blocks = parse_blocks( $content ); $unparsed = 0;' +
        'foreach ( $blocks as $b ) { if ( null === $b["blockName"] && false !== strpos( (string) $b["innerHTML"], "<!-- wp:" ) ) { $unparsed++; } }' +
        '$opens = substr_count( $content, "<!-- wp:" );' +
        '$selfclosed = substr_count( $content, "/-->" );' +
        '$closes = substr_count( $content, "<!-- /wp:" );' +
        '$out[ basename( dirname( $file ) ) . "/" . basename( $file ) ] = array( "unparsed" => $unparsed, "balance" => $opens - $selfclosed - $closes ); }' +
        'echo wp_json_encode( $out );',
    ]).stdout
  );
  const expectedTemplates = [
    'templates/front-page.html',
    'templates/index.html',
    'templates/page.html',
    'templates/search.html',
    'templates/404.html',
    'templates/archive-fp_product.html',
    'templates/single-fp_product.html',
    'parts/header.html',
    'parts/footer.html',
  ].sort();
  assert.deepEqual(Object.keys(parseReport).sort(), expectedTemplates, 'every theme template and part must be inspected');
  for (const [file, report] of Object.entries(parseReport)) {
    assert.equal(report.unparsed, 0, `${file} must parse with no unparsed block markup (no block recovery)`);
    assert.equal(report.balance, 0, `${file} must have balanced block delimiters`);
  }

  /* 13.9 — The theme contains no Catalog or Quote Request business logic. */
  const walkFiles = (dir) => {
    const out = [];
    for (const entry of readdirSync(dir)) {
      const full = join(dir, entry);
      if (statSync(full).isDirectory()) out.push(...walkFiles(full));
      else out.push(full);
    }
    return out;
  };
  const forbidden = [
    'fp_product',
    'WP_Query',
    '$wpdb',
    'get_posts',
    'get_post_meta',
    'update_post_meta',
    'wp_insert_post',
    'register_block_type',
    'admin-post.php',
    'basket_sessions',
    'FREEPLAST_CQ',
  ];
  for (const file of walkFiles(themeDir)) {
    if (statSync(file).isFile() && /\.(php|js|css|html|json)$/.test(file)) {
      const content = readFileSync(file, 'utf8');
      for (const token of forbidden) {
        assert.ok(!content.includes(token), `${file.replace(themeDir + '/', '')} must not contain business logic (${token})`);
      }
      assert.ok(!content.includes('Newsreader') && !content.includes('Outfit'), `${file} must not use the rejected v5 typefaces`);
    }
  }

  /* 13.10 — The header count and mini basket remain accurate on every route. */
  const chooserPage = await get(PRODUCT_URL, MOBILE_UA);
  const nonce = chooserPage.body.match(/name="fp_basket_nonce" value="([a-f0-9]{10})"/)?.[1];
  assert.ok(nonce, 'a chooser must be available to build a multi-line basket');
  const addLine = (product, quantity, extraHeaders = {}) =>
    postForm(
      { action: 'fp_basket_add', fp_product: product, fp_quantity: quantity, fp_basket_nonce: nonce, _wp_http_referer: PRODUCT_URL },
      extraHeaders
    );
  const firstLine = await addLine('fp-caja-cosechera-3-4', '5');
  assert.equal(firstLine.status, 302, 'the first line must add successfully');
  const token = firstLine.setCookies[0].match(/fpcq_basket=([0-9a-f]{64})/)?.[1];
  assert.ok(token, 'the guest must own a session cookie');
  const cookieHeader = { cookie: `fpcq_basket=${token}` };
  const secondLine = await addLine('fp-caja-tomatera', '10', cookieHeader);
  assert.equal(secondLine.status, 302, 'the second line must add successfully');
  const routes = [
    '/',
    '/nosotros/',
    '/tienda/',
    '/tienda/categoria/agricola/',
    PRODUCT_URL,
    '/cotizacion/',
    '/contacto/',
    '/politica-de-privacidad/',
    '/?s=tomatera',
    '/ruta-que-no-existe/',
  ];
  for (const route of routes) {
    const res = await get(route, MOBILE_UA, cookieHeader);
    assertContains(res.body, 'Cotización (2)', `${route}: the header count must show both distinct lines`);
    assertContains(res.body, 'Caja Cosechera 3/4', `${route}: the mini basket must list the first line`);
    assertContains(res.body, 'Caja Tomatera', `${route}: the mini basket must list the second line`);
  }

  /* 13.11 — Migration 5 replaces exactly the legacy placeholder content;
     human edits survive. */
  const shellPages = JSON.parse(wp(['option', 'get', 'fp_shell_pages', '--format=json']).stdout);
  const contactoId = String(shellPages['contacto']);
  const privacyId = String(shellPages['politica-de-privacidad']);
  wp([
    'eval',
    `wp_update_post( array( "ID" => ${contactoId}, "post_content" => Freeplast_CQ_Shell::legacy_contacto_placeholder() ) );` +
      `wp_update_post( array( "ID" => ${privacyId}, "post_content" => Freeplast_CQ_Shell::legacy_privacy_placeholder() ) );` +
      'update_option( "fp_db_version", 4 );',
  ]);
  const migrated = wp(['eval', 'Freeplast_CQ_Migrations::run(); echo get_option( "fp_db_version" );']);
  assert.equal(migrated.stdout, '5', 're-running the migrations must apply migration 5');
  const contactoAfter = await get('/contacto/', MOBILE_UA);
  assertContains(contactoAfter.body, 'api.whatsapp.com/send?phone=56968444265', 'migration 5 must upgrade the legacy Contacto content');
  assertContains((await get('/politica-de-privacidad/', MOBILE_UA)).body, 'Qué información recopilamos', 'migration 5 must upgrade the legacy privacy content');

  wp([
    'eval',
    `wp_update_post( array( "ID" => ${privacyId}, "post_content" => "<!-- wp:paragraph --><p>Edición humana que debe sobrevivir.</p><!-- /wp:paragraph -->" ) );` +
      'update_option( "fp_db_version", 4 );',
  ]);
  wp(['eval', 'Freeplast_CQ_Migrations::run();']);
  const editedContent = wp(['post', 'get', privacyId, '--field=post_content']).stdout;
  assertContains(editedContent, 'Edición humana que debe sobrevivir', 'a human edit must never be clobbered by the migration');
  wp(['eval', `wp_update_post( array( "ID" => ${privacyId}, "post_content" => Freeplast_CQ_Shell::privacy_content() ) );`]);

  section('v6 content and navigation (issue #12)', [
    'Frozen v6 design contract: design-tokens.json + DECISIONS.md hashes verified; theme implements the v6 palette/Manrope/blue controls; v5 rules removed from the governing docs',
    'Logo/Inicio, Nosotros, Tienda, Cotización count/CTA and Contacto navigate to their approved destinations (desktop + mobile sheet + footer)',
    'Home: concise Nosotros and contact sections, eight Featured Products, and a Quote Basket summary/CTA — no second submission form',
    'Nosotros renders editable mission/vision page content; Contacto renders phone, email, WhatsApp, warehouse/map, hours + exactly one CTA into Cotización (no Inquiry record)',
    'Política de privacidad carries the basic collection/submission disclosure without an acknowledgement checkbox',
    'Search and 404 routes keep usable navigation and empty states; the header count (Cotización (2)) and mini basket stay accurate on every route',
    'All nine templates/parts parse without block recovery; the theme contains no Catalog or Quote Request business logic',
    'Migration 5 upgrades the legacy Contacto/privacy placeholders byte-safely; human edits survive',
  ]);
});

/* ── 14. Write VERIFICATION.md and clean up ─────────────────────────── */

test('record mechanical proof in wordpress/VERIFICATION.md', () => {
  const lines = [
    `# Mechanical verification — Freeplast WordPress shell + catalog + discovery + quote basket + v6 content (issues #2–#12)`,
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
    'Home renders the approved eight Featured Products in source-controlled order with quotation actions',
    '/tienda/ lists all 17 Active Products on one page; every card links its canonical URL and opens a quantity chooser',
    'Todos/Agrícola/Otros filters: labelled link controls with meaningful /tienda/categoria/<categoria>/ URLs and aria-current state; unknown categories 404',
    'Search finds Products (as cards with quotation actions) and standard pages, with a clear no-result state',
    'Related Products render up to three reviewed ids in reviewed order',
    'An archived Product disappears from Home, Tienda, categories, search and related lists, and its URL stops resolving',
    'Agregar a cotización opens a quantity chooser on catalog cards and the product page — never an unseen-quantity add',
    'A positive whole-unit quantity adds Caja Cosechera 3/4 through the authoritative nonce-guarded admin-post operation',
    'The session cookie carries only a random 256-bit opaque token (Secure, HttpOnly, SameSite=Lax, 30 days); only its sha256 hash is stored server-side',
    'The basket survives refresh and navigation; the header counts distinct lines (Cotización (n)); the mini basket shows Product, quantity and a route to /cotizacion/',
    'Invalid nonce, session, Product (unknown or archived) and quantity mutate nothing and return recoverable messages; the JavaScript enhancement receives JSON state',
    'Frozen v6 design contract (wordpress/design/): tokens + SHA-256-frozen approved prototypes verified; theme implements the v6 palette, Manrope and blue controls; v5 rules removed from the governing docs',
    'Logo/Inicio, Nosotros, Tienda, Cotización count/CTA and Contacto navigate to their approved destinations',
    'Home keeps the concise v6 composition: Nosotros + contact sections, eight Featured Products, Quote Basket summary/CTA — never a second submission form',
    'Nosotros renders editable mission/vision page content; Contacto renders phone, email, WhatsApp, warehouse/map, hours and exactly one CTA into Cotización (no Inquiry record)',
    'Política de privacidad provides the basic collection/submission disclosure without an acknowledgement checkbox',
    'Search and 404 keep usable navigation and empty states; the header count and mini basket stay accurate on every route',
    'All theme templates/parts parse without block recovery; the theme contains no Catalog or Quote Request business logic',
    'Migration 5 upgrades the legacy Contacto/privacy placeholders byte-safely; human edits survive',
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
    `- The discovery journey (Home featured, Tienda grid/filters, search) is plugin-rendered semantic markup (fpcq- v1) driven only by synchronized catalog metadata; the theme supplies the v6 presentation, and every card opens the basket quantity chooser.`,
    `- The Quote Basket is an anonymous cookie-backed server session (issue #6): the cookie never carries basket data, only its sha256 hash is persisted, and every mutation revalidates nonce, session, Product lifecycle/visibility and whole-unit quantity. Line editing/removal, option lines, expiry enforcement and the submission form arrive with issues #7/#8.`,
    `- The v6 content and navigation experience (issue #12) is verified through served documents on the clean disposable database; the frozen design contract lives in wordpress/design/ (tokens + hash-frozen approved prototypes). Pixel-level rendering and human visual approval remain Gate 3.`,
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
