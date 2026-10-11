import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

// Execute the actual shared bootstrap helper with WP-CLI transport simulated.
// A fresh Woo activation leaves this transient for the first authorized admin.
export function runBootstrapOnboardingTests() {
  const source = readFileSync(new URL('./bootstrap.mjs', import.meta.url), 'utf8');
  const helper = source.match(/function pinComingSoonOff\(\) \{[\s\S]*?\n\}/)?.[0];
  assert.ok(helper);
  const options = new Map();
  const transients = new Set(['_wc_activation_redirect']);
  const wp = ([command, action, key, value]) => {
    if (command === 'option' && action === 'update') { options.set(key, value); }
    else if (command === 'transient' && action === 'delete') { transients.delete(key); }
    else { throw new Error('Unexpected bootstrap fixture command'); }
  };
  const pin = new Function('wp', helper + '\nreturn pinComingSoonOff;')(wp);
  pin();
  assert.equal(transients.has('_wc_activation_redirect'), false, 'fresh owner inbox must not redirect into Woo onboarding');
  assert.equal(options.get('woocommerce_coming_soon'), 'no');
  pin(); // Existing stack also takes the same idempotent path.
  assert.equal(transients.size, 0);
  console.log('bootstrap onboarding: 4 offline fixture checks passed (HTTP admin gate unrun)');
  return 4;
}
