<?php
/**
 * Importación de precios por planilla — issue #53, corte 4 de #49.
 *
 * The owner updates the private Price List (ADR-0008) from a spreadsheet:
 * upload → review → explicit confirm, or cancel. ONLY the confirmation
 * applies the reviewed batch onto fpw_price_list — neither the upload nor
 * the preview changes a single price.
 *
 * Contract v1 (an explicitly versioned technical proposal — the definitive
 * columns, keys and amount interpretation are agreed with the REAL sample,
 * still an external prerequisite): bounded UTF-8 text CSV, one row per
 * identity, exact columns `id_producto`, `id_variacion` (optional) and
 * `precio`. The identity is the NATIVE one (the ids the mantenedor itself
 * shows), carried by the file: no name similarity is ever inferred, no code
 * is invented, a variation must name its own parent (a variation belonging
 * to another product is an explicit ambiguity, never a guess), and a
 * duplicated in-file identity drops BOTH copies instead of picking a winner.
 * ZIP/xlsx workbooks are refused outright — reading a workbook would need an
 * unapproved dependency and there are no macros, formulas or remote
 * references in a bounded text read; nothing is ever evaluated.
 *
 * Apply semantics v1: a bounded MERGE. The confirmed batch sets ONLY the
 * identities it names; every unnamed entry stands (an import never sweeps
 * the list). Empty/zero prices are row errors — v1 never removes a price
 * from the file, because one empty spreadsheet cell must never silently
 * clear maintained prices; removal stays the mantenedor's direct edit.
 * Identical values write nothing: repeating the same import fabricates no
 * commercial change. The apply is all-or-nothing: if the catalog drifted
 * since the preview (a reviewed identity no longer exists), the whole batch
 * is refused demanding re-review, never partially applied.
 *
 * Storage follows the adapter's durable options-row pattern: ONE pending
 * slot (a new upload replaces it, so a stale preview can never silently
 * apply) and one receipt per applied batch written as a PLAIN INSERT — the
 * exactly-once anchor that makes a repeated confirmation of the same batch
 * lose before any merge. Receipts carry actor, time, source filename, batch
 * token and per-outcome counts, are swept past a bounded keep-list, and the
 * uploaded file is never retained. The import writes only the price row and
 * its own rows: products, meta, photos, requests, drafts, work states, the
 * Sales Register and every public surface stay untouched.
 *
 * Every mutation (upload, confirm, cancel) is owner-only — the unlisted
 * wp-admin screen is keyed on manage_woocommerce, which the Ventas role's
 * four approved caps do not include — and carries its own nonce, processed
 * at admin_init before wp-admin prints its header: a nonce failure is an
 * explicit 403. Knowing the link, the batch token or a nonce grants nothing.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'FPW_PRICE_IMPORT_SCREEN', 'fpw-price-import' );
define( 'FPW_PRICE_IMPORT_PENDING_ROW', 'fpw_price_import_batch' );
define( 'FPW_PRICE_IMPORT_RECEIPT_PREFIX', 'fpw_price_import_receipt_' );
define( 'FPW_PRICE_IMPORT_NONCE_UPLOAD', 'fpw_price_import_upload' );
define( 'FPW_PRICE_IMPORT_NONCE_CONFIRM', 'fpw_price_import_confirm' );
define( 'FPW_PRICE_IMPORT_NONCE_CANCEL', 'fpw_price_import_cancel' );
define( 'FPW_PRICE_IMPORT_MAX_BYTES', 2097152 ); // 2 MB per upload
define( 'FPW_PRICE_IMPORT_MAX_ROWS', 2000 );     // data rows per batch
define( 'FPW_PRICE_IMPORT_MAX_CELL', 100 );
define( 'FPW_PRICE_IMPORT_PREVIEW_ROWS', 40 );
define( 'FPW_PRICE_IMPORT_LIST_LIMIT', 100 );
define( 'FPW_PRICE_IMPORT_RECEIPT_KEEP', 20 );

/** The importer screen's address. */
function fpw_price_import_screen_url(): string {
	return admin_url( 'admin.php?page=' . FPW_PRICE_IMPORT_SCREEN );
}

/**
 * The native identities the catalog currently publishes: the product ids and
 * each variation id beside its own parent. The importer resolves nothing by
 * name and invents nothing — a row is only as valid as this index.
 *
 * @return array{products:array<int,true>,variations:array<int,int>}
 */
function fpw_price_import_catalog_index( array $catalog ): array {
	$products   = array();
	$variations = array();
	foreach ( $catalog as $entry ) {
		$product_id = (int) ( $entry['product_id'] ?? 0 );
		if ( $product_id <= 0 ) { continue; }
		$products[ $product_id ] = true;
		foreach ( ( is_array( $entry['variations'] ?? null ) ? $entry['variations'] : array() ) as $variation ) {
			$variation_id = (int) ( $variation['variation_id'] ?? 0 );
			if ( $variation_id > 0 ) { $variations[ $variation_id ] = $product_id; }
		}
	}
	return array( 'products' => $products, 'variations' => $variations );
}

