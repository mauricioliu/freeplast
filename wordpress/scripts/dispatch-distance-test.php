<?php
/** Issue #60 — corte 11 de #49: consultar distancia de despacho desde el borrador privado.
 * Offline server contract: the Routes configuration seam (absent by default — the private
 * server credential is a separate authorization), the working-destination resolution (typed
 * address and/or the recorded Place ID while the text still matches; editing invalidates the
 * association), one bounded computeRoutes call per explicit owner action simulated AT THE
 * TRANSPORT, the honest state machine (broad match, missing route, invalid response, timeout,
 * quota and provider failure are never an exact distance nor a zero amount), the permission
 * and CSRF boundaries on the private action, and the persistence inspection: kilometers,
 * durations, coordinates and provider payloads are never stored — the result is the current
 * consultation only, and the manual dispatch price path always survives. */
define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
$registered_actions = array();
$registered_filters = array();
function add_action( ...$args ) { global $registered_actions; $registered_actions[ $args[0] ][] = $args[1] ?? null; }
function add_filter( ...$args ) { global $registered_filters; $registered_filters[ $args[0] ][] = $args[1]; }
function register_activation_hook( ...$args ) {}
function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags ); }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function apply_filters( $tag, $value, ...$args ) { global $registered_filters; foreach ( $registered_filters[ $tag ] ?? array() as $callback ) { $value = $callback( $value, ...$args ); } return $value; }
class WP_Error {
	public function __construct( private string $code = '', private string $message = '' ) {}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
/* The Google simulation seam: pre_http_request in the real stack, this probe here — the
 * transport layer is what varies, never the code under test. */
$GLOBALS['fpwd_http'] = array( 'calls' => array(), 'script' => array() );
function wp_remote_post( $url, $args = array() ) {
	$GLOBALS['fpwd_http']['calls'][] = array( 'url' => $url, 'args' => $args );
	$next = array_shift( $GLOBALS['fpwd_http']['script'] );
	if ( null !== $next ) { return $next; }
	return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => wp_json_encode( array( 'routes' => array( array( 'distanceMeters' => 61200 ) ) ) ) );
}
function wp_remote_retrieve_response_code( $response ) { return is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_body( $response ) { return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : ''; }
class FPWDD_Die extends RuntimeException {}
function wp_die( $message = '', $title = '', $args = array() ) { throw new FPWDD_Die( (string) ( $args['response'] ?? 0 ) ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_url( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_textarea( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function get_current_user_id(): int { return 1; }
$GLOBALS['fpwd_nonce_ok'] = true;
function wp_create_nonce( $action = -1 ) { return 'offline-nonce'; }
function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ) {
	$html = '<input type="hidden" name="' . esc_attr( (string) $name ) . '" value="offline-nonce" />';
	if ( $echo ) { echo $html; }
	return $html;
}
function wp_verify_nonce( $nonce, $action = -1 ) { return $GLOBALS['fpwd_nonce_ok'] && 'offline-nonce' === (string) $nonce ? 1 : false; }
function admin_url( $path = '' ) { return 'https://freeplast.test/wp-admin/' . $path; }
function date_i18n( $format, $timestamp ) { return gmdate( 'Y-m-d', (int) $timestamp ); }
function wp_unslash( $value ) { return $value; }
function do_action( ...$args ): void {}
$GLOBALS['fpwd_options'] = array( 'date_format' => 'j F Y' );
function get_option( $name, $default = false ) { return $GLOBALS['fpwd_options'][ $name ] ?? $default; }
$GLOBALS['fpwd_caps'] = array();
function current_user_can( string $cap ): bool { return ! empty( $GLOBALS['fpwd_caps'][ $cap ] ); }
function add_submenu_page( $parent, $page_title, $menu_title, $capability, $slug, $callback ) { return $slug; }
$GLOBALS['fpwd_attr_labels'] = array();
function wc_attribute_label( $name, $product = '' ) { return $GLOBALS['fpwd_attr_labels'][ $name ] ?? $name; }

/* Fake wpdb with the unique option_name semantics of the real one. */
class FPWDD_Fake_wpdb {
	public string $prefix = 'fp_';
	public string $options = 'wp_options';
	public function suppress_errors( $set = null ) { return false; }
	public function esc_like( $text ) { return addcslashes( $text, '_%\\' ); }
	public function prepare( $sql, ...$args ) {
		foreach ( $args as $arg ) { $pos = strpos( $sql, '%s' ); $sql = substr( $sql, 0, $pos ) . "'" . $arg . "'" . substr( $sql, $pos + 2 ); }
		return $sql;
	}
	public function query( $sql ) {
		if ( preg_match( "/INSERT INTO \{?\w*options\}? \( option_name, option_value, autoload \) VALUES \( '(.+?)', '(.*)', 'off' \)$/s", $sql, $m ) ) {
			if ( array_key_exists( $m[1], $GLOBALS['fpwd_table'] ) ) { return false; }
			$GLOBALS['fpwd_table'][ $m[1] ] = $m[2]; return 1;
		}
		if ( preg_match( "/^UPDATE \{?\w*options\}? SET option_value = '(.*)' WHERE option_name = '(.+?)' AND option_value = '(.*)'$/s", $sql, $m ) ) {
			if ( ! array_key_exists( $m[2], $GLOBALS['fpwd_table'] ) || $GLOBALS['fpwd_table'][ $m[2] ] !== $m[3] ) { return 0; }
			$GLOBALS['fpwd_table'][ $m[2] ] = $m[1]; return 1;
		}
		return 0;
	}
	public function get_var( $sql ) {
		if ( preg_match( "/SELECT option_value FROM \{?\w*options\}? WHERE option_name = '(.+)'$/", $sql, $m ) ) {
			return $GLOBALS['fpwd_table'][ $m[1] ] ?? null;
		}
		return null;
	}
	public function get_col( $sql ) {
		if ( preg_match( "/SELECT option_name FROM \{?\w*options\}? WHERE option_name LIKE '(.+)'$/", $sql, $m ) ) {
			$like = str_replace( array( '\\%', '\\_' ), array( '%', '_' ), $m[1] );
			$prefix = ( $pos = strpos( $like, '%' ) ) !== false ? substr( $like, 0, $pos ) : $like;
			$out = array();
			foreach ( array_keys( $GLOBALS['fpwd_table'] ) as $name ) { if ( str_starts_with( $name, $prefix ) ) { $out[] = $name; } }
			return $out;
		}
		return array();
	}
}
$GLOBALS['fpwd_table'] = array();
$GLOBALS['wpdb'] = new FPWDD_Fake_wpdb();

class FPWDD_ItemMeta {
	public function __construct( private string $key, private string $value ) {}
	public function get_data(): array { return array( 'key' => $this->key, 'value' => $this->value ); }
}
class FPWDD_Item {
	public function __construct( private string $name, private int $quantity, private int $productId, private int $variationId = 0 ) {}
	public function get_name(): string { return $this->name; }
	public function get_quantity(): int { return $this->quantity; }
	public function get_product_id(): int { return $this->productId; }
	public function get_variation_id(): int { return $this->variationId; }
	public function get_meta_data(): array { return array(); }
}
class FPWDD_Order {
	public array $meta;
	public array $items;
	public function __construct( public int $id, array $items, array $meta = array() ) {
		$this->meta = array_merge( array(
			'_fp_request' => 'yes',
			'_fpw_attempt' => str_repeat( 'ab', 32 ),
			'_fp_submitted_details' => array( 'billing_first_name' => 'Pilar', 'billing_fp_dispatch' => 'si' ),
			'_billing_fp_rut' => '76.543.210-K',
			'_billing_fp_dispatch' => 'si',
			'_billing_fp_address' => 'Camino de prueba 123, Mostazal',
		), $meta );
		$this->items = $items;
	}
	public function get_id(): int { return $this->id; }
	public function get_order_number(): string { return 'FP-2026-' . sprintf( '%06d', $this->id ); }
	public function get_meta( string $key ): mixed { return $this->meta[ $key ] ?? ''; }
	public function get_items(): array { return $this->items; }
	public function get_billing_first_name(): string { return 'Pilar'; }
	public function get_billing_company(): string { return 'Agrícola de prueba SpA'; }
	public function get_billing_phone(): string { return '+56 9 1234 5678'; }
	public function get_billing_email(): string { return 'compras@prueba.invalid'; }
	public function get_date_created(): DateTimeImmutable { return new DateTimeImmutable( '2026-09-11 12:00:00' ); }
}
$GLOBALS['fpwd_orders'] = array();
function wc_get_order( $id ) { return $GLOBALS['fpwd_orders'][ (int) $id ] ?? null; }

require __DIR__ . '/../wp-content/plugins/freeplast-woo/freeplast-woo.php';

$assertions = 0;
function check( $ok, $message ) { global $assertions; $assertions++; if ( ! $ok ) { throw new RuntimeException( $message ); } }
function http_calls(): int { return count( $GLOBALS['fpwd_http']['calls'] ); }
function last_call(): array { $calls = $GLOBALS['fpwd_http']['calls']; return $calls[ count( $calls ) - 1 ] ?? array(); }
function with_config( $config ): void {
	$GLOBALS['registered_filters']['fpw_dispatch_distance_config'] = null === $config ? array() : array( $config );
}
function ok_response( int $meters ): array {
	return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => wp_json_encode( array( 'routes' => array( array( 'distanceMeters' => $meters ) ) ) ) );
}

/* Fixtures: a dispatch request with an assisted exact destination, and a no-dispatch one. */
$order68 = new FPWDD_Order( 68, array( new FPWDD_Item( 'Caja Cosechera 3/4', 140, 22 ) ), array(
	'_billing_fp_address_source' => 'asistida',
	'_billing_fp_place_id' => 'ChIJfixture-place-id-0000',
	'_billing_fp_place_scope' => 'exacta',
) );
$GLOBALS['fpwd_orders'][68] = $order68;
check( fpw_create_request_draft( $order68 ) === true, 'the dispatch fixture draft is created' );
$draft68 = fpw_read_request_draft( 68 );
check( is_array( $draft68 ) && $draft68['destination']['dispatch'] === 'si' && $draft68['destination']['place_id'] === 'ChIJfixture-place-id-0000', 'the dispatch fixture carries the recorded destination and its place claim' );

$order92 = new FPWDD_Order( 92, array( new FPWDD_Item( 'Caja Cosechera 3/4', 10, 22 ) ), array( '_billing_fp_dispatch' => 'no', '_billing_fp_address' => '' ) );
$GLOBALS['fpwd_orders'][92] = $order92;
check( fpw_create_request_draft( $order92 ) === true, 'the no-dispatch fixture draft is created' );
$draft92 = fpw_read_request_draft( 92 );

/* ----------------------------------------------------------------------- */
/* The configuration seam: absent by default, normalized when delivered — a  */
/* PRIVATE server credential, never a browser key, never rendered.          */
check( fpw_dispatch_distance_config() === array(), 'without configuration there is no distance consultation at all (no server key ships by default)' );
with_config( static fn() => 'forged' );
check( fpw_dispatch_distance_config() === array(), 'a non-array configuration is not a configuration' );
with_config( static fn() => array( 'key' => '   ' ) );
check( fpw_dispatch_distance_config() === array(), 'a blank key is not a configuration' );
with_config( static fn() => array( 'key' => 'server-key-fixture', 'origin' => '  Otra bodega 7, Rancagua ', 'region' => 'cl', 'timeout' => '2' ) );
check( fpw_dispatch_distance_config() === array( 'key' => 'server-key-fixture', 'origin' => 'Otra bodega 7, Rancagua', 'region' => 'CL', 'timeout' => 2 ), 'a delivered configuration is normalized (origin trimmed, region upper-cased, timeout integer)' );
with_config( static fn() => array( 'key' => 'server-key-fixture' ) );
$config = fpw_dispatch_distance_config();
check( $config['origin'] === 'Camino El Arrayán 52, San Francisco de Mostazal', 'the default origin is the documented warehouse' );
check( $config['region'] === 'CL' && $config['timeout'] === 10, 'region and timeout have bounded defaults (CL, 10s)' );
with_config( static fn() => array( 'key' => 'server-key-fixture', 'timeout' => 99 ) );
check( fpw_dispatch_distance_config()['timeout'] === 30, 'an over-long configured timeout is clamped: the call stays bounded' );
with_config( static fn() => array( 'key' => 'server-key-fixture', 'timeout' => 0 ) );
check( fpw_dispatch_distance_config()['timeout'] === 1, 'a zero timeout is clamped to a positive bound' );
with_config( null );

/* The working-destination resolution: the saved work destination over the
 * recorded address; the Place ID only while the text still matches the
 * recorded one — editing the destination invalidates the association. */
check( fpw_distance_destination_for( $draft68, null ) === array( 'address' => 'Camino de prueba 123, Mostazal', 'place_id' => 'ChIJfixture-place-id-0000', 'precision' => 'exacta' ), 'an unedited assisted destination resolves with its exact Place ID' );
$GLOBALS['fpwd_table']['fpw_draft_work_68'] = wp_json_encode( array( 'revision' => 1, 'destination' => 'Camino rural 9, Colchane', 'lines' => array() ) );
$work68 = fpw_read_draft_work( 68 );
check( fpw_distance_destination_for( $draft68, $work68 ) === array( 'address' => 'Camino rural 9, Colchane', 'place_id' => '', 'precision' => 'escrita' ), 'an edited working destination loses the previous place association (typed text only)' );
$GLOBALS['fpwd_table']['fpw_draft_work_68'] = wp_json_encode( array( 'revision' => 2, 'destination' => 'Camino de prueba 123, Mostazal', 'lines' => array() ) );
check( fpw_distance_destination_for( $draft68, fpw_read_draft_work( 68 ) )['place_id'] === 'ChIJfixture-place-id-0000', 'restoring the recorded text restores the association' );
$GLOBALS['fpwd_table']['fpw_draft_work_68'] = wp_json_encode( array( 'revision' => 3, 'destination' => '   ', 'lines' => array() ) );
check( fpw_distance_destination_for( $draft68, fpw_read_draft_work( 68 ) )['address'] === '', 'a blanked working destination is no usable destination at all' );
$GLOBALS['fpwd_table']['fpw_draft_work_68'] = wp_json_encode( array( 'revision' => 4, 'destination' => 'Camino de prueba 123, Mostazal', 'lines' => array() ) );
$manual_record = $draft68;
$manual_record['destination']['place_id'] = 'no space allowed';
check( fpw_distance_destination_for( $manual_record, fpw_read_draft_work( 68 ) )['precision'] === 'escrita', 'a malformed recorded Place ID is never sent: the address text serves alone' );
$manual_record['destination']['source'] = 'manual';
check( fpw_distance_destination_for( $manual_record, fpw_read_draft_work( 68 ) )['place_id'] === '', 'a manual provenance never contributes a Place ID' );
check( fpw_distance_destination_for( $draft92, null ) === null, 'a no-dispatch draft resolves no destination' );

/* The consultation state machine: every failure is honest and nothing is
 * invented; each state keeps the manual dispatch price path intact. */
$result = fpw_distance_consult( $draft92, null );
check( $result['state'] === 'sin_despacho' && http_calls() === 0, '«Sin despacho» makes NO routes call at all' );
$result = fpw_distance_consult( $draft68, null );
check( $result['state'] === 'sin_configuracion' && http_calls() === 0, 'without configuration the consultation answers honestly and contacts nobody' );

with_config( static fn() => array( 'key' => 'server-key-fixture' ) );
$GLOBALS['fpwd_table']['fpw_draft_work_68'] = wp_json_encode( array( 'revision' => 5, 'destination' => '', 'lines' => array() ) );
$result = fpw_distance_consult( $draft68, fpw_read_draft_work( 68 ) );
check( $result['state'] === 'sin_destino' && http_calls() === 0, 'no usable working destination, no call' );

$GLOBALS['fpwd_table']['fpw_draft_work_68'] = wp_json_encode( array( 'revision' => 6, 'destination' => 'Camino rural 9, Colchane', 'lines' => array() ) );
$work68 = fpw_read_draft_work( 68 );
$result = fpw_distance_consult( $draft68, $work68 );
check( $result['state'] === 'ok' && $result['distance_meters'] === 61200 && $result['origin'] === 'Camino El Arrayán 52, San Francisco de Mostazal', 'a valid route answers its driving distance with the documented origin' );
check( $result['destination'] === array( 'address' => 'Camino rural 9, Colchane', 'place_id' => '', 'precision' => 'escrita' ), 'the consultation names the typed destination it used' );
$call = last_call();
check( $call['url'] === 'https://routes.googleapis.com/directions/v2:computeRoutes', 'the consultation uses exactly the documented one-origin/one-destination computeRoutes endpoint' );
check( ( $call['args']['headers']['X-Goog-Api-Key'] ?? '' ) === 'server-key-fixture', 'the private server credential rides the request header only' );
check( ( $call['args']['headers']['X-Goog-FieldMask'] ?? '' ) === 'routes.distanceMeters', 'the field mask asks for the distance alone: no duration, no polyline, no extra data' );
$body = json_decode( (string) ( $call['args']['body'] ?? '' ), true );
check( is_array( $body )
	&& ( $body['origin']['address']['addressLines'] ?? array() ) === array( 'Camino El Arrayán 52, San Francisco de Mostazal' )
	&& ( $body['destination']['address']['addressLines'] ?? array() ) === array( 'Camino rural 9, Colchane' )
	&& ( $body['travelMode'] ?? '' ) === 'DRIVE'
	&& ( $body['routingPreference'] ?? '' ) === 'TRAFFIC_UNAWARE'
	&& ( $body['regionCode'] ?? '' ) === 'CL',
	'the request carries only origin, destination and the bounded driving reference parameters' );
check( ! str_contains( (string) $call['args']['body'], '@' ) && ! str_contains( (string) $call['args']['body'], '76.543.210' ), 'no identity, RUT, email, history or products ever travel to the route provider' );

/* The Place ID path: an unedited assisted destination consults with the
 * recorded identification, never with a re-typed guess. */
$GLOBALS['fpwd_table']['fpw_draft_work_68'] = wp_json_encode( array( 'revision' => 7, 'destination' => 'Camino de prueba 123, Mostazal', 'lines' => array() ) );
$result = fpw_distance_consult( $draft68, fpw_read_draft_work( 68 ) );
check( $result['state'] === 'ok' && $result['destination']['precision'] === 'exacta', 'the unedited assisted destination consults with its recorded precision' );
$body = json_decode( (string) ( last_call()['args']['body'] ?? '' ), true );
check( ( $body['destination']['placeId'] ?? '' ) === 'ChIJfixture-place-id-0000' && ! isset( $body['destination']['address'] ), 'the recorded Place ID is the route endpoint — the supported identification, not coordinates' );

/* Broad match: usable as a reference, announced as needing review. */
$GLOBALS['registered_filters']['fpw_dispatch_distance_config'] = array();
$order69 = new FPWDD_Order( 69, array( new FPWDD_Item( 'Tote', 5, 30 ) ), array(
	'_billing_fp_address' => 'Camino de prueba 123, Mostazal',
	'_billing_fp_address_source' => 'asistida',
	'_billing_fp_place_id' => 'ChIJfixture-place-id-0000',
	'_billing_fp_place_scope' => 'amplia',
) );
$GLOBALS['fpwd_orders'][69] = $order69;
fpw_create_request_draft( $order69 );
$draft69 = fpw_read_request_draft( 69 );
with_config( static fn() => array( 'key' => 'server-key-fixture' ) );
$result = fpw_distance_consult( $draft69, null );
check( $result['state'] === 'ok' && $result['destination']['precision'] === 'amplia', 'a broad match still consults, recorded as broad — never dressed as exact' );

/* The failure matrix: none of these is a distance, none is a zero. */
$GLOBALS['fpwd_http']['script'] = array( array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'routes' => array() ) ) ) );
check( fpw_distance_consult( $draft68, $work68 )['state'] === 'sin_ruta', 'no route returned stays an explicit no-route state' );
$GLOBALS['fpwd_http']['script'] = array( array( 'response' => array( 'code' => 200 ), 'body' => '{"routes": [{"distanceMeters": "not-a-number"}]}' ) );
check( fpw_distance_consult( $draft68, $work68 )['state'] === 'invalida', 'a malformed distance is an unusable response, never a number' );
$GLOBALS['fpwd_http']['script'] = array( array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'routes' => array( array( 'distanceMeters' => 0 ) ) ) ) ) );
check( fpw_distance_consult( $draft68, $work68 )['state'] === 'invalida', 'a zero distance is unusable: a route of zero meters is not a reference' );
$GLOBALS['fpwd_http']['script'] = array( array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'routes' => array( array( 'distanceMeters' => 99999999 ) ) ) ) ) );
check( fpw_distance_consult( $draft68, $work68 )['state'] === 'invalida', 'an absurd distance is outside the accepted bound and stays unusable' );
$GLOBALS['fpwd_http']['script'] = array( array( 'response' => array( 'code' => 200 ), 'body' => 'not json at all' ) );
check( fpw_distance_consult( $draft68, $work68 )['state'] === 'invalida', 'a non-JSON body is an unusable response' );
$GLOBALS['fpwd_http']['script'] = array( array( 'response' => array( 'code' => 429 ), 'body' => 'quota' ) );
check( fpw_distance_consult( $draft68, $work68 )['state'] === 'cuota', 'a 429 is the quota state, named as such' );
$GLOBALS['fpwd_http']['script'] = array( array( 'response' => array( 'code' => 403 ), 'body' => 'denied' ) );
check( fpw_distance_consult( $draft68, $work68 )['state'] === 'no_autorizada', 'a 403 from the provider is the credential-rejected state' );
$GLOBALS['fpwd_http']['script'] = array( array( 'response' => array( 'code' => 500 ), 'body' => 'boom' ) );
check( fpw_distance_consult( $draft68, $work68 )['state'] === 'fallo', 'a 5xx is an explicit provider failure' );
$GLOBALS['fpwd_http']['script'] = array( new WP_Error( 'http_request_timeout', 'cURL error 28: Operation timed out' ) );
check( fpw_distance_consult( $draft68, $work68 )['state'] === 'timeout', 'a transport timeout is named as a timeout' );
$GLOBALS['fpwd_http']['script'] = array( new WP_Error( 'http_request_failed', 'connection refused' ) );
check( fpw_distance_consult( $draft68, $work68 )['state'] === 'fallo', 'any other transport error is an explicit failure' );
$GLOBALS['fpwd_http']['script'] = array();

