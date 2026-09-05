# Freeplast catalog + quote implementation contract

> **Historical contract, superseded 2026-09-05.** Current architecture and operation: [ADR-0001](../../adr/0001-woocommerce-quote-only.md) and [WOO-MIGRATION.md](../../../wordpress/WOO-MIGRATION.md). Do not reinstate the no-Woo dependency rule or recurring JSON synchronization described below.

This reference defines the WordPress theme and private plugin. [RUNBOOK.md](RUNBOOK.md) owns
sequence and gates.

## Product boundary

Freeplast is a quote-only catalog with a small, source-controlled product set. It has no
customer-facing prices, cart, checkout, payment, stock or shipping calculator. Product changes
arrive through reviewed JSON and WP-CLI synchronization, not an editor screen.

WordPress owns pages, media, users and block rendering. `freeplast-catalog-quotes` owns product
records, routing, quote sessions, submissions, emails and quote administration. WooCommerce
is outside the dependency graph.

## Design contract

Frozen in `wordpress/design/` (issue #12): `design-tokens.json` is the machine-readable v6
contract, `DECISIONS.md` records the approved sources with SHA-256 hashes, `brief.md` the
per-route behavior. The rejected v5 editorial direction must not be used.

### Storefront

- Canonical prototype: `propuesta-textos-originales/` (the approved v6)
- Published reference: `https://mliu.site/freeplast/v6/`
- Tokens: `wordpress/design/design-tokens.json`
- Direction: flat mobile-first storefront with the preserved Freeplast palette.
- Typography: Manrope for display and UI/body (400–700), tabular numerals for data.
- Colors: Freeplast blue `#100090` primary, green `#306020` accent, tint/dark bands.
- Shape: soft radii (8 px controls, pills for island/chips, 24 px plates), glass island.
- Primary action: blue button, 44 px minimum height, 8 px radius, gentle lift on hover.

### Product page

- Structural reference: `product-page-prototype/?variant=A`
- Published reference: `https://mliu.site/freeplast/v7/?variant=A`
- Keep: breadcrumb, large media, title/current description, four quick specs, quote action,
  specification table and related products.
- Adapt: typography, geometry, header, spacing and CTA to the same v6 tokens and chrome.

### Content

`freeplast.cl` is the source at synchronization time. Preserve published business copy,
product names/excerpts, contact details and mission/vision. UI labels may improve clarity;
product claims may not be invented. Plugin-generated rating/carousel strings are not business
copy.

## Routes

| Route | Owner and required state |
|---|---|
| `/` | theme: v6 home — hero, concise Nosotros, eight Featured Products, Cotiza Online basket summary/CTA, concise contact |
| `/tienda/` | plugin archive + theme template: full catalog and quote actions |
| `/producto/<slug>/` | plugin record + theme product-A template |
| `/cotizacion/` | plugin block: basket, quantity controls and request form |
| `/nosotros/` | WordPress page: current mission and vision |
| `/contacto/` | WordPress page: current details and one CTA into Cotización (no inquiry record) |
| `/politica-de-privacidad/` | WordPress page: purpose, recipient and retention |
| search/404 | theme: usable navigation and empty states |

Staging carries `noindex`. Production canonicals are a release decision.

## Catalog model

### Product post type

Register `fp_product` with:

- `public: true`, `publicly_queryable: true`, `show_in_rest: true`;
- `show_ui: false` and `show_in_menu: false`;
- single rewrite base `producto`;
- archive rewrite `tienda`;
- support for title, editor/excerpt, thumbnail and revisions as required by sync;
- search inclusion and canonical permalink generation.

The absence of editor UI is intentional. Administrators inspect synchronization reports and
public pages; product mutations go through the versioned JSON + WP-CLI path.

### Source file

`wordpress/data/products.json` is the reviewed catalog source. Give it a versioned JSON schema
covering:

- stable source ID and source URL;
- slug, title, excerpt and optional full description;
- image URL, local checksum and alt text;
- related product slugs;
- structured specs supported by source text;
- minimum quote quantity and quantity step;
- source retrieval timestamp.

Reject unknown keys, duplicate IDs/slugs, invalid URLs, non-positive quantities and
minimums that do not align with their step.

### Product metadata

| Key | Meaning |
|---|---|
| `_fp_source_id` | immutable catalog identity |
| `_fp_source_url` | public source URL |
| `_fp_source_checked_at` | UTC retrieval timestamp |
| `_fp_material` | published material wording |
| `_fp_dimensions` | published dimensions/units |
| `_fp_weight_text` | published weight wording |
| `_fp_use` | source-supported use/capacity |
| `_fp_quote_min_qty` | minimum accepted quantity |
| `_fp_quote_step` | valid quantity increment |
| `_fp_related_slugs` | reviewed related products |
| `_fp_image_source` | import provenance |
| `_fp_image_checksum` | local media integrity |

Absent minimum/step means `1`; request handling never scrapes numbers from prose.

### Synchronizer

Command:

```bash
wp freeplast catalog sync --file=<products.json> [--dry-run]
```

Behavior:

1. Parse and validate the complete file before mutation.
2. Match records by immutable source ID, then verify slug uniqueness.
3. Create/update only plugin-owned fields.
4. Sideload images when checksum/source changes; reuse matching media.
5. Preserve post IDs/permalinks across updates.
6. Mark missing products `draft` only with explicit `--archive-missing`; default reports them.
7. Emit deterministic created/updated/unchanged/warning/error counts and a per-product diff.
8. Return non-zero on schema, import or consistency failure.

A second run against unchanged input must be a no-op.

## Theme/plugin interface

The plugin registers server-rendered dynamic blocks or equivalent stable APIs:

- `freeplast/catalog-grid`
- `freeplast/product-specs`
- `freeplast/add-to-quote`
- `freeplast/quote-count`
- `freeplast/mini-quote`
- `freeplast/quote-request`

Blocks provide semantic unstyled/minimally styled markup, context attributes and accessible
states. The theme owns final presentation through its tokens and plugin-owned public classes.
The interface and classes are versioned; the theme does not query plugin tables directly.

## Quote basket

### Session model

Guests receive a random 256-bit opaque token in a `Secure`, `HttpOnly`, `SameSite=Lax` cookie.
Store only its hash server-side. Use a plugin table created through versioned `dbDelta`
migrations:

```text
{prefix}fp_quote_sessions
  id
  token_hash UNIQUE
  user_id NULL
  items_json
  created_at
  updated_at
  expires_at
```

The cookie contains no product or personal data. Authenticate the session before every read or
write. Rotate/merge predictably on login. Expired sessions are removed by scheduled cleanup.

Each item stores product ID, quantity and any supported option identity. Product title/spec
snapshots are created only at final submission.

### Basket behavior

1. Add validates product visibility, integer quantity, minimum and step.
2. Re-adding the same product/options merges quantities.
3. Update and remove use POST and nonces.
4. Header count and mini quote match server state after refresh.
5. JavaScript updates without reload; HTML form redirects preserve full functionality.
6. Basket empties only after quote persistence succeeds.
7. Idempotency token prevents duplicate submission after refresh/back/retry.

## Quote request

### Fields

Required unless BUILD-DECISIONS records otherwise:

- Nombre
- Teléfono
- Email
- Nombre Empresa
- Rut Empresa
- Dirección
- Giro
- Product(s) and quantity/quantities from authenticated basket state
- Con Despacho: `Si` or `No`
- Mensaje, optional
- Privacy acknowledgement

Keep current public wording where exact-copy parity applies, including “Rut Empresa” and option
values.

### Persistence

Register non-public `fp_quote` with its own admin UI under top-level **Cotizaciones**. Store:

- public reference number;
- created/updated timestamps;
- status: `new`, `contacted`, `quoted`, `closed`, `cancelled`;
- submitted customer fields;
- immutable item snapshots: source/product ID, title, quantity, minimum, specs and URL;
- append-only status events;
- idempotency token hash.

Original customer fields and item snapshots remain immutable. Corrections/status changes append
an event. Quotes are not orders.

### Admin

Provide:

- sortable list with reference, company, email, created date and status;
- detail view with customer data, item snapshot and event history;
- nonce/capability-protected status transitions;
- resend-notification action;
- dedicated `manage_freeplast_quotes` capability;
- no product editor menu and no customer PII in logs.

### Email

Send one sales notification and one customer acknowledgement through WordPress mail. Include
reference, products and quantities. Mail transport/authentication is an environment concern and
must pass a delivery test before release.

## Security and privacy

- Nonces protect every state change; session authentication remains independent of nonce.
- Server validates product, quantity, minimum, step and option identity on every mutation.
- Sanitize at input, store canonical values and escape for each output context.
- Use prepared queries for plugin tables and WordPress APIs for post/meta access.
- Require `manage_freeplast_quotes` for quote access/transitions.
- Add a honeypot and bounded submission throttling without storing raw IP/email in rate keys.
- Integrate with WordPress personal-data export/erase.
- Run retention cleanup according to BUILD-DECISIONS and expose a dry-run WP-CLI cleanup command.
- Activation/deactivation is reversible. Uninstall deletes personal records only after explicit
  owner opt-in.

## Compatibility and failure behavior

- Declare tested WordPress/PHP/database versions.
- Plugin activation checks schema and required PHP extensions; failures leave prior data intact.
- Catalog and quote routes return a clear maintenance state during migrations.
- Session/database/email failures never show false success.
- The theme can deactivate without deleting or hiding catalog/quote admin records.
- The plugin can render functional minimal markup under a stock block theme.

## Acceptance matrix

| Case | Expected result |
|---|---|
| Catalog sync first run | Expected create count; source IDs/slugs unique |
| Catalog sync second run | Zero changes |
| Invalid catalog file | Entire sync rejected before mutation |
| Missing product default | Warning only; existing record unchanged |
| Image unchanged | Existing attachment reused |
| Add valid product | Count increments and survives refresh |
| Add same product twice | One line with merged valid quantity |
| Minimum 70, request 1 | Server rejects with linked error |
| Quantity not on step | Rejected with no mutation |
| Invalid nonce/session | Rejected with no mutation |
| Draft/deleted product | Rejected with recoverable message |
| Update/remove | Basket and header count agree after refresh |
| Empty submission | Focused summary; no record/email |
| Invalid fields | Values retained; inline + summary errors |
| Valid multi-item submit | One immutable record and two notifications |
| Persistence/email failure | No false success; recoverable basket retained |
| Repeat submit | No duplicate record |
| JavaScript disabled | Add, update, remove and submit work |
| Guest login | Basket rotates/merges without duplication |
| Keyboard only | Journey operable with visible focus |
| Privacy export/erase | Quote data included and handled per policy |
| Retention task dry run | Exact eligible IDs/count, no mutation |
| Theme deactivated | Products/routes/quotes remain functionally accessible |
| WooCommerce absent | Full target journey still passes |

## Optional formal-quote extension

Build only when Gate 0 selects it:

- admin-entered item prices, tax/shipping notes and expiry;
- generated PDF with immutable revision number;
- customer acceptance/rejection link with signed expiry;
- append-only audit event for every revision/transition.

There is no order conversion or payment path unless a future architecture decision adds one.
The request-only build is complete without this extension.
