/* Native checkout fields only. No values, validation rules or persistence are
   replaced: moving each existing radio into its own native label gives A's
   tile geometry while preserving names/ids/checked state and plain POST. */
(function ($) {
  'use strict';
  function dispatchValue() {
    var checked = document.querySelector('input[name="billing_fp_dispatch"]:checked');
    return checked ? checked.value : '';
  }
  function enhanceDispatch() {
    var row = document.getElementById('billing_fp_dispatch_field');
    var group = row && row.querySelector('.woocommerce-input-wrapper');
    if (!group) return;
    group.querySelectorAll('input[type="radio"]').forEach(function (input) {
      var label = Array.prototype.find.call(input.labels || [], function (node) { return node.classList.contains('radio'); });
      if (label && !label.contains(input)) { label.insertBefore(input, label.firstChild); }
    });
    var heading = Array.prototype.find.call(row.children, function (node) { return node.tagName === 'LABEL'; });
    if (heading) {
      heading.id = 'fp-dispatch-label'; heading.removeAttribute('for');
      group.setAttribute('role', 'radiogroup'); group.setAttribute('aria-labelledby', heading.id);
      group.setAttribute('aria-required', 'true');
    }
    group.setAttribute('data-fp-dispatch-enhanced', '');
  }
  function dispatchState() {
    enhanceDispatch();
    var field = document.getElementById('billing_fp_address');
    var wrapper = document.getElementById('billing_fp_address_field');
    if (!field || !wrapper) return;
    var needed = dispatchValue() === 'si';
    wrapper.hidden = !needed; field.required = needed;
    var slot = wrapper.closest('.fp-address-slot'); if (slot) slot.hidden = !needed;
    field.setAttribute('aria-required', String(needed)); wrapper.classList.toggle('validate-required', needed);
    var optional = wrapper.querySelector('.optional'); if (optional) optional.hidden = needed;
    var marker = wrapper.querySelector('[data-fpw-required]');
    if (!marker && wrapper.querySelector('label')) {
      marker = document.createElement('abbr'); marker.className = 'required'; marker.title = 'obligatorio'; marker.textContent = ' *';
      marker.setAttribute('data-fpw-required', ''); wrapper.querySelector('label').appendChild(marker);
    }
    if (marker) marker.hidden = !needed;
  }
  $(dispatchState);
  $(document.body).on('updated_checkout', dispatchState);
  $(document).on('change', '[name="billing_fp_dispatch"]', function () {
    dispatchState();
    var row = document.getElementById('billing_fp_dispatch_field');
    if (!row || !['si', 'no'].includes(dispatchValue())) return;
    row.querySelectorAll('[data-fp-field-error]').forEach(function (node) { node.remove(); });
    row.querySelectorAll('input[type="radio"]').forEach(function (input) {
      input.removeAttribute('aria-invalid');
      var ids = (input.getAttribute('aria-describedby') || '').split(/\s+/).filter(function (id) { return id && id.indexOf('fp-field-error-') !== 0; });
      if (ids.length) input.setAttribute('aria-describedby', ids.join(' ')); else input.removeAttribute('aria-describedby');
    });
  });
  $(document).on('change', 'form.checkout [name^="billing_"], form.checkout #order_comments', function () { $(document.body).trigger('update_checkout'); });
})(jQuery);
