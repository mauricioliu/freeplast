#!/usr/bin/env node
/**
 * Real-stack offline regression harness (issue #1 — Woo-side ports of #24/#27).
 *
 * Boot the disposable WordPress + SQLite + WooCommerce installation from
 * scripts/bootstrap.mjs, serve it with the PHP built-in server (multi-worker,
 * loopback only), drive scripts/woo-checkout-race.py through the Home
 * featured-grid contract (WA-04), the catalog search contract (post_type +
 * wc_query hook) and the concurrent-checkout attempt claim (WA-01). WordPress order state is re-checked through WP-CLI afterwards.
 *
 * Everything targets http://127.0.0.1:<port> — no staging, no external host,
 * no mail configured. The first run pays the one-off bootstrap cost; later
 * runs re-sync wp-content only. FREEPLAST_SKIP_STACK=1 skips this harness
 * (documented escape hatch).
 */
import { spawn, spawnSync } from 'node:child_process';
import { closeSync, existsSync, mkdirSync, openSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { randomUUID } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';

const HERE = dirname(fileURLToPath(import.meta.url));
const WORDPRESS_DIR = dirname(HERE);
const PHP = join(WORDPRESS_DIR, '.tools', 'php', 'php');
const WPCLI = join(WORDPRESS_DIR, '.tools', 'cache', 'wp-cli.phar');
const WP_DIR = join(WORDPRESS_DIR, '.build', 'wp');
const SITE_URL = process.env.FREEPLAST_TEST_URL || 'http://127.0.0.1:8091';
const PORT = Number(new URL(SITE_URL).port) || 80;

function sh(cmd, args, opts = {}) {
  const result = spawnSync(cmd, args, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], ...opts });
  if (result.status !== 0) throw Error(`${cmd} ${args.join(' ')} failed:\n${result.stderr || result.stdout}`);
  return result.stdout ? result.stdout.trim() : '';
}

function sleep(ms) {
  Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, false, ms);
}

async function fetchCode(path) {
  try {
    const response = await fetch(SITE_URL + path, { redirect: 'manual', signal: AbortSignal.timeout(60_000) });
    return response.status;
  } catch {
    return 0;
  }
}

async function fetchBody(path) {
  try {
    const response = await fetch(SITE_URL + path, { signal: AbortSignal.timeout(60_000) });
    return response.status === 200 ? await response.text() : '';
  } catch {
    return '';
  }
}

/* A fetch bound to its own cookie jar: each journey that must hold a session
 * (the public chrome journey, an owner or ventas draft reading) gets its own
 * jar, kept current from every Set-Cookie response. */
function makeCookieFetch() {
  const jar = new Map();
  return async (path, opts = {}) => {
    const headers = { ...(opts.headers || {}) };
    if (jar.size > 0) { headers.cookie = [...jar.entries()].map(([k, v]) => `${k}=${v}`).join('; '); }
    const response = await fetch(SITE_URL + path, { ...opts, headers, redirect: 'manual', signal: AbortSignal.timeout(60_000) });
    for (const raw of (response.headers.getSetCookie ? response.headers.getSetCookie() : [])) {
      const pair = raw.split(';')[0];
      const eq = pair.indexOf('=');
      if (eq > 0) { jar.set(pair.slice(0, eq), pair.slice(eq + 1)); }
    }
    return response;
  };
}

