import test from 'node:test';
import assert from 'node:assert/strict';
import WebSocket from 'ws';
const { Server: WebSocketServer } = WebSocket;
import { once } from 'node:events';
import { installGuard } from '../src/guard.mjs';
import { policy, SafetyError } from '../src/policy.mjs';
import { assertHttpsDenial } from './security-oracle.mjs';

async function fixture(synthetic = false, ownership = null, intercept = () => false) {
  const server = new WebSocketServer({ host: '127.0.0.1', port: 0 });
  await once(server, 'listening');
  let client, extraTarget = false;
  const commands = [];
  const target = { targetId: 'main', type: 'page', url: 'about:blank' };
  server.on('connection', socket => {
    client = socket;
    socket.on('message', data => {
      const message = JSON.parse(data);
      commands.push(message);
      if (intercept(message, socket)) return;
      socket.send(JSON.stringify({ id: message.id, result: message.method === 'Target.getTargets' ? { targetInfos: extraTarget ? [target, { targetId: 'unguarded', type: 'page' }] : [target] } : {} }));
      if (message.method === 'Target.setAutoAttach' && !message.sessionId) socket.send(JSON.stringify({ method: 'Target.attachedToTarget', params: { sessionId: 'main-session', targetInfo: target, waitingForDebugger: false } }));
    });
  });
  const browser = { wsEndpoint: () => `ws://127.0.0.1:${server.address().port}` };
  const page = { createCDPSession: async () => ({ send: async () => ({ targetInfo: target }), detach: async () => {} }) };
  ownership ??= { authorize() {}, revokeAfterExit() {}, verify() {} }; // Pure guard fixtures; explicit proxy-failure tests supply their own proof.
  const guard = await installGuard(browser, page, synthetic ? policy({ fixtureOrigin: `http://127.0.0.1:${server.address().port}` }) : policy(), undefined, ownership);
  const event = (method, params, sessionId = 'main-session') => client.send(JSON.stringify({ method, params, sessionId }));
  return {
    guard, commands, event,
    disconnect: () => client.terminate(),
    extraTarget: () => { extraTarget = true; },
    async document() {
      event('Fetch.requestPaused', { requestId: 'doc', request: { url: 'http://mliu:8131/?fp-measure=1', method: 'GET' }, resourceType: 'Document', responseStatusCode: 200, responseHeaders: [{ name: 'Cache-Control', value: 'no-store' }] });
      while (!guard.documents.length) await new Promise(resolve => setTimeout(resolve, 1));
    },
    async close() { guard.close(); for (const socket of server.clients) socket.terminate(); await new Promise(resolve => server.close(resolve)); },
  };
}

