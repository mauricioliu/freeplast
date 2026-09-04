#!/usr/bin/env node
/**
 * Freeplast WordPress shell — automated acceptance checks (issues #2–#9, #12).
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
 * verifies the acceptance criteria of issues #2 through #12 (including the
 *   issue #9 sales workflow):
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
 *  13. The Quote Basket is fully editable: Color Caja Universal choosers
 *      require one currently supported color option, the same Product+option
 *      merges quantities while different options stay separate lines, lines
 *      update and remove through nonce-guarded operations with JavaScript
 *      enabled or disabled, header count/mini basket/full view agree after
 *      every mutation, malformed or inactive Product/option submissions are
 *      rejected without mutation, confirmed minimum/step rules are enforced
 *      when present, logged-in staff browsers keep using the anonymous
 *      cookie basket (no user linking), and sessions expire 30 days after
 *      last activity (cookie cleared, empty state routes back to Tienda,
 *      daily sweep collects expired rows).
 *  14. The complete v6 content and navigation experience is governed by a
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
 *  15. The Quote Request submission completes the core customer outcome:
 *      /cotizacion/ is the sole final submission surface — the request
 *      form reads Product/options/quantities from the authenticated
 *      server basket (never duplicated request fields), validates the
 *      Freeplast business fields server-side (Nombre, Teléfono, Email,
 *      Nombre Empresa, Rut Empresa, Giro, Con Despacho = Sí/No; Mensaje
 *      optional/bounded; the manual Dirección de despacho only with
 *      dispatch), retains entered values and the basket after every
 *      invalid attempt with a focused linked error summary, persists
 *      exactly one non-public Quote Request with immutable Product
 *      snapshots and a permanent FP-YYYY-NNNNNN Request Reference, clears
 *      the basket only after durable persistence (a persistence failure
 *      shows no success and retains it), cannot duplicate the record on
 *      refresh/back/retry (idempotency token), drops archived Product
 *      lines, and exposes a minimal capability-protected admin detail —
 *      with no price, Quotation, Order, checkout or customer account.
 *
 * 16. The sales administration turns the persisted records into an
 *     operational workflow: the least-privilege Ventas Freeplast role
 *     (read + manage_freeplast_quotes, nothing else) reaches Cotizaciones
 *     while staying out of unrelated site administration, the list sorts
 *     (reference, company, email, created date, Request Status) and
 *     searches (reference, company, email, plus a status filter), the
 *     detail separates immutable Submitted Details from correctable
 *     Current Contact Details (corrections append a field/time/staff
 *     history event without PII values), internal Sales Notes append with
 *     author and timestamp, Request Status moves new → contacted →
 *     quoted → won/lost with permitted skips plus cancelled, terminal
 *     states reopen explicitly back to contacted, and every state change
 *     validates nonce + capability and records staff identity/time — with
 *     no Products in editor menus and no bulk CSV export.
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
const PLUGIN_MAIN = join(WORDPRESS_DIR, 'wp-content', 'plugins', 'freeplast-catalog-quotes', 'freeplast-catalog-quotes.php');
const DB_VERSION = Number(
  readFileSync(PLUGIN_MAIN, 'utf8').match(/FREEPLAST_CQ_DB_VERSION',\s*(\d+)\s*\)/)?.[1] || 0
);
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

/** The basket nonce of the form posting one admin-post action. */
function formNonce(html, action) {
  for (const form of html.split('<form ')) {
    if (form.includes(`name="action" value="${action}"`)) {
      return form.match(/name="fp_basket_nonce" value="([a-f0-9]{10})"/)?.[1];
    }
  }
  return undefined;
}