/**
 * Contract v1 parser: pure text in, proposed batch out — the live list is
 * never consulted and never touched here. Required columns: id_producto
 * (the native product id) and precio (integer CLP, thousands dots
 * tolerated, 1–99.999.999; the catalog's technical zero is refused).
 * id_variacion is optional per row: present, it must name a variation OF
 * that same product — a variation belonging to another product is an
 * explicit ambiguity. Unknown columns are reported and ignored; malformed
 * rows are reported individually and never staged; a duplicated in-file
 * identity is a conflict — BOTH copies drop. The whole file is refused
 * (nothing staged) when it is not bounded text CSV or misses a required
 * column. An empty precio is a row error: v1 never removes a price from
 * the file.
 *
 * @return array{ok:bool,error?:string,batch?:array}
 */
function fpw_price_import_parse_csv( string $content, array $catalog ): array {
	if ( str_starts_with( $content, "PK\x03\x04" ) ) {
		return array( 'ok' => false, 'error' => 'El archivo parece un libro de Excel comprimido (ZIP): este importador solo recibe CSV de texto, sin macros ni fórmulas. Guarda la planilla como CSV UTF-8 y súbelo de nuevo.' );
	}
	if ( str_contains( $content, "\0" ) ) { return array( 'ok' => false, 'error' => 'El archivo contiene bytes nulos: no parece un CSV de texto.' ); }
	if ( strlen( $content ) > FPW_PRICE_IMPORT_MAX_BYTES ) { return array( 'ok' => false, 'error' => 'El archivo supera el máximo de 2 MB por carga.' ); }
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
		if ( in_array( $name, array( 'id_producto', 'id_variacion', 'precio' ), true ) ) { $map[ $name ] = (int) $index; }
		else { $ignored[] = $name; }
	}
	$missing = array_diff( array( 'id_producto', 'precio' ), array_keys( $map ) );
	if ( ! empty( $missing ) ) {
		return array( 'ok' => false, 'error' => 'Faltan columnas obligatorias del contrato: ' . implode( ', ', $missing ) . '. El contrato v1 pide id_producto (ID nativo del producto), id_variacion (opcional, ID nativo de la opción) y precio (entero CLP).' );
	}
	$index = fpw_price_import_catalog_index( $catalog );
	$rows = array();
	$errores = array();
	$data_lines = 0;
	foreach ( $lines as $i => $line ) {
		if ( '' === trim( $line ) ) { continue; }
		$data_lines++;
		if ( $data_lines > FPW_PRICE_IMPORT_MAX_ROWS ) {
			return array( 'ok' => false, 'error' => 'El archivo supera el máximo de ' . FPW_PRICE_IMPORT_MAX_ROWS . ' filas por carga: divídelo en lotes menores.' );
		}
		$line_no = $i + 2;
		$cells = str_getcsv( $line, $delim );
		$get = static function ( string $name ) use ( $map, $cells ): string {
			$col = $map[ $name ] ?? null;
			return null === $col ? '' : mb_substr( trim( (string) ( $cells[ $col ] ?? '' ) ), 0, FPW_PRICE_IMPORT_MAX_CELL );
		};
		$product_raw    = $get( 'id_producto' );
		$variation_raw  = $get( 'id_variacion' );
		$price_raw      = $get( 'precio' );
		if ( '' === $product_raw ) { $errores[] = array( 'line' => $line_no, 'reason' => 'id_producto vacío: la identidad de cada precio debe venir del archivo y nunca se infiere del nombre' ); continue; }
		if ( ! preg_match( '/^\d{1,20}$/', $product_raw ) ) { $errores[] = array( 'line' => $line_no, 'reason' => 'id_producto ilegible («' . $product_raw . '»): usa el ID numérico nativo que muestra el mantenedor' ); continue; }
		$product_id = (int) $product_raw;
		if ( ! isset( $index['products'][ $product_id ] ) ) { $errores[] = array( 'line' => $line_no, 'reason' => 'producto desconocido (ID #' . $product_id . '): el catálogo no publica esa identidad y ningún precio se inventa para ella' ); continue; }
		$variation_id = 0;
		if ( '' !== $variation_raw ) {
			if ( ! preg_match( '/^\d{1,20}$/', $variation_raw ) ) { $errores[] = array( 'line' => $line_no, 'reason' => 'id_variacion ilegible («' . $variation_raw . '»): usa el ID numérico nativo de la opción, o deja la celda vacía para poner precio al producto' ); continue; }
			$variation_id = (int) $variation_raw;
			if ( ! isset( $index['variations'][ $variation_id ] ) ) { $errores[] = array( 'line' => $line_no, 'reason' => 'variación desconocida (ID #' . $variation_id . '): el catálogo no publica esa opción y ningún precio se inventa para ella' ); continue; }
			if ( $index['variations'][ $variation_id ] !== $product_id ) {
				$errores[] = array( 'line' => $line_no, 'reason' => 'asociación ambigua: la variación #' . $variation_id . ' pertenece al producto #' . $index['variations'][ $variation_id ] . ', no al #' . $product_id . ' — ninguna asociación se adivina' );
				continue;
			}
		}
		if ( '' === $price_raw ) { $errores[] = array( 'line' => $line_no, 'reason' => 'precio vacío: el contrato v1 no elimina precios desde el archivo; usa el mantenedor para quitar uno' ); continue; }
		if ( preg_match( '/^\d{1,3}(\.\d{3})+$/', $price_raw ) ) { $price_raw = str_replace( '.', '', $price_raw ); }
		if ( ! preg_match( '/^\d{1,8}$/', $price_raw ) ) { $errores[] = array( 'line' => $line_no, 'reason' => 'precio ilegible: usa enteros de pesos entre 1 y 99.999.999 (los puntos de miles son opcionales); una fórmula o un texto nunca se evalúan' ); continue; }
		$price = (int) $price_raw;
		if ( 0 === $price ) { $errores[] = array( 'line' => $line_no, 'reason' => 'precio 0 no está permitido: el cero del catálogo es un centinela técnico, no un precio' ); continue; }
		$rows[] = array(
			'line'         => $line_no,
			'key'          => fpw_price_key( $product_id, $variation_id ),
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'price'        => $price,
		);
	}
	$counts = array_count_values( array_column( $rows, 'key' ) );
	$dups = array_keys( array_filter( $counts, static fn( $n ) => $n > 1 ) );
	if ( ! empty( $dups ) ) {
		foreach ( $rows as $row ) {
			if ( in_array( $row['key'], $dups, true ) ) { $errores[] = array( 'line' => $row['line'], 'reason' => 'identidad duplicada en el archivo (' . $row['key'] . '): ninguna copia se aplica en vez de adivinar cuál gana' ); }
		}
		$rows = array_values( array_filter( $rows, static fn( $row ) => ! in_array( $row['key'], $dups, true ) ) );
	}
	return array( 'ok' => true, 'batch' => array(
		'rows'            => $rows,
		'ignored_columns' => $ignored,
		'errores'         => array_slice( $errores, 0, FPW_PRICE_IMPORT_LIST_LIMIT ),
		'errores_total'   => count( $errores ),
	) );
}

