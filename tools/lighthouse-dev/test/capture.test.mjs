import test from 'node:test';
import assert from 'node:assert/strict';
import { pngSize, captureConsistency, captureRequest, observeExpression } from '../src/capture.mjs';

import { pngWith } from './png-fixture.mjs';
const snap = (width, height, dpr) => ({ innerWidth: width, innerHeight: height, deviceScaleFactor: dpr, visualWidth: width, visualHeight: height, visualScale: 1 });

test('captureRequest builds exact same-session CDP params for each profile', () => {
  assert.deepEqual(captureRequest({ width: 412, height: 823, deviceScaleFactor: 1.75 }),
    { format: 'png', optimizeForSpeed: true, captureBeyondViewport: true, clip: { x: 0, y: 0, width: 412, height: 823, scale: 1 } });
  assert.deepEqual(captureRequest({ width: 1440, height: 900, deviceScaleFactor: 1 }),
    { format: 'png', optimizeForSpeed: true, captureBeyondViewport: true, clip: { x: 0, y: 0, width: 1440, height: 900, scale: 1 } });
  for (const bad of [{}, { width: 412, height: 823 }, { width: 412, height: 823, deviceScaleFactor: 0 }]) assert.throws(() => captureRequest(bad), /capture-integrity/);
  assert.equal(observeExpression.includes('visualViewport') && observeExpression.includes('devicePixelRatio'), true);
});

test('PNG validates CRC and full data; real Uint8Array views work; truncated IHDR impostors fail', () => {
  const png = pngWith(721, 1440), view = new Uint8Array(Buffer.concat([Buffer.alloc(7), png, Buffer.alloc(3)]));
  assert.deepEqual(pngSize(view.subarray(7, view.length - 3)), { width: 721, height: 1440 });
  for (const bad of [png.subarray(0, 24), png.subarray(0, -1), Buffer.concat([png, Buffer.from('extra')])]) assert.throws(() => pngSize(bad), /capture-integrity/);
  const corrupt = Buffer.from(png); corrupt[50] ^= 1; assert.throws(() => pngSize(corrupt), /capture-integrity/);
  assert.deepEqual(pngSize(pngWith(721, 1440)), { width: 721, height: 1440 });
  const huge = pngWith(10, 10); huge.writeUInt32BE(4294967295, 20);
  for (const bad of [Buffer.alloc(0), Buffer.from('notpng'), pngWith(0, 10), huge]) assert.throws(() => pngSize(bad), /capture-integrity/);
});

test('stabilized mobile capture: pixels equal configured 412x823 at DPR 1.75 (721x1440)', () => {
  const geometry = { width: 412, height: 823, deviceScaleFactor: 1.75 };
  assert.equal(captureConsistency({ pixels: pngSize(pngWith(721, 1440)), observed: [snap(412, 823, 1.75), snap(412, 823, 1.75)], geometry }), true);
  assert.throws(() => captureConsistency({ pixels: pngSize(pngWith(721, 1440)), observed: [snap(412, 823, 1.75)], geometry }), /capture-integrity/);
});

test('stabilized desktop capture: 1440x900 at DPR 1', () => {
  const geometry = { width: 1440, height: 900, deviceScaleFactor: 1 };
  assert.equal(captureConsistency({ pixels: pngSize(pngWith(1440, 900)), observed: [snap(1440, 900, 1), snap(1440, 900, 1)], geometry }), true);
});

test('native teardown state (800x600 surface, DPR 1, stale 412 metrics) never passes', () => {
  const geometry = { width: 412, height: 823, deviceScaleFactor: 1.75 };
  const torn = { innerWidth: 412, innerHeight: 823, deviceScaleFactor: 1, visualWidth: 412, visualHeight: 823, visualScale: 1 };
  const cleared = snap(800, 600, 1);
  assert.throws(() => captureConsistency({ pixels: pngSize(pngWith(800, 600)), observed: [torn, cleared], geometry }), /capture-integrity/, 'probe-1 native numbers must stay invalid');
  assert.throws(() => captureConsistency({ pixels: pngSize(pngWith(721, 1440)), observed: [torn], geometry }), /capture-integrity/, 'pixels without a configured observed state must fail');
  assert.throws(() => captureConsistency({ pixels: pngSize(pngWith(721, 1440)), observed: [torn, snap(412, 823, 1.75)], geometry }), /capture-integrity/, 'both before/after observations must agree');
});

test('wrong pixels for the configured profile are rejected; malformed input fails closed', () => {
  const geometry = { width: 412, height: 823, deviceScaleFactor: 1.75 };
  const zoomed = { ...snap(412, 823, 1.75), visualScale: 0.5 };
  assert.throws(() => captureConsistency({ pixels: { width: 721, height: 1440 }, observed: [zoomed, zoomed], geometry }), /capture-integrity/);
  for (const pixels of [pngWith(715, 1430), pngWith(800, 600), pngWith(412, 823)]) assert.throws(() => captureConsistency({ pixels, observed: [snap(412, 823, 1.75)], geometry }), /capture-integrity/);
  for (const bad of [null, undefined, {}, { pixels: pngSize(pngWith(721, 1440)) }, { pixels: pngSize(pngWith(721, 1440)), observed: [{}] }, { pixels: pngSize(pngWith(721, 1440)), observed: [] }, { pixels: pngSize(pngWith(721, 1440)), observed: [snap(412, 823, 1.75)] }]) assert.throws(() => captureConsistency(bad), /capture-integrity/);
  assert.throws(() => captureConsistency({ pixels: pngSize(pngWith(721, 1440)), observed: [snap(412, 823, 1.75), snap(412, 823, 1.75), snap(412, 823, 1.75)], geometry }), /capture-integrity/);
});
