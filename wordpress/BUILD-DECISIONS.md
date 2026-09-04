# Freeplast WordPress — build decisions

Owner-approved architecture (2026-09-03, RUNBOOK.md) applies: WordPress +
standalone block theme (`freeplast`) + one private plugin
(`freeplast-catalog-quotes`), no WooCommerce, request-only v1.

This file records the decisions taken per slice. Newest first.

## 2026-09-04 — Issue #9: the operational sales workflow

The minimally visible record becomes a focused sales workspace:
least-privilege access, searchable requests, current corrections, internal
context, explicit status transitions and auditable reopening. Decisions:
1. **Least privilege through a role, not broader caps.** Migration 7 (db
   version 7) creates the Ventas Freeplast role with exactly `read` +
   `manage_freeplast_quotes` (administrators keep the capability from
   migration 6). The role reaches Cotizaciones and nothing else — Users,
   Plugins, Posts and Themes stay denied. `Freeplast_CQ_Admin`
   (class-admin.php) owns the whole surface now; the minimal issue #8
   pages moved out of `Freeplast_CQ_Request`, keeping the same page
   slugs (`fp-quotes`/`fp-quote`) and the same capability constant.
2. **Menu pages register on the `admin_menu` hook.** Registering them
   during `init` makes WordPress' re-parent loop rewrite the top-level
   slug (first submenu becomes the parent) and the detail page's access
   resolution then fails with 403 — the canonical seam is `admin_menu`,
   after core builds the menus. The four state-changing operations
   (`fp_quote_update_contact`, `fp_quote_add_note`, `fp_quote_set_status`,
   `fp_quote_reopen`) attach immediately on `admin_post`/`admin_post_nopriv`
   (logged-out attempts die on the same capability guard).
3. **Current details are a separate correctable copy.** Each record now
   persists `_fpq_current` (initialized from the submitted details) plus
   the denormalized `_fpq_empresa`/`_fpq_email` columns that feed the
   list sort/search; corrections update only those while `_fpq_customer`
   (Submitted Details) stays byte-identical. Migration 7 backfills the
   copy onto records persisted before this slice. The correction form
   validates the same business rules as the submission (one shared field
   map, `Freeplast_CQ_Request::TEXT_FIELDS`), retains entered values on
   failure through a per-staff transient, and appends a history event
   naming the changed fields + time + staff identity — never the values.
4. **List operations work on the current details.** The list sorts by
   reference (title), created date, company, email and Request Status
   (named EXISTS meta clause + ID tiebreak) and searches reference/
   company/email with a status filter; a fruitless search renders an
   explicit empty state. No bulk CSV export exists.
5. **Status is a strict forward graph with one explicit exit.** new →
   contacted → quoted → won/lost, any strictly forward skip permitted,
   cancelled reachable from new/contacted/quoted. The terminal states
   have no direct targets; the separate reopen operation returns them to
   contacted. Every transition appends a `_fpq_history` event (from/to,
   time, staff) and every operation re-validates nonce + capability
   server-side — a bad nonce or a capability-less POST mutates nothing.
6. **Sales Notes and history stay internal by construction.** Notes
   (`_fpq_notes`: time, staff, text) and events render only inside the
   capability-guarded detail; the records remain non-public, so nothing
   reaches a customer-facing page (public search cannot leak them).
## 2026-09-04 — Issue #10: durable sales and customer notifications

Sales notification and customer acknowledgement stop being a delivery
problem and become durable state on the request itself. Decisions:
1. **The jobs commit with the record.** Every fp_quote insert carries
   `_fpq_notifications` (two jobs: sales + customer, state pending) in the
   same `wp_insert_post` meta payload, so a record can never exist without
   its jobs — and the filter seam `freeplast_cq_notification_jobs` aborts
   the whole submission (no record, no success, basket retained) when job
   creation fails. No jobs table: the jobs, their delivery state and the
   PII-free event log are meta on the records, like the rest of the slice
   family (migration 6).
