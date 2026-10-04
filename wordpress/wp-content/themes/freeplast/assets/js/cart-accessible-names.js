/* Spanish, variant-unambiguous accessible names on the Productos a Cotizar
   page (H3, 2026-10-03 review).

   Two supported seams over WooCommerce's cart page — no vendor fork:

   1. Translation: the cart block renders its line-item controls client-side
      through wp.i18n. Without installed translations the labels ship in
      English ("Quantity of %s in your cart.", "Remove %s from cart"). The
      public wp.i18n.setLocaleData seam supplies the journey's Spanish copy —
      the same strings the adapter maps server-side for PHP-rendered
      surfaces (fpw_accessible_name_translations()). It runs during page load,
      before the block's asynchronous cart data arrives, so labels are
      computed with these strings from the first render.

   2. Variant context: two variants of one product would otherwise produce
      identical accessible names ("…Caja Universal Cerrada Color…"). The
      cart/checkout blocks' own checkout-filter API
      (window.wc.blocksCheckout.registerCheckoutFilters, itemName filter)
      appends the chosen variation values to the item name those labels are
      built from. The filter reads only the cartItem the block already owns;
      if the API is absent or fails, Woo's own naming stands — nothing is
      patched or forced. */
(function () {
  'use strict';
  if (window.wp && window.wp.i18n && window.wp.i18n.setLocaleData) {
    window.wp.i18n.setLocaleData({
      'Products in cart': ['Productos en tu selección'],
      'Quantity of %s in your cart.': ['Cantidad de %s en tu selección.'],
      'Reduce quantity of %s': ['Reducir cantidad de %s'],
      'Increase quantity of %s': ['Aumentar cantidad de %s'],
      'Remove %s from cart': ['Quitar %s de tu selección']
    }, 'woocommerce');
  }
  var api = window.wc && window.wc.blocksCheckout;
  if (api && typeof api.registerCheckoutFilters === 'function') {
    try {
      api.registerCheckoutFilters('freeplast/variant-names', {
        itemName: function (value, extensions, arg) {
          try {
            var variation = arg && arg.cartItem && arg.cartItem.variation;
            if (!Array.isArray(variation)) { return value; }
            var parts = variation.map(function (entry) {
              return entry && (entry.value || entry.display) || '';
            }).filter(Boolean);
            if (!parts.length) { return value; }
            return value + ' · ' + parts.join(', ');
          } catch (error) { return value; }
        }
      });
    } catch (error) { /* Woo's own item naming stands if registration fails */ }
  }
})();
