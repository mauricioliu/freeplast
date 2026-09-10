<?php
/**
 * Registro de ventas importado — issue #54, corte 5 de #49.
 *
 * The owner imports the Sales Register manually (upload → preview → explicit
 * confirm, or cancel) and every Quotation Draft reads the actual Purchase
 * History beside the request, matched primarily by normalized company RUT.
 *
 * Identity contract v1: each row carries its own source identity (`id_venta`).
 * The importer NEVER derives a sale identity from the RUT, a date or an
 * amount, and never invents one for a row that lacks it — those rows are
 * reported as errors. The definitive column set and update-vs-append
 * semantics remain an explicit external prerequisite (the real sample has not
 * been examined); v1 is deliberately: identical re-import = no-op, conflicting
 * content = explicit conflict that keeps the imported truth, new id = append.
 *
 * Storage follows the adapter's durable options-row pattern (direct SQL, no
 * second record store): one register row, ONE pending-batch slot (a new
 * upload replaces it, so a stale preview can never silently apply), and one
 * receipt per applied batch written as a PLAIN INSERT — the exactly-once
 * anchor that makes repeated confirmation merge nothing twice.
 *
 * The upload is bounded text CSV (UTF-8, 2 MB, capped rows and cells).
 * Binary/ZIP workbooks are refused outright and no cell is ever evaluated:
 * there are no macros, formulas or external references in the read path.
 * Nothing here touches the Price List, drafts, requests or issued documents,
 * and requests/quotations never become completed sales.
 *
 * Every mutation (upload, confirm, cancel) is owner-only — the unlisted
 * wp-admin screen is keyed on manage_woocommerce, which the Ventas role's
 * four approved caps do not include — and carries its own nonce: knowing the
 * link, the batch token or a nonce grants nothing.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'FPW_SALES_SCREEN', 'fpw-sales-import' );
define( 'FPW_SALES_REGISTER_ROW', 'fpw_sales_register' );
define( 'FPW_SALES_PENDING_ROW', 'fpw_sales_batch' );
define( 'FPW_SALES_RECEIPT_PREFIX', 'fpw_sales_receipt_' );
define( 'FPW_SALES_NONCE_UPLOAD', 'fpw_sales_upload' );
define( 'FPW_SALES_NONCE_CONFIRM', 'fpw_sales_confirm' );
define( 'FPW_SALES_NONCE_CANCEL', 'fpw_sales_cancel' );
define( 'FPW_SALES_MAX_BYTES', 2097152 ); // 2 MB per upload
define( 'FPW_SALES_MAX_ROWS', 5000 );     // data rows per batch
define( 'FPW_SALES_MAX_SALES', 20000 );   // sales stored in the register
define( 'FPW_SALES_MAX_CELL', 200 );
define( 'FPW_SALES_MAX_ID', 100 );
define( 'FPW_SALES_PREVIEW_ROWS', 40 );
define( 'FPW_SALES_LIST_LIMIT', 100 );
define( 'FPW_SALES_RECEIPT_KEEP', 20 );

/**
 * The customer key: normalize a company RUT's presentation so equivalent
 * formats match. Deliberately shape-level validation (7-8 digits + verifier
 * 0-9/K); a deeper mod-11 rejection would silently unassociate rows over
 * transcription details — a data decision that belongs to the real-sample
 * agreement, not to the importer. Returns '' when the RUT is unusable:
 * missing, malformed, ambiguous (leftover tokens) or oversized.
 */
function fpw_sales_normalize_rut( string $raw ): string {
	$stripped = preg_replace( '/[\s.\-]/', '', substr( trim( $raw ), 0, 20 ) );
	if ( ! is_string( $stripped ) ) { return ''; }
	$clean = strtoupper( $stripped );
	if ( '' === $clean || ! preg_match( '/^\d{7,8}[0-9K]$/', $clean ) ) { return ''; }
	return $clean;
}

/**
 * Contract v1 parser: pure text in, proposed batch out — the live history is
 * never consulted and never touched here. Required columns: id_venta (the
 * source identity), fecha (strict AAAA-MM-DD), rut (its VALUE may be empty or
 * unusable: the sale imports, the association stays unresolved). Optional:
 * total (integer CLP, thousands dots tolerated). Unknown columns are reported
 * and ignored; malformed rows are reported individually and never imported;
 * a duplicated in-file identity is a conflict — BOTH copies drop instead of
 * guessing a winner. The whole file is refused (nothing staged) when it is
 * not bounded text CSV or misses a required column.
 *
 * @return array{ok:bool,error?:string,batch?:array}
 */
