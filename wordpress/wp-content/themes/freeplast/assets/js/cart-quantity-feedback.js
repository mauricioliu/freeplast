/* Visible feedback and recovery for failed quantity changes in Productos a
   Cotizar (issue #26, finding WA-03 of the 2026-09-05 acceptance review).

   Root cause, verified in the pinned WooCommerce 11.1.0 sources: the cart
   block's own data store rolls a failed quantity update back in silence.
   changeCartItemQuantity() POSTs /wc/store/v1/cart/update-item and, on any
   failure, dispatches receiveError(isApiErrorResponse(e) ? e : null) — a
   connection loss reaches it as api-fetch's {code:'fetch_error'} object,
   which only records an error no cart-page surface ever renders: no notice
   exists anywhere while the number jumps back to the persisted value. A
   server error response is also only a transient snackbar that never states
   the quantity that actually remained saved.

   This script mirrors the native surfaces; it owns no cart data (ADR-0001):
   - The customer's stated quantity is learned from WooCommerce's own store
     event 'experimental__woocommerce_blocks-cart-set-item-quantity', fired by
     the block's own quantity selector for every input modality (+/− buttons,
     typed numbers, keyboard arrows).
   - Settlement is detected with the store's own selectors
     (getItemsPendingQuantityUpdate/getCartItem) plus a read-only count of
     in-flight update-item requests at the transport boundary. The count and
     the deferral below close a real gap in the store's pending flag: Woo
     aborts an in-flight request when a new one starts and the aborted
     request's cleanup clears the flag while its replacement is still running.
     A verdict is therefore never taken on the settling tick itself; any store
     or transport activity cancels and re-arms a short deferred evaluation,
     so the verdict is only taken after every request chain has fully drained.
   - When a change settles below the stated quantity, a visible Spanish notice
     explains that the change was not saved and names the persisted quantity;
     it is announced to assistive technology through role="alert" without
     moving focus. When a change (or a retry after reconnecting) saves, the
     notice is replaced by a polite confirmation with the exact persisted
     quantity — retries cannot accumulate increments because the intent always
     comes from the block's own input and the persisted value is always stated.
   - While any quantity mutation is unconfirmed, the «Datos y envío» CTA
     carries aria-disabled="true" and cannot navigate: its native visual
     disabled state is an attribute that does not block anchors, and Woo's own
     preventDefault runs asynchronously, after navigation has already begun.
     "Unconfirmed" has one coherent definition shared with the verdicts: an
     operation is pending while its store flag is set OR its update-item
     request is still in flight — Woo's abort of a replaced request clears the
     store flag while the replacement still runs, so the transport count is
     what keeps the CTA locked in that window. The CTA gates on any pending
     operation (pointer and keyboard activation alike); once every operation
     settles — success or failure — the CTA is operable again, so continuing
     explicitly with the persisted quantity never blocks.
   - If the pending disable cycle dropped keyboard focus (a native row
     behaviour), focus is returned to the control the customer was using,
     never stolen from a deliberate later focus.

   No polling, no requests of its own, no parallel cart: WooCommerce keeps
   owning quantities, sessions and persistence. Without the cart block's data
   store the script exits; without JavaScript there are no quantity updates to
   report. */
