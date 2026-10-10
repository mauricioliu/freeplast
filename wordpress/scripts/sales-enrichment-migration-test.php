<?php
/** Sales-enrichment sealed migration regressions. Synthetic data only.
 * Runs run.php / sales-enrichment-fingerprint.php as real php processes over an
 * in-memory WordPress (stubs via auto_prepend_file, baseline on real stdin) from a
 * temp closure directory holding policy.php, run.php, SEAL.json, enrichment.csv.
 * Usage: php sales-enrichment-migration-test.php */
if ( PHP_SAPI !== 'cli' ) { exit; }
$count = 0;
function check( $ok, $label ) { global $count; $count++; if ( ! $ok ) { throw new RuntimeException( $label ); } }

$BOOT = tempnam( sys_get_temp_dir(), 'fpwsenrboot' );
$TMP = rtrim( sys_get_temp_dir(), '/' ) . '/fpwsenr-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
mkdir( $TMP, 0700, true );
file_put_contents( $BOOT, <<<'PHP'
<?php
$spec = json_decode( (string) file_get_contents( getenv( 'SENR_STATE' ) ), true );
define( 'WP_CLI', true );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'ABSPATH', getenv( 'SENR_ABSPATH' ) . '/' );
@mkdir( ABSPATH, 0777, true );
class FakeWpdb {
	public string $prefix = 'wp_'; public string $options = 'wp_options'; public string $last_error = '';
	public array $state; public ?array $snapshot = null; public array $log = array();
	public function __construct( array &$state ) { $this->state = &$state; }
	public function esc_like( $t ) { return $t; }
	public function prepare( $q, ...$p ) { foreach ( $p as $x ) { $q = preg_replace( '/%s/', "'" . str_replace( '\\', '\\\\', $x ) . "'", $q, 1 ); } return $q; }
	private function unq( string $s ): string { return str_replace( '\\\\', '\\', $s ); }
	public function get_var( $q ) {
		if ( preg_match( "/SHOW TABLES LIKE '(\w+)'/", $q, $m ) ) { return isset( $this->state['tables'][ $m[1] ] ) ? $m[1] : null; }
		if ( preg_match( "/^SELECT option_value FROM wp_options WHERE option_name = '(\w+)'( FOR UPDATE)?$/", $q, $m ) ) { return $this->state['options'][ $m[1] ] ?? null; }
		if ( str_starts_with( $q, 'SELECT ENGINE FROM information_schema.TABLES' ) ) { return array_key_exists( 'engine', $this->state ) ? $this->state['engine'] : 'InnoDB'; }
		throw new RuntimeException( 'Unexpected get_var: ' . $q );
	}
	public function get_col( $q ) {
		if ( preg_match( "/^SELECT option_name FROM wp_options WHERE option_name LIKE '(\w+)%'$/", $q, $m ) ) { return array_values( array_filter( array_keys( $this->state['options'] ), fn( $n ) => str_starts_with( $n, $m[1] ) ) ); }
		throw new RuntimeException( 'Unexpected get_col: ' . $q );
	}
	public function get_results( $q, $o = ARRAY_A ) {
		if ( preg_match( '/SELECT \* FROM `(\w+)` ORDER BY ([\w,]+) LIMIT 500 OFFSET (\d+)/', $q, $m ) ) {
			$rows = $this->state['tables'][ $m[1] ] ?? array(); $keys = explode( ',', $m[2] );
			usort( $rows, static function ( $a, $b ) use ( $keys ) { foreach ( $keys as $k ) { $c = $a[ $k ] <=> $b[ $k ]; if ( $c ) { return $c; } } return 0; } );
			return array_slice( $rows, (int) $m[3], 500 );
		}
		if ( str_starts_with( $q, 'SELECT option_name, option_value FROM wp_options' ) ) {
			$rows = array();
			foreach ( $this->state['options'] as $n => $v ) {
				if ( in_array( $n, array( 'home', 'siteurl', 'blog_public', 'wp_user_roles' ), true ) || str_starts_with( $n, 'fpw_' ) || str_starts_with( $n, 'woocommerce_' ) || str_starts_with( $n, 'qwc_' ) ) { $rows[] = array( 'option_name' => $n, 'option_value' => $v ); }
			}
			usort( $rows, static fn( $a, $b ) => $a['option_name'] <=> $b['option_name'] );
			return $rows;
		}
		throw new RuntimeException( 'Unexpected get_results: ' . $q );
	}
	public function query( $q ) {
		$this->log[] = substr( $q, 0, 20 );
		if ( 'START TRANSACTION' === $q ) { if ( ( $this->state['fail'] ?? '' ) === 'start' ) { return false; } $this->snapshot = $this->state['options']; return true; }
		if ( 'ROLLBACK' === $q ) { if ( null !== $this->snapshot ) { $this->state['options'] = $this->snapshot; $this->snapshot = null; $this->state['rolled_back'] = true; } return true; }
		if ( 'COMMIT' === $q ) { if ( ( $this->state['fail'] ?? '' ) === 'commit' ) { return false; } $this->snapshot = null; return true; }
		if ( preg_match( "/^UPDATE wp_options SET option_value = '(.*)' WHERE option_name = '(\w+)' AND BINARY option_value = BINARY '(.*)'$/s", $q, $m ) ) {
			if ( ( $this->state['fail'] ?? '' ) === 'update' ) { return false; }
			if ( ( $this->state['options'][ $m[2] ] ?? null ) !== $this->unq( $m[3] ) ) { return 0; }
			$this->state['options'][ $m[2] ] = $this->unq( $m[1] ); return 1;
		}
		if ( preg_match( "/^INSERT INTO wp_options \( option_name, option_value, autoload \) VALUES \( '(\w+)', '(.*)', 'off' \)$/s", $q, $m ) ) {
			if ( ( $this->state['fail'] ?? '' ) === 'insert' ) { return false; }
			if ( array_key_exists( $m[1], $this->state['options'] ) ) { return false; }
			$this->state['options'][ $m[1] ] = $this->unq( $m[2] ); return 1;
		}
		throw new RuntimeException( 'Unexpected query: ' . $q );
	}
}
$GLOBALS['wpdb'] = new FakeWpdb( $spec );
$GLOBALS['senr_opts'] = &$spec['options'];
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function get_option( $n, $d = false ) { return $GLOBALS['senr_opts'][ $n ] ?? $d; }
function wp_set_current_user( $id ) { return $id; }
if ( ! empty( $spec['new_plugin'] ) ) { function fpw_sales_plan() {} function fpw_sales_parse_csv() {} function fpw_sales_normalize_rut() {} } // NEW plugin loaded: no collision allowed
if ( is_string( $spec['guard'] ?? null ) && '' !== $spec['guard'] ) {
	@mkdir( ABSPATH . '.wp-release-lock', 0777, true ); @mkdir( ABSPATH . 'wp-content/mu-plugins', 0777, true );
	file_put_contents( ABSPATH . '.wp-release-lock/owner', $spec['guard'] ); touch( ABSPATH . '.maintenance' ); touch( ABSPATH . 'wp-content/mu-plugins/wp-release-guard.php' );
}
$args = array_slice( $GLOBALS['argv'], 1 );
register_shutdown_function( static function () use ( &$spec ) {
	file_put_contents( getenv( 'SENR_OUT' ), json_encode( array( 'tables' => $spec['tables'], 'options' => $spec['options'], 'rolled_back' => $spec['rolled_back'] ?? false, 'log' => $GLOBALS['wpdb']->log ) ) );
	@unlink( ABSPATH . '.wp-release-lock/owner' ); @unlink( ABSPATH . '.maintenance' ); @unlink( ABSPATH . 'wp-content/mu-plugins/wp-release-guard.php' );
	@rmdir( ABSPATH . '.wp-release-lock' ); @rmdir( ABSPATH . 'wp-content/mu-plugins' ); @rmdir( ABSPATH . 'wp-content' );
} );
PHP
);

