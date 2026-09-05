/* Accessible state of the variable-product add-to-cart button (issue #28).
   WooCommerce renders and updates the button's availability only as CSS
   classes (wc-variation-selection-needed / wc-variation-is-unavailable on
   .disabled), so the accessibility tree reads it as an available control.
   The template override renders the initial state; this script keeps the
   aria attributes and the visible instruction in step with the classes
   Woo's own variation form sets — it only reads them and never decides
   availability, owns no cart and never blocks the native form. Simple
   products have no instruction marker and stay untouched. */
(function () {
  'use strict';
  var BUTTON = '.single_add_to_cart_button';
  var HINT = '[data-fp-variation-hint]';
  var TEXTS = {
    unavailable: 'Esa combinación no está disponible. Prueba con otra.',
    pending: 'Agregando a Productos a Cotizar…'
  };

  /* Woo's classes are the single availability source of truth. */
  function stateOf(button) {
    if (!button.classList.contains('disabled')) { return 'enabled'; }
    return button.classList.contains('wc-variation-is-unavailable') ? 'unavailable' : 'selection-needed';
  }

  /* Inactive controls remain focusable (never a real disabled attribute),
     so keyboard users keep the native guidance click and the description
     stays discoverable; the inactive look itself is exempt from contrast,
     and the theme still gives it an AA-passing explicit style. */
  function applyState(button, hint, state, defaultText, pending) {
    if (state === 'enabled' && !pending) {
      button.setAttribute('aria-disabled', 'false');
      button.removeAttribute('aria-describedby');
      button.removeAttribute('aria-busy');
      hint.hidden = true;
      return;
    }
    button.setAttribute('aria-disabled', 'true');
    button.setAttribute('aria-describedby', hint.id);
    hint.hidden = false;
    if (pending) {
      button.setAttribute('aria-busy', 'true');
      hint.textContent = TEXTS.pending;
    } else {
      button.removeAttribute('aria-busy');
      hint.textContent = state === 'unavailable' ? TEXTS.unavailable : defaultText;
    }
  }

  function watch(form) {
    var button = form.querySelector(BUTTON);
    var hint = form.querySelector(HINT);
    if (!button || !hint) { return; }
    var defaultText = hint.textContent;
    var pending = false;
    function sync() { applyState(button, hint, stateOf(button), defaultText, pending); }
    sync();
    /* Woo's variation form toggles the classes through jQuery; the class
       attribute mutation is observable either way. */
    if (typeof MutationObserver === 'function') {
      new MutationObserver(sync).observe(button, { attributes: true, attributeFilter: ['class'] });
    } else {
      form.addEventListener('change', sync);
    }
    /* Woo's blocked clicks never reach the form (it preventDefaults), so a
       submit here is a genuine add: expose the round trip, and reset when a
       back/forward restore shows the page again. */
    form.addEventListener('submit', function () { pending = true; sync(); });
    window.addEventListener('pageshow', function (event) {
      if (event.persisted) { pending = false; sync(); }
    });
  }

  function init() {
    document.querySelectorAll('form.variations_form').forEach(watch);
  }

  if (typeof document !== 'undefined' && typeof window !== 'undefined') {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', init);
    } else {
      init();
    }
  }

  /* Offline behavioral coverage drives stateOf/applyState directly. */
  if (typeof module === 'object' && module.exports) {
    module.exports = { stateOf: stateOf, applyState: applyState, TEXTS: TEXTS };
  }
})();
