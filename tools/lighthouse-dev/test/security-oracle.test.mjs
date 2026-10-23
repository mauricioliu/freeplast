import test from 'node:test';
import assert from 'node:assert/strict';
import { assertHttpsDenial } from './security-oracle.mjs';

import { assetVectors, expectedStops, staticRequests } from './security-vectors.mjs';
import { checkRequest, policy, origin } from '../src/policy.mjs';

test('native static-resource vectors and diagnostic expectations agree with pure policy', () => {
  for (const v of assetVectors) assert.throws(() => checkRequest(policy(), origin + v.path, 'GET', v.type), e => expectedStops[v.name].includes(e.code));
  for (const path of staticRequests) assert.equal(checkRequest(policy(), origin + path, 'GET', 'Script').href, origin + path);
  assert.throws(() => checkRequest(policy(), origin + '/', 'POST'), e => expectedStops.POST.includes(e.code));
  assert.throws(() => checkRequest(policy(), origin + '/?add-to-cart=1', 'GET', 'XHR'), e => expectedStops['commercial-GET'].includes(e.code));
  assert.throws(() => checkRequest(policy(), origin + '/', 'GET', 'Document', true), e => expectedStops.popup.includes(e.code));
});

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
