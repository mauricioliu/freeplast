<?php
/** Read-only WP-CLI fixture snapshot; never installed as a web endpoint.
 * Usage: wp eval-file scripts/woo-ventas-state.php ORDER_ID RUN_TOKEN
 * Only a run-owned synthetic request is accepted. Output contains no record
 * values/keys: a digest of the complete native data plus its line identifiers.
 */
function fpw_verification_value( $value ) {
	if ( $value instanceof DateTimeInterface ) { return $value->format( 'c' ); }
	if ( is_object( $value ) && method_exists( $value, 'get_data' ) ) { $value = $value->get_data(); }
	if ( is_object( $value ) ) { $value = get_object_vars( $value ); }
	if ( ! is_array( $value ) ) { return $value; }
	$out = array();
	foreach ( $value as $key => $entry ) {
		// Core editor presence changes these on reads. No other metadata is ignored.
		if ( is_object( $entry ) && method_exists( $entry, 'get_data' ) ) { $entry = $entry->get_data(); }
		if ( is_array( $entry ) && in_array( $entry['key'] ?? '', array( '_edit_lock', '_edit_last' ), true ) ) { continue; }
		$out[$key] = fpw_verification_value( $entry );
	}
	ksort( $out );
	return $out;
}
function fpw_verification_digest( $order, array $notes ): string {
	return hash( 'sha256', json_encode( fpw_verification_value( array( 'order' => $order->get_data(), 'notes' => $notes ) ), JSON_THROW_ON_ERROR ) );
}
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { return; }
$id = (int) ( $args[0] ?? 0 );
$run = (string) ( $args[1] ?? '' );
if ( ! preg_match( '/^[a-f0-9]{12,40}$/D', $run ) || $id < 1 ) { WP_CLI::error( 'Missing fixture provenance.' ); }
$order = wc_get_order( $id );
if ( ! $order || $order->get_billing_email() !== 'ventas-' . $run . '@example.invalid' ) { WP_CLI::error( 'Fixture identity mismatch.' ); }
$notes = array();
foreach ( get_comments( array( 'post_id' => $id, 'type' => 'order_note', 'status' => 'all', 'orderby' => 'comment_ID', 'order' => 'ASC' ) ) as $note ) {
	$notes[] = array( 'comment' => $note, 'meta' => get_comment_meta( $note->comment_ID ) );
}
echo json_encode( array( 'digest' => fpw_verification_digest( $order, $notes ), 'item_ids' => array_keys( $order->get_items() ) ), JSON_THROW_ON_ERROR );
