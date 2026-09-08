/* Count pill on the product-card «added» link. WooCommerce's own AJAX add
   (pinned 11.1.0 add-to-cart.js) appends the native <a.added_to_cart> —
   «Ver carrito» — next to the button just used and fires added_to_cart on
   document.body carrying the SAME add-to-cart fragments the header count
   already uses (span.fpw-basket-count: distinct cart lines, rendered by the
   freeplast-woo adapter from WC()->cart). This script re-renders every
   .added_to_cart link on the page as a count pill («5 en cotización»), so the
   number sits exactly where the customer added and every card that shows the
   link stays consistent — not only the card last clicked.

   The script owns no cart state (ADR-0001): the number is read from Woo's
   own fragment payload; when the payload is missing or malformed the links
   are left exactly as WooCommerce rendered them, and without JavaScript the
   native link is kept. The render is deferred one task because Woo appends
   the link inside its own listener for this same event and binding order
   between scripts is not guaranteed. Enqueued on every route: any surface
   may render a product loop, and the binding is inert wherever no
   added_to_cart event ever fires. */
(function () {
  'use strict';
  var LINK_SELECTOR = '.added_to_cart';
  var FRAGMENT_KEY = 'span.fpw-basket-count';
  var PILL_CLASS = 'fp-added-pill';
  var BADGE_CLASS = 'fp-added-pill__badge';
  var TEXT_CLASS = 'fp-added-pill__text';

  /* Strict read of the adapter's pinned fragment shape
     '<span class="fpw-basket-count">N</span>' — anything else is unusable. */
  function extractCount(fragments) {
    if (!fragments || typeof fragments !== 'object') { return null; }
    var html = fragments[FRAGMENT_KEY];
    if (typeof html !== 'string') { return null; }
    var match = html.match(/fpw-basket-count">\s*(\d+)\s*</);
    return match ? parseInt(match[1], 10) : null;
  }

  function renderPill(doc, link, count) {
    if (!link || typeof link.appendChild !== 'function') { return false; }
    var badge = link.querySelector('.' + BADGE_CLASS);
    if (!badge) {
      badge = doc.createElement('span');
      badge.className = BADGE_CLASS;
      var text = doc.createElement('span');
      text.className = TEXT_CLASS;
      text.textContent = 'en cotización';
      while (link.firstChild) { link.removeChild(link.firstChild); } // drop the native «Ver carrito» text
      link.appendChild(badge);
      link.appendChild(text);
      link.classList.add(PILL_CLASS);
      /* The native title («Ver carrito») no longer describes this control. */
      link.removeAttribute('title');
    }
    badge.textContent = String(count);
    /* Starts with the visible text (WCAG 2.5.3) and names the destination. */
    link.setAttribute('aria-label', count + ' en cotización — ver Productos a Cotizar');
    return true;
  }

  function updateAll(doc, fragments) {
    var count = extractCount(fragments);
    if (count === null) { return 0; }
    var links = doc.querySelectorAll(LINK_SELECTOR);
    var updated = 0;
    for (var i = 0; i < links.length; i++) {
      if (renderPill(doc, links[i], count)) { updated++; }
    }
    return updated;
  }

  function bind(windowObj) {
    var jq = windowObj && windowObj.jQuery;
    if (typeof jq !== 'function' || !windowObj.document || typeof windowObj.document.body === 'undefined') { return false; }
    jq(windowObj.document.body).on('added_to_cart', function (event, fragments) {
      windowObj.setTimeout(function () { updateAll(windowObj.document, fragments); }, 0);
    });
    return true;
  }

  if (typeof window !== 'undefined' && typeof document !== 'undefined') {
    var init = function () { bind(window); };
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', init);
    } else {
      init();
    }
  }

  /* Offline behavioral coverage drives the pure helpers directly. */
  if (typeof module === 'object' && module.exports) {
    module.exports = { extractCount: extractCount, renderPill: renderPill, updateAll: updateAll, bind: bind };
  }
})();