test('slot4 unknown/null terminal Response stages until clean owner and proxy proof',async()=>{
 for(const mode of ['pass-null','pass-code','bad-exit','signal','proxy-open','ledger-invalid']){
 const held=Promise.withResolvers(),failSent=Promise.withResolvers();
 const ownership={revokeAfterExit(){if(mode==='proxy-open')throw new SafetyError('proxy-revocation-unproven');},verify(proof){assert.equal(proof.shutdownResponseCancellations,0);assert.equal(proof.pendingShutdownResponses,1);assert.throws(()=>f.guard.assertHealthy(),/guard-shutdown-pending/);if(mode==='ledger-invalid')throw new SafetyError('proxy-owner-unproven');return {};}};
 const f=await fixture(false,ownership,(message,socket)=>{
  if(message.method==='Fetch.continueRequest'){held.resolve({message,socket});return true;}
  if(message.method==='Fetch.failRequest'){socket.send(JSON.stringify({id:message.id,error:{message:'PRIVATE_SYNTHETIC_UNKNOWN'}}));failSent.resolve();return true;}return false;
 });
 try{
  f.event('Fetch.requestPaused',{requestId:'slot4',frameId:'frame',request:{url:'http://mliu:8131/asset.png',method:'GET'},resourceType:'Image',responseStatusCode:200});
  const {message,socket}=await held.promise;f.guard.beginShutdown();f.event('Target.detachedFromTarget',{sessionId:'main-session'});
  socket.send(JSON.stringify({id:message.id,error:{code:mode==='pass-code'?-32001:undefined,message:'PRIVATE_SYNTHETIC_UNKNOWN'}}));
  await failSent.promise;f.disconnect();
  const child={exitCode:mode==='bad-exit'?1:0,signalCode:mode==='signal'?'SIGKILL':null};
  if(!mode.startsWith('pass')){
   const expected=mode==='proxy-open'?/proxy-revocation-unproven/:mode==='ledger-invalid'?/proxy-owner-unproven/:/guard-owner-exit-unproven/;
   await assert.rejects(f.guard.settleShutdown(child),expected);
   await assert.rejects(f.guard.settleShutdown({exitCode:0,signalCode:null}),expected,'failed settlement cannot later finalize');
   assert.equal(f.guard.snapshot().shutdownResponseCancellations,0);continue;
  }
  const result=await f.guard.settleShutdown(child);
  assert.equal(result.shutdownResponseCancellations,1);assert.equal(result.shutdownResponses[0].cdpCategory,'unknown');assert.equal(result.shutdownResponses[0].cdpCode,mode==='pass-code'?-32001:null);assert.equal(result.shutdownResponses[0].failRequestAcknowledged,false);
  assert.equal(result.pendingShutdownResponses,0);assert.equal(result.shutdownResponses[0].finalized,true);
  assert.doesNotMatch(JSON.stringify(result),/PRIVATE_SYNTHETIC/);
 }finally{await f.close();}
 }
});

test('validated response continuation crossing owned shutdown is a typed terminal cancellation',async()=>{
 for(const terminal of [{code:-32602,message:'Invalid InterceptionId.',category:'stale-interception'},{code:-32000,message:'Session with given id not found.',category:'session-gone'},{code:-32602,message:'Invalid InterceptionId.',category:'stale-interception',responseError:true}]){
 const held=Promise.withResolvers(), failSent=Promise.withResolvers();
 const f=await fixture(false,null,(message,socket)=>{
  if(message.method==='Fetch.continueRequest'){held.resolve({message,socket});return true;}
  if(message.method==='Fetch.failRequest'){socket.send(JSON.stringify({id:message.id,error:{code:-32000,message:'Session with given id not found.'}}));failSent.resolve();return true;}return false;
 });
 try{
  f.event('Fetch.requestPaused',{requestId:'image',networkId:'image-network',request:{url:'http://mliu:8131/asset.png',method:'GET'},resourceType:'Image',...(terminal.responseError?{responseErrorReason:'Aborted'}:{responseStatusCode:200})});
  const {message,socket}=await held.promise;
  f.guard.beginShutdown();
  f.event('Target.detachedFromTarget',{sessionId:'main-session'});
  socket.send(JSON.stringify({id:message.id,error:{code:terminal.code,message:terminal.message}}));
  await failSent.promise; f.disconnect();
  const settled=await f.guard.settleShutdown({exitCode:0,signalCode:null});
  assert.equal(settled.shutdownResponseCancellations,1);
  assert.equal(settled.shutdownResponses[0].cdpCategory,terminal.category);
  assert.equal(settled.shutdownResponses[0].responseErrorEvent,terminal.responseError===true);
  assert.equal(settled.httpRequestsAuthorized,0);
  assert.equal(settled.shutdownResponses[0].failRequestAcknowledged,false);
  assert.doesNotThrow(()=>f.guard.assertHealthy());
 }finally{await f.close();}
 }
});

