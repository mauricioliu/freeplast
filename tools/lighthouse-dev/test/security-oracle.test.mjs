import test from 'node:test';
import assert from 'node:assert/strict';
import { assertHttpsDenial } from './security-oracle.mjs';

const url = 'https://127.0.0.1:444/';
const diagnostic = { source: 'renderer-fetch', sessionKnown: true, targetType: 'page', syntheticTarget: url, resourceType: 'XHR', stage: 'Request', continuationAttempted: false, failRequestAcknowledged: true, httpsDenied: 1, method: 'GET' };
const expected = { url, resourceType: 'XHR', allowPreflight: true };

test('HTTPS oracle accepts only observed exact-target GET denial or captured OPTIONS-before-GET denial', () => {
  assert.equal(assertHttpsDenial('https-resource-unsupported', diagnostic, expected), 'observed-https-get-blocked');
  assert.equal(assertHttpsDenial('method', { ...diagnostic, method: 'OPTIONS' }, expected), 'observed-options-blocked-before-https');
  for (const change of [
    { method: undefined }, { method: 'POST' }, { syntheticTarget: 'https://127.0.0.1:445/' },
    { source: 'proxy-http' }, { sessionKnown: false }, { targetType: 'unknown' },
    { resourceType: 'Image' }, { stage: 'Response' }, { continuationAttempted: true },
    { failRequestAcknowledged: false }, { httpsDenied: 0 },
  ]) assert.throws(() => assertHttpsDenial('method', { ...diagnostic, method: 'OPTIONS', ...change }, expected));
  assert.throws(() => assertHttpsDenial('method', diagnostic, expected));
  assert.throws(() => assertHttpsDenial('https-resource-unsupported', { ...diagnostic, method: 'OPTIONS' }, expected));
});
test('independent HTTPS image oracle requires GET; OPTIONS cannot satisfy it', () => {
  const image = { ...diagnostic, resourceType: 'Image', syntheticTarget: 'https://accounts.google.com/fixture.png' };
  const expectedImage = { url: image.syntheticTarget, resourceType: 'Image' };
  assert.equal(assertHttpsDenial('https-resource-unsupported', image, expectedImage), 'observed-https-get-blocked');
  assert.throws(() => assertHttpsDenial('method', { ...image, method: 'OPTIONS' }, expectedImage));
});
