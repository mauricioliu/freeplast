/* Original Freeplast banner; no carousel dependency. */
(() => {
  const banner = document.querySelector('.fp-original-banner');
  if (!banner || banner.dataset.ready) return;
  banner.dataset.ready = 'true';
  const slides = [...banner.querySelectorAll('.fp-banner-slide')];
  const dots = [...banner.querySelectorAll('[data-slide]')];
  const pause = banner.querySelector('.fp-banner-pause');
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
  let index = 0;
  let paused = reduced.matches;
  let timer;
  const show = (next, announce = false) => {
    index = (next + slides.length) % slides.length;
    slides.forEach((slide, i) => { slide.hidden = i !== index; });
    dots.forEach((dot, i) => {
      if (i === index) dot.setAttribute('aria-current', 'true');
      else dot.removeAttribute('aria-current');
    });
    if (announce) banner.querySelector('.fp-banner-status').textContent = `Diapositiva ${index + 1} de ${slides.length}`;
  };
  const schedule = () => {
    clearInterval(timer);
    pause.hidden = reduced.matches;
    pause.textContent = paused ? 'Reanudar rotación' : 'Pausar rotación';
    if (!paused && !reduced.matches && !document.hidden && !banner.matches(':hover') && !banner.contains(document.activeElement)) {
      timer = setInterval(() => show(index + 1), 5000);
    }
  };
  const navigate = next => { paused = true; show(next, true); schedule(); };
  banner.querySelector('.fp-banner-prev').addEventListener('click', () => navigate(index - 1));
  banner.querySelector('.fp-banner-next').addEventListener('click', () => navigate(index + 1));
  dots.forEach(dot => dot.addEventListener('click', () => navigate(Number(dot.dataset.slide))));
  pause.addEventListener('click', () => { paused = !paused; schedule(); });
  banner.addEventListener('mouseenter', schedule);
  banner.addEventListener('mouseleave', schedule);
  banner.addEventListener('focusin', event => {
    // Do not invert a pause click by changing its state before click fires.
    if (event.target !== pause) paused = true;
    schedule();
  });
  banner.addEventListener('focusout', () => setTimeout(schedule, 0));
  document.addEventListener('visibilitychange', schedule);
  reduced.addEventListener('change', () => { paused = reduced.matches || paused; schedule(); });
  banner.querySelector('.fp-banner-controls').hidden = false;
  schedule();
})();
