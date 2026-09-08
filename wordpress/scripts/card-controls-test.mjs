import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

/* Issue #42 — behavioral checks for the A · Directa card controls:
 * the −/+ stepper around Woo's own quantity input (loop-add-to-cart-quantity.js)
 * and the header-count/dock store bridge (basket-count.js). All mutations go
 * through the native input/anchor attributes; no fetch, no own cart. */
const stepperSource = readFileSync(new URL('../wp-content/themes/freeplast/assets/js/loop-add-to-cart-quantity.js', import.meta.url), 'utf8');
const bridgeSource = readFileSync(new URL('../wp-content/themes/freeplast/assets/js/basket-count.js', import.meta.url), 'utf8');

/* Load a script inside a realm with a local `module` so its exported unit
 * surface binds to THAT window's document (realm-matching events/DOM). */
function loadApi(windowObj, source) {
  return windowObj.eval('(function(){ var module = { exports: {} };\n' + source + '\nreturn module.exports; })()');
}

const loopCard = (id, value = 2, min = 1, max = '') =>
  `<li class="product-card"><div class="fpw-loop-add" data-fpw-loop-add><div class="quantity"><input type="number" class="qty" inputmode="numeric" min="${min}" ${max ? `max="${max}"` : ''} step="1" value="${value}" aria-label="Cantidad de Caja X"></div><a class="button add_to_cart_button" href="/?add-to-cart=${id}" data-product_id="${id}" data-quantity="1">Agregar</a></div></li>`;

