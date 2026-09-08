# Freeplast — Woo migration and operating record

Date: 2026-09-05. Scope approved explicitly by the owner: replace the repo implementation and **https://freeplast.mliu.site/**, back up first, preserve catalog/media/history; **do not change freeplast.cl**. Decision: [ADR-0001](../docs/adr/0001-woocommerce-quote-only.md). No paid plugin licenses.

## Staging release — A · Directa (2026-09-08 21:38 UTC)

Owner requested **commit, push and deploy**. Implementation `cf5f1cf` is on
`main` and installed at **https://freeplast.mliu.site/**: theme **1.0.14**,
adapter **1.6.8**, fields.js **1.0.4**. Backup/isolated DB+CLI trial completed;
100 native state checks and 45 installed-source hashes passed on staging.
Catalog, media, orders and historical records preserved; guarded cart/checkout
page content and continuation label updated without rerunning migration.
Read-only HTTPS smoke passed. No browser/device or visual acceptance;
**#48 remains partial**. Earlier “NOT released” sections are historical.

Full release identity, backup, ownership-probe finding, artifact checksums,
prepared rollback and evidence limits:
[2026-09-08 release record](docs/releases/2026-09-08-a-directa.md).

## Issue #48 (allowed portion) — evidence & handoff package (2026-09-08, NOT runtime)

Worker: pi / zai-coding-cn/glm-5.3 (high). No new runtime code: a durable
in-repo evidence package `wordpress/docs/journey-a-evidence/` —
`README.md` (source-A identity `785e65b` + reproducible `fingerprint.sh`
+ recorded `fingerprints/integrated-48.txt`, prerequisites, evidence
layers with explicit limits), `commands.md` (executed vs never-run command
records; safety-first authorized-later procedures; mliu-only external
links), `traceability.md` (#41–#48 AC → executed suites vs UNRUN
stack/browser/owner layers), `visual-matrix-procedure.md`
(320…1440 + 600/1000 boundaries, matched-state recipes, allowed
normalizations, keyboard/reduced-motion/zoom protocol, owner SR/phone
steps), `differences.md` (audited deliberate differences + gaps incl.
CSS-hidden block zero totals; two candidate diffs — confirmation «Editar»
and checkout-CTA placement — were withdrawn after lead source audit, not owner approval,
recorded in that file). Stack harness gains
§48 checks (corporate pages chrome-only without product grid; Home shared
cards) — implemented, UNRUN. Offline gate re-run green after package
(504 adapter + 414 aggregate). No acceptance claimed; matrix/hardware remain
unexecuted.

## Issue #48 — integration evidence and lead review (2026-09-08, PARTIAL / NOT released)

Package: [journey-a-evidence](docs/journey-a-evidence/README.md), with a
reproducible input fingerprint, exact offline gate output, traceability,
matched-state matrix and explicit gaps. Worker: pi / GLM-5.3 high; subsequent
corrections by lead. No commit/push/deploy or GitHub writes.

Lead reproduced native ClassicTemplate's duplicate outer/inner breadcrumb
and fixed its scoped placement + Catálogo/category presentation.13 new PHP
checks execute the pinned native hooks/frame/HTML parser; WordPress's existing
skip-link insertion is proven at that boundary, not falsely reported absent.
Fingerprint generation now covers full runtime/fixture/test input families,
actual cached probe sources and fields.js1.0.4;6 checks catch omissions,
empty versions and failures. Narrow checkout/cart/confirmation gutters follow
A below360px as well as360/600+.

Final permitted gate: **504 adapter assertions + 421 aggregate checks**, all
PHP/native/DOM/regression suites green. This is OFFLINE ONLY. No live HTTP,
hydrated browser, matched-state rendered comparison or owner hardware
acceptance. Native pagination>24 still needs an owned enlarged fixture. A
completed full gate would stop its server; browser setup remains separately
authorized work, not an already-running preview. Presentation differences
(toast channel, extra copy/chrome) remain open in the package; no parity claim.

## Issues #45–#47 — A · Directa basket, details form and confirmation (2026-09-08, implemented, NOT released)

Batch 3 on the lead-reviewed #41–#44 base. Theme **1.0.13 → 1.0.14**;
adapter **1.6.7 → 1.6.8** (dispatch field → native radio si/no, native
first/last row classes, fields.js 1.0.4 enhancing the actual native radios). Native
ownership unchanged: REAL Cart block, ONE classic checkout form (names,
nonces, hooks, hidden attempt, draft session, place-order trigger literal),
native validation/sanitization, adapter recovery/idempotence/mail
containment/ventas guards all intact.

- #45: canonical basket page markup (A steps/heading/rows/empty state; same
  file feeds the fixture and the migration), block styled to A (stepper,
  remove, sidebar summary card with store-driven lines/units), zero-total
  surfaces hidden `!important` (class vocabulary verified against the pinned
  block build), dock excluded from the cart route, new multi-chunk
  staged-body store scenario.
- #46: `form-checkout.php` override (four A groups, summary disclosure
  holding Woo's own review-table fragment target, native payment at the
  foot), `checkout-form.js` enhancement (linked focused error summary built
  FROM the native group, per-field aria wiring, values preserved).
- #47: busy submit + honest ambiguous-transport wording;
  `thankyou.php` A confirmation reading only the stored order (real
  reference, stored lines/options/quantities/dispatch; no demo markers,
  timers, delivery claims, prices).

Lead personally corrected6 failures reproduced with pinned checkout.js/jQuery:
live native errors, full causes and radio focus, DIV transport uncertainty,
and unrelated review completion prematurely releasing submission. Busy now
tracks actual native checkout AJAX; Editar waits for native review/draft save
(latest edits/replacements/failure included), without new persistence. Native
PHP field renderer drives the checkout lifecycle fixture. Hook priority and
extension execution, formatted stored options and invalid-order refusal are
covered. Cart late React DOM, removal identity/focus and progress/uncertain
removal feedback were corrected; quantity body/settlement engine unchanged.
CSS fixes include native47% field widths, actual radio grid and conditional
slot, root widths on true Woo body classes, confirmation820px. These are
source changes, not observed/rendered fidelity.

Lead offline gate `FREEPLAST_SKIP_STACK=1 npm test`: **504 adapter +
27/25/21/32 PHP suites + 30 cart-page + 49 checkout-form + 27 confirmation;
25 chrome + 42 controls + 14 cart-presentation + 24 colors + 25 form helper
+ 29 actual native checkout/jQuery checks, all prior race/recovery/store
scenarios, 414 combined syntax/dependency checks** — 0 failures. Stack additions implemented, **NOT executed** (A basket/details
route checks, dispatch radio contract, attempt identity presence, no-amount
guards); hydrated browser scenarios documented for the authorized run. Not
deployed; no commit/push by the worker; UI unvalidated on hardware.

## Issues #42–#44 — A · Directa cards, discovery and product sheet (2026-09-08, implemented, NOT released)

Batch 2 (lead-reviewed #41 base preserved). Theme **1.0.12 → 1.0.13**
(uncommitted shared tree); adapter **1.6.6 → 1.6.7** after lead review.
Native Woo persistence/mutations unchanged: cards render through
`wc_product_class` + the adapter's loop filter. Adapter projections now include
explicit variable-parent totals without multi-delete, retaining native keys;
explicit ordinary-content searches are no longer coerced into products. The
archive/loop templates keep native hooks, the variation form keeps its
select/ids/hidden inputs (color buttons are an enhancement over the single
`attribute_color` / `attribute_pa_color` select, two-way synced). New surfaces:
`woocommerce/content-product.php` (A card), `woocommerce/archive-product.php`
+ `loop/result-count.php` + `loop/orderby.php` (A discovery),
`woocommerce/content-single-product.php` + `single-product/related.php` (A
sheet), `assets/js/product-color-options.js`, stepper + dock bridges in
`loop-add-to-cart-quantity.js`/`basket-count.js`, mobile selection dock +
detail-added projections as theme-side fragments, loop/single add-to-cart
text filters, breadcrumb/related filters. Block templates lost their
duplicate search blocks; grid scoped to `ul.products` so Home featured
shares the card without Woo body classes. Bootstrap gains staging-parity
control texts. Scoped contract recorded in `design/DECISIONS.md`.

- Lead offline gate `FREEPLAST_SKIP_STACK=1 npm test`: **504 adapter + 27 card
  projection + 25 card theme + 21 catalog form/query/SQLite + 32 product sheet
  checks**, **25 chrome-nav + 32 card-controls + 24 product-color + 56 loop
  DOM/native-handler checks**, prior quantity/race/ventas regressions and
  **311 combined syntax/deployment checks** — 0 failures. Generated discovery
  SQL executes against in-memory SQLite; this is not native HTTP evidence.
  RED proof: pre-batch quantity script fails the stepper suite
  («initSteppers is not a function»).
- Stack extensions implemented, **NOT executed** (execution boundary):
  shop-route card/dock checks, discovery checks (toolbar, counts, two
  ordering options, accented query, category current, A no-results) and
  sheet checks (titles, disclosure, native select + enhancement enqueue,
  related) in `woo-stack-harness.mjs`.
- Lead review corrected duplicate native add hooks (actual WP hook probe
  RED2→GREEN1 callback), nonexistent ordering helper, lost filter/query state,
  wrong featured ordering, accent folding on SQLite, general-search layout,
  AJAX-only dock/detail projections, parent-color units, product-page steppers,
  invalid quantity/duplicate activation, uncertain add feedback, real TABLE-row
  color enhancement/availability, protected-product password guard, short
  excerpt/disclosure placement, placeholder-photo honesty and Woo CSS overrides.
  `inc/catalog.php` owns the bounded native query presentation; no extra catalog,
  public API, basket, checkout persistence or vendor edits were introduced.
- Not deployed; no commit/push by worker or lead. Rendered comparisons and owner
  review pending; UI unvalidated on hardware.

## Issue #41 — A · Directa shared chrome (2026-09-08, implemented, NOT released)

First slice of the approved #40/#41–#48 breakdown; explicit owner
authorization to implement superseded the «no desarrolles aún» hold for
development only. Theme **1.0.11 → 1.0.12** on `main` working tree
(see git status at handoff): the shared chrome (persistent 76/84 px header
bar, native-dialog mobile menu + help, A footer) replaces the v6 island,
burger sheet and dark footer across public routes; Manrope now loads from
the shipped OFL-licensed `assets/fonts/manrope.woff2` (+ preload). Real
destinations only (brand→/, /tienda/, /nosotros/, /contacto/,
/cotizacion/, /politica-de-privacidad/, help channels). The
`span.fpw-basket-count` native count contract is unchanged; its badge
styling moved to a `.count` wrapper that survives the adapter's fragment
replacement. Adapter untouched. Scoped contract documented in
`wordpress/design/DECISIONS.md` (§ «Scoped supersession — A · Directa
quotation chrome»).

- Offline gate `FREEPLAST_SKIP_STACK=1 npm test`: **504 PHP assertions**
  (up from 466 — new A chrome source-contract checks: real destinations,
  native dialogs, font/license presence, no review-only prototype tooling,
  retired-class absence, sticky-bar contract updated to `top:
  var(--fp-admin-offset)` + hairline), **25 chrome-nav behavioral checks**
  (`scripts/chrome-nav-test.mjs`, jsdom: dialog wiring, aria-expanded,
  dialog replacement, focus return, aria-current incl. Woo's
  single-product body class; RED proven against the old header/nav),
  21 card-projection, 49 loop DOM/native-handler, all prior race/recovery
  self-tests and the pinned cart-store scenarios — **0 failures**.
- Disposable-stack extensions implemented but **NOT executed** (execution
  boundary: no server/browser): `bootstrap.mjs` now seeds the 17 reference
  products (slugs/categories/featured/2×5-color variables, 15 checksummed
  reference photographs and 2 explicit pending photographs) from
  `wordpress/data/products.json` as a synthetic fixture, sets `/tienda/`
  as the native shop and points `/cotizacion/` at the **real Cart block**
  (staging parity, classic checkout unchanged per ADR-0001) and backs the
  chrome destinations with synthetic pages; `woo-stack-harness.mjs` gains
  the #41 journey checks (chrome render, native add → count 1 → other page
  → reload → Store-API removal → zero state, Cart-block page, classic
  checkout guard, 17-product fixture, no prototype tooling). PHP eval
  blocks lint clean; run when a stack execution is authorized.
