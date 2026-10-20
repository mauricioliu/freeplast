import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const source = readFileSync(new URL('../wp-content/themes/freeplast/assets/js/checkout-form.js', import.meta.url), 'utf8');
let checks = 0;
for (const width of [412, 1440]) {
  const dom = new JSDOM(`<!doctype html><form class="checkout"><p class="form-row" id="billing_fp_rut_field"><label for="billing_fp_rut">RUT empresa</label><input id="billing_fp_rut" name="billing_fp_rut" aria-describedby="rut-help"><span id="rut-help">Incluye el dígito verificador.</span></p></form>`, { runScripts: 'outside-only', pretendToBeVisual: true });
  const { window } = dom;
  const doc = window.document;
  window.matchMedia = () => ({ matches: width >= 1000, addEventListener() {} });
  window.eval(source);
  doc.dispatchEvent(new window.Event('DOMContentLoaded'));
  const api = window.eval(`(function(){var module = {exports:{}};${source};return module.exports;})()`);
  const input = doc.querySelector('input');
  const ok = (value, message) => { assert.ok(value, message); checks++; };
  for (const [raw, formatted] of [
    ['144424808', '14.442.480-8'], ['765916291', '76.591.629-1'],
    ['78117890k', '78.117.890-K'], ['10000013K', '10.000.013-K'],
    ['100000040', '10.000.004-0'], ['12345674', '1.234.567-4'],
  ]) {
    ok(api.validRut(raw) && api.validRut(` ${formatted} `), 'valid compact, dotted, lowercase K and spaced RUT');
    ok(api.formatRut(raw) === formatted, 'dots and verifier separator');
    input.value = raw;
    input.dispatchEvent(new window.Event('input', { bubbles: true }));
    ok(input.value === raw, 'editing never moves caret through formatting');
    input.dispatchEvent(new window.Event('blur'));
    await new Promise(resolve => window.setTimeout(resolve, 5));
    ok(input.value === formatted && !input.hasAttribute('aria-invalid'), 'blur formats and accepts correct verifier');
  }
  for (const raw of ['', '14.442.480-3', '00000000-0', '76591629', '1234567', '76.591.629-1 x2', 'RUT 765916291', '76123456KK', `765916291${' '.repeat(20)}2`, '1'.repeat(241)]) {
    ok(!api.validRut(raw), 'reject malformed, wrong verifier, zero and trailing garbage');
  }
  input.value = '144424803';
  input.dispatchEvent(new window.Event('blur'));
  await new Promise(resolve => window.setTimeout(resolve, 5));
  const note = doc.querySelector('[data-fp-rut-error]');
  ok(input.value === '14.442.480-3' && note?.textContent.includes('dígito verificador'), 'invalid RUT still formats and explains error');
  ok(input.getAttribute('aria-invalid') === 'true' && input.getAttribute('aria-describedby').includes(note.id), 'accessible error');
  input.dispatchEvent(new window.Event('blur'));
  await new Promise(resolve => window.setTimeout(resolve, 5));
  ok(doc.querySelectorAll('[data-fp-rut-error]').length === 1, 'repeat blur does not duplicate error');
  input.value = '144424808';
  input.dispatchEvent(new window.Event('input', { bubbles: true }));
  ok(!doc.querySelector('[data-fp-rut-error]') && !input.hasAttribute('aria-invalid'), 'typing clears stale error without blocking');
  ok(input.getAttribute('aria-describedby') === 'rut-help', 'existing helper association survives correction');
  input.value = '';
  input.dispatchEvent(new window.Event('blur'));
  await new Promise(resolve => window.setTimeout(resolve, 5));
  ok(!doc.querySelector('[data-fp-rut-error]'), 'empty-field validation stays with Woo');
  dom.window.close();
}
console.log(`checkout RUT: ${checks} checks passed (mobile + desktop, no jQuery needed)`);
