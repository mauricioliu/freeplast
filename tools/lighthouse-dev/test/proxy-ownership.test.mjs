import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import net from 'node:net';
import { policy, checkRequest } from '../src/policy.mjs';
import { publicTarget, urlShape, failureEvidence } from '../src/failure.mjs';
import { proxyUrlVectors, directProxyVectors } from './proxy-vectors.mjs';
import { filteringProxy, proxyOwnerHeader } from '../src/proxy.mjs';
import { fixtureAdapter, fixtureHtml } from './fixture-adapter.mjs';
const grant = (proxy,url,method='GET') => { proxy.authorize(url,method); const c=proxy.authenticate(url,method); return 'Basic '+Buffer.from(c.username+':'+c.password).toString('base64'); };
const proof = n => ({ httpRequestsAuthorized:n, allTargetsGuarded:true, httpsContinued:0, websocketCreations:0, networkEnabled:true, fetchEnabled:true });
const send = (proxy, fixture, { path=fixture.origin+'/', method='GET', host=new URL(fixture.origin).host, token }={}) => new Promise((resolve,reject)=>{
  const request=http.request(proxy.url,{path,method,headers:{host,...(token?{[proxyOwnerHeader]:token}:{})}},response=>{response.resume();response.on('end',()=>resolve(response.statusCode));});
  request.on('error',reject);request.end();
});
test('unowned origin-form non-root HostOTHER is 403 without poisoning complete owned traffic',async()=>{
 const f=await fixtureAdapter(()=>({body:fixtureHtml('owned page')})),p=await filteringProxy(f.policy,'127.0.0.1');let failure;p.failed.catch(e=>{failure=e;});p.arm();
 try{
  assert.equal(await send(p,f,directProxyVectors[1]),403);
  assert.equal(f.hits.length,0);assert.equal(p.snapshot().upstreamRequests,0);assert.equal(failure,undefined);
  const token=grant(p,f.origin+'/');assert.equal(await send(p,f,{token}),200);
  const result=p.verify(proof(1));assert.equal(result.unownedDenials,1);assert.equal(result.upstreamRequests,1);assert.equal(result.pendingAuthorizations,0);assert.equal(result.unownedSamples[0].urlShape.host,'other');assert.equal(result.unownedSamples[0].urlShape.form,'origin-form');assert.equal(result.unownedSamples[0].ownedRequest,false);assert.equal(result.unownedSamples[0].proxySocket,true);assert.equal(result.unownedSamples[0].loopbackPeer,true);assert.doesNotMatch(JSON.stringify(result),/PRIVATE_TEST|unowned\.invalid/);assert.ok(f.hits.every(h=>!h.controlHeaderSeen));
 }finally{await p.close();await f.close();}
});