/** One JSON options row, read straight from the database — never through the per-request cache. */
function fpw_price_import_read_row( string $name ): ?array {
	global $wpdb;
	$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
	if ( ! is_string( $raw ) || '' === $raw ) { return null; }
	$payload = json_decode( $raw, true );
	return is_array( $payload ) ? $payload : null;
}

/** Upsert one JSON options row — INSERT when absent, UPDATE when present. */
function fpw_price_import_write_row( string $name, array $payload ): void {
	global $wpdb;
	$json = (string) wp_json_encode( $payload );
	if ( null === fpw_price_import_read_row( $name ) ) {
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )", $name, $json ) );
		return;
	}
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", $json, $name ) );
}

/** The ONE pending batch slot: a new upload replaces it, so a stale reviewed batch can never silently apply. */
function fpw_price_import_pending_batch(): ?array {
	return fpw_price_import_read_row( FPW_PRICE_IMPORT_PENDING_ROW );
}

function fpw_price_import_clear_pending(): void {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", FPW_PRICE_IMPORT_PENDING_ROW ) );
}

/** The exactly-once anchor: a plain INSERT — the second apply of the same batch loses here, before any merge. Receipt times are kept monotonic (same-second applies advance one second) so "newest" is always the truly last apply. */
function fpw_price_import_insert_receipt( string $token, array $receipt ): bool {
	global $wpdb;
	$newest = fpw_price_import_receipts( 1 )[0]['at'] ?? 0;
	if ( (int) ( $receipt['at'] ?? 0 ) <= (int) $newest ) { $receipt['at'] = (int) $newest + 1; }
	$was_suppressed = $wpdb->suppress_errors();
	$result = $wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )",
			FPW_PRICE_IMPORT_RECEIPT_PREFIX . $token,
			wp_json_encode( $receipt )
		)
	);
	$wpdb->suppress_errors( $was_suppressed );
	return false !== $result && null !== $result;
}

function fpw_price_import_receipt( string $token ): ?array {
	return fpw_price_import_read_row( FPW_PRICE_IMPORT_RECEIPT_PREFIX . $token );
}

/** Applied receipts, newest first — the consultable provenance of the list's bulk updates. */
function fpw_price_import_receipts( int $limit = 10 ): array {
	global $wpdb;
	$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( FPW_PRICE_IMPORT_RECEIPT_PREFIX ) . '%' ) );
	$all = array();
	foreach ( (array) $names as $name ) {
		$row = fpw_price_import_read_row( (string) $name );
		if ( is_array( $row ) ) { $all[] = $row; }
	}
	usort( $all, static fn( $a, $b ) => (int) ( $b['at'] ?? 0 ) <=> (int) ( $a['at'] ?? 0 ) );
	return array_slice( $all, 0, max( 1, $limit ) );
}

/** Bounded retention: old receipts are swept past the keep-list (provenance stays consultable; the spreadsheet is never retained). */
function fpw_price_import_sweep_receipts(): void {
	global $wpdb;
	$all = fpw_price_import_receipts( PHP_INT_MAX );
	foreach ( array_slice( $all, FPW_PRICE_IMPORT_RECEIPT_KEEP ) as $old ) {
		$token = (string) ( $old['token'] ?? '' );
		if ( '' !== $token ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", FPW_PRICE_IMPORT_RECEIPT_PREFIX . $token ) );
		}
	}
}

