/* A named colors project the ONE native Woo select. Woo owns matching,
   option availability, variation ids, reset and add validation. */
(function () {
  'use strict';
  var COLOR_CLASS = { blanco: '', rojo: 'red', amarillo: 'yellow', azul: 'blue', verde: 'green' };
  function enhance(form) {
    var doc = form.ownerDocument;
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
      window.jQuery(select).on('change', sync);
      window.jQuery(form).on('woocommerce_update_variation_values reset_data found_variation hide_variation', sync);
    }
    sync();
  }
  function init() { document.querySelectorAll('form.variations_form').forEach(enhance); }
  if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
    else { init(); }
  }
  if (typeof module === 'object' && module.exports) { module.exports = { enhance: enhance }; }
})();
