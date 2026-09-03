/* ============================================================
   Freeplast — interacción de la propuesta
   Motion: cubic-bezier(0.32,0.72,0,1). Revelados con
   IntersectionObserver. Un único listener de scroll,
   throttled por requestAnimationFrame.
   ============================================================ */

(() => {
  "use strict";

  /* Conectar al endpoint real de ventas (formhandler, CRM, correo entrante).
     Mientras esté vacío, el formulario valida y muestra el estado de éxito
     en modo local, sin fingir que envió nada. */
  const ENDPOINT = "";

  const reduce = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

  /* ------------------------------------------------------------
     1. progreso de lectura + isla contraída
     ------------------------------------------------------------ */
  const bar = $("[data-progress]");
  const island = $("[data-island]");
  let words = [];
  let wordTops = [];
  let queued = false;

  const onFrame = () => {
    queued = false;
    const doc = document.documentElement;
    const max = doc.scrollHeight - window.innerHeight;
    const ratio = max > 0 ? Math.min(1, Math.max(0, window.scrollY / max)) : 0;
    if (bar) bar.style.width = (ratio * 100).toFixed(2) + "%";
    if (island) island.dataset.shrunk = window.scrollY > 64 ? "true" : "false";
    paintWords();
  };

  const requestFrame = () => {
    if (queued) return;
    queued = true;
    window.requestAnimationFrame(onFrame);
  };

  window.addEventListener("scroll", requestFrame, { passive: true });
  window.addEventListener("resize", requestFrame, { passive: true });
  requestFrame();

  /* ------------------------------------------------------------
     2. nav isla: hamburguesa con morph a X y overlay
     ------------------------------------------------------------ */
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
      const first = $("a", sheet);
      if (first) window.setTimeout(() => first.focus(), reduce ? 0 : 220);
    } else if (lastFocus && document.body.dataset.menuOpen === "false") {
      lastFocus.focus();
    }
  };

  if (burger) {
    burger.addEventListener("click", () => {
      setMenu(burger.getAttribute("aria-expanded") !== "true");
    });
  }
  if (sheet) {
    sheet.addEventListener("click", (e) => {
      if (e.target.closest("a")) setMenu(false);
    });
  }
  document.addEventListener("keydown", (e) => {
    if (e.key !== "Escape" || document.body.dataset.menuOpen !== "true") return;
    setMenu(false);
  });
  window.addEventListener("resize", () => {
    if (window.innerWidth >= 900 && document.body.dataset.menuOpen === "true") setMenu(false);
  }, { passive: true });

  /* ------------------------------------------------------------
     3. sección activa en la navegación (aria-current)
     ------------------------------------------------------------ */
  const spy = $("[data-spy]");
  if (spy) {
    const links = $$("a", spy);
    const map = new Map(
      links
        .map((a) => [document.getElementById(a.hash.slice(1)), a])
        .filter(([el]) => Boolean(el))
    );
    const spyObserver = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (!entry.isIntersecting) return;
          links.forEach((l) => l.removeAttribute("aria-current"));
          const link = map.get(entry.target);
          if (link) link.setAttribute("aria-current", "true");
        });
      },
      { rootMargin: "-45% 0px -50% 0px", threshold: 0 }
    );
    map.forEach((_l, el) => spyObserver.observe(el));
  }

  /* ------------------------------------------------------------
     4. B7 revelado por scroll (translate-y-16 + blur + fade)
     ------------------------------------------------------------ */
  const rises = $$(".rise");
  if (reduce || !("IntersectionObserver" in window)) {
    rises.forEach((el) => (el.dataset.in = "true"));
  } else {
    const riseObserver = new IntersectionObserver(
      (entries, obs) => {
        entries.forEach((entry) => {
          if (!entry.isIntersecting) return;
          entry.target.dataset.in = "true";
          obs.unobserve(entry.target);
        });
      },
      { rootMargin: "0px 0px -10% 0px", threshold: 0.12 }
    );
    rises.forEach((el) => riseObserver.observe(el));
  }

  /* ------------------------------------------------------------
     5. B11 revelado de tagline palabra por palabra
     La línea de disparo vive en el 62 % de la pantalla. Se mide una
     sola vez y se compara contra offsets cacheados: así una palabra
     nunca queda apagada si el usuario salta o hace scroll rápido.
     ------------------------------------------------------------ */
  const copy = $("[data-reveal]");
  words = copy ? $$(".w", copy) : [];

  function measureWords() {
    wordTops = words.map((w) => {
      const r = w.getBoundingClientRect();
      return r.top + window.scrollY + r.height / 2;
    });
  }

  function paintWords() {
    if (!wordTops.length) return;
    const line = window.scrollY + window.innerHeight * 0.62;
    words.forEach((w, i) => {
      if (line >= wordTops[i]) w.dataset.on = "true";
    });
  }

  if (reduce) {
    words.forEach((w) => (w.dataset.on = "true"));
  } else {
    measureWords();
    paintWords();
    window.addEventListener("resize", () => {
      measureWords();
      paintWords();
    }, { passive: true });
    window.addEventListener("load", () => {
      measureWords();
      paintWords();
    });
  }

  /* ------------------------------------------------------------
     6. catálogo: filtro por rubro con estados de carga y vacío
     ------------------------------------------------------------ */
  const filters = $("[data-filters]");
  const cardsWrap = $("[data-cards]");
  const empty = $("[data-empty]");

  if (filters && cardsWrap) {
    const cards = $$(".card", cardsWrap);

    /* shimmer real por imagen mientras decodifica */
    $$(".card-media", cardsWrap).forEach((media) => {
      const img = $("img", media);
      if (!img) return;
      media.dataset.loading = "true";
      const done = () => {
        media.dataset.loading = "false";
      };
      if (img.complete && img.naturalWidth > 0) done();
      else {
        img.addEventListener("load", done, { once: true });
        img.addEventListener("error", done, { once: true });
      }
    });

    const apply = (key, btn) => {
      $$("button", filters).forEach((b) =>
        b.setAttribute("aria-pressed", b === btn ? "true" : "false")
      );
      let shown = 0;
      cards.forEach((card) => {
        const on = key === "all" || card.dataset.cat === key;
        card.hidden = !on;
        if (on) {
          shown += 1;
          if (!reduce) {
            card.style.opacity = "0";
            card.style.transform = "translateY(24px)";
            window.requestAnimationFrame(() => {
              card.style.transition = "opacity 700ms cubic-bezier(0.32,0.72,0,1), transform 700ms cubic-bezier(0.32,0.72,0,1)";
              card.style.opacity = "1";
              card.style.transform = "translateY(0)";
            });
          }
        }
      });
      if (empty) empty.dataset.show = shown === 0 ? "true" : "false";
      cardsWrap.style.display = shown === 0 ? "none" : "";
    };

    filters.addEventListener("click", (e) => {
      const btn = e.target.closest("button[data-filter]");
      if (!btn) return;
      apply(btn.dataset.filter, btn);
    });
  }

  /* ------------------------------------------------------------
     7. acordeón de preguntas
     ------------------------------------------------------------ */
  $$("[data-faq] .qa").forEach((qa) => {
    const btn = $(":scope > button", qa);
    const body = $(".qa-body", qa);
    if (!btn || !body) return;
    const id = "qa-" + Math.random().toString(36).slice(2, 8);
    body.id = id;
    btn.setAttribute("aria-controls", id);
    btn.setAttribute("aria-expanded", "false");
    btn.addEventListener("click", () => {
      const open = qa.dataset.open === "true";
      qa.dataset.open = open ? "false" : "true";
      btn.setAttribute("aria-expanded", open ? "false" : "true");
    });
  });

  /* ------------------------------------------------------------
     8. CTA del hero: baja al formulario y enfoca el primer campo
     ------------------------------------------------------------ */
  const nombre = $("#nombre");
  $$("[data-cta]").forEach((cta) => {
    cta.addEventListener("click", () => {
      if (!nombre) return;
      window.setTimeout(
        () => {
          nombre.focus({ preventScroll: true });
        },
        reduce ? 60 : 700
      );
    });
  });

  /* ------------------------------------------------------------
     9. formulario: validación en línea, sin alert
     ------------------------------------------------------------ */
  const form = $("[data-form]");
  const sent = $("[data-sent]");
  const status = $("[data-status]");
  const statusText = $("[data-status-text]");
  const submitBtn = $("[data-submit]");

  const EMAIL = /^[^\s@]+@[^\s@]+\.[a-z]{2,}$/i;
  const PHONE = /^(?:\+56\s?9?\s?\d{4}\s?\d{4}|\+?\d{8,15}|9\s?\d{4}\s?\d{4})$/;

  const fieldOf = (input) => input.closest(".field");
  const invalid = (input, on) => {
    const f = fieldOf(input);
    if (!f) return;
    f.dataset.invalid = on ? "true" : "false";
    input.setAttribute("aria-invalid", on ? "true" : "false");
  };

  const check = (input) => {
    const v = (input.value || "").trim();
    const name = input.name;

    if (input.type === "checkbox") {
      const ok = input.checked;
      invalid(input, !ok);
      return ok;
    }
    if (name === "nombre") {
      const ok = v.length >= 2;
      invalid(input, !ok);
      return ok;
    }
    if (name === "email") {
      const ok = EMAIL.test(v);
      invalid(input, !ok);
      return ok;
    }
    if (name === "telefono") {
      if (!v) {
        invalid(input, false);
        return true;
      }
      const ok = PHONE.test(v.replace(/[\s().-]/g, " ").trim()) || PHONE.test(v);
      invalid(input, !ok);
      return ok;
    }
    if (name === "mensaje") {
      const ok = v.length >= 10;
      invalid(input, !ok);
      return ok;
    }
    return true;
  };

  const showStatus = (msg) => {
    if (!status || !statusText) return;
    statusText.textContent = msg;
    status.dataset.show = msg ? "true" : "false";
  };

  if (form) {
    const inputs = $$("input, select, textarea", form).filter(
      (i) => Boolean(i.name) && i.name !== "company_site"
    );

    inputs.forEach((input) => {
      const evt = input.tagName === "SELECT" || input.type === "checkbox" ? "change" : "blur";
      input.addEventListener(evt, () => {
        if ((input.value || "").trim() || input.checked) check(input);
      });
      input.addEventListener("input", () => {
        const f = fieldOf(input);
        if (f && f.dataset.invalid === "true") check(input);
      });
    });

    const payload = () => {
      const d = new FormData(form);
      const out = {};
      for (const [k, v] of d.entries()) {
        if (k === "company_site") continue;
        out[k] = typeof v === "string" ? v.trim() : v;
      }
      out.consent = d.get("consent") ? "Sí" : "No";
      return out;
    };

    const mailto = (data) => {
      const lines = [
        `Nombre: ${data.nombre || ""}`,
        `Empresa: ${data.empresa || "-"}`,
        `Correo: ${data.email || ""}`,
        `Teléfono: ${data.telefono || "-"}`,
        `Producto: ${data.producto || "-"}`,
        `Cantidad: ${data.cantidad || "-"}`,
        "",
        data.mensaje || "",
      ];
      return `mailto:ventas@freeplast.cl?subject=${encodeURIComponent(
        "Cotización desde la web: " + (data.producto || "producto a definir")
      )}&body=${encodeURIComponent(lines.join("\n"))}`;
    };

    const renderSent = (data) => {
      const summary = $("[data-sent-summary]");
      if (summary) {
        const rows = [
          ["Nombre", data.nombre],
          ["Correo", data.email],
          ["Producto", data.producto],
          ["Cantidad", data.cantidad || "Por definir"],
          ["Teléfono", data.telefono || "No indicado"],
          ["Empresa", data.empresa || "No indicada"],
        ];
        summary.innerHTML = rows
          .map(
            ([k, v]) =>
              `<dt>${k}</dt><dd>${String(v || "-").replace(/[<>&]/g, "")}</dd>`
          )
          .join("");
      }
      const link = $("[data-sent-mail]");
      if (link) link.href = mailto(data);
      const note = $("[data-sent-note]");
      if (note) {
        note.textContent = ENDPOINT
          ? "Te respondemos al correo indicado con precio, stock y fecha de despacho."
          : "La propuesta aún no conecta un endpoint de correo. Usa el botón de abajo para enviarla ahora mismo desde tu propio correo, o conecta ENDPOINT en script.js.";
      }
      form.style.display = "none";
      if (sent) {
        sent.dataset.show = "true";
        sent.focus({ preventScroll: true });
        sent.scrollIntoView({ behavior: reduce ? "auto" : "smooth", block: "center" });
      }
    };

    form.addEventListener("submit", async (e) => {
      e.preventDefault();
      if (form.dataset.busy === "true") return;

      const fields = $$("input, select, textarea", form).filter(
        (i) => i.name && i.name !== "company_site"
      );
      const bad = fields.filter((i) => !check(i));

      if (bad.length) {
        showStatus("Revisa los campos marcados: hacen falta para cotizar.");
        bad[0].focus();
        return;
      }
      showStatus("");

      const data = payload();
      if (data.company_site) return; /* spam */

      form.dataset.busy = "true";
      const original = submitBtn ? submitBtn.innerHTML : "";
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = "Enviando";
      }

      if (!ENDPOINT) {
        window.setTimeout(() => {
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = original;
          }
          form.dataset.busy = "false";
          renderSent(data);
        }, reduce ? 0 : 620);
        return;
      }

      try {
        const res = await fetch(ENDPOINT, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(data),
        });
        if (!res.ok) throw new Error(String(res.status));
        renderSent(data);
      } catch (_err) {
        showStatus(
          "No pudimos registrar la cotización. Intenta de nuevo o escribe a ventas@freeplast.cl."
        );
      } finally {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.innerHTML = original;
        }
        form.dataset.busy = "false";
      }
    });

    const again = $("[data-sent-again]");
    if (again) {
      again.addEventListener("click", () => {
        if (sent) sent.dataset.show = "false";
        form.reset();
        $$(".field", form).forEach((f) => (f.dataset.invalid = "false"));
        form.style.display = "";
        showStatus("");
        nombre?.focus();
      });
    }
  }
})();