2. **Receipt is persistence, never delivery.** After the insert, one
   scheduled event (`freeplast_cq_notify`) owns delivery; the confirmation
   and the cleared basket were already independent of it. The event and
   the jobs are processed by `Freeplast_CQ_Notifications::process()`, which
   is idempotent: only pending jobs (and failed jobs below the automatic
   attempt cap, retried with a 5-minute backoff) are attempted, so cron
   re-runs, duplicate events and staff resends can never duplicate a
   delivery — and a mail outage can never duplicate the request (the
   issue #8 idempotency token already guards the record).
3. **One message per audience, built from the immutable record.** Both
   messages carry the Request Reference and every product line with its
   option and quantity; the sales message adds the operational
   customer/dispatch details (nombre, teléfono + normalizado, email,
   empresa, RUT, giro, dirección de despacho, mensaje). Reply-To routing:
   sales → the customer email; customer → the configured sales address
   (filter `freeplast_cq_sales_recipient`, default `ventas@freeplast.cl`).
   The transport is one external adapter seam — the filter
   `freeplast_cq_send_mail` (default `wp_mail`); the automated check
   replaces exactly that boundary.
4. **Staging containment fails closed.** The mail mode is
   `FREEPLAST_CQ_MAIL_MODE` (environment) → `freeplast_cq_mail_mode`
   (option) → `suppress`. `live` delivers as addressed; `redirect` forces
   every message to `FREEPLAST_CQ_MAIL_TO`; `allowlist` delivers only to
   `FREEPLAST_CQ_MAIL_ALLOW` recipients (others are recorded suppressed,
   code `not_allowlisted`); `suppress` delivers nothing. Every restricted
   mode prefixes the subject with `[STAGING]` and preserves the Reply-To
   routing. The environment always wins over the option, so a staging
   environment that sets `suppress` cannot be weakened from WordPress.
5. **Staff visibility and safe resend.** The Cotizaciones detail gains a
   Notificaciones section (channel, recipient, state, attempts, last
   attempt, code, effective mail mode) and, for channels that still need
   delivery, a resend form (`admin-post.php` `action=fp_notify_resend`,
   nonce + `manage_freeplast_quotes`). Resend bypasses the automatic
   attempt cap but re-checks state: an already-sent channel answers `noop`
   without touching the transport.
6. **Logs stay PII-free.** `_fpq_notify_log` (bounded to 25 entries)
   records time, channel, state and code only — never an email address or
   customer field value; recipients are re-derived from the record at
   send time. Migration 7 (db 7) backfills the pending jobs and a delivery
   event onto records persisted before the slice, without ever resetting
   delivered state.
7. **The disposable host disables WP-Cron** (`DISABLE_WP_CRON` in the
   bootstrap wp-config): core's loopback `wp_cron()` on `init` would fire
   the scheduled delivery events at unpredictable moments mid-check. The
   check drives scheduled work explicitly (the same policy as the basket
   gc sweep); staging runs system cron in the deployment slice.
## 2026-09-04 — Issue #11: confirm Delivery Addresses and calculate Dispatch Distance

Dispatch requests gain Google-assisted Chilean address confirmation and an
internal road-distance calculation, without ever letting a provider failure
block request intake. Decisions:
1. **The assistance is server-mediated and dispatch-conditional.** The
   search field renders only inside the dispatch-conditional address block
   of the request form (hidden without Con Despacho, exactly like the manual
   field) and only while a provider client resolves — without credentials
   the manual textarea is the sole address path and no assistance markup
   ships. A nonce+session-guarded admin-post operation performs every
   provider lookup (`fp_address_search` PRG for the no-JS flow,
   `fp_address_suggest` JSON for the enhancement, `fp_address_pick`,
   `fp_address_confirm`, `fp_address_clear`), so the billable credential
   never reaches the page.
2. **Select → review → explicit confirm is a server-side state machine.**
   Picking a suggestion resolves its formatted destination; the re-rendered
   form shows it for review and requires an explicit Confirmar dirección
   click before it becomes the confirmed destination (Cambiar/Buscar otra
   drops it). State lives in a 15-minute session-hash transient — never the
   URL — mirroring the retained-attempt pattern. The manual Dirección de
   despacho always remains available (rural/unrecognized); once a Google
   destination is confirmed it stops being required and the confirmed
   address is the dispatch address.
3. **Distance is an internal sales fact, calculated after persistence.**
   `Freeplast_CQ_Address::calculate_and_store()` runs once the fp_quote
   record durably exists and stores `_fpq_distance` (status ok/pending/
   error, meters, origin, provider, calculation time) plus
   `_fpq_destination` (mode google/manual, formatted address, place id,
   coordinates as permitted — place ids are storable without limitation
   under the provider terms and the confirmed address is the operational
   delivery record). A Routes failure records status error with the
   destination preserved; a provider-less environment records pending;
   neither ever rejects the request. The customer-facing confirmation and
   every public surface stay distance-free, and the admin section carries
   an explicit “no es un precio de envío automático” note — v1 never turns
   distance into a shipping price or eligibility decision.
4. **Sales retry is a first-class guarded operation.** The admin detail
   (and list, with a km/status column) shows the distance to
   `manage_freeplast_quotes` holders; `fp_distance_retry` recalculates
   through the same adapter and is protected by capability + its own nonce
   (bad nonce → 403, capability-less user → 403), redirecting back with an
   outcome notice.
5. **Credentials and configuration stay out of code.** The credential is
   read via `getenv('FREEPLAST_GOOGLE_API_KEY')` (filter overridable for
   staging), never an option, never in the repository — the Google console
   restriction (Places API + Routes API) is the documented requirement.
   Migration 7 (db 7, no table) seeds the `fp_dispatch_origin` option with
   the provisional Camino El Arrayán 52, San Francisco de Mostazal origin,
   so the client's pending answer about Santiago as a second origin and
   the distance semantics apply as configuration, not redesign. The whole
   provider sits behind the narrow `freeplast_cq_google_client` filter —
   every automated check replaces it with a mode-switchable fake
   (ok/off/resolve_fail/route_fail) and no test performs network calls.

## 2026-09-04 — Issue #8: submit a Quote Request

The core customer outcome completes: the shared basket at Cotización becomes
a one-shot submission into a durable, non-public business record. Decisions:
1. **The form lives on the sole surface and reads the server basket.**
   `Freeplast_CQ_Request` (fpcq- v1) renders the request form below the
   basket lines inside the same `freeplast/basket` block — as its own
   section, outside the JS-mirrored basket view, so typed customer data
   survives in-place basket mutations and the form disappears with the last
   line. Product/options/quantities are never request fields: the handler
   re-resolves the authenticated basket session against the live catalog,
   so archived Products drop out and eligibility/options/quantities are the
   reviewed server state by construction.
2. **Fields mirror the current form; the address is dispatch-conditional.**
   Nombre, Teléfono, Email, Nombre Empresa, Rut Empresa, Giro and Con
   Despacho (exactly si/no) are required; Mensaje is optional and bounded
   (2000). The manual Dirección de despacho appears and is required only
   while Con Despacho is Sí — hidden otherwise (progressive JS reveal; a
   no-JavaScript Sí submission round-trips once through validation, which
   re-renders it visible, the server stays the authority). Email gets
   standard validity checks, the telephone accepts international formatting
   while preserving the entered text plus a normalized stored copy, and the
   RUT is kept as entered (required + charset bound, no invented checksum).
3. **Failures retain everything.** Invalid submissions redirect back with a
   focused linked summary (`role=alert`, anchored links `#fp-<field>`,
   fragment focus target) plus inline `aria-describedby`/`aria-invalid`
   errors; the entered values live in a 15-minute transient keyed by the
   session hash — never in the URL, never in logs — so fields and basket
   are retained after every invalid attempt. Nonce, session and idempotency
   token guard failures are recoverable codes that persist nothing.
4. **Exactly one record per submission.** A successful submission persists
   one private `fp_quote` post (non-public, no REST, no public URL) titled
   with its permanent `FP-YYYY-NNNNNN` reference allocated from the year's
   highest sequence (internal IDs stay internal), carrying the Submitted
   Details (`_fpq_customer`), immutable per-line snapshots (`_fpq_items`:
   source id, post id, title, option, quantity, minimum/step used, specs,
   canonical URL), status `new` and the sha256 idempotency hash. Only then
   is the basket cleared — the session row survives (empty) so the next
   visit is a fresh basket instead of an apparent expiry. A persistence
   failure (filter seam `freeplast_cq_request_persist` or failed insert)
   shows no success and retains basket plus values.
5. **Idempotency is token-based, not hope-based.** Each rendered form
   carries a session-scoped random token kept in a day transient; a
   submission whose token already served a persisted request redirects to
   that request's confirmation without creating a second record —
   refresh/back/repost cannot duplicate. A rebuilt basket renders a fresh
   token and receives its own reference. The confirmation itself renders
   only for the session that owns it (a session-scoped transient must match
   the `fpcq_submitted` reference — the URL alone reveals nothing).
6. **Minimal capability-protected inspection.** Migration 6 (db version 6,
   no new table) grants `manage_freeplast_quotes` to administrators; a
   top-level Cotizaciones menu lists the records (reference, company,
   email, dispatch, status, created) and the detail shows Submitted Details
   and the immutable snapshots. The full sales administration (statuses,
   notes, history, corrections) is issue #9; notifications are #10; the
   Google-assisted address is #11. No price, Quotation, Order, checkout or
   customer account is created.

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
## 2026-09-03 — Issue #12: complete the v6 content and navigation experience
The static one-page prototype is fully replaced by the approved WordPress
information architecture, under a frozen v6 design contract. Decisions:
1. **The design contract is frozen, not implied.** `wordpress/design/`
   now carries `design-tokens.json` (the v6 typography/color/spacing/shape/
   motion/controls/navigation/interaction tokens extracted verbatim from the
   approved prototype), `DECISIONS.md` (source URLs + SHA-256 hashes of the
   six approved v6/v7-A files, recomputed by `npm test` on every run so
   prototype drift is a failure) and `brief.md` (per-route behavior). The
   rejected v5 rules were removed from the governing docs (TARGET.md,
   RUNBOOK.md) — a sentence may mention v5 only to reject it.
2. **The v5-inherited control styling is corrected to v6.** The green
   rectangular ≥48px CTA came from the obsolete v5 TARGET contract; the
   frozen v6 contract makes the primary control blue `#100090` (hover
   `#0b078c`), 44px min-height, 8px radius, weight 600 — green remains the
   accent (kickers, mission/vision labels) and a documented `.btn-green`
   variant. The island became the v6 floating pill (glass + backdrop blur,
   sticky instead of fixed), headings weight 700, and dark surfaces use the
   v6 dark tokens (`#181818` footer, `#272727` raised strip). Recorded
   adaptations (compact cards with 8px radius because they embed chooser
   forms, no one-page scroll-spy/reveal) live in DECISIONS.md.
3. **Home keeps the concise v6 composition.** front-page.html adds the
   concise Nosotros section (current mission/vision summary + link to the
   standalone page), the **Cotiza Online** section — a Quote Basket
   summary/CTA into `/cotizacion/` explaining that the selection is saved
   and the header count follows the visitor, never a second submission
   form — and a concise contact section (phone/WhatsApp/email/map/hours).
4. **Contacto completes without creating an Inquiry domain.** Migration 5
   replaces exactly the legacy seeded placeholder (byte-compared, human
   edits untouched) with the current contact surface: warehouse + map link,
   phone, WhatsApp, email, hours, and exactly one CTA into `/cotizacion/`.
   No form exists on the page — the only quotation surface stays
   `/cotizacion/`.
5. **Política de privacidad carries the agreed basic disclosure.** What is
   collected (cotización fields + basket products), the purpose, the
   recipient (Freeplast / ventas@freeplast.cl), the anonymous 30-day basket
   session, and a queries path — explicitly without any acknowledgement
   checkbox (PRD #1). Also linked from every footer.
6. **Navigation reaches every approved destination.** CONTACTO joined the
   desktop island nav (it was already in the mobile sheet); the footer
   links the Contacto page and the privacy policy; the logo/INICIO,
   NOSOTROS, TIENDA links and the Cotización count/mini-basket widget were
   already correct. The header count and mini basket are asserted accurate
   on every route (home, nosotros, tienda, category, product, cotización,
   contacto, política, search, 404) with a two-line basket.
7. **Nosotros stays editable page content.** The mission/vision render as
   WordPress page blocks; the check proves editability by editing the page
   through WP-CLI and observing the rendered change. Baseline layout comes
   from the theme's page template, never from Site Editor overrides.
8. **Templates parse without block recovery, and the theme stays pure
   presentation.** Every template/part is parsed with `parse_blocks` and
   asserted free of unparsed block markup and unbalanced delimiters; a
   static scan forbids Catalog/Quote Request logic tokens (queries, post
   types, plugin tables, POST endpoints) anywhere in the theme.

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