function fpw_sales_parse_csv( string $content ): array {
	if ( str_starts_with( $content, "PK\x03\x04" ) ) {
		return array( 'ok' => false, 'error' => 'El archivo parece un libro de Excel comprimido (ZIP): este importador solo recibe CSV de texto, sin macros ni fórmulas. Guarda la planilla como CSV UTF-8 y súbelo de nuevo.' );
	}
	if ( str_contains( $content, "\0" ) ) { return array( 'ok' => false, 'error' => 'El archivo contiene bytes nulos: no parece un CSV de texto.' ); }
	if ( strlen( $content ) > FPW_SALES_MAX_BYTES ) { return array( 'ok' => false, 'error' => 'El archivo supera el máximo de 2 MB por carga.' ); }
	if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $content, 'UTF-8' ) ) { return array( 'ok' => false, 'error' => 'El archivo debe estar codificado en UTF-8.' ); }
	if ( str_starts_with( $content, "\xEF\xBB\xBF" ) ) { $content = substr( $content, 3 ); }
	$lines = preg_split( '/\r\n|\n|\r/', $content );
	if ( ! is_array( $lines ) ) { return array( 'ok' => false, 'error' => 'No se pudo leer el archivo como texto.' ); }
	while ( ! empty( $lines ) && '' === trim( (string) end( $lines ) ) ) { array_pop( $lines ); }
	if ( empty( $lines ) ) { return array( 'ok' => false, 'error' => 'El archivo está vacío.' ); }
	$header_line = (string) array_shift( $lines );
	$delim = ',';
	foreach ( array( ';', "\t" ) as $candidate ) {
		if ( substr_count( $header_line, $candidate ) > substr_count( $header_line, $delim ) ) { $delim = $candidate; }
	}
	$map = array();
	$ignored = array();
	foreach ( str_getcsv( $header_line, $delim ) as $index => $cell ) {
		$name = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( (string) $cell ) ) : strtolower( trim( (string) $cell ) );
		if ( '' === $name ) { continue; }
		if ( in_array( $name, array( 'id_venta', 'fecha', 'rut', 'total' ), true ) ) { $map[ $name ] = (int) $index; }
		else { $ignored[] = $name; }
	}
	$missing = array_diff( array( 'id_venta', 'fecha', 'rut' ), array_keys( $map ) );
	if ( ! empty( $missing ) ) {
		return array( 'ok' => false, 'error' => 'Faltan columnas obligatorias del contrato: ' . implode( ', ', $missing ) . '. El contrato v1 pide id_venta (identidad de la venta), fecha (AAAA-MM-DD) y rut; total es opcional.' );
	}
	$rows = array();
	$errores = array();
	$sin_asociacion = array();
	$data_lines = 0;
	foreach ( $lines as $i => $line ) {
		if ( '' === trim( $line ) ) { continue; }
		$data_lines++;
		if ( $data_lines > FPW_SALES_MAX_ROWS ) {
			return array( 'ok' => false, 'error' => 'El archivo supera el máximo de ' . FPW_SALES_MAX_ROWS . ' filas por carga: divídelo en lotes menores.' );
		}
		$line_no = $i + 2;
		$cells = str_getcsv( $line, $delim );
		$get = static function ( string $name ) use ( $map, $cells ): string {
			$index = $map[ $name ] ?? null;
			return null === $index ? '' : mb_substr( trim( (string) ( $cells[ $index ] ?? '' ) ), 0, FPW_SALES_MAX_CELL );
		};
		$id = $get( 'id_venta' );
		$date_raw = $get( 'fecha' );
		$rut_raw = $get( 'rut' );
		$total_raw = $get( 'total' );
		if ( '' === $id ) { $errores[] = array( 'line' => $line_no, 'reason' => 'id_venta vacío: la identidad de cada venta debe venir del archivo y nunca se inventa' ); continue; }
		if ( mb_strlen( $id ) > FPW_SALES_MAX_ID ) { $errores[] = array( 'line' => $line_no, 'reason' => 'id_venta demasiado largo (máximo ' . FPW_SALES_MAX_ID . ' caracteres)' ); continue; }
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_raw ) || ! checkdate( (int) substr( $date_raw, 5, 2 ), (int) substr( $date_raw, 8, 2 ), (int) substr( $date_raw, 0, 4 ) ) ) {
			$errores[] = array( 'line' => $line_no, 'reason' => 'fecha inválida: el contrato pide AAAA-MM-DD' ); continue;
		}
		$total = null;
		if ( '' !== $total_raw ) {
			if ( preg_match( '/^\d{1,3}(\.\d{3})+$/', $total_raw ) ) { $total = (int) str_replace( '.', '', $total_raw ); }
			elseif ( preg_match( '/^\d{1,9}$/', $total_raw ) ) { $total = (int) $total_raw; }
			else { $errores[] = array( 'line' => $line_no, 'reason' => 'total ilegible: usa enteros de pesos (los puntos de miles son opcionales) y deja vacío lo que no esté en el archivo' ); continue; }
		}
		$rut_norm = fpw_sales_normalize_rut( $rut_raw );
		if ( '' === $rut_norm ) { $sin_asociacion[] = array( 'line' => $line_no, 'rut' => $rut_raw ); }
		$rows[] = array( 'line' => $line_no, 'id' => $id, 'date' => $date_raw, 'rut' => $rut_raw, 'rut_norm' => $rut_norm, 'total' => $total );
	}
	$counts = array_count_values( array_column( $rows, 'id' ) );
	$dups = array_keys( array_filter( $counts, static fn( $n ) => $n > 1 ) );
	if ( ! empty( $dups ) ) {
		foreach ( $rows as $row ) {
			if ( in_array( $row['id'], $dups, true ) ) { $errores[] = array( 'line' => $row['line'], 'reason' => 'id_venta duplicado en el archivo (' . $row['id'] . '): ninguna copia se importa' ); }
		}
		$rows = array_values( array_filter( $rows, static fn( $row ) => ! in_array( $row['id'], $dups, true ) ) );
	}
	return array( 'ok' => true, 'batch' => array(
		'rows'                 => $rows,
		'ignored_columns'      => $ignored,
		'errores'              => array_slice( $errores, 0, FPW_SALES_LIST_LIMIT ),
		'errores_total'        => count( $errores ),
		'sin_asociacion'       => array_slice( $sin_asociacion, 0, FPW_SALES_LIST_LIMIT ),
		'sin_asociacion_total' => count( $sin_asociacion ),
	) );
}

