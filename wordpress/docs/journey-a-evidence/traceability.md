# Traceability — #40 common spec and #41–#48 acceptance criteria

Legend: **[E]** executed offline evidence (suite names refer to the gate
`FREEPLAST_SKIP_STACK=1 npm test`; layers and their limits in
[README.md](README.md)); **[S]** implemented stack checks — written but
**UNRUN** (they execute inside `woo-stack-harness.mjs` only when the
disposable stack is authorized); **[B]** prepared browser protocol — UNRUN
([visual-matrix-procedure.md](visual-matrix-procedure.md)); **[H]** owner
human/physical check — UNRUN and not delegable. Multiple tags = the
criterion needs several layers; no layer alone claims it met. Lead final
checkpoint:504 adapter +421 aggregate, plus the reported PHP suites;
`offline-gate.txt` is the exact log. Added native-chrome13 (actual
ClassicTemplate hooks and WordPress skip-link HTML parser) and fingerprint6
(stability, input coverage, version, fail-closed). These are not browser tests.

## #41 Shared chrome (header/menu/footer/help, count)

- Access to exact A artifact verified; reproducible access documented — **[E]**
  (`fingerprint.sh` source-A blobs; test-woo-adapter chrome contract) +
  README §1.
- Scoped visual contract registered preserving historical references — **[E]**
  (design/DECISIONS.md supersession sections; contract asserts Manrope
  @font-face + files, palette tokens, no review-only tooling).
- Header/menu/footer/help reproduce A, sticky, admin-bar aware, no island —
  **[E]** markup/behavior (`chrome-nav-test` 25; adapter sticky/contract
  assertions) / **[B]** rendered + scroll/sticky / **[H]** phone.
- Real destinations; Home/Nosotros/Contacto/privacidad available; ordinary
  pages unaffected — **[E]** (contract + fixture pages) / **[S]** route
  checks (`/nosotros/`, `/politica-de-privacidad/` 200; corporate body
  untouched — verified in code, no product grid on ordinary pages).
- Count = native distinct lines across add/visit/remove/reload — **[E]**
  (adapter fragment/projection suites, controls bridge) / **[S]** §2d
  journey (add → other page → reload → Store-API removal → zero).
- Keyboard menu/help, focus return, no prototype shortcuts — **[E]**
  (chrome-nav) / **[B]** Esc/backdrop native behaviors.
- Fixture: 17 products, Cart block, classic checkout reachable — **[E]**
  (bootstrap generator + cart-page contract; execution **[S]**).

## #42 Cards (quantity, add, remove, grid)

- Catalog = editable Woo source, all 17 reachable, edits reflect — **[E]**
  (native loop untouched; card-theme renders adapter fields) / **[S]** shop
  card checks / **[B]**.
- A grid 1/<600, 2@600, 3@1000, aligned actions, reserved added-state,
  transition widths — **[E]** CSS contract + card suites / **[B]** matrix
  boundaries.
- Long names/large qty/referential/pending photos, no overflow, supported
  facts only, no pallet-minimum — **[E]** (card-theme 25: fallback chain,
  placeholder, wrap) / **[B]**.
- Positive-integer quantity via A controls + native add; no invented
  limits — **[E]** (controls 42: stepper clamps to native min/max/step,
  mirror; loop/native-handler 56 incl. invalid-quantity inert adds).
- Repeat add → same line units; card units vs header lines; basket+reload —
  **[E]** (projection/fragment suites) / **[S]** journey.
- Native removal keys; one-line product removes whole units; split keys —
  **[E]** (card-selection 27 split/removal matrix).
- Repeated cards share one projection; stale controls cleared after
  empty fragment/navigation/bfcache — **[E]** (loop suite).
- Variable cards: Elegir color → native sheet; no card picker/default —
  **[E]** (card-theme + colors).
- Mobile dock in A states/routes, native truth, safe-area, desktop none —
  **[E]** (dock bridge/contract) / **[B]** sticky/safe-area rendering /
  **[H]**.
- Pending ops inert (pointer+keyboard), no false success, ≥44px/16px,
  focus after remove — **[E]** (store watcher CTA gate incl. chunked bodies;
  focus suites) / **[B]**.
- Journey two products + unaffected preserved — **[S]** / partially **[E]**
  at store level.

## #43 Discovery (search/filters/count/sort/empty)

- Toolbar as A, no B rail/C panel — **[E]** (catalog-tools 21 + archive
  contract) / **[B]**.
- Accent/case-tolerant native search — **[E]** SQLite accent-fold/order
  tests (native generated SQL executed in-memory) / **[S]** accented query
  check.
