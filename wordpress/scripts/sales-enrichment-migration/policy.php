<?php
/** Sealed sales-history enrichment library (tier DATA release). Shared by
 * sales-enrichment-migration/run.php and sales-enrichment-fingerprint.php.
 *
 * Self-contained: only core APIs ($wpdb, wp_json_encode, get_option). It uses
 * its OWN fpw_senr_* parser/classifier (a port of the importer's contract) so it
 * works at capture while the OLD plugin is loaded (no fpw_sales_plan) and at
 * apply/verify with the NEW plugin loaded (no redeclaration). Equivalence with
 * the importer is regression-tested.
 *
 * Closure inputs next to this file (supplied privately by the release lead,
 * never in Git):  enrichment.csv  and  SEAL.json  =
 *   {"schema":1,"target":"https://freeplast.mliu.site","csv_sha256":"<64hex>",
 *    "source_rows":N,"target_rut":"<normalized RUT>","target_rows":M,"min_enrich":K}
 * Exact keys only. The driver's SHA256SUMS seals both files and the baseline.
 *
 * Digest: byte-for-byte owner-workspace-fingerprint.php. The ONLY projections:
 * the fpw_sales_register row hashes as its captured original while its raw value
 * is byte-equal to the captured original OR the exact expected result; the one
 * deterministic receipt row is omitted only while byte-equal to the expected
 * receipt. Everything else (other receipts, pending batch, any other row/field)
 * hashes as it stands, so any deviation stays an inspectable different digest. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function fpw_senr_home(): string { return 'https://freeplast.mliu.site'; }
function fpw_senr_receipt_prefix(): string { return 'fpw_sales_receipt_'; }
function fpw_senr_register_name(): string { return 'fpw_sales_register'; }
function fpw_senr_pending_name(): string { return 'fpw_sales_batch'; }
function fpw_senr_fields(): array { return array( 'producto', 'total_cajas', 'precio', 'neto' ); }

function fpw_senr_normalize_rut( string $raw ): string {
	$stripped = preg_replace( '/[\s.\-]/', '', substr( trim( $raw ), 0, 20 ) );
	if ( ! is_string( $stripped ) ) { return ''; }
	$clean = strtoupper( $stripped );
	return ( '' !== $clean && preg_match( '/^\d{7,8}[0-9K]$/', $clean ) ) ? $clean : '';
}

/** Port of the importer's contract parser (same rules, bounded, no evaluation). @return array{ok:bool,error?:string,batch?:array} */
function fpw_senr_parse_csv( string $content ): array {
	if ( str_starts_with( $content, "PK\x03\x04" ) ) { return array( 'ok' => false, 'error' => 'ZIP workbook refused.' ); }
	if ( str_contains( $content, "\0" ) ) { return array( 'ok' => false, 'error' => 'Null bytes.' ); }
	if ( strlen( $content ) > 2097152 ) { return array( 'ok' => false, 'error' => 'Over 2 MB.' ); }
	if ( ! mb_check_encoding( $content, 'UTF-8' ) ) { return array( 'ok' => false, 'error' => 'Not UTF-8.' ); }
	if ( str_starts_with( $content, "\xEF\xBB\xBF" ) ) { $content = substr( $content, 3 ); }
	$lines = preg_split( '/\r\n|\n|\r/', $content );
	if ( ! is_array( $lines ) ) { return array( 'ok' => false, 'error' => 'Unreadable.' ); }
	while ( ! empty( $lines ) && '' === trim( (string) end( $lines ) ) ) { array_pop( $lines ); }
	if ( empty( $lines ) ) { return array( 'ok' => false, 'error' => 'Empty.' ); }
	$header_line = (string) array_shift( $lines );
	$delim = ',';
	foreach ( array( ';', "\t" ) as $candidate ) {
		if ( substr_count( $header_line, $candidate ) > substr_count( $header_line, $delim ) ) { $delim = $candidate; }
	}
	$map = array();
	foreach ( str_getcsv( $header_line, $delim, '"', '' ) as $index => $cell ) {
		$name = mb_strtolower( trim( (string) $cell ) );
		if ( in_array( $name, array( 'id_venta', 'fecha', 'rut', 'producto', 'total cajas', 'total_cajas', 'precio', 'neto', 'total' ), true ) ) { $map[ 'total cajas' === $name ? 'total_cajas' : $name ] = (int) $index; }
	}
	if ( array_diff( array( 'id_venta', 'fecha', 'rut' ), array_keys( $map ) ) ) { return array( 'ok' => false, 'error' => 'Missing required columns.' ); }
	$rows = array(); $errores = array(); $sin = 0; $data_lines = 0;
	foreach ( $lines as $i => $line ) {
		if ( '' === trim( $line ) ) { continue; }
		if ( ++$data_lines > 5000 ) { return array( 'ok' => false, 'error' => 'Over 5000 rows.' ); }
		$line_no = $i + 2;
		$cells = str_getcsv( $line, $delim, '"', '' );
		$get = static function ( string $name ) use ( $map, $cells ): string {
			$index = $map[ $name ] ?? null;
			return null === $index ? '' : mb_substr( trim( (string) ( $cells[ $index ] ?? '' ) ), 0, 200 );
		};
		$id = $get( 'id_venta' ); $date_raw = $get( 'fecha' ); $rut_raw = $get( 'rut' );
		if ( '' === $id || mb_strlen( $id ) > 100 ) { $errores[] = $line_no; continue; }
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_raw ) || ! checkdate( (int) substr( $date_raw, 5, 2 ), (int) substr( $date_raw, 8, 2 ), (int) substr( $date_raw, 0, 4 ) ) ) { $errores[] = $line_no; continue; }
		$detail = array( 'producto' => $get( 'producto' ) );
		foreach ( array( 'total_cajas', 'precio', 'neto', 'total' ) as $field ) {
			$raw = $get( $field );
			$detail[ $field ] = null;
			if ( '' === $raw ) { continue; }
			if ( preg_match( '/^\d{1,3}(\.\d{3})+$/', $raw ) && strlen( str_replace( '.', '', $raw ) ) <= 9 ) { $detail[ $field ] = (int) str_replace( '.', '', $raw ); }
			elseif ( preg_match( '/^\d{1,9}$/', $raw ) ) { $detail[ $field ] = (int) $raw; }
			else { $errores[] = $line_no; continue 2; }
		}
		$rut_norm = fpw_senr_normalize_rut( $rut_raw );
		if ( '' === $rut_norm ) { $sin++; }
		$rows[] = array( 'line' => $line_no, 'id' => $id, 'date' => $date_raw, 'rut' => $rut_raw, 'rut_norm' => $rut_norm ) + $detail;
	}
	$counts = array_count_values( array_column( $rows, 'id' ) );
	$dups = array_keys( array_filter( $counts, static fn( $n ) => $n > 1 ) );
	foreach ( $rows as $row ) { if ( in_array( $row['id'], $dups, true ) ) { $errores[] = $row['line']; } }
	$rows = array_values( array_filter( $rows, static fn( $row ) => ! in_array( $row['id'], $dups, true ) ) );
	return array( 'ok' => true, 'batch' => array( 'rows' => $rows, 'errores_total' => count( $errores ), 'sin_asociacion_total' => $sin ) );
}

