#!/usr/bin/env node
/**
 * Freeplast WordPress shell — automated acceptance checks (issues #2–#17, #19–#20, #22).
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
 * verifies the acceptance criteria of issues #2 through #17, of the
 * issue #19 basket-cookie scheme fix, of the issue #20 theme markup
 * hardening and of the issue #22 synchronization rollback discipline
 * (including
 *   the issue #9 sales workflow and the issue #10 notifications):
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
 *      HttpOnly/SameSite=Lax cookie (Secure follows the request scheme,
 *      issue #19; never basket data, only its sha256 hash stored
 *      server-side), the header counts distinct lines
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
 *  16. The complete journey is hardened (issue #13): the one- and
 *      multi-Product journey passes with JavaScript enabled and disabled;
 *      keyboard operation has logical order, visible focus, native
 *      disclosures and the focused linked error summary; responsive
 *      behavior is mechanically observed at 375, 412, 768, 1024 and
 *      1440 px; reduced-motion preferences disable nonessential motion
 *      and controls meet target-size/contrast expectations; the honeypot,
 *      minimum completion time, idempotency and bounded non-raw-PII
 *      throttling reject abuse without blocking ordinary retries; every
 *      public/admin mutation rejects invalid nonce, session, capability,
 *      Product, option, quantity or record without partial mutation;
 *      catalog/session/database/mail/Google failures never produce false
 *      request success or lose recoverable customer state; activation and
 *      versioned migrations fail safely with a clear public maintenance
 *      state that self-heals; a stock block theme still exposes functional
 *      minimal Catalog, basket, request and admin behavior; theme
 *      deactivation and plugin deactivation/uninstall preserve Products,
 *      Quote Requests, histories and sessions; no WooCommerce, customer
 *      accounts, prices, checkout, automatic shipping price or formal
 *      Quotation behavior appears; and PHP syntax + coding-standard scans
 *      pass alongside the integrated behavior tests at the real WordPress
 *      seam.
 *  17. The isolated staging deployment artifacts (issue #14) are
 *      reviewable, collision-checked and secret-safe before any server
 *      mutation: a dedicated WordPress + MariaDB Compose project with
 *      private persistent volumes and a loopback-only origin, an
 *      approved-hostname-only Nginx vhost with owner/client Basic Auth,
 *      noindex, TLS through the server convention and nginx -t before
 *      reload, a read-only preflight that fails on every resource
 *      collision, a deploy script that generates secrets on the server
 *      (never in the repository or command output) and gates the catalog
 *      synchronization on a zero-change dry run, an HTTPS verification
 *      walk, a backup with restore rehearsal, and a rollback bounded to
 *      the new resources only (DEPLOYMENT.md records the operator
 *      runbook; the on-server execution is the operator step).
 *  18. The verification and operations handoff (issue #15) packages the
 *      build for independent operation and human review: HANDOFF.md
 *      records every verification dimension (with the on-server
 *      infrastructure/Nginx/browser-console steps named as operator/
 *      reviewer actions, never as done), the 17→17 catalog
 *      reconciliation with every provisional client fact, the Quote
 *      Request acceptance matrix, mechanical-only accessibility
 *      observations, reproducible operator procedures, Gate 3 review
 *      URLs, pending owner/client actions and the separately-scoped
 *      release work; the shipped theme/plugin ZIPs are rebuilt
 *      deterministically into dist/ with SHA-256 checksums verified by
 *      unzip -t and sha256sum -c, and the handoff never claims visual
 *      validation.
 *  19. The staging infrastructure constants (issue #16) are single-sourced:
 *      the hostname, install root and loopback port are declared exactly
 *      once in infra/staging.sh, all five shell scripts source that shared
 *      definition instead of declaring literals, the Nginx vhost template
 *      carries only __-placeholders that deploy.sh renders from the shared
 *      values, and the Compose port requires the deploy-written .env value
 *      with no fallback literal. Rendering the template with the shared
 *      constants reproduces the deployed vhost byte-for-byte.
 *  20. One JSON codec serves every stored-meta read/write (issue #17):
 *      the plugin-level Freeplast_CQ_Codec pair replaces the three
 *      identical encoders, two identical decoders and the Delivery
 *      Address inline decodes; the stored form stays byte-for-byte
 *      identical (unescaped slashes/unicode), proven by the exact byte
 *      form, round-trip identity on the persisted staging data and a
 *      zero-change catalog dry run after the swap.
 *  21. The theme markup is position-independent and block-safe (issue
 *      #20): no template or part hardcodes an absolute wp-content theme
 *      path — the header logo, footer brand mark and Home hero image
 *      resolve through get_theme_file_uri() at render time (the
 *      {{FREEPLAST_THEME_URL}} token the theme resolves), the same theme
 *      sources are proven to render subdirectory-correct asset URLs when
 *      the disposable installation boots under a /subdir site URL, and
 *      every free-form (wp:html) block is balanced on its own (the header
 *      wrap and island header are group block boundaries with the basket
 *      button between them, so a Site Editor edit cannot split the v6
 *      chrome across blocks).
 *  22. A failed synchronization phase never leaves the run's media behind
 *      (issue #22): a post-phase failure rolls the attachments the run
 *      imported back together with the created posts (zero orphaned
 *      attachments, no leftover uploads file), a mid-import media failure
 *      removes only the run's own import, and attachments reused by
 *      checksum are never deleted on any failure path — recovery
 *      afterwards applies the same source cleanly and still reuses the
 *      checksum-matched media.
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

/**
 * The basket session-cookie attribute contract for one request scheme
 * (issue #19): always HttpOnly and SameSite=Lax, while Secure is set
 * exactly when the request presented TLS — a Secure cookie answered over
 * plain HTTP would be dropped by the browser and the basket would
 * silently stop persisting.
 */
function assertSessionCookieAttributes(setCookie, { tls, label }) {
  const attributes = setCookie.toLowerCase(); // PHP writes attributes lowercase
  const expected = tls ? ['secure', 'httponly', 'samesite=lax'] : ['httponly', 'samesite=lax'];
  for (const attribute of expected) {
    assert.ok(attributes.includes(attribute), `the ${label} cookie must be ${attribute}`);
  }
  if (!tls) {
    assert.ok(!attributes.includes('secure'), `the ${label} cookie must not be Secure on plain HTTP — the browser would drop it and the basket would silently break`);
  }
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

/**
 * Simulate the plausible human pace after a form render (issue #13): the
 * server records each form instance's render time with its idempotency
 * token, and a submission faster than the minimum completion time is
 * rejected. The guard itself is exercised in real time in the issue #13
 * section; the older sections use this deterministic backdate (the token
 * is opaque, so only its server-side timestamp moves).
 */
function humanPaced(sessionToken, idemToken, seconds = 60) {
  return wp([
    'eval',
    `$k = 'fpcq_reqtok_' . hash( 'sha256', '${sessionToken}' ); $v = get_transient( $k ); if ( is_array( $v ) && isset( $v['started'] ) && $v['token'] === '${idemToken}' ) { $v['started'] = time() - ${Math.floor(seconds)}; set_transient( $k, $v, DAY_IN_SECONDS ); echo 'ok'; } else { echo 'missing'; }`,
  ]).stdout;
}

/**
 * A complete valid set of request-form fields (Con Despacho: No) shared
 * by every issue #13 section that drives a submission. The older sections
 * keep their own dispatch-oriented copies.
 */
const VALID_REQUEST_FIELDS = {
  fp_nombre: 'María González',
  fp_telefono: '+56 9 6844 4265',
  fp_email: 'maria@acme.cl',
  fp_empresa: 'Agrícola ACME SpA',
  fp_rut: '76.335.888-6',
  fp_giro: 'Comercialización de productos plásticos',
  fp_despacho: 'no',
  fp_direccion: '',
  fp_mensaje: '',
};

/** Auth cookies of one user (auth + logged_in), for driving guarded admin surfaces. */
function authCookie(userId = 1) {
  return wp([
    'eval',
    `echo "wordpress_" . COOKIEHASH . "=" . wp_generate_auth_cookie( ${userId}, time() + 3600, "auth" ) . "; wordpress_logged_in_" . COOKIEHASH . "=" . wp_generate_auth_cookie( ${userId}, time() + 3600, "logged_in" );`,
  ]).stdout;
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
  assert.match(
    readFileSync(join(WP_DIR, 'wp-config.php'), 'utf8'),
    /HTTP_X_FORWARDED_PROTO/,
    'the disposable wp-config must honor the forwarded HTTPS scheme exactly as the staging Compose does (the TLS seam behind the issue #19 cookie check)'
  );
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
  assert.equal(wp(['option', 'get', 'fp_db_version']).stdout, String(DB_VERSION), `migration ${DB_VERSION} must be applied after activation (the sales workflow, notification and dispatch-distance slices bump it)`);

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

/* ─── 10b. Post-phase failure rolls back run-imported media (issue #22) ─ */

/** Names of every file under the disposable uploads directory (recursive). */
function uploadsFileNames() {
  const walk = (dir) =>
    readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
      const path = join(dir, entry.name);
      return entry.isDirectory() ? walk(path) : [entry.name];
    });
  try {
    return walk(join(WP_DIR, 'wp-content', 'uploads'));
  } catch {
    return [];
  }
}

test('a failed sync phase rolls back its run-imported media and never deletes checksum-reused attachments (issue #22)', () => {
  // Section 10 hands over the reviewed catalog: 17 records matching the
  // source plus the retained v2 tote attachment (DISTINCT_IMAGES + 1).
  const baseline = attachmentCount();
  assert.equal(baseline, DISTINCT_IMAGES + 1, 'the section-10 handover must carry the reviewed media plus the retained v2 tote attachment');
  const productsBefore = productCount();
  assert.equal(productsBefore, 17);
  const toteAttachment = productMeta('fp-tote', '_thumbnail_id');
  assert.match(toteAttachment, /^\d+$/, 'fp-tote must hold a featured attachment that later runs can reuse by checksum');
  const attachmentAlive = (id) =>
    wp(['post', 'get', id, '--field=post_status']).stdout === 'inherit';

  // Three never-synchronized fixture products exercising every media path in
  // one run: Caja Panalera reuses the existing tote media by checksum
  // (nothing may be imported), Caja Bacaladera and Caja Quitrasaca carry
  // fresh bytes (real imports). File order drives the apply order.
  const checksumOf = (bytes) => 'sha256:' + createHash('sha256').update(bytes).digest('hex');
  const freshBytes = (name, marker) =>
    Buffer.concat([readFileSync(join(WORDPRESS_DIR, 'data', 'media', name)), Buffer.from(`\n// issue-22 fixture: ${marker}\n`)]);
  const tote = PRODUCT_BY_SOURCE_ID.get('fp-tote');
  const fixtureProduct = (sourceId, slug, title, image) => ({
    source_id: sourceId,
    source_url: `https://freeplast.cl/producto/${slug}/`,
    lifecycle: 'active',
    slug,
    legacy_paths: [`/producto/${slug}/`],
    title,
    excerpt: `${title} de PEAD reciclado para cosecha y transporte hortofrutícola.`,
    description: `${title}. Fabricada en polietileno de alta densidad reciclado, apilable y resistente para uso agrícola.`,
    category: 'agricola',
    image,
    specs: {
      material: 'Polietileno de alta densidad reciclado',
      material_short: 'PEAD reciclado',
      dimensions: '600 x 400 x 180 mm',
      weight: '1.250 gramos aprox.',
      use: 'Cosecha y transporte hortofrutícola',
      units_per_pallet: null,
      minimum_quantity: null,
      quantity_step: null,
    },
    options: [],
    related_ids: ['fp-caja-tomatera'],
    featured: false,
    featured_order: null,
    review: {
      description_approved: false,
      notes: [`Fixture product for the issue #22 rollback check (${slug}); never part of the reviewed catalog.`],
    },
  });
  const bacaladeraBytes = freshBytes('fp-frutera.webp', 'fresh bacaladera media');
  const quitrasacaBytes = freshBytes('fp-pollera.webp', 'fresh quitrasaca media');
  const doc = cloneSourceDoc();
  doc.products.push(
    fixtureProduct('fp-caja-panalera', 'caja-panalera', 'Caja Panalera', {
      file: 'media/fp-tote.webp',
      checksum: tote.image.checksum,
      width: tote.image.width,
      height: tote.image.height,
      alt: 'Caja Panalera (fixture issue #22): reutiliza el medio existente por checksum',
      provisional: true,
    }),
    fixtureProduct('fp-caja-bacaladera', 'caja-bacaladera', 'Caja Bacaladera', {
      file: 'media/fp-caja-bacaladera.webp',
      checksum: checksumOf(bacaladeraBytes),
      width: 600,
      height: 600,
      alt: 'Caja Bacaladera (fixture issue #22)',
      provisional: true,
    }),
    fixtureProduct('fp-caja-quitrasaca', 'caja-quitrasaca', 'Caja Quitrasaca', {
      file: 'media/fp-caja-quitrasaca.webp',
      checksum: checksumOf(quitrasacaBytes),
      width: 600,
      height: 600,
      alt: 'Caja Quitrasaca (fixture issue #22)',
      provisional: true,
    }),
  );
  const failingFile = writeFullFixture('issue-22-post-fail.json', doc, [
    ['fp-caja-bacaladera.webp', bacaladeraBytes],
    ['fp-caja-quitrasaca.webp', quitrasacaBytes],
  ]);

  /* Fault injection at the wp_insert_post seam: fail exactly one post type
     (fp_product aborts the post phase after media succeeded; attachment
     aborts the media phase mid-import) without touching the other type. */
  const muDir = join(WP_DIR, 'wp-content', 'mu-plugins');
  mkdirSync(muDir, { recursive: true });
  const installFault = (postType, name) =>
    writeFileSync(
      join(muDir, name),
      `<?php
/* Issue #22 fault injection: short-circuit wp_insert_post for the
   "${postType}" post type only, so the catalog synchronizer fails in
   exactly one phase while every other insert proceeds untouched. */
add_filter( 'wp_insert_post_empty_content', static function ( $maybe_empty, $postarr ) {
	return isset( $postarr['post_type'] ) && '${postType}' === $postarr['post_type'] ? true : $maybe_empty;
}, 10, 2 );
`
    );
  const faulted = (name, postType) => {
    installFault(postType, name);
    const res = catalogSync([], failingFile);
    rmSync(join(muDir, name), { force: true });
    return res;
  };

  /* Post phase fails after the media phase imported Panalera's reuse plus
     two fresh imports: the run's own imports must roll back with the posts,
     while the checksum-reused tote attachment is never ours to delete. */
  const postFail = faulted('fp-test-issue22-post-fail.php', 'fp_product');
  assert.notEqual(postFail.status, 0, 'the faulted post phase must fail the run');
  assertContains(
    `${postFail.stdout}\n${postFail.stderr}`,
    'no partial catalog mutation was kept',
    'the post-phase failure must report the rollback'
  );
  assert.equal(productCount(), productsBefore, 'the post-phase failure must roll every created product back');
  assert.equal(attachmentCount(), baseline, 'a post-phase failure must leave zero orphaned attachments from the run (run-imported media rolled back)');
  assert.ok(attachmentAlive(toteAttachment), 'the checksum-reused tote attachment must never be deleted on the failure path');
  assert.ok(
    !uploadsFileNames().includes('fp-caja-bacaladera.webp'),
    'the rolled-back import must not leave an orphaned file in the uploads directory'
  );

  /* Media phase fails mid-import (reuse already resolved, one fresh import
     already done): only this run's import may be removed — the pre-existing
     tote attachment reused minutes earlier belongs to fp-tote, not to the
     failing run, and must survive. */
  const mediaFail = faulted('fp-test-issue22-media-fail.php', 'attachment');
  assert.notEqual(mediaFail.status, 0, 'the faulted media phase must fail the run');
  assertContains(`${mediaFail.stdout}\n${mediaFail.stderr}`, 'media import failed', 'the media-phase failure must name the import that failed');
  assert.equal(productCount(), productsBefore, 'a media-phase failure must not create any product');
  assert.equal(attachmentCount(), baseline, 'a media-phase failure must remove only the run import and keep every pre-existing attachment');
  assert.ok(attachmentAlive(toteAttachment), 'the checksum-reused tote attachment must survive the media-phase failure');
  assert.ok(
    !uploadsFileNames().includes('fp-caja-quitrasaca.webp'),
    'the failed import must not leave its uploaded bits in the uploads directory'
  );

  /* Recovery: with the fault cleared, the same source applies cleanly — the
     reused media is still not imported a second time. */
  const recovery = catalogSync([], failingFile);
  assert.equal(recovery.status, 0, `the recovery run must succeed:\n${recovery.stderr}`);
  assertContains(recovery.stdout, 'fp-caja-panalera: created', 'the recovery run must create the checksum-reuse product');
  assertContains(recovery.stdout, 'Summary: created=3 updated=0 unchanged=17 warnings=0 errors=0', 'the recovery run must report the three fixture creates');
  assert.equal(attachmentCount(), baseline + 2, 'only the two genuinely fresh media may be imported; the reuse must not import again');
  assert.equal(productMeta('fp-caja-panalera', '_thumbnail_id'), toteAttachment, 'the Panalera record must attach the existing tote media by checksum');
  const rerun = catalogSync([], failingFile);
  assertContains(rerun.stdout, 'Summary: created=0 updated=0 unchanged=20 warnings=0 errors=0', 'the fixture source must be a no-op once applied');

  /* Restore the exact section-11 handover state. */
  const deleteBySourceId = (sourceId) => {
    const id = wp([
      'eval',
      `echo (int) ( get_posts( array( "post_type" => "fp_product", "post_status" => "any", "posts_per_page" => 1, "fields" => "ids", "no_found_rows" => true, "suppress_filters" => true, "meta_key" => "_fp_source_id", "meta_value" => "${sourceId}" ) )[0] ?? 0 );`,
    ]).stdout;
    assert.notEqual(id, '0', `cleanup must find the fixture product ${sourceId}`);
    wp(['post', 'delete', id, '--force']);
  };
  const attachmentByChecksum = (checksum) =>
    wp([
      'eval',
      `echo (int) ( get_posts( array( "post_type" => "attachment", "post_status" => "inherit", "posts_per_page" => 1, "fields" => "ids", "no_found_rows" => true, "suppress_filters" => true, "meta_key" => "_fp_image_checksum", "meta_value" => "${checksum}" ) )[0] ?? 0 );`,
    ]).stdout;
  for (const sourceId of ['fp-caja-panalera', 'fp-caja-bacaladera', 'fp-caja-quitrasaca']) deleteBySourceId(sourceId);
  for (const checksum of [checksumOf(bacaladeraBytes), checksumOf(quitrasacaBytes)]) {
    const attachment = attachmentByChecksum(checksum);
    assert.notEqual(attachment, '0', 'cleanup must find each fixture import by checksum');
    wp(['post', 'delete', attachment, '--force']);
  }
  assert.equal(productCount(), 17, 'cleanup must restore the reviewed 17-product catalog');
  assert.equal(attachmentCount(), baseline, 'cleanup must restore the baseline media library');
  const restore = catalogSync();
  assert.equal(restore.status, 0, `the reviewed source must sync cleanly again:\n${restore.stderr}`);
  assertContains(restore.stdout, 'Summary: created=0 updated=0 unchanged=17 warnings=0 errors=0', 'the reviewed source must be a no-op again after cleanup');

  section('Post-phase failure rollback (issue #22)', [
    'A post-phase failure after successful media imports rolls its run-imported attachments back (zero orphans, no leftover uploads file) alongside the created posts',
    'A mid-import media failure removes only the run import — attachments reused by checksum are never deleted on any failure path',
    'Recovery after the failures applies the same source cleanly and still reuses the checksum-matched media instead of importing it again',
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

  // The cookie is a random opaque 256-bit token: HttpOnly, SameSite=Lax,
  // 30-day expiry — and it carries no basket data whatsoever. The check
  // origin is plain HTTP, so the cookie must not claim Secure (issue #19).
  const token = sessionCookie.match(/fpcq_basket=([0-9a-f]{64})/)?.[1];
  assert.ok(token, 'the cookie must carry the 64-hex-char opaque token');
  assertSessionCookieAttributes(sessionCookie, { tls: false, label: 'session' });
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
    'The cookie carries only a random 256-bit opaque token (HttpOnly, SameSite=Lax, 30 days; Secure follows the request scheme — issue #19); only its sha256 hash is stored server-side',
    'The basket survives refresh and navigation; the header counts distinct lines (Cotización (1)) regardless of unit quantity; re-adds merge into the line',
    'The mini basket shows Product, quantity and a route to the full Cotización view',
    'Invalid nonce, session, Product (unknown or archived) and quantity mutate nothing and return recoverable messages (stale cookies cleared for retry)',
    'The JavaScript enhancement receives JSON state and updates the visible count/mini basket; the server remains authoritative',
  ]);
});

/* ── 12b. Cookie Secure flag follows the request scheme (issue #19) ──── */

