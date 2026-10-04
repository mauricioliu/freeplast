<?php
/** Offline capability/route boundary; real HTTP companion exercises native WP. */
define('ABSPATH', __DIR__);
function add_action(...$args) {}
function add_filter(...$args) {}
function current_user_can($cap) { return !empty($GLOBALS['caps'][$cap]); }
require __DIR__.'/../wp-content/plugins/freeplast-woo/quotation-access.php';
$count=0;
function check($ok,$label) { global $count; $count++; if(!$ok) throw new RuntimeException($label); }
foreach (array(array(array(), false), array(array('read'=>true), false), array(array('edit_shop_orders'=>true), false), array(array('fpw_manage_quotations'=>true), true), array(array('manage_woocommerce'=>true), true)) as [$caps,$expected]) {
    $GLOBALS['caps']=$caps;
    check(fpw_can_manage_quotations()===$expected, 'Quotation boundary does not admit ordinary staff');
}
$GLOBALS['caps']=array('fpw_manage_quotations'=>true);
check(fpw_quotation_screen_capability()==='fpw_manage_quotations', 'Restricted registration uses its own capability');
check(!current_user_can('manage_woocommerce'), 'Quotation permission never grants Woo administration');
$GLOBALS['caps']=array('manage_woocommerce'=>true);
check(fpw_quotation_screen_capability()==='manage_woocommerce', 'Existing Woo manager registration preserved');
check(fpw_quotation_only_user((object)['roles'=>['fpw_quotation_manager']]), 'Dedicated role is constrained');
check(!fpw_quotation_only_user((object)['roles'=>['administrator']]), 'Administrator remains unchanged');
foreach (array(
 ['admin.php',['page'=>'fpw-quotations'],true],
 ['admin.php',['page'=>'fpw-quote-draft','workspace'=>'1'],true],
 ['admin-post.php',['action'=>'fpw_quotation_pdf'],true],
 ['admin.php',['page'=>'fpw-quote-draft'],false],
 ['admin.php',['page'=>'fpw-quotations','action'=>'evil'],false],
 ['admin.php',['page'=>'fpw-quotations','import'=>'evil'],false],
 ['admin.php',['page'=>['fpw-quotations']],false],
 ['admin.php',['page'=>'wc-settings'],false],
 ['admin-post.php',['action'=>['fpw_quotation_pdf']],false],
 ['admin-post.php',['action'=>'delete'],false],
 ['admin-ajax.php',['page'=>'fpw-quotations'],false],
 ['plugins.php',['page'=>'fpw-quotations'],false],
 ['options.php',['page'=>'fpw-quotations'],false],
 ['post.php',['page'=>'fpw-quotations'],false],
 ['users.php',['page'=>'fpw-quotations'],false]
) as [$script,$query,$allowed]) {
 check(fpw_quotation_route_allowed($script,$query)===$allowed, 'Only exact quotation routes allowed');
}
// Friendly entry has no auth bypass, no user-controlled redirect and no singular-route collision.
class FPW_Entry_Result extends RuntimeException {}
function home_url($path) { return 'https://example.invalid/subsite'.$path; }
function wp_login_url($redirect) { return home_url('/wp-login.php?redirect_to='.rawurlencode($redirect)); }
function fpw_workspace_url() { return home_url('/wp-admin/admin.php?page=fpw-quotations'); }
function fpw_data_hub_url() { return home_url('/wp-admin/admin.php?page=fpw-data'); }
function nocache_headers() { $GLOBALS['entry_no_cache']=true; }
function is_user_logged_in() { return $GLOBALS['entry_logged_in']; }
function wp_safe_redirect($url) { throw new FPW_Entry_Result($url,302); }
function wp_die($message,$title,$args) { throw new FPW_Entry_Result($message,$args['response']); }
foreach (array(
 ['cotizacion','GET',false,[],0],
 ['cotizaciones-extra','GET',false,[],0],
 ['cotizaciones','GET',false,[],302],
 ['cotizaciones','HEAD',false,[],302],
 ['cotizaciones','POST',false,[],405],
 ['cotizaciones','GET',true,['read'=>true],403],
 ['cotizaciones','GET',true,['fpw_manage_quotations'=>true],302],
 ['cotizaciones','GET',true,['manage_woocommerce'=>true],302]
) as [$path,$method,$logged_in,$caps,$status]) {
 $GLOBALS['wp']=(object)['request'=>$path]; $_SERVER['REQUEST_METHOD']=$method;
 $GLOBALS['entry_logged_in']=$logged_in; $GLOBALS['caps']=$caps; $GLOBALS['entry_no_cache']=false;
 try { fpw_quotation_entry(); check($status===0,'Unrelated routes alone are untouched'); }
 catch (FPW_Entry_Result $e) {
  check($e->getCode()===$status,'Entry enforces authentication and method');
  check($GLOBALS['entry_no_cache'],'Private entry is not cacheable');
  if($status===302) check($e->getMessage()===($logged_in ? fpw_workspace_url() : wp_login_url(fpw_quotation_entry_url())),'Redirect uses only fixed local destinations including subdirectory');
 }
}
// The data-hub entry admits owners and denies the quotation-only role.
foreach (array(
 ['mantenedor','GET',false,[],302],
 ['mantenedor','GET',true,['fpw_manage_quotations'=>true],403],
 ['mantenedor','GET',true,['manage_woocommerce'=>true],302],
 ['mantenedor','GET',true,['fpw_manage_data'=>true],302],
 ['mantenedores','GET',true,['manage_woocommerce'=>true],0],
 ['mantenedor','DELETE',true,['manage_woocommerce'=>true],405]
) as [$path,$method,$logged_in,$caps,$status]) {
 $GLOBALS['wp']=(object)['request'=>$path]; $_SERVER['REQUEST_METHOD']=$method;
 $GLOBALS['entry_logged_in']=$logged_in; $GLOBALS['caps']=$caps; $GLOBALS['entry_no_cache']=false;
 try { fpw_data_entry(); check($status===0,'Unrelated paths stay untouched'); }
 catch (FPW_Entry_Result $e) {
  check($e->getCode()===$status,'Hub entry follows the owner boundary');
  check($GLOBALS['entry_no_cache'],'Hub entry is not cacheable');
  if($status===302 && $logged_in) check($e->getMessage()===fpw_data_hub_url(),'Owner lands exactly on the data hub');
 }
}
echo "quotation access: {$count} offline checks passed\n";
foreach (array(
 ['admin.php',['page'=>'fpw-data'],true],
 ['admin.php',['page'=>'fpw-price-list'],true],
 ['admin.php',['page'=>'fpw-sales-import'],true],
 ['profile.php',[],true],
 ['admin.php',['page'=>'fpw-quotations'],false],
 ['admin.php',['page'=>'fpw-quote-draft','workspace'=>'1'],false],
 ['admin.php',['page'=>'fpw-price-list','action'=>'evil'],false],
 ['admin.php',['page'=>'fpw-data','import'=>'evil'],false],
 ['admin-post.php',['action'=>'fpw_quotation_pdf'],false],
 ['edit.php',['post_type'=>'product'],false],
 ['users.php',[],false]
) as [$script,$query,$allowed]) {
 check(fpw_data_route_allowed($script,$query)===$allowed, 'Data allowlist is exactly the hub, prices and sales');
}
$GLOBALS['caps']=array('fpw_manage_data'=>true);
check(fpw_can_manage_data() && !fpw_can_manage_quotations(), 'Data permission never grants quotations');
check(fpw_restricted_user((object)['roles'=>['fpw_data_manager']]), 'The data role is a restricted account');

