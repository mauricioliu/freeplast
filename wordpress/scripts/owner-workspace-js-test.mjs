import {JSDOM} from 'jsdom';
import {readFileSync} from 'node:fs';

export function runOwnerWorkspaceJsTests() {
  let checks = 0;
  const check = (ok, message) => { if (!ok) throw new Error(message); checks++; };
  const source = readFileSync(new URL('../wp-content/plugins/freeplast-woo/assets/owner-workspace.js', import.meta.url), 'utf8');
  for (const saved of ['0', '1']) {
    const dom = new JSDOM(`<div class="fpw-workspace">
      <form id="fpw-work-form" data-fpw-work data-fpw-has-saved="${saved}"><input name="fpw_work[lines][0][quantity]" value="100"><input name="fpw_work[lines][0][price]" value="1500"></form>
      <p data-fpw-pallets="0" data-units="70">1 pallet completo + 30 un.</p>
      <p data-fpw-origin="0">Precio guardado para esta oferta.</p>
      <button type="button" hidden data-fpw-apply-price="0" data-price="2400">1 a 4 pallets</button>
      <button type="button" hidden data-fpw-apply-price="0" data-price="1900">5 o más pallets</button>
      <aside id="fpw-summary"><p data-fpw-save-state>Importes guardados</p><button type="reset" form="fpw-work-form" data-fpw-discard>Descartar</button></aside>
      <form data-distance-test><input name="destination" value=""><button type="submit">Consultar distancia</button></form>
      <nav><strong data-fpw-dock-total>178.500 CLP</strong><button type="submit" name="fpw_work_save" value="1" form="fpw-work-form" data-fpw-dock-save>Guardar</button><button type="submit" name="fpw_work_preview" value="1" form="fpw-work-form" data-fpw-preview data-fpw-dock-preview hidden>Revisar</button></nav>
    </div>`, {runScripts:'outside-only', url:'https://freeplast.test/'});
    try {
      const {document, Event, FormData} = dom.window;
      dom.window.eval(source);
      const save = document.querySelector('[data-fpw-dock-save]');
      const preview = document.querySelector('[data-fpw-dock-preview]');
      const discard = document.querySelector('[data-fpw-discard]');
      const form = document.querySelector('[data-fpw-work]');
      const price = form.elements.namedItem('fpw_work[lines][0][price]');
      check(save.hidden === (saved === '1') && preview.hidden === (saved !== '1'), 'clean dock reviews saved work or saves a new draft');
      check(discard.hidden, 'discard is hidden while clean');
      const quantity = form.elements.namedItem('fpw_work[lines][0][quantity]');
      quantity.value = '350'; quantity.dispatchEvent(new Event('input', {bubbles:true}));
      check(!save.hidden && preview.hidden && preview.disabled && !discard.hidden, 'dirty dock offers save, never silent preview');
      check(document.querySelector('[data-fpw-dock-total]').textContent === 'Sin guardar' && price.value === '1500', 'quantity edit does not invent a total or change the unit price');
      const posted = new FormData(form, save);
      check(posted.get('fpw_work_save') === '1' && posted.get('fpw_work[lines][0][quantity]') === '350' && !posted.has('fpw_work_preview'), 'external dock button submits the native form and only its explicit action');
      const distance = document.querySelector('[data-distance-test]');
      const otherSubmit = new Event('submit', {bubbles:true, cancelable:true});
      distance.dispatchEvent(otherSubmit);
      check(otherSubmit.defaultPrevented && distance.querySelector('[role="alert"]'), 'another form cannot silently discard quotation edits');
      form.reset();
      check(quantity.value === '100' && price.value === '1500' && preview.disabled === false, 'reset restores native fields and review availability');
      check(discard.hidden && document.querySelector('[data-fpw-dock-total]').textContent === '178.500 CLP', 'reset restores saved total and clears discard');
      check(save.hidden === (saved === '1'), 'reset restores the appropriate dock action');
      const small = document.querySelector('[data-price="2400"]');
      const bulk = document.querySelector('[data-price="1900"]');
      check(!small.hidden && !bulk.hidden, 'both reference choices are progressively enhanced');
      small.click();
      check(price.value === '2400' && preview.disabled && document.activeElement === price, 'explicit small-reference choice changes only the working input and requires save');
      bulk.click();
      check(price.value === '1900', 'bulk reference is an explicit independent choice');
      const originLegend = document.querySelector('[data-fpw-origin="0"]');
      check(originLegend.textContent === 'Cambio sin guardar.', 'H6: editing the price stops the legend claiming a saved origin');
      check(document.querySelector('[data-fpw-pallets]').textContent.includes('1 pallet completo + 30 un.'), 'H6 context: the price edit leaves packaging facts untouched');
      quantity.value = '71'; quantity.dispatchEvent(new Event('input', {bubbles:true}));
      check(document.querySelector('[data-fpw-pallets]').textContent.includes('1 pallet completo + 1 un.') && price.value === '1900', 'quantity change updates packaging but never switches a chosen tier');
      check(originLegend.textContent === 'Cambio sin guardar.', 'H6: a quantity-only edit never rewrites the price legend');
      price.value = '1500'; price.dispatchEvent(new Event('input', {bubbles:true}));
      check(originLegend.textContent === 'Precio guardado para esta oferta.', 'H6: retyping the exact saved value restores the honest saved legend');
      price.value = '1750'; price.dispatchEvent(new Event('input', {bubbles:true}));
      quantity.value = '700'; quantity.dispatchEvent(new Event('input', {bubbles:true}));
      check(price.value === '1750' && document.querySelector('[data-fpw-pallets]').textContent.includes('10 pallets completos'), 'manual adjustment survives threshold crossings');
      form.reset();
      check(originLegend.textContent === 'Precio guardado para esta oferta.', 'H6: reset restores the price legend together with the native fields');
    } finally { dom.window.close(); }
  }
  const menuDom = new JSDOM('<div class="fpw-workspace"><nav><a href="/inbox">Cotizaciones</a><details class="fpw-maintenance-menu"><summary aria-controls="options">Mantenedores</summary><div id="options"><a href="/prices">Precios</a><a href="/sales">Ventas Históricas</a></div></details></nav></div>', {runScripts:'outside-only',url:'https://freeplast.test/'});
  try {
    const {document,Event,MouseEvent,KeyboardEvent} = menuDom.window;
    menuDom.window.eval(source);
    const menu = document.querySelector('details'), summary = menu.querySelector('summary');
    const pointer = (type,pointerType) => { const event=new Event(type); Object.defineProperty(event,'pointerType',{value:pointerType}); menu.dispatchEvent(event); };
    check(!menu.open && summary.getAttribute('aria-expanded')==='false','maintenance starts collapsed');
    pointer('pointerenter','touch');
    check(!menu.open,'touch does not trigger hover opening');
    pointer('pointerenter','mouse');
    check(menu.open,'mouse hover opens maintenance');
    pointer('pointerleave','mouse');
    check(!menu.open,'hover departure closes unpinned menu');
    pointer('pointerenter','mouse');
    const click=new MouseEvent('click',{bubbles:true,cancelable:true}); summary.dispatchEvent(click);
    check(click.defaultPrevented && menu.open,'click on hover-open trigger pins instead of hiding choices');
    pointer('pointerleave','mouse');
    check(menu.open,'pinned menu survives pointer departure');
    menu.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true,cancelable:true}));
    check(!menu.open && document.activeElement===summary,'Escape closes and returns keyboard focus');
    menu.open=true;
    document.body.dispatchEvent(new MouseEvent('click',{bubbles:true}));
    check(!menu.open,'outside click dismisses dropdown');
  } finally { menuDom.window.close(); }
  console.log(`owner workspace JS: ${checks} checks passed`);
  return checks;
}