- Destacados (native featured term) / Nombre A–Z ordering + coherent state —
  **[E]** (SQLite positive data) / **[S]** orderby check.
- Real routes usable directly; empty/escapable/no-match safe — **[E]**
  (form/query suite incl. escaping; no-results contract) / **[S]**.
- A no-results + catalog return + real help; recovery clears filters only —
  **[E]** contract / **[S]** no-results check / **[B]**.
- Results use full A cards; ordinary searches stay native — **[E]** (native
  search routing isolation; card suites) / **[S]**.
- All reachable; pagination preserves query/order; no 17 cap — **[E]**
  (native query/pagination code and preserved query state) / **[S]** requires
  an enlarged owned fixture; default20 products do not exercise page2.
  This criterion is NOT established by the current fixture.
- Keyboard/focus/responsive — **[B]**.

## #44 Product sheet

- Simple+variable A hierarchy, gallery, quick specs, disclosure, related —
  **[E]** (sheet 32 render contract) / **[B]** desktop/mobile.
- Native facts; referential/pending honesty; no invented data/internal
  notes; pallet ≠ minimum — **[E]**.
- Named colors with sample+selected over the ONE native variation select;
  ids/fields/hooks native — **[E]** (colors 24 two-way sync on real TABLE
  markup).
- No color → semantically unavailable + explanation; valid enables; clear/
  unavailable restores without silent pick — **[E]** (variation-state matrix
  + colors).
- Invalid variation never mutates selection; native validation
  authoritative; pending never fakes success — **[E]**.
- Add keeps product/color/units; repeat same color increments; other color
  distinct lines; header consistent — **[E]** (race/normalization) / **[S]**
  journey variant add.
- Sheet units-of-this-product with explicit meaning; basket link — **[E]**
  (detail-added projection incl. fragment).
- Keyboard/names/focus/sizes — **[E]** (aria wiring) / **[B]**.
- Back to catalog / related keeps basket; native related rules — **[E]**
  (native related loop + projections) / **[B]**.
- Catalog→Elegir color→add→basket→reload, two colors + invalid selection —
  **[S]** + **[B]**.

## #45 Basket (Cart block A)

- Real Cart block kept; no classic/own basket/session/endpoints — **[E]**
  (cart-page 30 contract; no shortcode).
- Lines: product/photo-or-pending/color/qty/explicit removal; A
  list/summary/step/CTA; lines≠units; no public amounts — **[E]** (markup +
  bridge; suppression rules verified against the pinned block build) /
  **[B]** hydrated rendering (modeled store ≠ hydrated evidence).
- Change/remove preserves other lines; block/header/summary/reload agree —
  **[E]** store-level (store harness incl. abort/replace) / **[S]**/​**[B]**.
- Last line removed → A empty state + catalog return; focus not stolen —
  **[E]** (cart-presentation 14 incl. last-line heading + focus identity) /
  **[B]**.
- Pending → CTA inert pointer+keyboard; not a link-disabled — **[E]**
  (watcher CTA gate, both modalities).
- Settlement: no release on headers/abort/debounce; full body + Woo
  application — **[E]** (staged/chunked body scenarios).
- Outcome matrix incl. invalid JSON/interrupted body; no definitive verdict
  while later op pending — **[E]**.
- Settled failure names persisted qty, preserves others, retry without
  accumulation, deliberate continue — **[E]** (retry ledger).
- Journey basket→change/fail/recover→reload→checkout — **[S]**/**[B]**.
- Store tests extended w/ staged Responses; browser = hydrated block (not
  classic fixture) — **[E]**/**[B]**.
- Populated/empty/pending/failure compared vs A; touch/focus/CTA widths;
  block limits reported (see differences) — **[B]**/**[H]**.
- Independent of future slices; no engine change; protections intact —
  **[E]** (all prior suites green).

## #46 Details form

- One classic form; names/nonces/hooks/trigger/hidden attempt preserved —
  **[E]** (form 49 + POST-trigger selftest + native-checkout suite w/ real
  renderer).
- A page: steps, Contacto/Empresa/Despacho/Mensaje, lateral summary /
  mobile disclosure with Editar — **[E]** markup+behavior / **[B]**.
- Required set exact; Mensaje optional; nothing added — **[E]**.
- Dispatch A presentation over the single native value source (radio si/no;
  no contradictory inputs) — **[E]** (native renderer suite; radio tiles).
- Address only with dispatch; Si→No stores no destination; no
  geocoding/charges — **[E]** (adapter matrix + confirmation stored-record
  test).
- Real privacy page; authoritative validation unchanged; no demo regex —
  **[E]**.
- Real errors: linked focused summary, per-field associations, values kept;
  dispatch explained — **[E]** (formUI 25 + native-checkout 29: live group
  enhancement, radio focus, existing associations).
- Editar productos → native selection edit → back keeps fields (native
  draft); review refresh/errors keep fields+Mensaje+attempt — **[E]**
  (native-checkout: Editar waits for native update_order_review response,
  replacement/latest-edit) / **[S]**/**[B]** round trip.
