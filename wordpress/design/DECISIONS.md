# Freeplast — frozen v6 design contract decisions

Recorded 2026-09-03 (issue #12). This file freezes the approved visual
contract the WordPress theme implements. `design-tokens.json` is the
machine-readable extraction; this file records the sources, the hashes and
the adaptation decisions. The obsolete v5 editorial direction
(`propuesta-editorial/`, `design-system/freeplast-editorial/`,
https://mliu.site/freeplast/v5/) is **rejected** — none of its rules
(Newsreader display face, Outfit UI face, square geometry, hairline-rule
paper grid) may be used by the theme.

## Approved sources (immutable, hash-frozen)

| Reference | Repository prototype | Published | SHA-256 |
| --- | --- | --- | --- |
| v6 storefront | `propuesta-textos-originales/index.html` | https://mliu.site/freeplast/v6/ | `c464ac476f52a7cba2dc4c34ee2dbbbad0a3092a02e14f6570b2b6a76e08b855` |
| v6 storefront styles | `propuesta-textos-originales/styles.css` | (same) | `fd2dc626cecb8224c373444cbc991055d7c73ceef32e9cda1f68eb1c19c84e86` |
| v6 storefront script | `propuesta-textos-originales/script.js` | (same) | `95380ef9171261a043d36c3eb4dcf97be3aeaf7efde0b6a5a4fea2698748329d` |
| v7 product page (variant A) | `product-page-prototype/index.html` | https://mliu.site/freeplast/v7/?variant=A | `a3ecf4186c43d349d546538cb2860036eef7f89935eac223432547e7d413efa3` |
| v7 product page styles | `product-page-prototype/styles.css` | (same) | `21b997dc70604a346f6c212dccca63017932b636807747694427765ef2d27247` |
| v7 product page script | `product-page-prototype/script.js` | (same) | `0bf3ffcd2dccc0a50a3890559d35a856d7a604c520c1270260cba2c1e3fff0c4` |

`npm test` recomputes every hash on each run: drift in an approved
prototype is a failure, not a silent decision. Variants B/C of the product
page, prototype switching, fake submission and review-artifact links are
not part of the contract.

## Governing decision — “v6 tokens, v7-A structure”

- **Storefront**: the v6 flat direction — Manrope, preserved Freeplast
  palette (blue `#100090` primary, green `#306020` accent), soft radii,
  island navigation, tint bands, dark footer. See `design-tokens.json`.
- **Product page**: approved v7 variant A hierarchy — breadcrumb, gallery +
  summary, description, quick specs, quote action, specification table,
  related products — presented with the same v6 site chrome and tokens.
- **Mobile-first**: designed around ~412 px; adaptation only through
  `min-width` media queries (768 / 1024 px in the theme).

## Theme adaptations (recorded, not drift)

The WordPress theme is a server-rendered block theme, so a few prototype
behaviors adapt by design. Each adaptation is v6-derived:

1. **Primary control color is blue.** v6's `.btn` is `#100090` with a
   `#0b078c` hover; green (`#306020`) is the accent (kickers, facts,
   mission/vision labels), available as the `.btn-green` variant. The
   earlier green-rectangular-CTA styling came from the obsolete v5 TARGET
   contract and is corrected in this slice.
2. **Sticky island, not fixed.** The v6 floating pill island
   (`border-radius: 9999px`, `rgba(255,255,255,.82)` glass + backdrop blur)
   is kept, but `position: sticky` replaces `position: fixed` so pages do
   not need prototype hero top-padding compensation.
3. **Compact catalog cards use the nested-radius principle.** v6 pads cards
   above their nested radii; WordPress cards embed quantity-chooser forms,
   so they use the v6 `--r-lg` (8 px) radius and compact paddings instead
   of `--r-3xl` (24 px) cards.
4. **One-page scroll behaviors are not ported.** Scroll-spy, scroll-reveal,
   the progress bar and the pointer/momentum hero of the single-page
   prototype have no multi-page equivalent; the only progressive script is
   the burger sheet (theme) and the basket enhancement (plugin).
5. **Dark surfaces use the v6 dark tokens.** Footer and dark bands use
   `#181818` (`--dark`) with `--muted-dark` text.

## Pages governed by the contract

Home (hero, concise Nosotros, eight Featured Products, Cotiza Online basket
summary/CTA, concise contact), Nosotros, Tienda (+ category filter), product
detail (v7-A), Cotización (the sole quotation surface), Contacto (details +
one CTA into Cotización, no inquiry record), Política de privacidad, search
and 404 — each with the shared island header, basket widget and footer.

## Scoped supersession — A · Directa quotation chrome (2026-09-08, issue #41)

The owner selected **A · Directa** from the quote-journey prototype (commit
`785e65b`, branch `prototype/quote-journey-20260908`) and required an
**identical** visual result for the quotation journey (issue #40). This
section records the first implemented slice — the **shared chrome** — and
which v6 presentation decisions it supersedes. Historical sections above
stay as written; they are superseded only where stated here.

**Superseded for the quotation journey's shared chrome** (header, mobile
menu, help dialog, footer, base typography): the v6 pill island navigation
(`border-radius: 9999px` glass capsule, uppercase letter-spaced links), the
v6 burger sheet, the v6 dark footer band and its three-column contact grid.
**Still governing** everywhere else: v6 tokens/structure for Home/corporate
page bodies, the v7-A product page, catalog cards (until #42–#44), the
Quote Basket presentation (until #45) and the details form (until #46/#47),
ADR-0001 (Woo + free Quotes + minimal adapter, classic checkout, Cart block)
and every business/safety rule.

**A · Directa chrome contract (frozen values):** local OFL-licensed Manrope
variable font (200–800), body 16 px/1.6; compact persistent header bar 76 px
mobile / 84 px ≥1000 px, white `rgba(255,255,255,.97)` with hairline
`#dedfe6` bottom border; shell `min(1200px, 100% − 32px)` (48 px gutter
≥600 px); brand 18→23 px blue `#100090`, weight 800; desktop nav 14 px/650
with 2 px underline current marker; Productos a Cotizar soft-blue pill
`#eeedf8` with `#100090` count badge; 44 px icon button menu trigger (hidden
≥1000 px); native `<dialog>` menu (full-height 12 px inset mobile, 400 px
right sheet ≥600 px) and 520 px help dialog with `#60626d` details; footer
border-top band with brand + tagline + help control.

**Narrow demo→service adaptations (per #40 §2, documented not silent):**

1. Review-only elements excluded: prototype notice bar, comparison bar,
   Escenarios/state inspector, their reserved spacing, and every demo
   disclaimer. No production query parameter resurrects them.
2. Real destinations replace prototype routing: brand → Home `/`,
   Catálogo → `/tienda/`, Contacto → `/contacto/`, Productos a Cotizar →
   `/cotizacion/`, privacy → `/politica-de-privacidad/`; help keeps the
   reference guidance with the authoritative channels (phone/WhatsApp,
   email, schedule) as real links.
3. **Nosotros** and **Contacto** remain in the mobile menu to preserve company
   destinations. Desktop retains A's exact Catálogo/Cómo cotizar/Contacto slots.
   The additional mobile rows need owner review; they are not silently accepted
   visual parity.
4. A **Política de privacidad** link joins the footer (the prototype's
   privacy surface lived only inside the checkout form's dialog).
5. The count badge keeps its styling wrapper (`.count`) around the adapter's
   `span.fpw-basket-count` so Woo's native add-to-cart fragment replacement
   cannot strip it; the accessible count is the link's readable text
   («Productos a Cotizar 2»), not a separate aria-label that a fragment
   swap could leave stale.
6. The sticky bar clears WordPress's fixed admin toolbar natively above
   600 px (A assumes no toolbar).
7. A's line-height 1.6 applies to shared chrome only. Corporate body line-height
   remains 1.5; loading Manrope and disabling synthesized font weights apply
   globally. The menu's specific geometry overrides the generic help-dialog
   rules, including the 320px brand treatment from A.

**Not yet ported (later slices own them):** the mobile selection dock and
its body padding, the skip link, product cards, catalog toolbar, basket and
details presentations. Slice #41 does not remap them.

**Evidence boundary:** offline suite only in this slice; the disposable
stack fixture extensions (17 reference products, Cart-block `/cotizacion/`,
classic checkout, chrome journey checks) are implemented but **not executed**
— no server/browser was run. Side-by-side visual comparison at 412/1440 px
and human PC/phone review remain open.

## Scoped supersession — A · Directa catalog cards, discovery and product sheet (2026-09-08, issues #42–#44)

Second batch of the #41–#48 implementation. Builds on the #41 chrome section
above; A · Directa (`785e65b`) stays the sole visual authority.

**Superseded presentation (quotation journey surfaces):** the v7 card grid
(`.fpcq-cards`/`.fpcq-card*` incl. the card CTA disclosure), the archive
toolbar (wp:search block + category link paragraph) and the v7-A product page
composition are retired. Woo's native machinery stays authoritative
throughout: `wc_product_class` cards, `woocommerce_loop_add_to_cart_link`
filter (adapter), native quantity input + `add_to_cart_button` anchor +
fragment projections, native archive query/hooks, native breadcrumb/gallery/
variation form/add-to-cart/related query.

- **#42 cards** (`themes/freeplast/woocommerce/content-product.php`): A card
  structure — photo (94px column mobile / full-width ≥600, aspect 1 / 1.35 /
  1.55), category kicker, title link, essential fact (Medidas minus
  «(exteriores)» → Material → Peso propio → «Ficha técnica por confirmar»),
  simple controls (native qty + Agregar), variable card = color-dot hint +
  «Elegir color» into the native sheet (never a card picker), green
  added-state bar («✓ N unidades agregadas» + Quitar) flush to the card
  bottom. Grid: 1 col / 2 @600 / 3 @1000, gaps 14/18/22; 359px tightening.
  The adapter's pill copy moved to A wording (unit surface unchanged).
  −/+ stepper buttons (44px, tab-excluded) wrap Woo's own input
  (`loop-add-to-cart-quantity.js`); no-JS keeps the native input. Mobile
  selection dock (`fpw-selection-dock`) renders on catalog/product routes,
  safe-area positioned, fragment + Store-API bridged, body padding reserved.
- **#43 discovery** (`themes/freeplast/woocommerce/archive-product.php` +
  `loop/result-count.php` + `loop/orderby.php`): A catalog intro, search form
  posting the native `?s=` route, category filters as the canonical term
  routes with live counts and aria-current, result count «N productos» /
  «N resultados para «q»», ordering limited to Destacados (native featured
  term, then menu order/title) and Nombre A–Z (native title), A no-results state (catalog return + real
  help dialog). Block templates drop their duplicate search blocks.
- **#44 product sheet** (`content-single-product.php` + `single-product/
  related.php`): breadcrumb (Catálogo › …, no Inicio crumb), category eyebrow
  + title, gallery in the tint surface with referential/pending caption,
  summary (Detalles que importan., description with the neutral pending-copy
  rule for review-dependent prose, spec grid with «Por confirmar» cells),
  native variation form + add-to-cart with A presentation, no-purchase note,
  per-product added state (aggregate across colors, fragment-refreshed),
  «Ficha técnica completa» disclosure (pallet = packaging, never a minimum),
  related block («Sigue completando tu selección», native rules, A cards).
  Named color buttons (`product-color-options.js`) drive the single native
  `attribute_color` / `attribute_pa_color` select exclusively, sync both ways
  (including native availability updates), and hide the real table row only
  when enhanced. The native reset link stays reachable.

**Native integration (lead corrections):** stepper buttons are keyboard
reachable, use the input's own stepping rules, and reject invalid whole-unit
adds rather than silently truncating them. The color select remains the sole
submitted value; named controls reflect its labels and disabled options. Todos
uses a distinct native total (category sums can double-count). `inc/catalog.php`
projects A ordering onto native query clauses: featured membership first,
menu-order/title ties; A–Z title folding; accent/case folding without a second
catalog/search index. Query, category and ordering survive navigation/pagination.
Ordinary content searches use the native content template. Complete native
fragments update cards, variable-parent totals, detail and dock even when the
AJAX request has no page conditional. Transport uncertainty uses truthful
feedback rather than an assertion that nothing was saved. The gallery's
migrated pending-photo metadata remains honest; real image replacement works.
The native password boundary is preserved; A uses the short excerpt and keeps
its technical disclosure in the summary column. High-specificity scoped card
rules override Woo's floats/widths, without styling unrelated corporate bodies.

**Evidence boundary:** offline suite only (see WOO-MIGRATION.md); stack
fixture/journey extensions for #42–#44 are implemented but unexecuted.
Rendered side-by-side comparisons at 412/1440 and owner PC/phone review
remain open.

## Scoped supersession — A · Directa basket, details form and confirmation (2026-09-08, issues #45–#47)

Third batch. Builds on #41–#44; A · Directa (`785e65b`) remains the only
visual authority and ADR-0001 the only architecture: the REAL Cart block,
the ONE classic checkout form and the adapter's durable
request/reference/recovery machinery are untouched in ownership.

- **#45 basket** (`scripts/woo-cart.html` = canonical page markup consumed by
  both the fixture and the staging migration; `page-cart.html` drops the
  1152px shell): A steps/heading/lead, A rows (photo 70/90px, variant line,
  native block quantity selector styled to A stepper), sidebar as A summary
  card with a native-truth lines/units projection
  (`basket-count.js`, inserted above the native proceed block, store-driven),
  A empty state with catalog return + real help. The block's technical zero
  totals and per-line prices are hidden at the stylesheet level
  (`!important`, block-class vocabulary verified against the pinned build);
  the dock is excluded from the cart route. Settlement semantics untouched
  (issue #26 script + new staged-body store scenario with multi-chunk
  release).
- **#46 details** (`woocommerce/checkout/form-checkout.php` override +
  `assets/js/checkout-form.js`, adapter `billing_fp_dispatch` → native radio
  si/no (one value source; fields.js 1.0.4 moves the existing native radios into their own labels) + native
  first/last row classes for A's two-column pairs): ONE classic form keeps
  names/nonces/hooks, the hidden attempt (adapter hook inside the form), the
  draft session mechanism (`get_value` path), and native validation. The
  native review table renders inside the mobile-disclosure summary at Woo's
  own `.woocommerce-checkout-review-order-table` fragment target
  (update_order_review refreshes it in place); the native payment/submit
  block renders at the form foot (payment fragment target preserved —
  verified against pinned WC_AJAX/checkout.js). The enhancement builds a
  linked, focused A error summary FROM Woo's native error group, attaches
  per-field messages (aria-describedby/aria-invalid) and keeps values; the
  native group remains the DOM source of truth (hidden only while
  superseded; no-JS shows it verbatim). No consent checkbox, registration,
  geocoding, new field rules or demo regexes.
- **#47 submission/confirmation**: busy submit semantics
  (aria-busy + spinner + «Enviando…» + double-activation guard) over the
  native button (whose literal `woocommerce_checkout_place_order` identity
  is untouched); ambiguous transport failures are reworded honestly (the
  attempt may already be saved; retry keeps identity) while definite
  server rejections pass through verbatim. `checkout/thankyou.php` renders
  A's confirmation from the STORED order only: real FP-YYYY-NNNNNN
  reference, persisted lines/options/quantities, stored dispatch fact,
  sales-review next steps and catalog return (help remains in shared chrome).
  Invalid/absent order explicitly cannot confirm receipt. Native formatted
  item metadata, stored contact email and historical address fallback retain
  truth without inventing dispatch/no-dispatch. No demo reference, timer,
  delivery guarantee, price, purchase or stock-reservation promise.

**Documented narrow adaptations:** dispatch radios are Woo-native radios
(single `billing_fp_dispatch` name, values si/no — no second input);
`Destacados`/«Continuar con mis datos» labels ride Woo's own extension
options (fixture updated; production option parity is a deploy-time step);
the cart-page summary reconciles late native block DOM and hides when empty;
Editar remains outside the collapsed disclosure and waits for the existing
native draft/review request to settle with current fields. Errors preserve
the complete cause and deferred native inline timing; non-field/transport
outcomes never assert definitely unsaved. Busy tracks native checkout AJAX,
not unrelated review events or vetoed submits. Removal focus uses DOM row
identity, while the native removal hook supplies actual line keys/options for
feedback: native optimistic speech is progress, absent settled lines get
success, uncertain acknowledgement shows the available native quantity and
asks reload rather than asserting backend non-persistence. No new mutation
or persistence API. Required hint + native aria-required, original field
names/values, native hooks/priorities and server rules remain authoritative. Rendered matched-state comparisons and owner PC/phone
review remain open; hydrated-block scenarios documented for the authorized
browser run (`/tmp` scenario note, mirrored in the batch reports).
