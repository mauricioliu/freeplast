import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { sha256 } from '../src/preflight.mjs';
import { fixtureHtml } from './fixture-adapter.mjs';
const cache = '/home/mauricio-liu/Projects/freeplast/wordpress/.tools/cache/';
const definitions = [
  ['/wp-includes/js/jquery/jquery.min.js', '/usr/bin/tar', ['-xOf', cache+'wordpress.tar.gz', 'wordpress/wp-includes/js/jquery/jquery.min.js'], 'cb6f2d32c49d1c2b25e9ffc9aaafa3f83075346c01bcd4ae6eb187392a4292cf'],
  ['/wp-content/plugins/woocommerce/assets/js/js-cookie/js.cookie.min.js', '/usr/bin/unzip', ['-p', cache+'woocommerce-11.1.0.zip', 'woocommerce/assets/js/js-cookie/js.cookie.min.js'], '8b083f64f2e9e8ac445c730dfce7013cc6449ce155fd1c2f42b60edba4ecb4b1'],
  ['/wp-content/plugins/woocommerce/assets/js/frontend/cart-fragments.min.js', '/usr/bin/unzip', ['-p', cache+'woocommerce-11.1.0.zip', 'woocommerce/assets/js/frontend/cart-fragments.min.js'], '592acc60b8eea94fc366110175d8406604a609201d6debe5eb008a6debfbdc3b'],
];
export function pinnedBootstrapSources() {
  return new Map(definitions.map(([path, binary, args, hash]) => {
    const bytes = execFileSync(binary, args, { maxBuffer: 2*1024**2, timeout: 10000 });
    assert.equal(sha256(bytes), hash, 'pinned bootstrap source drift'); return [path, bytes.toString('utf8')];
  }));
}
export function preparedBootstrap() {
  return JSON.parse(execFileSync('php', [new URL('./fragment-bootstrap.php', import.meta.url).pathname, 'active'], { encoding: 'utf8', timeout: 10000 }));
}
export function wooBootstrapFixture(prepared = false) {
  const sources = pinnedBootstrapSources();
  const bootstrap = prepared ? preparedBootstrap() : null;
  return ({req}) => {
    if (req.url === '/robots.txt') return {headers:{'Content-Type':'text/plain'},body:'User-agent: *\nDisallow:\n'};
    const source = sources.get(req.url);
    if (source) return {headers:{'Content-Type':'application/javascript'},body:source};
    const params = {cart_hash_key:'wc_cart_hash_synthetic',fragment_name:'wc_fragments_synthetic',wc_ajax_url:'/?wc-ajax=%%endpoint%%',request_timeout:5000};
    const mini = '<div class="widget_shopping_cart_content"><p class="woocommerce-mini-cart__empty-message">Native synthetic empty cart</p></div><span class="fpw-basket-count">0</span><div class="fpw-card-selections" data-fpw-cart-state="1" hidden></div><div class="fpw-selection-dock" hidden></div>';
    const scripts = [...sources.keys()].map(path => (path.includes('cart-fragments.') && bootstrap ? `<script>${bootstrap.scripts[0].script}window.fpFixtureSeedPresent=sessionStorage.getItem(wc_cart_fragments_params.fragment_name)!==null;window.fpFixturePersistedShows=0;window.addEventListener('pageshow',function(e){if(e.persisted)window.fpFixturePersistedShows++;});window.fpFixtureCartStorageEvents=0;window.addEventListener('storage',function(e){if(e.key===wc_cart_fragments_params.cart_hash_key)window.fpFixtureCartStorageEvents++;});</script>` : '') + `<script src="${path}"></script>`).join('');
    return {body:fixtureHtml(`<p>Actual pinned Woo11.1 anonymous bootstrap; no endpoint response supplied.</p>${mini}<script>var wc_cart_fragments_params=${JSON.stringify(params)};</script>${scripts}<script>jQuery(document.body).on('wc_fragments_loaded',function(){document.body.dataset.fpFixtureLoaded='yes';document.body.dataset.fpFixtureNativeState=String(document.querySelector('.fpw-basket-count').textContent==='0'&&document.querySelector('.fpw-card-selections').hidden&&document.querySelector('.fpw-selection-dock').hidden&&document.querySelector('.woocommerce-mini-cart__empty-message').textContent==='Native synthetic empty cart');});</script>`)};
  };
}
