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
    var select = picker.querySelector('[data-fpw-color-select]');
    var swatches = Array.from(picker.querySelectorAll('[data-fpw-variant]'));
    if (!input || !add || !status || !select || !swatches.length) { return; }
    var options;
    try { options = swatches.map(function (button) { return JSON.parse(button.dataset.fpwVariant); }); }
    catch (error) { return; } // Keep the visible product-page fallback.
    picker.dataset.fpwReady = '1';
    picker.hidden = false;
    wrap.classList.add('fp-featured-enhanced');
    var fallback = wrap.querySelector('a.button.secondary');
    if (fallback) { fallback.hidden = true; } // Photo and title still link to the sheet.
    var card = wrap.closest('li');
    var photo = card && card.querySelector('.product-photo');
    var originalPhoto = photo && photo.innerHTML;
    function showPhoto(option) {
      if (!photo) { return; }
      photo.innerHTML = originalPhoto;
      if (!option) { return; }
      var image = option.image;
      if (!image || !image.src) {
        photo.textContent = 'Fotografía no disponible en ' + option.label + '.';
        return;
      }
      var img = photo.querySelector('img') || win.document.createElement('img');
      photo.replaceChildren(img);
      ['src', 'srcset', 'sizes', 'width', 'height'].forEach(function (name) {
        if (image[name]) { img.setAttribute(name, image[name]); }
        else { img.removeAttribute(name); }
      });
      img.alt = image.alt || 'Producto en ' + option.label;
      img.addEventListener('error', function () {
        if (photo.contains(img)) { photo.textContent = 'Fotografía no disponible en ' + option.label + '.'; }
      }, { once: true });
    }
    var info = card && card.querySelector('.product-info');
    if (info) {
      var row = win.document.createElement('div');
      row.className = 'fp-featured-facts';
      var spec = info.querySelector('.product-spec');
      if (spec) { row.appendChild(spec); }
      var menu = win.document.createElement('details');
      menu.className = 'fp-featured-menu';
      var summary = win.document.createElement('summary');
      summary.innerHTML = 'Color <span class="fp-featured-choice">Elegir</span><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>';
      menu.appendChild(summary);
      var list = win.document.createElement('div');
      list.className = 'fp-featured-options';
      list.setAttribute('role', 'group');
      list.setAttribute('aria-label', 'Color obligatorio · sujeto a disponibilidad');
      var colors = { blanco: '#ffffff', negro: '#17181c', rojo: '#ae3636', amarillo: '#e2c23b', azul: '#31558c', verde: '#306020' };
      options.forEach(function (option, index) {
        var button = win.document.createElement('button');
        button.type = 'button';
        button.disabled = !option.id;
        button.setAttribute('aria-pressed', 'false');
        var dot = win.document.createElement('span');
        dot.className = 'color-dot';
        dot.setAttribute('aria-hidden', 'true');
        dot.style.backgroundColor = colors[option.label.toLowerCase()] || '#f4f5f7';
        button.appendChild(dot);
        button.appendChild(win.document.createTextNode(option.label + (!option.id ? ' (no disponible)' : '')));
        button.addEventListener('click', function () {
          if (blocked()) { return; }
          select.selectedIndex = index + 1;
          select.dispatchEvent(new win.Event('change', { bubbles: true }));
          list.querySelectorAll('button').forEach(function (item) { item.setAttribute('aria-pressed', item === button ? 'true' : 'false'); });
          var choice = summary.querySelector('.fp-featured-choice');
          choice.textContent = '';
          choice.appendChild(dot.cloneNode(true));
          choice.appendChild(win.document.createTextNode(option.label));
          menu.open = false;
          summary.focus();
        });
        list.appendChild(button);
      });
      menu.appendChild(list);
      menu.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') { menu.open = false; summary.focus(); }
      });
      win.document.addEventListener('click', function (event) {
        if (!menu.contains(event.target)) { menu.open = false; }
      });
      select.closest('.fp-featured-colors').hidden = true;
      row.appendChild(menu);
      info.appendChild(row);
      picker.fpwColorMenu = menu;
    }
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
      select.disabled = locked;
      if (picker.fpwColorMenu) {
        picker.fpwColorMenu.querySelectorAll('button').forEach(function (button, i) { button.disabled = locked || !options[i].id; });
        if (locked) { picker.fpwColorMenu.open = false; }
      }
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
    select.addEventListener('change', function () {
        if (blocked()) { return; }
        var index = swatches.indexOf(select.selectedOptions[0]);
        selected = index >= 0 && !swatches[index].disabled && options[index].id ? options[index] : null;
        showPhoto(selected);
        if (picker.fpwColorMenu) {
          picker.fpwColorMenu.querySelector('.fp-featured-choice').textContent = selected ? selected.label : 'Elegir';
          picker.fpwColorMenu.querySelectorAll('button').forEach(function (button, i) {
            button.setAttribute('aria-pressed', options[i] === selected ? 'true' : 'false');
          });
        }
        if (!selected) {
          add.dataset.product_id = '0';
          jq(add).data('product_id', 0);
          status.textContent = 'Colores sujetos a disponibilidad.';
          status.classList.remove('is-visible');
          sync();
          return;
        }
        add.dataset.product_id = String(selected.id);
        jq(add).data('product_id', selected.id); // Also support native jQuery dataset readers.
        input.max = selected.max === '' ? '' : String(selected.max);
        input.readOnly = selected.individual;
        if (selected.individual) { input.value = '1'; }
        picker.querySelectorAll('.fp-qty-step').forEach(function (step) { step.disabled = selected.individual; });
        status.textContent = 'Colores sujetos a disponibilidad.';
        status.classList.remove('is-visible');
        input.dispatchEvent(new win.Event('input', { bubbles: true }));
        sync();
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
          status.classList.add('is-visible');
          if (picker.fpwColorMenu) { picker.fpwColorMenu.open = true; picker.fpwColorMenu.querySelector('summary').focus(); }
          else { select.focus(); }
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
      status.classList.add('is-visible');
    });
    jq(win.document).on('ajaxError.fpwFeatured ajaxComplete.fpwFeatured', function (event, xhr, settings) {
      if (!picker.hasAttribute('data-fpw-pending') || !settings || !settings.url) { return; }
      var url = new win.URL(settings.url, win.location.href);
      var data = typeof settings.data === 'string' ? new win.URLSearchParams(settings.data) : null;
      var id = data ? data.get('product_id') : settings.data && settings.data.product_id;
      if (url.searchParams.get('wc-ajax') === 'add_to_cart' && String(id) === String(selected.id)) { uncertain(); }
    });
    if (select.value) { select.dispatchEvent(new win.Event('change', { bubbles: true })); }
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