/** The acting owner's login, for the consultable provenance receipt. */
function fpw_sales_actor(): string {
	if ( ! function_exists( 'wp_get_current_user' ) ) { return 'sistema'; }
	$user = wp_get_current_user();
	return ( is_object( $user ) && ! empty( $user->user_login ) ) ? (string) $user->user_login : 'sistema';
}

/** One JSON options row, read straight from the database — never through the per-request cache. */
function fpw_sales_read_row( string $name ): ?array {
	global $wpdb;
	$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
	if ( ! is_string( $raw ) || '' === $raw ) { return null; }
	$payload = json_decode( $raw, true );
	return is_array( $payload ) ? $payload : null;
}

/** The imported Purchase History: the ONLY source of completed sales. Requests, quotations and drafts never appear here. */
function fpw_sales_register(): array {
	$row = fpw_sales_read_row( FPW_SALES_REGISTER_ROW );
	if ( ! is_array( $row ) || ! isset( $row['sales'] ) || ! is_array( $row['sales'] ) ) { return array( 'schema' => 1, 'sales' => array() ); }
	return $row;
}

/** Upsert one JSON options row — INSERT when absent, UPDATE when present. The register and the pending slot are both written this way. */
function fpw_sales_write_row( string $name, array $payload ): void {
	global $wpdb;
	$json = (string) wp_json_encode( $payload );
	if ( null === fpw_sales_read_row( $name ) ) {
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )", $name, $json ) );
		return;
	}
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", $json, $name ) );
}

/** The ONE pending batch slot: a new upload replaces it, so a stale reviewed batch can never silently apply. */
function fpw_sales_pending_batch(): ?array {
	return fpw_sales_read_row( FPW_SALES_PENDING_ROW );
}

function fpw_sales_clear_pending(): void {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", FPW_SALES_PENDING_ROW ) );
}

/** The exactly-once anchor: a plain INSERT — the second apply of the same batch loses here, before any merge. Receipt times are kept monotonic (same-second applies advance one second) so "newest" is always the truly last apply. */
function fpw_sales_insert_receipt( string $token, array $receipt ): bool {
	global $wpdb;
	$newest = fpw_sales_receipts( 1 )[0]['at'] ?? 0;
	if ( (int) ( $receipt['at'] ?? 0 ) <= (int) $newest ) { $receipt['at'] = (int) $newest + 1; }
	$was_suppressed = $wpdb->suppress_errors();
	$result = $wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )",
			FPW_SALES_RECEIPT_PREFIX . $token,
			wp_json_encode( $receipt )
		)
	);
	$wpdb->suppress_errors( $was_suppressed );
	return false !== $result && null !== $result;
}

function fpw_sales_receipt( string $token ): ?array {
	return fpw_sales_read_row( FPW_SALES_RECEIPT_PREFIX . $token );
}

/** Applied receipts, newest first — the consultable provenance of the history. */
function fpw_sales_receipts( int $limit = 10 ): array {
	global $wpdb;
	$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( FPW_SALES_RECEIPT_PREFIX ) . '%' ) );
	$all = array();
	foreach ( (array) $names as $name ) {
		$row = fpw_sales_read_row( (string) $name );
		if ( is_array( $row ) ) { $all[] = $row; }
	}
	usort( $all, static fn( $a, $b ) => (int) ( $b['at'] ?? 0 ) <=> (int) ( $a['at'] ?? 0 ) );
	return array_slice( $all, 0, max( 1, $limit ) );
}

/** Bounded retention: old receipts are swept past the keep-list (provenance stays consultable; raw workbooks are never retained). */
function fpw_sales_sweep_receipts(): void {
	global $wpdb;
	$all = fpw_sales_receipts( PHP_INT_MAX );
	foreach ( array_slice( $all, FPW_SALES_RECEIPT_KEEP ) as $old ) {
		$token = (string) ( $old['token'] ?? '' );
		if ( '' !== $token ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", FPW_SALES_RECEIPT_PREFIX . $token ) );
		}
	}
}

/**
 * Parse and stage one upload as the pending preview proposal. The Purchase
 * History is NOT changed: confirmation is a separate, explicit action.
 */
