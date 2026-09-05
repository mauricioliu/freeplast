<?php
/** Read-only database/domain checks after migration. wp eval-file /bundle/verify-woo-state.php */
if (!defined('WP_CLI') || !WP_CLI || get_option('home') !== 'https://freeplast.mliu.site') { throw new RuntimeException('Staging only.'); }
$checks=0;
$check=static function($condition,$label) use (&$checks) { if (!$condition) { WP_CLI::error($label); } ++$checks; };
$check(class_exists('WooCommerce') && class_exists('Quotes_WC'),'Woo dependencies active');
$check(!class_exists('Freeplast_CQ_Basket'),'Legacy basket is inactive');
$check(file_exists(WPMU_PLUGIN_DIR.'/freeplast-staging-mail.php'),'Independent mail containment exists');
$check((int)wp_count_posts('product')->publish===17,'17 products');
$check((int)wp_count_posts('product_variation')->publish===10,'10 color variations');
$map=get_option('fpw_legacy_order_map',array());
$check(count($map)===2,'Two historical mappings');
foreach ($map as $post_id=>$order_id) {
    $order=wc_get_order($order_id);
    $check($order && get_post_type($post_id)==='fp_quote','Historical original and Woo copy exist');
    foreach(get_post_meta($post_id) as $key=>$values) {
        if(str_starts_with($key,'_fpq_')) { $check($order->get_meta($key) === maybe_unserialize($values[0]),'Historical metadata preserved: '.$key); }
    }
    $check(count($order->get_items())===count(json_decode(get_post_meta($post_id,'_fpq_items',true),true)),'Historical line count');
}
$check(false===apply_filters('woocommerce_hold_stock_for_checkout',true),'No stock reservations');
$check(false===apply_filters('woocommerce_can_reduce_order_stock',true),'No stock reduction');
$check(false===apply_filters('woocommerce_checkout_registration_required',true),'No registration required');
foreach(wc_get_orders(array('limit'=>-1)) as $order) {
    $check(!$order->is_paid() && !$order->needs_payment(),'No payment on request '.$order->get_id());
    $check(!$order->get_meta('_order_stock_reduced'),'No reduced stock on request '.$order->get_id());
}
$check((int)get_option('fpw_suppressed_mail_count')>=2,'Mail attempts suppressed');
$fields=fpw_checkout_fields(array());
foreach(array('billing_first_name','billing_phone','billing_email','billing_company','billing_fp_rut','billing_fp_giro','billing_fp_dispatch') as $key) { $check($fields['billing'][$key]['required'],'Required field '.$key); }
$errors=new WP_Error(); fpw_validate_checkout(array('payment_method'=>'quotes-gateway','billing_fp_dispatch'=>'si','billing_fp_address'=>''),$errors);
$check($errors->has_errors(),'Address required with dispatch');
$errors=new WP_Error(); fpw_validate_checkout(array('payment_method'=>'quotes-gateway','billing_fp_dispatch'=>'no'),$errors);
$check(!$errors->has_errors(),'Address not required without dispatch');
$check(str_contains(get_post_field('post_content',wc_get_page_id('cart')),'wp:woocommerce/cart'),'Native Cart block');
$check(str_contains(get_post_field('post_content',wc_get_page_id('checkout')),'[woocommerce_checkout]'),'Native classic Checkout');
$check((int)get_option('blog_public')===0,'Staging stays noindex');
WP_CLI::success($checks.' state checks passed. No production requests or mutations.');
