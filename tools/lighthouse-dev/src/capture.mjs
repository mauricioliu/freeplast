import { inflateSync, crc32 } from 'node:zlib';
import { SafetyError } from './policy.mjs';

// PNG IHDR pixel dimensions (bytes 16..24 after the 8-byte signature + 4+4 length/type).
export function pngSize(buffer) {
  if (!(buffer instanceof Uint8Array)) throw new SafetyError('capture-integrity');
  buffer = Buffer.from(buffer.buffer, buffer.byteOffset, buffer.byteLength);
  if (buffer.length < 24
    || buffer.subarray(0, 8).toString('hex') !== '89504e470d0a1a0a'
    || buffer.subarray(12, 16).toString('ascii') !== 'IHDR') throw new SafetyError('capture-integrity');
  const width = buffer.readUInt32BE(16), height = buffer.readUInt32BE(20);
  if (!Number.isSafeInteger(width) || !Number.isSafeInteger(height) || width < 1 || height < 1 || width > 16384 || height > 16384) throw new SafetyError('capture-integrity');
  // Decode the complete bounded PNG stream, not merely a forgeable IHDR prefix.
  const chunks = []; let offset = 8, ended = false;
  while (offset < buffer.length) {
    if (offset + 12 > buffer.length) throw new SafetyError('capture-integrity');
    const length = buffer.readUInt32BE(offset), end = offset + 12 + length;
    if (end > buffer.length) throw new SafetyError('capture-integrity');
    const type = buffer.toString('ascii', offset + 4, offset + 8);
    if (crc32(buffer.subarray(offset + 4, end - 4)) !== buffer.readUInt32BE(end - 4)) throw new SafetyError('capture-integrity');
    if (offset === 8 && (type !== 'IHDR' || length !== 13 || buffer[offset + 16] !== 8 || ![2, 6].includes(buffer[offset + 17]) || buffer[offset + 18] !== 0 || buffer[offset + 19] !== 0 || buffer[offset + 20] !== 0)) throw new SafetyError('capture-integrity');
    if (type === 'IDAT') chunks.push(buffer.subarray(offset + 8, end - 4));
    if (type === 'IEND') { if (length !== 0 || end !== buffer.length) throw new SafetyError('capture-integrity'); ended = true; }
    offset = end;
  }
  const channels = buffer[25] === 6 ? 4 : 3, stride = width * channels, size = (stride + 1) * height;
  if (!ended || !chunks.length || size > 64 * 1024 ** 2) throw new SafetyError('capture-integrity');
  let raw;
  try { raw = inflateSync(Buffer.concat(chunks), { maxOutputLength: size }); } catch { throw new SafetyError('capture-integrity'); }
  if (raw.length !== size) throw new SafetyError('capture-integrity');
  for (let y = 0; y < height; y++) if (raw[y * (stride + 1)] > 4) throw new SafetyError('capture-integrity');
  return { width, height };
}
const near = (a, b) => Math.abs(a - b) <= 2;
// The capture context is re-asserted to the configured profile before the shot, so
// Pixels and both before/after observations must agree with the configured state.
// Native historical default-surface mismatches remain invalid, regardless of cause.
export function captureConsistency(value) {
  const { pixels, observed, geometry } = value ?? {};
  if (!pixels || !geometry || ![geometry.width, geometry.height, pixels.width, pixels.height].every(n => Number.isInteger(n) && n > 0 && n <= 16384) || !Number.isFinite(geometry.deviceScaleFactor) || !(geometry.deviceScaleFactor > 0 && geometry.deviceScaleFactor <= 8)
    || !Array.isArray(observed) || observed.length !== 2) throw new SafetyError('capture-integrity');
  if (!near(pixels.width, geometry.width * geometry.deviceScaleFactor) || !near(pixels.height, geometry.height * geometry.deviceScaleFactor)) throw new SafetyError('capture-integrity');
  if (!observed.every(snap => snap && near(snap.innerWidth, geometry.width) && near(snap.innerHeight, geometry.height)
    && near(snap.visualWidth, geometry.width) && near(snap.visualHeight, geometry.height)
    && snap.deviceScaleFactor === geometry.deviceScaleFactor && Math.abs(snap.visualScale - 1) <= 0.001)) throw new SafetyError('capture-integrity');
  return true;
}
// Page.captureScreenshot params for the stabilized capture, sent on the SAME CDP
// session that owns the Emulation override (puppeteer's primary session captures
// the default-window surface instead — native probe 2). Page-origin clip at
// scale=1: native DPR already scales pixels; multiplying again double-scales.
export function captureRequest({ width, height, deviceScaleFactor }) {
  if (![width, height].every(n => Number.isInteger(n) && n > 0 && n <= 16384) || !Number.isFinite(deviceScaleFactor) || !(deviceScaleFactor > 0 && deviceScaleFactor <= 8)) throw new SafetyError('capture-integrity');
  return { format: 'png', optimizeForSpeed: true, captureBeyondViewport: true, clip: { x: 0, y: 0, width, height, scale: 1 } };
}
// Viewport observation expression, evaluated on that same owning session.
export const observeExpression = '(() => { const v = visualViewport; return { innerWidth, innerHeight, deviceScaleFactor: devicePixelRatio, visualWidth: v.width, visualHeight: v.height, visualScale: v.scale, userAgent: navigator.userAgent }; })()';
