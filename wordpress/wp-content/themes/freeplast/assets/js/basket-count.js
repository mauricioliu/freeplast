/* Productos a Cotizar header count: live bridge for the native cart block.
   The count is server-rendered on every route, and classic AJAX adds refresh
   it through WooCommerce's native add-to-cart fragments (freeplast-woo). The
   cart block, however, applies quantity changes, removals and emptying
   through its own data store without a reload, so this script subscribes to
   that store and re-renders the header count when the distinct line count
   changes. No polling, no fetches and no parallel cart service: the number
   always comes from the Woo cart. */
(function () {
  'use strict';
  var SELECTOR = '.fpw-basket-count';

  function render(count) {
    document.querySelectorAll(SELECTOR).forEach(function (el) {
      if (el.textContent !== String(count)) { el.textContent = String(count); }
    });
  }

  /* Distinct lines only — the same definition the server renders. A store
     that has not resolved its cart yet reports nothing, so a half-loaded
     value never flashes over the server-rendered number. */
  function lineCount() {
    try {
      if (typeof window.wp.data.getStoreNames === 'function' &&
          window.wp.data.getStoreNames().indexOf('wc/store/cart') === -1) { return null; }
      var store = window.wp.data.select('wc/store/cart');
      if (!store || typeof store.getCartData !== 'function') { return null; }
      if (!store.hasFinishedResolution('getCartData')) { return null; }
      var cart = store.getCartData();
      return cart && cart.items ? cart.items.length : null;
    } catch (err) {
      return null;
    }
  }

  function init() {
    if (!window.wp || !window.wp.data || typeof window.wp.data.subscribe !== 'function') { return; }
    window.wp.data.subscribe(function () {
      var count = lineCount();
      if (count !== null) { render(count); }
    });
  }

  /* DOMContentLoaded waits for the footer block scripts, so the cart store is
     registered by then; on pages without it nothing is subscribed. */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