/**
 * Classify the staged rows against the CURRENT list: nuevo (no maintained
 * price yet), cambio (a different maintained price) or igual (identical —
 * applying it writes nothing). The live list is only read here.
 */
function fpw_price_import_classify( array $rows ): array {
	$prices  = fpw_price_list()['prices'];
	$nuevos  = 0;
	$cambios = 0;
	$iguales = 0;
	foreach ( $rows as $i => $row ) {
		$price = (int) ( $row['price'] ?? 0 );
		$current = fpw_price_read_value( $prices[ (string) ( $row['key'] ?? '' ) ] ?? null );
		if ( null === $current ) { $state = 'nuevo'; $nuevos++; }
		elseif ( $current === $price ) { $state = 'igual'; $iguales++; }
		else { $state = 'cambio'; $cambios++; }
		$rows[ $i ] = $row + array( 'state' => $state, 'current' => $current );
	}
	return array( 'rows' => $rows, 'nuevos' => $nuevos, 'cambios' => $cambios, 'iguales' => $iguales );
}

/**
 * Parse and stage one upload as the pending preview proposal. The Price List
 * is NOT changed: confirmation is a separate, explicit action.
 */
function fpw_price_import_process_upload_string( string $content, string $filename, array $catalog ): array {
	$parsed = fpw_price_import_parse_csv( $content, $catalog );
	if ( empty( $parsed['ok'] ) ) { return array( 'ok' => false, 'message' => (string) ( $parsed['error'] ?? 'El archivo fue rechazado.' ) ); }
	$batch = $parsed['batch'];
	$batch['schema']   = 1;
	$batch['token']    = bin2hex( random_bytes( 16 ) );
	$batch['filename'] = mb_substr( sanitize_text_field( $filename ), 0, 200 );
	$batch['actor']    = fpw_price_actor();
	$batch['at']       = time();
	fpw_price_import_write_row( FPW_PRICE_IMPORT_PENDING_ROW, $batch );
	return array( 'ok' => true, 'message' => 'Carga recibida y previsualizada: la lista de precios NO cambió. Revisa la vista previa y confirma para aplicarla, o cancela sin efectos.' );
}

/**
 * Apply the reviewed batch exactly once. The pending slot must still hold
 * the token the owner previewed (a replaced or consumed batch is a stale
 * result, never an apply); every reviewed identity is re-checked against
 * the CURRENT catalog — drift since the preview refuses the whole batch
 * demanding re-review; the entry cap bounds the merge; and the receipt
 * INSERT decides: two concurrent confirmations of the same batch — one
 * winner only. Identical values write nothing, so repeating the same
 * import fabricates no commercial change.
 */
function fpw_price_import_confirm( string $token, array $catalog ): array {
	if ( '' === $token ) { return array( 'ok' => false, 'message' => 'Falta el identificador de la vista previa; confirma desde la pantalla del importador.' ); }
	$pending = fpw_price_import_pending_batch();
	if ( ! is_array( $pending ) ) {
		return array( 'ok' => false, 'message' => 'Esta vista previa ya no está disponible: fue aplicada, cancelada o reemplazada por una carga más reciente. Vuelve a cargar y previsualizar el archivo.' );
	}
	if ( ! hash_equals( (string) ( $pending['token'] ?? '' ), $token ) ) {
		return array( 'ok' => false, 'message' => 'El lote revisado ya no está disponible: la vista previa fue reemplazada por una carga posterior. Vuelve a revisar y confirmar la carga actual.' );
	}
	$index = fpw_price_import_catalog_index( $catalog );
	foreach ( ( $pending['rows'] ?? array() ) as $row ) {
		$product_id   = (int) ( $row['product_id'] ?? 0 );
		$variation_id = (int) ( $row['variation_id'] ?? 0 );
		$drifted = ! isset( $index['products'][ $product_id ] )
			|| ( $variation_id > 0 && ( $index['variations'][ $variation_id ] ?? 0 ) !== $product_id );
		if ( $drifted ) {
			return array( 'ok' => false, 'message' => 'El catálogo cambió desde la vista previa (la identidad ' . (string) ( $row['key'] ?? '' ) . ' ya no existe como se revisó): nada fue aplicado. Vuelve a cargar el archivo y revisa de nuevo.' );
		}
	}
	$classified = fpw_price_import_classify( $pending['rows'] ?? array() );
	$list = fpw_price_list();
	$cap = (int) apply_filters( 'fpw_price_max_entries', FPW_PRICE_MAX_ENTRIES );
	if ( count( $list['prices'] ) + $classified['nuevos'] > $cap ) {
		return array( 'ok' => false, 'message' => 'Aplicar este lote superaría el máximo de ' . $cap . ' precios mantenidos: el lote sigue pendiente y nada fue aplicado.' );
	}
	$now = time();
	$receipt = array(
		'token'         => $token,
		'actor'         => fpw_price_actor(),
		'at'            => $now,
		'filename'      => (string) ( $pending['filename'] ?? '' ),
		'filas'         => count( $classified['rows'] ),
		'nuevos'        => $classified['nuevos'],
		'cambios'       => $classified['cambios'],
		'iguales'       => $classified['iguales'],
		'errores_total' => (int) ( $pending['errores_total'] ?? 0 ),
	);
	if ( ! fpw_price_import_insert_receipt( $token, $receipt ) ) {
		return array( 'ok' => false, 'message' => 'Esta carga ya fue aplicada (su recibo existe): no se aplicará dos veces. La lista queda como está.' );
	}
	if ( $classified['nuevos'] + $classified['cambios'] > 0 ) {
		$prices = $list['prices'];
		foreach ( $classified['rows'] as $row ) {
			if ( 'igual' === $row['state'] ) { continue; }
			$prices[ (string) $row['key'] ] = (int) $row['price'];
		}
		fpw_price_save_entries( $prices, fpw_price_actor() );
	}
	fpw_price_import_clear_pending();
	fpw_price_import_sweep_receipts();
	$message = 'Importación aplicada: ' . fpw_price_import_counted( $classified['nuevos'], 'precio nuevo', 'precios nuevos' ) . ', '
		. fpw_price_import_counted( $classified['cambios'], 'precio actualizado', 'precios actualizados' ) . ', '
		. fpw_price_import_counted( $classified['iguales'], 'valor idéntico (sin cambios)', 'valores idénticos (sin cambios)' ) . ', '
		. fpw_price_import_counted( $receipt['errores_total'], 'fila con error', 'filas con error' ) . '.';
	return array( 'ok' => true, 'message' => $message, 'receipt' => $receipt );
}