const KEY_RUT = '961234567';
function sale( string $id, string $date, string $rut, int $total, array $extra = array() ): array { return array( 'id' => $id, 'rut' => $rut, 'date' => $date, 'total' => $total, 'batch' => 'legacy', 'imported_at' => 1700000000 ) + $extra; }
function base_sales(): array {
	return array(
		sale( 'S1', '2025-02-11', KEY_RUT, 1000 ), sale( 'S2', '2025-01-14', KEY_RUT, 2000, array( 'producto' => '', 'precio' => null ) ),
		sale( 'S3', '2025-03-01', '761234567', 3000 ), sale( 'S4', '2025-03-02', '761234567', 4000, array( 'producto' => 'CO', 'total_cajas' => 0 ) ),
		sale( 'U1', '2025-04-01', '765555555', 9000, array( 'producto' => 'ZZ' ) ), // unrelated, not in the CSV
	);
}
function csv_lines( array $rows ): string { return "id_venta,fecha,rut,producto,total_cajas,precio,neto,total\n" . implode( "\n", $rows ) . "\n"; }
$CSV_ROWS = array( 'S1,2025-02-11,96.123.456-7,CO,70,2100,147000,1000', 'S2,2025-01-14,96123456-7,CO,2000,1880,3760000,2000', 'S3,2025-03-01,76123456-7,ME,5,10,50,3000', 'S4,2025-03-02,76123456-7,CO,0,7,8,4000' );
function state( array $sales ): array {
	return array(
		'tables' => array( 'wp_posts' => array( array( 'ID' => 5, 'post_title' => 'Producto' ) ), 'wp_wc_orders' => array( array( 'id' => 70, 'status' => 'wc-pending' ) ), 'wp_users' => array( array( 'ID' => 1, 'user_login' => 'owner' ) ) ),
		'options' => array( 'home' => 'https://freeplast.mliu.site', 'siteurl' => 'https://freeplast.mliu.site', 'blog_public' => '1', 'wp_user_roles' => 'a:0:{}', 'fpw_draft_70' => '{"schema":1}', 'woocommerce_currency' => 'CLP',
			'fpw_sales_register' => json_encode( array( 'schema' => 1, 'sales' => $sales ) ),
			'fpw_sales_receipt_old' => json_encode( array( 'token' => 'old', 'at' => 1700000500, 'filename' => 'first.csv' ) ) ),
		'guard' => null,
	);
}
function closure( array $csv_rows, array $seal_over = array(), ?string $csv_text = null ): string {
	global $TMP;
	$dir = $TMP . '/closure-' . bin2hex( random_bytes( 4 ) ); mkdir( $dir );
	copy( __DIR__ . '/sales-enrichment-migration/run.php', $dir . '/run.php' ); copy( __DIR__ . '/sales-enrichment-migration/policy.php', $dir . '/policy.php' );
	$csv = $csv_text ?? csv_lines( $csv_rows );
	file_put_contents( $dir . '/enrichment.csv', $csv );
	file_put_contents( $dir . '/SEAL.json', json_encode( $seal_over + array( 'schema' => 1, 'target' => 'https://freeplast.mliu.site', 'csv_sha256' => hash( 'sha256', $csv ), 'source_rows' => count( $csv_rows ), 'target_rut' => KEY_RUT, 'target_rows' => 2, 'min_enrich' => 3 ) ) );
	return $dir;
}
function exec_php( string $script, array $st, string $stdin, array $args = array() ): array {
	global $BOOT, $TMP;
	$sf = tempnam( $TMP, 's' ); $of = tempnam( $TMP, 'o' );
	file_put_contents( $sf, json_encode( $st ) );
	$cmd = array_merge( array( PHP_BINARY, '-d', 'auto_prepend_file=' . $BOOT, $script ), $args );
	$proc = proc_open( $cmd, array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $p, null, array_merge( getenv(), array( 'SENR_STATE' => $sf, 'SENR_OUT' => $of, 'SENR_ABSPATH' => $TMP . '/wp' ) ) );
	fwrite( $p[0], $stdin ); fclose( $p[0] );
	$out = stream_get_contents( $p[1] ); $err = stream_get_contents( $p[2] ); fclose( $p[1] ); fclose( $p[2] );
	$exit = proc_close( $proc );
	$res = json_decode( (string) file_get_contents( $of ), true );
	return array( 'ok' => 0 === $exit, 'out' => $out, 'err' => $err . $out, 'state' => is_array( $res ) ? $res : array( 'options' => array(), 'tables' => array(), 'log' => array() ) );
}
function run( string $dir, array $st, string $mode, string $stdin = '', string $rel = 'REL1' ): array { return exec_php( $dir . '/run.php', $st, $stdin, array( $mode, $rel ) ); }
function fp( array $st, string $stdin = '', ?string $closure = null ): array {
	global $TMP, $DIR;
	static $bundles = array();
	$closure ??= $DIR; // the bundled layout: <x>/sales-enrichment-fingerprint.php + <x>/migration/{policy.php,SEAL.json,enrichment.csv}
	if ( ! isset( $bundles[ $closure ] ) ) {
		$x = $TMP . '/bundle-' . bin2hex( random_bytes( 4 ) ); mkdir( $x );
		copy( __DIR__ . '/sales-enrichment-fingerprint.php', $x . '/sales-enrichment-fingerprint.php' );
		exec( 'cp -r ' . escapeshellarg( $closure ) . ' ' . escapeshellarg( $x . '/migration' ) );
		$bundles[ $closure ] = $x;
	}
	return exec_php( $bundles[ $closure ] . '/sales-enrichment-fingerprint.php', $st, $stdin );
}
function legacy_fp( array $st ): string { return trim( exec_php( __DIR__ . '/owner-workspace-fingerprint.php', $st, '' )['out'] ); }
function with( array $st, array $opt ): array { $st['options'] = $opt + $st['options']; return $st; }

