import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';
import jquery from 'jquery';

// Actual pinned Woo checkout + custom-button dependency, real jQuery AJAX
// lifecycle, intercepted transport. No HTTP, browser, storage or mail execution.
const load = path => readFileSync(new URL(path, import.meta.url), 'utf8');
const woo = '../.build/wp/wp-content/plugins/woocommerce/assets/js/frontend/';
const native = load(woo + 'checkout.js');
const customButton = load(woo + 'utils/custom-place-order-button.js');
const source = load('../wp-content/themes/freeplast/assets/js/checkout-form.js');
const fields = load('../wp-content/plugins/freeplast-woo/fields.js');
// Full A template + pinned native PHP field renderer; only gateway/attempt
// fixture sentinels are replaced with owned, inert test controls.
const nativeForm = execFileSync(fileURLToPath(new URL('../.tools/php/php', import.meta.url)), [fileURLToPath(new URL('./checkout-form-test.php', import.meta.url))], { encoding: 'utf8', env: { ...process.env, FREEPLAST_TEST_FORM_HTML: '1' } })
  .replace('HIDDEN-ATTEMPT-INPUT', '<input type="hidden" name="fpw_attempt" value="owned-original-attempt">')
  .replace('PAYMENT-BLOCK[#place_order]', '<div class="woocommerce-checkout-payment"><input type="radio" name="payment_method" id="payment_method_quotes-gateway" value="quotes-gateway" checked><button id="place_order" type="submit" name="woocommerce_checkout_place_order" value="Solicitar cotización">Solicitar cotización</button></div>');
const wait = () => new Promise(resolve => setTimeout(resolve, 30));
async function environment(enhancement) {
  const dom = new JSDOM(`<!doctype html><body class="woocommerce-checkout">${nativeForm}</body>`, { url: 'https://example.test/checkout/', runScripts: 'outside-only' });
  dom.window.document.querySelector('.fp-edit-products').href = '#draft-saved';
  const w = dom.window, $ = jquery(w);
  w.$ = w.jQuery = $; w.matchMedia = () => ({ matches: false, addEventListener() {} });
  $.blockUI = { defaults: { overlayCSS: {} } };
  $.fn.block = $.fn.unblock = function () { return this; };
  $.scroll_to_notices = () => {}; $.fn.animate = function () { return this; };
  w.eval(customButton);
  w.wc_checkout_params = { is_checkout: '0', option_guest_checkout: 'yes', wc_ajax_url: '/?wc-ajax=%%endpoint%%', checkout_url: '/?wc-ajax=checkout', update_order_review_nonce: 'owned-fixture', i18n_checkout_error: 'Error processing checkout. Please try again.' };
  const requests = [];
  $.ajaxTransport('+*', options => ({ send(headers, complete) { requests.push({ options, complete }); }, abort() {} }));
  w.eval(native); w.eval(fields); w.eval(enhancement); await wait();
  const form = w.document.querySelector('form.checkout');
  return { dom, w, $, requests, form, submit: () => form.dispatchEvent(new w.Event('submit', { bubbles: true, cancelable: true })) };
}
const success = request => request.complete(200, 'OK', { text: JSON.stringify({ result: 'success', fragments: {} }) }, 'Content-Type: application/json');
const checkout = env => env.requests.find(r => r.options.url.endsWith('=checkout'));

