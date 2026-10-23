// Shared policy vectors: native fixture and pure oracle consume the same request/error contract.
export const assetVectors = [
  { name: 'unknown-plugin-denied', path: '/wp-content/plugins/other-plugin/assets/js/cart-helper.min.js', type: 'Script', stops: ['commercial-endpoint'] },
  { name: 'uploads-commercial-denied', path: '/wp-content/uploads/cart-badge.png', type: 'Image', stops: ['commercial-endpoint'] },
  { name: 'root-commercial-denied', path: '/cart-helper.js', type: 'Script', stops: ['commercial-endpoint'] },
  { name: 'asset-mutator-denied', path: '/wp-content/themes/freeplast/assets/js/nav.js?add-to-cart=1', type: 'Script', stops: ['commercial-endpoint'] },
];
export const expectedStops = { popup: ['popup'], POST: ['method'], 'commercial-GET': ['commercial-endpoint'], worker: ['worker-target'], ...Object.fromEntries(assetVectors.map(v => [v.name, v.stops])) };
export const staticRequests = [
  '/wp-content/themes/freeplast/assets/js/cart-quantity-feedback.js?ver=1.2',
  '/wp-content/plugins/woocommerce/assets/js/frontend/add-to-cart.min.js?ver=11.1',
  '/wp-content/plugins/woocommerce/assets/js/frontend/order-attribution.min.js?ver=11.1',
  '/wp-includes/js/jquery/jquery.min.js?ver=1',
  '/wp-content/plugins/quotes-for-woocommerce/assets/js/qwc-product-page.js?ver=2.13',
  '/wp-content/plugins/woocommerce/assets/js/frontend/add-to-cart.js?ver=11.1',
  '/wp-content/plugins/woocommerce/assets/js/frontend/add-to-cart-variation.js?ver=11.1',
  '/wp-content/plugins/woocommerce/assets/js/frontend/cart-fragments.js?ver=11.1',
  '/wp-content/plugins/woocommerce/assets/js/frontend/order-attribution.js?ver=11.1',
];
