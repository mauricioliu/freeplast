/* Product-card quantity selector (A · Directa stepper, issue #42).
   Two responsibilities, both projections onto Woo's own controls:

   1. Stepper: wrap Woo's native quantity input with −/+ buttons (44px
      targets, A geometry) and step the input's own value through its own
      min/max/step semantics. Without JavaScript the native input stands
      alone; no invented limits or multiples are ever introduced.

   2. Mirror: reflect the input's value into the add-to-cart anchor's
      data-quantity. WooCommerce's own AJAX handler reads data-quantity from
      the anchor's DOM dataset at click time (pinned Woo 11.1.0
      add-to-cart.js prefers data attributes over jQuery data), so keeping
      that one attribute current is the whole integration: no fetches, no
      own cart state (ADR-0001), and the native loading/added button states
      keep working untouched. A cleared or below-min value mirrors as the
      server-side default (1); values above max are left for WooCommerce's
      own add and stock validation to reject. Without JavaScript the anchor
   adds one unit, exactly as before this script existed. Enqueued on every
   route: any surface may render a product loop (home featured grid, shop
   archive, search results), and the script is inert wherever no
   [data-fpw-loop-add] wrapper exists. */
(function () {
  'use strict';
  var WRAP_SELECTOR = '[data-fpw-loop-add]';

  function stepValue(input, direction) {
    if (!input || input.disabled || input.readOnly) { return; }
    var before = input.value;
    try {
      if (direction < 0) { input.stepDown(-direction); }
      else { input.stepUp(direction); }
    } catch (error) { return; }
    if (input.value !== before) {
      /* The native input stays authoritative: notify it and the mirror.
         Events are built from the input's own realm so injected/replaced
         inputs (fragments) always receive realm-matching events. */
      var view = input.ownerDocument && input.ownerDocument.defaultView;
      var EventCtor = (view && view.Event) || Event;
      input.dispatchEvent(new EventCtor('input', { bubbles: true }));
      input.dispatchEvent(new EventCtor('change', { bubbles: true }));
    }
  }

  /* H3 (2026-10-03 review): every stepper control must name its product in
     Spanish. The name comes from the card/sheet title (loop title or single
     product_title) — falling back to an existing Spanish quantity label — and
     the GENERIC untranslated-or-translated placeholder labels Woo ships
     ("Product quantity" before translations, "Cantidad del producto" after
     this journey's own gettext map) are replaced with the same specific name.
     Nothing else about the native input (value, min/max/step) is touched. */
  var GENERIC_QUANTITY_LABEL = /^(Product quantity|Cantidad del producto)$/i;

  function productName(input) {
    var scopes = [];
    var wrapper = input.closest('[data-fpw-loop-add]');
    if (wrapper) { scopes.push(wrapper); }
    var product = input.closest('.product');
    if (product) { scopes.push(product); }
    var sheetForm = input.closest('form.cart');
    if (sheetForm && sheetForm.parentNode) { scopes.push(sheetForm.parentNode); }
    var fromTitle = '';
    for (var i = 0; i < scopes.length && !fromTitle; i++) {
      var title = scopes[i].querySelector('.woocommerce-loop-product__title, .product_title');
      fromTitle = title ? (title.textContent || '').replace(/\s+/g, ' ').trim() : '';
    }
    if (fromTitle) { return fromTitle; }
    var current = input.getAttribute('aria-label') || '';
    if (GENERIC_QUANTITY_LABEL.test(current.trim())) { return ''; } // generic label names nothing
    var fromLabel = current.replace(/^Cantidad (de|del)\s*/i, '').replace(/\s+/g, ' ').trim();
    return fromLabel || '';
  }

  function buildStepper(input) {
    if (!input || input.closest('.fp-qty-control')) { return; }
    var name = productName(input);
    var currentLabel = (input.getAttribute('aria-label') || '').trim();
    if (!currentLabel || GENERIC_QUANTITY_LABEL.test(currentLabel)) {
      input.setAttribute('aria-label', name ? 'Cantidad de ' + name : 'Cantidad');
    }
    var minus = input.ownerDocument.createElement('button');
    minus.type = 'button';
    minus.className = 'fp-qty-step fp-qty-step--minus';
    minus.setAttribute('aria-label', name ? 'Reducir cantidad de ' + name : 'Reducir cantidad');
    minus.textContent = '−';
    var plus = minus.cloneNode(true);
    plus.className = 'fp-qty-step fp-qty-step--plus';
    plus.setAttribute('aria-label', name ? 'Aumentar cantidad de ' + name : 'Aumentar cantidad');
    plus.textContent = '+';
    var wrap = input.ownerDocument.createElement('div');
    wrap.className = 'fp-qty-control';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(minus);
    wrap.appendChild(input);
    wrap.appendChild(plus);
  }

  function initSteppers(root) {
    (root || document).querySelectorAll(WRAP_SELECTOR + ' input.qty, form.cart input.qty').forEach(buildStepper);
  }

  function mirrorQuantity(wrap) {
    var input = wrap.querySelector('input.qty');
    var button = wrap.querySelector('.add_to_cart_button');
    if (!input || !button) { return; }
    var value = Number(input.value);
    var valid = Number.isSafeInteger(value) && value > 0 && input.checkValidity();
    button.setAttribute('aria-disabled', valid ? 'false' : 'true');
    if (valid) { button.setAttribute('data-quantity', String(value)); }
  }

  function onQuantityEvent(event) {
    var target = event.target;
    if (!target || typeof target.closest !== 'function') { return; }
    if (target.classList.contains('fp-qty-step')) {
      var wrap = target.closest('.fp-qty-control');
      if (wrap) { stepValue(wrap.querySelector('input.qty'), target.classList.contains('fp-qty-step--plus') ? 1 : -1); }
      return;
    }
    var wrapForInput = target.closest(WRAP_SELECTOR);
    if (wrapForInput) { mirrorQuantity(wrapForInput); }
  }

  /* 'input' covers typing, spinner arrows and keyboard steps live; 'change'
     covers the commits some browsers only deliver there (pickers, autofill). */
  if (typeof document !== 'undefined') {
    document.addEventListener('click', onQuantityEvent);
    document.addEventListener('input', onQuantityEvent);
    document.addEventListener('change', onQuantityEvent);
  }

  function boot() {
    initSteppers(document);
    document.querySelectorAll(WRAP_SELECTOR).forEach(mirrorQuantity);
  }

  if (typeof document !== 'undefined') {
    document.addEventListener('click', function (event) {
      var button = event.target.closest && event.target.closest(WRAP_SELECTOR + ' .add_to_cart_button');
      if (!button) { return; }
      var wrapper = button.closest(WRAP_SELECTOR);
      var input = wrapper.querySelector('input.qty');
      mirrorQuantity(wrapper);
      if (button.classList.contains('loading') || button.getAttribute('aria-disabled') === 'true') {
        event.preventDefault();
        event.stopImmediatePropagation();
        if (input && !button.classList.contains('loading')) { input.reportValidity(); input.focus(); }
      }
    }, true);
  }

  if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', boot);
    } else {
      boot();
    }
  }

  if (typeof module === 'object' && module.exports) {
    module.exports = { initSteppers: initSteppers, stepValue: stepValue, mirrorQuantity: mirrorQuantity };
  }
})();
