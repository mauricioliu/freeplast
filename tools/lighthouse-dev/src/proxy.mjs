import http from 'node:http';
import { checkRequest, SafetyError } from './policy.mjs';
import { publicTarget } from './failure.mjs';

export const proxyProtocol = { version: 2, mode: 'loopback HTTP forwarding; CONNECT and Upgrade denied', loopbackBypass: false, overhead: 'one local forwarding hop; included in every audit' };
export async function filteringProxy(policy, address) {
  let failure, rejectFailure, armed = false, closing = false;
  let upstreamRequests = 0, upstreamConnections = 0, nonReadOnlyUpstreamRequests = 0;
  const startupBlocks = [], tunnelDenials = [];
  const failed = new Promise((_, reject) => { rejectFailure = reject; });
  failed.catch(() => {});
  const requests = new Set();
  const stop = (code, diagnostic) => {
    if (closing) return;
    if (!armed) { startupBlocks.push({ code, ...diagnostic }); return; }
    if (!failure) { failure = new SafetyError(code); failure.diagnostic = diagnostic; rejectFailure(failure); }
    for (const request of requests) request.destroy();
  };
  const server = http.createServer((req, res) => {
    try {
      if (!armed) throw new SafetyError('startup-network-denied');
      if (failure) throw failure;
      if (req.headers.upgrade || req.headers.authorization || req.headers['proxy-authorization']) throw new SafetyError('proxy-forbidden-header');
      const url = checkRequest(policy, req.url, req.method, 'Other');
      if (url.origin !== policy.origin || url.protocol !== 'http:') throw new SafetyError('proxy-external-resource');
      if (req.headers['content-length'] && req.headers['content-length'] !== '0' || req.headers['transfer-encoding']) throw new SafetyError('proxy-request-body');
      const headers = { ...req.headers, host: url.host, ...(policy.fixture && policy.fixtureToken ? { 'x-freeplast-fixture-run': policy.fixtureToken } : {}) };
      delete headers['proxy-connection']; delete headers.connection;
      upstreamRequests++;
      if (!['GET', 'HEAD'].includes(req.method)) nonReadOnlyUpstreamRequests++;
      const upstream = http.request(url, {
        method: req.method, headers, agent: false,
        lookup: (_host, options, cb) => cb(null, options.all ? [{ address, family: address.includes(':') ? 6 : 4 }] : address, address.includes(':') ? 6 : 4),
      }, response => {
        res.writeHead(response.statusCode, response.headers);
        response.pipe(res);
        response.once('error', () => { stop('proxy-upstream'); res.destroy(); });
      });
      upstream.once('socket', socket => { socket.once('connect', () => { upstreamConnections++; }); });
      requests.add(upstream);
      upstream.once('close', () => requests.delete(upstream));
      upstream.once('error', () => { stop('proxy-upstream'); res.destroy(); });
      upstream.setTimeout(15000, () => { stop('proxy-upstream-timeout'); res.destroy(); });
      res.once('close', () => upstream.destroy());
      upstream.end();
    } catch (error) {
      res.writeHead(403); res.end();
      stop(error instanceof SafetyError ? error.code : 'proxy-policy', { source: 'proxy-http', method: ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'CONNECT', 'TRACE'].includes(req.method) ? req.method : 'OTHER', target: publicTarget(req.url, policy.origin), upstreamRequests, upstreamConnections });
    }
  });
  const rejectTunnel = code => (req, socket) => {
    socket.end('HTTP/1.1 403 Forbidden\r\nConnection: close\r\nContent-Length: 0\r\n\r\n');
    if (code === 'proxy-connect') {
      // No hostname exemption: all tunnels remain denied. Acceptance requires renderer proof.
      tunnelDenials.push({ phase: armed ? 'measurement' : 'startup', upstreamConnections: 0 });
    } else stop(code, { source: 'proxy-upgrade' });
  };
  server.on('connect', rejectTunnel('proxy-connect'));
  server.on('upgrade', rejectTunnel('proxy-websocket'));
  server.on('clientError', (_error, socket) => { socket.destroy(); stop('proxy-protocol'); });
  await new Promise((resolve, reject) => { server.once('error', reject); server.listen(0, '127.0.0.1', resolve); });
  return {
    url: `http://127.0.0.1:${server.address().port}`, failed,
    arm() { armed = true; },
    snapshot() { return { upstreamRequests, upstreamConnections, nonReadOnlyUpstreamRequests, activeRequests: requests.size, tunnelsDenied: tunnelDenials.length }; },
    verify(rendererProof) {
      if (failure) throw failure;
      if (tunnelDenials.some(x => x.phase === 'measurement') && (!rendererProof?.allTargetsGuarded || rendererProof.httpsContinued !== 0 || rendererProof.websocketCreations !== 0 || !rendererProof.networkEnabled || !rendererProof.fetchEnabled)) throw new SafetyError('proxy-attribution-unproven');
      return { ...proxyProtocol, startupBlocks, tunnelDenials, attribution: tunnelDenials.length ? 'non-required tunnels: renderer HTTPS/WS cannot succeed under the verified guard' : 'no tunnels' };
    },
    async close() {
      closing = true;
      const settled = [...requests].map(request => new Promise(resolve => { request.once('close', resolve); request.destroy(); }));
      server.closeAllConnections(); await Promise.all([...settled, new Promise(resolve => server.close(resolve))]);
    },
  };
}
