import { checkRequest, checkResponse, SafetyError } from './policy.mjs';
import { publicTarget, publicInitiator, urlShape } from './failure.mjs';

// Independent browser-level CDP connection: Lighthouse cannot detach our sessions.
export async function installGuard(browser, page, policy, onDocument = () => {}, ownership = null) {
  const own = await page.createCDPSession();
  const { targetInfo: main } = await own.send('Target.getTargetInfo');
  await own.detach();
  const socket = new WebSocket(browser.wsEndpoint());
  await new Promise((resolve, reject) => { socket.addEventListener('open', resolve, { once: true }); socket.addEventListener('error', reject, { once: true }); });
  let id = 0, closed = false, closing = false, failure;
  const provenance = { httpsDenied: 0, httpsContinued: 0, websocketCreations: 0, httpContinued: 0, httpRequestsAuthorized: 0, responseErrorEvents: 0, duplicateRequestPauses: 0, allTargetsGuarded: false, networkEnabled: false, fetchEnabled: false };
  const authorized = new Set(), authAttempted = new Set();
  const pending = new Map(), sessions = new Map(), initiators = new Map(), inventory = [], documents = [];
  let rejectFailure;
  const failed = new Promise((_, reject) => { rejectFailure = reject; });
  failed.catch(() => {});
  const fatal = (code, diagnostic) => { if (!failure && !closed) { failure = new SafetyError(code); failure.diagnostic = diagnostic; rejectFailure(failure); } };
  function send(method, params = {}, sessionId) {
    return new Promise((resolve, reject) => {
      const requestId = ++id;
      const timer = setTimeout(() => { pending.delete(requestId); reject(new SafetyError('guard-command-timeout')); }, 5000);
      pending.set(requestId, { resolve, reject, timer, method });
      socket.send(JSON.stringify({ id: requestId, method, params, ...(sessionId ? { sessionId } : {}) }));
    });
  }
  const auto = { autoAttach: true, waitForDebuggerOnStart: true, flatten: true, filter: [{ type: 'tab', exclude: true }, { type: 'browser', exclude: true }, {}] };
  async function event(message) {
    const { method, params, sessionId } = message;
    if (method === 'Target.attachedToTarget') {
      const { targetInfo, sessionId: child } = params;
      const entry = { type: targetInfo.type, targetId: targetInfo.targetId, ready: false };
      sessions.set(child, entry);
      if (!['page', 'iframe'].includes(targetInfo.type) || targetInfo.type === 'page' && targetInfo.targetId !== main.targetId) {
        fatal(targetInfo.type === 'page' ? 'popup' : 'worker-target', { source: 'paused-target', targetType: targetInfo.type, waitingForDebugger: params.waitingForDebugger });
        await send('Target.closeTarget', { targetId: targetInfo.targetId });
        return;
      }
      await send('Network.enable', {}, child);
      await send('Network.setBypassServiceWorker', { bypass: true }, child);
      await send('Network.setCacheDisabled', { cacheDisabled: true }, child);
      await send('Network.setBlockedURLs', { urls: ['ws://*', 'wss://*'] }, child);
      await send('Fetch.enable', { handleAuthRequests: true, patterns: [{ urlPattern: '*', requestStage: 'Request' }, { urlPattern: '*', requestStage: 'Response' }] }, child);
      await send('Target.setAutoAttach', auto, child);
      entry.ready = true;
      entry.networkEnabled = true;
      entry.fetchEnabled = true;
      await send('Runtime.runIfWaitingForDebugger', {}, child);
    } else if (method === 'Network.requestWillBeSent') {
      const key = `${sessionId}:${params.requestId}`;
      if (initiators.size >= 256) initiators.delete(initiators.keys().next().value);
      initiators.set(key, publicInitiator(params.initiator, policy.origin));
    } else if (method === 'Fetch.authRequired') {
      try {
        if (failure) throw failure;
        if (!sessions.get(sessionId)?.ready || !ownership || params.authChallenge?.source !== 'Proxy' || params.authChallenge.origin !== ownership.url || params.authChallenge.scheme !== 'basic' || params.authChallenge.realm !== 'freeplast-owned') throw new SafetyError('proxy-auth-provenance');
        if (authAttempted.has(params.requestId)) throw new SafetyError('proxy-auth-retry');
        const credentials = ownership.authenticate(params.request.url, params.request.method);
        authAttempted.add(params.requestId);
        await send('Fetch.continueWithAuth', { requestId: params.requestId, authChallengeResponse: { response: 'ProvideCredentials', ...credentials } }, sessionId);
      } catch (error) {
        await send('Fetch.continueWithAuth', { requestId: params.requestId, authChallengeResponse: { response: 'CancelAuth' } }, sessionId).catch(() => {});
        fatal(error instanceof SafetyError ? error.code : 'proxy-auth-failed', { source: 'proxy-auth', sessionKnown: sessions.has(sessionId), authSource: params.authChallenge?.source, authOriginMatches: params.authChallenge?.origin === ownership?.url, authSchemeBasic: params.authChallenge?.scheme === 'basic', authRealmMatches: params.authChallenge?.realm === 'freeplast-owned' });
      }
    } else if (method === 'Fetch.requestPaused') {
      const { requestId, request, resourceType, responseStatusCode } = params;
      const responseStage = responseStatusCode !== undefined || params.responseErrorReason !== undefined;
      if (params.responseErrorReason !== undefined) provenance.responseErrorEvents++;
      let continuationAttempted = false;
      try {
        if (failure) throw failure;
        if (!sessions.get(sessionId)?.ready) throw new SafetyError('renderer-provenance-missing');
        if (request.url.startsWith('https:')) provenance.httpsDenied++;
        checkRequest(policy, request.url, request.method, resourceType);
        if (resourceType === 'Document' && !responseStage) onDocument();
        checkResponse(resourceType, responseStatusCode, params.responseHeaders);
        if (responseStatusCode) {
          const item = { url: request.url, type: resourceType, status: responseStatusCode };
          inventory.push(item);
          if (resourceType === 'Document') documents.push(item);
        }
        if (request.url.startsWith('https:')) throw new SafetyError('https-resource-unsupported');
        if (!responseStage) {
          // CDP can pause the same network request again during proxy authentication.
          const key = `${sessionId}:${params.networkId || requestId}:${request.method}:${request.url}`;
          if (authorized.has(key)) provenance.duplicateRequestPauses++;
          else { ownership?.authorize(request.url, request.method, key, resourceType); authorized.add(key); provenance.httpRequestsAuthorized++; }
        }
        provenance.httpContinued++;
        continuationAttempted = true;
        await send('Fetch.continueRequest', { requestId }, sessionId);
      } catch (e) {
        let failRequestAcknowledged = false;
        await send('Fetch.failRequest', { requestId, errorReason: 'BlockedByClient' }, sessionId).then(() => { failRequestAcknowledged = true; }, () => {});
        fatal(e instanceof SafetyError ? e.code : 'guard-request-failure', { source: 'renderer-fetch', method: ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'CONNECT', 'TRACE'].includes(request.method) ? request.method : 'OTHER', target: publicTarget(request.url, policy.origin), urlBranch: e.urlBranch, urlShape: urlShape(request.url, policy.origin), initiator: initiators.get(`${sessionId}:${params.networkId}`) ?? null, frameKnown: typeof params.frameId === 'string', targetType: sessions.get(sessionId)?.type || 'unknown', sessionKnown: sessions.has(sessionId), resourceType, stage: responseStage ? 'Response' : 'Request', continuationAttempted, failRequestAcknowledged, httpsDenied: provenance.httpsDenied, publicRoute: policy.routes.find(path => request.url === policy.origin + path) || null, ...(policy.fixture ? { method: request.method, syntheticTarget: request.url } : {}) });
      }
    } else if (method === 'Network.webSocketCreated') {
      provenance.websocketCreations++;
      fatal(sessions.get(sessionId)?.ready ? 'websocket' : 'renderer-provenance-missing', { source: 'renderer-websocket', targetType: sessions.get(sessionId)?.type || 'unknown', sessionKnown: sessions.has(sessionId), websocketCreations: provenance.websocketCreations, ...(policy.fixture ? { syntheticTarget: params.url } : {}) });
    } else if (method === 'Target.detachedFromTarget') {
      const old = sessions.get(params.sessionId);
      if (old && !closing) fatal('guard-detached');
      sessions.delete(params.sessionId);
    }
  }
  socket.addEventListener('message', ({ data }) => {
    let message;
    try { message = JSON.parse(data); } catch { fatal('guard-protocol'); return; }
    if (message.id) {
      const item = pending.get(message.id);
      if (!item) return;
      clearTimeout(item.timer); pending.delete(message.id);
      if (message.error) {
        const error = new SafetyError('guard-command-failed');
        error.diagnostic = { method: item.method, message: message.error.message };
        item.reject(error);
      }
      else item.resolve(message.result);
    } else event(message).catch(() => fatal('guard-setup-failed'));
  });
  socket.addEventListener('close', () => { if (!closing) fatal('guard-disconnected'); else provenance.ownedShutdownDisconnect = true; });
  socket.addEventListener('error', () => { if (!closing) fatal('guard-disconnected', { source: 'guard-socket-error', duringOwnedShutdown: false }); else provenance.ownedShutdownDisconnect = true; });
  await send('Target.setAutoAttach', auto);
  const until = Date.now() + 5000;
  while (![...sessions.values()].some(x => x.targetId === main.targetId && x.ready)) {
    if (failure) throw failure;
    if (Date.now() > until) throw new SafetyError('guard-not-ready');
    await new Promise(resolve => setTimeout(resolve, 10));
  }
  return {
    failed, inventory, documents,
    snapshot() { return { ...provenance, guardedTargets: [...sessions.values()].filter(s => s.ready).length }; },
    async verify() {
      if (failure) throw failure;
      if (!documents.length || ![...sessions.values()].some(x => x.targetId === main.targetId && x.ready)) throw new SafetyError('guard-unproven');
      const { targetInfos } = await send('Target.getTargets');
      const rendererTargets = targetInfos.filter(x => !['browser', 'tab'].includes(x.type));
      const entries = [...sessions.values()];
      if (!rendererTargets.length || rendererTargets.some(target => !entries.some(x => x.targetId === target.targetId && x.ready && x.networkEnabled && x.fetchEnabled))) throw new SafetyError('renderer-provenance-missing');
      if (failure) throw failure;
      provenance.allTargetsGuarded = true;
      provenance.networkEnabled = true;
      provenance.fetchEnabled = true;
      return { version: 2, active: true, documents, resources: inventory, rendererProof: { ...provenance }, targets: rendererTargets.map(x => ({ type: x.type, guarded: true })) };
    },
    beginShutdown() { closing = true; },
    assertHealthy() { if (failure) throw failure; return { ...provenance }; },
    close() {
      closed = true;
      for (const { reject, timer } of pending.values()) { clearTimeout(timer); reject(new SafetyError('guard-closed')); }
      pending.clear(); socket.close();
    },
  };
}
