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
  data/products.json          catalog source (stub — catalog slices fill it)
  scripts/                    toolchain fetch, bootstrap, checks
  wp-content/
    themes/freeplast/         standalone block theme — v6 shell
    plugins/freeplast-catalog-quotes/
                              private plugin — shell routes, migrations
  .tools/                     pinned downloadable toolchain (gitignored)
  .build/                     disposable WordPress site (gitignored)
```

## Commands

First run needs `curl`, `tar`, `unzip` and `sha256sum` (plus Node ≥ 20) and
network access to download the pinned toolchain; later runs are offline.

```bash
npm test              # THE check command: bootstrap a clean disposable
                      # WordPress + SQLite, activate theme and plugin, and
                      # verify the issue-#2 acceptance criteria.
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
  (`fp_db_version`) are reported against the declared expectations.

Pixel-level visual fidelity at 412 px and desktop widths is human Gate 3
(RUNBOOK.md) — the automated check verifies the served document and
responsive stylesheet, not rendered pixels.
