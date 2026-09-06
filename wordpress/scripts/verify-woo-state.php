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
// Issue #25 (WA-02): the Ventas Freeplast role stays least-privilege over Woo's own order surfaces.
$sales=get_role('ventas_freeplast');
$check(null!==$sales,'Ventas Freeplast role exists');
foreach(array('read','manage_freeplast_quotes','edit_shop_orders','edit_others_shop_orders') as $cap) {
    $check(!empty($sales->capabilities[$cap]),'Sales role grants '.$cap);
}
$check(count($sales->capabilities)===4,'Sales role carries exactly the approved four-cap set');
foreach(array('delete_shop_orders','delete_others_shop_orders','delete_private_shop_orders','delete_shop_order','publish_shop_orders','read_private_shop_orders','manage_woocommerce','view_woocommerce_reports','create_customers','edit_products','manage_product_terms','edit_shop_coupons') as $forbidden) {
    $check(empty($sales->capabilities[$forbidden]),'Sales role lacks '.$forbidden);
}
$administrator=get_role('administrator');
$check(!empty($administrator->capabilities['manage_woocommerce']) && !empty($administrator->capabilities['manage_freeplast_quotes']),'Administrator keeps Woo + sales capabilities (the sales sync never rewrites other roles)');
$shop_manager=get_role('shop_manager');
$check(null===$shop_manager || !empty($shop_manager->capabilities['manage_woocommerce']),'shop_manager privileges untouched');
$sales_users=get_users(array('role'=>'ventas_freeplast','fields'=>'all'));
foreach($sales_users as $user) {
    foreach(array('delete_shop_orders','manage_woocommerce','edit_products','manage_options','promote_users') as $forbidden) {
        $check(!user_can($user,$forbidden),'Sales account '.$user->user_login.' cannot '.$forbidden);
    }
    $check(user_can($user,'edit_shop_orders'),'Sales account '.$user->user_login.' can list orders and add notes');
}
// The adapter's server-side ventas guards are registered (behavior unit-tested offline in test-woo-adapter.php).
$check(false!==has_filter('woocommerce_prevent_admin_access','fpw_allow_sales_admin_access'),'Woo\'s default admin lock-down opens for the sales role without granting edit_posts');
$check(false!==has_action('admin_init','fpw_force_private_sales_note'),'Sales notes normalize to private at the origin');
$check(false!==has_filter('woocommerce_order_actions','fpw_sales_order_actions'),'Email resends removed from the order-actions select for ventas');
$check(false!==has_action('woocommerce_before_resend_order_emails','fpw_deny_sales_email_resend'),'Crafted email resends are denied server-side for ventas');
// Issue #33: the restricted session reads and annotates, it never writes the record.
$check(false!==has_action('admin_init','fpw_deny_sales_record_mutation'),'The ventas mutation front door rides admin_init before any save machinery');
$check(false!==has_filter('woocommerce_process_shop_order_meta','fpw_deny_sales_order_save'),'The editor-save backstop refuses order writes for ventas in both storage modes');
$check(false!==has_filter('woocommerce_bulk_action_ids','fpw_deny_sales_bulk_actions'),'Orders-list bulk mutations are denied for ventas');
$check(false!==has_filter('woocommerce_rest_check_permissions','fpw_deny_sales_rest_mutation'),'The REST orders API denies mutating contexts for ventas');
$check(false!==has_filter('woocommerce_admin_order_preview_actions','fpw_sales_preview_status_actions'),'Quick-status buttons are not offered to ventas in the order preview');
$check(false!==has_filter('woocommerce_admin_order_actions','fpw_sales_row_status_actions'),'List row status buttons are not offered to ventas');
$check(false!==has_filter('bulk_actions-edit-shop_order','fpw_sales_list_bulk_actions'),'The bulk select offers ventas no mutating action');
$check(false!==has_filter('woocommerce_email_enabled_customer_note','__return_false'),'The note-to-customer email is disabled');
WP_CLI::success($checks.' state checks passed. No production requests or mutations.');
