<?php
/** Pinned native hook/template/HTML-parser probes; no WP bootstrap/DB/HTTP. */
define('ABSPATH', __DIR__ . '/../.build/wp/');
function esc_html($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return esc_html($s); }
function esc_url($s) { return esc_html($s); }
function esc_html__($s) { return esc_html($s); }
function is_product() { return $GLOBALS['sheet']; }
function wc_get_page_permalink($page) { return 'https://example.test/catalogo/'; }
function get_the_ID() { return 23; }
function wp_reset_postdata() {}
class WP_Query { private $index = 0; function __construct($args) {} function have_posts() { return $this->index++ === 0; } function the_post() {} }
require ABSPATH . 'wp-includes/plugin.php';
$checks=0;
function verify($ok,$label) { global $checks; $checks++; if (!$ok) { throw new RuntimeException($label); } }
function woocommerce_breadcrumb() { echo '[native-breadcrumb]'; }
function wc_get_template_part($slug,$name) {
    // Execute the actual breadcrumb call from our sheet inside native ClassicTemplate.
    $source=file_get_contents(__DIR__.'/../wp-content/themes/freeplast/woocommerce/content-single-product.php');
    preg_match('/<div class="fp-breadcrumb">\s*<\?php(.*?)\?>/s',$source,$m);
    if (!$m) throw new RuntimeException('Sheet breadcrumb call missing');
    eval($m[1]);
}
$source=file_get_contents(ABSPATH.'wp-content/plugins/woocommerce/src/Blocks/BlockTypes/ClassicTemplate.php');
preg_match('/protected function render_single_product\(\)\s*\{.*?\n\t\}/s',$source,$m);
if (!$m) throw new RuntimeException('Pinned ClassicTemplate method missing');
eval(str_replace('protected function render_single_product()', 'function render_native_single_product()', $m[0]));
$source=file_get_contents(__DIR__.'/../wp-content/themes/freeplast/functions.php');
preg_match('/function fp_theme_place_product_breadcrumb\(\)\s*\{.*?\n\}/s',$source,$m);
if (!$m) throw new RuntimeException('Theme placement helper missing');
eval($m[0]);
$GLOBALS['sheet']=true;
add_action('woocommerce_before_main_content','woocommerce_breadcrumb',27);
add_action('woocommerce_before_main_content',static function(){echo '[extension]';},35);
verify(substr_count(render_native_single_product(),'[native-breadcrumb]')===2,'native ClassicTemplate reproduces the outer/inner duplicate before the fix');
fp_theme_place_product_breadcrumb();
$html=render_native_single_product();
verify(substr_count($html,'[native-breadcrumb]')===1,'native frame renders one breadcrumb after scoped placement');
verify(str_contains($html,'[extension]'),'unrelated native/extension hooks survive');
add_action('woocommerce_before_main_content','woocommerce_breadcrumb',27);
$GLOBALS['sheet']=false; fp_theme_place_product_breadcrumb();
verify(has_action('woocommerce_before_main_content','woocommerce_breadcrumb')===27,'non-product native hook remains untouched');
function trail($crumbs,$sheet) {
    $GLOBALS['sheet']=$sheet; $breadcrumb=$crumbs;
    $wrap_before='<nav>'; $wrap_after='</nav>'; $before=$after=''; $delimiter=' › ';
    ob_start(); include __DIR__.'/../wp-content/themes/freeplast/woocommerce/global/breadcrumb.php'; return ob_get_clean();
}
$html=trail([['Agrícola','https://example.test/categoria/agricola/'],['Producto actual','']],true);
verify(str_contains($html,'href="https://example.test/catalogo/">Catálogo</a>'),'sheet begins at native shop URL');
verify(str_contains($html,'href="https://example.test/categoria/agricola/">Agrícola</a>'),'category remains a usable native link');
verify(!str_contains($html,'Producto actual'),'A does not repeat the product heading in the breadcrumb');
verify(substr_count(trail([['Tienda',wc_get_page_permalink('shop')],['Agrícola','/categoria/'],['Producto','']],true),'catalogo/')===1,'existing native shop crumb is not duplicated');
verify(str_contains(trail([['<script>','/safe/'],['Producto','']],true),'&lt;script&gt;'),'native labels are escaped');
verify(trail([['Tienda','/shop/'],['Categoría actual','']],false)==='<nav><a href="/shop/">Tienda</a> › Categoría actual</nav>','ordinary native trail rendering preserved');

require ABSPATH . 'wp-includes/utf8.php';
require ABSPATH . 'wp-includes/kses.php';
foreach (['span','text-replacement','attribute-token','decoder','tag-processor'] as $part) require ABSPATH.'wp-includes/html-api/class-wp-html-'.$part.'.php';
$source=file_get_contents(ABSPATH.'wp-includes/block-template.php');
preg_match('/function _block_template_add_skip_link\(.*?\n\}/s',$source,$m);
if (!$m) throw new RuntimeException('Pinned skip-link implementation missing');
eval($m[0]);
$html=_block_template_add_skip_link('<div class="wp-site-blocks">'.file_get_contents(__DIR__.'/../wp-content/themes/freeplast/templates/page-checkout.html').'</div>');
verify(str_contains($html,'id="wp-skip-link"'),'pinned WordPress supplies a skip link without theme duplication');
$processor = new WP_HTML_Tag_Processor($html); $processor->next_tag('MAIN');
verify($processor->get_attribute('id') === 'wp--skip-link--target','pinned WordPress supplies the missing main target on actual template markup');
$html=_block_template_add_skip_link('<div class="wp-site-blocks"><main id="existing-target">Content</main></div>');
verify(str_contains($html,'href="#existing-target"'),'native skip link respects existing main target');
echo "native chrome: $checks PHP checks passed (pinned hooks/ClassicTemplate/HTML parser; HTTP/rendered focus unrun)\n";
