const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)");

const header = document.querySelector("[data-header]");
const progressBar = document.querySelector(".scroll-progress span");
let scrollFrame = 0;

function updateScrollChrome() {
  scrollFrame = 0;
  const scrollTop = window.scrollY;
  const scrollRange = document.documentElement.scrollHeight - window.innerHeight;
  header?.classList.toggle("is-scrolled", scrollTop > 18);
  if (progressBar) {
    const progress = scrollRange > 0 ? Math.min(1, scrollTop / scrollRange) : 0;
    progressBar.style.transform = `scaleX(${progress})`;
  }
}

window.addEventListener(
  "scroll",
  () => {
    if (!scrollFrame) scrollFrame = requestAnimationFrame(updateScrollChrome);
  },
  { passive: true },
);
updateScrollChrome();

// The menu materializes from the button that opened it and remains keyboard friendly.
const menuButton = document.querySelector("[data-menu-button]");
const mobileMenu = document.querySelector("[data-mobile-menu]");
let menuAnimation;

function setMenu(open) {
  if (!menuButton || !mobileMenu) return;
  menuAnimation?.cancel();
  menuButton.setAttribute("aria-expanded", String(open));
  menuButton.setAttribute("aria-label", open ? "Cerrar menú" : "Abrir menú");
  document.body.classList.toggle("menu-open", open);

  if (open) {
    mobileMenu.hidden = false;
    if (!reduceMotion.matches) {
      menuAnimation = mobileMenu.animate(
        [
          { opacity: 0, transform: "translateY(-8px) scale(0.97)", filter: "blur(8px)" },
          { opacity: 1, transform: "translateY(0) scale(1)", filter: "blur(0)" },
        ],
        { duration: 280, easing: "cubic-bezier(.22, 1, .36, 1)", fill: "both" },
      );
    }
  } else if (reduceMotion.matches) {
    mobileMenu.hidden = true;
  } else {
    const transform = getComputedStyle(mobileMenu).transform;
    menuAnimation = mobileMenu.animate(
      [
        { opacity: 1, transform: transform === "none" ? "scale(1)" : transform, filter: "blur(0)" },
        { opacity: 0, transform: "translateY(-8px) scale(0.97)", filter: "blur(8px)" },
      ],
      { duration: 180, easing: "cubic-bezier(.4, 0, 1, 1)", fill: "both" },
    );
    menuAnimation.addEventListener("finish", () => {
      mobileMenu.hidden = true;
    }, { once: true });
  }
}

menuButton?.addEventListener("click", () => {
  setMenu(menuButton.getAttribute("aria-expanded") !== "true");
});

document.addEventListener("pointerdown", (event) => {
  if (menuButton?.getAttribute("aria-expanded") === "true" && !header?.contains(event.target)) {
    setMenu(false);
  }
});

window.matchMedia("(min-width: 48rem)").addEventListener("change", (event) => {
  if (event.matches && menuButton?.getAttribute("aria-expanded") === "true") setMenu(false);
});

mobileMenu?.querySelectorAll("a").forEach((link) => {
  link.addEventListener("click", () => setMenu(false));
});

document.addEventListener("keydown", (event) => {
  if (event.key === "Escape" && menuButton?.getAttribute("aria-expanded") === "true") {
    setMenu(false);
    menuButton.focus();
  }
});

// Reveal content once, avoiding large moving surfaces for reduced-motion users.
const revealItems = document.querySelectorAll("[data-reveal]");
if (reduceMotion.matches || !("IntersectionObserver" in window)) {
  revealItems.forEach((item) => item.classList.add("is-visible"));
} else {
  const revealObserver = new IntersectionObserver(
    (entries, observer) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        entry.target.classList.add("is-visible");
        observer.unobserve(entry.target);
      });
    },
    { threshold: 0.12, rootMargin: "0px 0px -4%" },
  );
  revealItems.forEach((item) => revealObserver.observe(item));
}

// A velocity-aware, interruptible rail. Dragging tracks 1:1; release momentum
// is projected before a critically damped spring chooses the nearest card.
class ProductRail {
  constructor(element) {
    this.element = element;
    this.cards = [...element.querySelectorAll(".product-card")];
    this.activePointer = null;
    this.dragging = false;
    this.cancelClick = false;
    this.history = [];
    this.springFrame = 0;
    this.springVelocity = 0;
    this.lastSpringTime = 0;

    element.addEventListener("pointerdown", (event) => this.onPointerDown(event));
    element.addEventListener("pointermove", (event) => this.onPointerMove(event));
    element.addEventListener("pointerup", (event) => this.onPointerUp(event));
    element.addEventListener("pointercancel", (event) => this.onPointerUp(event));
    element.addEventListener("click", (event) => {
      if (this.cancelClick) {
        event.preventDefault();
        event.stopPropagation();
        this.cancelClick = false;
      }
    }, true);
    element.addEventListener("keydown", (event) => {
      if (event.key === "ArrowRight" || event.key === "ArrowLeft") {
        event.preventDefault();
        this.step(event.key === "ArrowRight" ? 1 : -1);
      }
    });
  }