/* Persistence inspection: the consultation writes NOTHING anywhere. */
$rows_before = $GLOBALS['fpwd_table'];
$GLOBALS['fpwd_http']['script'] = array( ok_response( 61200 ) );
fpw_distance_consult( $draft68, $work68 );
check( $GLOBALS['fpwd_table'] === $rows_before, 'a consultation (even a successful one) stores nothing: no kilometers, no payloads, no rows at all' );

/* The private action: authorization first, then CSRF — processed ahead of the
 * header render, so every denial is a real 403. */
$_GET = array( 'page' => 'fpw-quote-draft', 'request' => '68' );
$GLOBALS['fpwd_caps'] = array( 'read' => true, 'manage_freeplast_quotes' => true, 'edit_shop_orders' => true, 'edit_others_shop_orders' => true );
$_POST = array( 'fpw_distance_consult' => '1', 'fpw_distance_nonce' => 'offline-nonce' );
$GLOBALS['fpwd_http']['script'] = array( ok_response( 61200 ) );
$calls_before = http_calls();
try {
	fpw_handle_distance_consult();
	check( false, 'a valid ventas session must be denied the distance consultation' );
} catch ( FPWDD_Die $e ) {
	check( $e->getMessage() === '403', 'ventas receives the 403 permission denial on the consult, whatever nonce it presents' );
}
check( http_calls() === $calls_before, 'the denied consult reached no provider' );
$GLOBALS['fpwd_caps'] = array( 'manage_woocommerce' => true );
$GLOBALS['fpwd_nonce_ok'] = false;
try {
	fpw_handle_distance_consult();
	check( false, 'a consult with an invalid nonce must be refused' );
} catch ( FPWDD_Die $e ) {
	check( $e->getMessage() === '403', 'a consult failing the CSRF check is refused 403' );
}
check( http_calls() === $calls_before, 'the refused consult reached no provider' );
check( fpw_pending_distance_consult() === null, 'nothing is stashed after a refusal' );
$GLOBALS['fpwd_nonce_ok'] = true;
$_POST = array( 'fpw_distance_consult' => '1', 'fpw_distance_nonce' => 'offline-nonce' );
fpw_handle_distance_consult();
$pending = fpw_pending_distance_consult();
check( is_array( $pending ) && (int) $pending['order_id'] === 68 && $pending['result']['state'] === 'ok', 'the authorized consult runs once, stashing its outcome for the screen' );

