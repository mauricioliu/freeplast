import { routes, staticSourceAssets } from './policy.mjs';

// Only source-known public names; never persist arbitrary URL/query/body/header strings.
const assets = new Set([...staticSourceAssets,
  '/wp-includes/js/jquery/jquery.min.js', '/wp-includes/js/jquery/jquery.js',
  '/wp-content/plugins/woocommerce/assets/js/frontend/woocommerce.min.js',
  '/wp-content/plugins/woocommerce/assets/js/frontend/woocommerce.js',
  '/wp-content/plugins/woocommerce/assets/js/sourcebuster/sourcebuster.min.js',
  '/wp-content/themes/freeplast/assets/js/basket-count.js',
  '/wp-content/themes/freeplast/assets/js/loop-added-count.js',
  '/wp-content/themes/freeplast/assets/js/featured-variants.js',
  '/wp-content/themes/freeplast/assets/js/nav.js',
]);
export function publicTarget(raw, origin) {
  try {
    const u = new URL(raw, origin), approved = u.origin === origin;
    return { origin: approved ? 'approved' : 'other', path: approved && (routes.includes(u.pathname) || assets.has(u.pathname)) ? u.pathname : null,
      action: approved && u.searchParams.getAll('wc-ajax').length === 1 && u.searchParams.get('wc-ajax') === 'get_refreshed_fragments' ? 'get_refreshed_fragments' : null };
  } catch { return { origin: 'invalid', path: null, action: null }; }
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
    source: select(d.source, ['renderer-fetch', 'renderer-websocket', 'paused-target', 'proxy-http', 'proxy-upgrade', 'guard-socket-error']),
    method: select(d.method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'CONNECT', 'TRACE', 'OTHER']),
    target: { origin: select(d.target?.origin, ['approved', 'other', 'invalid']), path: routes.includes(d.target?.path) || assets.has(d.target?.path) ? d.target.path : null, action: select(d.target?.action, ['get_refreshed_fragments']) },
    initiator: assets.has(d.initiator) ? d.initiator : null,
    targetType: select(d.targetType, ['page', 'iframe', 'worker', 'service_worker', 'shared_worker', 'unknown']),
    resourceType: select(d.resourceType, ['Document', 'Script', 'Stylesheet', 'Image', 'Font', 'XHR', 'Fetch', 'Other', 'Preflight']),
    stage: select(d.stage, ['Request', 'Response']),
  };
  for (const key of ['sessionKnown', 'frameKnown', 'continuationAttempted', 'failRequestAcknowledged', 'waitingForDebugger']) result[key] = typeof d[key] === 'boolean' ? d[key] : null;
  for (const key of ['httpsDenied', 'websocketCreations', 'upstreamConnections', 'upstreamRequests']) result[key] = count(d[key]);
  return result;
}
export function failureEvidence(value = {}) {
  const out = { schema: 1, diagnostic: safeDiagnostic(value?.diagnostic) };
  for (const [group, keys] of Object.entries({ proxy: ['upstreamRequests', 'upstreamConnections', 'nonReadOnlyUpstreamRequests', 'activeRequests', 'tunnelsDenied'], guard: ['httpsDenied', 'httpsContinued', 'websocketCreations', 'httpContinued', 'guardedTargets'] })) {
    out[group] = Object.fromEntries(keys.map(k => [k, count(value?.[group]?.[k])]));
  }
  for (const [group, keys] of Object.entries({ cleanup: ['browserExitObserved', 'profileRemoved', 'proxyClosed', 'guardClosed'], worker: ['exitObserved', 'processGroupAbsent', 'groupKillSent', 'profileRemoved'] })) {
    out[group] = Object.fromEntries(keys.map(k => [k, typeof value?.[group]?.[k] === 'boolean' ? value[group][k] : null]));
  }
  return out;
}
export function recordFailure(run, error) {
  run.failureEvidence = failureEvidence(error?.evidence);
  const row = run.audits.at(-1);
  if (row?.status === 'incomplete') { row.failure = run.failure; row.failureEvidence = run.failureEvidence; }
}
