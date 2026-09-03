(() => {
  "use strict";

  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));
  const reduce = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  const progress = $("[data-progress]");
  const island = $("[data-island]");
  let frameQueued = false;

  const updateScrollState = () => {
    frameQueued = false;
    const max = document.documentElement.scrollHeight - window.innerHeight;
    const ratio = max > 0 ? Math.min(1, Math.max(0, window.scrollY / max)) : 0;
    if (progress) progress.style.width = `${(ratio * 100).toFixed(2)}%`;
    if (island) island.dataset.shrunk = window.scrollY > 64 ? "true" : "false";
  };

  const requestScrollFrame = () => {
    if (frameQueued) return;
    frameQueued = true;
    window.requestAnimationFrame(updateScrollState);
  };

  window.addEventListener("scroll", requestScrollFrame, { passive: true });
  window.addEventListener("resize", requestScrollFrame, { passive: true });
  requestScrollFrame();

  const burger = $("[data-burger]");
  const sheet = $("[data-sheet]");
  let lastFocus = null;

  const setMenu = (open) => {
    if (!burger || !sheet) return;
    sheet.dataset.open = open ? "true" : "false";
    burger.setAttribute("aria-expanded", open ? "true" : "false");
    burger.setAttribute("aria-label", open ? "Cerrar menú" : "Abrir menú");
    document.body.dataset.menuOpen = open ? "true" : "false";

    if (open) {
      lastFocus = document.activeElement;
      window.setTimeout(() => $("a", sheet)?.focus(), reduce ? 0 : 220);
    } else {
      lastFocus?.focus();
    }
  };

  burger?.addEventListener("click", () => {
    setMenu(burger.getAttribute("aria-expanded") !== "true");
  });
  sheet?.addEventListener("click", (event) => {
    if (event.target.closest("a")) setMenu(false);
  });
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && document.body.dataset.menuOpen === "true") setMenu(false);
  });
  window.addEventListener(
    "resize",
    () => {
      if (window.innerWidth >= 900 && document.body.dataset.menuOpen === "true") setMenu(false);
    },
    { passive: true }
  );

  const spy = $("[data-spy]");
  if (spy && "IntersectionObserver" in window) {
    const links = $$("a", spy);
    const sections = links
      .map((link) => [document.getElementById(link.hash.slice(1)), link])
      .filter(([section]) => Boolean(section));
    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (!entry.isIntersecting) return;
          links.forEach((link) => link.removeAttribute("aria-current"));
          sections.find(([section]) => section === entry.target)?.[1].setAttribute("aria-current", "true");
        });
      },
      { rootMargin: "-45% 0px -50% 0px", threshold: 0 }
    );
    sections.forEach(([section]) => observer.observe(section));
  }

  const rises = $$(".rise");
  if (reduce || !("IntersectionObserver" in window)) {
    rises.forEach((element) => (element.dataset.in = "true"));
  } else {
    const observer = new IntersectionObserver(
      (entries, activeObserver) => {
        entries.forEach((entry) => {
          if (!entry.isIntersecting) return;
          entry.target.dataset.in = "true";
          activeObserver.unobserve(entry.target);
        });
      },
      { rootMargin: "0px 0px -10% 0px", threshold: 0.08 }
    );
    rises.forEach((element) => observer.observe(element));
  }

  $$(".card-media").forEach((media) => {
    const image = $("img", media);
    if (!image) return;
    media.dataset.loading = "true";
    const done = () => (media.dataset.loading = "false");
    if (image.complete && image.naturalWidth > 0) done();
    else {
      image.addEventListener("load", done, { once: true });
      image.addEventListener("error", done, { once: true });
    }
  });
})();
