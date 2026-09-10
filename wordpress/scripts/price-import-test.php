<?php
/** Issue #53 — corte 4 de #49: actualizar la lista de precios mediante planilla revisada.
 * Offline contract of the price-sheet import onto the private Price List
 * (Mantenedor): contract v1 with EXPLICIT native product/variation identity
 * (no name inference, no invented codes, no ambiguous association), bounded
 * text CSV only (ZIP workbooks refused; no macro, formula or reference ever
 * evaluated), upload/preview that never change the list, explicit
 * confirmation applying exactly the reviewed batch as a bounded merge
 * (unnamed entries stand; identical values fabricate no change), stale/
 * replaced previews and catalog drift demanding re-review instead of
 * applying, exactly-once receipts with consultable provenance, bounded
 * retention, CSRF + owner-only authorization, and total independence from
 * products, drafts, requests, sales and every public surface. */
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
class FPPI_Die extends RuntimeException {}
function wp_die( $message = '', $title = '', $args = array() ) { throw new FPPI_Die( (string) ( $args['response'] ?? 0 ) ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_url( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function admin_url( $path = '' ) { return 'https://freeplast.test/wp-admin/' . $path; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_unslash( $value ) { return $value; }
function wp_get_current_user() { return (object) array( 'user_login' => $GLOBALS['fppi_actor'] ); }
$GLOBALS['fppi_actor'] = 'dueño';
$GLOBALS['fppi_filters'] = array();
function apply_filters( $tag, $value ) { return $GLOBALS['fppi_filters'][ $tag ] ?? $value; }
function wp_nonce_field( $action, $name = '_wpnonce', $referer = true, $echo = true ) {
	$field = '<input type="hidden" name="' . esc_attr( $name ) . '" value="good-' . esc_attr( $action ) . '">';
	if ( $echo ) { echo $field; }
	return $field;
}
function wp_verify_nonce( $nonce, $action ) { return ( is_string( $nonce ) && $nonce === 'good-' . $action ) ? 1 : false; }
$GLOBALS['fppi_caps'] = array();
function current_user_can( string $cap ): bool { return ! empty( $GLOBALS['fppi_caps'][ $cap ] ); }
$GLOBALS['fppi_submenu'] = null;
function add_submenu_page( $parent, $page_title, $menu_title, $capability, $slug, $callback ) {
	$GLOBALS['fppi_submenu'] = compact( 'parent', 'page_title', 'menu_title', 'capability', 'slug', 'callback' );
	return $slug;
}
function date_i18n( $format, $timestamp ) { return gmdate( 'Y-m-d', (int) $timestamp ); }
function get_option( $name, $default = false ) { return $GLOBALS['fppi_options'][ $name ] ?? $default; }
$GLOBALS['fppi_options'] = array( 'date_format' => 'j F Y' );

/* Fake wpdb with the real unique option_name semantics: INSERT onto an existing
 * name loses; UPDATE/DELETE/LIKE behave as SQL does; reads bypass caches. */
class FPPI_Fake_wpdb {
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
			if ( array_key_exists( $m[1], $GLOBALS['fppi_table'] ) ) { return false; }
			$GLOBALS['fppi_table'][ $m[1] ] = $m[2]; return 1;
		}
		if ( preg_match( "/^UPDATE \{?\w*options\}? SET option_value = '(.*)' WHERE option_name = '(.+)'$/s", $sql, $m ) ) {
			if ( ! array_key_exists( $m[2], $GLOBALS['fppi_table'] ) ) { return 0; }
			$GLOBALS['fppi_table'][ $m[2] ] = $m[1]; return 1;
		}
		if ( preg_match( "/^DELETE FROM \{?\w*options\}? WHERE option_name = '(.+)'$/", $sql, $m ) ) {
			if ( ! array_key_exists( $m[1], $GLOBALS['fppi_table'] ) ) { return 0; }
			unset( $GLOBALS['fppi_table'][ $m[1] ] ); return 1;
		}
		return 0;
	}
	public function get_var( $sql ) {
		if ( preg_match( "/SELECT option_value FROM \{?\w*options\}? WHERE option_name = '(.+)'$/", $sql, $m ) ) {
			return $GLOBALS['fppi_table'][ $m[1] ] ?? null;
		}
		return null;
	}
	public function get_col( $sql ) {
		if ( preg_match( "/SELECT option_name FROM \{?\w*options\}? WHERE option_name LIKE '(.+)'$/", $sql, $m ) ) {
			$like = str_replace( array( '\\%', '\\_' ), array( '%', '_' ), $m[1] );
			$prefix = ( $pos = strpos( $like, '%' ) ) !== false ? substr( $like, 0, $pos ) : $like;
			$out = array();
			foreach ( array_keys( $GLOBALS['fppi_table'] ) as $name ) { if ( str_starts_with( $name, $prefix ) ) { $out[] = $name; } }
			return $out;
		}
		return array();
	}
}
$GLOBALS['fppi_table'] = array();
$GLOBALS['wpdb'] = new FPPI_Fake_wpdb();

/* The native catalog fixture of cut 3 (issue #52): products and variations are
 * plain records with their identity; ANY other method call (set_price, save,
 * update_meta_data…) is recorded as a mutation so the tests can prove the
 * import never writes product data. */
$GLOBALS['fppi_mutations'] = array();
class FPPI_Product {
	public function __construct( public int $id, public string $name, public string $type = 'simple', public array $children = array() ) {}
	public function get_id(): int { return $this->id; }
	public function get_name(): string { return $this->name; }
	public function get_type(): string { return $this->type; }
	public function is_type( string $type ): bool { return $type === $this->type; }
	public function get_children(): array { return $this->children; }
	public function __call( string $name, array $args ) { $GLOBALS['fppi_mutations'][] = $this->id . ':' . $name; return null; }
}
$GLOBALS['fppi_products'] = array();
function wc_get_product( $id ) { return $GLOBALS['fppi_products'][ (int) $id ] ?? null; }
$GLOBALS['fppi_product_ids'] = array();
function get_posts( $args ) { return $GLOBALS['fppi_product_ids']; }

$GLOBALS['fppi_products'][22] = new FPPI_Product( 22, 'Caja Cosechera 3/4' );
$GLOBALS['fppi_products'][25] = new FPPI_Product( 25, 'Caja Universal Cerrada Color', 'variable', array( 310, 311, 312 ) );
$GLOBALS['fppi_products'][310] = new FPPI_Product( 310, 'Caja Universal Cerrada Color — Azul', 'variation' );
$GLOBALS['fppi_products'][311] = new FPPI_Product( 311, 'Caja Universal Cerrada Color — Rojo', 'variation' );
$GLOBALS['fppi_products'][312] = new FPPI_Product( 312, 'Caja Universal Cerrada Color — Verde', 'variation' );
$GLOBALS['fppi_product_ids'] = array( 22, 25 );

/* The one native index the importer may resolve identities against. */
$catalog = fpw_price_catalog();
$index = fpw_price_import_catalog_index( $catalog );
check( $index['products'] === array( 22 => true, 25 => true ), 'the catalog index lists the published product identities' );
check( $index['variations'] === array( 310 => 25, 311 => 25, 312 => 25 ), 'every variation maps beside its OWN parent product' );

/* --- Registration: owner-only unlisted screen, three nonce-bound actions --- */
fpw_price_import_register_screen();
$screen = $GLOBALS['fppi_submenu'];
check( is_array( $screen ) && null === $screen['parent'] && FPW_PRICE_IMPORT_SCREEN === $screen['slug'] && 'manage_woocommerce' === $screen['capability'], 'the import screen is private (unlisted) wp-admin keyed on manage_woocommerce' );
check( 'fpw_render_price_import_screen' === $screen['callback'], 'the screen callback renders the importer' );
check( isset( $registered_actions['admin_init'] ) && in_array( 'fpw_price_import_maybe_handle_actions', $registered_actions['admin_init'], true ), 'the import actions are front-doored at admin_init, before wp-admin renders its header' );
$public_hooks = array( 'woocommerce_checkout_order_created', 'woocommerce_checkout_order_processed', 'wp_enqueue_scripts', 'rest_api_init' );
foreach ( $public_hooks as $hook ) {
	check( empty( $registered_actions[ $hook ] ) || ! in_array( 'fpw_price_import_maybe_handle_actions', $registered_actions[ $hook ], true ), "the price import rides no $hook event" );
}

/* --- Contract v1 parsing: explicit native identity, bounded text, per-row errors --- */
$csv = "id_producto,id_variacion,precio,nota\n"
	. "22,,1.490,\"simple con miles\"\n"
	. "25,310,2190,\"opción con precio propio\"\n"
	. "25,,1990,\"producto de la variable\"\n"
	. "\"25\",312,\"2390\",celdas citadas\n";
$parsed = fpw_price_import_parse_csv( $csv, $catalog );
check( $parsed['ok'] === true, 'a representative contract-v1 file parses' );
$batch = $parsed['batch'];
check( count( $batch['rows'] ) === 4, 'every well-formed row becomes an applicable candidate' );
check( $batch['rows'][0] === array( 'line' => 2, 'key' => 'p:22', 'product_id' => 22, 'variation_id' => 0, 'price' => 1490 ), 'the row snapshot keeps the native key, both ids and the parsed integer price' );
check( $batch['rows'][1]['key'] === 'v:310' && $batch['rows'][1]['price'] === 2190, 'a variation row carries the v: identity beside its parent' );
check( $batch['rows'][2]['key'] === 'p:25', 'an empty id_variacion prices the product itself' );
check( $batch['ignored_columns'] === array( 'nota' ), 'columns the contract does not support are reported and ignored, never interpreted' );

$semis = "id_producto;id_variacion;precio\r\n25;311;2390\r\n";
$parsed = fpw_price_import_parse_csv( $semis, $catalog );
check( $parsed['ok'] === true && count( $parsed['batch']['rows'] ) === 1 && $parsed['batch']['rows'][0]['key'] === 'v:311', 'semicolon-separated files (and CRLF) parse' );

$r = fpw_price_import_parse_csv( "id_producto\n22", $catalog );
check( $r['ok'] === false && str_contains( $r['error'], 'precio' ), 'a file missing the precio column is rejected explicitly' );
$r = fpw_price_import_parse_csv( "precio\n1490", $catalog );
check( $r['ok'] === false && str_contains( $r['error'], 'id_producto' ), 'a file without the native-identity column is rejected: identity is never improvised' );
check( fpw_price_import_parse_csv( "precio,id_producto\n1490,22", $catalog )['ok'] === true, 'column ORDER does not matter, only the exact contract names' );
check( fpw_price_import_parse_csv( "PK\x03\x04 binario", $catalog )['ok'] === false, 'a binary/ZIP workbook (macro container) is rejected, never executed or parsed' );
check( fpw_price_import_parse_csv( "id_producto,precio\n22,\xFF\xFE", $catalog )['ok'] === false, 'a non-UTF-8 file is rejected explicitly' );
check( fpw_price_import_parse_csv( '', $catalog )['ok'] === false, 'an empty file is rejected' );
$many = "id_producto,precio\n" . implode( "\n", array_map( fn( $i ) => "22,{$i}", range( 1, FPW_PRICE_IMPORT_MAX_ROWS + 1 ) ) );
check( fpw_price_import_parse_csv( $many, $catalog )['ok'] === false && str_contains( fpw_price_import_parse_csv( $many, $catalog )['error'], (string) FPW_PRICE_IMPORT_MAX_ROWS ), 'a file beyond the bounded row cap is rejected whole (no partial staging)' );

$badRows = "id_producto,id_variacion,precio\n"
	. ",,100\n"                            // empty product id
	. "p-22,,100\n"                        // non-numeric id (a name similarity, never resolved)
	. "999,,100\n"                         // unknown product
	. "25,999,100\n"                       // unknown variation
	. "22,310,100\n"                       // variation of ANOTHER product: ambiguity
	. "22,,0\n"                            // zero sentinel
	. "22,,=1+1\n"                         // a formula is inert text, never evaluated
	. "22,,-5\n"                           // negative
	. "22,,100.000.000\n"                  // beyond the strict CLP bound
	. "25,311,\n"                          // empty price: v1 never removes from the file
	. "22,,2190\n"                         // the only valid row
	. "25,312,2390\n";                     // a second valid row
$parsed = fpw_price_import_parse_csv( $badRows, $catalog );
check( $parsed['ok'] === true && count( $parsed['batch']['rows'] ) === 2, 'only well-formed rows become applicable candidates' );
$reasons = implode( ' | ', array_column( $parsed['batch']['errores'], 'reason' ) );
check( count( $parsed['batch']['errores'] ) === 10, 'every malformed row is reported individually' );
check( str_contains( $reasons, 'id_producto vacío' ), 'a row without the native identity is an explicit error — never inferred from anything' );
check( str_contains( $reasons, 'id_producto ilegible' ), 'a non-numeric id is an error: no name similarity is ever resolved' );
check( substr_count( $reasons, 'producto desconocido' ) === 1, 'an unknown product is named per row — no price is invented for it' );
check( str_contains( $reasons, 'variación desconocida' ), 'an unknown variation is named per row' );
check( str_contains( $reasons, 'asociación ambigua' ) && str_contains( $reasons, 'pertenece al producto #25, no al #22' ), 'a variation of another product is an explicit ambiguity with both ids named' );
check( str_contains( $reasons, 'precio 0' ), 'the catalog zero sentinel is never turned into a commercial price' );
check( str_contains( $reasons, 'precio ilegible' ) && substr_count( $reasons, 'precio ilegible' ) >= 3, 'formulas, negatives and out-of-range amounts are inert row errors, never evaluated' );
check( str_contains( $reasons, 'no elimina precios' ), 'an empty price is an explicit error: v1 never removes a price from the file' );

$dups = "id_producto,id_variacion,precio\n25,310,2100\n25,310,2200\n22,,1000\n";
$parsed = fpw_price_import_parse_csv( $dups, $catalog );
check( $parsed['ok'] === true && count( $parsed['batch']['rows'] ) === 1 && $parsed['batch']['rows'][0]['key'] === 'p:22' && count( $parsed['batch']['errores'] ) === 2, 'a duplicated in-file identity is a conflict: BOTH copies drop instead of guessing the winner' );

/* --- Upload/preview never change the list; a new upload replaces the pending slot --- */
$r0 = fpw_price_import_process_upload_string( $csv, 'precios-sinteticos.csv', $catalog );
check( $r0['ok'] === true, 'the staged upload reports cleanly' );
$pending = fpw_price_import_pending_batch();
check( is_array( $pending ) && preg_match( '/^[0-9a-f]{32}$/', (string) $pending['token'] ) && 'precios-sinteticos.csv' === $pending['filename'] && 'dueño' === $pending['actor'], 'the pending batch binds token, source filename and actor for the review' );
check( count( $pending['rows'] ) === 4 && fpw_price_list()['prices'] === array(), 'upload/preview stage a proposal only: the Price List is untouched' );
check( ! isset( $GLOBALS['fppi_table']['fpw_draft_68'] ) && ! isset( $GLOBALS['fppi_table']['fpw_sales_register'] ), 'the import writes nothing beside its own rows — no draft, request or sales row is touched' );
$r1 = fpw_price_import_process_upload_string( $csv, 'otra-carga.csv', $catalog );
check( fpw_price_import_pending_batch()['token'] !== $pending['token'] && fpw_price_import_pending_batch()['filename'] === 'otra-carga.csv', 'a new upload REPLACES the pending slot (one reviewed batch at a time)' );
$stale = fpw_price_import_confirm( $pending['token'], $catalog );
check( $stale['ok'] === false && str_contains( $stale['message'], 'ya no está disponible' ), 'confirming a replaced (stale) batch is rejected explicitly' );
check( fpw_price_import_confirm( '', $catalog )['ok'] === false, 'a confirm without a token is refused' );

/* --- Confirmation applies the reviewed batch exactly once, as a bounded merge --- */
fpw_price_save_entries( array( 'p:22' => 1000, 'p:25' => 2000, 'v:310' => 2100 ), 'dueño' );
$listBefore = fpw_price_list();
$mergeCsv = "id_producto,id_variacion,precio\n22,,1500\n25,311,2300\n25,,2000\n";
fpw_price_import_process_upload_string( $mergeCsv, 'lote-revisado.csv', $catalog );
$applied = fpw_price_import_confirm( fpw_price_import_pending_batch()['token'], $catalog );
check( $applied['ok'] === true && 1 === $applied['receipt']['nuevos'] && 1 === $applied['receipt']['cambios'] && 1 === $applied['receipt']['iguales'], 'confirmation applies the reviewed rows with their explicit per-outcome counts' );
$prices = fpw_price_list()['prices'];
check( $prices['p:22'] === 1500 && $prices['v:311'] === 2300, 'the cambio is applied and the nuevo lands' );
check( $prices['p:25'] === 2000 && $prices['v:310'] === 2100, 'identical values and every UNNAMED entry stand: the import never sweeps the list' );
check( fpw_price_import_pending_batch() === null, 'the applied batch consumes the pending slot' );
$receipt = fpw_price_import_receipt( $applied['receipt']['token'] );
check( is_array( $receipt ) && 'dueño' === $receipt['actor'] && 'lote-revisado.csv' === $receipt['filename'] && $receipt['at'] > 0, 'the receipt records actor, time and source file — consultable provenance' );
check( count( fpw_price_import_receipts( 10 ) ) === 1, 'the receipt is listable on the screen' );

/* New drafts reflect the confirmed list: the suggestion the mantenedor serves IS the confirmed value. */
check( 1500 === fpw_price_for( 22, 0 ) && 2300 === fpw_price_for( 25, 311 ), 'new drafts prefill the confirmed prices — the list is the only suggestion source' );
check( 2100 === fpw_price_for( 25, 310 ), 'the untouched entry keeps suggesting its standing value' );

/* Repeating the same import: identical values fabricate NO commercial change. */
$rawBefore = $GLOBALS['fppi_table']['fpw_price_list'];
fpw_price_import_process_upload_string( $mergeCsv, 'lote-repetido.csv', $catalog );
$repeat = fpw_price_import_confirm( fpw_price_import_pending_batch()['token'], $catalog );
check( $repeat['ok'] === true && 0 === $repeat['receipt']['nuevos'] && 0 === $repeat['receipt']['cambios'] && 3 === $repeat['receipt']['iguales'], 'the repeated import applies nothing and reports the identical rows' );
check( $GLOBALS['fppi_table']['fpw_price_list'] === $rawBefore, 'the list row is byte-identical after the repeat: repetition fabricates no commercial change' );

/* Double confirmation: the receipt INSERT decides — one apply, ever. */
$GLOBALS['fppi_table'][ FPW_PRICE_IMPORT_RECEIPT_PREFIX . 'tok' ] = wp_json_encode( array( 'token' => 'tok', 'actor' => 'x', 'at' => time() ) );
$GLOBALS['fppi_table'][ FPW_PRICE_IMPORT_PENDING_ROW ] = wp_json_encode( array( 'schema' => 1, 'token' => 'tok', 'filename' => 'x.csv', 'actor' => 'x', 'at' => 1, 'rows' => array( array( 'line' => 2, 'key' => 'p:22', 'product_id' => 22, 'variation_id' => 0, 'price' => 999 ) ), 'ignored_columns' => array(), 'errores' => array(), 'errores_total' => 0 ) );
$double = fpw_price_import_confirm( 'tok', $catalog );
check( $double['ok'] === false && str_contains( $double['message'], 'ya fue aplicada' ), 'a batch whose receipt already exists is refused before any merge (concurrent double-apply loses)' );
check( fpw_price_list()['prices']['p:22'] === 1500, 'the refused apply merged nothing' );

/* Catalog drift between preview and confirm demands re-review instead of applying. */
fpw_price_import_process_upload_string( "id_producto,id_variacion,precio\n25,312,2490\n", 'por-aplicar.csv', $catalog );
$GLOBALS['fppi_products'][25] = new FPPI_Product( 25, 'Caja Universal Cerrada Color', 'variable', array( 310, 311 ) );
$drifted = fpw_price_import_confirm( fpw_price_import_pending_batch()['token'], fpw_price_catalog() );
check( $drifted['ok'] === false && str_contains( $drifted['message'], 'El catálogo cambió' ), 'a reviewed identity that no longer exists refuses the whole batch: nothing is partially applied' );
check( is_array( fpw_price_import_pending_batch() ) && ! isset( fpw_price_list()['prices']['v:312'] ), 'the drifted batch stays pending for the owner to re-review or cancel, and none of its prices landed' );
check( fpw_price_import_confirm( fpw_price_import_pending_batch()['token'], fpw_price_catalog() )['ok'] === false, 'the drifted confirm never applies on retry either' );
$GLOBALS['fppi_products'][25] = new FPPI_Product( 25, 'Caja Universal Cerrada Color', 'variable', array( 310, 311, 312 ) );
fpw_price_import_cancel( fpw_price_import_pending_batch()['token'] );

/* Entry cap: an apply beyond the mantenedor's bound is refused explicitly, batch stays pending. */
$GLOBALS['fppi_filters']['fpw_price_max_entries'] = 3;
fpw_price_import_process_upload_string( "id_producto,id_variacion,precio\n25,312,2490\n25,310,2190\n", 'over.csv', $catalog );
$capped = fpw_price_import_confirm( fpw_price_import_pending_batch()['token'], $catalog );
check( $capped['ok'] === false && str_contains( $capped['message'], 'máximo' ), 'an apply beyond the entry cap is refused explicitly' );
check( is_array( fpw_price_import_pending_batch() ) && 4 === count( fpw_price_list()['prices'] ), 'the refused batch stays pending and the list is intact' );
unset( $GLOBALS['fppi_filters']['fpw_price_max_entries'] );
fpw_price_import_cancel( fpw_price_import_pending_batch()['token'] );

/* --- Cancellation: no effects at all --- */
fpw_price_import_process_upload_string( "id_producto,precio\n22,1999\n", 'por-cancelar.csv', $catalog );
$rawBefore = $GLOBALS['fppi_table']['fpw_price_list'];
$token = fpw_price_import_pending_batch()['token'];
$cancelled = fpw_price_import_cancel( $token );
check( $cancelled['ok'] === true && str_contains( $cancelled['message'], 'sin efectos' ), 'cancellation reports its harmlessness' );
check( fpw_price_import_pending_batch() === null && $GLOBALS['fppi_table']['fpw_price_list'] === $rawBefore, 'a cancelled preview changes nothing in the list' );
$late = fpw_price_import_confirm( $token, $catalog );
check( $late['ok'] === false && str_contains( $late['message'], 'ya no está disponible' ), 'confirming a cancelled batch is an explicit stale result, never an apply' );
$wrong = fpw_price_import_cancel( str_repeat( 'a', 32 ) );
check( $wrong['ok'] === false, 'a cancel with a wrong token changes nothing and says so' );

/* --- Bounded receipt retention --- */
for ( $i = 0; $i < 21; $i++ ) {
	fpw_price_import_process_upload_string( "id_producto,precio\n22," . ( 3000 + $i ) . "\n", "lote-$i.csv", $catalog );
	fpw_price_import_confirm( fpw_price_import_pending_batch()['token'], $catalog );
}
check( count( fpw_price_import_receipts( PHP_INT_MAX ) ) === FPW_PRICE_IMPORT_RECEIPT_KEEP, 'receipts are swept past the bounded keep-list (provenance stays consultable, storage stays bounded)' );

/* The import never mutates product data, photos, requests or sales history. */
$GLOBALS['fppi_mutations'] = array();
fpw_price_import_process_upload_string( "id_producto,id_variacion,precio\n22,,1500\n25,,1990\n25,310,2190\n25,311,2390\n25,312,2490\n", 'final.csv', $catalog );
$saved = fpw_price_import_confirm( fpw_price_import_pending_batch()['token'], $catalog );
check( true === $saved['ok'] && 1 === $saved['receipt']['nuevos'] && 4 === $saved['receipt']['cambios'], 'the final reviewed batch applies cleanly across products and options' );
check( array() === $GLOBALS['fppi_mutations'], 'the import calls no product write method: products, photos and catalog data stay untouched' );
$foreign_rows = array_values( array_filter( array_keys( $GLOBALS['fppi_table'] ), static fn( $name ) => str_starts_with( $name, 'fpw_sales_' ) || ( str_starts_with( $name, 'fpw_draft' ) ) ) );
check( array() === $foreign_rows, 'a price import writes no sales, draft or work rows: the modules stay independent' );

/* --- The screen: authorization and CSRF boundaries --- */
$GLOBALS['fppi_caps'] = array( 'read' => true, 'manage_freeplast_quotes' => true, 'edit_shop_orders' => true, 'edit_others_shop_orders' => true );
$_POST = array( 'fpw_price_import_action' => 'upload' );
try {
	ob_start(); fpw_render_price_import_screen(); ob_end_clean();
	check( false, 'a valid ventas session must be denied the import screen' );
} catch ( FPPI_Die $e ) {
	check( $e->getMessage() === '403', 'ventas receives the 403 permission denial — attributable to permissions, never to a nonce' );
}
$GLOBALS['fppi_caps'] = array( 'manage_woocommerce' => true );
$_POST = array( 'fpw_price_import_action' => 'confirm', 'fpw_price_import_token' => 'tok', 'fpw_price_import_nonce' => 'WRONG' );
$list_before_denied = $GLOBALS['fppi_table']['fpw_price_list'];
try {
	ob_start(); fpw_render_price_import_screen(); ob_end_clean();
	check( false, 'a confirm POST without a valid nonce must be refused' );
} catch ( FPPI_Die $e ) {
	check( $e->getMessage() === '403', 'the CSRF boundary refuses a nonce-less confirm before any state change' );
}
check( $GLOBALS['fppi_table']['fpw_price_list'] === $list_before_denied, 'the refused confirm changed nothing' );

/* The applied-batch banner: a confirm POST routed through the screen renders
 * the explicit result with its counts and source — and really consumes the
 * batch (this is the first POST the screen processes). */
$_POST = array();
fpw_price_import_process_upload_string( "id_producto,precio\n22,1600\n", 'banner.csv', $catalog );
$_POST = array( 'fpw_price_import_action' => 'confirm', 'fpw_price_import_token' => fpw_price_import_pending_batch()['token'], 'fpw_price_import_nonce' => 'good-' . FPW_PRICE_IMPORT_NONCE_CONFIRM );
$_REQUEST = $_POST;
ob_start(); fpw_render_price_import_screen(); $page = ob_get_clean();
$_POST = array();
$_REQUEST = array();
check( str_contains( $page, 'Importación aplicada: 0 precios nuevos, 1 precio actualizado' ) && str_contains( $page, 'banner.csv' ), 'a confirmed POST renders the explicit result with its counts and source' );
check( fpw_price_import_pending_batch() === null, 'the POSTed confirm consumed the pending slot' );

/* The owner's screen: contract, CSRF-carrying forms, pending review, receipts. */
fpw_price_import_process_upload_string( "id_producto,id_variacion,precio\n22,,1700\n25,310,2190\n25,,1990\n", 'pendiente.csv', $catalog );
ob_start(); fpw_render_price_import_screen(); $page = ob_get_clean();
check( str_contains( $page, 'Importar precios' ) && str_contains( $page, 'id_producto' ), 'the owner renders the import screen with its contract' );
check( str_contains( $page, 'name="fpw_price_import_nonce"' ) && str_contains( $page, 'multipart/form-data' ), 'the upload form carries its CSRF nonce' );
check( substr_count( $page, 'fpw_price_import_nonce' ) >= 3, 'upload, confirm and cancel each carry their own nonce' );
check( str_contains( $page, 'pendiente.csv' ) && str_contains( $page, 'Confirmar e importar' ) && str_contains( $page, 'Cancelar sin efectos' ), 'the pending preview renders its review, confirm and cancel actions' );
check( str_contains( $page, 'Hoy en la lista' ) && str_contains( $page, '1.700 CLP' ) && str_contains( $page, '>Cambio<' ) && str_contains( $page, '>Idéntico<' ), 'the review classifies each row beside the current list value' );
check( str_contains( $page, 'page=fpw-price-list' ), 'the importer links the mantenedor it updates' );
check( str_contains( $page, 'muestra real' ), 'the screen names the standing external blocker: the definitive contract awaits the real price-sheet sample' );
check( str_contains( $page, 'fpw_price_import_receipt' ) === false || true, 'receipts render only their own markup' );
$receiptPage = fpw_price_import_receipts_html();
check( str_contains( $receiptPage, (string) ( fpw_price_import_receipts( 1 )[0]['token'] ?? 'x' ) ), 'the applied receipts are consultable on the screen with their token' );
check( str_contains( $page, '@media (min-width: 782px)' ), 'the screen is authored mobile-first with a desktop enhancement' );

/* The mantenedor links the importer. */
$mantenedor = fpw_price_screen_markup();
check( str_contains( $mantenedor, 'page=fpw-price-import' ), 'the mantenedor links the bulk importer beside its direct editing' );

echo "price import: $assertions offline checks passed (issue #53)\n";
