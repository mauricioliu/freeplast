<?php
/** Execute Woo 11.1.0's actual ClassicTemplate archive path: it does NOT
 * include archive-product.php. No WP bootstrap, database, listener or HTTP. */
define('ABSPATH', __DIR__ . '/../.build/wp/');
require ABSPATH . 'wp-includes/plugin.php';
function esc_html($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_attr($s) { return esc_html($s); }
function esc_url($s) { return esc_html($s); }
function is_admin() { return false; }
function __return_false() { return false; }
function is_shop() { return $GLOBALS['route'] === 'shop'; }
function is_product_taxonomy() { return $GLOBALS['route'] === 'category'; }
function is_search() { return $GLOBALS['route'] === 'search'; }
function get_query_var($key, $default='') { return $key === 'post_type' && is_search() ? 'product' : ($key === 'product_cat' && is_product_taxonomy() ? 'agricola' : $default); }
function get_search_query($escape=true) { return is_search() ? 'sin <coincidencias>' : ''; }
function home_url($path='/') { return 'https://example.test'.$path; }
function wc_get_page_permalink($page) { return home_url('/tienda/'); }
function get_terms($args) { return [(object)['slug'=>'agricola','count'=>1],(object)['slug'=>'otros','count'=>0]]; }
function is_wp_error($v) { return false; }
function get_term_link($term) { return home_url('/categoria/'.$term->slug.'/'); }
function wc_get_products($args) { return (object)['total'=>1]; }
function add_query_arg($args,$url) { return $url.'?'.http_build_query($args); }
function woocommerce_breadcrumb() { echo '<nav>LEGACY BREADCRUMB</nav>'; }
function woocommerce_page_title() { echo 'LEGACY TITLE'; }
function woocommerce_product_loop() { return $GLOBALS['populated']; }
function wc_get_loop_prop($key) { return 1; }
function have_posts() { return $GLOBALS['iteration']++ === 0; }
function the_post() {}
function wp_reset_postdata() {}
function woocommerce_product_loop_start() { echo '<ul class="products catalog-grid">'; }
function woocommerce_product_loop_end() { echo '</ul>'; }
function wc_get_template_part($a,$b) { echo '<li>NATIVE PRODUCT</li>'; }
function wc_get_template($name,$args=[]) { extract($args); include __DIR__.'/../wp-content/themes/freeplast/woocommerce/'.$name; }
function selected($a,$b) { if($a===$b) echo 'selected'; }
function wc_query_string_form_fields(...$args) {}
function wc_no_products_found() { $p=__DIR__.'/../wp-content/themes/freeplast/woocommerce/loop/no-products-found.php'; if(file_exists($p))include $p; else echo 'NATIVE EMPTY'; }
function get_header(...$args) {}
function get_footer(...$args) {}
require __DIR__.'/../wp-content/themes/freeplast/inc/catalog.php';
$source=file_get_contents(ABSPATH.'wp-content/plugins/woocommerce/src/Blocks/BlockTypes/ClassicTemplate.php');
preg_match('/protected function render_archive_product\(\)\s*\{.*?\n\t\}/s',$source,$m);
if (!$m) throw new RuntimeException('Pinned native archive renderer missing');
eval(str_replace('protected function render_archive_product()', 'function native_archive()', $m[0]));
add_action('woocommerce_before_main_content','woocommerce_breadcrumb',27);
add_action('woocommerce_archive_description',static function(){echo '<span>EXTENSION</span>';},40);
add_action('woocommerce_no_products_found','wc_no_products_found');
add_action('woocommerce_after_shop_loop',static function(){echo '<nav>NATIVE PAGINATION</nav>';});
function verify($ok,$label) { if(!$ok)throw new RuntimeException($label); $GLOBALS['checks']++; }
$GLOBALS['checks']=0;
$GLOBALS['route']='page'; do_action('wp');
verify(has_action('woocommerce_before_main_content','woocommerce_breadcrumb')===27,'non-catalog breadcrumb untouched');
$GLOBALS['route']='shop'; do_action('wp');
foreach(['shop','category','search'] as $route) {
 $GLOBALS['route']=$route;
 foreach([true,false] as $populated) {
  $GLOBALS['populated']=$populated; $GLOBALS['iteration']=0;
  $html=native_archive();
  verify(substr_count($html,'class="fp-shell fp-catalog"')===1,"$route: one archive shell");
  verify(substr_count($html,'id="fp-catalog-search"')===1,"$route: native legacy path includes search");
  verify(substr_count($html,'class="filter"')===3,"$route: categories render");
  verify(!str_contains($html,'LEGACY TITLE')&&!str_contains($html,'LEGACY BREADCRUMB'),"$route: no duplicate native heading/trail");
  verify(str_contains($html,'EXTENSION'),"$route: extension hooks preserved");
  verify(str_contains($html,$populated?'NATIVE PRODUCT':'empty-state'),"$route: native loop/empty path kept");
  if($populated)verify(substr_count($html,'NATIVE PAGINATION')===1,'native pagination retained');
  if(!$populated&&is_search())verify(str_contains($html,'sin &lt;coincidencias&gt;'),'empty query is escaped');
  // The classic PHP fallback uses the same frame rather than a second copy.
  $GLOBALS['iteration']=0; ob_start(); include __DIR__.'/../wp-content/themes/freeplast/woocommerce/archive-product.php'; $fallback=ob_get_clean();
  verify(substr_count($fallback,'class="fp-shell fp-catalog"')===1&&substr_count($fallback,'id="fp-catalog-search"')===1,'classic fallback has one shared frame');
 }
}
if ('1' === getenv('FREEPLAST_TEST_CATALOG_HTML')) {
 $GLOBALS['route']='shop'; $GLOBALS['populated']=true; $GLOBALS['iteration']=0;
 echo native_archive();
} else { echo 'native catalog frame: '.$GLOBALS['checks']." checks passed (no HTTP)\n"; }
