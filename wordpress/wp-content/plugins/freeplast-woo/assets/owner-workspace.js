(() => {
  'use strict';
  const root = document.querySelector('.fpw-workspace');
  if (!root) return;
  const work = root.querySelector('[data-fpw-work]');
  const tracking = root.querySelector('[data-fpw-tracking]');
  let workDirty = false, trackingDirty = false, submitted = false;
  const status = root.querySelector('[data-fpw-save-state]');
  const savedStatus = status?.textContent;
  const dockTotal = root.querySelector('[data-fpw-dock-total]');
  const savedTotal = dockTotal?.textContent;
  work?.addEventListener('reset', () => {
    workDirty = false;
    if (status) status.textContent = savedStatus;
    if (dockTotal) dockTotal.textContent = savedTotal;
    root.querySelector('#fpw-summary')?.classList.remove('has-unsaved');
    root.querySelectorAll('[data-fpw-preview],[data-fpw-refresh]').forEach(button => { button.disabled = false; });
    root.querySelectorAll('[data-fpw-unsaved-warning]').forEach(notice => notice.remove());
  });
  tracking?.addEventListener('reset', () => {
    trackingDirty = false;
    root.querySelectorAll('[data-fpw-unsaved-warning]').forEach(notice => notice.remove());
  });
  const setWorkDirty = () => {
    workDirty = true;
    if (status) status.textContent = 'Cambios sin guardar. Guarda para actualizar los importes.';
    root.querySelector('#fpw-summary')?.classList.add('has-unsaved');
    const total = root.querySelector('[data-fpw-dock-total]');
    if (total) total.textContent = 'Sin guardar';
    root.querySelectorAll('[data-fpw-preview],[data-fpw-refresh]').forEach(button => { button.disabled = true; });
  };
  if (work?.dataset.fpwUnsaved === '1') setWorkDirty();
  root.querySelector('[data-fpw-discard-work]')?.addEventListener('click', () => { if (!trackingDirty) submitted = true; });
  work?.addEventListener('input', setWorkDirty);
  work?.addEventListener('change', setWorkDirty);
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
  tracking?.addEventListener('input', () => { trackingDirty = true; });
  tracking?.addEventListener('change', () => { trackingDirty = true; });
  root.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', event => {
      if ((workDirty && form !== work) || (trackingDirty && form !== tracking)) {
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
        notice.textContent = workDirty && form !== work
          ? 'Guarda los cambios del borrador antes de realizar otra acción.'
          : 'Guarda los cambios del seguimiento antes de realizar otra acción.';
        notice.focus();
        return;
      }
      submitted = true;
      form.setAttribute('aria-busy', 'true');
      // Do not disable successful form controls: native serialization owns the payload.
    });
  });
  window.addEventListener('beforeunload', event => {
    if (!submitted && (workDirty || trackingDirty)) {
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
