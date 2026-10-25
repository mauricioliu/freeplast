/* A named colors project the ONE native Woo select. Woo owns matching,
   option availability, variation ids, reset and add validation. */
(function () {
  'use strict';
  var COLOR_CLASS = { blanco: '', rojo: 'red', amarillo: 'yellow', azul: 'blue', verde: 'green' };
  var VARIATION_TEMPLATES = { 'variation-template': true, 'unavailable-variation-template': true };
  var ESCAPE_HTML = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;', '`': '&#x60;' };
  function escapeHtml(value) {
    return String(value).replace(/[&<>"'`]/g, function (ch) { return ESCAPE_HTML[ch]; });
  }
  /* Woo's wp_template regex backtracks on data.variation.<key> and falls back to
     wp.template() -> _.template() -> new Function(), which throws EvalError under
     strict CSP without unsafe-eval before variation_id and show_variation run. */
  function installVariationTemplateAdapter(win) {
    if (!win || !win.wp || typeof win.wp.template !== 'function' || win.wp.template.fpwVariationCspSafe) { return; }
    var baseTemplate = win.wp.template;
    var adapted = function (id) {
      if (!VARIATION_TEMPLATES[id]) { return baseTemplate.apply(this, arguments); }
      return function (data) {
        var doc = win.document;
        var el = doc && doc.getElementById('tmpl-' + id);
        if (!el) { throw new Error('Template not found: #tmpl-' + id); }
        var html = el.textContent || el.innerHTML || '';
        var variation = (data && data.variation) || {};
        return html.replace(/(\{\{\{?)\s*data\.variation\.([\w-]+)\s*(\}\}\}?)/g, function (_, open, key, close) {
          if (open.length !== close.length) { return ''; }
          var replacement = variation[key] || '';
          return open.length === 2 ? escapeHtml(replacement) : String(replacement);
        });
      };
    };
    adapted.fpwVariationCspSafe = true;
    win.wp.template = adapted;
  }
  function enhance(form) {
    var doc = form.ownerDocument;
    installVariationTemplateAdapter(doc.defaultView);
    var select = Array.from(form.querySelectorAll('select')).find(function (node) {
      return /(^|_)color$/i.test(node.name.replace(/^attribute_/, ''));
    });
    if (!select || form.querySelector('.fp-color-fieldset')) { return; }
    var options = Array.from(select.options).filter(function (option) { return option.value; });
    if (!options.length) { return; }
    var fieldset = doc.createElement('fieldset');
    fieldset.className = 'fp-color-fieldset';
    var legend = doc.createElement('legend');
    legend.textContent = 'Elige un color · obligatorio';
    fieldset.appendChild(legend);
    var wrap = doc.createElement('div');
    wrap.className = 'colors';
    wrap.setAttribute('role', 'group');
    wrap.setAttribute('aria-label', 'Color');
    fieldset.appendChild(wrap);
    var buttons = options.map(function (option) {
      var button = doc.createElement('button');
      button.type = 'button';
      button.className = 'color-option';
      button.setAttribute('data-fp-color', option.value);
      var dot = doc.createElement('span');
      var color = COLOR_CLASS[option.textContent.trim().toLowerCase()];
      dot.className = 'color-dot' + (color ? ' ' + color : '');
      dot.setAttribute('aria-hidden', 'true');
      button.appendChild(dot);
      button.appendChild(doc.createTextNode(option.textContent));
      wrap.appendChild(button);
      return button;
    });
    var status = doc.createElement('p');
    status.className = 'fp-fine fp-color-status';
    fieldset.appendChild(status);
    // Native variable.php uses TABLE > TR > TD, not a .form-row. Never
    // insert a fieldset between table cells or leave a second visible picker.
    var row = select.closest('tr, .form-row') || select;
    var anchor = row.closest('table') || row;
    anchor.parentNode.insertBefore(fieldset, anchor);
    var reset = row.querySelector && row.querySelector('.reset_variations');
    if (reset) { fieldset.appendChild(reset); }
    row.hidden = true;
    row.setAttribute('data-fp-color-superseded', 'true');
    function sync() {
      var current = Array.from(select.options);
      buttons.forEach(function (button) {
        var value = button.getAttribute('data-fp-color');
        var option = current.find(function (item) { return item.value === value; });
        button.disabled = select.disabled || !option || option.disabled;
        var chosen = select.value === value && !button.disabled;
        button.classList.toggle('selected', chosen);
        button.setAttribute('aria-pressed', chosen ? 'true' : 'false');
      });
      var selected = select.selectedOptions[0];
      status.textContent = 'Colores sujetos a disponibilidad. ' + (selected && selected.value && !selected.disabled
        ? 'Seleccionado: ' + selected.textContent + '.'
        : 'Selecciona un color para poder agregar.');
    }
    wrap.addEventListener('click', function (event) {
      var button = event.target.closest('[data-fp-color]');
      if (!button || button.disabled) { return; }
      select.value = button.getAttribute('data-fp-color');
      if (window.jQuery) { window.jQuery(select).trigger('change'); }
      else { select.dispatchEvent(new doc.defaultView.Event('change', { bubbles: true })); }
      sync();
    });
    select.addEventListener('change', sync);
    if (window.jQuery) {
      var product = form.closest('.product');
      var gallery = product && product.querySelector('.fp-detail-image');
      var caption = product && product.querySelector('.image-caption');
      var originalCaption = caption && caption.textContent;
      var missing = doc.createElement('div');
      missing.className = 'fp-detail-image';
      missing.hidden = true;
      if (gallery) { gallery.after(missing); }
      function resetPhoto() {
        if (gallery) { gallery.hidden = false; }
        missing.hidden = true;
        missing.replaceChildren();
        if (caption) { caption.textContent = originalCaption; }
      }
      window.jQuery(form).on('found_variation', function (event, variation) {
        resetPhoto();
        if (!gallery) { return; }
        var image = variation.fpw_color_image;
        var label = select.selectedOptions[0] && select.selectedOptions[0].textContent;
        if (!image || !image.src) {
          gallery.hidden = true;
          missing.hidden = false;
          var notice = doc.createElement('div');
          notice.className = 'missing-detail';
          notice.textContent = 'Fotografía no disponible en ' + label + '.';
          missing.appendChild(notice);
          if (caption) { caption.textContent = 'Consulta la fotografía de este color con ventas.'; }
        } else {
          if (!gallery.querySelector('.woocommerce-product-gallery')) {
            gallery.hidden = true;
            missing.hidden = false;
            var img = doc.createElement('img');
            img.src = image.src;
            img.alt = image.alt || 'Producto en ' + label;
            missing.appendChild(img);
          }
          if (caption) { caption.textContent = 'Fotografía referencial en ' + label + '.'; }
        }
      }).on('reset_data hide_variation', resetPhoto);
      window.jQuery(select).on('change', function () { if (!select.value) { resetPhoto(); } });
      window.jQuery(select).on('change', sync);
      window.jQuery(form).on('woocommerce_update_variation_values reset_data found_variation hide_variation', sync);
    }
    sync();
  }
  function init() {
    if (typeof window !== 'undefined') { installVariationTemplateAdapter(window); }
    document.querySelectorAll('form.variations_form').forEach(enhance);
  }
  if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
    else { init(); }
  }
  if (typeof module === 'object' && module.exports) { module.exports = { enhance: enhance }; }
})();
