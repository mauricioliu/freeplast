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
    check(mails.length === 2 * (outcomes.new_request_count + 3), `expected exactly ${2 * newRequestTotal} notification events (2 per new request × ${newRequestTotal}), got ${mails.length}:\n${mails.join('\n')}`);
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
      check((screenHtml.match(/Pendiente/g) || []).length >= 3, 'prices, history and dispatch estimate read as pending on the screen');
      check(!screenHtml.replace(/<script[\s\S]*?<\/script>/g, '').includes('$'), 'no price amount renders on the private draft');
      check(!screenHtml.includes('Sin historial'), 'the screen never presents missing history as a customer verdict');
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

  console.log(`stack harness: ${checks} real-stack checks passed (Home card contract + bounded concurrent-race repetition + same-attempt retry recovery + lost-response confirmation recovery + identical-rebuild-new-reference + per-request records + notification-event count + per-request private drafts and their owner-notice links + dispatch-address provenance journeys + restricted-ventas record boundary) on ${SITE_URL}`);
  return checks;
}
