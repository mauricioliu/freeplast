import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

// Declaration/selector contracts only; browser screenshots verify actual layout.
export function runDesktopVisualTests() {
  const theme = new URL('../wp-content/themes/freeplast/', import.meta.url);
  const dom = new JSDOM('<!doctype html><body>');
  let checks = 0;
  const ok = (value, label) => { assert.ok(value, label); checks++; };
  try {
    const sheets = ['style.css', 'assets/css/woo.css'].map(file => {
      const style = dom.window.document.createElement('style');
      style.textContent = readFileSync(new URL(file, theme), 'utf8');
      dom.window.document.head.append(style);
      return style.sheet;
    });
    const rules = list => [...list].flatMap(rule => rule.cssRules ? rules(rule.cssRules) : [rule]);
    const all = sheets.flatMap(sheet => rules(sheet.cssRules));
    const rule = selector => all.find(r => r.selectorText === selector);
    const property = (selector, name, value) => ok(rule(selector)?.style.getPropertyValue(name) === value, `${selector}: ${name} = ${value}`);
    property('body:is(.woocommerce, .woocommerce-cart) .fp-catalog .empty-state', 'display', 'grid');
    property('body:is(.woocommerce, .woocommerce-cart) .fp-catalog .empty-state h2', 'overflow-wrap', 'anywhere');
    property('.fp-cart-page:has(> .fp-steps), .fp-checkout-page', 'padding-top', '24px');
    property('body .wc-block-cart table.wc-block-cart-items .wc-block-cart-items__row .wc-block-components-product-name', 'font-size', '17px');
    property('body .wc-block-cart table.wc-block-cart-items tr.wc-block-cart-items__row > td', 'padding', '0');
    property('.fp-checkout-page .fp-fields .fp-dispatch-options label.radio', 'justify-content', 'flex-start');
    property('.fp-checkout-summary table.shop_table.woocommerce-checkout-review-order-table', 'border', '0');
    property('.fp-error-summary', 'background', 'var(--fp-tint)');
    property('.fp-checkout-page .fp-fields .form-row .fp-field-error', 'color', 'var(--fp-blue)');
    ok(all.some(r => r.selectorText?.includes('#order_comments_field') && r.style.getPropertyValue('grid-column') === '1 / -1'), 'native notes row spans both desktop tracks');
    const selector = 'body.woocommerce .fp-product-shell form.cart .variations:has([data-fp-color-superseded]):not(:has(tr:not([hidden])))';
    property(selector, 'display', 'none');
    const doc = dom.window.document;
    doc.body.className = 'woocommerce';
    doc.body.innerHTML = '<div class="fp-product-shell"><form class="cart"><table class="variations"><tbody><tr hidden data-fp-color-superseded="true"><td>Color</td></tr></tbody></table></form></div>';
    const table = doc.querySelector('table');
    ok(table.matches(selector), 'enhanced all-hidden native table loses its dead space');
    table.querySelector('tr').hidden = false;
    ok(!table.matches(selector), 'no-JS native color select remains visible');
    table.querySelector('tr').hidden = true;
    table.querySelector('tbody').insertAdjacentHTML('beforeend', '<tr><td>Other native option</td></tr>');
    ok(!table.matches(selector), 'a second native attribute is never hidden');
    console.log(`desktop visual: ${checks} CSS/selector contracts passed (not rendered-layout acceptance)`);
    return checks;
  } finally { dom.window.close(); }
}
