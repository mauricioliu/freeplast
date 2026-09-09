import assert from 'node:assert/strict';
import { readFileSync, statSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { join } from 'node:path';
const root = fileURLToPath(new URL('../..', import.meta.url));
const data = join(root, 'wordpress/data');
const source = JSON.parse(readFileSync(join(data, 'products.json'))).products;
const manifest = JSON.parse(readFileSync(join(data, 'catalog-photos/manifest.json')));
const hash = file => createHash('sha256').update(readFileSync(file)).digest('hex');
let checks = 0;
const check = (value, message) => { assert.ok(value, message); checks++; };
check(manifest.version === 1 && manifest.products.length === 17, 'reviewed manifest schema and size');
check(new Set(manifest.products.map(p => p.source_id)).size === 17, 'one entry per native product identity');
for (const entry of manifest.products) {
  const original = source.find(p => p.source_id === entry.source_id);
  check(original && original.slug === entry.slug, 'source identity matches ' + entry.slug);
  check(entry.previous_sha256 === original.image.checksum.replace('sha256:', ''), 'only the historical source photo can be replaced');
  check(/^[a-z0-9-]+\.webp$/.test(entry.file), 'no media path traversal');
  const file = join(data, 'catalog-photos', entry.file);
  check(hash(file) === entry.sha256, 'reviewed output checksum ' + entry.slug);
  check(entry.width === entry.height && entry.width <= 960 && entry.width >= 300, 'square bounded canvas');
  check(statSync(file).size < 150000, 'bounded media payload');
  check(entry.provisional && !entry.alt.includes('pendiente para'), 'real but still referential photo');
  check(hash(join(root, entry.source.file)) === entry.source.sha256, 'traceable local source');
  if (entry.slug.endsWith('-color')) {
    check(entry.alt.includes('rojo') && entry.caption.includes('Los demás colores no se muestran'), 'no false per-color photograph');
  }
}
const universal = manifest.products.filter(p => p.slug.startsWith('caja-universal-'));
check(universal.length === 4 && new Set(universal.map(p => p.sha256)).size === 4, 'four configurations have distinct images');
check(manifest.products.filter(p => p.source.page).length === 15, '15 photographs recovered from PDF, two from older assets');
for (const slug of ['caja-paltera', 'traversa-para-bins-tipo-romano']) {
  check(manifest.products.find(p => p.slug === slug).source.page > 0, 'previously missing photo recovered from a named PDF page');
}
console.log(`catalog photos: ${checks} source, manifest and asset checks passed`);
export { checks as catalogPhotoChecks };
