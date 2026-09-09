/* Per-product card counts and native removal controls. All markup/quantities
   come from the adapter's Woo cart fragment. No local cart, optimistic count,
   custom request or mutation: wc-add-to-cart owns add/remove and its queue;
   wc-cart-fragments owns cache/session refresh. The header keeps counting lines.
   Delay event renders until Woo has appended its own View cart link. */
(function () {
  'use strict';
  var KEY = 'div.fpw-card-selections';
  var SLOT = '[data-fpw-loop-add] .fpw-card-selection';

  function updateFromSnapshot(doc, snapshot) {
    if (!snapshot || snapshot.getAttribute('data-fpw-cart-state') !== '1') { return 0; }
    var selections = Object.create(null);
    snapshot.querySelectorAll('.fpw-card-selection').forEach(function (node) {
      selections[node.getAttribute('data-product-id')] = node;
    });
    var updated = 0;
    doc.querySelectorAll(SLOT).forEach(function (slot) {
      var selected = selections[slot.getAttribute('data-product-id')];
      var markup = selected ? selected.innerHTML : '';
      var wrapper = slot.closest('[data-fpw-loop-add]');
      // Replace the whole slot: native removal may have blocked this row.
      // Preserve untouched nodes (and focus) on ordinary fragment refreshes.
      if (slot.innerHTML !== markup) {
        var focused = slot.contains(doc.activeElement) ? doc.activeElement : null;
        var replacement = selected ? selected.cloneNode(true) : slot.cloneNode(false);
        replacement.removeAttribute('style');
        replacement.removeAttribute('aria-busy');
        slot.replaceWith(replacement);
        if (focused) {
          var target = replacement.querySelector(focused.classList.contains('fp-remove-product') ? '.fp-remove-product' : '.fp-added-pill');
          var fallback = target || wrapper.querySelector('.add_to_cart_button, .button, a[href]');
          if (fallback) { fallback.focus(); }
        }
      }
      wrapper.querySelectorAll('.added_to_cart').forEach(function (link) { link.remove(); });
      if (selected) {
        wrapper.querySelectorAll('[data-fpw-card-status]').forEach(function (status) { status.hidden = true; });
      }
      updated++;
    });
    doc.querySelectorAll('[data-fpw-detail-added]').forEach(function (detail) {
      var selected = selections[detail.getAttribute('data-product-id')];
      if (selected && !/^\d+$/.test(selected.getAttribute('data-quantity') || '')) { return; }
      var units = selected ? Number(selected.getAttribute('data-quantity')) : 0;
      if (!Number.isSafeInteger(units) || units < 0) { return; }
      var text = detail.querySelector('strong');
      if (text) { text.textContent = units.toLocaleString('es-CL') + (units === 1 ? ' unidad' : ' unidades') + ' de este producto en tu selección.'; }
      detail.hidden = units === 0;
    });
    return updated;
  }

  function updateAll(doc, fragments) {
    if (!fragments || typeof fragments[KEY] !== 'string') { return 0; }
    var template = doc.createElement('template');
    template.innerHTML = fragments[KEY];
    return updateFromSnapshot(doc, template.content.querySelector(KEY));
  }

  function bind(windowObj) {
    var jq = windowObj && windowObj.jQuery;
    if (typeof jq !== 'function' || !windowObj.document || !windowObj.document.body) { return false; }
    var doc = windowObj.document;
    var body = jq(doc.body);
    var restore = function () { updateFromSnapshot(doc, doc.querySelector(KEY)); };

    function hideStatus(wrapper) {
      var status = wrapper.querySelector('[data-fpw-card-status]');
      if (status) { status.hidden = true; }
    }

    function uncertainty(wrapper, removing) {
      hideStatus(wrapper);
      var notice = wrapper.querySelector('[data-fpw-add-error]');
      if (!notice) {
        notice = doc.createElement('p');
        notice.setAttribute('data-fpw-add-error', '');
        notice.setAttribute('role', 'alert');
        notice.setAttribute('aria-atomic', 'true');
        notice.className = 'fp-card-add-error';
        wrapper.appendChild(notice);
      }
      var text = removing
        ? 'No pudimos confirmar la eliminación. Revisa Productos a Cotizar antes de volver a quitar.'
        : 'No pudimos confirmar el agregado. Revisa Productos a Cotizar antes de volver a agregar.';
      // Do not repeat the same alert on unrelated fragment events.
      if (notice.firstChild && notice.firstChild.textContent === text) { return; }
      notice.textContent = text;
      var link = doc.createElement('a');
      link.href = (windowObj.wc_add_to_cart_params && windowObj.wc_add_to_cart_params.cart_url) || '/cotizacion/';
      link.textContent = ' Revisar selección';
      notice.appendChild(link);
    }

    function confirmedUnits(fragments, id) {
      if (!fragments || typeof fragments[KEY] !== 'string') { return null; }
      var template = doc.createElement('template');
      template.innerHTML = fragments[KEY];
      var snapshot = template.content.querySelector(KEY);
      if (!snapshot || snapshot.getAttribute('data-fpw-cart-state') !== '1') { return null; }
      var found = null;
      snapshot.querySelectorAll('.fpw-card-selection').forEach(function (slot) {
        if (slot.getAttribute('data-product-id') === id) { found = slot; }
      });
      if (!found) { return 0; }
      var raw = found.getAttribute('data-quantity') || '';
      var units = /^\d+$/.test(raw) ? Number(raw) : NaN;
      return Number.isSafeInteger(units) && units > 0 ? units : null;
    }

    body.on('adding_to_cart', function (event, button) {
      var wrapper = button && button[0] && button[0].closest('[data-fpw-loop-add]');
      if (wrapper) { hideStatus(wrapper); }
    });
    body.on('added_to_cart removed_from_cart', function (event, fragments, hash, button) {
      var wrapper = button && button[0] && button[0].closest('[data-fpw-loop-add]');
      var slot = wrapper && wrapper.querySelector('.fpw-card-selection');
      var id = slot && slot.getAttribute('data-product-id');
      var removing = event.type === 'removed_from_cart';
      windowObj.setTimeout(function () {
        var units = id ? confirmedUnits(fragments, id) : null;
        // Never project malformed quantities as a newly confirmed state.
        if (!wrapper || units !== null) { updateAll(doc, fragments); }
        if (!wrapper || !wrapper.isConnected) { return; }
        if (units === null || (removing ? units !== 0 : units === 0)) {
          uncertainty(wrapper, removing);
          return;
        }
        // Only this operation's confirmed product clears its prior errors.
        doc.querySelectorAll('[data-fpw-loop-add]').forEach(function (copy) {
          var current = copy.querySelector('.fpw-card-selection');
          if (!current || current.getAttribute('data-product-id') !== id) { return; }
          copy.querySelectorAll('[data-fpw-add-error]').forEach(function (notice) { notice.remove(); });
          hideStatus(copy);
        });
        if (!removing) { return; } // The native quantity pill already confirms adds.
        var status = wrapper.querySelector('[data-fpw-card-status]');
        if (!status) {
          status = doc.createElement('p');
          status.className = 'fp-card-status';
          status.setAttribute('data-fpw-card-status', '');
          status.setAttribute('role', 'status');
          status.setAttribute('aria-atomic', 'true');
          wrapper.appendChild(status);
        }
        status.textContent = 'Producto quitado de Productos a Cotizar.';
        status.hidden = false;
      }, 0);
    });
    // A transport error is not proof that the native add was not persisted.
    // Release its visual loading state, but direct the user to saved truth
    // before another add. Do not retry the mutation or increment a local count.
    jq(doc).on('ajaxError.fpwCard', function (event, xhr, settings) {
      if (!settings || !settings.url || !windowObj.wc_add_to_cart_params) { return; }
      var url = new windowObj.URL(settings.url, windowObj.location.href);
      if (url.searchParams.get('wc-ajax') !== 'add_to_cart') { return; }
      var params = typeof settings.data === 'string' ? new windowObj.URLSearchParams(settings.data) : null;
      var id = params ? params.get('product_id') : settings.data && settings.data.product_id;
      var buttons = Array.from(doc.querySelectorAll('[data-fpw-loop-add] .add_to_cart_button')).filter(function (button) {
        return String(id) === button.getAttribute('data-product_id');
      });
      var origin = buttons.find(function (button) { return button.classList.contains('loading'); }) || buttons[0];
      buttons.forEach(function (button) { button.classList.remove('loading'); });
      // Duplicate cards may share an ID; announce the failed operation once.
      if (origin) { uncertainty(origin.closest('[data-fpw-loop-add]'), false); }
    });
    body.on('wc_fragments_loaded wc_fragments_refreshed', function () {
      windowObj.setTimeout(restore, 0);
    });
    windowObj.addEventListener('pageshow', function (event) {
      if (event.persisted) { body.trigger('wc_fragment_refresh'); }
    });
    restore();
    return true;
  }

  if (typeof window !== 'undefined' && typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', function () { bind(window); });
    } else { bind(window); }
  }
  if (typeof module === 'object' && module.exports) {
    module.exports = { updateAll: updateAll, bind: bind };
  }
})();
