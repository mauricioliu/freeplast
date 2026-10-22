import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import net from 'node:net';
import { policy, checkRequest, checkResponse, publicAddress } from '../src/policy.mjs';
import { preflight } from '../src/preflight.mjs';
import { filteringProxy } from '../src/proxy.mjs';
import { fixtureAdapter, fixtureHtml } from './fixture-adapter.mjs';

const p = policy();
test('exact documents, assets and methods; no endpoint/origin aliases', () => {
  for (const path of p.routes) assert.equal(checkRequest(p, p.origin + path).origin, p.origin);
  assert.doesNotThrow(() => checkRequest(p, p.origin + '/wp-content/theme.css?ver=1.2', 'GET', 'Stylesheet'));
  for (const url of ['https://freeplast.cl/', 'https://freeplast.mliu.site/', 'http://mliu:8890/', 'http://localhost:8131/', 'http://127.0.0.1:8131/', 'http://user@mliu:8131/', p.origin + '/#x', p.origin + '/?ver=1', p.origin + '/%2e%2e/', p.origin + '/%252e/', p.origin + '/wp-admin/', p.origin + '/?add-to-cart=1', p.origin + '/?wc-ajax=1', p.origin + '/checkout/', p.origin + '/wp-json/', p.origin + '/wp-cron.php', 'ws://mliu:8131/']) assert.throws(() => checkRequest(p, url), url);
  for (const method of ['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']) assert.throws(() => checkRequest(p, p.origin + '/', method));
  assert.throws(() => checkRequest(p, p.origin + '/', 'GET', 'Document', true));
  assert.throws(() => checkRequest(p, 'https://freeplast.cl/logo.png', 'GET', 'Image'));
  assert.throws(() => checkRequest(p, p.origin + '/logo.png?token=secret', 'GET', 'Image'));
  assert.throws(() => checkRequest(p, 'https://cdn.example.com/logo.png', 'GET', 'Image'));
  assert.throws(() => checkRequest(policy({ hosts: { 'cdn.example.com': '8.8.8.8' } }), 'https://cdn.example.com/logo.png', 'GET', 'Image'), /https-resource-unsupported/);
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
  for (const mode of ['allowed', 'denied', 'loop', 'status', 'identity']) {
    const fixture = await fixtureAdapter(({ req, deniedOrigin }) => mode === 'status' ? { status: 429 } : mode === 'identity' ? { body: '<h1>Not LOCAL</h1>' } : req.url === '/' ? { status: 302, headers: { Location: mode === 'denied' ? deniedOrigin : mode === 'loop' ? '/' : '/tienda/' } } : { body: fixtureHtml('ok') });
    try {
      if (mode === 'allowed') assert.equal((await preflight(fixture.policy, '/', '127.0.0.1')).hops.length, 2);
      else await assert.rejects(preflight(fixture.policy, '/', '127.0.0.1'));
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
      const request = mode === 'wss' ? `CONNECT ${target.host} HTTP/1.1\r\nHost: ${target.host}\r\n\r\n` : mode === 'ws' ? `GET ${fixture.deniedOrigin}/ HTTP/1.1\r\nHost: ${target.host}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n\r\n` : `${mode === 'post' ? 'POST' : 'GET'} ${fixture.deniedOrigin}/ HTTP/1.1\r\nHost: ${target.host}\r\nConnection: close\r\n\r\n`;
      assert.match(await raw(Number(new URL(proxy.url).port), request), /403/);
      if (mode === 'wss') {
        assert.throws(() => proxy.verify(), /proxy-attribution-unproven/);
        const proof = { allTargetsGuarded: true, httpsContinued: 0, websocketCreations: 0, networkEnabled: true, fetchEnabled: true };
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
    const body = await new Promise((resolve, reject) => { http.get(proxy.url, { path: fixture.origin + '/' }, response => { let body = ''; response.on('data', x => { body += x; }); response.on('end', () => resolve(body)); }).on('error', reject); });
    assert.match(body, /LOCAL/);
    assert.equal(fixture.hits.length, 1);
    assert.equal(proxy.verify().startupBlocks.length, 1);
  } finally { await proxy.close(); await fixture.close(); }
});
