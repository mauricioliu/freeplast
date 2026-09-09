import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM, VirtualConsole } from 'jsdom';

/* Issue #41 — behavioral checks for the A · Directa shared chrome script
 * (assets/js/nav.js) against the REAL header/footer part markup, executed
 * in jsdom. The native <dialog> element owns modal focus containment and
 * the Esc close in a real browser; these checks cover what the script is
 * responsible for: wiring, aria-expanded bookkeeping, dialog replacement,
 * focus return and current-page marking. */
const headerMarkup = readFileSync(new URL('../wp-content/themes/freeplast/parts/header.html', import.meta.url), 'utf8');
const footerMarkup = readFileSync(new URL('../wp-content/themes/freeplast/parts/footer.html', import.meta.url), 'utf8');
const navSource = readFileSync(new URL('../wp-content/themes/freeplast/assets/js/nav.js', import.meta.url), 'utf8');

function resolve(markup) {
  return markup
    .replace(/<!-- wp:\w+ [^>]*-->|<!-- \/wp:\w+ -->/g, '')
    .replace(/\{\{FREEPLAST_THEME_URL\}\}/g, '')
    .replace(/\{\{FREEPLAST_BASKET_COUNT\}\}/g, '2');
}

/* jsdom cannot follow a clicked navigation link; the chrome script must NOT
 * preventDefault on menu links (the browser needs to navigate), so silence
 * only that known virtual-console noise. */
const quietConsole = () => new VirtualConsole().on('jsdomError', () => {});

function boot(path) {
  const dom = new JSDOM(`<!doctype html><html><body>${resolve(headerMarkup)}${resolve(footerMarkup)}</body></html>`, {
    url: 'https://example.test' + path,
    runScripts: 'outside-only',
    pretendToBeVisual: true,
    virtualConsole: quietConsole(),
  });
  shimDialog(dom.window);
  dom.window.eval(navSource);
  dom.window.document.dispatchEvent(new dom.window.Event('DOMContentLoaded', { bubbles: true }));
  return dom;
}

/* jsdom (26) parses <dialog> but ships no showModal/close. Provide the
 * minimal contract nav.js relies on — the open flag and the close event —
 * as a TEST shim. A real browser supplies the modal top layer, focus
 * containment and the Esc close natively; this shim claims none of that. */
function shimDialog(window) {
  const proto = window.HTMLDialogElement.prototype;
  if (typeof proto.showModal === 'function') { return; }
  proto.showModal = function () { this.open = true; };
  proto.close = function () {
    if (!this.open) { return; }
    this.open = false;
    window.setTimeout(() => this.dispatchEvent(new window.Event('close')), 0);
  };
}

const click = (element) => element.dispatchEvent(new element.ownerDocument.defaultView.MouseEvent('click', { bubbles: true, cancelable: true }));
const closeEvents = () => new Promise(resolve => setTimeout(resolve, 5));