test('the basket cookie sets Secure on TLS requests and omits it on plain HTTP', { timeout: 60_000 }, async () => {
  const nonce = (await get(PRODUCT_URL, MOBILE_UA)).body.match(/name="fp_basket_nonce" value="([a-f0-9]{10})"/)?.[1];
  assert.ok(nonce, 'the product-page chooser must carry a nonce');
  const addFields = (over = {}) => ({
    action: 'fp_basket_add',
    fp_product: 'fp-caja-cosechera-3-4',
    fp_quantity: '1',
    fp_basket_nonce: nonce,
    _wp_http_referer: PRODUCT_URL,
    ...over,
  });

  /* One authoritative add under each scheme — the identical request
     except for how it presents TLS. */
  const sessionCookieForScheme = async (extraHeaders, scheme) => {
    const res = await postForm(addFields(), extraHeaders);
    assert.equal(res.status, 302, `the add operation must succeed ${scheme}`);
    const [cookie] = res.setCookies;
    assert.ok(cookie, `the ${scheme} response must carry the session cookie`);
    return cookie;
  };

  /* TLS, presented exactly as staging presents it: the Nginx vhost forwards
     X-Forwarded-Proto: https and wp-config maps it onto $_SERVER['HTTPS']. */
  const tlsCookie = await sessionCookieForScheme({ 'x-forwarded-proto': 'https' }, 'under the forwarded TLS scheme');
  assertSessionCookieAttributes(tlsCookie, { tls: true, label: 'TLS' });

  /* Plain HTTP must keep a working basket: a Secure flag there would make
     every ordinary browser drop the cookie. */
  const plainCookie = await sessionCookieForScheme({}, 'over plain HTTP');
  assertSessionCookieAttributes(plainCookie, { tls: false, label: 'plain-HTTP' });

  section('Basket cookie scheme (issue #19)', [
    'The basket cookie sets Secure on TLS requests (staging always answers over TLS — staging behavior unchanged)',
    'The basket cookie omits Secure on plain-HTTP requests so basket persistence keeps working without TLS',
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
  const staffCookie = authCookie();
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
  assert.equal(humanPaced(token, idemToken), 'ok', 'the deterministic submission pace must apply to the issue #8 flow');

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
  assert.equal(humanPaced(token, idemToken), 'ok', 'the second request form must be paced like a human fill');
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
  assert.equal(humanPaced(token, idemToken), 'ok', 'the failure-recovery form must be paced like a human fill');
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
  assert.equal(humanPaced(eligToken, eligTokenField), 'ok', 'the eligibility submission must be paced like a human fill');
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
  const adminCookie = authCookie();
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
  const subCookie = authCookie(subUserId);
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
  const adminCookie = authCookie();
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

/* ── 17. Durable sales and customer notifications (issue #10) ──── */

test('sales and customer notifications are durable jobs delivered independently of receipt', { timeout: 240_000 }, async () => {
  const cookieHeader = (token) => ({ cookie: `fpcq_basket=${token}` });
  const noticeOf = (res) => new URL(res.headers.location || '', SITE_URL).searchParams.get('fpcq_notice');
  const submittedRef = (res) => new URL(res.headers.location || '', SITE_URL).searchParams.get('fpcq_submitted');
  const COLOR_ID = 'fp-caja-universal-cerrada-color';
  const COLOR_URL = '/producto/caja-universal-cerrada-color/';

  const quoteCount = () => Number(wp(['post', 'list', '--post_type=fp_quote', '--post_status=private', '--format=count']).stdout || '0');
  const notifyState = (reference) =>
    JSON.parse(
      wp([
        'eval',
        `$posts = get_posts( array( "post_type" => "fp_quote", "post_status" => "private", "posts_per_page" => 1, "no_found_rows" => true, "suppress_filters" => true, "meta_key" => "_fpq_reference", "meta_value" => "${reference}" ) );` +
          'if ( empty( $posts ) ) { echo "null"; } else { $p = $posts[0]; echo wp_json_encode( array( ' +
          '"id" => $p->ID, ' +
          '"jobs" => json_decode( (string) get_post_meta( $p->ID, "_fpq_notifications", true ), true ), ' +
          '"log" => json_decode( (string) get_post_meta( $p->ID, "_fpq_notify_log", true ), true ) ) ); }',
      ]).stdout || 'null'
    );
  const mailLog = () => JSON.parse(wp(['eval', 'echo wp_json_encode( get_option( "fp_test_mail_log", array() ) );']).stdout || '[]');
  const clearLog = () => wp(['eval', 'delete_option( "fp_test_mail_log" );']);
  const setBehavior = (behavior) => wp(['eval', `update_option( "fp_test_mail_behavior", "${behavior}" );`]);
  const setMode = (mode, to = null, allow = null) =>
    wp([
      'eval',
      `update_option( "freeplast_cq_mail_mode", "${mode}" );` +
        (to === null ? '' : ` update_option( "freeplast_cq_mail_to", "${to}" );`) +
        (allow === null ? '' : ` update_option( "freeplast_cq_mail_allow", "${allow}" );`),
    ]);
  const runDelivery = (reference) => wp(['eval', `Freeplast_CQ_Notifications::process( "${reference}" );`]);

  /* The single external mail adapter seam: every delivery is recorded and
     answered per the configured behavior (ok | fail | fail:sales |
     fail:customer). A refused message is never recorded — the log is the
     set of messages that actually reached the transport. Nothing else in
     the site touches a transport. */
  const muDir = join(WP_DIR, 'wp-content', 'mu-plugins');
  mkdirSync(muDir, { recursive: true });
  writeFileSync(
    join(muDir, 'fp-test-mail-adapter.php'),
    `<?php
/**
 * Test seam: replaces the external mail adapter boundary
 * (freeplast_cq_send_mail) so the checks control delivery and assert
 * business outcomes. Records every delivered message; answers per the
 * configured behavior option (ok | fail | fail:sales | fail:customer).
 */
add_filter( 'freeplast_cq_send_mail', function ( $result, $message ) {
    $behavior = (string) get_option( 'fp_test_mail_behavior', 'ok' );
    $channel  = isset( $message['channel'] ) ? $message['channel'] : '';
    if ( 'fail' === $behavior || 'fail:' . $channel === $behavior ) {
        return false;
    }
    $log   = get_option( 'fp_test_mail_log', array() );
    $log[] = array(
        'channel'  => $channel,
        'to'       => isset( $message['to'] ) ? $message['to'] : '',
        'subject'  => isset( $message['subject'] ) ? $message['subject'] : '',
        'reply_to' => isset( $message['reply_to'] ) ? $message['reply_to'] : '',
        'body'     => isset( $message['body'] ) ? $message['body'] : '',
    );
    update_option( 'fp_test_mail_log', $log );
    return true;
}, 10, 2 );
`
  );

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

  /** Build a fresh two-line basket (plain + Color option) and return its session with form credentials. */
  const prepareSession = async () => {
    const productPage = await get(PRODUCT_URL, MOBILE_UA);
    const addNonce = productPage.body.match(/name="fp_basket_nonce" value="([a-f0-9]{10})"/)?.[1];
    assert.ok(addNonce, 'the notification flow needs a chooser nonce');
    const seeded = await postForm({
      action: 'fp_basket_add',
      fp_product: 'fp-caja-cosechera-3-4',
      fp_quantity: '5',
      fp_basket_nonce: addNonce,
      _wp_http_referer: PRODUCT_URL,
    });
    const token = seeded.setCookies[0].match(/fpcq_basket=([0-9a-f]{64})/)?.[1];
    assert.ok(token, 'the notification flow needs its own guest session');
    const colorAdd = await postForm(
      {
        action: 'fp_basket_add',
        fp_product: COLOR_ID,
        fp_option: 'blanco',
        fp_quantity: '12',
        fp_basket_nonce: addNonce,
        _wp_http_referer: COLOR_URL,
      },
      cookieHeader(token)
    );
    assert.equal(noticeOf(colorAdd), 'added', 'the two-line basket must build');
    const cot = await get('/cotizacion/', MOBILE_UA, cookieHeader(token));
    assertContains(cot.body, 'Cotización (2)', 'the basket must carry both lines');
    const { nonce, token: idemToken } = requestCredentials(cot.body);
    assert.ok(nonce && idemToken, 'the form must be nonce-guarded and carry the idempotency token');
    assert.equal(humanPaced(token, idemToken), 'ok', 'the notification-section submissions must be paced like a human fill');
    return { token, nonce, idemToken };
  };

  /** Submit one request from a fresh two-line basket. */
  const submitRequest = async (over = {}) => {
    const session = await prepareSession();
    const res = await postForm(
      {
        action: 'fp_request_submit',
        ...validFields,
        fp_request_nonce: session.nonce,
        fp_request_token: session.idemToken,
        _wp_http_referer: '/cotizacion/',
        ...over,
      },
      cookieHeader(session.token)
    );
    return { res, token: session.token, reference: submittedRef(res) };
  };

  try {
    setMode('live');
    setBehavior('ok');
    clearLog();

    /* 16.1 — Receipt is persistence: confirmation and cleared basket never
       wait for mail, and the two durable jobs exist on the record itself. */
    const before = quoteCount();
    const first = await submitRequest();
    assert.match(first.reference, /^FP-\d{4}-\d{6}$/, 'the submission must persist with a reference');
    assert.equal(quoteCount(), before + 1, 'exactly one record persists');
    const confirmation = await get(`/cotizacion/?fpcq_submitted=${first.reference}`, MOBILE_UA, cookieHeader(first.token));
    assertContains(confirmation.body, 'Solicitud recibida', 'successful receipt confirms without mail');
    assertContains(confirmation.body, first.reference, 'the confirmation shows the Request Reference');
    assertContains(confirmation.body, 'Cotización (0)', 'the basket cleared before any delivery');
    assert.deepEqual(mailLog(), [], 'nothing is delivered yet — receipt never waited for mail');
    const jobs = notifyState(first.reference)?.jobs;
    assert.ok(jobs, 'the record must carry its notification jobs');
    assert.deepEqual(Object.keys(jobs).sort(), ['customer', 'sales'], 'exactly one sales job and one customer job per reference');
    assert.equal(jobs.sales.state, 'pending', 'the sales job starts pending');
    assert.equal(jobs.customer.state, 'pending', 'the customer job starts pending');
    const scheduled = wp(['eval', `echo wp_next_scheduled( "freeplast_cq_notify", array( "${first.reference}" ) ) ? "yes" : "no";`]).stdout;
    assert.equal(scheduled, 'yes', 'the durable jobs carry a scheduled delivery event');

    /* 16.2 — One delivery event processes both jobs exactly once, with the
       required content and Reply-To routing. */
    runDelivery(first.reference);
    const entries = mailLog();
    assert.equal(entries.length, 2, 'exactly one sales notification and one customer acknowledgement deliver');
    const sales = entries.find((e) => e.channel === 'sales');
    const customer = entries.find((e) => e.channel === 'customer');
    assert.ok(sales && customer, 'both channels must be represented');
    assert.equal(sales.to, 'ventas@freeplast.cl', 'the sales notification reaches the configured sales address');
    assert.ok(sales.reply_to.includes('maria@acme.cl'), 'sales Reply-To points to the customer');
    assert.ok(sales.subject.includes(first.reference), 'the sales subject carries the Request Reference');
    assert.ok(!sales.subject.includes('[STAGING]'), 'live mode adds no staging prefix');
    assert.equal(customer.to, 'maria@acme.cl', 'the acknowledgement reaches the customer');
    assert.ok(customer.reply_to.includes('ventas@freeplast.cl'), 'customer Reply-To points to the sales address');
    assert.ok(customer.subject.includes(first.reference), 'the customer subject carries the Request Reference');
    const productNeedles = ['Caja Cosechera 3/4', '5 unidades', 'Caja Universal Cerrada Color', 'Blanco', '12 unidades'];
    for (const needle of productNeedles) {
      assertContains(sales.body, needle, `the sales body includes ${needle}`);
      assertContains(customer.body, needle, `the customer body includes ${needle}`);
    }
    for (const needle of ['María González', '+56 9 6844 4265', 'maria@acme.cl', 'Agrícola ACME SpA', '76.335.888-6', 'Camino El Arrayán 52']) {
      assertContains(sales.body, needle, `the sales body includes the operational detail ${needle}`);
    }
    let after = notifyState(first.reference).jobs;
    assert.equal(after.sales.state, 'sent', 'the sales job records its delivery');
    assert.equal(after.customer.state, 'sent', 'the customer job records its delivery');
    assert.ok(after.sales.sent_at > 0 && after.customer.sent_at > 0, 'delivery times are recorded');
    // Idempotent: re-running the delivery event (retry, duplicate event)
    // sends nothing new.
    runDelivery(first.reference);
    runDelivery(first.reference);
    assert.equal(mailLog().length, 2, 'already successful delivery is never duplicated');

    /* 16.3 — A mail outage after persistence (total, then partial) never
       duplicates the request or a successful delivery; staff resends safely. */
    const baseline = quoteCount();
    setBehavior('fail');
    const failed = await submitRequest({ fp_mensaje: '' });
    assert.match(failed.reference, /^FP-\d{4}-\d{6}$/, 'a mail outage must not discard the request');
    assert.equal(quoteCount(), baseline + 1, 'a mail outage never duplicates or blocks the record');
    clearLog();
    runDelivery(failed.reference);
    assert.deepEqual(mailLog(), [], 'a failed delivery sends nothing');
    let failedJobs = notifyState(failed.reference).jobs;
    assert.equal(failedJobs.sales.state, 'failed', 'the sales job records the failure');
    assert.equal(failedJobs.customer.state, 'failed', 'the customer job records the failure');
    assert.equal(failedJobs.sales.attempts, 1, 'attempts are recorded');
    clearLog();
    setBehavior('fail:customer');
    runDelivery(failed.reference);
    const partial = mailLog();
    assert.equal(partial.length, 1, 'a partial outage delivers only the working channel');
    assert.equal(partial[0].channel, 'sales', 'the sales notification still delivers');
    failedJobs = notifyState(failed.reference).jobs;
    assert.equal(failedJobs.sales.state, 'sent', 'the working channel is delivered');
    assert.equal(failedJobs.customer.state, 'failed', 'the failing channel stays failed');

    const adminCookie = authCookie(1);
    assert.ok(adminCookie.includes('='), 'an admin auth cookie must be generated');
    const failedId = notifyState(failed.reference).id;
    const detail = await get(`/wp-admin/admin.php?page=fp-quote&p=${failedId}`, MOBILE_UA, { cookie: adminCookie });
    assert.equal(detail.status, 200, 'the detail must remain reachable');
    assertContains(detail.body, 'Notificaciones', 'the detail shows the notification delivery state');
    assertContains(detail.body, 'fallida', 'the failed channel state is visible');
    assertContains(detail.body, 'Modo de correo: live', 'the effective mail mode is visible');
    const resendNonce = detail.body.match(/name="fp_notify_nonce" value="([a-f0-9]{10})"/)?.[1];
    assert.ok(resendNonce, 'a failed channel offers the staff resend');
    setBehavior('ok');
    /** POST one staff resend of one channel (the guard cases vary nonce, identity and referer). */
    const postResend = (channel, nonce, headers = {}, referer = `/wp-admin/admin.php?page=fp-quote&p=${failedId}`) =>
      postForm(
        {
          action: 'fp_notify_resend',
          fp_quote: String(failedId),
          fp_channel: channel,
          fp_notify_nonce: nonce,
          _wp_http_referer: referer,
        },
        headers
      );
    const resent = await postResend('customer', resendNonce, { cookie: adminCookie });
    assert.equal(resent.status, 302, 'the resend answers POST-redirect-GET');
    assert.equal(new URL(resent.headers.location, SITE_URL).searchParams.get('fpcq_notify'), 'sent', 'the resend delivers the failed channel');
    const afterResend = mailLog();
    assert.equal(afterResend.length, 2, 'sales (partial) + customer (resent) are the only deliveries');
    assert.equal(afterResend.filter((e) => e.channel === 'sales').length, 1, 'the safe resend never duplicates the delivered sales notification');
    assert.equal(notifyState(failed.reference).jobs.customer.state, 'sent', 'the resent channel is delivered');

    // A crafted resend of an already-sent channel is a no-op.
    const noop = await postResend('sales', resendNonce, { cookie: adminCookie });
    assert.equal(new URL(noop.headers.location, SITE_URL).searchParams.get('fpcq_notify'), 'noop', 'an already-sent channel is never resent');
    assert.equal(mailLog().filter((e) => e.channel === 'sales').length, 1, 'already successful delivery cannot be duplicated');

    // Guards: bad nonce, no capability, logged out.
    const badNonce = await postResend('customer', 'deadbeefdeadbeefdeadbeefdeadbeef', { cookie: adminCookie }, '/wp-admin/');
    assert.notEqual(badNonce.status, 200, 'a bad resend nonce must be rejected');
    const loggedOut = await postResend('customer', resendNonce, {}, '/wp-admin/');
    assert.notEqual(loggedOut.status, 200, 'a logged-out resend must be denied');
    const noCapId = wp([
      'eval',
      'echo (int) wp_insert_user( array( "user_login" => "fp_sinventas2", "user_pass" => wp_generate_password( 24 ), "user_email" => "sinventas2@example.test" ) );',
    ]).stdout;
    assert.ok(Number(noCapId) > 0, 'a capability-less user must be created for the denial check');
    const denied = await postResend('customer', resendNonce, { cookie: authCookie(noCapId) }, '/wp-admin/');
    assert.notEqual(denied.status, 200, 'the resend must deny users without the capability');

    /* 16.4 — Job creation commits with the record or the whole submission
       fails (no record, no success, basket retained). */
    const kept = quoteCount();
    writeFileSync(join(muDir, 'fp-test-no-jobs.php'), "<?php\nadd_filter( 'freeplast_cq_notification_jobs', '__return_false' );\n");
    try {
      const session = await prepareSession();
      const res = await postForm(
        {
          action: 'fp_request_submit',
          ...validFields,
          fp_request_nonce: session.nonce,
          fp_request_token: session.idemToken,
          _wp_http_referer: '/cotizacion/',
        },
        cookieHeader(session.token)
      );
      assert.equal(noticeOf(res), 'request_failed', 'a failing job creation must not claim success');
      assert.ok(!(res.headers.location || '').includes('fpcq_submitted'), 'no confirmation may be shown');
      assert.equal(quoteCount(), kept, 'no record persists without its durable notification jobs');
      const back = await get('/cotizacion/', MOBILE_UA, cookieHeader(session.token));
      assertContains(back.body, 'Cotización (2)', 'the basket must be retained');
      assertContains(back.body, 'value="María González"', 'the entered values must be retained');
    } finally {
      rmSync(join(muDir, 'fp-test-no-jobs.php'), { force: true });
    }

    /* 16.5 — Staging containment: redirect override, approved-recipient
       allowlist, non-delivery — every restricted mode prefixes [STAGING]. */
    setBehavior('ok');
    setMode('suppress');
    clearLog();
    const suppressed = await submitRequest();
    assert.match(suppressed.reference, /^FP-\d{4}-\d{6}$/);
    runDelivery(suppressed.reference);
    let sJobs = notifyState(suppressed.reference).jobs;
    assert.equal(sJobs.sales.state, 'suppressed', 'non-delivery mode suppresses the sales job');
    assert.equal(sJobs.customer.state, 'suppressed', 'non-delivery mode suppresses the customer job');
    assert.equal(sJobs.sales.code, 'non_delivery_mode', 'the suppression reason is recorded');
    assert.deepEqual(mailLog(), [], 'non-delivery mode never reaches the transport');

    setMode('redirect', 'qa@mliu.test');
    clearLog();
    const redirected = await submitRequest();
    runDelivery(redirected.reference);
    const rlog = mailLog();
    assert.equal(rlog.length, 2, 'both messages deliver under the override');
    for (const entry of rlog) {
      assert.equal(entry.to, 'qa@mliu.test', 'redirect mode forces the configured override recipient');
      assert.ok(entry.subject.startsWith('[STAGING] '), 'the staging subject prefix is visible');
    }
    assert.ok(rlog[0].reply_to.includes('maria@acme.cl'), 'the Reply-To routing survives containment');
    sJobs = notifyState(redirected.reference).jobs;
    assert.equal(sJobs.sales.state, 'sent', 'the redirected sales job delivers');
    assert.equal(sJobs.customer.state, 'sent', 'the redirected customer job delivers');

    setMode('allowlist', null, 'ventas@freeplast.cl');
    clearLog();
    const listed = await submitRequest();
    runDelivery(listed.reference);
    const alog = mailLog();
    assert.equal(alog.length, 1, 'only the allowlisted recipient receives mail');
    assert.equal(alog[0].channel, 'sales', 'the sales address is the approved recipient');
    assert.equal(alog[0].to, 'ventas@freeplast.cl', 'the allowlisted recipient is addressed directly');
    assert.ok(alog[0].subject.startsWith('[STAGING] '), 'the allowlist mode also prefixes the subject');
    sJobs = notifyState(listed.reference).jobs;
    assert.equal(sJobs.sales.state, 'sent');
    assert.equal(sJobs.customer.state, 'suppressed', 'the non-approved recipient is not delivered');
    assert.equal(sJobs.customer.code, 'not_allowlisted', 'the rejection reason is recorded');
    setMode('live');

    /* 16.6 — The event log carries IDs, event and delivery state — never a
       customer field value. */
    for (const record of [first, failed, suppressed, listed]) {
      const state = notifyState(record.reference);
      const logJson = JSON.stringify(state.log || []);
      assert.ok((state.log || []).length > 0, 'each processed reference must log its delivery events');
      assertContains(logJson, '"state"', 'the log records the event/delivery state');
      for (const forbidden of ['maria', 'María', 'González', 'acme', '+56', 'Camino', '76.335', 'plásticos']) {
        assertAbsent(logJson, forbidden, `the notification log must never contain customer field values (${forbidden})`);
      }
    }

    /* 16.7 — Migration 7 backfills pending jobs and a delivery event onto
       records persisted before the slice. */
    const legacyId = notifyState(first.reference).id;
    wp(['eval', `delete_post_meta( ${legacyId}, "_fpq_notifications" ); delete_post_meta( ${legacyId}, "_fpq_notify_log" );`]);
    wp(['option', 'update', 'fp_db_version', '6']);
    const migrated = wp(['eval', 'Freeplast_CQ_Migrations::run(); echo get_option( "fp_db_version" );']);
    assert.equal(migrated.stdout, '7', 'migration 7 must apply');
    const backfilled = notifyState(first.reference).jobs;
    assert.deepEqual(Object.keys(backfilled).sort(), ['customer', 'sales'], 'a pre-slice record regains its two pending jobs');
    assert.equal(backfilled.sales.state, 'pending', 'the backfilled jobs start pending');
    const backfillScheduled = wp(['eval', `echo wp_next_scheduled( "freeplast_cq_notify", array( "${first.reference}" ) ) ? "yes" : "no";`]).stdout;
    assert.equal(backfillScheduled, 'yes', 'the backfill schedules the delivery');
    // Records that already carry delivery state are never reset by re-runs.
    wp(['eval', 'Freeplast_CQ_Migrations::run();']);
    assert.equal(notifyState(failed.reference).jobs.customer.state, 'sent', 'already delivered state survives migration re-runs');

    section('Durable sales and customer notifications (issue #10)', [
      'Quote Request persistence and its two durable notification jobs commit together (same record insert) or fail together (freeplast_cq_notification_jobs seam aborts the submission; basket and values retained)',
      'Successful receipt confirms with the Request Reference and clears the basket before any external mail delivery is required — nothing delivers until the scheduled event runs',
      'Exactly one sales notification and one customer acknowledgement are scheduled per Request Reference; both carry reference, products, options and quantities; sales adds the operational customer/dispatch details',
      'Sales Reply-To points to the customer; customer Reply-To points to ventas@freeplast.cl; live mode adds no prefix',
      'A temporary or partial mail failure after persistence never duplicates the request or a successful delivery: failed state + attempts recorded, retries and crafted resends of sent channels are no-ops',
      'Authorized staff see the per-channel delivery state and resend a failed notification safely (nonce + capability guarded; bad nonce, logged-out and capability-less resends are denied)',
      'Staging containment: redirect forces the configured override recipient, allowlist delivers only approved recipients, suppress delivers nothing — every restricted mode prefixes the subject with [STAGING]',
      'The event log records states and codes only (no customer field values); migration 7 backfills pending jobs and a delivery event onto pre-slice records without resetting delivered state',
    ]);
  } finally {
    rmSync(join(muDir, 'fp-test-mail-adapter.php'), { force: true });
    setMode('live');
    setBehavior('ok');
  }
});
/* ── 18. Delivery Address confirmation + Dispatch Distance (issue #11) ── */

test('dispatch requests confirm Chilean delivery addresses and record the internal road distance', { timeout: 240_000 }, async () => {
  const cookieHeader = (token) => ({ cookie: `fpcq_basket=${token}` });
  const noticeOf = (res) => new URL(res.headers.location || '', SITE_URL).searchParams.get('fpcq_notice');
  const submittedRef = (res) => new URL(res.headers.location || '', SITE_URL).searchParams.get('fpcq_submitted');
  const addressNonce = (html) => html.match(/name="fp_address_nonce" value="([a-f0-9]{10})"/)?.[1];
  const FORMATTED = 'Av. Providencia 1234, 7500131 Providencia, Región Metropolitana, Chile';
  const DEFAULT_ORIGIN = 'Camino El Arrayán 52, San Francisco de Mostazal';

  /* The Google provider is replaced at its narrow adapter boundary: a
     mu-plugin installs a fake client whose behavior is driven by the
     fp_fake_google option (ok | off | resolve_fail | route_fail), so no
     check ever performs a network call or needs a real credential. */
  const muDir = join(WP_DIR, 'wp-content', 'mu-plugins');
  mkdirSync(muDir, { recursive: true });
  writeFileSync(
    join(muDir, 'fp-test-fake-google.php'),
    `<?php
add_filter( 'freeplast_cq_google_client', function ( $client ) {
	$mode = get_option( 'fp_fake_google', 'ok' );
	if ( 'off' === $mode ) { return null; }
	return new FP_Fake_Google_Client( $mode );
} );
class FP_Fake_Google_Client {
	private $mode;
	public function __construct( $mode ) { $this->mode = $mode; }
	public function suggestions( $query ) {
		return array(
			array( 'id' => 'fake-place-1', 'description' => 'Av. Providencia 1234, Providencia, Santiago, Chile' ),
			array( 'id' => 'fake-place-2', 'description' => 'Camino El Arrayán 100, San Francisco de Mostazal, Chile' ),
		);
	}
	public function resolve( $place_id ) {
		if ( 'resolve_fail' === $this->mode ) { return null; }
		return array(
			'place_id'  => $place_id,
			'formatted' => '${FORMATTED}',
			'lat'       => -33.4264,
			'lng'       => -70.6236,
		);
	}
	public function route( $origin, $destination ) {
		if ( 'route_fail' === $this->mode ) { return null; }
		return array( 'meters' => 51234 );
	}
}
`
  );
  const fakeMode = (mode) => wp(['option', 'update', 'fp_fake_google', mode]);
  try {
    assert.equal(fakeMode('ok').status, 0, 'the fake provider mode must be settable');

    /* Destination and distance state of one persisted Quote Request. */
    const dispatchOf = (reference) =>
      JSON.parse(
        wp([
          'eval',
          `$posts = get_posts( array( "post_type" => "fp_quote", "post_status" => "private", "posts_per_page" => 1, "no_found_rows" => true, "suppress_filters" => true, "meta_key" => "_fpq_reference", "meta_value" => "${reference}" ) );` +
            'if ( empty( $posts ) ) { echo "null"; } else { $p = $posts[0]; echo wp_json_encode( array( ' +
            '"direccion" => (string) ( json_decode( (string) get_post_meta( $p->ID, "_fpq_customer", true ), true )["direccion_despacho"] ?? "" ), ' +
            '"destination" => json_decode( (string) get_post_meta( $p->ID, "_fpq_destination", true ), true ), ' +
            '"distance" => json_decode( (string) get_post_meta( $p->ID, "_fpq_distance", true ), true ), ' +
            '"id" => $p->ID ) ); }',
        ]).stdout || 'null'
      );

    /* A fresh anonymous one-line basket for each sub-scenario. */
    const newSession = async () => {
      const page = await get(PRODUCT_URL, MOBILE_UA);
      const nonce = page.body.match(/name="fp_basket_nonce" value="([a-f0-9]{10})"/)?.[1];
      assert.ok(nonce, 'the address section needs a chooser nonce');
      const res = await postForm(
        {
          action: 'fp_basket_add',
          fp_product: 'fp-caja-cosechera-3-4',
          fp_quantity: '5',
          fp_basket_nonce: nonce,
          _wp_http_referer: PRODUCT_URL,
        },
        {}
      );
      const token = res.setCookies[0].match(/fpcq_basket=([0-9a-f]{64})/)?.[1];
      assert.ok(token, 'the address section needs its own guest session');
      return token;
    };

    const validFields = {
      fp_nombre: 'María González',
      fp_telefono: '+56 9 6844 4265',
      fp_email: 'maria@acme.cl',
      fp_empresa: 'Agrícola ACME SpA',
      fp_rut: '76.335.888-6',
      fp_giro: 'Comercialización de productos plásticos',
      fp_despacho: 'si',
      fp_direccion: '',
      fp_mensaje: '',
    };
    const submitAs = async (token, over = {}) => {
      const page = await get('/cotizacion/', MOBILE_UA, cookieHeader(token));
      const { nonce, token: idemToken } = requestCredentials(page.body);
      assert.ok(nonce && idemToken, 'the submission form must carry its nonce and idempotency token');
      assert.equal(humanPaced(token, idemToken), 'ok', 'the address-section submissions must be paced like a human fill');
      return postForm(
        {
          action: 'fp_request_submit',
          ...validFields,
          fp_request_nonce: nonce,
          fp_request_token: idemToken,
          _wp_http_referer: '/cotizacion/',
          ...over,
        },
        cookieHeader(token)
      );
    };
    const confirmAddress = async (token) => {
      const page = await get('/cotizacion/', MOBILE_UA, cookieHeader(token));
      const nonce = addressNonce(page.body);
      assert.ok(nonce, 'the address steps need their nonce');
      const picked = await postForm(
        { action: 'fp_address_pick', fp_place: 'fake-place-1', fp_address_nonce: nonce, _wp_http_referer: '/cotizacion/' },
        cookieHeader(token)
      );
      assert.equal(noticeOf(picked), 'address_review', 'picking a suggestion presents it for review');
      const confirmed = await postForm(
        { action: 'fp_address_confirm', fp_place: 'fake-place-1', fp_address_nonce: nonce, _wp_http_referer: '/cotizacion/' },
        cookieHeader(token)
      );
      assert.equal(noticeOf(confirmed), 'address_confirmed', 'the explicit confirmation must succeed');
    };

    /* 16.1 — Assistance is dispatch-conditional and credential-conditional;
       the credential never reaches the page. */
    const token1 = await newSession();
    const cot = await get('/cotizacion/', MOBILE_UA, cookieHeader(token1));
    const wrapperAt = cot.body.indexOf('fpcq-hidden" data-fpcq-address-field');
    assert.ok(wrapperAt !== -1, 'the address block stays hidden without dispatch');
    const searchAt = cot.body.indexOf('data-fpcq-address-search');
    assert.ok(searchAt > wrapperAt, 'the Google assistance must live inside the dispatch-conditional address block');
    assert.doesNotMatch(cot.body, /AIza/, 'no Google credential may ever reach the page');
    fakeMode('off');
    const unassisted = await get('/cotizacion/', MOBILE_UA, cookieHeader(token1));
    assertAbsent(unassisted.body, 'data-fpcq-address-search', 'without provider credentials no assistance renders');
    assertContains(unassisted.body, 'name="fp_direccion"', 'the manual fallback always remains');
    fakeMode('ok');

    /* 16.2 — Suggestions: the JSON enhancement endpoint and the no-JS
       search round-trip both query the server-side adapter. */
    const suggest = await postForm(
      { action: 'fp_address_suggest', fp_query: 'Av. Providencia 1234', fp_address_nonce: addressNonce(cot.body), _wp_http_referer: '/cotizacion/' },
      cookieHeader(token1)
    );
    let payload = JSON.parse(suggest.body);
    assert.equal(payload.ok, true, 'the suggest endpoint must answer JSON');
    assert.equal(payload.suggestions.length, 2, 'the adapter returns its Chilean suggestions');
    assert.equal(payload.suggestions[0].id, 'fake-place-1');
    assertContains(payload.suggestions[0].description, 'Chile', 'suggestions are Chilean destinations');
    const badNonce = await postForm(
      { action: 'fp_address_suggest', fp_query: 'Av. Providencia 1234', fp_address_nonce: 'deadbeefdeadbeefdeadbeefdeadbeef', _wp_http_referer: '/cotizacion/' },
      cookieHeader(token1)
    );
    assert.equal(JSON.parse(badNonce.body).ok, false, 'a bad nonce never reaches the provider');
    const sessionless = await postForm(
      { action: 'fp_address_suggest', fp_query: 'Av. Providencia 1234', fp_address_nonce: addressNonce(cot.body), _wp_http_referer: '/cotizacion/' },
      {}
    );
    assert.equal(JSON.parse(sessionless.body).ok, false, 'a sessionless suggest never reaches the provider');

    const searched = await postForm(
      { action: 'fp_address_search', fp_query: 'Camino El Arrayán 100', fp_address_nonce: addressNonce(cot.body), _wp_http_referer: '/cotizacion/' },
      cookieHeader(token1)
    );
    assert.equal(searched.status, 302, 'the no-JS search answers POST-redirect-GET');
    assert.ok((searched.headers.location || '').includes('#fp-direccion'), 'the search redirect focuses the address field');
    const withSuggestions = await get('/cotizacion/', MOBILE_UA, cookieHeader(token1));
    assertContains(withSuggestions.body, 'value="fp_address_pick"', 'each suggestion posts the pick operation');
    assertContains(withSuggestions.body, 'fake-place-1', 'the suggestion list carries the provider place id');
    assertContains(withSuggestions.body, 'Av. Providencia 1234, Providencia, Santiago, Chile', 'the suggestion list shows the Chilean descriptions');

    /* 16.3 — Select → review the formatted destination → explicit confirm
       (and back: Cambiar restores search + manual entry). */
    const pick = await postForm(
      { action: 'fp_address_pick', fp_place: 'fake-place-1', fp_address_nonce: addressNonce(withSuggestions.body), _wp_http_referer: '/cotizacion/' },
      cookieHeader(token1)
    );
    assert.equal(noticeOf(pick), 'address_review');
    const reviewed = await get('/cotizacion/', MOBILE_UA, cookieHeader(token1));
    assertContains(reviewed.body, 'revísala y confírmala', 'the review state must ask for an explicit confirmation');
    assertContains(reviewed.body, FORMATTED, 'the formatted destination is presented for review');
    assertContains(reviewed.body, 'value="fp_address_confirm"', 'the review block carries the confirm operation');
    assertContains(reviewed.body, 'Confirmar dirección', 'the confirm button is explicit');
    assertContains(reviewed.body, 'Buscar otra', 'the review block keeps the change path');
    assertContains(reviewed.body, 'name="fp_direccion"', 'the manual fallback stays available during review');
    const confirm = await postForm(
      { action: 'fp_address_confirm', fp_place: 'fake-place-1', fp_address_nonce: addressNonce(reviewed.body), _wp_http_referer: '/cotizacion/' },
      cookieHeader(token1)
    );
    assert.equal(noticeOf(confirm), 'address_confirmed');
    const confirmedPage = await get('/cotizacion/', MOBILE_UA, cookieHeader(token1));
    assertContains(confirmedPage.body, 'Dirección confirmada', 'the confirmed destination renders on the form');
    assertContains(confirmedPage.body, FORMATTED, 'the confirmed formatted destination renders');
    assertContains(confirmedPage.body, 'value="fp_address_clear"', 'Cambiar dirección restores the manual path');
    const cleared = await postForm(
      { action: 'fp_address_clear', fp_address_nonce: addressNonce(confirmedPage.body), _wp_http_referer: '/cotizacion/' },
      cookieHeader(token1)
    );
    assert.equal(noticeOf(cleared), 'address_cleared');
    const clearedPage = await get('/cotizacion/', MOBILE_UA, cookieHeader(token1));
    assertContains(clearedPage.body, 'data-fpcq-address-search', 'clearing restores the search path');
    assertAbsent(clearedPage.body, 'Dirección confirmada', 'clearing drops the confirmed destination');

    /* 16.4 — A confirmed destination submits with the request: destination
       data + provider state stored, distance calculated from the
       provisional Warehouse origin, nothing customer-facing. */
    await confirmAddress(token1);
    const ok1 = await submitAs(token1);
    assert.equal(ok1.status, 302);
    const reference1 = submittedRef(ok1);
    assert.match(reference1, /^FP-\d{4}-\d{6}$/);
    const record1 = dispatchOf(reference1);
    assert.ok(record1, 'the confirmed-destination request must persist');
    assert.equal(record1.destination.mode, 'google');
    assert.equal(record1.destination.address, FORMATTED);
    assert.equal(record1.destination.place_id, 'fake-place-1');
    assert.equal(record1.destination.lat, -33.4264);
    assert.equal(record1.destination.lng, -70.6236);
    assert.equal(record1.destination.provider, 'google');
    assert.ok(record1.destination.confirmed_at, 'the confirmation time is stored');
    assert.equal(record1.direccion, FORMATTED, 'the confirmed address is the dispatch address');
    assert.equal(record1.distance.status, 'ok');
    assert.equal(record1.distance.meters, 51234);
    assert.equal(record1.distance.origin, DEFAULT_ORIGIN, 'Camino El Arrayán 52 is the provisional origin');
    assert.equal(record1.distance.provider, 'google-routes');
    assert.ok(record1.distance.calculated_at, 'the calculation time is stored');
    assert.ok(
      !JSON.stringify(record1).includes('price') && !JSON.stringify(record1).includes('precio'),
      'no shipping price is ever stored'
    );
    const confirmation1 = await get(`/cotizacion/?fpcq_submitted=${reference1}`, MOBILE_UA, cookieHeader(token1));
    assertContains(confirmation1.body, reference1, 'the confirmation keeps the Request Reference');
    const confirmationSection = pluginSection(confirmation1.body, 'class="fpcq-confirmation"', 'the confirmation must render for its owning session');
    assertAbsent(confirmationSection, 'distancia', 'the Dispatch Distance never reaches the customer');
    assertAbsent(confirmationSection, 'precio', 'no shipping price reaches the customer');
    assert.equal(wp(['option', 'get', 'fp_dispatch_origin']).stdout, DEFAULT_ORIGIN, 'migration 7 seeds the provisional origin');
    assert.equal(wp(['option', 'get', 'fp_db_version']).stdout, String(DB_VERSION), 'the dispatch-distance migration is applied');

    /* 16.5 — Manual fallback: a rural/unrecognized address submits without
       any provider interaction and routes by address text. */
    const token2 = await newSession();
    const rural = 'Km 12 camino rural sin numeración, Mostazal';
    const ok2 = await submitAs(token2, { fp_direccion: rural });
    const reference2 = submittedRef(ok2);
    assert.match(reference2, /^FP-\d{4}-\d{6}$/);
    const record2 = dispatchOf(reference2);
    assert.equal(record2.destination.mode, 'manual', 'the manual path stores mode manual');
    assert.equal(record2.destination.address, rural);
    assert.equal(record2.destination.place_id, '');
    assert.equal(record2.destination.lat, null, 'no provider data is invented for manual addresses');
    assert.equal(record2.direccion, rural);
    assert.equal(record2.distance.status, 'ok', 'manual addresses route by text');
    assert.equal(record2.distance.meters, 51234);

    /* 16.6 — A provider resolve failure is recoverable: the request is
       never rejected, the manual path still submits. */
    const token3 = await newSession();
    fakeMode('resolve_fail');
    const failedPick = await postForm(
      {
        action: 'fp_address_pick',
        fp_place: 'fake-place-1',
        fp_address_nonce: addressNonce((await get('/cotizacion/', MOBILE_UA, cookieHeader(token3))).body),
        _wp_http_referer: '/cotizacion/',
      },
      cookieHeader(token3)
    );
    assert.equal(noticeOf(failedPick), 'address_error', 'a failed resolve is a recoverable notice');
    const failedPage = await get('/cotizacion/', MOBILE_UA, cookieHeader(token3));
    assertContains(failedPage.body, 'name="fp_direccion"', 'the manual fallback remains after a provider failure');
    assertAbsent(failedPage.body, 'Dirección confirmada', 'nothing was confirmed');
    const ok3 = await submitAs(token3, { fp_direccion: rural });
    assert.match(submittedRef(ok3), /^FP-\d{4}-\d{6}$/, 'a resolve failure never rejects the request');
    assert.equal(dispatchOf(submittedRef(ok3)).destination.mode, 'manual');
    fakeMode('ok');

    /* 16.7 — A Routes failure persists an error state with the destination
       preserved; authorized staff retry through nonce + capability. */
    const token4 = await newSession();
    fakeMode('route_fail');
    await confirmAddress(token4);
    const ok4 = await submitAs(token4);
    const reference4 = submittedRef(ok4);
    assert.match(reference4, /^FP-\d{4}-\d{6}$/, 'a route failure never rejects an otherwise valid request');
    const record4 = dispatchOf(reference4);
    assert.equal(record4.destination.mode, 'google');
    assert.equal(record4.destination.address, FORMATTED, 'the destination is preserved for the retry');
    assert.equal(record4.distance.status, 'error', 'the failed calculation persists as error');
    assert.equal(record4.distance.error, 'route_unavailable');
    fakeMode('ok');

    const adminCookie = authCookie();
    assert.ok(adminCookie.includes('='), 'an admin auth cookie must be generated');
    const recordId4 = record4.id;
    const adminHeaders = { cookie: adminCookie };
    const detail4 = await get(`/wp-admin/admin.php?page=fp-quote&p=${recordId4}`, MOBILE_UA, adminHeaders);
    assert.equal(detail4.status, 200);
    assertContains(detail4.body, 'Distancia de despacho', 'the admin detail shows the internal distance section');
    assertContains(detail4.body, 'Error de cálculo (route_unavailable)', 'the error state is visible to sales');
    assertContains(detail4.body, FORMATTED, 'the preserved destination is visible to sales');
    assertContains(detail4.body, DEFAULT_ORIGIN, 'the Warehouse origin is visible to sales');
    assertContains(detail4.body, 'no es un precio de envío automático', 'the distance is explicitly not a shipping price');
    assertContains(detail4.body, 'Recalcular distancia', 'the staff retry action renders');
    const retryNonce = detail4.body.match(/name="fp_distance_nonce" value="([a-f0-9]{10})"/)?.[1];
    assert.ok(retryNonce, 'the retry form carries its nonce');

    const retry = await postForm(
      {
        action: 'fp_distance_retry',
        p: String(recordId4),
        fp_distance_nonce: retryNonce,
        _wp_http_referer: `/wp-admin/admin.php?page=fp-quote&p=${recordId4}`,
      },
      adminHeaders
    );
    assert.equal(retry.status, 302, 'the retry answers POST-redirect-GET');
    assert.ok((retry.headers.location || '').includes('fp_dist=ok'), 'the retry outcome is reported');
    const detailAfter = await get(`/wp-admin/admin.php?page=fp-quote&p=${recordId4}&fp_dist=ok`, MOBILE_UA, adminHeaders);
    assertContains(detailAfter.body, 'Calculada: 51,2 km', 'the recalculated distance renders for sales');
    assertContains(detailAfter.body, 'Distancia recalculada', 'the retry notice renders');
    assert.equal(dispatchOf(reference4).distance.status, 'ok', 'the retry stored the recalculated distance');
    assert.equal(dispatchOf(reference4).distance.meters, 51234);
    const list4 = await get('/wp-admin/admin.php?page=fp-quotes', MOBILE_UA, adminHeaders);
    assertContains(list4.body, '51,2 km', 'the Cotizaciones list shows the internal distance');

    const badRetry = await postForm(
      { action: 'fp_distance_retry', p: String(recordId4), fp_distance_nonce: 'deadbeefdeadbeefdeadbeefdeadbeef', _wp_http_referer: '/wp-admin/' },
      adminHeaders
    );
    assert.equal(badRetry.status, 403, 'a bad retry nonce must be rejected');
    const subUserId = wp(['eval', 'echo (int) get_user_by( "login", "fp_sinventas" )->ID;']).stdout;
    assert.ok(Number(subUserId) > 0, 'the capability-less user from section 15 must exist');
    const subCookie = authCookie(subUserId);
    const deniedRetry = await postForm(
      { action: 'fp_distance_retry', p: String(recordId4), fp_distance_nonce: retryNonce, _wp_http_referer: '/wp-admin/' },
      { cookie: subCookie }
    );
    assert.equal(deniedRetry.status, 403, 'the retry must deny users without the sales capability');

    /* 16.8 — Without provider credentials the request still persists with
       a pending distance (retryable once credentials exist). The nonce was
       taken from a page rendered while assistance existed (it stays valid
       for the session — the point is the provider-less degradation). */
    const token5 = await newSession();
    const assistedPage5 = await get('/cotizacion/', MOBILE_UA, cookieHeader(token5));
    fakeMode('off');
    const offlineSuggest = await postForm(
      {
        action: 'fp_address_suggest',
        fp_query: 'Av. Providencia 1234',
        fp_address_nonce: addressNonce(assistedPage5.body),
        _wp_http_referer: '/cotizacion/',
      },
      cookieHeader(token5)
    );
    payload = JSON.parse(offlineSuggest.body);
    assert.equal(payload.ok, true);
    assert.equal(payload.unavailable, true, 'the enhancement learns the assistance is unavailable');
    assert.deepEqual(payload.suggestions, []);
    const ok5 = await submitAs(token5, { fp_direccion: rural });
    const record5 = dispatchOf(submittedRef(ok5));
    assert.equal(record5.distance.status, 'pending', 'a provider-less calculation persists as pending');
    assert.equal(record5.distance.error, 'provider_unavailable');
    assert.equal(record5.destination.address, rural, 'the destination stays available for a later retry');
    fakeMode('ok');

    /* 16.9 — Origin selection stays a configuration decision: changing the
       stored Warehouse option changes the recorded origin. */
    wp(['option', 'update', 'fp_dispatch_origin', 'Bodega Santiago Centro, Chile']);
    const token6 = await newSession();
    const ok6 = await submitAs(token6, { fp_direccion: rural });
    const record6 = dispatchOf(submittedRef(ok6));
    assert.equal(record6.distance.origin, 'Bodega Santiago Centro, Chile', 'the recorded origin follows the stored option');

    /* 16.10 — Credentials: environment-supplied, never in source or the
       database. */
    const scanDir = (dir) =>
      readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const full = join(dir, entry.name);
        return entry.isDirectory() ? scanDir(full) : [full];
      });
    const scanned = [...scanDir(join(WORDPRESS_DIR, 'wp-content')), ...scanDir(HERE)].filter(
      (file) => /\.(php|js|mjs|json|css)$/.test(file) && !file.endsWith('check.mjs') /* the scanner carries its own pattern */
    );
    assert.ok(
      !scanned.some((file) => /AIza/.test(readFileSync(file, 'utf8'))),
      'no Google credential literal may live in source control'
    );
    const adapter = readFileSync(join(WORDPRESS_DIR, 'wp-content', 'plugins', 'freeplast-catalog-quotes', 'includes', 'class-address.php'), 'utf8');
    assertContains(adapter, 'FREEPLAST_GOOGLE_API_KEY', 'the credential comes from the environment');
    assertContains(adapter, 'getenv', 'the credential is read via getenv (never an option)');
    assert.equal(wp(['eval', 'echo get_option( "fp_google_api_key", "none" );']).stdout, 'none', 'no credential option exists');

    section('Google-assisted Delivery Address + Dispatch Distance (issue #11)', [
      'Google assistance renders only inside the dispatch-conditional address block and only while provider credentials are configured — the credential never reaches the page',
      'The customer searches (plain POST or the JSON enhancement), selects a Chilean suggestion, reviews the formatted destination and confirms it explicitly; Cambiar restores search + manual entry',
      'The manual Dirección de despacho stays the always-available fallback (rural/unrecognized); a confirmed destination stands in for it on submission',
      'Confirmed destination data (mode, formatted address, place id, coordinates, provider, confirmation time) is stored on the fp_quote record; without dispatch nothing address-related is stored',
      'Driving distance is calculated from the configured Warehouse (provisional Camino El Arrayán 52) after durable persistence; a Routes failure never rejects the request — it persists an error state with the destination preserved',
      'Dispatch Distance is an internal sales fact: the capability-protected list/detail show km, origin and state with an explicit not-a-shipping-price note; nothing distance-like reaches the customer',
      'Authorized staff retry the calculation through a nonce + capability-guarded operation (bad nonce and capability-less users are denied); provider-less requests persist as pending',
      'Credentials are environment-supplied (FREEPLAST_GOOGLE_API_KEY via getenv), absent from source control and the database; the provider is replaced at the freeplast_cq_google_client boundary in every check',
      'Origin selection remains configuration: the stored fp_dispatch_origin option (seeded by migration 7) defines the recorded origin',
    ]);
  } finally {
    rmSync(join(muDir, 'fp-test-fake-google.php'), { force: true });
    wp(['option', 'delete', 'fp_fake_google']);
    wp(['option', 'update', 'fp_dispatch_origin', DEFAULT_ORIGIN]);
  }
});

/* ─── 19-21. Hardened journey (issue #13) ─────────────────────────── */

test('the complete one- and multi-Product journey passes with JavaScript enabled and disabled', { timeout: 180_000 }, async () => {
  const noticeOf = (res) => new URL(res.headers.location || '', SITE_URL).searchParams.get('fpcq_notice');
  const submittedRef = (res) => new URL(res.headers.location || '', SITE_URL).searchParams.get('fpcq_submitted');
  const quoteCount = () => Number(wp(['post', 'list', '--post_type=fp_quote', '--post_status=private', '--format=count']).stdout || '0');
  const before = quoteCount();

  /* 19.1 — The one-Product journey with JavaScript disabled: every step
     is a plain server round-trip (Home → Tienda → product → add →
     Cotización → submit → confirmation). */
  const home = await get('/', MOBILE_UA);
  assert.equal(home.status, 200, 'the journey starts on Home');
  const tienda = await get('/tienda/', MOBILE_UA);
  assert.equal(tienda.status, 200, 'the journey reaches Tienda');
  assertContains(tienda.body, `/producto/caja-cosechera-3-4/`, 'Tienda links the product page the journey continues on');
  const product = await get(PRODUCT_URL, MOBILE_UA);
  assert.equal(product.status, 200, 'the product page renders');
  const addNonce = product.body.match(/name="fp_basket_nonce" value="([a-f0-9]{10})"/)?.[1];
  assert.ok(addNonce, 'the product chooser carries its nonce');
  const plainAdd = await postForm(
    { action: 'fp_basket_add', fp_product: 'fp-caja-cosechera-3-4', fp_quantity: '6', fp_basket_nonce: addNonce, _wp_http_referer: PRODUCT_URL },
    {}
  );
  assert.equal(noticeOf(plainAdd), 'added', 'the no-JS add answers POST-redirect-GET');
  const one = plainAdd.setCookies[0].match(/fpcq_basket=([0-9a-f]{64})/)?.[1];
  assert.ok(one, 'the no-JS journey owns its session');
  const cotOne = await get('/cotizacion/', MOBILE_UA, { cookie: `fpcq_basket=${one}` });
  assertContains(cotOne.body, 'Cotización (1)', 'the single line renders on the sole submission surface');
  const oneCreds = requestCredentials(cotOne.body);
  assert.ok(oneCreds.nonce && oneCreds.token, 'the form carries its nonce and idempotency token');
  assert.equal(humanPaced(one, oneCreds.token), 'ok', 'the journey submission must be paced like a human fill');
  const oneSubmit = await postForm(
    { action: 'fp_request_submit', ...VALID_REQUEST_FIELDS, fp_request_nonce: oneCreds.nonce, fp_request_token: oneCreds.token, _wp_http_referer: '/cotizacion/' },
    { cookie: `fpcq_basket=${one}` }
  );
  const oneRef = submittedRef(oneSubmit);
  assert.match(oneRef, /^FP-\d{4}-\d{6}$/, 'the no-JS journey submits successfully');
  const oneConfirm = await get(`/cotizacion/?fpcq_submitted=${oneRef}`, MOBILE_UA, { cookie: `fpcq_basket=${one}` });
  assertContains(oneConfirm.body, oneRef, 'the no-JS journey ends on the confirmation');
  assertContains(oneConfirm.body, 'Cotización (0)', 'the basket cleared after durable persistence');

  /* 19.2 — The multi-Product journey with the JavaScript enhancement: a
     card chooser adds the first line, an optioned product the second,
     one line updates, and the JSON state stays authoritative. */
  const tiendaCards = await get('/tienda/', MOBILE_UA);
  const cardForm = tiendaCards.body.slice(tiendaCards.body.indexOf('fp-caja-cosechera-3-4'));
  const cardNonce = cardForm.match(/name="fp_basket_nonce" value="([a-f0-9]{10})"/)?.[1];
  assert.ok(cardNonce, 'a Tienda card carries its own chooser nonce');
  const enhancedAdd = await postForm(
    { action: 'fp_basket_add', fp_product: 'fp-caja-cosechera-3-4', fp_quantity: '2', fp_basket_nonce: cardNonce, _wp_http_referer: '/tienda/', fp_enhanced: '1' },
    {}
  );
  const addPayload = JSON.parse(enhancedAdd.body);
  assert.equal(addPayload.ok, true, 'the JSON enhancement accepts the card add');
  assert.equal(addPayload.count, 1, 'the JSON state counts the first line');
  const multi = enhancedAdd.setCookies[0].match(/fpcq_basket=([0-9a-f]{64})/)?.[1];
  assert.ok(multi, 'the JS journey owns its session');

  const colorPage = await get('/producto/caja-universal-cerrada-color/', MOBILE_UA);
  const colorNonce = colorPage.body.match(/name="fp_basket_nonce" value="([a-f0-9]{10})"/)?.[1];
  const colorAdd = await postForm(
    { action: 'fp_basket_add', fp_product: 'fp-caja-universal-cerrada-color', fp_option: 'azul', fp_quantity: '20', fp_basket_nonce: colorNonce, _wp_http_referer: '/producto/caja-universal-cerrada-color/', fp_enhanced: '1' },
    { cookie: `fpcq_basket=${multi}` }
  );
  const colorPayload = JSON.parse(colorAdd.body);
  assert.equal(colorPayload.ok, true, 'the optioned product joins through the enhancement');
  assert.equal(colorPayload.count, 2, 'different options stay separate lines');
  assertContains(colorPayload.mini, 'Caja Cosechera 3/4', 'the mirrored mini basket lists the plain line');
  assertContains(colorPayload.mini, 'Azul', 'the mirrored mini basket lists the option line');

  const colorUpdate = await postForm(
    {
      action: 'fp_basket_update',
      fp_product: 'fp-caja-universal-cerrada-color',
      fp_option: 'azul',
      fp_quantity: '25',
      fp_basket_nonce: formNonce((await get('/cotizacion/', MOBILE_UA, { cookie: `fpcq_basket=${multi}` })).body, 'fp_basket_update'),
      _wp_http_referer: '/cotizacion/',
      fp_enhanced: '1',
    },
    { cookie: `fpcq_basket=${multi}` }
  );
  const updatePayload = JSON.parse(colorUpdate.body);
  assert.equal(updatePayload.ok, true, 'a line updates through the JSON enhancement');
  assert.equal(updatePayload.count, 2, 'an update never changes the distinct-line count');
  assertContains(updatePayload.view, '25 unidades', 'the re-rendered view carries the updated quantity');

  const cotMulti = await get('/cotizacion/', MOBILE_UA, { cookie: `fpcq_basket=${multi}` });
  assertContains(cotMulti.body, 'Cotización (2)', 'both lines render before submission');
  const multiCreds = requestCredentials(cotMulti.body);
  assert.equal(humanPaced(multi, multiCreds.token), 'ok', 'the multi-Product submission must be paced like a human fill');
  const multiSubmit = await postForm(
    { action: 'fp_request_submit', ...VALID_REQUEST_FIELDS, fp_mensaje: 'Dos productos en un solo envío.', fp_request_nonce: multiCreds.nonce, fp_request_token: multiCreds.token, _wp_http_referer: '/cotizacion/' },
    { cookie: `fpcq_basket=${multi}` }
  );
  const multiRef = submittedRef(multiSubmit);
  assert.match(multiRef, /^FP-\d{4}-\d{6}$/, 'the JS-enabled multi-Product journey submits successfully');
  assert.notEqual(multiRef, oneRef, 'each journey receives its own Request Reference');
  const multiRecord = JSON.parse(
    wp([
      'eval',
      `$posts = get_posts( array( "post_type" => "fp_quote", "post_status" => "private", "posts_per_page" => 1, "no_found_rows" => true, "suppress_filters" => true, "meta_key" => "_fpq_reference", "meta_value" => "${multiRef}" ) );` +
        'echo wp_json_encode( array_map( static function ( $i ) { return array( $i["source_id"], $i["option_id"], $i["quantity"] ); }, json_decode( (string) get_post_meta( $posts[0]->ID, "_fpq_items", true ), true ) ) );',
    ]).stdout || '[]'
  );
  assert.deepEqual(
    [...multiRecord].sort(),
    [['fp-caja-cosechera-3-4', '', 2], ['fp-caja-universal-cerrada-color', 'azul', 25]],
    'the multi-Product record persists exactly the journey lines'
  );
  assert.equal(quoteCount(), before + 2, 'the two journeys persist exactly two records');

  section('Complete journey, JavaScript on and off (issue #13)', [
    'The complete one-Product journey passes with JavaScript disabled: every step is a plain server round-trip (Home → Tienda → product page → quantity chooser add → /cotizacion/ → submit → confirmation with Request Reference and cleared basket)',
    'The complete multi-Product journey passes with the JavaScript enhancement: a Tienda card chooser and an optioned Color product add through the JSON state (count, mini basket, re-rendered view), a line updates, and both lines submit as one request',
  ]);
});

test('keyboard operation, motion safety, control sizing and contrast meet mechanical expectations', { timeout: 120_000 }, async () => {
  const css = readFileSync(join(WORDPRESS_DIR, 'wp-content', 'themes', 'freeplast', 'style.css'), 'utf8');

  /* 20.1 — Visible focus and reduced motion are CSS contract. */
  const focusRule = css.match(/:focus-visible\s*\{([^}]*)\}/)?.[1] || '';
  assert.ok(/outline:\s*2px/.test(focusRule), 'a global :focus-visible rule must draw a visible outline');
  const reducedAt = css.indexOf('@media (prefers-reduced-motion: reduce)');
  assert.ok(reducedAt !== -1, 'a prefers-reduced-motion media query must exist');
  const reducedBlock = css.slice(reducedAt, css.indexOf('}', css.indexOf('animation-iteration-count', reducedAt)) + 1);
  assert.ok(/transition-duration:\s*0\.01ms/.test(reducedBlock), 'nonessential transitions must collapse under reduced motion');
  assert.ok(/animation-duration:\s*0\.01ms/.test(reducedBlock), 'nonessential animations must collapse under reduced motion');
  assert.ok(/animation-iteration-count:\s*1/.test(reducedBlock), 'iterative animations must stop under reduced motion');

  /* 20.2 — Responsive behavior is mechanically observed at 375, 412,
     768, 1024 and 1440 px: one mobile-first document, adaptation only
     through min-width media queries covering those widths. */
  const breakpoints = [...css.matchAll(/@media \(min-width: (\d+)px\)/g)].map((m) => Number(m[1])).sort((a, b) => a - b);
  assert.ok(breakpoints.includes(768), 'the tablet adaptation (768px) must be a declared breakpoint');
  assert.ok(breakpoints.includes(1024), 'the desktop adaptation (1024px) must be a declared breakpoint');
  const WIDTHS = [375, 412, 768, 1024, 1440];
  for (const width of WIDTHS) {
    for (const path of ['/', '/tienda/', '/cotizacion/']) {
      const res = await get(path, `Mozilla/5.0 (Freeplast check; ${width}px) AppleWebKit/537.36`);
      assert.equal(res.status, 200, `${path} must answer at ${width}px`);
      assertContains(res.body, 'name="viewport"', `${path} must carry the viewport meta at ${width}px`);
    }
  }
  const base = await get('/', 'Mozilla/5.0 (Freeplast check; 375px)');
  for (const width of [412, 768, 1024, 1440]) {
    const other = await get('/', `Mozilla/5.0 (Freeplast check; ${width}px)`);
    assert.equal(other.body, base.body, 'the served document is identical at every width (adaptation is CSS-only)');
  }

  /* 20.3 — Keyboard semantics on the submission surface: native controls
     only, logical DOM (tab) order, focusable linked error summary,
     labelled fields, disclosure widgets. */
  const page = await get(PRODUCT_URL, MOBILE_UA);
  const addNonce = page.body.match(/name="fp_basket_nonce" value="([a-f0-9]{10})"/)?.[1];
  const seeded = await postForm(
    { action: 'fp_basket_add', fp_product: 'fp-caja-cosechera-3-4', fp_quantity: '1', fp_basket_nonce: addNonce, _wp_http_referer: PRODUCT_URL },
    {}
  );
  const token = seeded.setCookies[0].match(/fpcq_basket=([0-9a-f]{64})/)?.[1];
  const headers = { cookie: `fpcq_basket=${token}` };
  const cot = await get('/cotizacion/', MOBILE_UA, headers);
  const body = cot.body;
  assert.ok(!/onclick=/.test(body), 'the submission surface uses native controls, not inline handlers');
  const order = ['Navegación principal', 'fpcq-basketview', 'fpcq-request-form', 'fp-mensaje', 'Enviar solicitud'];
  let cursor = -1;
  for (const marker of order) {
    const at = body.indexOf(marker);
    assert.ok(at > cursor, `the DOM (tab) order must be logical: ${marker} follows the previous section`);
    cursor = at;
  }
  assert.ok(countMatches(body, '<details class="fpcq-basket">') === 1, 'the mini basket is a native disclosure widget');
  assert.ok(/<summary[^>]*class="[^"]*fpcq-basket-toggle/.test(body), 'the mini basket opens through its summary');
  assertContains(body, 'aria-expanded="false" aria-controls="fp-menu"', 'the mobile sheet trigger announces its control');
  assertContains(body, 'id="fp-menu"', 'the announced control exists');

  // The honeypot is programmatically hidden and keyboard-excluded.
  const honeypot = body.match(/<div class="fpcq-hp"[^>]*>/)?.[0] || '';
  assert.ok(/aria-hidden="true"/.test(honeypot), 'the honeypot is hidden from assistive technology');
  const honeypotAt = body.indexOf('fpcq-hp');
  assert.ok(/tabindex="-1"/.test(body.slice(honeypotAt, honeypotAt + 400)), 'the honeypot is removed from the tab order');

  // Every visible form control is labelled; the error summary is focusable
  // and its links point at real field anchors.
  const form = pluginSection(body, 'class="fpcq-request-form"', 'the request form must render');
  const inputs = [...form.matchAll(/<input [^>]*>/g)].map((m) => m[0]);
  for (const input of inputs) {
    const type = input.match(/type="([^"]+)"/)?.[1];
    if (type === 'hidden') continue;
    const id = input.match(/id="([^"]+)"/)?.[1];
    const selfLabelled = /aria-label=/.test(input) || type === 'radio'; /* radios sit inside wrapping labels */
    assert.ok(selfLabelled || (id && new RegExp(`<label[^>]*for="${id}"`).test(form)), `every visible control must be labelled (${id || 'unlabelled input'})`);
  }
  // Radios sit inside wrapping labels with their text.
  const radios = [...form.matchAll(/<label class="fpcq-choice"><input[^>]*>/g)].length;
  assert.ok(radios >= 2, 'the Con Despacho radios are wrapped in labels');

  // An invalid submission re-renders with the focusable linked summary.
  const creds = requestCredentials(body);
  const invalid = await postForm(
    { action: 'fp_request_submit', fp_nombre: '', fp_telefono: '', fp_email: '', fp_empresa: '', fp_rut: '', fp_giro: '', fp_despacho: '', fp_direccion: '', fp_mensaje: '', fp_request_nonce: creds.nonce, fp_request_token: creds.token, _wp_http_referer: '/cotizacion/' },
    headers
  );
  assert.ok((invalid.headers.location || '').endsWith('#fpcq-form-errors'), 'the invalid redirect must focus the error summary');
  const back = await get('/cotizacion/', MOBILE_UA, headers);
  const summaryBlock = pluginSection(back.body, 'id="fpcq-form-errors"', 'the retained page must carry the error summary');
  assertContains(summaryBlock, 'tabindex="-1"', 'the error summary must be focusable (fragment focus)');
  assertContains(summaryBlock, 'role="alert"', 'the error summary must announce itself');
  for (const key of ['nombre', 'telefono', 'email', 'empresa', 'rut', 'giro', 'despacho']) {
    assertContains(summaryBlock, `href="#fp-${key}"`, `the summary must link the ${key} error`);
    assert.ok(back.body.includes(`id="fp-${key}"`), `the ${key} link must target a real field`);
  }

  /* 20.4 — Target size: interactive controls declare a minimum target of
     at least 24×24 CSS px (WCAG 2.5.8); the primary ones declare 44px.
     (Comments stripped; the selector regex is brace-safe, so rules inside
     media queries are parsed too.) */
  const flatCss = css.replace(/\/\*[\s\S]*?\*\//g, '');
  const rules = [...flatCss.matchAll(/([^{}]+)\{([^{}]*)\}/g)].map((m) => ({
    selectors: m[1].split(',').map((s) => s.trim()),
    body: m[2],
  }));
  const ruleOf = (name) => rules.find((r) => r.selectors.includes(name))?.body || '';
  /** The widest min-height declared by the rules that list the selector exactly. */
  const exactMinHeight = (selector) =>
    Math.max(
      0,
      ...rules
        .filter((r) => r.selectors.includes(selector))
        .map((r) => Number(r.body.match(/min-height:\s*(\d+)px/)?.[1] || 0))
    );
  /** The widest min-height declared by the rules that mention the selector
      at all — compound selectors and media-query variants included. */
  const mentionedMinHeight = (selector) =>
    Math.max(
      0,
      ...rules
        .filter((r) => r.selectors.some((s) => s.includes(selector)))
        .map((r) => Number(r.body.match(/min-height:\s*(\d+)px/)?.[1] || 0))
    );
  for (const control of ['.fp-btn', '.fp-btn-sm', '.fpcq-request-submit', '.fpcq-add-submit', '.fpcq-edit-submit', '.fpcq-remove-submit', '.fpcq-choice', '.fpcq-add-option', '.fpcq-filters a']) {
    const px = mentionedMinHeight(control);
    assert.ok(px >= 24, `${control} must declare a minimum target height of at least 24px (found ${px}px)`);
  }
  assert.ok(exactMinHeight('.fp-btn') >= 44, 'primary controls must keep the 44px target');
  const burgerHeight = Number(ruleOf('.fp-burger').match(/height:\s*(\d+)px/)?.[1] || 0);
  assert.ok(burgerHeight >= 24, 'the burger trigger must meet the minimum target size');

  /* 20.5 — Contrast: the frozen palette pairs used by controls and text
     meet WCAG AA (4.5:1 text, 3:1 large headings and the focus outline). */
  const root = css.slice(css.indexOf(':root'), css.indexOf('}', css.indexOf(':root')));
  const colorOf = (name) => root.match(new RegExp(`--${name}:\\s*(#[0-9a-fA-F]{6})`))?.[1];
  const luminance = (hex) => {
    const channels = [0, 2, 4]
      .map((i) => parseInt(hex.slice(i + 1, i + 3), 16) / 255)
      .map((c) => (c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4)));
    return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
  };
  const contrast = (a, b) => {
    const [l1, l2] = [luminance(a), luminance(b)].sort((x, y) => y - x);
    return (l1 + 0.05) / (l2 + 0.05);
  };
  const paper = colorOf('fp-paper');
  const tint = colorOf('fp-tint');
  const pairs = [
    ['link and control blue on paper', colorOf('fp-blue'), paper, 4.5],
    ['button text on the blue control', '#ffffff', colorOf('fp-blue'), 4.5],
    ['headings on paper', colorOf('fp-blue-heading'), paper, 3],
    ['body text on paper', colorOf('fp-text'), paper, 4.5],
    ['body text on the tint surface', colorOf('fp-text'), tint, 4.5],
    ['muted text on paper', colorOf('fp-muted'), paper, 4.5],
    ['green accent text on paper', colorOf('fp-green'), paper, 4.5],
    ['focus outline against paper', colorOf('fp-blue'), paper, 3],
  ];
  for (const [name, fg, bg, minimum] of pairs) {
    assert.ok(fg && bg, `the ${name} pair must resolve from the frozen tokens`);
    const ratio = contrast(fg, bg);
    assert.ok(ratio >= minimum, `${name} must meet WCAG AA (${fg} on ${bg}: ${ratio.toFixed(2)}:1 < ${minimum}:1)`);
  }

  section('Accessibility and responsive hardening (issue #13)', [
    'Keyboard: every interactive element is a native control in logical DOM order (header navigation → basket view → request form → submit), the mini basket and quantity choosers are <details> disclosures, and the mobile sheet trigger announces aria-expanded/aria-controls with Escape support',
    'Focus: a global :focus-visible outline is declared; the linked error summary is focusable (tabindex=-1 + #fragment redirect), announced (role=alert) and every summary link targets a real labelled field',
    'Motion: prefers-reduced-motion collapses all transitions/animations (0.01ms, single iteration) and disables smooth scrolling',
    'Responsive: one identical mobile-first document serves 375, 412, 768, 1024 and 1440 px with viewport meta everywhere; adaptation happens only through the declared 768/1024 min-width breakpoints — pixel rendering remains human Gate 3',
    'Target size and contrast: every interactive control declares ≥24px targets (primary ones 44px, burger included) and the frozen palette pairs meet WCAG AA (4.5:1 text, 3:1 headings/focus outline)',
  ]);
});

