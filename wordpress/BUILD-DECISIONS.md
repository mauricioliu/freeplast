# Freeplast WordPress — build decisions

Owner-approved architecture (2026-09-03, RUNBOOK.md) applies: WordPress +
standalone block theme (`freeplast`) + one private plugin
(`freeplast-catalog-quotes`), no WooCommerce, request-only v1.

This file records the decisions taken per slice. Newest first.

## 2026-09-03 — Issue #7: complete Quote Basket editing and options

The basket becomes fully editable and option-aware: Color Caja Universal
lines require one supported color, quantities update, lines remove, and
sessions expire — with or without JavaScript. Decisions:

1. **Options are a required chooser, not free text.** A Product whose
   reviewed source declares options (the two Caja Universal Color
   configurations) renders a required radio group (blanco, rojo, amarillo,
   azul, verde — source order) inside the shared chooser, on catalog cards
   and the product page alike. The handler enforces the same rule
   server-side: a Product with options must receive exactly one reviewed
   option; a Product without options accepts none. Line identity is
   Product+option: re-adding the same pair merges quantities while
   different options stay separate lines.

2. **Editing is the same authoritative pattern as adding.** Two new
   nonce-guarded admin-post operations (`fp_basket_update`,
   `fp_basket_remove`) share the add handler's validate-everything-first
   prelude (nonce → session → published Product → option, then quantity for
   updates), then rewrite the whole stored line list in one authoritative
   write — header count, mini basket and full view always agree because
   they all render from the same rows. Each line of `/cotizacion/` carries
   its own update (Cantidad + Actualizar) and remove (Quitar) forms, so
   editing works with JavaScript disabled; the enhancement POSTs the same
   forms with `fp_enhanced=1` and mirrors the returned JSON (count, mini
   basket, message and the re-rendered view with fresh nonces) in place.

3. **Failures stay recoverable and mutation-free.** Malformed submissions
   (bad nonce, dead session, unknown or archived Product, unsupported or
   missing option, non-positive/fractional quantity, absent line) are
   rejected with a message (`fpcq_notice` codes incl. new updated/removed/
   line/expired) before anything is written. Confirmed minimum/step rules
   are enforced whenever the source carries them (proven by a fixture sync
   with minimum_quantity 10 / quantity_step 5); while they stay absent any
   positive whole unit is accepted and no minimum is claimed.

4. **Sessions expire 30 days after last activity, visibly.** Expired
   sessions already resolve as absent; now a front-end request presenting a
   dead cookie is cleared once and bounced to the same URL with
   `fpcq_notice=expired`, so the guest lands on the empty state (which
   routes back to Tienda) instead of a silently invisible basket. A daily
   `fpcq_basket_gc` sweep deletes the expired rows so anonymous sessions
   never accumulate forever (Quote Requests, arriving with issue #8, are
   business records and never expire this way).

5. **Staff browsers stay anonymous.** A logged-in WordPress browser keeps
   using the cookie basket: choosers render user-scoped nonces and the add
   still targets the same anonymous session row — verified end to end with
   a real auth cookie pair. No usermeta basket linking exists.

## 2026-09-03 — Issue #6: add Products to a persistent Quote Basket

The first basket slice: a guest selects a quantity and adds a synchronized
Product (Caja Cosechera 3/4 proven) to a secure server-side basket, then
inspects it through the header count and mini basket across refreshes.
Decisions:

1. **The basket is plugin-owned and anonymous.** A new `Freeplast_CQ_Basket`
   class (fpcq- v1) owns the session, the add operation and the two new
   server-rendered blocks: `freeplast/basket-button` (the header widget:
   `Cotización (n)` + mini basket, placed in the theme header part) and
   `freeplast/basket` (the full `/cotizacion/` view). No user linking, no
   guest-to-login merge — even for a logged-in staff browser the basket is
   the anonymous cookie session.

2. **Sessions are opaque cookies over a versioned table.** The browser
   receives only a random 256-bit hex token (`fpcq_basket`, Secure,
   HttpOnly, SameSite=Lax, 30 days); only its sha256 hash is persisted in
   migration 4's `basket_sessions` table (columns: session_hash,
   basket_lines JSON, created_at, last_activity; expiry = 30 days after
   last activity). The cookie never carries product, option or customer
   data. NB: the lines column is named `basket_lines` because `lines` is a
   reserved MySQL keyword that the SQLite drop-in fails to parse.

