<?php
/** Offline regression: php wordpress/scripts/sitemap-test.php */
define('ABSPATH', __DIR__);
$GLOBALS['hooks'] = array();
function add_action($name, $callback, $priority = 10) { $GLOBALS['hooks'][$name] = $callback; }
function add_filter($name, $callback) { $GLOBALS['hooks'][$name] = $callback; }
function home_url($path) { return 'http://mliu:8131' . $path; }
function wc_get_page_id($name) { return array('cart'=>10, 'checkout'=>11, 'myaccount'=>12)[$name]; }
function get_post_types($args) { return array('post'=>'post', 'page'=>'page', 'product'=>'product', 'attachment'=>'attachment'); }
function get_permalink($post) { return home_url($post->ID === 1 ? '/' : '/content-' . $post->ID . '/'); }
function has_block($name, $post) { return str_contains($post->post_content, $name); }
function has_shortcode($content, $name) { return str_contains($content, '[' . $name . ']'); }
function wc_get_product($id) { return new class($id) {
    function __construct(public $id) {}
    function get_catalog_visibility() { return $this->id === 6 ? 'hidden' : 'visible'; }
}; }
function taxonomy_exists($name) { return true; }
function get_terms($args) { return array((object)array('slug'=>'agricola')); }
function get_term_link($term) { return home_url('/categoria-producto/' . $term->slug . '/'); }
function is_wp_error($value) { return false; }
class WP_Query {
    public $posts;
    function __construct($args) {
        if ($args['post_status'] !== 'publish' || $args['has_password'] !== false || in_array('attachment', $args['post_type'], true)) {
            throw new RuntimeException('Public unprotected content only');
        }
        $this->posts = array_values(array_filter($GLOBALS['posts'], fn($p) => !in_array($p->ID, $args['post__not_in'], true)));
    }
}
require __DIR__ . '/../wp-content/themes/freeplast/inc/sitemap.php';
$GLOBALS['posts'] = array();
foreach (array(1, 2, 3, 4, 5, 6, 10, 11, 12) as $id) {
    $GLOBALS['posts'][] = (object)array('ID'=>$id, 'post_type'=>$id >= 5 && $id <= 6 ? 'product' : 'page', 'post_content'=>$id === 3 ? 'woocommerce/cart' : ($id === 4 ? '[woocommerce_checkout]' : ''));
}
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
$urls = freeplast_sitemap_urls();
check($urls === array(home_url('/'), home_url('/content-2/'), home_url('/content-5/'), home_url('/categoria-producto/agricola/')), 'Canonical deduplication, public products/categories, workflow exclusions');
$GLOBALS['posts'][] = (object)array('ID'=>20, 'post_type'=>'page', 'post_content'=>'');
check(in_array(home_url('/content-20/'), freeplast_sitemap_urls(), true), 'New publications appear without rebuilding');
array_pop($GLOBALS['posts']);
check(!in_array(home_url('/content-20/'), freeplast_sitemap_urls(), true), 'Removed publications disappear without rebuilding');
$robots = ($GLOBALS['hooks']['robots_txt'])("User-agent: *\nDisallow: /private/\n");
check(str_contains($robots, 'Disallow: /private/'), 'Existing robots policy preserved');
check(str_contains($robots, "Sitemap: http://mliu:8131/sitemap.xml\n"), 'Canonical sitemap advertised');
echo "Sitemap regression checks passed.\n";
