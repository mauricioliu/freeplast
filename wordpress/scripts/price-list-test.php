<?php
/** Issue #52 — corte 3 de #49: mantener precios privados y prellenar borradores.
 * Offline contract of the private Price List (Mantenedor de precios): one
 * private options row keyed by NATIVE product/variation identity (WordPress is
 * the authority — never a spreadsheet, never the last historical sale price),
 * direct editing on an owner-only unlisted screen (Ventas' four caps and
 * visitors never open it; a nonce grants nothing), strict CLP validation with
 * all-or-nothing saves, zero product-data mutation (the catalog's technical
 * zero prices are never touched nor published), and the draft contract: new
 * drafts prefill available suggestions (distinct per option of the same
 * product) while missing values stay pending; the saved distinction between a
 * suggested price and the owner's chosen one survives; changing the list never
 * rewrites saved work and the explicit refresh adopts new suggestions without
 * stepping on manual adjustments. */
define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
$registered_actions = array();
$registered_filters = array();
function add_action( ...$args ) { global $registered_actions; $registered_actions[ $args[0] ][] = $args[1] ?? null; }
function add_filter( ...$args ) { global $registered_filters; $registered_filters[ $args[0] ][] = $args[1] ?? null; }
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
class FPPW_Die extends RuntimeException {}
function wp_die( $message = '', $title = '', $args = array() ) { throw new FPPW_Die( (string) ( $args['response'] ?? 0 ) ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_url( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_textarea( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function get_current_user_id(): int { return 1; }
$GLOBALS['fppw_nonce_ok'] = true;
function wp_create_nonce( $action = -1 ) { return 'offline-nonce'; }
function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ) {
	$html = '<input type="hidden" name="' . esc_attr( (string) $name ) . '" value="offline-nonce" />';
	if ( $echo ) { echo $html; }
	return $html;
}
function wp_verify_nonce( $nonce, $action = -1 ) { return $GLOBALS['fppw_nonce_ok'] && 'offline-nonce' === (string) $nonce ? 1 : false; }
function admin_url( $path = '' ) { return 'https://freeplast.test/wp-admin/' . $path; }
function date_i18n( $format, $timestamp ) { return gmdate( 'Y-m-d', (int) $timestamp ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_unslash( $value ) { return $value; }
function do_action( ...$args ): void {}
function apply_filters( $tag, $value ) { return $value; }
$GLOBALS['fppw_options'] = array( 'date_format' => 'j F Y' );
function get_option( $name, $default = false ) { return $GLOBALS['fppw_options'][ $name ] ?? $default; }
$GLOBALS['fppw_caps'] = array();
function current_user_can( string $cap ): bool { return ! empty( $GLOBALS['fppw_caps'][ $cap ] ); }
$GLOBALS['fppw_submenu'] = null;
function add_submenu_page( $parent, $page_title, $menu_title, $capability, $slug, $callback ) {
	$GLOBALS['fppw_submenu'] = compact( 'parent', 'page_title', 'menu_title', 'capability', 'slug', 'callback' );
	return $slug;
}
$GLOBALS['fppw_attr_labels'] = array( 'pa_color' => 'Color' );
function wc_attribute_label( $name, $product = '' ) { return $GLOBALS['fppw_attr_labels'][ $name ] ?? $name; }

/* Fake wpdb with the real unique option_name semantics: INSERT onto an existing
 * name loses; both UPDATE shapes, DELETE and LIKE behave as SQL does; reads
 * bypass the per-request cache. */
class FPPW_Fake_wpdb {
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
			if ( array_key_exists( $m[1], $GLOBALS['fppw_table'] ) ) { return false; }
			$GLOBALS['fppw_table'][ $m[1] ] = $m[2]; return 1;
		}
		if ( preg_match( "/^UPDATE \{?\w*options\}? SET option_value = '(.*)' WHERE option_name = '(.+?)' AND option_value = '(.*)'$/s", $sql, $m ) ) {
			if ( ! array_key_exists( $m[2], $GLOBALS['fppw_table'] ) || $GLOBALS['fppw_table'][ $m[2] ] !== $m[3] ) { return 0; }
			$GLOBALS['fppw_table'][ $m[2] ] = $m[1]; return 1;
		}
		if ( preg_match( "/^UPDATE \{?\w*options\}? SET option_value = '(.*)' WHERE option_name = '(.+)'$/s", $sql, $m ) ) {
			if ( ! array_key_exists( $m[2], $GLOBALS['fppw_table'] ) ) { return 0; }
			$GLOBALS['fppw_table'][ $m[2] ] = $m[1]; return 1;
		}
		if ( preg_match( "/^DELETE FROM \{?\w*options\}? WHERE option_name = '(.+)'$/", $sql, $m ) ) {
			if ( ! array_key_exists( $m[1], $GLOBALS['fppw_table'] ) ) { return 0; }
			unset( $GLOBALS['fppw_table'][ $m[1] ] ); return 1;
		}
		return 0;
	}
	public function get_var( $sql ) {
		if ( preg_match( "/SELECT option_value FROM \{?\w*options\}? WHERE option_name = '(.+)'$/", $sql, $m ) ) {
			return $GLOBALS['fppw_table'][ $m[1] ] ?? null;
		}
		return null;
	}
	public function get_col( $sql ) {
		if ( preg_match( "/SELECT option_name FROM \{?\w*options\}? WHERE option_name LIKE '(.+)'$/", $sql, $m ) ) {
			$like = str_replace( array( '\\%', '\\_' ), array( '%', '_' ), $m[1] );
			$prefix = ( $pos = strpos( $like, '%' ) ) !== false ? substr( $like, 0, $pos ) : $like;
			$out = array();
			foreach ( array_keys( $GLOBALS['fppw_table'] ) as $name ) { if ( str_starts_with( $name, $prefix ) ) { $out[] = $name; } }
			return $out;
		}
		return array();
	}
}
$GLOBALS['fppw_table'] = array();
$GLOBALS['wpdb'] = new FPPW_Fake_wpdb();

/* The native catalog: products and variations are plain records with their
 * identity; ANY other method call (set_price, save, update_meta_data…) is
 * recorded as a mutation so the tests can prove the mantenedor never writes
 * product data. */
$GLOBALS['fppw_mutations'] = array();
class FPPW_Product {
	public function __construct( public int $id, public string $name, public string $type = 'simple', public array $children = array() ) {}
	public function get_id(): int { return $this->id; }
	public function get_name(): string { return $this->name; }
	public function get_type(): string { return $this->type; }
	public function is_type( string $type ): bool { return $type === $this->type; }
	public function get_children(): array { return $this->children; }
	public function __call( string $name, array $args ) { $GLOBALS['fppw_mutations'][] = $this->id . ':' . $name; return null; }
}
$GLOBALS['fppw_products'] = array();
function wc_get_product( $id ) { return $GLOBALS['fppw_products'][ (int) $id ] ?? null; }
$GLOBALS['fppw_product_ids'] = array();
function get_posts( $args ) { return $GLOBALS['fppw_product_ids']; }

$GLOBALS['fppw_products'][22] = new FPPW_Product( 22, 'Caja Cosechera 3/4' );
$GLOBALS['fppw_products'][25] = new FPPW_Product( 25, 'Caja Universal Cerrada Color', 'variable', array( 310, 311, 312 ) );
$GLOBALS['fppw_products'][310] = new FPPW_Product( 310, 'Caja Universal Cerrada Color — Azul', 'variation' );
$GLOBALS['fppw_products'][311] = new FPPW_Product( 311, 'Caja Universal Cerrada Color — Rojo', 'variation' );
$GLOBALS['fppw_products'][312] = new FPPW_Product( 312, 'Caja Universal Cerrada Color — Verde', 'variation' );
$GLOBALS['fppw_product_ids'] = array( 22, 25 );

/* The request fixture of cuts 1–2: three lines over two products, two options
 * of the SAME variable product, one option without a maintained price. */
class FPPW_ItemMeta {
	public function __construct( private string $key, private mixed $value ) {}
	public function get_data(): array { return array( 'key' => $this->key, 'value' => $this->value ); }
}
class FPPW_Item {
	public function __construct( private string $name, private int $quantity, private int $productId, private int $variationId, private array $meta = array() ) {}
	public function get_name(): string { return $this->name; }
	public function get_quantity(): int { return $this->quantity; }
	public function get_product_id(): int { return $this->productId; }
	public function get_variation_id(): int { return $this->variationId; }
	public function get_meta_data(): array { $out = array(); foreach ( $this->meta as $k => $v ) { $out[] = new FPPW_ItemMeta( $k, $v ); } return $out; }
}
class FPPW_Order {
	public array $meta;
	public function __construct( public int $id, public array $items, array $meta = array() ) {
		$this->meta = array_merge( array(
			'_fp_request' => 'yes',
			'_fpw_attempt' => str_repeat( 'ab', 32 ),
			'_fp_submitted_details' => array( 'billing_first_name' => 'Pilar', 'billing_fp_dispatch' => 'si', 'billing_fp_address' => 'Camino de prueba 123, Mostazal' ),
			'_billing_fp_rut' => '76.543.210-K',
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
$order68 = new FPPW_Order( 68, array(
	new FPPW_Item( 'Caja Cosechera 3/4', 140, 22, 0 ),
	new FPPW_Item( 'Caja Universal Cerrada Color', 25, 25, 310, array( 'pa_color' => 'Azul' ) ),
	new FPPW_Item( 'Caja Universal Cerrada Color', 10, 25, 312, array( 'pa_color' => 'Verde' ) ),
) );

/* ===== The private Price List module ===== */

/* Registration: the mantenedor is an unlisted wp-admin screen keyed on the
 * owner capability, and it hooks no public or checkout event. */
fpw_price_register_screen();
$screen = $GLOBALS['fppw_submenu'];
check( is_array( $screen ) && null === $screen['parent'] && FPW_PRICE_SCREEN === $screen['slug'] && 'manage_woocommerce' === $screen['capability'] && 'fpw_render_price_screen' === $screen['callback'], 'the mantenedor is a private (unlisted) wp-admin screen keyed on manage_woocommerce' );
check( isset( $registered_actions['admin_init'] ) && in_array( 'fpw_price_maybe_handle_save', $registered_actions['admin_init'], true ), 'the save is front-doored at admin_init, before wp-admin renders its header' );
$public_hooks = array( 'woocommerce_checkout_order_created', 'woocommerce_checkout_order_processed', 'wp_enqueue_scripts', 'rest_api_init' );
foreach ( $public_hooks as $hook ) {
	check( empty( $registered_actions[ $hook ] ) || ! in_array( 'fpw_price_maybe_handle_save', $registered_actions[ $hook ], true ), "the price list rides no $hook event" );
}

/* Empty state: no row, no invention. */
check( fpw_price_list() === array( 'schema' => 1, 'updated_at' => 0, 'updated_by' => '', 'prices' => array() ), 'an absent price row reads as an explicit empty list' );
check( fpw_price_for( 22, 0 ) === null && fpw_price_for( 25, 310 ) === null, 'with nothing maintained, no identity has a suggestion' );

/* Keys and resolution: the native identity is product id and variation id; an
 * option's own price outranks its product's, and the same product's options
 * can carry different prices. */
check( fpw_price_key( 22, 0 ) === 'p:22' && fpw_price_key( 25, 310 ) === 'v:310', 'keys are the native product/variation identity' );
fpw_price_save_entries( array( 'p:22' => 1490, 'p:25' => 1990, 'v:310' => 2190 ), 'dueño' );
$stored = fpw_price_list();
check( 1490 === $stored['prices']['p:22'] && 2190 === $stored['prices']['v:310'] && 1 === $stored['schema'] && 'dueño' === $stored['updated_by'] && $stored['updated_at'] > 0, 'the maintained prices persist with their provenance' );
check( 1490 === fpw_price_for( 22, 0 ), 'the simple product resolves its own price' );
check( 2190 === fpw_price_for( 25, 310 ), 'the variation resolves its OWN price' );
check( 1990 === fpw_price_for( 25, 311 ), 'an option without its own entry falls back to its product price' );
check( null === fpw_price_for( 99, 0 ), 'an unknown identity has no suggestion' );
fpw_price_save_entries( array( 'v:311' => 2390 ), 'dueño' );
check( 2390 === fpw_price_for( 25, 311 ) && null === fpw_price_for( 25, 310 ), 'a full replace drops cleared entries: the screen is the authority editor' );
fpw_price_save_entries( array( 'p:22' => 1490, 'p:25' => 1990, 'v:310' => 2190, 'v:311' => 2390 ), 'dueño' );

/* Save validation: strict CLP, known identities only, all-or-nothing. */
$catalog = fpw_price_catalog();
check( 2 === count( $catalog ), 'the catalog lists the published products' );
check( $catalog[0]['product_id'] === 22 && array() === $catalog[0]['variations'], 'the simple product lists without variations' );
check( $catalog[1]['product_id'] === 25 && array( array( 'variation_id' => 310, 'name' => 'Caja Universal Cerrada Color — Azul' ), array( 'variation_id' => 311, 'name' => 'Caja Universal Cerrada Color — Rojo' ), array( 'variation_id' => 312, 'name' => 'Caja Universal Cerrada Color — Verde' ) ) === $catalog[1]['variations'], 'the variable product lists every variation identity with its name' );

$ok = fpw_price_parse_input( $catalog, array( 'p:22' => '1490', 'v:310' => '2190', 'v:311' => '', 'p:25' => '' ) );
check( empty( $ok['errors'] ) && $ok['entries'] === array( 'p:22' => 1490, 'v:310' => 2190 ), 'valid entries parse; empty inputs mean not-maintained' );

$invented = fpw_price_parse_input( $catalog, array( 'p:999' => '100', 'v:4242' => '100' ) );
check( ! empty( $invented['errors'] ) && empty( $invented['entries'] ), 'an invented identity is refused: the mantenedor only knows native catalog identities' );

$zero = fpw_price_parse_input( $catalog, array( 'p:22' => '0' ) );
check( ! empty( $zero['errors'] ) && empty( $zero['entries'] ), 'a zero price is refused: the catalog zero sentinel is never turned into a commercial price' );
$junk = fpw_price_parse_input( $catalog, array( 'p:22' => 'abc' ) );
check( ! empty( $junk['errors'] ) && empty( $junk['entries'] ), 'a junk amount is refused' );
$negative = fpw_price_parse_input( $catalog, array( 'p:22' => '-5' ) );
check( ! empty( $negative['errors'] ) && empty( $negative['entries'] ), 'a negative amount is refused' );
$oversize = fpw_price_parse_input( $catalog, array( 'p:22' => '100000000' ) );
check( ! empty( $oversize['errors'] ) && empty( $oversize['entries'] ), 'an oversized amount is refused' );

$before_bad = fpw_price_list();
$_POST = array( 'fpw_price_nonce' => 'offline-nonce', 'fpw_prices' => array( 'p:999' => '100' ) );
$bad = fpw_price_handle_save_request( $catalog );
check( false === $bad['ok'] && fpw_price_list() === $before_bad, 'a save with errors writes nothing: the stored list stands' );

/* The save never mutates product data, photos, requests or sales history. */
$GLOBALS['fppw_mutations'] = array();
$_POST = array( 'fpw_price_nonce' => 'offline-nonce', 'fpw_prices' => array( 'p:22' => '1490', 'p:25' => '1990', 'v:310' => '2190', 'v:311' => '2390' ) );
$saved = fpw_price_handle_save_request( $catalog );
check( true === $saved['ok'] && str_contains( $saved['message'], '4' ), 'a valid save reports its outcome' );
check( array() === $GLOBALS['fppw_mutations'], 'saving prices calls no product write method: products, photos and catalog data stay untouched' );
check( 4 === count( fpw_price_list()['prices'] ), 'the valid entries persisted' );
$foreign_rows = array_values( array_filter( array_keys( $GLOBALS['fppw_table'] ), static fn( $name ) => str_starts_with( $name, 'fpw_sales_' ) || str_starts_with( $name, 'fpw_draft_' ) ) );
check( array() === $foreign_rows, 'a price save writes no sales, draft or work rows: the modules stay independent' );

/* ===== The mantenedor screen ===== */
$GLOBALS['fppw_caps'] = array( 'manage_woocommerce' => true );
$markup = fpw_price_screen_markup();
check( str_contains( $markup, 'Mantenedor de precios' ), 'the screen names itself' );
check( str_contains( $markup, 'name="fpw_prices[p:22]"' ) && str_contains( $markup, 'value="1490"' ), 'the simple product renders its input with the maintained value' );
check( preg_match( '/name="fpw_prices\[v:310\]"[^>]*value="2190"/', $markup ) === 1 && preg_match( '/name="fpw_prices\[v:311\]"[^>]*value="2390"/', $markup ) === 1, 'each option of the same product renders its own input and price' );
check( preg_match( '/name="fpw_prices\[v:312\]"[^>]*value=""/', $markup ) === 1, 'an unmaintained option renders an empty input even when its product carries a price' );
check( str_contains( $markup, 'sugerirá el precio del producto (1.990 CLP neto)' ), 'an unmaintained option names the product price it would inherit' );
check( str_contains( $markup, 'name="fpw_price_nonce"' ) && str_contains( $markup, 'name="fpw_price_save"' ), 'the save action carries its CSRF nonce' );
check( str_contains( $markup, 'no toca productos' ), 'the screen states it never mutates products or public data' );
check( str_contains( $markup, 'no reescribe' ) && str_contains( $markup, 'conserva sus importes' ), 'the screen states list changes never rewrite saved drafts' );
check( str_contains( $markup, 'jamás aparecen en páginas públicas' ), 'the screen states the prices stay private' );
check( str_contains( $markup, 'Caja Cosechera 3/4' ), 'the catalog renders by name' );
check( str_contains( $markup, 'Color — Azul' ), 'the variation rows name their option' );
check( str_contains( $markup, '@media (min-width: 782px)' ), 'the layout is authored mobile-first with a desktop enhancement' );

/* A banner renders its outcome. */
$with_banner = fpw_price_screen_markup( array( 'ok' => true, 'message' => 'Lista de precios guardada: 4 precios mantenidos.' ) );
check( str_contains( $with_banner, 'Lista de precios guardada' ), 'the save banner renders' );

/* An empty catalog reads honestly. */
$GLOBALS['fppw_product_ids'] = array();
$no_catalog = fpw_price_screen_markup();
check( str_contains( $no_catalog, 'catálogo' ) && ! str_contains( $no_catalog, 'name="fpw_prices[' ), 'an empty catalog offers no inputs and says so' );
$GLOBALS['fppw_product_ids'] = array( 22, 25 );

/* Boundaries: ventas (its exact four approved caps) is denied the screen and
 * its save — with a VALID nonce; the nonce grants nothing. A nonce failure is
 * an explicit 403 too. */
$GLOBALS['fppw_caps'] = array( 'read' => true, 'manage_freeplast_quotes' => true, 'edit_shop_orders' => true, 'edit_others_shop_orders' => true );
$_GET = array( 'page' => FPW_PRICE_SCREEN );
try {
	ob_start(); fpw_render_price_screen(); ob_end_clean();
	check( false, 'ventas must be denied the mantenedor screen' );
} catch ( FPPW_Die $e ) {
	check( $e->getMessage() === '403', 'ventas receives the 403 permission denial on the mantenedor' );
}
$_POST = array( 'fpw_price_save' => '1', 'fpw_price_nonce' => 'offline-nonce', 'fpw_prices' => array( 'p:22' => '1' ) );
$list_before_denied = fpw_price_list();
try {
	fpw_price_maybe_handle_save();
	check( false, 'ventas must be denied the mantenedor save' );
} catch ( FPPW_Die $e ) {
	check( $e->getMessage() === '403', 'a valid ventas session with a valid nonce is still denied the save: presenting a nonce grants nothing' );
}
check( fpw_price_list() === $list_before_denied, 'the denied save changed nothing' );

$GLOBALS['fppw_caps'] = array( 'manage_woocommerce' => true );
$GLOBALS['fppw_nonce_ok'] = false;
$_POST = array( 'fpw_price_save' => '1', 'fpw_price_nonce' => 'forged', 'fpw_prices' => array( 'p:22' => '1' ) );
try {
	fpw_price_maybe_handle_save();
	check( false, 'a save failing the CSRF check must be refused' );
} catch ( FPPW_Die $e ) {
	check( $e->getMessage() === '403', 'a nonce failure is an explicit 403' );
}
check( fpw_price_list() === $list_before_denied, 'the refused save changed nothing' );

$GLOBALS['fppw_nonce_ok'] = true;
$_POST = array( 'fpw_price_save' => '1', 'fpw_price_nonce' => 'offline-nonce', 'fpw_prices' => array( 'p:22' => '1500', 'p:25' => '1990', 'v:310' => '', 'v:311' => '2400' ) );
fpw_price_maybe_handle_save();
$after_save = fpw_price_list();
check( 1500 === $after_save['prices']['p:22'] && ! isset( $after_save['prices']['v:310'] ) && 2400 === $after_save['prices']['v:311'] && 1990 === $after_save['prices']['p:25'], 'the front-doored save persists the posted list (entries cleared on the screen are removed)' );

/* ===== Draft prefill: new drafts use the available values, missing ones stay pending ===== */
$GLOBALS['fppw_orders'] = array();
function wc_get_order( $id ) { return $GLOBALS['fppw_orders'][ (int) $id ] ?? null; }
$GLOBALS['fppw_orders'][68] = $order68;
check( fpw_create_request_draft( $order68 ) === true, 'the request creates its draft' );
$payload68 = fpw_read_request_draft( 68 );
fpw_price_save_entries( array( 'p:22' => 1490, 'v:310' => 2190, 'v:311' => 2390 ), 'dueño' );

$GLOBALS['fppw_caps'] = array( 'manage_woocommerce' => true );
$html = fpw_quote_draft_markup( $order68, $payload68 );
check( preg_match( '/name="fpw_work\\[lines\\]\\[0\\]\\[price\\]" value="1490"/', $html ) === 1, 'the simple line prefills its maintained price' );
check( preg_match( '/name="fpw_work\\[lines\\]\\[1\\]\\[price\\]" value="2190"/', $html ) === 1, 'the Azul option prefills its OWN price: different options of the same product carry different suggestions' );
check( preg_match( '/name="fpw_work\\[lines\\]\\[2\\]\\[price\\]" value=""/', $html ) === 1 && str_contains( $html, 'placeholder="Pendiente"' ), 'a line without any maintained price stays pending, never zero' );
check( substr_count( $html, 'sugerido por el mantenedor' ) >= 2, 'prefilled values are named as suggestions, never as chosen prices' );
check( str_contains( $html, 'Prellenado con la sugerencia del mantenedor' ), 'the prefill names its origin beside the input' );
check( str_contains( $html, 'page=fpw-price-list' ), 'the draft links the mantenedor' );

/* The suggestion comes ONLY from the mantenedor — never from the Purchase History's sale totals. */
$GLOBALS['fppw_table']['fpw_sales_register'] = wp_json_encode( array( 'schema' => 1, 'sales' => array( array( 'id' => 'V-1', 'rut' => '76543210K', 'date' => '2026-01-05', 'total' => 999999 ) ) ) );
$html = fpw_quote_draft_markup( $order68, $payload68 );
check( preg_match( '/999\.999 CLP/', $html ) === 1, 'the imported history still renders its total as history' );
check( preg_match( '/name="fpw_work\\[lines\\]\\[\\d\\]\\[price\\]" value="999999"/', $html ) !== 1, 'a historical sale total never prefills a price' );
unset( $GLOBALS['fppw_table']['fpw_sales_register'] );

/* A draft without the module's prices keeps the manual flow (all pending). */
fpw_price_save_entries( array(), 'dueño' );
$html = fpw_quote_draft_markup( $order68, $payload68 );
check( preg_match( '/name="fpw_work\\[lines\\]\\[0\\]\\[price\\]" value=""/', $html ) === 1, 'with nothing maintained, every line stays pending and manual' );
fpw_price_save_entries( array( 'p:22' => 1490, 'v:310' => 2190, 'v:311' => 2390 ), 'dueño' );

/* ===== Suggested vs chosen: the save records the origin, the screen keeps it distinct ===== */
$save = fpw_save_draft_work( 68, $payload68, array(
	'fpw_work_revision' => '0',
	'fpw_work' => array(
		'lines' => array(
			array( 'quantity' => '140', 'price' => '1490' ),
			array( 'quantity' => '25', 'price' => '1750' ),
			array( 'quantity' => '10', 'price' => '' ),
		),
		'destination' => 'Camino de trabajo 456, Mostazal',
		'dispatch_amount' => '39990',
	),
), 1 );
check( 'saved' === $save['state'], 'a valid save is accepted' );
check( 'suggested' === $save['work']['lines'][0]['price_source'], 'a price equal to the current suggestion saves as suggested (it tracks the list)' );
check( 'manual' === $save['work']['lines'][1]['price_source'], 'a price different from the suggestion saves as the owner\'s manual choice' );
check( 'pending' === $save['work']['lines'][2]['price_source'], 'an empty price stays pending' );
$work1 = fpw_read_draft_work( 68 );

$html = fpw_quote_draft_markup( $order68, $payload68, $work1 );
check( str_contains( $html, '1.490 CLP neto · sugerido por el mantenedor' ), 'the adopted suggestion renders named as such' );
check( str_contains( $html, '1.750 CLP neto · ingreso manual' ), 'the owner\'s choice renders named as manual' );
check( preg_match( '/name="fpw_work\\[lines\\]\\[2\\]\\[price\\]" value=""/', $html ) === 1, 'the pending line renders empty' );

/* ===== Stability: changing the list never rewrites saved work; the new suggestion shows beside it ===== */
fpw_price_save_entries( array( 'p:22' => 1550, 'v:310' => 2290 ), 'dueño' );
check( fpw_read_draft_work( 68 ) === $work1, 'changing the list never rewrites the saved draft' );
$html = fpw_quote_draft_markup( $order68, $payload68, $work1 );
check( preg_match( '/name="fpw_work\\[lines\\]\\[0\\]\\[price\\]" value="1490"/', $html ) === 1, 'the conserved amount still fills the input after the list moved' );
check( str_contains( $html, 'La lista sugiere hoy: 1.550 CLP neto' ) && str_contains( $html, 'La lista sugiere hoy: 2.290 CLP neto' ), 'the current suggestions render beside the conserved values' );

/* ===== The explicit refresh: suggestions update, manual choices survive ===== */
$refresh = fpw_refresh_draft_prices( 68, $payload68, 1 );
check( 'saved' === $refresh['state'] && 2 === (int) $refresh['work']['revision'], 'the refresh is itself a save: it bumps the revision and invalidates the previous review' );
check( 1550 === $refresh['work']['lines'][0]['price'] && 'suggested' === $refresh['work']['lines'][0]['price_source'], 'the tracking line adopts the new suggestion' );
check( 1750 === $refresh['work']['lines'][1]['price'] && 'manual' === $refresh['work']['lines'][1]['price_source'], 'the manual choice is conserved verbatim' );
check( null === $refresh['work']['lines'][2]['price'] && 'pending' === $refresh['work']['lines'][2]['price_source'], 'a line without a maintained price stays pending through a refresh' );
check( 'Camino de trabajo 456, Mostazal' === $refresh['work']['destination'] && 39990 === $refresh['work']['dispatch_amount'], 'the refresh touches only prices: working destination and dispatch stay as saved' );
check( $refresh['work']['dispatch_conditions'] === $work1['dispatch_conditions'], 'the dispatch conditions are preserved by the refresh' );
$work2 = fpw_read_draft_work( 68 );
check( is_array( $work2 ) && 1550 === $work2['lines'][0]['price'] && 1750 === $work2['lines'][1]['price'], 'the refreshed work persisted' );
$html = fpw_quote_draft_markup( $order68, $payload68, $work2 );
check( preg_match( '/name="fpw_work\\[lines\\]\\[0\\]\\[price\\]" value="1550"/', $html ) === 1, 'the adopted suggestion recovers into the form' );
check( substr_count( $html, 'La lista sugiere hoy' ) === 1, 'only the manual line that differs from the list carries the beside-note; the tracking line matches it silently' );
check( str_contains( $html, 'name="fpw_price_refresh"' ) && str_contains( $html, 'name="fpw_refresh_nonce"' ), 'the editing form offers the explicit refresh action with its own CSRF nonce' );

/* A form rendered from the pre-refresh revision is now stale: the refreshed work stands. */
$stale = fpw_save_draft_work( 68, $payload68, array(
	'fpw_work_revision' => '1',
	'fpw_work' => array(
		'lines' => array( array( 'quantity' => '140', 'price' => '1' ), array( 'quantity' => '25', 'price' => '1' ), array( 'quantity' => '10', 'price' => '' ) ),
		'destination' => 'Sobrescritura',
		'dispatch_amount' => '1',
	),
), 1 );
check( 'conflict' === $stale['state'] && fpw_read_draft_work( 68 ) === $work2, 'a stale pre-refresh form is refused; the refreshed work stands' );

/* Completing the last line manually names the mixed completion honestly. */
$save3 = fpw_save_draft_work( 68, $payload68, array(
	'fpw_work_revision' => '2',
	'fpw_work' => array(
		'lines' => array( array( 'quantity' => '140', 'price' => '1550' ), array( 'quantity' => '25', 'price' => '1750' ), array( 'quantity' => '10', 'price' => '2100' ) ),
		'destination' => 'Camino de trabajo 456, Mostazal',
		'dispatch_amount' => '39990',
	),
), 1 );
check( 'saved' === $save3['state'], 'the completing save is accepted' );
check( str_contains( fpw_quote_draft_markup( $order68, $payload68, fpw_read_draft_work( 68 ) ), 'Guardados · 1 sugerido por el mantenedor · 2 ingresados manualmente' ), 'the status names the suggested/manual mix once every line is priced' );

/* Refresh without saved work: the first save fills suggestions, invents no destination or freight. */
$order95 = new FPPW_Order( 95, array( new FPPW_Item( 'Caja Cosechera 3/4', 12, 22, 0 ) ) );
$GLOBALS['fppw_orders'][95] = $order95;
check( fpw_create_request_draft( $order95 ) === true, 'the second request creates its draft' );
$payload95 = fpw_read_request_draft( 95 );
$refresh95 = fpw_refresh_draft_prices( 95, $payload95, 1 );
check( 'saved' === $refresh95['state'] && 1 === (int) $refresh95['work']['revision'], 'a fresh draft\'s refresh is its first save' );
check( 1550 === $refresh95['work']['lines'][0]['price'] && 'suggested' === $refresh95['work']['lines'][0]['price_source'], 'the suggestion fills the fresh draft' );
check( 12 === (int) $refresh95['work']['lines'][0]['quantity'], 'the request\'s own quantity is kept' );
check( '' === $refresh95['work']['destination'] && null === $refresh95['work']['dispatch_amount'], 'a refresh invents no working destination and no freight' );

/* ===== The refresh through the admin_init front door: authorization first, then CSRF ===== */
$_GET = array( 'page' => 'fpw-quote-draft', 'request' => '68' );
$refresh_post = array( 'fpw_price_refresh' => '1', 'fpw_refresh_nonce' => 'offline-nonce' );
$GLOBALS['fppw_caps'] = array( 'read' => true, 'manage_freeplast_quotes' => true, 'edit_shop_orders' => true, 'edit_others_shop_orders' => true );
$_POST = $refresh_post;
try {
	fpw_handle_draft_save();
	ob_start(); fpw_render_quote_draft_screen(); ob_end_clean();
	check( false, 'ventas must be denied the refresh' );
} catch ( FPPW_Die $e ) {
	check( $e->getMessage() === '403', 'a valid ventas session with a valid nonce is denied the refresh: permissions, never a nonce, grant access' );
}
check( fpw_read_draft_work( 68 ) === $save3['work'], 'the denied refresh changed nothing' );
$GLOBALS['fppw_caps'] = array( 'manage_woocommerce' => true );
$GLOBALS['fppw_nonce_ok'] = false;
try {
	fpw_handle_draft_save();
	check( false, 'a refresh failing the CSRF check must be refused' );
} catch ( FPPW_Die $e ) {
	check( $e->getMessage() === '403', 'a refresh nonce failure is an explicit 403' );
}
check( fpw_read_draft_work( 68 ) === $save3['work'], 'the refused refresh changed nothing' );
$GLOBALS['fppw_nonce_ok'] = true;
fpw_handle_draft_save();
ob_start(); fpw_render_quote_draft_screen(); $page = ob_get_clean();
check( str_contains( $page, 'Precios refrescados desde el mantenedor (revisión 4)' ), 'the authorized refresh confirms its revision' );
check( str_contains( $page, 'se conservaron' ), 'the refresh notice states the manual choices were conserved' );
check( str_contains( $page, 'name="fpw_work_revision" value="4"' ), 'the form re-renders from the refreshed revision' );

echo "price list: $assertions offline checks passed (issue #52)\n";
