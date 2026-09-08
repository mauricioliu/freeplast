# A · Directa quotation journey — evidence & handoff package (issue #48)

> Subsequent event: owner-authorized commit/push/staging deployment completed
> with implementation `cf5f1cf` on 2026-09-08. See the
> [release record](../releases/2026-09-08-a-directa.md) for separately executed
> DB/CLI and HTTPS smoke checks. This package retains its earlier offline
> checkpoint; #48 and visual/owner acceptance remain incomplete.

Model/agent: pi, `zai-coding-cn/glm-5.3` (high) — implementation worker
#41–#48. Status of this package: **integration evidence prepared; NOT a
declaration of acceptance**. Every layer below states exactly what it does
and does not prove. Final visual/physical acceptance belongs to the owner on
PC and a real phone.

## 1. Identity of the normative source

- **Frozen visual authority:** A · Directa from the quote-journey prototype,
  local commit **`785e65b502078b67e29bffbd2a7beab8ae408aac`** (branch
  `prototype/quote-journey-20260908`, present in this repository; a
  read-only worktree also exists at
  `~/Projects/freeplast-quote-prototype`). The prototype's simulated
  state/submission app is **never** merged into production code.
- Byte identity is verifiable offline: [`fingerprint.sh`](fingerprint.sh)
  prints sha256 of the prototype's files **at that commit** (read from git
  objects — immune to worktree changes) plus the integrated delivery files.
  A recorded run for this handoff is
  [`fingerprints/integrated-48.txt`](fingerprints/integrated-48.txt).
- Integrated version at handoff: theme **1.0.14**, adapter **1.6.8**,
  `fields.js` **1.0.4** (uncommitted working tree). The final fingerprint
  identifies complete runtime/fixture/test families and actual cached native
  probe sources, not just a hand-picked list. Lead corrections and exact
  offline output are recorded in `differences.md` and `offline-gate.txt`.
  Earlier `/tmp` snapshots mean offline-reviewed, NEVER owner acceptance.

## 2. What the delivered system is

WooCommerce (pinned 11.1.0) + free Quotes for WooCommerce + the minimal
`freeplast-woo` adapter (ADR-0001): native catalog query, native classic
checkout form (one form; nonces/hooks/hidden attempt identity/place-order
literal), native Cart block on `/cotizacion/`, native sessions/drafts,
adapter-owned durable request identity/recovery and unchanged notification
behavior. Mail containment is test-fixture-only, not a change to real mail. No second
basket, no new mutation APIs, no vendor edits, no prices/payments/
registration/stock rules. Design contract decisions:
[`../../design/DECISIONS.md`](../../design/DECISIONS.md) (§ A · Directa
supersession series), operating record:
[`../../WOO-MIGRATION.md`](../../WOO-MIGRATION.md).

## 3. Evidence layers — meaning and limits

| Layer | Where | Proves | Does NOT prove |
| --- | --- | --- | --- |
| Offline PHP render/projection suites | `wordpress/scripts/*-test.php` via the gate | Markup contracts, native field names/ids, escaping, no-price origin rules, stored-order confirmation truth, adapter validation matrix — against real adapter/template source with stubbed WP/Woo environment | Rendered pages, DB persistence or a bootstrapped WordPress application |
| Offline DOM/behavioral suites (jsdom) | `chrome-nav/card-controls/cart-presentation/product-color/checkout-form-js` | Script behavior at DOM/event/store-bridge boundaries: dialogs/focus, steppers, dock+summary bridges, color-control two-way sync, error summary, busy submit | Real browser layout, hydration, screen readers |
| Pinned native code suites | `loop-added-count` (real Woo add/remove handler), `woo-cart-store-harness` (vendored `wc-blocks-data-11.1.0.js` + shipped watcher, real streamed Responses incl. staged/chunked bodies), `checkout-native-test` (pinned `checkout.js`, real jQuery transport, native PHP field renderer) | Native code paths under fully intercepted transport | Live HTTP, real database |
| Operator-flow selftests | `woo-checkout-race-selftest`, `woo-ventas-guard-selftest`, `woo-ventas-state-selftest`, `verify-ventas-role-selftest` | Identity/normalization/recovery/permission control flow with all I/O mocked | Anything against real data |
| **UNRUN:** real-stack harness | `woo-stack-harness.mjs` (+ `bootstrap.mjs` fixture, mail-log mu-plugin, race/ventas scenarios) | (When later authorized) whole public journey over loopback disposable Woo incl. persisted records, notification-event counts | Staging/production, visual acceptance |
| **UNRUN:** hydrated-browser matrix | [visual-matrix-procedure.md](visual-matrix-procedure.md) | (When later authorized) matched-state comparisons vs frozen A, keyboard/focus, loaded Manrope | Physical device experience, screen-reader reality |
| **UNRUN (owner):** PC/phone review | [visual-matrix-procedure.md](visual-matrix-procedure.md) § owner | Final acceptance | — |

