# Freeplast WordPress — build decisions

Owner-approved architecture (2026-09-03, RUNBOOK.md) applies: WordPress +
standalone block theme (`freeplast`) + one private plugin
(`freeplast-catalog-quotes`), no WooCommerce, request-only v1.

This file records the decisions taken per slice. Newest first.

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
