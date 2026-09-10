<?php
/** Issue #59 — corte 10 de #49: asistencia de la dirección de despacho sin
 * perder el ingreso manual. Offline server contract: the Places configuration
 * seam (absent by default — real credentials are a separate authorization),
 * the registered provenance carriers, the decision table that keeps ONLY the
 * confirmed address plus the allowed identification (a browser hidden field
 * is a claim, never verified evidence), the record persistence (never
 * coordinates, never raw responses, nothing with «Sin despacho»), and the
 * draft's destination provenance — honestly rendered for the private review. */
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
function apply_filters( $tag, $value, ...$args ) { global $registered_filters; foreach ( $registered_filters[ $tag ] ?? array() as $callback ) { $value = $callback( $value, ...$args ); } return $value; }
class WP_Error {
	public array $codes = array();
	public function add( $code, $message, $data = null ) { $this->codes[] = $code; }
}
/* A minimal WC() session so the adapter's attempt-token hook actually renders. */
class FPWP_Fake_Session {
	public array $data = array();
	public function get( $key, $default = '' ) { return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : $default; }
	public function set( $key, $value ) { $this->data[ $key ] = $value; }
	public function __unset( $key ) { unset( $this->data[ $key ] ); }
}
class FPWP_Fake_WC {
	public $session; public $cart = null;
}
if ( ! function_exists( 'WC' ) ) { $GLOBALS['fpwp_woo'] = new FPWP_Fake_WC(); $GLOBALS['fpwp_woo']->session = new FPWP_Fake_Session(); function WC() { return $GLOBALS['fpwp_woo']; } }
require __DIR__ . '/../wp-content/plugins/freeplast-woo/freeplast-woo.php';

$assertions = 0;
function check( $ok, $message ) { global $assertions; $assertions++; if ( ! $ok ) { throw new RuntimeException( $message ); } }

