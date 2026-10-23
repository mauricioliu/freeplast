import { lookup } from 'node:dns/promises';
import { networkInterfaces } from 'node:os';
import { isIP } from 'node:net';

export const origin = 'http://mliu:8131';
export const routes = ['/', '/tienda/', '/producto/caja-cosechera-3-4/'];
// Measurement mode: DEV pages are audited only at original path + this exact query.
// `marker` is the source-proven deterministic quantity-id prefix emitted by the
// gated theme mode; its presence in preflight HTML proves the server mode was active.
export const measureMode = Object.freeze({ id: 'fp-measure', query: '?fp-measure=1', marker: 'quantity_fpm_', version: 1 });
export const activeMode = () => ({ id: measureMode.id, query: measureMode.query, version: measureMode.version, active: true });
export const measureUrl = (base, path) => base + path + measureMode.query;
export const policyVersion = 2;
const asset = /\.(?:css|js|mjs|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|otf|mp4|webm)$/i;
// Finite source-backed allowlist: the ONLY commercial-NAMED same-origin static
// assets the DEV pages actually load. Theme entries are the shipped/enqueued
// scripts of wordpress/wp-content/themes/freeplast/functions.php; WooCommerce
// entries are proven from the pinned woocommerce-11.1.0.zip source
// (class-wc-frontend-scripts.php + OrderAttributionController.php). Every other
// commercial-named path — other themes/plugins, wp-includes, uploads, lookalikes —
// stays denied. Non-commercial static assets keep the generic asset rules.
export const staticSourceAssets = new Set([
  '/wp-content/themes/freeplast/assets/js/loop-add-to-cart-quantity.js',
  '/wp-content/themes/freeplast/assets/js/cart-quantity-feedback.js',
  '/wp-content/themes/freeplast/assets/js/cart-accessible-names.js',
  '/wp-content/themes/freeplast/assets/js/checkout-form.js',
  // Quotes for WooCommerce 2.13 class-quotes-wc.php:469; observed on product route.
  '/wp-content/plugins/quotes-for-woocommerce/assets/js/qwc-product-page.js',
  '/wp-content/plugins/woocommerce/assets/js/frontend/add-to-cart.min.js',
  '/wp-content/plugins/woocommerce/assets/js/frontend/add-to-cart-variation.min.js',
  '/wp-content/plugins/woocommerce/assets/js/frontend/cart-fragments.min.js',
  '/wp-content/plugins/woocommerce/assets/js/frontend/order-attribution.min.js',
  // Woo 11.1 SCRIPT_DEBUG selects these exact non-minified counterparts.
  '/wp-content/plugins/woocommerce/assets/js/frontend/add-to-cart.js',
  '/wp-content/plugins/woocommerce/assets/js/frontend/add-to-cart-variation.js',
  '/wp-content/plugins/woocommerce/assets/js/frontend/cart-fragments.js',
  '/wp-content/plugins/woocommerce/assets/js/frontend/order-attribution.js',
]);
const commercial = /(?:cart|checkout|cotiza|quote|order|payment|stock|wp-admin|wp-login|wp-json|wp-cron|xmlrpc|admin-ajax|wc-ajax|add-to|remove_item|logout|action|nonce|token|password|secret|authorization)/i;
const forbiddenHost = /(?:^|\.)(?:freeplast\.cl|freeplast\.mliu\.site|paypal\.com|stripe\.com|transbank\.cl|mercadopago\.[a-z.]+|whatsapp\.com|google-analytics\.com|googletagmanager\.com)$/i;
export class SafetyError extends Error {
  constructor(code) { super(code); this.code = code; }
}
export function deny(code) { throw new SafetyError(code); }

export function policy({ fixtureOrigin, fixtureDenied = [], hosts = {} } = {}) {
  // Only the dedicated test adapter calls this seam; the CLI never accepts origins.
  if (fixtureOrigin && !/^http:\/\/127\.0\.0\.1:[1-9]\d+$/.test(fixtureOrigin)) deny('invalid-fixture-origin');
  const base = fixtureOrigin || origin;
  return { origin: base, routes, hosts, fixtureDenied, fixture: Boolean(fixtureOrigin) };
}