(function () {
  'use strict';
  var CART_STORE = 'wc/store/cart';
  var INTENT_EVENT = 'experimental__woocommerce_blocks-cart-set-item-quantity';
  var EVENT_NAMESPACE = 'freeplast/cart-quantity-feedback';
  var SUBMIT_SELECTOR = 'a.wc-block-cart__submit-button';
  var DOCK_SELECTOR = '.wc-block-cart__main, .wp-block-woocommerce-cart';
  var UPDATE_ITEM_URL = '/wc/store/v1/cart/update-item';
  var VERDICT_DELAY_MS = 50;

  function unitsWord(quantity) {
    return quantity === 1 ? 'unidad' : 'unidades';
  }

  function failMessage(name, persisted) {
    return 'No se guardó el cambio de cantidad de «' + name + '»: Productos a Cotizar sigue con ' + persisted + ' ' + unitsWord(persisted) + '. Revisa tu conexión e inténtalo de nuevo.';
  }

  function savedMessage(name, persisted) {
    return 'Cantidad guardada: ' + persisted + ' ' + unitsWord(persisted) + ' de «' + name + '».';
  }

  /* Verdict once an update has settled: compare what the customer stated
     with what WooCommerce reports as persisted. */
  function evaluate(persisted, stated) {
    return persisted === stated ? 'saved' : 'failed';
  }

  /* Write the verdict into the notice slots. Error announcements are
     assertive (role="alert"); confirmations are polite (role="status"). */
  function applyNotice(slots, verdict, name, persisted) {
    if (verdict === 'failed') {
      slots.error.textContent = failMessage(name, persisted);
      slots.error.hidden = false;
      slots.status.hidden = true;
      return;
    }
    slots.status.textContent = savedMessage(name, persisted);
    slots.status.hidden = false;
    slots.error.hidden = true;
  }

  function createWatcher(windowObj) {
    var doc = windowObj.document;
    var wpdata = windowObj.wp && windowObj.wp.data;
    var store = wpdata && wpdata.select(CART_STORE);
    if (!wpdata || !store || typeof store.getCartData !== 'function' || typeof store.getItemsPendingQuantityUpdate !== 'function') {
      return null;
    }
    var slots = null;
    var intents = {};
    var inflight = 0;
    var verdictTimer = null;
    var lastFocus = null;
    var submit = null;

    function ensureSlots() {
      if (slots) { return slots; }
      var dock = doc.querySelector(DOCK_SELECTOR);
      if (!dock || typeof dock.insertBefore !== 'function') { return null; }
      var container = doc.createElement('div');
      container.className = 'fp-cart-feedback';
      container.setAttribute('data-fp-cart-feedback', '');
      var error = doc.createElement('p');
      error.className = 'fp-cart-feedback__error';
      error.setAttribute('role', 'alert');
      error.setAttribute('aria-atomic', 'true');
      error.hidden = true;
      var status = doc.createElement('p');
      status.className = 'fp-cart-feedback__status';
      status.setAttribute('role', 'status');
      status.setAttribute('aria-atomic', 'true');
      status.hidden = true;
      container.appendChild(error);
      container.appendChild(status);
      dock.insertBefore(container, dock.firstChild);
      slots = { error: error, status: status };
      return slots;
    }

    function rememberFocus() {
      var active = doc.activeElement;
      lastFocus = active && active !== doc.body && typeof active.focus === 'function' ? active : null;
    }

    /* Return keyboard focus lost to the pending disable cycle; never take
       it from wherever the customer deliberately moved it. */
    function restoreFocus() {
      var active = doc.activeElement;
      var lost = !active || (doc.body && active === doc.body);
      if (!lost || !lastFocus || lastFocus.disabled === true) { return; }
      if (typeof lastFocus.isConnected !== 'undefined' && !lastFocus.isConnected) { return; }
      try { lastFocus.focus({ preventScroll: true }); } catch (err) { try { lastFocus.focus(); } catch (inner) { /* unavailable: stay quiet */ } }
    }

    /* One coherent definition of an unconfirmed quantity operation, shared by
       the two decisions that must wait for it: the store's own pending flags
       AND the read-only transport count. The store flag alone clears early —
       Woo's abort of a replaced request runs its cleanup while the replacement
       is still in flight — so an in-flight update-item request always counts
       as pending too. The CTA gates on any pending operation; a verdict
       additionally waits on its own item's flag (verdictWaiting). */
    function operationsPending() {
      var storeBusy = typeof store.hasPendingItemsOperations === 'function' && store.hasPendingItemsOperations();
      return storeBusy || inflight > 0;
    }

    /* aria state of the «Datos y envío» CTA follows pending quantity
       operations; clicks are stopped synchronously while pending so an
       unconfirmed quantity can never advance — pointer and keyboard
       activation alike (Enter on an anchor fires a click event). */
    function syncSubmit() {
      if (submit && typeof submit.isConnected !== 'undefined' && !submit.isConnected) { submit = null; }
      if (!submit) { submit = doc.querySelector(SUBMIT_SELECTOR); }
      if (!submit || typeof submit.setAttribute !== 'function') { return; }
      var busy = operationsPending();
      submit.setAttribute('aria-disabled', busy ? 'true' : 'false');
      if (busy && typeof submit.getAttribute === 'function' && submit.getAttribute('data-fp-submit-guard') !== 'true') {
        submit.setAttribute('data-fp-submit-guard', 'true');
        submit.addEventListener('click', function (event) {
          if (operationsPending()) { event.preventDefault(); }
        }, true);
      }
    }

    /* Turn one settled intent into its visible verdict. The item is passed in
       by the caller, which has already confirmed it still exists. */
    function settle(key, item) {
      var stated = intents[key];
      delete intents[key];
      if (!stated || !item) { return; }
      var verdict = evaluate(item.quantity, stated.quantity);
      var target = ensureSlots();
      if (!target) { return; }
      applyNotice(target, verdict, stated.name, item.quantity);
      restoreFocus();
    }

    function cancelVerdict() {
      if (verdictTimer !== null) { clearTimeout(verdictTimer); verdictTimer = null; }
    }

    function scheduleVerdict() {
      if (verdictTimer === null) {
        verdictTimer = setTimeout(fireVerdict, VERDICT_DELAY_MS);
      }
    }

    /* A verdict must wait while the store still flags the item as pending or
       an update-item request is still in flight — an aborted request whose
       replacement is still running counts as active. */
    function verdictWaiting(key, pending) {
      return pending.indexOf(key) !== -1 || inflight > 0;
    }

    function evaluateIntents() {
      cancelVerdict();
      var pending = store.getItemsPendingQuantityUpdate();
      var remaining = false;
      for (var key in intents) {
        if (verdictWaiting(key, pending)) { remaining = true; continue; }
        if (!store.getCartItem(key)) { delete intents[key]; continue; }
        remaining = true; // settleable — but only once every chain has drained
      }
      if (remaining) { scheduleVerdict(); }
      syncSubmit();
    }

    function fireVerdict() {
      verdictTimer = null;
      var pending = store.getItemsPendingQuantityUpdate();
      for (var key in intents) {
        if (verdictWaiting(key, pending)) { scheduleVerdict(); return; }
        var item = store.getCartItem(key);
        if (!item) { delete intents[key]; continue; }
        settle(key, item);
      }
      syncSubmit();
    }

    /* Read-only observation of the transport: count in-flight update-item
       requests so the store's early pending cleanup after an abort cannot
       declare a verdict while its replacement is still running. All other
       requests pass through untouched. */
    function watchTransport() {
      var original = windowObj.fetch;
      if (typeof original !== 'function') { return; }
      var wrapped = function (input) {
        var url = '';
        try { url = typeof input === 'string' ? input : (input && input.url) || ''; } catch (err) { url = ''; }
        if (url.indexOf(UPDATE_ITEM_URL) === -1) { return original.apply(this, arguments); }
        inflight++;
        syncSubmit(); // the transport observation itself locks the CTA at request start
        var done = function () { inflight = Math.max(0, inflight - 1); evaluateIntents(); };
        var request = original.apply(this, arguments);
        request.then(done, done);
        return request;
      };
      try {
        windowObj.fetch = wrapped;
      } catch (err) { /* frozen environment: the store's own pending flag still rules */ }
    }

    if (windowObj.wp && windowObj.wp.hooks && typeof windowObj.wp.hooks.addAction === 'function') {
      windowObj.wp.hooks.addAction(INTENT_EVENT, EVENT_NAMESPACE, function (payload) {
        var item = payload && payload.product;
        var quantity = payload && payload.quantity;
        if (!item || typeof item.key !== 'string' || typeof quantity !== 'number' || !isFinite(quantity)) { return; }
        intents[item.key] = {
          name: typeof item.name === 'string' ? item.name : '',
          quantity: quantity
        };
        rememberFocus();
      });
    }
    if (typeof wpdata.subscribe === 'function') {
      wpdata.subscribe(evaluateIntents, CART_STORE);
    }
    watchTransport();
    syncSubmit();

    return {
      evaluateIntents: evaluateIntents,
      intents: intents,
      inflight: function () { return inflight; }
    };
  }

  function init() {
    if (typeof window === 'undefined' || typeof document === 'undefined') { return; }
    createWatcher(window);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  /* Offline behavioral coverage drives the pure helpers directly. */
  if (typeof module === 'object' && module.exports) {
    module.exports = {
      evaluate: evaluate,
      applyNotice: applyNotice,
      failMessage: failMessage,
      savedMessage: savedMessage,
      createWatcher: createWatcher
    };
  }
})();
