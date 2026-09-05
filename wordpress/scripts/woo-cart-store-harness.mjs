// Offline behavioral regression for issue #26 (WA-03): drives the REAL pinned
// WooCommerce 11.1.0 cart-block data store (vendor/wc-blocks-data-11.1.0.js —
// the exact code shipped to browsers) together with the REAL shipped theme
// script (assets/js/cart-quantity-feedback.js). Only the window.wp.* plumbing
// is stubbed; the store logic, the Store API request contract, the store event
// and the feedback script are the genuine production code. A quantity change
// is performed exactly as the cart block performs it: the block's own
// 'cart-set-item-quantity' store event (the customer's stated intent) followed
// by the real changeCartItemQuantity() action (the Store API round trip).
// Throws on failure; returns the number of assertions passed.
import {readFileSync} from 'node:fs';

const CART_STORE = 'wc/store/cart';
const INTENT_EVENT = 'experimental__woocommerce_blocks-cart-set-item-quantity';
const NETWORK_ERROR = {code: 'fetch_error', message: 'You are probably offline.'};

/* ---------- minimal window.wp plumbing (stubbed; the code under test is not) ---------- */

function makeWpData() {
	const api = {
		notices: [],
		peeks: new Map(),
		createReduxStore(name, config) { return {...config, name}; },
		register(store) {
			api.peeks.set(store.name, store);
			store.__state = store.reducer(undefined, {type: '@@INIT'});
		},
		select(name) {
			const store = api.peeks.get(name);
			if (!store) { return undefined; }
			return new Proxy({}, {get(_t, selector) {
				return (...args) => {
					const selectorFn = store.selectors && store.selectors[selector];
					if (!selectorFn) { return selector === 'hasFinishedResolution' ? true : undefined; }
					return selectorFn(store.__state, ...args);
				};
			}});
		},
		// Real wp.data: dispatch(name) with no action returns the store's bound action creators,
		// and a thunk's dispatch is bound to its own store with its actions attached as properties.
		boundActions(name) {
			const store = api.peeks.get(name);
			const bound = (action) => api.dispatch(name, action);
			for (const [key, creator] of Object.entries((store && store.actions) || {})) {
				bound[key] = (...args) => api.dispatch(name, creator(...args));
			}
			return bound;
		},
		dispatch(name, action) {
			if (name === 'core/notices' && action === undefined) {
				// wp.data.dispatch('core/notices') returns the store's action creators.
				return new Proxy({}, {get(_t, prop) {
					return (...args) => { api.notices.push({action: String(prop), args}); return {id: 'notice'}; };
				}});
			}
			if (action === undefined) { return api.boundActions(name); }
			const store = api.peeks.get(name);
			if (typeof action === 'function') {
				return action({
					dispatch: api.boundActions(name),
					select: api.select(name),
					resolveSelect: api.select(name),
					registry: {select: api.select, dispatch: (n, a) => api.dispatch(n, a)}
				});
			}
			if (!store) { throw new Error('store not registered: ' + name); }
			store.__state = store.reducer(store.__state, action);
			for (const fn of (api.__listeners.get(name) || [])) { fn(); }
			for (const fn of (api.__listeners.get('*') || [])) { fn(); }
			return action;
		},
		subscribe(fn, name) {
			const key = name || '*';
			if (!api.__listeners.has(key)) { api.__listeners.set(key, []); }
			api.__listeners.get(key).push(fn);
		},
		combineReducers(slices) {
			return (stateArg = {}, action) => {
				const next = {}; let changed = false;
				for (const key of Object.keys(slices)) {
					next[key] = slices[key](stateArg[key], action);
					if (next[key] !== stateArg[key]) { changed = true; }
				}
				return changed ? next : stateArg;
			};
		},
		createRegistrySelector: (fn) => fn,
		createRegistryControl: (fn) => fn,
		createSelector: (fn) => fn,
		controls: {},
		__listeners: new Map()
	};
	return api;
}