export async function runNativeCheckoutTests(enhancement = source) {
  let checks = 0;
  const ok = (condition, label) => { checks++; assert.ok(condition, label); };
  const instances = [];
  const env = async () => { const value = await environment(enhancement); instances.push(value); return value; };
  try {
    {
      const e = await env(); e.submit(); await wait(); const request = checkout(e);
      ok(request, 'REAL native checkout reaches the intercepted transport');
      ok(request.options.data.includes('fpw_attempt=owned-original-attempt'), 'native AJAX preserves submitted attempt');
      request.complete(200, 'OK', { text: JSON.stringify({ result: 'failure', messages: '<ul class="woocommerce-error" role="alert"><li data-id="billing_first_name"><strong>Nombre</strong> es obligatorio.</li><li data-id="billing_fp_dispatch">Selecciona si necesitas despacho.</li><li data-id="billing_fp_address">Indica la dirección.</li></ul>' }) }, 'Content-Type: application/json');
      await wait();
      const summary = e.form.querySelector('.fp-error-summary');
      ok(summary, 'actual native checkout_error produces the in-form summary');
      ok(summary.textContent.includes('Nombre es obligatorio.'), 'error cause survives strong-label markup');
      const link = summary.querySelector('a[href="#billing_fp_dispatch_si"]');
      ok(link, 'dispatch error links to a real radio'); link.click();
      ok(e.w.document.activeElement.id === 'billing_fp_dispatch_si', 'actual dispatch summary link focuses its real control');
      ok(e.form.querySelectorAll('#billing_first_name_field .checkout-inline-error-message').length === 1, 'native inline-message timing does not duplicate field errors');
      const no = e.form.querySelector('#billing_fp_dispatch_no'); no.focus(); no.click();
      ok(e.form.querySelector('.fp-error-summary').querySelectorAll('li').length === 1 && !e.form.querySelector('.fp-error-summary a[href="#billing_fp_address"]'), 'choosing no dispatch clears now-irrelevant errors but preserves the unrelated native cause');
      ok(e.w.document.activeElement === no && !no.closest('.form-row').classList.contains('woocommerce-invalid'), 'correcting native radio error does not steal focus or leave stale invalid styling');
    }
    {
      const e = await env(); e.submit(); await wait(); checkout(e).complete(0, 'error', {}, ''); await wait();
      const summary = e.form.querySelector('.fp-error-summary');
      ok(summary?.textContent.toLowerCase().includes('puede que ya se haya guardado'), 'native DIV transport error remains explicitly uncertain');
      ok(!summary?.textContent.includes('No pudimos enviar'), 'uncertain outcome never receives a definite failed-send heading');
      ok(e.form.querySelector('[name="fpw_attempt"]').value === 'owned-original-attempt', 'transport failure does not rotate the attempt');
    }
    {
      const e = await env(); e.submit(); await wait();
      ok(e.form.querySelector('#place_order').getAttribute('aria-busy') === 'true', 'actual pending POST exposes busy');
      // A native review refresh can finish/replace payment while checkout POST runs.
      e.form.querySelector('#place_order').outerHTML = '<button id="place_order" type="submit">Solicitar cotización</button>';
      e.$(e.w.document.body).trigger('updated_checkout');
      ok(e.form.querySelector('#place_order').disabled && e.form.querySelector('#place_order').getAttribute('aria-busy') === 'true', 'unrelated review/replacement cannot release pending checkout');
      e.submit(); await wait();
      ok(e.requests.filter(r => r.options.url.endsWith('=checkout')).length === 1, 'pinned native double-submit guard remains authoritative');
      checkout(e).complete(0, 'error', {}, ''); await wait();
      ok(!e.form.querySelector('#place_order').disabled, 'settled native rejection releases the current replacement button');
    }
    {
      const e = await env();
      const radios = [...e.form.querySelectorAll('input[name="billing_fp_dispatch"]')];
      ok(radios.length === 2 && radios.every(input => input.parentElement.matches('label.radio')), 'actual PHP radio siblings become A tiles without replacing inputs');
      ok(e.form.querySelector('[role="radiogroup"]')?.getAttribute('aria-labelledby') === 'fp-dispatch-label', 'native choices have a named required group');
      ok(new URLSearchParams(e.$(e.form).serialize()).getAll('billing_fp_dispatch').length === 1, 'one native selected dispatch value is serialized');
      e.form.querySelector('#billing_fp_address').value = 'Dirección de prueba conservada';
      e.form.querySelector('#billing_fp_dispatch_no').click();
      ok(e.form.querySelector('.fp-address-slot').hidden && !e.form.querySelector('#billing_fp_address').required, 'no-dispatch removes the whole address layout slot and requirement');
      ok(e.form.querySelector('#billing_fp_address').value === 'Dirección de prueba conservada', 'local address draft is retained; server decides not to persist a destination');
      await wait(); for (const request of e.requests) success(request); await wait();
    }
    {
      const e = await env(); e.$(e.form).on('checkout_place_order', () => false); e.submit(); await wait();
      ok(!checkout(e) && !e.form.querySelector('#place_order').hasAttribute('aria-busy'), 'native gateway veto does not invent an in-flight POST');
    }
    {
      const e = await env(), name = e.form.querySelector('#billing_first_name');
      name.value = 'Último dato'; e.form.querySelector('#order_comments').value = 'Mensaje conservado';
      e.$(name).trigger('change'); e.form.querySelector('.fp-edit-products').click();
      ok(e.w.location.hash === '', 'immediate Editar cannot outrun native draft debounce');
      await wait();
      const request = e.requests.find(r => r.options.url.endsWith('=update_order_review'));
      ok(request, 'draft handoff uses the native review request');
      const values = new URLSearchParams(new URLSearchParams(request.options.data).get('post_data'));
      ok(values.get('billing_first_name') === 'Último dato' && values.get('order_comments') === 'Mensaje conservado' && values.get('fpw_attempt') === 'owned-original-attempt', 'native serialization carries latest fields, message and original attempt');
      success(request); await wait();
      ok(e.w.location.hash === '#draft-saved', 'navigation intent occurs only after native successful draft response (not a real page load)');
    }
    {
      const e = await env(); e.form.querySelector('.fp-edit-products').click(); await wait();
      const first = e.requests.at(-1);
      const name = e.form.querySelector('#billing_first_name'); name.value = 'Más reciente'; e.$(name).trigger('change'); await wait();
      const second = e.requests.at(-1);
      ok(second !== first && e.w.location.hash === '', 'replacement review/abort cannot prematurely navigate');
      success(second); await wait();
      ok(e.w.location.hash === '#draft-saved', 'latest review replaces aborted draft handoff instead of hanging');
    }
    {
      const e = await env(); e.form.querySelector('.fp-edit-products').click(); await wait();
      e.requests.at(-1).complete(0, 'error', {}, ''); await wait();
      ok(e.w.location.hash === '' && e.form.querySelector('[data-fp-draft-error]'), 'draft transport failure stays on form with a retry explanation');
    }
    console.log(`native checkout: ${checks} pinned Woo/jQuery lifecycle checks passed (intercepted transport; no HTTP/storage evidence)`);
    return checks;
  } finally {
    await wait();
    for (const e of instances) { e.dom.window.close(); }
  }
}
