import { createServer } from 'node:http';
import { createServer as createTLSServer } from 'node:tls';
import { policy } from '../src/policy.mjs';
import { randomUUID } from 'node:crypto';
import { profiles } from '../src/config.mjs';

export async function fixtureAdapter(render) {
  const hits = [], denied = [], tlsContacts = [], deniedContacts = [];
  const runMarker = randomUUID();
  const contact = socket => ({ at: new Date().toISOString(), remoteAddress: socket.remoteAddress, remotePort: socket.remotePort, localPort: socket.localPort });
  const trace = req => ({ ...contact(req.socket), method: req.method, path: req.url, markerMatches: req.headers['x-freeplast-fixture-run'] === runMarker, userAgentProfile: Object.keys(profiles).find(name => req.headers['user-agent'] === profiles[name].emulatedUserAgent) || 'other', userAgentClass: (req.headers['user-agent'] || '').includes('Chrome/') ? 'chromium' : (req.headers['user-agent'] || '').includes('Freeplast-DEV-preflight') ? 'preflight' : 'other' });
  let deniedConnections = 0, deniedTLSConnections = 0;
  const tlsSockets = new Set();
  // No certificate needed: the safety assertion forbids even the first TCP connection.
  const tlsServer = createTLSServer();
  tlsServer.on('connection', socket => { deniedTLSConnections++; tlsContacts.push(contact(socket)); tlsSockets.add(socket); socket.once('close', () => tlsSockets.delete(socket)); });
  tlsServer.on('tlsClientError', () => {});
  await new Promise(resolve => tlsServer.listen(0, '127.0.0.1', resolve));
  const deniedTLSOrigin = `https://127.0.0.1:${tlsServer.address().port}`;
  const deniedServer = createServer((req, res) => { denied.push(trace(req)); res.end('DENIED'); });
  deniedServer.on('connection', socket => { deniedConnections++; deniedContacts.push(contact(socket)); });
  deniedServer.on('upgrade', (req, socket) => { denied.push({ ...trace(req), method: 'WS' }); socket.destroy(); });
  await new Promise(resolve => deniedServer.listen(0, '127.0.0.1', resolve));
  const deniedOrigin = `http://127.0.0.1:${deniedServer.address().port}`;
  const server = createServer((req, res) => {
    hits.push(trace(req));
    if (req.url === '/robots.txt') { res.end('User-agent: *\nDisallow: /\n'); return; }
    if (req.url === '/favicon.ico') { res.writeHead(404); res.end(); return; }
    const content = render({ req, hits, deniedOrigin, deniedTLSOrigin });
    res.writeHead(content.status || 200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store', ...content.headers });
    res.end(content.body || fixtureHtml(''));
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const origin = `http://127.0.0.1:${server.address().port}`;
  return {
    policy: { ...policy({ fixtureOrigin: origin, fixtureDenied: [deniedOrigin] }), fixtureToken: runMarker }, origin, deniedOrigin, deniedTLSOrigin, hits, denied, runMarker, tlsContacts, deniedContacts, get deniedConnections() { return deniedConnections; }, get deniedTLSConnections() { return deniedTLSConnections; },
    async close() { server.closeAllConnections(); deniedServer.closeAllConnections(); for (const socket of tlsSockets) socket.destroy(); await Promise.all([new Promise(resolve => server.close(resolve)), new Promise(resolve => deniedServer.close(resolve)), new Promise(resolve => tlsServer.close(resolve))]); },
  };
}
export const fixtureHtml = body => `<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="description" content="Synthetic security fixture"><title>LOCAL fixture</title></head><body><div aria-hidden="true" style="position:fixed;left:0;top:0;width:8px;height:8px;background:#123456"></div><main><h1>LOCAL</h1>${body}</main></body></html>`;
