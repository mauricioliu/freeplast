# Freeplast WordPress catalog + quote build runbook

> **Retired recipe (2026-09-05).** The owner approved replacing the private catalog/basket with WooCommerce. Current execution guide: [WOO-MIGRATION.md](../../../wordpress/WOO-MIGRATION.md), decision [ADR-0001](../../adr/0001-woocommerce-quote-only.md). Preserve the history below; do not execute it against the migrated staging.

Use this runbook to provision an isolated WordPress site on OpenClaw, implement the published
**v6 storefront** with the **product-page A structure**, and build a private catalog
and quote-basket plugin.

This is an execution recipe. Read [TARGET.md](TARGET.md) for the theme/plugin contract and
[OPENCLAW.md](OPENCLAW.md) only for server operations.

## Locked architecture

The owner approved this architecture on 2026-09-03:

- WordPress provides pages, media, users, block templates and administration.
- A standalone block theme owns presentation.
- One private plugin, `freeplast-catalog-quotes`, owns products, catalog synchronization,
  quote sessions, submissions, emails and quote administration.
- Products are source-controlled and synchronized through WP-CLI. The product post type has
  no editor UI.
- WooCommerce is not installed or required.
- Existing OpenClaw sites and `freeplast.cl` remain untouched until a separate cutover.

## Gates

### Gate 0 — unresolved owner inputs

Ask once for:

1. New hostname. Suggest `freeplast.mliu.site`; treat it as a proposal until approved and DNS
   resolves to OpenClaw.
2. Quote-recipient email. Suggest `ventas@freeplast.cl`.
3. Quote/customer-data retention period.
4. Scope choice:
   - **request only**: collect items and company data, then notify sales; or
   - **formal quote**: staff additionally enters prices and sends a versioned PDF.

Record answers verbatim in `wordpress/BUILD-DECISIONS.md`.

**Done when:** all four values are recorded, including an approved hostname, recipient,
retention period and scope.

### Gate 1 — infrastructure plan

Read [OPENCLAW.md](OPENCLAW.md), re-probe the host, and record the exact new paths, container
names, loopback port, hostname, volume names and rollback command in
`wordpress/DEPLOYMENT.md`. Show the bounded impact to the owner.

