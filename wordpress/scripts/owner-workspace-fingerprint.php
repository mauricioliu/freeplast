<?php
/** Read-only release fingerprint. No plugin APIs; usable with plugins/themes skipped. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || get_option( 'home' ) !== 'https://freeplast.mliu.site' ) { throw new RuntimeException( 'Wrong staging target.' ); }
global $wpdb;
$hash = hash_init( 'sha256' );
$tables = array(
	'posts' => 'ID', 'postmeta' => 'meta_id', 'comments' => 'comment_ID', 'commentmeta' => 'meta_id',
	'terms' => 'term_id', 'termmeta' => 'meta_id', 'term_taxonomy' => 'term_taxonomy_id',
	'term_relationships' => 'object_id,term_taxonomy_id', 'users' => 'ID', 'usermeta' => 'umeta_id',
	'woocommerce_order_items' => 'order_item_id', 'woocommerce_order_itemmeta' => 'meta_id',
	'wc_orders' => 'id', 'wc_orders_meta' => 'id', 'wc_order_addresses' => 'id', 'wc_order_operational_data' => 'id',
);
foreach ( $tables as $suffix => $key ) {
	$table = $wpdb->prefix . $suffix;
	$present = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	if ( $wpdb->last_error ) { throw new RuntimeException( 'Unable to inspect protected table.' ); }
	if ( ! $present ) { hash_update( $hash, $suffix . ':absent\n' ); continue; }
	hash_update( $hash, $suffix . "\n" );
	for ( $offset = 0; ; $offset += 500 ) {
		// Identifiers are exclusively the fixed allowlist above and WP's configured prefix.
		$rows = $wpdb->get_results( "SELECT * FROM `$table` ORDER BY $key LIMIT 500 OFFSET " . (int) $offset, ARRAY_A );
		if ( $wpdb->last_error ) { throw new RuntimeException( 'Unable to read protected records.' ); }
		foreach ( $rows as $row ) { hash_update( $hash, wp_json_encode( $row ) . "\n" ); }
		if ( count( $rows ) < 500 ) { break; }
	}
}
// Include all commercial rows (including frozen PDFs and tracking), Woo settings and role policy.
$rows = $wpdb->get_results( $wpdb->prepare(
	"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name IN ('home','siteurl','blog_public','" . $wpdb->prefix . "user_roles') ORDER BY option_name",
	$wpdb->esc_like( 'fpw_' ) . '%', $wpdb->esc_like( 'woocommerce_' ) . '%', $wpdb->esc_like( 'qwc_' ) . '%'
), ARRAY_A );
if ( $wpdb->last_error ) { throw new RuntimeException( 'Unable to read protected commercial settings.' ); }
foreach ( $rows as $row ) { hash_update( $hash, wp_json_encode( $row ) . "\n" ); }
echo hash_final( $hash ) . "\n";