3. **Adding is authoritative and fully validated before any mutation.**
   `Agregar a cotización` is a plain POST form to admin-post.php
   (`action=fp_basket_add`) guarded by a nonce. The handler validates nonce →
   session (a presented-but-dead cookie is rejected AND cleared so a retry
   starts fresh) → Product (published only; archived/unknown rejected) →
   option (must be one of the reviewed options) → positive whole-unit
   quantity (confirmed minimum/step enforced when present; unconfirmed
   minimums accept any positive integer, per PRD #1). Only then is a session
   created and the line merged. Re-adding the same product/option merges
   quantities; the header counts distinct lines, never units.

4. **The chooser is server-rendered, never an unseen quantity.** Catalog
   cards wrap the shared chooser in a native `<details>` disclosure
   (clicking “Cotizar” reveals Cantidad + Agregar a cotización — no
   JavaScript needed); the product page exposes its own chooser directly
   (approved v7-A). POST-redirect-GET returns a recoverable, non-blocking
   notice (`fpcq_notice` codes, rendered in the header widget with
   `role=status`); invalid input mutates nothing.

5. **JavaScript mirrors, the server decides.** A small progressive plugin
   script (`assets/js/basket.js`) intercepts the same form, POSTs with
   `fp_enhanced=1`, and mirrors the returned JSON (count, mini-basket
   markup, message) in place; any fetch/parse failure falls back to the
   plain form POST. The server handler answers both flows and remains the
   single source of truth.

6. **`/cotizacion/` becomes the basket view.** Migration 4 replaces exactly
   the seeded empty-state placeholder (byte-compared via
   `Freeplast_CQ_Shell::legacy_cotizacion_placeholder()` — human edits are
   never clobbered) with the `freeplast/basket` block: read-only lines plus
   a route into Tienda. Line editing/removal, option choosers, expiry
   cleanup and the submission form arrive with issues #7/#8 on this same
   sole quotation surface.

7. **The disposable-server router now serves real PHP endpoints.**
   `router.php` previously forced everything through index.php, so
   `/wp-admin/admin-post.php` 404'd; existing PHP files now execute
   directly (static files still stream, pretty permalinks still route
   through WordPress).

## 2026-09-03 — Issue #5: make the Catalog discoverable

The customer-facing discovery journey across Home, Tienda, search and
related products — rendered from the synchronized Catalog, never from
duplicated theme content. Decisions:

1. **Discovery is plugin-rendered, not theme content.** Three new
   server-rendered dynamic blocks (`Freeplast_CQ_Discovery`, versioned
   `fpcq-` v1 classes) own the behavior: `freeplast/featured-products`
   (Home's approved eight in `featured_order` sequence),
   `freeplast/catalog` (the full Tienda grid) and
   `freeplast/search-results`. The markup is semantic and functional under
   a stock block theme; the freeplast theme supplies the final v6
   presentation. Catalog cards carry exactly one canonical
   `/producto/<slug>/` link plus a “Cotizar” action into the sole quotation
   surface `/cotizacion/` (the card-level quantity chooser arrives with the
   basket slice, issues #6/#7).

2. **Tienda renders the complete grid on one page.** All 17 Active Products
   (filtered views included) render without pagination — the plainly
   paginated placeholder archive is retired.

3. **The category filter state lives in the URL.** `Todos` / `Agrícola` /
   `Otros` are plain accessible links backed by the rewrite rule
   `/tienda/categoria/<categoria>/` (restricted to the reviewed vocabulary,
   so unknown categories 404 instead of rendering an empty grid) with
   `aria-current` marking the active filter. No JavaScript, shareable
   URLs, and the query var degrades to `Todos` when an invalid value is
   passed directly. Migration 3 bumps the flag-based rewrite flush that
   makes the rule resolvable (no schema change).

4. **Search is WordPress search over Products + pages.** The plugin block
   queries `fp_product` and `page` (publish-only) for `?s=` and renders
   product hits as catalog cards (quotation actions included) and page hits
   as links; the no-result state names the term and recovers into the
   catalog (Todos/Agrícola/Otros/Contacto). The core `wp:search` block on
   Tienda, search and 404 provides the accessible form; the new
   `templates/search.html` owns the route.

5. **Archival hides a Product everywhere at once.** Archived Products are
   draft records, so every discovery query is publish-only: the archived
   Product leaves Home, Tienda, categories, search and related lists, its
   URL stops resolving (404), and no quotation action survives. Restoring
   the reviewed source returns it to discovery.

6. **Related products render the reviewed order.** `render_related` now
   re-orders the matched posts by the reviewed `related_ids` sequence
   (never query/runtime order) and still drops archived or missing ids
   silently — up to three explicit reviewed links, no runtime guessing.

## 2026-09-03 — Issue #4: synchronize all 17 Products

The proven Catalog seam extended to the complete union. Decisions:

1. **The Catalog Source is the 17-product union per PRD #1.** 15 PDF
   products — the 10 shared with freeplast.cl, the Caja Universal split into
   four distinct configurations (Cerrada/Ventilada × Negra/Color) and the
   provisional Traversa para Bins Tipo Romano (with Caja Paltera, which is
   named in the current contact form but has no published ficha) — plus the
   old-site-only Bases plásticas para pediluvios and Ladrillo plástico. All
   17 sync as Active; only an explicit source lifecycle change archives one.

2. **Schema v2: honest absence instead of invented facts.**
   `units_per_pallet` becomes nullable (the packaging fact is known only for
   Caja Cosechera 3/4 = 70 and Caja Frutera = 65; unknown values are deleted,
   never stored as 0, and render as “Consultar”). Options may declare a
   `group: "color"`; color-group ids are restricted to the supported
   vocabulary (blanco, rojo, amarillo, azul, verde) so Color configurations
   cannot offer unsupported colors. A schema-version bump rejects stale
   v1 files explicitly.

3. **The raster 2026 PDF is not transcribable in this environment.** Every
   fact is therefore taken from verifiable sources only: the published
   freeplast.cl excerpts (fetched 2026-09-03, matching the captured
   prototypes) plus the PRD’s structural decisions. Products whose sheets
   could not be transcribed (the ventilada/color Universal configurations,
   Tipo Romano, Caja Paltera) carry neutral name-derived descriptions,
   “Consultar” specs and explicit review notes; nothing is invented. Old-site
   commercial minimums (Universal 100, Tomatera 128) are NOT copied: PRD #1
   keeps every minimum unconfirmed.

4. **Legacy paths are retained as synchronized data.** `_fp_legacy_paths`
   stores the old `/producto/<historical-slug>/` paths (e.g. the Caja
   Pollera page that actually lives at `base-para-pediluvio`) separately
   from the clean canonical slug, ready for the future production redirect
   slice; staging routing is untouched.

5. **Stored JSON must be unescaped.** `update_post_meta` unslashes scalar
   values, so escaped JSON (`\/`) would never compare equal to the packed
   source form and every sync would report phantom updates. `pack()` now
   encodes with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`; nullable
   integer meta (`units_per_pallet`, `featured_order`, minimum/step) is
   deleted when null and `_fp_featured` keeps the raw '1'/'0' convention
   (no boolean sanitize callback) — the stored form equals the compared
   form byte for byte, which is what makes the no-op second run honest.

6. **Media: shared, checksum-keyed, provisional.** The four Universal
   configurations reference one reviewed photograph (one attachment reused
   by checksum); Caja Paltera and Tipo Romano have no photography at all and
   use visibly neutral placeholders. All staging media is tracked as
   provisional (`Imagen provisional`) pending original unwatermarked
   photography.

7. **Provenance is tracked per product.** `_fp_source_url` keeps the published
   freeplast.cl ficha URL where one exists, the contact page for Caja
   Paltera and the site root for Tipo Romano; `_fp_source_checked_at` keeps
   the retrieval timestamp. “Traversa Tipo G2” is retained as the review
   alias inside the Romano record per PRD #1.

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
