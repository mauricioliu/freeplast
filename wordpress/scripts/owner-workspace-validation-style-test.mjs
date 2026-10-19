import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { Script } from 'node:vm';
import { JSDOM } from 'jsdom';

const css = readFileSync(new URL('../wp-content/plugins/freeplast-woo/assets/owner-workspace.css', import.meta.url), 'utf8');
const fixture = readFileSync(new URL('./fixtures/owner-workspace-validation.html', import.meta.url), 'utf8');

// Offline selector/declaration checks; browser cascade and keyboard evidence belong to the fixture.
export function runOwnerWorkspaceValidationStyleTests() {
  let checks = 0;
  const ok = (condition, message) => { checks++; assert.ok(condition, message); };
  const dom = new JSDOM(fixture);
  const { document } = dom.window;
  try {
    const style = document.createElement('style');
    style.textContent = css;
    document.head.append(style);
    const rules = [...style.sheet.cssRules].filter(rule => rule.selectorText);
    const errorRules = rules.filter(rule => rule.cssText.includes('--fp-validation-error'));
    const matching = (node, property) => errorRules.some(rule => rule.style.getPropertyValue(property).includes('var(--fp-validation-error)') && node.matches(rule.selectorText));
    const get = id => document.getElementById(id);
    ok(errorRules.length === 5, 'bounded token/control/tile/message/summary overrides');
    ok(errorRules.every(rule => rule.selectorText.split(',').every(selector => selector.trim().startsWith('.fpw-workspace-editable'))), 'all overrides scoped to editable workspace');
    ok(errorRules.some(rule => rule.selectorText === '.fpw-workspace-editable' && rule.style.getPropertyValue('--fp-validation-error') === '#b42318'), 'reuse accessible semantic token');
    const controlRule = errorRules.find(rule => rule.selectorText.endsWith('#fpw-work-form [aria-invalid="true"]'));
    ok(controlRule && controlRule.style.getPropertyValue('box-shadow') === 'none', 'form ID specificity beats price and global focus; suppress blue shadow');
    ok(controlRule.style.getPropertyValue('accent-color') === 'var(--fp-validation-error)', 'native radios use accent');
    ok(!controlRule.style.getPropertyValue('color'), 'values retain ink');
    ok(errorRules.every(rule => !rule.selectorText.includes(':invalid')), 'untouched native invalidity never triggers red');
    for (const field of document.querySelectorAll('#fpw-work-form [aria-describedby]')) {
      ok(matching(field, 'border-color') && matching(field, 'outline'), field.id + ': invalid border and focus');
      const message = get(field.getAttribute('aria-describedby'));
      ok(message && matching(message, 'color'), field.id + ': associated red message');
      const tile = field.type === 'radio' ? field.closest('label') : null;
      if (tile) {
        ok(matching(tile, 'border-color') && matching(tile, 'outline-color') && matching(tile, 'box-shadow'), field.id + ': tile selected/focus overrides');
        field.checked = !field.checked;
        ok(matching(tile, 'border-color'), field.id + ': checked/unchecked');
      }
      for (const state of ['false', null]) {
        if (state === null) field.removeAttribute('aria-invalid');
        else field.setAttribute('aria-invalid', state);
        ok(!matching(field, 'border-color') && !matching(field, 'outline'), field.id + ': corrected ' + state);
        if (tile) ok(!matching(tile, 'border-color'), field.id + ': corrected tile ' + state);
      }
    }
    for (const id of ['server-summary', 'js-summary']) {
      ok(matching(get(id), 'color') && matching(get(id), 'border-color'), id + ': both summary placements');
    }
    for (const id of ['outside', 'outside-error', 'outside-summary', 'public', 'info', 'success', 'conflict', 'untouched']) {
      ok(!errorRules.some(rule => rule.style.getPropertyValue('color') && get(id).matches(rule.selectorText)), id + ': no red text leak');
      ok(!matching(get(id), 'border-color') && !matching(get(id), 'outline'), id + ': no red control leak');
    }
    document.querySelector('.fpw-workspace-editable').classList.remove('fpw-workspace-editable');
    ok(!matching(get('server-summary'), 'color'), 'summary excluded when editor inactive');
    const luminance = hex => {
      const channels = hex.match(/../g).map(channel => parseInt(channel, 16) / 255).map(value => value <= .04045 ? value / 12.92 : ((value + .055) / 1.055) ** 2.4);
      return channels[0] * .2126 + channels[1] * .7152 + channels[2] * .0722;
    };
    for (const background of ['ffffff', 'f4f5f7', 'eeedf8']) {
      const ratio = (luminance(background) + .05) / (luminance('b42318') + .05);
      ok(ratio >= 4.5, '#' + background + ': text >=4.5 and border/focus >=3 (' + ratio.toFixed(2) + ')');
    }
    new Script(document.querySelector('script').textContent);
    ok(fixture.includes("form-action 'none'") && fixture.includes("connect-src 'none'"), 'fixture blocks submissions and service calls');
    ok(fixture.includes('../../wp-content/plugins/freeplast-woo/assets/owner-workspace.css'), 'fixture loads real stylesheet');
    console.log(`owner workspace validation styles: ${checks} offline checks passed; browser checks separate`);
    return checks;
  } finally { dom.window.close(); }
}
