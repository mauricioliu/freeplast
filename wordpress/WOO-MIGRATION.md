# Freeplast — Woo migration and operating record

Date: 2026-09-05. Scope approved explicitly by the owner: replace the repo implementation and **https://freeplast.mliu.site/**, back up first, preserve catalog/media/history; **do not change freeplast.cl**. Decision: [ADR-0001](../docs/adr/0001-woocommerce-quote-only.md). No paid plugin licenses.

## Acceptance review update — 2026-09-05

[Post-migration review and evidence](../docs/reviews/woo-acceptance-2026-09-05/README.md): **not ready for operational acceptance**. Two simultaneous submissions from one anonymous session created duplicate orders **67/68**; the retained `ventas_freeplast` role lacks Woo order capabilities. Additional findings: silent quantity rollback on network failure, eight unnamed Home links, and the variable-product button's missing disabled semantics/low contrast. No fixes or new release were deployed in this review.

Offline checks remain 29; read-only runtime checks passed 68 after three new technical orders. Admin private-note persistence and unpriced HTML request-email rendering checked; real delivery, restricted-user login, physical mobile acceptance and performance measurements remain pending. See the review for exact scope rather than interpreting these checks as full acceptance.

### Merge record for findings WA-01 (#24) and WA-04 (#27) — 2026-09-05

The `ralph/issue-24` (one attempt, one request under concurrent submission) and `ralph/issue-27` (named links on the featured cards) branches were developed against pre-migration `main` and merged **into the retired implementation preserved under `legacy/`** (rename-detected; the retired suite `scripts/check.mjs`, its VERIFICATION/HANDOFF/BUILD-DECISIONS records and the deterministic `dist/` plugin ZIP — rebuilt from the merged legacy source via `scripts/rebuild-legacy-plugin-zip.mjs` — carry both fixes). The deployed Woo stack was **not** modified: the live duplicate-order (WA-01) and unnamed-link (WA-04) defects still need bounded Woo-side fixes — a checkout idempotency/claim integration in `freeplast-woo` for #24 and accessible card link naming in the Woo-rendered Home for #27 — each with its own Woo-native regression before either finding can be considered closed on staging. Issues #24/#27 were closed by the merge pipeline; reopen or file follow-ups if the Woo-side port is required (it is, per the acceptance criteria).

## Quantity-change feedback and recovery — issue #26 — 2026-09-05

Finding WA-03 of the [post-migration acceptance review](../docs/reviews/woo-acceptance-2026-09-05/README.md): in the Productos a Cotizar cart block, with 5 units on a variant, a + click with the connection disabled showed 6, disabled «Datos y envío» and then rolled the number back to 5 with **no visible explanation** — the customer cannot tell the change was not saved. Verified against the pinned WooCommerce 11.1.0 sources: the cart block's `changeCartItemQuantity()` POSTs `/wc/store/v1/cart/update-item` and, on failure, dispatches `receiveError(isApiErrorResponse(e) ? e : null)` — for a connection loss api-fetch's own `{code:'fetch_error'}` object reaches it, `setErrorData` records it, but **no notice is ever rendered**; the number simply jumps back. A server error response surfaces only as a transient snackbar that never states the quantity that actually remained saved.

Repo fix (theme **1.0.4**, pending deploy):