export function checkRequest(p, raw, method = 'GET', type = 'Document', popup = false) {
  if (popup) deny('popup');
  if (!['GET', 'HEAD'].includes(method)) deny('method');
  if (typeof raw !== 'string' || /[\\\s\x00-\x1f]/.test(raw) || /%(?:2e|2f|5c|25|00)/i.test(raw)) deny('ambiguous-url');
  let u;
  try { u = new URL(raw); } catch { deny('url'); }
  if (!['http:', 'https:'].includes(u.protocol) || u.username || u.password || u.hash) deny('url');
  if (forbiddenHost.test(u.hostname) || p.fixtureDenied.includes(u.origin)) deny('forbidden-host');
  if (u.protocol === 'https:') deny('https-resource-unsupported');
  const staticSourceAsset = u.origin === p.origin && staticSourceAssets.has(u.pathname);
  if (!staticSourceAsset && commercial.test(decodeURIComponent(u.pathname + u.search))) deny('commercial-endpoint');
  if (type === 'Document') {
    if (u.origin !== p.origin || !routes.includes(u.pathname) || u.search !== measureMode.query) deny('document');
  } else {
    if (!asset.test(u.pathname) && !(u.origin === p.origin && (u.pathname === '/robots.txt' || routes.includes(u.pathname)))) deny('resource');
    if (u.origin === p.origin && routes.includes(u.pathname)) {
      if (u.search && u.search !== measureMode.query) deny('query');
    } else if ([...u.searchParams].some(([key, value]) => key !== 'ver' || !/^[\w.-]{1,80}$/.test(value))) deny('query');
    if (u.origin !== p.origin) {
      if (u.port && u.port !== '443' || u.protocol !== 'https:' || isIP(u.hostname) || !Object.hasOwn(p.hosts, u.hostname)) deny('external-resource');
    }
  }
  return u;
}

export function checkResponse(type, status, headers = []) {
  if (status === 429 || status >= 500) deny('resource-status');
  if (type !== 'Document' || !status) return;
  if (status !== 200 && ![301, 302, 303, 307, 308].includes(status)) deny('document-status');
  if (status === 200 && !headers.some(x => x.name.toLowerCase() === 'cache-control' && /no-store/i.test(x.value))) deny('document-cache-policy');
}

export async function resolveLocal() {
  const records = await lookup('mliu', { all: true, verbatim: true });
  const local = new Set(['127.0.0.1', '::1', ...Object.values(networkInterfaces()).flat().map(x => x.address)]);
  if (!records.length || records.some(x => !local.has(x.address))) deny('dev-not-local');
  return records.map(x => x.address).sort();
}

export function publicAddress(ip) {
  // Fail closed on IPv6 until its special-use ranges have an explicit policy.
  if (isIP(ip) !== 4) return false;
  const [a, b] = ip.split('.').map(Number);
  return !(a === 0 || a === 10 || a === 127 || a >= 224 || a === 169 && b === 254 || a === 172 && b >= 16 && b <= 31 || a === 192 && [0, 168].includes(b) || a === 100 && b >= 64 && b <= 127 || a === 198 && [18, 19, 51].includes(b) || a === 203 && b === 0);
}

export function checkLocalResources(html, p) {
  for (const [tag] of html.matchAll(/<(?:script|link|img|source)\b[^>]*>/gi)) {
    const link = /^<link\b/i.test(tag);
    if (link && !/\brel\s*=\s*["'][^"']*(?:stylesheet|preload|modulepreload|icon)/i.test(tag)) continue;
    const attribute = link ? /\shref\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+))/i : /\ssrc\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+))/i;
    const match = tag.match(attribute); if (!match) continue;
    const raw = (match[1] ?? match[2] ?? match[3]).replace(/&(?:amp|#0*38|#x0*26);/gi, '&');
    let u; try { u = new URL(raw, p.origin); } catch { deny('resource'); }
    if (u.origin === p.origin) checkRequest(p, u.href, 'GET', /^<script\b/i.test(tag) ? 'Script' : 'Other');
  }
}
export async function resourceHosts(html, p) {
  checkLocalResources(html, p);
  const hosts = {};
  for (const match of html.matchAll(/(?:src|href)\s*=\s*["']([^"']+)["']/gi)) {
    let u;
    try { u = new URL(match[1], p.origin); } catch { continue; }
    if (u.origin === p.origin || !asset.test(u.pathname)) continue;
    checkRequest({ ...p, hosts: { [u.hostname]: true } }, u.href, 'GET', 'Other');
    const records = await lookup(u.hostname, { all: true });
    if (!records.length || records.some(x => !publicAddress(x.address))) deny('resource-address');
    hosts[u.hostname] = records[0].address;
  }
  return hosts;
}
