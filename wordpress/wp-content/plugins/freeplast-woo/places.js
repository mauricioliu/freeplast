/* Dispatch-address assistance (issue #59, cut 10 of #49): the official Google
   Places autocomplete widget joins the address slot ABOVE the native textarea —
   never replacing it. Manual entry stays valid with Google absent, quota-hit or
   failed: the assistant is an aid, not a dependency. A selection fills the
   textarea with the composed address and records a CLAIM (place id + match
   scope) in the adapter's registered carriers; editing or clearing the text —
   or choosing «Sin despacho» — invalidates it. The widget itself carries no
   name, so the native POST serialization is untouched, and nothing but the
   typed address ever reaches Google (no RUT, email, history or products). */
(function () {
  'use strict';
  var config = window.FPW_PLACES || {};
  var TEXTS = {
    confirmed: 'Dirección confirmada con el asistente de direcciones.',
    broad: 'El asistente encontró una coincidencia amplia (calle o comuna). Complementa tu dirección; si la editas, queda ingresada manualmente.',
    manual: 'Editaste tu dirección: se ingresa manualmente, sin asociación al asistente.',
    unconfirmed: 'El asistente no confirmó esa dirección. Escríbela manualmente: tu solicitud sigue siendo válida.',
    empty: 'Busca tu dirección con el asistente o escríbela manualmente.',
    failed: 'No pudimos cargar el asistente de direcciones (proveedor, cuota o clave). Escribe tu dirección manualmente: tu solicitud sigue siendo válida.'
  };
  /* A route, commune or region is a REFERENCE, not a certified delivery point:
     the recorded scope lets the private review judge it (no access claim). */
  var EXACT_TYPES = ['street_address', 'premise', 'subpremise', 'establishment', 'point_of_interest'];
  var state = { widget: null, loading: false, failed: false, provenance: false };

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
      var script = document.createElement('script');
      script.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(config.key || '')
        + '&libraries=places&v=' + encodeURIComponent(config.version || 'weekly')
        + '&loading=async&callback=fpwPlacesLoaded';
      script.async = true;
      function settle(fn, error) {
        if (settled) { return; }
        settled = true;
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
    /* Suggestion aid only: the region filter never defines Freeplast's
       coverage policy, and a rural address outside the suggestions stays a
       valid manual entry. */
    widget.setAttribute('data-fpw-places-widget', '');
    widget.setAttribute('aria-label', 'Buscar una dirección');
    widget.addEventListener('gmp-placeselect', function (event) {
      var place = event && event.place;
      if (!place) { return; }
      Promise.resolve(
        typeof place.fetchFields === 'function'
          ? place.fetchFields({ fields: ['id', 'formattedAddress', 'types'] })
          : null
      ).then(function () {
        var id = place.id || place.place_id || '';
        var formatted = place.formattedAddress || place.formatted_address || '';
        if (!id || !formatted) { announce(context.status, TEXTS.unconfirmed); return; }
        var scope = scopeOf(Array.isArray(place.types) ? place.types : []);
        context.field.value = formatted; // programmatic: fires no input event, so the claim survives its own fill
        setProvenance(context.form, id, scope);
        announce(context.status, 'exacta' === scope ? TEXTS.confirmed : TEXTS.broad);
      }).catch(function () {
        announce(context.status, TEXTS.unconfirmed);
      });
    });
    return widget;
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
  if (typeof module === 'object' && module.exports) {
    module.exports = { TEXTS: TEXTS };
  }
})();