- **Mirror, never own (ADR-0001):** new `assets/js/cart-quantity-feedback.js` (enqueued only on the cart page) learns the customer's stated quantity from the block's own `experimental__woocommerce_blocks-cart-set-item-quantity` store event, reads settlement from the store's own selectors (`getItemsPendingQuantityUpdate`/`getCartItem`), and counts in-flight `update-item` requests read-only at the transport boundary. The count closes a verified gap: Woo aborts an in-flight request when a new one starts and the aborted request's cleanup clears the pending flag while its replacement still runs.
- **Deferred verdicts:** a verdict is never taken on the settling tick itself — every store/transport activity cancels and re-arms a short (50 ms) deferred evaluation, so the verdict is taken only after every request chain has fully drained. Rapid restatement (Woo's abort-and-replace) therefore never flashes a false failure, and a genuine failure is announced ~50 ms after the rollback.
- **Visible, announced, Spanish:** when a change settles below the stated quantity, a visible notice (role=`alert`, no focus move, existing palette only, error pair 16.3:1) reads «No se guardó el cambio de cantidad de «<producto>»: Productos a Cotizar sigue con <n> unidad(es). Revisa tu conexión e inténtalo de nuevo.» When it saves — first try or retry after reconnecting — the notice is replaced by a polite confirmation (role=`status`) stating the exact persisted quantity; retries cannot accumulate increments because the intent always comes from the block's own input. An empty offline regression proves the real store dispatches **no notice at all** on connection loss — the shipped notice is the only feedback.
- **Pending state cannot advance:** while the store reports pending operations, the «Datos y envío» CTA carries `aria-disabled="true"` with an explicit muted style and clicks are prevented **synchronously** — Woo's own `disabled` on that anchor does not block navigation and its `preventDefault` runs asynchronously, after navigation has begun. Once operations settle, the CTA is operable again: continuing explicitly with the persisted quantity never blocks. If the native pending disable cycle dropped keyboard focus, it is returned to the control the customer was operating; deliberate later focus is never stolen.
- **What is untouched:** Woo keeps owning quantities, sessions and persistence (reload and Datos y envío always show the persisted truth; the review table shows quantities per issue #29). No polling, no requests of its own, no parallel cart. Without the cart block's data store the script exits; without JavaScript there are no quantity updates to report. If the cart block ever migrates to the new interactivity-API store, the script exits there too — a documented follow-up, not a regression.
- Offline regression 76 → **86 local assertions** (theme contract: cart-page-only enqueue, store-event/endpoint markers, alert semantics, Spanish texts) and 33 → **68 syntax/dependency/deployment checks**: the REAL pinned cart-block store (`scripts/vendor/wc-blocks-data-11.1.0.js`, byte-identical from the pinned 11.1.0 zip, provenance + SHA-256 sidecar) is driven in Node, with only the `window.wp.*` plumbing stubbed, through successful change, connection loss, server error response, retry-after-recovery, abort-and-replace, pending-advance blocking, keyboard focus restore, and removal-while-pending — exercising the exact store code shipped to browsers, not a simulated component. `verify-woo-http.py` (staging-only, not part of `npm test`) now asserts the delivered `/cotizacion/` HTML carries the bridge script.
- **Remaining:** package + deploy to staging; then the operator browser pass — with a controlled test selection (restore connectivity after each failure simulation, empty the test cart afterwards): successful change, network-loss + visible notice, retry saving the desired quantity, reload and Datos y envío showing the persisted quantity, keyboard walkthrough without focus loss, and narrow-width/desktop visual review. Physical mobile acceptance and screen-reader verification remain pending human Gate 3 work — emulation does not substitute them.

## Header Productos a Cotizar line count — issue #30 — 2026-09-05

Finding 2 of the [2026-09-05 afternoon site validation](../docs/reviews/site-validation-2026-09-05/README.md): the migrated Woo header renders a static `wp:html` Productos a Cotizar link with no count logic in the theme, `nav.js` or the adapter — the v6 contract and the retired implementation showed the distinct-line count on every page.

Repo fix (adapter **1.1.0**, theme **1.0.1**; **pending deploy** — the live stack still runs 1.0.2/1.0.0 with the static link):

- `freeplast-woo` owns the Woo-boundary value: `fpw_cart_line_count()` returns `count( WC()->cart->get_cart() )` — Woo keys every cart entry by product + variation + attributes, so counting entries is exactly the contract-v6 definition (variants/colours are separate lines; quantities never add lines). Zero when Woo or the cart is unavailable. No new session, table or service — the count reads the cart (ADR-0001).
- The adapter registers `span.fpw-basket-count` under `woocommerce_add_to_cart_fragments`, so classic AJAX adds from catalog cards update the header through Woo's own add-to-cart response (verified against the pinned WooCommerce 11.1.0 `WC_AJAX::add_to_cart`/`add-to-cart.js` fragment application).
- The theme part stays static `wp:html`; the count token `{{FREEPLAST_BASKET_COUNT}}` is resolved at render time by a second `render_block` filter (the established `{{FREEPLAST_THEME_URL}}` mechanism), so **every public route server-renders the current number on first paint, including without JavaScript**. Both header surfaces carry it: desktop island link and mobile menu sheet. Documented empty state: the link always shows the number — `(0)` when empty — so there is no stale-value flash; with the adapter inactive the link degrades to no number.
- New theme `assets/js/basket-count.js` bridges the one surface fragments cannot see: the native cart block mutates quantities/removals/empty through its own Store API data store, so the script subscribes to `wc/store/cart` and re-renders the header count from `getCartData().items.length` — the same distinct-lines definition. It renders nothing until the store has resolved its cart (no flash over the server value) and polls/fetches nothing.
- Offline regression grew from 16 to **30 local assertions**: line-count semantics (absent Woo, empty cart, quantities-not-lines, variants-as-lines), the fragment contract (selector + live value), the header-markup contract (both surfaces carry span + token) and the `render_block` resolution (live count, `(0)` empty state, byte-identical pass-through). Syntax/dependency/deployment checks grew 13 → 14 (the new JS file).
- **Remaining:** package + deploy to staging, then a browser regression on staging — add from card and ficha, quantity change, remove and empty, header matches Cotización after each mutation and after reload, no console errors — with a test cart emptied afterwards; visual review (narrow width) remains the pending human Gate 3 step.

## Checkout review table without amounts — issue #29 — 2026-09-05

Finding 1 of the [2026-09-05 afternoon site validation](../docs/reviews/site-validation-2026-09-05/README.md): the classic checkout's order-review table rendered the technical zero on `/datos-y-envio/` — line subtotal, Subtotal and Total appeared as `woocommerce-Price-amount` «$0» in clean anonymous HTML. The adapter's `woocommerce_get_price_html`, `woocommerce_order_get_formatted_order_total` and `woocommerce_get_order_item_totals` filters never reach this table: Woo's `checkout/review-order.php` template prints cart totals directly, and the theme's `display:none` rule only hid them visually while leaving the amounts in the DOM — the approach the finding rejects.

Repo fix (theme **1.0.2**, pending deploy — the live stack still hides the amounts with the old CSS rule):

- Render-origin fix by template override `woocommerce/checkout/review-order.php` (Woo's sanctioned override path, same as the existing `thankyou.php`): the table renders products, chosen options and quantities — name through the native `woocommerce_cart_item_name` filter, options through `wc_get_formatted_cart_item_data`, quantities as ×N — and calls **no price renderer at all**, so no `$0` or `woocommerce-Price-amount` can exist in the delivered HTML. The classic checkout re-renders this same template on every `update_order_review` AJAX pass, so first paint and refreshes are covered by the one origin. Verified against the pinned WooCommerce 11.1.0 template (version 11.0.0) — its Subtotal row uses `wc_cart_totals_subtotal_html()`, which has no filter, so the template is the only origin-level handle.
- Totals zone, documented decision: a single «Total → Por cotizar» row — the same wording the adapter returns for formatted order totals, coherent with the no-purchase/no-stock disclaimer; the misleading «Subtotal» column header is gone (columns are now Producto/Cantidad). Adapter filters were deliberately not extended: they cannot reach the subtotal row, and layering them beside the template would suggest suppression coverage the template alone already provides.
- The `display:none` hiding of `.product-total`/`tfoot` was removed from `assets/css/woo.css` — nothing is hidden because the DOM itself carries no amounts. Theme bumped to **1.0.2** for the stylesheet cache-bust.
- The technical zero stays in Woo's administration and APIs per the documented limit; this fix claims the public checkout page only. Checkout form flow untouched: the native product/class/visibility filters and review-table actions are kept, so conditional-fields behavior and the `update_order_review` draft flow are unaffected.
- Offline regression 30 → **53 local assertions** (override exists and calls no price renderer; offline render with a controlled fake cart — one simple product + one colour variant — yields names, options, quantities, «Por cotizar» and zero `$0`/`woocommerce-Price-amount`/`product-total`/«Subtotal»; no CSS hiding remains). Syntax/dependency/deployment checks 14 → **15** (the new PHP template). The staging HTTP regression (`verify-woo-http.py`, not part of `npm test`) now asserts the delivered `/datos-y-envio/` HTML contains no `$0` and no `woocommerce-Price-amount` while its cart holds a simple product and a colour variant.
- **Remaining:** package + deploy to staging; anonymous HTML re-check (no `$0`/`woocommerce-Price-amount` on `/datos-y-envio/`) plus the existing checkout regression with a test cart emptied afterwards and no real requests submitted; visual review of the reworded totals zone stays with the pending human Gate 3 step.

## Variable button with accessible state — issue #28 — 2026-09-05

Finding WA-05 of the [post-migration acceptance review](../docs/reviews/woo-acceptance-2026-09-05/README.md): on the Caja Universal Cerrada Color sheet, the «Agregar a Productos a Cotizar» button carried only a visual `disabled` class before a colour was chosen — no `disabled` attribute, no `aria-disabled` — so axe measured its dimmed 16 px white text at **3.51:1** and evaluated the control as available. Verified against the pinned WooCommerce 11.1.0 sources: `variation-add-to-cart-button.php` (base 10.5.2) renders the button with **no state at all**, and `add-to-cart-variation.js` toggles only classes (`wc-variation-selection-needed` / `wc-variation-is-unavailable` on `.disabled`), intercepting blocked clicks with `window.alert` — the accessibility tree never learns the state.

Repo fix (theme **1.0.3**, pending deploy):

- **Render-origin fix by template override** `woocommerce/single-product/add-to-cart/variation-add-to-cart-button.php` (Woo's sanctioned override path, same as `review-order.php`/`thankyou.php`; every native hook, the quantity input and the hidden `add-to-cart`/`product_id`/`variation_id` inputs are kept). The override ships the initial unavailable state at the origin — the same classes Woo's own variation form applies on init and on selection-clear — plus `aria-disabled="true"` and `aria-describedby` pointing at a visible instruction that names the product's real variation attributes («Selecciona Color para agregar este producto a Productos a Cotizar.»), so the state is honest on first paint and without JavaScript.
- **Never a real `disabled` attribute**: it would dead-end the no-JS flow (selects would work but the button could never submit) and removes native focus/click guidance; `aria-disabled` keeps the button focusable while truthfully unavailable, and Woo's server validation keeps rejecting submissions without a required variation (the regression already asserts the Store API 400).
- **State mirror, not new rules:** new `assets/js/variation-button-state.js` (enqueued only on product pages) reads the classes Woo's variation form keeps toggling — via a class-attribute `MutationObserver`, so jQuery class changes are seen — and keeps `aria-disabled`, `aria-describedby` and the instruction in step: initial/cleared → unavailable + instruction; valid variation → explicit `aria-disabled="false"`, instruction hidden; unpurchasable combination → unavailable + «Esa combinación no está disponible…»; form submit → `aria-busy="true"` + pending instruction until the page navigates (reset on bfcache `pageshow`). Availability rules, validation and the simple-product button are untouched (no `simple.php` override).
- **Contrast, documented distinction:** the inactive state is exempt from WCAG 1.4.3 as a control that genuinely cannot operate — that exemption is now true because the semantics say so, and it is claimed explicitly rather than by relabelling. The theme still replaces Woo's 3.5:1 opacity blend with an explicit muted style (`--fp-muted` on `--fp-tint`, **4.85:1**, AA-passing even though exempt; hover cannot reactivate the look), and the enabled button keeps white on `--fp-blue` (**14.74:1**, hover green **7.45:1**). The instruction text is 11.37:1. Keyboard focus keeps the global visible outline; keyboard users can reach every select, the button and Woo's native «Clear» control.
- The referential-photo notice (`fp-photo-note`) and product availability information are not modified, and nothing promises image–colour correspondence.
- Offline regression 53 → **76 local assertions** (override exists and keeps every native surface; no simple.php override; offline render of a variable product yields the initial classes, `aria-disabled`, the describedby link, the attribute-naming instruction, the name, the quantity input and no real `disabled` attribute). Syntax/dependency/deployment checks 15 → **33** (the new PHP template and JS file, plus behavioral node coverage of the state machine across initial / selected / cleared / unavailable / pending). `verify-woo-http.py` now asserts the delivered ficha HTML ships `aria-disabled`, the describedby instruction and no false enable.
- **Remaining:** package + deploy to staging; re-run the browser axe pass on the variable ficha (WA-05 should clear; review the remaining `incomplete` results without hiding them), plus a keyboard walkthrough (select colour, add, verify Productos a Cotizar shows exactly that variant and quantity, clear selection restores the state) and a test cart emptied afterwards. Screen-reader and physical-mobile acceptance remain pending human Gate 3 work — emulation and screenshots do not substitute them.

## Current implementation

- Existing WordPress 7.1 / PHP 8.3 / MariaDB stack, `/opt/freeplast-wordpress` on SSH alias `openclaw`; Nginx/hostname unchanged.
- WooCommerce **11.1.0**, Quotes for WooCommerce (TechnoVama) **2.13**, `freeplast-woo` **1.0.2**, existing standalone Freeplast block theme adapted as **1.0.0**.
- Dependencies pinned by URL and SHA-256 in `woo-dependencies.json`; no commercial extension installed.
- Woo owns products, variations, guest sessions, cart quantity mutations, checkout, request persistence, notes and Orders administration. JSON is now import/reference material, **not** an ongoing synchronizer.
- `/tienda/` and `/producto/<slug>/`: native Woo catalog and products.
- `/cotizacion/`: native **Cart block**, titled **Productos a Cotizar**.
- `/datos-y-envio/`: native **classic checkout**, titled **Datos y envío**. Deliberate choice: quote extension's address options are not equivalent in Checkout Blocks.
- Small adapter: fiscal/dispatch fields, local validation, terminology, request-only side-effect guards, old routes, unpriced request emails. It uses Woo's existing checkout-review hook/session for form drafts, not a separate session or persistence service.
- Legacy code moved to `legacy/`; original installed plugin remains **inactive** on staging. Do not reactivate or sync JSON into the migrated site.

## Preserved data

- **17 products converted in place**, retaining IDs, slugs, attachment relationships, image files and original `_fp_*` metadata. Native Woo attributes expose the existing specifications; **10 color variations** created.
- Two original private `fp_quote` records remain, including every `_fpq_*` field. Their Woo counterparts: original **40 → order 58**, original **39 → order 59**. Old references retained; mapped metadata compared field-for-field by runtime checks.
- Old status/history/delivery-provider details remain in imported metadata; they are not translated into a new six-state workflow.
- Existing pages' pre-migration contents saved in metadata when replaced. Legacy URL aliases retained. Theme branding, photography, v6 shell and product direction reused; **no new visual approval implied**.
- Technical Woo price value `0` enables native purchasability internally. It is **not a commercial offer**. Public HTML prices/totals, priced structured-data offers and quote-email amounts are suppressed. Woo APIs/admin can still expose the technical zero; this is not a claim that every API contains no amount fields.

## Quote-only boundary

- Guest intake; no required registration, payment, automated invoice, checkout stock hold or stock reduction. Gateway restricted to the extension's no-charge quote gateway. Payment URL redirects to contact. Requests remain unpaid.
- Required: name, phone, email, company, RUT, giro and dispatch choice. Dispatch requires a nonempty full address; textarea prompts street, number, commune and region. No geocoding/completeness certification or automatic route distance. RUT is required, not checksum-validated; phone normalization is not yet implemented.
- Initial pending order label: **Solicitud recibida**. Sales works in Woo **Pedidos** and native private order notes. Other Woo statuses and extension completion controls still need operator review; do not treat them as priced-document functionality or payment authorization.
- Submitted details stored separately from editable core contact details. Local fiscal/dispatch metadata is displayed in Woo administration; a dedicated correction UI is not added.
- Quote-request emails reuse extension events with an unpriced template. Ordinary order/invoice and priced-quote emails disabled. **Independent MU plugin blocks all `wp_mail` on staging**; it records a count, no recipient/payload log. Actual delivery is not tested/enabled.

## Backups and recovery

Private server paths (not committed; contain personal data):

1. **Before migration:** `/root/freeplast-wordpress-backups/20260905T111748Z` — database + full WordPress files + checksums; isolated restore rehearsal recovered 17 original products before any replacement.
2. **After migration:** `/root/freeplast-wordpress-backups/20260905T114819Z` — paired backup; isolated restore matched **17 catalog products / 4 Woo orders / 2 original requests**. This captures adapter 1.0.1; subsequent 1.0.2 extends draft retention only.
3. **Before acceptance-test submissions:** `/root/freeplast-wordpress-backups/20260905T124710Z` — paired backup of adapter **1.0.2**; isolated restore matched **17 / 4 / 2**. Predates technical orders 66–68; not a post-review database snapshot.

`infra/backup.sh` now counts both architectures and Woo orders in its rehearsal, then tears down only its temporary restore project. It does not replace the live volumes.

Explicit paired rollback, as root on `openclaw`:

```bash
bash /opt/freeplast-wordpress-src/infra/restore-woo-backup.sh \
  /root/freeplast-wordpress-backups/20260905T111748Z --execute
```

This verifies hashes and staging hostname, enables maintenance, restores database **and** files, then flushes caches/routes. Failure leaves maintenance enabled for investigation. Do not restore one component alone. A rollback discards newer live changes; take a fresh paired backup first. Live rollback itself was **not executed**; isolated restorations were.

## Reproduce checks / package / release

```bash
npm test                         # offline only; no server or submissions
npm run woo:package              # wordpress/.build/woo-release/
```

If the project PHP tool is unavailable, set `PHP_BINARY` to an absolute PHP executable. Current offline result: **86 local assertions (checkout fields + header line count + unpriced review table + variation button state + quantity-feedback contract, issues #30/#29/#28/#26) + 68 syntax/dependency/deployment checks**, the latter including the real pinned cart-block store scenarios for #26.

Read-only runtime checks after the bundle is copied to staging:

```bash
cd /opt/freeplast-wordpress
docker compose run --rm -T cli wp eval-file /bundle/verify-woo-state.php
```

Observed: **62 state checks passed** (catalog/variation counts, historical metadata and lines, native pages, request/payment/stock guards, required fields and containment count).

The HTTP regression **creates one clearly marked test order**; it is not part of `npm test`:

```bash
python3 wordpress/scripts/verify-woo-http.py --execute-staging
```

Never run it on production or while staging mail containment is disabled. Recorded technical orders **63 and 64** remain for evidence, with `example.invalid` addresses. Order 63 exercised submission but exposed a confirmation-template issue; after that fix, order 64 passed the full regression. Do not mistake these for customer requests. The subsequent acceptance review retained technical order **66** (normal regression, plus one private audit note) and **67/68** (same-session concurrent duplicates); all use synthetic `example.invalid` contact data. Never process/resend/delete them automatically.

Release procedure: package locally; upload bundle files to `/opt/freeplast-wordpress/bundle/` and scripts to `/opt/freeplast-wordpress-src/infra/`; on the server run:

```bash
FREEPLAST_BACKUP=/root/freeplast-wordpress-backups/<verified-stamp> \
  bash /opt/freeplast-wordpress-src/infra/deploy-woo.sh
```

The migration is resumable: marked products/pages/orders are skipped, not re-synchronized. It still enforces quote-only site options. Do not use it to overwrite later Woo catalog edits. Old `infra/deploy.sh` deliberately refuses execution; older build/target/verification documents are marked historical.

## Observed integration results and limits

- Anonymous HTTP: missing color rejected; simple product quantity updated **70 → 140**; **5 red Universal** units; RUT missing rejected; dispatch without address rejected; successful request confirmation with exact quantities and cleared cart. Server records unpaid, no stock reduction, empty destination for no dispatch.
- Browser: hydrated native Cart; changing quantity to **140** and proceeding displayed **×140** at checkout. On adapter 1.0.2, name, giro and optional message survived checkout → cart → checkout without submitting. Native remove announced removal, disabled continuation during the operation and ultimately left **0 items**; browser returned to Home. Conditional address toggles hidden/required correctly; optional marker removed when required. No checkout JS errors observed (only jQuery Migrate informational log).
- Privacy copy corrected to quotation purpose and correct policy link. Provisional-photo notice restored. Repeated singular/plural search normalized for `cajas` only, not an unsupported semantic synonym engine.
- Only selected desktop rendering observed. **No hardware/mobile/keyboard/screen-reader approval, fresh complete axe audit or Core Web Vitals measurement.** Do not call UI validated.
- **Updated by acceptance review:** quantity transport failure and two concurrent HTTP submissions were exercised; recovery feedback and idempotency failed as detailed above. Browser double-click behavior, actual checkout-response interruption, real email delivery, restricted-sales-role acceptance and full operator workflow approval remain untested/unapproved. HPOS declaration is not a two-mode runtime certification; this review confirmed live HPOS is disabled.
- Provisional photos, unknown commercial minimums/steps/specifications, response-time promises and outstanding Q4–Q10 remain owner decisions. Migration does **not** close UX-01–UX-17 wholesale. Some native ancillary strings still say “carrito”; primary journey labels use the approved terminology.
