# Freeplast WordPress — build decisions

**2026-09-05: superseded architecture.** Owner approved direct replacement of the repository implementation and `freeplast.mliu.site` with WooCommerce + free Quotes for WooCommerce; `freeplast.cl` remains untouched. See [ADR-0001](../docs/adr/0001-woocommerce-quote-only.md) and [WOO-MIGRATION.md](WOO-MIGRATION.md). Entries below are historical, not instructions to restore the private basket or JSON-sync authority.

Owner-approved architecture (2026-09-03, RUNBOOK.md) applies: WordPress +
standalone block theme (`freeplast`) + one private plugin
(`freeplast-catalog-quotes`), no WooCommerce, request-only v1.

This file records the decisions taken per slice. Newest first.

## 2026-09-06 — Issue #32 (SP-02): recovering the confirmation when the response is lost

Follow-up of #24 and its Woo port in #1, built on #31's attempt identity. Defect: the reviewed implementation recovered the landed attempt's confirmation too late — Woo rejects the emptied basket inside `WC_Checkout::process_checkout()` (the pinned 11.1.0 empty-cart throw) before the recovery integration runs, so a customer whose saved request's response was lost could only accept «sesión caducada», and the round's regression still demanded the replay to fail. Decision (adapter **1.6.0**, pending deploy):

- **Interception ahead of Woo's guards, authorization unchanged:** the confirmation recovery rides `wp_loaded` 0 (Woo's checkout AJAX runs at `template_redirect` 0) and answers the resubmission of a landed attempt's own form before the empty-cart rejection. It still requires Woo's own process-checkout nonce, a well-formed attempt token, and BOTH bindings (durable lookup row + session landing record) resolving to the same order of the same session — knowing an identifier authorizes nothing; nothing is created, changed or re-notified.
- **Defined vigencia (`FPW_RECOVERY_MAX_AGE`, one day from the landing):** expired landings fall through to Woo's own safe rejection, which reveals no reference, key or foreign data; one day stays inside the nonce's own 12–24 h window so the boundary is enforceable end to end. The durable replay binding stays permanent — the vigencia bounds only the confirmation re-show, never idempotency (a replay can never become a duplicate request).
- **Cart preservation (criterion 6):** the retry may arrive with a NEW unrelated selection already in Productos a Cotizar; the empty-cart gate was removed because its fallback — Woo's fold-in — empties the basket through the quotes gateway and stamps a duplicate-fold note on the record. The recovery answers read-only; the new selection survives untouched.
- **Checks:** offline 256 → **260 local assertions** (full-cart recovery with untouched cart, vigencia boundary both sides, lifetime pinned as a constant); real-stack 47 → **64** with four new native scenarios in `woo-checkout-race.py` (`lost` lost-response recovery with the response abandoned mid-processing, `inflight` recoverable in-flight retry, `preserve` new-selection survival — RED before the fix (`items=[]`), `stranger` unknown-token/foreign-session safe answers); notification events exactly 2 × 8 = **16**, never duplicated; syntax/dependency/deployment 133 → **150**. Remaining operator steps: deploy adapter 1.6.0 to staging, re-run `verify-woo-state.php` and the browser lost-response walkthrough; no-JS resubmission path and expired-vigencia-on-live-stack remain operator/human scope.

## 2026-09-06 — Issue #33 (SP-03 + ST-01): Ventas reads and annotates, it never writes the record

Follow-up of #25 (finding SP-03, P1): the two order caps that open the native editor also pass Woo's own checks on every record-mutation surface — editor saves (contact + status, both stores), list bulk actions, the quick-status AJAX (whose nonce Woo itself renders for the role in the order preview), the items/taxes/refunds/downloads AJAX family, the REST orders API (`wc_rest_check_post_permissions()` maps `edit`/`batch` onto the same `edit_others` cap) and note deletion; the round's deletion/priced-quote checks used absent or invalid nonces (ST-01, P2) and would have passed under privilege escalation. Decision (adapter **1.5.0**, pending deploy):

