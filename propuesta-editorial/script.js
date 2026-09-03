(function () {
  "use strict";

  var form = document.getElementById("form-cotizacion");
  var producto = document.getElementById("f-producto");
  var errores = document.getElementById("errores");
  var erroresLista = document.getElementById("errores-lista");
  var status = document.getElementById("form-status");

  var fields = {
    nombre: {
      el: document.getElementById("f-nombre"),
      error: document.getElementById("e-nombre"),
      check: function (v) {
        return v.trim().length >= 2 || "Escribe tu nombre.";
      },
    },
    email: {
      el: document.getElementById("f-email"),
      error: document.getElementById("e-email"),
      check: function (v) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v.trim()) || "Revisa el formato del correo.";
      },
    },
    telefono: {
      el: document.getElementById("f-telefono"),
      error: document.getElementById("e-telefono"),
      check: function (v) {
        if (!v.trim()) return true;
        return v.replace(/\D/g, "").length >= 8 || "Revisa el número (mínimo 8 dígitos).";
      },
    },
    cantidad: {
      el: document.getElementById("f-cantidad"),
      error: document.getElementById("e-cantidad"),
      check: function (v) {
        if (!v.trim()) return true;
        return Number(v) >= 1 || "Ingresa una cantidad mayor a 0.";
      },
    },
    producto: {
      el: producto,
      error: document.getElementById("e-producto"),
      check: function (v) {
        return v.trim().length > 0 || "Elige un producto o consulta general.";
      },
    },
  };

  function setError(field, message) {
    var invalid = message !== true;
    field.el.setAttribute("aria-invalid", invalid ? "true" : "false");
    field.error.hidden = !invalid;
    if (invalid) field.error.textContent = message;
    return invalid ? { el: field.el, message: message } : null;
  }

  function validate() {
    var problems = [];
    Object.keys(fields).forEach(function (key) {
      var field = fields[key];
      var result = field.check(field.el.value);
      var problem = setError(field, result);
      if (problem) problems.push(problem);
    });
    return problems;
  }

  function showSummary(problems) {
    erroresLista.replaceChildren();
    if (!problems.length) {
      errores.hidden = true;
      return;
    }
    problems.forEach(function (problem) {
      var li = document.createElement("li");
      var a = document.createElement("a");
      a.href = "#" + problem.el.id;
      a.textContent = problem.message;
      li.appendChild(a);
      erroresLista.appendChild(li);
    });
    errores.hidden = false;
    errores.focus();
  }

  document.querySelectorAll("[data-producto]").forEach(function (plate) {
    plate.addEventListener("click", function () {
      var name = plate.getAttribute("data-producto");
      if (!name || !producto) return;
      producto.value = name;
      setError(fields.producto, true);
      producto.focus({ preventScroll: true });
    });
  });

  form.addEventListener("submit", function (event) {
    event.preventDefault();
    status.hidden = true;
    var problems = validate();
    showSummary(problems);
    if (problems.length) return;

    status.hidden = false;
    status.textContent =
      "Propuesta: el formulario valida, pero aún no envía. En producción esto llega a ventas@freeplast.cl — " +
      fields.producto.el.value +
      (fields.cantidad.el.value ? ", " + fields.cantidad.el.value + " u" : "") +
      ".";
    status.focus({ preventScroll: true });
  });

  Object.keys(fields).forEach(function (key) {
    fields[key].el.addEventListener("blur", function () {
      if (fields[key].el.getAttribute("aria-invalid") === "true") {
        setError(fields[key], fields[key].check(fields[key].el.value));
      }
    });
  });

  if (!window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
    var nodes = document.querySelectorAll(
      ".fact, .plate-feature, .mosaic .plate, .about-fig, .about-copy, .contact-copy, .quote"
    );
    function show(node) {
      node.style.opacity = "1";
      node.style.transform = "none";
    }
    nodes.forEach(function (node) {
      node.style.opacity = "0";
      node.style.transform = "translateY(12px)";
      node.style.transition = "opacity 350ms ease-out, transform 350ms ease-out";
    });
    var observer = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;
          show(entry.target);
          observer.unobserve(entry.target);
        });
      },
      { threshold: 0.12, rootMargin: "0px 0px -8% 0px" }
    );
    nodes.forEach(function (node) {
      observer.observe(node);
    });
    window.setTimeout(function () {
      nodes.forEach(show);
    }, 2500);
  }
})();
