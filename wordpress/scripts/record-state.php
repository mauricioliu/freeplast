<?php
if (!defined('WP_CLI') || !WP_CLI || get_option('home') !== 'https://freeplast.mliu.site') { throw new RuntimeException('Wrong target'); }
if ('yes' === get_option('woocommerce_custom_orders_table_enabled')) { WP_CLI::error('Snapshot requires current CPT storage.'); }
global $wpdb;
$hash = hash_init('sha256');
foreach (array('posts'=>'ID', 'postmeta'=>'meta_id', 'comments'=>'comment_ID', 'commentmeta'=>'meta_id', 'woocommerce_order_items'=>'order_item_id', 'woocommerce_order_itemmeta'=>'meta_id') as $table=>$id) {
    $where = 'postmeta' === $table ? " WHERE meta_key NOT IN ('_edit_lock','_edit_last')" : '';
    $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}{$table}{$where} ORDER BY {$id}", ARRAY_A);
    if ($wpdb->last_error) { WP_CLI::error('Snapshot query failed.'); }
    hash_update($hash, $table . wp_json_encode($rows));
}
echo hash_final($hash) . "\n";
