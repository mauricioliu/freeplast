/* Productos a Cotizar header count + mobile selection dock: live bridges
   for the native cart. Counts and dock numbers are server-rendered on every
   route, and classic AJAX adds refresh them through WooCommerce's native
   add-to-cart fragments (freeplast-woo + the theme's dock fragment). The
   cart block, however, applies quantity changes, removals and emptying
   through its own data store without a reload, so this script subscribes to
   that store and re-renders the header count and the dock when the distinct
   line count or unit total changes. No polling, no fetches and no parallel
   cart service: every number comes from the Woo cart. */
(function () {
  'use strict';
  var SELECTOR = '.fpw-basket-count';
  var CART_STORE = 'wc/store/cart';
  var DOCK_SELECTOR = '[data-fpw-selection-dock]';

  function render(count) {
    var text = String(count);
    document.querySelectorAll(SELECTOR).forEach(function (el) {
      if (el.textContent !== text) { el.textContent = text; }
    });
  }

  function plural(n, one, many) { return n + ' ' + (1 === Number(n) ? one : many); }

  /* The dock only exists on catalog/product routes; absent is a no-op. */
  function renderDock(lines, units) {
    var dock = document.querySelector(DOCK_SELECTOR);
    if (!dock) { return; }
    if (lines > 0) {
      dock.removeAttribute('hidden');
      var strong = dock.querySelector('strong');
      var span = dock.querySelector('span');
      var action = dock.querySelector('.button');
      if (!strong || !span || !action) { return; } /* server markup owns the text; partial state stays untouched */
      var label = plural(lines, 'producto seleccionado', 'productos seleccionados');
      var total = units.toLocaleString('es-CL') + (1 === units ? ' unidad' : ' unidades') + ' · sin pago en línea';
      if (strong.textContent !== label) { strong.textContent = label; }
      if (span.textContent !== total) { span.textContent = total; }
    } else if (!dock.hidden) {
      dock.setAttribute('hidden', '');
    }
    syncBodyClass();
  }

  /* Cart page (A · Directa #45): the block's own sidebar hosts a native-truth
     summary — distinct lines vs total units — inserted above the native
     CTA. Numbers come from the same store subscription as the header count;
     with an empty cart the summary hides and the block's own empty state
     (page markup) takes over. No prices: the block's technical zero totals
     are hidden at the stylesheet level, never re-stated here. */
  var SUMMARY_SELECTOR = '[data-fpw-cart-summary]';

  function cartSummaryMarkup(lines, units) {
    return '<h2>Resumen de tu selección</h2>'
      + '<div class="summary-numbers"><div><strong>' + lines + '</strong><span>' + (1 === lines ? 'producto distinto' : 'productos distintos') + '</span></div>'
      + '<div><strong>' + units.toLocaleString('es-CL') + '</strong><span>' + (1 === units ? 'unidad en total' : 'unidades en total') + '</span></div></div>'
      ;
  }

  function ensureCartSummary() {
    if (typeof document === 'undefined' || !document.body) { return null; }
    if (!document.body.classList.contains('woocommerce-cart')) { return null; }
    var existing = document.querySelector(SUMMARY_SELECTOR);
    if (existing) { return existing; }
    var sidebar = document.querySelector('.wc-block-cart__sidebar');
    if (!sidebar) { return null; }
    var submit = sidebar.querySelector('.wp-block-woocommerce-proceed-to-checkout-block') || sidebar.querySelector('.wc-block-cart__submit-container');
    /* The CTA may be nested (block footer wrappers): anchor on its nearest
       sidebar-owned ancestor so the summary always lands above it. */
    var anchor = null;
    if (submit) {
      var cursor = submit;
      while (cursor && cursor.parentNode !== sidebar) { cursor = cursor.parentNode; }
      anchor = cursor;
    }
    var node = document.createElement('div');
    node.className = 'fpw-cart-summary summary-card';
    node.setAttribute('data-fpw-cart-summary', '');
    node.setAttribute('hidden', '');
    sidebar.insertBefore(node, anchor || sidebar.firstChild);
    return node;
  }

  function renderCartSummary(lines, units) {
    var node = ensureCartSummary();
    if (!node) { return; }
    if (lines > 0) {
      var html = cartSummaryMarkup(lines, units);
      if (node.innerHTML !== html) { node.innerHTML = html; }
      if (node.hidden) { node.removeAttribute('hidden'); }
      var sidebar = node.parentNode;
      if (!sidebar.querySelector('[data-fpw-cart-next]')) {
        var next = document.createElement('div'); next.setAttribute('data-fpw-cart-next', '');
        next.innerHTML = '<p class="fp-fine">Esta solicitud no es una compra ni reserva stock. Ventas confirmará precios, disponibilidad y condiciones.</p><p class="summary-links"><a href="/tienda/">Seguir agregando productos</a></p>';
        sidebar.appendChild(next);
      }
    } else if (!node.hidden) {
      node.setAttribute('hidden', '');
    }
    var footer = node.parentNode.querySelector('[data-fpw-cart-next]');
    if (footer && footer.hidden !== (lines === 0)) { footer.hidden = lines === 0; }
  }

  function syncBodyClass() {
    if (typeof document === 'undefined' || !document.body) { return; }
    var dock = document.querySelector(DOCK_SELECTOR);
    var has = Boolean(dock) && !dock.hasAttribute('hidden');
    document.body.classList.toggle('fpw-has-selection', has);
  }

  /* Distinct lines only — the same definition the server renders. A store
     that has not resolved its cart yet reports nothing, so a half-loaded
     value never flashes over the server-rendered number. */
  function lineCount() {
    try {
      var data = window.wp.data;
      if (typeof data.getStoreNames === 'function' && data.getStoreNames().indexOf(CART_STORE) === -1) { return null; }
      var store = data.select(CART_STORE);
      if (!store || typeof store.getCartData !== 'function') { return null; }
      if (!store.hasFinishedResolution('getCartData')) { return null; }
      var cart = store.getCartData();
      return cart && cart.items ? cart.items.length : null;
    } catch (err) {
      return null;
    }
  }

  function unitTotal() {
    try {
      var store = window.wp.data.select(CART_STORE);
      var cart = store && store.getCartData();
      if (!cart || !cart.items) { return null; }
      return cart.items.reduce(function (sum, item) { return sum + Number(item.quantity || 0); }, 0);
    } catch (err) {
      return null;
    }
  }

  /* Focus is DOM identity, not a product-name lookup (two colors can share a
     name). Retain the intent through deferred React commits and native error
     rollback; discard it on deliberate subsequent pointer/keyboard focus. */
  function createRemovalFocus() {
    var intent = null, restoring = false, removals = {}, removalErrors = {};
    if (!document.body.classList.contains('woocommerce-cart')) { return function () {}; }
    function pendingRemovalCopy() {
      // Pinned Cart speaks this phrase optimistically inside onClick, BEFORE
      // persistence. Use the public i18n seam for progress, then announce the
      // actual settled outcome below. No vendor or a11y API is patched.
      if (window.wp && window.wp.i18n && window.wp.i18n.setLocaleData) {
        window.wp.i18n.setLocaleData({ '%s has been removed from your cart.': ['Se está actualizando la selección de %s.'] }, 'woocommerce');
      }
    }
    pendingRemovalCopy();
    if (window.wp && window.wp.hooks && window.wp.hooks.addAction) {
      // Pinned dispatchStoreEvent('cart-remove-item', {product, quantity})
      // publishes through this same hook family as the quantity observer.
      window.wp.hooks.addAction('experimental__woocommerce_blocks-cart-remove-item', 'freeplast/removal-feedback', function (payload) {
        var item = payload && payload.product;
        if (!item || typeof item.key !== 'string' || !item.key) { return; }
        var options = Array.isArray(item.variation) ? item.variation.map(function (value) { return value.attribute + ': ' + value.value; }).join(', ') : '';
        removals[item.key] = String(item.name || '') + (options ? ' · ' + options : '');
        pendingRemovalCopy();
        // A new operation must not erase an unresolved error for another line.
        document.querySelectorAll('[data-fpw-removal-status]').forEach(function (node) { if (!node.hidden) node.hidden = true; });
        window.setTimeout(restore, 0);
      });
    }
    document.addEventListener('pointerdown', function () { intent = null; }, true);
    document.addEventListener('focusin', function (event) {
      if (intent && !restoring && event.target !== intent.button && event.target !== document.body) { intent = null; }
    });
    window.addEventListener('blur', function () { intent = null; });
    document.addEventListener('click', function (event) {
      var button = event.target.closest('.wc-block-cart-item__remove-link');
      var row = button && button.closest('.wc-block-cart-items__row');
      if (!row) { return; }
      intent = { button: button, row: row, next: row.nextElementSibling, previous: row.previousElementSibling };
      window.setTimeout(restore, 0);
    }, true);
    function restore() {
      if ((!intent && !Object.keys(removals).length) || typeof document === 'undefined' || !document.body) { return; }
      try {
        var store = window.wp.data.select(CART_STORE);
        var submit = document.querySelector('a.wc-block-cart__submit-button');
        if ((store.hasPendingItemsOperations && store.hasPendingItemsOperations()) || (submit && submit.getAttribute('aria-disabled') === 'true')) { return; }
      } catch (error) { return; }
      var settled = Object.keys(removals), successes = [];
      settled.forEach(function (key) {
        var saved = store.getCartItem(key), name = removals[key];
        if (saved) { removalErrors[key] = 'No pudimos confirmar la eliminación de «' + name + '». La última comprobación mostró ' + plural(saved.quantity, 'unidad', 'unidades') + '. Recarga para comprobar la selección guardada antes de volver a elegir Quitar.'; }
        else { delete removalErrors[key]; successes.push('Se quitó «' + name + '» de Productos a Cotizar.'); }
        delete removals[key];
      });
      if (settled.length) {
        [[Object.values(removalErrors), '[data-fpw-removal-error]'], [successes, '[data-fpw-removal-status]']].forEach(function (entry) {
          var node = document.querySelector(entry[1]), text = entry[0].join(' ');
          if (!node) { return; }
          if (node.textContent !== text) { node.textContent = text; }
          if (node.hidden !== !text) { node.hidden = !text; }
        });
      }
      if (!intent || (document.activeElement && document.activeElement !== document.body)) { return; }
      var target = intent.button.isConnected && !intent.button.disabled ? intent.button : null;
      if (!target && !intent.row.isConnected) {
        var row = intent.next && intent.next.isConnected ? intent.next : intent.previous && intent.previous.isConnected ? intent.previous : null;
        target = row ? row.querySelector('input:not(:disabled), a, button:not(:disabled)') : document.querySelector('.wp-block-woocommerce-empty-cart-block h2');
        if (target && target.tagName === 'H2') { target.tabIndex = -1; }
      }
      if (target) { restoring = true; target.focus(); restoring = false; }
    }
    return restore;
  }

  function init() {
    if (!document.body || document.body.hasAttribute('data-fpw-basket-bridge')) { return; }
    document.body.setAttribute('data-fpw-basket-bridge', '');
    var restoreFocus = createRemovalFocus();
    function syncFromStore() {
      if (typeof document === 'undefined' || !document.body) { return; }
      var count = lineCount();
      if (count !== null) {
        render(count);
        document.querySelectorAll('[data-fpw-cart-tools], [data-fpw-cart-hint]').forEach(function (node) {
          if (node.hidden !== (count === 0)) { node.hidden = count === 0; }
        });
        var label = document.querySelector('[data-fpw-line-count]');
        var text = plural(count, 'producto seleccionado', 'productos seleccionados');
        if (label && label.textContent !== text) { label.textContent = text; }
        var units = unitTotal();
        if (units !== null) { renderDock(count, units); renderCartSummary(count, units); }
      }
      restoreFocus();
    }
    if (window.wp && window.wp.data && typeof window.wp.data.subscribe === 'function') {
      window.wp.data.subscribe(syncFromStore);
    }
    syncFromStore();
    /* Classic fragment refresh replaces the dock node wholesale; jQuery's
       fragment events (and any DOM replacement) are followed by a body-class
       sync so the reserved padding always matches the visible dock. */
    var watchDom = function () { syncBodyClass(); syncFromStore(); };
    if (typeof MutationObserver === 'function' && document.body) {
      new MutationObserver(watchDom).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['hidden', 'aria-disabled'] });
    }
    if (window.jQuery && document.body) {
      window.jQuery(document.body).on('added_to_cart removed_from_cart wc_fragments_refreshed wc_fragments_loaded', function () {
        window.setTimeout(syncBodyClass, 0);
      });
    }
  }

  /* DOMContentLoaded waits for the footer block scripts, so the cart store is
     registered by then; on pages without it nothing is subscribed. */
  if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', init);
    } else {
      init();
    }
  }

  if (typeof module === 'object' && module.exports) {
    module.exports = { render: render, renderDock: renderDock, renderCartSummary: renderCartSummary, cartSummaryMarkup: cartSummaryMarkup, syncBodyClass: syncBodyClass };
  }
})();