// H1 (2026-10-03 review): the quotation role may operate ONLY its own basket lines
// through the Store API routes Woo's basket page itself uses — same session, same
// native wc_store_api nonce. Everything else stays denied, for every restricted role.
$quote_role = ['fpw_quotation_manager'];
foreach (array(
 [$quote_role, '/wc/store/v1/cart', 'GET', false],
 [$quote_role, '/wc/store/v1/cart/add-item', 'POST', false],
 [$quote_role, '/wc/store/v1/cart/update-item', 'POST', false],
 [$quote_role, '/wc/store/v1/cart/remove-item', 'POST', false]
) as [$roles, $route, $method, $denied]) {
 check(fpw_restricted_rest_route_denied($roles, $route, $method) === $denied, 'own-basket Store API route is exactly the allowed exception');
}
$own_basket_start = $count;
foreach (array(
 [$quote_role, '/wc/store/v1/cart', 'POST'],
 [$quote_role, '/wc/store/v1/cart/add-item', 'GET'],
 [$quote_role, '/wc/store/v1/cart/update-item', 'PUT'],
 [$quote_role, '/wc/store/v1/cart/remove-item', 'DELETE'],
 [$quote_role, '/wc/store/v1/cart', 'HEAD'],
 [$quote_role, '/wc/store/v1/cart/apply-coupon', 'POST'],
 [$quote_role, '/wc/store/v1/cart/remove-coupon', 'POST'],
 [$quote_role, '/wc/store/v1/cart/select-shipping-rate', 'POST'],
 [$quote_role, '/wc/store/v1/cart/update-customer', 'POST'],
 [$quote_role, '/wc/store/v1/cart/extensions', 'POST'],
 [$quote_role, '/wc/store/v1/checkout', 'POST'],
 [$quote_role, '/wc/store/v1/order', 'POST'],
 [$quote_role, '/wc/store/v1/orders', 'GET'],
 [$quote_role, '/wc/store/v1/products', 'GET'],
 [$quote_role, '/wc/store/v1/products/12', 'GET'],
 [$quote_role, '/wc/store/batch', 'POST'],
 [$quote_role, '/wc/store/v1/cart/add-item/extra', 'POST'],
 [$quote_role, '/wc/store/v2/cart/update-item', 'POST'],
 [$quote_role, '/wp/v2/users', 'GET'],
 [$quote_role, '/wp/v2/settings', 'POST'],
 [$quote_role, '/wc/v3/orders', 'GET'],
 [$quote_role, '/wc-analytics/orders', 'GET'],
 [$quote_role, '', 'GET'],
 [['fpw_data_manager'], '/wc/store/v1/cart', 'GET'],
 [['fpw_data_manager'], '/wc/store/v1/cart/update-item', 'POST'],
 [['fpw_data_manager'], '/wp/v2/users', 'GET']
) as [$roles, $route, $method]) {
 check(fpw_restricted_rest_route_denied($roles, $route, $method) === true, 'REST outside the own-basket exception stays denied: ' . $method . ' ' . $route . ' for ' . implode(',', $roles));
}
check(fpw_restricted_rest_route_denied(['subscriber'], '/wp/v2/users', 'GET') === false && fpw_restricted_rest_route_denied([], '/wp/v2/users', 'GET') === false && fpw_restricted_rest_route_denied(['subscriber'], '/wc/store/v1/cart/apply-coupon', 'POST') === false, 'unrestricted accounts keep their native REST boundary, including coupons');
check(fpw_restricted_rest_route_denied(['fpw_quotation_manager', 'fpw_data_manager'], '/wc/store/v1/cart', 'GET') === false && fpw_restricted_rest_route_denied(['fpw_quotation_manager', 'fpw_data_manager'], '/wp/v2/users', 'GET') === true, 'dual-role staff gets the same single exception, not admin REST');
check(fpw_quotation_own_basket_rest_route('/wc/store/v1/cart/update-item/../users', 'POST') === false, 'path segments never smuggle another route into the exception');
check(fpw_quotation_own_basket_rest_route('/wc/store/v1/cart', 'get') === true && fpw_quotation_own_basket_rest_route('/wc/store/v1/cart/add-item', 'post') === true, 'method casing normalizes without opening extra verbs');
echo "quotation own-basket REST boundary: " . ($count - $own_basket_start) . " denial checks in this block\n";
