import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

/* Issue #46/#47 — behavioral checks for the A · Directa checkout-form
 * enhancement: linked focused error summary built FROM Woo's native error
 * group (values kept, real inputs described), honest wording for ambiguous
 * transport failures, busy submit semantics and double-activation guard.
 * WooCommerce's validation/submission stays authoritative; this harness only
 * exercises the enhancement over Woo-shaped markup. */
const source = readFileSync(new URL('../wp-content/themes/freeplast/assets/js/checkout-form.js', import.meta.url), 'utf8');

const FORM = `
<form class="checkout woocommerce-checkout">
  <aside class="fp-checkout-summary"><details class="fp-summary-details" data-fp-summary-details><summary><span class="fp-summary-count">2 productos · 95 unidades</span><svg></svg></summary><div class="fp-summary-body"></div></details></aside>
  <div class="fp-checkout-form">
    <p class="form-row" id="billing_first_name_field"><label for="billing_first_name">Nombre</label><input class="input-text" id="billing_first_name" name="billing_first_name" value="Cliente"></p>
    <p class="form-row" id="billing_email_field"><label for="billing_email">Email</label><input class="input-text" id="billing_email" name="billing_email" value="cliente@example.invalid"></p>
    <div class="fp-form-submit"><div id="payment"><button type="submit" id="place_order" name="woocommerce_checkout_place_order" value="Solicitar cotización" data-value="Solicitar cotización">Solicitar cotización</button></div></div>
  </div>
</form>`;

function boot(width) {
  const dom = new JSDOM(`<!doctype html><body>${FORM}</body>`, { url: 'https://example.test/datos-y-envio/', runScripts: 'outside-only', pretendToBeVisual: true });
  dom.window.matchMedia = (query) => ({ matches: query.includes('1000px') && width >= 1000, media: query, addEventListener() {}, removeEventListener() {} });
  dom.window.eval(source);
  dom.window.document.dispatchEvent(new dom.window.Event('DOMContentLoaded', { bubbles: true }));
  return dom;
}

