/* Google selection is mandatory for dispatch; the server confirms the ID.
   Only the search query reaches the widget, never the separate delivery notes. */
(function () {
  'use strict';
  var config = window.FPW_PLACES || {};
  var TEXTS = {
    confirmed: 'Dirección confirmada con el asistente de direcciones.',
    broad: 'Seleccionaste una calle o sector. Puedes agregar referencias para llegar debajo de la dirección.',
    manual: 'La búsqueda cambió. Selecciona otra sugerencia de Google.',
    unconfirmed: 'Google no confirmó esa dirección. Selecciona otra sugerencia o recarga y vuelve a intentarlo.',
    empty: 'Busca tu dirección y selecciona una sugerencia de Google.',
    failed: 'Google no está disponible. No podemos recibir solicitudes con despacho sin confirmar la dirección. Recarga y vuelve a intentarlo.'
  };
  /* A route, commune or region is a REFERENCE, not a certified delivery point:
     the recorded scope lets the private review judge it (no access claim). */
  var EXACT_TYPES = ['street_address', 'premise', 'subpremise', 'establishment', 'point_of_interest'];
  var state = { widget: null, loading: false, failed: false, provenance: false, revision: 0 };

  function announce(status, message) {
    if (!status) { return; }
    status.hidden = false;
    status.textContent = message;
  }

  function carriers(form) {
    return {
      id: form.querySelector('[name="fpw_place_id"]'),
      scope: form.querySelector('[name="fpw_place_scope"]')
    };
  }

  function setProvenance(form, id, scope) {
    var fields = carriers(form);
    if (!fields.id || !fields.scope) { return; }
    fields.id.value = id;
    fields.scope.value = scope;
    state.provenance = true;
  }

  function clearProvenance(form) {
    state.revision += 1;
    var field = form.querySelector('#billing_fp_address');
    if (field) { field.value = ''; }
    var fields = carriers(form);
    if (!fields.id || !fields.scope) { return; }
    fields.id.value = '';
    fields.scope.value = '';
    state.provenance = false;
  }

  /* The documented async Maps-JS bootstrap: key + version + the places library
     only — no customer data rides the loader; the typed query goes exclusively
     through the widget's own prediction requests. */
  function loadMaps() {
    if (window.google && window.google.maps && typeof window.google.maps.importLibrary === 'function') {
      return Promise.resolve();
    }
    return new Promise(function (resolve, reject) {
      var settled = false;
      var timer = window.setTimeout(function () { settle(reject, new Error('maps-timeout')); }, 10000);
      var script = document.createElement('script');
      script.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(config.key || '')
        + '&libraries=places&v=' + encodeURIComponent(config.version || 'weekly')
        + '&loading=async&callback=fpwPlacesLoaded';
      script.async = true;
      function settle(fn, error) {
        if (settled) { return; }
        settled = true;
        window.clearTimeout(timer);
        delete window.fpwPlacesLoaded;
        fn(error);
      }
      window.fpwPlacesLoaded = function () { settle(resolve); };
      script.addEventListener('error', function () { settle(reject, new Error('maps-script-error')); });
      document.head.appendChild(script);
    });
  }

  function importPlaces() {
    return loadMaps().then(function () {
      var maps = window.google && window.google.maps;
      if (!maps || typeof maps.importLibrary !== 'function') { throw new Error('maps-unavailable'); }
      return maps.importLibrary('places');
    }).then(function (places) {
      if (!places || typeof places.PlaceAutocompleteElement !== 'function') { throw new Error('places-unavailable'); }
      return places.PlaceAutocompleteElement;
    });
  }

  function scopeOf(types) {
    var exact = types.some(function (type) { return EXACT_TYPES.indexOf(type) !== -1; });
    return exact ? 'exacta' : 'amplia';
  }

  function buildWidget(PlaceAutocompleteElement, context) {
    var widget = new PlaceAutocompleteElement({ includedRegionCodes: [context.region] });
    // Broad street/rural matches remain valid; references are separate.
    widget.setAttribute('data-fpw-places-widget', '');
    widget.setAttribute('aria-label', 'Buscar una dirección');
    widget.addEventListener('input', function () {
      clearProvenance(context.form);
      announce(context.status, TEXTS.manual);
    });
    widget.addEventListener('gmp-error', function () {
      clearProvenance(context.form);
      announce(context.status, TEXTS.failed);
    });
    widget.addEventListener('gmp-select', function (event) {
      clearProvenance(context.form);
      var revision = state.revision;
      var prediction = event && event.placePrediction;
      if (!prediction || typeof prediction.toPlace !== 'function') { return; }
      Promise.resolve().then(function () {
        var place = prediction.toPlace();
        return place.fetchFields({ fields: ['id', 'formattedAddress', 'types'] }).then(function () { return place; });
      }).then(function (place) {
        if (revision !== state.revision || !wantsDispatch(context.form)) { return; }
        var id = place.id || place.place_id || '';
        var formatted = place.formattedAddress || place.formatted_address || '';
        if (!id || !formatted) { announce(context.status, TEXTS.unconfirmed); return; }
        var scope = scopeOf(Array.isArray(place.types) ? place.types : []);
        context.field.value = formatted; // programmatic: fires no input event, so the claim survives its own fill
        setProvenance(context.form, id, scope);
        announce(context.status, 'exacta' === scope ? TEXTS.confirmed : TEXTS.broad);
      }).catch(function () {
        if (revision !== state.revision || !wantsDispatch(context.form)) { return; }
        announce(context.status, TEXTS.unconfirmed);
      });
    });
    return widget;
  }

  function wantsDispatch(form) {
    var checked = form.querySelector('input[name="billing_fp_dispatch"]:checked');
    return Boolean(checked) && checked.value === 'si';
  }

  function init() {
    var form = document.querySelector('form.checkout');
    var field = document.getElementById('billing_fp_address');
    var slot = field && field.closest('.fp-address-slot');
    if (!form || !field || !slot || !config.key || form.hasAttribute('data-fpw-places')) { return; }
    form.setAttribute('data-fpw-places', '');
    var row = document.getElementById('billing_fp_address_field');
    var host = document.createElement('div');
    host.className = 'fpw-places-host';
    host.hidden = true;
    var status = document.createElement('p');
    status.className = 'fpw-places-status';
    status.setAttribute('role', 'status');
    status.hidden = true;
    if (row && row.parentNode === slot) { slot.insertBefore(host, row); } else { slot.insertBefore(host, slot.firstChild); }
    if (row && row.nextSibling) { slot.insertBefore(status, row.nextSibling); } else { slot.appendChild(status); }
    var context = { form: form, field: field, status: status, region: (config.region || 'CL').toUpperCase() };
    field.readOnly = true;
    field.rows = 2;
    clearProvenance(form);
    form.addEventListener('submit', function (event) {
      if (wantsDispatch(form) && !carriers(form).id.value) {
        event.preventDefault();
        event.stopImmediatePropagation();
        announce(status, TEXTS.empty);
        if (state.widget) { state.widget.focus(); }
      }
    }, true);

    field.addEventListener('input', function () {
      if (!state.provenance) { return; }
      clearProvenance(form);
      announce(status, field.value.trim() ? TEXTS.manual : TEXTS.empty);
    });

    function syncDispatch() {
      var checked = form.querySelector('input[name="billing_fp_dispatch"]:checked');
      var wanted = Boolean(checked) && checked.value === 'si';
      if (!wanted) {
        // The destination and its associations leave the record at the server;
        // here the claim is dropped so nothing stale rides the next submit.
        clearProvenance(form);
        status.hidden = true;
        status.textContent = '';
        if (state.widget) { state.widget.disabled = true; }
        return;
      }
      if (state.widget) { state.widget.disabled = false; return; }
      if (state.loading || state.failed) { return; }
      state.loading = true;
      importPlaces().then(function (PlaceAutocompleteElement) {
        state.widget = buildWidget(PlaceAutocompleteElement, context);
        host.appendChild(state.widget);
        host.hidden = false;
        state.loading = false;
        state.widget.disabled = !wantsDispatch(form);
      }).catch(function () {
        state.loading = false;
        state.failed = true;
        host.hidden = true;
        announce(status, TEXTS.failed);
      });
    }

    form.addEventListener('change', function (event) {
      if (event.target && event.target.name === 'billing_fp_dispatch') { syncDispatch(); }
    });
    syncDispatch();
  }

  if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
  }
})();
