# Freeplast WordPress

WordPress implementation of the approved Freeplast v6 storefront and the
catalog/quote-request plugin. Architecture: WordPress + standalone block
theme `freeplast` + one private plugin `freeplast-catalog-quotes`
(see `docs/agents/freeplast-wordpress/RUNBOOK.md` and `TARGET.md`).
WooCommerce is not installed.

## Layout

```
wordpress/
  BUILD-DECISIONS.md          slice-by-slice decisions (read this first)
  VERIFICATION.md             generated mechanical proof (npm test)
  data/products.json          versioned catalog source (schema v2, 17 products)
  data/media/                 reviewed local media referenced by the source
  scripts/                    toolchain fetch, bootstrap, checks
  wp-content/
    themes/freeplast/         standalone block theme — v6 shell + v7-A product
    plugins/freeplast-catalog-quotes/
                              private plugin — shell routes, migrations,
                              fp_product records, catalog synchronization
  .tools/                     pinned downloadable toolchain (gitignored)
  .build/                     disposable WordPress site (gitignored)
```

## Commands

First run needs `curl`, `tar`, `unzip` and `sha256sum` (plus Node ≥ 20) and
network access to download the pinned toolchain; later runs are offline.

```bash
npm test              # THE check command: bootstrap a clean disposable
                      # WordPress + SQLite, activate theme and plugin, and
                      # verify the issue-#2 through issue-#5 acceptance criteria.
npm run typecheck     # php -l, node --check, theme.json/products.json validation
npm run bootstrap     # provision/refresh the disposable site without checks
```

The disposable site boots at `http://127.0.0.1:8091` (override with
`FREEPLAST_TEST_URL`). Admin credentials for the disposable install are in
`wordpress/.build/.provisioned.json`. Wipe and rebuild with
`FREEPLAST_KEEP_BUILD=0 npm run bootstrap -- --fresh` — `npm test` always
rebuilds from a clean database by default; set `FREEPLAST_KEEP_BUILD=1` (or
`--keep`) to reuse the existing build during iteration.

What `npm test` proves (see `VERIFICATION.md` after a run):

- a clean disposable WordPress database boots with no manual editor changes;
- `freeplast` and `freeplast-catalog-quotes` activate without warnings or
  fatal errors (WP_DEBUG + debug.log are monitored);
- Home `/` returns HTTP 200 and renders the approved v6 shell — brand,
  INICIO/NOSOTROS/TIENDA navigation, Cotiza Online CTA, hero copy — for
  mobile and desktop user agents, with mobile-first (min-width-only) CSS;
- `/cotizacion/` renders a non-functional-safe empty state and no form
  exists anywhere in the shell (submission is issue #8);
- WooCommerce is absent;
- WordPress/PHP/SQLite versions and the plugin migration version
  (`fp_db_version`) are reported against the declared expectations;
- the versioned Catalog Source (`data/products.json`, schema v2, the complete
  17-product union) validates before mutation, and `wp freeplast catalog sync
  --dry-run` reports the deterministic difference without touching
  WordPress;
- real synchronization creates all 17 Active Products — the 15 PDF products
  (including the four Caja Universal configurations and the provisional
  Traversa para Bins Tipo Romano) plus the old-site-only Pediluvio and Ladrillo
  plástico — with source identity, Product Category, canonical slugs, retained
  legacy paths, reviewed options and local media-library copies of the
  reviewed images; a second run against unchanged source reports zero changes;
- every product lives at a clean canonical URL `/producto/<slug>/` with
  `rel=canonical`, is absent from WordPress editor menus, and its page follows
  approved v7 variant A: source-supported description and specs, pallet facts
  as packaging facts (“Cantidad mínima: Consultar”), honest “Consultar” for
  every unconfirmed fact, no forms, no prototype controls;
- the discovery journey renders from the synchronized catalog only: Home
  shows the approved eight Featured Products in source-controlled order,
  `/tienda/` lists all 17 Active Products on one page (canonical links plus a
  “Cotizar” action per card), the Todos/Agrícola/Otros filters are accessible
  links with meaningful `/tienda/categoria/<categoria>/` URLs (unknown
  categories 404), search finds Products and standard pages with a clear
  no-result state, related products render the reviewed ids in reviewed
  order, and an explicitly archived Product disappears from every discovery
  surface (its URL stops resolving);
- unknown keys, duplicate identity, invalid slugs, unsupported color options
  and failed media imports exit non-zero with no partial catalog mutation;
  products missing from the source are warnings only, only explicit lifecycle
  changes archive/reactivate records, and changed media imports exactly once.

Synchronizing the catalog by hand against the disposable site:

```bash
node wordpress/scripts/bootstrap.mjs   # ensure the disposable site is up
cd wordpress/.build/wp
../../.tools/php/php ../../.tools/cache/wp-cli.phar \
  freeplast catalog sync --file=../../data/products.json --dry-run
../../.tools/php/php ../../.tools/cache/wp-cli.phar \
  freeplast catalog sync --file=../../data/products.json
```

Pixel-level visual fidelity at 412 px and desktop widths is human Gate 3
(RUNBOOK.md) — the automated check verifies the served document and
responsive stylesheet, not rendered pixels.

## Catalog source (schema v2)

`data/products.json` is the reviewed, version-controlled authority for
catalog content: the 17-product union of the 2026 PDF catalog and the current
freeplast.cl offering — 15 PDF products (including the four Caja Universal
configurations and the provisional Traversa para Bins Tipo Romano) plus the
old-site-only Bases plásticas para pediluvios and Ladrillo plástico, all
Active until the client explicitly archives one. It is validated completely
before any mutation: unknown keys, duplicate source IDs/slugs, invalid
URLs/paths, misaligned minimums/steps, unknown related-product IDs,
unsupported color options and missing or checksum-mismatched media are all
rejected (non-zero exit) before WordPress is touched. Product identity is the
immutable `source_id` (never the slug), lifecycle is explicit
(`active`/`archived`), canonical slugs are clean while old `legacy_paths` are
retained as data for the future production cutover, units-per-pallet are
packaging facts (null when unconfirmed), and unconfirmed commercial minimums
are `null` (rendered as “Consultar”). Color options are restricted to the
supported vocabulary (blanco, rojo, amarillo, azul, verde). Media lives in
`data/media/`, is imported into the local media library by the synchronizer
and tracked as provisional while original photography is pending. The 2026
PDF is raster-only: facts that could not be transcribed in this environment
(notably the Universal ventilada/color configurations, Tipo Romano and Caja
Paltera sheets) render as “Consultar” pending client review. The
customer-facing discovery journey (issue #5) consumes this file — content is
never duplicated into the theme.