function fpw_sales_process_upload_string( string $content, string $filename ): array {
	$parsed = fpw_sales_parse_csv( $content );
	if ( empty( $parsed['ok'] ) ) { return array( 'ok' => false, 'message' => (string) ( $parsed['error'] ?? 'El archivo fue rechazado.' ) ); }
	$batch = $parsed['batch'];
	$batch['token']    = bin2hex( random_bytes( 16 ) );
	$batch['filename'] = mb_substr( sanitize_text_field( $filename ), 0, 200 );
	$batch['actor']    = fpw_sales_actor();
	$batch['at']       = time();
	fpw_sales_write_row( FPW_SALES_PENDING_ROW, $batch );
	return array( 'ok' => true, 'message' => 'Carga recibida y previsualizada: el historial de compras NO cambió. Revisa la vista previa y confirma para aplicarla, o cancela sin efectos.' );
}

/**
 * Apply the reviewed batch exactly once. The pending slot must still hold the
 * token the owner previewed (a replaced or consumed batch is a stale result,
 * never an apply), the register cap bounds the merge, and the receipt INSERT
 * decides: two concurrent confirmations of the same batch — one winner only.
 * Identical re-imports are no-ops; conflicting content keeps the imported
 * truth and is reported.
 */
function fpw_sales_confirm( string $token ): array {
	if ( '' === $token ) { return array( 'ok' => false, 'message' => 'Falta el identificador de la vista previa; confirma desde la pantalla del importador.' ); }
	$pending = fpw_sales_pending_batch();
	if ( ! is_array( $pending ) ) {
		return array( 'ok' => false, 'message' => 'Esta vista previa ya no está disponible: fue aplicada, cancelada o reemplazada por una carga más reciente. Vuelve a cargar y previsualizar el archivo.' );
	}
	if ( ! hash_equals( (string) ( $pending['token'] ?? '' ), $token ) ) {
		return array( 'ok' => false, 'message' => 'El lote revisado ya no está disponible: la vista previa fue reemplazada por una carga posterior. Vuelve a revisar y confirmar la carga actual.' );
	}
	$register = fpw_sales_register();
	$by_id = array_column( $register['sales'], null, 'id' );
	$aplicadas = 0;
	$ya = 0;
	$conflictos = array();
	foreach ( ( $pending['rows'] ?? array() ) as $row ) {
		$id   = (string) ( $row['id'] ?? '' );
		$prev = $by_id[ $id ] ?? null;
		if ( null === $prev ) { $aplicadas++; continue; }
		$same = ( (string) ( $prev['date'] ?? '' ) === (string) ( $row['date'] ?? '' ) )
			&& ( (string) ( $prev['rut'] ?? '' ) === (string) ( $row['rut_norm'] ?? '' ) )
			&& ( ( $prev['total'] ?? null ) === ( $row['total'] ?? null ) );
		if ( $same ) { $ya++; } else { $conflictos[] = $id; }
	}
	$max_sales = (int) apply_filters( 'fpw_sales_max_sales', FPW_SALES_MAX_SALES );
	if ( count( $register['sales'] ) + $aplicadas > $max_sales ) {
		return array( 'ok' => false, 'message' => 'Aplicar este lote superaría el máximo de ' . $max_sales . ' ventas almacenadas: el lote sigue pendiente y nada fue aplicado.' );
	}
	$now = time();
	$receipt = array(
		'token'             => $token,
		'actor'             => fpw_sales_actor(),
		'at'                => $now,
		'filename'          => (string) ( $pending['filename'] ?? '' ),
		'filas'             => count( ( $pending['rows'] ?? array() ) ),
		'aplicadas'         => $aplicadas,
		'ya_importadas'     => $ya,
		'conflictos'        => array_slice( $conflictos, 0, 50 ),
		'conflictos_total'  => count( $conflictos ),
		'sin_asociacion'    => (int) ( $pending['sin_asociacion_total'] ?? 0 ),
		'errores_total'     => (int) ( $pending['errores_total'] ?? 0 ),
	);
	if ( ! fpw_sales_insert_receipt( $token, $receipt ) ) {
		return array( 'ok' => false, 'message' => 'Esta carga ya fue aplicada (su recibo existe): no se aplicará dos veces. El historial queda como está.' );
	}
	foreach ( ( $pending['rows'] ?? array() ) as $row ) {
		$id = (string) ( $row['id'] ?? '' );
		if ( in_array( $id, $conflictos, true ) || isset( $by_id[ $id ] ) ) { continue; }
		$register['sales'][] = array(
			'id'          => $id,
			'rut'         => (string) ( $row['rut_norm'] ?? '' ),
			'date'        => (string) ( $row['date'] ?? '' ),
			'total'       => $row['total'] ?? null,
			'batch'       => $token,
			'imported_at' => $now,
		);
	}
	fpw_sales_write_row( FPW_SALES_REGISTER_ROW, $register );
	fpw_sales_clear_pending();
	fpw_sales_sweep_receipts();
	$message = 'Importación aplicada: ' . $aplicadas . ' ventas nuevas, ' . $ya . ' ya importadas (sin duplicar), '
		. count( $conflictos ) . ' en conflicto (se conservó lo importado), ' . $receipt['sin_asociacion']
		. ' asociaciones sin resolver, ' . $receipt['errores_total'] . ' filas con error.';
	return array( 'ok' => true, 'message' => $message, 'receipt' => $receipt );
}

