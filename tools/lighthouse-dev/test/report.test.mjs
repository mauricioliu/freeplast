import test from 'node:test';
import assert from 'node:assert/strict';
import { generateReport } from 'lighthouse';
import { metricIds, categoryIds, validateLhr, sanitize, summarize } from '../src/report.mjs';
import { policy, measureMode, measureUrl } from '../src/policy.mjs';
const p = policy();
export function sampleLhr() {
  return {
    lighthouseVersion: '13.5.0', requestedUrl: measureUrl(p.origin, '/'), finalDisplayedUrl: measureUrl(p.origin, '/'), finalUrl: measureUrl(p.origin, '/'),
    audits: { ...Object.fromEntries(metricIds.map(id => [id, { id, numericValue: 1, score: 1, scoreDisplayMode: 'numeric' }])), 'is-crawlable': { id: 'is-crawlable', score: 0, scoreDisplayMode: 'binary' } },
    categories: Object.fromEntries(categoryIds.map(id => [id, { id, score: 0.5, auditRefs: [{ id: 'is-crawlable' }] }])),
  };
}
test('collection requires finite nonnegative metrics, complete categories and no audit/runtime errors', () => {
  validateLhr(sampleLhr(), p);
  for (const value of [null, undefined, NaN, Infinity, -1, '1']) {
    const lhr = sampleLhr(); lhr.audits[metricIds[0]].numericValue = value;
    assert.throws(() => validateLhr(lhr, p), /missing-metric/);
  }
  for (const value of [null, undefined, NaN, Infinity, -1, 1.01, '1']) {
    const lhr = sampleLhr(); lhr.categories.seo.score = value;
    assert.throws(() => validateLhr(lhr, p), /missing-category/);
  }
  for (const mutate of [lhr => { lhr.runtimeError = {}; }, lhr => { delete lhr.categories.seo; }, lhr => { delete lhr.audits['is-crawlable']; }, lhr => { lhr.audits['is-crawlable'].scoreDisplayMode = 'error'; }, lhr => { lhr.finalDisplayedUrl = 'https://freeplast.cl/'; }]) {
    const lhr = sampleLhr(); mutate(lhr); assert.throws(() => validateLhr(lhr, p));
  }
  assert.equal(summarize(sampleLhr()).audits.seo[0].score, 0, 'intentional noindex failure retained');
  assert.deepEqual(summarize(sampleLhr()).ttfb, { unavailable: 'audit-not-exposed' });
});
test('JSON and HTML derive from same sanitized LHR, never screenshots/headers/DOM/form values', () => {
  const lhr = sampleLhr();
  Object.assign(lhr, { cookies: 'SYNTHETIC_COOKIE', requestHeaders: 'SYNTHETIC_AUTH', screenshot: 'SYNTHETIC_PIXELS', snippet: '<input value="SYNTHETIC_FORM">' });
  lhr.audits['final-screenshot'] = { details: { data: 'data:image/png;base64,SYNTHETIC_PIXELS' } };
  const safe = sanitize(lhr), json = JSON.stringify(safe), html = generateReport(safe, 'html');
  assert.doesNotMatch(json + html, /SYNTHETIC_/);
  assert.match(html, /Screenshot omitted for privacy/);
  assert.equal(safe.audits['is-crawlable'].score, 0);
  assert.throws(() => sanitize({ title: 'password=SYNTHETIC_SECRET' }), /sensitive-report/);
  assert.throws(() => sanitize({ url: p.origin + '/?token=SYNTHETIC_SECRET' }), /sensitive-report/);
  // Report URLs may carry only cache-busters and the exact measurement query.
  assert.equal(sanitize({ url: measureUrl(p.origin, '/') }).url, measureUrl(p.origin, '/'));
  assert.throws(() => sanitize({ url: p.origin + '/?fp-measure=2' }), /sensitive-report-url/);
  assert.throws(() => sanitize({ url: p.origin + '/?fp-measure=1&x=1' }), /sensitive-report-url/);
  assert.throws(() => sanitize({ url: 'http://user:pass@mliu:8131/' }), /sensitive-report-url/);
});
test('cookie audit ID and category references survive, but real cookie-table payloads do not', () => {
  const lhr = sampleLhr();
  const metadata = { id: 'third-party-cookies', title: 'Uses third-party cookies', description: 'Cookie audit', score: 0, scoreDisplayMode: 'binary', displayValue: '2 cookies found' };
  lhr.audits['third-party-cookies'] = { ...metadata, details: { type: 'table', headings: [{ key: 'name', valueType: 'text' }, { key: 'url', valueType: 'url' }], items: [
    { name: 'SYNTHETIC_COOKIE_NAME', url: 'https://example.invalid/' },
    { name: 'session=SYNTHETIC_RAW_COOKIE; Secure; HttpOnly', url: 'https://example.invalid/?token=SYNTHETIC_TOKEN' },
  ] }, cookies: [{ name: 'session', value: 'SYNTHETIC_COOKIE_VALUE' }], rawCookieLine: 'session=SYNTHETIC_RAW', requestHeaders: { Cookie: 'session=SYNTHETIC_HEADER' } };
  lhr.categories['best-practices'].auditRefs.push({ id: 'third-party-cookies', weight: 1 });
  lhr.node = { type: 'node', selector: '#SYNTHETIC_SELECTOR', path: 'SYNTHETIC_DOM_PATH', snippet: '<input value="SYNTHETIC_FORM">', nodeLabel: 'SYNTHETIC_LABEL' };
  validateLhr(lhr, p);
  const safe = sanitize(lhr);
  validateLhr(safe, p);
  assert.deepEqual(safe.audits['third-party-cookies'], metadata);
  assert.deepEqual(safe.categories, lhr.categories);
  for (const category of Object.values(safe.categories)) for (const ref of category.auditRefs) assert.equal(safe.audits[ref.id].id, ref.id);
  assert.equal(safe.audits['is-crawlable'].score, 0);
  assert.doesNotMatch(JSON.stringify(safe) + generateReport(safe, 'html'), /SYNTHETIC_/);
  assert.deepEqual(sanitize({ 'third-party-cookies': { name: 'SYNTHETIC_COOKIE' } }), {}, 'exception applies only to audit records');
  lhr.audits['third-party-cookies'].title = 'authorization: Bearer SYNTHETIC_SECRET';
  assert.throws(() => sanitize(lhr), /sensitive-report/, 'audit metadata still undergoes sensitive-string checks');
});
test('sanitized output must independently pass category-reference validation', () => {
  const lhr = sampleLhr();
  lhr.audits['cookie-payload'] = { id: 'cookie-payload', score: 1, scoreDisplayMode: 'binary', value: 'SYNTHETIC_COOKIE' };
  lhr.categories['best-practices'].auditRefs.push({ id: 'cookie-payload' });
  validateLhr(lhr, p);
  assert.throws(() => validateLhr(sanitize(lhr), p), /audit-error/, 'valid input cannot license an incomplete sanitized report');
  delete lhr.audits['cookie-payload'];
  assert.throws(() => validateLhr(lhr, p), /audit-error/, 'dangling category reference rejected');
});
