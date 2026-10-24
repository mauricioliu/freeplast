<?php
/** Revised offers: isolated working copies, immutable issued snapshots, one atomic publication. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Route context is request-scoped, never a global switch or a version inferred from POST. */
function fpw_quotation_revision_base( int $id ): int {
	$base = $_GET['revise'] ?? null;
	return FPW_DRAFT_SCREEN === ( $_GET['page'] ?? null ) && '1' === ( $_GET['workspace'] ?? null )
		&& (string) $id === (string) ( $_GET['request'] ?? '' ) && is_string( $base )
		&& preg_match( '/^[1-9][0-9]{0,8}$/D', $base ) ? (int) $base : 0;
}

function fpw_quotation_revision_suffix( int $id ): string {
	$base = fpw_quotation_revision_base( $id );
	return $base ? '_after_' . $base : '';
}

function fpw_quotation_edit_url( int $id, int $base ): string {
	return admin_url( 'admin.php?page=' . FPW_DRAFT_SCREEN . '&request=' . $id . '&workspace=1&revise=' . $base );
}

function fpw_quotation_view_url( int $id, int $number ): string {
	return admin_url( 'admin.php?page=' . FPW_DRAFT_SCREEN . '&request=' . $id . '&workspace=1&version=' . $number );
}

/** Only a known, sent current version can start another offer; unknown outcomes never invite a resend. */
function fpw_quotation_can_revise( int $id, ?array $version ): bool {
	return 1 === ( $version['schema'] ?? null ) && $id === ( $version['order_id'] ?? null )
		&& is_int( $version['version'] ?? null ) && $version['version'] > 0
		&& 'ready' === ( $version['document'] ?? null ) && 'accepted' === ( $version['delivery']['state'] ?? null );
}

function fpw_quotation_revision_is_current( int $id ): bool {
	$version = fpw_read_quotation_version( $id );
	return fpw_quotation_can_revise( $id, $version ) && fpw_quotation_revision_base( $id ) === $version['version'];
}

/** Revision arithmetic keeps the reviewed tax policy of its sent base, not today's configuration. */
function fpw_quotation_offer_tax_config( int $id ): array {
	$base = fpw_quotation_revision_base( $id );
	if ( ! $base ) { return fpw_quotation_tax_config(); }
	$p = fpw_read_quotation_version( $id, $base )['projection'] ?? array();
	$rate = $p['tax_rate_permille'] ?? null;
	$dispatch = $p['tax_applies_to_dispatch'] ?? null;
	return is_int( $rate ) && $rate >= 0 && $rate <= FPW_QUOTATION_MAX_RATE_PERMILLE && is_bool( $dispatch )
		? array( 'rate_permille' => $rate, 'applies_to_dispatch' => $dispatch ) : array();
}

/** Virtual revision-zero work: GET prefills from the sent projection, never writes or reads stale original work. */
function fpw_quotation_revision_seed( int $id ): ?array {
	$base = fpw_quotation_revision_base( $id );
	if ( ! $base ) { return null; }
	$version = fpw_read_quotation_version( $id, $base );
	if ( ! fpw_quotation_can_revise( $id, $version ) ) { return null; }
	$p = $version['projection'] ?? null;
	if ( ! is_array( $p ) || empty( $p['complete'] ) || empty( $p['lines'] ) ) { return null; }
	$draft = fpw_read_request_draft( $id );
	if ( count( $draft['items'] ?? array() ) !== count( $p['lines'] ) ) { return null; }
	$lines = array();
	foreach ( $p['lines'] as $i => $line ) {
		$item = $draft['items'][$i];
		$lines[] = array( 'index' => $i, 'product_id' => $item['product_id'], 'variation_id' => $item['variation_id'],
			'quantity' => $line['quantity'], 'price' => $line['price'], 'price_source' => 'manual' );
	}
	$pickup = '';
	foreach ( fpw_pickup_locations() as $key => $address ) { if ( $address === ( $p['pickup_address'] ?? null ) ) { $pickup = $key; } }
	return array( 'schema' => 2, 'order_id' => $id, 'revision' => 0, 'updated_at' => 0, 'updated_by' => 0,
		'lines' => $lines, 'destination' => $p['destination'], 'pickup_location' => $pickup,
		'dispatch_amount' => ! empty( $p['dispatch_requested'] ) ? $p['dispatch'] : null,
		'dispatch_conditions' => ! empty( $p['dispatch_requested'] ) ? array( 'destination' => $p['destination'], 'quantities' => array_column( $lines, 'quantity' ) ) : null,
		'validity_days' => $p['validity_days'] );
}

/** CAS publishes the new pending version AND the exact previous snapshot together.
 * History entries never contain history recursively. A crash cannot lose the original.
 * Only the CAS winner can generate/send; later revisions cannot start until delivery is accepted.
 */
function fpw_quotation_publish_revision( int $id, string $previous_raw, array $next ): bool {
	global $wpdb;
	$was_suppressed = $wpdb->suppress_errors();
	$result = $wpdb->query( $wpdb->prepare(
		"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
		wp_json_encode( $next ), fpw_quotation_row_name( $id ), $previous_raw
	) );
	$wpdb->suppress_errors( $was_suppressed );
	return is_numeric( $result ) && (int) $result > 0;
}

/** Navigable old versions keep their own protected PDF URL and exact stored bytes. */
function fpw_quotation_history_html( int $id, int $selected ): string {
	$current = fpw_read_quotation_version( $id );
	if ( empty( $current['history'] ) ) { return ''; }
	$versions = array_merge( $current['history'], array( array_diff_key( $current, array( 'history' => true ) ) ) );
	$html = '<nav class="fpw-version-history" aria-label="Versiones de la cotización">';
	foreach ( $versions as $version ) {
		$n = (int) $version['version'];
		$html .= '<a class="fpw-button" href="' . esc_url( add_query_arg( fpw_workspace_context_args(), fpw_quotation_view_url( $id, $n ) ) ) . '"' . ( $selected === $n ? ' aria-current="page"' : '' ) . '>Versión ' . $n . '</a>';
	}
	return $html . '</nav>';
}
