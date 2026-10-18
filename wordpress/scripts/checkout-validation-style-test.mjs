import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { Script } from 'node:vm';
import { JSDOM } from 'jsdom';

const css = readFileSync(new URL('../wp-content/themes/freeplast/style.css', import.meta.url), 'utf8');
const fixture = readFileSync(new URL('./fixtures/checkout-validation.html', import.meta.url), 'utf8');

/* Offline selector/declaration and contrast regression. Actual computed cascade and
 * focus are checked by runCheckoutValidationStyleChecks() in the inert HTML fixture. */
export function runCheckoutValidationStyleTests() {
  let checks = 0;
  const ok = (condition, message) => { checks++; assert.ok(condition, message); };
  const dom = new JSDOM(fixture);
  const { document } = dom.window;
  const sheet = document.createElement('style');
  sheet.textContent = css;
  document.head.append(sheet);
  const rules = [...sheet.sheet.cssRules].filter(rule => rule.selectorText);
  const ruleWith = (property, value) => rules.filter(rule => rule.style.getPropertyValue(property) === value);
  const borderRules = ruleWith('border-color', 'var(--fp-validation-error)');
  const focusRules = ruleWith('outline', '2px solid var(--fp-validation-error)');
  const messageRules = ruleWith('color', 'var(--fp-validation-error)');
  const matches = (node, candidates) => candidates.some(rule => node.matches(rule.selectorText));
  const get = id => document.getElementById(id);
  try {
    ok(ruleWith('--fp-validation-error', '#b42318').some(rule => rule.selectorText === '.fp-checkout-page'), 'error token is request-scoped');
    ok(borderRules.length === 2 && focusRules.length === 2 && messageRules.length === 1, 'bounded control/tile/message rules');
    // Control specificity (0,5,2) beats native Woo invalid input (0,4,2), even loaded later.
    const controls = borderRules.find(rule => rule.selectorText.includes(':is(input, textarea, select)'));
    ok(controls.selectorText.includes('.fp-checkout-page form.checkout .fp-fields .form-row.woocommerce-invalid'), 'Woo control override retains higher specificity');
    ok(controls.style.getPropertyValue('box-shadow') === 'none', 'invalid controls suppress old shadows');
    ok(controls.style.getPropertyValue('accent-color') === 'var(--fp-validation-error)', 'native radio error uses accent-color, not an ignored border');
    ok(rules.some(rule => rule.selectorText === '.fp-checkout-page .fp-dispatch-options input[type="radio"]' && rule.style.getPropertyValue('accent-color') === 'var(--fp-blue)'), 'normal radio accent stays blue');
    for (const rule of [...borderRules, ...focusRules]) {
      ok(!rule.style.getPropertyValue('color'), 'border/focus rules do not recolor labels or values');
      ok(!rule.selectorText.includes(':invalid'), 'native untouched invalidity never activates red');
    }
    for (const id of ['rut', 'email', 'name', 'untouched', 'address', 'commune', 'dispatch_si']) {
      const control = get(id), row = control.closest('.form-row');
      for (const signal of ['woo', 'aria', 'both', 'valid']) {
        row.classList.toggle('woocommerce-invalid', signal === 'woo' || signal === 'both');
        control.setAttribute('aria-invalid', String(signal === 'aria' || signal === 'both'));
        const invalid = signal !== 'valid';
        ok(matches(control, borderRules) === invalid, id + '/' + signal + ': border selector');
        control.focus();
        ok(matches(control, focusRules) === invalid, id + '/' + signal + ': focus selector');
      }
    }
    for (const id of ['aria_dispatch', 'native_dispatch']) {
      const control = get(id), tile = control.closest('label.radio') || control.nextElementSibling;
      ok(matches(tile, borderRules), id + ': aria tile selector');
      control.focus();
      ok(matches(tile, focusRules), id + ': focused tile selector');
      control.removeAttribute('aria-invalid');
      ok(!matches(tile, borderRules) && !matches(tile, focusRules), id + ': corrected tile');
    }
    const radio = get('dispatch_si'), row = radio.closest('.form-row'), tile = radio.closest('label.radio');
    row.classList.add('woocommerce-invalid');
    radio.focus();
    ok(matches(tile, borderRules) && matches(tile, focusRules), 'Woo tile error and focus');
    for (const message of document.querySelectorAll('.fp-checkout-page .fp-field-error, .fp-checkout-page .checkout-inline-error-message')) {
      ok(matches(message, messageRules), message.id + ': native/enhanced message');
      ok(Boolean(document.querySelector('[aria-describedby="' + message.id + '"]')), message.id + ': association retained');
    }
    ok(!matches(get('outside'), borderRules), 'outside controls excluded');
    ok(!matches(get('outside_error'), messageRules), 'outside messages excluded');
    ok(rules.some(rule => rule.selectorText === ':focus-visible' && rule.style.outline.includes('var(--fp-blue)')), 'normal global blue focus retained');
    const luminance = hex => {
      const rgb = hex.match(/[a-f0-9]{2}/gi).map(v => parseInt(v, 16) / 255).map(v => v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4);
      return rgb[0] * .2126 + rgb[1] * .7152 + rgb[2] * .0722;
    };
    const red = luminance('b42318');
    for (const background of ['ffffff', 'f4f5f7', 'eeedf8']) {
      const ratio = (luminance(background) + .05) / (red + .05);
      ok(ratio >= 4.5, 'message and border/focus contrast on #' + background + ': ' + ratio.toFixed(2));
    }
    new Script(document.querySelector('script').textContent);
    ok(fixture.includes("form-action 'none'") && fixture.includes("connect-src 'none'"), 'fixture cannot submit or call services');
    console.log(`checkout validation styles: ${checks} offline checks passed; browser computed checks remain separate`);
    return checks;
  } finally { dom.window.close(); }
}