export async function runChromeNavTests() {
  let checks = 0;
  const ok = (cond, message) => { checks++; assert.ok(cond, message); };

  /* jsdom ships <dialog>; when an engine lacks showModal the script must
     stay inert rather than half-wire — asserted separately below. */
  const dom = boot('/tienda/');
  const doc = dom.window.document;
  ok(doc.getElementById('fp-menu') instanceof dom.window.HTMLDialogElement && doc.getElementById('fp-help') instanceof dom.window.HTMLDialogElement, 'the chrome dialogs are native <dialog> elements');

  const desktopItems = [...doc.querySelectorAll('.desktop-nav a, .desktop-nav button')];
  const mobileItems = [...doc.querySelectorAll('.menu-dialog nav a, .menu-dialog nav button')];
  const destination = el => el.getAttribute('href') || '#' + el.getAttribute('data-fp-dialog');
  const label = el => el.textContent.replace('→', '').trim();
  ok(JSON.stringify(desktopItems.map(destination)) === JSON.stringify(mobileItems.slice(0, 4).map(destination)), 'desktop and mobile share destinations and order');
  ok(JSON.stringify(desktopItems.map(label)) === JSON.stringify(mobileItems.slice(0, 4).map(label)), 'desktop and mobile share labels, including Cómo cotizar');
  ok(desktopItems.some(el => el.getAttribute('href') === '/nosotros/'), 'Nosotros is not mobile-only');
  ok(doc.querySelector('.header-selection').getAttribute('href') === mobileItems.at(-1).getAttribute('href'), 'the separate desktop selection CTA matches the mobile selection destination');

  const menuTrigger = doc.querySelector('.menu-trigger');
  const menu = doc.getElementById('fp-menu');
  const help = doc.getElementById('fp-help');

  click(menuTrigger);
  ok(menu.open === true, 'the menu trigger opens the menu dialog through showModal');
  ok(menuTrigger.getAttribute('aria-expanded') === 'true', 'the trigger exposes aria-expanded=true while open');
  click(menu.querySelector('[data-fp-close]'));
  await closeEvents();
  ok(menu.open === false, 'the named close control closes the menu');
  ok(menuTrigger.getAttribute('aria-expanded') === 'false', 'closing restores aria-expanded=false');
  ok(doc.activeElement === menuTrigger, 'focus returns to the control that opened the dialog');

  /* Navigation links close the dialog before the browser follows them. */
  click(menuTrigger);
  click(menu.querySelector('nav a[href="/contacto/"]'));
  await closeEvents();
  ok(menu.open === false, 'following a menu navigation link closes the menu first');

  /* Help opens from every shared surface: desktop nav, footer, menu row. */
  const navHelp = doc.querySelector('.desktop-nav [data-fp-dialog="fp-help"]');
  click(navHelp);
  ok(help.open === true, 'the desktop nav help control opens the help dialog');
  click(help.querySelector('[data-fp-close]'));
  await closeEvents();
  ok(help.open === false && doc.activeElement === navHelp, 'closing help returns focus to the desktop nav control');

  const footerHelp = doc.querySelector('.fp-help-trigger');
  click(footerHelp);
  ok(help.open === true, 'the footer help control opens the same shared dialog');
  click(help.querySelector('[data-fp-close]'));
  await closeEvents();

  click(menuTrigger);
  const menuHelpRow = menu.querySelector('[data-fp-dialog="fp-help"]');
  click(menuHelpRow);
  ok(help.open === true && menu.open === false, 'help from inside the menu replaces the menu — only one chrome dialog open');
  help.querySelector('[data-fp-close]').focus();
  await closeEvents();
  ok(menuTrigger.getAttribute('aria-expanded') === 'false', 'the outgoing menu no longer claims expansion while help is open');
  ok(doc.activeElement === help.querySelector('[data-fp-close]'), 'queued outgoing close does not steal help focus');
  click(help.querySelector('[data-fp-close]'));
  await closeEvents();
  ok(doc.activeElement === menuTrigger, 'closing replacement help returns to the visible menu trigger');
  click(menuTrigger);
  menu.close();
  click(menuTrigger);
  await closeEvents();
  ok(menu.open && menuTrigger.getAttribute('aria-expanded') === 'true', 'an old queued close cannot clear a reopened menu');
  menu.close();
  await closeEvents();

  /* Current-page marking (the reference keeps aria-current on the active
     destination; Home never marks a nav link). */
  ok(doc.querySelector('.desktop-nav a[href="/tienda/"]').getAttribute('aria-current') === 'page', 'Catálogo is marked current on a catalog route');
  ok(doc.querySelector('.menu-dialog nav a[href="/tienda/"]').getAttribute('aria-current') === 'page', 'the mobile menu marks the same destination');
  ok(doc.querySelector('.desktop-nav a[href="/contacto/"]').getAttribute('aria-current') === null, 'other destinations stay unmarked');
  ok(doc.querySelector('.brand').getAttribute('aria-current') === null, 'the brand never carries aria-current');

  const about = boot('/nosotros/');
  ok(about.window.document.querySelector('.desktop-nav a[href="/nosotros/"]').getAttribute('aria-current') === 'page', 'Nosotros is current on desktop');
  ok(about.window.document.querySelector('.menu-dialog nav a[href="/nosotros/"]').getAttribute('aria-current') === 'page', 'Nosotros is current on mobile');
  about.window.close();

  const home = boot('/');
  ok(home.window.document.querySelector('.desktop-nav a[href="/tienda/"]').getAttribute('aria-current') === null, 'no nav link is marked current on Home');

  /* A product route marks Catálogo current through Woo's native
     single-product body class, mirroring the reference's combined
     catalog/product marking. */
  const product = boot('/producto/caja-cosechera-3-4/');
  ok(product.window.document.querySelector('.desktop-nav a[href="/tienda/"]').getAttribute('aria-current') === null, 'without the single-product body class nothing is marked');
  const productMarked = new JSDOM(`<!doctype html><body class="single-product">${resolve(headerMarkup)}</body>`, { url: 'https://example.test/producto/caja-cosechera-3-4/', runScripts: 'outside-only', pretendToBeVisual: true });
  shimDialog(productMarked.window);
  productMarked.window.eval(navSource);
  productMarked.window.document.dispatchEvent(new productMarked.window.Event('DOMContentLoaded', { bubbles: true }));
  ok(productMarked.window.document.querySelector('.desktop-nav a[href="/tienda/"]').getAttribute('aria-current') === 'page', 'a product route marks Catálogo current through the native body class');
  ok(productMarked.window.document.querySelector('.brand').getAttribute('aria-current') === null, 'the brand never carries aria-current');

  /* Without native dialog support the script must stay completely inert. */
  const inert = new JSDOM(`<!doctype html><body>${resolve(headerMarkup)}</body>`, { url: 'https://example.test/tienda/', runScripts: 'outside-only', pretendToBeVisual: true });
  delete inert.window.HTMLDialogElement.prototype.showModal;
  inert.window.eval(navSource);
  inert.window.document.dispatchEvent(new inert.window.Event('DOMContentLoaded', { bubbles: true }));
  const inertTrigger = inert.window.document.querySelector('.menu-trigger');
  click(inertTrigger);
  ok(inertTrigger.getAttribute('aria-expanded') === 'false', 'without showModal the trigger never claims an open menu');
  ok(inert.window.document.getElementById('fp-menu').open === false, 'without showModal no dialog is force-opened');

  for (const instance of [dom, home, product, productMarked, inert]) { instance.window.close(); }
  console.log(`chrome nav: ${checks} A · Directa chrome script checks passed (native dialogs, aria-expanded, dialog replacement, focus return, current-page marking)`);
  return checks;
}
