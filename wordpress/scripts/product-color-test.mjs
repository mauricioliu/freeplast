import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';
import jquery from 'jquery';

/* Issue #44 — behavioral checks for the A · Directa color controls over the
 * single Woo variation form. The enhancement must drive the native select
 * exclusively and sync back from Woo's own changes (jQuery-triggered, as its
 * variation form and Restablecer link do). Availability stays the form's. */
const source = readFileSync(new URL('../wp-content/themes/freeplast/assets/js/product-color-options.js', import.meta.url), 'utf8');

const variationForm = `
<form class="variations_form cart" data-product_id="21">
  <table class="variations">
    <tbody><tr>
      <td class="label"><label for="color">Color</label></td>
      <td class="value">
        <select id="color" name="attribute_color" data-attribute_name="attribute_color">
          <option value="">Elige una opción</option>
          <option value="Blanco">Blanco</option>
          <option value="Rojo">Rojo</option>
          <option value="Azul">Azul</option>
        </select><a href="#" class="reset_variations">Restablecer</a>
      </td>
    </tr></tbody>
  </table>
  <div class="woocommerce-variation-add-to-cart variations_button">
    <button type="submit" class="single_add_to_cart_button button alt disabled wc-variation-selection-needed" aria-disabled="true">Agregar a cotización</button>
    <input type="hidden" name="variation_id" class="variation_id" value="0">
  </div>
</form>`;

export async function runProductColorTests() {
  let checks = 0;
  const ok = (cond, message) => { checks++; assert.ok(cond, message); };

  const dom = new JSDOM(`<!doctype html><body>${variationForm}</body>`, { url: 'https://example.test/producto/caja-universal-cerrada-color/', runScripts: 'outside-only', pretendToBeVisual: true });
  const win = dom.window;
  const $ = jquery(win);
  win.jQuery = $;
  win.eval(source);
  win.document.dispatchEvent(new win.Event('DOMContentLoaded', { bubbles: true }));

  const doc = win.document;
  const select = doc.getElementById('color');
  const fieldset = doc.querySelector('.fp-color-fieldset');
  ok(Boolean(fieldset), 'the color fieldset renders from the native select');
  ok(fieldset.querySelector('legend').textContent.includes('Elige un color'), 'the legend names the required choice');
  const buttons = [...fieldset.querySelectorAll('[data-fp-color]')];
  ok(buttons.length === 3 && buttons.map((b) => b.dataset.fpColor).join(',') === 'Blanco,Rojo,Azul', 'one named button per native option, no invented colors');
  ok(buttons.every((b) => b.getAttribute('aria-pressed') === 'false'), 'no color is silently preselected');
  ok(buttons.every((b) => b.querySelector('.color-dot')), 'every option carries a visible sample');
  const status = fieldset.querySelector('.fp-color-status');
  ok(status.textContent.includes('Selecciona un color para poder agregar'), 'the pre-selection guidance explains the unavailable add');
  ok(select.closest('tr').hidden && fieldset.parentNode === doc.querySelector('form'), 'native table row is truly hidden, fieldset is outside table cells');
  ok(fieldset.querySelector('.reset_variations'), 'the native reset remains reachable outside the hidden row');
  ok(select.querySelectorAll('option').length === 4, 'the native select keeps every option');

  /* Selection through the buttons drives the single native select. */
  const changeSpy = [];
  $(select).on('change', () => changeSpy.push(select.value));
  buttons[1].dispatchEvent(new win.MouseEvent('click', { bubbles: true }));
  ok(select.value === 'Rojo', 'clicking Rojo sets the native select value');
  ok(changeSpy.length === 1 && changeSpy[0] === 'Rojo', 'the change Woo listens for fired through jQuery');
  ok(buttons[1].classList.contains('selected') && buttons[1].getAttribute('aria-pressed') === 'true', 'the chosen color shows its selected state');
  ok(buttons[0].getAttribute('aria-pressed') === 'false' && buttons[2].getAttribute('aria-pressed') === 'false', 'other colors stay unselected');
  ok(status.textContent.includes('Seleccionado: Rojo.'), 'the status names the selection');

  /* Woo resets (Restablecer): the form's own change clears the buttons. */
  $(select).val('').trigger('change');
  ok(select.value === '', 'the reset clears the native select');
  ok(buttons.every((b) => b.getAttribute('aria-pressed') === 'false'), 'clearing restores no-color-selected without picking another');
  ok(status.textContent.includes('Selecciona un color'), 'clearing restores the guidance');

  /* A direct programmatic Woo change (restore paths) syncs too. */
  select.value = 'Azul';
  select.dispatchEvent(new win.Event('change', { bubbles: true }));
  ok(buttons[2].classList.contains('selected'), 'a native change event syncs the buttons');

  select.options[3].disabled = true;
  $(select.form).trigger('woocommerce_update_variation_values');
  ok(buttons[2].disabled && buttons[2].getAttribute('aria-pressed') === 'false', 'native availability disables and deselects the named button');
  select.value = '';
  buttons[2].dispatchEvent(new win.MouseEvent('click', { bubbles: true }));
  ok(select.value === '', 'disabled native color cannot be chosen through the enhancement');

  /* Enhancement is idempotent. */
  win.eval(source);
  ok(doc.querySelectorAll('.fp-color-fieldset').length === 1, 'the fieldset never duplicates');

  /* Without any color attribute the sheet stays untouched. */
  const plain = new JSDOM(`<!doctype html><body><form class="variations_form"><select name="attribute_tamano"><option value="Grande">Grande</option></select></form></body>`, { url: 'https://example.test/p/', runScripts: 'outside-only', pretendToBeVisual: true });
  plain.window.eval(source);
  plain.window.document.dispatchEvent(new plain.window.Event('DOMContentLoaded', { bubbles: true }));
  ok(plain.window.document.querySelector('.fp-color-fieldset') === null, 'a non-color variation never gets color buttons');

  const taxonomy = new JSDOM(`<!doctype html><body>${variationForm.replaceAll('attribute_color', 'attribute_pa_color').replace('value="Azul"', 'value="azul"')}</body>`, { runScripts: 'outside-only' });
  taxonomy.window.eval(source);
  taxonomy.window.document.dispatchEvent(new taxonomy.window.Event('DOMContentLoaded'));
  const blue = taxonomy.window.document.querySelector('[data-fp-color="azul"]');
  ok(blue.textContent === 'Azul' && blue.querySelector('.blue'), 'taxonomy uses visible native labels, not slugs, for name and swatch');
  blue.click();
  ok(taxonomy.window.document.querySelector('select').value === 'azul', 'taxonomy color still submits the native slug');
  for (const instance of [dom, plain, taxonomy]) { instance.window.close(); }
  console.log(`product colors: ${checks} A · Directa variation-control checks passed (native select driven exclusively, two-way sync, no silent defaults)`);
  return checks;
}
