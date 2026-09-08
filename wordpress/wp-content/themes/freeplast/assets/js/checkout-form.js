/* A's presentation over the ONE classic Woo form. Actual native AJAX events
   own busy/error/draft handoff timing. No submission, retry, persistence or
   request identity is implemented here; draft handoff triggers Woo's existing
   update_checkout and waits for its successful response before navigation. */
(function () {
  'use strict';
  var UNCERTAIN_COPY = 'No pudimos confirmar tu solicitud. Puede que ya se haya guardado. Inténtalo de nuevo desde este mismo formulario; se conserva el mismo intento para evitar una solicitud duplicada.';
  var buttons = new WeakMap();
  var disclosures = new WeakMap();
  function desktop() { return typeof matchMedia === 'function' && matchMedia('(min-width: 1000px)').matches; }
  function syncSummary() {
    var details = document.querySelector('[data-fp-summary-details]');
    if (!details) { return; }
    var wide = desktop(), previous = disclosures.get(details);
    if (wide) { details.open = true; }
    else if (previous === true || previous === undefined) { details.open = false; }
    disclosures.set(details, wide); // same mobile mode preserves the user's toggle
    var nativeCount = document.querySelector('[data-fpw-review-count]');
    var caption = document.querySelector('.fp-summary-count');
    if (nativeCount && caption) { caption.textContent = nativeCount.getAttribute('data-fpw-review-count'); }
  }
  function clearFieldErrors(form) {
    var nativeIds = new Set();
    form.querySelectorAll('.checkout-inline-error-message').forEach(function (node) { nativeIds.add(node.id); node.remove(); });
    form.querySelectorAll('[data-fp-field-error]').forEach(function (node) { node.remove(); });
    form.querySelectorAll('[aria-describedby]').forEach(function (input) {
      var ids = (input.getAttribute('aria-describedby') || '').split(/\s+/);
      var remaining = ids.filter(function (id) { return id.indexOf('fp-field-error-') !== 0 && !nativeIds.has(id); });
      if (remaining.length === ids.length) { return; }
      if (remaining.length) { input.setAttribute('aria-describedby', remaining.join(' ')); }
      else { input.removeAttribute('aria-describedby'); }
      if (!input.closest('.woocommerce-invalid')) { input.removeAttribute('aria-invalid'); }
    });
  }
  function clearDispatchErrors(form, value) {
    if (value !== 'si' && value !== 'no') { return; }
    var ids = ['billing_fp_dispatch_si', 'billing_fp_dispatch_no'];
    var fields = ['billing_fp_dispatch'];
    if (value === 'no') { ids.push('billing_fp_address'); fields.push('billing_fp_address'); }
    fields.forEach(function (key) {
      var row = document.getElementById(key + '_field');
      if (!row || !form.contains(row)) { return; }
      Array.prototype.slice.call(row.classList).forEach(function (name) { if (name.indexOf('woocommerce-invalid') === 0) row.classList.remove(name); });
      clearFieldErrors(row);
      row.querySelectorAll('[aria-invalid]').forEach(function (input) { input.removeAttribute('aria-invalid'); });
    });
    var summary = form.querySelector('.fp-error-summary');
    if (!summary) { return; }
    summary.querySelectorAll('li a').forEach(function (link) { if (ids.includes(link.getAttribute('href').slice(1))) link.closest('li').remove(); });
    if (!summary.querySelector('li')) { summary.remove(); return; }
    var count = new Set(Array.prototype.map.call(summary.querySelectorAll('li a'), function (link) { return link.getAttribute('href'); })).size;
    summary.querySelector('h2').textContent = count ? 'Revisa ' + count + (count === 1 ? ' campo' : ' campos') + ' para continuar.' : 'No pudimos confirmar tu solicitud.';
    if (!count) { summary.querySelector('.fp-fine').textContent = UNCERTAIN_COPY; }
  }
  function errorControl(form, id) {
    var input = document.getElementById(id);
    if (!input && id === 'billing_fp_dispatch') { input = form.querySelector('input[name="billing_fp_dispatch"]'); }
    return input && form.contains(input) && input.type !== 'hidden' ? input : null;
  }
  function buildErrorSummary(group) {
    var form = group && group.closest('form.checkout');
    if (!form) { return; }
    var existing = form.querySelector('.fp-error-summary');
    if (existing) { existing.remove(); }
    clearFieldErrors(form);
    var items = Array.prototype.slice.call(group.querySelectorAll('li'));
    var summary = document.createElement('div');
    summary.className = 'fp-error-summary'; summary.setAttribute('role', 'alert'); summary.tabIndex = -1;
    var ul = document.createElement('ul'), linked = new Set();
    items.forEach(function (li, index) {
      var id = li.getAttribute('data-id');
      var input = id && errorControl(form, id);
      var entry = document.createElement('li');
      var message = li.textContent.trim(); // keep the cause, not just <strong>Field</strong>
      if (input) {
        var link = document.createElement('a'); link.href = '#' + input.id; link.textContent = message;
        link.addEventListener('click', function (event) { event.preventDefault(); input.focus(); });
        entry.appendChild(link); linked.add(id);
        var span = document.createElement('span'); span.className = 'fp-field-error checkout-inline-error-message';
        span.id = 'fp-field-error-' + input.id + '-' + index;
        span.setAttribute('data-fp-field-error', ''); span.textContent = message;
        var row = input.closest('.form-row') || input.parentNode;
        row.appendChild(span);
        var targets = id === 'billing_fp_dispatch' ? form.querySelectorAll('input[name="billing_fp_dispatch"]') : [input];
        Array.prototype.forEach.call(targets, function (target) {
          target.setAttribute('aria-invalid', 'true');
          target.setAttribute('aria-describedby', ((target.getAttribute('aria-describedby') || '') + ' ' + span.id).trim());
        });
      } else { entry.textContent = message; }
      ul.appendChild(entry);
    });
    var heading = document.createElement('h2');
    heading.textContent = linked.size ? 'Revisa ' + linked.size + (linked.size === 1 ? ' campo' : ' campos') + ' para continuar.' : 'No pudimos confirmar tu solicitud.';
    summary.appendChild(heading);
    var note = document.createElement('p'); note.className = 'fp-fine';
    note.textContent = linked.size ? 'Los datos que completaste siguen en este formulario. Revisa los avisos antes de reintentar.' : UNCERTAIN_COPY;
    summary.appendChild(note);
    if (items.length) { summary.appendChild(ul); }
    var column = form.querySelector('.fp-checkout-form');
    var hint = column && column.querySelector('.fp-required-hint');
    if (hint) { hint.parentNode.insertBefore(summary, hint.nextSibling); }
    else if (column) { column.insertBefore(summary, column.firstChild); }
    else { group.parentNode.insertBefore(summary, group.nextSibling); }
    form.classList.add('fp-errors-enhanced'); summary.focus();
  }
  function enhanceErrors(group) { buildErrorSummary(group); }
  function markSending(form) {
    var button = form && form.querySelector('#place_order');
    if (!button) { return; }
    if (!buttons.has(button)) { buttons.set(button, { html: button.innerHTML, disabled: button.disabled }); }
    form.setAttribute('aria-busy', 'true'); button.setAttribute('aria-busy', 'true');
    button.disabled = true; button.textContent = 'Enviando…';
  }
  function restoreSending(form) {
    if (!form) { return; }
    form.removeAttribute('aria-busy');
    var button = form.querySelector('#place_order'), saved = button && buttons.get(button);
    if (!button) { return; }
    button.removeAttribute('aria-busy');
    if (saved) { button.innerHTML = saved.html; button.disabled = saved.disabled; buttons.delete(button); }
  }
  function endpoint(settings) {
    try { return new URL(settings.url, window.location.href).searchParams.get('wc-ajax'); } catch (error) { return null; }
  }
  function init() {
    var form = document.querySelector('form.checkout');
    if (!form || form.hasAttribute('data-fp-checkout-enhanced')) { return; }
    form.setAttribute('data-fp-checkout-enhanced', '');
    syncSummary();
    if (typeof matchMedia === 'function') { matchMedia('(min-width: 1000px)').addEventListener('change', syncSummary); }
    form.addEventListener('click', function (event) {
      if (event.target.closest('[data-fp-summary-details] summary') && desktop()) { event.preventDefault(); }
    });
    form.addEventListener('change', function (event) {
      if (event.target.name === 'billing_fp_dispatch') { clearDispatchErrors(form, event.target.value); }
    });
    if (!window.jQuery) { return; } // classic no-JS POST remains native
    var $ = window.jQuery, pending = new Set(), reviews = new WeakMap(), navigation = null;
    function syncBusy() {
      if (pending.size || form.classList.contains('processing')) { markSending(form); }
      else { restoreSending(form); }
    }
    function draftFailure() {
      navigation = null;
      var notice = form.querySelector('[data-fp-draft-error]');
      if (!notice) {
        notice = document.createElement('p'); notice.className = 'fp-error-summary';
        notice.setAttribute('data-fp-draft-error', ''); notice.setAttribute('role', 'alert');
        form.querySelector('.fp-checkout-summary').appendChild(notice);
      }
      notice.textContent = 'No pudimos confirmar el guardado de tus datos. Siguen en este formulario. Revisa tu conexión y vuelve a elegir Editar productos.';
    }
    form.addEventListener('click', function (event) {
      var link = event.target.closest('.fp-edit-products');
      if (!link || event.button > 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) { return; }
      event.preventDefault();
      if (navigation || pending.size || form.classList.contains('processing')) { return; }
      form.querySelectorAll('[data-fp-draft-error]').forEach(function (node) { node.remove(); });
      navigation = { href: link.href, serialized: $(form).serialize() };
      $(document.body).trigger('update_checkout');
    });
    $(document).on('ajaxSend.fpwCheckout', function (event, xhr, settings) {
      var action = endpoint(settings);
      if (action === 'checkout') { pending.add(xhr); syncBusy(); }
      if (action === 'update_order_review') {
        var data = typeof settings.data === 'string' ? new URLSearchParams(settings.data).get('post_data') : settings.data && settings.data.post_data;
        reviews.set(xhr, data);
        if (navigation && data === $(form).serialize()) { navigation.serialized = data; }
      }
    });
    $(document).on('ajaxComplete.fpwCheckout', function (event, xhr, settings) {
      if (endpoint(settings) === 'checkout') { pending.delete(xhr); syncBusy(); }
      if (!navigation || endpoint(settings) !== 'update_order_review' || xhr.statusText === 'abort') { return; }
      if (reviews.get(xhr) !== navigation.serialized) { return; }
      if (xhr.status !== 200 || !xhr.responseJSON || xhr.responseJSON.result !== 'success') { draftFailure(); return; }
      var latest = $(form).serialize();
      if (latest !== navigation.serialized) {
        navigation.serialized = latest; $(document.body).trigger('update_checkout'); return;
      }
      var href = navigation.href; navigation = null; window.location.assign(href);
    });
    $(document.body).on('checkout_error.fpwCheckout', function () {
      // Woo emits checkout_error BEFORE it appends its own inline messages.
      window.setTimeout(function () {
        var group = form.querySelector('.woocommerce-NoticeGroup-checkout');
        enhanceErrors(group); syncBusy();
      }, 0);
    });
    $(document.body).on('updated_checkout.fpwCheckout', function () { syncSummary(); syncBusy(); });
  }
  if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
  }
  if (typeof module === 'object' && module.exports) {
    module.exports = { syncSummary: syncSummary, enhanceErrors: enhanceErrors, markSending: markSending, restoreSending: restoreSending, buildErrorSummary: buildErrorSummary, UNCERTAIN_COPY: UNCERTAIN_COPY };
  }
})();
