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
  design/                     frozen v6 design contract (tokens, hashes, brief)
  data/products.json          versioned catalog source (schema v2, 17 products)
  data/media/                 reviewed local media referenced by the source
  scripts/                    toolchain fetch, bootstrap, checks
  wp-content/
    themes/freeplast/         standalone block theme — v6 shell + v7-A product
    plugins/freeplast-catalog-quotes/
                              private plugin — shell routes, migrations,
                              fp_product records, catalog synchronization,
                              quote basket, quote-request submission
                              and the restricted sales administration
  .tools/                     pinned downloadable toolchain (gitignored)
  .build/                     disposable WordPress site (gitignored)
```

## Commands

First run needs `curl`, `tar`, `unzip` and `sha256sum` (plus Node ≥ 20) and
network access to download the pinned toolchain; later runs are offline.

```bash
npm test              # THE check command: bootstrap a clean disposable
                      # WordPress + SQLite, activate theme and plugin, and
                      # verify the issue-#2 through issue-#12 acceptance
                      # criteria (including the issue-#8 submission and the
                      # issue-#10 durable notifications).
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
- `/cotizacion/` is the sole final submission surface: it renders the
  quote-basket view and, below the basket lines, the Quote Request form
  (issue #8) — never rendered without lines; the only other forms anywhere
  are the basket choosers and line-edit forms (fpcq-basket-add/update/remove);
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
  `/tienda/` lists all 17 Active Products on one page (canonical links, and a
  “Cotizar” action per card that opens the shared quantity chooser), the
  Todos/Agrícola/Otros filters are accessible links with meaningful
  `/tienda/categoria/<categoria>/` URLs (unknown categories 404), search finds
  Products and standard pages with a clear no-result state, related products
  render the reviewed ids in reviewed order, and an explicitly archived
  Product disappears from every discovery surface (its URL stops resolving);
- the Quote Basket is a persistent, secure, anonymous session: “Agregar a
  cotización” opens a quantity chooser on cards and on the product page, a
  positive whole-unit quantity adds Caja Cosechera 3/4 through the
  nonce-guarded authoritative POST (admin-post), the browser keeps only an
  opaque 256-bit Secure/HttpOnly/SameSite=Lax cookie while the server stores
  only its sha256 hash (migration 4's `basket_sessions` table), the header
  count reflects distinct lines (Cotización (n)), the mini basket shows
  Product/quantity and routes to the full `/cotizacion/` view across
  refreshes, invalid nonce/session/Product/quantity mutate nothing and return
  recoverable messages, and the progressive JavaScript enhancement receives
  JSON state from the same authoritative handler;
- the Quote Basket is fully editable: Color Caja Universal choosers require
  one currently supported color option, the same Product+option merges
  quantities while different options stay separate lines, every line on
  `/cotizacion/` updates and removes through its own nonce-guarded operation
  with JavaScript enabled or disabled, header count/mini basket/full view
  agree after every mutation and refresh, malformed or inactive
  Product/option submissions are rejected without mutation, confirmed
  minimum/step rules are enforced when present, logged-in staff browsers keep
  using the anonymous cookie basket (no user linking), and sessions expire
  30 days after last activity (dead cookie cleared once, empty state routes
  back to Tienda, daily sweep collects expired rows);
- the Quote Request submission (issue #8) completes the core customer
  outcome: the form carries the current Freeplast business fields (Nombre,
  Teléfono, Email, Nombre Empresa, Rut Empresa, Giro, Con Despacho = Sí/No;
  Mensaje optional/bounded; a manual Dirección de despacho only with
  dispatch), takes products/options/quantities from the authenticated
  server basket (never from request fields, archived Products drop out),
  retains entered values and the basket after every invalid attempt with a
  focused linked error summary plus inline aria-linked errors, and — once
  valid — persists exactly one private `fp_quote` record (non-public, no
  REST, no public URL) with Submitted Details and immutable per-line
  Product snapshots (source id, option, quantity, rules used, specs,
  canonical URL), a permanent `FP-YYYY-NNNNNN` Request Reference shown on
  the session-owned confirmation, a cleared basket only after durable
  persistence (a forced persistence failure shows no success and retains
  it), idempotent refresh/back/retry through a session-scoped token, and a
  minimal capability-protected Cotizaciones admin detail
  (`manage_freeplast_quotes`); no price, Quotation, Order, checkout or
  customer account is ever created;
- the sales administration workflow (issue #9) makes the records
  operational: a least-privilege Ventas Freeplast role (read +
  `manage_freeplast_quotes`, nothing else — migration 7) reaches
  Cotizaciones while Users/Plugins/Posts/Themes stay denied, the list
  sorts by reference/company/email/created date/Request Status and
  searches by reference/company/email with a status filter, the detail
  separates immutable Submitted Details from correctable Current Contact
  Details (corrections update the current copy and the list columns while
  the submitted record stays byte-identical, and the history event names
  only the changed fields + time + staff — never PII values), internal
  Sales Notes append with author and timestamp and never reach a public
  page, Request Status moves new → contacted → quoted → won/lost with
  permitted skips plus cancelled, terminal states reopen explicitly back
  to contacted, and every state change re-validates nonce + capability
  and records staff identity/time; no bulk CSV export exists;
- the durable notifications (issue #10) decouple mail from receipt: every
  Quote Request persists together with exactly two notification jobs (one
  sales notification, one customer acknowledgement) in the same record
  insert — a failing job creation aborts the whole submission (no record,
  basket retained) — while successful receipt still confirms and clears
  the basket before any external mail delivery is required (a scheduled
  delivery event runs later). Both messages carry the Request Reference
  and the product lines with options and quantities, the sales message
  adds the operational customer/dispatch details, sales Reply-To points
  to the customer and the customer Reply-To to `ventas@freeplast.cl`.
  Delivery is idempotent (sent channels are never re-attempted), a total
  or partial mail failure after persistence never duplicates the request
  or a successful delivery, authorized staff see the per-channel state in
  the Cotizaciones detail and can safely resend a failed notification
  (nonce + capability guarded), staging modes add the visible `[STAGING]`
  subject prefix and enforce the configured recipient override, approved
  recipient allowlist or non-delivery, and the event log records states
  and codes but never customer field values. Mail is controlled at the
  single external adapter seam (`freeplast_cq_send_mail`, default
  `wp_mail`); see “Mail configuration” below;
- the complete v6 content and navigation experience (issue #12) is governed
  by a frozen design contract (`design/design-tokens.json` + `DECISIONS.md`
  with SHA-256-frozen approved prototypes; the rejected v5 rules are not
  used): Logo/Inicio, Nosotros, Tienda, Cotización count/CTA and Contacto
  navigate to their approved destinations, Home keeps the concise v6
  composition (Nosotros + contact sections, the eight Featured Products and
  a Quote Basket summary/CTA — never a second submission form), Nosotros
  renders editable mission/vision page content, Contacto renders the current
  phone/email/WhatsApp/warehouse-map/hours plus one CTA into Cotización (no
  Inquiry record), Política de privacidad discloses collection/submission
  without a consent checkbox, search and 404 keep usable navigation/empty
  states, the header count and mini basket stay accurate on every route, all
  templates parse without block recovery (migration 5 upgrades the legacy
  Contacto/privacy placeholders byte-safely), and the theme contains no
  Catalog or Quote Request business logic;
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

## Mail configuration (issue #10)

Notification delivery is decoupled from Quote Request receipt and is
contained per environment. The effective mode is
`FREEPLAST_CQ_MAIL_MODE` (environment) — falling back to the
`freeplast_cq_mail_mode` option for unconfigured hosts — and an
environment with neither fails closed to non-delivery:

- `live` — production: messages deliver to their real recipients
  (`ventas@freeplast.cl` and the customer) with no subject prefix;
- `redirect` — staging override: every message goes to the configured
  `FREEPLAST_CQ_MAIL_TO` (or `freeplast_cq_mail_to` option) instead;
- `allowlist` — only recipients in `FREEPLAST_CQ_MAIL_ALLOW` (or the
  `freeplast_cq_mail_allow` option, comma-separated) receive mail, others
  are recorded as suppressed;
- `suppress` — non-delivery mode: nothing is sent, the jobs are recorded
  as suppressed.

Every restricted mode (redirect/allowlist/suppress) prefixes the subject
with `[STAGING]` and preserves the Reply-To routing (sales → customer,
customer → ventas@freeplast.cl). The single external transport boundary
is the `freeplast_cq_send_mail` filter (default `wp_mail`) — the automated
check replaces it to control delivery and assert business outcomes. The
disposable check host defines `DISABLE_WP_CRON` so the scheduled delivery
events run deterministically when the check drives them; staging runs
system cron (deployment slice).

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
never duplicated into the theme, and every “Cotizar” action opens the shared
quote-basket quantity chooser (issue #6), which never assumes an unseen
quantity.
