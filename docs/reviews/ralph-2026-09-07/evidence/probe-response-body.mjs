// Offline: pinned Woo store + shipped watcher in the existing fake-window harness.
// Expose helper functions in memory; no repo edits, HTTP, or browser/device use.
import {readFileSync} from 'node:fs';
const root = '/home/mauricio-liu/Projects/freeplast/wordpress';
const source = readFileSync(`${root}/scripts/woo-cart-store-harness.mjs`, 'utf8') + '\nexport {makeFakeWindow,attachScript,receiveCartOf,storeCart,userChangesQuantity,submitOf,slotsOf};';
const h = await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64'));
const win = h.makeFakeWindow(readFileSync(`${root}/scripts/vendor/wc-blocks-data-11.1.0.js`, 'utf8'));
win.transport.push('abort');
win.transport.pushDeferred();
const {watcher} = h.attachScript(win, readFileSync(`${root}/wp-content/themes/freeplast/assets/js/cart-quantity-feedback.js`, 'utf8'));
h.receiveCartOf(win, h.storeCart(140, 5));
h.userChangesQuantity(win, 'variant-line', 6);
h.userChangesQuantity(win, 'variant-line', 7);
await new Promise(r => setTimeout(r, 20));
let releaseBody;
const body = new Promise(r => {releaseBody = r;});
const response = new Response(null, {status:200});
response.json = () => body;
win.transport.deferred.resolve(response);
await new Promise(r => setTimeout(r, 80));
const submit = h.submitOf(win);
const event = {type:'click', preventDefault() {this.defaultPrevented = true;}};
submit.dispatch(event);
console.log(JSON.stringify({phase:'headers received; body pending', storePending:win.wp.data.select('wc/store/cart').getItemsPendingQuantityUpdate(), inflight:watcher.inflight(), ariaDisabled:submit.getAttribute('aria-disabled'), clickBlocked:!!event.defaultPrevented, error:h.slotsOf(win)?.error.textContent}, null, 2));
releaseBody(h.storeCart(140, 7));
await new Promise(r => setTimeout(r, 80));
console.log(JSON.stringify({phase:'body consumed', quantity:win.wp.data.select('wc/store/cart').getCartItem('variant-line').quantity, status:h.slotsOf(win)?.status.textContent, errorHidden:h.slotsOf(win)?.error.hidden}, null, 2));