- **Not deployed** to freeplast.mliu.site; no commit/push made by the
  worker or lead. Lead independently reran the offline gate (504 adapter,
  21 card PHP, 239 aggregate checks) and corrected queued-dialog focus races,
  menu CSS specificity/320px treatment, non-scoped corporate line-height and
  missing native shop/media fixtures. Fixture payload generation + PHP lint
  also passed without running WordPress. Extra corporate-menu/privacy links
  remain explicit owner-review differences, not accepted visual exceptions.
  Side-by-side 412/1440px comparisons and human PC/phone review remain open;
  no browser/device run.

## Owner-authorized staging release — 20260908T112200Z (persistent island navigation)

Owner requested the whole header remain visible when scrolling down, then
explicitly requested **commit, push and deploy**. Source commit **`be3ed73`**
pushed to `main`; theme **1.0.10 → 1.0.11** installed on
**https://freeplast.mliu.site/**. Adapter **1.6.6** unchanged and not reinstalled.
No migration, vendor update, catalog/media change, nginx change or `freeplast.cl`
operation.

- Sticky positioning now belongs to the outer `header.wp-block-template-part`
  directly under `.wp-site-blocks`, instead of the island child constrained by
  its header-height parent. Preserves normal flow and the native menu dialog.
  Clears the fixed WordPress admin bar above 600px using its native height;
  below that width the toolbar scrolls away. Anchor/focus scroll padding includes
  that offset and outspecifies WordPress admin-bar CSS.
- Added **19 source-contract assertions** (not layout evidence): reproduced RED
  against the old outer-header rule, then GREEN. Full `npm test` passed:
  **466 PHP assertions**, 21 card-projection assertions, **100 real-stack checks**
  (314 combined syntax/JS/deployment/stack checks). Disposable HTTP runner exited;
  no persistent development server, browser/device run or visual approval.
- Fresh paired backup `/root/freeplast-wordpress-backups/20260908T112200Z/`:
  hashes verified; isolated DB+CLI-only restore rehearsal recovered **17 catalog
  products / 7 Woo requests / 2 original requests**, matching live. Temporary
  rehearsal containers/volumes/network removed; no rehearsal HTTP listener.
- Immutable bundle `/opt/freeplast-wordpress/bundle/releases/20260908T112200Z/`:
  transfer hashes verified; theme ZIP SHA256
  `9825702fd83502ce6b7dcc8548c648da8e89d1f2053e92daacccc6eda4a7bcdb`.
  Adapter ZIP byte-identical to the prior release (`8841e535…`). Prior installed
  source matched all 31 baseline hashes before release; after maintenance-window
  theme installation all **31 installed source hashes** match the new bundle.
- Native WP-CLI verifier: **100 state checks passed**. Pre/post full record
  digest unchanged:
  `88abfd9594e860677262ec248b66dc031d8e35600539002a6bcad36193a88482`.
  Mail-containment MU plugin unchanged (`71164446…`); cache flushed, maintenance
  deactivated, existing DB healthy and WordPress running.
- Public **GET-only** checks: Home, `/tienda/`, `/cotizacion/`, `/?s=caja` all
  HTTP 200 with the direct-child header markup and **1.0.11** stylesheet URL.
  Served `style.css`, `woo.css` and `nav.js` match source bytes. Noindex retained;
  `X-Powered-By` absent. No public cart mutations or synthetic requests created.
  **Scroll behavior still needs human phone review; HTTP/source checks are not
  visual acceptance.**
- Private scripts: `/root/freeplast-release-20260908T112200Z/` remote;
  `/tmp/freeplast-publish-20260908T112200Z/` local. Local logs:
  `/tmp/freeplast-sticky-release-tests.log`,
  `/tmp/freeplast-backup-20260908T112200Z.log`,
  `/tmp/freeplast-deploy-20260908T112200Z.log`.
- Paired rollback, if required (not executed):
  `bash /root/freeplast-release-20260908T112200Z/restore-woo-backup.sh /root/freeplast-wordpress-backups/20260908T112200Z --execute`.
  This restores DB and files; evaluate any subsequent human writes first.
  Previous code artifacts remain in release `20260908T095829Z`.

## Per-product card quantity + native card removal — 2026-09-08 (released 20260908T095829Z)

Owner clarified the circled card pills: each must show the **units of that
product**, not the header's distinct-line total, and offer removal directly
there. The 1.0.9 interpretation below was incorrect for the cards. Header
semantics remain unchanged.

- Theme **1.0.10**, adapter **1.6.6**. Each simple-product loop card renders its
  own Woo cart quantity and a separate full-width **Quitar** button underneath.
  The server renders these controls on navigation too; absent products keep an
  empty slot. A dedicated complete Woo fragment carries the per-product markup,
  including the empty-cart snapshot. The JS maps by product identity, updates
  duplicate cards and removes stale controls after native removal. No independent
  cart/session, optimistic increments or custom mutation endpoint.
- `wc-add-to-cart` owns add/remove, request queuing, keyboard activation,
  pending-row blocking and success announcements. Removal carries that line's
  `cart_item_key` and Woo's nonce-protected URL as the native failure/no-JS
  fallback. Current simple catalog products have one native line each, so
  **Quitar removes all units of that product**. If a future extension splits one
  product across keys, each line retains a separately labelled native removal.
- `wc-cart-fragments` is explicitly enqueued for session/cache restoration;
  fragment-load/refresh events update cards, and bfcache return requests a native
  refresh. Unchanged controls retain focus; removing a focused control returns
  focus to that card's Add link. Layout uses separate >=44px controls, wrapping
  for large quantities rather than squeezing the removal action inside the pill.
- Repro `node wordpress/scripts/loop-added-count-test.mjs` failed on the old code
  with **5 !== 3**. Now 49 DOM/jQuery assertions exercise the delivered script,
  PHP-rendered fixture and byte-identical pinned Woo 11.1.0 add/remove handler
  (mocked transport/overlay plumbing): different quantities, repeat-add queue,
  duplicate cards, unrelated-product preservation, empty/re-add, malformed
  payloads, native Space removal, pending cleanup and focus restoration.
  `card-selection-test.php`: 21 projection/escaping/identity assertions.
- `FREEPLAST_SKIP_STACK=1 npm test` passed existing and new offline regressions.
  On the owner's subsequent commit/push/deploy request, full `npm test` also
  passed: **100 real-stack checks**, 447 existing PHP assertions, 21 new PHP
  projection assertions, 49 DOM/native-handler assertions (314 combined
  syntax/JS/deployment/stack checks). The disposable HTTP test runner exited;
  no persistent dev server, browser/device interaction or visual approval.
  Paired backup/restore rehearsal and verified installation completed below;
  human must still inspect the cards on the phone.

## Owner-authorized staging release — 20260908T095829Z (per-product quantity + card removal)

Owner requested **commit, push and deploy**. Source commit **`24b2df7`** pushed
on `main`, then shipped adapter **1.6.6** and theme **1.0.10** only. No migration,
Woo/Quotes vendor update, media/catalog change, nginx change or `freeplast.cl`
operation. Operator: pi session at owner's request.

- Paired backup `/root/freeplast-wordpress-backups/20260908T095829Z/`, checksums
  verified; isolated DB+CLI-only restore rehearsal verified **17 products / 7
  Woo requests / 2 original requests**, matching live. Rehearsal resources
  removed; no HTTP service started in that rehearsal.
- Immutable release `/opt/freeplast-wordpress/bundle/releases/20260908T095829Z/`;
  transferred files verified before install. ZIP SHA256:
  - adapter: `8841e535a2f1bc5f9565b3c86e0c7724a7ad688b5e594dc0a3784ca3ab974cd5`
  - theme: `0622a8e46f16af1d25346b6b320e7a42c584fd6b274fbea392570550f0c54e7d`
- Maintenance-window install: all **31 installed source hashes** matched;
  native WP-CLI verifier **100 state checks passed**. Pre/post record digest
  identical (`88abfd9594e860677262ec248b66dc031d8e35600539002a6bcad36193a88482`):
  posts/meta, notes/meta and order items/meta preserved. Mail MU hash unchanged
  (`71164446…`); cache flushed and maintenance deactivated. Existing WordPress
  and MariaDB containers/images left in place; DB still healthy.
- Public read-only checks: Home, `/cotizacion/` and `/?s=caja` HTTP 200;
  Home serves per-product slots, complete snapshot, native add/remove + fragment
  dependencies and version **1.0.10** assets. Served JS/CSS match source bytes.
  Noindex remains, `X-Powered-By` absent. No public add/remove POST or submitted
  request was generated; no browser/device/visual approval claimed.