The executable plan is committed (issue #14): `wordpress/infra/` carries
the Compose stack, the Nginx vhost and the preflight/deploy/verify/
backup/rollback scripts; `wordpress/infra/preflight.sh` automates the
re-probe and collision checks, and `npm test` validates the artifacts.

**Done when:** the owner approves the recorded plan and every collision check is negative.

### Gate 2 — visual contract

Open both approved sources:

- v6 storefront: `propuesta-textos-originales/` and `https://mliu.site/freeplast/v6/`
- product-page A: `product-page-prototype/?variant=A` and
  `https://mliu.site/freeplast/v7/?variant=A`

Create `wordpress/design/` containing:

- `brief.md`: pages, behavior and responsive intent;
- `design-tokens.json`: the extracted v6 colors, typography, spacing, shape, controls,
  navigation and interaction tokens;
- `DECISIONS.md`: source URLs, SHA-256 hashes, “v6 tokens, v7-A structure” and the recorded
  theme adaptations.

The product page inherits **v6 tokens**—Manrope, preserved Freeplast palette, soft radii
and the island chrome—while keeping **A’s hierarchy**—gallery + summary, quick specs, quote
action, detailed specification table and related products. Prototype switching, variants B/C
and review-artifact links are outside the target. The earlier v5 editorial direction
(`propuesta-editorial/`) was rejected and must not be used.

**Done when:** every approved-source hash matches, token JSON parses and DECISIONS records
“v6 tokens, v7-A structure.” (Frozen 2026-09-03, issue #12.)

## Build sequence

### 1. Scaffold

Create:

```text
wordpress/
  BUILD-DECISIONS.md
  DEPLOYMENT.md
  design/
  data/products.json
  infra/compose.yaml
  infra/nginx/
  wp-content/themes/freeplast/
  wp-content/plugins/freeplast-catalog-quotes/
  scripts/
```

Keep server credentials in `/opt/freeplast-wordpress/.env`, mode `0600`. Repository
`.env.example` files contain names and comments only.

Load `wp-block-themes` and `wp-patterns` before theme code. Consult current canonical
WordPress documentation for version-sensitive APIs.

**Done when:** the structure exists, repository files contain no secrets, and the unique
slugs are `freeplast` and `freeplast-catalog-quotes`.

### 2. Provision WordPress on OpenClaw

Follow [OPENCLAW.md](OPENCLAW.md). The committed stack (issue #14) lives in
`wordpress/infra/`: a `freeplast-wordpress` Compose project with separate
WordPress and MariaDB services, persistent private volumes, a profile-gated
WP-CLI sidecar and loopback-only HTTP on `127.0.0.1:8092`; the approved
Nginx hostname, TLS convention and owner/client Basic Auth render through
`infra/deploy.sh` (which runs `nginx -t` before every reload). The exact
operator commands are recorded in `wordpress/DEPLOYMENT.md`.

Bootstrap WordPress through WP-CLI with:

- locale `es_CL`;
- timezone `America/Santiago`;
- approved HTTPS home/site URL;
- staging search-engine visibility disabled;
- generated administrator credentials transferred through an approved secret channel.

Back up Nginx before changing it and record every created path. Preserve the static Freeplast
proposals, Cutulab stack and every unrelated Compose project.

**Done when:** containers are healthy, HTTPS reaches the new WordPress origin, WP-CLI reports
the expected URL/locale/timezone and rollback targets only the new resources.

### 3. Capture and synchronize the catalog

At execution time retrieve the current public source from:

- `https://freeplast.cl/`
- `https://freeplast.cl/quienes-somos/`
- `https://freeplast.cl/contacto/`
- `https://freeplast.cl/wp-json/wp/v2/product?per_page=100`

Create `wordpress/data/products.json` as the reviewed catalog source. Preserve titles, slugs,
excerpts, source URLs, punctuation and units. Include only source-supported structured values:
material, dimensions, weight/capacity/use, minimum quote quantity and quantity step. Store
image source URLs for import, not frontend hotlinking.

Implement an idempotent command:

```bash
wp freeplast catalog sync --file=/path/to/products.json
```

The command creates or updates hidden-UI `fp_product` records, sideloads images into the media
library, records source timestamps and reports created/updated/unchanged/error counts. A dry
run emits the same diff without mutation.

Create current pages for Home, Nosotros, Contacto and Política de privacidad. The plugin owns
Tienda, product and Cotización routes as defined in TARGET.

**Done when:** dry-run after synchronization reports zero changes; source and destination
counts match; every source product has title, slug, excerpt and featured image accounted for;
and a text audit reports zero unexplained differences.

### 4. Build the standalone block theme

Implement `wordpress/wp-content/themes/freeplast/` from the frozen visual contract. Theme
files render their baseline on a clean database without Site Editor overrides.

Required templates and parts:

- `front-page.html`;
- `archive-fp_product.html` at `/tienda/`;
- `single-fp_product.html` at `/producto/<slug>/` using product-page A structure;
- `search.html`, `page.html`, privacy and 404 behavior;
- a Cotización wrapper around `core/post-content`;
- shared header/footer parts;
- theme-owned home, mission/vision and contact patterns.

Map locked tokens into `theme.json`. Use core blocks for standard content and plugin dynamic
blocks for catalog/quote behavior. Product content and specifications come from `fp_product`
records and metadata, not duplicated pattern text.

The primary product action is **Agregar a cotización**. The header displays
**Cotización (n)**. Archive cards and product pages feed the same quote basket.

**Done when:** templates parse without block recovery, a fresh database renders the same
baseline, every required route returns HTTP 200 with synchronized content and the theme
contains no catalog or quote business logic.

### 5. Build `freeplast-catalog-quotes`

Implement the private plugin exactly to [TARGET.md](TARGET.md). Begin with tests for catalog
sync, server-side add/update/remove rules, quantity minimums, submission validation, nonce
failure and authorization.

The plugin must provide:

- public hidden-editor product records and `/tienda/` + `/producto/<slug>/` routing;
- the idempotent WP-CLI catalog synchronizer;
- a guest/user quote basket backed by an opaque cookie and server-side session table;
- add, update and remove forms with progressive JavaScript enhancement;
- dynamic blocks for catalog grid, product specs/action, quote count, mini quote and request
  form;
- current company/contact fields;
- persisted admin-visible quotes and status history;
- sales/customer email notifications;
- privacy export/erase and retention cleanup.

JavaScript enhances the flow; server-rendered forms remain authoritative. Every state change
validates nonce, product eligibility and quantity server-side. Sanitize on input, escape for
output and protect admin operations with a dedicated capability. Logs contain quote IDs and
events, never customer field values.

**Done when:** every TARGET acceptance case passes, no WooCommerce dependency exists and the
theme can be deactivated without losing products, quote records or admin access.

### 6. Integrate the catalog-to-quote journey

Complete as guest and logged-in user:

```text
Tienda → product A → choose quantity → add to quote → mini quote →
add second product → update/remove → Cotización → company form → confirmation
```

Verify refresh retains the basket, crafted requests cannot bypass minimums, duplicate adds
merge predictably, failed persistence retains the basket, and successful submission sends one
admin and one customer message.

**Done when:** one- and multi-product journeys pass with JavaScript enabled and disabled, and
the admin record exactly matches submitted item snapshots and quantities.

### 7. Mechanical proof

Save commands and results in `wordpress/VERIFICATION.md`:

- Compose validation/health and Nginx config test;
- PHP syntax and WordPress Coding Standards/PHPCS;
- JavaScript lint/build when applicable;
- unit/integration tests and database migration version;
- WP-CLI theme/plugin state;
- HTTP status for every route;
- catalog dry-run and text audit;
- quote acceptance matrix;
- browser console errors;
- keyboard path, focus, errors and reduced motion;
- responsive observations at 375, 412, 768, 1024 and 1440 px.

Mechanical proof is evidence, not visual approval.

**Done when:** every result is recorded, each failure is resolved or explicitly blocked and no
check is represented as human visual validation.

### Gate 3 — human review

Give the owner HTTPS review URLs for Home, Tienda, Caja Cosechera 3/4, Cotización, Nosotros
and Contacto, each beside its frozen reference. Ask for mobile-first review and a complete
quote journey. Append the owner’s exact verdict to `wordpress/design/DECISIONS.md`.

Agent screenshots and emulation do not satisfy this gate.

**Done when:** every page and the complete quote journey have an explicit human verdict.

### 8. Handoff

Produced by issue #15 as `wordpress/HANDOFF.md` (mechanically guarded by
`npm test`), covering:

- deployment and rollback commands;
- database/uploads/theme/plugin backup and restore commands;
- catalog JSON schema and sync instructions;
- quote administration and retention guide;
- versions and SHA-256 checksums of shipped theme/plugin ZIPs
  (`wordpress/dist/`);
- remaining blockers and exact next human action.

Keep staging `noindex`. Replacing `freeplast.cl`, production mail authentication and DNS
cutover require a separate owner-approved release plan.

**Done when:** another agent can restore, redeploy, synchronize the catalog and operate a quote
from new → contacted → closed using only the handoff.