export async function runCardControlsTests() {
  let checks = 0;
  const ok = (cond, message) => { checks++; assert.ok(cond, message); };

  /* Stepper: the script exposes its unit surface bound to this realm. */
  const dom = new JSDOM(`<!doctype html><body>${loopCard(11)}${loopCard(22, 5, 1, 5)}</body>`, { url: 'https://example.test/tienda/', runScripts: 'outside-only', pretendToBeVisual: true });
  const stepper = loadApi(dom.window, stepperSource);
  const doc = dom.window.document;
  stepper.initSteppers(doc);
  const [first, second] = doc.querySelectorAll('[data-fpw-loop-add]');
  ok(first.querySelectorAll('.fp-qty-control').length === 1, 'each quantity input is wrapped exactly once');
  const minus = first.querySelector('.fp-qty-step--minus');
  const plus = first.querySelector('.fp-qty-step--plus');
  ok(Boolean(minus && plus), 'the stepper renders − and + buttons');
  ok(minus.getAttribute('aria-label') === 'Reducir Caja X' && plus.getAttribute('aria-label') === 'Aumentar Caja X', 'stepper buttons carry descriptive names');
  const input = first.querySelector('input.qty');
  ok(input.closest('.fp-qty-control') === input.parentNode, 'the native input stays inside the stepper');
  ok(minus.tabIndex === 0 && plus.tabIndex === 0, 'both named stepper buttons are keyboard reachable');

  stepper.stepValue(input, 1);
  ok(input.value === '3', '+ steps the native input');
  stepper.mirrorQuantity(first);
  ok(first.querySelector('.add_to_cart_button').getAttribute('data-quantity') === '3', 'the mirrored data-quantity follows the input');
  stepper.stepValue(input, -1);
  ok(input.value === '2', '− steps back down');
  stepper.stepValue(input, -1);
  stepper.stepValue(input, -1);
  ok(input.value === '1', 'stepping never goes below the input min');
  stepper.mirrorQuantity(first);
  ok(input.value === '1' && first.querySelector('.add_to_cart_button').getAttribute('data-quantity') === '1', 'a clamped value keeps the mirrored quantity coherent');

  /* The delivered script binds its own document listeners: run it inside a
     live page so a real click travels the full native path. */
  const clickDom = new JSDOM(`<!doctype html><body>${loopCard(33)}</body>`, { url: 'https://example.test/tienda/', runScripts: 'outside-only', pretendToBeVisual: true });
  clickDom.window.eval(stepperSource);
  clickDom.window.document.dispatchEvent(new clickDom.window.Event('DOMContentLoaded', { bubbles: true }));
  const clickCard = clickDom.window.document.querySelector('[data-fpw-loop-add]');
  const clickInput = clickCard.querySelector('input.qty');
  clickCard.querySelector('.fp-qty-step--plus').dispatchEvent(new clickDom.window.MouseEvent('click', { bubbles: true }));
  ok(clickInput.value === '3' && clickCard.querySelector('.add_to_cart_button').getAttribute('data-quantity') === '3', '+ steps the native input and mirrors data-quantity through the delivered listeners');
  clickCard.querySelector('.fp-qty-step--minus').dispatchEvent(new clickDom.window.MouseEvent('click', { bubbles: true }));
  ok(clickInput.value === '2' && clickCard.querySelector('.add_to_cart_button').getAttribute('data-quantity') === '2', '− steps back down and re-mirrors');

  const capped = second.querySelector('input.qty');
  stepper.stepValue(capped, 1);
  ok(capped.value === '5', 'max caps the step when the input declares one');
  input.value = '';
  input.dispatchEvent(new dom.window.Event('input', { bubbles: true }));
  ok(first.querySelector('.add_to_cart_button').getAttribute('aria-disabled') === 'true', 'a cleared quantity is unavailable, not silently replaced with one');
  const add = clickCard.querySelector('.add_to_cart_button');
  let activations = 0;
  clickDom.window.document.body.addEventListener('click', event => { if (event.target === add) activations++; });
  for (const value of ['', '0', '-1', '2.5']) {
    clickInput.value = value;
    const event = new clickDom.window.MouseEvent('click', { bubbles: true, cancelable: true });
    add.dispatchEvent(event);
    ok(event.defaultPrevented && activations === 0, `invalid quantity ${value} never reaches native add`);
  }
  clickInput.value = '7';
  add.classList.add('loading');
  const busy = new clickDom.window.MouseEvent('click', { bubbles: true, cancelable: true });
  add.dispatchEvent(busy);
  ok(busy.defaultPrevented && activations === 0, 'an in-flight native add cannot be activated twice');
  const form = clickDom.window.document.createElement('form');
  form.className = 'cart'; form.innerHTML = '<input type="number" class="qty" min="3" max="9" step="3" value="3">';
  clickDom.window.document.body.appendChild(form);
  stepper.initSteppers(form);
  form.querySelector('.fp-qty-step--plus').click();
  ok(form.querySelector('input').value === '6', 'product-sheet stepper works without a catalog wrapper, using the real step');
  form.querySelector('input').disabled = true;
  form.querySelector('.fp-qty-step--minus').click();
  ok(form.querySelector('input').value === '6', 'disabled variation quantity cannot be changed by the enhancement');

  /* Header count + dock bridge (realm-bound like the stepper). */
  const dockDom = new JSDOM(
    `<!doctype html><body class="fpw-has-dock"><span class="count"><span class="fpw-basket-count">0</span></span><div class="fpw-selection-dock" data-fpw-selection-dock hidden><div><strong>0</strong><span></span></div><a class="button" href="/cotizacion/">Revisar</a></div></body>`,
    { url: 'https://example.test/tienda/', runScripts: 'outside-only', pretendToBeVisual: true },
  );
  const bridge = loadApi(dockDom.window, bridgeSource);
  const dockDoc = dockDom.window.document;
  dockDom.window.jQuery = undefined; /* store-only path under test here */
  bridge.render(2);
  ok(dockDoc.querySelector('.fpw-basket-count').textContent === '2', 'the header count re-renders from the store');

  bridge.renderDock(2, 72);
  const dock = dockDoc.querySelector('[data-fpw-selection-dock]');
  ok(!dock.hasAttribute('hidden'), 'the dock shows with a live selection');
  ok(dock.querySelector('strong').textContent === '2 productos seleccionados', 'the dock counts distinct lines');
  ok(dock.querySelector('span').textContent === '72 unidades · sin pago en línea', 'the dock totals native units');
  ok(dock.querySelector('a.button').getAttribute('href') === '/cotizacion/', 'the dock action targets Productos a Cotizar');
  ok(dockDoc.body.classList.contains('fpw-has-selection'), 'the body reserves the dock space');

  bridge.renderDock(1, 5);
  ok(dock.querySelector('strong').textContent === '1 producto seleccionado' && dock.querySelector('span').textContent === '5 unidades · sin pago en línea', 'singular/plural copy follows the native totals');
  bridge.renderDock(0, 0);
  ok(dock.hasAttribute('hidden') && !dockDoc.body.classList.contains('fpw-has-selection'), 'an authoritative empty selection hides the dock and frees the space');

  /* Store subscription drives both surfaces from wc/store/cart truth. */
  let notify;
  const storeDom = new JSDOM(`<!doctype html><body><span class="count"><span class="fpw-basket-count">0</span></span><div class="fpw-selection-dock" data-fpw-selection-dock hidden><div><strong></strong><span></span></div><a class="button" href="/cotizacion/">Revisar</a></div></body>`, { url: 'https://example.test/tienda/', runScripts: 'outside-only', pretendToBeVisual: true });
  const storeItems = [{ quantity: 70 }, { quantity: 2 }];
  storeDom.window.wp = {
    data: {
      subscribe(fn) { notify = fn; },
      getStoreNames: () => ['wc/store/cart'],
      select: () => ({ getCartData: () => ({ items: storeItems }), hasFinishedResolution: () => true }),
    },
  };
  storeDom.window.eval(bridgeSource);
  storeDom.window.document.dispatchEvent(new storeDom.window.Event('DOMContentLoaded', { bubbles: true }));
  storeItems.pop();
  notify();
  ok(storeDom.window.document.querySelector('.fpw-basket-count').textContent === '1', 'a store mutation updates the header line count');
  ok(storeDom.window.document.querySelector('[data-fpw-selection-dock] strong').textContent === '1 producto seleccionado', 'a store mutation re-renders the dock truth');
  storeItems.length = 0;
  notify();
  ok(storeDom.window.document.querySelector('[data-fpw-selection-dock]').hasAttribute('hidden'), 'emptying through the store hides the dock');

  for (const instance of [dom, clickDom, dockDom, storeDom]) { instance.window.close(); }

  /* Issue #45: cart-page summary lives inside the REAL block sidebar, above
   * the native CTA, driven by the same store truth. */
  const cartDom = new JSDOM(
    `<!doctype html><body class="woocommerce-cart"><div class="wc-block-cart__sidebar"><footer><div class="wp-block-woocommerce-proceed-to-checkout-block"><a class="wc-block-cart__submit-button" href="/datos-y-envio/">Continuar</a></div></footer></div></body>`,
    { url: 'https://example.test/cotizacion/', runScripts: 'outside-only', pretendToBeVisual: true },
  );
  const cartBridge = loadApi(cartDom.window, bridgeSource);
  const cartDoc = cartDom.window.document;
  cartBridge.renderCartSummary(2, 95);
  const summaryNode = cartDoc.querySelector('[data-fpw-cart-summary]');
  ok(Boolean(summaryNode), 'the summary is created inside the block sidebar');
  ok(summaryNode.nextElementSibling === null || summaryNode.nextElementSibling.querySelector('.wc-block-cart__submit-button') !== null, 'the summary sits above the native CTA');
  ok(!summaryNode.hasAttribute('hidden'), 'a live selection shows the summary');
  ok(summaryNode.querySelector('h2').textContent === 'Resumen de tu selección', 'A summary heading');
  ok(summaryNode.querySelectorAll('.summary-numbers strong')[0].textContent === '2' && summaryNode.querySelectorAll('.summary-numbers strong')[1].textContent === '95', 'distinct lines and total units are separated');
  ok(summaryNode.textContent.includes('productos distintos') && summaryNode.textContent.includes('unidades en total'), 'the units are labelled distinctly from the header line count');
  ok(!summaryNode.textContent.includes('$') && !summaryNode.textContent.toLowerCase().includes('total:'), 'no amounts appear in the summary');
  cartBridge.renderCartSummary(1, 1);
  ok(summaryNode.innerHTML.includes('1</strong><span>producto distinto') && summaryNode.innerHTML.includes('1</strong><span>unidad en total'), 'singular copy follows the native totals');
  cartBridge.renderCartSummary(0, 0);
  ok(summaryNode.hasAttribute('hidden'), 'an emptied cart hides the summary — the block\'s own empty state owns the page');
  const offCart = new JSDOM('<!doctype html><body class="woocommerce"><div class="wc-block-cart__sidebar"></div></body>', { url: 'https://example.test/tienda/', runScripts: 'outside-only' });
  const offBridge = loadApi(offCart.window, bridgeSource);
  offBridge.renderCartSummary(2, 9);
  ok(offCart.window.document.querySelector('[data-fpw-cart-summary]') === null, 'the summary never injects outside the cart page');
  cartDom.window.close();
  offCart.window.close();

  console.log(`card controls: ${checks} A · Directa stepper + dock + cart-summary bridge checks passed (native input/anchor contracts, store-driven header count, dock and basket truth)`);
  return checks;
}