/* 1 · capture: read-only, OLD plugin (no fpw_sales_*), exact derivation, original digest = standalone digest. */
$dir = closure( $CSV_ROWS ); $DIR = $dir;
$st = state( base_sales() );
$cap = run( $dir, $st, 'capture' );
check( $cap['ok'], 'capture runs on the old plugin: ' . $cap['err'] );
check( $cap['state']['options'] === $st['options'] && ! in_array( 'START TRANSACTION', $cap['state']['log'], true ) && array() === $cap['state']['log'], 'capture is read-only (no query, no write)' );
$b = json_decode( $cap['out'], true );
check( is_array( $b ) && 'REL1' === $b['release'] && 'https://freeplast.mliu.site' === $b['target'] && $b['register_original_raw'] === $st['options']['fpw_sales_register'], 'baseline binds target, release and the RAW original register' );
check( $b['counts'] === array( 'source' => 4, 'target' => 2, 'enrich' => 4, 'fields' => 14, 'same' => 0, 'excluded' => 0 ), 'counts: 4 source, 2 target, 4 enrich, 14 fields, none excluded' );
check( $b['original_digest'] === legacy_fp( $st ), 'original_digest equals the plain standalone fingerprint' );
$bj = json_encode( $b );
$expected = json_decode( $b['register_expected_raw'], true )['sales'];
$by = array_column( $expected, null, 'id' );
check( 'CO' === $by['S1']['producto'] && 70 === $by['S1']['total_cajas'] && 2100 === $by['S1']['precio'] && 147000 === $by['S1']['neto'] && 'legacy' === $by['S1']['batch'] && 1700000000 === $by['S1']['imported_at'], 'expected fills missing fields and keeps original batch/imported_at' );
check( 'CO' === $by['S4']['producto'] && 0 === $by['S4']['total_cajas'] && 7 === $by['S4']['precio'], 'populated values (incl. zero) are kept; only missing ones fill' );
check( $by['U1'] === base_sales()[4], 'unrelated sale is byte-identical' );
check( count( $expected ) === 5, 'zero new ids' );
$receipt = json_decode( $b['receipt_raw'], true );
check( 4 === $receipt['enriquecidas'] && 14 === $receipt['campos_enriquecidos'] && 0 === $receipt['aplicadas'] && $receipt['token'] === $by['S1']['enriched'][0]['batch'] && 1700000501 <= $receipt['at'], 'receipt is consultable, deterministic and later than every existing receipt' );
check( run( $dir, $st, 'capture' )['out'] !== '' && json_decode( run( $dir, $st, 'capture' )['out'], true )['receipt_raw'] !== '' , 'capture is repeatable' );

