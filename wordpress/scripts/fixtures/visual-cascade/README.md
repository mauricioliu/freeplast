# Native CSS cascade regression fixtures

Sanitized staging DOM captured in Chrome on 2026-09-08 (Woo 11.1.0,
Quotes 2.13, Freeplast 1.0.14). Only test selections were present. Scripts,
nonces/hidden inputs, cart-state payloads, form actions and non-theme data
attributes were removed. Theme presentation attributes are retained because
CSS depends on them. These are **inert layout fixtures, not a native store**.

## Run without starting a server

1. `node wordpress/scripts/layout-cascade-fixture.mjs`
2. In an authorized, already connected browser session, run
   `wordpress/scripts/layout-cascade-browser.js` with `browser_run_script`.
   Supply `manifestPath` from step 1, the session's owned `targetId`, and an
   existing `out` directory. The script only navigates to generated local files.
3. Inspect the returned PASS/FAIL and `out/layout-results.json`. Failure details
   are bounded in the response; the JSON contains every assertion.

To prove the CSS checks go red on the original bug:

```
FREEPLAST_LAYOUT_BASELINE=0713770 node wordpress/scripts/layout-cascade-fixture.mjs
```

Run the browser script on that separate manifest. This switches only the two
Freeplast stylesheets to the specified git revision, not the files in the
working tree. Native CSS and markup stay identical between red/green runs.
Observed: baseline 240 failed assertions; patched CSS 364/364 passed, across
52 route/width cases. These are geometry/state assertions, not visual approval.

## What is real and what is modeled

- Native markup for catalogue cards, related cards, hydrated Cart block and
  checkout; captured WP inline styles and dependency asset order.
- Current theme CSS + native CSS read from the already installed `.build/wp`
  fixture. No downloading/upgrading plugins or DB boot. Missing assets fail.
- Cart CSS is also appended **after** the theme to catch hydration ordering
  regressions. Includes Quotes' actual sidebar `right:40%` rule.
- Catalogue introduction is rendered by `catalog-native-frame-test.php`
  through Woo's real ClassicTemplate method and combined with captured native
  cards. Its category counts are test stubs, not live catalog measurements.
- Checkout replay removes only the deleted theme-owned privacy paragraph when
  the current source no longer emits it. The independent PHP test executes the
  actual native terms template and asserts one notice; replay is not that proof.
- The browser script models native Cart responsive classes and temporarily
  toggles `aria-disabled` on the existing product button to check CSS states.
  It restores the attribute afterwards. No React/store/request assertions.
- CSP blocks submissions and connections; JS application scripts are absent.
  Product images load from the public staging origin, fonts from local files.
- No hardware, native runtime round trip, Site Editor, screen reader or A-parity
  acceptance is implied. Full native interaction and owner review remain separate.

The non-browser regressions run in `FREEPLAST_SKIP_STACK=1 npm test`:
`catalog-native-frame-test.php` exercises the pinned archive renderer (shop,
category, search; populated and empty; classic fallback; extension hooks), and
`checkout-form-test.php` now includes native terms/privacy output rather than
only a payment marker. JSDOM/syntax tests alone cannot catch CSS cascade bugs.