- **Capability-boundary guards, never nonce checks:** the new server guards key on `fpw_is_order_limited_staff()` alone, so a request with a valid session and valid nonces is still denied and every denial is attributable to permissions. Kept surfaces (login, list, search, editor reads, private-note AJAX) pass untouched; managers keep native behavior; role caps unchanged.
- **Layered denials:** an `admin_init` front door for the mutating AJAX family and both editor-save routes (in the posts store WP rewrites the record row itself before Woo's save hooks, so the wall must sit before it); a priority-0 backstop on `woocommerce_process_shop_order_meta` (both stores' save pipelines, after Woo's nonce + cap checks, before any write); a `woocommerce_bulk_action_ids` chokepoint for all list bulk mutations; a `woocommerce_rest_check_permissions` boundary denying every mutating REST context for order-limited staff.
- **Interface honesty:** quick-status buttons (rows + preview modal), save controls, the status select, the order-actions select, the quotes extension's priced-quote buttons, per-note delete links and the bulk-actions UI are removed or hidden for the role, with a Spanish notice stating the consulta scope; notes stay addable, private, author- and date-stamped.
- **Evidence classes split (issue #33 criterion):** new `scripts/woo-ventas-guard.py` (disposable stack, part of `npm test`) drives a REAL restricted session: identity of its own synthetic request verified from the delivered editor before any mutation, private note through the native flow, then every protected operation attempted with valid session + valid nonce (minted for the restricted user by a disposable mu-plugin) demanding a 403 that leaves the record unchanged; guard-off positive controls prove the probes detect a removed guard (status actually changes, REST actually rewrites) and a recheck proves the denial returns. Staging `verify-ventas-role.py` rebuilt on the same classes (valid-nonce editor-save/bulk/REST/priced/resend denials + identity verification; CSRF controls separate).
- **Deterministic stack fixes found by the matrix:** Woo 11.x "coming soon" mode pinned off in the bootstrap (its onboarding enabled it mid-run and replaced store pages for logged-out visitors); the php -S server log now goes to a file — piped logs stalled the entire suite once node's event loop blocked inside `spawnSync` and the 64KB pipe buffer filled; the server process group is killed with a port-freed assertion.
- **Checks:** offline 207 → **256 local assertions** (including the regression probes: removing a guard lets the write through; granting `manage_woocommerce` reopens it); real-stack 40 → **47** (guarded matrix → guard-off positive controls → restored-guard recheck); syntax/dependency/deployment 126 → **133**. Expanding the Ventas scope remains a separate human decision; documenting that the editor allows something is not authorization.

## 2026-09-06 — Issue #31 (SP-01): a new request is not a retry — attempt identity is not content identity

Follow-up of #24 and its Woo port in #1. Defect: the attempt claim was keyed on session + cart hash + posted fields, and the durable binding was permanent — so a customer who COMPLETED a request and rebuilt the identical selection (same products, variants, quantities, details) was folded into the PREVIOUS order forever; no new reference was ever possible. The real-stack regression reproduced it before the fix: the rebuild returned the previous order.

Decision (adapter **1.4.0**, pending deploy):

- **Attempt identity = session + per-attempt token, never content (ADR-0001 — Woo keeps session ownership):** the session's first checkout-form render generates a random 40-hex token, stored in the customer's own Woo session (`fpw_attempt_open`) and shipped in one hidden form field (`fpw_attempt`). The claim key is sha256(session fingerprint + token). Cart contents and posted fields take NO part in the key: content identity belongs to the order's own lines and data.
- **Lifecycle (documented contract):** creation on first form render; validity spans the whole attempt (re-renders, AJAX refreshes, pre-save error corrections and resubmissions keep the token, so retries stay ONE request); completion when a persisted order marks the attempt landed (`fpw_attempt_landed` in the session: token + hash + order id — the authorized binding the confirmation-recovery follow-up (#32) reads, independent of the cart staying full); rotation on the first form render after a landing, so the completed attempt can never capture a later submission.
- **Not a WordPress nonce:** the token is opaque random identity with no tick/expiry semantics, never verified as or compared to a nonce; Woo's own `woocommerce-process_checkout` nonce checks stay exactly as shipped. Recovery additionally requires that nonce to verify — nothing is bypassed. Cross-session recovery is impossible: the hash binds the session fingerprint, and both bindings (durable lookup row + session landing record) must agree on the same order.
- **Retry recovery uses the binding (minimal, read-only):** a `wc-ajax=checkout` submission arriving with the cart Woo already emptied (the response never arrived / the same form was resubmitted) returns the landed attempt's own confirmation JSON instead of «sesión caducada» — only when nonce + token + durable lookup + session landing all agree. Nothing is created, changed or re-notified.
- **Claim budget:** `FPW_CLAIM_WAIT_SECONDS` 3 → 10 — the loser only ever waits while the winner is still mid-flight, and folding beats a spurious recoverable error on slow stacks; every observed loser path now converges on the winner's confirmation.
- **Checks:** offline regression 169 → **203 local assertions** (identity semantics: fields/cart changes do NOT change the identity, another session or token does; token format/stability/rotation; hidden-field render; landing binding + guards; retry-recovery authorization matrix incl. nonce, cart state, token mismatch, cross-session). The real-stack regression (`woo-checkout-race.py`, extended with the harness) now runs the concurrent test **three bounded rounds** with fresh sessions (one order per round, both confirmations equal), proves the replay of a landed attempt recovers the SAME request, and proves the #31 defect fixed: the identical rebuild gets a NEW reference with a rotated token. WP-CLI state checks verify each new request's pending status, quote meta, own attempt identity, own lines and own submitted details (renew: different identity, equal content). A mail-log mu-plugin (written into the disposable install by the harness, never in the repo) counts notification events: exactly 2 per new request (6 × 2 = 12), never duplicated for folds/replays/recoveries.

## 2026-09-06 — Issue #34: advance blocked while a replaced quantity update is still in flight (Woo stack)

Follow-up to #26 (finding SP-04): after Woo aborted a first quantity update to replace it, the store's pending list was already empty while the replacement request was still in flight — the «Datos y envío» CTA exposed `aria-disabled="false"` and clicks passed. Root cause verified in the pinned Woo 11.1.0 sources: `changeCartItemQuantity()`'s `finally` clears the item's pending flag for the ABORTED request. Decision (theme **1.0.6**, pending deploy):

- **One coherent predicate, two consumers:** pending now means "store flag set OR update-item request in flight" (`operationsPending()`), used by both the verdict deferral and the CTA — the same two signals the #26 script already observed, combined so the early store-flag cleanup can never declare the cart settled while a mutation runs. The CTA keeps `aria-disabled` and stops clicks synchronously at click time (pointer and keyboard alike) until the transport drains; a request start locks the CTA at the transport boundary itself.
- **Outcomes unchanged:** success announces the persisted quantity in Spanish and re-enables advancing; failure explains the quantity that remained saved and allows retrying or continuing explicitly with it; recovery never duplicates increments, loses lines or steals focus (ADR-0001 — Woo keeps owning cart, session and persistence).
- **The harness now exercises the transport observation for real:** its api-fetch stand-in routes every store request through `window.fetch` (as the real middleware does), so the shipped in-flight counter is live; new scenarios drive abort + deliberately slow replacement into the exact defective window (flag empty, request in flight, CTA locked), with success and failure endings and a slow retry. Still a logic/store regression — rendered-block walkthrough, narrow/desktop review, physical mobile acceptance remain operator/human steps.
- Offline assertions 169 → 173; syntax/dependency/deployment checks 84 → 102. Theme 1.0.5 → 1.0.6 (constant + style.css header together, per the #28 convention).

## 2026-09-05 — Issue #1 (WA-01 Woo-side): one attempt, one request under concurrent checkout

The #24 fix landed only in the retired implementation under `legacy/` (see the merge record in WOO-MIGRATION.md); the acceptance review still measured two concurrent POSTs creating two orders. Decision (adapter **1.3.0**, pending deploy):

- **Claim, never own (ADR-0001):** the adapter adds an atomic per-attempt claim around Woo's own checkout — a single options row keyed by the attempt's idempotency hash (session + cart hash + normalized posted attempt fields), inserted as a plain INSERT against the unique `option_name` (the option API is bypassed: `add_option()` is an upsert whose per-request cache hides defeats). The winner proceeds through Woo unchanged; the loser never creates a second order.
- **Recovery through Woo's own short-circuit:** on recovery the adapter returns the winner's order id from `woocommerce_create_order` — Woo itself skips creation, runs its own flow and sends the customer to the winner's confirmation. The fold-in forces `woocommerce_cart_needs_payment` so the flow takes the quotes gateway (Woo's own route that keeps the request pending): without it the emptied cart would take the no-payment path and move the request into a commercial status.
- **No duplicate notifications, no duplicate records:** the fold removes the quotes extension's `checkout_order_processed` hook for that request only (the winner's notification already covers the record) and leaves one honest private note. The attempt identity is bound durably in a dedicated lookup row (unique `option_name` → order id), so replays fold for the record's lifetime; the order also carries its attempt hash as meta for administration.
- **Verified pitfall avoided:** Woo's posts order store silently ignores `meta_query` since 9.2 — the first draft looked the attempt up by order meta and every attempt folded into the newest order. The real-stack regression caught it; lookups now go through the direct-SQL lookup row, never through order-meta queries and never through hand-written SQL against Woo's storage (ADR-0001).
- **Real-stack regression, not a simulation:** new `scripts/woo-stack-harness.mjs` + `scripts/woo-checkout-race.py` boot the disposable WP + SQLite + WooCommerce stack (bootstrap.mjs, repaired for the Woo era), serve it multi-worker on loopback and drive real HTTP: two concurrent checkouts → one pending order with both confirmations; a sequential replay → Woo's own empty-cart rejection and no new order; a different session/data → its own order. Dead-winner takeover and release-on-failure are unit-covered offline. Harness overhead on a warm stack: seconds.

## 2026-09-05 — Issue #1 (WA-04 Woo-side): Home featured grid with self-naming links

The #27 fix also landed only under `legacy/`; the live Home still rendered the featured grid through a `wp:shortcode` block. Verified root cause in WordPress core: `render_block_core_shortcode()` runs `wpautop()` over the shortcode's EXPANDED output, and `get_the_block_template_html()` expands `[products]` before `do_blocks()` — so Woo's native loop markup is paragraph-split at its internal blank lines, landing `</p>`/`<p>` pairs inside the product link (the unnamed-link defect axe measured). Decision (adapter **1.3.0**, theme **1.0.5**, pending deploy):

- **Stable rendering API, not new markup (PRD: "plugin-rendered dynamic blocks"):** the adapter registers `freeplast-woo/featured-products`, whose render callback executes the SAME native `[products]` shortcode inside `do_blocks`, where no wpautop runs; the theme template swaps the `wp:shortcode` block for it. The delivered card markup is byte-for-byte Woo's own loop — title text and alt-bearing image inside the link — and the adapter owns no card markup at all.
- **With the adapter inactive the grid degrades to nothing** (same class of degradation as the other adapter features); with it active the grid keeps the native `woocommerce columns-4` classes.
- **The real-stack regression computes the delivered Home**, not a fixture: no shortcode wrapper, no wpautop damage inside the product link, the title inside the link, and a dependency-free axe link-name rule (text, aria-label, title, alt-bearing image) over EVERY delivered anchor with zero unnamed links. `verify-woo-http.py` asserts the same contract on staging.

## 2026-09-05 — Issue #25: Ventas Freeplast enters the native Woo admin with least privilege (Woo stack)

Finding WA-02 of the post-migration acceptance review: the retained `ventas_freeplast` role had no Woo order capabilities, so the only reviewed flows ran as administrator or `shop_manager` — neither is least-privilege. Decision (adapter **1.2.0**, pending deploy):

- **Own the role definition, mirror the admin:** the adapter now owns `fpw_sync_sales_role()` — idempotent, self-healing (create when missing, restore approved caps, strip extras, never touch other roles) — and grants exactly `read`, `manage_freeplast_quotes`, `edit_shop_orders`, `edit_others_shop_orders`. The two Woo caps are the verified minimum for the native Pedidos surfaces in the pinned Woo 11.1.0 sources (list/search + note AJAX → `edit_shop_orders`; top-level WooCommerce menu + author-less order detail → `edit_others_shop_orders`). No parallel administration is built (ADR-0001).
- **Access seam, not a cap grant:** Woo's default admin lock-down redirects users without the `edit_posts` primitive to My Account; the adapter filters `woocommerce_prevent_admin_access` for order-limited staff instead of granting `edit_posts` (which would allow wide post/page editing). Everything behind the door stays WP-capability-checked.
- **Server-side denials over UI hiding:** deletes, catalog, users, settings and plugins stay ungranted (WP enforces); the quotes extension's priced actions stay behind `manage_woocommerce`; email resends are removed from the order-actions select AND denied 403 at `woocommerce_before_resend_order_emails` (the manual invoice email bypasses Woo's enabled-check, so disabled-email filters are not a guard); the note-to-customer email joins the disabled set; ventas' notes are normalized to private at the AJAX origin (the metabox posts a visibility choice), not merely hidden in the UI.
- **Honest scope boundary:** Woo's own editor still allows general order saves to cap-holders; restricting that would mean owning Woo's editor (ADR-0001 rejects it). Documented for human acceptance rather than papered over.
- **Checks:** offline 86 → 130 assertions + 68 syntax/deployment checks; `verify-woo-state.php` asserts role caps/denials/guards read-only; new staging-only `verify-ventas-role.py` runs a real restricted session (temp account, allowed walk, direct-route denials, cleanup) — verified end-to-end on a disposable pinned WP+Woo+Quotes stack. Deploy + operator/human walkthroughs remain the Gate 3 steps (see WOO-MIGRATION.md).

## 2026-09-05 — Issue #26: feedback and recovery for failed quantity changes in Productos a Cotizar (Woo stack)

Finding WA-03 of the post-migration acceptance review: a quantity change that fails (connection loss) rolls back silently — the block shows the persisted number again with no explanation, and the persisted value itself is never stated. Verified in the pinned Woo 11.1.0 sources: `receiveError(isApiErrorResponse(e) ? e : null)` renders nothing for connection loss, and the store's own pending flag has an abort-cleanup gap. Decision (theme **1.0.4**, pending deploy):

- **Mirror the native surfaces, own nothing (ADR-0001):** `assets/js/cart-quantity-feedback.js` (cart page only) learns the stated quantity from the block's own `cart-set-item-quantity` store event, detects settlement via the store's selectors plus a read-only in-flight count of `update-item` requests at the transport boundary (closing the abort-cleanup gap), and never writes quantities — Woo keeps owning cart, session and persistence.
- **Verdicts are deferred, never taken on the settling tick:** any store/transport activity re-arms a 50 ms evaluation, so abort-and-replace never flashes a false failure and verdicts fire only after all request chains drain. Failure → visible Spanish role=`alert` notice naming the product and the persisted quantity («No se guardó el cambio… sigue con <n>…»); success (first try or retry) → the notice is replaced by a role=`status` confirmation with the exact persisted quantity, so retries cannot accumulate increments.
- **Pending state cannot advance, and never blocks indefinitely:** while operations are pending the «Datos y envío» CTA is `aria-disabled` and clicks are prevented synchronously (Woo's anchor `disabled` does not block navigation; its own `preventDefault` is asynchronous and too late); after settling it is operable again. Focus dropped by the native disable cycle returns to the control the customer was using; deliberate focus elsewhere is never stolen.
- **The regression exercises the real Cart block, offline:** the byte-identical compiled `wc-blocks-data.js` from the pinned 11.1.0 zip is vendored (`scripts/vendor/`, provenance + hash sidecar) and driven in Node with only `window.wp.*` plumbing stubbed — real `changeCartItemQuantity`, real pending/rollback semantics, real store event — through success, connection loss (proving the store dispatches no notice at all), server error, recovery/retry, abort-and-replace, advance-blocking, focus restore and removal-while-pending. Staging deploy + browser pass with controlled test selection, narrow/desktop visual review and physical mobile acceptance remain the operator/human steps.
- Offline assertions 76 → 86; syntax/dependency/deployment checks 33 → 68 (harness scenarios + bundle integrity against its provenance sidecar).

## 2026-09-05 — Issue #28: accessible selection state on the variable product sheet (Woo stack)

Finding WA-05 of the post-migration acceptance review: the variable product's add-to-cart button looked disabled only through a CSS class — no `disabled`, no `aria-disabled` — so its 3.51:1 dimmed text was measured as an available control and no technology could tell the state. Verified in the pinned Woo 11.1.0 sources that the state exists only as JS-toggled classes. Decision (theme **1.0.3**, pending deploy):

- **State at the render origin, semantics everywhere:** template override `woocommerce/single-product/add-to-cart/variation-add-to-cart-button.php` ships Woo's own initial availability classes plus `aria-disabled="true"` and an `aria-describedby` link to a visible, attribute-naming instruction («Selecciona Color para agregar este producto a Productos a Cotizar.») — true before any JavaScript runs. No real `disabled` attribute: the no-JS flow stays operable and server validation keeps owning rejection of variation-less submissions.
- **Mirror, never decide:** `assets/js/variation-button-state.js` keeps the aria state and instruction in step with the classes Woo's own variation form toggles (class-attribute `MutationObserver`), covering initial, selected (`aria-disabled="false"`, instruction hidden), cleared (initial restored), unpurchasable combination and the pending submission (`aria-busy="true"`; reset on bfcache `pageshow`). Availability rules, native guidance clicks, simple products (no `simple.php` override), the photo notice and stock information are untouched.
- **Honest contrast:** the inactive look is exempt (real inactive semantics), and the theme still replaces Woo's 3.5:1 opacity blend with an explicit AA-passing muted style (4.85:1); enabled stays 14.74:1, hover 7.45:1, instruction 11.37:1. The distinction is documented, not papered over with a label.
- Offline assertions 53 → 76; syntax/behavioral checks 15 → 33; `verify-woo-http.py` asserts the delivered HTML state. Staging deploy, browser axe re-run, keyboard walkthrough and screen-reader/mobile acceptance remain the operator/human steps (see WOO-MIGRATION.md).

## 2026-09-05 — Issue #30: restore the header Productos a Cotizar line count (Woo stack)

Finding 2 of the 2026-09-05 afternoon site validation: the Woo migration dropped the header line count («Productos a Cotizar (n)») — a static `wp:html` link, no logic anywhere, undocumented cut. Decision (adapter 1.1.0, theme 1.0.1, pending deploy):

- **One definition, owned by the Woo boundary:** `fpw_cart_line_count()` in `freeplast-woo` returns `count( WC()->cart->get_cart() )` — Woo keys cart entries by product + variation + attributes, so counting entries is the contract-v6 line definition (variants/colours are separate lines, quantities are not lines). Reads the cart; no parallel session/persistence service (ADR-0001).
- **Three native surfaces, one number:** (1) server render on every public route via a `{{FREEPLAST_BASKET_COUNT}}` token resolved by a `render_block` filter — the established `{{FREEPLAST_THEME_URL}}` mechanism — so no-JS navigation always shows the real count; (2) Woo's native add-to-cart fragments refresh the same span on classic AJAX card adds (verified against the pinned 11.1.0 `WC_AJAX::add_to_cart` → `add-to-cart.js` fragment application); (3) new `assets/js/basket-count.js` subscribes to the cart block's `wc/store/cart` data store and re-renders from `getCartData().items.length` — the surface fragments cannot see (Store API quantity/remove/empty). The watcher renders nothing until the store resolves its cart, so the server value is never flashed over.
- **Empty state documented:** the link always carries the number, `(0)` when empty — coherent on first paint, no flicker; adapter-absent degrades to no number rather than a wrong one.
- Offline assertions grew 16 → 30 (line semantics, fragment contract, header-markup contract, token resolution). Staging deploy, browser add/change/remove/empty walkthrough and narrow-width visual review remain the operator/human steps.

## 2026-09-05 — Issue #29: no $0 amounts in the Datos y envío review table (Woo stack)

Finding 1 of the 2026-09-05 afternoon site validation: the classic checkout's order-review table printed the cart's technical zero as «Subtotal $0» / «Total $0» (line subtotal + Subtotal + Total, three `woocommerce-Price-amount` elements in clean anonymous HTML). The adapter's price-suppression filters never reach this table, and the theme's `display:none` rule only hid the amounts while leaving them in the DOM. Decision (theme **1.0.2**, pending deploy):

- **Fix at the render origin, by template override** `woocommerce/checkout/review-order.php` (Woo's sanctioned override path, same as `thankyou.php`): products, chosen options and quantities only — no price renderer is called anywhere in the template, so no amount markup can exist in the delivered HTML. The classic checkout re-renders this template on every `update_order_review` AJAX pass, so first paint and refreshes are one origin. Native product/class/visibility filters and the review-table actions are kept, so extension compatibility and the conditional-fields/draft flow are untouched.
- **Totals zone reworded, not hidden:** a single «Total → Por cotizar» row — the adapter's own order-total wording, coherent with the no-purchase/no-stock disclaimer. The «Subtotal» column header is gone (columns: Producto/Cantidad). Adapter filters deliberately not extended: Woo 11.1.0's subtotal row (`wc_cart_totals_subtotal_html()`) has no filter, so the template is the only origin-level handle; layering filters would suggest coverage the template alone provides.
- **No CSS hiding remains:** the `.product-total`/`tfoot` `display:none` rule was removed from `assets/css/woo.css` (theme bumped to 1.0.2 for the cache-bust). The technical zero stays visible in Woo admin/API per the documented limit — this claims the public checkout page only.
- Offline assertions 30 → 53 (override calls no price renderer; offline render of a simple product + colour variant yields names/options/quantities/«Por cotizar» and zero `$0`/`woocommerce-Price-amount`/«Subtotal»; no CSS hiding). Syntax/deployment checks 14 → 15. `verify-woo-http.py` (staging, not part of `npm test`) now asserts the delivered `/datos-y-envio/` HTML is amount-free. Staging deploy + anonymous re-check + visual review of the reworded totals zone remain the operator/human steps.

## 2026-09-05 — Issue #24: one attempt, one Quote Request under concurrent submission

Two POSTs of the same submission attempt (same anonymous session, same
form token, same lines — two tabs, or a retry fired while the first
request is still in flight) could both pass the replay check before
either persisted and both answer success with different references,
creating two Quote Requests for one customer intent; and the replay
recovery itself was not bound to the submitting session, so a token
copied to another session recovered the victim's confirmation into it.
Origin: post-migration Woo review 2026-09-05 (WA-01), mapped onto this
repo's native admin-post submission (no WooCommerce here — see the
repo-level note in BUILD-DECISIONS intro; the discipline is identical).
Decisions:

1. **An atomic per-attempt claim serializes the attempt.**
   `handle_submit` claims the attempt (the idempotency hash it already
   derives from the token) in a single options row
   (`fpcq_claim_<hash>`, autoload off) right before the record insert:
   a plain INSERT into the options table fails against the unique
   `option_name` on both database engines, so exactly one concurrent
   request becomes the owner and the loser never reaches the record
   insert. The option API is bypassed for the claim on purpose —
   `add_option()` writes an upsert (`ON DUPLICATE KEY UPDATE`) that
   "succeeds" for a defeated writer, and the per-request options cache
   would then hide the overwrite; the claim's insert and reads therefore
   go through `$wpdb` directly, with the expected duplicate-key error of
   the losing request suppressed (the defeat is information, not a
   fault). The owner persists and finalizes the claim with the
   reference; the loser waits a bounded moment (3s, 100ms poll) and then
   recovers the winner's confirmation instead of persisting a second
   copy. Only the winner schedules notifications, clears the basket and
   counts the throttle — one record, one reference, one set of receipt
   jobs, exactly once.
2. **Recovery is session-bound.** The replay lookup now verifies the
   record's `_fpq_session` hash against the presenting session (and the
   claim row carries the same binding), so a copied token in another
   session resolves as an unknown token: recoverable rejection, no
   reference, no confirmation ever crosses sessions.
3. **Failures stay recoverable and bounded.** A persistence failure
   releases the claim so the retry starts clean; an empty claim older
   than a 30s grace period belongs to a winner that died mid-flight —
   the same session first recovers a record that landed without its
   finalization, else resumes the attempt itself; claims older than a
   week are swept by the existing daily basket GC (the durable
   idempotency binding lives on the record meta, so the claim rows only
   serve the short concurrent window); uninstall.php deletes them as
   ephemeral state. No new table, no schema change, no new migration:
   the options row is created and dropped at runtime.
4. **A regression through the native endpoint proves it.** The check
   drives two real admin-post POSTs of one attempt with a deterministic
   coordinator (a mu-plugin parks the first submission inside the
   `freeplast_cq_request_persist` seam while the second runs; php -S is
   started with `PHP_CLI_SERVER_WORKERS` so the requests genuinely
   interleave) and fails explicitly when an attempt yields more than one
   record. The race repeats three times with the surviving references
   recorded in VERIFICATION.md; the recovery of a response that never
   arrived, cross-session isolation, the same-content resubmission after
   completion, and the single scheduled receipt event are asserted
   beside it.

Behavior preserved: invalid/too-fast/throttled attempts claim nothing
and count nothing; a claim released on persistence failure lets the
ordinary retry through; the confirmation still renders only for the
session that owns it. Files: class-request.php (claim + session-bound
recovery + sweep), uninstall.php (ephemeral cleanup),
scripts/check.mjs (issue-#24 section, multi-worker php -S,
VERIFICATION rows/notes), README.md, BUILD-DECISIONS.md. VERIFICATION.md
and dist artifacts regenerated by npm test.

Notes for next iteration: none blocking — the residual takeover window
(an owner dying exactly between its record insert and claim finalization
after being in flight >30s) recovers the landed record on takeover and
is documented in claim_attempt(); the acceptance walkthrough of the
staging site remains the operator/human step.
## 2026-09-05 — Issue #27: every rendered link names itself

The post-migration review of 2026-09-05 (finding WA-04) reported eight
tabbable anchors without an accessible name in the Home Featured Product
cards — each card stopping the keyboard on an empty link, invisible in
the accessibility tree. The defect class, not a Woo-specific markup
artifact, is what the repository must make impossible: any product link
whose visible content collapses to nothing (a titleless content-side
record, a redundant image-only anchor) renders a nameless keyboard stop.
Decisions:

1. **Fix the naming contract at the shared renderer, not per surface.**
   Every product link in the plugin now names itself through one helper,
   `Freeplast_CQ_Products::accessible_title()`: the product's visible
   title, falling back to its slug when a record published outside the
   reviewed source carries none (synchronization can never create one).
   It is applied by all four renderers that emit product links — the
   catalog cards (Home Featured, Tienda, search), the related Products
   list, and both basket line views — so no surface can regress
   independently.
2. **The check audits the HTML WordPress actually delivers, nothing
   excluded.** A dependency-free server-side computation of the axe
   `link-name` rule (text content, aria-label, resolvable
   aria-labelledby, title, alt-bearing image as last resort) runs over
   the served Home, Tienda, search and product-page documents — plugin
   block output, theme parts and any content-side markup included. The
   check reproduces the reported pattern (eight image-only card anchors)
   and requires all eight to be flagged, and shows an aria-label painted
   onto one empty link silences only that link — the exact band-aid the
   acceptance criteria forbid, made mechanically rejectable.
3. **Content-injected records are exercised end-to-end.** The check
   publishes a real fp_product without title/category/excerpt (bypassing
   the `wp_insert_post_empty_content` guard at the seam, since the
   reviewed source can never produce such a record), requires its card
   link to still be named (slug fallback in the served HTML) and requires
   Home to return to exactly the approved eight Featured Products after
   the record is removed.

Behavior-preserving: for every source-synced record (all titled), the
rendered markup is byte-identical; destinations, images, names and the
native Cotizar chooser are untouched, and no aria-label was added
anywhere. Mechanical evidence only — screen-reader and keyboard
acceptance remain human review (Gate 3).

Files: wordpress/wp-content/plugins/freeplast-catalog-quotes/includes/
class-products.php (accessible_title + related Products), class-discovery.php
(catalog cards), class-basket.php (mini + full basket line titles),
wordpress/scripts/check.mjs (issue #27 section + link-name audit helpers +
VERIFICATION rows/notes), BUILD-DECISIONS.md. dist/ plugin ZIP +
CHECKSUMS.sha256 and VERIFICATION.md regenerated deterministically by
npm test (39/39 pass).

## 2026-09-04 — Issue #23: strip the PHP origin header at the staging edge

Authenticated responses through the staging Nginx edge advertised the
origin runtime (`X-Powered-By: PHP/8.3.33`) — the vhost forwarded origin
headers untouched. Decisions:

1. **Hide at the edge, not at the origin.** One
   `proxy_hide_header X-Powered-By;` inside the proxied `location /`
   block of `nginx/staging.conf.tmpl`. Nginx is the single enforcement
   point every authenticated request already traverses; hiding there
   covers every proxied route and status (pages, admin, static, 404s)
   without teaching WordPress/PHP to suppress the header on each of its
   own response paths.
2. **Surgical change.** Exactly one `proxy_hide_header` directive exists
   in the template — no other origin header is hidden and the forwarded
   request chain is untouched, so the site's behavior is otherwise
   unchanged (the check asserts both).
3. **Mechanically proven + recorded operator step.** The check asserts
   the directive sits in the proxied block and is the only hidden header,
   and that `verify.sh` now walks an authenticated (owner-credential)
   response through the HTTPS edge and fails if `X-Powered-By` ever
   reappears. The on-server re-run — deploy.sh re-renders and installs
   the vhost (`nginx -t` before the reload) and verify.sh must still
   report "verification clean" — is the pending operator step recorded in
   DEPLOYMENT.md, alongside the still-pending issue #16 re-run.

Files: wordpress/infra/nginx/staging.conf.tmpl, wordpress/infra/verify.sh,
wordpress/DEPLOYMENT.md, wordpress/scripts/check.mjs (new issue #23
section + recorded expected vhost), wordpress/HANDOFF.md,
wordpress/README.md, BUILD-DECISIONS.md. VERIFICATION.md regenerated by
npm test.

Notes for next iteration: the operator re-run on OpenClaw (vhost
re-render + reload, verify.sh "verification clean") is pending and
recorded in DEPLOYMENT.md.

## 2026-09-04 — Issue #22: failed sync phases roll back run-imported media only

Catalog Sync already rolled the created product posts back when its post
phase failed, but the attachments that same run had imported stayed behind
(orphaned, reusable only by checksum on the next run) — and the media-phase
catch deleted every attachment id in the run map, including ones the run had
merely *reused* by checksum. Decisions:

1. **Only a run's own imports are rollback-eligible.** `import_attachment()`
   now returns the attachment id plus a `created` flag (this run imported it
   vs found it by checksum); `apply()` records
   the fresh imports in an `$imported` list separate from the
   `$attachments` map used for featured media. Both failure paths (media
   phase and post phase) roll back `$imported` only — a checksum-reused
   attachment is pre-existing library content and is never deleted, no
   matter where the run failed.
2. **Rollback deletes an import only while nothing references it.** The
   post-phase catch first deletes the created posts, then asks
   `delete_run_attachment()` per import, which skips any attachment a
   surviving record still lists as `_thumbnail_id` — media an *updated*
   record was already upserted with before the failure is not orphaned and
   must keep working. Everything genuinely orphaned goes away (attachment
   rows and their uploads files, via `wp_delete_attachment`).
3. **The check faults the real seam, not the fixture.** A disposable
   mu-plugin short-circuits `wp_insert_post_empty_content` for exactly one
   post type: `fp_product` aborts the post phase after media succeeded
   (fresh import + checksum reuse + a second fresh import all ran first),
   `attachment` aborts the media phase mid-import right after a reuse
   resolved and one fresh import landed. Both runs must exit non-zero with
   zero orphans and the reused attachment intact; the fault-free recovery
   run must then apply the same source cleanly and still not re-import the
   checksum-matched media. The section hands the exact section-11 state
   back (reviewed source no-op, baseline media library).

Files: plugin `class-catalog-sync.php` (contract note, `apply()`
bookkeeping, `import_attachment()` return shape, `roll_back_imports()` /
`delete_run_attachment()`),
`scripts/check.mjs` (section 10b + docblock/VERIFICATION records), README,
this file; dist plugin ZIP + CHECKSUMS regenerated by `npm test`.

Notes: the update-path meta wrinkle remains by design — a record updated
*before* the failure keeps its new checksum meta; the next clean run
reconciles it. Attachments are proven to survive the media-phase fault now,
where the old catch deleted the reused tote attachment outright.

## 2026-09-04 — Issue #21: the Delivery Address confirm validates the posted place

The confirm form posts the reviewed destination's place id as a hidden
`fp_place` field, but the confirm action ignored it — the confirmed state
came solely from the session transient, so a tampered or stale form
could silently confirm a destination the customer never reviewed.
Decisions:

1. **The posted place must be the reviewed destination's own place id.**
   `handle_confirm()` now reads the posted `fp_place` (same
   sanitize/unslash shape as `handle_pick()`), re-validates it with the
   shared `is_place_id()` bound and requires exact equality with the
   session state's `place_id` before flipping the stage to `confirmed`.
   A mismatched or absent value takes the existing recoverable
   `address_error` redirect and mutates nothing — the review survives so
   the customer can still confirm honestly or search again. The form
   itself is unchanged: it already posted `$state['place_id']`, so the
   honest path behaves byte-for-byte as before.
2. **The gate rides the issue #11 test at the real admin-post seam.**
   The check picks a suggestion into review, confirms with a foreign
   place id (rejected, error notice text rendered, state provably still
   in review), confirms with no place at all (rejected, still unmutated)
   and then confirms with the matching place (confirmed exactly as
   before). No new fixture or provider mode was needed.

## 2026-09-04 — Issue #20: position-independent theme assets and balanced free-form blocks

Two hygiene fixes to the theme's header/footer parts and front-page template,
shipped as theme 0.8.1 (the FREEPLAST_THEME_VERSION bump also re-busts the
stylesheet cache on staging after the CSS change below). Decisions:

1. **Theme assets resolve at render time, never from a hardcoded path.** Block
   templates and parts are static HTML and cannot call PHP, so theme-owned
   images are written as `{{FREEPLAST_THEME_URL}}/assets/…` and a
   `render_block` filter in functions.php replaces the token with
   `wp_make_link_relative( get_theme_file_uri() )` at render time. On a root
   install the rendered URLs are byte-identical to before (root-relative);
   the check proves position independence by booting the same disposable
   installation under a `/subdir` site URL — the identical sources render
   subdirectory-correct URLs, and no unresolved token reaches any served
   document. The Site Editor previews static wp:html blocks from their saved
   markup, so editors see the literal token there; the rendered v6 shell is
   always resolved.
2. **Each free-form (wp:html) block stands on its own.** The header part used
   to open `<div class="fp-island-wrap"><header class="fp-island">` in one
   wp:html block and close them in another, with the plugin's basket-button
   block interleaved — legal, but a Site Editor edit touching either block
   could silently corrupt the rendered chrome. The wrap and the island header
   are now group block boundaries (the island as `wp:group` with
   `tagName: header`), and the logo, navigation, burger button and mobile
   sheet each live in one balanced wp:html block, with the basket button
   between them exactly as before. The group blocks receive core
   flow-layout classes whose rules add block margins to container children;
   because the v6 pill chrome is gap-driven flex, the theme stylesheet resets
   block margins on their direct children with a deliberately
   outspecifying selector, and the check asserts the rendered page uses the
   same layout CSS rules as before. The rendered DOM delta on a root install
   is exactly: the two container elements carry
   `wp-block-group …is-layout-flow` classes, the neutralizing reset, and
   whitespace.

## 2026-09-04 — Issue #19: the basket cookie Secure flag follows the request scheme

`send_cookie()` set `secure => true` unconditionally — right for this
HTTPS-only staging, but it silently broke basket persistence on any
plain-HTTP install (the browser drops the cookie and never sends it
back).

Decisions:

1. **`is_ssl()` is the single source of the flag.** The cookie sets
   `secure` from `is_ssl()` instead of hardcoding it: staging terminates
   TLS at Nginx and its wp-config maps the forwarded `https` scheme onto
   `$_SERVER['HTTPS']` (Compose `WORDPRESS_CONFIG_EXTRA`), so every TLS
   request keeps the Secure cookie — staging behavior unchanged — while
   a plain-HTTP install keeps a working basket. Clearing a stale or
   expired cookie rides the same scheme, so the clear always matches the
   cookie the browser actually holds.
2. **The check exercises the real TLS seam.** The disposable wp-config
   now mirrors the staging forwarded-proto mapping, and the check
   presents `X-Forwarded-Proto: https` on one authoritative add and no
   header on another: the TLS cookie must carry
   Secure/HttpOnly/SameSite=Lax, the plain-HTTP one
   HttpOnly/SameSite=Lax without Secure. The older issue #6 assertions
   now reject a plain-HTTP Secure flag as a regression.

## 2026-09-04 — Issue #18: shared contact-field validators

The public Quote Request form and the admin contact-correction flow
validated Email, Teléfono and Rut Empresa with cloned regexes and
duplicated error messages, even though the text-field contract
(`Freeplast_CQ_Request::TEXT_FIELDS`) is already shared between them.
Decisions:

1. **One shared definition, on the class that owns the field contract.**
   `Freeplast_CQ_Request::validated_contact_formats()` now owns the
   acceptance patterns and user-facing messages of the three
   format-checked contact fields, right next to the TEXT_FIELDS contract
   both surfaces already iterate. The submission's `validated_fields()`
   and the sales correction's `Freeplast_CQ_Admin::validated_contact()`
   both delegate to it — adding or changing a contact-format rule is a
   one-place edit, and identical inputs cannot produce different
   outcomes on the two surfaces because there is only one code path.
2. **Behavior-preserving by construction, proven by inspection.** The
   extracted method body is the exact former Request block (the Admin
   copies were byte-identical clones): same guard order (required →
   length → format, earlier errors skip the format check), same
   messages, same patterns, email stored in its WordPress-sanitized
   form while Teléfono/Rut keep the entered text. The check scans the
   plugin so each pattern/message exists in exactly one file, and drives
   both surfaces' full validation paths (Reflection over the private
   validators) with the same posted inputs across valid, invalid-format,
   empty, boundary and overlong cases, requiring identical per-field
   results.
3. **No version bump, no schema change.** Stored data shapes are
   untouched (only validation moves); following the issue #17 precedent
   the plugin version stays 0.8.0 and the dist ZIP is rebuilt
   deterministically by `npm test`.

## 2026-09-04 — Issue #17: one JSON codec for stored meta

Quote Request meta, Catalog Sync and Notifications each carried their
own identical JSON encode/decode for stored meta (three encoders, two
decoders) plus inline decoding in the Delivery Address flow (and the
same flagged encode inline in the basket session store). Decisions:

1. **One plugin-level codec pair owns the stored form.** New
   `includes/class-codec.php` (`Freeplast_CQ_Codec::encode()/decode()`) is
   the only place the stored JSON form is decided; every stored-meta
   write/read now goes through it — Quote Request meta (`_fpq_*`),
   Catalog Sync product meta, notification jobs/logs, `_fp_options` /
   `_fp_related_ids` / `_fp_legacy_paths`, the basket session
   `basket_lines` column and the Delivery Address destination/distance
   meta (the inline decodes included). The retired per-class helpers
   (`Freeplast_CQ_Request::encode_meta()`, Catalog Sync `pack()`,
   Notifications `encode()/decoded_meta()`, Admin `json_meta()`) are
   deleted, not wrapped.
2. **The stored form stays byte-for-byte identical.** Encode keeps
   `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` — the documented
   issue #4 decision: unescaped JSON survives the metadata API
   unscathed (`update_post_meta` unslashes scalar values), so the
   stored form equals the compared form on every later run. Decode
   returns `array()` for absent/corrupt/non-array values, exactly the
   semantics the removed helpers had. Non-meta JSON is deliberately
   untouched: the catalog source document and the Google provider wire
   bodies keep their own encodings.
3. **Identity is proven, not assumed.** The check asserts the byte
   form exactly, decodes absent/corrupt meta as empty, round-trips
   every persisted meta key on the running installation back to its
   own bytes, and re-runs the catalog dry run after the swap expecting
   zero changes (`created=0 updated=0 unchanged=17 errors=0`).

Notes for next iteration: no schema change, no migration — existing
staging data reads and writes identically through the codec.

## 2026-09-04 — Issue #16: single-source the staging infrastructure constants

The staging hostname, install root and loopback port lived as literals in
every infra script, the Nginx vhost, the compose file and the env example.
They are now declared exactly once in `wordpress/infra/staging.sh`.
Decisions:

1. **One shared definition, sourced everywhere.** preflight/deploy/
   verify/backup/rollback source `infra/staging.sh` and declare no
   hostname/stack-dir/port literals of their own; rollback derives the
   vhost paths from `$SITE_HOSTNAME`. `PROJECT` and `BACKUP_ROOT` stay
   per-script — they are not part of the contracted trio.
2. **Templates receive the values.** The vhost is now
   `nginx/staging.conf.tmpl`: every hostname/stack-dir/port occurrence —
   comments included — is a `__`-placeholder rendered by deploy.sh's sed
   alongside the existing TLS placeholders, so the rendered file is
   byte-for-byte the deployed vhost. compose.yaml drops the `:-8092`
   fallback: the port is `${FREEPLAST_LOOPBACK_PORT:?…}`, written into
   `.env` by deploy.sh from staging.sh. Interpolation fails loudly without
   a value; the no-`.env` rollback branch could not interpolate the
   credentialed compose file before either, so no working path changes.
3. **Mechanically proven behavior-preserving.** check.mjs renders the
   template with the shared constants plus the recorded TLS convention and
   compares it byte-for-byte against the deployed vhost of the issue #14
   operator run; a scan asserts each value literal appears exactly once
   across infra (plus the name-only declarations in `.env.example`). The
   on-server re-run — existing-stack deploy must be a no-op and verify.sh
   must still report "verification clean" — stays the recorded operator
   step (DEPLOYMENT.md).

Files: wordpress/infra/ (new staging.sh;
nginx/freeplast.mliu.site.conf → nginx/staging.conf.tmpl; all five
scripts, compose.yaml, .env.example), scripts (check.mjs issue #16
section + updated issue #14 assertions + VERIFICATION rows), docs
(DEPLOYMENT.md, README, BUILD-DECISIONS).

Notes for next iteration: the on-server re-run (deploy.sh existing-stack
no-op + verify.sh "verification clean") is recorded in DEPLOYMENT.md as
the operator step.

## 2026-09-04 — Issue #15: verification and operations handoff

The completed build is packaged for independent operation and human
review in wordpress/HANDOFF.md, without claiming any visual validation.
Decisions:

1. **One committed handoff document, mechanically guarded.** HANDOFF.md
   records where every verification dimension lives — automated tests,
   migration version, active components, route statuses, PHP/JS syntax
   and coding standards recorded by `npm test`; infrastructure health,
   Nginx validation and the browser-console observation explicitly
   named as the on-server/operator/reviewer steps they are (DEPLOYMENT.md
   runbook, Gate 3) rather than asserted as done. The check asserts the
   coverage and rejects any phrase claiming visual approval.

2. **Catalog evidence is per-Product, not aggregate.** The handoff
   carries the 17→17 source/destination reconciliation with every
   source_id, canonical URL and provisional fact, the no-op dry-run
   expectation (`created=0 updated=0 … errors=0`), the media status
   (14 distinct images, all provisional) and the complete pending-client
   list, each with its apply-through-source/configuration procedure
   (products.json / fp_dispatch_origin / FREEPLAST_GOOGLE_API_KEY).

3. **Shipped artifacts are deterministic ZIPs with recorded checksums.**
   `npm test` builds `dist/freeplast-theme-0.8.0.zip` and
   `dist/freeplast-catalog-quotes-plugin-0.8.0.zip` as stored
   (uncompressed) archives with sorted entries and a fixed timestamp, so
   every rebuild is byte-identical; `unzip -t` validates them and
   `dist/CHECKSUMS.sha256` records the package digests plus a per-file
   manifest that `sha256sum -c` verifies against the real sources. The
   digests are re-recorded in VERIFICATION.md on every run (RUNBOOK §8).

4. **The acceptance matrix and accessibility observations point at their
   evidence.** Each Quote Request matrix row (JavaScript on/off,
   one/multiple Products, options, failures, idempotency, Google
   fallback, emails, admin state) names its VERIFICATION.md row and its
   manual staging reproduction; the keyboard/focus/error/reduced-motion/
   responsive results are labelled mechanical observations, not human
   approval, and the Gate 3 review URLs sit beside the frozen v6/v7-A
   references with the exact pending owner/client actions.

5. **Out-of-scope release work stays visibly separate.** Production DNS
   cutover, legacy-redirect activation, mail authentication
   (SPF/DKIM/DMARC), original photography and `live` mail mode are listed
   as separate owner-approved release work in the handoff — nothing in
   the staging build performs or schedules them.

## 2026-09-04 — Issue #14: deploy isolated password-protected staging

The complete build becomes an isolated, reviewable staging site on
OpenClaw without touching any existing service. The repository carries
the executable deployment plan (wordpress/infra/ + DEPLOYMENT.md) and
its mechanical checks; the on-server execution is the documented
operator step (the authoring environment has no route to the host).
Decisions:
1. **Everything new, verified before mutation.** preflight.sh is
   read-only and fails on any collision: hostname in sites-enabled,
   bound loopback port, existing stack directory, Compose project,
   volumes, network, disk under 10 GiB, DNS not resolving here,
   unreadable/near-expired/insufficient certificate SAN, or an unhealthy
   existing container. A collision aborts planning — never adoption or
   deletion of the conflicting resource (OPENCLAW.md).
2. **A dedicated stack with a loopback-only origin.** Compose project
   freeplast-wordpress at /opt/freeplast-wordpress: MariaDB 11.4
   (official healthcheck, no published port, private db_data volume),
   WordPress 7.1/php8.3 Apache (private wp_data volume, depends on a
   healthy db, publishes exactly 127.0.0.1:8092→80) and a profile-gated
   WP-CLI sidecar sharing the volume — started only on demand.
3. **TLS and authentication at the host Nginx.** One new vhost serves
   exactly the approved hostname freeplast.mliu.site: HTTP redirects to
   HTTPS, the certificate paths render from the server's approved
   convention (.env TLS_CERT_PATH/TLS_KEY_PATH, coverage verified by
   preflight), owner/client Basic Auth over a generated htpasswd,
   X-Robots-Tag noindex on every response (backing up WordPress
   blog_public 0), 64m uploads, dotfile/sensitive-extension denies and
   the loopback proxy with Host/Forwarded headers. The prior
   configuration is backed up before the vhost exists and nginx -t
   validates before every reload.
   *Superseded 2026-09-04 (owner instruction):* the edge Basic Auth gate
   was removed — the staging surface is public HTTPS (anonymous visitors
   served); `/wp-admin/` stays gated by the WordPress login; the
   generated htpasswd and its owner/client pairs are retained on the
   server but unused. Noindex, TLS, upload limits, denies and the
   proxy chain are unchanged.
4. **Secrets never touch the repository or command output.** deploy.sh
   (umask 077) generates every secret on the server with openssl rand
   into /opt/freeplast-wordpress/.env (0600) and .secrets/credentials
   (0400); the WordPress administrator password travels to WP-CLI
   through the container environment, never argv; scripts print paths
   only. .env.example carries names and comments, never values.
5. **Staging mail stays non-delivery until the owner approves
   recipients.** The stack pins FREEPLAST_CQ_MAIL_MODE=suppress on top of
   the plugin's own fail-closed default (issue #10); redirect needs an
   approved FREEPLAST_CQ_MAIL_TO and verify.sh fails the deployment if
   the effective mode is ever live.
6. **The reviewed Catalog is the deployment's content gate.** deploy.sh
   synchronizes data/products.json through WP-CLI and aborts unless a
   repeated dry run reports created=0 updated=0 … errors=0.
7. **Backups precede changes and rollback is bounded.** backup.sh dumps
   the database (password via environment), archives the WordPress
   volume, hashes both outside the live volumes and rehearses the
   restore into temporary freeplast-wordpress-restore names before
   tearing them down; the Nginx pre-change backup exists before the
   vhost is written. rollback.sh removes only the approved-hostname
   vhost and the freeplast-wordpress project — named volumes are
   retained unless the owner types the explicit purge confirmation, and
   the static proposals/unrelated projects are re-verified untouched.
8. **WordPress reports the approved identity.** Bootstrap installs with
   locale es_CL, timezone America/Santiago, home/site URLs
   https://freeplast.mliu.site, /%postname%/ permalinks, blog_public 0
   and DISALLOW_FILE_EDIT; wp-config honors the forwarded HTTPS scheme
   so admin/REST/media URLs stay HTTPS behind the proxy.

Files: wordpress/infra/ (compose.yaml, .env.example,
nginx/freeplast.mliu.site.conf, preflight.sh, deploy.sh, verify.sh,
backup.sh, rollback.sh), wordpress/DEPLOYMENT.md (the resource record +
operator runbook), scripts (check.mjs issue #14 section + VERIFICATION
rows), docs (BUILD-DECISIONS, README).

Notes for next iteration: issue #15 is the final slice; the on-server
staging execution (preflight/deploy/verify output, image digests) and
Gate 3 human review remain operator/owner steps recorded in
DEPLOYMENT.md.

## 2026-09-04 — Issue #13: harden the complete customer and sales journey

The complete Catalog-to-Quote-Request journey is exercised under
accessibility, abuse, migration, dependency and theme-failure conditions
closing gaps without changing the agreed domain boundaries. Decisions:
1. **Abuse resistance without a CAPTCHA (PRD boundary kept).** The request
   form gains an off-screen honeypot (`fp_referencia`, aria-hidden,
   tabindex=-1, off-screen inline styles so it is theme-independent); a
   plausible minimum completion time (2 s) measured from the token's
   server-side render time (the idempotency-token transient now stores
   `{token, started}` — the browser is never trusted with the clock); and
   bounded throttling (5 persisted requests per anonymous session per
   rolling hour, one expiring transient keyed by the opaque session hash —
   never a raw IP or email). Only durable persistences count, so invalid
   attempts, idempotent replays and failed persistences never block an
   ordinary retry; every rejection is recoverable with values and basket
   retained and a focused summary.
2. **Migrations fail safely into a self-healing maintenance state.** The
   one schema the plugin owns (`basket_sessions`) is verified after
   `dbDelta` (the `freeplast_cq_schema_ready` filter is the fault-injection
   seam); a failing migration marks the `fp_maintenance` option, leaves
   `fp_db_version` untouched and returns — every following request retries
   the pending migrations. While the flag is set, public routes answer a
   clear 503 page (purpose, data reassurance, human contact channel,
   noindex, Retry-After 300) rendered by the plugin (no theme dependency)
   and wp-admin shows an explanatory notice instead of failing silently;
   nothing is destroyed. The flag clears itself as soon as a retry
   completes.
3. **Uninstall is explicitly non-destructive.** `uninstall.php` keeps every
   business record (fp_product and fp_quote posts with all their meta,
   basket sessions, shell pages, dispatch origin, migration version) and
   only clears what nothing can serve once the code is gone: our scheduled
   events (fpcq basket sweep + notification delivery — the durable jobs
   stay on the records and remain resendable) and the expiring `fpcq_*`
   transients. WP-CLI's `plugin delete` removes files only, so the check
   drives core's `uninstall_plugin()` routine — exactly what the admin
   Delete action runs.
4. **Stock-theme fallback is a plugin obligation.** Because fp_product
   post content is the `freeplast/product-detail` block and /cotizacion/
   is the `freeplast/basket` block, the stock Twenty Twenty-Four theme
   keeps a functional minimal Catalog archive, full product singles with
   the quantity chooser, the basket/request flow end to end and the
   Cotizaciones administration; the header count widget is v6 chrome and
   deliberately not required for functionality.
5. **Mechanical accessibility evidence.** The check verifies (not claims):
   logical DOM/tab order, native `<details>` disclosures, the announced
   mobile sheet, focusable linked error summary (`tabindex=-1` + fragment
   redirect), labelled fields, `:focus-visible`, `prefers-reduced-motion`
   collapse, ≥24px targets parsed from the shipped CSS (primary 44px),
   WCAG AA contrast computed from the frozen palette pairs, and one
   identical mobile-first document at 375/412/768/1024/1440 px. Human
   visual approval remains Gate 3.
6. **Deterministic pace for the older sections.** Existing acceptance
   sections submit valid forms immediately after rendering; the check's
   `humanPaced` helper backdates the token's server-side render timestamp
   (documented in check.mjs), while the issue #13 section exercises the
   real-time guard: bot-speed rejection, values/basket retained, ordinary
   retry 2.4 s later succeeds.

Files: plugin (`class-request.php` honeypot/completion-time/throttle,
`class-basket.php` schema readiness + new notices,
`class-migrations.php` fail-safe + maintenance flag, bootstrap maintenance
 guard + 0.8.0, new `uninstall.php`), theme 0.8.0, check.mjs (six new
sections + pace backdates), docs.

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
   receives only a random 256-bit hex token (`fpcq_basket`, Secure on
   TLS requests, HttpOnly, SameSite=Lax, 30 days); only its sha256 hash
   is persisted in migration 4's `basket_sessions` table (columns: session_hash,
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
