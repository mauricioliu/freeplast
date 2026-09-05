<?php
if (!defined('WP_CLI') || get_option('home') !== 'https://freeplast.mliu.site') { throw new RuntimeException('Staging only'); }
$roles = wp_roles()->roles;
$capnames = ['read','manage_freeplast_quotes','edit_shop_orders','edit_others_shop_orders','read_private_shop_orders','delete_shop_orders','manage_woocommerce','edit_products','edit_users','manage_options','install_plugins'];
foreach ($roles as $slug=>$role) {
 $users=get_users(['role'=>$slug,'fields'=>'ID']);
 $caps=[]; foreach($capnames as $cap) $caps[$cap]=!empty($role['capabilities'][$cap]);
 echo wp_json_encode(['role'=>$slug,'users'=>count($users),'capabilities'=>$caps])."\n";
}
echo wp_json_encode(['hpos'=>get_option('woocommerce_custom_orders_table_enabled'),'hpos_sync'=>get_option('woocommerce_custom_orders_table_data_sync_enabled'),'mail_filter'=>has_filter('pre_wp_mail'),'statuses'=>wc_get_order_statuses(),'order_count'=>count(wc_get_orders(['limit'=>-1,'return'=>'ids']))])."\n";
// Inspect only explicitly synthetic request; never output contact values.
$o=wc_get_order(64);
if($o) echo wp_json_encode(['test_order'=>64,'unpaid'=>!$o->is_paid(),'needs_payment'=>$o->needs_payment(),'stock_reduced'=>(bool)$o->get_meta('_order_stock_reduced'),'gateway'=>$o->get_payment_method(),'actions'=>array_keys(apply_filters('woocommerce_order_actions',[],$o))])."\n";