/* 2 · fingerprint: original, expected and partial states. */
check( trim( fp( $st, $bj )['out'] ) === $b['original_digest'], 'fingerprint of the original state reproduces the baseline digest' );
$mig = with( $st, array( 'fpw_sales_register' => $b['register_expected_raw'], $b['receipt_name'] => $b['receipt_raw'] ) );
check( trim( fp( $mig, $bj )['out'] ) === $b['original_digest'], 'exact expected register + receipt project back to the ORIGINAL digest' );
foreach ( array(
	'register only' => with( $st, array( 'fpw_sales_register' => $b['register_expected_raw'] ) ),
	'receipt only' => with( $st, array( $b['receipt_name'] => $b['receipt_raw'] ) ),
	'tampered receipt' => with( $mig, array( $b['receipt_name'] => str_replace( '"actor"', '"actoR"', $b['receipt_raw'] ) ) ),
	'tampered register' => with( $mig, array( 'fpw_sales_register' => str_replace( '"CO"', '"XX"', $b['register_expected_raw'] ) ) ),
	'other receipt added' => with( $mig, array( 'fpw_sales_receipt_zzz' => '{"at":1}' ) ),
	'other receipt altered' => with( $mig, array( 'fpw_sales_receipt_old' => '{"token":"old","at":1}' ) ),
	'pending batch' => with( $mig, array( 'fpw_sales_batch' => '{}' ) ),
	'draft changed' => with( $mig, array( 'fpw_draft_70' => '{"schema":2}' ) ),
	'woo setting changed' => with( $mig, array( 'woocommerce_currency' => 'USD' ) ),
) as $label => $bad ) { check( trim( fp( $bad, $bj )['out'] ) !== $b['original_digest'], "fingerprint exposes: $label" ); }
foreach ( array( 'wp_posts' => array( array( 'ID' => 5, 'post_title' => 'Producto2' ) ), 'wp_wc_orders' => array( array( 'id' => 70, 'status' => 'wc-completed' ) ), 'wp_users' => array( array( 'ID' => 1, 'user_login' => 'x' ) ) ) as $table => $rows ) {
	$bad = $mig; $bad['tables'][ $table ] = $rows;
	check( trim( fp( $bad, $bj )['out'] ) !== $b['original_digest'], "fingerprint exposes a change in $table" );
}
$extra_field = json_decode( $b['register_expected_raw'], true ); $extra_field['sales'][4]['producto'] = 'HACK';
check( trim( fp( with( $mig, array( 'fpw_sales_register' => json_encode( $extra_field ) ) ), $bj )['out'] ) !== $b['original_digest'], 'a changed field in an unrelated sale is not projected' );
check( ! fp( $st, '{"schema":1}' )['ok'] && ! fp( $st, 'garbage' )['ok'], 'invalid baseline: the fingerprint fails closed' );
check( ! fp( $st, json_encode( array_merge( $b, array( 'target' => 'https://example.com' ) ) ) )['ok'], 'foreign-target baseline is refused' );
check( ! fp( $st, json_encode( $b + array( 'extra' => 1 ) ) )['ok'], 'a baseline with extra keys is refused' );
$wide_fp = json_decode( $b['register_expected_raw'], true ); $wide_fp['sales'][4]['producto'] = 'HACK';
check( ! fp( $st, json_encode( array_merge( $b, array( 'register_expected_raw' => json_encode( $wide_fp ) ) ) ) )['ok'], 'fingerprint re-derives from the closure: a broadened baseline gets no projection' );
check( ! fp( $st, $bj, $other_closure = closure( $CSV_ROWS, array( 'min_enrich' => 2 ) ) )['ok'], 'fingerprint refuses a baseline not bound to this closure seal' );
$no_seal = closure( $CSV_ROWS ); unlink( $no_seal . '/SEAL.json' );
check( ! fp( $st, $bj, $no_seal )['ok'], 'fingerprint without its sealed source refuses non-empty baselines' );
check( trim( fp( $st, '' )['out'] ) === $b['original_digest'], 'empty stdin = plain standalone digest' );

