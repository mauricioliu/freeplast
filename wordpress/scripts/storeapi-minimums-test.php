<?php
/** Public quantities against pinned Woo 11.1.0 QuantityLimits and CartController. */
require __DIR__ . '/quote-draft-test.php';
if (!function_exists('is_wp_error')) { function is_wp_error($v) { return $v instanceof WP_Error; } }
if (!function_exists('__')) { function __($v,$domain=null) { return $v; } }
if (!function_exists('esc_html__')) { function esc_html__($v,$domain=null) { return $v; } }
if (!function_exists('wp_parse_args')) { function wp_parse_args($args,$defaults) { return array_merge((array)$defaults,(array)$args); } }
if (!function_exists('wc_stock_amount')) { function wc_stock_amount($v) { return (int)$v; } }
if (!function_exists('wc_is_stock_amount_integer')) { function wc_is_stock_amount_integer() { return true; } }
if (!class_exists('WC_Product')) { class WC_Product {} }
class FPW_StoreAPI_Product extends WC_Product {
    function __construct(public int $id, public int $parent=0) {}
    function get_id() { return $this->id; }
    function get_parent_id() { return $this->parent; }
    function get_title() { return 'Producto'; }
    function get_name() { return 'Producto'; }
    function is_sold_individually() { return false; }
    function managing_stock() { return false; }
    function backorders_allowed() { return false; }
    function get_min_purchase_quantity() { return 1; }
    function get_max_purchase_quantity() { return -1; }
    function get_purchase_quantity_step() { return 1; }
    function is_purchasable() { return true; }
}
class FPW_StoreAPI_Cart {
    public array $cart_contents = array();
    public array $set_calls = array();
    function get_cart() { return $this->cart_contents; }
    function set_quantity($key,$qty=1) { $this->set_calls[]=array($key,$qty); $this->cart_contents[$key]['quantity']=$qty; return true; }
}
$GLOBALS['fpw_storeapi_cart']=new FPW_StoreAPI_Cart();
if (!function_exists('WC')) { function WC() { return (object)array('cart'=>$GLOBALS['fpw_storeapi_cart']); } }
$woo=realpath(__DIR__.'/../.build/wp/wp-content/plugins/woocommerce');
foreach (array('/src/Utilities/NumberUtil.php','/src/StoreApi/Exceptions/RouteException.php','/src/StoreApi/Utilities/DraftOrderTrait.php','/src/StoreApi/Utilities/QuantityLimits.php','/src/StoreApi/Utilities/CartController.php') as $file) {
    check(is_file($woo.$file),'pinned source present: '.$file); require $woo.$file;
}
$source=file_get_contents($woo.'/includes/wc-template-functions.php');
check(1===preg_match('/\nfunction wc_get_quantity_input_args\( .*?\n\}/s',$source,$match),'native quantity args');
eval(str_replace('function wc_get_quantity_input_args','function fpw_pinned_wc_get_quantity_input_args',$match[0]));
if (!function_exists('wc_get_quantity_input_args')) { function wc_get_quantity_input_args($args,$product=null) { return fpw_pinned_wc_get_quantity_input_args($args,$product); } }
class FPW_Test_Cart_Controller extends Automattic\WooCommerce\StoreApi\Utilities\CartController {
    function get_cart_instance() { return $GLOBALS['fpw_storeapi_cart']; }
    function get_cart_item($key) { return $GLOBALS['fpw_storeapi_cart']->cart_contents[$key] ?? array(); }
}
$limits=new Automattic\WooCommerce\StoreApi\Utilities\QuantityLimits();
$controller=new FPW_Test_Cart_Controller();
$GLOBALS['fpwd_post_meta'][22]['_fp_quote_min_qty']='2000';
$GLOBALS['fpwd_post_meta'][22]['_fp_quote_step']='200';
foreach (array(new FPW_StoreAPI_Product(22),new FPW_StoreAPI_Product(310,22),new FPW_StoreAPI_Product(999)) as $product) {
    $item=array('key'=>'a','product_id'=>$product->parent ?: $product->id,'variation_id'=>$product->parent ? $product->id : 0,'quantity'=>30,'data'=>$product);
    $GLOBALS['fpw_storeapi_cart']->cart_contents=array('a'=>$item);
    $native=$limits->get_cart_item_quantity_limits($item);
    check($native['minimum']===1 && $native['multiple_of']===1,'native limits are one, not commercial minimum/delta');
    check($GLOBALS['fpw_storeapi_cart']->cart_contents['a']['quantity']===30,'reading limits preserves selected quantity');
    foreach (array(1,5,35,65,71,128,129,1999,2001) as $qty) {
        check(true===$limits->validate_cart_item_quantity($qty,$item),'arbitrary positive integer accepted by native Store API');
        $controller->set_cart_item_quantity('a',$qty);
        check($GLOBALS['fpw_storeapi_cart']->cart_contents['a']['quantity']===$qty,'native update persists exact quantity');
    }
    check(is_wp_error($limits->validate_cart_item_quantity(0,$item)),'native minimum rejects zero');
}
$add=$registered_filters['woocommerce_add_to_cart_validation'][0];
$update=$registered_filters['woocommerce_update_cart_validation'][0];
foreach (array(1,25,65,128,2001) as $qty) {
    check($add(true,22,$qty,310) && $update(true,'a',array('product_id'=>22),$qty),'classic simple/variation add/update accepts any positive integer');
}
foreach (array(0,-1,1.5,'1.5','-2','abc','',array(),true,null) as $bad) {
    check(!fpw_valid_public_quantity($bad),'invalid quantity rejected');
    $_REQUEST['quantity']=$bad;
    check(!$add(true,22,1,310),'raw add checked before coercion');
    $_POST['cart']=array('a'=>array('qty'=>$bad));
    check(!$update(true,'a',array('product_id'=>22),1),'raw update checked before coercion');
}
unset($_REQUEST['quantity'],$_POST['cart']);
$guard=end($registered_filters['rest_pre_dispatch']);
$request=new class {
    public $qty=1; public $route='/wc/store/v1/cart/add-item'; public $method='POST';
    function get_method() { return $this->method; }
    function get_route() { return $this->route; }
    function get_param($name) { return $this->qty; }
    function has_param($name) { return true; }
};
foreach (array('add-item','update-item','items','items/'.str_repeat('a',32)) as $route) {
    $request->route='/wc/store/v1/cart/'.$route;
    foreach (array(0,-1,1.5,'1.5','abc',array(),true,null) as $bad) { $request->qty=$bad; check(is_wp_error($guard(null,null,$request)),'REST raw invalid quantity rejected before native coercion/removal'); }
    foreach (array(1,65,128,2001) as $qty) { $request->qty=$qty; check(null===$guard(null,null,$request),'REST arbitrary integer passes'); }
}
$request->route='/wc/store/v1/cart/remove-item'; $request->qty=0;
check(null===$guard(null,null,$request),'separate native removal action untouched');
$request->route='/wc/store/v1/cart/items/'.str_repeat('a',32);
foreach (array('PUT','PATCH') as $method) { $request->method=$method; check(is_wp_error($guard(null,null,$request)),'REST item edit rejects zero'); }
$request->method='DELETE';
check(null===$guard(null,null,$request),'native DELETE remains separate from invalid quantity update');
$item_data=apply_filters('woocommerce_get_item_data',array(),array('product_id'=>22));
check(in_array(array('key'=>'Mínimo','display'=>'2.000 unidades'),$item_data,true),'cart displays informative minimum');
check(fpw_quote_delta(fpw_quote_minimum_parent_id(new FPW_StoreAPI_Product(310,22)))===200,'variation inherits parent button delta');
echo "storeapi minimums: $assertions cumulative offline checks passed; pinned native code, no server\n";
