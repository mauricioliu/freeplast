<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

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
	$scope = array_intersect( $place['types'], array( 'street_address', 'premise', 'subpremise', 'establishment', 'point_of_interest' ) ) ? 'exacta' : 'amplia';
	return $confirmed[$id] = array( 'address' => $address, 'place_id' => $id, 'scope' => $scope );
}

/** Canonicalize before Woo validation and creation; hidden address/scope are never authority. */
function fpw_canonical_checkout_address( array $data ): array {
	if ( 'si' !== ( $data['billing_fp_dispatch'] ?? '' ) ) {
		foreach ( array( 'billing_fp_address', 'billing_fp_references', 'fpw_place_id', 'fpw_place_scope' ) as $key ) { $data[$key] = ''; }
		return $data;
	}
	$place = fpw_confirm_place( $data['fpw_place_id'] ?? '' );
	$data['billing_fp_address'] = $place['address'] ?? '';
	$data['fpw_place_scope'] = $place['scope'] ?? '';
	return $data;
}
add_filter( 'woocommerce_checkout_posted_data', 'fpw_canonical_checkout_address', 20 );