/* 3 · apply under guard. */
$g = $st; $g['guard'] = 'REL1';
$ap = run( $dir, $g, 'apply', $bj );
check( $ap['ok'] && str_contains( $ap['out'], 'sales_enrichment_applied: 4 sales, 14 fields' ) && str_contains( $ap['out'], 'sales_enrichment_verified' ), 'apply + verify succeed: ' . $ap['err'] );
$after = $ap['state']['options'];
check( $after['fpw_sales_register'] === $b['register_expected_raw'] && $after[ $b['receipt_name'] ] === $b['receipt_raw'], 'exact raw register and receipt written' );
$changed = array_keys( array_filter( $after, fn( $v, $k ) => ( $st['options'][ $k ] ?? null ) !== $v, ARRAY_FILTER_USE_BOTH ) );
sort( $changed );
$want = array( 'fpw_sales_register', $b['receipt_name'] ); sort( $want );
check( $changed === $want, 'only the register and the one receipt changed; other receipts untouched' );
check( $ap['state']['tables'] === $st['tables'], 'no table written' );
$o = array_column( json_decode( $st['options']['fpw_sales_register'], true )['sales'], null, 'id' );
$n = array_column( json_decode( $after['fpw_sales_register'], true )['sales'], null, 'id' );
check( array_keys( $o ) === array_keys( $n ), 'same ids in the same order' );
foreach ( $o as $id => $sale ) {
	foreach ( $sale as $k => $v ) { if ( null !== $v && '' !== $v ) { check( $n[ $id ][ $k ] === $v, "populated $id.$k preserved" ); } }
	foreach ( array( 'id', 'date', 'rut', 'total', 'batch', 'imported_at' ) as $k ) { check( ( $sale[ $k ] ?? null ) === $n[ $id ][ $k ], "$id.$k unchanged" ); }
}
check( $after['fpw_sales_receipt_old'] === $st['options']['fpw_sales_receipt_old'], 'existing receipts untouched' );
check( trim( fp( with( $st, $after ), $bj )['out'] ) === $b['original_digest'], 'post-apply fingerprint equals the original digest' );
$g2 = with( $st, $after ); $g2['guard'] = 'REL1';
$again = run( $dir, $g2, 'apply', $bj );
check( $again['ok'] && str_contains( $again['out'], 'sales_enrichment_already' ) && $again['state']['options'] === $after, 'retry on the exact expected state is an idempotent no-op' );
$ver = run( $dir, $g2, 'verify', $bj );
check( $ver['ok'] && $ver['state']['options'] === $after && ! in_array( 'START TRANSACTION', $ver['state']['log'], true ), 'verify is read-only and passes' );
check( ! run( $dir, $g, 'verify', $bj )['ok'], 'verify on the unmigrated state fails' );

