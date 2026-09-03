# Freeplast WordPress — build decisions

Owner-approved architecture (2026-09-03, RUNBOOK.md) applies: WordPress +
standalone block theme (`freeplast`) + one private plugin
(`freeplast-catalog-quotes`), no WooCommerce, request-only v1.

This file records the decisions taken per slice. Newest first.

## 2026-09-03 — Issue #3: synchronize and render the first Product

First complete Catalog seam, proven with Caja Cosechera 3/4: reviewed source
→ validated → synchronized → rendered at the clean canonical URL.

Decisions:

1. **Catalog Source schema v1 lives in the plugin, enforced completely
   before mutation.** `Freeplast_CQ_Catalog_Source` owns the versioned
   schema (`version: 1`) and validates the ENTIRE file first: unknown keys at
   every object level, duplicate source IDs/slugs, clean canonical slugs,
   absolute http(s) source URLs, clean absolute legacy paths, positive
   integer quantities with minimum aligned to step, options, related IDs
   (≤ 3, no self, same file), featured order, review flags, and local media
   existence + sha256 checksum. Any failure rejects the whole file (non-zero
   exit) — one valid product next to one invalid product still creates
   nothing.

2. **Identity is the immutable `source_id`, never the slug.** Products are
   matched by `_fp_source_id` meta; titles and slugs can change freely.
   `fp-caja-cosechera-3-4` is the first identity; the canonical slug stays
   `caja-cosechera-3-4` with `/producto/caja-cosechera-3-4/` as the URL and
   the old-site path kept as `legacy_paths` data for the future cutover.

