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
/* Configuration seams (Places, tax policy, default validity, distance credentials) deliver through filters; run the registered callbacks like the real thing, extra arguments passed through (issue #60). */
/* Issue #60: the distance section reads its configuration through apply_filters. */
function apply_filters( $tag, $value, ...$args ) { global $registered_filters; foreach ( $registered_filters[ $tag ] ?? array() as $callback ) { $value = $callback( $value, ...$args ); } return $value; }
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
/** Whether any list element carries the needle — for faltantes lists. */
function array_contains_string( array $haystack, string $needle ): bool {
	foreach ( $haystack as $item ) { if ( str_contains( (string) $item, $needle ) ) { return true; } }
	return false;
}
/** The rendered preview section of a draft screen, isolated for exclusion checks. */
function fpwd_draft_preview_section_of( string $html ): string {
	$start = strpos( $html, '<section class="fpw-draft__preview">' );
	if ( false === $start ) { return ''; }
	$end = strpos( $html, '</section>', $start );
	return false === $end ? '' : substr( $html, $start, $end - $start + 10 );
}

/* --- WordPress runtime stubs the feature needs beyond plugin load --- */
class FPWD_Die extends RuntimeException {}
function wp_die( $message = '', $title = '', $args = array() ) { throw new FPWD_Die( (string) ( $args['response'] ?? 0 ) ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_url( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_textarea( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function get_current_user_id(): int { return 1; }
/* Issue #51: the save action carries and verifies a nonce in-server. */
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
		if ( preg_match( "/^UPDATE \{?\w*options\}? SET option_value = '(.*)' WHERE option_name = '(.+?)' AND option_value = '(.*)'$/s", $sql, $m ) ) {
			if ( ! array_key_exists( $m[2], $GLOBALS['fpwd_table'] ) || $GLOBALS['fpwd_table'][ $m[2] ] !== $m[3] ) { return 0; }
			$GLOBALS['fpwd_table'][ $m[2] ] = $m[1]; return 1;
		}
		if ( preg_match( "/^UPDATE \{?\w*options\}? SET option_value = '(.*)' WHERE option_name = '(.+?)'$/s", $sql, $m ) ) {
			if ( ! array_key_exists( $m[2], $GLOBALS['fpwd_table'] ) ) { return 0; }
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
check( $payload['destination'] === array( 'dispatch' => 'si', 'address' => 'Camino de prueba 123, Mostazal', 'source' => '', 'place_id' => '', 'scope' => '' ), 'the destination comes from the record; a record without provenance carries no invented one (issue #59)' );
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
check( substr_count( $html, 'Pendiente' ) >= 3, 'prices and the dispatch estimate read as pending; history carries its own live state (issue #54)' );
check( ! str_contains( $html, '$' ), 'no price amounts anywhere on the draft' );
check( str_contains( $html, 'Sin historial asociado' ) && ! str_contains( $html, 'Cliente nuevo' ), 'with the importer live (issue #54), no matched history reads as unresolved — never as a customer verdict' );
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

/* ===== Issue #51 — corte 2 de #49: completar y ajustar el borrador manualmente =====
 * The owner completes net CLP prices, quantities, a working destination and the
 * dispatch amount on the same private screen; saves survive revisits; a missing
 * amount stays pending (never zero); stale or concurrent saves are detected
 * without silently overwriting newer work; saving never approves or sends. */

/* A request without dispatch gets its own draft. */
$order92 = new FPWD_Order( 92, array( new FPWD_Item( 'Caja Cosechera 3/4', 10, 22, 0 ) ), array( '_billing_fp_dispatch' => 'no', '_billing_fp_address' => '' ) );
$GLOBALS['fpwd_orders'][92] = $order92;
check( fpw_create_request_draft( $order92 ) === true, 'the no-dispatch request creates its draft' );
$payload92 = fpw_read_request_draft( 92 );

/* Fresh state: nothing is saved before the owner saves it. */
check( fpw_read_draft_work( 68 ) === null, 'no working state exists before the owner saves one' );

/* The private screen gains the editing form: revision 0, nonce, per-line
 * working quantity and net price inputs, working destination and dispatch. */
$GLOBALS['fpwd_caps'] = array( 'manage_woocommerce' => true );
$html = fpw_quote_draft_markup( $order68, $payload );
check( str_contains( $html, '<form method="post" action="' ) && str_contains( $html, 'page=fpw-quote-draft' ) && str_contains( $html, 'request=68' ), 'the editing form posts to the private screen of its own request' );
check( str_contains( $html, 'name="fpw_work_revision" value="0"' ), 'the form carries the revision it was rendered from (0 before any save)' );
check( str_contains( $html, 'name="fpw_draft_nonce"' ), 'the save action carries its CSRF nonce' );
check( str_contains( $html, 'name="fpw_work[lines][0][quantity]"' ) && str_contains( $html, 'name="fpw_work[lines][0][price]"' ), 'every line offers its working quantity and net price inputs' );
check( str_contains( $html, 'name="fpw_work[destination]"' ) && str_contains( $html, 'name="fpw_work[dispatch_amount]"' ), 'the working destination and the dispatch amount are editable' );
check( substr_count( $html, 'placeholder="Pendiente"' ) >= 2, 'unset amounts read as pending placeholders, never as zeros' );
check( str_contains( $html, 'Guardar cambios del borrador' ) && str_contains( $html, 'no aprueba ni envía' ), 'saving states it never approves nor sends an offer' );
check( str_contains( $html, 'afecta solo a este borrador' ), 'the form states manual adjustments affect only this draft, never a general list' );
check( str_contains( $html, 'Pedido: 140 unidades' ), 'the originally requested quantity stays beside the working one' );

/* Real edit → save → reopen: every chosen value is recovered. */
$save1 = fpw_save_draft_work( 68, $payload, array(
	'fpw_work_revision' => '0',
	'fpw_work' => array(
		'lines' => array(
			array( 'quantity' => '120', 'price' => '1490' ),
			array( 'quantity' => '25', 'price' => '' ),
		),
		'destination' => 'Camino de trabajo 456, Mostazal',
		'dispatch_amount' => '39990',
	),
), 1 );
check( ( $save1['state'] ?? '' ) === 'saved', 'a valid save is accepted' );
$work1 = fpw_read_draft_work( 68 );
check( is_array( $work1 ) && 1 === (int) $work1['revision'] && 68 === (int) $work1['order_id'], 'the first save stores revision 1 bound to its request' );
check( $work1['lines'][0] === array( 'index' => 0, 'product_id' => 22, 'variation_id' => 0, 'quantity' => 120, 'price' => 1490, 'price_source' => 'manual' ), 'line values are stored normalized with their manual origin' );
check( null === $work1['lines'][1]['price'] && 'pending' === $work1['lines'][1]['price_source'], 'a left-empty price stays pending, never zero' );
check( 25 === (int) $work1['lines'][1]['quantity'], 'the untouched quantity persists as chosen' );
check( 'Camino de trabajo 456, Mostazal' === $work1['destination'] && 39990 === (int) $work1['dispatch_amount'], 'the working destination and the dispatch amount persist' );
check( $work1['dispatch_conditions'] === array( 'destination' => 'Camino de trabajo 456, Mostazal', 'quantities' => array( 120, 25 ) ), 'the dispatch amount records the destination and quantities it was entered for' );
check( fpw_draft_dispatch_stale( $work1 ) === false, 'a dispatch amount matching its conditions is not stale' );

$html = fpw_quote_draft_markup( $order68, $payload, $work1 );
check( str_contains( $html, 'name="fpw_work_revision" value="1"' ), 'the form re-renders from the saved revision' );
check( str_contains( $html, 'value="120"' ) && str_contains( $html, 'value="1490"' ), 'the saved quantity and price recover into the form' );
check( str_contains( $html, '1.490 CLP neto' ) && str_contains( $html, 'ingreso manual' ), 'the saved price renders as a net CLP amount with its manual origin' );
check( str_contains( $html, 'Camino de trabajo 456, Mostazal' ) && str_contains( $html, '39.990 CLP' ), 'the saved working destination and dispatch amount recover' );
check( ! str_contains( $html, 'value="0"' ) && str_contains( $html, '[1][price]" value="" placeholder="Pendiente"' ) && ! preg_match( '/[^.\d]0 CLP/', $html ), 'no pending amount ever reads as zero' );
check( str_contains( $html, 'revisión 1' ), 'the screen names the saved revision the owner is retaking' );
check( str_contains( $html, 'Ingresada manualmente' ), 'the dispatch estimate state reads as manually entered' );
check( ! str_contains( $html, 'Ingresados manualmente por el dueño' ), 'prices with a pending line still read as pending in the status' );
check( str_contains( $html, 'Pedido: 140 unidades' ), 'the original request stays separate from the working values' );

/* Destination or quantity changes after an amount was saved put it under review. */
$save2 = fpw_save_draft_work( 68, $payload, array(
	'fpw_work_revision' => '1',
	'fpw_work' => array(
		'lines' => array(
			array( 'quantity' => '120', 'price' => '1490' ),
			array( 'quantity' => '25', 'price' => '' ),
		),
		'destination' => 'Bodega destino 789, Rancagua',
		'dispatch_amount' => '39990',
	),
), 1 );
check( ( $save2['state'] ?? '' ) === 'saved' && 2 === (int) $save2['work']['revision'], 'the second save stores revision 2' );
check( fpw_draft_dispatch_stale( $save2['work'] ) === true, 'changing the working destination marks the saved dispatch amount for review' );
check( str_contains( fpw_quote_draft_markup( $order68, $payload, $save2['work'] ), 'Requiere revisión' ), 'the screen marks the stale dispatch amount' );
$save3 = fpw_save_draft_work( 68, $payload, array(
	'fpw_work_revision' => '2',
	'fpw_work' => array(
		'lines' => array(
			array( 'quantity' => '100', 'price' => '1490' ),
			array( 'quantity' => '25', 'price' => '' ),
		),
		'destination' => 'Bodega destino 789, Rancagua',
		'dispatch_amount' => '39990',
	),
), 1 );
check( ( $save3['state'] ?? '' ) === 'saved' && fpw_draft_dispatch_stale( $save3['work'] ) === true, 'a quantity change keeps the dispatch amount under review' );

/* Every priced line completes the prices state. */
$save4 = fpw_save_draft_work( 68, $payload, array(
	'fpw_work_revision' => '3',
	'fpw_work' => array(
		'lines' => array(
			array( 'quantity' => '100', 'price' => '1490' ),
			array( 'quantity' => '25', 'price' => '2190' ),
		),
		'destination' => 'Bodega destino 789, Rancagua',
		'dispatch_amount' => '39990',
	),
), 1 );
check( ( $save4['state'] ?? '' ) === 'saved' && str_contains( fpw_quote_draft_markup( $order68, $payload, $save4['work'] ), 'Ingresados manualmente por el dueño' ), 'with every line priced, the status names the manual completion' );
$work68 = fpw_read_draft_work( 68 );

/* Stale and concurrent saves: the accepted edit is preserved, never overwritten. */
$stale = fpw_save_draft_work( 68, $payload, array(
	'fpw_work_revision' => '0',
	'fpw_work' => array(
		'lines' => array( array( 'quantity' => '9', 'price' => '1' ), array( 'quantity' => '9', 'price' => '9' ) ),
		'destination' => 'Sobrescritura',
		'dispatch_amount' => '1',
	),
), 1 );
check( ( $stale['state'] ?? '' ) === 'conflict', 'a stale revision is detected instead of silently overwriting' );
check( fpw_read_draft_work( 68 ) === $work68, 'the accepted edit is preserved; the stale submission changed nothing' );
/* A double click submits the same rendered form twice: the first write lands,
 * the retried submission of the SAME revision now conflicts. */
$double_body = array(
	'fpw_work_revision' => '4',
	'fpw_work' => array(
		'lines' => array( array( 'quantity' => '100', 'price' => '1490' ), array( 'quantity' => '25', 'price' => '2190' ) ),
		'destination' => 'Bodega destino 789, Rancagua',
		'dispatch_amount' => '39990',
	),
);
$double_first = fpw_save_draft_work( 68, $payload, $double_body, 1 );
check( ( $double_first['state'] ?? '' ) === 'saved' && 5 === (int) $double_first['work']['revision'], 'the first submission of a rendered form saves its revision' );
$double = fpw_save_draft_work( 68, $payload, $double_body, 1 );
check( ( $double['state'] ?? '' ) === 'conflict', 'the retried submission of the same revision (double click) cannot write twice' );
$work68 = fpw_read_draft_work( 68 );
$raw68 = fpw_read_draft_work_raw( 68 );
check( fpw_cas_draft_work_row( 68, $raw68 . '-touched', array( 'revision' => 999 ) ) === false, 'a compare-and-set against a diverged previous value loses' );
check( fpw_read_draft_work( 68 ) === $work68, 'the losing compare-and-set changed nothing' );

/* Server-side validation: no zero price, no junk amounts, sane quantities. */
$zero = fpw_save_draft_work( 68, $payload, array(
	'fpw_work_revision' => '5',
	'fpw_work' => array(
		'lines' => array( array( 'quantity' => '100', 'price' => '0' ), array( 'quantity' => '25', 'price' => '2190' ) ),
		'destination' => 'Bodega destino 789, Rancagua',
		'dispatch_amount' => '39990',
	),
), 1 );
check( ( $zero['state'] ?? '' ) === 'invalid' && 1 === count( $zero['errors'] ), 'a zero price is refused: no unapproved gratuity policy' );
check( str_contains( $zero['errors'][0] ?? '', 'deja el campo vacío' ), 'the zero-price error points at leaving the value pending' );
foreach (
	array(
		'negative price' => array( 'price' => '-5' ),
		'non-numeric price' => array( 'price' => 'abc' ),
		'zero quantity' => array( 'quantity' => '0' ),
		'junk quantity' => array( 'quantity' => '2x' ),
		'oversized price' => array( 'price' => '100000000' ),
	) as $case => $field
) {
	$lines = array(
		array( 'quantity' => '100', 'price' => '1490' ),
		array( 'quantity' => '25', 'price' => '2190' ),
	);
	$lines[0] = array_merge( $lines[0], $field );
	$bad = fpw_save_draft_work( 68, $payload, array( 'fpw_work_revision' => '5', 'fpw_work' => array( 'lines' => $lines, 'destination' => 'Bodega destino 789, Rancagua', 'dispatch_amount' => '39990' ) ), 1 );
	check( ( $bad['state'] ?? '' ) === 'invalid' && ! empty( $bad['errors'] ), "the $case is refused server-side" );
}
$long_dest = fpw_save_draft_work( 68, $payload, array(
	'fpw_work_revision' => '5',
	'fpw_work' => array(
		'lines' => array( array( 'quantity' => '100', 'price' => '1490' ), array( 'quantity' => '25', 'price' => '2190' ) ),
		'destination' => str_repeat( 'a', 801 ),
		'dispatch_amount' => '39990',
	),
), 1 );
check( ( $long_dest['state'] ?? '' ) === 'invalid', 'an over-long working destination is refused' );
check( fpw_read_draft_work( 68 ) === $work68, 'every invalid save stored nothing: the accepted edit stands' );

/* Sin despacho is different from dispatch not yet priced: no destination, no
 * freight, nothing to review. */
$save92 = fpw_save_draft_work( 92, $payload92, array(
	'fpw_work_revision' => '0',
	'fpw_work' => array( 'lines' => array( array( 'quantity' => '10', 'price' => '990' ) ), 'destination' => 'Intento de destino', 'dispatch_amount' => '5000' ),
), 1 );
check( ( $save92['state'] ?? '' ) === 'saved' && '' === $save92['work']['destination'] && null === $save92['work']['dispatch_amount'], 'a no-dispatch draft never stores a working destination or a freight amount' );
$html92 = fpw_quote_draft_markup( $order92, $payload92, $save92['work'] );
check( ! str_contains( $html92, 'name="fpw_work[destination]"' ) && ! str_contains( $html92, 'name="fpw_work[dispatch_amount]"' ), 'a no-dispatch draft offers no destination or freight inputs' );
check( str_contains( $html92, 'no incluye destino de entrega ni flete' ), 'the no-dispatch work offer excludes the delivery destination and freight' );
check( str_contains( $html92, 'No requerida (sin despacho)' ), 'the dispatch estimate reads as not required, distinct from pending' );

/* The POST path: authorization first, then CSRF — neither substitutes the other.
 * Every case drives fpw_handle_draft_posted_action() first, exactly as a real request
 * meets admin_init, then the screen callback renders the outcome. */
$_GET = array( 'page' => 'fpw-quote-draft', 'request' => '68' );
$save_post = array(
	'fpw_work_save' => '1',
	'fpw_work_revision' => '5',
	'fpw_draft_nonce' => 'offline-nonce',
	'fpw_work' => array( 'lines' => array( array( 'quantity' => '111', 'price' => '1200' ), array( 'quantity' => '25', 'price' => '990' ) ), 'destination' => 'Destino guardado 1, Mostazal', 'dispatch_amount' => '5000' ),
);
$GLOBALS['fpwd_caps'] = array( 'read' => true, 'manage_freeplast_quotes' => true, 'edit_shop_orders' => true, 'edit_others_shop_orders' => true );
$_POST = $save_post;
try {
	fpw_handle_draft_posted_action();
	ob_start(); fpw_render_quote_draft_screen(); ob_end_clean();
	check( false, 'ventas must be denied the draft save' );
} catch ( FPWD_Die $e ) {
	check( $e->getMessage() === '403', 'ventas receives the 403 permission denial on save, whatever nonce it presents' );
}
check( fpw_read_draft_work( 68 ) === $work68, 'the denied save changed nothing' );
$GLOBALS['fpwd_caps'] = array( 'manage_woocommerce' => true );
$GLOBALS['fpwd_nonce_ok'] = false;
try {
	fpw_handle_draft_posted_action();
	ob_start(); fpw_render_quote_draft_screen(); ob_end_clean();
	check( false, 'a save with an invalid nonce must be refused' );
} catch ( FPWD_Die $e ) {
	check( $e->getMessage() === '403', 'a save failing the CSRF check is refused 403' );
}
check( fpw_read_draft_work( 68 ) === $work68, 'the refused save changed nothing' );
$GLOBALS['fpwd_nonce_ok'] = true;
fpw_handle_draft_posted_action();
ob_start(); fpw_render_quote_draft_screen(); $page = ob_get_clean();
check( str_contains( $page, 'Cambios guardados (revisión 6)' ) && str_contains( $page, 'value="111"' ), 'the authorized save persists and confirms its revision' );
check( str_contains( $page, 'no aprueba ni envía' ), 'even a successful save states it approves nothing' );

/* The conflict journey through the screen: the submission from a stale
 * revision is refused, the accepted edit is shown preserved. */
$stale_post = $save_post;
$stale_post['fpw_work_revision'] = '0';
$stale_post['fpw_work']['destination'] = 'Sobrescritura';
$_POST = $stale_post;
fpw_handle_draft_posted_action();
ob_start(); fpw_render_quote_draft_screen(); $page = ob_get_clean();
check( str_contains( $page, 'no se guardó' ) && str_contains( $page, 'revisión más reciente' ), 'a stale save through the screen gets the conflict message' );
check( str_contains( $page, 'Destino guardado 1, Mostazal' ) && str_contains( $page, 'value="111"' ), 'the conflict screen shows the preserved accepted edit' );
check( ! str_contains( $page, 'Sobrescritura' ), 'the stale submission is not merged in' );
check( str_contains( $page, 'name="fpw_work_revision" value="6"' ), 'the conflict screen re-renders the form from the accepted revision' );

/* A record whose draft never existed offers no editing at all. */
$manual2 = new FPWD_Order( 79, array( new FPWD_Item( 'X', 1, 1, 0 ) ), array( '_fp_request' => '' ) );
$GLOBALS['fpwd_caps'] = array( 'manage_woocommerce' => true );
check( ! str_contains( fpw_quote_draft_markup( $manual2, null ), 'fpw_work' ), 'a record without a draft offers no editing form' );

/* ===== Issue #55 — corte 6 de #49: revisar totales y vigencia antes de aprobar =====
 * ONE shared server-side projection (deterministic integer CLP arithmetic), a
 * fiscal policy that is ABSENT by default (the prototype's 19% is no fiscal
 * approval), a seven-day editable validity, and a stored preview bound to the
 * revision the owner actually reviewed — obsolete after any later commercial
 * save, issuing no version, no PDF and no mail. */

/* Fiscal policy and default validity: configuration seams, absent by default. */
check( fpw_quotation_tax_config() === array(), 'without a delivered configuration there is no fiscal policy: no IVA rate is invented' );
check( fpw_quotation_default_validity_days() === 7, 'the offer validity defaults to seven days' );
$GLOBALS['registered_filters']['fpw_quotation_default_validity_days'] = array( fn() => 'junk' );
check( fpw_quotation_default_validity_days() === 7, 'a malformed default-validity delivery falls back to seven' );
$GLOBALS['registered_filters']['fpw_quotation_default_validity_days'] = array( fn() => 10 );
check( fpw_quotation_default_validity_days() === 10, 'the default validity is configurable through its filter' );
$GLOBALS['registered_filters']['fpw_quotation_default_validity_days'] = array();
$GLOBALS['registered_filters']['fpw_quotation_tax_config'] = array( fn() => array( 'rate_permille' => '19' ) );
check( fpw_quotation_tax_config() === array(), 'a non-integer rate delivery degrades to absent instead of becoming authority' );
$GLOBALS['registered_filters']['fpw_quotation_tax_config'] = array( fn() => array( 'rate_permille' => 99999 ) );
check( fpw_quotation_tax_config() === array(), 'an out-of-range rate delivery degrades to absent' );
$GLOBALS['registered_filters']['fpw_quotation_tax_config'] = array( fn() => array( 'rate_permille' => 0 ) );
check( fpw_quotation_tax_config() === array( 'rate_permille' => 0, 'applies_to_dispatch' => false ), 'a confirmed 0‰ policy is representable and distinct from absent' );
$GLOBALS['registered_filters']['fpw_quotation_tax_config'] = array( fn() => array( 'rate_permille' => 190, 'applies_to_dispatch' => true ) );
check( fpw_quotation_tax_config() === array( 'rate_permille' => 190, 'applies_to_dispatch' => true ), 'a delivered policy is validated and carried with the dispatch treatment' );

/* The deterministic tax kernel: exact integer arithmetic, nearest half-up — never a float. */
check( fpw_quotation_tax_amount( 3195, 190 ) === 607, '3.195 × 19% = 607.05 rounds to 607' );
check( fpw_quotation_tax_amount( 1050, 190 ) === 200, '199.5 rounds half-up to 200' );
check( fpw_quotation_tax_amount( 1058, 190 ) === 201, '201.02 rounds down to 201' );
check( fpw_quotation_tax_amount( 0, 190 ) === 0 && fpw_quotation_tax_amount( 3195, 0 ) === 0, 'a zero base or a confirmed 0‰ policy yields a true zero tax' );
check( fpw_quotation_tax_amount( 99999999 * 1000000, 5000 ) === 499999995000000, 'the kernel stays exact at the accepted input limits (no float, no overflow)' );

/* The shared projection over a completed manual draft: exact deterministic amounts. */
$save91 = fpw_save_draft_work( 91, $payload91, array(
	'fpw_work_revision' => '0',
	'fpw_work' => array(
		'lines' => array(
			array( 'quantity' => '3', 'price' => '335' ),
			array( 'quantity' => '1', 'price' => '2190' ),
		),
		'destination' => 'Destino oferta 12, Mostazal',
		'dispatch_amount' => '1000',
		'validity_days' => '10',
	),
), 1 );
check( ( $save91['state'] ?? '' ) === 'saved', 'the complete manual save for the projection journey is accepted' );
$work91 = fpw_read_draft_work( 91 );
check( 2 === (int) $work91['schema'] && 10 === (int) $work91['validity_days'], 'the working row stores the offer validity (schema 2)' );
$GLOBALS['registered_filters']['fpw_quotation_tax_config'] = array( fn() => array( 'rate_permille' => 190, 'applies_to_dispatch' => false ) );
$projection = fpw_quotation_projection( $payload91, $work91 );
check( $projection['lines'][0] === array( 'index' => 0, 'name' => 'Caja Cosechera 3/4', 'options' => array(), 'quantity' => 3, 'price' => 335, 'line_total' => 1005 ), 'line amounts are the exact integer product of quantity × price' );
check( 2190 === $projection['lines'][1]['line_total'] && 3195 === $projection['subtotal'], 'the subtotal is the exact sum of the line amounts' );
check( $projection['dispatch_requested'] === true && 1000 === $projection['dispatch'], 'the offered dispatch is the saved amount, separate from the subtotal' );
check( 190 === $projection['tax_rate_permille'] && 3195 === $projection['taxable_base'] && 607 === $projection['tax'] && 4802 === $projection['total'], 'IVA and total come from the delivered policy: 3.195 + 1.000 + 607 = 4.802' );
check( is_int( $projection['subtotal'] ) && is_int( $projection['tax'] ) && is_int( $projection['total'] ), 'every projected amount is an integer, never a float' );
check( $projection['destination'] === 'Destino oferta 12, Mostazal', 'the projection carries the working destination' );
check( 10 === $projection['validity_days'] && $projection['complete'] === true && array() === $projection['missing'], 'a fully completed draft with a delivered policy projects a complete offer' );
$GLOBALS['registered_filters']['fpw_quotation_tax_config'] = array( fn() => array( 'rate_permille' => 190, 'applies_to_dispatch' => true ) );
$projected_with_dispatch_tax = fpw_quotation_projection( $payload91, $work91 );
check( 4195 === $projected_with_dispatch_tax['taxable_base'] && 797 === $projected_with_dispatch_tax['tax'] && 4992 === $projected_with_dispatch_tax['total'], 'the delivered fiscal treatment decides whether dispatch joins the taxable base' );
$GLOBALS['registered_filters']['fpw_quotation_tax_config'] = array();
$projected_no_policy = fpw_quotation_projection( $payload91, $work91 );
check( null === $projected_no_policy['tax'] && null === $projected_no_policy['total'] && 3195 === $projected_no_policy['subtotal'], 'without a confirmed policy the IVA and total stay pending while the net amounts stand' );
check( array_contains_string( $projected_no_policy['missing'], 'política fiscal' ) && $projected_no_policy['complete'] === false, 'the missing policy is an explicit faltante that blocks the complete offer' );

/* Faltantes are named and distinguished from zero — history and Maps never block. */
$projected_fresh = fpw_quotation_projection( $payload91, null );
check( 2 === count( preg_grep( '/Falta el precio neto/', $projected_fresh['missing'] ) ), 'each unpriced line is a named faltante, never a zero' );
check( array_contains_string( $projected_fresh['missing'], 'monto de despacho' ), 'a requested dispatch without an amount is a named faltante' );
check( null === $projected_fresh['subtotal'] && null === $projected_fresh['dispatch'] && $projected_fresh['validity_days'] === 7, 'an untouched working state projects pendings with the default validity' );
$stale_work = $work91;
$stale_work['destination'] = 'Otro destino 9, Colchane';
$projected_stale = fpw_quotation_projection( $payload91, $stale_work );
check( array_contains_string( $projected_stale['missing'], 'requiere revisión' ) && null === $projected_stale['dispatch'] && null === $projected_stale['total'], 'a dispatch amount entered for other conditions is a faltante, not an offer' );
$no_destination = $work91;
$no_destination['destination'] = '';
$projected_no_dest = fpw_quotation_projection( $payload91, $no_destination );
check( array_contains_string( $projected_no_dest['missing'], 'destino de entrega' ), 'a dispatch offer without a working destination is blocked' );
$GLOBALS['registered_filters']['fpw_quotation_tax_config'] = array( fn() => array( 'rate_permille' => 190, 'applies_to_dispatch' => false ) );
$projected92 = fpw_quotation_projection( $payload92, fpw_read_draft_work( 92 ) );
check( $projected92['dispatch_requested'] === false && null === $projected92['dispatch'] && $projected92['complete'] === true, 'a no-dispatch draft with every line priced projects a complete offer without freight' );
check( ! array_contains_string( $projected92['missing'], 'despacho' ) && ! array_contains_string( $projected92['missing'], 'historial' ), 'missing history never blocks, and a no-dispatch offer owes no freight faltante' );
$GLOBALS['registered_filters']['fpw_quotation_tax_config'] = array();

/* The preview action: ONE stored projection bound to the revision reviewed. */
$GLOBALS['registered_filters']['fpw_quotation_tax_config'] = array( fn() => array( 'rate_permille' => 190, 'applies_to_dispatch' => false ) );
$_GET = array( 'page' => 'fpw-quote-draft', 'request' => '91' );
$GLOBALS['fpwd_caps'] = array( 'manage_woocommerce' => true );
$_POST = array( 'fpw_work_preview' => '1', 'fpw_preview_nonce' => 'offline-nonce' );
fpw_handle_draft_posted_action();
ob_start(); fpw_render_quote_draft_screen(); $page = ob_get_clean();
check( str_contains( $page, 'Vista previa generada (revisión 1)' ), 'an authorized preview confirms the revision it reviewed' );
check( str_contains( $page, 'no aprueba ni envía nada al comprador' ), 'previewing states it issues nothing to the buyer' );
$stored = fpw_read_draft_preview( 91 );
check( is_array( $stored ) && 1 === (int) $stored['revision'] && 1 === (int) $stored['created_by'], 'the stored preview is bound to the work revision the owner reviewed' );
check( $stored['projection'] === fpw_quotation_projection( $payload91, fpw_read_draft_work( 91 ) ), 'the stored preview IS the shared projection: no parallel screen math' );
$preview_section = fpwd_draft_preview_section_of( $page );
check( str_contains( $preview_section, 'Vista previa para el comprador' ) && str_contains( $preview_section, 'revisión 1' ), 'the screen renders the stored buyer-facing preview' );
check( str_contains( $preview_section, 'Subtotal (neto)' ) && str_contains( $preview_section, '3.195 CLP' ) && str_contains( $preview_section, '1.000 CLP' ) && str_contains( $preview_section, '607 CLP' ) && str_contains( $preview_section, '4.802 CLP' ), 'the preview shows subtotal, dispatch, IVA and total as exact amounts' );
check( str_contains( $preview_section, 'IVA (19%)' ), 'the IVA row names the confirmed rate it applied' );
check( str_contains( $preview_section, 'Vigencia de la oferta: 10 días' ) && str_contains( $preview_section, 'a contar de su aprobación' ), 'the preview expresses the validity as days from approval, without invented deadlines' );
check( str_contains( $preview_section, 'no incluye historial de compras' ) && ! str_contains( $preview_section, 'Historial de compras' ), 'the preview excludes purchase history, internal notes and the estimate breakdown' );
check( str_contains( $preview_section, 'Destino de la oferta: Destino oferta 12, Mostazal' ), 'the preview names the working destination the offer quotes' );
check( str_contains( $preview_section, 'name="fpw_work_preview"' ) && str_contains( $preview_section, 'name="fpw_preview_nonce"' ), 'the preview regeneration carries its own action and nonce' );

/* A later commercial save obsoletes the reviewed preview — frozen, never recomputed. */
$raw_preview_before = $GLOBALS['fpwd_table']['fpw_draft_preview_91'];
$save91b = fpw_save_draft_work( 91, $payload91, array(
	'fpw_work_revision' => '1',
	'fpw_work' => array(
		'lines' => array(
			array( 'quantity' => '3', 'price' => '340' ),
			array( 'quantity' => '1', 'price' => '2190' ),
		),
		'destination' => 'Destino oferta 12, Mostazal',
		'dispatch_amount' => '1000',
		'validity_days' => '10',
	),
), 1 );
check( ( $save91b['state'] ?? '' ) === 'saved', 'the later commercial save lands (revision 2)' );
check( $GLOBALS['fpwd_table']['fpw_draft_preview_91'] === $raw_preview_before, 'the reviewed preview is frozen: the later save never rewrites what was seen' );
$html = fpw_quote_draft_markup( $order91, $payload91, $save91b['work'] );
check( str_contains( $html, 'Vista previa obsoleta' ) && str_contains( $html, 'Ninguna aprobación futura puede usar esta vista previa' ), 'the screen marks the reviewed preview obsolete after the commercial change' );
check( str_contains( $html, 'esta revisó la 1' ) && str_contains( $html, 'revisión 2' ), 'the obsolete marker names both revisions honestly' );
check( str_contains( $html, '3.195 CLP' ), 'the frozen preview still shows exactly the amounts that were reviewed' );
check( str_contains( $html, 'Obsoleta — el trabajo cambió después de la revisión 1' ), 'the aside names the revision the stale preview reviewed' );
$GLOBALS['registered_filters']['fpw_quotation_tax_config'] = array( fn() => array( 'rate_permille' => 190, 'applies_to_dispatch' => false ) );
$_POST = array( 'fpw_work_preview' => '1', 'fpw_preview_nonce' => 'offline-nonce' );
fpw_handle_draft_posted_action();
ob_start(); fpw_render_quote_draft_screen(); $page = ob_get_clean();
check( str_contains( $page, 'Vista previa generada (revisión 2)' ), 'a new preview re-binds to the new revision' );
$stored = fpw_read_draft_preview( 91 );
check( 2 === (int) $stored['revision'] && 1020 === $stored['projection']['lines'][0]['line_total'] && 4820 === $stored['projection']['total'], 'the replacement preview carries the newly reviewed amounts (3.210 + 1.000 + 610 = 4.820)' );
$html = fpw_quote_draft_markup( $order91, $payload91, fpw_read_draft_work( 91 ) );
check( ! str_contains( $html, 'Vista previa obsoleta' ), 'the regenerated preview is current again' );

/* Preview boundaries: permission first, then CSRF — and nothing is ever issued. */
$GLOBALS['fpwd_caps'] = array( 'read' => true, 'manage_freeplast_quotes' => true, 'edit_shop_orders' => true, 'edit_others_shop_orders' => true );
$_POST = array( 'fpw_work_preview' => '1', 'fpw_preview_nonce' => 'offline-nonce' );
try {
	fpw_handle_draft_posted_action();
	check( false, 'ventas must be denied the preview generation' );
} catch ( FPWD_Die $e ) {
	check( $e->getMessage() === '403', 'a valid ventas session with a valid preview nonce is denied as a permission' );
}
$GLOBALS['fpwd_caps'] = array( 'manage_woocommerce' => true );
$GLOBALS['fpwd_nonce_ok'] = false;
try {
	fpw_handle_draft_posted_action();
	check( false, 'a preview with an invalid nonce must be refused' );
} catch ( FPWD_Die $e ) {
	check( $e->getMessage() === '403', 'a preview failing the CSRF check is refused 403' );
}
$GLOBALS['fpwd_nonce_ok'] = true;
$stored_after = fpw_read_draft_preview( 91 );
check( $stored_after === $stored, 'the denied previews changed nothing' );
$GLOBALS['registered_filters']['fpw_quotation_tax_config'] = array();
foreach ( array_keys( $GLOBALS['fpwd_table'] ) as $row_name ) {
	check( ! str_contains( $row_name, 'version' ) && ! str_contains( $row_name, 'pdf' ), "saving and previewing issue no approved version or document row ($row_name)" );
}

/* The validity field: editable in the draft, bounded, defaulting when empty. */
$html = fpw_quote_draft_markup( $order91, $payload91, fpw_read_draft_work( 91 ) );
check( str_contains( $html, 'name="fpw_work[validity_days]"' ) && str_contains( $html, 'value="10"' ), 'the saved validity recovers into the editing form' );
$bad_validity = fpw_save_draft_work( 91, $payload91, array(
	'fpw_work_revision' => '2',
	'fpw_work' => array(
		'lines' => array( array( 'quantity' => '3', 'price' => '340' ), array( 'quantity' => '1', 'price' => '2190' ) ),
		'destination' => 'Destino oferta 12, Mostazal',
		'dispatch_amount' => '1000',
		'validity_days' => '366',
	),
), 1 );
check( ( $bad_validity['state'] ?? '' ) === 'invalid' && array_contains_string( $bad_validity['errors'], 'vigencia' ), 'an out-of-range validity refuses the whole save, like any other field' );
foreach ( array( '0', '-3', 'x', '1000' ) as $bad_days ) {
	check( 'invalid' === fpw_parse_draft_validity( $bad_days ), "a validity of '$bad_days' is refused" );
}
check( null === fpw_parse_draft_validity( '' ) && 30 === fpw_parse_draft_validity( '30' ), 'an empty validity takes the default; a valid one parses' );
$empty_validity = fpw_save_draft_work( 91, $payload91, array(
	'fpw_work_revision' => '2',
	'fpw_work' => array(
		'lines' => array( array( 'quantity' => '3', 'price' => '340' ), array( 'quantity' => '1', 'price' => '2190' ) ),
		'destination' => 'Destino oferta 12, Mostazal',
		'dispatch_amount' => '1000',
		'validity_days' => '',
	),
), 1 );
check( ( $empty_validity['state'] ?? '' ) === 'saved' && null === $empty_validity['work']['validity_days'], 'a left-empty validity saves as the default' );
$work91 = fpw_read_draft_work( 91 );
$GLOBALS['registered_filters']['fpw_quotation_default_validity_days'] = array( fn() => 10 );
check( 3 === (int) $work91['revision'] && 10 === fpw_draft_validity_days( null ) && 10 === fpw_quotation_projection( $payload91, $work91 )['validity_days'], 'the effective validity falls back to the configured default (10 by filter here)' );
$GLOBALS['registered_filters']['fpw_quotation_default_validity_days'] = array();
check( 7 === fpw_quotation_projection( $payload91, $work91 )['validity_days'], 'with the filter removed the default returns to seven days' );

/* The status board and the honest pendings on a fresh draft. */
$html = fpw_quote_draft_markup( $order91, $payload91, null );
check( substr_count( $html, 'Pendiente' ) >= 4, 'a fresh draft reads prices, dispatch, preview and history as pending — never zero' );
check( ! preg_match( '/[^.\d]0 CLP/', $html ), 'no pending amount ever renders as 0 CLP, preview included' );
check( str_contains( $html, 'Vigencia de la oferta</dt><dd>7 días' ), 'the status board shows the effective validity' );
check( str_contains( $html, 'Vista previa' ) && str_contains( $html, 'placeholder="7 (por defecto)"' ), 'the validity input names its default without inventing a value' );

echo "quote draft: $assertions offline checks passed (issues #50 + #51 + #55)\n";
