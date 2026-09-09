import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';
const source = readFileSync(new URL('../wp-content/themes/freeplast/assets/js/basket-count.js', import.meta.url), 'utf8');
const tick = () => new Promise(resolve => setTimeout(resolve, 10));
const row = id => `<tr class="wc-block-cart-items__row" id="row-${id}"><td><span>Producto con dos colores</span><input id="qty-${id}" value="3"><button type="button" class="wc-block-cart-item__remove-link" id="remove-${id}">Quitar</button></td></tr>`;

export async function runCartPresentationTests() {
  let checks = 0;
  const ok = (value, message) => { checks++; assert.ok(value, message); };
  const dom = new JSDOM('<!doctype html><body class="woocommerce-cart"><span class="fpw-basket-count">2</span><a id="other" href="#help">Otra acción</a><p data-fpw-removal-error role="alert" hidden></p><p data-fpw-removal-status role="status" hidden></p></body>', { url: 'https://example.test/cotizacion/', runScripts: 'outside-only' });
  const w = dom.window, doc = w.document;
  let items = [{ key: 'a', quantity: 3 }, { key: 'b', quantity: 3 }], pending = false, notify;
  const hooks = {}, translations = {};
  w.wp = { hooks: { addAction(name, namespace, callback) { hooks[name] = callback; } }, i18n: { setLocaleData(data) { Object.assign(translations, data); } }, data: { subscribe(callback) { notify = callback; }, getStoreNames: () => ['wc/store/cart'], select: () => ({ hasFinishedResolution: () => true, getCartData: () => ({ items }), getCartItem: key => items.find(item => item.key === key), hasPendingItemsOperations: () => pending }) } };
  const removing = key => hooks['experimental__woocommerce_blocks-cart-remove-item']({ product: { key, name: 'Producto con dos colores', variation: [{ attribute: 'Color', value: 'Azul' }] } });
  try {
    w.eval(source); await tick();
    // Cart store has resolved BEFORE React commits the sidebar. No new store
    // event follows the commit: the DOM reconciliation must catch this case.
    doc.body.insertAdjacentHTML('beforeend', `<table><tbody>${row('a')}${row('b')}</tbody></table><aside class="wc-block-cart__sidebar"><footer><div class="wp-block-woocommerce-proceed-to-checkout-block"><a class="wc-block-cart__submit-button" href="#next">Continuar</a></div></footer></aside><div class="wp-block-woocommerce-empty-cart-block" hidden><h2>Aún no agregas productos.</h2></div>`);
    await tick();
    ok(doc.querySelector('[data-fpw-cart-summary] strong')?.textContent === '2', 'late React sidebar gets initial native truth without another store event');
    const summary = doc.querySelector('[data-fpw-cart-summary]');
    const stableHeading = summary.querySelector('h2');
    notify(); await tick();
    ok(summary.querySelector('h2') === stableHeading, 'unchanged store/DOM reconciliation is idempotent, not an observer mutation loop');
    ok(doc.querySelectorAll('[data-fpw-cart-next]').length === 1, 'native CTA follow-up stays singular');
    const followUp = doc.querySelector('[data-fpw-cart-next]');
    ok(followUp.querySelectorAll('p').length === 2, 'follow-up contains only the commercial note and secondary link');
    ok(followUp.firstElementChild.textContent === 'Esta solicitud no es una compra ni reserva stock. Ventas confirmará precios, disponibilidad y condiciones.', 'one complete commercial explanation follows the native CTA');
    ok(followUp.lastElementChild.querySelector('a')?.textContent === 'Seguir agregando productos', 'secondary action follows the explanation');
    ok(!followUp.textContent.includes('El siguiente paso'), 'no redundant next-step paragraph');
    ok(Boolean(summary.compareDocumentPosition(doc.querySelector('.wc-block-cart__submit-button')) & w.Node.DOCUMENT_POSITION_FOLLOWING) && Boolean(doc.querySelector('.wc-block-cart__submit-button').compareDocumentPosition(followUp) & w.Node.DOCUMENT_POSITION_FOLLOWING), 'native nested CTA remains between numbers and explanation');

    ok(translations['%s has been removed from your cart.'][0].includes('actualizando'), 'native optimistic removal announcement is progress, not an unconfirmed success');
    const removeA = doc.getElementById('remove-a'); removeA.focus(); pending = true; removeA.click(); removing('a');
    doc.getElementById('row-a').remove(); items = [items[1]];
    await tick();
    ok(doc.activeElement === doc.body, 'pending removal never prematurely restores focus');
    const submit = doc.querySelector('.wc-block-cart__submit-button'); submit.setAttribute('aria-disabled', 'true');
    pending = false; notify(); await tick();
    ok(doc.querySelector('[data-fpw-removal-status]').hidden, 'other operation body-settlement guard suppresses removal verdicts');
    submit.setAttribute('aria-disabled', 'false'); await tick();
    ok(doc.querySelector('[data-fpw-removal-status]').textContent.includes('Se quitó') && doc.querySelector('[data-fpw-removal-status]').textContent.includes('Azul'), 'settled native removal names the actual color only after all guards release');
    ok(doc.activeElement === doc.getElementById('qty-b'), 'settled removal focuses the actual next color row, not a name match');
    ok(doc.querySelector('.fpw-basket-count').textContent === '1', 'native removal updates line count');

    const removeB = doc.getElementById('remove-b'); removeB.focus(); pending = true; removeB.click();
    doc.getElementById('other').focus(); doc.getElementById('row-b').remove(); items = [];
    doc.querySelector('.wp-block-woocommerce-empty-cart-block').hidden = false;
    pending = false; notify(); await tick();
    ok(doc.activeElement.id === 'other', 'later deliberate focus is never stolen');
    ok(doc.querySelector('[data-fpw-cart-summary]').hidden && doc.querySelector('[data-fpw-cart-next]').hidden, 'empty native state hides both populated summary and follow-up');

    doc.querySelector('tbody').insertAdjacentHTML('beforeend', row('c')); items = [{ key: 'c', quantity: 3 }]; notify(); await tick();
    const removeC = doc.getElementById('remove-c'); removeC.focus(); pending = true; removeC.click();
    doc.getElementById('row-c').remove(); items = []; pending = false; notify(); await tick();
    ok(doc.activeElement === doc.querySelector('.wp-block-woocommerce-empty-cart-block h2'), 'last-line removal focuses the real empty-state heading');

    doc.querySelector('tbody').insertAdjacentHTML('beforeend', row('d')); items = [{ key: 'd', quantity: 3 }]; notify(); await tick();
    const removeD = doc.getElementById('remove-d'); removeD.focus(); pending = true; removeD.click(); removing('d'); removeD.disabled = true; removeD.blur();
    await tick(); removeD.disabled = false; pending = false; notify(); await tick();
    ok(doc.activeElement === removeD && doc.getElementById('qty-d').value === '3', 'native failed removal restores its still-present control without changing quantity');
    const failure = doc.querySelector('[data-fpw-removal-error]');
    ok(!failure.hidden && failure.textContent.includes('3 unidades') && failure.textContent.includes('No pudimos confirmar'), 'unconfirmed deletion names the native quantity without falsely asserting backend non-persistence');
    doc.querySelector('tbody').insertAdjacentHTML('beforeend', row('e')); items.push({ key: 'e', quantity: 1 }); notify(); await tick();
    pending = true; removing('e'); await tick();
    ok(!failure.hidden, 'a new removal does not prematurely dismiss an unresolved error');
    doc.getElementById('row-e').remove(); items = items.filter(item => item.key !== 'e'); pending = false; notify(); await tick();
    ok(!failure.hidden && failure.textContent.includes('3 unidades'), 'success for another line does not clear the failed line');
    pending = true; removing('d'); await tick();
    ok(!failure.hidden, 'retry keeps its error until confirmed');
    doc.getElementById('row-d').remove(); items = []; pending = false; notify(); await tick();
    ok(failure.hidden && failure.textContent === '', 'successful retry clears only its resolved error');
    console.log(`cart presentation: ${checks} late-DOM/focus/feedback checks passed (modeled store; hydrated native browser unrun)`);
    return checks;
  } finally { dom.window.close(); }
}