/**
 * Importer classification (same id-only match, same fill rule), with the importer's
 * single "conflict" bucket split so unsafe core problems are distinguishable from
 * safe detail conflicts. Details fill only when stored is null/''; zero is populated;
 * absent incoming never erases; any conflict rejects the whole row.
 * @return array{enrich:array<string,array>,same:int,detail_conflict:array,missing:array,core_mismatch:array,foreign_target:array}
 */
function fpw_senr_plan( array $sales, array $rows, string $target_rut ): array {
	$by_id = array_column( $sales, null, 'id' );
	$plan = array( 'enrich' => array(), 'same' => 0, 'detail_conflict' => array(), 'missing' => array(), 'core_mismatch' => array(), 'foreign_target' => array() );
	foreach ( $rows as $row ) {
		$id = (string) $row['id'];
		$prev = $by_id[ $id ] ?? null;
		if ( null === $prev ) { $plan['missing'][] = $id; continue; }
		$core = ( (string) ( $prev['date'] ?? '' ) === (string) $row['date'] )
			&& ( (string) ( $prev['rut'] ?? '' ) === (string) $row['rut_norm'] )
			&& ( ( $prev['total'] ?? null ) === ( $row['total'] ?? null ) );
		if ( ! $core ) {
			$foreign = ( (string) $row['rut_norm'] === $target_rut ) !== ( (string) ( $prev['rut'] ?? '' ) === $target_rut );
			$plan[ $foreign ? 'foreign_target' : 'core_mismatch' ][] = $id;
			continue;
		}
		$fill = array(); $conflict = false;
		foreach ( fpw_senr_fields() as $field ) {
			$new = $row[ $field ] ?? null;
			$old = $prev[ $field ] ?? null;
			if ( null === $new || '' === $new ) { continue; }
			if ( null === $old || '' === $old ) { $fill[ $field ] = $new; }
			elseif ( $old !== $new ) { $conflict = true; }
		}
		if ( $conflict ) { $plan['detail_conflict'][] = $id; }
		elseif ( $fill ) { $plan['enrich'][ $id ] = $fill; }
		else { $plan['same']++; }
	}
	return $plan;
}

