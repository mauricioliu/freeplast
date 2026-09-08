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
// Added-to-cart count pill (loop-added-count.js): pure helpers plus a fake DOM —
// the count MUST come from Woo's own fragment payload and never be invented.
{
 const addedCountSource=readFileSync(path.join(root,'wp-content/themes/freeplast/assets/js/loop-added-count.js'),'utf8');
 const addedCount=new Function('module',addedCountSource+'\nreturn module.exports;')({exports:{}});
 const assertPill=(ok,message)=>{if(!ok)throw Error(message);checks++;};
 const FRAG=(n)=>({'span.fpw-basket-count':'<span class="fpw-basket-count">'+n+'</span>'});
 function fakePillLink(){
  const el={
   children:[{nativeText:'View cart'}],
   attrs:{title:'View cart',href:'/cotizacion/'},
   className:'added_to_cart wc-forward',
   classList:{add(c){if((' '+el.className+' ').indexOf(' '+c+' ')===-1)el.className+=' '+c;}},
   appendChild(child){el.children.push(child);return child;},
   removeChild(child){const i=el.children.indexOf(child);if(i!==-1)el.children.splice(i,1);return child;},
   get firstChild(){return el.children[0]||null;},
   setAttribute(n,v){el.attrs[n]=String(v);},
   removeAttribute(n){delete el.attrs[n];},
   querySelector(sel){const wanted=sel.replace(/^\./,'');const walk=(node)=>{for(const child of node.children||[]){if(child.className&&child.className.split(/\s+/).indexOf(wanted)!==-1)return child;const deep=walk(child);if(deep)return deep;}return null;};return walk(el);}
  };
  return el;
 }
 function pillDoc(links){return {createElement(){return {className:'',textContent:'',children:[]};},querySelectorAll(){return links;}};}
 // extractCount: strict read of the adapter's pinned fragment shape.
 assertPill(addedCount.extractCount(FRAG(5))===5,'a well-formed fragment yields its count');
 assertPill(addedCount.extractCount({'span.fpw-basket-count':'<span class="fpw-basket-count"> 12 </span>'})===12,'whitespace around the count is tolerated');
 assertPill(addedCount.extractCount()===null&&addedCount.extractCount(null)===null,'a missing payload yields no count');
 assertPill(addedCount.extractCount({})===null&&addedCount.extractCount({'span.fpw-basket-count':7})===null,'a non-string fragment value yields no count');
 assertPill(addedCount.extractCount({'span.fpw-basket-count':'<span>5</span>'})===null,'a foreign fragment shape yields no count');
 assertPill(addedCount.extractCount({'span.fpw-basket-count':'<span class="fpw-basket-count">x</span>'})===null,'a non-numeric count yields no count');
 // updateAll: every .added_to_cart link is re-rendered from the payload.
 {
  const links=[fakePillLink(),fakePillLink()];
  const updated=addedCount.updateAll(pillDoc(links),FRAG(5));
  assertPill(updated===2,'both native links are re-rendered');
  for(const link of links){
   const badge=link.querySelector('.fp-added-pill__badge');
   assertPill(badge&&badge.textContent==='5','the badge carries the fragment count');
   assertPill(link.className.indexOf('fp-added-pill')!==-1,'the pill class marks the rendered link');
   assertPill(link.attrs.title===undefined,'the stale native title is dropped');
   assertPill(link.attrs['aria-label']==='5 en cotización — ver Productos a Cotizar','the accessible name starts with the visible text and names the destination');
   assertPill(link.children.length===2&&link.children.every(c=>!c.nativeText),'the native «View cart» text is replaced, not appended to');
  }
  // Idempotent re-render (a later add on the same page): badge reused, counts move.
  const second=addedCount.updateAll(pillDoc(links),FRAG(8));
  assertPill(second===2,'re-render still reports every link');
  for(const link of links){
   assertPill(link.children.length===2,'a re-render never duplicates the badge');
   assertPill(link.querySelector('.fp-added-pill__badge').textContent==='8','the badge follows the newer payload');
   assertPill(link.attrs['aria-label']==='8 en cotización — ver Productos a Cotizar','the accessible name follows the newer payload');
  }
 }
 // Unusable payloads leave WooCommerce's own links exactly as rendered.
 {
  const links=[fakePillLink()];
  assertPill(addedCount.updateAll(pillDoc(links),{})===0,'a payload without the count fragment updates nothing');
  assertPill(addedCount.updateAll(pillDoc(links),undefined)===0,'a missing payload updates nothing');
  assertPill(links[0].className==='added_to_cart wc-forward'&&links[0].children.length===1,'an untouched link keeps its native text, class and title');
 }
 // bind: the render runs one task AFTER the event so it also covers the link
 // Woo's own listener appends (binding order between scripts is not guaranteed).
 {
  const links=[fakePillLink()];
  const doc=Object.assign(pillDoc(links),{body:{}});
  const timeouts=[];
  const fakeJq=()=>({on(event,handler){fakeJq.handlers[event]=handler;return fakeJq;}});
  fakeJq.handlers={};
  const fakeWindow={jQuery:fakeJq,document:doc,setTimeout(fn){timeouts.push(fn);}};
  assertPill(addedCount.bind(fakeWindow)===true,'bind succeeds with jQuery present');
  assertPill(addedCount.bind({})===false,'bind refuses to bind without jQuery');
  fakeJq.handlers.added_to_cart({type:'added_to_cart'},FRAG(7),'hash',{});
  assertPill(timeouts.length===1,'the event schedules exactly one deferred render');
  assertPill(links[0].children.length===1,'nothing is rendered synchronously (Woo has not appended its link yet)');
  links.push(fakePillLink()); // Woo's own listener appends the new link in the same event
  timeouts.splice(0).forEach((fn)=>fn());
  assertPill(links.every((link)=>link.querySelector('.fp-added-pill__badge')&&link.querySelector('.fp-added-pill__badge').textContent==='7'),'the deferred render covers every link including the one Woo appended in the same event');
 }
}

console.log(run(php,[path.join(root,'scripts/test-woo-adapter.php')]));
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
