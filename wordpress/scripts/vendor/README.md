# Vendored, pinned WooCommerce bundle for offline regression

`wc-blocks-data-11.1.0.js` is the byte-identical compiled cart-block data
store (wc/store/cart) from the pinned WooCommerce 11.1.0 release — the same
code that runs on the staging site. It is **not** enqueued by the theme or
the adapter; `wordpress/scripts/check-woo.mjs` evaluates it in Node with
stubbed `window.wp.*` plumbing to drive `changeCartItemQuantity` through
real success, connection-loss and server-error paths (issue #26, WA-03).

Provenance and hashes: `wc-blocks-data-11.1.0.json`. License: GPL-2.0-or-later.
Update it only when `woo-dependencies.json` pins a new Woo release, and keep
both hashes in step.
