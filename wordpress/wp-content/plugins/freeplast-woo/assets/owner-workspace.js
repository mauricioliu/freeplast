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
  const rejected = work?.dataset.fpwUnsaved === '1';
  const calculation = JSON.parse(work?.dataset.fpwCalculation || '{}');
  const amounts = [...root.querySelectorAll('[data-fpw-line-total],[data-fpw-amount],[data-fpw-line-label],[data-fpw-total-label],[data-fpw-minimum],[data-fpw-dispatch-warning]')];
  const savedAmounts = amounts.map(node => [node, node.textContent, node.hidden]);
  const guarded = [...root.querySelectorAll('[data-fpw-preview],[data-fpw-refresh]')].map(node => [node, node.disabled]);
  const freightFacts = [...root.querySelectorAll('.fpw-workspace-dispatch dd > span,.fpw-workspace-dispatch dd > em')].map(node => [node, node.hidden]);
  const fields = () => [...(work?.elements || [])].filter(field => /^fpw_work\[/.test(field.name));
  const integer = (raw, digits, max) => {
    const text = String(raw ?? '').trim();
    if (!new RegExp(`^\\d{1,${digits}}$`).test(text)) return null;
    const value = BigInt(text);
    return value > 0n && value <= max ? value : null;
  };
  const money = value => value === null ? 'Por definir' : `${value.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.')} CLP`;
  const estimate = () => {
    const quantities = [];
    let subtotal = 0n, complete = true;
    fields().filter(field => /^fpw_work\[lines\]\[\d+\]\[quantity\]$/.test(field.name)).forEach(field => {
      const index = /\[(\d+)\]/.exec(field.name)[1];
      const quantity = integer(field.value, 7, 1000000n);
      const price = integer(work.elements.namedItem(`fpw_work[lines][${index}][price]`)?.value, 8, 99999999n);
      quantities.push(quantity);
      const line = quantity !== null && price !== null ? quantity * price : null;
      if (line === null) complete = false; else subtotal += line;
      const cell = root.querySelector(`[data-fpw-line-total="${index}"]`);
      if (cell) cell.textContent = money(line);
      const minimum = root.querySelector(`[data-fpw-minimum="${index}"]`);
      if (minimum?.dataset.minimum) minimum.textContent = `Mínimo por solicitud: ${Number(minimum.dataset.minimum).toLocaleString('es-CL')} unidades — ${quantity === null ? 'ingresa una cantidad válida.' : quantity >= BigInt(minimum.dataset.minimum) ? 'cubierto.' : 'revisa la cantidad con el comprador.'}`;
    });
    if (!quantities.length || !complete) subtotal = null;
    let dispatch = 0n, warning = '';
    if (calculation.dispatch) {
      dispatch = integer(work.elements.namedItem('fpw_work[dispatch_amount]')?.value, 8, 99999999n);
      const destination = work.elements.namedItem('fpw_work[destination]')?.value || '';
      const basis = calculation.conditions;
      // ponytail: ambiguous WordPress sanitation stays pending; expand only with shared normalization tests.
      const safeDestination = destination !== '' && destination === destination.trim() && !/[<>&%\r\t]| {2}/.test(destination) && new TextEncoder().encode(destination).length <= 800;
      const stale = dispatch !== null && dispatch === BigInt(calculation.savedAmount ?? 0) && basis &&
        (destination !== basis.destination || quantities.length !== basis.quantities?.length || quantities.some((quantity, i) => quantity === null || quantity !== BigInt(basis.quantities[i])));
      if (!safeDestination) warning = 'Completa o revisa el destino y guarda para confirmar el despacho.';
      else if (stale) warning = 'Despacho requiere revisión: cambió el destino o las cantidades. Revisa el monto antes de guardar.';
      else if (dispatch === null) warning = 'Ingresa el monto de despacho (1 a 99.999.999 CLP); no equivale a cero.';
      if (warning) dispatch = null;
    }
    const taxPolicy = calculation.tax;
    const validPolicy = Number.isInteger(taxPolicy?.rate_permille) && taxPolicy.rate_permille >= 0 && taxPolicy.rate_permille <= 5000;
    const validityField = work?.elements.namedItem('fpw_work[validity_days]');
    const validity = validityField?.value || '';
    const validDays = !validityField?.validity.badInput && (validity.trim() === '' || integer(validity, 3, 365n) !== null);
    let tax = null, total = null;
    if (subtotal !== null && dispatch !== null && validPolicy && validDays) {
      const base = subtotal + (taxPolicy.applies_to_dispatch ? dispatch : 0n);
      tax = (base * BigInt(taxPolicy.rate_permille) + 500n) / 1000n;
      total = subtotal + dispatch + tax;
    }
    for (const [key, value] of Object.entries({subtotal, dispatch, tax, total})) {
      root.querySelectorAll(`[data-fpw-amount="${key}"]`).forEach(cell => { cell.textContent = money(value); });
    }
    if (dockTotal) dockTotal.textContent = money(total);
    root.querySelectorAll('[data-fpw-line-label]').forEach(node => { node.textContent = 'Neto estimado'; });
    root.querySelectorAll('[data-fpw-total-label]').forEach(node => { node.textContent = 'Total estimado · Cambios sin guardar'; });
    freightFacts.forEach(([node]) => { node.hidden = true; });
    const freightWarning = root.querySelector('[data-fpw-dispatch-warning]');
    if (freightWarning) { freightWarning.textContent = warning; freightWarning.hidden = !warning; }
    if (status) status.textContent = 'Cambios sin guardar. Importes estimados; guarda y revisa antes de aprobar.' +
      (subtotal === null ? ' Revisa cantidades (1 a 1.000.000) y precios (1 a 99.999.999 CLP).' : '') +
      (!validPolicy ? ' IVA y total pendientes: falta la política fiscal confirmada.' : '') +
      (!validDays ? ' Ingresa una vigencia entre 1 y 365 días.' : '') + (warning ? ` ${warning}` : '');
  };
  const restoreAmounts = () => {
    savedAmounts.forEach(([node, text, hidden]) => { node.textContent = text; node.hidden = hidden; });
    freightFacts.forEach(([node, hidden]) => { node.hidden = hidden; });
    if (status) status.textContent = savedStatus;
    if (dockTotal) dockTotal.textContent = savedTotal;
    guarded.forEach(([button, disabled]) => { button.disabled = disabled; });
    root.querySelector('#fpw-summary')?.classList.remove('has-unsaved');
  };
  const syncPallets = () => {
    root.querySelectorAll('[data-fpw-pallets]').forEach(note => {
      const units = Number(note.dataset.units);
      const field = work?.elements.namedItem(`fpw_work[lines][${note.dataset.fpwPallets}][quantity]`);
      if (!units || !field) return;
      const quantity = Number(field.value);
      if (integer(field.value, 7, 1000000n) === null) {
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
    legend.textContent = !rejected && field.value === field.defaultValue ? legend.dataset.fpwSavedLegend : 'Cambio sin guardar.';
    legend.hidden = !legend.textContent;
  };
  const resetPriceOrigins = () => {
    root.querySelectorAll('[data-fpw-origin]').forEach(legend => {
      if (legend.dataset.fpwSavedLegend !== undefined) { legend.textContent = legend.dataset.fpwSavedLegend; }
      legend.hidden = !legend.textContent;
    });
  };
  work?.addEventListener('reset', () => {
    setTimeout(syncPallets, 0);
    if (rejected) { setTimeout(setWorkDirty, 0); return; }
    workDirty = false;
    restoreAmounts();
    root.querySelectorAll('[data-fpw-unsaved-warning]').forEach(notice => notice.remove());
    resetPriceOrigins();
    syncActions();
  });
  const setWorkDirty = () => {
    workDirty = rejected || fields().some(field => field.value !== field.defaultValue || field.validity.badInput);
    if (workDirty) {
      estimate();
      root.querySelector('#fpw-summary')?.classList.add('has-unsaved');
      guarded.forEach(([button]) => { button.disabled = true; });
    } else restoreAmounts();
    syncActions();
  };
  if (work?.dataset.fpwUnsaved === '1') {
    setWorkDirty();
    // The server-rendered unsaved screen carries rejected posted prices: every
    // legend must admit those values are not the saved ones until reset/save.
    root.querySelectorAll('[data-fpw-origin]').forEach(legend => {
      if (legend.dataset.fpwSavedLegend === undefined) { legend.dataset.fpwSavedLegend = legend.textContent; }
      legend.textContent = 'Cambio sin guardar.';
      legend.hidden = false;
    });
  }
  else syncActions();
  root.querySelector('[data-fpw-discard-work]')?.addEventListener('click', () => { submitted = true; });
  work?.addEventListener('input', (event) => { syncPallets(); setWorkDirty(); syncPriceOrigin(event.target); });
  work?.addEventListener('change', (event) => { syncPallets(); setWorkDirty(); syncPriceOrigin(event.target); });
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
      if (workDirty && (form !== work || event.submitter?.name === 'fpw_work_preview' || event.submitter?.name === 'fpw_price_refresh')) {
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