- Valid submit → stored request + real reference (positive control) —
  **[E]** offline (normalization/create-order) / **[S]** full HTTP.
- All-required, both branches, Si→No with address, draft, back, refresh,
  valid submit, persisted data via native verifiers — **[E]** offline
  matrix / **[S]**.
- Summary/AJAX without $0/price markup; ≥16px; mobile/desktop composition —
  **[E]** origin rules / **[B]**.
- Real-form + normalization tests stay green — **[E]**.

## #47 Submission & confirmation

- Unambiguous submitting state (busy semantics, focus, re-activation guard;
  disabled ≠ server protection) — **[E]** (native-checkout: real AJAX
  start/complete + `.processing`; review-fragment replacement cannot unlock
  a pending POST).
- Known pre-save rejection keeps products/options/draft, cause in A
  treatment, retry keeps attempt identity — **[E]** offline / **[S]**.
- Uncertain/lost response: not described as unsaved; original attempt kept;
  authorized recovery; honest wording (public `woocommerce_get_script_data`
  generic-message replacement, not guessed regex classification) — **[E]** (native-checkout transport
  uncertainty) / **[S]**.
- Confirmation A only after durable save/authorized recovery; real
  FP-YYYY-NNNNNN; stored lines/options/quantities; no demo ref/timer/later
  cart — **[E]** (confirmation 27 over stored order; null/invalid context
  refuses assertions). This does not independently prove database durability
  or HTTP authorization; those require the unrun native stack.
- Selection emptied only on native completion; old-recovery keeps newer
  basket — **[E]** (race suites).
- Concurrent/double/refresh: no duplicates; new identical request gets own
  reference — **[E]** (race matrix, notification-event counts) / **[S]**.
- Older-attempt recovery, validity limits, session separation, unknown/
  foreign rejection, safe no-JS POST — **[E]** (stale/unknown/foreign/
  plain-form suites).
- Confirmation copy: sales review, no purchase/stock, no delivery claim;
  guards/admin/history intact — **[E]**.
- A-comparisons for submit/applicable failure/confirmation; truthfulness
  message differences documented — **[B]** + differences.md.
- Mail contained; events counted not delivered — **[E]** (mu-plugin
  contract) / **[S]** live count.
- Normalization/concurrency/recovery/new-attempt/no-price regressions green
  — **[E]**.

## #48 Integration & handoff (this slice — status: PARTIAL by design)

- Blocking tickets delivered their own journeys/evidence; fixed A commit +
  identified integrated version, baseline not gamed — **[E]** (this package;
  fingerprint).
- Public journey over real Woo executed — **UNRUN [S]** (procedure A in
  commands.md); persisted native results beyond screenshots — same.
- Projection consistency (cards/header/dock/selection/summary after
  fragments/history/reload; two-color lines vs units) — **[E]** offline /
  **[S]**/**[B]** live.
- Combined journey with empty/pending/color/error/uncertain/recovery states
  + idempotence/preservation/permissions green — **[E]** offline suites /
  **[S]** full matrix.
- Visual matrix 320…1440 + 600/1000, whole/scrolled/sticky, long names/large
  qty, mobile summary open/closed — **[B]** prepared, UNRUN.
- Composition/loaded Manrope/typographic metrics/spacing/geometry/state
  comparison; only enumerated normalizations — **[B]**; no broad tolerance.
- Keyboard journey incl. dialogs/quantities/colors/errors/submit + reduced
  motion/zoom — **[B]**; SR/hardware not claimed.
- Home/corporate/privacy with shared chrome; no demo controls/shortcuts —
  **[E]** contract / **[S]** route checks / **[B]**.
- Fixture/version/commands/execute-executed-vs-not identified; synthetic
  mutations only; no shared-site ops — **[E]** (this package).
- Explicit pending differences list; deliberate difference needs owner
  approval; failed regression blocks readiness — **[E]** (differences.md;
  no regression failing).
- Package prepares human PC/phone review (focus/sticky/on-screen keyboard
  as observation points); no physical validation claimed — **[E]** (owner
  section); approval stays with the owner.
- No deploy/bundles/staging or production requests/mails; parent issue
  untouched — **[E]** (no such operations performed).