function fpw_senr_hex64( $v ): bool { return is_string( $v ) && 1 === preg_match( '/^[0-9a-f]{64}$/', $v ); }

/** Seal + CSV from the closure directory; the CSV must hash to the seal, parse cleanly and match the sealed counts. */
function fpw_senr_load_closure( string $dir, string $home ): array {
	$seal_raw = @file_get_contents( $dir . '/SEAL.json' );
	$csv_raw = @file_get_contents( $dir . '/enrichment.csv' );
	if ( ! is_string( $seal_raw ) || ! is_string( $csv_raw ) ) { throw new RuntimeException( 'Closure lacks SEAL.json or enrichment.csv.' ); }
	$seal = json_decode( $seal_raw, true );
	$keys = array( 'schema', 'target', 'csv_sha256', 'source_rows', 'target_rut', 'target_rows', 'min_enrich' );
	$have = is_array( $seal ) ? array_keys( $seal ) : array();
	sort( $have ); sort( $keys );
	if ( $have !== $keys ) { throw new RuntimeException( 'Invalid seal shape.' ); }
	if ( 1 !== $seal['schema'] || $seal['target'] !== $home || ! fpw_senr_hex64( $seal['csv_sha256'] ) ) { throw new RuntimeException( 'Seal is foreign or malformed.' ); }
	foreach ( array( 'source_rows', 'target_rows', 'min_enrich' ) as $k ) { if ( ! is_int( $seal[ $k ] ) || $seal[ $k ] < 1 ) { throw new RuntimeException( 'Seal count malformed.' ); } }
	if ( ! is_string( $seal['target_rut'] ) || $seal['target_rut'] !== fpw_senr_normalize_rut( $seal['target_rut'] ) || '' === $seal['target_rut'] ) { throw new RuntimeException( 'Seal target RUT malformed.' ); }
	$csv_sha = hash( 'sha256', $csv_raw );
	if ( $csv_sha !== $seal['csv_sha256'] ) { throw new RuntimeException( 'enrichment.csv does not match the sealed hash.' ); }
	$parsed = fpw_senr_parse_csv( $csv_raw );
	if ( empty( $parsed['ok'] ) || 0 !== $parsed['batch']['errores_total'] || 0 !== $parsed['batch']['sin_asociacion_total'] ) { throw new RuntimeException( 'Sealed CSV is not clean contract data.' ); }
	$rows = $parsed['batch']['rows'];
	foreach ( $rows as $row ) { if ( null === $row['total'] ) { throw new RuntimeException( 'Every sealed row needs a total to reconcile core fields.' ); } }
	$targets = count( array_filter( $rows, static fn( $r ) => $r['rut_norm'] === $seal['target_rut'] ) );
	if ( count( $rows ) !== $seal['source_rows'] || $targets !== $seal['target_rows'] ) { throw new RuntimeException( 'Source identity counts differ from the seal.' ); }
	return array( 'seal' => $seal, 'seal_sha' => hash( 'sha256', $seal_raw ), 'csv_sha' => $csv_sha, 'rows' => $rows );
}

