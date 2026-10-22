import assert from 'node:assert/strict';

export function assertHttpsDenial(error, diagnostic, { url, resourceType, allowPreflight = false }) {
  assert.equal(diagnostic?.source, 'renderer-fetch');
  assert.equal(diagnostic.sessionKnown, true);
  assert.ok(['page', 'iframe'].includes(diagnostic.targetType));
  assert.equal(diagnostic.syntheticTarget, url);
  assert.equal(new URL(url).protocol, 'https:');
  assert.equal(diagnostic.resourceType, resourceType);
  assert.equal(diagnostic.stage, 'Request');
  assert.equal(diagnostic.continuationAttempted, false);
  assert.equal(diagnostic.failRequestAcknowledged, true);
  assert.ok(diagnostic.httpsDenied > 0);
  if (diagnostic.method === 'OPTIONS') {
    assert.equal(allowPreflight, true, 'Only the expected fetch may have a preflight');
    assert.equal(resourceType, 'XHR');
    assert.equal(error, 'method', 'Policy rejects non-GET/HEAD before URL classification');
    return 'observed-options-blocked-before-https';
  }
  assert.equal(diagnostic.method, 'GET', 'Must observe the intended GET, not an unrelated method');
  assert.equal(error, 'https-resource-unsupported');
  return 'observed-https-get-blocked';
}
