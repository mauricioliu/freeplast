# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Primary user: a business buyer for an agricultural producer, fishing operation, or
distribution/commerce business in central Chile (VI Región and Santiago) who needs plastic
products — crates, bins, totes and similar — in wholesale volume. Today they find Freeplast
through the existing site and referrals and contact by phone, WhatsApp or email. Their job:
confirm the right products for their operation and request pricing for specific quantities,
with delivery considered.

Internal user: Freeplast sales staff, who receive and work incoming Quote Requests in the
WordPress administration (Request Status workflow, internal notes).

No meaningful secondary consumer audience: small walk-in buyers of single units are out of
scope (owner-confirmed 2026-09-09).

## Product Purpose

The site presents Freeplast's wholesale product catalog and receives Quote Requests from
prospective customers, replacing phone/WhatsApp/email-only intake with a structured request
that arrives complete (products, quantities, contact, delivery). Success is measured in
volume: more Quote Requests is more success (owner-confirmed 2026-09-09). Preparing and
issuing priced Quotations remains a separate, future capability outside this site.

## Positioning

Freeplast sells products made from genuine recycled plastic — "Plástico que vuelve a servir"
is a factual material claim (owner-confirmed 2026-09-09), backed by the recycle-arrow logo.
A neighboring reseller could not truthfully copy: real recycled-material products at
wholesale, regional presence with its own Warehouse in San Francisco de Mostazal (VI Región)
plus Santiago coverage, and fast, reliable service with direct quote intake.

## Operating Context

- Customer-facing language is Spanish (Chile). Terms follow CONTEXT.md: Catálogo,
  Productos a Cotizar, Solicitud de cotización.
- Commercial reality: buyers compare volumes and freight; the Warehouse at Camino El Arrayán
  52, San Francisco de Mostazal is the dispatch origin; Dispatch Distance is evaluated by
  sales when handling dispatch requests.
- Sales workflow: Quote Requests enter with status `new` and progress
  `contacted → quoted → won/lost/cancelled`; Quotations are prepared outside the
  first-release system and reflected via status.
- Development and review run on an isolated, non-indexed Staging Site; production
  freeplast.cl is touched only with separate explicit authorization.
- The 2026 print catalog (`docs/Catálogo Freeplast 2026.pdf`) and the live site are content
  sources; the editable Catalog Source in daily operation is the WooCommerce product records.

## Capabilities and Constraints

- Quote-only intake is a durable product constraint: no customer-facing prices, no checkout,
  no online sales — the owner confirms there are no plans for online sales (2026-09-09).
- A Quote Basket is a request selection, never a purchase or stock reservation. Request
  Reference format `FP-YYYY-NNNNNN`.
- Implementation: WordPress + WooCommerce + Quotes for WooCommerce + `freeplast` block theme
  + `freeplast-woo` adapter (ADR-0001). The theme owns presentation; WooCommerce and the
  adapter own catalog, basket and requests.
- Terminology guardrails in CONTEXT.md are binding (avoid "cart", "order", "checkout",
  "cotización" for the request, etc.).
- Mobile-first: the incumbent design contract is written for ≈412 px first; desktop adapts
  through min-width media queries.

## Brand Commitments

- Name: Freeplast. Logo: `assets/logo.webp` ("free" blue + "plast" green, two recycle
  arrows) — reference the real asset, never redraw.
- Palette: brand blue `#100090`, heading blue `#0B078C`, green `#306020`, product ink
  `#17181C`; every hue derives from blue/green/neutrals — no new hues (frozen v6 contract).
- Typeface: Manrope, loaded from a local OFL-licensed file (frozen v6 contract).
- Voice: "Plástico que vuelve a servir" · "Venta mayorista de productos plásticos" ·
  "Estamos en la VI Región y en Santiago. Servicio rápido y confiable".
- Real contact data: Camino El Arrayán 52, San Francisco de Mostazal, VI Región ·
  +56 9 6844 4265 · ventas@freeplast.cl.
- Full tokens and rules: `brand-spec.md` and `wordpress/design/` (frozen v6 design contract).

## Evidence on Hand

- Real logo and product photography: `assets/` (Tote, Caja Cosechera 3/4, Caja Merlucera,
  Caja Universal, Traversas Bins UPC, Traversas Bins G1, Caja Tomatera, Caja Pollera, Bases
  para pediluvios, Caja Frutillera, Caja Frutera, Ladrillo plástico), plus reviewed media
  under `wordpress/data/media/`.
- Print catalog: `docs/Catálogo Freeplast 2026.pdf` (source for product content and
  photography).
- Approved visual direction: v6 storefront shell and v7-A product page prototypes
  (immutable review versions at `https://mliu.site/freeplast/v6/` and
  `https://mliu.site/freeplast/v7/?variant=A`), reproduced by the current theme.

Absences future work must not fabricate: customer testimonials, case studies, customer
photos, stock levels, and customer-facing prices (none exist by design).

## Product Principles

1. Every path leads to a Quote Request — the request is the conversion; nothing pretends to
   be a sale.
2. More requests, better qualified: make requesting effortless and complete (product,
   quantity, contact, delivery) to beat the phone/WhatsApp/email baseline.
3. Speak wholesale to business buyers: volumes, real specifications, real photography; no
   consumer-retail patterns.
4. The recycled story is true — keep every material claim factual and verifiable.
5. Trust through reality: Spanish, real contact data, no invented prices, stock, or social
   proof.