function makeHooks() {
	const registry = {};
	return {
		addAction(name, namespace, cb) { (registry[name] = registry[name] || []).push({namespace, cb}); },
		doAction(name, ...args) { for (const entry of registry[name] || []) { entry.cb(...args); } },
		addFilter(name, namespace, cb) { (registry['filter:' + name] = registry['filter:' + name] || []).push({namespace, cb}); },
		applyFilters(name, value) { let out = value; for (const {cb} of registry['filter:' + name] || []) { out = cb(out); } return out; },
		removeAction() {}, removeFilter() {}, didAction() { return false; }, hasAction() { return false; }, hasFilter() { return false; }
	};
}

function makeElement(tag, windowObj) {
	const element = {
		tagName: tag, className: '', attrs: {}, hidden: false, textContent: '',
		disabled: false, isConnected: true, parentNode: null, children: [], listeners: {}, focusCount: 0,
		setAttribute(name, value) { element.attrs[name] = String(value); },
		getAttribute(name) { return name in element.attrs ? element.attrs[name] : null; },
		removeAttribute(name) { delete element.attrs[name]; },
		appendChild(child) { element.children.push(child); child.parentNode = element; return child; },
		insertBefore(child) { element.children.unshift(child); child.parentNode = element; return child; },
		get firstChild() { return element.children[0] || null; },
		addEventListener(type, fn) { (element.listeners[type] = element.listeners[type] || []).push(fn); },
		dispatch(event) { for (const fn of element.listeners[event.type] || []) { fn(event); } },
		focus() { element.focusCount++; windowObj.document.activeElement = element; }
	};
	return element;
}

const SETTINGS = {
	wcBlocksConfig: {pluginUrl: 'https://freeplast.mliu.site/wp-content/plugins/woocommerce/', productCount: 1, restApiRoutes: {}, wordCountType: 'words'},
	STORE_PAGES: {shop: {permalink: '/tienda/'}, checkout: {permalink: '/datos-y-envio/'}, cart: {permalink: '/cotizacion/'}, privacy: {permalink: '/'}, terms: {permalink: '/'}, myaccount: {permalink: '/mi-cuenta/'}},
	adminUrl: '/wp-admin/', wpLoginUrl: '/wp-login.php', checkoutData: {},
	localPickupEnabled: false, shippingMethodsExist: false, shippingEnabled: true,
	countries: {}, countryData: {}, addressFieldsLocations: {address: ['first_name'], contact: ['email'], order: []},
	collectableMethodIds: [], globalPaymentMethods: [], customerPaymentMethods: {}, additionalOrderFields: {}, additionalContactFields: {}, additionalAddressFields: {}, isCheckoutBlock: false, displayCartPricesIncludingTax: false
};

