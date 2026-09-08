# A · Directa — differences and open evidence

Source: frozen `785e65b502078b67e29bffbd2a7beab8ae408aac`.
This is a source audit, NOT rendered comparison or acceptance. Fix a genuine
visual deviation or obtain an explicit owner exception; offline green alone
cannot accept it. No owner exception has been recorded in this work.

## Source-visible differences to review

- Shared chrome retains additional real corporate/contact destinations;
  exact menu/footer/help composition needs comparison with A's `index.html`
  and approval or correction. Corporate body content was not redesigned.
- Feedback uses native notices/pills and settled Cart messages rather than
  A's `app.js` toast surface. Behavior tests do not establish identical
  placement, timing or appearance. This remains a visual parity gap.
- Basket lead repeats no-purchase/no-reservation copy absent from A's
  `basket()` lead. Confirmation adds an explicit no-stock-reservation
  sentence. Other corresponding A copy must not be mislabeled as extra:
  `details()` ALREADY has privacy text and “Sin pagos ni reserva de stock”.
- Header count accessibility uses native replaced text rather than relying
  on a static counted aria-label. Compare the actual accessible names and
  announcements against A; no screen-reader equivalence is claimed.

## Mandated native implementation / permitted normalizations

These are not automatic visual exceptions. They still need rendered checks.

- Cart remains Woo's real table/block, not A's simulated article list.
  Native inputs/variation select/classic form own values and mutations.
  Different DOM internals are required by ADR-0001; geometry must still match.
- Technical zero values remain in native Cart client data/DOM and are hidden
  with CSS. Hydrated leakage checks are UNRUN. This implementation does not
  remove them; no claim is made that vendor edits are the only alternative.
- Featured membership and ordering use real native catalog properties.
  Source-fixture flags are not runtime authority.
- Real destinations/privacy replace demo wiring; operational errors and
  references reflect actual state. Error causes AND trailing periods are
  preserved. Generic native transport copy is uncertain, not proof of a
  pre-save rejection.
- Dock offset removes only the demo toolbar's reserved space and uses the
  safe area. A ALSO hides `.selection-dock` at min-width1000; desktop hiding
  is not an added deviation. Native required/conditional address behavior
  corresponds to A's conditional address; hidden stars are not extra UI.

## Lead corrections to the initial #48 audit

- Confirmation `summaryCard({edit:true})` means `miniLines`, NOT an Editar
  control (A `app.js:100–101`). No missing confirmation action.
- A basket CTA is INSIDE its summary card; mobile aside has `order:-1`.
  Details submit is at the form foot, not the summary. Actual A `basket()` /
  `details()` and the integrated PHP agree. No contrary runtime evidence.
- “Skip link absent” was incorrect. Pinned WordPress
  `_block_template_add_skip_link()` supplies the link and missing main ID.
  `native-chrome-test.php` executes that function and its HTML parser on the
  actual template markup. Rendered keyboard/focus behavior remains UNRUN.
- Native ClassicTemplate called the outer breadcrumb hook AND the sheet's
  inner call. Lead reproduced two trails, corrected the scoped placement,
  and added a presentation override for A's Catálogo → category links.
  Native/extension hooks and non-product trails remain intact;13 native PHP
  checks cover the correction. Rendered placement is still UNRUN.
- Initial fingerprint omitted real inputs (including `loop-start.php` and
  catalog fixture data) and silently printed an empty fields.js version.
  Lead replaced it with complete input families, actual probe-source hashes
  and fail-closed generation. Six executable checks guard it.

These corrections were made by the **lead**, not approvals by the owner.
Earlier worker reports are historical delivery notes, not final authority.

## Still open

- All matched-state visual/keyboard/font/sticky/zoom checks and real
  screen-reader/PC/phone review.
- Real Woo HTTP/database/race execution and hydrated cross-page journey.
- Native pagination beyond page1: the default20-parent-product fixture is
  below24/page. An authorized run must create enough additional run-owned
  synthetic products (with provenance and cleanup) or prepare an equivalent
  isolated pagination fixture. No such mutation has been executed here.
- Browser setup is not supplied by a finished `npm test` run: that run stops
  its server. A separately authorized, contained listener/origin/lifecycle
  plan is needed; see commands.md. This is a blocker, not a ready preview.
- Deploy-time template/option parity and all release authorization.

No native HTTP, browser, hardware or owner acceptance is claimed.