test('the honeypot, minimum completion time and bounded throttling reject abuse without blocking ordinary retries', { timeout: 180_000 }, async () => {
  const noticeOf = (res) => new URL(res.headers.location || '', SITE_URL).searchParams.get('fpcq_notice');
  const submittedRef = (res) => new URL(res.headers.location || '', SITE_URL).searchParams.get('fpcq_submitted');
  const quoteCount = () => Number(wp(['post', 'list', '--post_type=fp_quote', '--post_status=private', '--format=count']).stdout || '0');
  const headers = (token) => ({ cookie: `fpcq_basket=${token}` });
  const seedSession = async () => {
    const page = await get(PRODUCT_URL, MOBILE_UA);
    const nonce = page.body.match(/name="fp_basket_nonce" value="([a-f0-9]{10})"/)?.[1];
    const res = await postForm(
      { action: 'fp_basket_add', fp_product: 'fp-caja-cosechera-3-4', fp_quantity: '4', fp_basket_nonce: nonce, _wp_http_referer: PRODUCT_URL },
      {}
    );
    const token = res.setCookies[0].match(/fpcq_basket=([0-9a-f]{64})/)?.[1];
    assert.ok(token, 'the abuse section needs its guest session');
    return token;
  };
  const before = quoteCount();

  /* 21.1 — Honeypot: a filled decoy field is rejected before anything
     else, recoverably, and persists nothing. */
  const honeypotToken = await seedSession();
  const honeypotPage = await get('/cotizacion/', MOBILE_UA, headers(honeypotToken));
  const hpCreds = requestCredentials(honeypotPage.body);
  assert.ok(hpCreds.nonce && hpCreds.token, 'the honeypot flow carries its form credentials');
  assertContains(honeypotPage.body, 'name="fp_referencia"', 'the honeypot field renders on the form');
  const spam = await postForm(
    { action: 'fp_request_submit', ...VALID_REQUEST_FIELDS, fp_referencia: 'http://spam.example/offer', fp_request_nonce: hpCreds.nonce, fp_request_token: hpCreds.token, _wp_http_referer: '/cotizacion/' },
    headers(honeypotToken)
  );
  assert.equal(noticeOf(spam), 'spam', 'a filled honeypot must be rejected as spam');
  assert.equal(quoteCount(), before, 'the honeypot rejection persists nothing');
  const spamBack = await get('/cotizacion/', MOBILE_UA, headers(honeypotToken));
  assertContains(spamBack.body, 'Cotización (1)', 'the honeypot rejection retains the basket');

  /* 21.2 — Minimum completion time in real time: a bot-speed submission
     is rejected recoverably; an ordinary retry moments later succeeds. */
  const fastToken = await seedSession();
  const fastPage = await get('/cotizacion/', MOBILE_UA, headers(fastToken));
  const fastCreds = requestCredentials(fastPage.body);
  const tooFast = await postForm(
    { action: 'fp_request_submit', ...VALID_REQUEST_FIELDS, fp_request_nonce: fastCreds.nonce, fp_request_token: fastCreds.token, _wp_http_referer: '/cotizacion/' },
    headers(fastToken)
  );
  assert.equal(noticeOf(tooFast), 'too_fast', 'a submission faster than a human fill must be rejected');
  assert.ok((tooFast.headers.location || '').includes('#fpcq-form-errors'), 'the rejection must focus the summary');
  assert.equal(quoteCount(), before, 'the too-fast rejection persists nothing');
  const fastBack = await get('/cotizacion/', MOBILE_UA, headers(fastToken));
  assertContains(fastBack.body, 'Tómate un momento', 'the rejection explains itself');
  assertContains(fastBack.body, 'value="maria@acme.cl"', 'the entered values stay retained');
  assertContains(fastBack.body, 'Cotización (1)', 'the basket stays retained');

  await new Promise((resolve) => setTimeout(resolve, 2400)); /* the plausible minimum (2s) plus margin */
  const human = await postForm(
    { action: 'fp_request_submit', ...VALID_REQUEST_FIELDS, fp_request_nonce: fastCreds.nonce, fp_request_token: fastCreds.token, _wp_http_referer: '/cotizacion/' },
    headers(fastToken)
  );
  assert.match(submittedRef(human), /^FP-\d{4}-\d{6}$/, 'the ordinary retry after the minimum time succeeds');

  /* 21.3 — Bounded throttling: the cap of persisted requests per session
     rejects the next one recoverably; only successes count, so invalid
     attempts and idempotent replays never block an ordinary retry. */
  const floodToken = await seedSession();
  const floodProductPage = await get(PRODUCT_URL, MOBILE_UA);
  const floodAddNonce = floodProductPage.body.match(/name="fp_basket_nonce" value="([a-f0-9]{10})"/)?.[1];
  assert.ok(floodAddNonce, 'the throttle loop needs its chooser nonce');
  const CAP = 5;
  for (let i = 0; i < CAP; i++) {
    const readd = await postForm(
      { action: 'fp_basket_add', fp_product: 'fp-caja-cosechera-3-4', fp_quantity: '4', fp_basket_nonce: floodAddNonce, _wp_http_referer: PRODUCT_URL },
      headers(floodToken)
    );
    assert.equal(noticeOf(readd), 'added', `throttle cycle ${i + 1} rebuilds its basket`);
    const page = await get('/cotizacion/', MOBILE_UA, headers(floodToken));
    const creds = requestCredentials(page.body);
    assert.equal(humanPaced(floodToken, creds.token), 'ok', `throttling submission ${i + 1} must be paced like a human fill`);
    const res = await postForm(
      { action: 'fp_request_submit', ...VALID_REQUEST_FIELDS, fp_request_nonce: creds.nonce, fp_request_token: creds.token, _wp_http_referer: '/cotizacion/' },
      headers(floodToken)
    );
    assert.match(submittedRef(res), /^FP-\d{4}-\d{6}$/, `submission ${i + 1} of the cap persists`);
  }
  const capped = quoteCount();
  const overCapAdd = await postForm(
    { action: 'fp_basket_add', fp_product: 'fp-caja-cosechera-3-4', fp_quantity: '4', fp_basket_nonce: floodAddNonce, _wp_http_referer: PRODUCT_URL },
    headers(floodToken)
  );
  assert.equal(noticeOf(overCapAdd), 'added', 'the over-cap attempt rebuilds its basket first');
  const floodPage = await get('/cotizacion/', MOBILE_UA, headers(floodToken));
  const floodCreds = requestCredentials(floodPage.body);
  assert.equal(humanPaced(floodToken, floodCreds.token), 'ok', 'the over-cap attempt must still be paced like a human fill');
  const throttled = await postForm(
    { action: 'fp_request_submit', ...VALID_REQUEST_FIELDS, fp_request_nonce: floodCreds.nonce, fp_request_token: floodCreds.token, _wp_http_referer: '/cotizacion/' },
    headers(floodToken)
  );
  assert.equal(noticeOf(throttled), 'throttled', 'a session past its persisted-request cap must be throttled');
  assert.ok((throttled.headers.location || '').includes('#fpcq-form-errors'), 'the throttle rejection must focus the summary');
  assert.equal(quoteCount(), capped, 'the throttled attempt persists nothing');
  const throttledBack = await get('/cotizacion/', MOBILE_UA, headers(floodToken));
  assertContains(throttledBack.body, 'varias solicitudes en poco tiempo', 'the throttle rejection explains itself');
  assertContains(throttledBack.body, 'Cotización (1)', 'the throttled attempt retains the recoverable basket state');

  /* 21.4 — Rate keys hold no raw PII and stay bounded: one expiring
     counter keyed by the opaque session hash only. */
  const rateKeys = wp([
    'eval',
    'global $wpdb; echo implode("\n", $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE \'\\_transient\\_fpcq\\_rate\\_%\'" ) );',
  ]).stdout.split('\n').filter(Boolean);
  assert.ok(rateKeys.length >= 1, 'the throttle counters live in expiring transients');
  for (const key of rateKeys) {
    assert.match(key, /^_transient_fpcq_rate_[0-9a-f]{64}$/, `rate keys are opaque session hashes, never raw PII (${key})`);
  }
  const optionDump = wp(['eval', 'global $wpdb; echo $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE \'\\_transient\\_fpcq\\_%\'" );']).stdout;
  assert.ok(Number(optionDump) < 200, 'the transient namespace stays bounded');

  section('Abuse resistance (issue #13)', [
    'Honeypot: the off-screen decoy field (aria-hidden, tabindex=-1, off-screen inline styles) rejects automated submissions before any mutation, recoverably, with the basket retained',
    'Minimum completion time: each form instance records its server-side render time with its idempotency token; a bot-speed submission is rejected recoverably (values + basket retained, summary focused) and the ordinary retry moments later succeeds',
    'Bounded throttling: a rolling cap of 5 persisted requests per anonymous session per hour rejects the next one recoverably; only durable persistences count, so invalid attempts, idempotent replays and failed persistences never block an ordinary retry',
    'Rate keys hold no raw IP or email: one expiring counter per opaque session hash, bounded by the transient namespace',
  ]);
});