function makeFakeWindow(bundleSource) {
	const windowObj = {};
	windowObj.transport = makeTransport();
	windowObj.wp = {};
	// The bundle captures window.wp.apiFetch at load time, so the transport
	// must be installed before it evaluates.
	windowObj.wp.apiFetch = windowObj.transport.apiFetch;
	windowObj.wp.data = makeWpData();
	windowObj.wp.hooks = makeHooks();
	windowObj.wp.i18n = {__: (s) => s, _x: (s) => s, _n: (s, p) => p, sprintf: (format, ...args) => { let i = 0; return String(format).replace(/%(\d+\$)?[sd]/g, (m, pos) => args[pos ? Number(pos) - 1 : i++]); }};
	windowObj.wp.url = {getPath: () => null, isEmail: () => true, addQueryArgs: (u) => u, getQueryArg: () => null, hasQueryArg: () => false, removeQueryArgs: (u) => u, prependHTTP: (u) => u, isURL: () => true, filterURLForDisplay: (u) => u, cleanForSlug: (u) => u};
	windowObj.wp.deprecated = () => {};
	windowObj.wp.dom = {__unstableStripHTML: (s) => String(s).replace(/<[^>]*>/g, ''), focus: {}};
	windowObj.wp.element = new Proxy({}, {get: () => () => null});
	windowObj.wp.notices = {store: 'core/notices'};
	windowObj.wp['html-entities'] = {decodeEntities: (s) => s};
	windowObj.wp.htmlEntities = windowObj.wp['html-entities'];
	windowObj.wp['is-shallow-equal'] = {default: {isShallowEqual: () => false}, isShallowEqualObjects: () => false, isShallowEqualArrays: () => false};
	windowObj.wp.dataControls = {controls: {}};
	windowObj.wc = {
		wcSettings: {getSetting: (k, fallback) => SETTINGS[k] !== undefined ? SETTINGS[k] : fallback, STORE_PAGES: SETTINGS.STORE_PAGES, SITE_CURRENCY: {code: 'CLP', symbol: '$', minorUnit: 0, prefix: '$', suffix: '', thousandSeparator: '.', decimalSeparator: ','}},
		wcTypes: {
			isObject: (e) => e !== null && e instanceof Object && e.constructor === Object,
			objectHasProp: (e, k) => windowObj.wc.wcTypes.isObject(e) && k in e,
			isString: (e) => typeof e === 'string',
			isNumber: (e) => typeof e === 'number',
			isBoolean: (e) => typeof e === 'boolean',
			isNull: (e) => e === null,
			isEmptyObject: (e) => windowObj.wc.wcTypes.isObject(e) && Object.keys(e).length === 0,
			isError: (e) => e instanceof Error,
			isSuccessResponse: () => false,
			isErrorResponse: () => false,
			isFailResponse: () => false,
			isObserverResponse: () => false,
			isApiErrorResponse: (e) => windowObj.wc.wcTypes.isObject(e) && 'code' in e && 'message' in e,
			getNoticeContextFromErrorResponse: () => undefined
		},
		blocksCheckoutEvents: {},
		blocksRegistry: {}
	};
	windowObj.location = {href: 'https://freeplast.mliu.site/cotizacion/'};
	windowObj.localStorage = {getItem: () => null, setItem() {}};
	windowObj.addEventListener = () => {}; windowObj.removeEventListener = () => {};
	windowObj.CustomEvent = class { constructor(type, options) { this.type = type; this.detail = options && options.detail; } };
	const dock = makeElement('div', windowObj); dock.className = 'wc-block-cart__main';
	const submit = makeElement('a', windowObj); submit.className = 'wc-block-cart__submit-button';
	const body = makeElement('body', windowObj);
	windowObj.document = {
		body,
		cookie: '',
		readyState: 'loading', // the footer script defers its init, as on the real page
		addEventListener() {},
		activeElement: body,
		querySelector(selector) {
			if (selector === '.wc-block-cart__main, .wp-block-woocommerce-cart') { return dock; }
			if (selector === 'a.wc-block-cart__submit-button') { return submit; }
			return null;
		},
		querySelectorAll: () => [],
		createElement: (tag) => makeElement(tag, windowObj)
	};
	// Load the REAL bundle against the stubbed plumbing. The bundle's compiled
	// code checks `instanceof Response`, so it must see the same constructor.
	new Function('window', 'globalThis', 'document', bundleSource + '\n;return true;')(windowObj, {Response: globalThis.Response}, windowObj.document);
	if (!windowObj.wp.data.select(CART_STORE)) { throw new Error('wc/store/cart did not register'); }
	return windowObj;
}

/* Controllable transport mimicking the real @wordpress/api-fetch contract:
   2xx resolves a Response; a !ok status throws the Response (which Woo's
   request wrapper unwraps via .json()); an offline failure rejects with the
   {code:'fetch_error'} object api-fetch itself produces. */