/* 4 · bindings and guards. */
check( ! run( $dir, $st, 'apply', $bj )['ok'], 'apply without the maintenance guard is refused' );
$wrong_guard = $st; $wrong_guard['guard'] = 'OTHER';
check( ! run( $dir, $wrong_guard, 'apply', $bj )['ok'], 'apply under another release guard is refused' );
check( ! run( $dir, $g, 'apply', $bj, 'REL2' )['ok'], 'baseline bound to another release is refused' );
check( ! run( $dir, $g, 'apply', json_encode( array_merge( $b, array( 'target' => 'https://example.com' ) ) ) )['ok'], 'baseline for another target is refused' );
$foreign_home = $g; $foreign_home['options']['home'] = 'https://example.com';
check( ! run( $dir, $foreign_home, 'capture' )['ok'] && ! run( $dir, $foreign_home, 'apply', $bj )['ok'], 'non-staging home refuses capture and apply' );
$wide = json_decode( $b['register_expected_raw'], true ); $wide['sales'][4]['producto'] = 'HACK';
$broad = array_merge( $b, array( 'register_expected_raw' => json_encode( $wide ) ) );
check( ! run( $dir, $g, 'apply', json_encode( $broad ) )['ok'] && ! run( $dir, $g, 'verify', json_encode( $broad ) )['ok'], 'a baseline broadened beyond the sealed source is refused (re-derived from the closure)' );
$other = closure( $CSV_ROWS, array(), csv_lines( array_merge( $CSV_ROWS, array( 'S3,2025-03-01,76123456-7,ME,5,10,50,3000' ) ) ) );
check( ! run( $other, $g, 'apply', $bj )['ok'], 'baseline from another sealed source is refused' );
$bad_digest = array_merge( $b, array( 'original_digest' => str_repeat( 'a', 64 ) ) );
check( ! run( $dir, $g, 'apply', json_encode( $bad_digest ) )['ok'], 'drift from the captured original digest is refused' );
check( ! run( $dir, with( $g, array( 'fpw_draft_70' => '{"schema":2}' ) ), 'apply', $bj )['ok'], 'any unrelated protected change before apply is refused' );

/* 5 · seal and source checks at capture. */
$tampered = closure( $CSV_ROWS ); file_put_contents( $tampered . '/enrichment.csv', csv_lines( $CSV_ROWS ) . "S9,2025-01-01,96123456-7,CO,1,1,1,5\n" );
check( ! run( $tampered, $st, 'capture' )['ok'], 'capture: wrong source hash refused' );
check( ! run( closure( $CSV_ROWS, array( 'source_rows' => 5 ) ), $st, 'capture' )['ok'], 'capture: wrong source count refused' );
check( ! run( closure( $CSV_ROWS, array( 'target_rows' => 3 ) ), $st, 'capture' )['ok'], 'capture: wrong target-customer count refused' );
check( ! run( closure( $CSV_ROWS, array( 'target' => 'https://example.com' ) ), $st, 'capture' )['ok'], 'capture: foreign seal target refused' );
check( ! run( closure( $CSV_ROWS, array( 'extra' => 1 ) ), $st, 'capture' )['ok'], 'capture: seal with extra keys refused' );
check( ! run( closure( $CSV_ROWS, array( 'min_enrich' => 5 ) ), $st, 'capture' )['ok'], 'capture: fewer enrichable sales than sealed refused' );
$nodir = closure( $CSV_ROWS ); unlink( $nodir . '/SEAL.json' );
check( ! run( $nodir, $st, 'capture' )['ok'], 'capture: missing seal refused' );
check( ! run( closure( array(), array( 'source_rows' => 0 ), "id_venta,fecha,rut\n" ), $st, 'capture' )['ok'], 'capture: empty source refused' );
check( ! run( closure( array( 'S1,2025-02-11,96123456-7,CO,1,1,1,1000', 'S1,2025-02-11,96123456-7,CO,1,1,1,1000' ) ), $st, 'capture' )['ok'], 'capture: duplicate source ids refused' );