/* A real wp-login.php session for one user; returns the login status. */
async function wpLogin(fetcher, user, pass) {
  await fetcher('/wp-login.php');
  const posted = await fetcher('/wp-login.php', {
    method: 'POST',
    headers: { 'content-type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ log: user, pwd: pass, 'wp-submit': 'Log In', redirect_to: `${SITE_URL}/wp-admin/`, testcookie: '1' }).toString(),
  });
  return posted.status;
}

// Class order is presentation, not the search contract. The theme may put
// product-card before Woo's product token; parse tokens rather than prefixes.
export function countProductCards(html) {
  const dom = new JSDOM(html);
  try { return dom.window.document.querySelectorAll('ul.products > li.product').length; }
  finally { dom.window.close(); }
}

export function serverRenderedText(html) {
  const dom = new JSDOM(html);
  try {
    dom.window.document.querySelectorAll('script,style,template,[hidden]').forEach(node => node.remove());
    return dom.window.document.body.textContent;
  } finally { dom.window.close(); }
}

let checks = 0;
function check(ok, message) {
  checks++;
  if (!ok) throw Error(`stack harness: ${message}`);
}

export async function runStackHarness() {
  if (process.env.FREEPLAST_SKIP_STACK === '1') {
    console.log('stack harness: skipped (FREEPLAST_SKIP_STACK=1)');
    return 0;
  }

  if (!existsSync(PHP) || !existsSync(WPCLI)) {
    sh('bash', [join(HERE, 'fetch-tools.sh')], { stdio: 'inherit' });
  }

  /* 0. The port must not be owned by a foreign server before we bind it. */
  {
    const code = await fetchCode('/');
    if (code !== 0) throw Error(`stack harness: ${SITE_URL} already answers (HTTP ${code}) — a foreign server owns the port`);
  }

  /* 1. Provision (idempotent) and verify the disposable stack. */
  sh(process.execPath, [join(HERE, 'bootstrap.mjs')], { stdio: 'inherit', env: { ...process.env, FREEPLAST_TEST_URL: SITE_URL } });
  const provisioned = JSON.parse(readFileSync(join(WORDPRESS_DIR, '.build', '.provisioned.json'), 'utf8'));
  check(provisioned.siteUrl === SITE_URL, `provisioned URL ${provisioned.siteUrl} does not match ${SITE_URL}`);

  /* 1b. Mail containment + notification-event log for this run (issue #31):
     the disposable stack must never deliver anything, and every mail ATTEMPT
     is the observable record-notification event the acceptance criteria
     count. Since issue #50 the log also extracts, from the intercepted body,
     the private draft link's request id — the observable that proves the
     owner notice points at the corresponding draft. The mu-plugin is written
     straight into the disposable install and never exists in the repository's
     wp-content. */
  const mailLog = join(WORDPRESS_DIR, '.build', 'mail-log.jsonl');
  rmSync(mailLog, { force: true });
  mkdirSync(join(WP_DIR, 'wp-content', 'mu-plugins'), { recursive: true });
  writeFileSync(
    join(WP_DIR, 'wp-content', 'mu-plugins', 'fpw-stack-maillog.php'),
    `<?php
/** Disposable-stack only (written by woo-stack-harness.mjs): block every mail
 * attempt and record it — the offline substitute for the receipt-notification
 * events. Counts subjects only, plus the draft request id the body names; no
 * bodies, no recipients. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
add_filter( 'pre_wp_mail', static function ( $result, $atts ) {
	$draft_request = null;
	if ( preg_match( '/page=fpw-quote-draft&(?:amp;|#038;)?request=(\\d+)/', (string) ( $atts['message'] ?? '' ), $m ) ) {
		$draft_request = (int) $m[1];
	}
	$entry = wp_json_encode( array( 'subject' => (string) ( $atts['subject'] ?? '' ), 'draft_request' => $draft_request ) );
	if ( is_string( $entry ) ) { file_put_contents( dirname( ABSPATH ) . '/mail-log.jsonl', $entry . "\\n", FILE_APPEND ); }
	return true; // blocked: the disposable stack never delivers mail.
}, PHP_INT_MAX, 2 );
`,
  );
  writeFileSync(
    join(WP_DIR, 'wp-content', 'mu-plugins', 'fpw-stack-nonces.php'),
    `<?php
/** Disposable-stack only (written by woo-stack-harness.mjs, issue #33): mints the
 * CURRENT user's nonce for a requested admin action — the exact string the native
 * UI would carry for that actor. It lets the restricted-session regression prove
 * denials are PERMISSIONS (valid session + valid nonce) rather than CSRF, even
 * where the interface rightly no longer offers the denied control. The mint only
 * produces a nonce string; it grants nothing. Never shipped in the repository's
 * wp-content. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
add_action( 'wp_ajax_fpw_test_nonce', static function () {
	if ( ! is_user_logged_in() ) { wp_send_json_error( array( 'reason' => 'not-logged-in' ), 403 ); }
	$for = (string) ( $_REQUEST['for'] ?? '' );
	if ( '' === $for || strlen( $for ) > 100 ) { wp_send_json_error( array( 'reason' => 'bad-action' ), 400 ); }
	wp_send_json_success( array( 'nonce' => wp_create_nonce( $for ) ) );
} );
`,
  );

  /* 2. Serve (multi-worker so two checkout POSTs can genuinely overlap). The
     spawn is detached so the whole process GROUP (master + every worker) can
     be signalled: a plain master SIGTERM has been observed to leave workers
     holding the listening socket, poisoning every later run on the port. */
  const traceFile = join(WORDPRESS_DIR, '.build', 'fatal-trace.log');
  rmSync(traceFile, { force: true });
  writeFileSync(join(WORDPRESS_DIR, '.build', 'fpw-trace-prepend.php'), `<?php
register_shutdown_function( static function () {
	$e = error_get_last();
	if ( $e && E_ERROR === $e['type'] ) {
		file_put_contents( dirname( ABSPATH ) . '/fatal-trace.log', $e['message'] . ' in ' . $e['file'] . ':' . $e['line'] . "\n" . ( new Exception() )->getTraceAsString() . "\n====\n", FILE_APPEND );
	}
} );
`);
  const serverLogFile = join(WORDPRESS_DIR, '.build', 'server.log');
  rmSync(serverLogFile, { force: true });
  /* The server output MUST go to a file, not to pipes: node blocks its event
     loop inside spawnSync while the race/matrix subprocesses run, and nobody
     drains a pipe then — once the 64KB pipe buffer filled, every worker
     blocked on its access-log write and the whole stack stalled mid-suite. */
  const serverLogFd = openSync(serverLogFile, 'a');
  const server = spawn(PHP, ['-d', 'max_execution_time=10', '-d', `auto_prepend_file=${join(WORDPRESS_DIR, '.build', 'fpw-trace-prepend.php')}`, '-S', `127.0.0.1:${PORT}`, join(HERE, 'router.php')], {
    cwd: WORDPRESS_DIR,
    env: { ...process.env, PHP_CLI_SERVER_WORKERS: '8' },
    stdio: ['ignore', serverLogFd, serverLogFd],
    detached: true,
  });

  try {
    let home = 0;
    for (let attempt = 0; attempt < 60 && home !== 200; attempt++) {
      home = await fetchCode('/');
      if (home !== 200) sleep(500);
    }
    check(home === 200, `the disposable stack never answered 200 (last ${home})\n${readFileSync(serverLogFile, 'utf8').slice(-800)}`);

    /* 2b. Catalog search contract (2026-09-07 staging regression): the adapter's
       search hook forces post_type=product AND wc_query=product_query — without
       the latter wc_setup_loop() defaults total=0 and archive-product.php skips
       its while loop, rendering an empty <ul> even though the main query found
       the products (the ?s=…&post_type=product route always worked because the
       URL makes Woo run product_query natively). */
    const liProducts = countProductCards;
    const search = await fetchBody('/?s=caja');
    const searchPlural = await fetchBody('/?s=cajas');
    const searchNone = await fetchBody('/?s=zz-sin-coincidencias');
    const searchCount = liProducts(search);
    const searchPluralCount = liProducts(searchPlural);
    check(searchCount >= 1, `catalog search 'caja' rendered ${searchCount} products — the search loop is collapsing to an empty <ul> (wc_query/search contract regressed)`);
    check(searchPluralCount === searchCount, `plural search 'cajas' rendered ${searchPluralCount} products vs ${searchCount} for 'caja' — the conservative plural normalization regressed`);
    const emptySearch = new JSDOM(searchNone);
    try {
      check(liProducts(searchNone) === 0 && emptySearch.window.document.querySelector('.fp-catalog .empty-state h2')?.textContent === 'No encontramos «zz-sin-coincidencias».', 'a no-match search must render the theme empty state through the native archive path');
    } finally { emptySearch.window.close(); }
    /* 2026-09-07 visual regression (owner screenshot): products rendered but
       unstyled — Woo's catalog CSS (woocommerce-layout.css grid floats, the
       .woocommerce button skin) and the theme's woo.css are all scoped to the
       woocommerce/woocommerce-page body classes, which wc_body_class() only
       adds when its page conditionals match; a plain search matches none. The
       adapter's body_class filter must ship the same pair it emits for
       is_woocommerce(). */
    const bodyClasses = (html) => ((html.match(/<body[^>]*class="([^"]*)"/) || [])[1] || '').split(/\s+/);
    const searchBodyClasses = bodyClasses(search);
    const searchNoneBodyClasses = bodyClasses(searchNone);
    check(searchBodyClasses.includes('woocommerce') && searchBodyClasses.includes('woocommerce-page'),
          `a plain search page must ship Woo's body scope classes — got [${searchBodyClasses.join(', ')}]; without them the product grid renders as an unstyled list`);
    check(searchNoneBodyClasses.includes('woocommerce') && searchNoneBodyClasses.includes('woocommerce-page'),
          `a no-match search page must ship Woo's body scope classes — got [${searchNoneBodyClasses.join(', ')}]`);

    /* 2c. Added-to-cart count pill (owner request, 2026-09-08): the number shown
       on the card after an add is read from the SAME fragment the header count
       uses — never a parallel count — so (a) every loop route must enqueue the
       pill script and (b) the classic AJAX add, the exact request the cards
       fire, must answer it with a real numeric span.fpw-basket-count count. */
    {
      const homeForPill = await fetchBody('/');
      check(/assets\/js\/loop-added-count\.js\?ver=/.test(homeForPill),
            'home must enqueue loop-added-count.js — the added-count pill would never render');
      /* Only SIMPLE products carry Woo's add_to_cart_button loop anchor; a
         variable product's loop link cannot be added directly. */
      const productId = (homeForPill.match(/<a[^>]*class="[^"]*\badd_to_cart_button\b[^"]*"[^>]*data-product_id="(\d+)"/) || [])[1];
      check(Boolean(productId), 'home must expose an add-to-cart product id for the pill data-contract probe');
      const addResponse = await fetch(SITE_URL + '/?wc-ajax=add_to_cart', {
        method: 'POST',
        headers: { 'content-type': 'application/x-www-form-urlencoded' },
        body: 'product_id=' + productId + '&quantity=2',
        signal: AbortSignal.timeout(60_000),
      });
      check(addResponse.status === 200, `the classic add-to-cart AJAX must answer 200 (got ${addResponse.status})`);
      const payload = await addResponse.json();
      const fragment = payload && payload.fragments && payload.fragments['span.fpw-basket-count'];
      const count = typeof fragment === 'string' ? (fragment.match(/fpw-basket-count">\s*(\d+)\s*</) || [])[1] : null;
      check(count !== null && Number(count) >= 1,
            `the add-to-cart fragments must carry a numeric span.fpw-basket-count count (got ${JSON.stringify(fragment)})`);
    }

    /* 2d. Issue #41 — A · Directa shared chrome + the minimal native journey.
       The chrome renders on public routes with real destinations, the count is
       the native distinct-line count (add → other page → reload → removal →
       zero), /cotizacion/ is the REAL Cart block, checkout stays CLASSIC
       (ADR-0001), the 17 reference products are seeded, and no review-only
       prototype tooling reaches the served site. A tiny cookie jar carries
       the WooCommerce session cookie across the requests — the exact
       persistence the journey must survive. */
    let placesJourneyIds = null;   // filled inside the block below (issue #59)
    {
      const jarFetch = makeCookieFetch();
      const headerCount = (html) => Number((html.match(/fpw-basket-count">\s*(\d+)\s*</) || [])[1]);

      const homeChrome = await fetchBody('/');
      check(/class="header-inner"/.test(homeChrome), 'home renders the A · Directa header row');
      check(/class="header-selection" href="\/cotizacion\/"/.test(homeChrome), 'the header selection links the native cart page');
      check(/class="count"><span class="fpw-basket-count">\d+<\/span><\/span>/.test(homeChrome), 'the header count badge server-renders a number');
      check(/id="fp-menu"/.test(homeChrome) && /id="fp-help"/.test(homeChrome), 'the native menu and help dialogs ship with the chrome');
      check(/class="site-footer"/.test(homeChrome), 'the A footer band renders');
      check(/assets\/js\/nav\.js\?ver=/.test(homeChrome), 'the chrome script is enqueued');
      check(/rel="preload"[^>]*manrope\.woff2/.test(homeChrome), 'the local Manrope file is preloaded');
      for (const forbidden of ['prototype-bar', 'prototype-notice', 'data-scenario', 'data-switch', 'Escenarios']) {
        check(!homeChrome.includes(forbidden), `no review-only prototype tooling on the served site: ${forbidden}`);
      }

      const shop = await (await jarFetch('/tienda/')).text();
      const journeyId = (shop.match(/<a[^>]*class="[^"]*\badd_to_cart_button\b[^"]*"[^>]*data-product_id="(\d+)"/) || [])[1];
      check(Boolean(journeyId), 'the catalog exposes a native add-to-cart id for the journey');
      /* Issue #42: A · Directa cards on the native shop surface. */
      check(/class="[^"]*\bproduct-card\b[^"]*"/.test(shop), 'shop products render through the A · Directa card');
      check(/class="photo-link"/.test(shop) && /class="product-photo"/.test(shop), 'cards render the A photo surface');
      check(/class="product-category">Agrícola|class="product-category">Otros/.test(shop), 'cards carry the catalog category kicker');
      check(shop.includes('Elegir color'), 'a variable card offers Elegir color into its native product page');
      check(/<input[^>]*class="[^"]*\bqty\b[^"]*"[^>]*type="number"|<input[^>]*type="number"[^>]*class="[^"]*\bqty\b/.test(shop), 'simple cards expose Woo\'s native quantity input');
      check(/data-fpw-selection-dock/.test(shop), 'the catalog route ships the mobile selection dock');
      check(!/prototype-bar|data-scenario|Escenarios/.test(shop), 'no review tooling rides the catalog surface');
      /* Issue #43: A discovery tools over native queries. */
      check(/class="catalog-tools"/.test(shop) && /role="search"/.test(shop) && /name="s"/.test(shop), 'the A search toolbar renders on the native shop route');
      const termCounts = JSON.parse(sh(PHP, [WPCLI, 'eval', `
        $counts = array();
        foreach (array('agricola'=>'Agrícola', 'otros'=>'Otros') as $slug=>$label) {
          $term = get_term_by('slug', $slug, 'product_cat');
          if (!$term) { throw new RuntimeException('Missing owned category fixture'); }
          $counts[$label] = (int) $term->count;
        }
        echo wp_json_encode($counts);
      `, `--url=${SITE_URL}`, `--path=${WP_DIR}`, '--user=1']).split('\n').pop());
      const filterDom = new JSDOM(shop);
      try {
        const tabs = filterDom.window.document.querySelector('.filter-tabs[aria-label="Categorías"]');
        check(Boolean(tabs) && Object.entries(termCounts).every(([label, count]) => [...tabs.querySelectorAll('a')].some(a => a.firstChild?.textContent.trim() === label && a.querySelector('span')?.textContent === String(count))), `native category filters must match current WP term counts ${JSON.stringify(termCounts)}`);
      } finally { filterDom.window.close(); }
      check(/woocommerce-result-count/.test(shop) && /<strong>\d+<\/strong> productos/.test(shop), 'the native loop total is presented in A copy');
      check(/name="orderby"/.test(shop) && shop.includes('>Destacados<') && shop.includes('>Nombre A–Z<') && !shop.includes('>Popularity<') && !shop.includes('>Price'), 'ordering offers exactly the two reference choices');
      const searchAccent = await fetchBody('/?s=caj%C3%A1');
      const accentCount = liProducts(searchAccent);
      const searchPlain = await fetchBody('/?s=caja');
      check(accentCount >= 1 && accentCount === liProducts(searchPlain), `an accented query matches like the reference (${accentCount} vs ${liProducts(searchPlain)})`);
      const searchNoneA = await fetchBody('/?s=zz-sin-coincidencias');
      check(/No encontramos «/.test(searchNoneA) && searchNoneA.includes('Ver todos los productos'), 'the A no-results state names the query and offers the catalog return');
      check(/data-fp-dialog="fp-help"/.test(searchNoneA), 'the no-results state offers real help');
      const nativeRoutes = JSON.parse(sh(PHP, [WPCLI, 'eval', `
        $simple = get_page_by_path('caja-cosechera-3-4', OBJECT, 'product');
        $color = get_page_by_path('caja-universal-cerrada-color', OBJECT, 'product');
        $term = get_term_by('slug', 'agricola', 'product_cat');
        if (!$simple || !$color || !$term) { throw new RuntimeException('Missing owned catalog fixture'); }
        echo wp_json_encode(array('simple'=>get_permalink($simple), 'color'=>get_permalink($color), 'category'=>get_term_link($term)));
      `, `--url=${SITE_URL}`, `--path=${WP_DIR}`, '--user=1']).split('\n').pop());
      const nativePath = value => { const url = new URL(value); check(url.origin === new URL(SITE_URL).origin, 'fixture route must stay on the isolated origin'); return url.pathname + url.search; };
      const categoryPage = await fetchBody(nativePath(nativeRoutes.category));
      check(/class="filter"[^>]*aria-current="page"[^>]*>Agrícola/.test(categoryPage), 'the actual native category route marks Agrícola current');
      const sorted = await fetchBody('/tienda/?orderby=title');
      const firstSortedTitle = (sorted.match(/woocommerce-loop-product__title"><a href="[^"]*">([^<]+)</) || [])[1];
      check(Boolean(firstSortedTitle), 'Nombre A–Z ordering renders product titles');
      check(/name="orderby"/.test(sorted), 'the ordering control survives with a query applied');
      /* Issue #44: A product sheets on the native surfaces. */
      const simpleSheet = await fetchBody(nativePath(nativeRoutes.simple));
      check(/product_title">Caja Cosechera 3\/4/.test(simpleSheet), 'the simple sheet renders its native title');
      check(/Detalles que importan\./.test(simpleSheet), 'the summary title renders');
      check(/Ficha técnica completa/.test(simpleSheet), 'the technical disclosure renders');
      check(/data-fpw-detail-added/.test(simpleSheet) && /Sin compra ni reserva de stock\./.test(simpleSheet), 'the sheet carries the added-state slot and the no-purchase note');
      const colorSheet = await fetchBody(nativePath(nativeRoutes.color));
      check(/name="attribute_color"/.test(colorSheet), 'the variable sheet keeps the native color select');
      check(/product-color-options\.js\?ver=/.test(colorSheet), 'the color enhancement ships on the variable sheet');
      check(/variation-button-state\.js\?ver=/.test(colorSheet), 'the variation button state regression ships');
      check(/RELATED|related/.test(colorSheet), 'related products render on the sheet');
      const add = await jarFetch('/?wc-ajax=add_to_cart', {
        method: 'POST',
        headers: { 'content-type': 'application/x-www-form-urlencoded' },
        body: 'product_id=' + journeyId + '&quantity=3',
      });
      check(add.status === 200, `the journey add must answer 200 (got ${add.status})`);
      const nativeFragments = (await add.json()).fragments || {};
      const addedFragment = nativeFragments['span.fpw-basket-count'];
      const dockFragment = nativeFragments['div.fpw-selection-dock'] || '';
      check(dockFragment.includes('1 producto seleccionado') && dockFragment.includes('3 unidades') && !/data-fpw-selection-dock[^>]*hidden/.test(dockFragment), 'actual wc-ajax response includes the complete dock even without page conditionals');
      check(typeof addedFragment === 'string' && Number((addedFragment.match(/(\d+)/) || [])[1]) === 1,
            `the add answers a distinct-line count of 1 (got ${JSON.stringify(addedFragment)})`);

      const otherPage = await jarFetch('/nosotros/');
      check(otherPage.status === 200, `the Nosotros destination must answer 200 (got ${otherPage.status})`);
      const reloaded = await (await jarFetch('/')).text();
      check(headerCount(reloaded) === 1, `a reload keeps the selection and server-renders count 1 (got ${headerCount(reloaded)})`);
      const shopAfterAdd = await (await jarFetch('/tienda/')).text();
      check(/data-fpw-selection-dock(?![^>]*hidden)/.test(shopAfterAdd), 'with a live selection the dock renders unhidden on the catalog route');
      check(/1 producto seleccionado/.test(shopAfterAdd), 'the dock states the native single-line truth');
      check(/unidades agregadas/.test(shopAfterAdd), 'the card added state shows the product\'s own units after the journey add');

      const cartPage = await (await jarFetch('/cotizacion/')).text();
      check(/wp-block-woocommerce-cart/.test(cartPage), '/cotizacion/ renders the REAL Cart block, not the classic shortcode fixture');
      check(!/\[woocommerce_cart\]/.test(cartPage), 'no raw cart shortcode leaks on /cotizacion/');
      const checkoutPage = await (await jarFetch('/checkout/'));
      const checkoutHtml = await checkoutPage.text();
      check(checkoutPage.status === 200 && /woocommerce-checkout/.test(checkoutHtml), 'the CLASSIC checkout form still renders');
      check(!/wp-block-woocommerce-checkout/.test(checkoutHtml), 'the checkout must NOT have migrated to Checkout Blocks (ADR-0001)');
      for (const path of ['/contacto/', '/politica-de-privacidad/']) {
        const code = await fetchCode(path);
        check(code === 200, `${path} must stay reachable (got ${code})`);
      }

      /* Issues #45/#46: the A basket and details routes over native
         surfaces (markup-level checks; hydrated block behavior is the
         browser scenario). */
      const basketPage = await (await jarFetch('/cotizacion/')).text();
      check(/Pasos de la solicitud/.test(basketPage) && /<h1>Productos a Cotizar<\/h1>/.test(basketPage), 'the A basket heading and steps render');
      check(/wp-block-woocommerce-cart/.test(basketPage) && /wp-block-woocommerce-proceed-to-checkout-block/.test(basketPage), 'the REAL Cart block with its native CTA block');
      check(/Aún no agregas productos\./.test(basketPage) && /Elegir productos/.test(basketPage), 'the A empty state ships with the page markup');
      check(!serverRenderedText(basketPage).includes('$'), 'no currency amount in server-rendered basket text (scripts and technical payloads are not public copy)');
      const detailsPage = await jarFetch('/datos-y-envio/');
      const detailsHtml = await detailsPage.text();
      check(detailsPage.status === 200, `the Datos y envío route must answer 200 (got ${detailsPage.status})`);
      check(/<form name="checkout"/.test(detailsHtml) && /class="checkout woocommerce-checkout"/.test(detailsHtml), 'ONE classic native checkout form');
      check(!/wp-block-woocommerce-checkout/.test(detailsHtml), 'no Checkout Blocks migration (ADR-0001)');
      check((detailsHtml.match(/name="billing_fp_dispatch"/g) || []).length === 2 && detailsHtml.includes('value="si"') && detailsHtml.includes('value="no"'), 'dispatch is one native radio name carrying the two accepted values');
      check(!detailsHtml.includes('<select'), 'no dispatch select contradicts the radios');
      check(/01<\/span> Contacto/.test(detailsHtml) && /02<\/span> Empresa/.test(detailsHtml) && /03<\/span> Despacho/.test(detailsHtml) && /04<\/span> Algo más que debamos saber/.test(detailsHtml), 'the four A groups render');
      check(/woocommerce-checkout-review-order-table/.test(detailsHtml) && detailsHtml.includes('Editar productos'), 'the summary holds the native review table and Editar productos');
      check(/name="fpw_attempt"/.test(detailsHtml), 'the hidden submitted-attempt identity is present');
      check(/name="fpw_place_id"/.test(detailsHtml) && /name="fpw_place_scope"/.test(detailsHtml), 'the address-provenance carriers render empty in the served form (issue #59)');
      check(/name="fpw_place_id" value=""/.test(detailsHtml), 'the provenance carriers ship empty: the server never echoes posted provenance (issue #59)');
      check(!/places\.js\?ver=/.test(detailsHtml) && !/maps\.googleapis\.com/.test(detailsHtml), 'without an authorized Places configuration the assistant never loads and no Google contact ships (issue #59)');
      check(/name="woocommerce_checkout_place_order"/.test(detailsHtml), 'the native place-order trigger is preserved');
      check(/checkout-form\.js\?ver=/.test(detailsHtml), 'the A checkout enhancement ships');
      check(/fields\.js\?ver=1\.0\.4/.test(detailsHtml), 'the adapter field enhancement ships at its current pinned version');
      check(!serverRenderedText(detailsHtml).includes('$'), 'no currency amount in server-rendered details text');
      /* Issue #48: corporate pages keep the shared chrome without inheriting
         the product grid; Home carries the A cards through the native loop. */
      const corporate = await (await jarFetch('/nosotros/')).text();
      check(/class="header-inner"/.test(corporate) && /class="site-footer"/.test(corporate), 'a corporate page keeps the shared chrome');
      check(!/ul class="products|product-card/.test(corporate), 'an ordinary page does not inherit the product grid');
      check(!/prototype-bar|data-scenario|Escenarios|DEMO ·/.test(corporate), 'no demo tooling reaches corporate pages');
      const homeAgain = await fetchBody('/');
      check(/<ul[^>]*class="[^"]*products/.test(homeAgain) || /product-card/.test(homeAgain), 'Home renders its featured products through the shared card surface');

      /* Native removal through the cart's own Store API (the same mutations
         the Cart block performs), then the zero state on a fresh page load. */
      const storeCart = await jarFetch('/wp-json/wc/store/v1/cart');
      check(storeCart.status === 200, `the Store API cart must answer 200 (got ${storeCart.status})`);
      const storeNonce = storeCart.headers.get('nonce');
      const storeData = await storeCart.json();
      check(Array.isArray(storeData.items) && storeData.items.length === 1,
            `the native cart holds one distinct line after the journey add (got ${JSON.stringify(storeData.items && storeData.items.length)})`);
      const removed = await jarFetch('/wp-json/wc/store/v1/cart/remove-item', {
        method: 'POST',
        headers: { 'content-type': 'application/json', ...(storeNonce ? { nonce: storeNonce } : {}) },
        body: JSON.stringify({ key: storeData.items[0].key }),
      });
      check(removed.status === 200, `the native remove-item must answer 200 (got ${removed.status})`);
      const zeroPage = await (await jarFetch('/')).text();
      check(headerCount(zeroPage) === 0, `the empty selection server-renders the zero state (got ${headerCount(zeroPage)})`);

      /* Issue #59 journey: the dispatch-address assistance over the REAL native
         POST. An assisted confirmation keeps ONLY its recorded claim, a payload
         with a malformed place id degrades to a plainly manual address, and
         «Sin despacho» excludes the destination AND every place association.
         No Google exists on this stack: the server's provenance contract is
         what's under test, exactly as the criteria require. */
      const placesAddress = 'Camino El Arrayán 52, San Francisco de Mostazal';
      const hiddenValues = (html) => {
        const values = {};
        for (const match of html.matchAll(/<input[^>]*type="hidden"[^>]*>/g)) {
          const name = (match[0].match(/name="([^"]+)"/) || [])[1];
          if (!name || name in values) { continue; }
          values[name] = (match[0].match(/value="([^"]*)"/) || [])[1] ?? '';
        }
        return values;
      };
      const placesJourney = async (fields, label) => {
        const jar = makeCookieFetch();
        const add = await jar('/?wc-ajax=add_to_cart', { method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' }, body: 'product_id=' + journeyId + '&quantity=2' });
        check(add.status === 200, `places journey ${label}: the add must answer 200 (got ${add.status})`);
        const page = await (await jar('/datos-y-envio/')).text();
        const hidden = hiddenValues(page);
        check(hidden['woocommerce-process-checkout-nonce'] && hidden['fpw_attempt'] && hidden['fpw_place_id'] === '', `places journey ${label}: the native form carries its identity with empty carriers`);
        const posted = { ...hidden,
          billing_first_name: 'PRUEBA LOCAL DESPACHO', billing_phone: '+56 9 1234 5678',
          billing_email: `despacho-${label}@example.invalid`, billing_company: 'PRUEBA NO COMERCIAL',
          billing_fp_rut: '76.123.456-7', billing_fp_giro: 'Prueba local',
          payment_method: 'quotes-gateway', order_comments: 'Recorrido local automatizado (no atender)',
          ...fields };
        const response = await jar('/?wc-ajax=checkout', { method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(posted).toString() });
        const payload = await response.json();
        check(payload && payload.result === 'success' && /\/order-received\/(\d+)/.test(String(payload.redirect || '')), `places journey ${label}: a valid request lands natively (got ${JSON.stringify(payload).slice(0, 140)})`);
        return Number((String(payload.redirect).match(/\/order-received\/(\d+)/) || [])[1]);
      };
      placesJourneyIds = {
        asistida: await placesJourney({ billing_fp_dispatch: 'si', billing_fp_address: placesAddress, fpw_place_id: 'ChIJfreesideQ9fXhZplaces-fixture', fpw_place_scope: 'exacta' }, 'asistida'),
        degradada: await placesJourney({ billing_fp_dispatch: 'si', billing_fp_address: 'Camino rural sin asistente, Mostazal', fpw_place_id: 'no space allowed', fpw_place_scope: 'exacta' }, 'degradada'),
        sindespacho: await placesJourney({ billing_fp_dispatch: 'no', billing_fp_address: '', fpw_place_id: 'ChIJfreesideQ9fXhZplaces-fixture', fpw_place_scope: 'amplia' }, 'sin-despacho'),
      };

      /* The controlled reference fixture: all 17 products, categories, and the
         two 5-color variable products — synthetic run-owned data only. */
      const seeded = sh(PHP, [WPCLI, 'eval', `
        $names = array();
        foreach (get_posts(array('post_type' => 'product', 'post_status' => 'publish', 'numberposts' => -1)) as $post) { $names[] = $post->post_name; }
        $variable = get_posts(array('post_type' => 'product', 'name' => 'caja-universal-cerrada-color', 'post_status' => 'any', 'numberposts' => 1));
        $variations = 0;
        if ($variable) { $product = wc_get_product($variable[0]->ID); $variations = $product ? count($product->get_children()) : 0; }
        echo wp_json_encode(array('names' => $names, 'variations' => $variations));
      `, `--url=${SITE_URL}`, `--path=${WP_DIR}`, '--user=1']);
      const fixture = JSON.parse(seeded.split('\n').pop());
      const reference = JSON.parse(readFileSync(join(WORDPRESS_DIR, 'data', 'products.json'), 'utf8'));
      const referenceSlugs = (Array.isArray(reference) ? reference : reference.products).map((item) => item.slug);
      const missing = referenceSlugs.filter((slug) => !fixture.names.includes(slug));
      check(missing.length === 0, `the disposable catalog must seed all 17 reference products (missing: ${missing.join(', ')})`);
      check(fixture.variations === 5, `the reference color product carries its 5 named variations (got ${fixture.variations})`);
    }

    /* 3. Home + race(repeated) + replay + correct + renew + lost + inflight
       + preserve + stranger + isolation over real HTTP. */
    const py = process.env.PYTHON || 'python3';
    const scenario = spawnSync(py, [join(HERE, 'woo-checkout-race.py'), '--base', SITE_URL], { encoding: 'utf8', timeout: 300_000 });
    check(scenario.status === 0, `checkout scenarios failed:\n${scenario.stdout || ''}\n${scenario.stderr || ''}`);
    const outcomes = JSON.parse(scenario.stdout.trim().split('\n').pop());
    const raceOrders = outcomes.race_orders.map(Number);
    const raceOrder = raceOrders[raceOrders.length - 1];
    const renewOrder = Number(outcomes.renew_order);
    const correctOrder = Number(outcomes.correct_order);
    const isolateOrder = Number(outcomes.isolate_order);
    check(outcomes.home.total_links > 0 && outcomes.home.unnamed === 0, `Home delivered ${outcomes.home.unnamed} unnamed links of ${outcomes.home.total_links}`);
    check(outcomes.home.no_shortcode_wrapper, 'Home still routes the grid through the wp:shortcode wpautop renderer');
    check(raceOrders.length === 3, `the concurrent test must run its bounded three rounds (got ${raceOrders.length})`);
    check(raceOrders.every((id) => Number.isInteger(id) && id > 0), `a race round produced no order: ${raceOrders}`);
    check(new Set(raceOrders).size === raceOrders.length, `the bounded rounds interfered with each other: ${raceOrders}`);
    check(outcomes.replay_recovered_order === raceOrder, `the replay of the landed attempt must recover the SAME request (${outcomes.replay_recovered_order} vs ${raceOrder})`);
    check(outcomes.attempt_token_rotated === true, 'the checkout form must rotate the attempt token after a landing');
    check(Number.isInteger(renewOrder) && renewOrder > 0, 'the identical rebuild produced no new request');
    check(renewOrder !== raceOrder && renewOrder !== correctOrder, `a new submission after a completion must NOT return the previous request (renew ${renewOrder} vs race ${raceOrder}/correct ${correctOrder})`);
    check(Number.isInteger(isolateOrder) && isolateOrder !== raceOrder, 'a different attempt must never fold into the race order');
    const lostOrder = Number(outcomes.lost_order);
    const inflightOrder = Number(outcomes.inflight_order);
    check(Number.isInteger(lostOrder) && lostOrder > 0, 'the lost-response scenario produced no order');
    check(lostOrder !== raceOrder && lostOrder !== correctOrder && lostOrder !== renewOrder && lostOrder !== isolateOrder,
          `the lost-response retry must return its own original request, never another one (lost ${lostOrder})`);
    check(Number.isInteger(inflightOrder) && inflightOrder > 0 && inflightOrder !== lostOrder,
          `the in-flight scenario produced no distinct order (inflight ${outcomes.inflight_order})`);
    check(outcomes.inflight_retry_recoverable === true, 'the in-flight retry answered unrecoverably');
    check(outcomes.preserve_recovers_original === true, 'the old form retry with a new selection must recover the original confirmation');
    check(outcomes.preserve_selection_identical === true && outcomes.preserve_cart_lines === 2,
          `the recovery must leave the two-line selection (simple + variant) EXACTLY intact (${outcomes.preserve_cart_lines} lines, identical=${outcomes.preserve_selection_identical})`);
    const staleOrder = Number(outcomes.stale_order);
    check(outcomes.replayunknown_rejected === true && outcomes.replayunknown_selection_identical === true && outcomes.replayunknown_cart_lines === 2,
          `an unknown token over a full new selection must be rejected safely with every line, variant and quantity preserved (rejected=${outcomes.replayunknown_rejected}, identical=${outcomes.replayunknown_selection_identical}, lines=${outcomes.replayunknown_cart_lines})`);
    check(outcomes.stale_older_form_recovers_original === true && outcomes.stale_selection_identical === true
          && outcomes.stale_b_form_recovers_own === true && outcomes.stale_repeat_recovers_original === true,
          `an OLDER completed form must recover its OWN attempt after a newer one landed — read-only, selection intact (A=${outcomes.stale_older_form_recovers_original}, identical=${outcomes.stale_selection_identical}, B=${outcomes.stale_b_form_recovers_own}, repeat=${outcomes.stale_repeat_recovers_original})`);
    check(outcomes.plainreplay_rejected === true && outcomes.plainreplay_selection_identical === true && outcomes.plainreplay_cart_lines === 2,
          `a completed attempt replayed through the PLAIN form route must get the cart-preserving rejection — never the fold (rejected=${outcomes.plainreplay_rejected}, identical=${outcomes.plainreplay_selection_identical}, lines=${outcomes.plainreplay_cart_lines})`);
    check(Number.isInteger(staleOrder) && staleOrder > 0 && staleOrder !== raceOrder && staleOrder !== correctOrder && staleOrder !== renewOrder && staleOrder !== lostOrder && staleOrder !== inflightOrder && staleOrder !== isolateOrder,
          `the fresh form after rotation must produce its own new request (stale ${outcomes.stale_order})`);
    const lostmultiA = Number(outcomes.lostmulti_a_order);
    const lostmultiB = Number(outcomes.lostmulti_b_order);
    check(outcomes.lostmulti_retry_recovers_a === true && outcomes.lostmulti_selection_identical === true && outcomes.lostmulti_cart_lines === 2,
          `A's lost-response retry after B completed must recover A — not B, not «sesión caducada» — with the third selection intact (recovers=${outcomes.lostmulti_retry_recovers_a}, identical=${outcomes.lostmulti_selection_identical}, lines=${outcomes.lostmulti_cart_lines})`);
    check(outcomes.scenario_ids_unique === true && outcomes.new_request_count === 11,
          `the scenario ledger must show 11 pairwise-distinct new-request ids (unique=${outcomes.scenario_ids_unique}, count=${outcomes.new_request_count}, ids=${JSON.stringify(outcomes.scenario_orders)})`);
    check(outcomes.unknown_token_safe === true && outcomes.foreign_session_safe === true,
          'unknown or foreign-session attempts must receive the safe rejection, never the recovered reference');

    /* 4. WordPress state behind the responses (WP-CLI, read-only): each new
       request keeps its own record — pending status, quote meta, its own
       attempt identity, its own lines and its own submitted details — while
       the identical rebuild (renew) carries DIFFERENT identity with EQUAL
       content. The notification-event count closes the criterion: exactly one
       sales + one customer notification per new request, never duplicated for
       folds/replays. */
    const phpCode = `
      global $wpdb;
      $out = array();
      $ids = array('race' => ${raceOrder}, 'renew' => ${renewOrder}, 'correct' => ${correctOrder}, 'isolate' => ${isolateOrder}, 'lost' => ${lostOrder}, 'inflight' => ${inflightOrder}, 'lostmulti_a' => ${lostmultiA}, 'lostmulti_b' => ${lostmultiB}, 'stale' => ${staleOrder});
      foreach ($ids as $key => $id) {
        $order = wc_get_order($id);
        $quantities = array();
        foreach ($order->get_items() as $item) { $quantities[] = $item->get_quantity(); }
        $details = $order->get_meta('_fp_submitted_details');
        $out[$key] = array(
          'id' => $id,
          'status' => $order->get_status(),
          'qwc' => (string) $order->get_meta('_qwc_quote'),
          'attempt' => (string) $order->get_meta('_fpw_attempt'),
          'billing_email' => $order->get_billing_email(),
          'quantities' => $quantities,
          'has_details' => is_array($details) && !empty($details),
        );
      }
      $lookup = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'fpw_attempt_' . $out['race']['attempt']));
      $out['lookup_row'] = array('id' => (int) (string) $lookup, 'for_order' => ${raceOrder});
      $lookup_a = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'fpw_attempt_' . $out['lostmulti_a']['attempt']));
      $out['lookup_row_a'] = array('id' => (int) (string) $lookup_a, 'for_order' => ${lostmultiA});
      echo wp_json_encode($out);
    `;
    const state = sh(PHP, [WPCLI, 'eval', phpCode, `--url=${SITE_URL}`, `--path=${WP_DIR}`, '--user=1']);
    const parsed = JSON.parse(state.split('\n').pop());
    const totalUnits = (entry) => entry.quantities.reduce((a, b) => a + b, 0);
    for (const [key, entry] of Object.entries(parsed)) {
      if (key === 'lookup_row' || key === 'lookup_row_a') continue;
      check(entry.status === 'pending', `request ${entry.id} left the pending quote state (status ${entry.status})`);
      check(entry.qwc === '1', `request ${entry.id} lost the quote meta`);
      check(entry.attempt.length === 64, `request ${entry.id} does not carry a bound attempt identity`);
      check(entry.has_details, `request ${entry.id} lost its submitted details`);
      check(entry.quantities.length > 0, `request ${entry.id} has no lines`);
    }
    check(totalUnits(parsed.race) === 70 && totalUnits(parsed.renew) === 70, 'the rebuild must reproduce the identical selection (70 units)');
    check(parsed.race.billing_email === parsed.renew.billing_email, 'the identical rebuild must carry the same submitted details (content repeats; requests must not)');
    check(parsed.race.attempt !== parsed.renew.attempt, 'the identical rebuild must carry a DIFFERENT attempt identity');
    check(parsed.correct.billing_email === parsed.renew.billing_email, 'the corrected retry keeps the same customer details');
    check(parsed.lookup_row.id === parsed.lookup_row.for_order, `the durable lookup row does not resolve to the race order (${JSON.stringify(parsed.lookup_row)})`);
    /* #36 rev 2: the lostmulti ORIGINALS — not merely "not-B". A is the record
       the retry returned: its own distinct attempt identity, its durable
       lookup resolving to itself (exactly one original A), its own 70-unit
       selection and submitted details; B carries its own 6-unit identity; the
       stale request carries the rotated attempt's own 7-unit record. */
    check(totalUnits(parsed.lostmulti_a) === 70, `the recovered original A must carry A's own 70-unit selection (${parsed.lostmulti_a.quantities})`);
    check(totalUnits(parsed.lostmulti_b) === 6, `B must carry its own 6-unit selection (${parsed.lostmulti_b.quantities})`);
    check(totalUnits(parsed.stale) === 7, `the stale scenario's fresh request must carry the rotated attempt's own two-line selection (${parsed.stale.quantities})`);
    check(parsed.lookup_row_a.id === parsed.lookup_row_a.for_order && parsed.lookup_row_a.id === Number(outcomes.lostmulti_a_order),
          `A's durable lookup must resolve to the record the retry returned — exactly one original A (${JSON.stringify(parsed.lookup_row_a)} vs retry ${outcomes.lostmulti_a_order})`);
    check(new Set([parsed.race.attempt, parsed.renew.attempt, parsed.lostmulti_a.attempt, parsed.lostmulti_b.attempt, parsed.stale.attempt]).size === 5,
          'each scenario record carries its OWN attempt identity');
    check(parsed.lostmulti_a.has_details && parsed.lostmulti_b.has_details && parsed.stale.has_details, 'the lostmulti/stale records keep their submitted details');

    /* 5. Notification events: exactly one sales + one customer notification
       per NEW request — DERIVED from the scenario ledger (3 race rounds +
       correct + renew + lost + lostmulti A + lostmulti B + inflight + stale
       + isolate = 11) PLUS the three issue-#59 dispatch journeys, never
       duplicated for folds, replays, recoveries or identity-gate rejections. */
    const mails = existsSync(mailLog) ? readFileSync(mailLog, 'utf8').trim().split('\n').filter(Boolean) : [];
    const newRequestTotal = outcomes.new_request_count + 3;   // the ledger PLUS the three issue-#59 dispatch journeys
    check(mails.length === 2 * newRequestTotal, `expected exactly ${2 * newRequestTotal} notification events (2 per new request × ${newRequestTotal}), got ${mails.length}:\n${mails.join('\n')}`);
    const subjects = mails.map((line) => { try { return JSON.parse(line).subject ?? ''; } catch { return '?'; } });
    check(subjects.every((s) => s.length > 0), 'every notification event carries a subject');

    /* 5b. Issue #50: the owner notice rides the EXISTING admin email — no new
       notification surface. Each new request's intercepted owner mail links
       its OWN draft exactly once; no customer acknowledgement ever does. */
    const scenarioIds = Object.values(outcomes.scenario_orders).map(Number);
    const allRequestIds = [...scenarioIds, placesJourneyIds.asistida, placesJourneyIds.degradada, placesJourneyIds.sindespacho];
    const draftMails = mails.map((line) => JSON.parse(line)).filter((m) => m.draft_request !== null);
    check(draftMails.length === newRequestTotal, `exactly one owner notice with a draft link per new request (${newRequestTotal}), got ${draftMails.length}`);
    for (const id of allRequestIds) {
      check(draftMails.filter((m) => m.draft_request === id).length === 1, `request ${id}'s owner notice must link its own draft exactly once`);
    }

    /* 5c. Issue #50 journey: solicitud real → registro → aviso interceptado →
       lectura privada. Every scenario request keeps exactly ONE durable draft
       built from its own native record (reference, attempt identity, items,
       identity, Submitted Details, explicit pendings); a record that never
       went through the checkout receipt carries none; the private screen
       reads for an authorized owner session, DENIES a valid ventas session
       (permission, not CSRF), sends a visitor to the login, and invents
       nothing for missing records. */
    const idsList = allRequestIds.join(',');
    const draftState = JSON.parse(sh(PHP, [WPCLI, 'eval', `
      global $wpdb;
      $out = array('drafts' => array(), 'records' => array());
      foreach (array(${idsList}) as $id) {
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'fpw_draft_' . $id));
        $order = wc_get_order($id);
        $lines = array();
        foreach ($order->get_items() as $item) { $lines[] = array('name' => $item->get_name(), 'quantity' => (int) $item->get_quantity()); }
        $out['drafts'][$id] = is_string($raw) ? json_decode($raw, true) : null;
        $out['records'][$id] = array(
          'reference' => (string) $order->get_order_number(),
          'attempt' => (string) $order->get_meta('_fpw_attempt'),
          'email' => (string) $order->get_billing_email(),
          'company' => (string) $order->get_billing_company(),
          'lines' => $lines,
          'details' => is_array($order->get_meta('_fp_submitted_details')) ? count($order->get_meta('_fp_submitted_details')) : 0,
        );
      }
      $manual = new WC_Order();
      $manual->set_created_via('admin');
      $manual->set_status('pending');
      $manual->save();
      $out['manual_order'] = $manual->get_id();
      $out['manual_has_draft'] = (bool) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'fpw_draft_' . $manual->get_id()));
      echo wp_json_encode($out);
    `, `--url=${SITE_URL}`, `--path=${WP_DIR}`, '--user=1']).split('\n').pop());
    for (const id of scenarioIds) {
      const draft = draftState.drafts[id];
      const record = draftState.records[id];
      check(draft && draft.order_id === id, `request ${id} keeps its durable initial draft`);
      check(draft.reference === record.reference && String(record.reference).startsWith('FP-'), `request ${id}'s draft carries the native request reference`);
      check(draft.attempt === record.attempt && draft.attempt.length === 64, `request ${id}'s draft binds its own attempt identity`);
      check(JSON.stringify((draft.items || []).map((l) => [l.name, l.quantity])) === JSON.stringify(record.lines.map((l) => [l.name, l.quantity])), `request ${id}'s draft lines come from the record, not a later basket`);
      check(draft.identity && draft.identity.email === record.email && draft.identity.company === record.company, `request ${id}'s draft identity comes from the record`);
      check(draft.destination && 'dispatch' in draft.destination, `request ${id}'s draft carries the recorded destination`);
      check(draft.enrichment && draft.enrichment.prices === 'pending' && draft.enrichment.history === 'pending' && draft.enrichment.dispatch === 'pending', `request ${id}'s draft names prices/history/dispatch pending, never zero`);
      check(draft.submitted_details && Object.keys(draft.submitted_details).length === record.details, `request ${id}'s draft preserves its Submitted Details`);
      check(draft.schema === 2, `request ${id}'s draft uses the run's single schema (issue #59 destination-provenance shape)`);
    }
    check(draftState.manual_has_draft === false, `a record created without a checkout receipt gets no draft (order ${draftState.manual_order})`);

    /* 5d. Issue #59: the recorded destination provenance over real records —
       only the confirmed address plus its allowed identification persists;
       a malformed payload degrades to plainly manual; «Sin despacho» keeps
       no destination and no place association at all. */
    const placesState = JSON.parse(sh(PHP, [WPCLI, 'eval', `
      global $wpdb;
      $out = array();
      foreach (array('asistida' => ${placesJourneyIds.asistida}, 'degradada' => ${placesJourneyIds.degradada}, 'sindespacho' => ${placesJourneyIds.sindespacho}) as $key => $id) {
        $order = wc_get_order($id);
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'fpw_draft_' . $id));
        $out[$key] = array(
          'id' => $id,
          'dispatch' => (string) $order->get_meta('_billing_fp_dispatch'),
          'address' => (string) $order->get_meta('_billing_fp_address'),
          'source' => (string) $order->get_meta('_billing_fp_address_source'),
          'place_id' => (string) $order->get_meta('_billing_fp_place_id'),
          'scope' => (string) $order->get_meta('_billing_fp_place_scope'),
          'all_meta' => wp_json_encode($order->get_meta_data()),
          'draft_destination' => is_string($raw) ? (json_decode($raw, true)['destination'] ?? null) : null,
        );
      }
      echo wp_json_encode($out);
    `, `--url=${SITE_URL}`, `--path=${WP_DIR}`, '--user=1']).split('\n').pop());
    {
      const assisted = placesState.asistida;
      check(assisted.dispatch === 'si' && assisted.address === 'Camino El Arrayán 52, San Francisco de Mostazal', `the assisted record keeps the confirmed address (got ${assisted.address})`);
      check(assisted.source === 'asistida' && assisted.place_id === 'ChIJfreesideQ9fXhZplaces-fixture' && assisted.scope === 'exacta', `the assisted record keeps exactly its claim (got ${JSON.stringify([assisted.source, assisted.place_id, assisted.scope])})`);
      check(!assisted.all_meta.includes('coordinates') && !/-33\./.test(assisted.all_meta), 'no coordinate ever persists');
      check(assisted.draft_destination && assisted.draft_destination.source === 'asistida' && assisted.draft_destination.place_id === 'ChIJfreesideQ9fXhZplaces-fixture', 'the draft snapshot carries the recorded provenance');
      const degraded = placesState.degradada;
      check(degraded.source === 'manual' && degraded.place_id === '' && degraded.scope === '', `a malformed place id degrades to a plainly manual record (got ${JSON.stringify([degraded.source, degraded.place_id, degraded.scope])})`);
      check(degraded.address === 'Camino rural sin asistente, Mostazal', 'the degraded record keeps the valid manual address: the request was never lost');
      check(!degraded.all_meta.includes('no space allowed'), 'the malformed value never reaches the record');
      check(degraded.draft_destination && degraded.draft_destination.source === 'manual' && degraded.draft_destination.place_id === '', 'the degraded draft names the manual provenance');
      const none = placesState.sindespacho;
      check(none.dispatch === 'no' && none.address === '', '«Sin despacho» keeps no destination (existing rule intact over the real POST)');
      check(none.source === '' && none.place_id === '' && none.scope === '', '«Sin despacho» keeps no place association, even with stale place fields posted');
      check(!none.all_meta.includes('ChIJ'), 'no place identification of any shape survives a no-dispatch request');
      check(none.draft_destination && none.draft_destination.dispatch === 'no' && none.draft_destination.source === '', 'the no-dispatch draft carries no invented provenance');
    }

    /* The private reading over real HTTP: owner opens the intercepted
       notice's destination from a fresh authenticated session. */
    const draftOwner = 'fp-draft-owner';
    const draftOwnerPass = `owner-${randomUUID().replace(/-/g, '').slice(0, 18)}`;
    const draftVentas = 'fp-draft-ventas';
    const draftVentasPass = `ventas-${randomUUID().replace(/-/g, '').slice(0, 18)}`;
    const draftWpArgs = [`--path=${WP_DIR}`, `--url=${SITE_URL}`];
    try {
      sh(PHP, [WPCLI, 'user', 'create', draftOwner, `${draftOwner}@example.invalid`, '--role=administrator', `--user_pass=${draftOwnerPass}`, ...draftWpArgs, '--quiet']);
      sh(PHP, [WPCLI, 'user', 'create', draftVentas, `${draftVentas}@example.invalid`, '--role=ventas_freeplast', `--user_pass=${draftVentasPass}`, ...draftWpArgs, '--quiet']);

      const owner = makeCookieFetch();
      const ownerLogin = await wpLogin(owner, draftOwner, draftOwnerPass);
      check(ownerLogin === 302, `the owner session must log in over real HTTP (got ${ownerLogin})`);
      const draftUrl = `/wp-admin/admin.php?page=fpw-quote-draft&request=${raceOrder}`;
      const screen = await owner(draftUrl);
      check(screen.status === 200, `the private draft must open for the authorized owner session (got ${screen.status})`);
      const screenHtml = await screen.text();
      const raceRecord = draftState.records[raceOrder];
      check(screenHtml.includes(raceRecord.reference), 'the private reading shows the request reference');
      for (const line of raceRecord.lines) { check(screenHtml.includes(String(line.quantity)) && screenHtml.includes(line.name), `the private reading shows the stored line ${line.name} × ${line.quantity}`); }
      check(screenHtml.includes(raceRecord.company) && screenHtml.includes(raceRecord.email), 'the private reading shows the stored identity');
      check((screenHtml.match(/Pendiente/g) || []).length >= 3, 'prices and the dispatch estimate read as pending on the screen (history carries its own live state since #54)');
      check(!screenHtml.replace(/<script[\s\S]*?<\/script>/g, '').includes('$'), 'no price amount renders on the private draft');
      check(screenHtml.includes('Cliente nuevo') === false, 'missing history is never presented as a customer verdict (the exact unresolved wording is pinned after the deterministic import reset below)');
      const reread = await (await owner(draftUrl)).text();
      const region = (html) => { const start = html.indexOf('<div class="wrap fpw-draft">'); const end = html.indexOf('<!-- fpw-draft:end -->'); return start >= 0 && end > start ? html.slice(start, end) : null; };
      check(region(reread) !== null && region(reread) === region(screenHtml), 'the private read is stable: the draft screen markup changes nothing');

      /* Issue #59: the private review reads the destination provenance — the
         assisted claim named for what it is, the degraded request plainly
         manual, and the dispatch estimate still pending (no routes here). */
      const assistedScreen = await (await owner(`/wp-admin/admin.php?page=fpw-quote-draft&request=${placesJourneyIds.asistida}`)).text();
      check(assistedScreen.includes('Procedencia de la dirección') && assistedScreen.includes('Confirmada con el asistente de direcciones'), 'the private reading names the assisted provenance (issue #59)');
      check(assistedScreen.includes('Coincidencia exacta') && assistedScreen.includes('ChIJfreesideQ9fXhZplaces-fixture'), 'the private reading shows the claim and its scope');
      const degradedScreen = await (await owner(`/wp-admin/admin.php?page=fpw-quote-draft&request=${placesJourneyIds.degradada}`)).text();
      check(degradedScreen.includes('Ingresada manualmente') && !degradedScreen.includes('Place ID'), 'the degraded request reads as plainly manual, with no invented place row (issue #59)');

      /* Negative permission with a VALID session (no nonce applies to a GET
         read): ventas' exact approved caps never open the private draft. */
      const ventas = makeCookieFetch();
      const ventasLogin = await wpLogin(ventas, draftVentas, draftVentasPass);
      check(ventasLogin === 302, `the ventas session must log in over real HTTP (got ${ventasLogin})`);
      const denied = await ventas(draftUrl);
      check(denied.status === 403, `a valid ventas session must be DENIED the private draft (got ${denied.status})`);

      /* A visitor with the direct link is sent to the login — knowing the
         link grants nothing. */
      const visitor = makeCookieFetch();
      const anon = await visitor(draftUrl);
      check(anon.status === 302 && String(anon.headers.get('location') || '').includes('wp-login.php'), 'a visitor with the direct link is sent to the login, never shown data');

      /* A request without a draft reads honestly; bogus ids invent nothing. */
      const manualScreen = await (await owner(`/wp-admin/admin.php?page=fpw-quote-draft&request=${draftState.manual_order}`)).text();
      check(manualScreen.includes('Sin borrador') && !manualScreen.includes('Borrador inicial'), 'a record without a draft reads honestly, without inventing one');
      const bogus = await owner('/wp-admin/admin.php?page=fpw-quote-draft&request=99999999');
      check(bogus.status === 200 && (await bogus.text()).includes('no encontrada'), 'an unknown request id answers an honest empty state');

      /* 5d. Issue #51 journey: the owner completes and adjusts drafts
         manually over real HTTP — real edit → save → reopen with recovered
         values, pending-not-zero, no-dispatch contracts, destination/quantity
         dispatch review, stale-edit conflicts that preserve the accepted
         revision, server validation without a gratuity policy, CSRF +
         permission boundaries with valid nonces, and source records that
         stay untouched. Fixture-driven: the race request has dispatch=no,
         the corrected request dispatch=si, the stale request two lines. */
      {
        const editUrl = (requestId) => `/wp-admin/admin.php?page=fpw-quote-draft&request=${requestId}`;
        const mailCount = () => (existsSync(mailLog) ? readFileSync(mailLog, 'utf8').trim().split('\n').filter(Boolean).length : 0);
        const mint = async (fetcher, forAction) => {
          const minted = await fetcher('/wp-admin/admin-ajax.php', { method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ action: 'fpw_test_nonce', for: forAction }).toString() });
          const payload = await minted.json();
          return payload && payload.success ? String(payload.data.nonce) : null;
        };
        const postForm = (fetcher, requestId, fields) => fetcher(editUrl(requestId), { method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(fields).toString() });
        const lineFields = (record, priceFor, quantityFor) => {
          const fields = {};
          record.lines.forEach((line, index) => {
            fields[`fpw_work[lines][${index}][quantity]`] = String(quantityFor ? quantityFor(index, line) : line.quantity);
            const price = priceFor ? priceFor(index, line) : null;
            fields[`fpw_work[lines][${index}][price]`] = price === null ? '' : String(price);
          });
          return fields;
        };
        const mailsBefore = mailCount();
        const correctRecord = draftState.records[correctOrder];

        /* The no-dispatch request: no working destination or freight exists to
           edit — sin despacho is different from dispatch not yet priced. */
        const noDispatchInitial = await (await owner(editUrl(raceOrder))).text();
        check(!noDispatchInitial.includes('name="fpw_work[destination]"') && !noDispatchInitial.includes('name="fpw_work[dispatch_amount]"'), 'a no-dispatch draft offers no working destination or freight input');
        check(noDispatchInitial.includes('no incluye destino de entrega ni flete'), 'the no-dispatch work offer states it excludes the delivery destination and freight');
        const noDispatchSave = await postForm(owner, raceOrder, {
          'fpw_work_save': '1', 'fpw_work_revision': '0', 'fpw_draft_nonce': await mint(owner, `fpw-draft-save-${raceOrder}`),
          ...lineFields(draftState.records[raceOrder], (i) => (i === 0 ? 1490 : null)),
          'fpw_work[destination]': 'Intento de destino', 'fpw_work[dispatch_amount]': '5000',
        });
        check((await noDispatchSave.text()).includes('Cambios guardados (revisión 1)'), 'the no-dispatch draft saves its line work');
        const noDispatchReopened = await (await owner(editUrl(raceOrder))).text();
        check(/name="fpw_work\[lines\]\[0\]\[price\]" value="1490"/.test(noDispatchReopened), 'the saved price is recovered on reopening');
        check(!noDispatchReopened.includes('Intento de destino') && !noDispatchReopened.includes('name="fpw_work[dispatch_amount]"'), 'the posted destination and freight stay out of a no-dispatch draft');

        /* The dispatch request: real edit → save → reopen, every chosen value
           recovered. */
        const initial = await (await owner(editUrl(correctOrder))).text();
        const baseRevision = (initial.match(/name="fpw_work_revision" value="(\d+)"/) || [])[1];
        check(baseRevision === '0', `a fresh draft renders its editing form at revision 0 (got ${baseRevision})`);
        check(initial.includes('Camino de prueba 1, Mostazal'), 'the working destination pre-fills from the recorded address');
        const saveNonce = await mint(owner, `fpw-draft-save-${correctOrder}`);
        check(typeof saveNonce === 'string' && saveNonce.length >= 10, 'the editing form carries a mintable CSRF nonce for the owner');
        const saved = await postForm(owner, correctOrder, {
          'fpw_work_save': '1', 'fpw_work_revision': baseRevision, 'fpw_draft_nonce': saveNonce,
          ...lineFields(correctRecord, (i) => (i === 0 ? 1490 : null)),
          'fpw_work[destination]': 'Plaza de Armas 123, Santiago', 'fpw_work[dispatch_amount]': '39990',
        });
        check(saved.status === 200, `the owner's save must answer 200 (got ${saved.status})`);
        check((await saved.text()).includes('Cambios guardados (revisión 1)'), 'a valid save confirms the revision it stored');
        const reopened = await (await owner(editUrl(correctOrder))).text();
        check(/name="fpw_work\[lines\]\[0\]\[price\]" value="1490"/.test(reopened), 'the saved line price is recovered on reopening');
        check(reopened.includes('Plaza de Armas 123, Santiago') && /39\.990 CLP/.test(reopened) && reopened.includes('ingreso manual'), 'the saved working destination and dispatch amount recover with their manual origin');
        check((reopened.match(/name="fpw_work_revision" value="(\d+)"/) || [])[1] === '1', 'the form re-renders from the saved revision');
        check(!/(^|[^.\d])0 CLP/.test(reopened) && !reopened.includes('value="0"'), 'no pending amount ever reads as zero');

        /* The dispatch amount keeps the conditions it was entered for: moving
           the working destination (amount unchanged) marks it for review. */
        const moved = await postForm(owner, correctOrder, {
          'fpw_work_save': '1', 'fpw_work_revision': '1', 'fpw_draft_nonce': await mint(owner, `fpw-draft-save-${correctOrder}`),
          ...lineFields(correctRecord, (i) => (i === 0 ? 1490 : null)),
          'fpw_work[destination]': 'Camino rural 9, Colchane', 'fpw_work[dispatch_amount]': '39990',
        });
        check((await moved.text()).includes('Requiere revisión'), 'a destination change after saving a dispatch amount marks it for review');

        /* A quantity change requires dispatch review too; the original request
           stays visible beside the working values. */
        const requantifiedHtml = await (await postForm(owner, correctOrder, {
          'fpw_work_save': '1', 'fpw_work_revision': '2', 'fpw_draft_nonce': await mint(owner, `fpw-draft-save-${correctOrder}`),
          ...lineFields(correctRecord, (i) => (i === 0 ? 1490 : null), (i, line) => (i === 0 ? line.quantity + 5 : line.quantity)),
          'fpw_work[destination]': 'Camino rural 9, Colchane', 'fpw_work[dispatch_amount]': '39990',
        })).text();
        check(requantifiedHtml.includes('Requiere revisión'), 'a quantity change keeps the dispatch amount under review');
        check(requantifiedHtml.includes('Pedido: ' + correctRecord.lines[0].quantity), 'the originally requested quantity stays visible beside the working one');

        /* Stale edits are detected, refused, and the accepted edit is shown
           preserved — nothing is silently overwritten or merged. */
        const staleHtml = await (await postForm(owner, correctOrder, {
          'fpw_work_save': '1', 'fpw_work_revision': '1', 'fpw_draft_nonce': await mint(owner, `fpw-draft-save-${correctOrder}`),
          ...lineFields(correctRecord, () => 1),
          'fpw_work[destination]': 'Sobrescritura', 'fpw_work[dispatch_amount]': '1',
        })).text();
        check(staleHtml.includes('no se guardó') && staleHtml.includes('revisión más reciente'), 'a stale edit is refused with the conflict message');
        check(/name="fpw_work_revision" value="3"/.test(staleHtml), 'the conflict screen re-renders from the accepted revision');
        check(staleHtml.includes('Camino rural 9, Colchane') && !staleHtml.includes('Sobrescritura'), 'the accepted edit is preserved; the stale submission is not merged in');

        /* Server-side validation: a zero price is refused (no unapproved
           gratuity policy) and the stored values stand. */
        const zeroHtml = await (await postForm(owner, correctOrder, {
          'fpw_work_save': '1', 'fpw_work_revision': '3', 'fpw_draft_nonce': await mint(owner, `fpw-draft-save-${correctOrder}`),
          ...lineFields(correctRecord, (i) => (i === 0 ? 0 : null)),
          'fpw_work[destination]': 'Camino rural 9, Colchane', 'fpw_work[dispatch_amount]': '39990',
        })).text();
        check(zeroHtml.includes('No se guardó nada') && zeroHtml.includes('deja el campo vacío'), 'a zero price is refused server-side with the pending escape hatch');
        check(/name="fpw_work\[lines\]\[0\]\[price\]" value="1490"/.test(zeroHtml), 'the refused save left the stored values intact');

        /* A two-line request keeps every line's own choice: one priced, one
           left pending. */
        const staleRecord = draftState.records[staleOrder];
        check(staleRecord.lines.length === 2, `the two-line fixture must have two lines (got ${staleRecord.lines.length})`);
        const twoLineHtml = await (await postForm(owner, staleOrder, {
          'fpw_work_save': '1', 'fpw_work_revision': '0', 'fpw_draft_nonce': await mint(owner, `fpw-draft-save-${staleOrder}`),
          ...lineFields(staleRecord, (i) => (i === 0 ? 990 : null)),
          'fpw_work[destination]': '', 'fpw_work[dispatch_amount]': '',
        })).text();
        check(twoLineHtml.includes('Cambios guardados (revisión 1)'), 'the two-line draft saves');
        const twoLineReopened = await (await owner(editUrl(staleOrder))).text();
        check(/name="fpw_work\[lines\]\[0\]\[price\]" value="990"/.test(twoLineReopened) && /name="fpw_work\[lines\]\[1\]\[price\]" value="" placeholder="Pendiente"/.test(twoLineReopened), 'each line recovers its own price: one entered, one left pending');

        /* CSRF: a valid owner session with an invalid nonce is refused 403. */
        const forged = { 'fpw_work_save': '1', 'fpw_work_revision': '3', 'fpw_draft_nonce': 'forged', ...lineFields(correctRecord, () => 1), 'fpw_work[destination]': 'x', 'fpw_work[dispatch_amount]': '1' };
        const forgedStatus = (await postForm(owner, correctOrder, forged)).status;
        check(forgedStatus === 403, `a save with an invalid nonce is refused 403 (got ${forgedStatus})`);

        /* Permissions: ventas' valid session with a VALID nonce is still
           denied — presenting a nonce grants nothing. A visitor goes to login. */
        const ventasSave = await postForm(ventas, correctOrder, { ...forged, 'fpw_draft_nonce': await mint(ventas, `fpw-draft-save-${correctOrder}`) });
        check(ventasSave.status === 403, `a valid ventas session with a valid nonce must be DENIED the save (got ${ventasSave.status})`);
        const visitorSave = await postForm(visitor, correctOrder, forged);
        check(visitorSave.status === 302 && String(visitorSave.headers.get('location') || '').includes('wp-login.php'), 'a visitor save is sent to the login');

        /* Saving approves nothing: no notification fires and the source
           records keep their status, quantities and identity. */
        check(mailCount() === mailsBefore, `saving must not send any notification (${mailsBefore} → ${mailCount()})`);
        const recordState = JSON.parse(sh(PHP, [WPCLI, 'eval', `
          $out = array();
          foreach (array(${raceOrder}, ${correctOrder}, ${staleOrder}) as $id) {
            $order = wc_get_order($id);
            $quantities = array();
            foreach ($order->get_items() as $item) { $quantities[] = (int) $item->get_quantity(); }
            $out[$id] = array('status' => $order->get_status(), 'quantities' => $quantities, 'qwc' => (string) $order->get_meta('_qwc_quote'), 'email' => $order->get_billing_email());
          }
          echo wp_json_encode($out);
        `, `--url=${SITE_URL}`, `--path=${WP_DIR}`, '--user=1']).split('\n').pop());
        for (const [id, record] of Object.entries(recordState)) {
          check(record.status === 'pending' && record.qwc === '1', `saving must not approve record ${id} (status ${record.status})`);
          check(JSON.stringify(record.quantities) === JSON.stringify(draftState.records[id].lines.map((l) => l.quantity)), `record ${id} keeps its own quantities (${JSON.stringify(record.quantities)})`);
          check(record.email === draftState.records[id].email, `record ${id} keeps its identity`);
        }
      }

      /* 5e. Issue #54 (cut 5 of #49): ventas import → solicitud con RUT → historial
         privado. The owner imports a SYNTHETIC Sales Register file (explicit
         source-id identity), previews without changing history, confirms, and
         each affected request's private draft renders its OWN history —
         matched by normalized RUT across formatting variants — with provenance.
         Ventas and visitors stay out; repetition never duplicates; a cancelled
         or stale batch answers explicitly and changes nothing. */
      const importUrl = '/wp-admin/admin.php?page=fpw-sales-import';
      /* The disposable DB persists across runs: reset the run-owned import state so the journey below is deterministic. Only the importer's own rows are removed — requests, records, drafts and notes stay untouched. */
      sh(PHP, [WPCLI, 'eval', `
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name = 'fpw_sales_register'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name = 'fpw_sales_batch'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'fpw_sales_receipt_%'");
      `, `--url=${SITE_URL}`, `--path=${WP_DIR}`, '--user=1']);
      const draftBeforeAnyImport = await (await owner(draftUrl)).text();
      check(draftBeforeAnyImport.includes('Sin historial asociado') && !draftBeforeAnyImport.includes('Cliente nuevo'), 'with a clean register, history reads as unresolved («Sin historial asociado»), never a customer verdict');
      const formNonce = (html, action) => {
        for (const f of (html.match(/<form\b[\s\S]*?<\/form>/g) || [])) {
          if (f.includes(`name="fpw_sales_action" value="${action}"`)) {
            return (f.match(/name="fpw_sales_nonce" value="([0-9a-f]+)"/) || [])[1] || null;
          }
        }
        return null;
      };
      const salesState = () => JSON.parse(sh(PHP, [WPCLI, 'eval', `
        global $wpdb;
        $raw = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'fpw_sales_register'");
        $reg = is_string($raw) ? json_decode($raw, true) : null;
        $receipts = 0;
        foreach ( (array) $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'fpw_sales_receipt_%'") as $name ) {
          if ( str_starts_with( (string) $name, 'fpw_sales_receipt_' ) ) { $receipts++; }
        }
        echo wp_json_encode(array(
          'sales' => ( $reg && isset( $reg['sales'] ) ) ? count( $reg['sales'] ) : 0,
          'ids' => ( $reg && isset( $reg['sales'] ) ) ? array_values( array_column( $reg['sales'], 'id' ) ) : array(),
          'receipts' => $receipts,
          'pending' => (bool) $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'fpw_sales_batch'"),
        ));
      `, `--url=${SITE_URL}`, `--path=${WP_DIR}`, '--user=1']).split('\n').pop());

      const ventasDeniedImport = await ventas(importUrl);
      check(ventasDeniedImport.status === 403, `a valid ventas session must be DENIED the sales import (got ${ventasDeniedImport.status})`);
      const ventasUpload = await ventas(importUrl, { method: 'POST', body: new URLSearchParams({ 'fpw_sales_action': 'upload' }) });
      check(ventasUpload.status === 403, `a ventas POST to the importer must be refused as a PERMISSION denial (got ${ventasUpload.status})`);
      const visitorImport = await visitor(importUrl);
      check(visitorImport.status === 302 && String(visitorImport.headers.get('location') || '').includes('wp-login.php'), 'a visitor with the import link is sent to the login, never shown the importer');
      const csrfProbe = await owner(importUrl, { method: 'POST', body: new URLSearchParams({ 'fpw_sales_action': 'confirm', 'fpw_sales_token': 'x' }) });
      check(csrfProbe.status === 403, `a confirm POST without a valid nonce must hit the CSRF boundary (got ${csrfProbe.status})`);

      const beforeImport = await salesState();
      check(beforeImport.sales === 0 && !beforeImport.pending && beforeImport.receipts === 0, 'the import surface starts empty on the disposable run');
      const syntheticCsv = [
        'id_venta,fecha,rut,total',
        'FPW-TEST-0001,2026-03-15,76123456-7,1250000',
        'FPW-TEST-0002,2026-05-02,"76.123.456-7",890000',
        'FPW-TEST-0003,2026-06-11,,450000',
        'FPW-TEST-0004,2026-06-20,"76.999.999-9",320000',
        'FPW-TEST-0005,15/06/2026,76123456-7,100000',
        'FPW-TEST-0006,2026-07-01,76123456-7,ilegible',
      ].join('\n');
      const uploadCsv = async (csv, filename) => {
        const page = await (await owner(importUrl)).text();
        const uploadNonce = formNonce(page, 'upload');
        check(Boolean(uploadNonce), 'the upload form carries a minted nonce');
        const form = new FormData();
        form.set('fpw_sales_action', 'upload');
        form.set('fpw_sales_nonce', uploadNonce);
        form.set('fpw_sales_file', new Blob([csv], { type: 'text/csv' }), filename);
        const posted = await owner(importUrl, { method: 'POST', body: form });
        check(posted.status === 200, `the upload must answer 200 (got ${posted.status})`);
        return posted.text();
      };
      let importHtml = await uploadCsv(syntheticCsv, 'ventas-sinteticas.csv');
      check(importHtml.includes('NO cambió'), 'the upload banner states the history did not change');
      check(importHtml.includes('ventas-sinteticas.csv') && importHtml.includes('4 filas candidatas') && importHtml.includes('2 filas con error') && importHtml.includes('1 asociación sin resolver'), 'the preview names its source file and its explicit per-row outcomes');
      const afterUpload = await salesState();
      check(afterUpload.sales === 0 && afterUpload.pending === true, 'upload/preview stage a proposal only: the register stays empty until confirmation');
      const draftAfterUpload = await (await owner(draftUrl)).text();
      check(draftAfterUpload.includes('Sin historial asociado'), 'history reads unchanged right after upload/preview');

      const confirmNonce = formNonce(importHtml, 'confirm');
      const confirmToken = (importHtml.match(/name="fpw_sales_token" value="([0-9a-f]+)"/) || [])[1];
      check(Boolean(confirmNonce) && Boolean(confirmToken), 'the confirm action carries its own nonce and the reviewed batch token');
      const confirmed = await owner(importUrl, { method: 'POST', body: new URLSearchParams({ 'fpw_sales_action': 'confirm', 'fpw_sales_nonce': confirmNonce, 'fpw_sales_token': confirmToken }) });
      check(confirmed.status === 200, `the confirm must answer 200 (got ${confirmed.status})`);
      importHtml = await confirmed.text();
      check(importHtml.includes('Importación aplicada: 4 ventas nuevas'), 'the confirmation reports its explicit result with counts');
      const afterConfirm = await salesState();
      check(afterConfirm.sales === 4 && afterConfirm.ids.includes('FPW-TEST-0001') && afterConfirm.receipts === 1 && !afterConfirm.pending, 'the applied batch lands once, with a consultable receipt and no pending slot');

      const draftWithHistory = await (await owner(draftUrl)).text();
      check(draftWithHistory.includes('FPW-TEST-0001') && draftWithHistory.includes('2026-03-15') && draftWithHistory.includes('1.250.000 CLP'), 'the race draft renders its imported history (date, source id, amount)');
      check(draftWithHistory.includes('FPW-TEST-0002') && !draftWithHistory.includes('FPW-TEST-0003'), 'both RUT formats match one customer; the unassociated sale belongs to nobody');
      check(draftWithHistory.includes('ventas-sinteticas.csv'), 'the history names its provenance (which import supplies it)');
      check(!draftWithHistory.replace(/<script[\s\S]*?<\/script>/g, '').includes('$'), 'history totals never render as draft prices');
      const isolateDraft = await (await owner(`/wp-admin/admin.php?page=fpw-quote-draft&request=${isolateOrder}`)).text();
      check(isolateDraft.includes('FPW-TEST-0004') && isolateDraft.includes('320.000 CLP') && !isolateDraft.includes('FPW-TEST-0001'), 'a different RUT sees only its own history — customer scoping holds');

      importHtml = await uploadCsv(syntheticCsv, 'ventas-sinteticas.csv');
      const repeatNonce = formNonce(importHtml, 'confirm');
      const repeatToken = (importHtml.match(/name="fpw_sales_token" value="([0-9a-f]+)"/) || [])[1];
      const repeated = await owner(importUrl, { method: 'POST', body: new URLSearchParams({ 'fpw_sales_action': 'confirm', 'fpw_sales_nonce': repeatNonce, 'fpw_sales_token': repeatToken }) });
      check(repeated.status === 200, `the repeated confirm must answer 200 (got ${repeated.status})`);
      const repeatedHtml = await repeated.text();
      check(repeatedHtml.includes('0 ventas nuevas') && repeatedHtml.includes('4 ya importadas'), 'the repeated reviewed import applies nothing and reports the skips');
      const afterRepeat = await salesState();
      check(afterRepeat.sales === 4 && afterRepeat.receipts === 2, 'repetition never duplicates purchases (register intact, second receipt recorded)');

      importHtml = await uploadCsv(syntheticCsv.replace(/FPW-TEST-/g, 'FPW-CXL-'), 'por-cancelar.csv');
      const staleToken = (importHtml.match(/name="fpw_sales_token" value="([0-9a-f]+)"/) || [])[1];
      const staleConfirmNonce = formNonce(importHtml, 'confirm');
      check(Boolean(staleConfirmNonce), 'the pending preview mints its confirm nonce');
      const cancelNonce = formNonce(importHtml, 'cancel');
      const cancelled = await owner(importUrl, { method: 'POST', body: new URLSearchParams({ 'fpw_sales_action': 'cancel', 'fpw_sales_nonce': cancelNonce, 'fpw_sales_token': staleToken }) });
      check(cancelled.status === 200 && (await cancelled.text()).includes('sin efectos'), 'the cancellation answers its harmlessness explicitly');
      const afterCancel = await salesState();
      check(afterCancel.sales === 4 && !afterCancel.pending, 'a cancelled preview leaves the register untouched');
      const stale = await owner(importUrl, { method: 'POST', body: new URLSearchParams({ 'fpw_sales_action': 'confirm', 'fpw_sales_nonce': staleConfirmNonce, 'fpw_sales_token': staleToken }) });
      check(stale.status === 200 && (await stale.text()).includes('ya no está disponible'), 'confirming a cancelled batch answers the explicit stale result, never an apply');

      /* 5f. Issue #52 (cut 3 of #49): the private Price List (Mantenedor de precios) and the
         drafts it prefills. The owner maintains net CLP prices per NATIVE product/variation
         identity; a new request's draft prefills the available suggestions (distinct per option
         of the same product) and leaves the rest pending; changing the list conserves saved
         work and shows the new suggestions beside the manual choices; the explicit refresh
         adopts suggestions without touching manual entries; ventas/visitors stay out; nothing
         public leaks the maintained amounts. */
      {
        const priceUrl = '/wp-admin/admin.php?page=fpw-price-list';
        const mailCountNow = () => (existsSync(mailLog) ? readFileSync(mailLog, 'utf8').trim().split('\n').filter(Boolean).length : 0);
        const wpEval = (code) => sh(PHP, [WPCLI, 'eval', code, `--url=${SITE_URL}`, `--path=${WP_DIR}`, '--user=1']);
        const mint52 = async (fetcher, action) => {
          const minted = await fetcher('/wp-admin/admin-ajax.php', { method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ action: 'fpw_test_nonce', for: action }).toString() });
          const p = await minted.json();
          return p && p.success ? String(p.data.nonce) : null;
        };

        /* Deterministic reset of the run-owned price row on the persistent disposable DB. */
        wpEval(`global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name = 'fpw_price_list'");`);

        const catalog52 = JSON.parse(wpEval(`
          $simple = get_page_by_path('caja-cosechera-3-4', OBJECT, 'product');
          $variable = get_page_by_path('caja-universal-cerrada-color', OBJECT, 'product');
          $vp = wc_get_product($variable->ID);
          echo wp_json_encode(array(
            'simple' => (int) $simple->ID,
            'variable' => (int) $variable->ID,
            'variations' => array_values(array_map('intval', $vp->get_children())),
            'simple_permalink' => get_permalink($simple->ID),
            'variable_permalink' => get_permalink($variable->ID),
          ));
        `).split('\n').pop());
        const [varA, varB] = catalog52.variations;
        check(varA > 0 && varB > 0 && varA !== varB, `the variable fixture exposes two distinct variations (got ${catalog52.variations.join(', ')})`);

        /* Negative controls first: ventas' valid session is denied the screen and the save
           (even with a VALID nonce), a visitor goes to the login, a forged nonce is 403. */
        const ventasPrice = await ventas(priceUrl);
        check(ventasPrice.status === 403, `a valid ventas session must be DENIED the mantenedor (got ${ventasPrice.status})`);
        const ventasPriceNonce = await mint52(ventas, 'fpw_price_save');
        const ventasPricePost = await ventas(priceUrl, { method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ 'fpw_price_save': '1', 'fpw_price_nonce': ventasPriceNonce, [`fpw_prices[p:${catalog52.simple}]`]: '1' }).toString() });
        check(ventasPricePost.status === 403, `a ventas save with a VALID nonce is denied as a permission (got ${ventasPricePost.status})`);
        const visitorPrice = await visitor(priceUrl);
        check(visitorPrice.status === 302 && String(visitorPrice.headers.get('location') || '').includes('wp-login.php'), 'a visitor with the mantenedor link is sent to the login, never shown prices');
        const forgedPrice = await owner(priceUrl, { method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' }, body: 'fpw_price_save=1&fpw_price_nonce=forged' });
        check(forgedPrice.status === 403, `a mantenedor save without a valid nonce is refused 403 (got ${forgedPrice.status})`);

        /* Product fingerprint: the price save must not mutate product data. */
        const productFingerprint = () => JSON.parse(wpEval(`
          $out = array();
          foreach (array(${catalog52.simple}, ${catalog52.variable}, ${varA}, ${varB}) as $id) {
            $p = wc_get_product($id);
            $out[$id] = array('price' => (string) $p->get_price('edit'), 'meta' => md5(wp_json_encode($p->get_meta_data())), 'status' => get_post_status($id));
          }
          echo wp_json_encode($out);
        `).split('\n').pop());
        const fpBefore = productFingerprint();

        /* The owner saves the list over the real screen: the simple product plus two
           options of the SAME product, each with its own price. */
        const formNonceIn = (html, marker) => {
          for (const f of (html.match(/<form\b[\s\S]*?<\/form>/g) || [])) {
            if (f.includes(marker)) { return (f.match(/name="fpw_price_nonce" value="([0-9a-f]+)"/) || [])[1] || null; }
          }
          return null;
        };
        const pricePage = await (await owner(priceUrl)).text();
        check(pricePage.includes('Mantenedor de precios') && pricePage.includes(`fpw_prices[v:${varA}]`), 'the owner opens the mantenedor with an input per native identity');
        const saveNonce = formNonceIn(pricePage, 'fpw_price_save');
        check(Boolean(saveNonce), 'the mantenedor save form carries a minted nonce');
        const amounts52 = { simple: '14971', varA: '21973', varB: '23979' };
        const mailsBeforePriceSave = mailCountNow();
        const savedPrices = await owner(priceUrl, { method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ 'fpw_price_save': '1', 'fpw_price_nonce': saveNonce, [`fpw_prices[p:${catalog52.simple}]`]: amounts52.simple, [`fpw_prices[v:${varA}]`]: amounts52.varA, [`fpw_prices[v:${varB}]`]: amounts52.varB }).toString() });
        check(savedPrices.status === 200, `the price save must answer 200 (got ${savedPrices.status})`);
        check((await savedPrices.text()).includes('Lista de precios guardada: 3 precios mantenidos'), 'the save banner reports its explicit outcome');
        const priceRow52 = JSON.parse(wpEval(`
          global $wpdb;
          $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'fpw_price_list'));
          echo wp_json_encode(is_string($raw) ? json_decode($raw, true) : null);
        `).split('\n').pop());
        check(priceRow52 && priceRow52.prices[`p:${catalog52.simple}`] === 14971 && priceRow52.prices[`v:${varA}`] === 21973 && priceRow52.prices[`v:${varB}`] === 23979, 'the maintained prices persist by native product/variation identity');
        check(JSON.stringify(productFingerprint()) === JSON.stringify(fpBefore), 'saving prices mutated no product data: prices, meta and status all stand');
        check(mailCountNow() === mailsBeforePriceSave, 'saving prices sends no notification');
        const priceReopened = await (await owner(priceUrl)).text();
        check(new RegExp(`name="fpw_prices\\[v:${varA}\\]"[^>]*value="21973"`).test(priceReopened), 'the mantenedor recovers its saved values on reopening (persistence)');

        /* Nothing public leaks the maintained amounts: catalog routes, cart, checkout,
           the request confirmation and the native Store API all stay price-free. */
        const leakPaths = ['/tienda/', '/cotizacion/', '/datos-y-envio/', '/?s=caja', new URL(catalog52.simple_permalink).pathname, new URL(catalog52.variable_permalink).pathname];
        for (const leakPath of leakPaths) {
          const html = await fetchBody(leakPath);
          const text = serverRenderedText(html);
          for (const amount of Object.values(amounts52)) {
            check(!text.includes(amount) && !new RegExp(`value="${amount}"`).test(html), `no maintained amount (${amount}) leaks on public ${leakPath}`);
          }
        }
        const storeJson = await (await fetch(SITE_URL + '/wp-json/wc/store/v1/products', { signal: AbortSignal.timeout(60_000) })).text();
        for (const amount of Object.values(amounts52)) {
          check(!storeJson.includes(`"${amount}"`) && !storeJson.includes(`:${amount}`), `no maintained amount (${amount}) leaks through the native Store API`);
        }

        /* The prefill journey over a REAL checkout: the simple product plus two options
           of the same variable product. The simple line rides the classic wc-ajax add;
           the option lines ride the Store API (the route the race journey proves), since
           a wc-ajax add that posts a VARIATION id trips the quotes extension's
           quotable-conflict emptying (variations carry no qwc_enable_quotes meta). */
        const jar52 = makeCookieFetch();
        const addAjax52 = (params) => jar52('/?wc-ajax=add_to_cart', { method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(params).toString() });
        check((await addAjax52({ product_id: String(catalog52.simple), quantity: '3' })).status === 200, 'the price-journey simple add must answer 200');
        const storeCart52 = await jar52('/wp-json/wc/store/v1/cart');
        check(storeCart52.status === 200, `the Store API cart must answer 200 (got ${storeCart52.status})`);
        const storeNonce52 = storeCart52.headers.get('nonce');
        const storeCartData52 = await storeCart52.json();
        check(storeCartData52.items.length === 1 && Number(storeCartData52.items[0].id) === catalog52.simple, `the simple line stands before the option adds (got ${JSON.stringify(storeCartData52.items && storeCartData52.items.map((i) => i.id))})`);
        const color52 = JSON.parse(wpEval(`
          $out = array();
          foreach (array(${varA}, ${varB}) as $vid) { $v = wc_get_product($vid); $out[$vid] = array_values($v->get_attributes()); }
          echo wp_json_encode($out);
        `).split('\n').pop());
        for (const [vid, qty] of [[varA, '2'], [varB, '1']]) {
          const added = await jar52('/wp-json/wc/store/v1/cart/add-item', {
            method: 'POST',
            headers: { 'content-type': 'application/json', ...(storeNonce52 ? { nonce: storeNonce52 } : {}) },
            body: JSON.stringify({ id: String(vid), quantity: qty, variation: [{ attribute: 'attribute_color', value: String((color52[vid] || [])[0]) }] }),
          });
          check(added.status === 200 || added.status === 201, `the option add must answer 2xx (got ${added.status})`);
        }
        const cartBeforeCheckout = await (await jar52('/wp-json/wc/store/v1/cart')).json();
        check(cartBeforeCheckout.items.length === 3, `the cart holds three lines before checkout (got ${cartBeforeCheckout.items.length})`);
        const page52 = await (await jar52('/datos-y-envio/')).text();
        const hidden52 = {};
        for (const match of page52.matchAll(/<input[^>]*type="hidden"[^>]*>/g)) {
          const name = (match[0].match(/name="([^"]+)"/) || [])[1];
          if (!name || name in hidden52) { continue; }
          hidden52[name] = (match[0].match(/value="([^"]*)"/) || [])[1] ?? '';
        }
        check(hidden52['woocommerce-process-checkout-nonce'] && hidden52['fpw_attempt'], 'the price-journey checkout form carries its identity');
        const posted52 = { ...hidden52,
          billing_first_name: 'PRUEBA LOCAL PRECIOS', billing_phone: '+56 9 1234 5678',
          billing_email: 'precios-52@example.invalid', billing_company: 'PRUEBA NO COMERCIAL',
          billing_fp_rut: '76.876.543-4', billing_fp_giro: 'Prueba local',
          payment_method: 'quotes-gateway', order_comments: 'Recorrido local automatizado (no atender)',
          billing_fp_dispatch: 'si', billing_fp_address: 'Camino de precios 123, Mostazal' };
        const response52 = await jar52('/?wc-ajax=checkout', { method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(posted52).toString() });
        const payload52 = await response52.json();
        check(payload52 && payload52.result === 'success' && /\/order-received\/(\d+)/.test(String(payload52.redirect || '')), `the price-journey request lands natively (got ${JSON.stringify(payload52).slice(0, 140)})`);
        const order52 = Number((String(payload52.redirect).match(/\/order-received\/(\d+)/) || [])[1]);
        const draftRow52 = () => JSON.parse(wpEval(`
          global $wpdb;
          $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'fpw_draft_' . ${order52}));
          echo wp_json_encode(is_string($raw) ? json_decode($raw, true) : null);
        `).split('\n').pop());
        const draft52 = draftRow52();
        check(draft52 && draft52.items.length === 3, `the price-journey draft keeps its three lines (got ${draft52 && draft52.items.length})`);

        const draftUrl52 = `/wp-admin/admin.php?page=fpw-quote-draft&request=${order52}`;
        const postDraft52 = (fetcher, fields) => fetcher(draftUrl52, { method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(fields).toString() });
        const draft52Html = await (await owner(draftUrl52)).text();
        check(new RegExp('name="fpw_work\\[lines\\]\\[0\\]\\[price\\]" value="14971"').test(draft52Html), 'the simple line prefills its maintained price');
        check(new RegExp('name="fpw_work\\[lines\\]\\[1\\]\\[price\\]" value="21973"').test(draft52Html) && new RegExp('name="fpw_work\\[lines\\]\\[2\\]\\[price\\]" value="23979"').test(draft52Html), 'the two options of the same product prefill their OWN different prices');
        check(draft52Html.includes('sugerido por el mantenedor') && draft52Html.includes('Prellenado con la sugerencia del mantenedor'), 'the prefill reads as a suggestion, never a chosen price');
        check(draft52Html.includes('Mantenedor de precios'), 'the draft links the mantenedor');

        /* The owner saves: two suggestions adopted, one deliberate manual override. */
        const lineFields52 = (priceFor) => {
          const fields = {};
          draft52.items.forEach((line, index) => {
            fields[`fpw_work[lines][${index}][quantity]`] = String(line.quantity);
            const price = priceFor(index, line);
            fields[`fpw_work[lines][${index}][price]`] = price === null ? '' : String(price);
          });
          return fields;
        };
        const saved52 = await postDraft52(owner, {
          'fpw_work_save': '1', 'fpw_work_revision': '0', 'fpw_draft_nonce': await mint52(owner, `fpw-draft-save-${order52}`),
          ...lineFields52((i) => (i === 1 ? 17500 : [14971, 21973, 23979][i])),
          'fpw_work[destination]': 'Camino de precios 123, Mostazal', 'fpw_work[dispatch_amount]': '39990',
        });
        check((await saved52.text()).includes('Cambios guardados (revisión 1)'), 'the draft save with adopted + manual prices lands');
        const workRow52 = () => JSON.parse(wpEval(`
          global $wpdb;
          $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'fpw_draft_work_' . ${order52}));
          echo wp_json_encode(is_string($raw) ? json_decode($raw, true) : null);
        `).split('\n').pop());
        const workBeforeListChange = workRow52();
        check(workBeforeListChange && workBeforeListChange.revision === 1 && workBeforeListChange.lines[1].price === 17500 && workBeforeListChange.lines[1].price_source === 'manual', 'the saved work keeps the manual override');
        check(workBeforeListChange.lines[0].price === 14971 && workBeforeListChange.lines[0].price_source === 'suggested' && workBeforeListChange.lines[2].price === 23979 && workBeforeListChange.lines[2].price_source === 'suggested', 'the adopted suggestions are stored with their suggested origin');
        const reopened52 = await (await owner(draftUrl52)).text();
        check(reopened52.includes('17.500 CLP neto · ingreso manual'), 'the manual override renders as the owner\'s choice');
        check((reopened52.match(/sugerido por el mantenedor/g) || []).length >= 2, 'the adopted suggestions render named as suggestions');

        /* The list changes: the saved draft keeps its amounts, the new suggestions show
           beside them, nothing is silently rewritten. */
        const changedPage = await (await owner(priceUrl)).text();
        const changedPrices = await owner(priceUrl, { method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ 'fpw_price_save': '1', 'fpw_price_nonce': formNonceIn(changedPage, 'fpw_price_save'), [`fpw_prices[p:${catalog52.simple}]`]: '15980', [`fpw_prices[v:${varA}]`]: '22976', [`fpw_prices[v:${varB}]`]: '24978' }).toString() });
        check((await changedPrices.text()).includes('Lista de precios guardada'), 'the list change saves');
        const workAfterListChange = workRow52();
        check(JSON.stringify(workAfterListChange) === JSON.stringify(workBeforeListChange) && workAfterListChange.lines[0].price === 14971 && workAfterListChange.lines[1].price === 17500, 'changing the list never rewrites the saved draft');
        const stable52 = await (await owner(draftUrl52)).text();
        check(new RegExp('name="fpw_work\\[lines\\]\\[0\\]\\[price\\]" value="14971"').test(stable52), 'the conserved amount still fills its input after the list moved');
        check(stable52.includes('La lista sugiere hoy: 15.980 CLP neto') && stable52.includes('La lista sugiere hoy: 22.976 CLP neto'), 'the new suggestions render beside the conserved values');

        /* The explicit refresh: tracking lines adopt the new list, the manual choice survives. */
        const refreshed52 = await (await postDraft52(owner, { 'fpw_price_refresh': '1', 'fpw_refresh_nonce': await mint52(owner, `fpw-draft-refresh-${order52}`) })).text();
        check(refreshed52.includes('Precios refrescados desde el mantenedor (revisión 2)'), 'the explicit refresh lands as a new revision');
        const workAfterRefresh = workRow52();
        check(workAfterRefresh.revision === 2 && workAfterRefresh.lines[0].price === 15980 && workAfterRefresh.lines[0].price_source === 'suggested', 'the tracking line adopts the new list value');
        check(workAfterRefresh.lines[1].price === 17500 && workAfterRefresh.lines[1].price_source === 'manual', 'the manual choice survives the refresh');
        check(workAfterRefresh.lines[2].price === 24978 && workAfterRefresh.lines[2].price_source === 'suggested', 'the second option adopts its own new price');
        check(workAfterRefresh.dispatch_amount === 39990 && workAfterRefresh.destination === 'Camino de precios 123, Mostazal', 'the refresh touches only prices: destination and dispatch stay as saved');

        /* A form rendered from the pre-refresh revision is now stale. */
        const stale52 = await postDraft52(owner, {
          'fpw_work_save': '1', 'fpw_work_revision': '1', 'fpw_draft_nonce': await mint52(owner, `fpw-draft-save-${order52}`),
          ...lineFields52(() => 1), 'fpw_work[destination]': 'Sobrescritura', 'fpw_work[dispatch_amount]': '1',
        });
        check((await stale52.text()).includes('no se guardó'), 'a pre-refresh form is refused as stale instead of overwriting');

        /* The refresh boundary: ventas' valid session with a valid nonce is denied. */
        const ventasRefresh = await postDraft52(ventas, { 'fpw_price_refresh': '1', 'fpw_refresh_nonce': await mint52(ventas, `fpw-draft-refresh-${order52}`) });
        check(ventasRefresh.status === 403, `a valid ventas session with a valid nonce must be DENIED the refresh (got ${ventasRefresh.status})`);

        /* The journey adds exactly one request's notifications, never more. */
        check(mailCountNow() === mailsBeforePriceSave + 2, `the price journey added exactly one request's notifications (${mailsBeforePriceSave} → ${mailCountNow()})`);
        const receivedPath52 = String(payload52.redirect).replace(SITE_URL, '');
        const receivedText = serverRenderedText(await fetchBody(receivedPath52));
        for (const amount of [...Object.values(amounts52), '15980', '22976', '24978', '17500']) {
          check(!receivedText.includes(amount), `no maintained or suggested amount (${amount}) leaks on the request confirmation`);
        }
      }
    } finally {
      spawnSync(PHP, [WPCLI, 'user', 'delete', draftOwner, '--yes', ...draftWpArgs], { stdio: 'ignore' });
      spawnSync(PHP, [WPCLI, 'user', 'delete', draftVentas, '--yes', ...draftWpArgs], { stdio: 'ignore' });
    }

    /* 6. Issue #33: the restricted ventas session over real HTTP. Every protected
       operation is attempted with a VALID session and a VALID nonce (minted for
       the restricted user by the disposable mu-plugin above) and each denial
       must be a server 403 that leaves the synthetic record unchanged. Positive
       controls with the guards lifted prove the probes detect a removed guard —
       the authorization-regression criterion — and a final recheck proves the
       denial returns when the guards are restored. The temporary ventas account
       lives only inside this run. */
    const ventasUser = 'fp-ventas-check';
    const ventasPass = `vcheck-${randomUUID().replace(/-/g, '').slice(0, 18)}`;
    const wpArgs = [`--path=${WP_DIR}`, `--url=${SITE_URL}`];
    try {
      sh(PHP, [WPCLI, 'user', 'create', ventasUser, `${ventasUser}@example.invalid`, '--role=ventas_freeplast', `--user_pass=${ventasPass}`, ...wpArgs, '--quiet']);
    } catch {
      sh(PHP, [WPCLI, 'user', 'update', ventasUser, '--role=ventas_freeplast', `--user_pass=${ventasPass}`, ...wpArgs, '--quiet']);
    }
    const ventasEnv = { ...process.env, FREEPLAST_VENTAS_USER: ventasUser, FREEPLAST_VENTAS_PASS: ventasPass };
    // #37: a fresh per-run provenance token, shared by every mode; the guarded
    // run binds the new fixture to it and the mutating modes verify that
    // binding before touching anything.
    ventasEnv.FREEPLAST_VENTAS_RUN = randomUUID().replace(/-/g, '').slice(0, 12);
    ventasEnv.FREEPLAST_VENTAS_STATE_COMMAND = JSON.stringify([PHP, WPCLI, ...wpArgs, '--user=1', 'eval-file', join(HERE, 'woo-ventas-state.php')]);
    // #37: a synthetic coupon on the DISPOSABLE fixture only, so the coupon
    // positive control exercises the REAL CouponsController against a valid
    // native coupon instead of a made-up code. Deleted with the stack.
    const fixtureCoupon = `ventaslocal-${randomUUID().replace(/-/g, '').slice(0, 10)}`;
    // Woo 11.1.0's CLI runner derives subcommands from the REST schema title:
    // coupons register as `wp wc shop_coupon`, never `wp wc coupon`.
    sh(PHP, [WPCLI, 'wc', 'shop_coupon', 'create', `--code=${fixtureCoupon}`, '--discount_type=percent', '--amount=100', '--user=1', ...wpArgs, '--quiet']);
    ventasEnv.FREEPLAST_VENTAS_COUPON = fixtureCoupon;
    const ventasArgs = [join(HERE, 'woo-ventas-guard.py'), '--base', SITE_URL];
    const guardOffMu = join(WP_DIR, 'wp-content', 'mu-plugins', 'fpw-stack-guard-off.php');
    try {
      const guarded = spawnSync(py, [...ventasArgs, '--mode', 'guarded'], { encoding: 'utf8', timeout: 300_000, env: ventasEnv });
      check(guarded.status === 0, `ventas guarded matrix failed:\n${guarded.stdout || ''}\n${guarded.stderr || ''}\n--- server log tail ---\n${readFileSync(serverLogFile, 'utf8').slice(-2000)}`);
      const { order } = JSON.parse(guarded.stdout.trim().split('\n').pop());
      writeFileSync(
        guardOffMu,
        `<?php
/** Disposable-stack only (written by woo-stack-harness.mjs, issue #33): lifts the
 * adapter's ventas record guards for the positive-controls run — the removed-guard
 * world the regression must detect. Deleted right after the run; never shipped. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
add_action( 'init', static function () {
	remove_action( 'admin_init', 'fpw_deny_sales_record_mutation', 0 );
	remove_action( 'admin_init', 'fpw_deny_sales_order_note_and_meta_mutation', 0 );
	remove_filter( 'woocommerce_process_shop_order_meta', 'fpw_deny_sales_order_save', 0 );
	remove_filter( 'woocommerce_bulk_action_ids', 'fpw_deny_sales_bulk_actions', 0 );
	remove_filter( 'woocommerce_rest_check_permissions', 'fpw_deny_sales_rest_mutation', 10 );
}, 0 );
`,
      );
      const off = spawnSync(py, [...ventasArgs, '--mode', 'guard-off'], { encoding: 'utf8', timeout: 300_000, env: { ...ventasEnv, FREEPLAST_VENTAS_ORDER: String(order) } });
      check(off.status === 0, `ventas guard-off positive controls failed:\n${off.stdout || ''}\n${off.stderr || ''}`);
      rmSync(guardOffMu, { force: true });
      const recheck = spawnSync(py, [...ventasArgs, '--mode', 'recheck'], { encoding: 'utf8', timeout: 120_000, env: { ...ventasEnv, FREEPLAST_VENTAS_ORDER: String(order) } });
      check(recheck.status === 0, `ventas recheck failed:\n${recheck.stdout || ''}\n${recheck.stderr || ''}`);
    } finally {
      rmSync(guardOffMu, { force: true });
      spawnSync(PHP, [WPCLI, 'user', 'delete', ventasUser, '--yes', ...wpArgs], { stdio: 'ignore' });
    }
    checks += 3;
  } finally {
    closeSync(serverLogFd);
    /* Kill the whole server process group, then verify the port is actually
       freed — a surviving worker would silently serve stale state to the next
       run and make every scenario on it nondeterministic. */
    try { process.kill(-server.pid, 'SIGTERM'); } catch { server.kill('SIGTERM'); }
    sleep(500);
    try { process.kill(-server.pid, 'SIGKILL'); } catch { /* already gone */ }
    let lingering = 0;
    for (let attempt = 0; attempt < 20; attempt++) {
      lingering = await fetchCode('/');
      if (lingering === 0) break;
      try { process.kill(-server.pid, 'SIGKILL'); } catch { /* already gone */ }
      sleep(250);
    }
    check(lingering === 0, `the disposable stack still answers on ${SITE_URL} after shutdown (HTTP ${lingering}) — a server instance survived`);
  }

  console.log(`stack harness: ${checks} real-stack checks passed (Home card contract + bounded concurrent-race repetition + same-attempt retry recovery + lost-response confirmation recovery + identical-rebuild-new-reference + per-request records + notification-event count + per-request private drafts and their owner-notice links + manual draft completion by the owner + dispatch-address provenance journeys + restricted-ventas record boundary + ventas import and RUT purchase history + the private price list and its draft prefill/refresh journey) on ${SITE_URL}`);
  return checks;
}