/* ─── 22-23. Guard matrix, dependency failures, maintenance, lifecycle, standards (issue #13) ── */

test('every guard rejects invalid input without partial mutation, and dependency failures never produce false success', { timeout: 120_000 }, async () => {
  const noticeOf = (res) => new URL(res.headers.location || '', SITE_URL).searchParams.get('fpcq_notice');
  const quoteCount = () => Number(wp(['post', 'list', '--post_type=fp_quote', '--post_status=private', '--format=count']).stdout || '0');
  const adminCookie = authCookie();
  const adminHeaders = { cookie: adminCookie };
  const before = quoteCount();

  /* 22.1 — Invalid record identity on guarded admin mutations: a valid
     session, capability and nonce still cannot mutate a missing or
     foreign record. The retry/resend nonces are action-wide (not
     per-record), so a legitimately rendered one still cannot unlock a
     missing record. */
  const dispatchId = Number(
    wp([
      'eval',
      'echo (int) get_posts( array( "post_type" => "fp_quote", "post_status" => "private", "posts_per_page" => 1, "fields" => "ids", "no_found_rows" => true, "suppress_filters" => true, "meta_key" => "_fpq_destination" ) )[0];',
    ]).stdout || 0
  );
  assert.ok(dispatchId > 0, 'a dispatch record is needed to render the retry/resend forms');
  wp(['eval', `update_post_meta( ${dispatchId}, "_fpq_notifications", Freeplast_CQ_Notifications::initial_state_json() );`]); /* pending channels render the resend form */
  const seededDetail = await get(`/wp-admin/admin.php?page=fp-quote&p=${dispatchId}`, MOBILE_UA, adminHeaders);
  assert.equal(seededDetail.status, 200, 'the detail must render for the nonce extraction');
  const distanceNonce = seededDetail.body.match(/name="fp_distance_nonce" value="([a-f0-9]{10})"/)?.[1];
  assert.ok(distanceNonce, 'a legitimately rendered retry nonce is needed');
  const resendNonce = seededDetail.body.match(/name="fp_notify_nonce" value="([a-f0-9]{10})"/)?.[1];
  assert.ok(resendNonce, 'a legitimately rendered resend nonce is needed');
  const pageId = Number(wp(['eval', 'echo (int) get_option( "fp_shell_pages", array() )["nosotros"] ?? 0;']).stdout || 0);
  assert.ok(pageId > 0, 'a foreign record id is needed for the guard matrix');
  const modifiedBefore = wp(['eval', `echo get_post( ${pageId} )->post_modified;`]).stdout;

  const missingDistance = await postForm(
    { action: 'fp_distance_retry', p: '999999', fp_distance_nonce: distanceNonce, _wp_http_referer: '/wp-admin/' },
    adminHeaders
  );
  assert.equal(missingDistance.status, 404, 'a distance retry against a missing record must 404');
  const foreignDistance = await postForm(
    { action: 'fp_distance_retry', p: String(pageId), fp_distance_nonce: distanceNonce, _wp_http_referer: '/wp-admin/' },
    adminHeaders
  );
  assert.equal(foreignDistance.status, 404, 'a distance retry against a foreign record type must 404');
  const missingResend = await postForm(
    { action: 'fp_notify_resend', fp_quote: '999999', fp_channel: 'sales', fp_notify_nonce: resendNonce, _wp_http_referer: '/wp-admin/' },
    adminHeaders
  );
  assert.equal(missingResend.status, 404, 'a notification resend against a missing record must 404');
  const foreignStatus = await postForm(
    { action: 'fp_quote_set_status', p: String(pageId), fp_status: 'won', fp_status_nonce: 'any-nonce-cannot-reach-the-record-check', _wp_http_referer: '/wp-admin/' },
    adminHeaders
  );
  assert.equal(foreignStatus.status, 404, 'a status transition against a foreign record type must 404');
  assert.equal(wp(['eval', `echo get_post( ${pageId} )->post_modified;`]).stdout, modifiedBefore, 'guard failures never touch the foreign record');

  /* 22.2 — Session/database failure mid-journey: losing the basket
     session never produces a false success or a partial record. */
  const product = await get(PRODUCT_URL, MOBILE_UA);
  const addNonce = product.body.match(/name="fp_basket_nonce" value="([a-f0-9]{10})"/)?.[1];
  const seeded = await postForm(
    { action: 'fp_basket_add', fp_product: 'fp-caja-cosechera-3-4', fp_quantity: '8', fp_basket_nonce: addNonce, _wp_http_referer: PRODUCT_URL },
    {}
  );
  const token = seeded.setCookies[0].match(/fpcq_basket=([0-9a-f]{64})/)?.[1];
  const headers = { cookie: `fpcq_basket=${token}` };
  const cot = await get('/cotizacion/', MOBILE_UA, headers);
  const creds = requestCredentials(cot.body);
  assert.ok(creds.nonce && creds.token, 'the mid-journey form carries its credentials');
  assert.equal(humanPaced(token, creds.token), 'ok', 'the mid-journey submission must be paced like a human fill');
  /* Simulate the session store losing the row (database failure). */
  wp(['eval', `global $wpdb; $wpdb->delete( "{$wpdb->prefix}basket_sessions", array( "session_hash" => hash( "sha256", "${token}" ) ) );`]);
  const orphaned = await postForm(
    { action: 'fp_request_submit', ...VALID_REQUEST_FIELDS, fp_request_nonce: creds.nonce, fp_request_token: creds.token, _wp_http_referer: '/cotizacion/' },
    headers
  );
  assert.equal(noticeOf(orphaned), 'session', 'a lost session must fail the session guard, never claim success');
  assert.ok((orphaned.headers.location || '').indexOf('fpcq_submitted') === -1, 'no confirmation may be shown');
  assert.equal(quoteCount(), before, 'the lost-session submission persists nothing');
  assert.ok(
    orphaned.setCookies.some((cookie) => /fpcq_basket=(deleted;|;)/.test(cookie)),
    'the dead cookie is cleared so a retry starts fresh'
  );

  /* 22.3 — Public-surface hygiene: no commerce or account machinery ever
     renders on the public journey. */
  for (const path of ['/', '/tienda/', PRODUCT_URL, '/cotizacion/', '/nosotros/', '/contacto/']) {
    const res = await get(path, MOBILE_UA);
    for (const marker of ['woocommerce', 'wp-block-woocommerce', 'mi-cuenta', 'Mi cuenta', 'Finalizar compra', 'carrito']) {
      assertAbsent(res.body, marker, `${path} must not render commerce/account machinery (${marker})`);
    }
    assert.ok(!/\$\s?\d|\d+\s?CLP/.test(res.body), `${path} must never render a price value`);
  }
  assert.equal(wp(['option', 'get', 'users_can_register']).stdout, '0', 'customer accounts stay disabled (no registration surface)');

  section('Guard matrix and failure honesty (issue #13)', [
    'Guarded admin mutations (distance retry, notification resend, status transition) reject missing and foreign records with 404 and no mutation, even with a valid capability and nonce',
    'A lost basket session mid-journey fails the session guard: no success, no record, and the dead cookie is cleared so an ordinary retry starts fresh',
    'Public surfaces render no WooCommerce, cart/checkout, account or price machinery, and customer registration stays disabled',
  ]);
});

