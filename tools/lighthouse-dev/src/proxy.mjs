import http from 'node:http';
import { randomBytes } from 'node:crypto';
import { checkRequest, SafetyError } from './policy.mjs';
import { publicTarget, urlShape } from './failure.mjs';

export const proxyOwnerHeader = 'proxy-authorization';
export const proxyProtocol = { version: 3, ownership: 'ephemeral proxy authentication plus one-use guard-issued method/URL claims; stripped before upstream; all claims reconciled', mode: 'loopback HTTP forwarding; CONNECT and Upgrade denied', loopbackBypass: false, overhead: 'one local forwarding hop; included in every audit' };
export async function filteringProxy(policy, address) {
  let failure, rejectFailure, closePromise, armed = false, closing = false;
  let upstreamRequests = 0, upstreamConnections = 0, nonReadOnlyUpstreamRequests = 0, unownedDenials = 0, authChallenges = 0, authenticatedAuxiliaryRequests = 0;
  const startupBlocks = [], tunnelDenials = [], unownedSamples = [], claims = [];
  const nonce = randomBytes(32).toString('hex');
  const authorization = 'Basic ' + Buffer.from('freeplast-owned:' + nonce).toString('base64');
  const pending = () => claims.filter(c => !c.used && !c.cancelled).length;
  const consumed = () => claims.filter(c => c.used).length;
  const cancelled = () => claims.filter(c => c.cancelled).length;
  const claimFor = (url, method) => claims.find(c => !c.used && !c.cancelled && c.url === url && c.method === method);
  const diagnostic = (req, error, ownedRequest) => ({ source: 'proxy-http', method: ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'CONNECT', 'TRACE'].includes(req.method) ? req.method : 'OTHER', urlBranch: error?.urlBranch, target: publicTarget(req.url, policy.origin), urlShape: urlShape(req.url, policy.origin, req.headers.host, `127.0.0.1:${server.address()?.port}`), ownedRequest, loopbackPeer: req.socket.remoteAddress === '127.0.0.1', proxySocket: req.socket.localPort === server.address()?.port, upstreamRequests, upstreamConnections });
  const failed = new Promise((_, reject) => { rejectFailure = reject; });
  failed.catch(() => {});
  const requests = new Set();
  const stop = (code, detail) => {
    if (closing) return;
    if (!armed) { startupBlocks.push({ code, ...detail }); return; }
    if (!failure) { failure = new SafetyError(code); failure.diagnostic = detail; rejectFailure(failure); }
    for (const request of requests) request.destroy();
  };
  const server = http.createServer((req, res) => {
    const owned = req.headers[proxyOwnerHeader] === authorization;
    // Only this exact direct-proxy signature can be nonfatal; pending claims still fail verification.
    const probe = ['GET', 'HEAD'].includes(req.method) && req.url === '/' && req.headers.host === `127.0.0.1:${server.address()?.port}` && req.socket.remoteAddress === '127.0.0.1'
      && req.rawHeaders.filter((v, i) => i % 2 === 0 && v.toLowerCase() === 'host').length === 1
      && !req.headers[proxyOwnerHeader] && !req.headers.authorization && !req.headers.upgrade && !req.headers['transfer-encoding'] && (!req.headers['content-length'] || req.headers['content-length'] === '0');
    if (probe) {
      unownedDenials++;
      if (unownedSamples.length < 16) unownedSamples.push(diagnostic(req, null, false));
      res.writeHead(403); res.end(); return;
    }
    try {
      if (!armed) throw new SafetyError('startup-network-denied');
      if (failure) throw failure;
      if (req.headers.upgrade || req.headers.authorization) throw new SafetyError('proxy-forbidden-header');
      const url = checkRequest(policy, req.url, req.method, 'Other');
      if (url.origin !== policy.origin || url.protocol !== 'http:') throw new SafetyError('proxy-external-resource');
      if (typeof req.headers.host !== 'string' || req.headers.host.toLowerCase() !== url.host || req.rawHeaders.filter((v, i) => i % 2 === 0 && v.toLowerCase() === 'host').length !== 1) throw new SafetyError('proxy-host');
      if (req.headers['content-length'] && req.headers['content-length'] !== '0' || req.headers['transfer-encoding']) throw new SafetyError('proxy-request-body');
      const claim = claimFor(url.href, req.method);
      // Pinned Lighthouse RobotsTxt uses Network.loadNetworkResource outside Fetch interception.
      const auxiliary = !claim && owned && req.method === 'GET' && url.href === policy.origin + '/robots.txt';
      if (!claim && !auxiliary) throw new SafetyError('proxy-owner-binding');
      if (auxiliary && authenticatedAuxiliaryRequests !== 0) throw new SafetyError('proxy-auxiliary-replay');
      if (!owned) {
        if (req.headers[proxyOwnerHeader]) throw new SafetyError('proxy-owner-forged');
        authChallenges++;
        res.writeHead(407, { 'Proxy-Authenticate': 'Basic realm="freeplast-owned"' }); res.end(); return;
      }
      if (auxiliary) authenticatedAuxiliaryRequests++; else claim.used = true;
      const headers = { ...req.headers, host: url.host, ...(policy.fixture && policy.fixtureToken ? { 'x-freeplast-fixture-run': policy.fixtureToken } : {}) };
      delete headers['proxy-connection']; delete headers.connection; delete headers[proxyOwnerHeader];
      upstreamRequests++;
      if (!['GET', 'HEAD'].includes(req.method)) nonReadOnlyUpstreamRequests++;
      const upstream = http.request(url, {
        method: req.method, headers, agent: false,
        lookup: (_host, options, cb) => cb(null, options.all ? [{ address, family: address.includes(':') ? 6 : 4 }] : address, address.includes(':') ? 6 : 4),
      }, response => {
        res.writeHead(response.statusCode, response.headers); response.pipe(res);
        response.once('error', () => { stop('proxy-upstream'); res.destroy(); });
      });
      upstream.once('socket', socket => { socket.once('connect', () => { upstreamConnections++; }); });
      requests.add(upstream); upstream.once('close', () => requests.delete(upstream));
      upstream.once('error', () => { stop('proxy-upstream'); res.destroy(); });
      upstream.setTimeout(15000, () => { stop('proxy-upstream-timeout'); res.destroy(); });
      res.once('close', () => upstream.destroy()); upstream.end();
    } catch (error) {
      res.writeHead(403); res.end();
      stop(error instanceof SafetyError ? error.code : 'proxy-policy', diagnostic(req, error, owned));
    }
  });
  const rejectTunnel = code => (req, socket) => {
    socket.end('HTTP/1.1 403 Forbidden\r\nConnection: close\r\nContent-Length: 0\r\n\r\n');
    if (code === 'proxy-connect') tunnelDenials.push({ phase: armed ? 'measurement' : 'startup', upstreamConnections: 0 });
    else stop(code, { source: 'proxy-upgrade' });
  };
  server.on('connect', rejectTunnel('proxy-connect'));
  server.on('upgrade', rejectTunnel('proxy-websocket'));
  server.on('clientError', (_error, socket) => { const facts = { source: 'proxy-protocol', urlShape: { form: 'invalid', scheme: 'none' }, loopbackPeer: socket.remoteAddress === '127.0.0.1', proxySocket: socket.localPort === server.address()?.port }; socket.destroy(); stop('proxy-protocol', facts); });
  await new Promise((resolve, reject) => { server.once('error', reject); server.listen(0, '127.0.0.1', resolve); });
  return {
    url: `http://127.0.0.1:${server.address().port}`, failed,
    arm() { armed = true; },
    authorize(url, method, key = `claim-${claims.length}`, type = 'Other') {
      if (claims.length >= 4096) throw new SafetyError('proxy-authorization-capacity');
      const target = checkRequest(policy, url, method, 'Other');
      claims.push({ key, type, url: target.href, method, used: false, cancelled: false });
      return claims.length - 1;
    },
    revokeAfterExit(child, resources) {
      if (!closing || server.address() || requests.size || child.exitCode !== 0 || child.signalCode !== null) throw new SafetyError('proxy-revocation-unproven');
      for (const claim of claims.filter(c => !c.used && !c.cancelled)) {
        // Unused permission is revoked only after owner exit and a real matching resource response.
        if (!claim.key || !resources.some(r => r.url === claim.url && r.type === claim.type && r.status === 200)) throw new SafetyError('proxy-required-resource-unproven');
        claim.cancelled = true;
      }
    },
    authenticate(url, method) {
      const target = checkRequest(policy, url, method, 'Other');
      if (!claimFor(target.href, method)) throw new SafetyError('proxy-auth-unproven');
      return { username: 'freeplast-owned', password: nonce };
    },
    snapshot() { return { upstreamRequests, upstreamConnections, nonReadOnlyUpstreamRequests, activeRequests: requests.size, tunnelsDenied: tunnelDenials.length, authorizationClaims: claims.length, consumedAuthorizations: consumed(), cancelledAuthorizations: cancelled(), pendingAuthorizations: pending(), unownedDenials, authChallenges, authenticatedAuxiliaryRequests }; },
    assertPrivate(...reports) {
      if (reports.some(report => report.includes(nonce) || report.includes(authorization.slice(6)))) throw new SafetyError('proxy-owner-leak');
    },
    verify(rendererProof) {
      if (failure) throw failure;
      if (pending() || consumed() + authenticatedAuxiliaryRequests !== upstreamRequests || rendererProof?.httpRequestsAuthorized !== claims.length) throw new SafetyError('proxy-owner-unproven');
      if (!rendererProof?.allTargetsGuarded || rendererProof.httpsContinued !== 0 || rendererProof.websocketCreations !== 0 || !rendererProof.networkEnabled || !rendererProof.fetchEnabled) throw new SafetyError('proxy-attribution-unproven');
      return { ...proxyProtocol, startupBlocks, tunnelDenials, unownedSamples, ...this.snapshot(), revoked: claims.flatMap((c, id) => c.cancelled ? [{ requestIndex: id, target: publicTarget(c.url, policy.origin), method: c.method, type: c.type, reason: 'owner-exited-proxy-closed-resource-observed' }] : []), attribution: 'forwarded HTTP reconciled to guard claims plus at most one authenticated pinned-Lighthouse robots.txt auxiliary GET; exact unauthenticated direct-proxy probes denied, never attributed to a historical actor' };
    },
    close() {
      if (!closePromise) {
        closing = true;
        const settled = [...requests].map(request => new Promise(resolve => { request.once('close', resolve); request.destroy(); }));
        server.closeAllConnections(); closePromise = Promise.all([...settled, new Promise(resolve => server.close(resolve))]);
      }
      return closePromise;
    },
  };
}
