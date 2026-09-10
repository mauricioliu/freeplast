<?php
/**
 * Consulta de distancia de despacho desde el borrador privado — issue #60, corte 11 de #49.
 *
 * The owner consults or recalculates the ORIENTATIVE road distance from the
 * documented Warehouse to the draft's working destination, from an authorized
 * private action on the draft screen. The consultation is one bounded
 * Routes API `computeRoutes` call per explicit owner action — one origin, one
 * destination, no Geocoding/Address Validation dependency, no distance matrix,
 * no embedded map — simulated at the transport layer in tests.
 *
 * What it is NOT: never a certification of truck access, never a delivery-time
 * promise, never a Carrier Charge, never a dispatch price. The result is the
 * CURRENT consultation only: nothing — no kilometers, no durations, no
 * coordinates, no provider payload — is persisted in any durable row, log,
 * email or backup. Every consultation recalculates from the permitted
 * destination identification, and the owner's manual dispatch amount keeps
 * being the only commercial value (a saved amount stays exactly as saved).
 *
 * Configuration follows the ADR-0007 seam pattern: `fpw_dispatch_distance_config()`
 * delivers the PRIVATE server credential through its own filter, absent by
 * default — without it the action answers honestly and contacts nobody. The
 * destination served is the draft's working destination (saved work over the
 * recorded address), sent as the recorded Place ID ONLY while the working text
 * still matches the recorded address — editing the destination invalidates the
 * association — and as the typed address otherwise. A broad recorded match is
 * announced as needing review; a missing route, an invalid response, a
 * timeout, a quota stop or a provider failure are named as what they are,
 * never presented as an exact distance nor a zero amount.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'FPW_DISTANCE_ORIGIN', 'Camino El Arrayán 52, San Francisco de Mostazal' );
define( 'FPW_DISTANCE_TIMEOUT', 10 );
define( 'FPW_DISTANCE_MAX_METERS', 10000000 );
define( 'FPW_DISTANCE_ENDPOINT', 'https://routes.googleapis.com/directions/v2:computeRoutes' );

/**
 * The Routes configuration seam (issue #60): the consultation exists ONLY with
 * a deliberate configuration delivered through this filter — a private,
 * restricted SERVER credential with quotas and terms arranged separately from
 * the code (external prerequisite still pending). Absent configuration means
 * the action answers honestly and never contacts the provider; the owner's
 * manual dispatch price path serves alone. The key is used server-side only
 * and is never rendered, localized or emailed.
 *
 * @return array{key:string, origin:string, region:string, timeout:int}
 */
function fpw_dispatch_distance_config(): array {
	$config = apply_filters( 'fpw_dispatch_distance_config', array() );
	if ( ! is_array( $config ) || ! isset( $config['key'] ) || ! is_string( $config['key'] ) || '' === trim( $config['key'] ) ) { return array(); }
	return array(
		'key'     => trim( $config['key'] ),
		'origin'  => isset( $config['origin'] ) && is_string( $config['origin'] ) && '' !== trim( $config['origin'] ) ? trim( $config['origin'] ) : FPW_DISTANCE_ORIGIN,
		'region'  => isset( $config['region'] ) && is_string( $config['region'] ) ? strtoupper( trim( $config['region'] ) ) : 'CL',
		'timeout' => isset( $config['timeout'] ) ? max( 1, min( 30, (int) $config['timeout'] ) ) : FPW_DISTANCE_TIMEOUT,
	);
}

/** The nonce action of one draft's distance consultation: scoped to its request. */
function fpw_distance_nonce_action( int $order_id ): string {
	return 'fpw-draft-distance-' . $order_id;
}

/**
 * The permitted destination identification for one consultation: the working
 * address text — the owner's saved work destination over the receipt
 * snapshot's recorded address, the same precedence the editing form renders
 * (a saved blank stays blank) — plus the recorded Place ID ONLY while the
 * working text still matches the recorded address. Editing the destination
 * invalidates the previous association (a stale Place ID must never route a
 * different journey); a malformed recorded identification is never sent; a
 * manual or unknown provenance is plainly typed text.
 *
 * @return ?array{address:string, place_id:string, precision:'exacta'|'amplia'|'escrita'} Null only for a no-dispatch draft; a dispatch draft always resolves the shape, with an empty address when nothing usable is saved.
 */