  onPointerDown(event) {
    if (!event.isPrimary || event.button !== 0) return;
    this.cancelSpring();
    this.activePointer = event.pointerId;
    this.startX = event.clientX;
    this.startY = event.clientY;
    this.startScroll = this.element.scrollLeft;
    this.dragging = false;
    this.cancelClick = false;
    this.history = [{ x: event.clientX, time: performance.now() }];
  }

  onPointerMove(event) {
    if (event.pointerId !== this.activePointer) return;
    const dx = event.clientX - this.startX;
    const dy = event.clientY - this.startY;

    if (!this.dragging) {
      if (Math.abs(dy) > 10 && Math.abs(dy) > Math.abs(dx)) {
        this.activePointer = null;
        return;
      }
      if (Math.abs(dx) < 9 || Math.abs(dx) <= Math.abs(dy)) return;
      this.dragging = true;
      this.cancelClick = true;
      this.element.classList.add("is-dragging");
      this.element.style.scrollSnapType = "none";
      this.element.setPointerCapture(event.pointerId);
    }

    event.preventDefault();
    this.element.scrollLeft = this.startScroll - dx;
    const now = performance.now();
    this.history.push({ x: event.clientX, time: now });
    this.history = this.history.filter((sample) => now - sample.time <= 110);
  }

  onPointerUp(event) {
    if (event.pointerId !== this.activePointer) return;
    this.activePointer = null;
    if (!this.dragging) return;
    this.dragging = false;
    this.element.classList.remove("is-dragging");

    const first = this.history[0];
    const last = this.history[this.history.length - 1];
    const elapsed = Math.max(16, last.time - first.time);
    const pointerVelocity = ((last.x - first.x) / elapsed) * 1000;
    const scrollVelocity = -pointerVelocity;
    const projected = this.element.scrollLeft + this.project(scrollVelocity);
    const target = this.nearestCard(projected);
    this.springTo(target, scrollVelocity);
  }

  project(velocity, decelerationRate = 0.998) {
    return (velocity / 1000) * (decelerationRate / (1 - decelerationRate));
  }

  cardPositions() {
    if (!this.cards.length) return [];
    const firstCardOffset = this.cards[0].offsetLeft - this.element.offsetLeft;
    return this.cards.map((card) => card.offsetLeft - this.element.offsetLeft - firstCardOffset);
  }

  nearestCard(position) {
    const positions = this.cardPositions();
    if (!positions.length) return 0;
    return positions.reduce((nearest, point) =>
      Math.abs(point - position) < Math.abs(nearest - position) ? point : nearest,
    positions[0]);
  }

  step(direction) {
    const positions = this.cardPositions();
    if (!positions.length) return;
    const current = this.element.scrollLeft;
    const epsilon = 8;
    const next = direction > 0
      ? positions.find((position) => position > current + epsilon) ?? positions.at(-1)
      : [...positions].reverse().find((position) => position < current - epsilon) ?? positions[0];
    this.springTo(next, this.springVelocity);
  }

  springTo(target, initialVelocity = 0) {
    if (reduceMotion.matches) {
      this.element.scrollLeft = target;
      this.element.style.scrollSnapType = "";
      return;
    }

    this.cancelSpring();
    this.element.style.scrollSnapType = "none";
    this.springVelocity = initialVelocity;
    this.lastSpringTime = performance.now();
    let position = this.element.scrollLeft;
    const response = 0.42;
    const omega = (2 * Math.PI) / response;

    const tick = (time) => {
      const dt = Math.min(0.032, Math.max(0.001, (time - this.lastSpringTime) / 1000));
      this.lastSpringTime = time;
      const displacement = position - target;
      const acceleration = -omega * omega * displacement - 2 * omega * this.springVelocity;
      this.springVelocity += acceleration * dt;
      position += this.springVelocity * dt;
      this.element.scrollLeft = position;

      if (Math.abs(target - position) < 0.6 && Math.abs(this.springVelocity) < 5) {
        this.element.scrollLeft = target;
        this.element.style.scrollSnapType = "";
        this.springVelocity = 0;
        this.springFrame = 0;
        return;
      }
      this.springFrame = requestAnimationFrame(tick);
    };

    this.springFrame = requestAnimationFrame(tick);
  }

  cancelSpring() {
    if (this.springFrame) cancelAnimationFrame(this.springFrame);
    this.springFrame = 0;
    this.springVelocity = 0;
  }
}

const railElement = document.querySelector("[data-product-rail]");
const productRail = railElement ? new ProductRail(railElement) : null;
document.querySelector("[data-rail-prev]")?.addEventListener("click", () => productRail?.step(-1));
document.querySelector("[data-rail-next]")?.addEventListener("click", () => productRail?.step(1));

// The hero product follows the pointer with independent, critically damped X/Y springs.
const heroVisual = document.querySelector("[data-hero-visual]");
const heroProduct = document.querySelector("[data-hero-product]");
const finePointer = window.matchMedia("(hover: hover) and (pointer: fine)");