/** Cancel the reviewed preview: the list and everything else stay exactly as they were. */
function fpw_price_import_cancel( string $token ): array {
	$pending = fpw_price_import_pending_batch();
	if ( ! is_array( $pending ) || ! hash_equals( (string) ( $pending['token'] ?? '' ), $token ) ) {
		return array( 'ok' => false, 'message' => 'No hay una vista previa vigente con ese identificador: no se cambió nada.' );
	}
	fpw_price_import_clear_pending();
	return array( 'ok' => true, 'message' => 'Vista previa cancelada sin efectos: la lista de precios no cambió.' );
}

/** The uniform denial: private to the owner, stated in Spanish, 403. */
function fpw_die_price_import_forbidden(): void {
	wp_die( 'El importador de precios es privado del dueño: requiere una sesión con permisos de administración de WooCommerce.', '', array( 'response' => 403 ) );
}

/** The private screen: unlisted, keyed on the owner capability. */
add_action( 'admin_menu', 'fpw_price_import_register_screen' );
function fpw_price_import_register_screen(): void {
	add_submenu_page( null, 'Importar precios', 'Importar precios', 'manage_woocommerce', FPW_PRICE_IMPORT_SCREEN, 'fpw_render_price_import_screen' );
}

/**
 * The three POST actions are processed at admin_init — BEFORE wp-admin prints
 * its header — so every denial is a real HTTP status (403 permissions, 403
 * CSRF) and every result renders once. The stored result is what the screen
 * renders; a second call never re-processes.
 */
add_action( 'admin_init', 'fpw_price_import_maybe_handle_actions', 0 );
function fpw_price_import_maybe_handle_actions(): void {
	if ( FPW_PRICE_IMPORT_SCREEN !== (string) ( $_GET['page'] ?? '' ) ) { return; }
	fpw_price_import_handle_actions();
}

/** Capability first, then the nonce-scoped action; the screen renders the explicit result either way. */
function fpw_render_price_import_screen(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) { fpw_die_price_import_forbidden(); }
	$banner = fpw_price_import_handle_actions();
	echo fpw_price_import_markup( is_array( $banner ) ? $banner : array() );
}

/**
 * Route the three POST actions; each carries its own nonce (CSRF) under the
 * owner capability (authorization). Both boundaries are re-checked on every
 * call; the processing itself runs once per request (at admin_init, see
 * fpw_price_import_maybe_handle_actions) and a later call just returns the
 * stored result. A nonce failure is an EXPLICIT 403.
 */
function fpw_price_import_handle_actions(): array {
	static $result  = array();
	static $handled = false;
	if ( ! current_user_can( 'manage_woocommerce' ) ) { fpw_die_price_import_forbidden(); }
	$action = isset( $_POST['fpw_price_import_action'] ) ? (string) wp_unslash( $_POST['fpw_price_import_action'] ) : '';
	fpw_price_import_verify_nonce( $action );
	if ( $handled ) { return $result; }
	$handled = true;
	switch ( $action ) {
		case 'upload':
			$result = fpw_price_import_handle_upload( fpw_price_catalog() );
			break;
		case 'confirm':
			$result = fpw_price_import_confirm( fpw_price_import_posted_token(), fpw_price_catalog() );
			break;
		case 'cancel':
			$result = fpw_price_import_cancel( fpw_price_import_posted_token() );
			break;
	}
	return $result;
}

/** The reviewed batch token as POSTed, '' when absent. */
function fpw_price_import_posted_token(): string {
	return isset( $_POST['fpw_price_import_token'] ) ? (string) wp_unslash( $_POST['fpw_price_import_token'] ) : '';
}