/* The screen renders the current consultation honestly — and only it. */
ob_start();
fpw_render_quote_draft_screen();
$html = (string) ob_get_clean();
check( str_contains( $html, '61,2 km' ) && str_contains( $html, '61.200 m' ), 'the consultation renders its reference distance in km with the exact meters beside it' );
check( str_contains( $html, 'Camino de prueba 123, Mostazal' ) && str_contains( $html, 'Camino El Arrayán 52, San Francisco de Mostazal' ), 'the screen names both the consulted destination and the origin' );
check( str_contains( $html, 'no certifica el acceso de un camión' ) && str_contains( $html, 'no se guarda' ), 'the screen carries the honest reference disclaimer and the nothing-is-stored statement' );
check( ! str_contains( $html, '$' ), 'no amounts render in the distance section' );
check( str_contains( $html, 'name="fpw_distance_nonce"' ) && str_contains( $html, 'name="fpw_distance_consult" value="1"' ), 'the consult form carries its own flag and CSRF nonce' );

/* A failed consultation reads as a failure, never as a distance or a zero,
 * and the form stays for a retry. */
$GLOBALS['fpwd_http']['script'] = array( array( 'response' => array( 'code' => 429 ), 'body' => 'quota' ) );
fpw_handle_distance_consult();
ob_start();
fpw_render_quote_draft_screen();
$html = (string) ob_get_clean();
check( str_contains( $html, 'cuota' ) && ! str_contains( $html, '61,2 km' ), 'the quota state renders as the quota state: no stale distance from an earlier consult' );
check( str_contains( $html, 'name="fpw_distance_consult" value="1"' ), 'after a failed consult the retry action remains' );

