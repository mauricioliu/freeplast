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
          (target || wrapper.querySelector('.add_to_cart_button')).focus();
        }
      }
      wrapper.querySelectorAll('.added_to_cart').forEach(function (link) { link.remove(); });
      updated++;
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
    body.on('added_to_cart removed_from_cart', function (event, fragments) {
      windowObj.setTimeout(function () { updateAll(doc, fragments); }, 0);
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