function fpw_distance_destination_for( array $draft, ?array $work ): ?array {
	if ( ! fpw_draft_requests_dispatch( $draft ) ) { return null; }
	$record  = is_array( $draft['destination'] ?? null ) ? $draft['destination'] : array();
	$address = (string) ( $record['address'] ?? '' );
	if ( is_array( $work ) ) { $address = (string) ( $work['destination'] ?? $address ); }
	$address = trim( $address );
	$place_id  = '';
	$precision = 'escrita';
	$record_address = trim( (string) ( $record['address'] ?? '' ) );
	if ( 'asistida' === (string) ( $record['source'] ?? '' ) && $address === $record_address ) {
		$candidate = (string) ( $record['place_id'] ?? '' );
		if ( fpw_is_place_id( $candidate ) ) {
			$place_id = $candidate;
			$precision = 'amplia' === (string) ( $record['scope'] ?? '' ) ? 'amplia' : 'exacta';
		}
	}
	return array( 'address' => $address, 'place_id' => $place_id, 'precision' => $precision );
}

/** One route waypoint identified by a free-text address line. */
function fpw_distance_address_waypoint( string $address ): array {
	return array( 'address' => array( 'addressLines' => array( $address ) ) );
}

/**
 * Classify one provider response with honest precision. Only a well-formed
 * single route with a positive, bounded integer distance is a usable
 * reference; anything else — no route, malformed shape, zero or absurd
 * meters, quota stop, rejected credential, provider or transport failure,
 * timeout — is the explicit state it is, never a distance and never zero.
 *
 * @return array{state:'ok'|'sin_ruta'|'invalida'|'cuota'|'no_autorizada'|'timeout'|'fallo', distance_meters?:int}
 */
function fpw_distance_classify_response( $response ): array {
	if ( is_wp_error( $response ) ) {
		$code    = (string) $response->get_error_code();
		$message = strtolower( (string) $response->get_error_message() );
		return ( 'http_request_timeout' === $code || str_contains( $message, 'timed out' ) || str_contains( $message, 'timeout' ) )
			? array( 'state' => 'timeout' )
			: array( 'state' => 'fallo' );
	}
	$status = (int) wp_remote_retrieve_response_code( $response );
	if ( 429 === $status ) { return array( 'state' => 'cuota' ); }
	if ( 401 === $status || 403 === $status ) { return array( 'state' => 'no_autorizada' ); }
	if ( 200 !== $status ) { return array( 'state' => 'fallo' ); }
	$payload = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $payload ) ) { return array( 'state' => 'invalida' ); }
	$routes = $payload['routes'] ?? null;
	if ( ! is_array( $routes ) ) { return array( 'state' => 'invalida' ); }
	if ( 0 === count( $routes ) ) { return array( 'state' => 'sin_ruta' ); }
	if ( 1 !== count( $routes ) || ! is_array( $routes[0] ) ) { return array( 'state' => 'invalida' ); }
	$meters = $routes[0]['distanceMeters'] ?? null;
	if ( ! is_int( $meters ) || $meters < 1 || $meters > FPW_DISTANCE_MAX_METERS ) { return array( 'state' => 'invalida' ); }
	return array( 'state' => 'ok', 'distance_meters' => $meters );
}

/**
 * Run one distance consultation for a draft. No durable write anywhere: the
 * answer describes THIS consultation only.
 *
 * @return array{state:string, origin?:string, destination?:array{address:string,place_id:string,precision:string}, distance_meters?:int} Origin and destination appear only in the states that reached them; the distance only in 'ok'.
 */
function fpw_distance_consult( array $draft, ?array $work ): array {
	if ( ! fpw_draft_requests_dispatch( $draft ) ) {
		return array( 'state' => 'sin_despacho' );
	}
	$config = fpw_dispatch_distance_config();
	if ( empty( $config ) ) { return array( 'state' => 'sin_configuracion' ); }
	$destination = fpw_distance_destination_for( $draft, $work );
	if ( null === $destination || '' === $destination['address'] ) { return array( 'state' => 'sin_destino', 'origin' => $config['origin'] ); }
	$body = array(
		'origin'            => fpw_distance_address_waypoint( $config['origin'] ),
		'destination'       => '' !== $destination['place_id']
			? array( 'placeId' => $destination['place_id'] )
			: fpw_distance_address_waypoint( $destination['address'] ),
		'travelMode'        => 'DRIVE',
		'routingPreference' => 'TRAFFIC_UNAWARE',
		'languageCode'      => 'es',
		'regionCode'        => $config['region'],
	);
	$response = wp_remote_post( FPW_DISTANCE_ENDPOINT, array(
		'timeout' => $config['timeout'],
		'headers' => array(
			'Content-Type'     => 'application/json',
			'X-Goog-Api-Key'   => $config['key'],
			'X-Goog-FieldMask' => 'routes.distanceMeters',
		),
		'body'    => (string) wp_json_encode( $body ),
	) );
	$class = fpw_distance_classify_response( $response );
	$result = array( 'state' => $class['state'], 'origin' => $config['origin'], 'destination' => $destination );
	if ( 'ok' === $class['state'] ) { $result['distance_meters'] = $class['distance_meters']; }
	return $result;
}

