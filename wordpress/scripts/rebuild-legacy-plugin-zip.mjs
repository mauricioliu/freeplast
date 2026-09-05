#!/usr/bin/env node
// Rebuild the retired plugin's deterministic dist artifact from the
// preserved legacy source (wordpress/legacy/plugins/freeplast-catalog-quotes),
// replicating wordpress/scripts/check.mjs's buildStoredZip/collectShippedFiles
// byte-for-byte. Used to resolve the ralph/issue-24 × ralph/issue-27 dist
// conflict: neither branch's zip contains both fixes, so the merged tree
// regenerates the artifact from the merged legacy source.
import { readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { createHash } from 'node:crypto';

const ROOT = new URL('..', import.meta.url).pathname; // wordpress/
const SRC = join(ROOT, 'legacy', 'plugins', 'freeplast-catalog-quotes');
const DIST = join(ROOT, 'dist');

const byName = (a, b) => (a.name < b.name ? -1 : a.name > b.name ? 1 : 0);
function collectShippedFiles(dir, root) {
  const out = [];
  const entries = readdirSync(dir, { withFileTypes: true }).sort(byName);
  for (const entry of entries) {
    const name = root ? `${root}/${entry.name}` : entry.name;
    if (entry.isDirectory()) out.push(...collectShippedFiles(join(dir, entry.name), name));
    else out.push({ name, data: readFileSync(join(dir, entry.name)) });
  }
  return out;
}

const CRC32_TABLE = (() => {
  const table = new Int32Array(256);
  for (let n = 0; n < 256; n++) {
    let c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    table[n] = c;
  }
  return table;
})();

function crc32(buffer) {
  let crc = -1;
  for (const byte of buffer) crc = (crc >>> 8) ^ CRC32_TABLE[(crc ^ byte) & 0xff];
  return (crc ^ -1) >>> 0;
}

const ZIP_DOS_TIME = 0;
const ZIP_DOS_DATE = ((2026 - 1980) << 9) | (1 << 5) | 1;

function buildStoredZip(files) {
  const local = [];
  const central = [];
  let offset = 0;
  for (const file of files) {
    const name = Buffer.from(file.name, 'utf8');
    const crc = crc32(file.data);
    const header = Buffer.alloc(30);
    header.writeUInt32LE(0x04034b50, 0);
    header.writeUInt16LE(20, 4);
    header.writeUInt16LE(0, 6);
    header.writeUInt16LE(0, 8);
    header.writeUInt16LE(ZIP_DOS_TIME, 10);
    header.writeUInt16LE(ZIP_DOS_DATE, 12);
    header.writeUInt32LE(crc, 14);
    header.writeUInt32LE(file.data.length, 18);
    header.writeUInt32LE(file.data.length, 22);
    header.writeUInt16LE(name.length, 26);
    header.writeUInt16LE(0, 28);
    local.push(header, name, file.data);

    const entry = Buffer.alloc(46);
    entry.writeUInt32LE(0x02014b50, 0);
    entry.writeUInt16LE(20, 4);
    entry.writeUInt16LE(20, 6);
    entry.writeUInt16LE(0, 8);
    entry.writeUInt16LE(0, 10);
    entry.writeUInt16LE(ZIP_DOS_TIME, 12);
    entry.writeUInt16LE(ZIP_DOS_DATE, 14);
    entry.writeUInt32LE(crc, 16);
    entry.writeUInt32LE(file.data.length, 20);
    entry.writeUInt32LE(file.data.length, 24);
    entry.writeUInt16LE(name.length, 28);
    entry.writeUInt16LE(0, 30);
    entry.writeUInt16LE(0, 32);
    entry.writeUInt16LE(0, 34);
    entry.writeUInt16LE(0, 36);
    entry.writeUInt32LE((0o100644 << 16) >>> 0, 38);
    entry.writeUInt32LE(offset, 42);
    central.push(Buffer.concat([entry, name]));
    offset += header.length + name.length + file.data.length;
  }
  const centralBuf = Buffer.concat(central);
  const eocd = Buffer.alloc(22);
  eocd.writeUInt32LE(0x06054b50, 0);
  eocd.writeUInt16LE(files.length, 8);
  eocd.writeUInt16LE(files.length, 10);
  eocd.writeUInt32LE(centralBuf.length, 12);
  eocd.writeUInt32LE(offset, 16);
  return Buffer.concat([...local, centralBuf, eocd]);
}

const pluginVersion = readFileSync(join(SRC, 'freeplast-catalog-quotes.php'), 'utf8').match(/FREEPLAST_CQ_VERSION',\s*'([^']+)'/)?.[1];
if (!pluginVersion) throw new Error('plugin version not found');
const files = collectShippedFiles(SRC, 'freeplast-catalog-quotes');
const zipName = `freeplast-catalog-quotes-plugin-${pluginVersion}.zip`;
const bytes = buildStoredZip(files);
const sha256 = (data) => createHash('sha256').update(data).digest('hex');
writeFileSync(join(DIST, zipName), bytes);
console.log(`rebuilt dist/${zipName}: ${files.length} files, sha256:${sha256(bytes)}`);
