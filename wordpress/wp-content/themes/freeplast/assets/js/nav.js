/* Shared chrome of the A · Directa quote journey (#41): the persistent
   header's mobile menu and the quotation help dialog are native <dialog>
   elements — the browser owns modal focus containment and the Esc close.
   Focus returns to the exact control that opened the dialog. No parallel
   focus-trap implementation, no prototype switcher/scenario shortcuts. */
(function () {
  'use strict';

  var CHROME_SELECTOR = 'dialog.fp-chrome';
  var OPEN_ATTR = 'data-fp-dialog';
  var returnTargets = new WeakMap();
  var initialized = false;

  function expanded(trigger, value) {
    if (trigger && trigger.hasAttribute('aria-expanded')) {
      trigger.setAttribute('aria-expanded', value);
    }
  }

  function openDialog(dialog, trigger) {
    if (!dialog || typeof dialog.showModal !== 'function') { return; }
    if (dialog.open) { return; }
    var returnTarget = trigger || null;
    /* Native close events are queued. Retire the outgoing dialog's target
       BEFORE closing it; its later event must not steal the new modal's focus.
       Help opened inside the menu returns to the visible menu trigger, not
       to a now-inert descendant of the closed menu. */
    document.querySelectorAll(CHROME_SELECTOR).forEach(function (other) {
      if (other === dialog || !other.open) { return; }
      var previous = returnTargets.get(other);
      if (trigger && other.contains(trigger)) { returnTarget = previous || null; }
      returnTargets.delete(other);
      expanded(previous, 'false');
      other.close();
    });
    returnTargets.set(dialog, returnTarget);
    expanded(trigger, 'true');
    dialog.showModal();
  }

  function wireDialog(dialog) {
    dialog.addEventListener('close', function () {
      // An old queued close can arrive after this same dialog was reopened.
      if (dialog.open) { return; }
      var trigger = returnTargets.get(dialog);
      returnTargets.delete(dialog);
      expanded(trigger, 'false');
      if (trigger && document.contains(trigger)) { trigger.focus(); }
    });
    var closer = dialog.querySelector('[data-fp-close]');
    if (closer) {
      closer.addEventListener('click', function () { dialog.close(); });
    }
    /* Navigation links close the dialog before the browser follows them. */
    dialog.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', function () { dialog.close(); });
    });
  }

  function init() {
    if (initialized) { return; }
    initialized = true;
    document.querySelectorAll('.fp-catalog-menu').forEach(function (menu) {
      var summary = menu.querySelector('summary');
      var hoverOpened = false;
      var close = function () { menu.open = false; hoverOpened = false; };
      menu.addEventListener('pointerenter', function (event) {
        if (event.pointerType === 'mouse' && menu.closest('.desktop-nav') && !menu.open) { menu.open = true; hoverOpened = true; }
      });
      menu.addEventListener('pointerleave', function (event) {
        if (event.pointerType === 'mouse' && hoverOpened && !menu.contains(document.activeElement)) { close(); }
      });
      summary.addEventListener('click', function (event) {
        if (hoverOpened) { event.preventDefault(); hoverOpened = false; }
      });
      menu.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && menu.open) { event.preventDefault(); event.stopPropagation(); close(); summary.focus(); }
      });
      menu.addEventListener('focusout', function () {
        setTimeout(function () { if (!menu.contains(document.activeElement)) { close(); } }, 0);
      });
      document.addEventListener('click', function (event) { if (!menu.contains(event.target)) { close(); } });
      menu.querySelectorAll('a').forEach(function (link) { link.addEventListener('click', close); });
    });
    var dialogs = document.querySelectorAll(CHROME_SELECTOR);
    if (dialogs.length === 0) { return; }
    dialogs.forEach(wireDialog);
    document.addEventListener('click', function (event) {
      var trigger = event.target.closest('[' + OPEN_ATTR + ']');
      if (!trigger) { return; }
      var dialog = document.getElementById(trigger.getAttribute(OPEN_ATTR));
      if (!dialog) { return; }
      event.preventDefault();
      openDialog(dialog, trigger);
    });
    /* Current-page marking on the shared navigation (the reference keeps
       aria-current="page" on the active destination; its catalog marking
       covers catalog AND product views, which WooCommerce exposes natively
       as the single-product body class). Home never marks a nav link: the
       brand is the home destination. */
    var isProduct = document.body.classList.contains('single-product');
    document.querySelectorAll('.desktop-nav a, .menu-dialog nav a').forEach(function (link) {
      try {
        var target = new URL(link.href).pathname.replace(/\/+$/, '') || '/';
        var here = location.pathname.replace(/\/+$/, '') || '/';
        var current = (target === here && here !== '/') || (target === '/tienda' && isProduct);
        if (current) { link.setAttribute('aria-current', 'page'); }
        var category = document.body.classList.contains('tax-product_cat');
        var catalogMenu = link.closest('.fp-catalog-menu');
        if (catalogMenu && (current || isProduct || category)) {
          catalogMenu.querySelector('summary').classList.add('is-current');
          if (current && category && catalogMenu.closest('.menu-dialog')) { catalogMenu.open = true; }
        }
      } catch (err) { /* non-URL hrefs stay unmarked */ }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
