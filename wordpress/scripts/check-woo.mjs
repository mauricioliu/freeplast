#!/usr/bin/env node
// Offline unit checks + the disposable local stack (loopback only). Never touches staging or external hosts.
import {readdirSync,readFileSync,existsSync} from 'node:fs';
import {spawnSync} from 'node:child_process';
import {createHash} from 'node:crypto';
import {fileURLToPath} from 'node:url';
import path from 'node:path';
const root=fileURLToPath(new URL('..',import.meta.url));
const php=process.env.PHP_BINARY || path.join(root,'.tools/php/php');
if(!existsSync(php)) throw Error('PHP missing. Set PHP_BINARY to your PHP executable.');
function walk(dir){return readdirSync(dir,{withFileTypes:true}).flatMap(e=>e.isDirectory()?walk(path.join(dir,e.name)):[path.join(dir,e.name)]);}
function run(bin,args){const result=spawnSync(bin,args,{encoding:'utf8'});if(result.status!==0)throw Error(result.stderr||result.stdout);return result.stdout.trim();}
const files=[...walk(path.join(root,'wp-content/plugins/freeplast-woo')),...walk(path.join(root,'wp-content/themes/freeplast')),...walk(path.join(root,'wp-content/mu-plugins')),...['migrate-to-woo.php','verify-woo-state.php'].map(p=>path.join(root,'scripts',p))];
let checks=0;
for(const file of files){
 if(file.endsWith('.php')) {run(php,['-l',file]);checks++;}
 if(file.endsWith('.js')) {run(process.execPath,['--check',file]);checks++;}
 if(file.endsWith('.json')) {JSON.parse(readFileSync(file,'utf8'));checks++;}
 if(file.endsWith('.html') && /wp:freeplast\//.test(readFileSync(file,'utf8'))) throw Error('Legacy block in active theme: '+file);
}
const deps=JSON.parse(readFileSync(path.join(root,'woo-dependencies.json'),'utf8'));
for(const dep of Object.values(deps)){if(!/^[a-f0-9]{64}$/.test(dep.sha256)||!dep.url.startsWith('https://downloads.wordpress.org/plugin/'))throw Error('Unpinned dependency');checks++;}
// Issue #28: behavioral states of the variation add-to-cart button script (initial, selected, cleared, unavailable, pending).
const variationStateSource=readFileSync(path.join(root,'wp-content/themes/freeplast/assets/js/variation-button-state.js'),'utf8');
const variationState=new Function('module',variationStateSource+'\nreturn module.exports;')({exports:{}});
const DEFAULT_HINT='Selecciona Color para agregar este producto a Productos a Cotizar.';
function fakeButton(classes){const set=new Set(classes.split(' ').filter(Boolean));return {attrs:{},classList:{contains:c=>set.has(c)},setAttribute(n,v){this.attrs[n]=String(v);},removeAttribute(n){delete this.attrs[n];}};}
function fakeHint(){return {id:'fp-variation-hint-25',hidden:false,textContent:DEFAULT_HINT};}
function assertVariationState(ok,message){if(!ok)throw Error(message);checks++;}
function variationCase(classes){return {button:fakeButton(classes),hint:fakeHint()};}
// Initial (no colour chosen): unavailable, described by the visible instruction.
{
 const {button,hint}=variationCase('single_add_to_cart_button button alt wp-element-button disabled wc-variation-selection-needed');
 const state=variationState.stateOf(button);
 assertVariationState(state==='selection-needed','initial state is selection-needed');
 variationState.applyState(button,hint,state,hint.textContent,false);
 assertVariationState(button.attrs['aria-disabled']==='true','initial state exposes aria-disabled=true');
 assertVariationState(button.attrs['aria-describedby']==='fp-variation-hint-25','initial state links the instruction via aria-describedby');
 assertVariationState(hint.hidden===false&&hint.textContent===DEFAULT_HINT,'initial state shows the attribute-naming instruction');
 assertVariationState(!('aria-busy' in button.attrs),'initial state is not pending');
}
// Colour selected: enabled with explicit state, instruction hidden.
{
 const {button,hint}=variationCase('single_add_to_cart_button button alt wp-element-button');
 const state=variationState.stateOf(button);
 assertVariationState(state==='enabled','a chosen variation enables the button');
 variationState.applyState(button,hint,state,hint.textContent,false);
 assertVariationState(button.attrs['aria-disabled']==='false','enabled state is explicit aria-disabled=false');
 assertVariationState(!('aria-describedby' in button.attrs),'enabled state drops the instruction link');
 assertVariationState(hint.hidden===true,'enabled state hides the instruction');
}
// Selection cleared: back to the initial state, no false enable.
{
 const {button,hint}=variationCase('single_add_to_cart_button button alt wp-element-button');
 variationState.applyState(button,hint,'enabled',hint.textContent,false);
 variationState.applyState(button,hint,'selection-needed',hint.textContent,false);
 assertVariationState(button.attrs['aria-disabled']==='true'&&button.attrs['aria-describedby']==='fp-variation-hint-25','cleared selection restores the unavailable state');
 assertVariationState(hint.hidden===false&&hint.textContent===DEFAULT_HINT,'cleared selection restores the instruction');
}
// Unavailable combination: unavailable with its own instruction.
{
 const {button,hint}=variationCase('single_add_to_cart_button button alt wp-element-button disabled wc-variation-is-unavailable');
 const state=variationState.stateOf(button);
 assertVariationState(state==='unavailable','an unpurchasable combination is unavailable');
 variationState.applyState(button,hint,state,hint.textContent,false);
 assertVariationState(button.attrs['aria-disabled']==='true'&&hint.hidden===false,'unavailable state stays semantic');
 assertVariationState(hint.textContent===variationState.TEXTS.unavailable,'unavailable state swaps the instruction');
}
// Pending submission: busy and inert while the native form round-trips.
{
 const {button,hint}=variationCase('single_add_to_cart_button button alt wp-element-button');
 variationState.applyState(button,hint,variationState.stateOf(button),hint.textContent,true);
 assertVariationState(button.attrs['aria-busy']==='true'&&button.attrs['aria-disabled']==='true','pending submission exposes aria-busy and stays inert');
 assertVariationState(hint.textContent===variationState.TEXTS.pending,'pending submission announces itself in the instruction');
}
// Real DOM + jQuery event boundary: per-product units, not header line totals.
{ const {runLoopAddedCountTests}=await import('./loop-added-count-test.mjs');
  checks+=await runLoopAddedCountTests(); }

// Issue #41: A · Directa shared chrome script over the real header/footer
// markup — native dialogs, aria bookkeeping, focus return, current marking.
{ const {runChromeNavTests}=await import('./chrome-nav-test.mjs');
  checks+=await runChromeNavTests(); }

// Issue #42: A · Directa card controls — stepper around Woo's own quantity
// input, data-quantity mirror, header count + dock store bridge.
{ const {runCardControlsTests}=await import('./card-controls-test.mjs');
  checks+=await runCardControlsTests(); }
{ const {runCartPresentationTests}=await import('./cart-presentation-test.mjs');
  checks+=await runCartPresentationTests(); }

// Issue #44: A · Directa color controls over the single Woo variation form.
{ const {runProductColorTests}=await import('./product-color-test.mjs');
  checks+=await runProductColorTests(); }

// Issues #46/#47: A · Directa checkout enhancements — linked error summary,
// honest ambiguous transport wording, busy submit semantics.
{ const {runCheckoutFormTests}=await import('./checkout-form-js-test.mjs');
  checks+=await runCheckoutFormTests(); }
{ const {runNativeCheckoutTests}=await import('./checkout-native-test.mjs');
  checks+=await runNativeCheckoutTests(); }
{ const {runEvidenceFingerprintTests}=await import('./evidence-fingerprint-test.mjs');
  checks+=runEvidenceFingerprintTests(); }

console.log(run(php,[path.join(root,'scripts/test-woo-adapter.php')]));
console.log(run(php,[path.join(root,'scripts/card-selection-test.php')]));
console.log(run(php,[path.join(root,'scripts/card-theme-test.php')]));
console.log(run(php,[path.join(root,'scripts/catalog-tools-test.php')]));
console.log(run(php,[path.join(root,'scripts/catalog-native-frame-test.php')]));
console.log(run(php,[path.join(root,'scripts/product-sheet-test.php')]));
console.log(run(php,[path.join(root,'scripts/cart-page-test.php')]));
console.log(run(php,[path.join(root,'scripts/checkout-form-test.php')]));
console.log(run(php,[path.join(root,'scripts/confirmation-test.php')]));
console.log(run(php,[path.join(root,'scripts/native-chrome-test.php')]));
// Issue #36 final red-gate: the native plain-route helper's payload self-test
// (place-order submit trigger, no update_totals shortcut, native dispatch
// predicate) — offline, mocked Session, no HTTP/server.
console.log(run(process.env.PYTHON || 'python3',[path.join(root,'scripts/woo-checkout-race-selftest.py')]));
// Issue #37: execute the operator control flow with all I/O mocked, and hash
// complete native record data rather than guessing persistence from admin HTML.
console.log(run(process.env.PYTHON || 'python3',[path.join(root,'scripts/woo-ventas-guard-selftest.py')]));
console.log(run(php,[path.join(root,'scripts/woo-ventas-state-selftest.php')]));
console.log(run(process.env.PYTHON || 'python3',[path.join(root,'scripts/verify-ventas-role-selftest.py')]));
checks+=7+3+10+7;
for (const file of ['woo-ventas-state.php', 'woo-ventas-state-selftest.php', 'provision-ventas-fixture.php']) {
  run(php, ['-l', path.join(root, 'scripts', file)]); checks++;
}
run('bash',['-n',path.join(root,'infra/deploy-woo.sh')]);
// Issue #26: drive the REAL pinned cart-block store (vendored wc-blocks-data 11.1.0) with the
// REAL shipped feedback script through success, connection loss, server error and recovery.
{const {runCartStoreScenarios}=await import('./woo-cart-store-harness.mjs');
 const bundle=path.join(root,'scripts/vendor/wc-blocks-data-11.1.0.js');
 const sidecar=JSON.parse(readFileSync(path.join(root,'scripts/vendor/wc-blocks-data-11.1.0.json'),'utf8'));
 if(createHash('sha256').update(readFileSync(bundle)).digest('hex')!==sidecar.file_sha256) throw Error('Vendored wc-blocks-data hash mismatch against its sidecar');
 checks+=2; // bundle integrity + sidecar verification
 checks+=await runCartStoreScenarios(bundle,path.join(root,'wp-content/themes/freeplast/assets/js/cart-quantity-feedback.js'));}
// Issue #1 (Woo-side ports of #24/#27): boot the disposable WP+Woo stack and exercise the
// delivered Home card contract (WA-04) and the concurrent-checkout attempt claim (WA-01)
// over real HTTP — loopback only; the port is refused if a foreign server owns it.
{const {runStackHarness}=await import('./woo-stack-harness.mjs');
 checks+=await runStackHarness();}
console.log(`checks: ${checks+1} syntax, dependency and deployment checks passed`);
console.log(process.env.FREEPLAST_SKIP_STACK === '1'
  ? 'scope: offline only; native HTTP stack skipped; no staging, browser, device or visual approval'
  : 'scope: offline checks + disposable local stack; no staging, external hosts or visual approval');
