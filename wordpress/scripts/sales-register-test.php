<?php
/** Issue #54 — corte 5 de #49: importar ventas y consultar historial por RUT.
 * Offline contract of the Sales Register import: explicit source-identity CSV
 * contract v1 (never the RUT alone, never date+amount), upload/preview that
 * never touch history, explicit confirmation/cancellation, exactly-once
 * batches with consultable provenance receipts, customer matching by
 * normalized company RUT (equivalent formats match; names/emails never merge),
 * unresolved associations kept unresolved («Sin historial asociado», never
 * «Cliente nuevo»), bounded reads, CSRF + owner-only authorization, and total
 * independence from drafts, requests, Price List and commercial documents. */
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
class FPWS_Die extends RuntimeException {}
function wp_die( $message = '', $title = '', $args = array() ) { throw new FPWS_Die( (string) ( $args['response'] ?? 0 ) ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_url( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function admin_url( $path = '' ) { return 'https://freeplast.test/wp-admin/' . $path; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_unslash( $value ) { return $value; }
function wp_get_current_user() { return (object) array( 'user_login' => $GLOBALS['fpws_actor'] ); }
$GLOBALS['fpws_actor'] = 'dueño';
$GLOBALS['fpws_filters'] = array();
function apply_filters( $tag, $value ) { return $GLOBALS['fpws_filters'][ $tag ] ?? $value; }
$GLOBALS['fpws_nonces'] = array();
function wp_nonce_field( $action, $name = '_wpnonce', $referer = true, $echo = true ) {
	$field = '<input type="hidden" name="' . esc_attr( $name ) . '" value="good-' . esc_attr( $action ) . '">';
	if ( $echo ) { echo $field; }
	return $field;
}
function wp_verify_nonce( $nonce, $action ) { return ( is_string( $nonce ) && $nonce === 'good-' . $action ) ? 1 : false; }
$GLOBALS['fpws_caps'] = array();
function current_user_can( string $cap ): bool { return ! empty( $GLOBALS['fpws_caps'][ $cap ] ); }
$GLOBALS['fpws_submenu'] = null;
function add_submenu_page( $parent, $page_title, $menu_title, $capability, $slug, $callback ) {
	$GLOBALS['fpws_submenu'] = compact( 'parent', 'page_title', 'menu_title', 'capability', 'slug', 'callback' );
	return $slug;
}
function date_i18n( $format, $timestamp ) { return gmdate( 'Y-m-d', (int) $timestamp ); }
function get_option( $name, $default = false ) { return $GLOBALS['fpws_options'][ $name ] ?? $default; }
$GLOBALS['fpws_options'] = array( 'date_format' => 'j F Y' );

/* Fake wpdb with the real unique option_name semantics: INSERT onto an existing
 * name loses; UPDATE/DELETE/LIKE behave as SQL does; reads bypass caches. */
class FPWS_Fake_wpdb {
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
			if ( array_key_exists( $m[1], $GLOBALS['fpws_table'] ) ) { return false; }
			$GLOBALS['fpws_table'][ $m[1] ] = $m[2]; return 1;
		}
		if ( preg_match( "/UPDATE \{?\w*options\}? SET option_value = '(.*)' WHERE option_name = '(.+)'$/s", $sql, $m ) ) {
			if ( ! array_key_exists( $m[2], $GLOBALS['fpws_table'] ) ) { return 0; }
			$GLOBALS['fpws_table'][ $m[2] ] = $m[1]; return 1;
		}
		if ( preg_match( "/DELETE FROM \{?\w*options\}? WHERE option_name = '(.+)'$/", $sql, $m ) ) {
			if ( ! array_key_exists( $m[1], $GLOBALS['fpws_table'] ) ) { return 0; }
			unset( $GLOBALS['fpws_table'][ $m[1] ] ); return 1;
		}
		return 0;
	}
	public function get_var( $sql ) {
		if ( preg_match( "/SELECT option_value FROM \{?\w*options\}? WHERE option_name = '(.+)'$/", $sql, $m ) ) {
			return $GLOBALS['fpws_table'][ $m[1] ] ?? null;
		}
		return null;
	}
	public function get_col( $sql ) {
		if ( preg_match( "/SELECT option_name FROM \{?\w*options\}? WHERE option_name LIKE '(.+)'$/", $sql, $m ) ) {
			$like = str_replace( array( '\\%', '\\_' ), array( '%', '_' ), $m[1] );
			$prefix = ( $pos = strpos( $like, '%' ) ) !== false ? substr( $like, 0, $pos ) : $like;
			$out = array();
			foreach ( array_keys( $GLOBALS['fpws_table'] ) as $name ) { if ( str_starts_with( $name, $prefix ) ) { $out[] = $name; } }
			return $out;
		}
		return array();
	}
}
$GLOBALS['fpws_table'] = array();
$GLOBALS['wpdb'] = new FPWS_Fake_wpdb();

/* The request fixture of cut 1 (issue #50): its draft already exists and must
 * survive every import untouched (drafts are never rewritten). */
function fpws_make_order( int $id, string $rut ): object {
	return new class( $id, $rut ) {
		public function __construct( public int $id, public string $rut ) {}
		public function get_id(): int { return $this->id; }
		public function get_meta( string $key ): mixed { return '_billing_fp_rut' === $key ? $this->rut : ''; }
	};
}
$GLOBALS['fpws_draft_68'] = array( 'schema' => 1, 'order_id' => 68, 'identity' => array( 'rut' => '76.543.210-K' ) );

/* --- Registration: owner-only unlisted screen, three nonce-bound actions --- */
fpw_sales_register_import_screen();
$screen = $GLOBALS['fpws_submenu'];
check( is_array( $screen ) && null === $screen['parent'] && FPW_SALES_SCREEN === $screen['slug'] && 'manage_woocommerce' === $screen['capability'], 'the import screen is private (unlisted) wp-admin keyed on manage_woocommerce' );
check( 'fpw_render_sales_import_screen' === $screen['callback'], 'the screen callback renders the importer' );
$string_hooks = array_values( array_filter( $registered_actions['woocommerce_checkout_order_created'] ?? array(), 'is_string' ) );
check( array( 'fpw_create_request_draft' ) === $string_hooks, 'the importer hooks no checkout event: sales enter only through the owner file' );

/* --- RUT normalization: equivalent formats collapse; garbage stays unusable --- */
check( fpw_sales_normalize_rut( '76.123.456-7' ) === '761234567', 'a dotted/dashed RUT normalizes' );
check( fpw_sales_normalize_rut( '76123456-7' ) === '761234567', 'a dash-only RUT normalizes to the SAME key' );
check( fpw_sales_normalize_rut( ' 761234567 ' ) === '761234567', 'spacing around a RUT is irrelevant' );
check( fpw_sales_normalize_rut( '76.543.210-k' ) === '76543210K', 'the verifier digit uppercases to K' );
check( fpw_sales_normalize_rut( '' ) === '' && fpw_sales_normalize_rut( '   ' ) === '', 'a missing RUT is unusable' );
check( fpw_sales_normalize_rut( 'abc' ) === '' && fpw_sales_normalize_rut( '76.123.456-7 x2' ) === '', 'garbage and multi-token cells stay unusable (ambiguous, never guessed)' );
check( fpw_sales_normalize_rut( '1234567' ) === '' && fpw_sales_normalize_rut( '12345678-52' ) === '', 'a RUT missing its verifier slot (or with two) is unusable' );
check( fpw_sales_normalize_rut( '76.123.45.6-7' ) === '761234567', 'inner dot placement is presentation, not identity' );

/* --- Contract v1 parsing: explicit columns, explicit identity, bounded --- */
$csv = "id_venta,fecha,rut,total\n"
	. "V-0001,2026-03-15,76123456-7,1250000\n"
	. "V-0002,2026-05-02,\"76.123.456-7\",890.000\n"
	. "V-0003,2026-06-11,,450000\n"
	. "V-0004,2026-06-20,76.999.999-9,\n";
$parsed = fpw_sales_parse_csv( $csv );
check( $parsed['ok'] === true, 'a representative contract-v1 file parses' );
$batch = $parsed['batch'];
check( count( $batch['rows'] ) === 4, 'every well-formed row becomes an importable candidate' );
check( $batch['rows'][0] === array( 'line' => 2, 'id' => 'V-0001', 'date' => '2026-03-15', 'rut' => '76123456-7', 'rut_norm' => '761234567', 'total' => 1250000 ), 'the row snapshot keeps the source id verbatim, the ISO date, the written RUT plus its normalized key and the parsed integer total' );
check( $batch['rows'][1]['rut_norm'] === '761234567' && $batch['rows'][1]['total'] === 890000, 'an equivalently formatted RUT collapses to the same key; thousands dots are presentation' );
check( $batch['rows'][2]['rut_norm'] === '' && $batch['rows'][2]['total'] === 450000, 'a sale without RUT stays importable but WITHOUT a resolved association' );
check( $batch['rows'][3]['total'] === null, 'an empty total is an absent value, never zero' );
check( $batch['sin_asociacion_total'] === 1, 'the unresolved association is counted, not silently dropped' );

$semis = "id_venta;fecha;rut;total;canal\r\nV-100;2026-01-31;76.123.456-7;1000;sucursal\r\n";
$parsed = fpw_sales_parse_csv( $semis );
check( $parsed['ok'] === true && count( $parsed['batch']['rows'] ) === 1 && $parsed['batch']['rows'][0]['total'] === 1000, 'semicolon-separated files (and CRLF) parse' );
check( $parsed['batch']['ignored_columns'] === array( 'canal' ), 'columns the contract does not support are reported and ignored, never interpreted' );

$r = fpw_sales_parse_csv( 'id_venta,rut' );
check( $r['ok'] === false && str_contains( $r['error'], 'fecha' ), 'a file missing the fecha column is rejected explicitly' );
$r = fpw_sales_parse_csv( 'id_venta,fecha' );
check( $r['ok'] === false && str_contains( $r['error'], 'rut' ), 'a file missing the rut column is rejected explicitly' );
$r = fpw_sales_parse_csv( 'fecha,rut' );
check( $r['ok'] === false && str_contains( $r['error'], 'id_venta' ), 'a file without the source-identity column is rejected: identity is never improvised' );
check( fpw_sales_parse_csv( "fecha,id_venta,rut\n2026-01-01,V-1,76123456-7" )['ok'] === true, 'column ORDER does not matter, only the exact contract names' );
check( fpw_sales_parse_csv( "PK\x03\x04 binario" )['ok'] === false, 'a binary/ZIP workbook (macro container) is rejected, never executed or parsed' );
check( fpw_sales_parse_csv( "id_venta,fecha,rut\nV-1,2026-01-01,\xFF\xFE" )['ok'] === false, 'a non-UTF-8 file is rejected explicitly' );
$many = "id_venta,fecha,rut\n" . implode( "\n", array_map( fn( $i ) => "V-$i,2026-01-01,76123456-7", range( 1, FPW_SALES_MAX_ROWS + 1 ) ) );
check( fpw_sales_parse_csv( $many )['ok'] === false && str_contains( fpw_sales_parse_csv( $many )['error'], (string) FPW_SALES_MAX_ROWS ), 'a file beyond the bounded row cap is rejected whole (no partial staging)' );
$badRows = "id_venta,fecha,rut,total\n"
	. ",2026-01-01,76123456-7,100\n"
	. "V-2,15/06/2026,76123456-7,100\n"
	. "V-3,2026-13-01,76123456-7,100\n"
	. "V-4,2026-01-02,76123456-7,mucho\n"
	. "V-5,2026-01-03,76.1.23.456-7 X,100\n"
	. "V-6,2026-01-04,76123456-7,100,,\n";
$parsed = fpw_sales_parse_csv( $badRows );
check( $parsed['ok'] === true && count( $parsed['batch']['rows'] ) === 2, 'only well-formed rows become importable candidates (an unreadable RUT still imports, unassociated)' );
$reasons = implode( ' | ', array_column( $parsed['batch']['errores'], 'reason' ) );
check( count( $parsed['batch']['errores'] ) === 4, 'every malformed row is reported individually' );
check( $parsed['batch']['sin_asociacion_total'] === 1, 'a row with an unreadable RUT stays importable but without association' );
check( str_contains( $reasons, 'id_venta' ), 'a row without the source identity is an explicit error — the importer never invents one' );
check( str_contains( $reasons, 'fecha' ), 'dates outside the strict contract format are row errors' );
check( str_contains( $reasons, 'total' ), 'an illegible amount is a row error, never a guessed number' );
$dups = "id_venta,fecha,rut,total\nV-1,2026-01-01,76123456-7,100\nV-1,2026-02-02,76123456-7,200\n";
$parsed = fpw_sales_parse_csv( $dups );
check( $parsed['ok'] === true && count( $parsed['batch']['rows'] ) === 0 && count( $parsed['batch']['errores'] ) === 2, 'a duplicated in-file identity is a conflict: BOTH rows drop instead of guessing the winner' );

/* --- Upload/preview never change history; a new upload replaces the pending slot --- */
$r0 = fpw_sales_process_upload_string( $csv, 'ventas-sinteticas.csv' );
check( $r0['ok'] === true, 'the staged upload reports cleanly' );
$pending = fpw_sales_pending_batch();
check( is_array( $pending ) && preg_match( '/^[0-9a-f]{32}$/', (string) $pending['token'] ) && 'ventas-sinteticas.csv' === $pending['filename'] && 'dueño' === $pending['actor'], 'the pending batch binds token, source filename and actor for the review' );
check( count( $pending['rows'] ) === 4 && fpw_sales_register()['sales'] === array(), 'upload/preview stage a proposal only: the Purchase History is untouched' );
check( ! isset( $GLOBALS['fpws_table']['fpw_draft_68'] ), 'the import writes nothing beside its own rows — no draft, request or document is touched' );
$r1 = fpw_sales_process_upload_string( $csv, 'otra-carga.csv' );
check( fpw_sales_pending_batch()['token'] !== $pending['token'] && fpw_sales_pending_batch()['filename'] === 'otra-carga.csv', 'a new upload REPLACES the pending slot (one reviewed batch at a time)' );
$stale = fpw_sales_confirm( $pending['token'] );
check( $stale['ok'] === false && str_contains( $stale['message'], 'ya no está disponible' ), 'confirming a replaced (stale) batch is rejected explicitly' );

/* --- Confirmation applies the reviewed batch exactly once, with provenance --- */
$applied = fpw_sales_confirm( fpw_sales_pending_batch()['token'] );
check( $applied['ok'] === true && 4 === $applied['receipt']['aplicadas'] && 1 === $applied['receipt']['sin_asociacion'], 'confirmation applies the reviewed rows and reports the unresolved association' );
$register = fpw_sales_register();
check( count( $register['sales'] ) === 4 && fpw_sales_pending_batch() === null, 'the register now holds the batch and the pending slot is consumed' );
$receipt = fpw_sales_receipt( $applied['receipt']['token'] );
check( is_array( $receipt ) && 'dueño' === $receipt['actor'] && 'otra-carga.csv' === $receipt['filename'] && $receipt['at'] > 0, 'the receipt records actor, time and source file — consultable provenance' );
check( count( fpw_sales_receipts( 10 ) ) === 1, 'the receipt is listable on the screen' );

/* History lookup: equivalent RUT formats find the same customer; scoping holds. */
$h1 = fpw_sales_history_for_rut( '761234567' );
check( is_array( $h1 ) && count( $h1['sales'] ) === 2, 'a normalized RUT finds the sales imported under both formats' );
check( $h1['sales'][0]['date'] === '2026-05-02' && $h1['sales'][0]['total'] === 890000, 'the newest transaction renders first' );
$ids1 = array_column( $h1['sales'], 'id' );
check( $ids1 === array( 'V-0002', 'V-0001' ), 'only the two associated sales belong to this customer; the unassociated row belongs to nobody' );
$h2 = fpw_sales_history_for_rut( '769999999' );
check( count( $h2['sales'] ) === 1 && 'V-0004' === $h2['sales'][0]['id'], 'a different RUT sees only its own transaction' );
check( fpw_sales_history_for_rut( '' ) === null && fpw_sales_history_for_rut( 'zzz' ) === null, 'an unusable RUT returns no history at all — no invented association' );
$fresh = $h1['freshness'];
check( is_array( $fresh ) && 'otra-carga.csv' === $fresh['filename'] && $fresh['at'] > 0, 'the history carries its freshness and provenance (which import supplies it)' );

/* Re-import of the SAME content: no duplicated purchases. */
$r2 = fpw_sales_process_upload_string( $csv, 'ventas-sinteticas.csv' );
$before = $GLOBALS['fpws_table']['fpw_sales_register'];
$reapplied = fpw_sales_confirm( fpw_sales_pending_batch()['token'] );
check( $reapplied['ok'] === true && 0 === $reapplied['receipt']['aplicadas'] && 4 === $reapplied['receipt']['ya_importadas'], 're-confirming the same reviewed content applies nothing new and reports the skips' );
check( $GLOBALS['fpws_table']['fpw_sales_register'] === $before, 'the register is byte-identical after the repeat: repetition never duplicates purchases' );

/* Conflicting content: reported explicitly; the register keeps the imported truth. */
$conflict = "id_venta,fecha,rut,total\nV-0001,2026-03-15,76123456-7,999999\nV-0007,2026-07-07,76123456-7,700000\n";
fpw_sales_process_upload_string( $conflict, 'cambiada.csv' );
$conflicted = fpw_sales_confirm( fpw_sales_pending_batch()['token'] );
check( $conflicted['ok'] === true && 1 === $conflicted['receipt']['aplicadas'] && 1 === $conflicted['receipt']['conflictos_total'], 'a changed transaction is reported as a conflict, not silently rewritten' );
$register = fpw_sales_register();
$byid = array_column( $register['sales'], null, 'id' );
check( $byid['V-0001']['total'] === 1250000 && $byid['V-0007']['total'] === 700000, 'the conflicting id keeps its original imported values while the new id appends — explicit update-vs-append semantics' );

/* Double confirmation: the receipt INSERT decides — one apply, ever. */
$GLOBALS['fpws_table'][ FPW_SALES_RECEIPT_PREFIX . 'tok' ] = wp_json_encode( array( 'actor' => 'x', 'at' => time() ) );
$GLOBALS['fpws_table']['fpw_sales_batch'] = wp_json_encode( array( 'token' => 'tok', 'rows' => array( array( 'line' => 2, 'id' => 'V-9', 'date' => '2026-01-01', 'rut' => 'x', 'rut_norm' => '', 'total' => 1 ) ) ) );
$double = fpw_sales_confirm( 'tok' );
check( $double['ok'] === false && str_contains( $double['message'], 'ya fue aplicada' ), 'a batch whose receipt already exists is refused before any merge (concurrent double-apply loses)' );
check( ! isset( $byid['V-9'] ) && ! isset( array_column( fpw_sales_register()['sales'], null, 'id' )['V-9'] ), 'the refused apply merged nothing' );

/* Cancellation: no effects at all. */
fpw_sales_process_upload_string( "id_venta,fecha,rut,total\nV-8,2026-08-08,76123456-7,800000\n", 'por-cancelar.csv' );
$before = $GLOBALS['fpws_table']['fpw_sales_register'];
$token = fpw_sales_pending_batch()['token'];
$cancelled = fpw_sales_cancel( $token );
check( $cancelled['ok'] === true && str_contains( $cancelled['message'], 'sin efectos' ), 'cancellation reports its harmlessness' );
check( fpw_sales_pending_batch() === null && $GLOBALS['fpws_table']['fpw_sales_register'] === $before, 'a cancelled preview changes nothing in the history' );
$late = fpw_sales_confirm( $token );
check( $late['ok'] === false && str_contains( $late['message'], 'ya no está disponible' ), 'confirming a cancelled batch is an explicit stale result, never an apply' );
$wrong = fpw_sales_cancel( str_repeat( 'a', 32 ) );
check( $wrong['ok'] === false, 'a cancel with a wrong token changes nothing and says so' );

/* Resource bound: the register cap refuses the apply explicitly, batch stays pending. */
$GLOBALS['fpws_filters']['fpw_sales_max_sales'] = 5;

fpw_sales_process_upload_string( "id_venta,fecha,rut,total\nV-A,2026-01-01,76123456-7,1\nV-B,2026-01-02,76123456-7,2\n", 'over.csv' );
$capped = fpw_sales_confirm( fpw_sales_pending_batch()['token'] );
check( $capped['ok'] === false && str_contains( $capped['message'], 'máximo' ), 'an apply beyond the register cap is refused explicitly' );
check( is_array( fpw_sales_pending_batch() ) && count( fpw_sales_register()['sales'] ) === 5, 'the refused batch stays pending and the register is intact' );
unset( $GLOBALS['fpws_filters']['fpw_sales_max_sales'] );
fpw_sales_cancel( fpw_sales_pending_batch()['token'] );

/* --- The draft history section: «Sin historial asociado», never «Cliente nuevo» --- */
$GLOBALS['fpws_caps'] = array( 'manage_woocommerce' => true );
$draft = $GLOBALS['fpws_draft_68'];
$html = fpw_quote_draft_markup( fpws_make_order( 68, '76.543.210-K' ), $draft );
check( str_contains( $html, 'Sin historial asociado' ), 'before any match, the draft says «Sin historial asociado»' );
check( ! str_contains( $html, 'Cliente nuevo' ), 'missing history is never presented as a customer verdict' );

fpw_sales_process_upload_string( "id_venta,fecha,rut,total\nH-1,2025-11-03,76.543.210-K,240000\nH-2,2026-02-27,76543210K,180000\n", 'historial-k.csv' );
fpw_sales_confirm( fpw_sales_pending_batch()['token'] );
$html = fpw_quote_draft_markup( fpws_make_order( 68, '76.543.210-K' ), $draft );
check( str_contains( $html, 'H-1' ) && str_contains( $html, 'H-2' ), 'the draft now renders the imported transactions beside the request' );
check( str_contains( $html, '2025-11-03' ) && str_contains( $html, '240.000 CLP' ), 'dates and totals render in the supported-source shape (no fabricated metrics)' );
check( ! str_contains( $html, '$' ), 'no price-notation amounts appear on the draft: history totals are not draft prices' );
check( str_contains( $html, 'historial-k.csv' ) && str_contains( $html, 'Registro de ventas' ), 'the history section names its provenance/freshness' );
check( str_contains( $html, 'detalle por producto' ), 'the view states that per-product detail is not supported by the source' );
check( str_contains( $html, 'page=fpw-sales-import' ), 'the section links the owner to the importer' );
$htmlNobody = fpw_quote_draft_markup( fpws_make_order( 91, '11.222.333-4' ), array( 'schema' => 1, 'order_id' => 91, 'identity' => array( 'rut' => '11.222.333-4' ) ) );
check( str_contains( $htmlNobody, 'Sin historial asociado' ) && ! str_contains( $htmlNobody, 'Cliente nuevo' ), 'a valid RUT without imported sales reads as unresolved, not as a verdict' );
$htmlNoRut = fpw_quote_draft_markup( fpws_make_order( 92, '' ), array( 'schema' => 1, 'order_id' => 92, 'identity' => array( 'rut' => '' ) ) );
check( str_contains( $htmlNoRut, 'Sin historial asociado' ), 'a request without a usable RUT stays without a resolved association' );

/* Requests never become sales: the register holds ONLY imported transactions. */
$register_ids = array_column( fpw_sales_register()['sales'], 'id' );
check( count( $register_ids ) === 7 && array( 'V-0001', 'V-0002', 'V-0003', 'V-0004', 'V-0007', 'H-1', 'H-2' ) === array_values( array_intersect( array( 'V-0001', 'V-0002', 'V-0003', 'V-0004', 'V-0007', 'H-1', 'H-2' ), $register_ids ) ), 'quote requests and drafts never appear as completed sales: the register holds exactly the imported transactions' );
check( ! isset( $GLOBALS['fpws_table']['fpw_draft_68'] ), 'no import ever wrote or rewrote a draft row' );

/* --- The import screen: authorization and CSRF boundaries --- */
$GLOBALS['fpws_caps'] = array( 'read' => true, 'manage_freeplast_quotes' => true, 'edit_shop_orders' => true, 'edit_others_shop_orders' => true );
$_POST = array( 'fpw_sales_action' => 'upload' );
try {
	ob_start(); fpw_render_sales_import_screen(); ob_end_clean();
	check( false, 'a valid ventas session must be denied the import screen' );
} catch ( FPWS_Die $e ) {
	check( $e->getMessage() === '403', 'ventas receives the 403 permission denial — attributable to permissions, never to a nonce' );
}
$GLOBALS['fpws_caps'] = array( 'manage_woocommerce' => true );
$_POST = array( 'fpw_sales_action' => 'confirm', 'fpw_sales_token' => 'tok', 'fpw_sales_nonce' => 'WRONG' );
try {
	ob_start(); fpw_render_sales_import_screen(); ob_end_clean();
	check( false, 'a confirm POST without a valid nonce must be refused' );
} catch ( FPWS_Die $e ) {
	check( $e->getMessage() === '403', 'the CSRF boundary refuses a nonce-less confirm before any state change' );
}

/* The applied-batch banner: a confirm POST routed through the screen renders
 * the explicit result with its counts and source — and really consumes the
 * batch (this is the first POST the screen processes). */
$_POST = array();
fpw_sales_process_upload_string( "id_venta,fecha,rut,total\nH-3,2026-03-03,76.543.210-K,111\n", 'banner.csv' );
$_POST = array( 'fpw_sales_action' => 'confirm', 'fpw_sales_token' => fpw_sales_pending_batch()['token'], 'fpw_sales_nonce' => 'good-' . FPW_SALES_NONCE_CONFIRM );
$_REQUEST = $_POST;
ob_start(); fpw_render_sales_import_screen(); $page = ob_get_clean();
$_POST = array();
$_REQUEST = array();
check( str_contains( $page, 'Importación aplicada: 1 ventas nuevas' ) && str_contains( $page, 'banner.csv' ), 'a confirmed POST renders the explicit result with its counts and source' );
check( fpw_sales_pending_batch() === null, 'the POSTed confirm consumed the pending slot' );

/* The owner's screen: contract, CSRF-carrying forms, pending review, receipts. */
fpw_sales_process_upload_string( "id_venta,fecha,rut,total\nP-1,2026-09-01,76123456-7,5000\n", 'pendiente.csv' );
ob_start(); fpw_render_sales_import_screen(); $page = ob_get_clean();
check( str_contains( $page, 'Importar ventas' ) && str_contains( $page, 'id_venta' ), 'the owner renders the import screen with its contract' );
check( str_contains( $page, 'name="fpw_sales_nonce"' ) && str_contains( $page, 'multipart/form-data' ), 'the upload form carries its CSRF nonce' );
check( substr_count( $page, 'fpw_sales_nonce' ) >= 3, 'upload, confirm and cancel each carry their own nonce' );
check( str_contains( $page, 'pendiente.csv' ) && str_contains( $page, 'Confirmar e importar' ) && str_contains( $page, 'Cancelar sin efectos' ), 'the pending preview renders its review, confirm and cancel actions' );
check( str_contains( $page, $applied['receipt']['token'] ), 'the applied receipts are consultable on the screen with their token' );
check( str_contains( $page, '@media (min-width: 782px)' ), 'the screen is authored mobile-first with a desktop enhancement' );
check( str_contains( $page, 'muestra real' ), 'the screen names the standing external blocker: the definitive contract awaits the real Sales Register sample' );

echo "sales register: $assertions offline checks passed (issue #54)\n";