test('versioned migrations fail safely behind a clear public maintenance state that self-heals', { timeout: 120_000 }, async () => {
  const productTotal = () => Number(wp(['post', 'list', '--post_type=fp_product', '--post_status=publish', '--format=count']).stdout || '0');
  const quoteTotal = () => Number(wp(['post', 'list', '--post_type=fp_quote', '--post_status=private', '--format=count']).stdout || '0');
  const adminCookie = authCookie();
  const productsBefore = productTotal();
  const quotesBefore = quoteTotal();

  /* The schema failure is injected at the narrow verification seam: the
     table exists, but the migration cannot confirm it (an unwritable
     schema in production behaves identically). */
  const muDir = join(WP_DIR, 'wp-content', 'mu-plugins');
  mkdirSync(muDir, { recursive: true });
  writeFileSync(join(muDir, 'fp-test-schema-fail.php'), "<?php\nadd_filter( 'freeplast_cq_schema_ready', '__return_false' );\n");
  try {
    wp(['option', 'update', 'fp_db_version', '3']); /* a pending migration 4 */

    const home = await get('/', MOBILE_UA);
    assert.equal(home.status, 503, 'a failed migration must serve the maintenance state, not a half-migrated store');
    assertContains(home.body, 'Sitio en mantención', 'the maintenance state must state its purpose clearly');
    assertContains(home.body, 'tus datos no se han perdido', 'the maintenance state must reassure about the data');
    assertContains(home.body, 'ventas@freeplast.cl', 'the maintenance state keeps the human contact channel');
    assertContains(home.body, 'noindex', 'the maintenance page stays non-indexed');
    assert.equal(home.headers.get('retry-after'), '300', 'the maintenance answer carries a Retry-After');
    for (const path of ['/tienda/', '/cotizacion/', PRODUCT_URL]) {
      const res = await get(path, MOBILE_UA);
      assert.equal(res.status, 503, `${path} must answer the maintenance state too`);
    }

    /* Nothing is destroyed while the state is active. */
    assert.equal(productTotal(), productsBefore, 'Products survive the failed migration untouched');
    assert.equal(quoteTotal(), quotesBefore, 'Quote Requests survive the failed migration untouched');
    assert.equal(wp(['option', 'get', 'fp_db_version']).stdout, '3', 'the stored migration version stays untouched (retry pending)');
    assert.equal(wp(['eval', 'echo get_option( "fp_maintenance" ) ? "set" : "clear";']).stdout, 'set', 'the maintenance state is recorded');

    /* Administration surfaces keep explaining instead of failing silently. */
    const adminHome = await get('/wp-admin/index.php', MOBILE_UA, { cookie: adminCookie });
    assert.equal(adminHome.status, 200, 'administration stays reachable for inspection');
    assertContains(adminHome.body, 'una migración pendiente no pudo completarse', 'the admin notice explains the maintenance state');
  } finally {
    rmSync(join(muDir, 'fp-test-schema-fail.php'), { force: true });
  }

  /* Self-healing: once the fault clears, the very next request completes
     the pending migrations, clears the flag and the site returns. */
  const healed = await get('/', MOBILE_UA);
  assert.equal(healed.status, 200, 'the site must self-heal once the schema fault clears');
  assertContains(healed.body, 'Venta Mayorista de Productos Plásticos', 'the healed site renders the storefront again');
  assert.equal(wp(['option', 'get', 'fp_db_version']).stdout, String(DB_VERSION), 'the retry completes the pending migrations');
  assert.equal(wp(['eval', 'echo get_option( "fp_maintenance" ) ? "set" : "clear";']).stdout, 'clear', 'the maintenance flag clears with it');
  assert.equal(productTotal(), productsBefore, 'the healing retry duplicates or destroys nothing');
  assert.equal(quoteTotal(), quotesBefore, 'the healing retry duplicates or destroys nothing');

  section('Safe migrations and maintenance state (issue #13)', [
    'A migration that cannot complete (schema fault injected at the freeplast_cq_schema_ready verification seam) marks the maintenance state, leaves fp_db_version untouched and retries on every request — no half-migrated store is ever rendered',
    'Public routes answer a clear 503 maintenance page (purpose, data reassurance, human contact channel, noindex, Retry-After 300) while Products, Quote Requests and options survive untouched',
    'Administration surfaces stay reachable for inspection with an explicit notice explaining the pending migration',
    'Self-healing: once the fault clears, the next request completes the pending migrations, clears the flag and the storefront returns without duplicating or destroying anything',
  ]);
});