- Private operator scripts/artifacts:
  `/root/freeplast-release-20260908T095829Z/` (remote),
  `/tmp/freeplast-publish-20260908T095829Z/` (local). Local gate/deploy logs:
  `/tmp/freeplast-card-release-tests.log`,
  `/tmp/freeplast-backup-20260908T095829Z.log`,
  `/tmp/freeplast-deploy-20260908T095829Z.log`.
- Paired rollback, if required, on OpenClaw:
  `bash /root/freeplast-release-20260908T095829Z/restore-woo-backup.sh /root/freeplast-wordpress-backups/20260908T095829Z --execute`.
  The repo's restore script and companion `staging.sh` are preserved at that
  private path. Restores both DB and files; consider any later human writes
  before choosing a full rollback. Previous code-only artifacts also remain in
  release `20260908T031702Z`.

## Count pill on cards + visible per-line removal — 2026-09-08 (theme 1.0.9, released 20260908T031702Z; card meaning superseded above)

Owner request (screenshot of Home cards): after «Agregar a Cotización», the
count of added items should appear where the native «Ver carrito» link lands,
plus a way to remove items. Two presentation-only surfaces, theme only
(**1.0.8 → 1.0.9**, style.css header and cache-bust constant together); no
adapter change (1.6.5 zip byte-identical), no migrations, no vendor updates.

- Count pill: Woo's own AJAX add appends the native `.added_to_cart` link and
  fires `added_to_cart` carrying the add-to-cart fragments — the same payload
  the header count uses (`span.fpw-basket-count`, distinct cart lines,
  `fpw_cart_line_count()`). New theme script `loop-added-count.js` (enqueued
  on every route with a `jquery` dependency, inert without the event) parses
  that fragment and re-renders EVERY `.added_to_cart` on the page as the pill
  «N en cotización» (tint band, blue badge, 44px touch target, one line even on
  a ~180px two-column phone card; aria-label starts with the visible text and
  names the destination). The render is deferred one task so it covers the link
  Woo appends inside its own listener regardless of script binding order; an
  unusable payload leaves Woo's links untouched; without JavaScript the native
  link is kept. The number is always Woo's own fragment value — no parallel
  count (ADR-0001). The count remains distinct lines, the header's frozen
  definition, not summed units.
- Per-line removal already existed on the quote list — the cart block renders
  an «Eliminar {producto} del carrito» icon button per line — but as a bare
  24×24 glyph beside the quantity stepper it read as decoration, and Woo's own
  cart.css hides it inside narrow containers (`@container max-width:699px`)
  and the `.wc-block-cart` layout. `woo.css` restyles it as a 44px bordered
  button (existing hues, hover/focus/disabled states) through a selector chain
  that outspecifies both Woo's base skin and the narrow-container hiding;
  removal itself stays Woo's own handler — no cart mutation code (ADR-0001).
- Locked by: 26 offline assertions (strict fragment parsing, all-links
  re-render, idempotent badge, unusable payloads, deferred render covering the
  link Woo appends in the same event, no-bind without jQuery) and 2 new
  real-stack probes (loop routes enqueue the script; the classic
  `?wc-ajax=add_to_cart` answer carries a numeric `span.fpw-basket-count`
  count) — stack suite 96 → 100. Observed on the disposable stack at 412/1440
  (pill render, both pills updating together, native removal + header refresh);
  no browser/device validation claimed.

## Owner-authorized staging release — 20260908T031702Z (count pill + visible removal)

Owner requested commit, push and deploy for the count-pill/removal work.
Commit `e7f40f1` (pushed to `mauricioliu/freeplast` main) shipped **freeplast
theme 1.0.8 → 1.0.9**, adapter unchanged at 1.6.5. Full gate re-run on the
committed tree before push (offline + 100 real-stack + syntax, all green).
Release chain, all green:

- Paired backup `/root/freeplast-wordpress-backups/20260908T031702Z` with
  isolated DB+CLI-only restore rehearsal (catalog/orders/originals **17/7/2**,
  identical to live; rehearsal containers/volumes removed).
- Immutable bundle `/opt/freeplast-wordpress/bundle/releases/20260908T031702Z/`
  (transfer SHA256-verified); maintenance-window install; **31 installed source
  files** match their packaged hashes (30 + the new `loop-added-count.js`);
  native WP-CLI verifier **100 state checks passed**; pre/post record
  fingerprint identical (`88abfd95…` — the same value as every 2026-09-07
  release). Mail MU byte-identical (`71164446…`). Cache flushed, maintenance
  deactivated.
- Public read-only HTTP checks: home 200 with `woo.css?ver=1.0.9` and
  `loop-added-count.js?ver=1.0.9` enqueued; «Agregar a Cotización» live on the
  featured grid; served `loop-added-count.js` and `woo.css` byte-identical to
  the repo; `/cotizacion/` 200; `/?s=caja` renders 10 products; noindex
  retained; `X-Powered-By` absent. No add-to-cart mutation was fired against
  production (the data contract is locked by the stack harness on the exact
  shipped bytes; a production POST would have broken the just-proven record
  fingerprint with an anonymous session).
- Local release scripts: `/tmp/freeplast-publish-20260908T031702Z/`; private
  remote operator scripts: `/root/freeplast-release-20260908T031702Z/`.

## Owner-authorized staging release — 20260907T225331Z (search styling fix)

Owner screenshot of live `/?s=caja`: products rendered as an unstyled bullet list
(one column, default links and form controls) although every stylesheet loaded.
Root cause: all catalog CSS is scoped to the `woocommerce` / `woocommerce-page`
body classes, and `wc_body_class()` only emits that pair when its page
conditionals match — a plain search matches none, but since 1.6.4 the adapter
turns it into a product loop anyway. The native `/?s=caja&post_type=product`
route always satisfied `is_woocommerce()`, which is why only plain searches
looked broken and the HTML-level release checks stayed green. Fix (adapter
**1.6.5**): a `body_class` filter in the same seam as the search hook, emitting
the same pair `wc_body_class()` emits for `is_woocommerce()`, front-end searches
only; locked by two new stack-harness probes (found + no-match searches must
ship both classes; stack suite 94 → 96) and headless-Chrome A/B on the
disposable stack at 1440/412 against `/tienda/`.

Owner requested "commit, push y deploy" for the search-results fix. Commit
`df6178f` (pushed to `mauricioliu/freeplast` main) shipped **freeplast-woo
1.6.4 → 1.6.5**, theme unchanged at 1.0.8 (one installed file differs from the
live tree: `wp-content/plugins/freeplast-woo/freeplast-woo.php`). Full gate
before commit: 447 offline assertions + 96 real-stack checks + 260
syntax/dependency/deployment checks. Release chain, all green:

- Paired backup `/root/freeplast-wordpress-backups/20260907T225331Z` with
  isolated DB+CLI-only restore rehearsal (catalog/orders/originals **17/7/2**,
  identical to live; rehearsal containers/volumes removed).
- Immutable bundle `/opt/freeplast-wordpress/bundle/releases/20260907T225331Z/`
  (transfer SHA256-verified); maintenance-window install; **30 installed source
  files** match their packaged hashes; native WP-CLI verifier **100 state
  checks passed**; pre/post record fingerprint identical (`88abfd95…` — the
  same value as after both releases of 2026-09-07 morning/evening). Mail MU
  byte-identical. Cache flushed, maintenance deactivated.
- One precondition failed safe BEFORE maintenance mode: the bundle
  `sha256sum -c` line used the container path (`cd $RELEASE`) on the host —
  the 2026-09-07 lesson again; corrected to the host-relative
  `bundle/releases/<stamp>`. No public impact, no state touched.
- Public read-only HTTP checks: `/?s=caja` 200 renders **10 products** with
  body classes `woocommerce woocommerce-page` present (the fix), plural
  `cajas` identical, no-match search ships the classes plus WooCommerce's
  native empty-results message, noindex retained, `X-Powered-By` absent,
  `/cotizacion/` 200, wp-admin → login, home assets at `?ver=1.0.8`.
  Headless-Chrome renders at 1440/412 match the `/tienda/` grid. **Zero
  native template overrides; zero Ventas-role accounts.**
- Local release scripts: `/tmp/freeplast-publish-20260907T225331Z/`;
  private remote operator scripts: `/root/freeplast-release-20260907T225331Z/`.

## Button label «Agregar a Cotización» and card quantity selector — 2026-09-07 (deployed in 20260907T215925Z)

Owner request (screenshot of Home cards): the add-to-cart button now reads **«Agregar a Cotización»** (was «Agregar a Productos a Cotizar») and every simple-product card offers a **quantity selector**. Covered surfaces: loop cards (`woocommerce_product_add_to_cart_text`), the anchors' aria descriptions (`woocommerce_product_add_to_cart_description`) and both product-sheet buttons (`woocommerce_product_single_add_to_cart_text`, which Woo's templates call directly, bypassing `add_to_cart_text`). The basket itself keeps its approved name «Productos a Cotizar» everywhere else (header, cart page title, add-to-cart notice, «Ver Productos a Cotizar» link).

