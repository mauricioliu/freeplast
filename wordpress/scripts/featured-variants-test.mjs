import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { JSDOM } from 'jsdom';
const require = createRequire(import.meta.url);
const jquery = require('jquery');
const theme = new URL('../wp-content/themes/freeplast/assets/js/', import.meta.url);
const native = new URL('./vendor/woocommerce-11.1.0-add-to-cart.js', import.meta.url);
function card(id) {
  return `<li class="product"><a class="photo-link" href="/producto/caja/"><span class="product-photo"><img src="/parent.jpg" srcset="/parent-2.jpg 2x" sizes="50vw" alt="Original"></span></a><div class="product-info"><h2 class="woocommerce-loop-product__title">Caja ${id}</h2><p class="product-spec">625 × 444 × 226 mm</p></div><div data-fpw-loop-add><div data-fpw-featured-picker hidden>
  <label class="fp-featured-colors">Color · obligatorio<select data-fpw-color-select required><option value="">Selecciona un color</option><option value="101" data-fpw-variant='{"id":101,"label":"Azul","max":200,"individual":false,"image":{"src":"/blue.jpg","srcset":"/blue-2.jpg 2x","sizes":"40vw","alt":"Caja azul","width":960,"height":960}}'>Azul</option><option value="102" data-fpw-variant='{"id":102,"label":"Verde","max":"","individual":false}'>Verde</option><option value="0" data-fpw-variant='{"id":0,"label":"Rojo","max":"","individual":false}' disabled>Rojo</option></select></label>
  <p data-fpw-featured-status role="status"></p><div class="quantity"><input class="qty fpw-delta-10" type="number" value="70" min="1" step="1"></div><a class="add_to_cart_button ajax_add_to_cart" href="/producto/caja/" data-product_id="0" data-fpw_featured_variant="1" aria-disabled="true">Agregar</a></div><span class="color-hint">Colores</span><a class="button secondary" href="/producto/caja/">Elegir color</a><div class="fpw-card-selection" data-product-id="${id}" data-quantity="0"></div></div></li>`;
}
const dom = new JSDOM(`<ul>${card(21)}${card(22)}${card(23)}</ul><span class="fpw-basket-count">0</span><div class="fpw-card-selections" data-fpw-cart-state="1" hidden></div>`, { url:'http://localhost/', runScripts:'outside-only' });
const { window: w } = dom;
const $ = jquery(w); w.jQuery = $; w.$ = $;
// Only transport and overlay plumbing are stubbed; Woo's actual queue,
// validation hooks, dataset/event order and fragment replacement run unchanged.
$.fn.block = function () { return this; };
$.fn.unblock = function () { return this; };
$.fx.off = true;
w.wc_add_to_cart_params = { wc_ajax_url:'/?wc-ajax=%%endpoint%%', cart_url:'/cotizacion/', cart_redirect_after_add:'no', is_cart:false, i18n_view_cart:'Ver selección' };
const requests = [];
$.ajax = options => { requests.push(options); };
for (const name of ['loop-add-to-cart-quantity.js','loop-added-count.js','featured-variants.js']) { w.eval(readFileSync(new URL(name, theme), 'utf8')); }
w.eval(readFileSync(native, 'utf8'));
await new Promise(resolve => setTimeout(resolve, 30));
const pickers = w.document.querySelectorAll('[data-fpw-featured-picker]');
const picker = pickers[0], other = pickers[1];
const select = picker.querySelector('select');
function choose(picker, value) {
  const select = picker.querySelector('select');
  if (select.disabled) { return; }
  select.value = value;
  select.dispatchEvent(new w.Event('change', {bubbles:true}));
}
const add = picker.querySelector('.add_to_cart_button'), input = picker.querySelector('input');
assert.equal(picker.hidden, false);
assert.equal(select.value, '');
assert.equal(select.required, true);
assert.equal(select.options[3].disabled, true);
const menu = picker.closest('li').querySelector('.fp-featured-menu');
assert.ok(menu.closest('.product-info'), 'compact menu lives beside product facts');
assert.equal(picker.querySelector('.fp-featured-colors').hidden, true);
assert.equal(menu.querySelectorAll('button').length, 3);
assert.equal(menu.querySelectorAll('button')[2].disabled, true);
add.click();
assert.equal(requests.length, 0, 'unselected color cannot call native AJAX');
assert.match(picker.textContent, /Elige un color/);
menu.open = true;
menu.querySelector('button').click();
assert.equal(menu.open, false);
assert.match(menu.querySelector('summary').textContent, /Azul/);
assert.equal(menu.querySelector('button').getAttribute('aria-pressed'), 'true');
assert.equal(add.dataset.product_id, '101');
assert.equal(other.querySelector('.add_to_cart_button').dataset.product_id, '0', 'card selection isolated');
assert.equal(input.value, '70', 'initial quantity preserved');
const photo = picker.closest('li').querySelector('.product-photo');
assert.equal(photo.querySelector('img').getAttribute('src'), '/blue.jpg');
assert.equal(photo.querySelector('img').getAttribute('srcset'), '/blue-2.jpg 2x');
assert.equal(photo.querySelector('img').alt, 'Caja azul');
assert.equal(other.closest('li').querySelector('img').getAttribute('src'), '/parent.jpg', 'image changes scoped to selected card');
choose(picker, '102');
assert.equal(photo.querySelector('img'), null, 'missing color cannot display previous photo');
assert.match(photo.textContent, /Fotografía no disponible en Verde/);
choose(picker, '');
assert.equal(photo.querySelector('img').getAttribute('src'), '/parent.jpg', 'clear restores original photo');
assert.equal(photo.querySelector('img').getAttribute('srcset'), '/parent-2.jpg 2x');
assert.match(menu.querySelector('summary').textContent, /Elegir/);
assert.ok([...menu.querySelectorAll('button')].every(button => button.getAttribute('aria-pressed') === 'false'));
choose(picker, '101');
photo.querySelector('img').dispatchEvent(new w.Event('error'));
assert.match(photo.textContent, /Fotografía no disponible en Azul/, 'broken attachment never leaves misleading photo');
choose(picker, '101');
picker.querySelector('.fp-qty-step--plus').click();
assert.equal(input.value, '80', 'existing increment preserved');
input.value = '1.5'; input.dispatchEvent(new w.Event('input', { bubbles:true })); add.click();
assert.equal(requests.length, 0, 'fractional quantity blocked');
input.value = '201'; input.dispatchEvent(new w.Event('input', { bubbles:true })); add.click();
assert.equal(requests.length, 0, 'Woo variation maximum respected');
input.value = '7'; input.dispatchEvent(new w.Event('input', { bubbles:true })); add.click(); add.click();
assert.equal(requests.length, 1, 'duplicate activation suppressed');
assert.equal(requests[0].data.product_id, '101', 'actual native Woo transport gets variation ID');
assert.equal(requests[0].data.quantity, '7');
assert.equal(requests[0].data.fpw_featured_variant, '1', 'native request preserves narrowly scoped QWC compatibility marker');
assert.equal(input.disabled, true);
assert.equal(select.disabled, true);
choose(picker, '102'); assert.equal(add.dataset.product_id, '101', 'pending operation freezes variant identity');
const fragments = (id, units, parentUnits, count) => ({
  'div.fpw-card-selections': `<div class="fpw-card-selections" data-fpw-cart-state="1" hidden><div class="fpw-card-selection" data-product-id="21" data-quantity="${parentUnits}"><span>${parentUnits} unidades agregadas</span></div><div class="fpw-card-selection" data-product-id="${id}" data-quantity="${units}"></div></div>`,
  'span.fpw-basket-count': `<span class="fpw-basket-count">${count}</span>`
});
function finish(request, response) {
  request.success(response); request.complete();
  $(w.document).trigger('ajaxComplete', [{responseJSON:response}, request]);
}
finish(requests[0], { fragments:fragments(101,7,7,1), cart_hash:'a' });
await new Promise(resolve => setTimeout(resolve, 5));
assert.equal(input.disabled, false);
assert.equal(w.document.querySelector('.fpw-basket-count').textContent, '1', 'native fragments update header');
assert.match(picker.textContent, /Azul: 7 unidades/);
assert.match(picker.closest('[data-fpw-loop-add]').querySelector('.fpw-card-selection').textContent, /7 unidades agregadas/, 'server projection updates card');
choose(picker, '102'); input.value='3'; input.dispatchEvent(new w.Event('input', {bubbles:true})); add.click();
assert.equal(requests[1].data.product_id, '102');
assert.equal(requests[1].data.quantity, '3');
finish(requests[1], { fragments:fragments(102,3,10,2), cart_hash:'b' });
await new Promise(resolve => setTimeout(resolve, 5));
assert.equal(w.document.querySelector('.fpw-basket-count').textContent, '2');
choose(picker, '101'); input.value='2'; input.dispatchEvent(new w.Event('input', {bubbles:true})); add.click();
finish(requests[2], { fragments:fragments(101,9,12,2), cart_hash:'c' });
assert.match(picker.textContent, /Azul: 9 unidades/);
assert.equal(w.document.querySelector('.fpw-basket-count').textContent, '2', 'same color accumulation uses authoritative fragments');
// A successful HTTP response without authoritative variation state is uncertain.
add.click(); finish(requests[3], {fragments:{}, cart_hash:'bad'});
assert.equal(picker.hasAttribute('data-fpw-uncertain'), true);
assert.equal(add.getAttribute('aria-disabled'), 'true');
assert.match(picker.textContent, /No pudimos confirmar/);
add.click(); assert.equal(requests.length, 4, 'uncertainty cannot replay the mutation');
// Independent second card: transport error locks only the affected card.
choose(other, '101');
other.querySelector('.add_to_cart_button').click();
$(w.document).trigger('ajaxError', [{}, requests[4]]); requests[4].complete();
assert.equal(other.hasAttribute('data-fpw-uncertain'), true);
assert.equal(requests.length, 5, 'no automatic retry');
assert.equal(other.querySelector('input').disabled, true, 'uncertain controls are visibly inert, not apparently enabled');
assert.match(other.querySelector('[data-fpw-featured-status] a').href, /cotizacion/, 'uncertainty provides a saved-selection recovery link');
const emptyResponse = pickers[2];
choose(emptyResponse, '101');
emptyResponse.querySelector('.add_to_cart_button').click();
finish(requests[5], null);
assert.equal(emptyResponse.hasAttribute('data-fpw-uncertain'), true, 'empty native success response is caught at AJAX completion');
assert.equal(emptyResponse.querySelector('.add_to_cart_button').classList.contains('loading'), false, 'uncertainty releases the spinner but never retries');
assert.ok(other.closest('[data-fpw-loop-add]').querySelector('a[href="/producto/caja/"]'), 'sheet fallback stays present');
w.close();
const fallbackDom = new JSDOM(`<ul>${card(21)}</ul>`);
const featureSource = readFileSync(new URL('featured-variants.js', theme), 'utf8');
const api = new Function('module', featureSource + '\nreturn module.exports;')({exports:{}});
const fallbackPicker = fallbackDom.window.document.querySelector('[data-fpw-featured-picker]');
api.enhance(fallbackPicker, fallbackDom.window);
assert.equal(fallbackPicker.hidden, true, 'missing Woo transport leaves the product-page fallback');
fallbackDom.window.jQuery = jquery(fallbackDom.window);
fallbackDom.window.wc_add_to_cart_params = {cart_redirect_after_add:'yes'};
api.enhance(fallbackPicker, fallbackDom.window);
assert.equal(fallbackPicker.hidden, true, 'redirect-enabled native setup falls back rather than breaking homepage preservation');
fallbackDom.window.close();
console.log('featured variants JS: real native Woo handler, scope, constraints, duplicates, fragments and uncertainty passed');
