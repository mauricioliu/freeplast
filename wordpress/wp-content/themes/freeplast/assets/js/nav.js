/* Native dialog provides modal focus containment; no parallel focus-trap implementation. */
(function () {
  'use strict';
  var burger = document.querySelector('[data-fp-burger]');
  var dialog = document.querySelector('[data-fp-sheet]');
  if (!burger || !dialog || !dialog.showModal) return;
  function close() { dialog.close(); }
  burger.addEventListener('click', function () { dialog.showModal(); burger.setAttribute('aria-expanded', 'true'); });
  dialog.querySelector('[data-fp-close]').addEventListener('click', close);
  dialog.addEventListener('close', function () { burger.setAttribute('aria-expanded', 'false'); burger.focus(); });
  dialog.querySelectorAll('a').forEach(function (link) { link.addEventListener('click', close); });
  document.querySelectorAll('nav a').forEach(function (link) {
    if (new URL(link.href).pathname === location.pathname) link.setAttribute('aria-current', 'page');
  });
})();
