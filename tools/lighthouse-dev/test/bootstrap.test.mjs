import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { runInNewContext } from 'node:vm';
import { pinnedBootstrapSources, preparedBootstrap, busyWooFixture } from './woo-bootstrap-fixture.mjs';
const php = new URL('./fragment-bootstrap.php', import.meta.url).pathname;
test('busy no-capture fixture adds 32 real images without removing native bootstrap resources', () => {
  const render=busyWooFixture(), html=render({req:{url:'/?fp-measure=1'}}).body;
  assert.equal([...html.matchAll(/<img src="\/busy-\d+\.svg"/g)].length,32);
  assert.equal([...html.matchAll(/<script src=/g)].length,3);
  assert.match(html,/fp_measure_native_empty_v1/);
  for(let i=0;i<32;i++){
    const image=render({req:{url:`/busy-${i}.svg`}});
    assert.equal(image.headers['Content-Type'],'image/svg+xml');assert.match(image.body,/<svg/);
  }
});
test('LOCAL native projection gates keep offmode, other origins/routes/methods/users/sessions/carts unchanged', () => {
  for (const scenario of ['active','off','production','other-port','wrong-server','array-query','post','checkout','cookie','logged-in','session','nonempty','missing-cart','missing-session','missing-param','other-handle','hash']) {
    const result = JSON.parse(execFileSync('php',[php,scenario],{encoding:'utf8'}));
    assert.equal(result.scripts.length,scenario==='active'?1:0,scenario);
    if(scenario==='active') {
      assert.deepEqual(result.calls,['woocommerce_mini_cart','woocommerce_add_to_cart_fragments','get_cart_hash']);
      assert.doesNotMatch(result.scripts[0].script,/<\/script>/i);
      assert.match(result.scripts[0].script,/fp_measure_native_empty_v1/);
    }
  }
});
const sources=pinnedBootstrapSources(), bootstrap=preparedBootstrap();
const native=sources.get('/wp-content/plugins/woocommerce/assets/js/frontend/cart-fragments.min.js');
function exercise({seed=false,cookie='',existing=null,storageFails=false}={}) {
  const values=new Map(),local=new Map(),requests=[],replaced=[],events=[],handlers=new Map();
  const storage=map=>({getItem:k=>map.get(k)??null,setItem:(k,v)=>{if(storageFails)throw Error('storage unavailable');map.set(k,String(v));},removeItem:k=>map.delete(k)});
  if(existing!==null)values.set(bootstrap.params.fragment_name,existing);
  const api={on(names,fn){for(const name of names.split(' '))handlers.set(name,fn);return api;},closest(){return api;},hide(){return api;},show(){return api;},empty(){return api;},trigger(name){events.push(name);handlers.get(name)?.({});return api;},replaceWith(value){replaced.push(value);return api;}};
  const $=fn=>{if(typeof fn==='function')fn($);return api;};
  $.ajax=opts=>requests.push({method:opts.type,url:opts.url});$.each=(object,fn)=>Object.entries(object).forEach(([k,v])=>fn(k,v));
  const context={jQuery:$,document:{body:{},cookie},Cookies:{get:k=>k==='woocommerce_cart_hash'&&cookie?'nonempty':undefined},wc_cart_fragments_params:bootstrap.params,sessionStorage:storage(values),localStorage:storage(local),setTimeout:()=>1,clearTimeout:()=>{}};
  context.window=context;
  if(seed)runInNewContext(bootstrap.scripts[0].script,context);
  const afterSeed=new Map(values);
  runInNewContext(native,context);
  return {requests,replaced,events,afterSeed,handlers};
}
test('actual pinned Woo script reproduces cold POST; native empty projection takes existing cache branch, no fake endpoint',()=>{
  assert.deepEqual(exercise().requests,[{method:'POST',url:'/?wc-ajax=get_refreshed_fragments'}]);
  const prepared=exercise({seed:true});assert.deepEqual(prepared.requests,[]);assert.equal(prepared.replaced.length,4);assert.ok(prepared.events.includes('wc_fragments_loaded'));
  prepared.handlers.get('pageshow')({originalEvent:{persisted:true}});
  assert.deepEqual(prepared.requests,[{method:'POST',url:'/?wc-ajax=get_refreshed_fragments'}],'native BFCache restore necessarily refreshes even a seeded empty cart');
  assert.equal(prepared.afterSeed.size,2);assert.equal(prepared.afterSeed.get(bootstrap.params.cart_hash_key),'');
  for(const options of [{cookie:'woocommerce_cart_hash=existing'},{existing:'{}'},{storageFails:true}])assert.equal(exercise({seed:true,...options}).requests.length,1,'refused preparation must preserve native fallback, not suppress it');
});