function fpw_senr_option_raw( string $name ): ?string {
	global $wpdb;
	$v = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
	if ( $wpdb->last_error ) { throw new RuntimeException( 'Unable to read an option.' ); }
	return is_string( $v ) ? $v : null;
}

/** Newest existing receipt time (read-only). */
function fpw_senr_newest_receipt_at(): int {
	global $wpdb;
	$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( fpw_senr_receipt_prefix() ) . '%' ) );
	if ( $wpdb->last_error ) { throw new RuntimeException( 'Unable to list receipts.' ); }
	$newest = 0;
	foreach ( (array) $names as $name ) {
		$row = json_decode( (string) fpw_senr_option_raw( (string) $name ), true );
		$newest = max( $newest, (int) ( is_array( $row ) ? ( $row['at'] ?? 0 ) : 0 ) );
	}
	return $newest;
}

function fpw_senr_token( string $csv_sha ): string { return substr( hash( 'sha256', 'fpw-sales-enrichment-v1|' . $csv_sha ), 0, 32 ); }

/** Pure, deterministic derivation of the exact expected register + receipt. Throws on any unsafe condition. */
function fpw_senr_derive( array $closure, string $home, string $register_raw, int $at ): array {
	$seal = $closure['seal'];
	$register = json_decode( $register_raw, true );
	if ( ! is_array( $register ) || ! is_array( $register['sales'] ?? null ) ) { throw new RuntimeException( 'The register is unreadable.' ); }
	$ids = array();
	foreach ( $register['sales'] as $sale ) {
		if ( ! is_array( $sale ) || ! is_string( $sale['id'] ?? null ) ) { throw new RuntimeException( 'The register has a malformed sale.' ); }
		$ids[] = $sale['id'];
	}
	if ( count( $ids ) !== count( array_unique( $ids ) ) ) { throw new RuntimeException( 'The register has duplicate identities.' ); }
	$plan = fpw_senr_plan( $register['sales'], $closure['rows'], $seal['target_rut'] );
	$unsafe = count( $plan['missing'] ) . ' missing ids, ' . count( $plan['core_mismatch'] ) . ' core mismatches, ' . count( $plan['foreign_target'] ) . ' foreign targets';
	if ( $plan['missing'] || $plan['core_mismatch'] || $plan['foreign_target'] ) { throw new RuntimeException( 'Unsafe source/register reconciliation: ' . $unsafe . '.' ); }
	if ( count( $plan['enrich'] ) < $seal['min_enrich'] ) { throw new RuntimeException( 'Fewer enrichable sales than the sealed minimum: ' . count( $plan['enrich'] ) . '.' ); }
	$token = fpw_senr_token( $closure['csv_sha'] );
	foreach ( $register['sales'] as $i => $sale ) {
		$fields = $plan['enrich'][ $sale['id'] ] ?? null;
		if ( null === $fields ) { continue; }
		$register['sales'][ $i ] = array_merge( $sale, $fields, array( 'enriched' => array_merge( (array) ( $sale['enriched'] ?? array() ), array( array( 'batch' => $token, 'at' => $at, 'fields' => array_keys( $fields ) ) ) ) ) );
	}
	$fields_total = array_sum( array_map( 'count', $plan['enrich'] ) );
	$receipt = array(
		'token' => $token, 'actor' => 'sales-enrichment-migration', 'at' => $at, 'filename' => 'enrichment.csv',
		'filas' => count( $closure['rows'] ), 'aplicadas' => 0, 'ya_importadas' => $plan['same'],
		'conflictos' => array_slice( $plan['detail_conflict'], 0, 50 ), 'conflictos_total' => count( $plan['detail_conflict'] ),
		'sin_asociacion' => 0, 'errores_total' => 0,
		'enriquecidas' => count( $plan['enrich'] ), 'campos_enriquecidos' => $fields_total,
		'cambios' => array_slice( array_map( static fn( $id, $f ) => array( 'id' => $id, 'campos' => $f ), array_keys( $plan['enrich'] ), $plan['enrich'] ), 0, 50 ),
		'migracion' => array( 'csv_sha256' => $closure['csv_sha'], 'seal_sha256' => $closure['seal_sha'] ),
	);
	return array(
		'token' => $token, 'receipt_name' => fpw_senr_receipt_prefix() . $token, 'receipt_raw' => (string) wp_json_encode( $receipt ),
		'register_expected_raw' => (string) wp_json_encode( $register ),
		'counts' => array( 'source' => count( $closure['rows'] ), 'target' => $seal['target_rows'], 'enrich' => count( $plan['enrich'] ), 'fields' => $fields_total, 'same' => $plan['same'], 'excluded' => count( $plan['detail_conflict'] ) ),
	);
}