const rawRequest = (proxy, request) => new Promise((resolve,reject)=>{
 const socket=net.connect({host:'127.0.0.1',port:new URL(proxy.url).port});let response='';
 socket.on('data',data=>{response+=data;});socket.once('error',reject);socket.once('close',()=>resolve(Number(/^HTTP\/1\.[01] (\d+)/.exec(response)?.[1])||null));socket.once('connect',()=>socket.end(request));
});
test('direct-server HEAD, missing Host and encoded path/query deny without reconstruction',async()=>{
 const f=await fixtureAdapter(()=>({body:fixtureHtml('owned page')})),p=await filteringProxy(f.policy,'127.0.0.1');p.arm();
 try{
  for(const request of ['GET / HTTP/1.1\r\nHost: other.invalid\r\n','HEAD /non-root HTTP/1.1\r\nHost: other.invalid\r\n','GET /%2f%2fPRIVATE_TEST/%40user?next=http%3A%2F%2Fother.invalid HTTP/1.1\r\nHost: other.invalid\r\n','GET /no-host HTTP/1.0\r\n'])assert.equal(await rawRequest(p,request+'Connection: close\r\n\r\n'),403);
  assert.equal(f.hits.length,0);assert.equal(p.snapshot().upstreamRequests,0);const token=grant(p,f.origin+'/');assert.equal(await send(p,f,{token}),200);
  const result=p.verify(proof(1));assert.equal(result.unownedDenials,4);assert.equal(result.unownedSamples.at(-1).urlShape.host,'missing');assert.equal(result.upstreamRequests,1);assert.doesNotMatch(JSON.stringify(result),/PRIVATE_TEST|other\.invalid/);
 }finally{await p.close();await f.close();}
});
test('direct-server exception excludes auth presence, methods, bodies, upgrade and ambiguous targets',async()=>{
 for(const mode of ['post','options','body','transfer','upgrade','connection-upgrade','duplicate-host','proxy-auth-empty','proxy-auth-forged','proxy-auth-valid','server-auth-empty','absolute','authority','hash','backslash','empty']){
  const f=await fixtureAdapter(()=>({body:fixtureHtml('owned')})),p=await filteringProxy(f.policy,'127.0.0.1');let failure;p.failed.catch(e=>{failure=e;});p.arm();
  try{
   const token=grant(p,f.origin+'/');let path='/non-root',method='GET',headers='Host: other.invalid\r\nConnection: close\r\n',body='';
   if(mode==='post')method='POST';if(mode==='options')method='OPTIONS';
   if(mode==='body'){headers+='Content-Length: 1\r\n';body='x';}
   if(mode==='transfer'){headers+='Transfer-Encoding: chunked\r\n';body='0\r\n\r\n';}
   if(mode==='upgrade')headers='Host: other.invalid\r\nConnection: upgrade\r\nUpgrade: websocket\r\n';
   if(mode==='connection-upgrade')headers='Host: other.invalid\r\nConnection: upgrade\r\n';
   if(mode==='duplicate-host')headers+='Host: duplicate.invalid\r\n';
   if(mode.startsWith('proxy-auth'))headers+='Proxy-Authorization: '+(mode==='proxy-auth-valid'?token:mode==='proxy-auth-forged'?'Basic forged':'')+'\r\n';
   if(mode==='server-auth-empty')headers+='Authorization:\r\n';
   if(mode==='absolute')path='http://other.invalid/non-root';if(mode==='authority')path='//user@other.invalid/non-root';if(mode==='hash')path='/non-root#fragment';if(mode==='backslash')path='/\\other.invalid';if(mode==='empty')path='';
   const status=await rawRequest(p,`${method} ${path} HTTP/1.1\r\n${headers}\r\n${body}`).catch(()=>null);
   assert.ok([null,400,403].includes(status),mode);assert.equal(p.snapshot().unownedDenials,0,mode);assert.ok(failure,mode+' must remain fatal');assert.equal(p.snapshot().upstreamRequests,0);assert.equal(f.hits.length,0);
  }finally{await p.close();await f.close();}
 }
});

