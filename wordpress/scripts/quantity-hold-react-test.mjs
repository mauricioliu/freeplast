import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { JSDOM } from 'jsdom';

// Real installed WordPress React event/value tracking; component is a small fixture,
// not the Woo selector. Real Woo persistence/rollback lives in woo-cart-store-harness.
export async function runQuantityHoldReactTests() {
  const vendor = new URL('../.build/wp/wp-includes/js/dist/vendor/', import.meta.url);
  if (!existsSync(new URL('react-dom.min.js', vendor))) {
    console.log('quantity hold React: skipped (local WordPress vendor unavailable)');
    return 0;
  }
  const dom = new JSDOM('<body><div id="root"></div>', { url: 'https://example.test', runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window;
  let changes = 0, root;
  try {
    w.eval(readFileSync(new URL('react.min.js', vendor), 'utf8'));
    w.eval(readFileSync(new URL('react-dom.min.js', vendor), 'utf8'));
    const h = w.React.createElement;
    function Row() {
      const [quantity, setQuantity] = w.React.useState(65);
      return h('div', { className: 'wc-block-cart-items__row' },
        h('a', { className: 'wc-block-components-product-name', href: '/product' }, 'Caja'),
        h('div', {}, h('input', { type: 'number', min: 1, className: 'wc-block-components-quantity-selector__input', value: quantity, onChange: event => { changes++; setQuantity(Number(event.target.value)); } }),
          h('button', { className: 'wc-block-components-quantity-selector__button wc-block-components-quantity-selector__button--plus' }, '+')));
    }
    root = w.ReactDOM.createRoot(w.document.getElementById('root'));
    w.ReactDOM.flushSync(() => root.render(h(Row)));
    w.freeplastQuantityDeltas = { 'https://example.test/product': 5 };
    w.eval(readFileSync(new URL('../wp-content/themes/freeplast/assets/js/loop-add-to-cart-quantity.js', import.meta.url), 'utf8'));
    const plus = w.document.querySelector('button'), input = w.document.querySelector('input');
    const pointer = type => {
      const event = new w.MouseEvent(type, { bubbles: true, cancelable: true, button: 0 });
      Object.defineProperty(event, 'pointerId', { value: 1 });
      plus.dispatchEvent(event);
    };
    pointer('pointerdown');
    await new Promise(resolve => setTimeout(resolve, 450));
    assert.equal(input.value, '75'); assert.equal(changes, 0);
    pointer('pointerup'); plus.click();
    assert.equal(changes, 1); assert.equal(input.value, '75');
    // A controlled rerender must preserve the final React state, not erase a DOM-only draft.
    w.ReactDOM.flushSync(() => root.render(h(Row)));
    assert.equal(input.value, '75');
    console.log('quantity hold React: 5 real value-tracker/event checks passed');
    return 5;
  } finally { if (root) w.ReactDOM.flushSync(() => root.unmount()); dom.window.close(); }
}