function fpw_senr_baseline_keys(): array {
	$k = array( 'schema', 'kind', 'target', 'release', 'seal_sha256', 'csv_sha256', 'token', 'receipt_name', 'receipt_raw', 'receipt_at', 'register_original_raw', 'register_expected_raw', 'counts', 'original_digest' );
	sort( $k );
	return $k;
}

/** Structural validation (exact keys, shapes, derivable names). Integrity itself comes from the driver's sealed manifest. */
function fpw_senr_baseline_valid( $b, ?string $release, string $home ): bool {
	if ( ! is_array( $b ) ) { return false; }
	$keys = array_keys( $b ); sort( $keys );
	if ( $keys !== fpw_senr_baseline_keys() || 1 !== $b['schema'] || 'sales-enrichment' !== $b['kind'] || $b['target'] !== $home ) { return false; }
	if ( ! is_string( $b['release'] ) || ! preg_match( '/^[A-Za-z0-9_-]+$/D', $b['release'] ) || ( null !== $release && $b['release'] !== $release ) ) { return false; }
	foreach ( array( 'seal_sha256', 'csv_sha256', 'original_digest' ) as $k ) { if ( ! fpw_senr_hex64( $b[ $k ] ) ) { return false; } }
	if ( $b['token'] !== fpw_senr_token( $b['csv_sha256'] ) || $b['receipt_name'] !== fpw_senr_receipt_prefix() . $b['token'] ) { return false; }
	foreach ( array( 'receipt_raw', 'register_original_raw', 'register_expected_raw' ) as $k ) { if ( ! is_string( $b[ $k ] ) || '' === $b[ $k ] ) { return false; } }
	if ( $b['register_original_raw'] === $b['register_expected_raw'] || ! is_int( $b['receipt_at'] ) || $b['receipt_at'] < 1 || ! is_array( $b['counts'] ) ) { return false; }
	$receipt = json_decode( $b['receipt_raw'], true );
	return is_array( $receipt ) && ( $receipt['token'] ?? null ) === $b['token'] && ( $receipt['at'] ?? null ) === $b['receipt_at'];
}

/** Re-derive from the sealed closure: a baseline cannot say more than the sealed source allows. */
function fpw_senr_assert_matches_closure( array $b, array $closure, string $home ): void {
	if ( $b['seal_sha256'] !== $closure['seal_sha'] || $b['csv_sha256'] !== $closure['csv_sha'] ) { throw new RuntimeException( 'Baseline is not bound to this sealed source.' ); }
	$d = fpw_senr_derive( $closure, $home, $b['register_original_raw'], $b['receipt_at'] );
	if ( $d['register_expected_raw'] !== $b['register_expected_raw'] || $d['receipt_raw'] !== $b['receipt_raw'] || $d['token'] !== $b['token'] || $d['counts'] !== $b['counts'] ) {
		throw new RuntimeException( 'Baseline differs from what the sealed source derives; refusing.' );
	}
}