3. **Pallet facts are packaging facts, never minimums.** The published
   description's “Cantidad mínima de compra 1 pallet de 70 cajas” sentence is
   NOT ported to the public page: the source stores `units_per_pallet: 70`
   and `minimum_quantity: null` (client confirmation pending per PRD #1), so
   the page shows “Unidades por pallet: 70” and “Cantidad mínima: Consultar”
   with an explicit packaging-fact note. Nothing is invented.

4. **`fp_product` has no editor UI anywhere** (`show_ui`/`show_in_menu`
   false) with public routing (`/producto/<slug>/` single, `tienda`
   archive, REST exposed, search included). Catalog mutations exist only
   through `wp freeplast catalog sync`.

5. **Migration 2 retires the `/tienda/` placeholder page** (trashed, never
   deleted, only the exact page recorded in `fp_shell_pages`) and the fp_product
   archive takes over the route. Rewrite flushes are flag-based on `init`
   after post types are registered — flushing inside a late
   `wp plugin activate` would write rules without the archive and 404
   `/tienda/`.

6. **Media is imported, checksum-keyed.** The reviewed webp lives in
   `data/media/`, is verified by sha256 at validation, imported into the
   local media library on change, and reused (never re-imported) when the
   checksum already exists. Provisional media is visibly tracked on the
   product page (“Imagen provisional”) and in attachment meta. The frontend
   never hotlinks source media.

7. **Deterministic reports, no-op second run.** Sync prints one line per
   product (`would create/update` + changed fields, or `unchanged`) plus
   `Summary: created=N updated=N unchanged=N warnings=N errors=N`. A second
   run against unchanged input reports zero changes without touching
   `post_modified`. Products missing from the source are warnings only;
   archiving requires an explicit source lifecycle change. Media imports run
   before any post mutation; on failure, imports made during the run are
   deleted and nothing is kept.

8. **Public rendering: plugin block `freeplast/product-detail`, theme owns
   presentation.** The plugin renders semantic, minimally-styled markup
   (versioned `fpcq-` classes) from synchronized metadata only — v7 variant A
   structure (breadcrumb, gallery + summary, description, four quick specs,
   quote action into `/cotizacion/`, specification table, related products
   when reviewed). The freeplast theme styles it with v6 tokens, mobile-first
   min-width-only. No forms, no quantity controls, no prototype variant
   switching — the basket/quantity slice owns those (issues #6/#7).

## 2026-09-03 — Issue #2: boot the WordPress shell

Disposable baseline for every later slice. No OpenClaw resources are touched
in this slice; Gate 0/1 (hostname, recipient, retention, scope, deployment
plan) remain open for the staging-deploy slice (issue #14).

Decisions:

1. **Disposable stack = pinned local toolchain, not Docker.**
   The shell must boot on any machine (including this workspace, which has
   no PHP/Docker): a static PHP 8.3.32 CLI build, wp-cli.phar, WordPress
   7.1 core and the `sqlite-database-integration` drop-in, all fetched by
   `wordpress/scripts/fetch-tools.sh` with pinned SHA-256 hashes into the
   gitignored `wordpress/.tools/`. The disposable site lives in
   `wordpress/.build/wp` with a SQLite database (`wp-content/database/`).
   Production staging remains MariaDB on OpenClaw (issue #14); nothing
   depends on SQLite beyond the disposable installation.

2. **One documented check command.** `npm test`
   (`wordpress/scripts/check.mjs`) bootstraps a *clean* disposable
   installation, activates the theme and plugin, serves the site through
   `php -S` and verifies the issue #2 acceptance criteria, writing
   `wordpress/VERIFICATION.md`. `npm run typecheck` runs php -l / node
   --check / JSON validation. `npm run bootstrap` provisions without
   checking.

3. **Locale `en_US` for the disposable installation** for deterministic,
   network-light bootstrapping. Front-end copy is Spanish regardless (it
   lives in the theme/plugin). The OpenClaw staging site will be installed
   with `es_CL` per RUNBOOK Gate 1 (issue #14).

4. **Navigation contract (v6 shell).** Island header: logo/INICIO → `/`,
   NOSOTROS → `/nosotros/`, TIENDA → `/tienda/`, Cotiza Online →
   `/cotizacion/`; Contacto reachable from the mobile sheet and footer.
   The v6 prototype anchors (`#inicio`, `#nosotros`, …) become real
   standalone routes per the PRD.

5. **Shell routes are seeded by the plugin** (`Freeplast_CQ_Shell`) on
   activation, idempotently: `cotizacion` (empty state, no form), `tienda`
   (honest placeholder), `nosotros`, `contacto`, `politica-de-privacidad`
   with approved/current copy from `propuesta-textos-originales`.
   The `/tienda/` placeholder **will be retired** by the catalog slice
   (issue #3/#5) when the `fp_product` archive takes over the slug — that
   slice's migration bumps `FREEPLAST_CQ_DB_VERSION`.

6. **No forms anywhere in the shell.** The v6 prototype quote form
   (`action="mailto:ventas@freeplast.cl"`) is intentionally *not* ported:
   `/cotizacion/` renders "Tu cotización está vacía" until issue #8 owns
   submission. No prototype behavior is treated as a real submission
   endpoint; the automated check enforces this.

7. **Migration boundary established now.** `FREEPLAST_CQ_DB_VERSION = 1`
   (baseline, no tables yet) stored in the `fp_db_version` option and
   reported by the check. Later schema work goes through
   `Freeplast_CQ_Migrations` with version bumps.

8. **Version expectations declared in theme and plugin headers**
   (`Requires at least: 7.0`, `Requires PHP: 8.1`) and reported against the
   running environment by the check.

9. **Mobile-first, min-width only.** The theme stylesheet adapts only via
   `min-width` media queries (768/1024 px), mirroring the approved v6
   implementation; the automated check enforces the absence of `max-width`
   gates and that mobile and desktop user agents receive the identical
   document. Pixel-level rendering remains human Gate 3.

## Open owner inputs (Gate 0 — unchanged)

Hostname (proposed `freeplast.mliu.site`), quote-recipient email, retention
period, request-only vs formal-quote scope. These gate the staging
deployment (issue #14), not the shell baseline.