test('shutdown never waives live or unterminated errors, Request stage or response policy failures',async()=>{
 for(const mode of ['live','active-cancel','still-live-shutdown','unknown','request','post-response','bad-response','unknown-session']){
  const held=Promise.withResolvers();
  const f=await fixture(false,null,(message,socket)=>{
   if(message.method==='Fetch.continueRequest'){held.resolve({message,socket});return true;}
   if(message.method==='Fetch.failRequest'){socket.send(JSON.stringify({id:message.id,error:{code:-32602,message:'Invalid InterceptionId.'}}));return true;}return false;
  });
  try{
   if(['post-response','bad-response','unknown-session'].includes(mode))f.guard.beginShutdown();
   f.event('Fetch.requestPaused',{requestId:'control',networkId:'control-network',request:{url:'http://mliu:8131/asset.png',method:mode==='post-response'?'POST':'GET'},resourceType:'Image',...(mode==='request'?{}:mode==='active-cancel'?{responseErrorReason:'Aborted'}:{responseStatusCode:mode==='bad-response'?503:200})},mode==='unknown-session'?'unknown':'main-session');
   if(['live','active-cancel','still-live-shutdown','unknown','request'].includes(mode)){
    const {message,socket}=await held.promise;
    if(mode==='active-cancel')f.event('Network.loadingFailed',{requestId:'control-network',canceled:true,errorText:'net::ERR_ABORTED'});
    if(!['live','active-cancel','unknown'].includes(mode))f.guard.beginShutdown();
    if(mode==='request')f.event('Target.detachedFromTarget',{sessionId:'main-session'});
    socket.send(JSON.stringify({id:message.id,error:{code:-32602,message:['unknown','still-live-shutdown'].includes(mode)?'PRIVATE_TEST unexpected raw CDP error':'Invalid InterceptionId.'}}));
   }
   if(mode==='still-live-shutdown'){
    await assert.rejects(f.guard.settleShutdown({exitCode:0,signalCode:null}),/guard-shutdown-transport/);
    f.disconnect();await assert.rejects(f.guard.settleShutdown({exitCode:0,signalCode:null}),/guard-shutdown-transport/);
    assert.equal(f.guard.snapshot().shutdownResponseCancellations,0);continue;
   }
   const error=await f.guard.failed.catch(e=>e);
   assert.equal(error.code,mode==='post-response'?'method':mode==='bad-response'?'resource-status':mode==='unknown-session'?'renderer-provenance-missing':'guard-command-failed');
   assert.equal(f.guard.snapshot().shutdownResponseCancellations,0);
   assert.ok(!JSON.stringify(error.diagnostic).includes('PRIVATE_TEST'));
   if(mode==='unknown')assert.equal(error.diagnostic.cdpCategory,'unknown');
   if(['live','active-cancel'].includes(mode))assert.equal(error.diagnostic.duringOwnedShutdown,false);
   if(mode==='active-cancel')assert.equal(error.diagnostic.responseErrorEvent,true);
   if(mode==='still-live-shutdown')assert.equal(error.diagnostic.terminalSessionObserved,false);
  }finally{await f.close();}
 }
});
test('active command error stays fatal when failed-block acknowledgement crosses owned shutdown',async()=>{
 const held=Promise.withResolvers(), failSent=Promise.withResolvers();
 const f=await fixture(false,null,(message,socket)=>{
  if(message.method==='Fetch.continueRequest'){held.resolve({message,socket});return true;}
  if(message.method==='Fetch.failRequest'){f.guard.beginShutdown();f.event('Target.detachedFromTarget',{sessionId:'main-session'});failSent.resolve();return true;}
  return false;
 });
 try{
  f.event('Fetch.requestPaused',{requestId:'late-block',request:{url:'http://mliu:8131/asset.png',method:'GET'},resourceType:'Image',responseStatusCode:200});
  const {message,socket}=await held.promise;
  socket.send(JSON.stringify({id:message.id,error:{code:-32602,message:'Invalid InterceptionId.'}}));
  await failSent.promise;f.disconnect();
  await assert.rejects(f.guard.settleShutdown({exitCode:0,signalCode:null}),/guard-command-failed/);
  const error=await f.guard.failed.catch(e=>e);assert.equal(error.diagnostic.duringOwnedShutdown,false);
 }finally{await f.close();}
});