/** Capture: read-only; current state must be unmigrated, with no pending import and no existing receipt. */
function fpw_senr_capture_baseline( string $dir, string $release, string $home ): array {
	$closure = fpw_senr_load_closure( $dir, $home );
	$register_raw = fpw_senr_option_raw( fpw_senr_register_name() );
	if ( null === $register_raw ) { throw new RuntimeException( 'The sales register option is missing.' ); }
	if ( null !== fpw_senr_option_raw( fpw_senr_pending_name() ) ) { throw new RuntimeException( 'A pending import exists; refusing to replace user work.' ); }
	if ( null !== fpw_senr_option_raw( fpw_senr_receipt_prefix() . fpw_senr_token( $closure['csv_sha'] ) ) ) { throw new RuntimeException( 'The enrichment receipt already exists.' ); }
	$at = max( time(), fpw_senr_newest_receipt_at() + 1 );
	$d = fpw_senr_derive( $closure, $home, $register_raw, $at );
	$b = array(
		'schema' => 1, 'kind' => 'sales-enrichment', 'target' => $home, 'release' => $release,
		'seal_sha256' => $closure['seal_sha'], 'csv_sha256' => $closure['csv_sha'], 'token' => $d['token'], 'receipt_name' => $d['receipt_name'],
		'receipt_raw' => $d['receipt_raw'], 'receipt_at' => $at, 'register_original_raw' => $register_raw, 'register_expected_raw' => $d['register_expected_raw'],
		'counts' => $d['counts'],
	);
	$b['original_digest'] = fpw_senr_protected_digest( null ); // unmigrated state: the plain standalone digest
	return $b;
}

/** EXACTLY owner-workspace-fingerprint.php's algorithm plus the two bounded projections above. */
function fpw_senr_protected_digest( ?array $b ): string {
	global $wpdb;
	$hash = hash_init( 'sha256' );
	$tables = array(
		'posts' => 'ID', 'postmeta' => 'meta_id', 'comments' => 'comment_ID', 'commentmeta' => 'meta_id',
		'terms' => 'term_id', 'termmeta' => 'meta_id', 'term_taxonomy' => 'term_taxonomy_id',
		'term_relationships' => 'object_id,term_taxonomy_id', 'users' => 'ID', 'usermeta' => 'umeta_id',
		'woocommerce_order_items' => 'order_item_id', 'woocommerce_order_itemmeta' => 'meta_id',
		'wc_orders' => 'id', 'wc_orders_meta' => 'id', 'wc_order_addresses' => 'id', 'wc_order_operational_data' => 'id',
	);
	foreach ( $tables as $suffix => $key ) {
		$table = $wpdb->prefix . $suffix;
		$present = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $wpdb->last_error ) { throw new RuntimeException( 'Unable to inspect protected table.' ); }
		if ( ! $present ) { hash_update( $hash, $suffix . ':absent\n' ); continue; }
		hash_update( $hash, $suffix . "\n" );
		for ( $offset = 0; ; $offset += 500 ) {
			// Identifiers are exclusively the fixed allowlist above and WP's configured prefix.
			$rows = $wpdb->get_results( "SELECT * FROM `$table` ORDER BY $key LIMIT 500 OFFSET " . (int) $offset, ARRAY_A );
			if ( $wpdb->last_error ) { throw new RuntimeException( 'Unable to read protected records.' ); }
			foreach ( $rows as $row ) { hash_update( $hash, wp_json_encode( $row ) . "\n" ); }
			if ( count( $rows ) < 500 ) { break; }
		}
	}
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name IN ('home','siteurl','blog_public','" . $wpdb->prefix . "user_roles') ORDER BY option_name",
		$wpdb->esc_like( 'fpw_' ) . '%', $wpdb->esc_like( 'woocommerce_' ) . '%', $wpdb->esc_like( 'qwc_' ) . '%'
	), ARRAY_A );
	if ( $wpdb->last_error ) { throw new RuntimeException( 'Unable to read protected commercial settings.' ); }
	$project = false;
	if ( null !== $b ) {
		$values = array();
		foreach ( $rows as $row ) { $values[ (string) $row['option_name'] ] = (string) $row['option_value']; }
		$reg = $values[ fpw_senr_register_name() ] ?? null;
		$rec = $values[ $b['receipt_name'] ] ?? null;
		// Exactly two coupled states project: untouched (no receipt) or fully applied (exact receipt).
		$project = ( $reg === $b['register_original_raw'] && null === $rec ) || ( $reg === $b['register_expected_raw'] && $rec === $b['receipt_raw'] );
	}
	foreach ( $rows as $row ) {
		if ( $project ) {
			if ( (string) $row['option_name'] === fpw_senr_register_name() ) { $row['option_value'] = $b['register_original_raw']; }
			elseif ( (string) $row['option_name'] === $b['receipt_name'] ) { continue; }
		}
		hash_update( $hash, wp_json_encode( $row ) . "\n" );
	}
	return hash_final( $hash );
}