/** Cancel the reviewed preview: the register and everything else stay exactly as they were. */
function fpw_sales_cancel( string $token ): array {
	$pending = fpw_sales_pending_batch();
	if ( ! is_array( $pending ) || ! hash_equals( (string) ( $pending['token'] ?? '' ), $token ) ) {
		return array( 'ok' => false, 'message' => 'No hay una vista previa vigente con ese identificador: no se cambió nada.' );
	}
	fpw_sales_clear_pending();
	return array( 'ok' => true, 'message' => 'Vista previa cancelada sin efectos: el historial de compras no cambió.' );
}

/**
 * The Purchase History actually available for one normalized RUT: only the
 * imported register, newest transaction first, with the provenance/freshness
 * of its supplying import. Null when the RUT is unusable — the caller shows
 * the unresolved state instead of inventing an association.
 */
function fpw_sales_history_for_rut( string $rut_norm ): ?array {
	$rut_norm = fpw_sales_normalize_rut( $rut_norm );
	if ( '' === $rut_norm ) { return null; }
	$sales = array();
	foreach ( fpw_sales_register()['sales'] as $sale ) {
		if ( is_array( $sale ) && (string) ( $sale['rut'] ?? '' ) === $rut_norm ) { $sales[] = $sale; }
	}
	usort( $sales, static fn( $a, $b ) => array( (string) ( $b['date'] ?? '' ), (string) ( $b['id'] ?? '' ) ) <=> array( (string) ( $a['date'] ?? '' ), (string) ( $a['id'] ?? '' ) ) );
	$latest = fpw_sales_receipts( 1 )[0] ?? null;
	$freshness = is_array( $latest )
		? array( 'at' => (int) ( $latest['at'] ?? 0 ), 'actor' => (string) ( $latest['actor'] ?? '' ), 'filename' => (string) ( $latest['filename'] ?? '' ) )
		: null;
	return array( 'sales' => $sales, 'freshness' => $freshness );
}

/** The private screen's address. */
function fpw_sales_import_screen_url(): string {
	return admin_url( 'admin.php?page=' . FPW_SALES_SCREEN );
}

/** The uniform denial: private to the owner, stated in Spanish, 403 — attributable to permissions, never to a nonce. */
function fpw_die_sales_import_forbidden(): void {
	wp_die( 'El importador de ventas es privado del dueño: requiere una sesión con permisos de administración de WooCommerce.', '', array( 'response' => 403 ) );
}

/** The private screen: unlisted, keyed on the owner capability. */
add_action( 'admin_menu', 'fpw_sales_register_import_screen' );
function fpw_sales_register_import_screen(): void {
	add_submenu_page( null, 'Importar ventas', 'Importar ventas', 'manage_woocommerce', FPW_SALES_SCREEN, 'fpw_render_sales_import_screen' );
}

/**
 * The three POST actions are processed at admin_init — BEFORE wp-admin prints
 * its header — so every denial is a real HTTP status (403 permissions, 403
 * CSRF) and every result renders once. The stored result is what the screen
 * renders; a second call never re-processes.
 */
add_action( 'admin_init', 'fpw_sales_maybe_handle_actions', 0 );
function fpw_sales_maybe_handle_actions(): void {
	if ( FPW_SALES_SCREEN !== (string) ( $_GET['page'] ?? '' ) ) { return; }
	fpw_sales_handle_actions();
}

/** Capability first, then the nonce-scoped action; the screen renders the explicit result either way. */
function fpw_render_sales_import_screen(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) { fpw_die_sales_import_forbidden(); }
	$banner = fpw_sales_handle_actions();
	echo fpw_sales_import_markup( $banner );
}

/**
 * Route the three POST actions; each carries its own nonce (CSRF) under the
 * owner capability (authorization). Both boundaries are re-checked on every
 * call; the processing itself runs once per request (at admin_init, see
 * fpw_sales_maybe_handle_actions) and a later call just returns the stored
 * result. A nonce failure is an EXPLICIT 403 — never the default 200
 * nonce-ays page — so every denial stays attributable and machine-checkable.
 */
function fpw_sales_handle_actions(): array {
	static $result  = array();
	static $handled = false;
	if ( ! current_user_can( 'manage_woocommerce' ) ) { fpw_die_sales_import_forbidden(); }
	$action = isset( $_POST['fpw_sales_action'] ) ? (string) wp_unslash( $_POST['fpw_sales_action'] ) : '';
	fpw_sales_verify_nonce( $action );
	if ( $handled ) { return $result; }
	$handled = true;
	switch ( $action ) {
		case 'upload':
			$result = fpw_sales_handle_upload();
			break;
		case 'confirm':
			$result = fpw_sales_confirm( fpw_sales_posted_token() );
			break;
		case 'cancel':
			$result = fpw_sales_cancel( fpw_sales_posted_token() );
			break;
	}
	return $result;
}