test('already-owned shutdown may prove terminal session after failed block',async()=>{
 const held=Promise.withResolvers(),failSent=Promise.withResolvers();
 const f=await fixture(false,null,(message,socket)=>{
  if(message.method==='Fetch.continueRequest'&&message.params.requestId==='late-terminal'){held.resolve({message,socket});return true;}
  if(message.method==='Fetch.failRequest'){
   socket.send(JSON.stringify({id:message.id,error:{message:'PRIVATE_BLOCK_ERROR'}}));
   f.event('Fetch.requestPaused',{requestId:'barrier',request:{url:'http://mliu:8131/asset.png',method:'GET'},resourceType:'Image',responseStatusCode:200});return true;
  }
  if(message.method==='Fetch.continueRequest'&&message.params.requestId==='barrier')failSent.resolve();
  return false;
 });
 try{
  f.event('Fetch.requestPaused',{requestId:'late-terminal',request:{url:'http://mliu:8131/asset.png',method:'GET'},resourceType:'Image',responseStatusCode:200});
  const {message,socket}=await held.promise;f.guard.beginShutdown();socket.send(JSON.stringify({id:message.id,error:{message:'PRIVATE_UNKNOWN'}}));
  await failSent.promise;
  assert.equal(f.guard.snapshot().pendingShutdownResponses,1);assert.equal(f.guard.snapshot().shutdownResponseCancellations,0);assert.equal(f.guard.snapshot().shutdownResponses[0].failRequestAcknowledged,false);
  f.disconnect();const proof=await f.guard.settleShutdown({exitCode:0,signalCode:null});
  assert.equal(proof.shutdownResponseCancellations,1);const observation=proof.shutdownResponses[0];assert.equal(observation.duringOwnedShutdown,true);assert.equal(observation.terminalSessionObserved,false);assert.equal(observation.terminalAtSettlement,true);assert.equal(observation.cdpCategory,'unknown');assert.equal(observation.cdpCode,null);assert.equal(observation.failRequestAcknowledged,false);
 }finally{await f.close();}
});
test('unknown other-command failure remains fatal after owned terminal event',async()=>{
 const held=Promise.withResolvers();
 const owner={url:'http://127.0.0.1:12345',authenticate(){return {username:'test',password:'PRIVATE_AUTH'};}};
 const f=await fixture(false,owner,(message,socket)=>{if(message.method==='Fetch.continueWithAuth'&&message.params.authChallengeResponse.response==='ProvideCredentials'){held.resolve({message,socket});return true;}return false;});
 try{
  f.event('Fetch.authRequired',{requestId:'other-command',request:{url:'http://mliu:8131/asset.png',method:'GET'},authChallenge:{source:'Proxy',origin:owner.url,scheme:'basic',realm:'freeplast-owned'}});
  const {message,socket}=await held.promise;f.guard.beginShutdown();f.event('Target.detachedFromTarget',{sessionId:'main-session'});socket.send(JSON.stringify({id:message.id,error:{message:'PRIVATE_UNKNOWN'}}));
  const e=await f.guard.failed.catch(e=>e);assert.equal(e.code,'guard-command-failed');assert.equal(e.diagnostic.cdpCommand,'Fetch.continueWithAuth');assert.equal(e.diagnostic.cdpCategory,'unknown');assert.equal(f.guard.snapshot().pendingShutdownResponses,0);assert.doesNotMatch(JSON.stringify(e.diagnostic),/PRIVATE_/);
 }finally{await f.close();}
});

test('owned transport close drains pending validated response; unproven owner exit fails',async()=>{
 for(const exitCode of [null,0]){
 const held=Promise.withResolvers();const f=await fixture(false,null,(message)=>{if(message.method==='Fetch.continueRequest'){held.resolve();return true;}return false;});
 try{
  f.event('Fetch.requestPaused',{requestId:'transport',request:{url:'http://mliu:8131/asset.png',method:'HEAD'},resourceType:'Image',responseStatusCode:200});await held.promise;
  f.guard.beginShutdown();f.disconnect();
  if(exitCode===null){await assert.rejects(f.guard.settleShutdown({exitCode,signalCode:null}),/guard-owner-exit-unproven/);assert.equal(f.guard.snapshot().shutdownResponseCancellations,0);continue;}
  const result=await f.guard.settleShutdown({exitCode:0,signalCode:null});assert.equal(result.shutdownResponseCancellations,1);assert.equal(result.shutdownResponses[0].cdpCategory,'transport-closed');assert.equal(result.shutdownResponses[0].failRequestAcknowledged,false);
 }finally{await f.close();}
 }
});

