import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import net from 'node:net';
import { policy, checkRequest, checkResponse, publicAddress, measureMode, measureUrl, staticSourceAssets, checkLocalResources } from '../src/policy.mjs';
import { preflight } from '../src/preflight.mjs';
import { filteringProxy } from '../src/proxy.mjs';
import { fixtureAdapter, fixtureHtml } from './fixture-adapter.mjs';

const p = policy();
test('exact documents, assets and methods; no endpoint/origin aliases', () => {
  for (const path of p.routes) assert.equal(checkRequest(p, measureUrl(p.origin, path)).origin, p.origin);
  assert.doesNotThrow(() => checkRequest(p, p.origin + '/wp-content/theme.css?ver=1.2', 'GET', 'Stylesheet'));
  for (const url of ['https://freeplast.cl/', 'https://freeplast.mliu.site/', 'http://mliu:8890/', 'http://localhost:8131/', 'http://127.0.0.1:8131/', 'http://user@mliu:8131/', p.origin + '/#x', p.origin + '/', p.origin + '/?ver=1', p.origin + '/?fp-measure=1&fp-measure=1', p.origin + '/?fp-measure=0', p.origin + '/?fp-measure=1&x=1', p.origin + '/?FP-MEASURE=1', p.origin + '/%2e%2e/', p.origin + '/%252e/', p.origin + '/wp-admin/', p.origin + '/?add-to-cart=1', p.origin + '/?wc-ajax=1', p.origin + '/checkout/', p.origin + '/wp-json/', p.origin + '/wp-cron.php', 'ws://mliu:8131/']) assert.throws(() => checkRequest(p, url), url);
  for (const method of ['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']) assert.throws(() => checkRequest(p, measureUrl(p.origin, '/'), method));
  assert.throws(() => checkRequest(p, p.origin + '/', 'GET', 'Document', true));
  assert.throws(() => checkRequest(p, 'https://freeplast.cl/logo.png', 'GET', 'Image'));
  assert.throws(() => checkRequest(p, p.origin + '/logo.png?token=secret', 'GET', 'Image'));
  assert.throws(() => checkRequest(p, 'https://cdn.example.com/logo.png', 'GET', 'Image'));
  assert.throws(() => checkRequest(policy({ hosts: { 'cdn.example.com': '8.8.8.8' } }), 'https://cdn.example.com/logo.png', 'GET', 'Image'), /https-resource-unsupported/);
});
test('finite source-backed commercial-name allowlist; unknown lookalikes never load', () => {
  // Exact shipped/enqueued commercial-named theme assets (functions.php) plus
  // pinned Woo 11.1.0 runtime scripts (frontend-scripts + order-attribution).
  for (const path of staticSourceAssets) assert.doesNotThrow(() => checkRequest(p, p.origin + path + '?ver=1.0', 'GET', 'Script'), path);
  assert.doesNotThrow(() => checkRequest(p, p.origin + '/wp-includes/js/jquery/jquery.min.js?ver=1', 'GET', 'Script'), 'non-commercial static keeps generic rules');
  // Unknown theme/plugin, wp-includes lookalikes, uploads, root, unregistered variants, non-asset extensions.
  for (const [url, type] of [
    [p.origin + '/wp-content/plugins/other-plugin/assets/js/cart-helper.min.js', 'Script'],
    [p.origin + '/wp-content/plugins/woocommerce/assets/js/frontend/cart.min.js', 'Script'],
    [p.origin + '/wp-content/plugins/woocommerce/assets/js/frontend/add-to-cart-debug.js', 'Script'],
    [p.origin + '/wp-content/themes/other-theme/assets/js/loop-add-to-cart-quantity.js', 'Script'],
    [p.origin + '/wp-content/themes/freeplast/assets/js/cart.php.js', 'Script'],
    [p.origin + '/wp-content/themes/freeplast/cart.php', 'Script'],
    [p.origin + '/wp-includes/js/cart-helper.min.js', 'Script'],
    [p.origin + '/wp-content/uploads/2026/01/cart-badge.png', 'Image'],
    [p.origin + '/cart-helper.js', 'Script'],
  ]) assert.throws(() => checkRequest(p, url, 'GET', type), url);
  // Mutator/foreign queries on allowlisted assets stay denied.
  for (const query of ['?add-to-cart=1', '?fp-measure=1', '?ver=' + 'x'.repeat(81)]) assert.throws(() => checkRequest(p, p.origin + '/wp-content/plugins/woocommerce/assets/js/frontend/add-to-cart.min.js' + query, 'GET', 'Script'), /query/);
  assert.throws(() => checkRequest(p, p.origin + '/wp-content/plugins/woocommerce/assets/js/frontend/add-to-cart.min.js?ver=1', 'POST', 'Script'), /method/);
  assert.throws(() => checkRequest(policy({ hosts: { 'cdn.example.com': '8.8.8.8' } }), 'http://cdn.example.com/cart.js', 'GET', 'Script'), /commercial-endpoint/);
  // Same-origin route subresource fetches stay plain-or-exact-mode-query only.
  assert.doesNotThrow(() => checkRequest(p, p.origin + '/tienda/', 'GET', 'Other'));
  assert.doesNotThrow(() => checkRequest(p, measureUrl(p.origin, '/tienda/'), 'GET', 'Other'));
  assert.throws(() => checkRequest(p, p.origin + '/tienda/?ver=1', 'GET', 'Other'), /query/);
});
test('preflight detects local required resource-policy mismatches without following them', () => {
  const p = policy();
  checkLocalResources('<script src="/wp-content/plugins/woocommerce/assets/js/frontend/add-to-cart.js?ver=11.1"></script><a href="/cart/">link only</a>', p);
  for (const html of ['<script src="/wp-content/plugins/unknown/cart.js"></script>', '<script src=/wp-json/private></script>', '<link rel="stylesheet" href="/cart.css">', '<script src="/wp-content/themes/freeplast/assets/js/nav.js?ver=1&amp;add-to-cart=1"></script>']) assert.throws(() => checkLocalResources(html, p));
});

