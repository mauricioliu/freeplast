import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

export async function runQuantityHoldTests() {
  const dom = new JSDOM(`<body><div data-fpw-loop-add><input class="qty fpw-delta-5" type="number" min="1" value="65"><a class="add_to_cart_button"></a></div><form class="cart"><input class="qty fpw-delta-200" type="number" min="1" value="2000"></form><table><tr class="wc-block-cart-items__row" data-cart-item-key="line"><td><a class="wc-block-components-product-name" href="/product">Caja</a><div><button class="wc-block-components-quantity-selector__button wc-block-components-quantity-selector__button--minus">−</button><input class="wc-block-components-quantity-selector__input" type="number" min="1" max="9999" value="65"><button class="wc-block-components-quantity-selector__button wc-block-components-quantity-selector__button--plus">+</button></div></td></tr></table><button id="other">Other</button>`, { url: 'https://example.test', runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window, doc = w.document;
  let now = 0, id = 0, checks = 0;
  const timers = new Map();
  w.setTimeout = (fn, delay) => { timers.set(++id, { fn, at: now + delay }); return id; };
  w.clearTimeout = key => timers.delete(key);
  const advance = ms => {
    const end = now + ms;
    while (true) {
      const next = [...timers.entries()].filter(([, t]) => t.at <= end).sort((a, b) => a[1].at - b[1].at)[0];
      if (!next) break;
      now = next[1].at; timers.delete(next[0]); next[1].fn();
    }
    now = end;
  };
  const ok = (value, message) => { checks++; assert.ok(value, message); };
  const pointer = (target, type, pointerType = 'mouse', pointerId = 1) => {
    const event = new w.MouseEvent(type, { bubbles: true, cancelable: true, button: 0 });
    Object.defineProperties(event, { pointerId: { value: pointerId }, pointerType: { value: pointerType }, isPrimary: { value: true } });
    target.dispatchEvent(event);
  };
  const key = (target, type, value, repeat = false) => target.dispatchEvent(new w.KeyboardEvent(type, { key: value, repeat, bubbles: true, cancelable: true }));
  w.freeplastQuantityDeltas = { 'https://example.test/product': 5 };
  w.eval(readFileSync(new URL('../wp-content/themes/freeplast/assets/js/loop-add-to-cart-quantity.js', import.meta.url), 'utf8'));
  doc.dispatchEvent(new w.Event('DOMContentLoaded'));
  try {
    const input = doc.querySelector('input.qty'), plus = doc.querySelector('.fp-qty-step--plus');
    pointer(plus, 'pointerdown');
    ok(input.value === '70', 'pointer applies the five-unit exception immediately');
    advance(399); ok(input.value === '70', 'initial pause');
    advance(201); ok(input.value === '85', 'constant five-unit 100ms repeats after 400ms pause');
    pointer(doc, 'pointerup'); plus.click(); advance(1000);
    ok(input.value === '85', 'release and following click produce no extra change');
    ok(doc.querySelector('.add_to_cart_button').dataset.quantity === '85', 'card native add quantity mirrors draft');
    pointer(plus, 'pointerdown', 'touch'); pointer(doc, 'pointercancel', 'touch'); advance(1000);
    ok(input.value === '90', 'touch cancellation stops immediately');
    pointer(plus, 'pointerdown'); doc.getElementById('other').focus(); advance(1000);
    ok(input.value === '95', 'focus loss stops');
    pointer(plus, 'pointerdown'); w.dispatchEvent(new w.Event('blur')); advance(1000);
    ok(input.value === '100', 'window blur stops');
    key(plus, 'keydown', ' '); key(plus, 'keydown', ' ', true); advance(400); key(plus, 'keyup', ' '); plus.click(); advance(1000);
    ok(input.value === '110', 'keyboard repeats use same clock, not OS repeats or follow-up click');
    const sheet = doc.querySelector('form input'), sheetPlus = doc.querySelector('form .fp-qty-step--plus');
    pointer(sheetPlus, 'pointerdown', 'touch'); advance(400); pointer(doc, 'pointerup', 'touch');
    ok(sheet.value === '2020', 'sheet uses ten on touch despite legacy delta two hundred');
    const cart = doc.querySelector('.wc-block-components-quantity-selector__input');
    const cartPlus = doc.querySelector('.wc-block-components-quantity-selector__button--plus');
    let pending = [], notify, intent;
    const item = { key: 'line', name: 'Caja', quantity: 65 };
    w.wp = { data: { select: () => ({ getCartData: () => ({ items: [item] }), getCartItem: () => item, getItemsPendingQuantityUpdate: () => pending, hasPendingItemsOperations: () => pending.length > 0 }), subscribe: fn => { notify = fn; } }, hooks: { addAction: (_name, _ns, fn) => { intent = fn; } } };
    const dock = doc.createElement('div'); dock.className = 'wc-block-cart__main'; doc.body.appendChild(dock);
    const submit = doc.createElement('a'); submit.className = 'wc-block-cart__submit-button'; doc.body.appendChild(submit);
    const feedback = readFileSync(new URL('../wp-content/themes/freeplast/assets/js/cart-quantity-feedback.js', import.meta.url), 'utf8');
    w.eval('(function(){var module={exports:{}};' + feedback + ';module.exports.createWatcher(window);})()');
    let changes = 0, final;
    cart.addEventListener('input', () => { changes++; final = cart.value; });
    pointer(cartPlus, 'pointerdown'); advance(1000);
    ok(cart.value === '105' && changes === 0 && w.freeplastQuantityHolding, 'cart repeats locally, no native change/request per tick');
    pointer(doc, 'pointerup'); cartPlus.click();
    ok(changes === 1 && final === '105' && !w.freeplastQuantityHolding, 'release sends exactly one native final input');
    advance(600);
    ok(submit.getAttribute('aria-disabled') === 'true', 'native input debounce before intent keeps CTA locked');
    const click = new w.MouseEvent('click', { bubbles: true, cancelable: true }); submit.dispatchEvent(click);
    ok(click.defaultPrevented, 'next CTA cannot advance with unconfirmed final draft');
    pointer(cartPlus, 'pointerdown'); pointer(doc, 'pointerup'); cartPlus.click();
    ok(cart.value === '105' && changes === 1, 'new hold waits for previous native confirmation');
    intent({ product: item, quantity: 105 }); advance(400);
    ok(submit.getAttribute('aria-disabled') === 'true', 'native store debounce after intent also stays locked');
    pending = ['line']; notify(); item.quantity = 105; pending = []; notify(); advance(50);
    ok(submit.getAttribute('aria-disabled') === 'false', 'confirmed final quantity unlocks');
    pointer(cartPlus, 'pointerdown'); pointer(doc, 'lostpointercapture'); advance(500);
    ok(changes === 2 && final === '110', 'lost capture flushes once and stops');
    intent({ product: item, quantity: 110 }); pending = ['line']; notify(); pending = []; notify(); advance(50);
    ok(doc.querySelector('[role="alert"]').textContent.includes('105 unidades'), 'failure feedback names persisted quantity, not held draft');
    cart.value = '105'; // Native React rolls its controlled input back from the store.
    pointer(cartPlus, 'pointerdown'); cartPlus.closest('tr').remove();
    await Promise.resolve(); advance(1000);
    ok(changes === 2 && !w.freeplastQuantityHolding, 'removed cart control cancels without detached input persistence');
    input.value = '2'; pointer(doc.querySelector('.fp-qty-step--minus'), 'pointerdown'); advance(600); pointer(doc, 'pointerup');
    ok(input.value === '1', 'decrement respects positive floor');
    input.value = '128'; input.dispatchEvent(new w.Event('input', { bubbles: true }));
    ok(doc.querySelector('.add_to_cart_button').dataset.quantity === '128', 'manual entry preserved');
    pointer(plus, 'pointerdown'); plus.remove(); await Promise.resolve(); advance(1000);
    ok(input.value === '133' && timers.size === 0, 'removed card button clears timers');
    input.className = 'qty fpw-delta-1';
    input.value = '128';
    const tomateraPlus = doc.createElement('button');
    tomateraPlus.type = 'button';
    tomateraPlus.className = 'fp-qty-step fp-qty-step--plus';
    input.parentNode.appendChild(tomateraPlus);
    pointer(tomateraPlus, 'pointerdown');
    ok(input.value === '129', 'tomatera steps one immediately from 128');
    advance(399); ok(input.value === '129', 'tomatera initial pause');
    advance(1); ok(input.value === '130', 'tomatera hold reaches 130');
    advance(200); ok(input.value === '132', 'tomatera repeats one-unit ticks');
    pointer(doc, 'pointerup'); tomateraPlus.click(); advance(1000);
    ok(input.value === '132' && doc.querySelector('.add_to_cart_button').dataset.quantity === '132', 'release mirrors the tomatera draft without extra steps');
    pointer(doc.querySelector('.fp-qty-step--minus'), 'pointerdown'); pointer(doc, 'pointerup');
    ok(input.value === '131', 'tomatera minus steps one down');
  } finally { dom.window.close(); }
  console.log(`quantity hold: ${checks} timer/DOM checks passed`);
  return checks;
}