/** The CSRF boundary of every importer action — each action's own nonce, verified server-side, denied 403 with its own message. */
function fpw_price_import_verify_nonce( string $action ): void {
	$nonce_action = array(
		'upload'  => FPW_PRICE_IMPORT_NONCE_UPLOAD,
		'confirm' => FPW_PRICE_IMPORT_NONCE_CONFIRM,
		'cancel'  => FPW_PRICE_IMPORT_NONCE_CANCEL,
	)[ $action ] ?? null;
	if ( null === $nonce_action ) { return; }
	$nonce = isset( $_REQUEST['fpw_price_import_nonce'] ) ? (string) wp_unslash( $_REQUEST['fpw_price_import_nonce'] ) : '';
	if ( '' === $nonce || ! wp_verify_nonce( $nonce, $nonce_action ) ) {
		wp_die( 'La acción no pudo verificarse (nonce inválido o vencido). Vuelve a la pantalla del importador y vuelve a intentarlo: nada se aplicó.', '', array( 'response' => 403 ) );
	}
}

/** The $_FILES boundary of the upload: type/size checks, then the shared text parser. */
function fpw_price_import_handle_upload( array $catalog ): array {
	if ( ! isset( $_FILES['fpw_price_import_file'] ) || ! is_array( $_FILES['fpw_price_import_file'] ) ) {
		return array( 'ok' => false, 'message' => 'No se recibió ningún archivo: elige el CSV y vuelve a intentar.' );
	}
	$error = (int) ( $_FILES['fpw_price_import_file']['error'] ?? UPLOAD_ERR_NO_FILE );
	if ( UPLOAD_ERR_OK !== $error ) {
		return array( 'ok' => false, 'message' => ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error )
			? 'El archivo supera el máximo de 2 MB por carga.'
			: 'La carga del archivo falló (código ' . $error . '). Intenta de nuevo.' );
	}
	$tmp = (string) ( $_FILES['fpw_price_import_file']['tmp_name'] ?? '' );
	if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) { return array( 'ok' => false, 'message' => 'La carga no provino de un formulario válido.' ); }
	$size = (int) ( $_FILES['fpw_price_import_file']['size'] ?? 0 );
	if ( $size <= 0 || $size > FPW_PRICE_IMPORT_MAX_BYTES ) { return array( 'ok' => false, 'message' => 'El archivo supera el máximo de 2 MB por carga.' ); }
	$content = file_get_contents( $tmp );
	if ( false === $content ) { return array( 'ok' => false, 'message' => 'No se pudo leer el archivo subido.' ); }
	return fpw_price_import_process_upload_string( $content, (string) ( $_FILES['fpw_price_import_file']['name'] ?? 'precios.csv' ), $catalog );
}

/** One maintained amount as the list holds it; an absent price is an em dash, never zero. */
function fpw_price_import_format_clp( $price ): string {
	return null === $price ? '—' : number_format( (int) $price, 0, ',', '.' ) . ' CLP';
}

/** One counted noun with its Spanish plural: «1 fila con error», «2 filas con error». */
function fpw_price_import_counted( int $n, string $one, string $many ): string {
	return $n . ' ' . ( 1 === $n ? $one : $many );
}

/** The mobile-first screen shell and its styles. */
function fpw_price_import_shell( string $inner ): string {
	return '<div class="wrap fpw-price-import"><style>'
		. '.fpw-price-import{max-width:960px;font-size:16px;line-height:1.5}'
		. '.fpw-price-import h1{font-size:24px;line-height:1.2;margin:4px 0 4px}'
		. '.fpw-price-import__kicker{color:#60626d;margin:12px 0 0}'
		. '.fpw-price-import section{border:1px solid #dcdcde;border-radius:8px;padding:14px 16px;background:#fff;margin:16px 0;min-width:0}'
		. '.fpw-price-import h2{font-size:16px;margin:0 0 10px}'
		. '.fpw-price-import table{width:100%;border-collapse:collapse;margin:8px 0;font-size:14px}'
		. '.fpw-price-import th,.fpw-price-import td{border:1px solid #e4e4e8;padding:6px 8px;text-align:left;overflow-wrap:anywhere}'
		. '.fpw-price-import th{background:#f6f7f7;font-weight:600}'
		. '.fpw-price-import code{font-size:13px;overflow-wrap:anywhere}'
		. '.fpw-price-import form{margin:12px 0 0}'
		. '.fpw-price-import .button{margin-right:8px}'
		. '.fpw-price-import__note{color:#60626d;font-size:14px;margin:8px 0 0}'
		. '.fpw-price-import__actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:12px}'
		. '.fpw-price-import dl{margin:8px 0 0}'
		. '.fpw-price-import dt{font-weight:600}'
		. '.fpw-price-import dd{margin:0 0 8px}'
		. '@media (min-width: 782px){.fpw-price-import__grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.fpw-price-import__grid section{margin:0}}'
		. '</style>' . $inner . '<!-- fpw-price-import:end --></div>';
}

