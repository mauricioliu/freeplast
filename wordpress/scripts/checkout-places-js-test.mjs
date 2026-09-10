import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

/* Issue #59 — behavioral checks for the dispatch-address assistance: the
 * official Places widget loads ONLY when configured and dispatch is wanted,
 * a selection records its claim (place id + match scope) beside the native
 * textarea, any edit or the «Sin despacho» choice invalidates it, and every
 * provider failure leaves a fully valid manual entry. The Google transport is
 * simulated (real credentials are a separate authorization); the native
 * serialization is asserted untouched. */
const source = readFileSync(new URL('../wp-content/plugins/freeplast-woo/places.js', import.meta.url), 'utf8');

const FORM = `
<form class="checkout woocommerce-checkout">
  <div class="fp-form-section">
    <div class="fp-fields">
      <div class="fp-dispatch-options">
        <p class="form-row" id="billing_fp_dispatch_field">
          <label id="fp-dispatch-label">¿Necesitas despacho?</label>
          <span class="woocommerce-input-wrapper" role="radiogroup">
            <label class="radio"><input type="radio" name="billing_fp_dispatch" value="si"></label>
            <label class="radio"><input type="radio" name="billing_fp_dispatch" value="no"></label>
          </span>
        </p>
      </div>
      <div class="fp-address-slot">
        <p class="form-row" id="billing_fp_address_field">
          <label for="billing_fp_address">Dirección de despacho</label>
          <span class="woocommerce-input-wrapper"><textarea id="billing_fp_address" name="billing_fp_address" cols="5" rows="2"></textarea></span>
        </p>
      </div>
      <input type="hidden" name="fpw_attempt" value="owned-fixture-attempt">
      <input type="hidden" name="fpw_place_id" value="">
      <input type="hidden" name="fpw_place_scope" value="">
    </div>
  </div>
</form>`;

const PLACE_EXACT = { id: 'ChIJexact-place-fixture_0000', formattedAddress: 'Camino El Arrayán 52, San Francisco de Mostazal, Región Metropolitana', types: ['street_address', 'geocode'] };
const PLACE_BROAD = { id: 'ChIJbroad-place-fixture_0000', formattedAddress: 'Camino El Arrayán, Mostazal', types: ['route', 'geocode'] };

function boot({ config = { key: 'browser-key-fixture', region: 'CL', version: 'weekly' }, google = null } = {}) {
  const dom = new JSDOM(`<!doctype html><body>${FORM}</body>`, { url: 'https://example.test/datos-y-envio/', runScripts: 'outside-only' });
  const w = dom.window;
  w.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
  w.FPW_PLACES = config;
  const created = [];
  if (google === 'quota') {
    w.google = { maps: { importLibrary: () => Promise.reject(new Error('quota exceeded')) } };
  } else if (google === 'script-error') {
    /* neither google nor a loader: the injected maps script must be observed */
  } else if (google) {
    w.google = { maps: { importLibrary: (library) => {
      created.push(library);
      return Promise.resolve({ PlaceAutocompleteElement: function FakeWidget(options) {
        const el = w.document.createElement('gmp-place-autocomplete');
        el.options = options;
        el.disabled = false;
        el.select = (place) => { const event = new w.Event('gmp-placeselect'); event.place = place; el.dispatchEvent(event); };
        created.push(el);
        return el;
      } });
    } } };
  }
  w.eval(source);
  w.document.dispatchEvent(new w.Event('DOMContentLoaded', { bubbles: true }));
  return { dom, w, created, form: w.document.querySelector('form.checkout') };
}