The selector is presentation only (ADR-0001): `woocommerce_loop_add_to_cart_link` wraps — never rewrites — Woo's own anchor with Woo's own quantity input (`woocommerce_quantity_input`, so min/max/step and the sold-individually behavior stay Woo's), and the AJAX quantity remains the anchor's own `data-quantity` attribute, which pinned Woo 11.1.0 `add-to-cart.js` reads from the DOM dataset at click time. Theme script `loop-add-to-cart-quantity.js` (enqueued on every route, inert without the wrapper) mirrors the input into that attribute; without JavaScript the anchor adds one unit, exactly as before. Variable, grouped, unpurchasable and out-of-stock cards keep the native link to their product sheet. Theme **1.0.7 → 1.0.8** (style.css header and cache-bust constant moved together); no plugin version bump for the UI itself (behavior-compatible; the same release's search fix bumped the adapter to 1.6.4).

## Owner-authorized staging release — 2026-09-07T21:59:25Z (search fix + owner card UI)

Owner requested commit, push and publication. Commit `f29422f` (pushed to
`mauricioliu/freeplast` main) first captured the whole working tree — including
the 1.6.1–1.6.3 review fixes that had been live-but-uncommitted since the
19:38Z release — then the release shipped **freeplast-woo 1.6.3 → 1.6.4** and
**freeplast theme 1.0.7 → 1.0.8**. No vendor update, migration/bootstrap,
catalog synchronization, Nginx change or production operation was run.

- Catalog search root cause and fix: the search hook already forced
  `post_type=product` and the main query found the products, but
  `wc_setup_loop()` only reads real totals from the global query when the
  `wc_query` var is present (Woo sets it via `product_query()`, product
  archives only), so `archive-product.php` skipped its loop and rendered an
  empty `<ul>` for plain `/?s=…` searches. The hook now also sets
  `wc_query=product_query`. Diagnosed by staging instrumentation
  (parse_query/pre_get_posts/posts_request logs + direct SQL) and proven by a
  local real-stack A/B; locked by three new stack-harness probes
  (stack suite 91 → 94 checks).
- Full gate before commit: **447 offline assertions + 94 real-stack checks +
  258 syntax/dependency/deployment checks**.
- Fresh paired backup: `/root/freeplast-wordpress-backups/20260907T215925Z`.
  SHA256 verified; isolated DB + CLI-only restore recovered **17 products /
  7 Woo requests / 2 original requests**; the unique rehearsal project's
  containers/volumes were removed; no rehearsal web listener.
- Immutable server bundle: `/opt/freeplast-wordpress/bundle/releases/20260907T215925Z/`.
  Maintenance-window install; **30 installed source files** match their
  packaged hashes. Native WP-CLI verifier: **100 state checks passed**.
- Pre/post SHA256 of complete posts, postmeta (excluding editor-presence keys),
  comments, commentmeta, Woo order items and itemmeta identical
  (`88abfd95…`, same as after the 19:38Z release). No fixture, note, account
  or request created. Mail-containment MU plugin kept byte-identical
  (`71164446…`). Cache flushed, maintenance deactivated.
- Public read-only HTTP checks: home 200 with theme assets at `?ver=1.0.8`;
  `/?s=caja` renders **10 products** (11 matches, page 2 renders the last)
  with `search-results` body class; `/?s=cajas` renders the same (plural
  normalization); a no-match search renders WooCommerce's native empty-results
  message; noindex retained; `X-Powered-By` absent; `/cotizacion/` 200;
  `/datos-y-envio/` still redirects to `/cotizacion/`; orders admin still
  requires WordPress login; «Agregar a Cotización» labels live;
  `loop-add-to-cart-quantity.js` served byte-identical to the repo.
  **Zero native template overrides; zero Ventas-role accounts.**
- Local release logs/scripts: `/tmp/freeplast-publish-20260907T215925Z/`;
  private remote operator scripts: `/root/freeplast-release-20260907T215925Z/`.

This deploy is not native HTTP mutation regression or visual acceptance. No
browser/device review, checkout submission, authorization mutation probe,
fixture provisioning, mail delivery or role-account creation was performed.
Native #37–#39 scenario HTTP execution and the human visual decision remain
pending.

## Owner-authorized staging release — 2026-09-07T19:38:40Z

Owner explicitly requested uploading the changes to **https://freeplast.mliu.site**
after the offline closeout. Updated only **freeplast-woo 1.0.2 → 1.6.3** and
**freeplast theme 1.0.0 → 1.0.7** from the current uncommitted tree. No vendor
update, migration/bootstrap, catalog synchronization, Nginx change or production
operation was run. No commit/push/GitHub issue changes.

- Fresh paired backup: `/root/freeplast-wordpress-backups/20260907T193840Z`.
  SHA256 verified; isolated DB + CLI-only restore recovered **17 products /
  7 Woo requests / 2 original requests**. No rehearsal web listener was started;
  only the unique temporary project's containers/volumes were removed.
- Immutable server bundle: `/opt/freeplast-wordpress/bundle/releases/20260907T193840Z/`.
  Installation under maintenance; **29 installed source files** match their
  packaged hashes. Native WP-CLI verifier: **100 state checks passed**.
- Pre/post SHA256 of complete posts, postmeta (excluding editor-presence keys),
  comments, commentmeta, Woo order items and itemmeta unchanged. No fixture,
  note, account or request created. Independent mail-containment MU plugin kept
  byte-identical. Cache flushed and maintenance deactivated.
- Public read-only HTTP checks: home/cart 200, empty checkout redirects to cart,
  orders admin requires WordPress login, noindex remains, runtime header absent.
  Home loads theme assets at `?ver=1.0.7`; downloaded quantity JS matches source.
  **Zero native template overrides; zero Ventas-role accounts currently exist.**
- Local release logs/scripts: `/tmp/freeplast-publish-20260907T193840Z/`;
  private remote operator scripts: `/root/freeplast-release-20260907T193840Z/`.
  Pre-release offline gate still **434 PHP assertions +163 checks**.

This deploy is not native HTTP regression or visual acceptance. No browser/device
review, checkout submission, authorization mutation probe, fixture provisioning,
mail delivery or role-account creation was performed. Native #37–#39 scenarios
and the human visual decision remain pending.

## Direct closeout #37–#39 — 2026-09-07 (implementation phase, before deployment)

Current evidence and safe operator procedure: [verification-safety.md](verification-safety.md).
**No deployment, server, HTTP, browser or device run** occurred in this closeout.
The independent offline gate passes **434 PHP assertions + 163 checks** (including
97 real pinned-store assertions, 7 plain-form payload checks, 10 Python
control-flow test methods and 10 native-data digest checks). No commits or issue
state changes were made.

- **#37:** native tax/coupon and scoped core note/meta guards remain. The worker's
  intermediate verifier was rejected: undefined helper, lossy HTML parser and a
  test that erased failures. Those were replaced by actual mocked module flows
  and complete native data digests via a read-only, disposable-only WP-CLI helper.
  No body/nonce/customer values appear in failed-check output. Native valid-nonce
  guarded/guard-off/restored scenarios are prepared, not executed.
- **#38:** staging verifier now requires a fresh operator fixture receipt, verifies
  exact stored run provenance and native creation time, aborts before all dependent
  probes on failure, and always attempts cleanup of its uniquely owned account.
  Historical `--order-id` / search fallback removed. See the new provisioning
  helper and explicit mail-containment preconditions. Trash nonce unavailable is
  reported as not run, never counted as authorization evidence.
- **#39 (theme 1.0.7):** fetch headers no longer settle quantity work. The observer
  waits for the real body reader and the following task (Woo applies its response
  in promise continuations), then uses one shared pending predicate for feedback
  and CTA activation. Streamed success/error/interruption/invalid-JSON tests pass;
  unchanged focus behavior and visual tokens still require human screen review.

The older entries below are historical implementation reports. In particular,
#37's intermediate test counts/snapshot claims and the old staging invocation
are superseded by this closeout, not evidence of native execution or approval.

## Acceptance review update — 2026-09-05

[Post-migration review and evidence](../docs/reviews/woo-acceptance-2026-09-05/README.md): **not ready for operational acceptance**. Two simultaneous submissions from one anonymous session created duplicate orders **67/68**; the retained `ventas_freeplast` role lacks Woo order capabilities. Additional findings: silent quantity rollback on network failure, eight unnamed Home links, and the variable-product button's missing disabled semantics/low contrast. No fixes or new release were deployed in this review.

Offline checks: **207 local assertions + 126 syntax/dependency/deployment checks**, the latter including the real pinned cart-block store scenarios for #26/#34 and the disposable local WP+Woo stack regression (real HTTP Home card contract + bounded concurrent-checkout repetition + attempt-identity rotation and retry recovery, issues #24/#31) below. The restricted-user login, physical mobile acceptance and performance measurements remain pending. See the review for exact scope rather than interpreting these checks as full acceptance.

## Intermediate worker implementation — issue #37 (superseded above) — 2026-09-07

Follow-up of #33/#25 (untouched). Findings: the denylist missed native tax recalculation (`woocommerce_calc_line_taxes` — the pinned `TaxesController` gates on `edit_shop_orders` alone and SAVES the submitted items before recalculating), and the staging deletion probe sent no nonce (CSRF coverage mislabeled as authorization). Working tree, uncommitted (adapter **1.6.3**, pending review/merge/deploy):

- **Denylist family closure:** `woocommerce_add_coupon_discount` (same single-cap gate through `CouponsController`) and `woocommerce_order_add_meta`/`woocommerce_order_delete_meta` (also natively `manage_woocommerce`-gated — denied at the front door as defense in depth) join `woocommerce_calc_line_taxes`. Bounded route-to-guard matrix with sources in the implementation report.
- **Core note/meta routes, scoped to ORDER targets, resolved per HANDLER:** WP maps `edit_comment`/`delete_comment` onto `edit_post` of the ORDER (`wp-includes/capabilities.php`), which the role carries — so core `edit-comment`/`delete-comment`/`replyto-comment` AJAX, `comment.php` WRITE actions, `edit-comments.php` bulk (incl. the `action2` select and the timestamp delete-all branch) and core `add-meta`/`delete-meta` on the order post are denied for order-limited staff only, before any write. Target resolution matches each handler's CANONICAL field (edit-comment→`$_POST['comment_ID']`, delete-comment→`$_POST['id']`, replyto→`comment_post_ID`, delete-meta→meta id→post, add-meta→post_id AND every `$_POST['meta']` key of its UPDATE branch, comment.php editedcomment→`comment_ID` while status actions read `c`); contradictory benign decoy fields cannot mask the real target (adversarial controls offline + native). comment.php's read-only `editcomment` VIEW stays allowed. Sales Notes stay append-only; non-order comments/posts, managers and admins untouched.
- **Restricted UI no longer offers the items editor's denied controls:** for order-limited staff the read-only style additionally hides Recalculate, Add item(s), Apply coupon, Refund, the per-line edit/delete links and the tax-delete links, and makes the per-line quantity inputs inert — narrowly: lines, names, options, chosen values and quantities stay fully readable and private note creation is untouched (presentation layer only; every control is denied server-side; admin/manager UI unchanged). User-facing change, unvalidated on hardware.
- **Evidence (executed offline only):** 356 → **434 local assertions** + the plain-route (7) and ventas-helper (13) self-tests, **106 checks** total (`FREEPLAST_SKIP_STACK=1 npm test`). The REAL vendored pinned `TaxesController`/`CouponsController` (hash-chained sidecar) are dispatched with a valid nonce fixture and persistence tripwires: restricted actor dies at the front door before any boundary; with the guard removed the controllers REACH the write boundary (qty 999 saved / order object mutated) — the positive controls prove the probes detect a removed guard; managers keep the function. Family audit as data: every order-record-mutating `wp_ajax_woocommerce_*` member asserted present in the denylist, kept surfaces asserted absent. Core note/meta guard covered per route and per scope (order vs non-order, staff vs manager). RED-verified both closures. Operator-run (NOT executed): `woo-ventas-guard.py` gains valid-nonce tax-recalc/coupon/core-note-edit/delete/comment.php/add-meta denials with record-unchanged verification, guard-off positive controls (tax recalc writes qty 999; core edit-comment rewrites a note) and a restored-guard recheck; the harness guard-off mu-plugin also lifts the new guard. `verify-ventas-role.py` deletion probe repaired: scrapes the trash nonce the native list renders for the actor (valid actor nonce via the legitimate UI surface), demands the capability wall refuse it, and records UNAVAILABLE honestly if the role is not offered the link. No staging execution (#38 owns the verifier's fail-closed repair). No UI text changed beyond existing notices; unvalidated on hardware.

## Independent per-attempt recovery lifetime — issue #36 (SP-03) — 2026-09-07

Finding SP-03 (P2) of the 2026-09-07 review of Ralph's #31–#34 batch (follow-up of #32, built on #31's lifecycle and #35's corrected submitted identity — both untouched): the session kept only the LATEST landing binding, so after B completed in the same session, A could no longer recover through the early authorized path even inside its declared lifetime; with an emptied basket the native checkout then rejected A's retry. Implemented in the working tree, uncommitted (adapter **1.6.2**, pending review/merge/deploy — NOT merged and NOT executed on any stack in this change). It supersedes the #35-planned `stale` native expectation (older-form rejection) with recovery — the old code lost A, so rejection was the safe answer; both scenarios were operator-planned, never executed, so no shipped contradiction exists:

- **Independent, immutable, durable bindings — no eviction, no admission cap:** each completed attempt writes its OWN `fpw_recovery_<hash>` row (session fingerprint + order + landing time) as a PLAIN INSERT that is never rewritten: the landing time — and with it the attempt's own vigencia (`FPW_RECOVERY_MAX_AGE`, one day) — is immutable, so folding, replaying or re-landing never extends it. The session keeps only the single latest record (rotation signal); no history map and no LRU/cap can evict a still-eligible attempt. Rows are swept only past vigencia + 1 h grace (`fpw_sweep_recovery_rows`); the permanent attempt lookup rows are untouched. A first landing is the only moment a row is born (`fpw_insert_recovery_row` refuses when the durable binding already exists, and `woocommerce_checkout_order_created` records the landing BEFORE finalization writes the lookup) — a swept attempt cannot be reborn by re-landing, and `fpw_mark_attempt_landed` sources the session record's time from the immutable row (or writes a non-authorizing rotation-only record when the row is gone): the legacy pre-#36 session-record fallback can never reauthorize or extend a post-#36 attempt after expiry/sweep.
- **Recovery for any eligible attempt (criteria 1–4):** `fpw_recover_landed_attempt` now authorizes per attempt — durable lookup row + THIS attempt's recovery row (or, for pre-#36 sessions, the single session record) resolving to the same order of THIS session, inside ITS OWN lifetime, always behind Woo's own nonce. A recovers after B completed, over an empty basket or a third selection, read-only, repeatedly, creating nothing; B stays recoverable while A is expired — expiry per attempt, never extended by another attempt's activity.
- **No destructive fallback for completed attempts (lead red-gates 1–3, fresh and expired):** the identity gate closes the whole class — any COMPLETED attempt whose form still posts (fresh OR expired, equals the open token or reached via any route the read-only recovery did not serve, e.g. the no-JS plain form POST that skips wc-ajax) receives ONE safe, cart-preserving rejection (`Tu solicitud anterior ya fue recibida…`). A fold is authorized by REQUEST-OBSERVED, HASH-SCOPED IN-FLIGHT EVIDENCE alone (`fpw_attempt_inflight_seen`): the request's own validation ran before the attempt's completion existed (durable lookup row OR finalized claim row — an absent lookup alone proves nothing; a crash between the claim-row UPDATE and the permanent lookup INSERT leaves a finalized attempt without its lookup), or its claim wait observed the unfinalized claim row. Wall-clock ages are NEVER concurrency evidence — the lead's independent probe reproduced a fresh <30 s post-completion plain-form replay folding and emptying a new selection through the old `landed_at`-age rule; the probe now reports gate rejection, no existing-order return, no fold path, no private note. The claim seam enforces the same evidence rule at every recovery source (durable entry, claim row, takeover); genuine in-flight retries keep the documented wait/fold behavior. Claim rows carry a once-stamped `landed_at` for observability only.
- **Checks (executed offline only):** offline regression 297 → **350 local assertions** (independent bindings + immutability + no-rebirth + sweep and the legacy-fallback non-reauthorization, per-attempt expiry both sides, the full gating chain in its live order — wp_loaded recovery → after-validation gate → create_order claim — driven over the REAL vendored normalization for A-after-B, expired-open, unknown-token, B-while-A-expired, THE LEAD-PROBE fresh post-completion plain-route replay over a two-line selection (gate rejection + claim-seam reload + no fold path/no note/selection intact) and the crash-between-UPDATE-and-INSERT case (finalized claim row, permanent lookup absent, new request rejected safely), plus gate recording of hash-scoped in-flight evidence and genuine-concurrency fold authorization at both the durable and claim-row seams) plus **86 syntax/dependency/deployment checks** under `FREEPLAST_SKIP_STACK=1 npm test`. Verified RED against simulations of the pre-fix code (latest-only authorization; gate without the completed-attempt closure; the restored wall-clock live-race bypass; gate inspecting only the durable lookup). The lead's own probe (`/tmp/freeplast-herd-w2P/probe-36-fresh-replay.php`) re-run against the fixed tree: all four outcomes safe. Native HTTP regressions ADDED/UPDATED for the operator but NOT executed: `lostmulti` — A's response lost, B completes, A's original form + valid nonce recovers A's own reference over the empty basket and over a third two-line (simple + Rojo variant) selection with full snapshot equality; `stale` — the older completed form recovers its own attempt after a newer one landed and rotated the token (read-only, selection intact), the newer form recovers its own, repeated recovery creates nothing, and the rotated fresh submission keeps its own NEW reference; `plainreplay` — the mandatory no-JS boundary over the plain form route: the copied payload carries the native PLACE-ORDER submit trigger (`woocommerce_checkout_place_order` — the pinned `WC_Form_Handler::checkout_action` dispatches `process_checkout` only on that trigger or on `update_totals`, which is deliberately unused), and the completed attempt's replay then gets the cart-preserving gate rejection with the Spanish reload guidance and every line/variant/quantity intact (baseline snapshot read BEFORE the POST and asserted equal to the known two-line selection). The helper itself is covered by an OFFLINE executed self-test (`woo-checkout-race-selftest.py`, mocked Session, no HTTP: trigger present, no `update_totals` shortcut, caller values unchanged, native dispatch predicate satisfied — RED-verified by omitting the trigger). The notification-event expectation is DERIVED from the scenario ledger — 11 pairwise-distinct new-request ids (3 race + correct + renew + lost + lostmulti A + lostmulti B + inflight + stale + isolate) × 2 = 22 events, PLANNED, not observed — and the WP-CLI state block proves the lostmulti ORIGINALS (A: own 70-unit record whose durable lookup resolves to the record the retry returned — exactly one original A; B: own 6-unit record; stale: own 7-unit rotated record; each with its own attempt identity and submitted details) — totals are established when the operator executes the regression.
- **Scope, honestly bounded:** read-only multi-attempt recovery answers the native `wc-ajax=checkout` route the shipped checkout JS drives. The no-JS plain form POST never reaches it — for those submissions the identity gate gives the mandatory cart-preserving rejection (never the fold), whatever the nonce's or the landing's age — WordPress' 12–24 h nonce window is never assumed to bound anything (docblocks corrected); full no-JS read-only recovery remains optional and uncovered. Crash-window residue: a winner dying between finalization and its landing record, or between the claim-row UPDATE and the permanent lookup INSERT, leaves a finalized attempt whose retries receive the same safe rejection (the lookup-less case after the claim row is swept, ≤ 7 days, would let a same-hash resubmission start a new attempt — bounded, synthetic-only path, documented). Remaining operator steps: review/merge, package + deploy adapter 1.6.2, run the full stack regression (now covering `lostmulti`/`stale` recovery and `plainreplay`) and a browser pass of the #36 walkthrough (A lost → B completes → retry A → A's confirmation; repeat with a new selection present; replay the completed form without JS → rejection with the selection intact). No payments, invoices, stock changes or historical-record mutations; physical mobile acceptance remains a human gate.

## Preserving the submitted attempt identity — issue #35 (ST-01/SP-02) — 2026-09-07

Findings ST-01 and SP-02 (P1) of the 2026-09-07 review of Ralph's #31–#34 batch (compared `eb69ec7`→`433c6a3`; follow-up of #31/#32, which stay untouched): the claim read the attempt token from Woo's normalized checkout data, but the hidden field was not registered in that normalization — `WC_Checkout::get_posted_data()` (pinned 11.1.0, reproduced offline with the actual method) drops unregistered fields, and the adapter substituted the session's CURRENT open token. Unsafe sequence reproduced by the review: complete attempt A, add a new selection without rendering checkout again, then submit with a valid checkout nonce and an unknown token — the early recovery declines, but native checkout adopted A's identity and ran its cart-emptying fold path, destroying the new selection. Implemented in the working tree, uncommitted (adapter **1.6.1**, pending review/merge/deploy — NOT merged and NOT executed on any stack in this change):

- **The submitted token is the identity, and it survives the real normalization:** the field is registered as a checkout field in its own `fpw` fieldset (a key none of Woo's rendered fieldsets draws as a form row; the hidden input itself is still emitted by the adapter at `woocommerce_after_order_notes`). `get_posted_data()` now carries `fpw_attempt`, so claiming, binding and persistence all use the identifier the submitted form carried. The offline regression drives the REAL pinned `WC_Checkout::get_posted_data()` — vendored byte-identical under `scripts/vendor/` with a sha256 sidecar chained to the pinned Woo zip — never a stubbed posted-data array.
- **No substitution, ever:** `fpw_attempt_identity()` keeps a well-formed posted token and nothing else — the session's open token is never a fallback. A missing or malformed token claims no identity (the claim stays out of the way), and an unknown well-formed token is its OWN attempt key: no posted identifier can silently acquire another attempt's identity, recover its confirmation or empty a new selection.
- **Deliberate safe outcome at the gate:** the new `fpw_validate_attempt_identity` rides Woo's own `woocommerce_after_checkout_validation` seam and rejects — recoverably, in Spanish, creating nothing — a form whose token is missing, malformed, unknown or older than the session's open attempt (an older form never adopts the newer attempt's identity; the customer reopens Datos y envío and submits the fresh form; pre-save retries of the SAME form keep working). Authorized replays of a LANDED attempt never reach the gate: `fpw_recover_landed_attempt` (#32) answers them earlier, read-only, without touching Productos a Cotizar. Woo's own checkout nonce and session authorization stay exactly as Woo ships them; a copied identifier from another session reveals no reference, key or foreign data.
- **Checks (executed offline only):** offline regression 260 → **297 local assertions** (field registration and non-rendered fieldset, the REAL normalization probe, the identity-gate matrix including the older-form case, no-substitution defense-in-depth at the claim — verified RED against the pre-fix adapter before the fix — and the seam checks for the variant fixture below) plus **86 syntax/dependency/deployment checks** under `FREEPLAST_SKIP_STACK=1 npm test`. The variant fixture is seeded with the NORMALIZED attribute key (`color`) and verified through the pinned `WC_Product_Data_Store_CPT::find_matching_product_variation(… attribute_color = Rojo)` seam itself — the pinned `WC_Product_Variation::set_attributes` preserves key case while the matcher requires `attribute_` + `sanitize_title(parent name)`, so a `Color`-keyed variant could never match; a wrong preexisting fixture is repaired (type/attribute/variation), never silently accepted, and an unmatchable one aborts the bootstrap loudly. Native HTTP regressions were ADDED for the operator but NOT executed (no server run in this change): `replayunknown` — the review's exact unsafe sequence over a TWO-LINE selection (distinct simple product + the bootstrap-seeded Rojo variant, preserved idempotently on every bootstrap): safe rejection with every line, variant and quantity preserved by full identity/options/quantity snapshot equality, no confirmation, no new request; `stale` — a later attempt landed and rotated the open token, the older form fails safely with the two-line selection exactly intact while the fresh form gets its own NEW reference; `preserve` was strengthened to the same full-snapshot comparison. The extended stack harness expectations (including the two-line snapshots and the planned notification-event count of 9 new requests × 2 = 18) are PLANNED, not observed — full-stack totals are established when the operator executes the regression.
- **Remaining operator steps:** review/merge, then package + deploy adapter 1.6.1 to staging; run the full stack regression (now covering `replayunknown`/`stale` with the variant fixture) and a browser pass of the #35 walkthrough (complete A → add a two-line selection without re-rendering → replay with an unknown token → rejection with every line/variant/quantity intact; replay of A's own form → A's confirmation, selection intact). Pre-existing gaps NOT proven fixed here and explicitly deferred to #36 (multi-attempt recovery lifetime): the no-JS resubmission path beyond the native `wc-ajax=checkout` route and expired-vigencia behavior on a live stack; physical mobile acceptance remains a human gate.

## Recovering the confirmation when the response is lost — issue #32 (SP-02) — 2026-09-06

Finding SP-02 (P1) of the 2026-09-05 round's review (over `eb69ec7`, follow-up of #24 and its Woo port in #1, built on #31's attempt identity): a customer whose Solicitud de cotización was saved but whose checkout response never arrived had to accept the native empty-cart rejection («sesión caducada») when retrying the same submission — Woo throws it inside `WC_Checkout::process_checkout()` (pinned 11.1.0, line-checked) before any recovery integration could run, and the round's regression still treated the failed replay as the correct answer. Repo fix (adapter **1.6.0**, pending deploy):

- **Interception ahead of Woo's guards, authorization unchanged:** the confirmation recovery rides `wp_loaded` priority 0, ahead of Woo's own checkout AJAX (`template_redirect` 0), so the resubmission of a landed attempt's own form is answered before `process_checkout()` rejects the emptied basket. The recovery re-shows the landed attempt's own confirmation URL — nothing is created, changed or re-notified. It still requires Woo's own process-checkout nonce to verify (never bypassed), a well-formed attempt token, and BOTH bindings — the durable lookup row and the session's landing record — resolving to the same order of THIS session: knowing an identifier authorizes nothing.
- **Defined vigencia (new `FPW_RECOVERY_MAX_AGE` = one day, from the landing):** an expired landing is no longer recoverable — the resubmission falls through to Woo's own guards, whose safe answer reveals no reference, key or foreign data. One day sits inside WordPress' own nonce window (12–24 h), so past the vigencia the nonce cannot verify either; the boundary holds end to end. The durable attempt binding stays permanent — replays never become duplicates; the vigencia bounds only the confirmation re-show, never idempotency.
- **The cart is no longer a condition (criterion 6 defect):** the retry can arrive while a NEW selection unrelated to that attempt already sits in Productos a Cotizar (stale tab, back button, resubmit). Until this fix the empty-cart gate sent those retries down Woo's fold-in path, whose quotes gateway empties the basket (`class-quotes-payment-gateway.php`, line-checked) — the new selection was destroyed and the record received a duplicate-fold note. The recovery now answers read-only without touching the cart: the original confirmation returns and the new selection survives.
- **Checks:** offline regression 256 → **260 local assertions** (full-cart recovery with an untouched cart, the vigencia boundary on both sides, the lifetime pinned as a deliberate constant). Real-stack checks 47 → **64** with four new native scenarios in `woo-checkout-race.py`: `lost` — the response deliberately abandoned mid-processing (client-side timeout), persistence proven by the basket-empty poll without reading the response, retry recovers the ORIGINAL reference; `inflight` — a retry while the first submission is still in flight answers recoverably (fold inside the claim budget or the «se está procesando» message) and the original reference is obtainable afterwards without a second request; `preserve` — the old form's retry with a new unrelated selection in the basket recovers the original confirmation and the selection survives (FAILED before the fix: `items=[]`); `stranger` — a well-formed unknown token and a stolen form replayed from another session receive the safe native rejection, never the reference. Notification events: exactly one sales + one customer notification per new request (8 × 2 = **16**), never duplicated for folds/replays/recoveries; WP-CLI state checks cover the lost/inflight records (pending status, quote meta, own attempt identity, own lines, own submitted details). Syntax/dependency/deployment checks 133 → **150** (the counter includes the stack harness).
- **Remaining operator steps:** package + deploy adapter 1.6.0 to staging; re-run `verify-woo-state.php` and a browser pass of the lost-response walkthrough (complete a request with the response interrupted → resubmit the same form → the original confirmation returns with the basket empty; rebuild the identical selection → a NEW reference per #31). Not covered here, left to the operator/human gate: a no-JS form resubmission (the recovery answers the native `wc-ajax=checkout` route the shipped checkout JS drives; the plain checkout-page POST path stays Woo's own), physical mobile acceptance and the expired-vigencia behavior on the real stack (covered offline; backdating a live session record was not attempted).

## Ventas reads and annotates, never writes — issue #33 (SP-03 + ST-01) — 2026-09-06

Findings SP-03 (P1) and ST-01 (P2) of the 2026-09-06 review (over `eb69ec7`, follow-up of #25/WA-02): the two order caps that open the native editor and its note AJAX also pass Woo's own nonce + capability checks on every record-mutation surface — a restricted ventas session could save contact data and commercial status through the editor, change status via the quick-status AJAX (whose nonce Woo itself renders for the role), run bulk status changes/trash, edit items, taxes and refunds, update records through the REST orders API (its permission map keys `edit`/`batch` on the same `edit_others` cap), and delete order notes. And the deletion/priced-quote checks of the round used absent or invalid nonces — CSRF answers that would still pass under privilege escalation. Repo fix (adapter **1.5.0**, pending deploy):

- **Read and annotate, never write (ADR-0001 — Woo keeps owning the admin):** a new server guard set keys strictly on the capability boundary (`fpw_is_order_limited_staff()`), verifies NO nonce, and therefore denies valid requests with valid nonces — every denial attributable to permissions, never to the CSRF check. The kept surfaces (login, list, search, editor reads, the private-note AJAX) pass untouched; managers (`manage_woocommerce`) keep Woo's native behavior everywhere; the role definition, its caps and its idempotent sync are unchanged.
- **Front door (`admin_init`, before any save machinery):** the mutating admin-AJAX family (`woocommerce_mark_order_status`, note deletion, the items/fees/shipping/taxes/coupons/downloads/refunds family) and the editor saves of both storage modes are denied 403 before any handler runs — in the posts store WordPress core would rewrite the record row itself (including status) before Woo's save hooks, so the denial must sit at `admin_init`.
- **Deep backstop (`woocommerce_process_shop_order_meta` at priority 0, both storage modes):** any route into Woo's own save pipeline — contact fields, status, order actions such as resends and download permissions — dies after Woo's nonce + capability checks and before any metabox write, leaving the record unchanged.
- **Bulk and REST boundaries:** all orders-list bulk mutations (status changes, trash/untrash/delete, personal-data removal) pass one chokepoint inside Woo's own handlers (`woocommerce_bulk_action_ids`) after their nonce + cap checks; the REST orders API denies every mutating context (`create`/`edit`/`delete`/`batch`) for order-limited staff via `woocommerce_rest_check_permissions` — read contexts pass through unchanged for everyone.
- **The interface never offers what the server denies:** quick-status buttons (list rows and the order-preview modal, which carry Woo's own nonce for the role), the editor's save controls and status select, the order-actions select, the quotes extension's priced-quote buttons, the per-note delete links and the bulk-actions UI are removed or hidden for order-limited staff, with a Spanish notice stating the consulta scope; sales notes stay addable through the native flow and keep private normalization, author and date.
- **Honest evidence, disposable only:** new `scripts/woo-ventas-guard.py` drives a REAL restricted session over the disposable stack — it creates its own synthetic request through real checkout HTTP (identity verified from the delivered editor — reference, marker, `Datos originales recibidos` — before any mutation), adds a private note through the native flow, then attempts every protected operation with a VALID session and a VALID nonce (minted for the restricted user by a disposable mu-plugin, replacing the nonce string the UI rightly no longer carries) and demands a server 403 that leaves the record unchanged. Positive controls with the guards lifted by another disposable mu-plugin prove the probes DETECT a removed guard (status actually changes, REST update actually rewrites), and a final recheck proves the denial returns — the authorization-regression criterion. The staging script `verify-ventas-role.py` was rebuilt on the same evidence classes: valid-nonce editor-save, bulk-status, REST-update, priced-quote and resend denials on staging, with identity verification of the technical record and CSRF controls recorded separately.
- **Deterministic disposable stack:** the harness now pins Woo 11.x "coming soon" mode off (its onboarding flows enabled it mid-run and replaced store pages for logged-out visitors), redirects the php -S server log to a file (piped logs stalled the whole suite once node's event loop blocked inside `spawnSync` and the 64KB pipe buffer filled), kills the whole server process group with a port-freed assertion, and runs the restricted-session matrix guarded → guard-off → recheck.
- **Checks:** offline regression 207 → **256 local assertions** (front-door matrix, save backstop, bulk chokepoint, REST boundary, UI filters, registration and record-unchanged pipeline proof, and the two regression probes: removing the guard lets the write through; granting `manage_woocommerce` reopens it). Real-stack checks 40 → **47**: the guarded matrix, the guard-off positive controls and the restored-guard recheck over real HTTP. Syntax/dependency/deployment checks 126 → **133**. Remaining operator steps: package + deploy adapter 1.5.0 to staging, re-run `verify-woo-state.php` and the rebuilt `verify-ventas-role.py --execute-staging`; expanding the Ventas scope remains a separate human decision — documenting that the editor allows something is not authorization.

## A new request is not a retry: attempt identity rotation — issue #31 (SP-01) — 2026-09-06

Finding SP-01 of the 2026-09-06 review (over `eb69ec7`, follow-up of #24 and its Woo port in #1): the attempt claim was keyed on session + cart hash + posted fields with a permanent binding to the previous order — so after completing a request, a customer who rebuilt the IDENTICAL selection in the same session was folded into the PREVIOUS order and could never obtain a new reference. Reproduced on the real-stack regression before the fix: the rebuild returned the previous request.

Repo fix (adapter **1.4.0**, pending deploy):

- **Attempt identity is not content identity:** the claim key is the session fingerprint plus a per-attempt token (random 40-hex, in the customer's own Woo session, shipped in one hidden checkout field) — never the cart contents or the posted fields. Content identity belongs to the order's own lines and data. Two concurrent submissions of one attempt share the identity (one request); a new, identical request after a completion gets a fresh identity (a new reference).
- **Lifecycle:** creation on the first checkout-form render; validity spans the whole attempt (re-renders, AJAX refreshes, pre-save error corrections and resubmissions keep the token — retries stay ONE request); completion when a persisted order marks the attempt landed in the session (token + hash + order id — the authorized binding the confirmation-recovery follow-up #32 builds on, independent of the cart staying full); rotation on the first render after a landing, so a completed attempt never captures a later submission. Historical records are never touched.
- **Authorization:** the token is NOT a WordPress nonce — opaque random identity, no tick/expiry semantics, never verified as a nonce; Woo's own checkout nonce checks stay as shipped and the retry recovery additionally requires that nonce to verify. Cross-session recovery is impossible (the hash binds the session fingerprint; the durable lookup row AND the session landing record must agree on the same order).
- **Retry recovery (minimal, read-only):** a native checkout submission arriving with the cart Woo already emptied (response lost, same form resubmitted) re-shows the landed attempt's own confirmation instead of «sesión caducada» — creating nothing, changing nothing, re-notifying nothing. Only when nonce + token + both bindings agree.
- **Claim budget 3 s → 10 s:** the loser only waits while the winner is mid-flight; folding beats a spurious recoverable error on slow stacks.
- **Checks:** offline regression 169 → **203 local assertions** (identity semantics, token format/stability/rotation, hidden-field render, landing binding and guards, recovery authorization matrix). Real-stack regression extended and repeated: the concurrent test runs **three bounded rounds** with fresh sessions — one order per round, both confirmations equal; the replay of a landed attempt recovers the SAME request; the identical rebuild produces a NEW reference with a rotated token, its own lines, its own submitted details and its own receipt notifications (different attempt identity, equal content — no legitimate request silently lost). A notification-event log (mail-log mu-plugin written into the disposable install by the harness, never in the repo) counts exactly one sales + one customer notification per new request (6 × 2 = 12), never duplicated for folds/replays/recoveries.
- **Remaining operator steps:** package + deploy adapter 1.4.0 to staging; re-run `verify-woo-state.php`, the concurrency probe (`unique_returned_orders` must stay 1) and a browser pass of the #31 walkthrough (complete → rebuild identical → new reference; concurrent pair → one request; retry of the same form → same reference). Human Gate 3 acceptance unchanged.

## One attempt, one request under concurrent checkout — issue #1 (WA-01 Woo-side) — 2026-09-05

The #24 merge record below documents that the fix had landed only in the retired `legacy/` implementation, and that the live duplicate-order defect still needed a bounded Woo-side port. Repo fix (adapter **1.3.0**, pending deploy):

- **Atomic per-attempt claim around Woo's own checkout:** a single options row keyed by the attempt's idempotency hash (session + cart hash + normalized posted attempt fields), inserted as a plain INSERT against the unique `option_name` — the reviewed legacy primitive, proven on both database engines. The option API is bypassed on purpose (`add_option()` is an upsert that answers later reads from the per-request cache). The winner proceeds through Woo unchanged; the loser never creates a second order.
- **Recovery through Woo's own short-circuit:** `woocommerce_create_order` returns the winner's order id for a recovered attempt — Woo itself skips creation, runs its own flow and sends the customer to the winner's confirmation. No parallel submission system, no custom response ownership (ADR-0001). The fold-in forces `woocommerce_cart_needs_payment` so Woo's flow takes the quotes gateway (which keeps the request pending and returns the winner's confirmation): with the cart already emptied by the winner, Woo's own branching would otherwise take the no-payment path and move the request into a commercial status.
- **Deduplication where duplication would be visible:** the fold removes the quotes extension's request-notification hook for that request only (the winner's notification already covers the record — verified on the stack: exactly one sales + one customer notification) and leaves one honest private note per folded attempt. The order keeps its `_fpw_attempt` hash as administration meta.
- **Durable replay binding without order-meta lookups:** the attempt → order binding lives in a dedicated lookup row (unique `option_name` → order id), read by direct SQL. Woo's posts order store silently ignores `meta_query` since 9.2 — the first draft looked the attempt up by order meta and every attempt folded into the newest order; the real-stack regression caught it before it could ship. The adapter never hand-queries Woo's storage. (Key update: since #31 the claim key is the attempt identity — session + per-attempt token — not the session + cart hash + fields named above; see the #31 section.)
- **Real-stack regression:** new `scripts/woo-stack-harness.mjs` + `scripts/woo-checkout-race.py` boot the disposable WP + SQLite + WooCommerce stack (`bootstrap.mjs`, repaired for the Woo era: freeplast-woo synced, pinned Woo + Quotes installed and activated, classic cart/checkout pages, two quote-enabled featured products), serve multi-worker on loopback and drive real HTTP: two concurrent checkouts → exactly ONE pending order, both responses carrying the winner's confirmation; a sequential replay → Woo's own empty-cart rejection, no new order; a different session/data → its own order with its own details. WP-CLI state checks verify pending status, quote meta, attempt binding and the lookup row. Dead-winner takeover (claim empty past a 30 s grace) and release-on-failure are unit-covered offline (130 → 169 local assertions).
- **Remaining operator steps:** package + deploy to staging, then re-run `docs/reviews/woo-acceptance-2026-09-05/evidence/concurrency-probe.py --execute-staging` (after confirming staging mail containment): `unique_returned_orders` must be 1. Human Gate 3 acceptance unchanged.

## Home featured grid with self-naming links — issue #1 (WA-04 Woo-side) — 2026-09-05

The #27 merge record likewise left the live Home unnamed-link defect unfixed. Verified root cause in WordPress core: `render_block_core_shortcode()` runs `wpautop()` over the shortcode's EXPANDED output, and `get_the_block_template_html()` expands `[products]` before `do_blocks()` — Woo's native loop markup is paragraph-split at its internal blank lines, landing `</p>`/`<p>` pairs inside the product link. Repo fix (adapter **1.3.0**, theme **1.0.5**, pending deploy):

- **Plugin-rendered dynamic block, native markup untouched (PRD: "plugin-rendered dynamic blocks or equivalent stable rendering APIs"):** the adapter registers `freeplast-woo/featured-products`, whose render callback executes the SAME native `[products limit="8" columns="4" visibility="featured" orderby="menu_order"]` shortcode inside `do_blocks`, where no wpautop runs. `templates/front-page.html` swaps the `wp:shortcode` block for it. The delivered card markup is Woo's own loop — the link carries the title text and the alt-bearing image — and the adapter owns no card markup at all.
- **Degradation documented:** with the adapter inactive the grid renders nothing (same class of degradation as the other adapter features); with it active the grid keeps the native `woocommerce columns-4` classes.
- **Checks:** the real-stack regression computes the delivered Home — no shortcode wrapper, no wpautop damage inside the product link, the title inside the link, and a dependency-free axe link-name rule (text, aria-label, title, alt-bearing image) over EVERY delivered anchor with zero unnamed links. `verify-woo-http.py` asserts the same contract on staging. Theme 1.0.4 → 1.0.5 bookkeeping bump (template change).
- **Remaining operator steps:** package + deploy to staging; re-run the browser axe pass on Home (WA-04 should clear). Human Gate 3 acceptance unchanged.

## Sales enters the native Woo admin with least privilege — issue #25 — 2026-09-05

Finding WA-02 of the [post-migration acceptance review](../docs/reviews/woo-acceptance-2026-09-05/README.md): the retained `ventas_freeplast` role carried only `read` + `manage_freeplast_quotes`, so sales could not open the native order administration where Quote Requests now live — the reviewed flow used an administrator, and `shop_manager` is not least-privilege (it can delete orders, edit products and configure Woo). Repo fix (adapter **1.2.0**, pending deploy):

- **The role, normalized by the adapter (ADR-0001 — Woo owns orders administration):** `freeplast-woo` owns `fpw_sync_sales_role()`, an idempotent, self-healing definition run on every request (writes only when drifted) and on activation: create the role when missing, restore missing approved capabilities, strip anything else, never touch other roles. Approved set — exactly four caps: `read`, `manage_freeplast_quotes`, `edit_shop_orders`, `edit_others_shop_orders`. The two Woo caps are the verified minimum for the native Pedidos surfaces (pinned Woo 11.1.0 + WP `map_meta_cap`): the Orders list/search screen and the private-note AJAX key on `edit_shop_orders`; the top-level WooCommerce menu and the author-less order detail key on `edit_others_shop_orders` (orders have no author, so `edit_post` maps there). Deletes, catalog, coupons, terms, settings, reports and the quotes extension's priced actions (`manage_woocommerce`) stay ungranted — the role deliberately does NOT carry the WordPress primitive `edit_posts`, which would grant wide post/page editing.
- **Admin entry without elevation:** Woo redirects users lacking `edit_posts`/`manage_woocommerce` to My Account (`WC_Admin::prevent_admin_access`); the adapter filters `woocommerce_prevent_admin_access` so order-limited staff enters wp-admin through the order caps alone — every screen beyond the door stays capability-checked by WordPress itself.
- **Notes are private at the origin:** sales notes use Woo's own note metabox and AJAX (author and date are kept by Woo for users holding `edit_shop_orders`). The metabox posts a visibility choice, so for order-limited staff the adapter normalizes the posted type to private before Woo's AJAX handler reads it — server-side, not just a hidden control (the visibility select is additionally hidden in the admin UI for honesty). Request-only site: the note-to-customer email joins the adapter's disabled set, and both email-resend order actions are removed from the actions select for ventas AND denied server-side with a 403 (`woocommerce_before_resend_order_emails`) — the manual invoice email bypasses Woo's enabled-check, so the disabled-email filters alone cannot be the guard.
- **Denials hold on the server, not just in navigation:** deletes (`delete_*shop_order*` ungranted), catalog (`edit_products` ungranted), users/settings/plugins (WordPress core caps ungranted), priced quotes and quote emails (`qwc_update_status`/`qwc_send_quote` demand `manage_woocommerce`), charges/refunds (quotes-gateway takes no payment and implements no refund). Editing order data or commercial transitions inside Woo's own editor is NOT granted specifically, NOT restricted here, and remains a documented native-editor reality for human acceptance — restricting it would mean owning Woo's editor, which ADR-0001 rejects.
- **Repeatable checks:** offline regression grew 86 → **130 local assertions** (role sync create/idempotence/self-heal, other roles untouched, order-limited staff predicate, admin-entry filter, order-actions and resend guards, note-type normalization, disabled note email) + 68 syntax/dependency/deployment checks. `verify-woo-state.php` (staging, read-only) now asserts the role's exact caps, forbidden caps, untouched administrator/shop_manager, effective account denials via `user_can`, and the registered adapter guards. New `verify-ventas-role.py` (staging-only, like `verify-woo-http.py`, not part of `npm test`) runs a REAL restricted session: provisions a temporary ventas account with a random never-printed password, logs in as that user, walks the allowed surfaces (menu, list, search, detail, note added through the native flow and crafted as a customer note — normalized private — with persistence on reload), then proves the direct-route denials (catalog, settings, users, plugins, trash attempt leaving the request intact, priced-quote AJAX, crafted email resend hitting the 403 guard), and removes the temporary account. The check targets an existing technical request (billing email on `example.*`, or `--order-id`).
- **Verified end-to-end against a disposable local stack** (pinned WP 7.1 + Woo 11.1.0 + Quotes 2.13): role caps exactly `read, manage_freeplast_quotes, edit_shop_orders, edit_others_shop_orders`; the menu shows only Dashboard / WooCommerce→Pedidos / Profile; the full restricted-session script passes all 25 checks including the crafted-resend 403.
- **Remaining operator steps:** package + deploy to staging, run `verify-woo-state.php`, then run `verify-ventas-role.py --execute-staging` with controlled admin credentials from the environment (never published). Human Gate 3 acceptance: a real ventas walkthrough (login, list/search/open, add a private note, denied surfaces) on staging with a synthetic request, per the acceptance criteria — the script demonstrates it technically but does not replace human approval. Issue left open.

### Merge record for findings WA-01 (#24) and WA-04 (#27) — 2026-09-05

The `ralph/issue-24` (one attempt, one request under concurrent submission) and `ralph/issue-27` (named links on the featured cards) branches were developed against pre-migration `main` and merged **into the retired implementation preserved under `legacy/`** (rename-detected; the retired suite `scripts/check.mjs`, its VERIFICATION/HANDOFF/BUILD-DECISIONS records and the deterministic `dist/` plugin ZIP — rebuilt from the merged legacy source via `scripts/rebuild-legacy-plugin-zip.mjs` — carry both fixes). The deployed Woo stack was **not** modified: the live duplicate-order (WA-01) and unnamed-link (WA-04) defects still need bounded Woo-side fixes — a checkout idempotency/claim integration in `freeplast-woo` for #24 and accessible card link naming in the Woo-rendered Home for #27 — each with its own Woo-native regression before either finding can be considered closed on staging. Issues #24/#27 were closed by the merge pipeline; reopen or file follow-ups if the Woo-side port is required (it is, per the acceptance criteria). **Update (same day): the Woo-side ports are implemented and regression-tested in-repo — see the two sections above; deploys to staging remain the operator step.**

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

## Advance blocked while a replaced update is still in flight — issue #34 — 2026-09-06

Follow-up to #26 (finding SP-04 of the 2026-09-06 review of the Ralph batch, reproduced against `eb69ec7` with the real Woo store and the shipped script): after Woo aborted a first update to replace it, one request remained in flight, the store's pending list was EMPTY, the «Datos y envío» CTA exposed `aria-disabled="false"` and clicks were not stopped — the feedback waited for the transport, but the CTA observed only the store's pending flag. Verified root cause in the pinned Woo 11.1.0 sources: `changeCartItemQuantity()`'s `finally` runs `itemIsPendingQuantity(key, false)` for the ABORTED request, clearing the flag while its replacement still runs.

Repo fix (theme **1.0.6**, pending deploy):

- **One coherent pending-operation predicate:** the script now defines an operation as pending while its store flag is set OR its `update-item` request is still in flight (`operationsPending()`), and BOTH waiting decisions use it — the verdict deferral (which additionally waits on its own item's flag) and the CTA state. The CTA communicates `aria-disabled="true"` and stops pointer AND keyboard activation (Enter on an anchor fires click) synchronously, at click time, until the transport drains; the transport observation itself also locks the CTA the moment a request starts, before any store flag could clear early.
- **Outcomes unchanged in wording:** when the slow replacement settles successfully, the Spanish confirmation names the exact persisted quantity and advancing is allowed again; on network failure or server error the notice explains the quantity that actually remained saved and the customer may retry or continue explicitly with it. Recovery never duplicates increments (the intent always comes from the block's own input), never loses other lines and never steals focus; reload and Datos y envío keep showing Woo's persisted truth (ADR-0001 — no second cart, no parallel persistence).
- **Honest harness, not instant-response-only:** the offline regression's transport now routes every store request through `window.fetch` (as api-fetch does in a browser), so the shipped transport observation is exercised for real; new scenarios drive abort + deliberately SLOW replacement to the exact defective window — store flag cleared, replacement in flight — with the CTA locked (pointer + keyboard), no transient verdict announced for the aborted request, then success and failure endings, and a slow retry that locks and confirms without duplicated increments. Line removal during an operation asserts the lock as well. The evidence remains a logic/store regression: the rendered Cart block walkthrough and narrow/desktop review stay operator steps; no capture or emulation is presented as mobile approval.
- Offline regression 169 → **173 local assertions** (shared predicate, transport-boundary lock, click-time guard) and 84 → **102 syntax/dependency/deployment checks** (the two new scenarios + removal-window lock). Theme 1.0.5 → 1.0.6 for the bridge script's cache-busting (constant and style.css header bumped together).
- **Remaining operator steps:** package + deploy theme 1.0.6 to staging; browser pass with a controlled test selection (two rapid quantity changes with a throttled connection, verifying Datos y envío stays inert until the second settles; restore connectivity after each failure simulation; empty the test cart afterwards). Human Gate 3 acceptance unchanged.

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
- Small adapter: fiscal/dispatch fields, local validation, terminology, request-only side-effect guards, old routes, unpriced request emails, the ventas role definition, the header line count, the checkout attempt claim (one attempt, one request) and the featured-grid block. It uses Woo's existing checkout-review hook/session for form drafts, not a separate session or persistence service.
- Repo ahead of the live stack: adapter **1.6.3** and theme **1.0.6** (issues #25/#26/#28/#29/#30/#31/#32/#33/#34 and the Woo-side ports of #24/#27 are merged and regression-tested; the #35, #36 and #37 adapter changes sit in the working tree, offline-regression-tested, pending review/merge) — pending deploy; the live stack still runs adapter 1.0.2 / theme 1.0.0.
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
npm test                         # offline checks + disposable local stack (loopback only; never staging)
npm run woo:package              # wordpress/.build/woo-release/
```

If the project PHP tool is unavailable, set `PHP_BINARY` to an absolute PHP executable. Current offline result as executed for #35+#36+#37 (`FREEPLAST_SKIP_STACK=1 npm test`, 2026-09-07): **434 local assertions (… attempt identity vs content + submitted attempt identity through the real Woo normalization + variant-fixture seam checks + independent per-attempt recovery lifetime with the full gating chain incl. the fresh post-completion and crash-lookup rejections + landed-attempt retry recovery with lifetime and cart preservation + ventas record boundary incl. the #37 mutation-family audit with real pinned controller dispatches + native-regression structural checks, issues #30/#29/#28/#26/#34/#25/#1/#31/#32/#33/#35/#36/#37) + the 7-check plain-route-helper and 13-check ventas-helper self-tests (106 checks total)**. The full local-stack regression (real pinned cart-block store scenarios for #26/#34 plus the disposable WP+Woo stack: Woo-side ports of #24/#27, #31 attempt-identity rotation, #32 lost-response confirmation recovery, #33 restricted-ventas record boundary, the #35 submitted-identity scenarios `replayunknown`/`stale` with the seeded variant fixture and two-line preservation snapshots, and the #36 scenarios `lostmulti`/`stale`-recovery/`plainreplay`) was EXTENDED but NOT executed in this change — its totals are established when the operator runs `npm test` without `FREEPLAST_SKIP_STACK` (`woo-stack-harness.mjs` boots the stack automatically; the port is refused if a foreign server owns it; `FREEPLAST_SKIP_STACK=1` skips it).

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
