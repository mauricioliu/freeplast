/* Card/sheet button deltas come from the server's catalog policy, independently
   of HTML step=1 and informative minimums. Cards mirror valid quantities into
   Woo's native AJAX anchor; native validation and persistence stay intact. */
(function () {
  'use strict';
  var WRAP_SELECTOR = '[data-fpw-loop-add]';

  function stepValue(input, direction) {
    if (!input || input.disabled || input.readOnly) { return; }
    var before = input.value;
    var current = parseInt(input.value, 10);
    var min = input.min !== '' ? parseInt(input.min, 10) : 1;
    var max = input.max !== '' ? parseInt(input.max, 10) : Infinity;
    // Frutera/Merlucera: five; traversas: one; every other product: ten.
    // The server emits this policy; the retired 200-unit class is ignored.
    var deltaClass = (input.className || '').match(/\bfpw-delta-(1|5|10)\b/);
    var step = deltaClass ? Number(deltaClass[1]) : 10;
    if (isNaN(current)) {
      current = isNaN(min) ? 1 : min;
    } else {
      current = current + (direction * step);
    }
    if (!isNaN(min) && current < min) { current = min; }
    if (!isNaN(max) && current > max) { current = max; }
    input.value = String(current);
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
    (root || document).querySelectorAll(WRAP_SELECTOR + ' input.qty, form.cart input.qty').forEach(function (input) {
      buildStepper(input);
      syncValidityMessage(input);
    });
  }

  function syncValidityMessage(input) {
    if (!input || typeof input.setCustomValidity !== 'function') { return; }
    input.setCustomValidity('');
    if (input.disabled || input.readOnly) { return; }
    var val = input.validity;
    if (!val) { return; }
    if (val.rangeUnderflow) {
      var minVal = input.min || '1';
      input.setCustomValidity('El valor debe ser mayor o igual a ' + minVal + '.');
    } else if (val.rangeOverflow) {
      var maxVal = input.max;
      input.setCustomValidity('El valor debe ser menor o igual a ' + maxVal + '.');
    } else if (val.stepMismatch || val.badInput || val.valueMissing) {
      input.setCustomValidity('Ingresa una cantidad válida.');
    }
  }

  function mirrorQuantity(wrap) {
    var input = wrap.querySelector('input.qty');
    var button = wrap.querySelector('.add_to_cart_button');
    if (!input || !button) { return; }
    syncValidityMessage(input);
    var value = Number(input.value);
    var valid = Number.isSafeInteger(value) && value > 0 && input.checkValidity();
    var picker = wrap.querySelector('[data-fpw-featured-picker]');
    if (picker) {
      valid = valid && Number(button.getAttribute('data-product_id')) > 0 && !picker.hasAttribute('data-fpw-pending') && !picker.hasAttribute('data-fpw-uncertain');
    }
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
    var input = (target.matches && target.matches('input.qty')) ? target : target.closest('input.qty');
    if (input) {
      syncValidityMessage(input);
      var wrapForInput = input.closest(WRAP_SELECTOR);
      if (wrapForInput) { mirrorQuantity(wrapForInput); }
    }
  }

  // Cart block retains its own input handler, intent event, debounce and rollback.
  function onCartStep(event, draft) {
    var button = event.target.closest && event.target.closest('.wc-block-components-quantity-selector__button');
    if (!button || button.disabled) { return; }
    var row = button.closest('.wc-block-cart-items__row');
    var link = row && row.querySelector('.wc-block-components-product-name');
    var input = row && row.querySelector('.wc-block-components-quantity-selector__input');
    if (!link || !input || input.disabled || input.readOnly) { return; }
    var deltas = window.freeplastQuantityDeltas || {};
    var delta = deltas[link.href];
    if (!delta) { return; }
    var current = Number(input.value);
    if (!Number.isSafeInteger(current) || current < 1) { return; }
    event.preventDefault();
    event.stopImmediatePropagation();
    if (!draft && window.freeplastQuantityPending) { return; }
    var direction = button.classList.contains('wc-block-components-quantity-selector__button--plus') ? 1 : -1;
    var next = Math.max(1, current + direction * delta);
    if (input.max !== '') { next = Math.min(next, Number(input.max)); }
    if (next === current) { return; }
    var view = input.ownerDocument.defaultView;
    Object.getOwnPropertyDescriptor(view.HTMLInputElement.prototype, 'value').set.call(input, String(next));
    if (!draft) { input.dispatchEvent(new view.Event('input', { bubbles: true })); }
    return input;
  }

  // Draft cart values never enter React until release: one native change, one debounce.
  var held = null;
  var suppressClick = null;
  var holdSelector = '.fp-qty-step, .wc-block-components-quantity-selector__button';

  function holdState(busy) {
    window.freeplastQuantityHolding = busy;
    document.dispatchEvent(new window.Event('fp-quantity-hold'));
  }

  function stopHold(event) {
    if (!held) { return; }
    if (event && (event.type.indexOf('pointer') === 0 || event.type === 'lostpointercapture') && event.pointerId !== held.pointerId) { return; }
    var previous = held;
    held = null;
    clearTimeout(previous.timer);
    previous.observer.disconnect();
    if (event && event.type === 'pointercancel') { suppressClick = null; }
    if (previous.pointerId !== undefined && previous.button.hasPointerCapture && previous.button.hasPointerCapture(previous.pointerId)) {
      previous.button.releasePointerCapture(previous.pointerId);
    }
    if (previous.cart && previous.input.isConnected && !previous.input.disabled && !previous.input.readOnly) {
      previous.input.dispatchEvent(new window.Event('input', { bubbles: true }));
    }
    holdState(false);
  }

  function startHold(event) {
    var button = event.target.closest && event.target.closest(holdSelector);
    if (!button || button.disabled) { suppressClick = null; return; }
    var keyboard = event.type === 'keydown';
    if (keyboard && event.key !== ' ' && event.key !== 'Enter') { return; }
    if (!keyboard && (event.button !== 0 || event.isPrimary === false)) { return; }
    if (keyboard && event.repeat) { event.preventDefault(); return; }
    stopHold();
    var cart = button.matches('.wc-block-components-quantity-selector__button');
    var input = button.parentNode.querySelector(cart ? 'input' : 'input.qty');
    if (!input || input.disabled || input.readOnly) { return; }
    if (cart) {
      var row = button.closest('.wc-block-cart-items__row');
      var link = row && row.querySelector('.wc-block-components-product-name');
      if (!link || !(window.freeplastQuantityDeltas || {})[link.href]) { return; }
    }
    event.preventDefault();
    event.stopImmediatePropagation();
    if (cart && window.freeplastQuantityPending) { return; }
    button.focus({ preventScroll: true });
    suppressClick = button;
    held = { button: button, input: input, cart: cart, pointerId: event.pointerId, key: event.key };
    if (!keyboard && button.setPointerCapture) {
      try { button.setPointerCapture(event.pointerId); } catch (err) { /* Pointer already cancelled. */ }
    }
    held.observer = new MutationObserver(function () {
      if (held && (!button.isConnected || !input.isConnected || button.disabled || input.disabled)) { stopHold(); }
    });
    held.observer.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled'] });
    if (cart) { holdState(true); }
    function tick() {
      if (!held) { return; }
      if (!button.isConnected || !input.isConnected || button.disabled || input.disabled || input.readOnly) { stopHold(); return; }
      if (cart) {
        onCartStep({ target: button, preventDefault: function () {}, stopImmediatePropagation: function () {} }, true);
      } else {
        stepValue(input, button.classList.contains('fp-qty-step--plus') ? 1 : -1);
      }
    }
    tick();
    function repeat() {
      tick();
      if (held) { held.timer = setTimeout(repeat, 100); }
    }
    if (held) { held.timer = setTimeout(repeat, 400); }
  }

  function onQuantityKeydown(event) {
    var target = event.target;
    if (!target || !target.matches || !target.matches('input.qty')) { return; }
    if (event.key === 'ArrowUp') {
      event.preventDefault();
      stepValue(target, 1);
    } else if (event.key === 'ArrowDown') {
      event.preventDefault();
      stepValue(target, -1);
    }
  }

  /* 'input' covers typing, spinner arrows and keyboard steps live; 'change'
     covers the commits some browsers only deliver there (pickers, autofill).
     'invalid' (capture phase) catches native constraint evaluation before UI popups. */
  if (typeof document !== 'undefined') {
    document.addEventListener('pointerdown', startHold, true);
    document.addEventListener('keydown', startHold, true);
    document.addEventListener('keyup', function (event) {
      if (held && event.key === held.key) { event.preventDefault(); stopHold(); }
    }, true);
    ['pointerup', 'pointercancel', 'lostpointercapture'].forEach(function (type) {
      document.addEventListener(type, stopHold, true);
    });
    document.addEventListener('focusout', function (event) {
      if (held && event.target === held.button) { stopHold(); }
    }, true);
    window.addEventListener('blur', function () { stopHold(); });
    document.addEventListener('visibilitychange', function () { if (document.hidden) { stopHold(); } });
    document.addEventListener('click', function (event) {
      var button = event.target.closest && event.target.closest(holdSelector);
      if (button && button === suppressClick) {
        suppressClick = null;
        event.preventDefault();
        event.stopImmediatePropagation();
      }
    }, true);
    document.addEventListener('click', onCartStep, true);
    document.addEventListener('click', onQuantityEvent);
    document.addEventListener('input', onQuantityEvent);
    document.addEventListener('change', onQuantityEvent);
    document.addEventListener('keydown', onQuantityKeydown);
    document.addEventListener('invalid', function (event) {
      var target = event.target;
      if (target && target.matches && target.matches('input.qty')) {
        syncValidityMessage(target);
      }
    }, true);
  }

  if (typeof window !== 'undefined' && window.jQuery) {
    window.jQuery(document).on('found_variation reset_data hide_variation show_variation', 'form.cart', function () {
      var form = this;
      setTimeout(function () {
        if (!form || !form.querySelectorAll) { return; }
        form.querySelectorAll('input.qty').forEach(syncValidityMessage);
      }, 20);
    });
  }

  function boot() {
    initSteppers(document);
    document.querySelectorAll(WRAP_SELECTOR).forEach(mirrorQuantity);
    document.querySelectorAll('form.cart input.qty').forEach(syncValidityMessage);
  }

  if (typeof document !== 'undefined') {
    document.addEventListener('click', function (event) {
      var button = event.target.closest && event.target.closest(WRAP_SELECTOR + ' .add_to_cart_button, form.cart .single_add_to_cart_button, form.cart button[type="submit"]');
      if (!button) { return; }
      var wrapper = button.closest(WRAP_SELECTOR);
      if (wrapper) {
        // The featured shortcut guards both color and quantity at its own
        // capture boundary, where it can focus the missing named swatch.
        var featured = wrapper.querySelector('[data-fpw-featured-picker]');
        if (featured && featured.dataset.fpwReady === '1') { return; }
        var input = wrapper.querySelector('input.qty');
        mirrorQuantity(wrapper);
        if (button.classList.contains('loading') || button.getAttribute('aria-disabled') === 'true') {
          event.preventDefault();
          event.stopImmediatePropagation();
          if (input && !button.classList.contains('loading')) {
            input.reportValidity();
            input.focus();
          }
        }
      } else {
        var form = button.closest('form.cart');
        var sheetInput = form && form.querySelector('input.qty');
        if (sheetInput) {
          syncValidityMessage(sheetInput);
        }
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
    module.exports = { initSteppers: initSteppers, stepValue: stepValue, mirrorQuantity: mirrorQuantity, syncValidityMessage: syncValidityMessage, onCartStep: onCartStep };
  }
})();
