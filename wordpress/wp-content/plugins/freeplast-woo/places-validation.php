<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Local capture suppresses Google loading; manual entry is valid in every environment. */
function fpw_manual_address_allowed(): bool {
	return defined('FPW_LOCAL_MANUAL_ADDRESS') && true === FPW_LOCAL_MANUAL_ADDRESS
		&& defined('WP_ENVIRONMENT_TYPE') && 'local' === WP_ENVIRONMENT_TYPE
		&& defined('WP_HOME') && WP_HOME === getenv('FREEPLAST_LOCAL_URL')
		&& 1 === preg_match('~\Ahttp://(?:mliu|localhost|127\.0\.0\.1):[1-9][0-9]{3,4}\z~D', WP_HOME)
		&& function_exists('fpw_local_capture_active') && fpw_local_capture_active();
}

/** Submission-only Places confirmation. No browser key or customer notes cross this seam. */
function fpw_confirm_place( $id ): array {
	static $confirmed = array(); // Request-local: never reuse a confirmation across submissions.
	if ( ! is_string( $id ) || ! fpw_is_place_id( $id ) ) { return array(); }
	if ( isset( $confirmed[$id] ) ) { return $confirmed[$id]; }
	$key = defined( 'FREEPLAST_GOOGLE_PLACES_SERVER_KEY' ) ? FREEPLAST_GOOGLE_PLACES_SERVER_KEY : '';
	if ( ! is_string( $key ) || '' === trim( $key ) ) { return array(); }
	$confirmed[$id] = array();
	$response = wp_remote_get( 'https://places.googleapis.com/v1/places/' . rawurlencode( $id ), array(
		'timeout' => 8, 'redirection' => 0, 'limit_response_size' => 32768,
		'headers' => array( 'X-Goog-Api-Key' => $key, 'X-Goog-FieldMask' => 'id,formattedAddress,addressComponents,types' ),
	) );
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) { return array(); }
	$place = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $place ) || ( $place['id'] ?? null ) !== $id || ! is_string( $place['formattedAddress'] ?? null ) || ! is_array( $place['addressComponents'] ?? null ) || ! is_array( $place['types'] ?? null ) ) { return array(); }
	$address = trim( $place['formattedAddress'] );
	if ( '' === $address || strlen( $address ) > 800 || preg_match( '/[<>\x00-\x1F\x7F]/', $address ) || sanitize_textarea_field( $address ) !== $address ) { return array(); }
	$chile = false;
	foreach ( $place['addressComponents'] as $component ) {
		if ( is_array( $component ) && is_array( $component['types'] ?? null ) && in_array( 'country', $component['types'], true ) ) { $chile = 'CL' === ( $component['shortText'] ?? '' ); break; }
	}
	if ( ! $chile || ! $place['types'] || count( array_filter( $place['types'], 'is_string' ) ) !== count( $place['types'] ) ) { return array(); }
	// Chilean comuna comes only from Google's administrative component, never
	// from a posted hidden field or a guess based on the formatted address.
	$commune = '';
	foreach ( $place['addressComponents'] as $component ) {
		if ( ! is_array( $component ) || ! is_array( $component['types'] ?? null ) || ! in_array( 'administrative_area_level_3', $component['types'], true ) ) { continue; }
		$text = $component['longText'] ?? '';
		if ( is_string( $text ) && '' !== trim( $text ) && strlen( $text ) <= 240 && ! preg_match( '/[<>\x00-\x1F\x7F]/', $text ) && sanitize_textarea_field( $text ) === $text ) { $commune = $text; }
		break;
	}
	$scope = array_intersect( $place['types'], array( 'street_address', 'premise', 'subpremise', 'establishment', 'point_of_interest' ) ) ? 'exacta' : 'amplia';
	return $confirmed[$id] = array( 'address' => $address, 'place_id' => $id, 'scope' => $scope, 'commune' => $commune );
}

/** Canonicalize before Woo validation and creation; hidden address/scope are never authority. */
function fpw_canonical_checkout_address( array $data ): array {
	if ( 'si' !== ( $data['billing_fp_dispatch'] ?? '' ) ) {
		foreach ( array( 'billing_fp_address', 'billing_fp_commune', 'billing_fp_location_url', 'billing_fp_references', 'fpw_place_id', 'fpw_place_scope' ) as $key ) { $data[$key] = ''; }
		return $data;
	}
	// Removed public fields cannot re-enter new records through stale forms,
	// saved drafts or forged POSTs. Historical records are not rewritten.
	$data['billing_fp_commune'] = $data['billing_fp_location_url'] = '';
	foreach ( array( 'billing_fp_address' => 800 ) as $key => $limit ) {
		$value = $data[$key] ?? '';
		$value = is_string( $value ) ? trim( $value ) : '';
		$data[$key] = strlen( $value ) <= $limit && ! preg_match( '/[<>\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value ) && sanitize_textarea_field( $value ) === $value ? $value : '';
	}
	$place = fpw_manual_address_allowed() ? array() : fpw_confirm_place( $data['fpw_place_id'] ?? '' );
	// An edited address must never inherit the previous Google association.
	if ( ! $place || $data['billing_fp_address'] !== $place['address'] ) {
		$data['fpw_place_id'] = $data['fpw_place_scope'] = '';
	} else {
		$data['fpw_place_scope'] = $place['scope'];
		$data['billing_fp_commune'] = $place['commune'];
	}
	return $data;
}
add_filter( 'woocommerce_checkout_posted_data', 'fpw_canonical_checkout_address', 20 );

/** Store text only: never fetch, resolve or calculate distance from this URL. */
function fpw_valid_location_url( $value ): bool {
	if ( ! is_string( $value ) || strlen( $value ) > 2048 || preg_match( '/[<>\s\x00-\x1F\x7F]/', $value ) ) { return false; }
	if ( '' === $value ) { return true; }
	$parts = parse_url( $value );
	return false !== filter_var( $value, FILTER_VALIDATE_URL ) && is_array( $parts )
		&& in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true )
		&& ! isset( $parts['user'] ) && ! isset( $parts['pass'] );
}
