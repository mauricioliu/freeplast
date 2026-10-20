<?php
/** Offline regression: php wordpress/scripts/discovery-test.php */
define('ABSPATH', __DIR__);
function add_action($name, $callback, $priority) { $GLOBALS['callback'] = $callback; }
function esc_url_raw($url) { return $url; }
function get_rest_url($blog = null, $path = '') { return 'https://example.test/subdir/?rest_route=/' . ltrim($path, '/'); }
function get_queried_object() { return $GLOBALS['object']; }
function rest_get_route_for_post($post) { return $GLOBALS['route']; }
class WP_Post {}
require __DIR__ . '/../wp-content/themes/freeplast/inc/discovery.php';
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
$GLOBALS['object'] = new WP_Post();
$GLOBALS['route'] = '/wp/v2/pages/52';
$links = freeplast_discovery_links();
check($links === array(
    '<https://example.test/subdir/?rest_route=/>; rel="service-desc"; type="application/json"',
    '<https://example.test/subdir/?rest_route=/wp/v2/pages/52>; rel="describedby"; type="application/json"',
), 'Registered relations with install-aware API URLs');
$GLOBALS['object'] = null;
check(count(freeplast_discovery_links()) === 1, 'Posts front page needs no invented page resource');
$GLOBALS['object'] = new WP_Post();
$GLOBALS['route'] = '';
check(count(freeplast_discovery_links()) === 1, 'No description advertised for an unavailable REST route');
function is_front_page() { return false; }
function is_feed() { return false; }
function is_embed() { return false; }
function is_paged() { return false; }
($GLOBALS['callback'])();
check(headers_list() === array(), 'Non-home responses unchanged');
echo "Discovery regression checks passed.\n";