export async function runCheckoutPlacesTests() {
  let checks = 0;
  const ok = (condition, label) => { checks++; assert.ok(condition, label); };
  const instances = [];
  const env = (options) => { const value = boot(options); instances.push(value); return value; };
  const chooseDispatch = (e, value) => {
    const input = e.form.querySelector(`input[name="billing_fp_dispatch"][value="${value}"]`);
    input.checked = true;
    input.dispatchEvent(new e.w.Event('change', { bubbles: true }));
  };
  const type = (e, text) => {
    const field = e.form.querySelector('#billing_fp_address');
    field.value = text;
    field.dispatchEvent(new e.w.Event('input', { bubbles: true }));
  };
  const flush = () => new Promise((resolve) => setTimeout(resolve, 20));
  const serializeForm = (e) => {
    /* the native plain-POST shape: one value per name, hidden carriers included */
    const pairs = [];
    e.form.querySelectorAll('[name]').forEach((node) => {
      if (node.type === 'radio' && !node.checked) { return; }
      pairs.push([node.name, node.value]);
    });
    return pairs;
  };

  /* Without configuration the enhancement stays inert: no Google contact at all. */
  {
    const e = await env({ config: {}, google: null });
    chooseDispatch(e, 'si'); await flush();
    ok(!e.form.hasAttribute('data-fpw-places'), 'no configuration means no enhancement marker on the form');
    ok(!e.w.document.querySelector('.fpw-places-host'), 'no configuration means no widget host');
    ok(!e.created.length, 'no configuration means no Google library request');
    ok(serializeForm(e).filter(([name]) => name === 'billing_fp_address').length === 1, 'manual entry keeps its native serialization');
  }

  /* Configured + dispatch=si: the official library loads once, the widget joins
     the slot above the native textarea, and no name ever rides the widget. */
  {
    const e = await env({ google: true });
    ok(!e.created.length, 'nothing loads while the form opens without a dispatch choice');
    chooseDispatch(e, 'si'); await flush();
    ok(e.created.filter((name) => name === 'places').length === 1, 'dispatch wanted: the official places library is requested once');
    const widget = e.w.document.querySelector('.fpw-places-host gmp-place-autocomplete');
    ok(Boolean(widget), 'the widget joins the address slot');
    ok(widget.getAttribute('data-fpw-places-widget') !== null && !widget.hasAttribute('name'), 'the assistant carries an explicit marker and NO name: it never serializes');
    const host = e.w.document.querySelector('.fpw-places-host');
    const row = e.w.document.getElementById('billing_fp_address_field');
    ok(host.parentNode === e.w.document.querySelector('.fp-address-slot') && (host.nextSibling === row), 'the widget sits above the native textarea row without replacing it');
    ok(e.form.querySelector('#billing_fp_address').name === 'billing_fp_address', 'the native textarea keeps its name and label');
    ok(e.w.document.querySelector('.fpw-places-status').getAttribute('role') === 'status', 'the provenance status is a live region');
    chooseDispatch(e, 'no'); await flush();
    chooseDispatch(e, 'si'); await flush();
    ok(e.created.filter((name) => name === 'places').length === 1, 'toggling dispatch never reloads the library');
    ok(e.w.document.querySelector('.fpw-places-host gmp-place-autocomplete') === widget, 'the same widget returns when dispatch comes back');
  }

  /* Exact selection: the confirmed address fills the native textarea and the
     claim rides only the registered carriers. */
  {
    const e = await env({ google: true });
    chooseDispatch(e, 'si'); await flush();
    const widget = e.w.document.querySelector('gmp-place-autocomplete');
    widget.select(PLACE_EXACT); await flush();
    const field = e.form.querySelector('#billing_fp_address');
    ok(field.value === PLACE_EXACT.formattedAddress, 'the confirmed place composes the native textarea (the customer sees and keeps control)');
    const values = Object.fromEntries(serializeForm(e));
    ok(values.fpw_place_id === PLACE_EXACT.id && values.fpw_place_scope === 'exacta', 'an exact selection records its place id and exact scope');
    const status = e.w.document.querySelector('.fpw-places-status');
    ok(status.textContent.includes('confirmada'), 'the status names the confirmed provenance');
    ok(!status.textContent.includes('acceso certificado'), 'no deliverability certification is ever claimed');

    /* Editing the text invalidates the previous Place ID. */
    type(e, 'Camino El Arrayán 52 depto 3'); await flush();
    const edited = Object.fromEntries(serializeForm(e));
    ok(edited.fpw_place_id === '' && edited.fpw_place_scope === '', 'editing the address invalidates the previous Place ID: only manual remains');
    ok(status.textContent.includes('manualmente'), 'the status names the manual turn');

    /* A broad match after an edit records amplia, honestly. */
    widget.select(PLACE_BROAD); await flush();
    const broad = Object.fromEntries(serializeForm(e));
    ok(field.value === PLACE_BROAD.formattedAddress && broad.fpw_place_id === PLACE_BROAD.id && broad.fpw_place_scope === 'amplia', 'a road-level match records its broad scope');
    ok(status.textContent.includes('amplia') || status.textContent.includes('coincidencia amplia'), 'the status distinguishes the broad match from a certified point');

    /* Clearing the field invalidates too. */
    type(e, ''); await flush();
    const cleared = Object.fromEntries(serializeForm(e));
    ok(cleared.fpw_place_id === '' && cleared.fpw_place_scope === '', 'clearing the address invalidates the association as well');
  }

  /* Provider failure: manual entry stays fully valid and honestly announced. */
  {
    const e = await env({ google: 'quota' });
    chooseDispatch(e, 'si'); await flush();
    const status = e.w.document.querySelector('.fpw-places-status');
    ok(status.hidden === false && status.textContent.includes('Escribe tu dirección manualmente'), 'a quota/key failure announces the manual path');
    ok(status.textContent.includes('sigue siendo válida'), 'the failure never invalidates the customer\'s request');
    ok(!e.w.document.querySelector('.fpw-places-host gmp-place-autocomplete'), 'no half-built widget remains after the failure');
    type(e, 'Camino rural sin asistente, Mostazal'); await flush();
    const values = Object.fromEntries(serializeForm(e));
    ok(values.billing_fp_address === 'Camino rural sin asistente, Mostazal' && values.fpw_place_id === '' && values.fpw_place_scope === '', 'manual entry stays serializable and carries no claim');
  }

  /* Script-level transport failure (network): same honest outcome. */
  {
    const e = await env({ google: 'script-error' });
    chooseDispatch(e, 'si'); await flush();
    const script = e.w.document.querySelector('script[src*="maps.googleapis.com"]');
    ok(Boolean(script), 'the official maps script is the only Google contact');
    ok(script.src.includes('libraries=places') && script.src.includes('key=browser-key-fixture'), 'the script requests only the places library with the configured key');
    ok(!script.src.includes('billing_'), 'no form field travels in the loader URL');
    script.dispatchEvent(new e.w.Event('error'));
    await flush();
    const status = e.w.document.querySelector('.fpw-places-status');
    ok(status.hidden === false && status.textContent.includes('Escribe tu dirección manualmente'), 'a failed load announces the manual path');
  }

  /* «Sin despacho»: the claim is dropped, not merely hidden. */
  {
    const e = await env({ google: true });
    chooseDispatch(e, 'si'); await flush();
    const widget = e.w.document.querySelector('gmp-place-autocomplete');
    widget.select(PLACE_EXACT); await flush();
    chooseDispatch(e, 'no'); await flush();
    const values = Object.fromEntries(serializeForm(e));
    ok(values.fpw_place_id === '' && values.fpw_place_scope === '', 'choosing no dispatch drops the place association');
    ok(e.form.querySelector('#billing_fp_address').value === PLACE_EXACT.formattedAddress, 'the typed draft itself stays (the server owns the exclusion)');
    chooseDispatch(e, 'si'); await flush();
    const revived = Object.fromEntries(serializeForm(e));
    ok(revived.fpw_place_id === '', 'returning to dispatch never resurrects a stale claim');
  }

  /* Re-init is inert: a second run never duplicates hosts or listeners. */
  {
    const e = await env({ google: true });
    e.w.eval(source);
    e.w.document.dispatchEvent(new e.w.Event('DOMContentLoaded', { bubbles: true }));
    chooseDispatch(e, 'si'); await flush();
    ok(e.w.document.querySelectorAll('.fpw-places-host').length === 1, 'a second boot never duplicates the widget host');
  }

  for (const e of instances) { e.dom.window.close(); }
  console.log(`checkout places: ${checks} A · Directa dispatch-assistance checks passed (simulated Places transport; manual entry always valid)`);
  return checks;
}