function makeTransport() {
	const transport = {calls: [], queue: [], deferred: null};
	transport.push = (kind, payload) => transport.queue.push({kind, payload});
	transport.pushDeferred = () => {
		let resolve, reject;
		const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
		transport.deferred = {resolve, reject};
		transport.queue.push({kind: 'deferred', promise});
	};
	transport.apiFetch = (options) => {
		transport.calls.push(options);
		const job = transport.queue.shift();
		if (!job) { return Promise.reject({code: 'unexpected_transport_call', message: options.path || '(no path)'}); }
		if (job.kind === 'deferred') { return job.promise; }
		if (job.kind === 'offline') { return Promise.reject({...NETWORK_ERROR}); }
		if (job.kind === 'abort') { return Promise.reject(Object.assign(new Error('The user aborted a request.'), {name: 'AbortError'})); }
		if (job.kind === 'server-error') { return Promise.reject(new Response(JSON.stringify(job.payload), {status: 400, statusText: 'Bad Request'})); }
		return Promise.resolve(new Response(JSON.stringify(job.payload), {status: 200, statusText: 'OK'}));
	};
	transport.apiFetch.setNonce = () => {};
	transport.apiFetch.setCartHash = () => {};
	return transport;
}

function attachScript(windowObj, scriptSource) {
	const moduleObj = {exports: {}};
	const previousReadyState = windowObj.document.readyState;
	windowObj.document.readyState = 'loading'; // init defers, as on the real page; the harness attaches explicitly
	new Function('window', 'document', 'module', scriptSource)(windowObj, windowObj.document, moduleObj);
	windowObj.document.readyState = previousReadyState;
	const watcher = moduleObj.exports.createWatcher(windowObj);
	if (!watcher) { throw new Error('the shipped script did not attach to ' + CART_STORE); }
	return {module: moduleObj.exports, watcher};
}

/* A quantity change exactly as the block performs it: the store event carries
   the customer's stated quantity, then the real action runs the round trip.
   The component catches rejections (processErrorResponse); so does this. */
function userChangesQuantity(windowObj, key, quantity) {
	const store = windowObj.wp.data.select(CART_STORE);
	windowObj.wp.hooks.doAction(INTENT_EVENT, {product: store.getCartItem(key), quantity});
	const promise = windowObj.wp.data.dispatch(CART_STORE, windowObj.wp.data.peeks.get(CART_STORE).actions.changeCartItemQuantity(key, quantity));
	return promise instanceof Promise ? promise.catch(() => {}) : promise;
}

function receiveCartOf(windowObj, cart) {
	windowObj.wp.data.dispatch(CART_STORE, windowObj.wp.data.peeks.get(CART_STORE).actions.receiveCart(cart));
}

function readSlots(windowObj) {
	const dock = windowObj.document.querySelector('.wc-block-cart__main, .wp-block-woocommerce-cart');
	const container = dock.firstChild;
	if (!container) { throw new Error('the feedback container was never created'); }
	return {container, error: container.children[0], status: container.children[1]};
}

function submitOf(windowObj) { return windowObj.document.querySelector('a.wc-block-cart__submit-button'); }

function slotsOf(windowObj) {
	const container = windowObj.document.querySelector('.wc-block-cart__main, .wp-block-woocommerce-cart').firstChild;
	return container ? {container, error: container.children[0], status: container.children[1]} : null;
}

const failureVisible = (win) => { const slots = slotsOf(win); return Boolean(slots) && slots.error.hidden === false; };
const savedVisible = (win) => { const slots = slotsOf(win); return Boolean(slots) && slots.status.hidden === false; };

async function settled(condition, description) {
	// Real timers, so the shipped script's deferred verdict (a setTimeout of its
	// own) is given the chance to fire, exactly as in a browser.
	for (let i = 0; i < 1000; i++) {
		await new Promise((resolve) => setTimeout(resolve, 5));
		if (condition()) { return true; }
	}
	throw new Error('settlement never reached: ' + description);
}

/* ---------- Store API cart fixtures (camelCased, as the store normalizes them) ---------- */