/** The reviewed batch token as POSTed, '' when absent. */
function fpw_sales_posted_token(): string {
	return isset( $_POST['fpw_sales_token'] ) ? (string) wp_unslash( $_POST['fpw_sales_token'] ) : '';
}

/** The CSRF boundary of every importer action — each action's own nonce, verified server-side, denied 403 with its own message. */
function fpw_sales_verify_nonce( string $action ): void {
	$nonce_action = array(
		'upload'  => FPW_SALES_NONCE_UPLOAD,
		'confirm' => FPW_SALES_NONCE_CONFIRM,
		'cancel'  => FPW_SALES_NONCE_CANCEL,
	)[ $action ] ?? null;
	if ( null === $nonce_action ) { return; }
	$nonce = isset( $_REQUEST['fpw_sales_nonce'] ) ? (string) wp_unslash( $_REQUEST['fpw_sales_nonce'] ) : '';
	if ( '' === $nonce || ! wp_verify_nonce( $nonce, $nonce_action ) ) {
		wp_die( 'La acción no pudo verificarse (nonce inválido o vencido). Vuelve a la pantalla del importador y vuelve a intentarlo: nada se aplicó.', '', array( 'response' => 403 ) );
	}
}

/** The $_FILES boundary of the upload: type/size checks, then the shared text parser. */
function fpw_sales_handle_upload(): array {
	if ( ! isset( $_FILES['fpw_sales_file'] ) || ! is_array( $_FILES['fpw_sales_file'] ) ) {
		return array( 'ok' => false, 'message' => 'No se recibió ningún archivo: elige el CSV y vuelve a intentar.' );
	}
	$error = (int) ( $_FILES['fpw_sales_file']['error'] ?? UPLOAD_ERR_NO_FILE );
	if ( UPLOAD_ERR_OK !== $error ) {
		return array( 'ok' => false, 'message' => ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error )
			? 'El archivo supera el máximo de 2 MB por carga.'
			: 'La carga del archivo falló (código ' . $error . '). Intenta de nuevo.' );
	}
	$tmp = (string) ( $_FILES['fpw_sales_file']['tmp_name'] ?? '' );
	if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) { return array( 'ok' => false, 'message' => 'La carga no provino de un formulario válido.' ); }
	$size = (int) ( $_FILES['fpw_sales_file']['size'] ?? 0 );
	if ( $size <= 0 || $size > FPW_SALES_MAX_BYTES ) { return array( 'ok' => false, 'message' => 'El archivo supera el máximo de 2 MB por carga.' ); }
	$content = file_get_contents( $tmp );
	if ( false === $content ) { return array( 'ok' => false, 'message' => 'No se pudo leer el archivo subido.' ); }
	return fpw_sales_process_upload_string( $content, (string) ( $_FILES['fpw_sales_file']['name'] ?? 'ventas.csv' ) );
}

/** One CLP amount as the source supports it; an absent total is an em dash, never zero. */
function fpw_sales_format_clp( $total ): string {
	return null === $total ? '—' : number_format( (int) $total, 0, ',', '.' ) . ' CLP';
}

/** The mobile-first screen shell and its styles. */
function fpw_sales_screen_shell( string $inner ): string {
	return '<div class="wrap fpw-sales"><style>'
		. '.fpw-sales{max-width:960px;font-size:16px;line-height:1.5}'
		. '.fpw-sales h1{font-size:24px;line-height:1.2;margin:4px 0 4px}'
		. '.fpw-sales__kicker{color:#60626d;margin:12px 0 0}'
		. '.fpw-sales section{border:1px solid #dcdcde;border-radius:8px;padding:14px 16px;background:#fff;margin:16px 0;min-width:0}'
		. '.fpw-sales h2{font-size:16px;margin:0 0 10px}'
		. '.fpw-sales table{width:100%;border-collapse:collapse;margin:8px 0;font-size:14px}'
		. '.fpw-sales th,.fpw-sales td{border:1px solid #e4e4e8;padding:6px 8px;text-align:left;overflow-wrap:anywhere}'
		. '.fpw-sales th{background:#f6f7f7;font-weight:600}'
		. '.fpw-sales code{font-size:13px;overflow-wrap:anywhere}'
		. '.fpw-sales form{margin:12px 0 0}'
		. '.fpw-sales .button{margin-right:8px}'
		. '.fpw-sales__note{color:#60626d;font-size:14px;margin:8px 0 0}'
		. '.fpw-sales__actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:12px}'
		. '@media (min-width: 782px){.fpw-sales__grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.fpw-sales__grid section{margin:0}}'
		. '</style>' . $inner . '<!-- fpw-sales:end -->';
}

/** The action-result banner: the explicit outcome message, success or error. */
function fpw_sales_banner_html( array $banner ): string {
	if ( ! isset( $banner['message'] ) ) { return ''; }
	$class = ! empty( $banner['ok'] ) ? 'notice-success' : 'notice-error';
	return '<div class="notice ' . $class . '"><p>' . esc_html( (string) $banner['message'] ) . '</p></div>';
}