export async function runCheckoutFormTests() {
  let checks = 0;
  const ok = (cond, message) => { checks++; assert.ok(cond, message); };

  /* Summary disclosure: closed on mobile, open on desktop widths. */
  const mobile = boot(412);
  ok(mobile.window.document.querySelector('[data-fp-summary-details]').open === false, 'the summary starts collapsed on mobile');
  const desktop = boot(1440);
  ok(desktop.window.document.querySelector('[data-fp-summary-details]').open === true, 'the summary starts open beside the form on desktop');
  const desktopSummary = desktop.window.document.querySelector('[data-fp-summary-details] summary');
  const click = new desktop.window.MouseEvent('click', { bubbles: true, cancelable: true });
  desktopSummary.dispatchEvent(click);
  ok(click.defaultPrevented && desktop.window.document.querySelector('[data-fp-summary-details]').open === true, 'the desktop disclosure cannot be collapsed');

  /* Field errors: linked focused summary from Woo's native group. */
  const dom = boot(412);
  const doc = dom.window.document;
  const form = doc.querySelector('form.checkout');
  const group = doc.createElement('div');
  group.className = 'woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout woocommerce-error';
  group.innerHTML = '<ul class="woocommerce-error" role="alert">'
    + '<li data-id="billing_first_name"><strong>Nombre</strong> es un campo obligatorio.</li>'
    + '<li data-id="billing_email"><strong>Email</strong> es un campo obligatorio.</li>'
    + '<li>Elige un método de pago.</li></ul>';
  form.prepend(group);
  doc.body.focus = doc.body.focus.bind(doc.body); /* jsdom focus bookkeeping */
  const api = dom.window.eval('(function(){ var module = { exports: {} };\n' + source + '\nreturn module.exports; })()');
  api.enhanceErrors(group);

  const summary = form.querySelector('.fp-error-summary');
  ok(Boolean(summary), 'the linked summary is built from the native error group');
  ok(summary.querySelector('h2').textContent === 'Revisa 2 campos para continuar.', 'the heading counts the addressable field errors');
  const links = [...summary.querySelectorAll('a')];
  ok(links.length === 2 && links[0].getAttribute('href') === '#billing_first_name' && links[1].getAttribute('href') === '#billing_email', 'each addressable error links its real control');
  ok(summary.textContent.includes('Elige un método de pago'), 'unaddressed errors remain in the summary');
  const nameInput = doc.getElementById('billing_first_name');
  ok(nameInput.getAttribute('aria-invalid') === 'true', 'the invalid input is marked');
  ok((nameInput.getAttribute('aria-describedby') || '').includes('fp-field-error-'), 'the field message is associated');
  ok(form.querySelector('[data-fp-field-error]').textContent === 'Nombre es un campo obligatorio.', 'the per-field message names the problem');
  ok(doc.getElementById('billing_first_name').value === 'Cliente', 'submitted values are preserved');
  ok(form.classList.contains('fp-errors-enhanced'), 'the native group is superseded only while the summary exists');
  ok(doc.activeElement === summary, 'the summary takes focus once, for correction');

  /* Re-running (a second failed submit) rebuilds without duplication. */
  api.enhanceErrors(group);
  ok(form.querySelectorAll('.fp-error-summary').length === 1 && form.querySelectorAll('[data-fp-field-error]').length === 2, 're-enhancement never duplicates summaries or messages');

  /* Ambiguous transport failure: honest wording, no fake definite failure. */
  const lost = boot(412);
  const lostDoc = lost.window.document;
  const lostApi = lost.window.eval('(function(){ var module = { exports: {} };\n' + source + '\nreturn module.exports; })()');
  const lostGroup = lostDoc.createElement('div');
  lostGroup.className = 'woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout woocommerce-error';
  lostGroup.innerHTML = '<ul class="woocommerce-error" role="alert"><li>No hemos podido procesar tu pedido. Por favor, inténtalo de nuevo.</li></ul>';
  lostDoc.querySelector('form.checkout').prepend(lostGroup);
  lostApi.enhanceErrors(lostGroup);
  const lostSummary = lostDoc.querySelector('.fp-error-summary');
  ok(lostSummary.textContent.toLowerCase().includes('puede que ya se haya guardado') && lostSummary.textContent.includes('se conserva el mismo intento'), 'an ambiguous outcome is reworded honestly (the attempt may already be saved)');
  ok(lostSummary.querySelector('h2').textContent === 'No pudimos confirmar tu solicitud.', 'an uncertain outcome never has a definite failed-send heading');
  ok(lostSummary.textContent.includes('No hemos podido procesar tu pedido'), 'native cause is retained rather than classified solely by a language regex');

  /* A definite server rejection without transport wording passes through. */
  const definite = boot(412);
  const definiteApi = definite.window.eval('(function(){ var module = { exports: {} };\n' + source + '\nreturn module.exports; })()');
  const definiteGroup = definite.window.document.createElement('div');
  definiteGroup.className = 'woocommerce-NoticeGroup woocommerce-error';
  definiteGroup.innerHTML = '<ul class="woocommerce-error"><li>La sesión de solicitud no es válida.</li></ul>';
  definite.window.document.querySelector('form.checkout').prepend(definiteGroup);
  definiteApi.enhanceErrors(definiteGroup);
  ok(definite.window.document.querySelector('.fp-error-summary').textContent.includes('La sesión de solicitud no es válida'), 'definite rejections keep their native wording');

  /* Submit busy state + double activation. */
  const sendDom = boot(412);
  const sendDoc = sendDom.window.document;
  const sendApi = sendDom.window.eval('(function(){ var module = { exports: {} };\n' + source + '\nreturn module.exports; })()');
  const button = sendDoc.getElementById('place_order');
  sendApi.markSending(sendDoc.querySelector('form.checkout'));
  ok(button.getAttribute('aria-busy') === 'true' && button.textContent === 'Enviando…', 'submitting exposes busy semantics and honest label');
  let submits = 0;
  sendDoc.querySelector('form').addEventListener('submit', event => { submits++; event.preventDefault(); });
  button.click();
  ok(button.disabled && submits === 0, 'native disabled button cannot activate a form submit (server duplicate protection is tested separately)');
  sendApi.restoreSending(sendDoc.querySelector('form.checkout'));
  ok(button.getAttribute('aria-busy') === null && button.textContent === 'Solicitar cotización', 'restoring returns the native label');

  const detail = lostDoc.querySelector('[data-fp-summary-details]');
  lostApi.syncSummary(); // initialize this exported helper's first disclosure observation
  detail.open = true;
  lostApi.syncSummary();
  ok(detail.open, 'a mobile review refresh preserves the user-open disclosure');
  detail.insertAdjacentHTML('beforeend', '<table data-fpw-review-count="3 productos · 125 unidades"></table>');
  lostApi.syncSummary();
  ok(lostDoc.querySelector('.fp-summary-count').textContent === '3 productos · 125 unidades', 'summary count consumes the refreshed native table projection');
  ok(form.querySelector('.fp-error-summary').closest('.fp-checkout-form'), 'error summary stays in the form column rather than shifting the desktop grid');
  form.querySelector('.fp-error-summary a').click();
  ok(doc.activeElement === nameInput, 'the actual summary link focuses the field');

  for (const instance of [mobile, desktop, dom, lost, definite, sendDom]) { instance.window.close(); }
  console.log(`checkout form: ${checks} A · Directa checkout enhancement checks passed (linked errors, honest ambiguity, busy submit)`);
  return checks;
}
