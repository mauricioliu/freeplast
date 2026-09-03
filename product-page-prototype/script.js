(() => {
  "use strict";

  const variants = [
    { key: "A", name: "Ficha dividida", quote: "#quote-a" },
    { key: "B", name: "Catálogo técnico", quote: "#quote-b" },
    { key: "C", name: "Cotización guiada", quote: "#quote-c" },
  ];

  const pages = Array.from(document.querySelectorAll("[data-page-variant]"));
  const label = document.querySelector("[data-variant-label]");
  const mobileCta = document.querySelector("[data-mobile-cta] a");

  const requested = new URLSearchParams(window.location.search).get("variant")?.toUpperCase();
  let current = Math.max(0, variants.findIndex((variant) => variant.key === requested));

  const showVariant = (index, updateUrl = true) => {
    current = (index + variants.length) % variants.length;
    const variant = variants[current];

    pages.forEach((page) => {
      const active = page.dataset.pageVariant === variant.key;
      page.hidden = !active;
      page.setAttribute("aria-hidden", active ? "false" : "true");
    });

    document.body.dataset.variant = variant.key;
    label.textContent = `${variant.key} — ${variant.name}`;
    mobileCta.href = variant.quote;

    if (updateUrl) {
      const url = new URL(window.location.href);
      url.searchParams.set("variant", variant.key);
      window.history.replaceState({ variant: variant.key }, "", url);
      window.scrollTo({ top: 0, behavior: "instant" });
    }
  };

  document.querySelector("[data-previous]").addEventListener("click", () => showVariant(current - 1));
  document.querySelector("[data-next]").addEventListener("click", () => showVariant(current + 1));

  document.addEventListener("keydown", (event) => {
    if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
    if (event.target.closest("input, textarea, select, [contenteditable]")) return;
    event.preventDefault();
    showVariant(current + (event.key === "ArrowRight" ? 1 : -1));
  });

  document.querySelectorAll(".quantity-control").forEach((control) => {
    const input = control.querySelector("input");
    const form = control.closest("form");
    const readout = form.querySelector("[data-pallet-readout]");

    const update = () => {
      const quantity = Math.max(70, Math.round((Number(input.value) || 70) / 70) * 70);
      input.value = String(quantity);
      const pallets = quantity / 70;
      readout.textContent = `${pallets} ${pallets === 1 ? "pallet" : "pallets"} · ${quantity} cajas`;
    };

    control.querySelectorAll("[data-step]").forEach((button) => {
      button.addEventListener("click", () => {
        input.value = String(Math.max(70, (Number(input.value) || 70) + Number(button.dataset.step)));
        update();
      });
    });
    input.addEventListener("change", update);
  });

  const cSummary = document.querySelector("[data-c-summary]");
  const updateGuidedSummary = () => {
    const amount = document.querySelector('input[name="cantidad-rapida"]:checked')?.value || "70";
    const delivery = document.querySelector('input[name="despacho"]:checked')?.value === "Si" ? "Con despacho" : "Retiro";
    cSummary.textContent = `${amount} cajas · ${delivery}`;
  };
  document.querySelectorAll('input[name="cantidad-rapida"], input[name="despacho"]').forEach((input) => {
    input.addEventListener("change", updateGuidedSummary);
  });

  document.querySelectorAll(".demo-form").forEach((form) => {
    form.addEventListener("submit", (event) => {
      event.preventDefault();
      if (!form.reportValidity()) return;
      form.querySelector(".form-status").textContent = "Prototipo: solicitud lista para enviar.";
    });
  });

  document.querySelectorAll('.related a[href="#"], .b-related a[href="#"]').forEach((link) => {
    link.addEventListener("click", (event) => event.preventDefault());
  });

  showVariant(current, requested !== variants[current].key);
})();
