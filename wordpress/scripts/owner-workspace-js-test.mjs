import {JSDOM} from 'jsdom';
import {readFileSync} from 'node:fs';

export function runOwnerWorkspaceJsTests() {
  let checks = 0;
  const check = (ok, message) => { if (!ok) throw new Error(message); checks++; };
  const source = readFileSync(new URL('../wp-content/plugins/freeplast-woo/assets/owner-workspace.js', import.meta.url), 'utf8');
  // No workspace script: native click must reach PHP despite invalid min/max fields.
  for (const action of ['main', 'mobile']) {
    const dom = new JSDOM('<form id="edit" novalidate><input type="number" min="1" value="0"><input type="number" max="365" value="366"><button id="main">Revisar</button></form><button id="mobile" form="edit">Revisar móvil</button>');
    try {
      const doc = dom.window.document, form = doc.querySelector('form');
      let submitted = false;
      form.addEventListener('submit', event => { event.preventDefault(); submitted = true; });
      doc.getElementById(action).click();
      check(submitted && !form.checkValidity(), `${action}: no-JS native click submits all invalid values for aggregate server validation`);
      submitted = false; form.noValidate = false; doc.getElementById(action).click();
      check(!submitted, 'regression control: native constraints otherwise intercept before server validation');
    } finally { dom.window.close(); }
  }
  for (const dispatch of [false, true]) {
    for (const action of ['main', 'mobile']) {
      const dom = new JSDOM(`<div class="fpw-workspace"><form id="edit" data-fpw-work data-fpw-calculation='${JSON.stringify({dispatch, tax:{rate_permille:190}, savedAmount:20, conditions:dispatch ? {destination:'Calle 1', quantities:[2]} : null})}'>
        <input data-fpw-review-field type="number" name="fpw_work[lines][0][quantity]" value="0">
        <input data-fpw-review-field type="number" name="fpw_work[lines][0][price]" value="">
        ${dispatch ? '<textarea data-fpw-review-field name="fpw_work[destination]"></textarea><input data-fpw-review-field type="number" name="fpw_work[dispatch_amount]" value="">' : '<input data-fpw-review-field type="radio" name="fpw_work[pickup_location]" value="mostazal"><input data-fpw-review-field type="radio" name="fpw_work[pickup_location]" value="los-paltos">'}
        <input data-fpw-review-field type="number" name="fpw_work[validity_days]" value="366">
        <button id="main" name="fpw_work_preview">Revisar</button><button id="save" name="fpw_work_save">Guardar</button></form>
        <button id="mobile" form="edit" name="fpw_work_preview">Revisar móvil</button></div>`, {runScripts:'outside-only'});
      try {
        dom.window.TextEncoder = TextEncoder;
        dom.window.eval(source);
        const doc = dom.window.document, form = doc.querySelector('form');
        const submit = id => { const event = new dom.window.SubmitEvent('submit', {bubbles:true, cancelable:true, submitter:doc.getElementById(id)}); form.dispatchEvent(event); return event; };
        check(submit(action).defaultPrevented && doc.querySelectorAll('[aria-invalid="true"]').length === 5, `${action}: every missing field marked, review blocked`);
        check(doc.activeElement.name.endsWith('[quantity]') && doc.activeElement.validationMessage.includes('cantidad'), `${action}: first invalid field focused with native floating help`);
        check(!submit('save').defaultPrevented, 'incomplete draft still submits explicit save');
        const set = (suffix, value) => { const field = form.elements.namedItem(`fpw_work${suffix}`); field.value = value; field.dispatchEvent(new dom.window.Event('input', {bubbles:true})); };
        set('[lines][0][quantity]', '1'); set('[lines][0][price]', '10'); set('[validity_days]', '');
        if (dispatch) {
          set('[destination]', 'Calle 1');
          for (const amount of ['20', '020', '00000020']) {
            set('[dispatch_amount]', amount);
            check(submit(action).defaultPrevented && form.elements.namedItem('fpw_work[dispatch_amount]').validationMessage.includes('Revisa el monto'), 'numerically unchanged freight blocks stale review, including leading zeros');
          }
          set('[dispatch_amount]', '21');
        }
        else { const radio = form.querySelector('[type="radio"]'); radio.checked = true; radio.dispatchEvent(new dom.window.Event('change', {bubbles:true})); }
        check(!doc.querySelector('[aria-invalid="true"]') && !submit(action).defaultPrevented, 'correction clears all errors; blank validity keeps default and permits review');
      } finally { dom.window.close(); }
    }
  }
  for (const existingNotice of ['', '<div class="fpw-workspace-notice" role="alert" tabindex="-1" data-fpw-review-error>Falta IVA</div>']) {
    const dom = new JSDOM(`<div class="fpw-workspace">${existingNotice}<form data-fpw-work data-fpw-calculation='{"tax":[]}'><input data-fpw-review-field name="fpw_work[lines][0][price]" value="10"><button name="fpw_work_preview">Revisar</button></form></div>`, {runScripts:'outside-only'});
    try {
      dom.window.eval(source);
      const doc = dom.window.document, form = doc.querySelector('form');
      const event = new dom.window.SubmitEvent('submit', {bubbles:true, cancelable:true, submitter:doc.querySelector('button')}); form.dispatchEvent(event);
      check(event.defaultPrevented && !doc.querySelector('[aria-invalid="true"]') && doc.activeElement === doc.querySelector('[data-fpw-review-error]') && doc.activeElement.textContent.includes('política fiscal'), 'new or server-rendered fiscal notice receives focus without field blame');
    } finally { dom.window.close(); }
  }
  for (const saved of ['0', '1']) {
    const dom = new JSDOM(`<div class="fpw-workspace">
      <form id="fpw-work-form" data-fpw-work data-fpw-has-saved="${saved}" data-fpw-calculation='{"tax":{"rate_permille":190,"applies_to_dispatch":false},"dispatch":false}' ><input name="fpw_work[lines][0][quantity]" value="100"><input name="fpw_work[lines][0][price]" value="1500"></form>
      <p data-fpw-pallets="0" data-units="70">1 pallet completo + 30 un.</p>
      <p data-fpw-origin="0">Precio guardado para esta oferta.</p><strong data-fpw-line-total="0">150.000 CLP</strong><dd data-fpw-amount="total">178.500 CLP</dd>
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
      check(save.hidden && !preview.hidden && !preview.disabled, 'dock always offers review, including a new draft');
      check(discard.hidden, 'discard is hidden while clean');
      const quantity = form.elements.namedItem('fpw_work[lines][0][quantity]');
      quantity.value = '350'; quantity.dispatchEvent(new Event('input', {bubbles:true}));
      check(save.hidden && !preview.hidden && !preview.disabled && !discard.hidden, 'dirty draft can be reviewed without a separate save');
      check(document.querySelector('[data-fpw-dock-total]').textContent === '624.750 CLP' && price.value === '1500', 'quantity edit estimates total without changing chosen price');
      check(document.querySelector('[data-fpw-amount="total"]').textContent === '624.750 CLP' && document.querySelector('[data-fpw-line-total]').textContent === '525.000 CLP', 'summary and line totals update with dock');
      const attemptedReview = new dom.window.SubmitEvent('submit', {bubbles:true, cancelable:true, submitter:preview});
      form.dispatchEvent(attemptedReview);
      check(!attemptedReview.defaultPrevented, 'review submits current edits for server-side save and validation');
      const reviewPosted = new FormData(form, preview);
      check(reviewPosted.get('fpw_work_preview') === '1' && reviewPosted.get('fpw_work[lines][0][quantity]') === '350', 'review includes the current edited fields');
      const posted = new FormData(form, save);
      check(posted.get('fpw_work_save') === '1' && posted.get('fpw_work[lines][0][quantity]') === '350' && !posted.has('fpw_work_preview'), 'external dock button submits the native form and only its explicit action');
      const distance = document.querySelector('[data-distance-test]');
      const otherSubmit = new Event('submit', {bubbles:true, cancelable:true});
      distance.dispatchEvent(otherSubmit);
      check(otherSubmit.defaultPrevented && distance.querySelector('[role="alert"]'), 'another form cannot silently discard quotation edits');
      form.reset();
      check(quantity.value === '100' && price.value === '1500' && preview.disabled === false, 'reset restores native fields and review availability');
      check(discard.hidden && document.querySelector('[data-fpw-dock-total]').textContent === '178.500 CLP', 'reset restores saved total and clears discard');
      check(save.hidden && !preview.hidden, 'reset keeps review as the dock action');
      const small = document.querySelector('[data-price="2400"]');
      const bulk = document.querySelector('[data-price="1900"]');
      check(!small.hidden && !bulk.hidden, 'both reference choices are progressively enhanced');
      small.click();
      check(price.value === '2400' && !preview.disabled && document.activeElement === price, 'reference price can be reviewed directly');
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
  for (const unsaved of [false, true]) {
    const dom = new JSDOM(`<div class="fpw-workspace">
      <form data-fpw-work data-fpw-has-saved="0" ${unsaved ? 'data-fpw-unsaved="1"' : ''}><input name="fpw_work[lines][0][price]" value=""></form>
      <p data-fpw-origin="0" hidden></p><p data-fpw-save-state></p>
      <button hidden data-fpw-apply-price="0" data-price="1300">Usar precio</button>
      <button data-fpw-preview>Revisar</button>
    </div>`, {runScripts:'outside-only', url:'https://freeplast.test/'});
    try {
      const {document, Event} = dom.window;
      dom.window.eval(source);
      const legend = document.querySelector('[data-fpw-origin]');
      const form = document.querySelector('form');
      const price = form.elements.namedItem('fpw_work[lines][0][price]');
      check(legend.hidden === !unsaved, 'pending origin hidden unless server reports unsaved work');
      document.querySelector('[data-fpw-apply-price]').click();
      check(price.value === '1300' && !legend.hidden && legend.textContent === 'Cambio sin guardar.', 'reference selection reveals unsaved feedback without packaging node');
      check(document.querySelector('[data-fpw-save-state]').textContent.includes('Cambios sin guardar') && !document.querySelector('[data-fpw-preview]').disabled, 'unsaved feedback does not block review');
      price.value = ''; price.dispatchEvent(new Event('input', {bubbles:true}));
      check(unsaved ? !legend.hidden && legend.textContent === 'Cambio sin guardar.' : legend.hidden && legend.textContent === '', 'returning to rejected fields never claims saved origin');
      price.value = '1400'; price.dispatchEvent(new Event('input', {bubbles:true}));
      form.reset();
      check(legend.hidden === !unsaved && price.value === '', 'reset restores native fields without clearing rejected-POST state');
      if (unsaved) check(!document.querySelector('[data-fpw-preview]').disabled, 'rejected POST can retry server validation through review');
    } finally { dom.window.close(); }
  }
  const live = (config = {}, count = 2) => {
    const dom = new JSDOM(`<div class="fpw-workspace"><form data-fpw-work data-fpw-has-saved="1" data-fpw-calculation='${JSON.stringify({tax:{rate_permille:190, applies_to_dispatch:false},dispatch:false,...config})}'>
      ${Array.from({length:count}, (_, i) => `<input name="fpw_work[lines][${i}][quantity]" value="1"><input name="fpw_work[lines][${i}][price]" value="1"><strong data-fpw-line-total="${i}">Guardado</strong>`).join('')}
      <input name="fpw_work[dispatch_amount]" value="10"><textarea name="fpw_work[destination]">Calle 1</textarea><input name="fpw_work[validity_days]" value="7"></form>
      <p data-fpw-save-state>Guardado</p><p data-fpw-minimum="0" data-minimum="70">Mínimo guardado</p><p data-fpw-minimum="1" data-minimum="">Mínimo por confirmar</p>
      <span data-fpw-line-label>Neto guardado</span><span data-fpw-total-label>Total guardado</span><p data-fpw-dispatch-warning hidden></p>
      ${['subtotal','dispatch','tax','total'].map(key => `<dd data-fpw-amount="${key}">Guardado</dd>`).join('')}
      <strong data-fpw-dock-total>Guardado</strong><button data-fpw-preview disabled>Bloqueado previamente</button></div>`, {runScripts:'outside-only'});
    dom.window.TextEncoder = TextEncoder;
    dom.window.eval(source);
    const form = dom.window.document.querySelector('form');
    const set = (name, value) => { const field = form.elements.namedItem(`fpw_work${name}`); field.value = value; field.dispatchEvent(new dom.window.Event('input', {bubbles:true})); };
    const text = selector => dom.window.document.querySelector(selector).textContent;
    const amount = key => text(`[data-fpw-amount="${key}"]`);
    return {dom, form, set, text, amount};
  };
  for (const [rate, price, tax] of [[190,'49','10 CLP'],[190,'48','9 CLP'],[0,'49','0 CLP'],[5000,'49','250 CLP']]) {
    const t = live({tax:{rate_permille:rate,applies_to_dispatch:false}});
    try {
      t.set('[lines][0][price]',price);
      check(t.amount('tax') === tax, 'multi-line tax uses half-up rounding at confirmed rate');
      check(t.amount('total') === t.text('[data-fpw-dock-total]'), 'dock equals summary estimate');
      check(t.text('[data-fpw-save-state]').includes('estimados'), 'dirty money explicitly estimated');
      t.form.reset();
      check(t.amount('total') === 'Guardado' && t.text('[data-fpw-line-label]') === 'Neto guardado', 'reset restores actual server text, not calculated suggestion');
      check(t.dom.window.document.querySelector('[data-fpw-preview]').disabled, 'reset preserves previously disabled action');
    } finally { t.dom.window.close(); }
  }
  for (const tax of [{}, {rate_permille:5001}, {rate_permille:-1}, {rate_permille:'190'}]) {
    const t = live({tax});
    try { t.set('[lines][0][price]','49'); check(t.amount('subtotal') === '50 CLP' && t.amount('tax') === 'Por definir' && t.amount('total') === 'Por definir', 'absent/invalid policy never invents IVA'); }
    finally { t.dom.window.close(); }
  }
  for (const field of ['quantity','price']) {
    for (const value of ['', '0', '-1', '1.5', 'NaN', '1e2', field === 'quantity' ? '1000001' : '100000000', '000000001']) {
      const t = live();
      try {
        t.set(`[lines][0][${field}]`,value);
        check(t.amount('total') === 'Por definir' && t.text('[data-fpw-line-total="0"]') === 'Por definir', `invalid ${field} ${value} stays pending`);
      } finally { t.dom.window.close(); }
    }
  }
  const max = live({tax:{rate_permille:5000,applies_to_dispatch:true}}, 100);
  try {
    for (let i=0;i<100;i++) { max.form.elements.namedItem(`fpw_work[lines][${i}][quantity]`).value='1000000'; max.form.elements.namedItem(`fpw_work[lines][${i}][price]`).value='99999999'; }
    max.set('[lines][0][price]','99999999');
    check(max.amount('subtotal') === '9.999.999.900.000.000 CLP' && max.amount('total') === '59.999.999.400.000.000 CLP', 'maximum multi-line accumulation remains exact above Number safe range');
  } finally { max.dom.window.close(); }
  for (const taxed of [false,true]) {
    const t = live({dispatch:true,savedAmount:10,conditions:{destination:'Calle 1',quantities:[1,1]},tax:{rate_permille:190,applies_to_dispatch:taxed}});
    try {
      t.set('[lines][0][price]','49');
      check(t.amount('tax') === (taxed ? '11 CLP' : '10 CLP') && t.amount('total') === (taxed ? '71 CLP' : '70 CLP'), 'freight taxed only by explicit policy');
      t.set('[lines][0][quantity]','70');
      check(t.amount('dispatch') === 'Por definir' && t.amount('total') === 'Por definir' && t.text('[data-fpw-dispatch-warning]').includes('requiere revisión'), 'quantity edit invalidates old freight basis');
      check(t.text('[data-fpw-minimum="0"]').includes('cubierto') && t.text('[data-fpw-minimum="1"]') === 'Mínimo por confirmar', 'minimum crossing updates known warning, unknown remains');
      t.set('[dispatch_amount]','11');
      check(t.amount('dispatch') === '11 CLP' && t.amount('total') !== 'Por definir', 'changed freight establishes estimated basis as future save would');
      t.set('[dispatch_amount]','10');
      check(t.amount('total') === 'Por definir', 'returning to old freight amount re-exposes mismatch');
      t.set('[lines][0][quantity]','1');
      check(t.amount('total') !== 'Por definir', 'returning quantities to saved basis restores freight estimate');
      t.set('[destination]','Calle 2');
      check(t.amount('total') === 'Por definir', 'destination edit invalidates old basis');
      t.set('[dispatch_amount]','12');
      check(t.amount('total') !== 'Por definir', 'new amount covers edited destination estimate');
      for (const destination of ['', '<b>Calle 2</b>', ' Calle 2 ', 'é'.repeat(401)]) {
        t.set('[destination]',destination); check(t.amount('total') === 'Por definir', 'uncertain or oversized destination never pretends confirmed');
      }
      t.set('[destination]','Calle 2');
      for (const freight of ['', '0', '100000000']) { t.set('[dispatch_amount]',freight); check(t.amount('total') === 'Por definir', 'missing/invalid freight never zero'); }
      t.form.reset();
      check(t.amount('dispatch') === 'Guardado' && t.dom.window.document.querySelector('[data-fpw-dispatch-warning]').hidden, 'reset restores saved freight and warning visibility');
    } finally { t.dom.window.close(); }
  }
  const fresh = live({dispatch:true,savedAmount:10,conditions:null});
  try { fresh.set('[lines][0][quantity]','2'); check(fresh.amount('dispatch') === '10 CLP', 'absent saved basis can be established on next save'); }
  finally { fresh.dom.window.close(); }
  const validity = live();
  try {
    validity.set('[lines][0][price]','49');
    for (const days of ['0','366','no']) { validity.set('[validity_days]',days); check(validity.amount('total') === 'Por definir', 'invalid validity blocks estimate'); }
    validity.set('[validity_days]',''); check(validity.amount('total') === '60 CLP', 'blank validity uses server default and no fictitious freight required');
    validity.set('[validity_days]','7'); validity.set('[lines][0][price]','1');
    check(validity.amount('total') === 'Guardado', 'returning all values restores original saved totals');
  } finally { validity.dom.window.close(); }
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
