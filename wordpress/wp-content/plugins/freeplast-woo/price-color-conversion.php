<?php
/** Explicit, local-only conversion of private color prices; never runs on plugin load. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Pure plan: retain the complete row, and refuse a product if any stored values disagree. */
function fpw_price_color_conversion_plan( array $list, array $catalog ): array {
	$plan = array( 'payload' => $list, 'pending' => array(), 'conflicts' => array() );
	if ( ( $list['schema'] ?? 1 ) !== 1 || ! is_array( $list['prices'] ?? null ) || ( isset( $list['references'] ) && ! is_array( $list['references'] ) ) ) {
		$plan['conflicts']['row'] = 'Formato de lista no reconocido; no convertir.';
		return $plan;
	}
	foreach ( $catalog as $entry ) {
		if ( empty( $entry['color_only'] ) ) { continue; }
		$id = (int) $entry['product_id'];
		$parent = fpw_price_key( $id, 0 );
		$keys = array_map( static fn( $v ) => fpw_price_key( $id, (int) $v['variation_id'] ), $entry['variations'] );
		$legacy = array_intersect_key( ( $list['prices'] + ( $list['references'] ?? array() ) ), array_flip( $keys ) );
		if ( ! $legacy ) { continue; }
		$plan['pending'][] = $id;
		$candidate = $plan['payload'];
		foreach ( array( 'prices', 'references' ) as $field ) {
			$records = array_intersect_key( $list[ $field ] ?? array(), array_flip( array_merge( array( $parent ), $keys ) ) );
			$values = array();
			$invalid = false;
			foreach ( $records as $key => $value ) {
				if ( 'prices' === $field ) {
					$normalized = fpw_price_read_value( $value );
					$invalid = $invalid || null === $normalized || $normalized > 99999999;
				} else {
					$normalized = fpw_price_reference_normalize( $value );
					$invalid = $invalid || ! is_array( $value ) || ! $normalized;
					if ( is_array( $value ) ) {
						foreach ( $value as $name => $stored ) {
							if ( ! array_key_exists( $name, $normalized ) || $normalized[ $name ] !== $stored ) { $invalid = true; }
						}
					}
				}
				$values[] = $normalized;
			}
			$distinct = array_unique( array_map( 'serialize', $values ) );
			if ( $invalid || count( $distinct ) > 1 ) {
				$plan['conflicts'][ $id ][ $field ] = $records;
				continue;
			}
			if ( $records ) { $candidate[ $field ][ $parent ] = $records[ $parent ] ?? reset( $records ); }
			foreach ( $keys as $key ) { unset( $candidate[ $field ][ $key ] ); }
		}
		if ( ! isset( $plan['conflicts'][ $id ] ) ) { $plan['payload'] = $candidate; }
	}
	return $plan;
}

/** WP-CLI operator seam: exact private backup required; one byte-exact CAS, no automatic retries. */
function fpw_price_apply_color_conversion( string $backup_path ): array {
	global $wpdb;
	if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! function_exists( 'wp_get_environment_type' ) || 'local' !== wp_get_environment_type() || ! fpw_can_manage_data() ) {
		return array( 'state' => 'forbidden' );
	}
	$url = getenv( 'FREEPLAST_LOCAL_URL' );
	if ( ! is_string( $url ) || rtrim( home_url(), '/' ) !== $url || ! preg_match( '~\Ahttp://(?:mliu|localhost|127\.0\.0\.1):[1-9][0-9]{3,4}\z~D', $url ) ) {
		return array( 'state' => 'wrong_environment' );
	}
	$sql = $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", FPW_PRICE_ROW );
	$raw = $wpdb->get_var( $sql );
	if ( null === $raw ) { return array( 'state' => 'unchanged' ); }
	$list = is_string( $raw ) ? json_decode( $raw, true ) : null;
	if ( ! is_array( $list ) ) { return array( 'state' => 'invalid_row' ); }
	$plan = fpw_price_color_conversion_plan( $list, fpw_price_catalog() );
	if ( $plan['conflicts'] ) { return array( 'state' => 'conflict', 'conflicts' => $plan['conflicts'] ); }
	if ( ! $plan['pending'] ) { return array( 'state' => 'unchanged' ); }
	$path = realpath( $backup_path );
	$webroot = realpath( ABSPATH );
	if ( false === $path || ! is_file( $path ) || ! is_readable( $path ) || false === $webroot || str_starts_with( $path, rtrim( $webroot, '/' ) . '/' ) || ( fileperms( $path ) & 0077 ) !== 0 || file_get_contents( $path ) !== $raw ) {
		return array( 'state' => 'backup_required' );
	}
	$payload = $plan['payload'];
	$payload['updated_at'] = time();
	$payload['updated_by'] = fpw_price_actor();
	$json = wp_json_encode( $payload );
	if ( ! is_string( $json ) ) { return array( 'state' => 'invalid_row' ); }
	$result = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s", $json, FPW_PRICE_ROW, $raw ) );
	if ( false === $result ) { return array( 'state' => 'uncertain', 'message' => 'Inspeccionar la fila y el respaldo; no reintentar automáticamente.' ); }
	if ( 1 !== $result ) { return array( 'state' => 'stale' ); }
	if ( $wpdb->get_var( $sql ) !== $json ) { return array( 'state' => 'uncertain', 'message' => 'La verificación posterior no coincide; no reintentar automáticamente.' ); }
	return array( 'state' => 'applied', 'products' => $plan['pending'], 'backup_sha256' => hash( 'sha256', $raw ) );
}