/**
 * The pending review: staged candidate rows, per-row errors and unresolved
 * associations, with the confirm/cancel actions — each behind its own nonce.
 */
function fpw_sales_pending_preview_html(): string {
	$pending = fpw_sales_pending_batch();
	if ( ! is_array( $pending ) ) { return ''; }
	$url           = fpw_sales_import_screen_url();
	$nonce_confirm = wp_nonce_field( FPW_SALES_NONCE_CONFIRM, 'fpw_sales_nonce', true, false );
	$nonce_cancel  = wp_nonce_field( FPW_SALES_NONCE_CANCEL, 'fpw_sales_nonce', true, false );

	$rows = is_array( $pending['rows'] ?? null ) ? $pending['rows'] : array();
	$preview = '';
	foreach ( array_slice( $rows, 0, FPW_SALES_PREVIEW_ROWS ) as $row ) {
		$rut  = (string) ( $row['rut'] ?? '' );
		$norm = (string) ( $row['rut_norm'] ?? '' );
		$preview .= '<tr><td>' . (int) ( $row['line'] ?? 0 ) . '</td><td><code>' . esc_html( (string) ( $row['id'] ?? '' ) ) . '</code></td><td>' . esc_html( (string) ( $row['date'] ?? '' ) ) . '</td><td>' . esc_html( $rut )
			. ( '' !== $norm && $norm !== $rut ? '<br><code>' . esc_html( $norm ) . '</code>' : '' ) . '</td><td>' . esc_html( fpw_sales_format_clp( $row['total'] ?? null ) ) . '</td></tr>';
	}
	$errores_html = '';
	foreach ( ( is_array( $pending['errores'] ?? null ) ? $pending['errores'] : array() ) as $e ) {
		$errores_html .= '<tr><td>' . ( (int) ( $e['line'] ?? 0 ) > 0 ? (int) $e['line'] : '—' ) . '</td><td>' . esc_html( (string) ( $e['reason'] ?? '' ) ) . '</td></tr>';
	}
	$sin_html = '';
	foreach ( ( is_array( $pending['sin_asociacion'] ?? null ) ? $pending['sin_asociacion'] : array() ) as $s ) {
		$sin_html .= '<li>Línea ' . (int) ( $s['line'] ?? 0 ) . ': RUT «' . esc_html( (string) ( $s['rut'] ?? '' ) ) . '» sin asociación resuelta.</li>';
	}

	$token     = (string) ( $pending['token'] ?? '' );
	$sin_total = (int) ( $pending['sin_asociacion_total'] ?? 0 );
	$html = '<section><h2>Vista previa pendiente</h2>'
		. '<p><strong>' . esc_html( (string) ( $pending['filename'] ?? '' ) ) . '</strong> · subida el ' . esc_html( date_i18n( get_option( 'date_format' ), (int) ( $pending['at'] ?? 0 ) ) ) . ' por ' . esc_html( (string) ( $pending['actor'] ?? '' ) ) . '</p>'
		. '<p>' . count( $rows ) . ' filas candidatas · ' . (int) ( $pending['errores_total'] ?? 0 ) . ' filas con error · ' . $sin_total . ' ' . esc_html( 1 === $sin_total ? 'asociación sin resolver' : 'asociaciones sin resolver' )
		. ( ! empty( $pending['ignored_columns'] ) ? ' · columnas ignoradas: ' . esc_html( implode( ', ', (array) $pending['ignored_columns'] ) ) : '' ) . '</p>';
	if ( '' !== $preview ) {
		$html .= '<table><thead><tr><th scope="col">Línea</th><th scope="col">id_venta</th><th scope="col">Fecha</th><th scope="col">RUT</th><th scope="col">Total</th></tr></thead><tbody>' . $preview . '</tbody></table>';
		if ( count( $rows ) > FPW_SALES_PREVIEW_ROWS ) {
			$html .= '<p class="fpw-sales__note">Mostrando las primeras ' . FPW_SALES_PREVIEW_ROWS . ' de ' . count( $rows ) . ' filas.</p>';
		}
	}
	if ( '' !== $errores_html ) {
		$html .= '<h3 style="font-size:14px">Filas con error (no se importan)</h3><table><tbody>' . $errores_html . '</tbody></table>';
	}
	if ( '' !== $sin_html ) {
		$html .= '<h3 style="font-size:14px">Asociaciones sin resolver</h3><ul style="margin:4px 0 0 18px">' . $sin_html . '</ul><p class="fpw-sales__note">Estas ventas se importan sin cliente asociado: ningún borrador las mostrará hasta que la asociación se resuelva con datos reales.</p>';
	}
	$html .= '<div class="fpw-sales__actions">'
		. '<form action="' . esc_url( $url ) . '" method="post" style="margin:0"><input type="hidden" name="fpw_sales_action" value="confirm"><input type="hidden" name="fpw_sales_token" value="' . esc_attr( $token ) . '">' . $nonce_confirm
		. '<button type="submit" class="button button-primary">Confirmar e importar</button></form>'
		. '<form action="' . esc_url( $url ) . '" method="post" style="margin:0"><input type="hidden" name="fpw_sales_action" value="cancel"><input type="hidden" name="fpw_sales_token" value="' . esc_attr( $token ) . '">' . $nonce_cancel
		. '<button type="submit" class="button">Cancelar sin efectos</button></form>'
		. '</div></section>';
	return $html;
}

