import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { checkCatalogFixture } from './woo-stack-harness.mjs';

// Offline regression (no server, no bootstrap, fixture only): the stack harness
// must REFUSE to run when a crashed previous run left the disposable
// fpw-stack-guard-off.php mu-plugin installed (ventas guards REMOVED), before
// any port contact, bootstrap or spawn — and must never remove it silently.
// Source-extracted like bootstrap-onboarding-test.mjs, so the shipped harness
// code itself is exercised.
export function runStackPreflightTests() {
  const source = readFileSync(new URL('./woo-stack-harness.mjs', import.meta.url), 'utf8');
  const block = source.match(/const staleGuardOff[\s\S]*?\n  \}/)?.[0];
  assert.ok(block, 'stale guard-off preflight block present in the harness source');
  const run = (exists) => new Function('existsSync', 'join', 'WP_DIR', block)
    (() => exists, (...parts) => parts.join('/'), '/disposable/wp');
  assert.throws(
    () => run(true),
    (e) => e instanceof Error && e.message.includes('fpw-stack-guard-off.php') && e.message.includes('Refusing'),
    'a stale guard-off remnant must abort the harness with an explicit refusal');
  assert.doesNotThrow(() => run(false), 'a clean stack must pass the preflight untouched');
  const preflight = source.indexOf('/* -1.');
  const portCheck = source.indexOf('/* 0. The port');
  const bootstrap = source.indexOf("sh(process.execPath, [join(HERE, 'bootstrap.mjs')]");
  assert.ok(
    preflight !== -1 && portCheck !== -1 && bootstrap !== -1 && preflight < portCheck && portCheck < bootstrap,
    'the refusal runs before the port probe and before any bootstrap');
  const reference = JSON.parse(readFileSync(new URL('../data/products.json', import.meta.url), 'utf8')).products;
  const baseline = { variations: 5, selection_applied: false, products: reference.map((entry, index) => ({ id: index + 1, slug: entry.slug, source_id: entry.source_id, status: 'publish', visibility: 'visible', public: true })) };
  const ids = baseline.products.map((product) => product.id);
  const verify = (fixture, publicIds = fixture.products.filter((product) => product.public).map((product) => product.id)) => checkCatalogFixture(fixture, reference, publicIds, assert.ok);
  assert.doesNotThrow(() => verify(baseline), 'all 17 seed-only products remain valid before migration');
  const migrated = structuredClone(baseline);
  migrated.selection_applied = true;
  const archived = migrated.products.find((product) => product.slug === 'bases-plasticas-para-pediluvios');
  Object.assign(archived, { status: 'draft', visibility: 'hidden', public: false });
  assert.doesNotThrow(() => verify(migrated), 'archived identity remains in Woo while absent from public cards');
  assert.throws(() => verify(migrated, ids), /public catalog/, 'archived product cannot leak publicly');
  assert.throws(() => verify(migrated, ids.filter((id) => id !== archived.id && id !== 1)), /public catalog/, 'missing active products are not excused by archival');
  const deleted = structuredClone(migrated);
  deleted.products = deleted.products.filter((product) => product.id !== archived.id);
  assert.throws(() => verify(deleted), /historical Woo identity/, 'archive must not delete the historical identity');
  const wrongIdentity = structuredClone(migrated);
  wrongIdentity.products[0].source_id = 'wrong';
  assert.throws(() => verify(wrongIdentity), /historical Woo identity/, 'slug alone cannot substitute for the fixture source identity');
  const invalidArchive = structuredClone(migrated);
  invalidArchive.products.find((product) => product.id === archived.id).visibility = 'visible';
  assert.throws(() => verify(invalidArchive), /draft\/hidden/, 'migrated archive must be both draft and hidden');
  const visibleAfterMigration = structuredClone(baseline);
  visibleAfterMigration.selection_applied = true;
  assert.throws(() => verify(visibleAfterMigration), /draft\/hidden/, 'completed migration cannot silently restore pediluvios');
  console.log('stack preflight: 12 offline fixture checks passed (stale guard refusal; seed-only/migrated catalog identity and visibility)');
  return 12;
}
