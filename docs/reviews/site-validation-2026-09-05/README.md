# Site validation — freeplast.mliu.site — 2026-09-05 (afternoon)

Read-only re-validation of the migrated staging site (adapter `freeplast-woo` 1.0.2, WooCommerce 11.1.0, Quotes for WooCommerce 2.13). Requested by the owner ("validate the site we created"). **No submission, no order creation, no code or configuration changes.** Test carts created during the browser/HTTP walkthrough were emptied afterwards; final order count equals the pre-validation baseline.

## What passed

| Check | Result |
| --- | --- |
| `npm test` (offline): 16 field assertions + 13 syntax/dependency/deployment checks | pass |
| Runtime state checks on staging (`wp eval-file verify-woo-state.php`) | **68/68 pass**, no mutations |
| MU mail blocker `freeplast-staging-mail` active (containment in place) | pass |
| Stack state matches the acceptance-review record: adapter 1.0.2 active, old `freeplast-catalog-quotes` 0.8.0 inactive, orders 58/59 (migrated) + 63/64/66/67/68 (technical) all `pending`, nothing new, nothing deleted | pass |
| Public routes 200: `/`, `/tienda/`, `/nosotros/`, `/contacto/`, `/cotizacion/`, search, privacy, product page; unknown product 404 | pass |
| `/datos-y-envio/` with empty cart → 302 to `/cotizacion/` (correct guard) | pass |
| Legacy aliases: direct hits resolve; stale slugs 301 to canonical (`/producto/pediluvio/` → `bases-plasticas-para-pediluvios`, `/producto/totem/` → `tote`) per `fpw_legacy_paths` | pass |
| `/tienda/` renders 17 products; no `$`/`woocommerce-Price-amount` on tienda or product pages; product page `<p class="price">` empty | pass |
| Home: v6 shell, "Productos a Cotizar" terminology, 8 featured cards with labelled add buttons, provisional-photo notice, no prices | pass |
| Variable product (Caja Universal Cerrada Color): 5-color select, variation resolves (`variation_id=54`), reset link appears, add succeeds with correct notice «… se agregó a Productos a Cotizar» (no "carrito" wording in journey labels) | pass |
| `/cotizacion/`: H1 + no-purchase/no-stock disclaimer, line shows "Color: Rojo", accessible quantity stepper (− disabled at 1), qty 1→5 persisted server-side, "Datos y envío" CTA | pass |
| `/datos-y-envio/` anonymous: dispatch defaults to "Selecciona una opción"; all required rows carry `validate-required` + `*`; adapter fields present (RUT, Giro, Con despacho select, address textarea, Mensaje optional) | pass |
| Conditional address (fields.js): hidden until "Con despacho"; when required the "(opcional)" hint is hidden and the `*` marker shown — toggling Sí/No behaves correctly on load, change and `updated_checkout` | pass |
| Draft retention observed: dispatch draft ("si") survived for the returning admin session — expected per adapter 1.0.2 draft feature | pass |
| Browser console on product/cart/checkout: no site errors | pass |

## New findings (not in the 2026-09-05 acceptance review)

1. **Checkout order-review table leaks the technical zero — «Subtotal $0» / «Total $0»** on `/datos-y-envio/` (three `woocommerce-Price-amount` elements, confirmed in clean anonymous HTML). The adapter suppresses `woocommerce_get_price_html`, `woocommerce_order_get_formatted_order_total` and `woocommerce_get_order_item_totals`, but the checkout review table renders cart totals directly and bypasses all three. Contradicts the documented quote-only boundary ("public HTML prices/totals are suppressed") on the one page customers see before submitting. Suggested fix direction: filter the checkout totals template / add a `woocommerce_cart_totals_*` or review-order template override, or CSS+aria handling consistent with the unpriced contract.
2. **Header basket line-count is missing.** The v6 contract and the retired implementation showed the distinct-line count next to the header link («Cotización (n)»). The Woo theme header is a static `wp:html` link (`parts/header.html` line 21) with no count logic in `functions.php`, `nav.js` or the adapter. Not documented anywhere as a deliberate cut. After adding a product, the only feedback is the on-page notice; the header never reflects basket state until the customer is on `/cotizacion/`.

## Known tickets confirmed live (no re-testing needed to keep them open)

- **#27** — eight Home product-card links (quick-view/gallery per card) have no accessible name (e12/e15/e18/… in the AX tree).
- **#28** — variable-product add button with no variation selected: `disabled` **property** false, no `aria-disabled`; only a CSS class (`disabled wc-variation-selection-needed`) signals the state. Clicking still triggers the Woo notice, so no data risk — but the state is invisible to assistive tech. Contrast and semantics unchanged since the review.

Also re-observed (documented, not new): ancillary Woo strings still say «carrito» inside `/cotizacion/` (table caption, stepper aria labels, remove button label); primary journey labels use approved terminology.

## Scope and limitations

- One browser walkthrough at 500 px viewport, admin session profile (admin toolbar visible on pages; anonymous behaviour covered separately by curl with clean cookie jars). **Not** a hardware/mobile acceptance, screen-reader pass, or Core Web Vitals measurement.
- No submission exercised end-to-end this round (the acceptance review already retained orders 66–68 as evidence; the 68 state checks + prior HTTP regression cover submission paths). Mail containment was verified active before any cart interaction.
- Real email delivery, restricted sales-role login, checkout-response interruption (#24/#26) remain open items from the acceptance review.
- Per repo policy: this report records observations only; **operational acceptance is a human call**.