test('a stock block theme keeps minimal Catalog, basket, request and admin behavior; lifecycle operations preserve records', { timeout: 240_000 }, async () => {
  const counts = () => ({
    products: Number(wp(['post', 'list', '--post_type=fp_product', '--post_status=publish', '--format=count']).stdout || '0'),
    quotes: Number(wp(['post', 'list', '--post_type=fp_quote', '--post_status=private', '--format=count']).stdout || '0'),
    sessions: basketRows().length,
    pages: Number(wp(['post', 'list', '--post_type=page', '--post_status=publish', '--format=count']).stdout || '0'),
  });
  const submittedRef = (res) => new URL(res.headers.location || '', SITE_URL).searchParams.get('fpcq_submitted');
  const adminCookie = authCookie();
  const baseline = counts();
  assert.equal(baseline.products, PRODUCT_COUNT, 'the records under lifecycle pressure start complete');

  /* 23.1 — Theme failure: under the stock Twenty Twenty-Four block theme
     the plugin's dynamic blocks still expose a functional minimal
     Catalog, basket, request form and administration. */
  assert.equal(wp(['theme', 'activate', 'twentytwentyfour']).status, 0, 'the stock block theme must activate');
  const underStock = counts();
  assert.equal(underStock.products, baseline.products, 'a theme switch preserves Products');
  assert.equal(underStock.quotes, baseline.quotes, 'a theme switch preserves Quote Requests');

  const tienda = await get('/tienda/', MOBILE_UA);
  assert.equal(tienda.status, 200, 'the minimal Catalog archive renders under the stock theme');
  assert.ok(countMatches(tienda.body, 'type-fp_product') >= 5, 'the stock archive lists the synchronized Products');
  assertContains(tienda.body, 'wp-block-post-title', 'the stock archive links the product singles');

  const product = await get(PRODUCT_URL, MOBILE_UA);
  assert.equal(product.status, 200, 'the product single renders under the stock theme');
  assertContains(product.body, 'Caja Cosechera 3/4', 'the product detail block renders from synchronized metadata');
  assertContains(product.body, 'name="action" value="fp_basket_add"', 'the quantity chooser stays functional under the stock theme');

  const addNonce = product.body.match(/name="fp_basket_nonce" value="([a-f0-9]{10})"/)?.[1];
  const added = await postForm(
    { action: 'fp_basket_add', fp_product: 'fp-caja-cosechera-3-4', fp_quantity: '3', fp_basket_nonce: addNonce, _wp_http_referer: PRODUCT_URL },
    {}
  );
  const token = added.setCookies[0].match(/fpcq_basket=([0-9a-f]{64})/)?.[1];
  assert.ok(token, 'the stock-theme journey owns its basket session');
  const cot = await get('/cotizacion/', MOBILE_UA, { cookie: `fpcq_basket=${token}` });
  assertContains(cot.body, 'Tu cotización', 'the basket block renders under the stock theme');
  assertContains(cot.body, 'Caja Cosechera 3/4', 'the basket line renders under the stock theme');
  assertContains(cot.body, '3 unidades', 'the basket quantity renders under the stock theme');
  const creds = requestCredentials(cot.body);
  assert.ok(creds.nonce && creds.token, 'the request form renders under the stock theme');
  assert.equal(humanPaced(token, creds.token), 'ok', 'the stock-theme submission must be paced like a human fill');
  const stockSubmit = await postForm(
    {
      action: 'fp_request_submit',
      ...VALID_REQUEST_FIELDS,
      fp_request_nonce: creds.nonce,
      fp_request_token: creds.token,
      _wp_http_referer: '/cotizacion/',
    },
    { cookie: `fpcq_basket=${token}` }
  );
  const stockRef = submittedRef(stockSubmit);
  assert.match(stockRef, /^FP-\d{4}-\d{6}$/, 'the complete request journey works under the stock block theme');
  const stockConfirm = await get(`/cotizacion/?fpcq_submitted=${stockRef}`, MOBILE_UA, { cookie: `fpcq_basket=${token}` });
  assertContains(stockConfirm.body, stockRef, 'the confirmation renders under the stock theme');

  const stockAdmin = await get('/wp-admin/admin.php?page=fp-quotes', MOBILE_UA, { cookie: adminCookie });
  assert.equal(stockAdmin.status, 200, 'the Cotizaciones administration works under the stock theme');
  assertContains(stockAdmin.body, stockRef, 'the stock-theme request is administrable');

  assert.equal(wp(['theme', 'activate', 'freeplast']).status, 0, 'the v6 theme returns');
  const homeBack = await get('/', MOBILE_UA);
  assert.equal(homeBack.status, 200, 'the v6 storefront returns after the stock-theme round-trip');
  assertContains(homeBack.body, 'Venta Mayorista de Productos Plásticos', 'the v6 hero renders again');
  const afterTheme = counts();
  assert.equal(afterTheme.quotes, baseline.quotes + 1, 'only the stock-theme journey request was added');
  assert.equal(afterTheme.products, baseline.products, 'the theme round-trips preserve Products');

  /* 23.2 — Plugin deactivation preserves every business record and the
     session store; reactivation reattaches without duplicating seeds. */
  const beforeDeactivate = counts();
  assert.equal(wp(['plugin', 'deactivate', 'freeplast-catalog-quotes']).status, 0, 'the plugin must deactivate (reversible)');
  assert.deepEqual(counts(), beforeDeactivate, 'deactivation preserves Products, Quote Requests, sessions and pages');
  assert.equal(wp(['plugin', 'activate', 'freeplast-catalog-quotes']).status, 0, 'the plugin reactivates');
  assert.deepEqual(counts(), beforeDeactivate, 'reactivation reattaches without duplicating anything');
  assert.equal(wp(['option', 'get', 'fp_db_version']).stdout, String(DB_VERSION), 'reactivation keeps the migration version current');
  const homeAfter = await get('/', MOBILE_UA);
  assert.equal(homeAfter.status, 200, 'the site works again after the reactivation round-trip');

  /* 23.3 — Uninstall: the explicit uninstall.php keeps every business
     record (Products, Quote Requests with histories, basket sessions,
     configuration) and only clears ephemeral scheduling state. */
  const pluginDir = join(WP_DIR, 'wp-content', 'plugins', 'freeplast-catalog-quotes');
  const backupDir = join(BUILD_DIR, 'plugin-uninstall-backup');
  cpSync(pluginDir, backupDir, { recursive: true });
  const firstQuoteId = wp(['post', 'list', '--post_type=fp_quote', '--post_status=private', '--orderby=ID', '--order=ASC', '--format=ids']).stdout.split(/\s+/)[0];
  const firstQuoteState = JSON.parse(
    wp([
      'eval',
      `echo wp_json_encode( array( "ref" => get_post_meta( ${firstQuoteId}, "_fpq_reference", true ), "customer" => json_decode( (string) get_post_meta( ${firstQuoteId}, "_fpq_customer", true ), true ), "history" => json_decode( (string) get_post_meta( ${firstQuoteId}, "_fpq_history", true ), true ) ) );`,
    ]).stdout || '{}'
  );
  try {
    wp(['plugin', 'deactivate', 'freeplast-catalog-quotes']);
    /* Core's own uninstall routine: exactly what the WordPress admin's
       Delete action runs for an inactive plugin with an uninstall.php —
       WP-CLI's `plugin delete` only removes files, so the check drives
       the real uninstall boundary itself. */
    const uninstalled = wp([
      'eval',
      'require_once ABSPATH . "wp-admin/includes/plugin.php";' +
        '$r = uninstall_plugin( "freeplast-catalog-quotes/freeplast-catalog-quotes.php" );' +
        'echo true === $r ? "ok" : "no";',
    ]).stdout;
    assert.equal(uninstalled, 'ok', 'the explicit uninstall routine must run for the inactive plugin');
    assert.equal(wp(['plugin', 'delete', 'freeplast-catalog-quotes']).status, 0, 'the plugin files are removed after the uninstall routine');

    const afterUninstall = counts();
    assert.equal(afterUninstall.products, beforeDeactivate.products, 'uninstall preserves the Products');
    assert.equal(afterUninstall.quotes, beforeDeactivate.quotes, 'uninstall preserves the Quote Requests');
    assert.equal(afterUninstall.sessions, beforeDeactivate.sessions, 'uninstall preserves the basket sessions');
    assert.equal(afterUninstall.pages, beforeDeactivate.pages, 'uninstall preserves the shell pages');
    const preservedState = JSON.parse(
      wp([
        'eval',
        `echo wp_json_encode( array( "ref" => get_post_meta( ${firstQuoteId}, "_fpq_reference", true ), "customer" => json_decode( (string) get_post_meta( ${firstQuoteId}, "_fpq_customer", true ), true ), "history" => json_decode( (string) get_post_meta( ${firstQuoteId}, "_fpq_history", true ), true ) ) );`,
      ]).stdout || '{}'
    );
    assert.deepEqual(preservedState, firstQuoteState, 'uninstall preserves the Submitted Details and histories byte for byte');
    assert.equal(wp(['option', 'get', 'fp_shell_pages']).status, 0, 'the shell page map survives');
    assert.equal(wp(['option', 'get', 'fp_dispatch_origin']).stdout, 'Camino El Arrayán 52, San Francisco de Mostazal', 'the configured origin survives');
    assert.equal(wp(['option', 'get', 'fp_db_version']).stdout, String(DB_VERSION), 'the applied migration version survives (for a correct upgrade on reinstall)');

    /* Only ephemeral scheduling state is cleared. */
    const hooks = wp(['cron', 'event', 'list', '--fields=hook', '--format=csv']).stdout.split('\n');
    assert.ok(!hooks.some((h) => /^"?(fpcq_|freeplast_cq_)/.test(h.trim())), 'our scheduled events are unscheduled by the uninstall');
    const transients = Number(
      wp(['eval', 'global $wpdb; echo $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE \'\\_transient\\_fpcq\\_%\'" );']).stdout || '0'
    );
    assert.equal(transients, 0, 'the expiring fpcq transients are cleared by the uninstall');
  } finally {
    cpSync(backupDir, pluginDir, { recursive: true });
    rmSync(backupDir, { recursive: true, force: true });
    wp(['plugin', 'activate', 'freeplast-catalog-quotes']); /* restore the running check environment */
  }
  assert.equal(wp(['plugin', 'list', '--status=active', '--field=name']).stdout.includes('freeplast-catalog-quotes'), true, 'the plugin must return after the uninstall inspection');
  const homeReinstalled = await get('/', MOBILE_UA);
  assert.equal(homeReinstalled.status, 200, 'the site works after the plugin returns');
  assert.deepEqual(counts(), beforeDeactivate, 'the reinstall reattaches to the same records');
  const adminBack = await get('/wp-admin/admin.php?page=fp-quotes', MOBILE_UA, { cookie: adminCookie });
  assert.equal(adminBack.status, 200, 'the Cotizaciones administration works after the reinstall');
  assertContains(adminBack.body, firstQuoteState.ref, 'the preserved records are administrable again');

  section('Stock theme fallback and lifecycle preservation (issue #13)', [
    'Under the stock Twenty Twenty-Four block theme the plugin blocks still expose a functional minimal Catalog (archive listing + full product singles with chooser), basket, request submission (a request persists end to end with its confirmation) and Cotizaciones administration',
    'Theme deactivation/switching preserves Products, Quote Requests and sessions; the v6 theme returns to the full experience',
    'Plugin deactivation preserves every business record and the session store; reactivation reattaches without duplicating seeds',
    'The explicit uninstall.php preserves Products, Quote Requests with their Submitted Details and histories, basket sessions and configuration (shell pages, origin, migration version) — only expiring transients and our scheduled events are cleared — and a reinstall reattaches to the same records',
  ]);
});

test('PHP syntax and coding-standard scans pass over the shipped theme and plugin', () => {
  const sourceDirs = [
    join(WORDPRESS_DIR, 'wp-content', 'plugins', 'freeplast-catalog-quotes'),
    join(WORDPRESS_DIR, 'wp-content', 'themes', 'freeplast'),
  ];
  const scanDir = (dir) =>
    readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
      const full = join(dir, entry.name);
      return entry.isDirectory() ? scanDir(full) : [full];
    });
  const phpFiles = sourceDirs.flatMap((dir) => scanDir(dir)).filter((file) => file.endsWith('.php'));
  assert.ok(phpFiles.length >= 12, 'the scan must cover the shipped PHP files');

  for (const file of phpFiles) {
    const lint = spawnSync(PHP_BIN, ['-l', file], { encoding: 'utf8' });
    assert.equal(lint.status, 0, `${file} must pass php -l: ${lint.stderr}`);

    const code = readFileSync(file, 'utf8');
    assert.ok(code.endsWith('\n'), `${file} must end with a newline`);
    for (const forbidden of [/\beval\s*\(/, /\bextract\s*\(/, /base64_decode\s*\(/, /\bshell_exec\s*\(/, /\bpassthru\s*\(/, /\bproc_open\s*\(/, /\bpopen\s*\(/]) {
      assert.ok(!forbidden.test(code), `${file} must not use ${forbidden} (coding standards)`);
    }
    assert.ok(!/TODO|FIXME/.test(code), `${file} must not ship unfinished-work markers`);

    const isUninstall = file.endsWith('uninstall.php');
    const guard = isUninstall ? /WP_UNINSTALL_PLUGIN/ : /defined\( 'ABSPATH' \)/;
    assert.ok(guard.test(code), `${file} must guard direct access (ABSPATH${isUninstall ? ' / WP_UNINSTALL_PLUGIN' : ''})`);
  }

  const jsFiles = sourceDirs.flatMap((dir) => scanDir(dir)).filter((file) => file.endsWith('.js'));
  for (const file of jsFiles) {
    const code = readFileSync(file, 'utf8');
    assert.ok(code.endsWith('\n'), `${file} must end with a newline`);
    assert.ok(!/\beval\s*\(/.test(code), `${file} must not use eval`);
    assert.ok(!/document\.write\s*\(/.test(code), `${file} must not use document.write`);
  }

  section('Syntax and coding-standard scans (issue #13)', [
    `php -l passes on every shipped PHP file (${phpFiles.length} files across plugin + theme)`,
    'Coding-standard scans: no eval/extract/base64_decode/shell_exec/passthru/proc_open/popen, no TODO/FIXME markers, newline-terminated files, ABSPATH (or WP_UNINSTALL_PLUGIN) direct-access guards everywhere',
  ]);
});

/* ─── 23a. One JSON codec for stored meta (issue #17) ────────────── */

test('one shared JSON codec serves every stored-meta read/write with byte-identical unescaped JSON (issue #17)', () => {
  const pluginDir = join(WORDPRESS_DIR, 'wp-content', 'plugins', 'freeplast-catalog-quotes');
  const codecPath = join(pluginDir, 'includes', 'class-codec.php');
  assert.ok(existsSync(codecPath), 'includes/class-codec.php must exist (the plugin-level codec pair)');
  assert.ok(
    readFileSync(join(pluginDir, 'freeplast-catalog-quotes.php'), 'utf8').includes("require_once __DIR__ . '/includes/class-codec.php'"),
    'the plugin bootstrap must load the codec before the classes that use it'
  );

  /* Structural consolidation: the unescaped stored form is decided in
     exactly one file, the retired per-class helpers are gone, and the
     only remaining json_decode sites are non-meta (the catalog source
     document and the Google provider wire responses). */
  const scanDir = (dir) =>
    readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
      const full = join(dir, entry.name);
      return entry.isDirectory() ? scanDir(full) : [full];
    });
  const phpFiles = scanDir(pluginDir).filter((file) => file.endsWith('.php'));
  const code = Object.fromEntries(phpFiles.map((file) => [file, readFileSync(file, 'utf8')]));
  const flagFiles = phpFiles.filter((file) => code[file].includes('JSON_UNESCAPED_SLASHES'));
  assert.deepEqual(
    flagFiles,
    [codecPath],
    `the unescaped-JSON stored form must be decided only in class-codec.php, found in: ${flagFiles.map((f) => f.replaceAll(pluginDir + '/', '')).join(', ')}`
  );
  for (const retired of ['encode_meta', 'private static function pack', 'json_meta(', 'decoded_meta(']) {
    const sites = phpFiles.filter((file) => code[file].includes(retired));
    assert.deepEqual(sites, [], `the retired helper ${JSON.stringify(retired)} must not remain (found in ${sites.map((f) => f.replaceAll(pluginDir + '/', '')).join(', ')})`);
  }
  for (const file of phpFiles) {
    if (file === codecPath) continue;
    const relative = file.replaceAll(pluginDir + '/', '');
    if (relative.endsWith('class-catalog-source.php') || relative.endsWith('class-address.php')) {
      /* Non-meta decodes only: the source document, the provider wire
         bodies — never a stored-meta key. */
      for (const line of code[file].split('\n')) {
        if (line.includes('json_decode(')) {
          assert.ok(!line.includes('_fp'), `${relative} must not json_decode a stored-meta key inline: ${line.trim()}`);
        }
      }
      continue;
    }
    assert.ok(!code[file].includes('json_decode('), `${relative} must decode stored JSON only through the codec`);
  }

  /* Behavioral: the codec reproduces the documented byte form exactly
     (unescaped slashes and unicode), decodes honestly, and round-trips
     the actual stored meta on the running installation byte for byte. */
  const encoded = wp([
    'eval',
    'echo bin2hex( Freeplast_CQ_Codec::encode( array( "nombre" => "Ñandú Ltda.", "ruta" => "Caja 3/4", "articulo" => "nº 7 «azul»", "lista" => array( "a/b", "segunda" ) ) ) );',
  ]).stdout;
  const expectedBytes = Buffer.from(
    '{"nombre":"Ñandú Ltda.","ruta":"Caja 3/4","articulo":"nº 7 «azul»","lista":["a/b","segunda"]}',
    'utf8'
  ).toString('hex');
  assert.equal(encoded, expectedBytes, 'encode must produce the exact documented unescaped byte form');

  const decoded = JSON.parse(
    wp([
      'eval',
      'echo wp_json_encode( array( "roundtrip" => Freeplast_CQ_Codec::decode( Freeplast_CQ_Codec::encode( array( "k" => "v/ñ" ) ) ), "absent" => Freeplast_CQ_Codec::decode( "" ), "corrupt" => Freeplast_CQ_Codec::decode( "{nonsense" ) ) );',
    ]).stdout || '{}'
  );
  assert.deepEqual(
    decoded,
    { roundtrip: { k: 'v/ñ' }, absent: [], corrupt: [] },
    'decode must round-trip the encoded form and read absent/corrupt meta as an empty array'
  );

  const identityPhp =
    '$out = array();' +
    '$id = intval( get_posts( array( "post_type" => "fp_quote", "post_status" => "private", "posts_per_page" => 1, "orderby" => "ID", "order" => "ASC", "fields" => "ids", "no_found_rows" => true, "suppress_filters" => true ) )[0] ?? 0 );' +
    'foreach ( array( "_fpq_customer", "_fpq_items", "_fpq_current", "_fpq_notifications", "_fpq_history", "_fpq_notes", "_fpq_destination", "_fpq_distance" ) as $k ) {' +
    '  $raw = (string) get_post_meta( $id, $k, true ); if ( "" === $raw ) { continue; }' +
    '  $out[ $k ] = Freeplast_CQ_Codec::encode( Freeplast_CQ_Codec::decode( $raw ) ) === $raw ? "identity" : "drift";' +
    '}' +
    '$pid = intval( get_posts( array( "post_type" => "fp_product", "post_status" => "any", "posts_per_page" => 1, "orderby" => "ID", "order" => "ASC", "fields" => "ids", "no_found_rows" => true, "suppress_filters" => true ) )[0] ?? 0 );' +
    'foreach ( array( "_fp_options", "_fp_related_ids", "_fp_legacy_paths" ) as $k ) {' +
    '  $raw = (string) get_post_meta( $pid, $k, true ); if ( "" === $raw ) { continue; }' +
    '  $out[ $k ] = Freeplast_CQ_Codec::encode( Freeplast_CQ_Codec::decode( $raw ) ) === $raw ? "identity" : "drift";' +
    '}' +
    'global $wpdb;' +
    '$raw = (string) $wpdb->get_var( "SELECT basket_lines FROM {$wpdb->prefix}basket_sessions LIMIT 1" );' +
    'if ( "" !== $raw ) { $out[ "basket_lines" ] = Freeplast_CQ_Codec::encode( Freeplast_CQ_Codec::decode( $raw ) ) === $raw ? "identity" : "drift"; }' +
    'echo wp_json_encode( $out );';
  const stored = JSON.parse(wp(['eval', identityPhp]).stdout || '{}');
  for (const required of ['_fpq_customer', '_fpq_items', '_fpq_history', '_fpq_notifications', '_fp_options', '_fp_related_ids']) {
    assert.equal(stored[required], 'identity', `${required} must exist on the running installation and round-trip byte for byte`);
  }
  for (const [key, value] of Object.entries(stored)) {
    assert.equal(value, 'identity', `${key} must round-trip byte for byte through the codec (issue #17 identity gate)`);
  }

  /* The stored form still equals the compared form: the synchronizer's
     dry run over the untouched catalog must report zero changes. */
  const dry = catalogSync(['--dry-run']);
  assert.equal(dry.status, 0, `the post-swap dry run must succeed:\n${dry.stderr}`);
  assertContains(dry.stdout, 'Summary: created=0 updated=0 unchanged=17 warnings=0 errors=0', 'the dry run after the codec swap must report zero changes (stored form equals compared form)');

  section('One JSON codec for stored meta (issue #17)', [
    'One plugin-level encode/decode pair (Freeplast_CQ_Codec) serves every stored-meta read/write — Quote Request meta, Catalog Sync, Notifications, basket sessions and the Delivery Address inline decodes included; the unescaped stored form is decided in exactly one file and the retired per-class helpers are gone',
    'Encode reproduces the documented byte form exactly (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); absent/corrupt meta decodes to an empty array',
    'Round-trip identity on existing staging data: every stored meta key on the running installation re-encodes to its own bytes; the catalog dry run after the swap reports created=0 updated=0 unchanged=17 errors=0',
  ]);
});

/* ─── 23b. Isolated staging deployment artifacts (issue #14) ─────── */

/**
 * Parse the strict YAML subset used by infra/compose.yaml: mappings,
 * sequences, quoted/plain scalars, "|" block scalars and full-line
 * comments. Deployment artifact only — the file is authored in this
 * subset, so anything richer is a parse error (kept strict on purpose).
 */
function parseComposeYaml(text) {
  const lines = text.split('\n').flatMap((raw, idx) => {
    const trimmed = raw.trim();
    if (!trimmed || trimmed.startsWith('#')) return [];
    return [{ indent: raw.length - raw.replace(/^ +/, '').length, raw, text: trimmed, no: idx + 1 }];
  });
  let pos = 0;
  const scalar = (value) => (/^".*"$/.test(value) ? value.slice(1, -1) : value);
  function block(indent) {
    if (pos >= lines.length) throw new Error('unexpected end of file');
    return lines[pos].text.startsWith('- ') ? sequence(indent) : mapping(indent);
  }
  function mapping(indent) {
    const out = {};
    while (pos < lines.length && lines[pos].indent === indent && !lines[pos].text.startsWith('- ')) {
      const match = lines[pos].text.match(/^([^:\s]+):(?:[ \t]+(.*))?$/);
      if (!match) throw new Error(`compose.yaml line ${lines[pos].no}: not a mapping entry`);
      const key = scalar(match[1]);
      const value = match[2];
      pos++;
      if (value === '|') {
        if (pos >= lines.length || lines[pos].indent <= indent) throw new Error(`compose.yaml line ${lines[pos - 1].no}: empty block scalar`);
        const childIndent = lines[pos].indent;
        const parts = [];
        while (pos < lines.length && lines[pos].indent >= childIndent) {
          parts.push(lines[pos].raw.slice(childIndent));
          pos++;
        }
        out[key] = parts.join('\n');
      } else if (value === undefined || value === '') {
        out[key] = pos < lines.length && lines[pos].indent > indent ? block(lines[pos].indent) : null;
      } else {
        out[key] = scalar(value);
      }
    }
    return out;
  }
  function sequence(indent) {
    const out = [];
    while (pos < lines.length && lines[pos].indent === indent && lines[pos].text.startsWith('- ')) {
      out.push(scalar(lines[pos].text.slice(2)));
      pos++;
    }
    return out;
  }
  return block(0);
}

test('the isolated staging deployment is collision-checked, secret-safe and bounded before any server mutation', () => {
  const INFRA = join(WORDPRESS_DIR, 'infra');
  const read = (name) => readFileSync(join(INFRA, name), 'utf8');
  const files = ['staging.sh', 'compose.yaml', '.env.example', 'nginx/staging.conf.tmpl', 'preflight.sh', 'deploy.sh', 'verify.sh', 'backup.sh', 'rollback.sh'];

  /* Every artifact exists, is newline-terminated and carries no unfinished-work markers. */
  for (const name of files) {
    const text = read(name);
    assert.ok(text.endsWith('\n'), `infra/${name} must end with a newline`);
    assert.ok(!/TODO|FIXME/.test(text), `infra/${name} must not ship unfinished-work markers`);
  }
  const deployment = readFileSync(join(WORDPRESS_DIR, 'DEPLOYMENT.md'), 'utf8');
  assert.ok(deployment.endsWith('\n'), 'DEPLOYMENT.md must end with a newline');

  /* Shell scripts are at least syntactically valid for the operator. */
  for (const name of files.filter((f) => f.endsWith('.sh'))) {
    const lint = spawnSync('bash', ['-n', join(INFRA, name)], { encoding: 'utf8' });
    assert.equal(lint.status, 0, `bash -n infra/${name}: ${lint.stderr}`);
  }

  /* 1. The Compose stack: a dedicated project with private persistent
     volumes, a loopback-only origin and an on-demand WP-CLI sidecar. */
  const composeText = read('compose.yaml');
  const compose = parseComposeYaml(composeText);
  assert.equal(compose.name, 'freeplast-wordpress', 'the Compose project name must be collision-checked unique');
  assert.deepEqual(Object.keys(compose.services).sort(), ['cli', 'db', 'wordpress'], 'exactly the three contracted services');

  const db = compose.services.db;
  assert.match(db.image, /^mariadb:\d/, 'the database is a pinned MariaDB release');
  assert.deepEqual(
    db.healthcheck.test,
    ['CMD', 'healthcheck.sh', '--connect', '--innodb_initialized'],
    'the database healthcheck uses the official image command'
  );
  assert.deepEqual(db.volumes, ['db_data:/var/lib/mysql'], 'the database persists into a private named volume');
  assert.equal(db.ports, undefined, 'the database must not publish any port');
  assert.equal(db.restart, 'unless-stopped');

  const wp = compose.services.wordpress;
  assert.match(wp.image, /^wordpress:\d/, 'WordPress is a pinned multi-arch release');
  assert.deepEqual(
    wp.ports,
    ['127.0.0.1:${FREEPLAST_LOOPBACK_PORT:?set in .env by deploy.sh}:80'],
    'origin HTTP is published to loopback only, taking the single-sourced port from .env (host Nginx terminates TLS)'
  );
  assert.ok(wp.volumes.includes('wp_data:/var/www/html'), 'WordPress persists into a private named volume');
  assert.equal(wp.depends_on.db.condition, 'service_healthy', 'WordPress waits for a healthy database');
  assert.equal(wp.restart, 'unless-stopped');
  const extra = wp.environment.WORDPRESS_CONFIG_EXTRA;
  assert.match(extra, /HTTP_X_FORWARDED_PROTO/, 'wp-config honors the Nginx-forwarded HTTPS scheme');
  assert.match(extra, /\$\$_SERVER\['HTTPS'\]\s*=\s*'on'/, 'the forwarded scheme switches WordPress to HTTPS');
  assert.match(extra, /DISALLOW_FILE_EDIT/, 'dashboard file editing is disabled');
  assert.equal(
    wp.environment.FREEPLAST_CQ_MAIL_MODE,
    '${FREEPLAST_CQ_MAIL_MODE:-suppress}',
    'staging notifications fail closed to non-delivery unless the environment overrides'
  );
  assert.match(wp.environment.WORDPRESS_DB_PASSWORD, /^\$\{MARIADB_PASSWORD/, 'database credentials come from the server-side .env');

  const cli = compose.services.cli;
  assert.deepEqual(cli.profiles, ['tools'], 'the WP-CLI sidecar starts only on demand through its profile');
  assert.ok(cli.volumes.includes('wp_data:/var/www/html'), 'the sidecar shares the WordPress volume');
  assert.equal(cli.ports, undefined, 'the sidecar publishes nothing');

  assert.deepEqual(Object.keys(compose.volumes).sort(), ['db_data', 'wp_data'], 'exactly the two private named volumes');
  assert.ok(compose.networks && 'freeplast' in compose.networks, 'a dedicated project-scoped network');
  for (const [key, value] of Object.entries({ ...db.environment, ...wp.environment, ...cli.environment })) {
    if (/PASSWORD/i.test(key)) assert.match(value, /^\$\{/, `${key} must be an .env reference, never a literal secret`);
  }

  /* 2. The environment template carries names and comments only. */
  const envTemplate = read('.env.example');
  const envKeys = [];
  for (const line of envTemplate.split('\n')) {
    if (/^#/.test(line.trim()) || !line.trim()) continue;
    const match = line.match(/^([A-Z0-9_]+)=(.*)$/);
    assert.ok(match, `.env.example lines must be names or comments: ${JSON.stringify(line)}`);
    assert.equal(match[2].trim(), '', `.env.example must not carry values (${match[1]})`);
    envKeys.push(match[1]);
  }
  for (const required of [
    'MARIADB_ROOT_PASSWORD', 'MARIADB_DATABASE', 'MARIADB_USER', 'MARIADB_PASSWORD',
    'WORDPRESS_ADMIN_USER', 'WORDPRESS_ADMIN_EMAIL', 'WORDPRESS_ADMIN_PASSWORD',
    'FREEPLAST_LOOPBACK_PORT', 'TLS_CERT_PATH', 'TLS_KEY_PATH',
    'BASIC_AUTH_OWNER_USER', 'BASIC_AUTH_OWNER_PASSWORD', 'BASIC_AUTH_CLIENT_USER', 'BASIC_AUTH_CLIENT_PASSWORD',
    'FREEPLAST_CQ_MAIL_MODE', 'FREEPLAST_CQ_MAIL_TO', 'FREEPLAST_GOOGLE_API_KEY',
  ]) {
    assert.ok(envKeys.includes(required), `.env.example must document ${required}`);
  }

  /* 3. The Nginx vhost proxies only the approved hostname with owner/client
     Basic Auth and staging noindex, following the TLS convention. */
  const vhost = read('nginx/staging.conf.tmpl');
  const serverBlocks = vhost.split(/^server\s*\{/m);
  assert.equal(serverBlocks.length, 3, 'exactly the HTTP-redirect and HTTPS server blocks');
  const names = vhost.match(/server_name\s+([^;]+);/g) || [];
  assert.deepEqual(names, ['server_name __SITE_HOSTNAME__;', 'server_name __SITE_HOSTNAME__;'], 'only the approved hostname is proxied (collision discipline)');
  assert.ok(/listen 80;/.test(vhost) && /return 301 https:\/\/\$host\$request_uri;/.test(vhost), 'plain HTTP redirects to HTTPS');
  assert.ok(/listen 443 ssl;/.test(vhost), 'the review surface is TLS');
  assert.ok(/ssl_certificate __TLS_CERT__;/.test(vhost) && /ssl_certificate_key __TLS_KEY__;/.test(vhost), 'TLS paths render from the server convention at deploy time');
  assert.ok(/auth_basic "Freeplast staging";/.test(vhost), 'owner/client Basic Auth protects the review surface');
  assert.ok(/auth_basic_user_file __STACK_DIR__\/nginx\/.htpasswd;/.test(vhost), 'the htpasswd lives inside the stack directory');
  assert.ok(/add_header X-Robots-Tag "noindex, nofollow" always;/.test(vhost), 'Nginx-level noindex backs up the WordPress setting');
  assert.ok(/client_max_body_size 64m;/.test(vhost), 'an explicit upload limit for WordPress media');
  assert.ok(/proxy_pass http:\/\/127\.0\.0\.1:__LOOPBACK_PORT__;/.test(vhost), 'the proxy targets the loopback-only origin');
  assert.ok(/proxy_set_header X-Forwarded-Proto https;/.test(vhost) && /proxy_set_header Host \$host;/.test(vhost), 'the forwarded chain carries Host and HTTPS scheme');
  assert.ok(vhost.includes('location ~ /\\. { deny all; }'), 'dotfiles are denied at Nginx');
  const listens = [...vhost.matchAll(/^\s*listen\s+([^;]+);/gm)].map((m) => m[1]);
  assert.deepEqual(listens.sort(), ['443 ssl', '80'], 'no other ports are listened on');

  /* 4. preflight.sh is read-only and checks every collision before mutation. */
  const preflight = read('preflight.sh');
  const preflightChecks = [
    ['sites-enabled', 'nginx server_name collision'],
    ['ss -ltn', 'loopback port collision'],
    ['docker ps', 'container-name collision'],
    ['docker volume ls', 'volume collision'],
    ['docker network ls', 'network collision'],
    ['docker compose ls', 'compose-project collision'],
    ['df -BG', 'disk capacity'],
    ['getent ahosts', 'DNS resolves to this host'],
    ['openssl x509', 'certificate exists'],
    ['subjectAltName', 'certificate covers the approved hostname'],
    ['checkend', 'certificate is not near expiry'],
    ['unhealthy', 'existing containers stay healthy'],
  ];
  for (const [needle, why] of preflightChecks) assert.ok(preflight.includes(needle), `preflight.sh must check ${why} (${needle})`);
  assert.ok(preflight.includes('[[ ! -e "$STACK_DIR" ]]') || preflight.includes('! -e "$STACK_DIR"'), 'preflight.sh must assert the stack directory is new');
  for (const mutating of [
    /docker compose (up|down|run|create|restart|stop|kill)/,
    /systemctl (reload|restart|start|stop)/,
    /nginx -s /,
    /rm -rf/,
    /mkdir/,
    /cp -a/,
    /ln -s/,
    /install -m/,
  ]) {
    assert.ok(!mutating.test(preflight), `preflight.sh is read-only and must not mutate (${mutating})`);
  }

  /* 5. deploy.sh: secrets never printed, validation before mutation, the
     recorded WordPress facts, catalog idempotence and the nginx order. */
  const deploy = read('deploy.sh');
  assert.ok(deploy.includes('umask 077'), 'deploy.sh restricts the file-mode creation mask');
  assert.ok(deploy.includes('preflight.sh'), 'deploy.sh runs the read-only preflight before creating anything');
  assert.ok((deploy.match(/openssl rand/g) || []).length >= 5, 'every secret is generated on the server (root, db, admin, owner, client)');
  assert.ok(deploy.includes('chmod 600 .env'), 'the generated .env is mode 0600');
  for (const key of envKeys) assert.ok(deploy.includes(key), `deploy.sh must write .env key ${key}`);
  assert.ok(
    deploy.indexOf('docker compose --env-file .env config --quiet') < deploy.indexOf('docker compose --env-file .env up -d'),
    'Compose configuration is validated before anything starts'
  );
  assert.ok(deploy.includes('healthcheck.sh --connect --innodb_initialized'), 'the database is healthy before WordPress bootstraps');
  assert.ok(deploy.includes('--locale=es_CL') && deploy.includes('--skip-email'), 'WordPress installs with the es_CL locale without mailing');
  assert.ok(deploy.includes('timezone_string') && deploy.includes('America/Santiago'), 'the timezone is America/Santiago');
  assert.ok(deploy.includes('blog_public 0'), 'search-engine visibility is disabled (noindex)');
  assert.ok(deploy.includes('rewrite structure') && deploy.includes('--hard'), 'the approved permalink structure is applied');
  assert.ok(deploy.includes('plugin activate freeplast-catalog-quotes') && deploy.includes('theme activate freeplast'), 'theme and plugin activate through WP-CLI');
  assert.ok(deploy.includes('catalog sync --file=/bundle/catalog/products.json'), 'the reviewed Catalog Source synchronizes');
  assert.ok(deploy.includes('created=0 updated=0') && deploy.includes('errors=0'), 'a repeated dry run must report zero changes or deployment fails');
  assert.ok(
    deploy.indexOf('nginx-sites-available.pre-freeplast') < deploy.indexOf('sites-available/${SITE_HOSTNAME}'),
    'Nginx is backed up before the new vhost exists'
  );
  assert.ok(deploy.indexOf('nginx -t') < deploy.indexOf('systemctl reload nginx'), 'nginx -t validates before reload');
  assert.ok(deploy.includes('openssl passwd'), 'Basic Auth hashes are generated, never stored in clear');
  assert.ok(deploy.includes('.secrets/credentials') && deploy.includes('chmod 400'), 'credentials land in a mode-0400 file');
  assert.ok(!deploy.includes('set -x'), 'command tracing would leak secrets');
  assert.ok(!/cat [^\n]*\.env/.test(deploy), 'the .env is never printed');
  for (const line of deploy.split('\n')) {
    if (/\b(echo|printf)\b/.test(line)) {
      assert.ok(
        !/\$(MARIADB_ROOT_PASSWORD|MARIADB_PASSWORD|WORDPRESS_ADMIN_PASSWORD|BASIC_AUTH_OWNER_PASSWORD|BASIC_AUTH_CLIENT_PASSWORD)\b/.test(line),
        `a secret value must never reach command output: ${line.trim()}`
      );
    }
  }

  /* 6. verify.sh checks the acceptance matrix through HTTPS + WP-CLI. */
  const verify = read('verify.sh');
  for (const needle of [
    '301', '401', '200',
    '/nosotros/', '/tienda/', '/contacto/', '/cotizacion/', '/politica-de-privacidad/', '/producto/caja-cosechera-3-4/',
    'x-robots-tag', 'blog_public', 'get_locale', 'es_CL', 'America/Santiago',
    'option get home', 'wp-login.php', '/wp-admin/',
    'created=0 updated=0', 'Freeplast_CQ_Notifications::mode()',
  ]) {
    assert.ok(verify.includes(needle), `verify.sh must assert ${needle}`);
  }
  assert.ok(verify.includes('BASIC_AUTH_OWNER_PASSWORD') && verify.includes('BASIC_AUTH_CLIENT_PASSWORD'), 'both owner and client credentials are exercised');
  const verifyLines = verify.split('\n');
  assert.ok(
    verifyLines.some((line) => line.includes('mode') && line.includes('live')),
    'verify.sh must reject the live mail mode on staging'
  );
  assert.ok(!/--url=http:/.test(verify), 'verification goes through the approved HTTPS hostname');

  /* 7. backup.sh dumps, hashes and rehearses the restore into temporary names. */
  const backup = read('backup.sh');
  for (const needle of ['mariadb-dump', 'sha256sum', 'freeplast-wordpress-restore', 'down -v', 'tar']) {
    assert.ok(backup.includes(needle), `backup.sh must cover ${needle}`);
  }
  assert.ok(/\/root\/freeplast-wordpress-backups/.test(backup), 'backups are stored outside the live Compose volumes');

  /* 8. rollback.sh is bounded to the new resources. */
  const rollback = read('rollback.sh');
  assert.ok(rollback.includes('sites-enabled/${SITE_HOSTNAME}') && rollback.includes('rm -f'), 'rollback removes only the approved-hostname vhost');
  assert.ok(rollback.indexOf('nginx -t') < rollback.indexOf('systemctl reload nginx'), 'nginx -t validates before reload');
  assert.ok(rollback.includes('freeplast-wordpress') && rollback.includes('--purge-volumes'), 'the rollback targets only the new Compose project');
  assert.ok(!rollback.match(/docker compose[^\n]*down[^\n]*-v/) || rollback.includes('--purge-volumes'), 'volumes are retained unless the owner explicitly purges');
  assert.ok(rollback.includes('/var/www/html/freeplast'), 'the static proposals are verified untouched');
  for (const text of [rollback, preflight, deploy, verify, backup]) {
    assert.ok(!text.includes('cutulab') && !text.includes('mliu.site/freeplast'), 'no unrelated OpenClaw service is referenced for mutation');
  }

  /* 9. DEPLOYMENT.md records every resource name, path, port and volume. */
  for (const needle of [
    'freeplast.mliu.site', '127.0.0.1:8092', 'freeplast-wordpress', 'db_data', 'wp_data',
    '/opt/freeplast-wordpress', 'sites-available/freeplast.mliu.site', '.htpasswd',
    'mariadb:11.4', 'wordpress:7.1-php8.3-apache', 'digest', 'DNS', 'suppress',
    'rollback', 'restore rehearsal', 'preflight.sh', 'deploy.sh', 'verify.sh', 'backup.sh',
  ]) {
    assert.ok(deployment.includes(needle), `DEPLOYMENT.md must record ${needle}`);
  }

  /* 10. Cross-file consistency: one proxy target, one htpasswd path — the
     constants themselves are single-sourced in staging.sh (issue #16 test
     below) and DEPLOYMENT.md records the deployed values (§9). */
  assert.equal((vhost.match(/proxy_pass http:\/\/127\.0\.0\.1:__LOOPBACK_PORT__;/g) || []).length, 1, 'exactly one proxy target');
  assert.ok(
    deploy.includes('"$STACK_DIR/nginx/.htpasswd"') && vhost.includes('__STACK_DIR__/nginx/.htpasswd;'),
    'deploy.sh writes the exact htpasswd path the vhost reads'
  );

  section('Isolated staging deployment artifacts (issue #14)', [
    'Compose stack: dedicated freeplast-wordpress project, MariaDB healthcheck, private named volumes (db_data, wp_data), loopback-only origin 127.0.0.1:8092, profile-gated WP-CLI sidecar, no literal secrets (all .env references)',
    'Nginx vhost: approved-hostname-only server names, HTTP→HTTPS redirect, TLS rendered from the server convention, owner/client Basic Auth, X-Robots-Tag noindex always, 64m uploads, dotfile/sensitive denies, proxy to the loopback origin with Host/Forwarded headers',
    'preflight.sh is read-only and collision-checks hostname, port, stack directory, Compose project, volumes, network, disk, DNS, certificate SAN/expiry and existing-container health before any mutation',
    'deploy.sh: umask 077, server-generated secrets (openssl rand) into mode-0600 .env, Compose validation before up, es_CL + America/Santiago + HTTPS URLs + blog_public 0 + permalinks, plugin/theme activation, catalog sync with a zero-change dry-run gate, Nginx backup before vhost, nginx -t before reload, credentials only in mode-0400 files — never repo files or command output',
    'verify.sh: HTTP→HTTPS 301, 401 without credentials, owner+client 200s, every required route, X-Robots-Tag + blog_public, locale/timezone/home URL, catalog dry-run zero changes, restricted (non-live) mail mode through HTTPS and WP-CLI',
    'backup.sh: database dump + WordPress-volume archive, SHA-256 hashes outside the live volumes, restore rehearsal into temporary freeplast-wordpress-restore names with teardown',
    'rollback.sh: bounded to the approved-hostname vhost and the freeplast-wordpress project; named volumes retained unless the owner types the explicit purge confirmation; static proposals verified untouched',
    'DEPLOYMENT.md records every resource name, path, port, volume, backup and rollback scope; the server-side execution on OpenClaw is the operator runbook step (this environment has no route to the host)',
  ]);
});

/* ─── 23c. Verification and operations handoff (issue #15) ───────── */

/** Compare Dirent entries by name (code-point order) for deterministic packaging. */
function byName(a, b) {
  if (a.name < b.name) return -1;
  if (a.name > b.name) return 1;
  return 0;
}

/** Walk one shipped artifact into sorted (name, bytes) pairs — deterministic. */
function collectShippedFiles(dir, root) {
  const out = [];
  const entries = readdirSync(dir, { withFileTypes: true }).sort(byName);
  for (const entry of entries) {
    const name = root ? `${root}/${entry.name}` : entry.name;
    if (entry.isDirectory()) out.push(...collectShippedFiles(join(dir, entry.name), name));
    else out.push({ name, data: readFileSync(join(dir, entry.name)) });
  }
  return out;
}

const CRC32_TABLE = (() => {
  const table = new Int32Array(256);
  for (let n = 0; n < 256; n++) {
    let c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    table[n] = c;
  }
  return table;
})();

function crc32(buffer) {
  let crc = -1;
  for (const byte of buffer) crc = (crc >>> 8) ^ CRC32_TABLE[(crc ^ byte) & 0xff];
  return (crc ^ -1) >>> 0;
}

/**
 * Deterministic stored (uncompressed) ZIP so every rebuild is byte-identical
 * and the recorded checksums stay verifiable: sorted entries, a fixed
 * 2026-01-01 DOS timestamp, no extras. `unzip -t` validates the result.
 */
const ZIP_DOS_TIME = 0; // 00:00:00
const ZIP_DOS_DATE = ((2026 - 1980) << 9) | (1 << 5) | 1; // 2026-01-01

function buildStoredZip(files) {
  const local = [];
  const central = [];
  let offset = 0;
  for (const file of files) {
    const name = Buffer.from(file.name, 'utf8');
    const crc = crc32(file.data);
    const header = Buffer.alloc(30);
    header.writeUInt32LE(0x04034b50, 0); // local file header signature
    header.writeUInt16LE(20, 4); // version needed
    header.writeUInt16LE(0, 6); // flags
    header.writeUInt16LE(0, 8); // method: store
    header.writeUInt16LE(ZIP_DOS_TIME, 10);
    header.writeUInt16LE(ZIP_DOS_DATE, 12);
    header.writeUInt32LE(crc, 14);
    header.writeUInt32LE(file.data.length, 18);
    header.writeUInt32LE(file.data.length, 22);
    header.writeUInt16LE(name.length, 26);
    header.writeUInt16LE(0, 28);
    local.push(header, name, file.data);

    const entry = Buffer.alloc(46);
    entry.writeUInt32LE(0x02014b50, 0); // central directory signature
    entry.writeUInt16LE(20, 4); // version made by
    entry.writeUInt16LE(20, 6); // version needed
    entry.writeUInt16LE(0, 8); // flags
    entry.writeUInt16LE(0, 10); // method: store
    entry.writeUInt16LE(ZIP_DOS_TIME, 12);
    entry.writeUInt16LE(ZIP_DOS_DATE, 14);
    entry.writeUInt32LE(crc, 16);
    entry.writeUInt32LE(file.data.length, 20);
    entry.writeUInt32LE(file.data.length, 24);
    entry.writeUInt16LE(name.length, 28);
    entry.writeUInt16LE(0, 30); // extra length
    entry.writeUInt16LE(0, 32); // comment length
    entry.writeUInt16LE(0, 34); // disk number
    entry.writeUInt16LE(0, 36); // internal attributes
    entry.writeUInt32LE((0o100644 << 16) >>> 0, 38); // external attributes: regular 0644
    entry.writeUInt32LE(offset, 42);
    central.push(Buffer.concat([entry, name]));
    offset += header.length + name.length + file.data.length;
  }
  const centralBuf = Buffer.concat(central);
  const eocd = Buffer.alloc(22);
  eocd.writeUInt32LE(0x06054b50, 0); // end of central directory signature
  eocd.writeUInt16LE(files.length, 8);
  eocd.writeUInt16LE(files.length, 10);
  eocd.writeUInt32LE(centralBuf.length, 12);
  eocd.writeUInt32LE(offset, 16);
  return Buffer.concat([...local, centralBuf, eocd]);
}

let checksumLines = [];

test('the verification and operations handoff packages the build for independent operation and human review (issue #15)', () => {
  const THEME_DIR = join(WORDPRESS_DIR, 'wp-content', 'themes', 'freeplast');
  const PLUGIN_DIR = join(WORDPRESS_DIR, 'wp-content', 'plugins', 'freeplast-catalog-quotes');
  const handoffPath = join(WORDPRESS_DIR, 'HANDOFF.md');
  assert.ok(existsSync(handoffPath), 'wordpress/HANDOFF.md must exist (the issue #15 deliverable)');
  const handoff = readFileSync(handoffPath, 'utf8');
  /* Prose needles are matched on a whitespace-normalized copy so wrapped
     lines never break the coverage assertions. */
  const flat = handoff.replace(/\s+/g, ' ');
  const flatLower = flat.toLowerCase();
  const covers = (needle) => flatLower.includes(needle.toLowerCase());
  assert.ok(handoff.endsWith('\n'), 'HANDOFF.md must end with a newline');
  assert.ok(!/TODO|FIXME/.test(handoff), 'HANDOFF.md must not ship unfinished-work markers');

  /* 1. Shipped artifacts: deterministic ZIPs with recorded versions and
     SHA-256 checksums (RUNBOOK §Handoff). */
  const themeVersion = readFileSync(join(THEME_DIR, 'style.css'), 'utf8').match(/^Version:\s*(\S+)$/m)?.[1];
  const pluginVersion = readFileSync(PLUGIN_MAIN, 'utf8').match(/FREEPLAST_CQ_VERSION',\s*'([^']+)'/)?.[1];
  assert.ok(themeVersion, 'the theme version must be readable from style.css');
  assert.ok(pluginVersion, 'the plugin version must be readable from the plugin header constant');
  const themeFiles = collectShippedFiles(THEME_DIR, 'freeplast');
  const pluginFiles = collectShippedFiles(PLUGIN_DIR, 'freeplast-catalog-quotes');
  assert.ok(themeFiles.length >= 8, `the theme ZIP must ship the complete theme (${themeFiles.length} files)`);
  assert.ok(pluginFiles.length >= 10, `the plugin ZIP must ship the complete plugin (${pluginFiles.length} files)`);
  const sha256 = (data) => createHash('sha256').update(data).digest('hex');

  const dist = join(WORDPRESS_DIR, 'dist');
  mkdirSync(dist, { recursive: true });
  const zips = [
    { name: `freeplast-theme-${themeVersion}.zip`, files: themeFiles },
    { name: `freeplast-catalog-quotes-plugin-${pluginVersion}.zip`, files: pluginFiles },
  ];
  const manifest = [
    '# Freeplast shipped artifacts — SHA-256 checksums (issue #15).',
    `# Regenerated by npm test; theme freeplast ${themeVersion} · plugin freeplast-catalog-quotes ${pluginVersion} · fp_db_version ${DB_VERSION}.`,
  ];
  checksumLines = [
    `Theme:     freeplast ${themeVersion} — ${themeFiles.length} files → dist/${zips[0].name}`,
    `Plugin:    freeplast-catalog-quotes ${pluginVersion} — ${pluginFiles.length} files → dist/${zips[1].name}`,
  ];
  for (const zip of zips) {
    const bytes = buildStoredZip(zip.files);
    writeFileSync(join(dist, zip.name), bytes);
    const hash = sha256(bytes);
    manifest.push(`${hash}  ${zip.name}`);
    checksumLines.push(`dist/${zip.name} — sha256:${hash} (${zip.files.length} stored entries, deterministic rebuild)`);
    const probe = spawnSync('unzip', ['-t', join(dist, zip.name)], { encoding: 'utf8' });
    assert.equal(probe.status, 0, `unzip -t dist/${zip.name}: ${probe.stdout || ''}${probe.stderr || ''}`);
    assert.ok(/No errors detected/.test(probe.stdout), `unzip -t must report a sound archive: ${probe.stdout}`);
  }
  /* Per-file manifest paths stay relative to dist/ so `sha256sum -c`
     verifies the real repository files, not just the packages. */
  for (const file of themeFiles) {
    manifest.push(`${sha256(file.data)}  ../wp-content/themes/${file.name}`);
  }
  for (const file of pluginFiles) {
    manifest.push(`${sha256(file.data)}  ../wp-content/plugins/${file.name}`);
  }
  writeFileSync(join(dist, 'CHECKSUMS.sha256'), manifest.join('\n') + '\n');
  const verify = spawnSync('sha256sum', ['-c', 'CHECKSUMS.sha256'], { cwd: dist, encoding: 'utf8' });
  assert.equal(verify.status, 0, `sha256sum -c CHECKSUMS.sha256: ${verify.stdout || ''}${verify.stderr || ''}`);
  checksumLines.push(`Per-file manifest: dist/CHECKSUMS.sha256 — ${themeFiles.length + pluginFiles.length} shipped files with SHA-256 checksums (sha256sum -c from dist/ verifies both packages and sources)`);

  /* 2. The handoff records the artifact versions and checksum location. */
  for (const needle of [themeVersion, pluginVersion, 'CHECKSUMS.sha256', ...zips.map((zip) => zip.name), 'SHA-256', 'dist/']) {
    assert.ok(covers(needle), `HANDOFF.md must record the artifact identity: ${needle}`);
  }

  /* 3. The verification record names every required dimension. */
  for (const needle of [
    'Infrastructure health', 'Nginx validation', 'PHP syntax', 'Coding standards', 'npm test',
    'fp_db_version', 'Active components', 'Route statuses', 'Browser console',
    'VERIFICATION.md', 'DEPLOYMENT.md', `fp_db_version=${DB_VERSION}`,
  ]) {
    assert.ok(covers(needle), `HANDOFF.md §1 must record: ${needle}`);
  }

  /* 4. Catalog evidence: counts, the complete per-Product reconciliation,
     the no-op dry run, media status and every provisional client fact. */
  assert.ok(handoff.includes(`${PRODUCT_COUNT} Active Products`), 'the source/destination count must be recorded');
  assert.ok(handoff.includes(`${PRODUCT_COUNT} destination`), 'the destination count must be recorded');
  for (const product of PRODUCTS) {
    for (const needle of [product.source_id, product.slug, product.title]) {
      assert.ok(covers(needle), `the per-Product reconciliation must cover ${needle}`);
    }
    assert.ok(covers(`/producto/${product.slug}/`), `the reconciliation must carry the canonical URL of ${product.slug}`);
  }
  for (const needle of ['no-op dry run', 'created=0 updated=0', 'errors=0', `${DISTINCT_IMAGES}`, 'provisional', 'media library']) {
    assert.ok(covers(needle), `HANDOFF.md §2 must record: ${needle}`);
  }
  for (const fact of ['Romano', 'G2', 'pediluvio', 'Ladrillo', 'minimum', 'color', 'photograph', 'dispatch origin', 'distance']) {
    assert.ok(covers(fact), `HANDOFF.md §2 must list the provisional client fact: ${fact}`);
  }

  /* 5. The Quote Request acceptance matrix covers the full journey. */
  for (const needle of [
    'JavaScript disabled', 'JavaScript enabled', 'one Product', 'multiple Products', 'option',
    'idempoten', 'manual', 'fallback', 'sales notification', 'customer acknowledgement', 'Cotizaciones',
  ]) {
    assert.ok(covers(needle), `HANDOFF.md §3 acceptance matrix must cover: ${needle}`);
  }

  /* 6. Accessibility and responsive observations stay mechanical. */
  for (const needle of ['Keyboard', 'focus', 'error summary', 'reduced motion', '375', '412', '768', '1024', '1440', 'WCAG']) {
    assert.ok(covers(needle), `HANDOFF.md §4 must record the mechanical observation: ${needle}`);
  }
  assert.ok(
    flat.includes('mechanical observations, not human approval'),
    'the accessibility record must explicitly deny human approval'
  );
  assert.ok(!/visually approved|visual approval (is |has )?(complete|recorded|granted)/i.test(flat), 'the handoff must not claim visual validation');

  /* 7. Operator procedures are reproducible by another operator. */
  for (const needle of [
    'preflight.sh', 'deploy.sh', 'backup.sh', 'rollback.sh', 'restore', 'catalog sync',
    'Cotizaciones', 'Reenviar', 'fp_dispatch_origin', 'FREEPLAST_CQ_MAIL_MODE',
  ]) {
    assert.ok(covers(needle), `HANDOFF.md §5 must carry the operator procedure for: ${needle}`);
  }

  /* 8. HTTPS review links beside the frozen v6/v7-A references. */
  for (const needle of [
    'https://freeplast.mliu.site/', '/tienda/', '/producto/caja-cosechera-3-4/', '/cotizacion/',
    '/nosotros/', '/contacto/', 'https://mliu.site/freeplast/v6/', 'https://mliu.site/freeplast/v7/?variant=A',
  ]) {
    assert.ok(covers(needle), `HANDOFF.md §7 must prepare the review link: ${needle}`);
  }

  /* 9. The exact pending human actions are stated as pending. */
  for (const needle of ['pending', 'Gate 3', 'DECISIONS.md', 'visual review', 'complete quote journey', 'Basic Auth']) {
    assert.ok(covers(needle), `HANDOFF.md §8 must state the pending human action: ${needle}`);
  }

  /* 10. Pending client answers list their apply-through-source/config process. */
  for (const needle of ['products.json', 'Catalog Source', 'fp_dispatch_origin', 'FREEPLAST_GOOGLE_API_KEY']) {
    assert.ok(covers(needle), `HANDOFF.md §9 must document applying client answers through: ${needle}`);
  }

  /* 11. Out-of-scope release work stays explicitly separate. */
  for (const needle of ['cutover', 'mail authentication', 'SPF', 'redirects', 'original photography', 'release plan']) {
    assert.ok(covers(needle), `HANDOFF.md §10 must keep out of scope: ${needle}`);
  }

  section('Verification and operations handoff (issue #15)', [
    `Shipped artifacts recorded: theme freeplast ${themeVersion} (${themeFiles.length} files) and plugin freeplast-catalog-quotes ${pluginVersion} (${pluginFiles.length} files) as deterministic ZIPs in dist/ with SHA-256 checksums (dist/CHECKSUMS.sha256, sha256sum-compatible; unzip -t clean)`,
    `HANDOFF.md packages the verification record (infrastructure health, Nginx validation, syntax/coding standards, automated tests, migration version fp_db_version=${DB_VERSION}, active components, route statuses, browser console), catalog evidence (17→17 reconciliation, no-op dry run, provisional media and client facts) and the full Quote Request acceptance matrix`,
    `Accessibility/responsive observations recorded as mechanical observations, not human approval; Gate 3 review URLs (Home, Tienda, Caja Cosechera 3/4, Cotización, Nosotros, Contacto) prepared beside the frozen v6/v7-A references`,
    `Operator procedures reproducible from HANDOFF.md alone (deploy/rollback/backup/restore, catalog sync, sales administration, notification resend, warehouse and mail configuration); pending owner/client actions and out-of-scope release work stated separately`,
  ]);
});

/* ─── 23d. Single-sourced staging constants (issue #16) ───────────── */

/** The deployed vhost, rendered exactly as it stands on the server since
 * the issue #14 operator run (TLS paths per DEPLOYMENT.md §Post-deploy
 * records). The issue #16 template must reproduce these bytes. */
const DEPLOYED_VHOST = `# Freeplast staging vhost — the approved hostname freeplast.mliu.site (issue #14).
#
# Rendered by deploy.sh: /etc/nginx/ssl/mliu.site/fullchain.pem / /etc/nginx/ssl/mliu.site/key.pem are replaced with the
# server's approved certificate paths from .env (preflight.sh verifies the
# certificate covers the hostname first). Align TLS protocol/cipher lines
# with the server's existing convention when recording the deployment.
#
# Installed as /etc/nginx/sites-available/freeplast.mliu.site plus one
# symlink in /etc/nginx/sites-enabled — nginx -t always runs before the
# reload, and deploy.sh backs up the prior configuration first.

server {
    listen 80;
    server_name freeplast.mliu.site;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    server_name freeplast.mliu.site;

    ssl_certificate /etc/nginx/ssl/mliu.site/fullchain.pem;
    ssl_certificate_key /etc/nginx/ssl/mliu.site/key.pem;

    # Password-protected review surface: owner/client credentials only.
    auth_basic "Freeplast staging";
    auth_basic_user_file /opt/freeplast-wordpress/nginx/.htpasswd;

    # Staging stays non-indexed even if WordPress is ever misconfigured.
    add_header X-Robots-Tag "noindex, nofollow" always;

    client_max_body_size 64m;

    # Defense in depth: dotfiles and sensitive backup/config extensions.
    location ~ /\\. { deny all; }
    location ~* /(wp-config\\.php|readme\\.html|license\\.txt)(/|$) { deny all; }
    location ~* \\.(bak|config|ini|log|orig|sh|sql|swp|tar\\.gz|tgz)$ { deny all; }

    location / {
        proxy_pass http://127.0.0.1:8092;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-Host $host;
        proxy_read_timeout 120s;
    }
}
`;

test('the staging hostname, install root and loopback port are single-sourced in infra (issue #16)', () => {
  const INFRA = join(WORDPRESS_DIR, 'infra');
  const read = (name) => readFileSync(join(INFRA, name), 'utf8');
  const scripts = ['preflight.sh', 'deploy.sh', 'verify.sh', 'backup.sh', 'rollback.sh'];
  const infraFiles = ['staging.sh', ...scripts, 'compose.yaml', '.env.example', 'nginx/staging.conf.tmpl'];
  const CONSTANTS = [
    ['SITE_HOSTNAME', 'freeplast.mliu.site'],
    ['STACK_DIR', '/opt/freeplast-wordpress'],
    ['LOOPBACK_PORT', '8092'],
  ];
  /* Everything deploy.sh substitutes into the vhost template: the shared
     constants plus the server's TLS convention as deployed. */
  const SUBSTITUTIONS = [
    ...CONSTANTS.map(([name, value]) => [`__${name}__`, value]),
    ['__TLS_CERT__', '/etc/nginx/ssl/mliu.site/fullchain.pem'],
    ['__TLS_KEY__', '/etc/nginx/ssl/mliu.site/key.pem'],
  ];

  /* Every artifact is read exactly once; the generic hygiene checks
     (newline-terminated, no unfinished-work markers, bash -n) live in the
     issue #14 test above. */
  const text = new Map(infraFiles.map((name) => [name, read(name)]));
  const shared = text.get('staging.sh');
  const vhost = text.get('nginx/staging.conf.tmpl');
  const deploy = text.get('deploy.sh');

  /* 1. staging.sh declares each constant exactly once; sourcing it is
     silent and exposes exactly those values. */
  for (const [name, value] of CONSTANTS) {
    assert.deepEqual(
      shared.split('\n').filter((line) => line.startsWith(`${name}=`)).map((line) => line.replace(/\s+#.*$/, '')),
      [`${name}='${value}'`],
      `staging.sh declares ${name} exactly once`
    );
  }
  const sourced = spawnSync(
    'bash',
    ['-c', '. "$1" && printf \'%s\\n\' "$SITE_HOSTNAME" "$STACK_DIR" "$LOOPBACK_PORT"', 'staging.sh', join(INFRA, 'staging.sh')],
    { encoding: 'utf8' }
  );
  assert.equal(sourced.status, 0, `sourcing staging.sh: ${sourced.stderr}`);
  assert.equal(sourced.stdout, `${CONSTANTS.map(([, value]) => value).join('\n')}\n`, 'sourcing exposes exactly the declared values');
  assert.equal(sourced.stderr, '', 'sourcing staging.sh prints nothing');

  /* 2. All five shell scripts source the shared definition and declare no
     constant literals of their own. */
  for (const name of scripts) {
    const script = text.get(name);
    assert.ok(script.includes('. "$INFRA_DIR/staging.sh"'), `${name} sources the shared definition`);
    for (const [key] of CONSTANTS) {
      assert.ok(!script.includes(`${key}='`), `${name} must not redeclare ${key}`);
    }
  }

  /* 3. Each value literal appears exactly once across all of infra — in
     staging.sh (a staging host or port change is a one-place edit). */
  for (const [, value] of CONSTANTS) {
    const hits = infraFiles
      .map((name) => ({ name, count: text.get(name).split(value).length - 1 }))
      .filter((h) => h.count > 0);
    assert.equal(hits.reduce((sum, h) => sum + h.count, 0), 1, `${value} must appear exactly once in infra`);
    assert.deepEqual(hits.map((h) => h.name), ['staging.sh'], `${value} must be declared only in staging.sh`);
  }

  /* 4. The vhost template carries only placeholders (comments included) and
     deploy.sh renders all five of them. */
  for (const [placeholder] of SUBSTITUTIONS) {
    assert.ok(vhost.includes(placeholder), `the vhost template carries ${placeholder}`);
    assert.ok(deploy.includes(`s|${placeholder}|`), `deploy.sh renders ${placeholder}`);
  }

  /* 5. Strictly behavior-preserving: rendering the template with the shared
     constants and the deployed TLS convention reproduces the deployed vhost
     byte-for-byte. */
  let rendered = vhost;
  for (const [placeholder, value] of SUBSTITUTIONS) {
    rendered = rendered.split(placeholder).join(value);
  }
  assert.equal(rendered, DEPLOYED_VHOST, 'the rendered vhost is byte-identical to the deployed configuration');

  /* 6. The Compose port takes the deploy-written .env value with no fallback
     literal, resolving to the deployed loopback-only mapping. */
  const portLine = text.get('compose.yaml').split('\n').find((line) => line.includes('FREEPLAST_LOOPBACK_PORT'));
  assert.ok(portLine, 'compose.yaml must publish the loopback port mapping');
  assert.equal(
    portLine.trim(),
    '- "127.0.0.1:${FREEPLAST_LOOPBACK_PORT:?set in .env by deploy.sh}:80"',
    'the compose port requires the .env value (fail-loud) and resolves to 127.0.0.1:<port>→80'
  );
  assert.ok(!portLine.includes('8092'), 'the compose port line carries no literal');

  section('Single-sourced staging constants (issue #16)', [
    'infra/staging.sh declares the hostname, install root and loopback port exactly once; every script sources it and no other infra file repeats the literals (one-place edit)',
    'nginx/staging.conf.tmpl carries only __-placeholders that deploy.sh renders from the shared values and the TLS convention; the render is byte-identical to the deployed vhost',
    'The Compose port drops its fallback literal and takes the deploy-written .env value (fail-loud); DEPLOYMENT.md records the deployed values and the re-run expectation',
  ]);
});

/* ─── 23e. Position-independent theme assets and balanced free-form blocks (issue #20) ── */

test('theme assets are position-independent and every free-form block is balanced (issue #20)', async () => {
  const THEME_DIR = join(WORDPRESS_DIR, 'wp-content', 'themes', 'freeplast');
  const htmlFiles = ['templates', 'parts']
    .flatMap((dir) => readdirSync(join(THEME_DIR, dir)).map((name) => `${dir}/${name}`))
    .sort();

  /* 23e.1 — No hardcoded absolute theme-asset paths remain in the block
     markup; the theme-owned images are referenced through the
     {{FREEPLAST_THEME_URL}} token that functions.php resolves at render
     time with get_theme_file_uri(). */
  const tokenAssets = {
    'parts/header.html': 'assets/img/mark.svg',
    'parts/footer.html': 'assets/img/mark.svg',
    'templates/front-page.html': 'assets/img/warehouse.webp',
  };
  const sources = new Map(htmlFiles.map((name) => [name, readFileSync(join(THEME_DIR, name), 'utf8')]));
  for (const [name, content] of sources) {
    assert.ok(!content.includes('/wp-content/themes/'), `${name} must not hardcode an absolute wp-content theme path`);
  }
  for (const [name, asset] of Object.entries(tokenAssets)) {
    assert.ok(
      sources.get(name).includes(`{{FREEPLAST_THEME_URL}}/${asset}`),
      `${name} must reference ${asset} through the {{FREEPLAST_THEME_URL}} token`
    );
  }

  /* 23e.2 — Every free-form (wp:html) block is independently balanced: no
     element may open in one block and close in another, so a Site Editor
     edit can never silently corrupt the v6 chrome across a block
     boundary. */
  const VOID_TAGS = new Set(['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr']);
  const TAG_PATTERN = /<(?<closing>\/)?(?<tag>[a-zA-Z][a-zA-Z0-9-]*)(?:"[^"]*"|'[^']*'|[^"'>])*(?<selfClosing>\/)?>/g;
  const balanced = (fragment) => {
    const stack = [];
    for (const { groups } of fragment.replace(/<!--[\s\S]*?-->/g, '').matchAll(TAG_PATTERN)) {
      const tag = groups.tag.toLowerCase();
      if (VOID_TAGS.has(tag) || groups.selfClosing) continue;
      if (groups.closing) {
        if (stack.pop() !== tag) return false;
      } else {
        stack.push(tag);
      }
    }
    return stack.length === 0;
  };
  for (const [name, content] of sources) {
    const blocks = [...content.matchAll(/<!-- wp:html -->([\s\S]*?)<!-- \/wp:html -->/g)];
    for (const [index, block] of blocks.entries()) {
      assert.ok(balanced(block[1]), `${name} free-form block #${index + 1} must be balanced on its own`);
    }
  }

  /* 23e.3 — At render time the token resolves through the WordPress theme
     API and nothing unresolved reaches a served document; the resolved
     assets are really served. */
  const themeAssetUri = (asset) =>
    wp(['eval', `echo wp_make_link_relative( get_theme_file_uri( "${asset}" ) );`]).stdout;
  const markUri = themeAssetUri('assets/img/mark.svg');
  const warehouseUri = themeAssetUri('assets/img/warehouse.webp');
  assert.match(markUri, /wp-content\/themes\/freeplast\/assets\/img\/mark\.svg$/, 'the logo URL must resolve from get_theme_file_uri()');
  assert.match(warehouseUri, /wp-content\/themes\/freeplast\/assets\/img\/warehouse\.webp$/, 'the hero-image URL must resolve from get_theme_file_uri()');
  /* The served <img> carries WordPress's lazy-loading attributes before
     src, so match the resolved src within the tag instead of a fixed tag
     prefix. */
  const imgSrc = (uri) => new RegExp(`<img[^>]*src="${uri.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}"`);
  const pages = new Map();
  for (const route of ['/', PRODUCT_URL, '/cotizacion/']) {
    const res = await get(route, MOBILE_UA);
    assert.equal(res.status, 200, `${route} must render to check the theme assets`);
    assert.ok(!res.body.includes('{{FREEPLAST_THEME_URL}}'), `${route} must not leak the unresolved theme-URL token`);
    assert.ok(imgSrc(markUri).test(res.body), `${route} must resolve the header logo through the theme API at render time`);
    pages.set(route, res.body);
  }
  assert.ok(
    imgSrc(warehouseUri).test(pages.get('/')),
    'Home must resolve the hero image through the theme API at render time'
  );
  /* The php -S built-in server sends no Content-Type for svg/webp, so the
     "served as an image" proof is the byte signature of the response. */
  const firstBytes = async (uri) => {
    const res = await fetch(SITE_URL + uri, { headers: { 'user-agent': MOBILE_UA } });
    assert.equal(res.status, 200, `${uri} must be reachable at the resolved URL`);
    return String.fromCharCode(...new Uint8Array((await res.arrayBuffer()).slice(0, 12)));
  };
  assert.match(await firstBytes(markUri), /^<svg /, 'the resolved logo URL must really serve the SVG mark');
  const webpHead = await firstBytes(warehouseUri);
  assert.ok(webpHead.startsWith('RIFF') && webpHead.includes('WEBP'), 'the resolved hero-image URL must really serve the WebP image');

  /* 23e.4 — Position independence, proven: booting the same disposable
     installation under a /subdir site URL renders subdirectory-correct
     asset URLs from the unchanged theme sources, and the rendered parts
     stay balanced as a whole. */
  const subdirPhp = join(BUILD_DIR, 'issue-20-subdir-loader.php');
  writeFileSync(
    subdirPhp,
    `<?php
/* Same disposable database as wp-config.php, different site URL: what a
   subdirectory install would render from the identical theme sources. */
define( 'WP_HOME', '${SITE_URL}/subdir' );
define( 'WP_SITEURL', '${SITE_URL}/subdir' );
define( 'DB_NAME', 'freeplast_disposable' );
define( 'DB_USER', 'db' );
define( 'DB_PASSWORD', 'db' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
$table_prefix = 'fp_';
define( 'ABSPATH', '${WP_DIR}/' );
require ABSPATH . 'wp-settings.php';

$rendered = '';
foreach ( array( 'parts/header.html', 'parts/footer.html' ) as $part ) {
	$rendered .= "\n" . do_blocks( file_get_contents( get_theme_file_path( $part ) ) );
}
echo $rendered;
`
  );
  const subdir = spawnSync(PHP_BIN, [subdirPhp], { encoding: 'utf8' });
  assert.equal(subdir.status, 0, `the subdirectory boot must succeed: ${subdir.stderr}`);
  assert.ok(
    subdir.stdout.includes('src="/subdir/wp-content/themes/freeplast/assets/img/mark.svg"'),
    'the unchanged theme sources must render subdirectory-correct asset URLs (position independence)'
  );
  assert.ok(!subdir.stdout.includes('{{FREEPLAST_THEME_URL}}'), 'no unresolved theme-URL token may survive rendering');
  assert.ok(!/src="\/wp-content\/themes\//.test(subdir.stdout), 'the subdirectory render must not fall back to root-absolute asset URLs');
  assert.ok(balanced(subdir.stdout), 'the rendered header/footer parts must be balanced as a whole');

  section('Position-independent assets and balanced free-form blocks (issue #20)', [
    'No template/part hardcodes an absolute wp-content theme path; header logo, footer brand mark and Home hero image render through get_theme_file_uri() at render time (the {{FREEPLAST_THEME_URL}} token resolved by the theme)',
    'The same theme sources render subdirectory-correct asset URLs when the disposable installation boots under a /subdir site URL; the resolved assets are reachable and served as images',
    'Every free-form (wp:html) block in the theme is balanced on its own — the header wrap/island containers are group block boundaries with the basket button between them, and the rendered parts stay balanced as a whole',
  ]);
});

/* ─── 24. Write VERIFICATION.md and clean up ──────────────────────────── */

test('record mechanical proof in wordpress/VERIFICATION.md', () => {
  const lines = [
    `# Mechanical verification — Freeplast WordPress shell + catalog + discovery + quote basket + quote request + sales workflow + durable notifications + delivery addresses + v6 content + hardened journey + staging deployment artifacts + operations handoff + single-sourced staging constants + one stored-meta JSON codec + scheme-following basket cookie Secure flag + position-independent theme markup + synchronization rollback discipline (issues #2–#17, #19–#20, #22)`,
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
    'A failed sync phase rolls its own media imports back (issue #22): a post-phase failure leaves zero orphaned attachments alongside the rolled-back posts, checksum-reused attachments are never deleted on any failure path, and recovery reuses the checksum-matched media instead of importing again',
    'Home renders the approved eight Featured Products in source-controlled order with quotation actions',
    '/tienda/ lists all 17 Active Products on one page; every card links its canonical URL and opens a quantity chooser',
    'Todos/Agrícola/Otros filters: labelled link controls with meaningful /tienda/categoria/<categoria>/ URLs and aria-current state; unknown categories 404',
    'Search finds Products (as cards with quotation actions) and standard pages, with a clear no-result state',
    'Related Products render up to three reviewed ids in reviewed order',
    'An archived Product disappears from Home, Tienda, categories, search and related lists, and its URL stops resolving',
    'Agregar a cotización opens a quantity chooser on catalog cards and the product page — never an unseen-quantity add',
    'A positive whole-unit quantity adds Caja Cosechera 3/4 through the authoritative nonce-guarded admin-post operation',
    'The session cookie carries only a random 256-bit opaque token (HttpOnly, SameSite=Lax, 30 days); only its sha256 hash is stored server-side',
    'The basket cookie Secure flag follows the request scheme (issue #19): Secure when the request presents TLS the way staging forwards it (X-Forwarded-Proto: https mapped by wp-config), absent on plain HTTP so basket persistence never silently breaks',
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
    'Quote Request persistence and its two durable notification jobs commit together (same record insert) or fail together — a failing job creation aborts the submission and retains basket + values',
    'Successful receipt confirms with the Request Reference and clears the basket before any external mail delivery is required (scheduled delivery event; nothing delivers until it runs)',
    'Exactly one sales notification and one customer acknowledgement per Request Reference; both carry reference, products, options and quantities; sales adds operational customer/dispatch details; Reply-To routing: sales → customer, customer → ventas@freeplast.cl',
    'Total or partial mail failure after persistence never duplicates the request or a successful delivery; delivery is idempotent (sent channels are never re-attempted); staff see the state and resend a failed channel safely (nonce + capability guarded)',
    'Staging containment: [STAGING] subject prefix plus configured recipient override (redirect), approved-recipient allowlist, or non-delivery (suppress); unconfigured environments fail closed; the event log records states/codes only — no customer field values',
    'Google assistance renders only inside the dispatch-conditional address block and only while provider credentials are configured; the credential never reaches the page',
    'The customer searches (plain POST or the JSON enhancement), selects a Chilean suggestion, reviews the formatted destination and confirms it explicitly; Cambiar restores search + manual entry',
    'The manual Dirección de despacho remains the always-available fallback (rural/unrecognized); a confirmed destination stands in for it on submission',
    'Confirmed destination data (mode, formatted address, place id, coordinates, provider, confirmation time) is stored on the fp_quote record; without dispatch nothing address-related is stored',
    'Driving distance is calculated from the configured Warehouse (provisional Camino El Arrayán 52) after durable persistence; provider failures never reject a valid request — they persist pending/error states with the destination preserved',
    'Dispatch Distance is an internal sales fact: the capability-protected Cotizaciones list/detail show km, origin and state with an explicit not-a-shipping-price note; nothing distance-like reaches the customer',
    'Authorized staff retry the calculation through a nonce + capability-guarded operation (bad nonce and capability-less users are denied)',
    'Credentials are environment-supplied (FREEPLAST_GOOGLE_API_KEY via getenv), absent from source control and the database; the provider is replaced at its narrow adapter boundary in every automated check',
    'Origin selection remains configuration: the stored fp_dispatch_origin option (seeded by migration 7) defines the recorded origin',
    'Complete journey: the one-Product journey passes with JavaScript disabled (Home → Tienda → product → chooser add → /cotizacion/ → submit → confirmation) and the multi-Product journey with the JSON enhancement (card chooser + optioned Color line + update)',
    'Keyboard: native controls in logical DOM order, <details> disclosures, announced mobile sheet, focusable linked error summary (tabindex=-1, #fragment focus, role=alert) with every link targeting a real labelled field',
    'Motion and sizing: prefers-reduced-motion collapses all nonessential motion; every interactive control declares ≥ 24px targets (primary ones 44px)',
    'Contrast: the frozen palette pairs meet WCAG AA (4.5:1 text and controls, 3:1 headings and focus outline)',
    'Responsive: one identical mobile-first document serves 375, 412, 768, 1024 and 1440 px with viewport meta everywhere; adaptation only through the declared 768/1024 breakpoints',
    'Abuse resistance: honeypot decoy, server-side minimum completion time (recoverable, ordinary retry succeeds) and bounded per-session throttling of persisted requests (only successes count) — rate keys are opaque session hashes, never raw IP/email',
    'Guard matrix: guarded admin mutations reject missing/foreign records with 404 and no mutation; a lost basket session never produces false success (cookie cleared for a fresh retry)',
    'Public-surface hygiene: no WooCommerce, cart/checkout, account or price machinery renders anywhere and customer registration stays disabled',
    'Safe migrations: a schema-fault migration marks a clear 503 public maintenance state (data preserved, admin notice), retries every request and self-heals once the fault clears',
    'Stock block theme (Twenty Twenty-Four): functional minimal Catalog archive, full product singles with chooser, basket, request submission and Cotizaciones administration',
    'Lifecycle preservation: theme switching, plugin deactivation/reactivation and the explicit uninstall.php preserve Products, Quote Requests with histories, basket sessions and configuration — only ephemeral transients and scheduled events are cleared',
    'Coding standards: php -l on every shipped PHP file; scans reject eval/extract/base64_decode/shell_exec/passthru/proc_open/popen, TODO/FIXME markers and missing ABSPATH/WP_UNINSTALL_PLUGIN guards',
    'Staging deployment artifacts (issue #14): dedicated freeplast-wordpress Compose project with private named volumes, loopback-only origin 127.0.0.1:8092, MariaDB healthcheck and a profile-gated WP-CLI sidecar; no literal secrets (all .env references)',
    'Staging Nginx vhost: approved-hostname-only server names, HTTP→HTTPS redirect, TLS rendered from the server convention, owner/client Basic Auth, X-Robots-Tag noindex always, upload limit, dotfile/sensitive denies and the loopback proxy with Host/Forwarded headers',
    'preflight.sh is read-only and collision-checks hostname, port, stack directory, Compose project, volumes, network, disk, DNS, certificate SAN/expiry and existing-container health — a collision aborts planning, never adoption',
    'deploy.sh: umask 077, server-generated secrets into mode-0600/0400 files (never the repository or command output), Compose validation before up, es_CL + America/Santiago + approved HTTPS URLs + blog_public 0, catalog sync gated on a zero-change dry run, Nginx backed up before the vhost and nginx -t before reload',
    'verify.sh walks the acceptance matrix through HTTPS (301/401/owner+client 200s, every route, noindex at both layers, WordPress identity, catalog idempotence, non-live mail mode); backup.sh dumps + hashes + rehearses the restore into temporary project names; rollback.sh is bounded to the new resources with volumes retained unless the owner explicitly purges',
    'DEPLOYMENT.md records every resource name, path, port, volume, backup and rollback scope — the on-server execution on OpenClaw is the documented operator step',
    `Shipped artifacts (issue #15): theme ${checksumLines[0].replace(/^Theme:\s*/, '')} and plugin ${checksumLines[1].replace(/^Plugin:\s*/, '')} recorded as deterministic ZIPs with SHA-256 checksums in dist/ (unzip -t clean; per-file manifest in dist/CHECKSUMS.sha256)`,
    'HANDOFF.md packages the verification record (infrastructure health, Nginx validation, syntax/coding standards, automated tests, migration version, active components, route statuses, browser console), the 17→17 catalog reconciliation with every provisional client fact, the full Quote Request acceptance matrix, mechanical-only accessibility observations, reproducible operator procedures, Gate 3 review URLs beside the frozen v6/v7-A references, pending owner/client actions and the separately-scoped release work',
    'Staging constants single-sourced (issue #16): hostname, install root and loopback port declared exactly once in infra/staging.sh; all five scripts source it; the Nginx vhost template renders from it byte-identically to the deployed configuration; the Compose port takes the deploy-written .env value with no fallback literal',
    'One shared JSON codec (issue #17): every stored-meta write/read goes through Freeplast_CQ_Codec with the byte-identical unescaped stored form — the retired per-class helpers are gone, round-trip identity holds on the persisted staging data and the catalog dry run after the swap reports zero changes',
    'Theme assets are position-independent (issue #20): no template/part hardcodes an absolute wp-content theme path; the header logo, footer mark and Home hero image render through get_theme_file_uri() at render time, and a subdirectory boot of the same sources renders subdirectory-correct asset URLs',
    'Every free-form (wp:html) block in the theme is balanced on its own (issue #20): the header wrap/island containers are group block boundaries with the basket button between them; the logo, navigation, burger and mobile sheet each stand in one self-contained block',
  ];
  for (const name of passed) lines.push(`| ${name} | pass |`);
  lines.push(``, `## Versions reported by the check`, ``);
  for (const line of versionLines) lines.push(`- ${line}`);
  lines.push(``, `## Shipped artifact checksums (issue #15)`, ``);
  for (const line of checksumLines) lines.push(`- ${line}`);
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
    `- The Quote Request submission (issue #8) is verified through served documents and the persisted fp_quote records: the manual Dirección de despacho is the fallback address path (the Google-assisted confirmation is verified with issue #11 below). Human visual approval remains Gate 3.`,
    `- The sales administration workflow (issue #9) is verified through served wp-admin documents and the persisted fp_quote metadata: corrections, notes, status history and staff identity live on the records; the Dispatch Distance staff retry renders on the same capability-protected detail. Human visual approval remains Gate 3.`,
    '- The durable notifications (issue #10) are verified through the persisted job/delivery state, the single external mail adapter seam (freeplast_cq_send_mail, replaced by the check) and the Cotizaciones detail: receipt never waits for delivery, WP-Cron is disabled on the disposable host so the scheduled delivery events run only when the check drives them (staging runs system cron), and the default mail mode fails closed to non-delivery until FREEPLAST_CQ_MAIL_MODE (or the freeplast_cq_mail_mode option) is configured.',
    `- The Google-assisted Delivery Address confirmation and Dispatch Distance (issue #11) are verified by replacing the Google provider at its narrow adapter boundary (freeplast_cq_google_client) with a mode-switchable fake — no check performs a network call or holds a real credential. The real client is only built when FREEPLAST_GOOGLE_API_KEY is present in the environment (Places + Routes APIs, Google-console restricted); origin selection (Camino El Arrayán 52 provisional, Santiago pending the client answer) and distance semantics stay the stored-option/filter configuration. Human visual approval remains Gate 3.`,
    `- The v6 content and navigation experience (issue #12) is verified through served documents on the clean disposable database; the frozen design contract lives in wordpress/design/ (tokens + hash-frozen approved prototypes). Pixel-level rendering and human visual approval remain Gate 3.`,
    `- The hardened journey (issue #13) is verified mechanically at the WordPress HTTP seam: accessibility structure (keyboard order, native disclosures, focus contract, labels, error-summary linkage), motion/target-size/contrast rules parsed from the shipped CSS, responsive widths observed through identical mobile-first documents, abuse resistance exercised in real time (the older sections use the documented deterministic pace backdate for their valid submissions), guard/failure matrices, the schema-fault maintenance injection at the freeplast_cq_schema_ready verification seam, the stock Twenty Twenty-Four fallback and lifecycle preservation including a real wp plugin delete with the directory restored afterwards. Browser-pixel rendering and human visual approval remain Gate 3.`,
    `- The staging deployment (issue #14) is verified as repository artifacts: the Compose stack, Nginx vhost, preflight/deploy/verify/backup/rollback scripts and DEPLOYMENT.md are parsed and asserted structurally (collision discipline, loopback-only origin, secret hygiene, order of the nginx backup/validation/reload steps, bounded rollback). The OpenClaw host is not reachable from this environment, so the on-server execution — preflight output, image digests, nginx -t and the HTTPS walk — is the operator runbook step recorded in DEPLOYMENT.md; human visual approval (Gate 3) of the deployed site remains pending with it.`,
    `- The verification and operations handoff (issue #15) is wordpress/HANDOFF.md: it records where every verification dimension lives (including the on-server infrastructure/Nginx/browser-console steps that remain operator actions), the 17→17 catalog reconciliation with per-Product provisional facts, the Quote Request acceptance matrix with reproduction pointers, mechanical accessibility observations explicitly labelled as not human approval, the reproducible operator procedures, the Gate 3 review URLs and the exact pending owner/client actions. The shipped theme/plugin ZIPs and their SHA-256 manifest in dist/ are rebuilt deterministically by every npm test run and verified with unzip -t; nothing in the handoff claims visual validation.`,
    `- The staging constants (issue #16) are single-sourced in infra/staging.sh and proven strictly behavior-preserving: the check renders the Nginx vhost template with the shared constants plus the recorded TLS convention and compares it byte-for-byte against the deployed configuration from the issue #14 operator run; the Compose stack resolves to the same loopback-only mapping from the deploy-written .env. The operator re-run (deploy.sh existing-stack path must be a no-op; verify.sh must still report “verification clean”) is recorded in DEPLOYMENT.md as the next on-server step.`,
    `- The basket cookie Secure flag follows the request scheme (issue #19): send_cookie() derives it from is_ssl() — the staging wp-config maps the Nginx-forwarded https scheme onto \$_SERVER['HTTPS'], so TLS responses keep the Secure cookie (staging behavior unchanged) and plain-HTTP installs keep a working basket. The disposable wp-config mirrors that mapping and the check presents both schemes at the real admin-post seam.`,
    `- The theme markup hygiene (issue #20) is verified as source + rendered behavior: templates/parts carry no absolute wp-content theme path — theme-owned images reference the {{FREEPLAST_THEME_URL}} token resolved by functions.php through get_theme_file_uri()/wp_make_link_relative() at render time — and a throwaway boot of the same disposable installation under a /subdir site URL proves the identical sources render subdirectory-correct URLs. The header part now carries its wrap/island containers as group block boundaries so every free-form (wp:html) block (logo, navigation, burger, mobile sheet) is balanced on its own; the extra flow-layout classes the group blocks receive are neutralized by the island margin reset in the theme stylesheet. Pixel fidelity of the restructured header at ~412 px and desktop remains Gate 3 human review.`,
    `- The synchronization rollback discipline (issue #22) is verified at the real apply seam with a disposable mu-plugin that short-circuits wp_insert_post for exactly one post type: faulting fp_product aborts the post phase after the media phase succeeded (created posts and the run-imported attachments roll back — zero orphaned attachments and no leftover uploads file), faulting attachment aborts the media phase mid-import after a checksum reuse had already resolved (only the run's own import is removed), and the fault-free recovery run applies the same source cleanly while reusing the checksum-matched media. The synchronizer only ever deletes attachments it imported itself and only while no surviving record still references them.`,
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
