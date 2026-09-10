<?php
/** Issue #50 — corte 1 de #49: una solicitud recibida deja UN borrador privado.
 * Offline contract of the draft feature: the durable relationship (one unique
 * options row, plain INSERT), the receipt snapshot built from the native record
 * only, honest pending states (never zero prices, never a customer verdict),
 * the private screen's authorization boundary (owner capability, ventas denied)
 * and the owner notice riding the EXISTING admin email — no second notification. */
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
class WP_Error {
	public array $codes = array();
	public function add( $code, $message, $data = null ) { $this->codes[] = $code; }
}
require __DIR__ . '/../wp-content/plugins/freeplast-woo/freeplast-woo.php';

$assertions = 0;
function check( $ok, $message ) { global $assertions; $assertions++; if ( ! $ok ) { throw new RuntimeException( $message ); } }

/* --- WordPress runtime stubs the feature needs beyond plugin load --- */
class FPWD_Die extends RuntimeException {}
function wp_die( $message = '', $title = '', $args = array() ) { throw new FPWD_Die( (string) ( $args['response'] ?? 0 ) ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_url( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function admin_url( $path = '' ) { return 'https://freeplast.test/wp-admin/' . $path; }
function date_i18n( $format, $timestamp ) { return gmdate( 'Y-m-d', (int) $timestamp ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_unslash( $value ) { return $value; }
function do_action( ...$args ): void {}
$GLOBALS['fpwd_options'] = array( 'date_format' => 'j F Y' );
function get_option( $name, $default = false ) { return $GLOBALS['fpwd_options'][ $name ] ?? $default; }
$GLOBALS['fpwd_caps'] = array();
function current_user_can( string $cap ): bool { return ! empty( $GLOBALS['fpwd_caps'][ $cap ] ); }
$GLOBALS['fpwd_submenu'] = null;
function add_submenu_page( $parent, $page_title, $menu_title, $capability, $slug, $callback ) {
	$GLOBALS['fpwd_submenu'] = compact( 'parent', 'page_title', 'menu_title', 'capability', 'slug', 'callback' );
	return $slug;
}
$GLOBALS['fpwd_attr_labels'] = array( 'pa_color' => 'Color' );
function wc_attribute_label( $name, $product = '' ) { return $GLOBALS['fpwd_attr_labels'][ $name ] ?? $name; }
$GLOBALS['fpwd_orders'] = array();
function wc_get_order( $id ) { return $GLOBALS['fpwd_orders'][ (int) $id ] ?? null; }

/* Fake wpdb with the unique option_name semantics of the real one (the INSERT
 * that hits an existing name loses; reads bypass the per-request cache). */
class FPWD_Fake_wpdb {
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
		return 0;
	}
	public function get_var( $sql ) {
		if ( preg_match( "/SELECT option_value FROM \{?\w*options\}? WHERE option_name = '(.+)'$/", $sql, $m ) ) {
			return $GLOBALS['fpwd_table'][ $m[1] ] ?? null;
		}
		return null;
	}
}
$GLOBALS['fpwd_table'] = array();
$GLOBALS['wpdb'] = new FPWD_Fake_wpdb();

/* The native record: a stored order with its lines, chosen options and identity. */
class FPWD_ItemMeta {
	public function __construct( private string $key, private mixed $value ) {}
	public function get_data(): array { return array( 'key' => $this->key, 'value' => $this->value ); }
}
class FPWD_Item {
	public function __construct( private string $name, private int $quantity, private int $productId, private int $variationId, private array $meta = array() ) {}
	public function get_name(): string { return $this->name; }
	public function get_quantity(): int { return $this->quantity; }
	public function get_product_id(): int { return $this->productId; }
	public function get_variation_id(): int { return $this->variationId; }
	public function get_meta_data(): array { $out = array(); foreach ( $this->meta as $k => $v ) { $out[] = new FPWD_ItemMeta( $k, $v ); } return $out; }
}
class FPWD_Order {
	public array $meta;
	public function __construct( public int $id, public array $items, array $meta = array() ) {
		$this->meta = array_merge( array(
			'_fp_request' => 'yes',
			'_fpw_attempt' => str_repeat( 'ab', 32 ),
			'_fp_submitted_details' => array( 'billing_first_name' => 'Pilar', 'billing_fp_dispatch' => 'si', 'billing_fp_address' => 'Camino de prueba 123, Mostazal' ),
			'_billing_fp_rut' => '76.543.210-K',
			'_billing_fp_giro' => 'Producción agrícola',
			'_billing_fp_dispatch' => 'si',
			'_billing_fp_address' => 'Camino de prueba 123, Mostazal',
		), $meta );
	}
	public function get_id(): int { return $this->id; }
	public function get_order_number(): string { return 'FP-2026-' . sprintf( '%06d', $this->id ); }
	public function get_meta( string $key ): mixed { return $this->meta[ $key ] ?? ''; }
	public function get_items(): array { return $this->items; }
	public function get_billing_first_name(): string { return 'Pilar'; }
	public function get_billing_company(): string { return 'Agrícola de prueba SpA'; }
	public function get_billing_phone(): string { return '+56 9 1234 5678'; }
	public function get_billing_email(): string { return 'compras@prueba.invalid'; }
	public function get_date_created(): DateTimeImmutable { return new DateTimeImmutable( '2026-09-10 12:00:00' ); }
}
$attempt68 = str_repeat( 'ab', 32 );
$order68 = new FPWD_Order( 68, array(
	new FPWD_Item( 'Caja Cosechera 3/4', 140, 22, 0 ),
	new FPWD_Item( 'Caja Universal Cerrada Color', 25, 25, 310, array( 'pa_color' => 'Azul', '_reduced_stock' => '1' ) ),
) );
$GLOBALS['fpwd_orders'][68] = $order68;

/* Registration: the draft is born on the native receipt event; the screen rides
 * admin_menu with the owner capability; no new notification surface exists. */
check( in_array( 'fpw_create_request_draft', $registered_actions['woocommerce_checkout_order_created'] ?? array(), true ), 'the draft is created on woocommerce_checkout_order_created — the durable receipt moment' );
check( in_array( 'fpw_quote_draft_register_screen', $registered_actions['admin_menu'] ?? array(), true ), 'the private screen registers through admin_menu' );
fpw_quote_draft_register_screen();
$screen = $GLOBALS['fpwd_submenu'];
check( is_array( $screen ) && null === $screen['parent'] && 'fpw-quote-draft' === $screen['slug'] && 'manage_woocommerce' === $screen['capability'] && 'fpw_render_quote_draft_screen' === $screen['callback'], 'the screen is a private (unlisted) wp-admin page keyed on manage_woocommerce' );
check( empty( $registered_actions['woocommerce_checkout_order_processed'] ), 'the feature registers nothing on checkout_order_processed: the owner notice stays the extension\'s single existing email' );
check( is_string( fpw_draft_screen_url( 68 ) ) && str_contains( fpw_draft_screen_url( 68 ), 'page=fpw-quote-draft&request=68' ), 'the notice link names the screen and its request' );

/* The receipt snapshot: everything from the native record, nothing from a cart
 * or demo values; what the record does not carry yet is explicitly pending. */
check( fpw_create_request_draft( $order68 ) === true, 'the first receipt creates the request draft' );
$payload = fpw_read_request_draft( 68 );
check( is_array( $payload ) && $payload['order_id'] === 68 && $payload['reference'] === 'FP-2026-000068', 'the draft binds the request id and its native reference' );
check( ( $payload['received_at'] ?? 0 ) > 0, 'the receipt time is stored' );
check( $payload['attempt'] === $attempt68, 'the draft carries the request\'s own attempt identity' );
check( count( $payload['items'] ) === 2, 'every stored line joins the snapshot' );
check( $payload['items'][0] === array( 'name' => 'Caja Cosechera 3/4', 'product_id' => 22, 'variation_id' => 0, 'quantity' => 140, 'options' => array() ), 'the simple line snapshots name, ids and quantity' );
check( $payload['items'][1]['quantity'] === 25 && $payload['items'][1]['variation_id'] === 310, 'the variant line keeps its own identity and quantity' );
check( $payload['items'][1]['options'] === array( array( 'key' => 'pa_color', 'value' => 'Azul' ) ), 'the chosen option comes from the record; internal meta stays out' );
check( $payload['identity'] === array( 'name' => 'Pilar', 'company' => 'Agrícola de prueba SpA', 'rut' => '76.543.210-K', 'giro' => 'Producción agrícola', 'phone' => '+56 9 1234 5678', 'email' => 'compras@prueba.invalid' ), 'identity comes from the persisted record' );
check( $payload['destination'] === array( 'dispatch' => 'si', 'address' => 'Camino de prueba 123, Mostazal' ), 'the destination comes from the record' );
check( $payload['submitted_details'] === $order68->get_meta( '_fp_submitted_details' ), 'Submitted Details are preserved verbatim' );
check( $payload['enrichment'] === array( 'prices' => 'pending', 'history' => 'pending', 'dispatch' => 'pending' ), 'missing enrichment is an explicit pending — never a zero price, never a customer verdict' );
foreach ( $payload['items'] as $line ) { check( ! array_key_exists( 'price', $line ) && ! array_key_exists( 'total', $line ), 'no line carries a fabricated amount' ); }

/* One request, ONE initial draft: repeated processing, double activation and
 * re-runs with different data never rewrite or duplicate; a new legitimate
 * request with identical content stays independent. */
$row_before = $GLOBALS['fpwd_table']['fpw_draft_68'];
check( fpw_create_request_draft( $order68 ) === false, 'a second activation of the same receipt creates no second draft' );
check( $GLOBALS['fpwd_table']['fpw_draft_68'] === $row_before, 'the stored draft is never rewritten' );
$order68b = new FPWD_Order( 68, array( new FPWD_Item( 'Otra cosa', 1, 1, 0 ) ), array( '_fpw_attempt' => str_repeat( 'cd', 32 ) ) );
check( fpw_create_request_draft( $order68b ) === false && $GLOBALS['fpwd_table']['fpw_draft_68'] === $row_before, 'a re-run with different data changes nothing: the initial draft stands' );
$order91 = new FPWD_Order( 91, $order68->items, array( '_fpw_attempt' => str_repeat( 'cd', 32 ) ) );
$GLOBALS['fpwd_orders'][91] = $order91;
check( fpw_create_request_draft( $order91 ) === true, 'a new legitimate request creates its own draft' );
$payload91 = fpw_read_request_draft( 91 );
check( $payload91['order_id'] === 91 && $payload91['reference'] === 'FP-2026-000091' && $payload91['attempt'] === str_repeat( 'cd', 32 ), 'the identical-content request is an independent draft with its own identity' );
check( $payload91['items'] == $payload['items'], 'content may repeat across requests without folding them' );

/* Only requests get drafts: a record that never went through the checkout
 * receipt carries none, and the screen will say so honestly. */
$manual = new FPWD_Order( 77, array( new FPWD_Item( 'X', 1, 1, 0 ) ), array( '_fp_request' => '' ) );
check( fpw_create_request_draft( $manual ) === false, 'a non-request record gets no draft' );
check( ! isset( $GLOBALS['fpwd_table']['fpw_draft_77'] ), 'no draft row exists for it' );

/* A snapshot failure never fatals the checkout and never leaves a partial draft. */
class FPWD_Broken_Order extends FPWD_Order {
	public function get_items(): array { throw new RuntimeException( 'storage hiccup' ); }
}
$broken = new FPWD_Broken_Order( 78, array() );
check( fpw_create_request_draft( $broken ) === false, 'a snapshot failure reports cleanly, without breaking the receipt' );
check( ! isset( $GLOBALS['fpwd_table']['fpw_draft_78'] ), 'no half-built draft row remains' );

/* The private screen: owner-readable, mobile-first, honest about pendings. */
$GLOBALS['fpwd_caps'] = array( 'manage_woocommerce' => true );
$html = fpw_quote_draft_markup( $order68, $payload );
check( str_contains( $html, 'FP-2026-000068' ), 'the reference renders from the draft' );
check( str_contains( $html, 'Caja Cosechera 3/4' ) && str_contains( $html, '140' ), 'the simple line renders with its stored quantity' );
check( str_contains( $html, 'Caja Universal Cerrada Color' ) && str_contains( $html, '25' ), 'the variant line renders with its stored quantity' );
check( str_contains( $html, 'Color: Azul' ), 'the chosen option renders through the native label' );
check( ! str_contains( $html, 'pa_color' ) && ! str_contains( $html, '_reduced_stock' ), 'raw keys and internal meta never render' );
check( substr_count( $html, 'Pendiente' ) >= 3, 'prices, history and dispatch estimate read as pending' );
check( ! str_contains( $html, '$' ), 'no price amounts anywhere on the draft' );
check( ! str_contains( $html, 'Sin historial' ), 'missing history is never presented as a customer verdict' );
check( str_contains( $html, 'cotización emitida' ), 'the draft states it is not an issued quotation' );
check( str_contains( $html, 'Camino de prueba 123, Mostazal' ), 'the stored destination renders' );
check( str_contains( $html, 'compras@prueba.invalid' ), 'the stored identity renders' );
check( str_contains( $html, 'Datos originales recibidos' ), 'Submitted Details stay available beside the draft' );
check( str_contains( $html, 'post.php?post=68' ), 'the native request record stays reachable' );
check( str_contains( $html, '@media (min-width: 782px)' ), 'the layout is authored mobile-first with a desktop enhancement' );

/* A request without a draft (creation failure or legacy record) reads honestly:
 * the screen invents nothing. */
$htmlNone = fpw_quote_draft_markup( $order68, null );
check( str_contains( $htmlNone, 'Sin borrador' ), 'a request without a draft says so honestly' );
check( ! str_contains( $htmlNone, 'Caja Cosechera' ), 'no fabricated lines without the receipt snapshot' );
$htmlUnknown = fpw_quote_draft_markup( null, null );
check( str_contains( $htmlUnknown, 'no encontrada' ), 'an unknown request id answers an honest empty state' );
check( ! str_contains( $htmlUnknown, 'FP-' ), 'no reference is invented without a record' );

/* The authorization boundary: ventas' exact four approved caps read the native
 * record but never this draft — and the denial is attributable to permissions. */
$GLOBALS['fpwd_caps'] = array( 'read' => true, 'manage_freeplast_quotes' => true, 'edit_shop_orders' => true, 'edit_others_shop_orders' => true );
$_GET = array( 'request' => '68' );
try {
	ob_start(); fpw_render_quote_draft_screen(); ob_end_clean();
	check( false, 'a valid ventas session must be denied the private draft' );
} catch ( FPWD_Die $e ) {
	check( $e->getMessage() === '403', 'ventas receives the 403 permission denial, not a CSRF complaint' );
}
$GLOBALS['fpwd_caps'] = array( 'manage_woocommerce' => true );
ob_start(); fpw_render_quote_draft_screen(); $page = ob_get_clean();
check( str_contains( $page, 'FP-2026-000068' ), 'the owner session reads the draft screen' );
$_GET = array( 'request' => '99999999' );
ob_start(); fpw_render_quote_draft_screen(); $page = ob_get_clean();
check( str_contains( $page, 'no encontrada' ), 'a bogus request id stays an honest empty state for the owner' );
$_GET = array( 'request' => 'xx' );
ob_start(); fpw_render_quote_draft_screen(); $page = ob_get_clean();
check( str_contains( $page, 'no encontrada' ), 'a malformed request id answers the same honest state' );

/* The owner notice: the EXISTING extension email gains the draft link for the
 * admin; the buyer acknowledgement stays exactly that — no link, no draft. */
function fpwd_render_request_email( $order, bool $sent_to_admin ): string {
	$email_heading = 'Solicitud de cotización recibida';
	$plain_text = false;
	$email = null;
	ob_start();
	require __DIR__ . '/../wp-content/plugins/freeplast-woo/request-email.php';
	return (string) ob_get_clean();
}
function wc_display_item_meta( $item ): void { echo '<p class="variation">Color: Azul</p>'; }
$GLOBALS['fpwd_caps'] = array( 'manage_woocommerce' => true );
$admin_mail = fpwd_render_request_email( $order68, true );
check( str_contains( $admin_mail, 'page=fpw-quote-draft' ) && str_contains( $admin_mail, 'request=68' ), 'the existing owner email points at the corresponding draft' );
check( str_contains( $admin_mail, 'Abrir borrador privado' ), 'the owner notice carries the draft call to action' );
check( str_contains( $admin_mail, 'sesión' ), 'the notice states the link requires an authorized session' );
check( str_contains( $admin_mail, '140' ) && str_contains( $admin_mail, '25' ), 'the notice keeps the requested quantities' );
check( ! str_contains( $admin_mail, '$' ), 'the notice carries no prices' );
$customer_mail = fpwd_render_request_email( $order68, false );
check( ! str_contains( $customer_mail, 'fpw-quote-draft' ) && ! str_contains( $customer_mail, 'Borrador' ), 'the buyer acknowledgement carries no private link and no draft' );
check( str_contains( $customer_mail, 'no constituye una compra' ), 'the acknowledgement keeps its own no-purchase wording, distinct from an issued quotation' );

echo "quote draft: $assertions offline checks passed (issue #50)\n";