function storeCart(simpleQty, variantQty) {
	return {
		coupons: [], shippingRates: [], shippingAddress: {}, billingAddress: {},
		items: [
			{key: 'simple-line', id: 22, type: 'simple', name: 'Caja Cosechera 3/4', quantity: simpleQty, catalog_visibility: 'visible', prices: {}, totals: {}, quantity_limits: {minimum: 1, maximum: 99, multiple_of: 1, editable: true}},
			{key: 'variant-line', id: 25, type: 'variation', name: 'Caja Universal Cerrada Color', quantity: variantQty, catalog_visibility: 'visible', variation: [{attribute: 'Color', value: 'Rojo'}], prices: {}, totals: {}, quantity_limits: {minimum: 1, maximum: 99, multiple_of: 1, editable: true}}
		],
		itemsCount: simpleQty + variantQty, itemsWeight: 0, crossSells: [], fees: [],
		needsShipping: false, needsPayment: false, hasCalculatedShipping: true,
		totals: {currency_code: 'CLP', total_price: '0', total_items: '0', total_tax: '0'},
		errors: [], paymentMethods: [], paymentRequirements: [], extensions: {}
	};
}

/* ---------- scenarios (each starts a fresh page with the real store + real script) ---------- */

export async function runCartStoreScenarios(bundlePath, scriptPath) {
	const bundleSource = readFileSync(bundlePath, 'utf8');
	const scriptSource = readFileSync(scriptPath, 'utf8');
	let checks = 0;
	const assert = (ok, message) => { checks++; if (!ok) { throw new Error(message); } };
	const quantity = (win, key) => win.wp.data.select(CART_STORE).getCartItem(key).quantity;
	const pendingOf = (win) => win.wp.data.select(CART_STORE).getItemsPendingQuantityUpdate();
	const quiet = (win) => win.watcher.inflight() === 0 && pendingOf(win).length === 0;

	// 1 · Successful change through the real store: stated 6 → persisted 6, polite confirmation.
	{
		const win = makeFakeWindow(bundleSource);
		win.transport.push('ok', storeCart(140, 6));
		win.watcher = attachScript(win, scriptSource).watcher;
		receiveCartOf(win, storeCart(140, 5));
		userChangesQuantity(win, 'variant-line', 6);
		await settled(() => quantity(win, 'variant-line') === 6 && savedVisible(win), 'successful change should persist 6 and confirm politely');
		assert(quantity(win, 'simple-line') === 140, 'the untouched line keeps its quantity after another line changes');
		assert(quiet(win), 'no pending quantity operations remain after success');
		const slots = readSlots(win);
		assert(slots.status.hidden === false && slots.status.textContent.indexOf('Cantidad guardada: 6 unidades de «Caja Universal Cerrada Color»') === 0, 'success announces the exact persisted quantity politely');
		assert(slots.error.hidden === true, 'no error notice is shown after a successful change');
		const updates = win.transport.calls.filter((call) => call.path === '/wc/store/v1/cart/update-item');
		assert(updates.length === 1 && updates[0].data.quantity === 6 && updates[0].data.key === 'variant-line', 'exactly one update-item request carries the stated quantity');
	}

	// 2 · Connection loss (WA-03): the real store rolls back silently; the shipped script is the visible feedback.
	{
		const win = makeFakeWindow(bundleSource);
		win.transport.push('offline');
		win.watcher = attachScript(win, scriptSource).watcher;
		receiveCartOf(win, storeCart(140, 5));
		userChangesQuantity(win, 'variant-line', 6);
		await settled(() => failureVisible(win), 'the failure notice should appear');
		assert(quantity(win, 'variant-line') === 5, 'the store keeps the persisted quantity 5 after the connection loss');
		assert(quantity(win, 'simple-line') === 140, 'the other line survives the failed change');
		assert(quiet(win), 'no pending operations remain after the failure');
		const slots = readSlots(win);
		assert(slots.error.getAttribute('role') === 'alert', 'the failure notice is announced via role="alert"');
		assert(slots.error.textContent.indexOf('No se guardó el cambio de cantidad de «Caja Universal Cerrada Color»') === 0 && slots.error.textContent.indexOf('sigue con 5 unidades') !== -1, 'the failure notice explains the change was not saved and names the persisted quantity');
		assert(slots.status.hidden === true, 'no false confirmation accompanies the failure');
		assert(win.wp.data.notices.length === 0, 'the real store creates no notice of its own for a connection loss — the silent rollback WA-03 found');
		assert(submitOf(win).getAttribute('aria-disabled') === 'false', 'after the failure the CTA is operable again (no indefinite block)');
	}

	// 3 · Server error response: same visible feedback; persisted truth comes back in the error's cart payload.
	{
		const win = makeFakeWindow(bundleSource);
		win.transport.push('server-error', {code: 'invalid_quantity', message: 'Cantidad no válida.', data: {status: 400, cart: storeCart(140, 5)}});
		win.watcher = attachScript(win, scriptSource).watcher;
		receiveCartOf(win, storeCart(140, 5));
		userChangesQuantity(win, 'variant-line', 6);
		await settled(() => failureVisible(win), 'server error should surface the failure notice');
		assert(quantity(win, 'variant-line') === 5, 'the persisted quantity is the server truth, not the stated 6');
		const slots = readSlots(win);
		assert(slots.error.textContent.indexOf('sigue con 5 unidades') !== -1, 'the server-error failure states the persisted quantity');
		assert(win.wp.data.select(CART_STORE).getCartErrors().some((error) => error && error.code === 'invalid_quantity'), 'the store records the server error for Woo\u2019s own surfaces');
		assert(quiet(win), 'the transport is quiet after the server rejection');
	}

	// 4 · Recovery: retry after reconnecting saves the desired quantity with one request; the notice updates, nothing accumulates.
	{
		const win = makeFakeWindow(bundleSource);
		win.transport.push('offline');
		win.transport.push('ok', storeCart(140, 6));
		win.watcher = attachScript(win, scriptSource).watcher;
		receiveCartOf(win, storeCart(140, 5));
		userChangesQuantity(win, 'variant-line', 6);
		await settled(() => failureVisible(win), 'first attempt fails visibly');
		// Reconnected: the block's own input shows the persisted 5; one more click states 6 again.
		userChangesQuantity(win, 'variant-line', 6);
		await settled(() => quantity(win, 'variant-line') === 6 && savedVisible(win), 'the retry persists the desired quantity');
		const slots = readSlots(win);
		assert(slots.error.hidden === true && slots.status.hidden === false, 'the success replaces the failure notice');
		assert(slots.status.textContent.indexOf('Cantidad guardada: 6') === 0, 'the confirmation states exactly the retried quantity');
		const updates = win.transport.calls.filter((call) => call.path === '/wc/store/v1/cart/update-item');
		assert(updates.length === 2 && updates[1].data.quantity === 6, 'the retry sends one request for 6 — never a duplicated increment to 7');
		assert(quiet(win), 'transport is quiet after recovery');
	}

	// 5 · Rapid restatement (Woo aborts the in-flight request): no false failure flash; the final intent wins.
	{
		const win = makeFakeWindow(bundleSource);
		win.transport.push('abort');
		win.transport.push('ok', storeCart(140, 7));
		win.watcher = attachScript(win, scriptSource).watcher;
		receiveCartOf(win, storeCart(140, 5));
		userChangesQuantity(win, 'variant-line', 6);
		userChangesQuantity(win, 'variant-line', 7);
		await settled(() => quantity(win, 'variant-line') === 7 && savedVisible(win), 'the replacement request persists the final intent');
		const slots = readSlots(win);
		assert(slots.error.hidden === true, 'an aborted first request never flashes a failure notice');
		assert(slots.status.hidden === false && slots.status.textContent.indexOf('Cantidad guardada: 7') === 0, 'the confirmation matches the final persisted quantity');
		assert(quiet(win), 'no transport work remains after the abort-and-replace');
	}

	// 6 · Pending state cannot advance; after settling, advancing is explicit again (pointer and keyboard).
	{
		const win = makeFakeWindow(bundleSource);
		win.transport.pushDeferred();
		win.watcher = attachScript(win, scriptSource).watcher;
		receiveCartOf(win, storeCart(140, 5));
		userChangesQuantity(win, 'variant-line', 6);
		const submit = submitOf(win);
		assert(submit.getAttribute('aria-disabled') === 'true', 'while the change is unconfirmed the CTA is aria-disabled');
		const click = {type: 'click', preventDefault() { this.defaultPrevented = true; }};
		submit.dispatch(click);
		assert(click.defaultPrevented === true, 'navigation is stopped synchronously while the quantity is unconfirmed');
		win.transport.deferred.reject({...NETWORK_ERROR});
		await settled(() => failureVisible(win), 'the failed update settles');
		assert(submit.getAttribute('aria-disabled') === 'false', 'once settled the CTA is operable again');
		const retry = {type: 'click', preventDefault() { this.defaultPrevented = true; }};
		submit.dispatch(retry);
		assert(!retry.defaultPrevented, 'continuing explicitly with the persisted quantity is never blocked');
	}

	// 7 · Keyboard: focus dropped by the pending disable cycle returns; deliberate focus elsewhere is never stolen.
	{
		const win = makeFakeWindow(bundleSource);
		win.transport.push('offline');
		attachScript(win, scriptSource);
		receiveCartOf(win, storeCart(140, 5));
		const plusButton = makeElement('button', win);
		plusButton.className = 'wc-block-components-quantity-selector__button--plus';
		plusButton.focus(); // the keyboard user is on the + control
		plusButton.focusCount = 0; // only the restore, not this manual focus, is asserted
		userChangesQuantity(win, 'variant-line', 6);
		win.document.activeElement = win.document.body; // the native disable cycle drops focus
		await settled(() => failureVisible(win), 'the failure settles before focus restoration');
		assert(plusButton.focusCount === 1, 'focus returns to the control the keyboard user was operating');
		const elsewhere = makeElement('button', win);
		elsewhere.focus();
		plusButton.focusCount = 0;
		elsewhere.focusCount = 0; // only a steal, not this manual focus, is asserted
		win.transport.push('ok', storeCart(140, 7));
		userChangesQuantity(win, 'variant-line', 7);
		await settled(() => quantity(win, 'variant-line') === 7 && savedVisible(win), 'the follow-up change settles');
		assert(elsewhere.focusCount === 0 && plusButton.focusCount === 0, 'deliberate focus is never stolen by the notice');
	}

	// 8 · A line Woo removes while its change is unsettled drops the intent silently.
	{
		const win = makeFakeWindow(bundleSource);
		win.transport.pushDeferred();
		win.watcher = attachScript(win, scriptSource).watcher;
		receiveCartOf(win, storeCart(140, 5));
		userChangesQuantity(win, 'variant-line', 6); // in flight, unconfirmed
		const emptied = storeCart(140, 5);
		emptied.items = emptied.items.filter((entry) => entry.key !== 'variant-line');
		emptied.itemsCount = 140;
		receiveCartOf(win, emptied); // Woo's own removal flow
		win.transport.deferred.reject(Object.assign(new Error('The user aborted a request.'), {name: 'AbortError'}));
		await settled(() => win.wp.data.select(CART_STORE).getCartItem('variant-line') === undefined, 'the removed line disappears from the store');
		await settled(() => Object.keys(win.watcher.intents).length === 0, 'the stale intent is dropped');
		const slots = slotsOf(win);
		assert(!slots || slots.error.hidden === true, 'no false failure notice for a line Woo removed');
		assert(quiet(win), 'everything is settled after the removal');
	}

	return checks;
}
