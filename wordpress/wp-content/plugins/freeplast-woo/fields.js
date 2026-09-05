/* Local field enhancement only; WooCommerce owns checkout submission and errors. */
(function ($) {
  'use strict';
  function dispatchState() {
    var select = document.getElementById('billing_fp_dispatch');
    var field = document.getElementById('billing_fp_address');
    var wrapper = document.getElementById('billing_fp_address_field');
    if (!select || !field || !wrapper) return;
    var needed = select.value === 'si';
    wrapper.hidden = !needed;
    field.required = needed;
    field.setAttribute('aria-required', String(needed));
    wrapper.classList.toggle('validate-required', needed);
    var optional = wrapper.querySelector('.optional');
    if (optional) optional.hidden = needed;
    var marker = wrapper.querySelector('[data-fpw-required]');
    if (!marker) {
      marker = document.createElement('abbr');
      marker.className = 'required'; marker.title = 'obligatorio'; marker.textContent = ' *';
      marker.setAttribute('data-fpw-required', '');
      wrapper.querySelector('label').appendChild(marker);
    }
    marker.hidden = !needed;
  }
  $(dispatchState);
  $(document.body).on('updated_checkout', dispatchState);
  $(document).on('change', '#billing_fp_dispatch', dispatchState);
  $(document).on('change', 'form.checkout [name^="billing_"], form.checkout #order_comments', function () { $(document.body).trigger('update_checkout'); });
})(jQuery);