test('renderer direct-proxy endpoint attempt stays fatal before continuation',async()=>{
 const f=await fixture();
 try{
  f.event('Fetch.requestPaused',{requestId:'direct-proxy',request:{url:'http://127.0.0.1:43210/synthetic-unowned.png',method:'GET'},resourceType:'Image'});
  const error=await f.guard.failed.catch(e=>e);assert.equal(error.code,'external-resource');assert.equal(error.diagnostic.source,'renderer-fetch');assert.equal(error.diagnostic.continuationAttempted,false);assert.equal(error.diagnostic.failRequestAcknowledged,true);assert.equal(f.guard.snapshot().httpRequestsAuthorized,0);assert.ok(!f.commands.some(command=>command.method==='Fetch.continueRequest'));
 }finally{await f.close();}
});

test('duplicate request pauses share one authorization; redirect/new network request gets its own',async()=>{
 const claims=[];const f=await fixture(false,{authorize(url,method){claims.push({url,method});}});
 try{
  for(const [id,networkId,path] of [['a','one','/'],['b','one','/'],['c','one','/tienda/'],['d','two','/']])f.event('Fetch.requestPaused',{requestId:id,networkId,request:{url:'http://mliu:8131'+path+'?fp-measure=1',method:'GET'},resourceType:'Document'});
  const until=Date.now()+1000;while(f.commands.filter(c=>c.method==='Fetch.continueRequest').length<4&&Date.now()<until)await new Promise(r=>setTimeout(r,1));
  assert.equal(f.commands.filter(c=>c.method==='Fetch.continueRequest').length,4);assert.equal(claims.length,3);assert.equal(f.guard.snapshot().httpRequestsAuthorized,3);assert.equal(f.guard.snapshot().duplicateRequestPauses,1);
 }finally{await f.close();}
});

test('Fetch responseErrorReason is terminal response, never a second outbound authorization',async()=>{
 let authorizations=0;const f=await fixture(false,{authorize(){authorizations++;}});
 try{f.event('Fetch.requestPaused',{requestId:'terminal',request:{url:'http://mliu:8131/?fp-measure=1',method:'GET'},resourceType:'Document',responseErrorReason:'Aborted'});
  const until=Date.now()+1000;while(!f.commands.some(c=>c.method==='Fetch.continueRequest'&&c.params.requestId==='terminal')&&Date.now()<until)await new Promise(r=>setTimeout(r,1));
  assert.ok(f.commands.some(c=>c.method==='Fetch.continueRequest'&&c.params.requestId==='terminal'));assert.equal(authorizations,0);assert.equal(f.guard.snapshot().httpRequestsAuthorized,0);assert.equal(f.guard.snapshot().responseErrorEvents,1);
 }finally{await f.close();}
});

test('proxy credentials only answer exact owned Proxy challenge; Server/wrong-origin/scheme/realm/unknown-session cancel', async()=>{
  for(const mode of ['owned','server','origin','scheme','realm','session']){
    let calls=0;
    const ownership={url:'http://127.0.0.1:12345',authenticate(url,method){assert.equal(url,'http://mliu:8131/robots.txt');assert.equal(method,'GET');calls++;return {username:'synthetic',password:'synthetic-control'};}};
    const f=await fixture(false,ownership);
    try{
      f.event('Fetch.authRequired',{requestId:'auth',request:{url:'http://mliu:8131/robots.txt',method:'GET'},authChallenge:{source:mode==='server'?'Server':'Proxy',origin:mode==='origin'?'http://other.invalid':ownership.url,scheme:mode==='scheme'?'digest':'basic',realm:mode==='realm'?'other':'freeplast-owned'}},mode==='session'?'unknown':'main-session');
      if(mode!=='owned')await assert.rejects(f.guard.failed,/proxy-auth-provenance/);
      const until=Date.now()+1000;while(!f.commands.some(c=>c.method==='Fetch.continueWithAuth')&&Date.now()<until)await new Promise(r=>setTimeout(r,1));
      const response=f.commands.find(c=>c.method==='Fetch.continueWithAuth')?.params.authChallengeResponse;
      assert.equal(response?.response,mode==='owned'?'ProvideCredentials':'CancelAuth');assert.equal(calls,mode==='owned'?1:0);
      if(mode!=='owned')assert.equal(response.password,undefined);
    }finally{await f.close();}
  }
});

