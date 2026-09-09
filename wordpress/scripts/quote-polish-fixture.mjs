#!/usr/bin/env node
/** Inert local CSS replay. Real summary JS + PHP lead; modeled state, no server. */
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';
const root = new URL('../', import.meta.url);
const built = JSON.parse(execFileSync(process.execPath, [fileURLToPath(new URL('./layout-cascade-fixture.mjs', import.meta.url))], { encoding: 'utf8' }));
const php = process.env.PHP_BINARY || fileURLToPath(new URL('.tools/php/php', root));
const cartMarkup = execFileSync(php, [fileURLToPath(new URL('./quote-presentation-test.php', import.meta.url)), '--fixture'], { encoding: 'utf8' });
const leadDom = new JSDOM(cartMarkup);
for (const page of built.pages) {
  const dom = new JSDOM(readFileSync(page.path, 'utf8'), { runScripts: 'outside-only' });
  const { window: w } = dom, doc = w.document;
  // No staging/image requests. Photos are explicitly placeholders in this
  // geometry replay; file:// fonts and locally embedded dependency CSS remain.
  doc.querySelector('meta[http-equiv="Content-Security-Policy"]').content = "default-src 'none'; style-src 'unsafe-inline'; img-src data:; font-src file: data:; connect-src 'none'; form-action 'none'; base-uri 'none'";
  for (const img of doc.querySelectorAll('img')) {
    img.src = 'data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><rect width="100" height="100" fill="#f4f4f7"/></svg>');
  }
  if (page.name === 'cart') {
    doc.querySelector('.fp-page-lead').outerHTML = leadDom.window.document.querySelector('.fp-page-lead').outerHTML;
    doc.querySelectorAll('[data-fpw-cart-summary], [data-fpw-cart-next]').forEach(node => node.remove());
    const items = [{ key: 'fixture', quantity: 1 }];
    w.wp = { data: { subscribe() {}, select: () => ({ hasFinishedResolution: () => true, getCartData: () => ({ items }) }) } };
    w.eval(readFileSync(new URL('wp-content/themes/freeplast/assets/js/basket-count.js', root), 'utf8'));
    await new Promise(resolve => w.setTimeout(resolve, 20));
    if (!doc.querySelector('[data-fpw-cart-next]')?.textContent.startsWith('Esta solicitud')) throw Error('Current summary script did not render');
  }
  if (page.name === 'catalog') {
    const cards = doc.querySelectorAll('[data-fpw-loop-add]');
    cards[0].insertAdjacentHTML('beforeend', '<p class="fp-card-add-error" data-fpw-add-error role="alert">No pudimos confirmar el agregado. Revisa Productos a Cotizar antes de volver a agregar.<a href="/cotizacion/"> Revisar selección</a></p>');
    cards[1].insertAdjacentHTML('beforeend', '<p class="fp-card-status" data-fpw-card-status role="status">Producto quitado de Productos a Cotizar.</p>');
  }
  if (page.name === 'checkout') {
    const source = readFileSync(new URL('wp-content/themes/freeplast/woocommerce/checkout/form-checkout.php', root), 'utf8');
    const note = source.match(/<p class="fp-submit-note fp-fine">.*?<\/p>/s)?.[0];
    if (!note) throw Error('Current checkout commercial note missing');
    doc.querySelector('.fp-submit-note').outerHTML = note;
    doc.querySelectorAll('.fp-summary-body > .fp-fine').forEach(node => node.remove());
  }
  writeFileSync(page.path, dom.serialize());
  dom.window.close();
}
leadDom.window.close();
console.log(JSON.stringify(built, null, 2));