/**
 * Request-scoped stash for the consultation outcome: admin_init runs the
 * action BEFORE wp-admin renders (a CSRF refusal is a real 403); the screen
 * callback reads the outcome back to render it. Calling it with null clears
 * the stash (a fresh request starts with none); calling it with an array sets
 * it; calling it with no argument reads it. Direct invocation without the
 * admin_init pass (offline tests) is supported by the same fallback the save
 * action uses.
 */
function fpw_pending_distance_consult( $set = false ): ?array {
	static $pending = null;
	if ( false === $set ) { return $pending; }   // read
	$pending = is_array( $set ) ? $set : null;   // explicit set/clear
	return $pending;
}

/** The action's server-side half: CSRF, then the read-only consultation; the outcome is stashed for the screen. */
function fpw_handle_distance_consult_request( $order, array $draft ): void {
	$order_id = (int) $order->get_id();
	if ( ! wp_verify_nonce( (string) ( $_POST['fpw_distance_nonce'] ?? '' ), fpw_distance_nonce_action( $order_id ) ) ) {
		wp_die( 'Tu sesión expiró o el formulario no es válido: vuelve a cargar el borrador e inténtalo de nuevo.', '', array( 'response' => 403 ) );
	}
	fpw_pending_distance_consult( array(
		'order_id' => $order_id,
		'result'   => fpw_distance_consult( $draft, fpw_read_draft_work( $order_id ) ),
	) );
}

/**
 * The consultation front door, ahead of wp-admin's own header render:
 * authorization first (the capability — never the nonce — grants access),
 * then CSRF, then the bounded consultation. Requests without a resolved draft
 * consult nothing.
 */
function fpw_handle_distance_consult(): void {
	fpw_pending_distance_consult( null );   // a fresh request starts with no outcome
	if ( FPW_DRAFT_SCREEN !== (string) ( $_GET['page'] ?? '' ) || empty( $_POST['fpw_distance_consult'] ) ) { return; }
	if ( ! current_user_can( 'manage_woocommerce' ) ) { fpw_die_draft_forbidden(); }
	$order = fpw_draft_screen_order();
	$draft = $order ? fpw_read_request_draft( (int) $order->get_id() ) : null;
	if ( ! $draft ) { return; }
	fpw_handle_distance_consult_request( $order, $draft );
}
add_action( 'admin_init', 'fpw_handle_distance_consult' );

/** The human state line of one consultation outcome, in the screen's language. */
function fpw_distance_state_html( array $result ): string {
	switch ( $result['state'] ?? '' ) {
		case 'ok':
			$meters = (int) ( $result['distance_meters'] ?? 0 );
			$km     = number_format( round( $meters / 100 ) / 10, 1, ',', '.' );
			return '<strong>' . esc_html( $km ) . ' km</strong> de referencia (' . esc_html( number_format( $meters, 0, ',', '.' ) ) . ' m de ruta de conducción).';
		case 'sin_ruta':
			return 'El proveedor no devolvió ninguna ruta para este destino: no se inventa ninguna distancia ni un monto en cero.';
		case 'invalida':
			return 'La respuesta del proveedor no fue utilizable: no se muestra ninguna distancia ni un monto en cero.';
		case 'cuota':
			return 'La consulta alcanzó la cuota configurada del proveedor: espera e inténtalo más tarde.';
		case 'no_autorizada':
			return 'La credencial del servidor fue rechazada por el proveedor: revisa la configuración privada.';
		case 'timeout':
			return 'La consulta excedió el tiempo límite y se abortó: nada cambió.';
		case 'fallo':
			return 'La consulta falló (proveedor o red): nada cambió. Puedes reintentar o fijar el monto manualmente.';
		case 'sin_configuracion':
			return 'La consulta de distancia no está configurada en este sitio (falta la credencial privada de servidor): el monto de despacho se define manualmente.';
		case 'sin_destino':
			return 'Este borrador no tiene un destino de trabajo utilizable: completa el destino y vuelve a consultar.';
		case 'sin_despacho':
			return 'La solicitud no pide despacho: no hay consulta de distancia.';
	}
	return '';
}

/** The precision note of a consulted destination: how the route endpoint was identified. */
function fpw_distance_precision_note( string $precision ): string {
	switch ( $precision ) {
		case 'exacta':
			return 'Destino identificado con el asistente (coincidencia exacta).';
		case 'amplia':
			return 'Coincidencia amplia del destino: revisa número y comuna antes de confiar en esta referencia.';
	}
	return 'Destino escrito a mano: la ruta se calculó sobre el texto guardado.';
}