/* 6 · unsafe reconciliations are blockers; safe detail conflicts are excluded and counted. */
$unsafe_ok = true;
foreach ( array(
	'new id' => array_merge( $CSV_ROWS, array( 'N1,2025-05-05,96123456-7,CO,1,1,1,10' ) ),
	'core total' => array( 'S1,2025-02-11,96123456-7,CO,70,2100,147000,1001' ) + $CSV_ROWS,
	'core date' => array( 'S1,2025-02-12,96123456-7,CO,70,2100,147000,1000' ) + $CSV_ROWS,
	'core rut' => array( 'S3,2025-03-01,76999999-9,ME,5,10,50,3000' ) + $CSV_ROWS,
	'foreign target' => array( 'S3,2025-03-01,96123456-7,ME,5,10,50,3000' ) + $CSV_ROWS,
	'total absent' => array( 'S3,2025-03-01,76123456-7,ME,5,10,50,' ) + $CSV_ROWS,
) as $label => $rows ) {
	$rows = array_values( $rows ); if ( 'new id' === $label ) { $seal = array( 'source_rows' => 5, 'target_rows' => 3 ); } else { $rows = array_values( array_unique( $rows ) ); $seal = array( 'source_rows' => count( $rows ) ); }
	if ( 'foreign target' === $label ) { $seal['target_rows'] = 3; }
	$res = run( closure( $rows, $seal ), $st, 'capture' );
	check( ! $res['ok'] && null === json_decode( $res['out'], true ), "capture blocks unsafe: $label (" . $res['err'] . ')' );
}
$conf = state( array_merge( base_sales(), array() ) );
$sales = base_sales(); $sales[2]['producto'] = 'OTRO'; // S3 holds a populated, different product -> safe detail conflict
$conf = state( $sales );
$cc = run( $dir, $conf, 'capture' );
$cb = json_decode( $cc['out'], true );
check( $cc['ok'] && 3 === $cb['counts']['enrich'] && 1 === $cb['counts']['excluded'], 'a safe detail conflict is excluded and counted' );
$cby = array_column( json_decode( $cb['register_expected_raw'], true )['sales'], null, 'id' );
check( $cby['S3'] === $sales[2], 'the conflicting row stays entirely unchanged (no partial fill)' );
check( 1 === json_decode( $cb['receipt_raw'], true )['conflictos_total'] && array( 'S3' ) === json_decode( $cb['receipt_raw'], true )['conflictos'], 'the receipt reports the exclusion' );
check( ! run( closure( $CSV_ROWS, array( 'min_enrich' => 4 ) ), $conf, 'capture' )['ok'], 'sealed minimum catches an unexpected exclusion' );
check( ! run( $dir, state( array_slice( base_sales(), 1 ) ), 'capture' )['ok'], 'capture: a source id missing from the register is a blocker' );
$dupreg = base_sales(); $dupreg[] = $dupreg[0];
check( ! run( $dir, state( $dupreg ), 'capture' )['ok'], 'capture: duplicate register identities refused' );
check( ! run( $dir, with( $st, array( 'fpw_sales_batch' => '{"token":"x"}' ) ), 'capture' )['ok'], 'capture: an existing pending import blocks (no replacing user work)' );
check( ! run( $dir, with( $st, array( $b['receipt_name'] => '{}' ) ), 'capture' )['ok'], 'capture: pre-existing enrichment receipt refused' );
$populated = base_sales(); foreach ( $populated as $i => $s ) { if ( in_array( $s['id'], array( 'S1', 'S2', 'S3', 'S4' ), true ) ) { $populated[ $i ] += array( 'producto' => 'CO', 'total_cajas' => 1, 'precio' => 1, 'neto' => 1 ); } }
check( ! run( $dir, state( $populated ), 'capture' )['ok'], 'capture: nothing enrichable (below sealed minimum) refused' );

