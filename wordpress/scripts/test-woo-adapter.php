<?php
/** Offline unit checks of the small local field boundary, not a WooCommerce simulation. */
define('ABSPATH',__DIR__);
function add_action(...$args) {}
function add_filter(...$args) {}
class WP_Error {
    public array $codes=array();
    public function add($code,$message,$data=null) { $this->codes[]=$code; }
    public function has_errors() { return (bool)$this->codes; }
}
require __DIR__.'/../wp-content/plugins/freeplast-woo/freeplast-woo.php';
function check($ok,$message) { if(!$ok) { throw new RuntimeException($message); } }
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
echo "checks: 16 local field assertions passed\n";
