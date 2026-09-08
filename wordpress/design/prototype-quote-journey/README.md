# PROTOTYPE — Freeplast quote journey

A disposable, browser-only design exploration. **Not a theme, not a WooCommerce adapter, not production code.**

## Question

Which composition makes selecting multiple products and submitting a wholesale Quote Request clearest at ~412 px and on desktop?

- **A — Directa:** search and category filters above a product grid (one product per row on mobile).
- **B — Por categorías:** a category rail and grouped product grids, with visible process guidance; mobile collapses to category controls and product rows.
- **C — Con resumen:** catalog workspace alongside a live selection panel; mobile exposes the selection in a compact expandable summary.

The floating dark toolbar is a **review tool**, not a proposed storefront control. Its arrows cycle `?variant=A|B|C`; keyboard left/right works outside text/select controls. Switching preserves in-memory basket and form state. Escenarios exposes simulated states and a state inspector without printing entered field values.

## Run

From this directory: `npm run preview` (Python 3 and Tailscale; no npm install needed).

Open **http://mliu:7785/?variant=A** with Tailscale connected. The server binds only to this machine's Tailscale IP and serves only this prototype directory. It has no POST handler.

Alternatively use the installed serve-output helper on this directory for a two-hour preview URL. The port printed by that helper is authoritative.

## Routes and controls

One isolated static route: `index.html?variant=A&view=catalog`. `view` may be `catalog`, `product`, `basket`, `details`, or `confirmation`; a product route adds `product=<slug>`. Confirmation requires a simulated submitted state; use Escenarios or complete the mock form.

- Search and category filters, ordering, product detail, technical data disclosure.
- Quantity + explicit add for simple products. Variant colors remain in the product detail, with names and selected state.
- Per-product added amounts, removal, distinct-product and unit totals.
- Basket editing and return to catalog.
- One contact/company/dispatch form; conditional dispatch address.
- Inline errors, linked error summary, submitting, simulated failure with data retained, simulated success.
- Empty selection, empty search, mobile menu and help/privacy explanations.

## Confirmed brief — 2026-09-08 conversation

The owner explicitly approved all interview recommendations Q1–Q7 and confirmed Q8 before construction:

1. Favor fast multi-product selection from the catalog while providing essential comparison information and fuller product details.
2. Preserve existing commercial requirements, required fields, dispatch rules, no public prices, no payments, no registration. Production stays on native WooCommerce behavior.
3. Permit structural UI reorganization while keeping logo, blue and sober Freeplast identity. Corporate content is out of scope; shared header presentation may change.
4. Mobile: one product row; desktop: grid with aligned actions.
5. Simple products add from catalog; color variants use the detail page.
6. Compact persistent mobile header instead of the floating capsule, retaining Productos a Cotizar.
7. One form page grouped Contacto / Empresa / Despacho, summary on the right on desktop and compact above on mobile, with Editar productos.
8. Isolated navigable prototype, simulated empty/add/color/error/submission states, then human review before specification and implementation. Pending media and specifications do not block the exploration; never invent them.

The existing frozen v6/v7 sources and `wordpress/design/design-tokens.json` are **not edited**. This is an authorized exploration of changed layout/header hierarchy, not a declaration that a new design has superseded the production design contract. Manrope, brand palette and soft geometry are retained; older rejected editorial/serif designs are not revived.

## Isolation and boundaries

- All action state lives in JavaScript memory. Reload resets it. No cookies, localStorage, sessionStorage, analytics, API calls, email or payments.
- CSP `connect-src 'none'` and `form-action 'none'` prevent remote requests and form submission. Assets and font are local.
- Only use fictitious contact/company information. The example RUT is deliberately fictitious; this prototype does not certify RUT validity.
- Demo reference `DEMO · FP-2026-000001` is never a real Request Reference. Success explicitly says no real request/email was created.
- The failure scenario is a known simulated failure, **not** a design for ambiguous server-side completion/retry recovery. Production must retain Woo attempt identity, deduplication and recovery.
- This in-memory interaction model must not be promoted as a replacement for Woo carts, sessions, checkout, server validation, native accessibility behaviors or persistence.
- No stock assumptions, minimum-quantity or pallet-multiple rules are invented. Pallet size is packaging information only.
- `catalog.js` is a read-only snapshot of repository bootstrap data as checked against the audit on 2026-09-08; it is not a new Catalog Source. All 17 products and current categories are represented. Commercial data remains editable in Woo.
- Reference photographs remain labeled; missing photos use an explicit placeholder, not a fabricated product. Internal pending-review prose is presented neutrally as unconfirmed information, not completed facts.
- New home/corporate content, category restructuring, final product content, backend changes, deployments and real request verification are excluded.

## Assets

- Products copied from `wordpress/data/media/` without altering their source files.
- Existing header mark copied from `wordpress/wp-content/themes/freeplast/assets/img/mark.svg`.
- Manrope variable font copied from the local WordPress Twenty Twenty-Five distribution. Copyright © 2018 The Manrope Project Authors; SIL OFL 1.1 included under `assets/fonts/OFL.txt`.

## Review status

**Pending human visual review.** Browser observations and runnable-state checks are not hardware validation, production acceptance or permission to deploy. No variant has won yet.

Observed on 2026-09-08 in desktop Chrome:

- Added quantities and a required color; switched compositions without losing selection; reloaded to confirm reset.
- Empty required fields produced a linked seven-field summary. Enter from a form input also invokes the simulated validation.
- The failure scenario retained three product lines / 107 units and fictitious form values. Retry showed Enviando… and then an explicitly fictitious confirmation, with the selection cleared.
- Inspected catalog, product, selection, form and confirmation across A/B/C at viewport widths 320, 375, 412, 768, 1024 and 1440 px: no horizontal document overflow in these 90 combinations; measured quantity buttons at least 44 × 44 px and visible text-entry fields at least 16 px.
- Captures of mobile and desktop compositions were inspected. Review-toolbar scroll clearance was increased; programmatic landmark focus no longer draws an outline around the whole page, while interactive controls retain visible keyboard focus.
- JavaScript syntax checks passed. The observed browser network log contained no POST requests and its console no captured warnings/errors. These are bounded observations, not a production security or accessibility audit.

Temporary observation artifacts: `/tmp/freeplast-prototype-review/`. No phone, ADB, WordPress runtime, public submission or deployment was used for prototype review. The isolated preview server is the only server started for this artifact.

Keep this artifact on `prototype/quote-journey-20260908`, outside main. Once a design is approved, reference this branch from the implementation issue and implement properly within Woo. Do not merge the prototype toolbar or simulation into the storefront.