/* 7 · drift/tamper between capture and apply; transaction failure rollback. */
$drift = $g; $drift['options']['fpw_sales_register'] = str_replace( '"S1"', '"S1"', $st['options']['fpw_sales_register'] ) . ' ';
check( ! run( $dir, $drift, 'apply', $bj )['ok'], 'apply refuses a register changed since capture' );
$tamper_vals = $g; $tt = json_decode( $st['options']['fpw_sales_register'], true ); $tt['sales'][0]['producto'] = 'MANUAL'; $tamper_vals['options']['fpw_sales_register'] = json_encode( $tt );
$tv = run( $dir, $tamper_vals, 'apply', $bj );
check( ! $tv['ok'] && $tv['state']['options']['fpw_sales_register'] === $tamper_vals['options']['fpw_sales_register'], 'a manually populated value after capture is never overwritten' );
$half = with( $g, array( $b['receipt_name'] => $b['receipt_raw'] ) );
check( ! run( $dir, $half, 'apply', $bj )['ok'], 'receipt present with original register is a conflict, fail closed' );
$half2 = with( $g, array( 'fpw_sales_register' => $b['register_expected_raw'] ) );
check( ! run( $dir, $half2, 'apply', $bj )['ok'], 'expected register without its receipt is a conflict, fail closed' );
$pend = with( $g, array( 'fpw_sales_batch' => '{"token":"p"}' ) );
check( ! run( $dir, $pend, 'apply', $bj )['ok'], 'a pending import at apply time is refused' );
foreach ( array( 'start', 'commit', 'update', 'insert' ) as $fail ) {
	$f = $g; $f['fail'] = $fail;
	$r = run( $dir, $f, 'apply', $bj );
	check( ! $r['ok'] && ( 'start' === $fail || true === $r['state']['rolled_back'] ) && $r['state']['options'] === $g['options'], "transaction failure at $fail rolls everything back" );
}
$myisam = $g; $myisam['engine'] = 'MyISAM';
$my = run( $dir, $myisam, 'apply', $bj );
check( ! $my['ok'] && $my['state']['options'] === $g['options'] && ! in_array( 'START TRANSACTION', $my['state']['log'], true ), 'a non-transactional options table fails closed before any write' );
$noengine = $g; $noengine['engine'] = null;
check( ! run( $dir, $noengine, 'apply', $bj )['ok'], 'an unknown storage engine fails closed' );
$newplug = $g; $newplug['new_plugin'] = true;
$np = run( $dir, $newplug, 'apply', $bj );
check( $np['ok'] && str_contains( $np['out'], 'applied' ), 'apply/verify run with the NEW plugin loaded: no redeclaration' );
check( ! in_array( 'x', array(), true ) && 0 === preg_match( '/function\s+fpw_sales_/', file_get_contents( __DIR__ . '/sales-enrichment-migration/policy.php' ) . file_get_contents( __DIR__ . '/sales-enrichment-migration/run.php' ) ), 'the closure declares no fpw_sales_* names' );

/* 8 · parity with the importer's parser/classifier (extracted from the shipped source). */
$src = file_get_contents( __DIR__ . '/../wp-content/plugins/freeplast-woo/sales-register.php' );
foreach ( array( 'fpw_sales_normalize_rut', 'fpw_sales_parse_csv', 'fpw_sales_plan' ) as $fn ) {
	check( 1 === preg_match( '/^function ' . $fn . '\(.*?^}\n/ms', $src, $m ), "importer defines $fn" );
	$code = $m[0];
	if ( 'fpw_sales_plan' === $fn ) { $code = str_replace( 'function fpw_sales_plan', 'function fpw_imp_plan', $code ); }
	elseif ( 'fpw_sales_parse_csv' === $fn ) { $code = str_replace( array( 'function fpw_sales_parse_csv', 'fpw_sales_normalize_rut' ), array( 'function fpw_imp_parse', 'fpw_senr_normalize_rut' ), $code ); }
	else { $code = str_replace( 'fpw_sales_normalize_rut', 'fpw_imp_rut', $code ); }
	eval( $code );
}
foreach ( array( 'FPW_SALES_MAX_BYTES' => 2097152, 'FPW_SALES_MAX_ROWS' => 5000, 'FPW_SALES_MAX_CELL' => 200, 'FPW_SALES_MAX_ID' => 100, 'FPW_SALES_LIST_LIMIT' => 100 ) as $k => $v ) { if ( ! defined( $k ) ) { define( $k, $v ); } }
define( 'ABSPATH', '/x/' );
require_once __DIR__ . '/sales-enrichment-migration/policy.php';
$sample = csv_lines( array_merge( $CSV_ROWS, array( 'S5;bad', 'S6,2025-13-01,76123456-7,CO,1,1,1,1', 'S7,2025-01-01,76123456-7,CO,1.400,2,x,1', 'S8,2025-01-01,,CO,1,1,1,1', 'S3,2025-03-01,76123456-7,ME,5,10,50,3000' ) ) );
$pi = fpw_imp_parse( $sample ); $pm = fpw_senr_parse_csv( $sample );
check( $pi['batch']['rows'] === $pm['batch']['rows'] && $pi['batch']['errores_total'] === $pm['batch']['errores_total'], 'parser parity with the importer on valid, malformed, duplicate and unassociated rows' );
$clean = fpw_senr_parse_csv( csv_lines( $CSV_ROWS ) )['batch']['rows'];
$sales = base_sales(); $sales[3]['precio'] = 8;
$pa = fpw_imp_plan( $sales, $clean ); $pb = fpw_senr_plan( $sales, $clean, KEY_RUT );
check( $pa['enrich'] === $pb['enrich'] && $pa['same'] === $pb['same'] && $pa['conflicts'] === array_merge( $pb['detail_conflict'], $pb['core_mismatch'], $pb['foreign_target'] ), 'classifier parity with the importer (enrich, same, conflicts)' );

exec( 'rm -rf ' . escapeshellarg( $TMP ) ); @unlink( $BOOT );
echo "sales enrichment migration: $count offline checks passed\n";
