import { routes, staticSourceAssets } from './policy.mjs';

// Only source-known public names; never persist arbitrary URL/query/body/header strings.
const assets = new Set([...staticSourceAssets, '/robots.txt', '/favicon.ico',
  '/wp-includes/js/jquery/jquery.min.js', '/wp-includes/js/jquery/jquery.js',
  '/wp-content/plugins/woocommerce/assets/js/frontend/woocommerce.min.js',
  '/wp-content/plugins/woocommerce/assets/js/frontend/woocommerce.js',
  '/wp-content/plugins/woocommerce/assets/js/sourcebuster/sourcebuster.min.js',
  '/wp-content/themes/freeplast/assets/js/basket-count.js',
  '/wp-content/themes/freeplast/assets/js/loop-added-count.js',
  '/wp-content/themes/freeplast/assets/js/featured-variants.js',
  '/wp-content/themes/freeplast/assets/js/nav.js',
]);
export const publicCdpCode = value => Number.isInteger(value) && value >= -2147483648 && value <= 2147483647 ? value : null;
export function publicTarget(raw, origin) {
  try {
    const u = new URL(raw), approved = u.origin === origin;
    return { origin: approved ? 'approved' : 'other', path: approved && (routes.includes(u.pathname) || assets.has(u.pathname)) ? u.pathname : null,
      action: approved && u.searchParams.getAll('wc-ajax').length === 1 && u.searchParams.get('wc-ajax') === 'get_refreshed_fragments' ? 'get_refreshed_fragments' : null };
  } catch { return { origin: 'invalid', path: null, action: null }; }
}
export function urlShape(raw, origin, host, proxyHost) {
  const form = raw === '*' ? 'asterisk' : typeof raw === 'string' && /^[a-z][a-z0-9+.-]*:/i.test(raw) ? 'absolute' : typeof raw === 'string' && /^\/(?!\/)/.test(raw) ? 'origin-form' : 'invalid';
  let parsed; try { parsed = new URL(raw); } catch {}
  return { form, scheme: parsed ? (['http:', 'https:'].includes(parsed.protocol) ? parsed.protocol.slice(0, -1) : 'other') : 'none',
    hasUserinfo: parsed ? Boolean(parsed.username || parsed.password) : null, hasHash: parsed ? Boolean(parsed.hash) : null,
    host: typeof host !== 'string' ? 'missing' : host.toLowerCase() === new URL(origin).host ? 'approved' : host.toLowerCase() === proxyHost ? 'proxy' : 'other' };
}
export function publicInitiator(initiator, origin) {
  const urls = [initiator?.url, ...(initiator?.stack?.callFrames || []).map(f => f.url)];
  let library = null;
  for (const raw of urls.slice(0, 32)) {
    if (typeof raw !== 'string') continue;
    const target = publicTarget(raw, origin);
    if (assets.has(target.path)) { if (target.path.startsWith('/wp-includes/js/jquery/')) library ||= target.path; else return target.path; }
  }
  return library;
}
const select = (value, names) => names.includes(value) ? value : null;
const count = n => Number.isSafeInteger(n) && n >= 0 ? n : null;
export function safeDiagnostic(d = {}) {
  if (!d || typeof d !== 'object') d = {};
  const result = {
    source: select(d.source, ['renderer-fetch', 'renderer-websocket', 'paused-target', 'proxy-http', 'proxy-auth', 'proxy-protocol', 'proxy-upgrade', 'guard-socket-error']),
    cdpCommand: select(d.cdpCommand, ['Fetch.continueRequest', 'Fetch.failRequest', 'Fetch.continueWithAuth', 'Target.getTargets', 'Target.setAutoAttach', 'Target.closeTarget', 'Network.enable', 'Fetch.enable']),
    cdpCategory: select(d.cdpCategory, ['stale-interception', 'session-gone', 'transport-closed', 'timeout', 'unknown']),
    cdpCode: publicCdpCode(d.cdpCode),
    phase: select(d.phase, ['launch', 'lighthouse', 'capture', 'owned-shutdown']),
    authSource: select(d.authSource, ['Proxy', 'Server']),
    urlBranch: select(d.urlBranch, ['parse', 'scheme', 'userinfo', 'hash']),
    urlShape: { form: select(d.urlShape?.form, ['absolute', 'origin-form', 'asterisk', 'invalid']), scheme: select(d.urlShape?.scheme, ['http', 'https', 'other', 'none']), host: select(d.urlShape?.host, ['approved', 'proxy', 'other', 'missing']), hasUserinfo: typeof d.urlShape?.hasUserinfo === 'boolean' ? d.urlShape.hasUserinfo : null, hasHash: typeof d.urlShape?.hasHash === 'boolean' ? d.urlShape.hasHash : null },
    method: select(d.method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'CONNECT', 'TRACE', 'OTHER']),
    target: { origin: select(d.target?.origin, ['approved', 'other', 'invalid']), path: routes.includes(d.target?.path) || assets.has(d.target?.path) ? d.target.path : null, action: select(d.target?.action, ['get_refreshed_fragments']) },
    initiator: assets.has(d.initiator) ? d.initiator : null,
    targetType: select(d.targetType, ['page', 'iframe', 'worker', 'service_worker', 'shared_worker', 'unknown']),
    resourceType: select(d.resourceType, ['Document', 'Script', 'Stylesheet', 'Image', 'Font', 'XHR', 'Fetch', 'Other', 'Preflight']),
    stage: select(d.stage, ['Request', 'Response']),
  };
  for (const key of ['sessionKnown', 'frameKnown', 'continuationAttempted', 'failRequestAcknowledged', 'waitingForDebugger', 'ownedRequest', 'loopbackPeer', 'proxySocket', 'authOriginMatches', 'authSchemeBasic', 'authRealmMatches', 'duringOwnedShutdown', 'terminalSessionObserved', 'sessionKnownAtPause', 'responseValidated', 'responseErrorEvent', 'captureWanted', 'finalized', 'terminalAtSettlement']) result[key] = typeof d[key] === 'boolean' ? d[key] : null;
  for (const key of ['httpsDenied', 'websocketCreations', 'upstreamConnections', 'upstreamRequests']) result[key] = count(d[key]);
  return result;
}
export function failureEvidence(value = {}) {
  const out = { schema: 1, diagnostic: safeDiagnostic(value?.diagnostic) };
  for (const [group, keys] of Object.entries({ proxy: ['upstreamRequests', 'upstreamConnections', 'nonReadOnlyUpstreamRequests', 'activeRequests', 'tunnelsDenied', 'authorizationClaims', 'consumedAuthorizations', 'cancelledAuthorizations', 'pendingAuthorizations', 'unownedDenials', 'authChallenges', 'authenticatedAuxiliaryRequests'], guard: ['httpsDenied', 'httpsContinued', 'websocketCreations', 'httpContinued', 'httpRequestsAuthorized', 'responseErrorEvents', 'duplicateRequestPauses', 'shutdownResponseCancellations', 'pendingShutdownResponses', 'guardedTargets'] })) {
    out[group] = Object.fromEntries(keys.map(k => [k, count(value?.[group]?.[k])]));
  }
  for (const [group, keys] of Object.entries({ cleanup: ['browserExitObserved', 'profileRemoved', 'proxyClosed', 'guardClosed'], worker: ['exitObserved', 'processGroupAbsent', 'groupKillSent', 'profileRemoved'] })) {
    out[group] = Object.fromEntries(keys.map(k => [k, typeof value?.[group]?.[k] === 'boolean' ? value[group][k] : null]));
  }
  out.guard.shutdownResponses = Array.isArray(value?.guard?.shutdownResponses) ? value.guard.shutdownResponses.slice(0, 16).map(safeDiagnostic) : null;
  return out;
}
export function recordFailure(run, error) {
  run.failureEvidence = failureEvidence(error?.evidence);
  const row = run.audits.at(-1);
  if (row?.status === 'incomplete') { row.failure = run.failure; row.failureEvidence = run.failureEvidence; }
}
