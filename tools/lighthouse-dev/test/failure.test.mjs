import test from 'node:test';
import assert from 'node:assert/strict';
import { publicTarget, publicInitiator, failureEvidence } from '../src/failure.mjs';
const origin = 'http://mliu:8131';
test('failure vocabulary keeps exact source-known action, never query/body/header/private path', () => {
  assert.deepEqual(publicTarget(origin+'/?wc-ajax=get_refreshed_fragments&token=SECRET',origin), {origin:'approved',path:'/',action:'get_refreshed_fragments'});
  assert.deepEqual(publicTarget(origin+'/private/SECRET?wc-ajax=SECRET',origin), {origin:'approved',path:null,action:null});
  assert.equal(publicTarget('https://other.test/SECRET',origin).path,null);
  assert.equal(publicTarget(origin+'/?wc-ajax=get_refreshed_fragments&wc-ajax=SECRET',origin).action,null);
  const path='/wp-content/plugins/woocommerce/assets/js/frontend/cart-fragments.min.js';
  assert.equal(publicInitiator({stack:{callFrames:[{url:origin+'/wp-includes/js/jquery/jquery.min.js'},{url:origin+path+'?token=SECRET'}]}},origin),path);
  const e=failureEvidence({diagnostic:{source:'renderer-fetch',method:'POST',target:publicTarget(origin+'/?wc-ajax=get_refreshed_fragments',origin),postData:'SECRET',headers:{cookie:'SECRET'},message:'SECRET',syntheticTarget:'SECRET'},proxy:{nonReadOnlyUpstreamRequests:0,activeRequests:0},cleanup:{browserExitObserved:true},worker:{processGroupAbsent:true}});
  assert.doesNotMatch(JSON.stringify(e),/SECRET|postData|headers|cookie|syntheticTarget/);
  assert.equal(e.proxy.nonReadOnlyUpstreamRequests,0);assert.equal(e.diagnostic.method,'POST');
  assert.equal(failureEvidence().proxy.nonReadOnlyUpstreamRequests,null,'missing evidence never means zero');
  assert.equal(failureEvidence({proxy:{activeRequests:-1}}).proxy.activeRequests,null);
});
