import test from 'node:test';
import assert from 'node:assert/strict';
import WebSocket from 'ws';
const { Server: WebSocketServer } = WebSocket;
import { once } from 'node:events';
import { installGuard } from '../src/guard.mjs';
import { policy } from '../src/policy.mjs';
import { assertHttpsDenial } from './security-oracle.mjs';

async function fixture(synthetic = false) {
  const server = new WebSocketServer({ host: '127.0.0.1', port: 0 });
  await once(server, 'listening');
  let client, extraTarget = false;
  const commands = [];
  const target = { targetId: 'main', type: 'page', url: 'about:blank' };
  server.on('connection', socket => {
    client = socket;
    socket.on('message', data => {
      const message = JSON.parse(data);
      commands.push(message);
      socket.send(JSON.stringify({ id: message.id, result: message.method === 'Target.getTargets' ? { targetInfos: extraTarget ? [target, { targetId: 'unguarded', type: 'page' }] : [target] } : {} }));
      if (message.method === 'Target.setAutoAttach' && !message.sessionId) socket.send(JSON.stringify({ method: 'Target.attachedToTarget', params: { sessionId: 'main-session', targetInfo: target, waitingForDebugger: false } }));
    });
  });
  const browser = { wsEndpoint: () => `ws://127.0.0.1:${server.address().port}` };
  const page = { createCDPSession: async () => ({ send: async () => ({ targetInfo: target }), detach: async () => {} }) };
  const guard = await installGuard(browser, page, synthetic ? policy({ fixtureOrigin: `http://127.0.0.1:${server.address().port}` }) : policy());
  const event = (method, params, sessionId = 'main-session') => client.send(JSON.stringify({ method, params, sessionId }));
  return {
    guard, commands, event,
    disconnect: () => client.terminate(),
    extraTarget: () => { extraTarget = true; },
    async document() {
      event('Fetch.requestPaused', { requestId: 'doc', request: { url: 'http://mliu:8131/?fp-measure=1', method: 'GET' }, resourceType: 'Document', responseStatusCode: 200, responseHeaders: [{ name: 'Cache-Control', value: 'no-store' }] });
      while (!guard.documents.length) await new Promise(resolve => setTimeout(resolve, 1));
    },
    async close() { guard.close(); for (const socket of server.clients) socket.terminate(); await new Promise(resolve => server.close(resolve)); },
  };
}

test('guard enables Fetch and Network before resume; verifies every renderer target', async () => {
  const f = await fixture();
  try {
    const methods = f.commands.map(x => x.method);
    assert.ok(methods.indexOf('Network.enable') < methods.indexOf('Runtime.runIfWaitingForDebugger'));
    assert.ok(methods.indexOf('Fetch.enable') < methods.indexOf('Runtime.runIfWaitingForDebugger'));
    await f.document();
    assert.equal((await f.guard.verify()).rendererProof.allTargetsGuarded, true);
    f.extraTarget();
    await assert.rejects(f.guard.verify(), /renderer-provenance-missing/);
  } finally { await f.close(); }
});
test('unexpected CDP disconnect and detached renderer fail closed', async () => {
  for (const mode of ['disconnect', 'detach']) {
    const f = await fixture();
    try {
      if (mode === 'disconnect') f.disconnect();
      else f.event('Target.detachedFromTarget', { sessionId: 'main-session' }, undefined);
      await assert.rejects(f.guard.failed, mode === 'disconnect' ? /guard-disconnected/ : /guard-detached/);
    } finally { await f.close(); }
  }
});
test('HTTPS GET and observed OPTIONS both fail before continuation; method ordering is explicit', async () => {
  for (const method of ['GET', 'OPTIONS', 'POST']) {
    const f = await fixture(true);
    const url = 'https://127.0.0.1:444/';
    try {
      f.event('Fetch.requestPaused', { requestId: 'https-request', request: { url, method }, resourceType: 'XHR' });
      const error = await f.guard.failed.catch(error => error);
      assert.equal(error.code, method === 'GET' ? 'https-resource-unsupported' : 'method');
      assert.equal(error.diagnostic.method, method);
      assert.equal(error.diagnostic.syntheticTarget, url);
      assert.equal(error.diagnostic.resourceType, 'XHR');
      assert.equal(error.diagnostic.continuationAttempted, false);
      assert.equal(error.diagnostic.failRequestAcknowledged, true);
      assert.equal(f.commands.some(x => x.method === 'Fetch.continueRequest'), false);
      assert.ok(f.commands.some(x => x.method === 'Fetch.failRequest' && x.params.requestId === 'https-request'));
      const expected = { url, resourceType: 'XHR', allowPreflight: true };
      if (method === 'POST') assert.throws(() => assertHttpsDenial(error.code, error.diagnostic, expected));
      else assertHttpsDenial(error.code, error.diagnostic, expected);
    } finally { await f.close(); }
  }
});
test('unknown renderer provenance and HTTPS continuation are rejected before continueRequest', async () => {
  for (const mode of ['unknown', 'https']) {
    const f = await fixture();
    try {
      f.event('Fetch.requestPaused', { requestId: 'denied', request: { url: mode === 'https' ? 'https://accounts.google.com/fixture.png' : 'http://mliu:8131/logo.png?token=1', method: 'GET' }, resourceType: 'Image' }, mode === 'unknown' ? 'missing-session' : 'main-session');
      await assert.rejects(f.guard.failed, mode === 'https' ? /https-resource-unsupported/ : /renderer-provenance-missing/);
      assert.equal(f.commands.some(x => x.method === 'Fetch.continueRequest' && x.params.requestId === 'denied'), false);
      assert.ok(f.commands.some(x => x.method === 'Fetch.failRequest' && x.params.requestId === 'denied'));
    } finally { await f.close(); }
  }
});
