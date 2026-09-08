# Vendored, pinned WooCommerce bundle for offline regression

`wc-blocks-data-11.1.0.js` is the byte-identical compiled cart-block data
store (wc/store/cart) from the pinned WooCommerce 11.1.0 release — the same
code that runs on the staging site. It is **not** enqueued by the theme or
the adapter; `wordpress/scripts/check-woo.mjs` evaluates it in Node with
stubbed `window.wp.*` plumbing to drive `changeCartItemQuantity` through
real success, connection-loss and server-error paths (issue #26, WA-03).

* `woocommerce-11.1.0-class-wc-checkout.php` (+ `woocommerce-11.1.0-cogs-aware-trait.php`,
  its load-time companion) is the byte-identical server-side checkout class from
  the pinned WooCommerce 11.1.0 release. `test-woo-adapter.php` loads it with
  stubbed WordPress primitives so the REAL `WC_Checkout::get_posted_data()`
  normalization runs over the adapter's registered fields — issue #35: the
  submitted attempt token must survive Woo's own normalization boundary, never
  a stubbed posted-data array.

* `woocommerce-11.1.0-add-to-cart.js` is the byte-identical native classic
  add/remove handler (`woocommerce/assets/js/frontend/add-to-cart.js`) from
  the same pinned release ZIP. SHA256:
  `0fc09a783746f16c4f6133418bbb4b340750364bf912d43d9a298ce3fbb92ae3`.
  `loop-added-count-test.mjs` runs it with real jQuery/DOM and mocked transport
  to check product-card quantity mirroring, request queue, fragment replacement,
  and the native per-line removal/keyboard path. It is never enqueued/deployed.

Provenance and hashes: `wc-blocks-data-11.1.0.json` and
`woocommerce-11.1.0-class-wc-checkout.json`. License: GPL-2.0-or-later.
Update them only when `woo-dependencies.json` pins a new Woo release, and keep
both hashes in step.
