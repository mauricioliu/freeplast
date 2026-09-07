/* Product-card quantity selector: mirror the card's native WooCommerce
   quantity input into the add-to-cart anchor's data-quantity. WooCommerce's
   own AJAX handler reads data-quantity from the anchor's DOM dataset at click
   time (pinned Woo 11.1.0 add-to-cart.js gives preference to data attributes
   over jQuery data), so keeping that one attribute current is the whole
   integration: no fetches, no own cart state (ADR-0001), and the native
   loading/added button states and «Ver Productos a Cotizar» link keep working
   untouched. min/max/step stay Woo's own (woocommerce_quantity_input);
   a cleared or below-1 value mirrors as 1, the server-side default, and
   values above max are left for WooCommerce's own add and stock validation
   to reject. Without JavaScript the anchor adds one unit, exactly as before
   this script existed. Enqueued on every route: any surface may render a
   product loop (home featured grid, shop archive, search results), and the
   mirror is inert wherever no [data-fpw-loop-add] wrapper exists. */
(function () {
  'use strict';
  var WRAP_SELECTOR = '[data-fpw-loop-add]';

  function mirrorQuantity(wrap) {
    var input = wrap.querySelector('input.qty');
    var button = wrap.querySelector('.add_to_cart_button');
    if (!input || !button) { return; }
    var value = parseInt(input.value, 10);
    if (!isFinite(value) || value < 1) { value = 1; }
    button.setAttribute('data-quantity', String(value));
  }

  function onQuantityEvent(event) {
    var target = event.target;
    if (!target || typeof target.closest !== 'function') { return; }
    var wrap = target.closest(WRAP_SELECTOR);
    if (wrap) { mirrorQuantity(wrap); }
  }

  /* 'input' covers typing, spinner arrows and keyboard steps live; 'change'
     covers the commits some browsers only deliver there (pickers, autofill). */
  document.addEventListener('input', onQuantityEvent);
  document.addEventListener('change', onQuantityEvent);
})();
