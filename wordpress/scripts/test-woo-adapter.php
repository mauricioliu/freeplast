<?php
/** Offline unit checks of the small local field boundary, not a WooCommerce simulation. */
define('ABSPATH',__DIR__);
$registered_filters=array();
function add_action(...$args) {}
function add_filter(...$args) { global $registered_filters; $registered_filters[$args[0]][]=$args[1]; }
class WP_Error {
    public array $codes=array();
    public function add($code,$message,$data=null) { $this->codes[]=$code; }
    public function has_errors() { return (bool)$this->codes; }
}
require __DIR__.'/../wp-content/plugins/freeplast-woo/freeplast-woo.php';
require __DIR__.'/../wp-content/themes/freeplast/functions.php';
$assertions=0;
function check($ok,$message) { global $assertions; $assertions++; if(!$ok) { throw new RuntimeException($message); } }
$fields=fpw_checkout_fields(array());
foreach(array('billing_first_name','billing_email','billing_phone','billing_company','billing_fp_rut','billing_fp_giro','billing_fp_dispatch') as $field) { check($fields['billing'][$field]['required'], $field.' required'); }
check($fields['billing']['billing_fp_address']['required']===false,'Address is conditionally validated');
check($fields['shipping']===array(),'No shipping calculator fields');
$cases=array(
    array(array('payment_method'=>'quotes-gateway','billing_fp_dispatch'=>'no'),false),
    array(array('payment_method'=>'quotes-gateway','billing_fp_dispatch'=>'si','billing_fp_address'=>''),true),
    array(array('payment_method'=>'quotes-gateway','billing_fp_dispatch'=>'si','billing_fp_address'=>'   '),true),
    array(array('payment_method'=>'quotes-gateway','billing_fp_dispatch'=>'si','billing_fp_address'=>'Camino de prueba, Mostazal'),false),
    array(array('payment_method'=>'quotes-gateway','billing_fp_dispatch'=>'otra'),true),
    array(array('payment_method'=>'bacs','billing_fp_dispatch'=>'no'),true),
    array(array('payment_method'=>'quotes-gateway','billing_fp_dispatch'=>'no','billing_fp_rut'=>str_repeat('1',241)),true),
);
foreach($cases as $index=>[$data,$fails]) { $errors=new WP_Error();fpw_validate_checkout($data,$errors);check($errors->has_errors()===$fails,'Validation case '.$index); }

// Issue #30: the header Productos a Cotizar count reads the Woo cart — distinct lines, never units.
check(!function_exists('WC'),'WooCommerce stub is not loaded yet');
check(fpw_cart_line_count()===0,'Absent WooCommerce renders a zero line count');
class FPW_Fake_WC { public $cart=null; }
class FPW_Fake_Cart { public array $lines=array(); public function get_cart() { return $this->lines; } }
if (!function_exists('WC')) { $GLOBALS['fpw_woo']=new FPW_Fake_WC(); function WC() { return $GLOBALS['fpw_woo']; } }
check(fpw_cart_line_count()===0,'Empty cart renders a zero line count');
$GLOBALS['fpw_woo']->cart=new FPW_Fake_Cart();
check(fpw_cart_line_count()===0,'Cart without lines renders a zero line count');
$GLOBALS['fpw_woo']->cart->lines=array('a'=>array('quantity'=>3),'b'=>array('quantity'=>5));
check(fpw_cart_line_count()===2,'Quantities do not add lines: 3+5 units stay 2 distinct lines');
$GLOBALS['fpw_woo']->cart->lines+=array('c'=>array('quantity'=>1),'d'=>array('quantity'=>7));
check(fpw_cart_line_count()===4,'Distinct variants and colours count as separate lines');
$fragment_filter=reset($registered_filters['woocommerce_add_to_cart_fragments']);
$fragments=$fragment_filter(array());
check(isset($fragments['span.fpw-basket-count']),'Header count joins the native add-to-cart fragment refresh');
check($fragments['span.fpw-basket-count']==='<span class="fpw-basket-count">4</span>','Fragment carries the current distinct line count');
$header=file_get_contents(__DIR__.'/../wp-content/themes/freeplast/parts/header.html');
check(substr_count($header,'fpw-basket-count')===2,'Both header surfaces (desktop link + mobile menu) carry the count span');
check(substr_count($header,'{{FREEPLAST_BASKET_COUNT}}')===2,'Both header surfaces server-render the count token');
$resolvers=array_values(array_filter($registered_filters['render_block'],static function($resolver){
    $rendered=$resolver('{{FREEPLAST_BASKET_COUNT}}');
    return is_string($rendered) && !str_contains($rendered,'{{FREEPLAST_BASKET_COUNT}}');
}));
check(count($resolvers)===1,'Exactly one theme resolver replaces the count token');
$link='<a class="fp-woo-selection" href="/cotizacion/">Productos a Cotizar (<span class="fpw-basket-count">{{FREEPLAST_BASKET_COUNT}}</span>)</a>';
check(str_contains($resolvers[0]($link),'(<span class="fpw-basket-count">4</span>)'),'First paint renders the live distinct line count');
$GLOBALS['fpw_woo']->cart->lines=array();
check(str_contains($resolvers[0]($link),'(<span class="fpw-basket-count">0</span>)'),'Empty basket renders the documented (0) state');
check($resolvers[0]('<p>sin token</p>')==='<p>sin token</p>','Blocks without the token pass through byte-identically');
echo "checks: {$assertions} local assertions passed (checkout fields + header line count)\n";
