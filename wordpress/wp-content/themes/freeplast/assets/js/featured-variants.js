/* Featured cards only. Woo's native add-to-cart handler owns transport/queue,
   validation and fragments; this module selects an exact, server-resolved ID.
   No alternative cart, endpoint, optimistic count or automatic retry. */
(function () {
  'use strict';
  function enhance(picker, win) {
    var jq = win.jQuery;
    if (!jq || !win.wc_add_to_cart_params || win.wc_add_to_cart_params.cart_redirect_after_add === 'yes' || picker.dataset.fpwReady) { return; }
    var wrap = picker.closest('[data-fpw-loop-add]');
    var input = picker.querySelector('input.qty');
    var add = picker.querySelector('.add_to_cart_button');
    var status = picker.querySelector('[data-fpw-featured-status]');
    var swatches = Array.from(picker.querySelectorAll('[data-fpw-variant]'));
    if (!input || !add || !status || !swatches.length) { return; }
    var options;
    try { options = swatches.map(function (button) { return JSON.parse(button.dataset.fpwVariant); }); }
    catch (error) { return; } // Keep the visible product-page fallback.
    picker.dataset.fpwReady = '1';
    picker.hidden = false;
    wrap.classList.add('fp-featured-enhanced');
    var fallback = wrap.querySelector('a.button.secondary');
    if (fallback && fallback.firstChild) { fallback.firstChild.textContent = 'Ver ficha '; }
    var watchdog;
    var hint = wrap.querySelector('.color-hint');
    if (hint) { hint.hidden = true; }
    var selected = null;
    function blocked() { return picker.hasAttribute('data-fpw-pending') || picker.hasAttribute('data-fpw-uncertain'); }
    function sync() {
      var valid = selected && Number.isSafeInteger(Number(input.value)) && Number(input.value) > 0 && input.checkValidity();
      add.setAttribute('aria-disabled', valid && !blocked() ? 'false' : 'true');
      if (valid) { add.dataset.quantity = input.value; }
    }
    function busy(value) {
      picker.toggleAttribute('data-fpw-pending', value);
      picker.setAttribute('aria-busy', value ? 'true' : 'false');
      var locked = value || picker.hasAttribute('data-fpw-uncertain');
      swatches.forEach(function (button, i) { button.disabled = locked || !options[i].id; });
      input.disabled = locked;
      picker.querySelectorAll('.fp-qty-step').forEach(function (button) { button.disabled = locked || !!(selected && selected.individual); });
      sync();
    }
    function uncertain() {
      win.clearTimeout(watchdog);
      picker.setAttribute('data-fpw-uncertain', '');
      add.classList.remove('loading');
      busy(false);
      status.textContent = 'No pudimos confirmar el agregado. Revisa Productos a Cotizar antes de volver a agregar; recarga esta página para continuar.';
      var review = win.document.createElement('a');
      review.href = win.wc_add_to_cart_params.cart_url;
      review.textContent = 'Revisar Productos a Cotizar';
      status.appendChild(review);
    }
    swatches.forEach(function (button, i) {
      button.addEventListener('click', function () {
        if (blocked() || button.disabled || !options[i].id) { return; }
        selected = options[i];
        swatches.forEach(function (other) { other.setAttribute('aria-pressed', other === button ? 'true' : 'false'); });
        add.dataset.product_id = String(selected.id);
        jq(add).data('product_id', selected.id); // Also support native jQuery dataset readers.
        input.max = selected.max === '' ? '' : String(selected.max);
        input.readOnly = selected.individual;
        if (selected.individual) { input.value = '1'; }
        picker.querySelectorAll('.fp-qty-step').forEach(function (step) { step.disabled = selected.individual; });
        status.textContent = 'Seleccionado: ' + selected.label + '. Colores sujetos a disponibilidad.';
        input.dispatchEvent(new win.Event('input', { bubbles: true }));
        sync();
      });
    });
    picker.addEventListener('input', sync);
    picker.addEventListener('change', sync);
    // Capture precedes Woo's delegated click and blocks rapid/keyboard repeats.
    picker.addEventListener('click', function (event) {
      if (!event.target.closest('.add_to_cart_button')) { return; }
      sync();
      if (add.getAttribute('aria-disabled') === 'true') {
        event.preventDefault();
        event.stopImmediatePropagation();
        if (!blocked() && !selected) {
          status.textContent = 'Elige un color antes de agregar.';
          var available = swatches.find(function (button) { return !button.disabled; });
          if (available) { available.focus(); }
        } else if (!blocked()) { input.reportValidity(); input.focus(); }
      }
    }, true);
    jq(win.document.body).on('adding_to_cart', function (event, button) {
      if (!button || button[0] !== add) { return; }
      busy(true);
      watchdog = win.setTimeout(uncertain, 20000);
      status.textContent = 'Agregando ' + selected.label + '…';
    }).on('added_to_cart', function (event, fragments, hash, button) {
      if (!button || button[0] !== add) { return; }
      var template = win.document.createElement('template');
      template.innerHTML = fragments && typeof fragments['div.fpw-card-selections'] === 'string' ? fragments['div.fpw-card-selections'] : '';
      var snapshot = template.content.querySelector('[data-fpw-cart-state="1"]');
      var line = snapshot && snapshot.querySelector('[data-product-id="' + selected.id + '"]');
      var units = line && line.getAttribute('data-quantity');
      if (!units || !/^\d+$/.test(units) || !Number.isSafeInteger(Number(units)) || Number(units) < 1) { uncertain(); return; }
      win.clearTimeout(watchdog);
      picker.removeAttribute('data-fpw-uncertain');
      busy(false);
      status.textContent = selected.label + ': ' + Number(units).toLocaleString('es-CL') + ' unidades en Productos a Cotizar.';
    });
    jq(win.document.body).on('ajax_request_not_sent.adding_to_cart', function (event, fragments, hash, button) {
      if (!button || button[0] !== add) { return; }
      win.clearTimeout(watchdog);
      busy(false);
      add.classList.remove('loading');
      status.textContent = 'El agregado no se envió. Revisa los datos o continúa desde la ficha.';
    });
    jq(win.document).on('ajaxError.fpwFeatured ajaxComplete.fpwFeatured', function (event, xhr, settings) {
      if (!picker.hasAttribute('data-fpw-pending') || !settings || !settings.url) { return; }
      var url = new win.URL(settings.url, win.location.href);
      var data = typeof settings.data === 'string' ? new win.URLSearchParams(settings.data) : null;
      var id = data ? data.get('product_id') : settings.data && settings.data.product_id;
      if (url.searchParams.get('wc-ajax') === 'add_to_cart' && String(id) === String(selected.id)) { uncertain(); }
    });
    sync();
  }
  function boot() {
    document.querySelectorAll('[data-fpw-featured-picker]').forEach(function (picker) { enhance(picker, window); });
  }
  if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); }
    else { boot(); }
  }
  if (typeof module === 'object' && module.exports) { module.exports = { enhance: enhance }; }
})();
