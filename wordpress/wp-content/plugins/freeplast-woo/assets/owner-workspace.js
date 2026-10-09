(() => {
  'use strict';
  const root = document.querySelector('.fpw-workspace');
  if (!root) return;
  // Native details remains click/keyboard operable without JavaScript.
  const maintenance = root.querySelector('.fpw-maintenance-menu');
  if (maintenance) {
    const trigger = maintenance.querySelector('summary');
    let hoverOpened = false;
    const closeMenu = () => { maintenance.open = false; hoverOpened = false; };
    maintenance.addEventListener('pointerenter', event => {
      if (event.pointerType !== 'mouse' || maintenance.open) return;
      maintenance.open = true;
      hoverOpened = true;
    });
    maintenance.addEventListener('pointerleave', event => {
      if (event.pointerType === 'mouse' && hoverOpened && !maintenance.contains(document.activeElement)) closeMenu();
    });
    trigger.addEventListener('click', event => {
      // Clicking a hover-open trigger pins it instead of immediately closing it.
      if (hoverOpened) { event.preventDefault(); hoverOpened = false; }
    });
    maintenance.addEventListener('toggle', () => trigger.setAttribute('aria-expanded', String(maintenance.open)));
    trigger.setAttribute('aria-expanded', String(maintenance.open));
    document.addEventListener('click', event => { if (!maintenance.contains(event.target)) closeMenu(); });
    maintenance.addEventListener('keydown', event => {
      if (event.key === 'Escape' && maintenance.open) {
        event.preventDefault(); closeMenu(); trigger.focus();
      }
    });
    maintenance.addEventListener('focusout', () => {
      setTimeout(() => { if (!maintenance.contains(document.activeElement)) closeMenu(); }, 0);
    });
  }
  const work = root.querySelector('[data-fpw-work]');
  let workDirty = false, submitted = false;
  const status = root.querySelector('[data-fpw-save-state]');
  const savedStatus = status?.textContent;
  const dockTotal = root.querySelector('[data-fpw-dock-total]');
  const savedTotal = dockTotal?.textContent;
  const syncPallets = () => {
    root.querySelectorAll('[data-fpw-pallets]').forEach(note => {
      const units = Number(note.dataset.units);
      const field = work?.elements.namedItem(`fpw_work[lines][${note.dataset.fpwPallets}][quantity]`);
      if (!units || !field) return;
      const quantity = Number(field.value);
      if (!/^\d+$/.test(field.value) || !Number.isSafeInteger(quantity) || quantity < 1) {
        note.textContent = 'Ingresa una cantidad entera para ver la equivalencia en pallets.';
        return;
      }
      const full = Math.floor(quantity / units);
      note.textContent = `${full} ${full === 1 ? 'pallet completo' : 'pallets completos'} + ${quantity % units} un. · ${units} un. por pallet`;
    });
  };
  const syncActions = () => {
    const reviewReady = !workDirty && work?.dataset.fpwHasSaved === '1';
    const save = root.querySelector('[data-fpw-dock-save]');
    const preview = root.querySelector('[data-fpw-dock-preview]');
    if (save) save.hidden = reviewReady;
    if (preview) preview.hidden = !reviewReady;
    root.querySelectorAll('[data-fpw-discard]').forEach(button => { button.hidden = !workDirty; });
  };
  /* H6 (2026-10-03 review): the per-line price legend must not keep claiming
     «Precio guardado…» while the owner has typed or reference-applied a new
     value. The legend switches to «Cambio sin guardar.» against the field's
     own native defaultValue (the saved offer price the server rendered) — no
     second money source — and the form's reset restores both field and legend. */
  const PRICE_FIELD = /^fpw_work\[lines\]\[(\d+)\]\[price\]$/;
  const syncPriceOrigin = (field) => {
    const match = field && PRICE_FIELD.exec(String(field.name || ''));
    if (!match) { return; }
    const legend = root.querySelector(`[data-fpw-origin="${match[1]}"]`);
    if (!legend) { return; }
    if (legend.dataset.fpwSavedLegend === undefined) { legend.dataset.fpwSavedLegend = legend.textContent; }
    legend.textContent = field.value === field.defaultValue ? legend.dataset.fpwSavedLegend : 'Cambio sin guardar.';
  };
  const resetPriceOrigins = () => {
    root.querySelectorAll('[data-fpw-origin]').forEach(legend => {
      if (legend.dataset.fpwSavedLegend !== undefined) { legend.textContent = legend.dataset.fpwSavedLegend; }
    });
  };
  work?.addEventListener('reset', () => {
    setTimeout(syncPallets, 0);
    workDirty = false;
    if (status) status.textContent = savedStatus;
    if (dockTotal) dockTotal.textContent = savedTotal;
    root.querySelector('#fpw-summary')?.classList.remove('has-unsaved');
    root.querySelectorAll('[data-fpw-preview],[data-fpw-refresh]').forEach(button => { button.disabled = false; });
    root.querySelectorAll('[data-fpw-unsaved-warning]').forEach(notice => notice.remove());
    resetPriceOrigins();
    syncActions();
  });
  const setWorkDirty = () => {
    workDirty = true;
    if (status) status.textContent = 'Cambios sin guardar. Guarda para actualizar los importes.';
    root.querySelector('#fpw-summary')?.classList.add('has-unsaved');
    const total = root.querySelector('[data-fpw-dock-total]');
    if (total) total.textContent = 'Sin guardar';
    root.querySelectorAll('[data-fpw-preview],[data-fpw-refresh]').forEach(button => { button.disabled = true; });
    syncActions();
  };
  if (work?.dataset.fpwUnsaved === '1') {
    setWorkDirty();
    // The server-rendered unsaved screen carries rejected posted prices: every
    // legend must admit those values are not the saved ones until reset/save.
    root.querySelectorAll('[data-fpw-origin]').forEach(legend => {
      if (legend.dataset.fpwSavedLegend === undefined) { legend.dataset.fpwSavedLegend = legend.textContent; }
      legend.textContent = 'Cambio sin guardar.';
    });
  }
  else syncActions();
  root.querySelector('[data-fpw-discard-work]')?.addEventListener('click', () => { submitted = true; });
  work?.addEventListener('input', (event) => { syncPallets(); setWorkDirty(); syncPriceOrigin(event.target); });
  work?.addEventListener('change', (event) => { setWorkDirty(); syncPriceOrigin(event.target); });
  root.querySelectorAll('[data-fpw-apply-price]').forEach(button => {
    button.hidden = false;
    button.addEventListener('click', () => {
      const index = button.dataset.fpwApplyPrice;
      const field = work?.elements.namedItem(`fpw_work[lines][${index}][price]`);
      if (!field || field.matches(':disabled')) return;
      field.value = button.dataset.price;
      field.dispatchEvent(new Event('input', {bubbles:true}));
      field.focus();
    });
  });
  root.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', event => {
      if (workDirty && form !== work) {
        event.preventDefault();
        let notice = form.querySelector('[data-fpw-unsaved-warning]');
        if (!notice) {
          notice = document.createElement('p');
          notice.dataset.fpwUnsavedWarning = '';
          notice.className = 'fpw-workspace-notice';
          notice.setAttribute('role', 'alert');
          notice.tabIndex = -1;
          form.prepend(notice);
        }
        notice.textContent = 'Guarda los cambios del borrador antes de realizar otra acción.';
        notice.focus();
        return;
      }
      submitted = true;
      form.setAttribute('aria-busy', 'true');
      // Do not disable successful form controls: native serialization owns the payload.
    });
  });
  window.addEventListener('beforeunload', event => {
    if (!submitted && workDirty) {
      event.preventDefault();
      event.returnValue = '';
    }
  });
  root.querySelectorAll('a[href^="#"]').forEach(link => {
    link.addEventListener('click', event => {
      const target = document.getElementById(link.hash.slice(1));
      if (!target) return;
      event.preventDefault();
      if (target.tagName === 'DETAILS') target.open = true;
      target.tabIndex = -1;
      target.focus({preventScroll:true});
      target.scrollIntoView({block:'start'});
    });
  });
})();