/** The applied receipts: the consultable provenance of the history. */
function fpw_sales_receipts_html(): string {
	$receipts = fpw_sales_receipts( 10 );
	if ( empty( $receipts ) ) { return ''; }
	$receipt_rows = '';
	foreach ( $receipts as $receipt ) {
		$conflictos = (int) ( $receipt['conflictos_total'] ?? 0 );
		$receipt_rows .= '<tr><td>' . esc_html( date_i18n( get_option( 'date_format' ), (int) ( $receipt['at'] ?? 0 ) ) ) . '</td><td>' . esc_html( (string) ( $receipt['actor'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $receipt['filename'] ?? '' ) ) . '</td>'
			. '<td>' . (int) ( $receipt['aplicadas'] ?? 0 ) . '</td><td>' . (int) ( $receipt['ya_importadas'] ?? 0 ) . '</td><td>' . $conflictos . '</td><td>' . (int) ( $receipt['sin_asociacion'] ?? 0 ) . '</td><td>' . (int) ( $receipt['errores_total'] ?? 0 ) . '</td>'
			. '<td><code>' . esc_html( (string) ( $receipt['token'] ?? '' ) ) . '</code></td></tr>';
	}
	return '<section><h2>Importaciones aplicadas</h2>'
		. '<table><thead><tr><th scope="col">Fecha</th><th scope="col">Hecha por</th><th scope="col">Archivo</th><th scope="col">Nuevas</th><th scope="col">Ya importadas</th><th scope="col">Conflictos</th><th scope="col">Sin asociación</th><th scope="col">Errores</th><th scope="col">Lote</th></tr></thead><tbody>' . $receipt_rows . '</tbody></table>'
		. '<p class="fpw-sales__note">Cada lote aplicado deja este recibo de procedencia: es la frescura que verás junto al historial en cada borrador.</p></section>';
}

/** The screen markup: contract, upload, pending preview, applied receipts. */
function fpw_sales_import_markup( array $banner = array() ): string {
	$url          = fpw_sales_import_screen_url();
	$nonce_upload = wp_nonce_field( FPW_SALES_NONCE_UPLOAD, 'fpw_sales_nonce', true, false );

	$contract = '<section><h2>Contrato de importación (v1)</h2>'
		. '<p>Una fila por venta completada, en CSV de texto UTF-8 (hasta 2 MB, ' . FPW_SALES_MAX_ROWS . ' filas). Columnas exactas:</p>'
		. '<dl><dt><code>id_venta</code></dt><dd>Identificador de la venta en tu registro: LA identidad de cada fila. Nunca se usa el RUT solo ni una combinación de fecha e importe como identidad, y una fila sin este identificador no se importa.</dd>'
		. '<dt><code>fecha</code></dt><dd>Formato AAAA-MM-DD.</dd>'
		. '<dt><code>rut</code></dt><dd>RUT de empresa del comprador. Sin RUT o con un RUT ilegible la venta se importa <strong>sin asociación resuelta</strong>.</dd>'
		. '<dt><code>total</code> (opcional)</dt><dd>Importe total de la transacción en pesos enteros. Si la columna no viene, la vista no muestra montos.</dd></dl>'
		. '<p class="fpw-sales__note">Las columnas no soportadas se informan y se ignoran. Las ventas importadas nunca tocan la lista de precios, los borradores ni los documentos emitidos, y las solicitudes de cotización jamás aparecen como ventas.</p>'
		. '<p class="fpw-sales__note"><strong>El contrato definitivo (columnas, identidad y semántica de actualización) se acordará con la muestra real del Registro de ventas.</strong></p></section>';

	$upload = '<section><h2>Cargar CSV</h2><form action="' . esc_url( $url ) . '" method="post" enctype="multipart/form-data">'
		. '<input type="hidden" name="fpw_sales_action" value="upload">' . $nonce_upload
		. '<p><input type="file" name="fpw_sales_file" accept=".csv,text/csv" required></p>'
		. '<p><button type="submit" class="button button-primary">Previsualizar carga</button></p></form>'
		. '<p class="fpw-sales__note">Subir y previsualizar NO cambia el historial: solo la confirmación aplica el lote revisado.</p></section>';

	return fpw_sales_screen_shell(
		'<h1>Importar ventas</h1>'
		. '<p class="fpw-sales__kicker">Registro de ventas · historial de compras por RUT de empresa</p>'
		. fpw_sales_banner_html( $banner )
		. '<div class="fpw-sales__grid">' . $contract . $upload . '</div>'
		. fpw_sales_pending_preview_html()
		. fpw_sales_receipts_html()
	);
}