test('URL shape identifies precise branch without secret or relative-origin invention',()=>{
  for(const vector of proxyUrlVectors){
    const shape=urlShape(vector.raw,'http://mliu:8131','127.0.0.1:1234','127.0.0.1:1234');
    assert.equal(shape.form,vector.form);assert.equal(shape.scheme,vector.scheme);assert.equal(shape.host,'proxy');
    assert.throws(()=>checkRequest(policy(),vector.raw,'GET','Other'),e=>e.code==='url'&&e.urlBranch===vector.branch);
    const evidence=failureEvidence({diagnostic:{urlShape:shape,urlBranch:vector.branch,target:publicTarget(vector.raw,'http://mliu:8131')}});
    assert.ok(!JSON.stringify(evidence).includes('PRIVATE_TEST'));
  }
  assert.equal(publicTarget('/','http://mliu:8131').origin,'invalid');
  assert.equal(urlShape('http://mliu:8131/','http://mliu:8131','mliu:8131').host,'approved');
});
test('owned invalid URL vectors retain strict fatal branch before forwarding',async()=>{
 for(const v of proxyUrlVectors){
  const f=await fixtureAdapter(()=>({body:fixtureHtml('ok')})),p=await filteringProxy(f.policy,'127.0.0.1');p.arm();
  try{const token=grant(p,f.origin+'/');
   if(v.name==='invalid'){await assert.rejects(send(p,f,{path:v.raw,token}));await assert.rejects(p.failed,e=>e.code==='proxy-protocol');}
   else{assert.equal(await send(p,f,{path:v.raw,token}),403);await assert.rejects(p.failed,e=>e.code==='url'&&e.diagnostic.urlBranch===v.branch);}
   assert.equal(f.hits.length,0);assert.equal(p.snapshot().upstreamRequests,0);}
  finally{await p.close();await f.close();}
 }
});
test('one-use binding, missing/forged/reused tokens and Host/method mismatches never forward',async()=>{
 for(const mode of ['missing','forged','reused','url','method','host']){
  const f=await fixtureAdapter(()=>({body:fixtureHtml('ok')})),p=await filteringProxy(f.policy,'127.0.0.1');p.arm();
  try{const token=mode==='missing'?undefined:grant(p,f.origin+'/');if(mode==='reused')assert.equal(await send(p,f,{token}),200);
   const options={token:mode==='missing'?undefined:mode==='forged'?'synthetic-invalid':token};
   if(mode==='url')options.path=f.origin+'/tienda/';if(mode==='method')options.method='HEAD';if(mode==='host')options.host=new URL(p.url).host;
   assert.equal(await send(p,f,options),403);await assert.rejects(p.failed,/proxy-owner-binding|proxy-owner-forged|proxy-host/);
   assert.equal(f.hits.length,mode==='reused'?1:0);assert.ok(f.hits.every(h=>h.controlHeaderSeen===false));
  }finally{await p.close();await f.close();}
 }
});
test('only authenticated source-pinned auxiliary robots GET can bypass Fetch claims, once',async()=>{
 for(const mode of ['owned','missing','forged','query','other','method']){
  const f=await fixtureAdapter(()=>({body:fixtureHtml('ok')})),p=await filteringProxy(f.policy,'127.0.0.1');p.arm();
  try{const token=grant(p,f.origin+'/');assert.equal(await send(p,f,{token}),200);
   const options={path:f.origin+(mode==='other'?'/favicon.ico':'/robots.txt')+(mode==='query'?'?ver=1':''),method:mode==='method'?'HEAD':'GET',token:mode==='missing'?undefined:mode==='forged'?'synthetic-invalid':token};
   if(mode==='owned'){
    assert.equal(await send(p,f,options),200);const result=p.verify(proof(1));assert.equal(result.authenticatedAuxiliaryRequests,1);assert.equal(result.upstreamRequests,result.consumedAuthorizations+result.authenticatedAuxiliaryRequests);
    assert.equal(await send(p,f,options),403);await assert.rejects(p.failed,/proxy-auxiliary-replay/);
   }else{assert.equal(await send(p,f,options),403);await assert.rejects(p.failed,/proxy-owner-binding/);assert.equal(f.hits.length,1);}
   assert.ok(f.hits.every(h=>!h.controlHeaderSeen));
  }finally{await p.close();await f.close();}
 }
});
test('407 challenge never forwards or consumes claim; authenticated retry consumes once',async()=>{
 const f=await fixtureAdapter(()=>({body:fixtureHtml('ok')})),p=await filteringProxy(f.policy,'127.0.0.1');p.arm();
 try{const token=grant(p,f.origin+'/');assert.equal(await send(p,f),407);assert.equal(p.snapshot().pendingAuthorizations,1);assert.equal(f.hits.length,0);assert.equal(await send(p,f,{token}),200);assert.equal(p.verify(proof(1)).authChallenges,1);assert.throws(()=>p.authenticate(f.origin+'/','GET'),/proxy-auth-unproven/);}
 finally{await p.close();await f.close();}
});
test('pending/aborted renderer claim cannot be hidden by nonfatal proxy-root denial',async()=>{
 const f=await fixtureAdapter(()=>({body:fixtureHtml('ok')})),p=await filteringProxy(f.policy,'127.0.0.1');p.arm();
 try{p.authorize(f.origin+'/','GET');assert.equal(await get(p.url+'/'),403);assert.throws(()=>p.verify(proof(1)),/proxy-owner-unproven/);assert.equal(p.snapshot().pendingAuthorizations,1);assert.equal(f.hits.length,0);}
 finally{await p.close();await f.close();}
});
test('unbound origin-form denial cannot satisfy a required non-root renderer claim',async()=>{
 const f=await fixtureAdapter(()=>({body:fixtureHtml('owned')})),p=await filteringProxy(f.policy,'127.0.0.1');p.arm();
 try{
  p.authorize(f.origin+'/asset.png','GET','required','Image');
  assert.equal(await send(p,f,{path:'/asset.png'}),403);assert.equal(f.hits.length,0);assert.equal(p.snapshot().pendingAuthorizations,1);
  assert.throws(()=>p.verify(proof(1)),/proxy-owner-unproven/);await p.close();assert.throws(()=>p.revokeAfterExit({exitCode:0,signalCode:null},[]),/proxy-required-resource-unproven/);
 }finally{await p.close();await f.close();}
});
test('redirect hops require fresh capabilities; capabilities are stripped and never serialized',async()=>{
 const f=await fixtureAdapter(({req})=>req.url==='/'?{status:302,headers:{location:'/tienda/'}}:{body:fixtureHtml('ok')}),p=await filteringProxy(f.policy,'127.0.0.1');p.arm();
 try{const first=grant(p,f.origin+'/');assert.equal(await send(p,f,{token:first}),302);const next=grant(p,f.origin+'/tienda/');assert.ok(first===next,'same owned proxy credentials, fresh one-use request claims');assert.equal(await send(p,f,{path:f.origin+'/tienda/',token:next}),200);
  const result=p.verify(proof(2));assert.equal(result.upstreamRequests,2);assert.equal(result.consumedAuthorizations,2);assert.equal(result.pendingAuthorizations,0);assert.ok(f.hits.every(h=>h.controlHeaderSeen===false));
  p.assertPrivate(JSON.stringify(result));assert.throws(()=>p.assertPrivate(first),/proxy-owner-leak/);
 }finally{await p.close();await f.close();}
});
test('request-bound close revocation requires observed same URL/type200, owner exit, drained closed proxy',async()=>{
 for(const mode of ['exact','open','alive','signaled','wrong-url','wrong-type','failed-resource']){
  const f=await fixtureAdapter(()=>({body:fixtureHtml('ok')})),p=await filteringProxy(f.policy,'127.0.0.1');p.arm();
  try{
   p.authorize(f.origin+'/','GET','frame:request-1','Document');const c=p.authenticate(f.origin+'/','GET');const token='Basic '+Buffer.from(c.username+':'+c.password).toString('base64');assert.equal(await send(p,f,{token}),200);
   p.authorize(f.origin+'/','GET','frame:request-2','Document');
   if(mode!=='open')await p.close();
   const child={exitCode:mode==='alive'?null:0,signalCode:mode==='signaled'?'SIGKILL':null};
   const resources=[{url:f.origin+(mode==='wrong-url'?'/tienda/':'/'),type:mode==='wrong-type'?'Script':'Document',status:mode==='failed-resource'?403:200}];
   if(mode==='exact'){p.revokeAfterExit(child,resources);const state=p.verify(proof(2));assert.equal(state.upstreamRequests,1);assert.equal(state.consumedAuthorizations,1);assert.equal(state.cancelledAuthorizations,1);assert.equal(state.revoked[0].requestIndex,1);assert.equal(state.pendingAuthorizations,0);assert.equal(state.revoked[0].reason,'owner-exited-proxy-closed-resource-observed');}
   else{assert.throws(()=>p.revokeAfterExit(child,resources),/proxy-revocation-unproven|proxy-required-resource-unproven/);assert.equal(p.snapshot().pendingAuthorizations,1);}
  }finally{await p.close();await f.close();}
 }
});
test('owned upstream failure remains fatal, never reclassified as unowned',async()=>{
 const f=await fixtureAdapter(()=>({body:fixtureHtml('ok')})),p=await filteringProxy(f.policy,'127.0.0.1');p.arm();const token=grant(p,f.origin+'/');await f.close();
 try{await assert.rejects(send(p,f,{token}));await assert.rejects(p.failed,/proxy-upstream/);assert.throws(()=>p.verify(proof(1)),/proxy-upstream/);}finally{await p.close();}
});
const get = url => new Promise((resolve,reject) => { http.get(url,res=>{res.resume();res.on('end',()=>resolve(res.statusCode));}).on('error',reject); });
test('unowned origin-form proxy-root probe is denied without poisoning guarded collection', async()=>{
  const fixture=await fixtureAdapter(()=>({body:fixtureHtml('owned page')}));
  const proxy=await filteringProxy(fixture.policy,'127.0.0.1'); let failure;
  proxy.failed.catch(e=>{failure=e;});proxy.arm();
  try {
    assert.equal(await get(proxy.url+'/'),403);
    assert.equal(fixture.hits.length,0);
    assert.equal(failure,undefined,'unowned probe must not be attributed to the renderer');
    assert.equal(proxy.verify(proof(0)).unownedDenials,1);
    assert.throws(()=>proxy.verify({...proof(0),allTargetsGuarded:false}),/proxy-attribution-unproven/);
  } finally {await proxy.close();await fixture.close();}
});
