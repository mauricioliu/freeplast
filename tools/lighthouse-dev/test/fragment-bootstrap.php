<?php
// Isolated source test: no WordPress bootstrap, database, HTTP or real session.
$scenario = $argv[1] ?? 'active';
define('ABSPATH', __DIR__);
define('WP_ENVIRONMENT_TYPE', $scenario === 'production' ? 'production' : 'local');
define('WP_HOME', $scenario === 'other-port' ? 'http://mliu:8890' : 'http://mliu:8131');
putenv('FREEPLAST_LOCAL_URL=' . ($scenario === 'wrong-server' ? 'http://mliu:8890' : 'http://mliu:8131'));
$_GET = $scenario === 'off' ? array() : array('fp-measure' => $scenario === 'array-query' ? array('1') : '1');
$_SERVER = array('REQUEST_METHOD' => $scenario === 'post' ? 'POST' : 'GET', 'REQUEST_URI' => $scenario === 'checkout' ? '/checkout/?fp-measure=1' : '/?fp-measure=1');
$_COOKIE = $scenario === 'cookie' ? array('existing' => 'private') : array();
$calls = array(); $scripts = array();
function add_filter(...$args) {}
function is_user_logged_in() { global $scenario; return $scenario === 'logged-in'; }
$cart = new class {
 public function is_empty() { global $scenario; return $scenario !== 'nonempty'; }
 public function get_cart_hash() { global $scenario, $calls; $calls[]='get_cart_hash'; return $scenario === 'hash' ? 'unexpected' : ''; }
};
$session = new class { public function has_session() { global $scenario; return $scenario === 'session'; } };
$woo = (object)array('cart'=>$scenario === 'missing-cart' ? null : $cart,'session'=>$scenario === 'missing-session' ? null : $session);
function WC() { global $woo; return $woo; }
function woocommerce_mini_cart() { global $calls; $calls[]='woocommerce_mini_cart'; echo '<p class="woocommerce-mini-cart__empty-message">Native synthetic empty cart</p>'; }
function apply_filters($name,$value) {
 global $calls; $calls[]=$name;
 if ($name !== 'woocommerce_add_to_cart_fragments') throw new Exception('unexpected hook');
 $value['span.fpw-basket-count']='<span class="fpw-basket-count">0</span>';
 $value['div.fpw-card-selections']='<div class="fpw-card-selections" data-fpw-cart-state="1" hidden></div>';
 $value['div.fpw-selection-dock']='<div class="fpw-selection-dock" hidden></div>';
 return $value;
}
function wp_json_encode($v,$flags=0) { return json_encode($v,$flags); }
function wp_add_inline_script($handle,$script,$position) { global $scripts; $scripts[]=array('handle'=>$handle,'script'=>$script,'position'=>$position); }
require __DIR__.'/../../../wordpress/wp-content/themes/freeplast/inc/measure-mode.php';
$params=array('cart_hash_key'=>'wc_cart_hash_synthetic','fragment_name'=>'wc_fragments_synthetic','wc_ajax_url'=>'/?wc-ajax=%%endpoint%%','request_timeout'=>5000);
if ($scenario === 'missing-param') unset($params['fragment_name']);
$result=fp_measure_fragment_bootstrap($params,$scenario === 'other-handle' ? 'wc-other' : 'wc-cart-fragments');
if ($result !== $params) throw new Exception('native params changed');
if ($scenario === 'active') {
 if (count($scripts)!==1 || $scripts[0]['position']!=='before' || $calls!==array('woocommerce_mini_cart','woocommerce_add_to_cart_fragments','get_cart_hash')) throw new Exception('native projection/order changed');
} elseif (count($scripts)!==0) throw new Exception('prep escaped predicate');
if (!in_array($scenario,array('active','hash'),true) && $calls!==array()) throw new Exception('inactive mode executed native projection');
echo json_encode(array('scenario'=>$scenario,'params'=>$params,'scripts'=>$scripts,'calls'=>$calls));