/** The action-result banner: the explicit outcome message, success or error. */
function fpw_price_import_banner_html( array $banner ): string {
	if ( ! isset( $banner['message'] ) ) { return ''; }
	$class = ! empty( $banner['ok'] ) ? 'notice-success' : 'notice-error';
	return '<div class="notice ' . $class . '"><p>' . esc_html( (string) $banner['message'] ) . '</p></div>';
}

/** The state of one proposed row as the review names it. */
function fpw_price_import_state_label( string $state ): string {
	return array( 'nuevo' => 'Nuevo', 'cambio' => 'Cambio', 'igual' => 'Idéntico' )[ $state ] ?? $state;
}

/**
 * The pending review: staged candidate rows with their classification beside
 * the current list, per-row errors, and the confirm/cancel actions — each
 * behind its own nonce.
 */
function fpw_price_import_pending_preview_html(): string {
	$pending = fpw_price_import_pending_batch();
	if ( ! is_array( $pending ) ) { return ''; }
	$url           = fpw_price_import_screen_url();
	$nonce_confirm = wp_nonce_field( FPW_PRICE_IMPORT_NONCE_CONFIRM, 'fpw_price_import_nonce', true, false );
	$nonce_cancel  = wp_nonce_field( FPW_PRICE_IMPORT_NONCE_CANCEL, 'fpw_price_import_nonce', true, false );

	$rows        = fpw_price_import_classify( is_array( $pending['rows'] ?? null ) ? $pending['rows'] : array() );
	$applicable  = count( $rows['rows'] );
	$error_total = (int) ( $pending['errores_total'] ?? 0 );
	$preview = '';
	foreach ( array_slice( $rows['rows'], 0, FPW_PRICE_IMPORT_PREVIEW_ROWS ) as $row ) {
		$preview .= '<tr><td>' . (int) ( $row['line'] ?? 0 ) . '</td><td><code>' . esc_html( (string) ( $row['key'] ?? '' ) ) . '</code></td>'
			. '<td>' . esc_html( fpw_price_import_format_clp( $row['current'] ?? null ) ) . '</td>'
			. '<td>' . esc_html( fpw_price_import_format_clp( (int) ( $row['price'] ?? 0 ) ) ) . '</td>'
			. '<td>' . esc_html( fpw_price_import_state_label( (string) ( $row['state'] ?? '' ) ) ) . '</td></tr>';
	}
	$errores_html = '';
	foreach ( ( is_array( $pending['errores'] ?? null ) ? $pending['errores'] : array() ) as $e ) {
		$errores_html .= '<tr><td>' . ( (int) ( $e['line'] ?? 0 ) > 0 ? (int) $e['line'] : '—' ) . '</td><td>' . esc_html( (string) ( $e['reason'] ?? '' ) ) . '</td></tr>';
	}

	$token = (string) ( $pending['token'] ?? '' );
	$html = '<section><h2>Vista previa pendiente</h2>'
		. '<p><strong>' . esc_html( (string) ( $pending['filename'] ?? '' ) ) . '</strong> · subida el ' . esc_html( date_i18n( get_option( 'date_format' ), (int) ( $pending['at'] ?? 0 ) ) ) . ' por ' . esc_html( (string) ( $pending['actor'] ?? '' ) ) . '</p>'
		. '<p>' . esc_html( fpw_price_import_counted( $applicable, 'fila aplicable', 'filas aplicables' ) ) . ' · ' . $rows['nuevos'] . ' nuevos · ' . $rows['cambios'] . ' cambios · ' . $rows['iguales'] . ' idénticos · ' . esc_html( fpw_price_import_counted( $error_total, 'fila con error', 'filas con error' ) )
		. ( ! empty( $pending['ignored_columns'] ) ? ' · columnas ignoradas: ' . esc_html( implode( ', ', (array) $pending['ignored_columns'] ) ) : '' ) . '</p>';
	if ( '' !== $preview ) {
		$html .= '<table><thead><tr><th scope="col">Línea</th><th scope="col">Identidad</th><th scope="col">Hoy en la lista</th><th scope="col">En el archivo</th><th scope="col">Estado</th></tr></thead><tbody>' . $preview . '</tbody></table>';
		if ( $applicable > FPW_PRICE_IMPORT_PREVIEW_ROWS ) {
			$html .= '<p class="fpw-price-import__note">Mostrando las primeras ' . FPW_PRICE_IMPORT_PREVIEW_ROWS . ' de ' . $applicable . ' filas.</p>';
		}
	}
	if ( '' !== $errores_html ) {
		$html .= '<h3 style="font-size:14px">Filas con error (no se aplican)</h3><table><tbody>' . $errores_html . '</tbody></table>';
	}
	$html .= '<p class="fpw-price-import__note">Confirmar aplica exactamente este lote revisado: solo las identidades que nombra cambian; el resto de la lista queda como está.</p>'
		. '<div class="fpw-price-import__actions">'
		. '<form action="' . esc_url( $url ) . '" method="post" style="margin:0"><input type="hidden" name="fpw_price_import_action" value="confirm"><input type="hidden" name="fpw_price_import_token" value="' . esc_attr( $token ) . '">' . $nonce_confirm
		. '<button type="submit" class="button button-primary">Confirmar e importar</button></form>'
		. '<form action="' . esc_url( $url ) . '" method="post" style="margin:0"><input type="hidden" name="fpw_price_import_action" value="cancel"><input type="hidden" name="fpw_price_import_token" value="' . esc_attr( $token ) . '">' . $nonce_cancel
		. '<button type="submit" class="button">Cancelar sin efectos</button></form>'
		. '</div></section>';
	return $html;
}