test('response status and document cache failures stop collection', () => {
  assert.doesNotThrow(() => checkResponse('Document', 200, [{ name: 'Cache-Control', value: 'no-store, max-age=0' }]));
  assert.doesNotThrow(() => checkResponse('Document', 302));
  assert.doesNotThrow(() => checkResponse('Image', 404));
  assert.throws(() => checkResponse('Document', 200), /document-cache-policy/);
  assert.throws(() => checkResponse('Document', 404), /document-status/);
  for (const status of [429, 500, 503]) assert.throws(() => checkResponse('Image', status), /resource-status/);
});
test('public addresses fail closed for special-use/IPv6', () => {
  for (const ip of ['127.0.0.1', '10.1.2.3', '100.64.0.1', '172.31.1.1', '169.254.0.1', '192.168.0.1', '0.0.0.0', '224.0.0.1', '::1']) assert.equal(publicAddress(ip), false);
  assert.equal(publicAddress('8.8.8.8'), true);
});
test('preflight follows only authorized relative redirect; loops and forbidden hops never contacted', async () => {
  const markerHtml = body => fixtureHtml(`${body}<!-- ${measureMode.bootstrapMarker} --><label for="quantity_fpm_11_1">C</label><input id="quantity_fpm_11_1">`);
  for (const mode of ['allowed', 'denied', 'loop', 'status', 'identity', 'inactive']) {
    const fixture = await fixtureAdapter(({ req, deniedOrigin }) => mode === 'status' ? { status: 429 } : mode === 'identity' ? { body: '<h1>Not LOCAL</h1>' } : mode === 'inactive' ? { body: fixtureHtml('no marker') } : req.url === '/' + measureMode.query ? { status: 302, headers: { Location: mode === 'denied' ? deniedOrigin + '/' : '/' + (mode === 'loop' ? '' : 'tienda/') + measureMode.query } } : { body: markerHtml('ok') });
    try {
      if (mode === 'allowed') assert.equal((await preflight(fixture.policy, '/', '127.0.0.1', undefined, { mode: true })).hops.length, 2);
      else await assert.rejects(preflight(fixture.policy, '/', '127.0.0.1', undefined, { mode: true }), mode === 'inactive' ? /measure-mode-inactive/ : undefined);
      assert.equal(fixture.deniedConnections, 0);
    } finally { await fixture.close(); }
  }
});
const raw = (port, message) => new Promise((resolve, reject) => {
  const socket = net.connect(port, '127.0.0.1', () => socket.end(message));
  let data = ''; socket.on('data', x => { data += x; }); socket.on('error', reject); socket.on('close', () => resolve(data));
});
test('proxy rejects HTTP mutators, WS Upgrade, WSS CONNECT before first TCP contact', async () => {
  for (const mode of ['ws', 'wss', 'post', 'cross-port']) {
    const fixture = await fixtureAdapter(() => ({ body: fixtureHtml('ok') }));
    const proxy = await filteringProxy(fixture.policy, '127.0.0.1');
    proxy.arm();
    try {
      const target = new URL(fixture.deniedOrigin);
      let token = '';
      if (['post', 'cross-port'].includes(mode)) { proxy.authorize(fixture.origin + '/', 'GET'); const c = proxy.authenticate(fixture.origin + '/', 'GET'); token = 'Basic ' + Buffer.from(c.username + ':' + c.password).toString('base64'); }
      const request = mode === 'wss' ? `CONNECT ${target.host} HTTP/1.1\r\nHost: ${target.host}\r\n\r\n` : mode === 'ws' ? `GET ${fixture.deniedOrigin}/ HTTP/1.1\r\nHost: ${target.host}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n\r\n` : `${mode === 'post' ? 'POST' : 'GET'} ${fixture.deniedOrigin}/ HTTP/1.1\r\nHost: ${target.host}\r\nProxy-Authorization: ${token}\r\nConnection: close\r\n\r\n`;
      assert.match(await raw(Number(new URL(proxy.url).port), request), /403/);
      if (mode === 'wss') {
        assert.throws(() => proxy.verify({ httpRequestsAuthorized: 0 }), /proxy-attribution-unproven/);
        const proof = { httpRequestsAuthorized: 0, allTargetsGuarded: true, httpsContinued: 0, websocketCreations: 0, networkEnabled: true, fetchEnabled: true };
        assert.equal(proxy.verify(proof).tunnelDenials.length, 1);
        assert.throws(() => proxy.verify({ ...proof, websocketCreations: 1 }), /proxy-attribution-unproven/);
        assert.throws(() => proxy.verify({ ...proof, httpsContinued: 1 }), /proxy-attribution-unproven/);
        assert.throws(() => proxy.verify({ ...proof, allTargetsGuarded: false }), /proxy-attribution-unproven/);
      } else await assert.rejects(proxy.failed);
      assert.equal(fixture.deniedConnections, 0);
      assert.equal(fixture.hits.length, 0);
    } finally { await proxy.close(); await fixture.close(); }
  }
});
test('proxy forwards authorized HTTP and blocks all startup traffic', async () => {
  const fixture = await fixtureAdapter(() => ({ body: fixtureHtml('ok') }));
  const proxy = await filteringProxy(fixture.policy, '127.0.0.1');
  try {
    const port = Number(new URL(proxy.url).port);
    const request = `GET ${fixture.origin}/ HTTP/1.1\r\nHost: ${new URL(fixture.origin).host}\r\nConnection: close\r\n\r\n`;
    assert.match(await raw(port, request), /403/);
    assert.equal(fixture.hits.length, 0);
    proxy.arm();
    proxy.authorize(fixture.origin + '/', 'GET');
    const credentials = proxy.authenticate(fixture.origin + '/', 'GET');
    const body = await new Promise((resolve, reject) => { http.get(proxy.url, { path: fixture.origin + '/', headers: { host: new URL(fixture.origin).host, 'Proxy-Authorization': 'Basic ' + Buffer.from(credentials.username + ':' + credentials.password).toString('base64') } }, response => { let body = ''; response.on('data', x => { body += x; }); response.on('end', () => resolve(body)); }).on('error', reject); });
    assert.match(body, /LOCAL/);
    assert.equal(fixture.hits.length, 1);
    assert.equal(proxy.verify({ httpRequestsAuthorized: 1, allTargetsGuarded: true, httpsContinued: 0, websocketCreations: 0, networkEnabled: true, fetchEnabled: true }).startupBlocks.length, 1);
  } finally { await proxy.close(); await fixture.close(); }
});