if (heroVisual && heroProduct && finePointer.matches && !reduceMotion.matches) {
  const state = { x: 0, y: 0, vx: 0, vy: 0, targetX: 0, targetY: 0, frame: 0, time: 0 };

  const runSpring = (time) => {
    const dt = Math.min(0.032, Math.max(0.001, (time - (state.time || time - 16)) / 1000));
    state.time = time;
    const omega = (2 * Math.PI) / 0.42;
    const ax = -omega * omega * (state.x - state.targetX) - 2 * omega * state.vx;
    const ay = -omega * omega * (state.y - state.targetY) - 2 * omega * state.vy;
    state.vx += ax * dt;
    state.vy += ay * dt;
    state.x += state.vx * dt;
    state.y += state.vy * dt;
    heroProduct.style.transform = `translate3d(${state.x}px, ${state.y}px, 0) rotateX(${state.y * -0.06}deg) rotateY(${state.x * 0.05}deg) rotateZ(-5deg)`;

    const unsettled =
      Math.abs(state.x - state.targetX) > 0.05 ||
      Math.abs(state.y - state.targetY) > 0.05 ||
      Math.abs(state.vx) > 0.05 ||
      Math.abs(state.vy) > 0.05;
    state.frame = unsettled ? requestAnimationFrame(runSpring) : 0;
  };

  const retarget = (x, y) => {
    state.targetX = x;
    state.targetY = y;
    if (!state.frame) {
      state.time = performance.now();
      state.frame = requestAnimationFrame(runSpring);
    }
  };

  heroVisual.addEventListener("pointermove", (event) => {
    const rect = heroVisual.getBoundingClientRect();
    const x = ((event.clientX - rect.left) / rect.width - 0.5) * 18;
    const y = ((event.clientY - rect.top) / rect.height - 0.5) * 14;
    retarget(x, y);
  });
  heroVisual.addEventListener("pointerleave", () => retarget(0, 0));
}

// Product CTA buttons map directly to the relevant form selection.
const productSelect = document.querySelector("#producto");
document.querySelectorAll("[data-product]").forEach((button) => {
  button.addEventListener("click", () => {
    if (productSelect) {
      productSelect.value = button.dataset.product;
      validateField(productSelect.closest(".field"));
      hideErrorSummaryWhenResolved();
    }
    document.querySelector("#contacto")?.scrollIntoView({ behavior: reduceMotion.matches ? "auto" : "smooth" });
    productSelect?.focus({ preventScroll: true });
  });
});

// Inline validation gives specific feedback. This proposal intentionally does not
// fake a network send; the success state states what remains to connect.
const contactForm = document.querySelector("[data-contact-form]");
const formStatus = document.querySelector("[data-form-status]");
const formErrors = document.querySelector("[data-form-errors]");
const formErrorList = document.querySelector("[data-form-error-list]");

function validateField(field) {
  const input = field.querySelector("input, select, textarea");
  if (!input || !input.required) return true;
  const valid = input.checkValidity();
  field.classList.toggle("is-invalid", !valid);
  input.setAttribute("aria-invalid", String(!valid));
  return valid;
}

function hideErrorSummaryWhenResolved() {
  if (!formErrors || !contactForm) return;
  const invalidFields = [...contactForm.querySelectorAll(".field.is-invalid")];
  if (!invalidFields.length) {
    formErrors.hidden = true;
  } else if (!formErrors.hidden) {
    showErrorSummary(invalidFields, false);
  }
}

function showErrorSummary(invalidFields, shouldFocus = true) {
  if (!formErrors || !formErrorList) return;
  formErrorList.replaceChildren();

  invalidFields.forEach((field) => {
    const input = field.querySelector("input, select, textarea");
    const message = field.querySelector(".field-error")?.textContent;
    if (!input || !message) return;

    const item = document.createElement("li");
    const link = document.createElement("a");
    link.href = `#${input.id}`;
    link.textContent = message;
    item.append(link);
    formErrorList.append(item);
  });

  formErrors.hidden = false;
  if (shouldFocus) {
    formErrors.focus({ preventScroll: true });
    formErrors.scrollIntoView({ behavior: reduceMotion.matches ? "auto" : "smooth", block: "center" });
  }
}

contactForm?.querySelectorAll("input, select, textarea").forEach((input) => {
  input.addEventListener("input", () => {
    validateField(input.closest(".field"));
    hideErrorSummaryWhenResolved();
    if (formStatus) formStatus.textContent = "";
  });
  input.addEventListener("blur", () => {
    validateField(input.closest(".field"));
    hideErrorSummaryWhenResolved();
  });
});

contactForm?.addEventListener("submit", (event) => {
  event.preventDefault();
  const fields = [...contactForm.querySelectorAll(".field")];
  fields.forEach(validateField);
  const invalidFields = fields.filter((field) => field.classList.contains("is-invalid"));

  if (invalidFields.length) {
    formStatus.textContent = "Revisa los campos marcados antes de continuar.";
    formStatus.style.color = "#a51d27";
    showErrorSummary(invalidFields);
    return;
  }

  if (formErrors) formErrors.hidden = true;
  formStatus.textContent = "Solicitud preparada. En la versión final, este formulario se conectará al canal de ventas.";
  formStatus.style.color = "";
});

document.querySelector("[data-year]").textContent = new Date().getFullYear();