/* --- WordPress stubs the provenance tests need beyond plugin load --- */
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_url( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function admin_url( $path = '' ) { return 'https://freeplast.test/wp-admin/' . $path; }
function date_i18n( $format, $timestamp ) { return gmdate( 'Y-m-d', (int) $timestamp ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_unslash( $value ) { return $value; }
function do_action( ...$args ): void {}
$GLOBALS['fpwp_options'] = array( 'date_format' => 'j F Y' );
function get_option( $name, $default = false ) { return $GLOBALS['fpwp_options'][ $name ] ?? $default; }
$GLOBALS['fpwp_caps'] = array();
function current_user_can( string $cap ): bool { return ! empty( $GLOBALS['fpwp_caps'][ $cap ] ); }
function add_submenu_page( $parent, $page_title, $menu_title, $capability, $slug, $callback ) { return $slug; }
function wc_attribute_label( $name, $product = '' ) { return $name; }
function wc_get_order( $id ) { return null; }

/* ----------------------------------------------------------------------- */
/* The configuration seam: nothing loads without a deliberate configuration */
check( fpw_places_config() === array(), 'without configuration the Places assistant never loads (no key ships by default)' );
$GLOBALS['registered_filters']['fpw_places_config'] = array( static function ( $config ) {
	return array( 'key' => 'browser-key-fixture', 'region' => 'cl', 'version' => 'quarterly' );
} );
check( fpw_places_config() === array( 'key' => 'browser-key-fixture', 'region' => 'CL', 'version' => 'quarterly' ), 'a delivered configuration is normalized (region upper-cased, defaults filled)' );
$GLOBALS['registered_filters']['fpw_places_config'] = array( static function ( $config ) {
	return array( 'key' => '   ' );
} );
check( fpw_places_config() === array(), 'a blank key is not a configuration' );
$GLOBALS['registered_filters']['fpw_places_config'] = array( static function ( $config ) {
	return 'ChIJ-forged-string';
} );
check( fpw_places_config() === array(), 'a non-array configuration is not a configuration' );
$GLOBALS['registered_filters']['fpw_places_config'] = array();

/* The registered carriers: normalized by Woo like the attempt token, rendered
 * empty by the adapter hook, never a rendered form row, never session-drafted. */
$fields = fpw_checkout_fields( array() );
foreach ( array( 'fpw_place_id', 'fpw_place_scope' ) as $carrier ) {
	check( isset( $fields['fpw'][ $carrier ] ), $carrier . ' is registered for Woo\'s own checkout normalization (issue #59)' );
	check( ( $fields['fpw'][ $carrier ]['required'] ?? true ) === false, $carrier . ' carries no requirement at Woo\'s own validation layer' );
	foreach ( array( 'billing', 'shipping', 'account', 'order' ) as $rendered_fieldset ) {
		check( ! isset( $fields[ $rendered_fieldset ][ $carrier ] ), $carrier . ' never renders as a native form row (' . $rendered_fieldset . ')' );
	}
	check( ! in_array( $carrier, fpw_draft_keys(), true ), $carrier . ' never round-trips the draft-value restore: it is attempt-scoped client state' );
}
ob_start();
foreach ( $registered_actions['woocommerce_after_order_notes'] ?? array() as $hooked ) { $hooked(); }
$hook_html = (string) ob_get_clean();
check( substr_count( $hook_html, 'name="fpw_attempt"' ) === 1, 'the adapter hook still emits the attempt identity exactly once' );
check( substr_count( $hook_html, 'name="fpw_place_id" value=""' ) === 1 && substr_count( $hook_html, 'name="fpw_place_scope" value=""' ) === 1, 'the provenance carriers render EMPTY: the server never echoes posted provenance back' );

/* The decision table: only a confirmed address with its allowed identification
 * persists; every malformed, contradictory or absent piece — and any arbitrary
 * hidden field — degrades to plainly manual, never rejecting a valid request. */
function fpw_provenance_for( array $overrides = array() ): array {
	return fpw_address_provenance( array_merge( array(
		'billing_fp_dispatch' => 'si',
		'billing_fp_address'  => 'Camino de prueba 123, Mostazal, VI Región',
		'fpw_place_id'        => 'ChIJfixture-place-id_0000',
		'fpw_place_scope'     => 'exacta',
		'fpw_place_lat'       => '-33.123456',
		'billing_fp_place_raw_response' => '{"formattedAddress":"forged"}',
	), $overrides ) );
}
check( fpw_provenance_for() === array( 'source' => 'asistida', 'place_id' => 'ChIJfixture-place-id_0000', 'scope' => 'exacta' ), 'a confirmed exact selection keeps the address, the place id and its scope' );
check( fpw_provenance_for( array( 'fpw_place_scope' => 'amplia' ) ) === array( 'source' => 'asistida', 'place_id' => 'ChIJfixture-place-id_0000', 'scope' => 'amplia' ), 'a broad road/commune match is recorded as such, not dressed up as exact' );
check( fpw_provenance_for( array( 'fpw_place_scope' => 'verificado' ) ) === array( 'source' => 'manual', 'place_id' => '', 'scope' => '' ), 'an unknown scope value is not evidence: the address degrades to manual' );
check( fpw_provenance_for( array( 'fpw_place_scope' => '' ) ) === array( 'source' => 'manual', 'place_id' => '', 'scope' => '' ), 'a place id without its scope carrier is not evidence' );
check( fpw_provenance_for( array( 'fpw_place_id' => '' ) ) === array( 'source' => 'manual', 'place_id' => '', 'scope' => '' ), 'a scope without its place id is not evidence' );
check( fpw_provenance_for( array( 'fpw_place_id' => '<script>alert(1)</script>' ) ) === array( 'source' => 'manual', 'place_id' => '', 'scope' => '' ), 'a forged place id is not evidence' );
check( fpw_provenance_for( array( 'fpw_place_id' => str_repeat( 'a', 256 ) ) ) === array( 'source' => 'manual', 'place_id' => '', 'scope' => '' ), 'an oversized place id is not evidence' );
check( fpw_provenance_for( array( 'fpw_place_id' => 'short id' ) ) === array( 'source' => 'manual', 'place_id' => '', 'scope' => '' ), 'a place id with separators/spaces is not evidence' );
check( fpw_provenance_for( array( 'billing_fp_address' => '   ' ) ) === array( 'source' => 'manual', 'place_id' => '', 'scope' => '' ), 'a place association without the confirmed address text keeps nothing' );
check( fpw_provenance_for( array( 'fpw_place_id' => '', 'fpw_place_scope' => '' ) ) === array( 'source' => 'manual', 'place_id' => '', 'scope' => '' ), 'a manual address is recorded as manual: the assistant state is never invented' );
check( fpw_provenance_for( array( 'billing_fp_dispatch' => 'no', 'fpw_place_id' => 'ChIJfixture-place-id_0000', 'fpw_place_scope' => 'exacta' ) ) === array( 'source' => '', 'place_id' => '', 'scope' => '' ), '«Sin despacho» excludes the destination AND every place association' );

/* The persistence: the created record keeps exactly the allowed facts. */
class FPWP_Fake_Order {
	public array $meta = array();
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function get_id(): int { return 91; }
}
function is_closure( $candidate ): bool { return is_object( $candidate ) && $candidate instanceof Closure; }
$create_callbacks = array();
foreach ( ( $GLOBALS['registered_actions']['woocommerce_checkout_create_order'] ?? array() ) as $callback ) {
	if ( is_closure( $callback ) ) { $create_callbacks[] = $callback; }
}
function fpwp_create( array $data ): FPWP_Fake_Order {
	global $create_callbacks;
	$order = new FPWP_Fake_Order();
	foreach ( $create_callbacks as $callback ) { $callback( $order, $data ); }
	return $order;
}
$order = fpwp_create( array(
	'billing_fp_dispatch' => 'si', 'billing_fp_address' => 'Camino de prueba 123, Mostazal',
	'billing_fp_rut' => '76.123.456-7', 'billing_fp_giro' => 'Giro', 'fpw_place_id' => 'ChIJfixture-place-id_0000', 'fpw_place_scope' => 'exacta',
) );
check( ( $order->meta['_billing_fp_address_source'] ?? '' ) === 'asistida', 'an assisted confirmation records its provenance' );
check( ( $order->meta['_billing_fp_place_id'] ?? '' ) === 'ChIJfixture-place-id_0000', 'the allowed place identification persists' );
check( ( $order->meta['_billing_fp_place_scope'] ?? '' ) === 'exacta', 'the reported match scope persists' );
check( ! str_contains( wp_json_encode( $order->meta ), '-33.123456' ), 'coordinates are never persisted' );
check( ! str_contains( wp_json_encode( $order->meta ), 'forged' ), 'raw provider responses are never persisted' );

$order_manual = fpwp_create( array(
	'billing_fp_dispatch' => 'si', 'billing_fp_address' => 'Camino rural sin asistente, Mostazal',
	'fpw_place_id' => 'no space allowed', 'fpw_place_scope' => 'exacta',
) );
check( ( $order_manual->meta['_billing_fp_address_source'] ?? '' ) === 'manual', 'a degraded payload records a plainly manual address' );
check( ! isset( $order_manual->meta['_billing_fp_place_id'] ) && ! isset( $order_manual->meta['_billing_fp_place_scope'] ), 'a degraded payload persists no place identification' );

$order_none = fpwp_create( array(
	'billing_fp_dispatch' => 'no', 'billing_fp_address' => '', 'billing_fp_rut' => '76.123.456-7', 'billing_fp_giro' => 'Giro',
	'fpw_place_id' => 'ChIJfixture-place-id_0000', 'fpw_place_scope' => 'exacta',
) );
check( ( $order_none->meta['_billing_fp_address'] ?? 'x' ) === '', '«Sin despacho» persists no destination (existing rule intact)' );
check( ! isset( $order_none->meta['_billing_fp_address_source'] ) && ! isset( $order_none->meta['_billing_fp_place_id'] ) && ! isset( $order_none->meta['_billing_fp_place_scope'] ), '«Sin despacho» persists no provenance at all, even with stale place fields posted' );

/* The draft: destination provenance joins the receipt snapshot (schema 2) and
 * renders for the private review without ever dressing a claim as evidence. */
class FPWP_Item { public function get_name(): string { return 'Caja de prueba'; } public function get_product_id(): int { return 22; } public function get_variation_id(): int { return 0; } public function get_quantity(): int { return 10; } public function get_meta_data(): array { return array(); } }
class FPWP_Order {
	public array $meta;
	public function __construct( array $meta = array() ) { $this->meta = $meta; }
	public function get_id(): int { return 91; }
	public function get_order_number(): string { return 'FP-2026-000091'; }
	public function get_meta( string $key ): mixed { return $this->meta[ $key ] ?? ''; }
	public function get_items(): array { return array( new FPWP_Item() ); }
	public function get_billing_first_name(): string { return 'Pilar'; }
	public function get_billing_company(): string { return 'Agrícola de prueba SpA'; }
	public function get_billing_phone(): string { return '+56 9 1234 5678'; }
	public function get_billing_email(): string { return 'compras@prueba.invalid'; }
	public function get_date_created(): DateTimeImmutable { return new DateTimeImmutable( '2026-09-11 12:00:00' ); }
}
$place_id_evil = 'ChIJ" onmouseover="alert(1)';
$assisted = new FPWP_Order( array(
	'_fp_request' => 'yes', '_fpw_attempt' => str_repeat( 'ab', 32 ),
	'_billing_fp_dispatch' => 'si', '_billing_fp_address' => 'Camino de prueba 123, Mostazal',
	'_billing_fp_address_source' => 'asistida', '_billing_fp_place_id' => $place_id_evil, '_billing_fp_place_scope' => 'amplia',
	'_fp_submitted_details' => array( 'billing_fp_dispatch' => 'si' ),
) );
$GLOBALS['fpwp_caps'] = array( 'manage_woocommerce' => true );
$draft = fpw_build_request_draft_payload( $assisted );
check( $draft['schema'] === 2, 'the draft schema marks the destination-provenance shape' );
check( $draft['destination'] === array( 'dispatch' => 'si', 'address' => 'Camino de prueba 123, Mostazal', 'source' => 'asistida', 'place_id' => $place_id_evil, 'scope' => 'amplia' ), 'the snapshot carries the recorded provenance exactly as the record kept it' );
$html = fpw_quote_draft_markup( $assisted, $draft );
check( str_contains( $html, 'Procedencia de la dirección' ) && str_contains( $html, 'Confirmada con el asistente de direcciones' ), 'the assisted provenance renders for the private review' );
check( str_contains( $html, 'Coincidencia amplia — revisar número y comuna' ), 'a broad match is named as needing review, never as a certified delivery point' );
check( str_contains( $html, htmlspecialchars( $place_id_evil, ENT_QUOTES ) ) && ! str_contains( $html, 'onmouseover="alert' ), 'the place id renders escaped: a stored claim can never inject markup' );
check( ! str_contains( $html, 'acceso certificado' ) && ! str_contains( $html, 'entrega garantizada' ), 'the screen never certifies deliverability' );

$manual_order = new FPWP_Order( array(
	'_fp_request' => 'yes', '_fpw_attempt' => str_repeat( 'cd', 32 ),
	'_billing_fp_dispatch' => 'si', '_billing_fp_address' => 'Camino rural sin asistente, Mostazal',
	'_billing_fp_address_source' => 'manual',
) );
$legacy_order = new FPWP_Order( array(
	'_fp_request' => 'yes', '_fpw_attempt' => str_repeat( 'ef', 32 ),
	'_billing_fp_dispatch' => 'si', '_billing_fp_address' => 'Dirección anterior al asistente',
) );
$legacy_draft = fpw_build_request_draft_payload( $legacy_order );
check( $legacy_draft['destination']['source'] === '' && $legacy_draft['destination']['place_id'] === '' && $legacy_draft['destination']['scope'] === '', 'records older than the feature carry no invented provenance' );
$html_legacy = fpw_quote_draft_markup( $legacy_order, $legacy_draft );
check( str_contains( $html_legacy, 'Sin registro' ), 'a missing provenance is named honestly, not dressed as manual or assisted' );

$manual_draft = fpw_build_request_draft_payload( $manual_order );
check( $manual_draft['destination']['source'] === 'manual' && $manual_draft['destination']['place_id'] === '' && $manual_draft['destination']['scope'] === '', 'a manual address keeps no place identification' );
$html_manual = fpw_quote_draft_markup( $manual_order, $manual_draft );
check( str_contains( $html_manual, 'Ingresada manualmente' ), 'the manual provenance renders plainly' );
check( ! str_contains( $html_manual, 'Place ID' ), 'a manual address renders no place identification row' );

$none_order = new FPWP_Order( array( '_fp_request' => 'yes', '_billing_fp_dispatch' => 'no', '_billing_fp_address' => '' ) );
$none_draft = fpw_build_request_draft_payload( $none_order );
check( $none_draft['destination']['dispatch'] === 'no' && $none_draft['destination']['source'] === '', 'a no-dispatch record carries no destination provenance' );
$html_none = fpw_quote_draft_markup( $none_order, $none_draft );
check( str_contains( $html_none, 'no pide despacho' ) && ! str_contains( $html_none, 'Procedencia' ), 'the no-dispatch section renders no provenance row' );

echo "checkout places: $assertions offline checks passed (issue #59: provenance claim handling, persistence boundary, draft rendering)\n";