/** The submission nonce and idempotency token carried by one rendered request form. */
function requestCredentials(html) {
  return {
    nonce: html.match(/name="fp_request_nonce" value="([a-f0-9]{10})"/)?.[1],
    token: html.match(/name="fp_request_token" value="([0-9a-f]{32})"/)?.[1],
  };
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
  if (ids) wp(['post', 'delete', ...ids.split(/\s+/), '--force']);
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
  assert.doesNotMatch(res.body, /<form[\s>]/i, '/cotizacion/ must not contain a form while the basket is empty (the request form renders only with basket lines)');
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
    'every form on Home must be a basket quantity chooser — the request form lives only on /cotizacion/ below basket lines (issue #8)'
  );

  section('Cotización state', [
    '/cotizacion/ renders "Tu cotización está vacía" while the basket is empty — no form without lines',
    'Basket choosers are the only forms outside /cotizacion/; the request form (issue #8) renders there only below basket lines; prototype mailto/WhatsApp behavior is not a submission endpoint',
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
  assert.equal(wp(['option', 'get', 'fp_db_version']).stdout, String(DB_VERSION), `migration ${DB_VERSION} must be applied after activation (the sales workflow slice bumps it)`);

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

/* ── 13. Quote Basket editing, options, expiry (issue #7) ─────────── */

test('a guest can edit the Quote Basket: options, update/remove, expiry and staff-browser anonymity', { timeout: 180_000 }, async () => {
  const cookieHeader = (token) => ({ cookie: `fpcq_basket=${token}` });
  const noticeOf = (res) => new URL(res.headers.location || '', SITE_URL).searchParams.get('fpcq_notice');
  const COLOR_ID = 'fp-caja-universal-cerrada-color';
  const COLOR_URL = '/producto/caja-universal-cerrada-color/';

  // Universal Color choosers require one currently supported color option.
  const colorPage = await get(COLOR_URL, MOBILE_UA);
  assertContains(colorPage.body, 'class="fpcq-add-options"', 'the Color chooser must render a required option group');
  for (const color of SUPPORTED_COLOR_IDS) {
    assertContains(colorPage.body, `name="fp_option" value="${color}"`, `the supported color ${color} must be offered`);
  }
  assertAbsent(colorPage.body, 'name="fp_option" value="morado"', 'unsupported colors must not be offered');
  assertContains(colorPage.body, 'name="fp_option" value="blanco" required', 'the color choice must be required (one supported color per line)');
  const colorAddNonce = colorPage.body.match(/name="fp_basket_nonce" value="([a-f0-9]{10})"/)?.[1];
  assert.ok(colorAddNonce, 'the Color chooser must carry a nonce');

  const tiendaPage = await get('/tienda/', MOBILE_UA);
  assert.equal(
    countMatches(tiendaPage.body, 'class="fpcq-add-options"'),
    2,
    'the two Color Universal card choosers on Tienda must offer the color group'
  );
  const blackPage = await get('/producto/caja-universal-cerrada-negra/', MOBILE_UA);
  assertAbsent(blackPage.body, 'name="fp_option"', 'a Product without reviewed options must not offer an option chooser');

  // A fresh anonymous guest session for this section.
  const productPage = await get(PRODUCT_URL, MOBILE_UA);
  const addNonce = productPage.body.match(/name="fp_basket_nonce" value="([a-f0-9]{10})"/)?.[1];
  const add = (over = {}, headers = {}) =>
    postForm(
      {
        action: 'fp_basket_add',
        fp_product: 'fp-caja-cosechera-3-4',
        fp_quantity: '5',
        fp_basket_nonce: addNonce,
        _wp_http_referer: PRODUCT_URL,
        ...over,
      },
      headers
    );
  const seeded = await add();
  assert.equal(noticeOf(seeded), 'added');
  const token = seeded.setCookies[0].match(/fpcq_basket=([0-9a-f]{64})/)?.[1];
  assert.ok(token, 'the editing section needs its own guest session');

  // Header count, mini basket and full view agree after every mutation.
  const agrees = async (n, snippets = [], absent = []) => {
    for (const route of ['/', '/tienda/', '/cotizacion/']) {
      const page = await get(route, MOBILE_UA, cookieHeader(token));
      assertContains(page.body, `Cotización (${n})`, `header count on ${route} must be ${n}`);
      for (const snippet of snippets) assertContains(page.body, snippet, `${route} must show ${snippet}`);
      for (const snippet of absent) assertAbsent(page.body, snippet, `${route} must not show ${snippet}`);
    }
  };

  // Different options produce separate lines; the same option merges quantities.
  const addColor = (over = {}, headers = {}) =>
    postForm(
      {
        action: 'fp_basket_add',
        fp_product: COLOR_ID,
        fp_option: 'blanco',
        fp_quantity: '10',
        fp_basket_nonce: colorAddNonce,
        _wp_http_referer: COLOR_URL,
        ...over,
      },
      headers
    );
  assert.equal(noticeOf(await addColor({ fp_quantity: '10' }, cookieHeader(token))), 'added');
  assert.equal(noticeOf(await addColor({ fp_quantity: '4' }, cookieHeader(token))), 'added', 're-adding the same option must merge');
  assert.equal(noticeOf(await addColor({ fp_option: 'rojo', fp_quantity: '6' }, cookieHeader(token))), 'added', 'a different option must become its own line');
  await agrees(3, ['option">Blanco', '14 unidades', 'option">Rojo', '6 unidades', '5 unidades']);

  // Missing, unsupported and foreign options are rejected without mutation.
  const basketSnapshot = () => basketRows().map((row) => `${row.session_hash}|${row.lines}`).join(';');
  const before = basketSnapshot();
  for (const over of [{ fp_option: '' }, { fp_option: 'morado' }, { fp_option: 'negro-uv' }]) {
    const res = await addColor(over, cookieHeader(token));
    assert.equal(noticeOf(res), 'option', `the color submission ${JSON.stringify(over.fp_option)} must be rejected`);
  }
  assert.equal(noticeOf(await add({ fp_option: 'blanco' })), 'option', 'a Product without reviewed options must not accept one');
  assert.equal(basketSnapshot(), before, 'invalid option submissions must not mutate the basket');

  // Updating a line: plain POST → POST-redirect-GET (JavaScript disabled parity).
  const editPage = () => get('/cotizacion/', MOBILE_UA, cookieHeader(token));
  let html = (await editPage()).body;
  const updateNonce = formNonce(html, 'fp_basket_update');
  const removeNonce = formNonce(html, 'fp_basket_remove');
  assert.ok(updateNonce && removeNonce, 'every basket line must expose nonce-guarded update/remove forms');
  const update = (over = {}, headers = {}) =>
    postForm(
      {
        action: 'fp_basket_update',
        fp_product: COLOR_ID,
        fp_option: 'blanco',
        fp_quantity: '20',
        fp_basket_nonce: updateNonce,
        _wp_http_referer: '/cotizacion/',
        ...over,
      },
      headers
    );
  const updated = await update({}, cookieHeader(token));
  assert.equal(updated.status, 302, 'the update must answer the browser with a redirect');
  assert.equal(noticeOf(updated), 'updated');
  await agrees(3, ['option">Blanco', '20 unidades', 'option">Rojo', '6 unidades'], ['14 unidades']);

  // Removing a line: plain POST → POST-redirect-GET.
  const remove = (over = {}, headers = {}) =>
    postForm(
      {
        action: 'fp_basket_remove',
        fp_product: COLOR_ID,
        fp_option: 'rojo',
        fp_basket_nonce: removeNonce,
        _wp_http_referer: '/cotizacion/',
        ...over,
      },
      headers
    );
  const removed = await remove({}, cookieHeader(token));
  assert.equal(removed.status, 302, 'the remove must answer the browser with a redirect');
  assert.equal(noticeOf(removed), 'removed');
  await agrees(2, ['option">Blanco', '20 unidades', '5 unidades'], ['option">Rojo']);

  // Malformed update/remove submissions are rejected without mutation.
  const beforeEdits = basketSnapshot();
  const tomateraId = PRODUCT_BY_SLUG.get('caja-tomatera').source_id;
  for (const [label, over] of [
    ['zero quantity', { fp_quantity: '0' }],
    ['fractional quantity', { fp_quantity: '2.5' }],
    ['unknown product', { fp_product: 'fp-no-existe' }],
    ['unsupported option', { fp_option: 'morado' }],
    ['line not in basket', { fp_product: tomateraId, fp_option: '' }],
    ['bad nonce', { fp_basket_nonce: 'deadbeefdeadbeefdeadbeefdeadbeef' }],
  ]) {
    const res = await update(over, cookieHeader(token));
    assert.ok(['quantity', 'product', 'option', 'line', 'nonce'].includes(noticeOf(res)), `${label}: rejected with a recoverable message`);
  }
  assert.equal(noticeOf(await remove({ fp_product: tomateraId, fp_option: '' }, cookieHeader(token))), 'line', 'removing an absent line must be rejected');
  assert.equal(noticeOf(await remove({ fp_option: 'morado' }, cookieHeader(token))), 'option', 'removing an unsupported option must be rejected');
  assert.equal(noticeOf(await remove({ fp_product: 'fp-no-existe' }, cookieHeader(token))), 'product', 'removing an unknown product must be rejected');
  assert.equal(basketSnapshot(), beforeEdits, 'malformed edit submissions must not mutate the basket');

  // Archived products cannot be edited into the basket either.
  const polleraId = PRODUCT_BY_SLUG.get('caja-pollera').source_id;
  const polleraPostId = wp([
    'eval',
    `echo get_posts( array( "post_type" => "fp_product", "post_status" => "any", "posts_per_page" => 1, "fields" => "ids", "no_found_rows" => true, "suppress_filters" => true, "meta_key" => "_fp_source_id", "meta_value" => "${polleraId}" ) )[0];`,
  ]).stdout;
  wp(['post', 'update', polleraPostId, '--post_status=draft']);
  const archivedAdd = await postForm({
    action: 'fp_basket_add',
    fp_product: polleraId,
    fp_quantity: '3',
    fp_basket_nonce: addNonce,
    _wp_http_referer: PRODUCT_URL,
  });
  wp(['post', 'update', polleraPostId, '--post_status=publish']);
  assert.equal(noticeOf(archivedAdd), 'product', 'an archived Product must not be added');
  assert.equal(basketSnapshot(), beforeEdits, 'archived adds must not mutate the basket');

  // JavaScript enhancement: the same handlers answer JSON state in place.
  const enhancedUpdate = await postForm(
    {
      action: 'fp_basket_update',
      fp_product: 'fp-caja-cosechera-3-4',
      fp_option: '',
      fp_quantity: '1',
      fp_basket_nonce: updateNonce,
      _wp_http_referer: '/cotizacion/',
      fp_enhanced: '1',
    },
    { ...cookieHeader(token), 'x-requested-with': 'fetch' }
  );
  assert.equal(enhancedUpdate.status, 200, 'the enhanced update answers in place');
  let payload = JSON.parse(enhancedUpdate.body);
  assert.equal(payload.ok, true);
  assert.equal(payload.count, 2, 'the payload carries the distinct-line count');
  assert.ok(payload.message.includes('actualiz'), 'the payload carries the update message');
  assert.ok(payload.mini.includes('1 unidad'), 'the mini-basket payload reflects the new quantity');
  assert.ok(payload.view.includes('1 unidad'), 'the view payload reflects the new quantity');

  const enhancedRemove = await remove({ fp_option: 'blanco', fp_enhanced: '1' }, { ...cookieHeader(token), 'x-requested-with': 'fetch' });
  payload = JSON.parse(enhancedRemove.body);
  assert.equal(payload.ok, true);
  assert.equal(payload.count, 1, 'the enhanced remove answers JSON state');
  assert.ok(payload.message.includes('quit'), 'the payload carries the removal message');
  assert.ok(payload.view.includes('Caja Cosechera 3/4'), 'the view payload keeps the remaining line');
  assertAbsent(payload.view, 'Caja Universal', 'the removed line must leave the view payload');

  // Removing the last line returns the empty state with a route back to Tienda.
  const lastRemove = await postForm(
    {
      action: 'fp_basket_remove',
      fp_product: 'fp-caja-cosechera-3-4',
      fp_option: '',
      fp_basket_nonce: removeNonce,
      _wp_http_referer: '/cotizacion/',
    },
    cookieHeader(token)
  );
  assert.equal(noticeOf(lastRemove), 'removed');
  await agrees(0, ['Tu cotización está vacía']);
  const empty = await editPage();
  assertContains(empty.body, 'Explorar la tienda', 'the empty state must route back to Tienda');

  // Confirmed minimum/step rules are enforced when present (fixture sync).
  const minDoc = cloneSourceDoc();
  const pollera = minDoc.products.find((p) => p.source_id === polleraId);
  pollera.specs.minimum_quantity = 10;
  pollera.specs.quantity_step = 5;
  const minRun = catalogSync([], writeFullFixture('minstep.json', minDoc));
  assert.equal(minRun.status, 0, `the confirmed-rules sync must succeed:\n${minRun.stderr}`);
  assertContains(minRun.stdout, 'Summary: created=0 updated=1 unchanged=16 warnings=0 errors=0', 'only the confirmed rules may change');
  const polleraPage = await get('/producto/caja-pollera/', MOBILE_UA);
  assertContains(polleraPage.body, '>10 unidades</td>', 'a confirmed minimum is presented as such');

  const minSession = await postForm({
    action: 'fp_basket_add',
    fp_product: polleraId,
    fp_quantity: '10',
    fp_basket_nonce: addNonce,
    _wp_http_referer: '/producto/caja-pollera/',
  });
  const minToken = minSession.setCookies[0].match(/fpcq_basket=([0-9a-f]{64})/)?.[1];
  assert.ok(minToken, 'the confirmed-rules flow needs its own session');
  const minSnapshot = () => basketRows().find((row) => row.session_hash === createHash('sha256').update(minToken).digest('hex')).lines;
  assert.equal(minSnapshot(), JSON.stringify([{ product: polleraId, option: '', quantity: 10 }]));
  for (const quantity of ['5', '9', '12']) {
    const below = await postForm(
      {
        action: 'fp_basket_add',
        fp_product: polleraId,
        fp_quantity: quantity,
        fp_basket_nonce: addNonce,
        _wp_http_referer: '/producto/caja-pollera/',
      },
      cookieHeader(minToken)
    );
    assert.equal(noticeOf(below), 'quantity', `quantity ${quantity} violates the confirmed minimum/step rules`);
  }
  assert.equal(minSnapshot(), JSON.stringify([{ product: polleraId, option: '', quantity: 10 }]), 'rejected quantities must not mutate');
  const minHtml = (await get('/cotizacion/', MOBILE_UA, cookieHeader(minToken))).body;
  const minUpdateNonce = formNonce(minHtml, 'fp_basket_update');
  const updatePollera = (over = {}) =>
    postForm(
      {
        action: 'fp_basket_update',
        fp_product: polleraId,
        fp_option: '',
        fp_quantity: '15',
        fp_basket_nonce: minUpdateNonce,
        _wp_http_referer: '/cotizacion/',
        ...over,
      },
      cookieHeader(minToken)
    );
  assert.equal(noticeOf(await updatePollera()), 'updated', 'an on-step update must succeed');
  assert.equal(noticeOf(await updatePollera({ fp_quantity: '11' })), 'quantity', 'an off-step update must be rejected');
  assert.equal(
    minSnapshot(),
    JSON.stringify([{ product: polleraId, option: '', quantity: 15 }]),
    'the confirmed rules must hold after the update'
  );
  const restoreRules = catalogSync();
  assert.equal(restoreRules.status, 0, `restoring the reviewed source must succeed:\n${restoreRules.stderr}`);
  assert.equal(productMeta(polleraId, '_fp_quote_min_qty'), '', 'the unconfirmed minimum must be deleted again (no minimum claim)');

  // A logged-in staff browser still uses the anonymous cookie basket.
  const staffCookie = wp([
    'eval',
    'echo "wordpress_" . COOKIEHASH . "=" . wp_generate_auth_cookie( 1, time() + 3600, "auth" ) . "; wordpress_logged_in_" . COOKIEHASH . "=" . wp_generate_auth_cookie( 1, time() + 3600, "logged_in" );',
  ]).stdout;
  assert.ok(staffCookie.includes('='), 'a staff auth cookie must be generated');
  const staffHeaders = { cookie: `${staffCookie}; fpcq_basket=${token}` };
  assert.equal((await get('/wp-admin/profile.php', MOBILE_UA, { cookie: staffCookie })).status, 200, 'the staff cookie must authenticate');
  const staffCotizacion = await get('/cotizacion/', MOBILE_UA, staffHeaders);
  assertContains(staffCotizacion.body, 'Tu cotización está vacía', 'the logged-in staff browser sees its anonymous basket');
  const staffPage = await get(PRODUCT_URL, MOBILE_UA, staffHeaders);
  const staffNonce = staffPage.body.match(/name="fp_basket_nonce" value="([a-f0-9]{10})"/)?.[1];
  assert.ok(staffNonce, 'the chooser must render for the logged-in staff browser');
  const rowsBefore = basketRows().length;
  const staffAdd = await postForm(
    {
      action: 'fp_basket_add',
      fp_product: 'fp-caja-cosechera-3-4',
      fp_quantity: '7',
      fp_basket_nonce: staffNonce,
      _wp_http_referer: PRODUCT_URL,
    },
    staffHeaders
  );
  assert.equal(noticeOf(staffAdd), 'added', 'the staff browser adds through the same anonymous session');
  assert.equal(basketRows().length, rowsBefore, 'no new session may be created for the logged-in staff browser');
  assertContains((await get('/cotizacion/', MOBILE_UA, staffHeaders)).body, '7 unidades', 'the anonymous basket carries the staff-added line');
  const linked = wp([
    'eval',
    'global $wpdb; echo (int) $wpdb->get_var( \'SELECT COUNT(*) FROM \' . $wpdb->prefix . \'usermeta WHERE meta_key LIKE "%basket%"\' );',
  ]).stdout;
  assert.equal(linked, '0', 'no customer user linking or merge behavior may exist');

  // Anonymous sessions expire 30 days after the last activity.
  const expiredHash = createHash('sha256').update(token).digest('hex');
  wp([
    'eval',
    `global $wpdb; $old = gmdate( "Y-m-d H:i:s", time() - 40 * DAY_IN_SECONDS ); $wpdb->query( 'UPDATE ' . $wpdb->prefix . 'basket_sessions SET created_at = "' . $old . '", last_activity = "' . $old . '" WHERE session_hash = "${expiredHash}"' );`,
  ]);
  const expired = await get('/cotizacion/', MOBILE_UA, cookieHeader(token));
  assert.equal(expired.status, 302, 'an expired session must resolve as absent (recoverable redirect)');
  const expiredLocation = expired.headers.get('location');
  assert.equal(new URL(expiredLocation, SITE_URL).searchParams.get('fpcq_notice'), 'expired');
  assert.ok(
    expired.headers.getSetCookie().some((cookie) => /fpcq_basket=(deleted;|;)/.test(cookie)),
    'the expired cookie must be cleared so the guest starts fresh'
  );
  const afterExpiry = await get(expiredLocation.replace(SITE_URL, ''), MOBILE_UA);
  assertContains(afterExpiry.body, 'Tu cotización está vacía', 'the expired basket state must show the empty state');
  assertContains(afterExpiry.body, 'expir', 'the expired basket state must explain itself');
  assertContains(afterExpiry.body, 'Explorar la tienda', 'the expired basket state must route back to Tienda');

  const collected = Number(wp(['eval', 'echo Freeplast_CQ_Basket::gc();']).stdout || '0');
  assert.ok(collected >= 1, 'the expiry sweep must collect expired sessions');
  assert.equal(
    basketRows().filter((row) => row.session_hash === expiredHash).length,
    0,
    'the expired session row must be gone after the sweep'
  );

  section('Quote Basket editing and options (issue #7)', [
    'Color Caja Universal choosers require one currently supported color (cards + product page); missing/unsupported/foreign options are rejected without mutation',
    'Different options produce separate lines; the same product+option merges quantities; header count, mini basket and full view agree after every mutation and refresh',
    'Lines update and remove through nonce-guarded admin-post operations with JavaScript enabled (JSON state) or disabled (POST-redirect-GET)',
    'Malformed update/remove submissions (quantity, product, option, line, nonce, archived products) are rejected without mutation',
    'Confirmed minimum/step rules are enforced when present; unknown rules accept any positive whole unit and make no minimum claim',
    'A logged-in staff browser still uses the anonymous cookie basket; no user linking or merge exists',
    'Sessions expire 30 days after last activity: the cookie is cleared, the empty state routes back to Tienda, and the sweep collects expired rows',
  ]);
});


/* ── 14. v6 content and navigation experience (issue #12) ───────────── */

test('the complete v6 content and navigation experience is governed, connected and honest', { timeout: 120_000 }, async () => {
  /* 14.1 — A frozen v6 design contract governs the presentation; the
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

  /* 14.2 — Logo/Inicio, Nosotros, Tienda, Cotización count/CTA and Contacto
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

  /* 14.3 — Home retains the concise v6 composition: Nosotros + contact
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

  /* 14.4 — Nosotros renders the current mission and vision as editable
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

  /* 14.5 — Contacto renders the current contact surface with one CTA into
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

  /* 14.6 — Política de privacidad provides the basic collection/submission
     disclosure without a standalone acknowledgement checkbox. */
  const privacy = await get('/politica-de-privacidad/', MOBILE_UA);
  assert.equal(privacy.status, 200, '/politica-de-privacidad/ must return HTTP 200');
  assertContains(privacy.body, 'Qué información recopilamos', 'the disclosure must name what is collected');
  assertContains(privacy.body, 'Para qué la usamos', 'the disclosure must name the purpose');
  assertContains(privacy.body, 'ventas@freeplast.cl', 'the disclosure must name the recipient');
  assertContains(privacy.body, '30 días', 'the disclosure must describe the anonymous basket session');
  assert.doesNotMatch(privacy.body, /<form[\s>]/i, 'the privacy page must not render a form');
  assertAbsent(privacy.body, 'type="checkbox"', 'no standalone privacy acknowledgement checkbox');

  /* 14.7 — Search and 404 routes provide usable navigation and empty states. */
  const notFound = await get('/esta-ruta-no-existe/', MOBILE_UA);
  assert.equal(notFound.status, 404, 'an unknown URL must resolve as 404');
  assertContains(notFound.body, 'Página no encontrada', 'the 404 route must explain the situation');
  assertContains(notFound.body, 'role="search"', 'the 404 route must offer a search form');
  assertContains(notFound.body, 'href="/tienda/"', 'the 404 route must recover into the catalog');
  assertContains(notFound.body, 'href="/contacto/"', 'the 404 route must offer Contacto');
  const searchEmpty = await get('/?s=zzzz-sin-resultados-v6', MOBILE_UA);
  assertContains(searchEmpty.body, 'No encontramos resultados', 'search must keep its usable empty state');
  assertContains(searchEmpty.body, 'href="/tienda/"', 'the search empty state must recover into the catalog');

  /* 14.8 — Templates parse without block recovery. */
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

  /* 14.9 — The theme contains no Catalog or Quote Request business logic. */
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

  /* 14.10 — The header count and mini basket remain accurate on every route. */
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

  /* 14.11 — Migration 5 replaces exactly the legacy placeholder content;
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
  assert.equal(migrated.stdout, String(DB_VERSION), `re-running the migrations from 4 must apply migrations 5 and ${DB_VERSION}`);
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

/* ── 15. Quote Request submission (issue #8) ────────────────────── */

test('a guest submits exactly one Quote Request from the authenticated basket', { timeout: 240_000 }, async () => {
  const cookieHeader = (token) => ({ cookie: `fpcq_basket=${token}` });
  const noticeOf = (res) => new URL(res.headers.location || '', SITE_URL).searchParams.get('fpcq_notice');
  const submittedRef = (res) => new URL(res.headers.location || '', SITE_URL).searchParams.get('fpcq_submitted');
  const COLOR_ID = 'fp-caja-universal-cerrada-color';
  const COLOR_URL = '/producto/caja-universal-cerrada-color/';

  const quoteCount = () => Number(wp(['post', 'list', '--post_type=fp_quote', '--post_status=private', '--format=count']).stdout || '0');
  const quoteIds = () => wp(['post', 'list', '--post_type=fp_quote', '--post_status=private', '--orderby=ID', '--order=ASC', '--format=ids']).stdout;
  const quoteRecord = (reference) =>
    JSON.parse(
      wp([
        'eval',
        `$posts = get_posts( array( "post_type" => "fp_quote", "post_status" => "private", "posts_per_page" => 1, "no_found_rows" => true, "suppress_filters" => true, "meta_key" => "_fpq_reference", "meta_value" => "${reference}" ) );` +
          'if ( empty( $posts ) ) { echo "null"; } else { $p = $posts[0]; echo wp_json_encode( array( ' +
          '"id" => $p->ID, "post_status" => $p->post_status, "title" => $p->post_title, ' +
          '"status" => (string) get_post_meta( $p->ID, "_fpq_status", true ), ' +
          '"customer" => json_decode( (string) get_post_meta( $p->ID, "_fpq_customer", true ), true ), ' +
          '"items" => json_decode( (string) get_post_meta( $p->ID, "_fpq_items", true ), true ), ' +
          '"idempotency" => (string) get_post_meta( $p->ID, "_fpq_idempotency", true ), ' +
          '"type_public" => (bool) get_post_type_object( "fp_quote" )->public, ' +
          '"type_queryable" => (bool) get_post_type_object( "fp_quote" )->publicly_queryable, ' +
          '"type_rest" => (bool) get_post_type_object( "fp_quote" )->show_in_rest ) ); }',
      ]).stdout || 'null'
    );

  const usersTotal = () => Number(wp(['eval', 'echo count_users()["total_users"];']).stdout || '0');
  const usersBefore = usersTotal();
  const types = wp(['eval', 'echo implode( ",", get_post_types() );']).stdout;
  assert.ok(!types.includes('shop_order') && !types.includes('fp_quotation'), 'no Order or Quotation record type may exist');

  /* 15.1 — Without basket lines there is nothing to submit on the sole
     submission surface. */
  const emptyCot = await get('/cotizacion/', MOBILE_UA);
  assert.doesNotMatch(emptyCot.body, /fpcq-request-form/, 'the request form must not render without basket lines');

  /* 15.2 — Build an authenticated two-line basket (plain + Color option). */
  const productPage = await get(PRODUCT_URL, MOBILE_UA);
  const addNonce = productPage.body.match(/name="fp_basket_nonce" value="([a-f0-9]{10})"/)?.[1];
  assert.ok(addNonce, 'a chooser nonce is needed to build the basket');
  const add = (over = {}, headers = {}) =>
    postForm(
      {
        action: 'fp_basket_add',
        fp_product: 'fp-caja-cosechera-3-4',
        fp_quantity: '5',
        fp_basket_nonce: addNonce,
        _wp_http_referer: PRODUCT_URL,
        ...over,
      },
      headers
    );
  const seeded = await add();
  const token = seeded.setCookies[0].match(/fpcq_basket=([0-9a-f]{64})/)?.[1];
  assert.ok(token, 'the submission flow needs its own guest session');
  const colorPage = await get(COLOR_URL, MOBILE_UA);
  const colorAddNonce = colorPage.body.match(/name="fp_basket_nonce" value="([a-f0-9]{10})"/)?.[1];
  const colorAdd = await postForm(
    {
      action: 'fp_basket_add',
      fp_product: COLOR_ID,
      fp_option: 'blanco',
      fp_quantity: '12',
      fp_basket_nonce: colorAddNonce,
      _wp_http_referer: COLOR_URL,
    },
    cookieHeader(token)
  );
  assert.equal(noticeOf(colorAdd), 'added', 'the Color line must join the basket');

  /* 15.3 — The request form renders below the basket lines with the current
     Freeplast business fields, its own nonce and the idempotency token. */
  let cot = await get('/cotizacion/', MOBILE_UA, cookieHeader(token));
  assertContains(cot.body, 'Cotización (2)', 'the basket must carry both lines');
  assertContains(cot.body, 'class="fpcq-request-form"', 'the request form must render below the basket');
  for (const field of ['nombre', 'telefono', 'email', 'empresa', 'rut', 'giro']) {
    assert.ok(
      new RegExp(`name="fp_${field}"[^>]*required`).test(cot.body),
      `${field} must be a required input`
    );
  }
  assertContains(cot.body, 'type="radio" name="fp_despacho" value="si" required', 'Con Despacho must be a required Sí choice');
  assertContains(cot.body, 'type="radio" name="fp_despacho" value="no" required', 'Con Despacho must be a required No choice');
  assertContains(cot.body, 'name="fp_mensaje"', 'Mensaje must be offered');
  assert.ok(!/name="fp_mensaje"[^>]*required/.test(cot.body), 'Mensaje must stay optional');
  assertContains(cot.body, 'fpcq-hidden" data-fpcq-address-field', 'without dispatch selected the Dirección de despacho is omitted (hidden)');
  assertContains(cot.body, 'name="action" value="fp_request_submit"', 'the form targets the submission operation');
  assertContains(cot.body, 'href="/politica-de-privacidad/"', 'the form carries its disclosure link');
  const requestSection = pluginSection(cot.body, 'class="fpcq-request"', 'the request form section must render');
  assert.doesNotMatch(requestSection, /name="fp_product"/i, 'products must never be request-form fields');
  assert.doesNotMatch(requestSection, /name="fp_quantity"/i, 'quantities must never be request-form fields');
  let { nonce: reqNonce, token: idemToken } = requestCredentials(cot.body);
  assert.ok(reqNonce && idemToken, 'the form must be nonce-guarded and carry the idempotency token');

  const validFields = {
    fp_nombre: 'María González',
    fp_telefono: '+56 9 6844 4265',
    fp_email: 'maria@acme.cl',
    fp_empresa: 'Agrícola ACME SpA',
    fp_rut: '76.335.888-6',
    fp_giro: 'Comercialización de productos plásticos',
    fp_despacho: 'si',
    fp_direccion: 'Camino El Arrayán 52, San Francisco de Mostazal',
    fp_mensaje: 'Necesitamos las cajas para la próxima cosecha.',
  };
  const submit = (over = {}, headers = cookieHeader(token)) =>
    postForm(
      {
        action: 'fp_request_submit',
        ...validFields,
        fp_request_nonce: reqNonce,
        fp_request_token: idemToken,
        _wp_http_referer: '/cotizacion/',
        ...over,
      },
      headers
    );

  /* 15.4 — Invalid submissions retain values and basket, and return a
     focused linked summary plus inline errors. */
  for (const [label, over] of [
    ['missing nombre', { fp_nombre: '' }],
    ['missing teléfono', { fp_telefono: '' }],
    ['missing email', { fp_email: '' }],
    ['missing empresa', { fp_empresa: '' }],
    ['missing rut', { fp_rut: '' }],
    ['missing giro', { fp_giro: '' }],
    ['missing despacho', { fp_despacho: '' }],
    ['malformed email', { fp_email: 'no-es-un-email' }],
    ['invalid telephone', { fp_telefono: 'llamanos' }],
    ['invalid dispatch value', { fp_despacho: 'tal vez' }],
    ['missing dirección with dispatch', { fp_direccion: '' }],
    ['unbounded mensaje', { fp_mensaje: 'x'.repeat(2001) }],
  ]) {
    const res = await submit(over);
    assert.equal(res.status, 302, `${label}: must answer POST-redirect-GET`);
    assert.equal(noticeOf(res), 'request_invalid', `${label}: must be a recoverable invalid submission`);
    assert.ok((res.headers.location || '').includes('#fpcq-form-errors'), `${label}: the redirect must focus the error summary`);
  }

  // Every required field missing at once: the focused summary links each one.
  const allMissing = await submit({
    fp_nombre: '',
    fp_telefono: '',
    fp_email: '',
    fp_empresa: '',
    fp_rut: '',
    fp_giro: '',
    fp_despacho: '',
    fp_direccion: '',
    fp_mensaje: '',
  });
  assert.equal(noticeOf(allMissing), 'request_invalid', 'an all-empty submission is invalid');
  const invalidBack = await get('/cotizacion/', MOBILE_UA, cookieHeader(token));
  assertContains(invalidBack.body, 'id="fpcq-form-errors"', 'the retained page must carry the error summary');
  assertContains(invalidBack.body, 'role="alert"', 'the error summary must be announced');
  for (const key of ['nombre', 'telefono', 'email', 'empresa', 'rut', 'giro', 'despacho']) {
    assertContains(invalidBack.body, `href="#fp-${key}"`, `the summary must link the ${key} field`);
  }
  assertContains(invalidBack.body, 'id="fp-email-error"', 'email must carry its inline error');
  assertContains(invalidBack.body, 'aria-invalid="true"', 'invalid fields must be marked');

  // One invalid field among valid ones: every other entered value is retained.
  const oneBad = await submit({ fp_nombre: '' });
  assert.equal(noticeOf(oneBad), 'request_invalid');
  const retainedBack = await get('/cotizacion/', MOBILE_UA, cookieHeader(token));
  assertContains(retainedBack.body, 'value="maria@acme.cl"', 'the entered email must be retained');
  assertContains(retainedBack.body, 'value="Agrícola ACME SpA"', 'the entered empresa must be retained');
  assertContains(retainedBack.body, 'value="76.335.888-6"', 'the entered rut must be retained');
  assertContains(retainedBack.body, 'value="si" checked', 'the dispatch choice must be retained');
  assert.ok(!retainedBack.body.includes('fpcq-hidden" data-fpcq-address-field'), 'with dispatch retained Sí the dirección must be visible');
  assertContains(retainedBack.body, 'Camino El Arrayán 52', 'the entered dirección must be retained');
  assertContains(retainedBack.body, 'Necesitamos las cajas para la próxima cosecha.', 'the optional mensaje must be retained');
  assertContains(retainedBack.body, 'Cotización (2)', 'the basket must be retained after invalid submissions');
  assertContains(retainedBack.body, 'Caja Cosechera 3/4', 'the basket lines must still render');
  assert.equal(quoteCount(), 0, 'invalid submissions must persist nothing');

  // The missing-dirección-with-dispatch case re-renders the address field
  // visible (retained Sí) — the server is the authority without JavaScript.
  const noAddress = await submit({ fp_direccion: '' });
  assert.equal(noticeOf(noAddress), 'request_invalid');
  const noAddressBack = await get('/cotizacion/', MOBILE_UA, cookieHeader(token));
  assertContains(noAddressBack.body, 'La dirección de despacho es obligatoria', 'the dirección error must be explicit');
  assertContains(noAddressBack.body, 'href="#fp-direccion"', 'the summary must link the dirección field');

  // Guard failures: nonce, session, idempotency token.
  assert.equal(noticeOf(await submit({ fp_request_nonce: 'deadbeefdeadbeefdeadbeefdeadbeef' })), 'nonce', 'a bad nonce must be rejected');
  assert.equal(noticeOf(await submit({ fp_request_token: 'deadbeefdeadbeefdeadbeefdeadbeef' })), 'token', 'a foreign idempotency token must be rejected');
  assert.equal(noticeOf(await submit({}, {})), 'session', 'a sessionless submission must be rejected');
  assert.equal(quoteCount(), 0, 'guard failures must persist nothing');

  /* 15.5 — A valid submission persists exactly one non-public record,
     clears the basket and confirms with a permanent reference. */
  const ok = await submit();
  assert.equal(ok.status, 302, 'the valid submission must answer POST-redirect-GET');
  const location = new URL(ok.headers.location, SITE_URL);
  assert.equal(location.pathname, '/cotizacion/', 'the redirect must return to the sole submission surface');
  const reference = location.searchParams.get('fpcq_submitted');
  assert.match(reference, /^FP-\d{4}-\d{6}$/, 'the Request Reference must follow FP-YYYY-NNNNNN');

  const confirmed = await get(`/cotizacion/?fpcq_submitted=${reference}`, MOBILE_UA, cookieHeader(token));
  assertContains(confirmed.body, reference, 'the confirmation must display the Request Reference');
  assertContains(confirmed.body, 'Solicitud recibida', 'the confirmation must state the outcome');
  assertContains(confirmed.body, 'Cotización (0)', 'the basket must be cleared after durable persistence');
  assertContains(confirmed.body, 'Tu cotización está vacía', 'the cleared basket renders its empty state');
  assert.doesNotMatch(confirmed.body, /fpcq-request-form/, 'no request form renders after submission');
  // Refreshing the confirmation keeps the reference and creates nothing.
  assertContains(
    (await get(`/cotizacion/?fpcq_submitted=${reference}`, MOBILE_UA, cookieHeader(token))).body,
    reference,
    'refreshing the confirmation keeps the reference'
  );
  assert.equal(quoteCount(), 1, 'exactly one Quote Request must exist');

  const record = quoteRecord(reference);
  assert.ok(record, 'the persisted record must be readable');
  assert.equal(record.post_status, 'private', 'the record must be non-public (private status)');
  assert.equal(record.title, reference, 'the record title is the business reference');
  assert.equal(record.status, 'new', 'a fresh request starts in status new');
  assert.equal(record.type_public, false, 'the record type must not be public');
  assert.equal(record.type_queryable, false, 'the record type must not be publicly queryable');
  assert.equal(record.type_rest, false, 'the record type must not be REST-exposed');
  assert.match(record.idempotency, /^[0-9a-f]{64}$/, 'the idempotency hash must be stored');

  assert.equal(record.customer.nombre, 'María González');
  assert.equal(record.customer.telefono, '+56 9 6844 4265', 'the entered telephone is preserved');
  assert.equal(record.customer.telefono_normalizado, '+56968444265', 'a normalized telephone is stored when derivable');
  assert.equal(record.customer.email, 'maria@acme.cl');
  assert.equal(record.customer.empresa, 'Agrícola ACME SpA');
  assert.equal(record.customer.rut, '76.335.888-6');
  assert.equal(record.customer.giro, 'Comercialización de productos plásticos');
  assert.equal(record.customer.con_despacho, 'si');
  assert.equal(record.customer.direccion_despacho, 'Camino El Arrayán 52, San Francisco de Mostazal');
  assert.equal(record.customer.mensaje, 'Necesitamos las cajas para la próxima cosecha.');

  assert.equal(record.items.length, 2, 'both basket lines submit');
  const bySource = Object.fromEntries(record.items.map((item) => [item.source_id, item]));
  const cosechera = bySource['fp-caja-cosechera-3-4'];
  const universal = bySource[COLOR_ID];
  assert.ok(cosechera && universal, 'the items must carry the immutable source identity');
  assert.deepEqual(
    Object.keys(cosechera).sort(),
    ['dimensions', 'material', 'minimum', 'option_id', 'option_label', 'post_id', 'quantity', 'source_id', 'step', 'title', 'url', 'weight'],
    'the immutable snapshot must carry exactly the committed shape (no price fields)'
  );
  assert.equal(cosechera.title, 'Caja Cosechera 3/4');
  assert.equal(cosechera.option_id, '');
  assert.equal(cosechera.quantity, 5);
  assert.equal(cosechera.minimum, null, 'unconfirmed minimums snapshot as null');
  assert.equal(cosechera.step, null);
  assert.equal(cosechera.material, 'Polietileno de alta densidad reciclado');
  assert.equal(cosechera.dimensions, '600 x 400 x 180 mm');
  assert.ok(cosechera.url.includes('/producto/caja-cosechera-3-4/'), 'the snapshot carries the canonical URL');
  assert.equal(universal.option_id, 'blanco', 'the reviewed option submits with the line');
  assert.equal(universal.option_label, 'Blanco');
  assert.equal(universal.quantity, 12);
  assert.ok(universal.post_id > 0, 'the snapshot carries the Product identity');

  // No public route exposes the record.
  assert.notEqual((await get(`/?p=${record.id}`, MOBILE_UA)).status, 200, 'the record must have no public URL');
  assert.notEqual((await get('/wp-json/wp/v2/fp_quote', MOBILE_UA)).status, 200, 'the record must not be REST-queryable');

  /* 15.6 — Idempotency: retrying the same form (refresh/back/repost)
     returns the existing confirmation and never duplicates the record. */
  const retry = await submit();
  assert.equal(retry.status, 302);
  assert.equal(submittedRef(retry), reference, 'the retry must confirm the same request');
  assert.equal(quoteCount(), 1, 'the retry must not duplicate the Quote Request');
  assertContains((await get(`/cotizacion/?fpcq_submitted=${reference}`, MOBILE_UA, cookieHeader(token))).body, reference, 'the retry keeps the confirmation');

  /* 15.7 — A second basket submits a second request without dispatch —
     the address is omitted, a fresh token is issued, the reference differs. */
  const reAdd = await add(
    {
      fp_product: 'fp-caja-tomatera',
      fp_quantity: '30',
    },
    cookieHeader(token)
  );
  assert.equal(noticeOf(reAdd), 'added', 'the session can build a new basket');
  cot = await get('/cotizacion/', MOBILE_UA, cookieHeader(token));
  ({ nonce: reqNonce, token: idemToken } = requestCredentials(cot.body));
  assert.ok(reqNonce && idemToken, 'the fresh form carries its own nonce and token');
  const ok2 = await submit({ fp_despacho: 'no', fp_direccion: '', fp_mensaje: '' });
  const reference2 = submittedRef(ok2);
  assert.match(reference2, /^FP-\d{4}-\d{6}$/);
  assert.notEqual(reference2, reference, 'each request receives its own unique reference');
  assert.equal(quoteCount(), 2, 'two Quote Requests now exist');
  const record2 = quoteRecord(reference2);
  assert.equal(record2.customer.con_despacho, 'no');
  assert.equal(record2.customer.direccion_despacho, '', 'without dispatch no address data is stored');
  assert.equal(record2.items.length, 1, 'only the new basket line submits');
  assert.equal(record2.items[0].source_id, 'fp-caja-tomatera');
  assert.equal(record2.items[0].quantity, 30);

  /* 15.8 — A persistence failure shows no success and retains basket and
     values (the record is created only by durable persistence). */
  const failAdd = await add({ fp_quantity: '3' }, cookieHeader(token));
  assert.equal(noticeOf(failAdd), 'added');
  cot = await get('/cotizacion/', MOBILE_UA, cookieHeader(token));
  ({ nonce: reqNonce, token: idemToken } = requestCredentials(cot.body));
  const muDir = join(WP_DIR, 'wp-content', 'mu-plugins');
  mkdirSync(muDir, { recursive: true });
  writeFileSync(join(muDir, 'fp-test-no-persist.php'), "<?php\nadd_filter( 'freeplast_cq_request_persist', '__return_false' );\n");
  try {
    const failed = await submit({ fp_despacho: 'no', fp_direccion: '', fp_mensaje: '' });
    assert.equal(failed.status, 302);
    assert.equal(noticeOf(failed), 'request_failed', 'a persistence failure must not claim success');
    assert.ok(!(failed.headers.location || '').includes('fpcq_submitted'), 'no confirmation may be shown');
    assert.equal(quoteCount(), 2, 'a failed persistence must create no record');
    const failedBack = await get('/cotizacion/', MOBILE_UA, cookieHeader(token));
    assertContains(failedBack.body, 'Cotización (1)', 'the basket must be retained');
    assertContains(failedBack.body, 'No pudimos guardar tu solicitud', 'the retained page explains the failure');
    assertContains(failedBack.body, 'value="María González"', 'the entered values must be retained');
  } finally {
    rmSync(join(muDir, 'fp-test-no-persist.php'), { force: true });
  }
  // The same form (same token) succeeds once the store works again.
  const ok3 = await submit({ fp_despacho: 'no', fp_direccion: '', fp_mensaje: '' });
  assert.match(submittedRef(ok3), /^FP-\d{4}-\d{6}$/, 'the retried submission persists');
  assert.equal(quoteCount(), 3);

  /* 15.9 — Eligibility: an archived Product line drops out of the
     submission (the basket resolves against the live catalog). */
  const eligibilitySession = await add({ fp_quantity: '7' });
  const eligToken = eligibilitySession.setCookies[0].match(/fpcq_basket=([0-9a-f]{64})/)?.[1];
  assert.ok(eligToken, 'the eligibility flow needs its own session');
  const secondAdd = await add(
    {
      fp_product: 'fp-caja-frutillera',
      fp_quantity: '9',
      _wp_http_referer: '/producto/caja-frutillera/',
    },
    cookieHeader(eligToken)
  );
  assert.equal(noticeOf(secondAdd), 'added');
  const frutilleraPostId = wp([
    'eval',
    'echo get_posts( array( "post_type" => "fp_product", "post_status" => "any", "posts_per_page" => 1, "fields" => "ids", "no_found_rows" => true, "suppress_filters" => true, "meta_key" => "_fp_source_id", "meta_value" => "fp-caja-frutillera" ) )[0];',
  ]).stdout;
  wp(['post', 'update', frutilleraPostId, '--post_status=draft']);
  const eligCot = await get('/cotizacion/', MOBILE_UA, cookieHeader(eligToken));
  assertContains(eligCot.body, 'Cotización (1)', 'the archived line drops out before submission');
  assertAbsent(eligCot.body, 'Caja Frutillera', 'the archived Product must not render');
  const { nonce: eligNonce, token: eligTokenField } = requestCredentials(eligCot.body);
  const eligSubmit = await postForm(
    {
      action: 'fp_request_submit',
      ...validFields,
      fp_despacho: 'no',
      fp_direccion: '',
      fp_mensaje: '',
      fp_request_nonce: eligNonce,
      fp_request_token: eligTokenField,
      _wp_http_referer: '/cotizacion/',
    },
    cookieHeader(eligToken)
  );
  wp(['post', 'update', frutilleraPostId, '--post_status=publish']);
  const eligRef = submittedRef(eligSubmit);
  assert.match(eligRef, /^FP-\d{4}-\d{6}$/);
  const eligRecord = quoteRecord(eligRef);
  assert.equal(eligRecord.items.length, 1, 'only the published Product submits');
  assert.equal(eligRecord.items[0].source_id, 'fp-caja-cosechera-3-4');
  assert.equal(eligRecord.items[0].quantity, 7);

  /* 15.10 — Minimal capability-protected admin inspection. */
  const adminCookie = wp([
    'eval',
    'echo "wordpress_" . COOKIEHASH . "=" . wp_generate_auth_cookie( 1, time() + 3600, "auth" ) . "; wordpress_logged_in_" . COOKIEHASH . "=" . wp_generate_auth_cookie( 1, time() + 3600, "logged_in" );',
  ]).stdout;
  assert.ok(adminCookie.includes('='), 'an admin auth cookie must be generated');
  const canManage = wp(['eval', 'echo user_can( 1, "manage_freeplast_quotes" ) ? "yes" : "no";']).stdout;
  assert.equal(canManage, 'yes', 'administrators receive the dedicated sales capability (migration 6)');

  const list = await get('/wp-admin/admin.php?page=fp-quotes', MOBILE_UA, { cookie: adminCookie });
  assert.equal(list.status, 200, 'the Cotizaciones list must be reachable for the capability holder');
  assertContains(list.body, reference, 'the list shows the first reference');
  assertContains(list.body, reference2, 'the list shows the second reference');
  assertContains(list.body, 'Agrícola ACME SpA', 'the list shows the company');
  assertContains(list.body, 'maria@acme.cl', 'the list shows the email');

  const firstId = quoteIds().split(/\s+/)[0];
  const firstTitle = wp(['post', 'get', firstId, '--field=post_title']).stdout;
  const detail = await get(`/wp-admin/admin.php?page=fp-quote&p=${firstId}`, MOBILE_UA, { cookie: adminCookie });
  assert.equal(detail.status, 200, 'the detail view must be reachable for the capability holder');
  assertContains(detail.body, firstTitle, 'the detail shows the reference');
  assertContains(detail.body, 'María González', 'the detail shows the Submitted Details');
  assertContains(detail.body, '+56 9 6844 4265', 'the detail shows the entered telephone');
  assertContains(detail.body, 'Caja Cosechera 3/4', 'the detail shows the immutable snapshot title');
  assertContains(detail.body, 'snapshot inmutable', 'the detail marks the immutable snapshot');

  // Without the capability the surface is denied.
  const subUserId = wp([
    'eval',
    'echo (int) wp_insert_user( array( "user_login" => "fp_sinventas", "user_pass" => wp_generate_password( 24 ), "user_email" => "sinventas@example.test" ) );',
  ]).stdout;
  assert.ok(Number(subUserId) > 0, 'a capability-less user must be created for the denial check');
  const subCookie = wp([
    'eval',
    `echo "wordpress_" . COOKIEHASH . "=" . wp_generate_auth_cookie( ${subUserId}, time() + 3600, "auth" ) . "; wordpress_logged_in_" . COOKIEHASH . "=" . wp_generate_auth_cookie( ${subUserId}, time() + 3600, "logged_in" );`,
  ]).stdout;
  const deniedList = await get('/wp-admin/admin.php?page=fp-quotes', MOBILE_UA, { cookie: subCookie });
  assert.notEqual(deniedList.status, 200, 'the Cotizaciones surface must deny users without the capability');
  assertAbsent(deniedList.body, 'Agrícola ACME SpA', 'no request data may reach a denied user');
  const deniedDetail = await get(`/wp-admin/admin.php?page=fp-quote&p=${firstId}`, MOBILE_UA, { cookie: subCookie });
  assert.notEqual(deniedDetail.status, 200, 'the detail surface must deny users without the capability');
  assertAbsent(deniedDetail.body, 'María González', 'no customer data may reach a denied user');

  /* 15.11 — Nothing but the request was created: no customer account, no
     price, no order. */
  assert.equal(usersTotal(), usersBefore + 1, 'only the test denial user was created — submissions create no account');
  assert.ok(
    !JSON.stringify(record.items).includes('price') && !JSON.stringify(record.items).includes('precio'),
    'no price is ever stored'
  );

  section('Quote Request submission (issue #8)', [
    '/cotizacion/ is the sole submission surface: the form renders only below live basket lines; products/options/quantities come from the authenticated server basket, never from request fields',
    'Nombre, Teléfono, Email, Nombre Empresa, Rut Empresa, Giro and Con Despacho (Sí/No) required; Mensaje optional/bounded; manual Dirección de despacho only with dispatch — all validated server-side',
    'Invalid submissions retain fields and basket, return a focused linked summary (role=alert) plus inline aria-linked errors; nonce/session/token guard failures persist nothing',
    'A valid submission persists exactly one private fp_quote record (non-public, no REST) with Submitted Details and immutable per-line snapshots (source id, option, quantity, rules used, specs, canonical URL) — no price fields',
    'The confirmation shows the permanent unique FP-YYYY-NNNNNN reference; the basket clears only after durable persistence; a persistence failure shows no success and retains basket + values',
    'Refresh/back/retry with the same idempotency token returns the existing confirmation and never duplicates the record; a second basket receives a fresh token and its own reference',
    'Archived Product lines drop out of the submission (live-catalog resolution); the telephone stores a normalized copy alongside the entered text',
    'A minimal capability-protected admin detail lists and inspects the records (reference, company, email, dispatch, snapshot); users without manage_freeplast_quotes are denied; no customer account is created',
  ]);
});

/* ─── 16. Sales administration workflow (issue #9) ───────────────── */

test('sales operates Quote Requests through the restricted Cotizaciones workflow', { timeout: 240_000 }, async () => {
  const authCookie = (userId) =>
    wp([
      'eval',
      `echo "wordpress_" . COOKIEHASH . "=" . wp_generate_auth_cookie( ${userId}, time() + 3600, "auth" ) . "; wordpress_logged_in_" . COOKIEHASH . "=" . wp_generate_auth_cookie( ${userId}, time() + 3600, "logged_in" );`,
    ]).stdout;
  const adminCookie = authCookie(1);
  const noticeOf = (res) => new URL(res.headers.location || '', SITE_URL).searchParams.get('fpqa_notice');
  const quoteMeta = (id, key) => wp(['eval', `echo (string) get_post_meta( ${id}, "${key}", true );`]).stdout;
  const quoteJson = (id, key) => JSON.parse(wp(['eval', `echo (string) get_post_meta( ${id}, "${key}", true );`]).stdout || 'null');
  const ids = wp(['post', 'list', '--post_type=fp_quote', '--post_status=private', '--orderby=ID', '--order=ASC', '--format=ids'])
    .stdout.split(/\s+/)
    .filter(Boolean)
    .map(Number);
  assert.ok(ids.length >= 4, 'the sales workflow section needs the requests persisted by the issue #8 section');
  const [r1, r2, r3, r4] = ids;
  const refs = ids.map((id) => quoteMeta(id, '_fpq_reference'));

  /** The nonce field of the admin form posting one operation. */
  const adminNonce = (html, action) => {
    for (const form of html.split('<form ')) {
      if (form.includes(`name="action" value="${action}"`)) {
        return form.match(/name="fp_[a-z]+_nonce" value="([a-f0-9]{10})"/)?.[1];
      }
    }
    return undefined;
  };
  const detailOf = async (id, cookie = adminCookie) => {
    const res = await get(`/wp-admin/admin.php?page=fp-quote&p=${id}`, MOBILE_UA, { cookie });
    assert.equal(res.status, 200, 'the detail view must be reachable for the capability holder');
    return res.body;
  };
  const listRefs = async (query = '', cookie = adminCookie) => {
    const res = await get(`/wp-admin/admin.php?page=fp-quotes${query}`, MOBILE_UA, { cookie });
    assert.equal(res.status, 200, 'the Cotizaciones list must be reachable for the capability holder');
    const tbody = res.body.slice(res.body.indexOf('<tbody>'));
    return [...new Set([...tbody.matchAll(/FP-\d{4}-\d{6}/g)].map((m) => m[0]))];
  };
  const between = (html, from, to) => html.slice(html.indexOf(from), html.indexOf(to, html.indexOf(from)));

  /* 16.1 — The Ventas Freeplast role: least privilege. */
  assert.equal(wp(['eval', 'echo get_role( "ventas_freeplast" ) ? "exists" : "missing";']).stdout, 'exists', 'migration 7 must create the Ventas Freeplast role');
  const ventasId = Number(
    wp([
      'eval',
      '$u = get_user_by( "login", "fp_ventas" );' +
        'if ( ! $u ) { $id = wp_insert_user( array( "user_login" => "fp_ventas", "user_pass" => wp_generate_password( 24 ), "user_email" => "ventas@example.test", "role" => "ventas_freeplast" ) ); }' +
        'else { $id = $u->ID; } echo $id;',
    ]).stdout || 0
  );
  assert.ok(ventasId > 0, 'a Ventas Freeplast user must exist');
  const ventasCaps = JSON.parse(
    wp([
      'eval',
      `$u = get_userdata( ${ventasId} ); echo wp_json_encode( array( "read" => $u->has_cap( "read" ), "quotes" => $u->has_cap( "manage_freeplast_quotes" ), "manage_options" => $u->has_cap( "manage_options" ), "edit_posts" => $u->has_cap( "edit_posts" ), "edit_pages" => $u->has_cap( "edit_pages" ), "edit_theme_options" => $u->has_cap( "edit_theme_options" ), "list_users" => $u->has_cap( "list_users" ), "activate_plugins" => $u->has_cap( "activate_plugins" ), "edit_fp_product" => $u->has_cap( "edit_fp_product" ) ) );`,
    ]).stdout || '{}'
  );
  assert.equal(ventasCaps.read, true, 'the role must be able to reach wp-admin at all (read)');
  assert.equal(ventasCaps.quotes, true, 'the role must carry the dedicated sales capability');
  for (const [cap, allowed] of Object.entries(ventasCaps)) {
    if (cap !== 'read' && cap !== 'quotes') {
      assert.equal(allowed, false, `the Ventas Freeplast role must not gain unrelated administration (${cap})`);
    }
  }
  const ventasCookie = authCookie(ventasId);
  assert.equal((await get('/wp-admin/admin.php?page=fp-quotes', MOBILE_UA, { cookie: ventasCookie })).status, 200, 'Ventas Freeplast reaches Cotizaciones');
  for (const adminPath of ['/wp-admin/users.php', '/wp-admin/plugins.php', '/wp-admin/edit.php', '/wp-admin/themes.php']) {
    const res = await get(adminPath, MOBILE_UA, { cookie: ventasCookie });
    assert.notEqual(res.status, 200, `${adminPath} must stay denied for Ventas Freeplast`);
  }
  assert.equal(wp(['eval', 'echo user_can( 1, "manage_freeplast_quotes" ) ? "yes" : "no";']).stdout, 'yes', 'administrators keep the same dedicated capability');

  /* 16.2 — The detail separates Submitted from Current Contact Details. */
  let detail = await detailOf(r2);
  assertContains(detail, 'Datos enviados', 'the detail must show the Submitted Details');
  assertContains(detail, 'Datos de contacto actuales', 'the detail must show the editable Current Contact Details');
  const submittedBlock = between(detail, 'Datos enviados', 'Datos de contacto actuales');
  assertContains(submittedBlock, 'Agrícola ACME SpA', 'Submitted Details keep the submitted company');
  assertContains(submittedBlock, 'maria@acme.cl', 'Submitted Details keep the submitted email');
  assertContains(detail, 'name="action" value="fp_quote_update_contact"', 'the correction form posts to the guarded admin operation');

  /* 16.3 — Correcting Current Contact Details. */
  const contactNonce = adminNonce(detail, 'fp_quote_update_contact');
  assert.ok(contactNonce, 'the correction form carries its per-object nonce');
  const contactPost = (over = {}, cookie = adminCookie, nonce = contactNonce) =>
    postForm(
      {
        action: 'fp_quote_update_contact',
        p: String(r2),
        fp_nombre: 'María González',
        fp_telefono: '+56 9 6844 4265',
        fp_email: 'maria@acme.cl',
        fp_empresa: 'Agrícola ACME SpA',
        fp_rut: '76.335.888-6',
        fp_giro: 'Comercialización de productos plásticos',
        fp_direccion: 'Camino El Arrayán 52, San Francisco de Mostazal',
        fp_contact_nonce: nonce,
        ...over,
      },
      { cookie }
    );
  // Invalid correction: no mutation, entered values retained.
  const badContact = await contactPost({ fp_email: 'no-es-un-email', fp_empresa: 'Bodegas del Sur SpA' });
  assert.equal(badContact.status, 302, 'the correction answers POST-redirect-GET');
  assert.equal(noticeOf(badContact), 'contact_invalid', 'a malformed correction must be rejected');
  assert.equal(quoteMeta(r2, '_fpq_email'), 'maria@acme.cl', 'an invalid correction mutates nothing');
  const invalidBack = await detailOf(r2);
  assertContains(invalidBack, 'value="Bodegas del Sur SpA"', 'the attempted correction value is retained');
  assertContains(invalidBack, 'Ingresa un email válido', 'the inline error explains the problem');
  // Valid correction of empresa + email + teléfono only.
  const fixed = await contactPost({ fp_email: 'compras@bodegasdelsur.cl', fp_empresa: 'Bodegas del Sur SpA', fp_telefono: '+56 2 2345 6789' });
  assert.equal(fixed.status, 302);
  assert.equal(noticeOf(fixed), 'contact_updated');
  detail = await detailOf(r2);
  const currentBlock = between(detail, 'Datos de contacto actuales', 'Productos solicitados');
  assertContains(currentBlock, 'Bodegas del Sur SpA', 'the corrected company shows as current');
  assertContains(currentBlock, 'compras@bodegasdelsur.cl', 'the corrected email shows as current');
  assertContains(currentBlock, '+56 2 2345 6789', 'the corrected telephone shows as current');
  const submittedAfter = between(detail, 'Datos enviados', 'Datos de contacto actuales');
  assertContains(submittedAfter, 'Agrícola ACME SpA', 'the correction never overwrites Submitted Details');
  assertContains(submittedAfter, 'maria@acme.cl', 'the submitted email stays visible');
  const submittedJson = quoteJson(r2, '_fpq_customer');
  assert.equal(submittedJson.empresa, 'Agrícola ACME SpA', 'the stored Submitted Details stay immutable');
  assert.equal(submittedJson.email, 'maria@acme.cl');
  const currentJson = quoteJson(r2, '_fpq_current');
  assert.equal(currentJson.empresa, 'Bodegas del Sur SpA', 'the current contact copy is stored separately');
  assert.equal(currentJson.telefono_normalizado, '+56223456789', 'the corrected telephone gets a normalized copy');
  // The change history records fields/time/staff without PII values.
  const historyR2 = quoteJson(r2, '_fpq_history') || [];
  const contactEvent = [...historyR2].reverse().find((e) => e.type === 'contact');
  assert.ok(contactEvent, 'a correction appends a history event');
  assert.equal(contactEvent.staff, 1, 'the event records the staff identity');
  assert.ok(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(contactEvent.time), 'the event records a timestamp');
  assert.ok(contactEvent.fields.includes('email') && contactEvent.fields.includes('empresa'), 'the event names the corrected fields');
  const historyRaw = JSON.stringify(historyR2);
  assert.ok(!historyRaw.includes('compras@bodegasdelsur.cl') && !historyRaw.includes('Bodegas del Sur'), 'history events never copy PII values');

  /* 16.4 — Internal Sales Notes. */
  detail = await detailOf(r1);
  const noteNonce = adminNonce(detail, 'fp_quote_add_note');
  assert.ok(noteNonce, 'the note form carries its per-object nonce');
  const notePost = (text, cookie = adminCookie, nonce = noteNonce) =>
    postForm({ action: 'fp_quote_add_note', p: String(r1), fp_nota: text, fp_note_nonce: nonce }, { cookie });
  assert.equal(noticeOf(await notePost('')), 'note_invalid', 'an empty note is rejected');
  assert.equal(noticeOf(await notePost('Cliente prefiere contacto por WhatsApp en la mañana.')), 'note_added');
  // The Ventas Freeplast user appends a second note through the same surface.
  const ventasDetail = await detailOf(r1, ventasCookie);
  const ventasNoteNonce = adminNonce(ventasDetail, 'fp_quote_add_note');
  assert.ok(ventasNoteNonce, 'Ventas Freeplast renders the note form');
  assert.equal(noticeOf(await notePost('Se pidió muestra de la Caja Tomatera.', ventasCookie, ventasNoteNonce)), 'note_added');
  const notes = quoteJson(r1, '_fpq_notes') || [];
  assert.equal(notes.length, 2, 'both notes append');
  assert.equal(notes[0].staff, 1, 'the first note records the administrator');
  assert.equal(notes[1].staff, ventasId, 'the second note records the Ventas Freeplast staff');
  assert.ok(notes[0].time && notes[1].time, 'notes carry timestamps');
  detail = await detailOf(r1);
  assertContains(detail, 'Cliente prefiere contacto por WhatsApp', 'the note renders in the detail');
  assertContains(detail, 'Se pidió muestra de la Caja Tomatera', 'the second note renders in order');
  // Sales Notes never reach a public page (records are non-public; search does not leak them).
  const publicSearch = await get(`/?s=${encodeURIComponent('WhatsApp en la mañana')}`, MOBILE_UA);
  assertAbsent(publicSearch.body, 'Cliente prefiere contacto', 'no Sales Note may reach a public page');

  /* 16.5 — Request Status transitions with skips and terminal handling. */
  const statusOf = (id) => quoteMeta(id, '_fpq_status');
  const statusNonceOf = async (id, cookie = adminCookie) => adminNonce(await detailOf(id, cookie), 'fp_quote_set_status');
  const reopenNonceOf = async (id) => adminNonce(await detailOf(id), 'fp_quote_reopen');
  const setStatus = (id, target, nonce, cookie = adminCookie) =>
    postForm({ action: 'fp_quote_set_status', p: String(id), fp_status: target, fp_status_nonce: nonce }, { cookie });
  const reopen = (id, nonce, cookie = adminCookie) =>
    postForm({ action: 'fp_quote_reopen', p: String(id), fp_reopen_nonce: nonce }, { cookie });

  assert.equal(statusOf(r1), 'new', 'a fresh request starts new');
  const sNonce = await statusNonceOf(r1);
  assert.equal(noticeOf(await setStatus(r1, 'contacted', sNonce)), 'status_updated');
  assert.equal(statusOf(r1), 'contacted');
  assert.equal(noticeOf(await setStatus(r1, 'quoted', sNonce)), 'status_updated', 'forward movement is allowed');
  assert.equal(statusOf(r1), 'quoted');
  assert.equal(noticeOf(await setStatus(r1, 'won', sNonce)), 'status_updated');
  assert.equal(statusOf(r1), 'won', 'won is reachable');
  assert.equal(noticeOf(await setStatus(r1, 'lost', sNonce)), 'bad_transition', 'won is terminal — no further direct transition');
  assert.equal(statusOf(r1), 'won');
  assert.equal(noticeOf(await setStatus(r1, 'contacted', sNonce)), 'bad_transition', 'reopening never happens through the status operation');
  const reopenNonce = await reopenNonceOf(r1);
  assert.equal(noticeOf(await reopen(r1, reopenNonce)), 'reopened', 'an explicit reopen returns a terminal request to contacted');
  assert.equal(statusOf(r1), 'contacted');
  assert.equal(noticeOf(await setStatus(r1, 'cancelled', sNonce)), 'status_updated', 'contacted may be cancelled');
  assert.equal(statusOf(r1), 'cancelled');
  assert.equal(noticeOf(await setStatus(r1, 'quoted', sNonce)), 'bad_transition', 'cancelled is terminal');
  assert.equal(noticeOf(await reopen(r1, reopenNonce)), 'reopened');
  assert.equal(statusOf(r1), 'contacted', 'the explicit reopen returns cancelled to contacted too');
  // Skips: intermediate steps may be omitted entirely.
  assert.equal(noticeOf(await setStatus(r3, 'won', await statusNonceOf(r3))), 'status_updated', 'new may skip straight to won');
  assert.equal(statusOf(r3), 'won');
  assert.equal(noticeOf(await setStatus(r4, 'cancelled', await statusNonceOf(r4))), 'status_updated', 'new may be cancelled directly');
  assert.equal(statusOf(r4), 'cancelled');
  assert.equal(noticeOf(await setStatus(r2, 'new', await statusNonceOf(r2))), 'bad_transition', 'a same-status no-op is not a transition');
  // Every transition records staff identity and time (without PII).
  const historyR3 = quoteJson(r3, '_fpq_history') || [];
  const statusEvent = historyR3.find((e) => e.type === 'status');
  assert.ok(statusEvent, 'a transition appends a history event');
  assert.equal(statusEvent.from, 'new');
  assert.equal(statusEvent.to, 'won');
  assert.equal(statusEvent.staff, 1, 'the event records the staff identity');
  assert.ok(statusEvent.time, 'the event records a timestamp');
  // A bad nonce changes nothing.
  assert.equal(noticeOf(await setStatus(r2, 'contacted', 'deadbeefdead')), 'nonce', 'a bad nonce must be rejected');
  assert.equal(statusOf(r2), 'new');

  /* 16.6 — Unauthorized users cannot list, view, correct, note or transition. */
  const subUserId = Number(wp(['eval', 'echo (int) get_user_by( "login", "fp_sinventas" )->ID;']).stdout || 0);
  assert.ok(subUserId > 0, 'the capability-less user from the issue #8 section must exist');
  const subCookie = authCookie(subUserId);
  const before = JSON.stringify([statusOf(r2), quoteJson(r2, '_fpq_notes'), quoteJson(r2, '_fpq_history'), quoteJson(r2, '_fpq_current')]);
  for (const fields of [
    { action: 'fp_quote_set_status', p: String(r2), fp_status: 'won', fp_status_nonce: 'x' },
    { action: 'fp_quote_reopen', p: String(r2), fp_reopen_nonce: 'x' },
    { action: 'fp_quote_add_note', p: String(r2), fp_nota: 'nota infiltrada', fp_note_nonce: 'x' },
    {
      action: 'fp_quote_update_contact',
      p: String(r2),
      fp_nombre: 'X',
      fp_telefono: '+56 9 0000 0000',
      fp_email: 'infiltrado@example.test',
      fp_empresa: 'X',
      fp_rut: '1',
      fp_giro: 'X',
      fp_contact_nonce: 'x',
    },
  ]) {
    const res = await postForm(fields, { cookie: subCookie });
    assert.notEqual(res.status, 200, `${fields.action} must deny users without the capability`);
  }
  assert.notEqual((await get('/wp-admin/admin.php?page=fp-quotes', MOBILE_UA, { cookie: subCookie })).status, 200, 'the list stays denied');
  assert.notEqual((await get(`/wp-admin/admin.php?page=fp-quote&p=${r2}`, MOBILE_UA, { cookie: subCookie })).status, 200, 'the detail stays denied');
  assert.equal(
    JSON.stringify([statusOf(r2), quoteJson(r2, '_fpq_notes'), quoteJson(r2, '_fpq_history'), quoteJson(r2, '_fpq_current')]),
    before,
    'unauthorized POSTs mutate nothing'
  );

  /* 16.7 — The list sorts and searches. */
  assert.deepEqual(await listRefs(), [refs[3], refs[2], refs[1], refs[0]], 'newest first by default');
  assert.deepEqual(await listRefs('&orderby=referencia&order=asc'), refs, 'sortable by Request Reference');
  assert.deepEqual(await listRefs('&orderby=referencia&order=desc'), [refs[3], refs[2], refs[1], refs[0]]);
  assert.deepEqual(await listRefs('&orderby=creada&order=asc'), refs, 'sortable by created date');
  assert.deepEqual(await listRefs('&orderby=creada&order=desc'), [refs[3], refs[2], refs[1], refs[0]]);
  assert.deepEqual(await listRefs('&orderby=empresa&order=asc'), [refs[0], refs[2], refs[3], refs[1]], 'sortable by company (current details)');
  assert.deepEqual(await listRefs('&orderby=empresa&order=desc'), [refs[1], refs[3], refs[2], refs[0]]);
  assert.deepEqual(await listRefs('&orderby=email&order=asc'), [refs[1], refs[0], refs[2], refs[3]], 'sortable by email');
  assert.deepEqual(await listRefs('&orderby=email&order=desc'), [refs[3], refs[2], refs[0], refs[1]]);
  assert.deepEqual(await listRefs('&orderby=estado&order=asc'), [refs[3], refs[0], refs[1], refs[2]], 'sortable by Request Status');
  assert.deepEqual(await listRefs('&orderby=estado&order=desc'), [refs[2], refs[1], refs[0], refs[3]]);
  assert.deepEqual(await listRefs(`&s=${encodeURIComponent(refs[0])}`), [refs[0]], 'search by Request Reference');
  assert.deepEqual(await listRefs('&s=Bodegas'), [refs[1]], 'search by company');
  assert.deepEqual(await listRefs('&s=compras%40bodegasdelsur.cl'), [refs[1]], 'search by email');
  assert.deepEqual(await listRefs('&s=maria%40acme.cl'), [refs[3], refs[2], refs[0]], 'search matches the remaining records (default newest-first order)');
  const none = await get('/wp-admin/admin.php?page=fp-quotes&s=NoExisteSA', MOBILE_UA, { cookie: adminCookie });
  assertContains(none.body, 'Sin solicitudes', 'a fruitless search renders an explicit empty state');
  assert.deepEqual(await listRefs('&estado=won'), [refs[2]], 'filter by Request Status: won');
  assert.deepEqual(await listRefs('&estado=new'), [refs[1]], 'filter by Request Status: new');
  assert.deepEqual(await listRefs('&estado=contacted'), [refs[0]], 'filter by Request Status: contacted');

  /* 16.8 — The sales role operates transitions too. */
  const ventasStatusNonce = await statusNonceOf(r2, ventasCookie);
  assert.equal(noticeOf(await setStatus(r2, 'contacted', ventasStatusNonce, ventasCookie)), 'status_updated');
  assert.equal(statusOf(r2), 'contacted');
  const ventasEvent = [...(quoteJson(r2, '_fpq_history') || [])].reverse().find((e) => e.type === 'status');
  assert.equal(ventasEvent.staff, ventasId, 'the Ventas Freeplast staff identity is recorded');

  /* 16.9 — Least-privilege hygiene: no Products in editor menus, no bulk export. */
  assert.equal(wp(['eval', 'echo get_post_type_object( "fp_product" )->show_ui ? "shown" : "hidden";']).stdout, 'hidden', 'fp_product must stay absent from editor menus');
  const listPage = await get('/wp-admin/admin.php?page=fp-quotes', MOBILE_UA, { cookie: adminCookie });
  const ourSurface = listPage.body.slice(listPage.body.indexOf('<div class="wrap"'), listPage.body.indexOf('</table>'));
  assert.ok(ourSurface.length > 0, 'the list surface must render');
  assert.ok(!/exportar|\.csv|export/i.test(ourSurface), 'no bulk CSV export may be introduced in the Cotizaciones surface');

  section('Sales administration workflow (issue #9)', [
    'Ventas Freeplast role: read + manage_freeplast_quotes only — reaches Cotizaciones while staying out of unrelated site administration; administrators keep the capability',
    'The list sorts by reference, company, email, created date and Request Status, searches by reference/company/email and filters by status, with an explicit empty state',
    'The detail separates immutable Submitted Details from editable Current Contact Details; a correction updates the current copy and the list/search columns while the submitted record stays byte-identical',
    'Corrections append a history event naming the changed fields, time and staff identity — never the PII values',
    'Timestamped internal Sales Notes append with author identity and never reach any public page',
    'Status moves new → contacted → quoted → won/lost with permitted skips; new/contacted/quoted may be cancelled; terminal states leave only through the explicit reopen action (→ contacted)',
    'Every state change validates nonce + capability and records staff identity/time; capability-less users cannot list, view, correct, note or transition anything',
    'Products stay absent from editor menus and no bulk CSV export exists',
  ]);
});

/* ─── 17. Write VERIFICATION.md and clean up ──────────────────────────── */

test('record mechanical proof in wordpress/VERIFICATION.md', () => {
  const lines = [
    `# Mechanical verification — Freeplast WordPress shell + catalog + discovery + quote basket + quote request + v6 content + sales workflow (issues #2–#9, #12)`,
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
    'Color Caja Universal choosers require one currently supported color (cards + product page); missing/unsupported/foreign options are rejected without mutation',
    'Different options produce separate lines; the same product+option merges; header count, mini basket and full view agree after every mutation and refresh',
    'Lines update and remove through nonce-guarded operations with JavaScript enabled (JSON state) or disabled (POST-redirect-GET)',
    'Malformed update/remove submissions (quantity, product, option, line, nonce, archived products) are rejected without mutation',
    'Confirmed minimum/step rules are enforced when present; unknown rules accept any positive whole unit and make no minimum claim',
    'A logged-in staff browser still uses the anonymous cookie basket; no user linking or merge exists',
    'Sessions expire 30 days after last activity: cookie cleared, empty state routes back to Tienda, daily sweep collects expired rows',
    'Frozen v6 design contract (wordpress/design/): tokens + SHA-256-frozen approved prototypes verified; theme implements the v6 palette, Manrope and blue controls; v5 rules removed from the governing docs',
    'Logo/Inicio, Nosotros, Tienda, Cotización count/CTA and Contacto navigate to their approved destinations',
    'Home keeps the concise v6 composition: Nosotros + contact sections, eight Featured Products, Quote Basket summary/CTA — never a second submission form',
    'Nosotros renders editable mission/vision page content; Contacto renders phone, email, WhatsApp, warehouse/map, hours and exactly one CTA into Cotización (no Inquiry record)',
    'Política de privacidad provides the basic collection/submission disclosure without an acknowledgement checkbox',
    'Search and 404 keep usable navigation and empty states; the header count and mini basket stay accurate on every route',
    'All theme templates/parts parse without block recovery; the theme contains no Catalog or Quote Request business logic',
    'Migration 5 upgrades the legacy Contacto/privacy placeholders byte-safely; human edits survive',
    'The Quote Request form renders only below live basket lines on the sole submission surface /cotizacion/; products/options/quantities come from the authenticated server basket, never from request fields',
    'Nombre, Teléfono, Email, Nombre Empresa, Rut Empresa, Giro and Con Despacho (Sí/No) are required, Mensaje optional/bounded, the manual Dirección de despacho only with dispatch — all validated server-side',
    'Invalid submissions retain fields and basket with a focused linked error summary plus inline aria-linked errors; nonce/session/token guard failures persist nothing',
    'A valid submission persists exactly one private fp_quote record (non-public, no REST) with Submitted Details and immutable per-line snapshots (source id, option, quantity, rules used, specs, canonical URL) — no price fields',
    'The confirmation shows the permanent unique FP-YYYY-NNNNNN Request Reference; the basket clears only after durable persistence; a persistence failure shows no success and retains basket + values',
    'Refresh/back/retry with the same idempotency token never duplicates the record; a second basket receives a fresh token and its own reference; archived Product lines drop out',
    'A minimal capability-protected admin detail lists and inspects the records; users without manage_freeplast_quotes are denied; no customer account is created',
    'Ventas Freeplast role: read + manage_freeplast_quotes only — reaches Cotizaciones, stays out of unrelated site administration; administrators keep the capability',
    'The Cotizaciones list sorts by reference/company/email/created date/Request Status and searches by reference/company/email with a status filter and an explicit empty state',
    'The detail separates immutable Submitted Details from editable Current Contact Details; corrections update the current copy and the list/search columns, never the submitted record',
    'Corrections append a history event naming the changed fields, time and staff identity without PII values',
    'Timestamped internal Sales Notes append with author identity and never reach any public page',
    'Request Status supports new/contacted/quoted/won/lost/cancelled with permitted skips; terminal states reopen explicitly back to contacted',
    'Every state change validates nonce and capability and records staff identity/time; unauthorized users cannot list, view, correct, note or transition anything',
    'Products stay absent from editor menus; no bulk CSV export exists',
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
    `- The Quote Basket is an anonymous cookie-backed server session (issues #6–#7): the cookie never carries basket data, only its sha256 hash is persisted, and every mutation (add, update, remove) revalidates nonce, session, Product lifecycle/visibility, option identity and whole-unit quantity. The Color Caja Universal configurations require one supported color; the submission form arrives with issue #8 on the same /cotizacion/ surface.`,
    `- The Quote Request submission (issue #8) is verified through served documents and the persisted fp_quote records: the manual Dirección de despacho is the this-slice address path (the Google-assisted confirmation arrives with issue #11) and the acknowledgement/notification emails arrive with issue #10. Human visual approval remains Gate 3.`,
    `- The sales administration workflow (issue #9) is verified through served wp-admin documents and the persisted fp_quote metadata: corrections, notes, status history and staff identity live on the records; notifications (issue #10) and Dispatch Distance retry (issue #11) arrive with their slices. Human visual approval remains Gate 3.`,
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