test('guard enables Fetch and Network before resume; verifies every renderer target', async () => {
  const f = await fixture();
  try {
    const methods = f.commands.map(x => x.method);
    assert.ok(methods.indexOf('Network.enable') < methods.indexOf('Runtime.runIfWaitingForDebugger'));
    assert.ok(methods.indexOf('Fetch.enable') < methods.indexOf('Runtime.runIfWaitingForDebugger'));
    await f.document();
    assert.equal((await f.guard.verify()).rendererProof.allTargetsGuarded, true);
    f.extraTarget();
    await assert.rejects(f.guard.verify(), /renderer-provenance-missing/);
  } finally { await f.close(); }
});
test('unexpected CDP disconnect and detached renderer fail closed', async () => {
  for (const mode of ['disconnect', 'detach']) {
    const f = await fixture();
    try {
      if (mode === 'disconnect') f.disconnect();
      else f.event('Target.detachedFromTarget', { sessionId: 'main-session' }, undefined);
      await assert.rejects(f.guard.failed, mode === 'disconnect' ? /guard-disconnected/ : /guard-detached/);
    } finally { await f.close(); }
  }
});
test('HTTPS GET and observed OPTIONS both fail before continuation; method ordering is explicit', async () => {
  for (const method of ['GET', 'OPTIONS', 'POST']) {
    const f = await fixture(true);
    const url = 'https://127.0.0.1:444/';
    try {
      f.event('Fetch.requestPaused', { requestId: 'https-request', request: { url, method }, resourceType: 'XHR' });
      const error = await f.guard.failed.catch(error => error);
      assert.equal(error.code, method === 'GET' ? 'https-resource-unsupported' : 'method');
      assert.equal(error.diagnostic.method, method);
      assert.equal(error.diagnostic.syntheticTarget, url);
      assert.equal(error.diagnostic.resourceType, 'XHR');
      assert.equal(error.diagnostic.continuationAttempted, false);
      assert.equal(error.diagnostic.failRequestAcknowledged, true);
      assert.equal(f.commands.some(x => x.method === 'Fetch.continueRequest'), false);
      assert.ok(f.commands.some(x => x.method === 'Fetch.failRequest' && x.params.requestId === 'https-request'));
      const expected = { url, resourceType: 'XHR', allowPreflight: true };
      if (method === 'POST') assert.throws(() => assertHttpsDenial(error.code, error.diagnostic, expected));
      else assertHttpsDenial(error.code, error.diagnostic, expected);
    } finally { await f.close(); }
  }
});
test('unknown renderer provenance and HTTPS continuation are rejected before continueRequest', async () => {
  for (const mode of ['unknown', 'https']) {
    const f = await fixture();
    try {
      f.event('Fetch.requestPaused', { requestId: 'denied', request: { url: mode === 'https' ? 'https://accounts.google.com/fixture.png' : 'http://mliu:8131/logo.png?token=1', method: 'GET' }, resourceType: 'Image' }, mode === 'unknown' ? 'missing-session' : 'main-session');
      await assert.rejects(f.guard.failed, mode === 'https' ? /https-resource-unsupported/ : /renderer-provenance-missing/);
      assert.equal(f.commands.some(x => x.method === 'Fetch.continueRequest' && x.params.requestId === 'denied'), false);
      assert.ok(f.commands.some(x => x.method === 'Fetch.failRequest' && x.params.requestId === 'denied'));
    } finally { await f.close(); }
  }
});