/** The shared disclaimer: a driving reference, never certification, never a promise, never stored. */
function fpw_distance_disclaimer_html(): string {
	return 'Referencia de conducción para la operación en vehículo menor: no certifica el acceso de un camión ni promete tiempos de entrega, y no reemplaza el cobro del transportista. La distancia no se guarda en ningún registro: cada consulta se calcula de nuevo desde el destino guardado, y el monto de despacho sigue siendo tu ingreso manual.';
}

/** One consultation result rendered under the state row, with the rule's suggestion for a usable distance (issue #61) and the consulted destination when it no longer matches the current one. */
function fpw_distance_result_html( array $result, string $current_destination ): string {
	$state = fpw_distance_state_html( $result );
	if ( '' === $state ) { return ''; }
	$html = '<p>' . $state . '</p>';
	if ( 'ok' === ( $result['state'] ?? '' ) && is_int( $result['distance_meters'] ?? null ) ) {
		// Issue #61: the maintained rule turns THIS consultation's distance into
		// a suggestion for the owner's decision — never a stored, chosen or
		// buyer-facing amount.
		$html .= fpw_rule_suggestion_html( (int) $result['distance_meters'] );
	}
	if ( is_array( $result['destination'] ?? null ) ) {
		$html .= '<p>' . esc_html( fpw_distance_precision_note( (string) ( $result['destination']['precision'] ?? 'escrita' ) ) ) . '</p>';
		$consulted = (string) ( $result['destination']['address'] ?? '' );
		if ( '' !== $consulted && $consulted !== $current_destination ) {
			$html .= '<p>Atención: la consulta se hizo para el destino «' . esc_html( $consulted ) . '»; después de ella el destino cambió, vuelve a consultar.</p>';
		}
	}
	return $html;
}

/**
 * The draft's distance-consultation section (issue #60): rendered ONLY for
 * drafts that ask for dispatch — «Sin despacho» never renders the action and
 * never triggers a route call. Without configuration the section names the
 * honest state and keeps the manual path; with configuration it shows origin,
 * working destination (and how it is identified), the state of the CURRENT
 * consultation, and the bounded recalculation action.
 *
 * @param array      $draft The stored receipt snapshot.
 * @param array|null $work  The owner's saved work, when any exists.
 */
function fpw_draft_distance_section_html( array $draft, ?array $work ): string {
	$config = fpw_dispatch_distance_config();
	$disclaimer = '<p class="fpw-draft__aside-note">' . esc_html( fpw_distance_disclaimer_html() ) . '</p>';
	if ( empty( $config ) ) {
		return '<section><h2>Distancia de despacho (referencia)</h2>'
			. fpw_distance_result_html( array( 'state' => 'sin_configuracion' ), '' )
			. $disclaimer . '</section>';
	}

	$order_id = (int) ( $draft['order_id'] ?? 0 );
	$consult  = fpw_pending_distance_consult();
	if ( is_array( $consult ) && (int) ( $consult['order_id'] ?? 0 ) !== $order_id ) { $consult = null; }
	$destination = fpw_distance_destination_for( $draft, $work );

	$destination_text = '';
	$destination_note = '';
	if ( is_array( $destination ) ) {
		$destination_text = (string) $destination['address'];
		if ( '' !== $destination['place_id'] ) {
			$destination_note = 'exacta' === $destination['precision']
				? ' — con Place ID del asistente (coincidencia exacta)'
				: ' — con Place ID del asistente (coincidencia amplia: revisar número y comuna)';
		} else {
			$destination_note = ' — texto escrito';
		}
	}

	$state_line = is_array( $consult )
		? fpw_distance_result_html( $consult['result'] ?? array(), $destination_text )
		: '<p>Sin consulta en esta pantalla: la distancia no se guarda; cada consulta se calcula de nuevo.</p>';

	$form = '<form method="post" action="' . esc_url( fpw_draft_screen_url( $order_id ) ) . '">'
		. '<input type="hidden" name="fpw_distance_consult" value="1" />'
		. wp_nonce_field( fpw_distance_nonce_action( $order_id ), 'fpw_distance_nonce', true, false )
		. '<button type="submit">Consultar distancia (referencia)</button>'
		. '<span class="fpw-draft__origin">Una llamada acotada por consulta; usa el destino guardado del borrador.</span></form>';

	return '<section><h2>Distancia de despacho (referencia)</h2><dl>'
		. '<div class="fpw-draft__fact"><dt>Origen (bodega)</dt><dd>' . esc_html( $config['origin'] ) . '</dd></div>'
		. '<div class="fpw-draft__fact"><dt>Destino de trabajo</dt><dd>' . ( '' !== $destination_text ? esc_html( $destination_text ) : 'Sin destino de trabajo guardado' ) . esc_html( $destination_note ) . '</dd></div>'
		. '<div class="fpw-draft__fact"><dt>Estado de la consulta</dt><dd>' . $state_line . '</dd></div>'
		. '</dl>' . $form . $disclaimer . '</section>';
}