/* A consultation made for a destination that changed afterwards names the
 * mismatch instead of dressing old work as current. */
$GLOBALS['fpwd_http']['script'] = array( ok_response( 61200 ) );
fpw_handle_distance_consult();
$GLOBALS['fpwd_table']['fpw_draft_work_68'] = wp_json_encode( array( 'revision' => 8, 'destination' => 'Otro destino posterior, Rancagua', 'lines' => array() ) );
ob_start();
fpw_render_quote_draft_screen();
$html = (string) ob_get_clean();
check( str_contains( $html, 'la consulta se hizo para' ) && str_contains( $html, 'Camino de prueba 123, Mostazal' ), 'a consultation older than the current working destination names what it consulted' );

/* Issue #61: the rule turns a successful consultation into a SUGGESTION with
 * its internal breakdown — distinguishable from the chosen amount, never
 * stored; without a maintained rule no amount is suggested at all. */
check( str_contains( $html, 'no está configurada' ) && ! str_contains( $html, 'Sugerencia de la regla' ), 'without a maintained rule a successful consult suggests no amount and names the manual path' );
check( str_contains( $html, 'name="fpw_rule_save"' ) === false && str_contains( $html, 'fpw-dispatch-rule' ), 'the draft screen links the rule mantenedor instead of inlining a rule editor' );
$GLOBALS['fpwd_table']['fpw_dispatch_rule'] = wp_json_encode( array( 'schema' => 1, 'updated_at' => time(), 'updated_by' => 'dueña', 'rule' => array( 'base_fee' => 15000, 'per_km' => 2500, 'minimum' => 20000 ) ) );
ob_start();
fpw_render_quote_draft_screen();
$html = (string) ob_get_clean();
check( str_contains( $html, 'Sugerencia de la regla: 170.000 CLP neto' ), 'the rule suggestion renders beside the standing consultation (15.000 + 2.500 × 62 km iniciados)' );
check( str_contains( $html, 'cargo fijo 15.000' ) && str_contains( $html, '2.500 CLP/km × 62 km' ) && str_contains( $html, 'no se aplica' ), 'the breakdown names its inputs, the started-kilometer interpretation and the unused minimum' );
check( str_contains( $html, 'no es el monto elegido' ) && str_contains( $html, 'no certifica el acceso de un camión' ), 'the suggestion distinguishes itself from the chosen amount and carries the route-reference limitations' );
$GLOBALS['fpwd_table']['fpw_draft_work_68'] = wp_json_encode( array( 'revision' => 9, 'destination' => 'Otro destino posterior, Rancagua', 'lines' => array(), 'dispatch_amount' => 90000 ) );
ob_start();
fpw_render_quote_draft_screen();
$html = (string) ob_get_clean();
check( str_contains( $html, '90.000 CLP neto · ingreso manual' ) && str_contains( $html, 'Sugerencia de la regla: 170.000' ), 'the chosen amount keeps its manual origin beside the standing suggestion: consulting never replaces it' );
$GLOBALS['fpwd_table']['fpw_dispatch_rule'] = wp_json_encode( array( 'schema' => 1, 'updated_at' => time(), 'updated_by' => 'dueña', 'rule' => array( 'base_fee' => 15000, 'per_km' => 2500, 'minimum' => 99999999 ) ) );
ob_start();
fpw_render_quote_draft_screen();
$html = (string) ob_get_clean();
check( str_contains( $html, 'Sugerencia de la regla: 99.999.999 CLP neto' ) && str_contains( $html, 'se aplica el mínimo' ), 'a binding minimum is applied and explained in the breakdown' );
$rows_before = $GLOBALS['fpwd_table'];
fpw_rule_suggestion( 61200 );
ob_start();
fpw_render_quote_draft_screen();
$html = (string) ob_get_clean();
check( $GLOBALS['fpwd_table'] === $rows_before, 'rendering the suggestion (and computing it) stores nothing: the commercial decision stays manual' );

/* The no-dispatch draft never renders the section nor its action. */
$_GET = array( 'page' => 'fpw-quote-draft', 'request' => '92' );
$_POST = array();
ob_start();
fpw_render_quote_draft_screen();
$html92 = (string) ob_get_clean();
check( ! str_contains( $html92, 'fpw_distance_consult' ) && ! str_contains( $html92, 'Distancia de despacho' ), 'a no-dispatch draft offers no distance consultation at all' );

/* A request without a draft offers no consultation either. */
$_GET = array( 'page' => 'fpw-quote-draft', 'request' => '99999999' );
$_POST = array( 'fpw_distance_consult' => '1', 'fpw_distance_nonce' => 'offline-nonce' );
$before = http_calls();
fpw_handle_distance_consult();
check( http_calls() === $before && fpw_pending_distance_consult() === null, 'an unknown request consults nothing' );

with_config( null );
echo "dispatch distance: $assertions offline checks passed (issue #60: config seam, destination resolution, honest states, boundaries, nothing stored)\n";
