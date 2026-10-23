import { deny, checkRequest } from './policy.mjs';

export const metricIds = ['largest-contentful-paint', 'cumulative-layout-shift', 'first-contentful-paint', 'total-blocking-time', 'speed-index'];
export const categoryIds = ['performance', 'accessibility', 'best-practices', 'seo'];
export function validateLhr(lhr, p) {
  if (!lhr || lhr.runtimeError) deny('lighthouse-runtime');
  checkRequest(p, lhr.finalDisplayedUrl);
  checkRequest(p, lhr.finalUrl || lhr.finalDisplayedUrl);
  for (const id of metricIds) {
    const n = lhr.audits?.[id]?.numericValue;
    if (typeof n !== 'number' || !Number.isFinite(n) || n < 0) deny('missing-metric');
  }
  for (const id of categoryIds) {
    const category = lhr.categories?.[id];
    if (!category || typeof category.score !== 'number' || !Number.isFinite(category.score) || category.score < 0 || category.score > 1 || !category.auditRefs?.length) deny('missing-category');
    for (const ref of category.auditRefs) {
      const audit = lhr.audits?.[ref.id];
      if (!audit || audit.scoreDisplayMode === 'error') deny('audit-error');
    }
  }
}
const removed = new Set(['screenshot', 'screenshotData', 'full-page-screenshot', 'final-screenshot', 'screenshot-thumbnails', 'data', 'snippet', 'nodeLabel', 'text', 'value', 'requestHeaders', 'responseHeaders', 'headers', 'cookies', 'stack', 'stackTrace']);
const sensitive = /(?:password|authorization|bearer|secret|access[_-]?token|api[_-]?key)\s*[:= ]\s*[^\s<"']+/i;
export function sanitize(value, key = '', parent = '') {
  // Lighthouse's cookie table can put a raw Set-Cookie line in its `name` column.
  if (parent === 'audits' && key === 'third-party-cookies') return sanitize({ ...value, details: undefined });
  if (value?.type === 'node') return { type: 'text', value: '[DOM omitted]' };
  if (['full-page-screenshot', 'final-screenshot', 'screenshot-thumbnails'].includes(key)) return { id: key, title: 'Screenshot omitted for privacy', description: 'No pixels persisted', score: null, scoreDisplayMode: 'notApplicable' };
  if (removed.has(key) || /cookie|authorization|password|secret|token/i.test(key)) return undefined;
  if (typeof value === 'string') {
    if (sensitive.test(value)) deny('sensitive-report');
    if (/^data:|^blob:|^mailto:|^tel:/i.test(value)) return '[omitted]';
    if (/^https?:\/\//i.test(value)) {
      let url;
      try { url = new URL(value); } catch { deny('report-url'); }
      // `ver` cache-busters and the exact measurement query are the only allowed URL parameters.
      const bad = ([key, v]) => key === 'fp-measure' ? v !== '1' : key !== 'ver' || !/^[\w.-]{1,80}$/.test(v);
      if (url.username || url.password || [...url.searchParams].some(bad)) deny('sensitive-report-url');
      url.hash = '';
      return url.href;
    }
    return value;
  }
  if (Array.isArray(value)) return value.map(item => sanitize(item)).filter(item => item !== undefined);
  if (value && typeof value === 'object') return Object.fromEntries(Object.entries(value).map(([k, v]) => [k, sanitize(v, k, key)]).filter(([, v]) => v !== undefined));
  return value;
}
export function summarize(lhr) {
  return {
    metrics: Object.fromEntries(metricIds.map(id => [id, lhr.audits[id].numericValue])),
    scores: Object.fromEntries(categoryIds.map(id => [id, lhr.categories[id].score * 100])),
    audits: Object.fromEntries(categoryIds.slice(1).map(category => [category, lhr.categories[category].auditRefs.map(({ id }) => ({ id, score: lhr.audits[id].score, mode: lhr.audits[id].scoreDisplayMode }))])),
    ttfb: typeof lhr.audits['server-response-time']?.numericValue === 'number' ? { value: lhr.audits['server-response-time'].numericValue, auditId: 'server-response-time' } : { unavailable: 'audit-not-exposed' },
    noindex: 'intentional; is-crawlable failure retained',
  };
}