/** The applied receipts: the consultable provenance of the list's bulk updates. */
function fpw_price_import_receipts_html(): string {
	$receipts = fpw_price_import_receipts( 10 );
	if ( empty( $receipts ) ) { return ''; }
	$receipt_rows = '';
	foreach ( $receipts as $receipt ) {
		$receipt_rows .= '<tr><td>' . esc_html( date_i18n( get_option( 'date_format' ), (int) ( $receipt['at'] ?? 0 ) ) ) . '</td><td>' . esc_html( (string) ( $receipt['actor'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $receipt['filename'] ?? '' ) ) . '</td>'
			. '<td>' . (int) ( $receipt['nuevos'] ?? 0 ) . '</td><td>' . (int) ( $receipt['cambios'] ?? 0 ) . '</td><td>' . (int) ( $receipt['iguales'] ?? 0 ) . '</td><td>' . (int) ( $receipt['errores_total'] ?? 0 ) . '</td>'
			. '<td><code>' . esc_html( (string) ( $receipt['token'] ?? '' ) ) . '</code></td></tr>';
	}
	return '<section><h2>Importaciones aplicadas</h2>'
		. '<table><thead><tr><th scope="col">Fecha</th><th scope="col">Hecha por</th><th scope="col">Archivo</th><th scope="col">Nuevos</th><th scope="col">Actualizados</th><th scope="col">Idénticos</th><th scope="col">Errores</th><th scope="col">Lote</th></tr></thead><tbody>' . $receipt_rows . '</tbody></table>'
		. '<p class="fpw-price-import__note">Cada lote aplicado deja este recibo acotado (actor, fecha, archivo y resultado). El archivo subido no se conserva.</p></section>';
}

/** The screen markup: contract, upload, pending preview, applied receipts. */
function fpw_price_import_markup( array $banner = array() ): string {
	$url          = fpw_price_import_screen_url();
	$nonce_upload = wp_nonce_field( FPW_PRICE_IMPORT_NONCE_UPLOAD, 'fpw_price_import_nonce', true, false );

	$contract = '<section><h2>Contrato de importación (v1)</h2>'
		. '<p>Una fila por identidad nativa, en CSV de texto UTF-8 (hasta 2 MB, ' . FPW_PRICE_IMPORT_MAX_ROWS . ' filas). Columnas exactas:</p>'
		. '<dl><dt><code>id_producto</code></dt><dd>El ID numérico nativo del producto, tal como lo muestra el <a href="' . esc_url( fpw_price_screen_url() ) . '">mantenedor de precios</a>. Nunca se infiere una asociación por parecido de nombres ni se inventa un código que el archivo no trae.</dd>'
		. '<dt><code>id_variacion</code> (opcional)</dt><dd>El ID nativo de la opción, que debe pertenecer al producto de la misma fila; vacío, la fila pone precio al producto. Una variación de otro producto es una asociación ambigua y se rechaza.</dd>'
		. '<dt><code>precio</code></dt><dd>Precio neto entero en CLP (1 a 99.999.999; los puntos de miles son opcionales). El contrato v1 no elimina precios desde el archivo: para quitar uno, usa el mantenedor. Un precio 0 se rechaza: el cero del catálogo es un centinela técnico.</dd></dl>'
		. '<p class="fpw-price-import__note">Las columnas no soportadas se informan y se ignoran; las filas con error se nombran una a una y nunca se aplican. Confirmar cambia SOLO las identidades que el archivo nombra; el resto de la lista queda como está.</p>'
		. '<p class="fpw-price-import__note"><strong>El contrato definitivo (columnas, claves e interpretación de importes) se acordará con la muestra real de la planilla de precios.</strong></p></section>';

	$upload = '<section><h2>Cargar CSV</h2><form action="' . esc_url( $url ) . '" method="post" enctype="multipart/form-data">'
		. '<input type="hidden" name="fpw_price_import_action" value="upload">' . $nonce_upload
		. '<p><input type="file" name="fpw_price_import_file" accept=".csv,text/csv" required></p>'
		. '<p><button type="submit" class="button button-primary">Previsualizar carga</button></p></form>'
		. '<p class="fpw-price-import__note">Subir y previsualizar NO cambia la lista: solo la confirmación aplica el lote revisado.</p></section>';

	return fpw_price_import_shell(
		'<h1>Importar precios</h1>'
		. '<p class="fpw-price-import__kicker">Actualización masiva del mantenedor · planilla revisada antes de aplicar</p>'
		. fpw_price_import_banner_html( $banner )
		. '<div class="fpw-price-import__grid">' . $contract . $upload . '</div>'
		. fpw_price_import_pending_preview_html()
		. fpw_price_import_receipts_html()
	);
}
