import {readFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
const root=fileURLToPath(new URL('../../../../',import.meta.url));
let source=readFileSync(root+'wordpress/scripts/woo-cart-store-harness.mjs','utf8');
// Keep the shipped store and theme script unchanged. Route the harness transport
// through window.fetch so the production in-flight observer actually runs.
source=source.replace('windowObj.wp.apiFetch = windowObj.transport.apiFetch;', `windowObj.fetch = (url, options) => windowObj.transport.apiFetch(options);
  windowObj.wp.apiFetch = (options) => windowObj.fetch(options.path, options);
  windowObj.wp.apiFetch.setNonce = () => {};
  windowObj.wp.apiFetch.setCartHash = () => {};`);
source+='\nexport {makeFakeWindow,attachScript,receiveCartOf,userChangesQuantity,storeCart,submitOf};';
const h=await import('data:text/javascript;base64,'+Buffer.from(source).toString('base64'));
const win=h.makeFakeWindow(readFileSync(root+'wordpress/scripts/vendor/wc-blocks-data-11.1.0.js','utf8'));
win.transport.pushDeferred(); const first=win.transport.deferred;
win.transport.pushDeferred(); const second=win.transport.deferred;
win.watcher=h.attachScript(win,readFileSync(root+'wordpress/wp-content/themes/freeplast/assets/js/cart-quantity-feedback.js','utf8')).watcher;
h.receiveCartOf(win,h.storeCart(140,5));
h.userChangesQuantity(win,'variant-line',6);
h.userChangesQuantity(win,'variant-line',7);
first.reject(Object.assign(new Error('Aborted'),{name:'AbortError'}));
await new Promise(r=>setTimeout(r,100));
const click={type:'click',defaultPrevented:false,preventDefault(){this.defaultPrevented=true;}};
h.submitOf(win).dispatch(click);
console.log(JSON.stringify({scenario:'abort first update while replacement still pending',inflight:win.watcher.inflight(),pending:win.wp.data.select('wc/store/cart').getItemsPendingQuantityUpdate(),ariaDisabled:h.submitOf(win).getAttribute('aria-disabled'),navigationPrevented:click.defaultPrevented}));
second.resolve(new Response(JSON.stringify(h.storeCart(140,7)),{status:200}));
await new Promise(r=>setTimeout(r,100));
console.log(JSON.stringify({scenario:'replacement settled',inflight:win.watcher.inflight(),quantity:win.wp.data.select('wc/store/cart').getCartItem('variant-line').quantity}));