Safety rules that govern all layers:
[`../../verification-safety.md`](../../verification-safety.md) (fixture
identity before mutation, synthetic-only data, contained mail, no shared-site
operations). `npm test` **without** `FREEPLAST_SKIP_STACK=1` starts a
disposable local HTTP server — see [commands.md](commands.md) before running
anything.

## 4. Fixture prerequisites (all offline-verifiable)

- Catalog fixture: 17 reference products from `wordpress/data/products.json`
  (idempotent by slug; **15 checksummed reference photographs, 2 explicit
  pending photographs** (`caja-paltera`, `traversa-para-bins-tipo-romano`),
  2 variable products × 5 named colors configured for native Woo;
  actual matching in the live fixture remains UNRUN), plus the synthetic race fixtures (`… (prueba)` simple
  pair + `Caja Variable Color (prueba)`). Checksum refusal runs **before**
  catalog-seeding writes (`bootstrap.mjs` `seedReferenceCatalog`), not
  a claim that WordPress installation has not already written its own data.
- Surfaces: `/tienda/` native shop, `/cotizacion/` real Cart block
  (`scripts/woo-cart.html` canonical markup), `/datos-y-envio/` classic
  checkout, synthetic `/nosotros/` `/contacto/` `/politica-de-privacidad/`
  pages; staging-parity control texts (`qwc_*`, «Continuar con mis datos»).
- Mail containment: disposable-only mu-plugin written by the harness blocks
  every `wp_mail` attempt and appends subject-only lines to
  `wordpress/.build/mail-log.jsonl` — counts are **events**, never delivery.
- Ownership: fixture identity is read back (products/pages/options) before
  any mutating scenario; race/ventas runs own their synthetic records and
  verify provenance tokens; historical data is never touched.

## 5. Package contents

- [Lead review](lead-review.md) + [exact final offline gate](offline-gate.txt)
  supersede stale worker handoff statements; this package remains uncommitted.

- [`fingerprint.sh`](fingerprint.sh) + [`fingerprints/`](fingerprints/) —
  reproducible identity (source-A blobs + integrated tree).
- [`commands.md`](commands.md) — every command executed during #41–#48 (and
  what its output means), plus the exact **authorized-later** procedures
  (real stack, hydrated browser) with safety-first wording.
- [`traceability.md`](traceability.md) — issue-by-issue AC coverage: which
  executable evidence backs each criterion, and which are explicitly unrun.
- [`visual-matrix-procedure.md`](visual-matrix-procedure.md) — the prepared
  matched-state matrix (320/375/412/768/1024/1440 + 600/1000 boundaries),
  state recipes, allowed normalizations, keyboard/focus/reduced-motion/zoom
  protocol and the owner's PC/phone/screen-reader steps.
- [`differences.md`](differences.md) — explicit differences/gaps requiring
  human or native evidence (known items audited against the frozen source;
  none silently accepted).
