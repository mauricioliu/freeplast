<?php
/** Real SQLite connections/processes exercise production confirmation SQL, not a fake merge. */
require __DIR__ . '/sales-register-test.php';
define( 'SQLITE_DB_DROPIN_VERSION', 'test' );
$_SERVER['DOCUMENT_ROOT'] = ABSPATH;
$start = $assertions;
$dir = __DIR__ . '/../.build/sales-persistence-' . getmypid();
mkdir( $dir, 0700, true );
$path = $dir . '/db.sqlite';
class FPW_Persistence_DB {
	public string $options = 'wp_options';
	public PDO $db;
	public string $fail = '';
	public string $pause = '';
	public function __construct( string $path ) {
		$this->db = new PDO( 'sqlite:' . $path, null, null, array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
		$this->db->exec( 'PRAGMA busy_timeout=10000' );
	}
	public function prepare( $sql, ...$args ) { foreach ( $args as $arg ) { $sql = substr_replace( $sql, $this->db->quote( (string) $arg ), strpos( $sql, '%s' ), 2 ); } return $sql; }
	public function suppress_errors( $set = null ) { return false; }
	public function esc_like( $text ) { return addcslashes( $text, '_%\\' ); }
	public function get_var( $sql ) { $v = $this->db->query( $sql )->fetchColumn(); return false === $v ? null : $v; }
	public function get_col( $sql ) { return $this->db->query( $sql . " ESCAPE '\\'" )->fetchAll( PDO::FETCH_COLUMN ); }
	public function query( $sql ) {
		$boundary = match ( true ) {
			'START TRANSACTION' === $sql => 'start',
			str_contains( $sql, 'SET option_value = option_value' ) => 'lock',
			str_starts_with( $sql, 'INSERT' ) && str_contains( $sql, FPW_SALES_RECEIPT_PREFIX ) => 'receipt',
			str_starts_with( $sql, 'INSERT' ) && str_contains( $sql, "'" . FPW_SALES_REGISTER_ROW . "'" ) => 'register',
			str_starts_with( $sql, 'UPDATE' ) && ! str_contains( $sql, 'SET option_value = option_value' ) && str_contains( $sql, "'" . FPW_SALES_REGISTER_ROW . "'" ) => 'register',
			str_starts_with( $sql, 'DELETE' ) => 'consume',
			'COMMIT' === $sql => 'commit',
			default => '',
		};
		if ( 'register' === $boundary && '' !== $this->pause ) { file_put_contents( $this->pause, 'locked' ); usleep( 350000 ); }
		if ( '' !== $this->fail && $this->fail === $boundary ) { return false; }
		try {
			$result = $this->db->exec( 'START TRANSACTION' === $sql ? 'BEGIN TRANSACTION' : $sql );
			if ( 'COMMIT' === $sql && 'commit-ack' === $this->fail ) { return false; }
			return $result;
		} catch ( PDOException $e ) { return false; }
	}
}
$wpdb = new FPW_Persistence_DB( $path );
$wpdb->db->exec( 'CREATE TABLE wp_options (option_name TEXT PRIMARY KEY, option_value TEXT NOT NULL, autoload TEXT)' );
$row = array( 'source_row' => 2, 'invoice' => '123', 'rut' => '76.123.456-7', 'date' => '2024-02-29', 'producto' => 'Caja <script>', 'total_cajas' => '2', 'precio' => '10.125', 'neto' => '20.25', 'total' => '24.0975', 'payment' => '', 'notes' => '', 'raw' => array( 'G' => '<script>', 'O' => '24.0975' ) );
$other = $row; $other['source_row'] = 3; $other['producto'] = 'Otra caja'; $other['total'] = '0.000000000001';
$legacy = array( '123' => array( 'id' => '123', 'rut' => '761234567', 'date' => $row['date'], 'producto' => $row['producto'], 'total_cajas' => 2, 'precio' => '10.125', 'neto' => '20.25', 'total' => '24.0975', 'batch' => 'original', 'imported_at' => 123, 'custom_provenance' => 'preserve' ) );
$evidence = $dir . '/legacy.json';
file_put_contents( $evidence, json_encode( $legacy ) ); chmod( $evidence, 0600 );
define( 'FPW_SALES_LEGACY_FILE', realpath( $evidence ) );
define( 'FPW_SALES_LEGACY_SHA256', hash_file( 'sha256', $evidence ) );
function persistence_stage( array $rows, string $token = 'batch' ): array {
	$b = array( 'format' => 'xlsx', 'rows' => $rows, 'token' => $token, 'filename' => 'synthetic.xlsx', 'source_sha256' => str_repeat( 'a', 64 ), 'sheet' => 'Ventas', 'blank_rows' => 0, 'extra_columns' => array() );
	$result = fpw_sales_transaction( static function () use ( $b ) { fpw_sales_write_row( FPW_SALES_PENDING_ROW, $b ); return array( 'ok' => true ); } );
	if ( ! $result['ok'] ) { throw new RuntimeException( 'staging failed' ); }
	return $b;
}
function persistence_reset(): void { $GLOBALS['wpdb']->db->exec( 'DELETE FROM wp_options' ); }
try {
	// Evidence-only legacy ID without its previous record is deliberately blocked.
	$newrow = $row; $newrow['invoice'] = '456'; $other['invoice'] = '456';
	foreach ( array( 'start', 'lock', 'receipt', 'register', 'consume', 'commit' ) as $fault ) {
		persistence_reset(); persistence_stage( array( $newrow, $other ) );
		$before = $wpdb->db->query( 'SELECT * FROM wp_options' )->fetchAll();
		$wpdb->fail = $fault;
		check( ! fpw_sales_confirm( 'batch' )['ok'], 'fault reported: ' . $fault );
		$wpdb->fail = '';
		check( $before === $wpdb->db->query( 'SELECT * FROM wp_options' )->fetchAll(), 'atomic rollback: ' . $fault );
	}
	persistence_reset(); persistence_stage( array( $newrow, $other ) );
	$wpdb->fail = 'commit-ack';
	check( ! fpw_sales_confirm( 'batch' )['ok'], 'lost commit acknowledgement reports uncertainty' );
	$wpdb->fail = '';
	check( count( fpw_sales_register()['sales'] ) === 1 && fpw_sales_receipt( 'batch' ) !== null && ! fpw_sales_confirm( 'batch' )['ok'], 'committed uncertain result never replayed' );
	$original = fpw_sales_register();
	persistence_stage( array( $other, $newrow ), 'reordered' );
	$r = fpw_sales_confirm( 'reordered' );
	check( $r['ok'] && 1 === $r['receipt']['ya_importadas'] && $original === fpw_sales_register(), 'reordered rows repeat exact no-op, original provenance retained' );
	$history = fpw_sales_history_for_rut( '76.123.456-7' );
	check( count( $history['sales'] ) === 1 && count( $history['sales'][0]['lines'] ) === 2 && '24.097500000001' === $history['sales'][0]['total'], 'RUT/date multiline exact sum persisted' );
	check( '24,097500000001 CLP' === fpw_sales_format_clp( $history['sales'][0]['total'] ) && '1.234.567,0001 CLP' === fpw_sales_format_clp( '1234567.0001' ), 'decimal rendering never casts float or integer' );
	$html = fpw_sales_detail_html( $history['sales'][0] );
	check( str_contains( $html, '&lt;script&gt;' ) && ! str_contains( $html, '<script>' ) && str_contains( $html, str_repeat( 'a', 64 ) ), 'escaped lines and provenance displayed' );
	foreach ( array( 'precio', 'total', 'rut', 'date' ) as $field ) {
		$changed = $newrow; $changed[$field] = match( $field ) { 'rut' => '761234568', 'date' => '2024-03-01', default => '99' };
		// Both lines share identity fields so this is a populated-field conflict, not ambiguity.
		$second = $other; if ( in_array( $field, array( 'rut', 'date' ), true ) ) { $second[$field] = $changed[$field]; }
		persistence_stage( array( $changed, $second ), 'changed-' . $field );
		$r = fpw_sales_confirm( 'changed-' . $field );
		check( $r['ok'] && 1 === $r['receipt']['conflictos_total'] && $original === fpw_sales_register(), 'whole entity conflict: ' . $field );
	}
	$pending = $newrow; $pending['notes'] = 'devolución';
	persistence_stage( array( $pending ), 'movement' ); $r = fpw_sales_confirm( 'movement' );
	check( 1 === $r['receipt']['pendientes_total'] && array( 'return' ) === $r['receipt']['pendientes'][0]['movements'] && $original === fpw_sales_register(), 'special movements pending, not purchases' );
	$invalid = $newrow; $invalid['date'] = '2023-02-29';
	check( 'pending' === fpw_sales_workbook_plan( array( $invalid ), array(), array() )[0]['status'], 'invalid calendar date pending' );
	$invalid['date'] = '2024-02-29'; $invalid['rut'] = 'bad';
	check( 'pending' === fpw_sales_workbook_plan( array( $invalid ), array(), array() )[0]['status'], 'invalid RUT pending' );
	persistence_reset(); fpw_sales_write_row( FPW_SALES_REGISTER_ROW, array( 'schema' => 1, 'sales' => array_values( $legacy ) ) );
	persistence_stage( array( $row ), 'legacy' ); $r = fpw_sales_confirm( 'legacy' );
	check( $r['ok'] && 1 === $r['receipt']['enriquecidas'], 'authenticated legacy enriches' );
	$sale = fpw_sales_register()['sales'][0];
	foreach ( $legacy['123'] as $key => $value ) { check( $sale[$key] === $value, 'legacy field preserved: ' . $key ); }
	check( count( $sale['lines'] ) === 1 && isset( $sale['workbook_source'], $sale['enriched'] ), 'legacy snapshot and audit recorded' );
	$old = $sale; unset( $old['lines'] ); $old['workbook_source'] = array( 'original' => 'do not overwrite' );
	fpw_sales_write_row( FPW_SALES_REGISTER_ROW, array( 'schema' => 1, 'sales' => array( $old ) ) );
	persistence_stage( array( $row ), 'prior-source' ); fpw_sales_confirm( 'prior-source' );
	check( fpw_sales_register()['sales'][0]['workbook_source'] === $old['workbook_source'], 'populated audit provenance never overwritten' );
	persistence_stage( array( $newrow ), 'new-slot' );
	check( ! fpw_sales_confirm( 'stale' )['ok'] && ! fpw_sales_cancel( 'stale' )['ok'] && 'new-slot' === fpw_sales_pending_batch()['token'], 'stale confirmation/cancel preserves newer slot' );
	// Independent connections and OS processes race on the same real persistence transaction.
	persistence_reset(); persistence_stage( array( $newrow, $other ), 'race' );
	while ( ob_get_level() ) { ob_end_flush(); }
	$pids = array();
	for ( $i = 0; $i < 4; $i++ ) {
		$pid = pcntl_fork();
		if ( 0 === $pid ) {
			$wpdb = new FPW_Persistence_DB( $path );
			file_put_contents( $dir . '/result-' . $i, json_encode( fpw_sales_confirm( 'race' ) ) ); exit( 0 );
		}
		if ( $pid < 0 ) { throw new RuntimeException( 'fork failed' ); }
		$pids[] = $pid;
	}
	foreach ( $pids as $pid ) { pcntl_waitpid( $pid, $status ); check( 0 === pcntl_wexitstatus( $status ), 'concurrent worker exited cleanly' ); }
	$winners = 0;
	for ( $i = 0; $i < 4; $i++ ) { $winners += (int) json_decode( file_get_contents( $dir . '/result-' . $i ), true )['ok']; }
	check( 1 === $winners && 1 === count( fpw_sales_register()['sales'] ) && 1 === count( fpw_sales_receipts() ) && null === fpw_sales_pending_batch(), 'four concurrent confirms: one complete durable result' );
	// A replacing upload waits behind confirmation, then remains pending (never consumed by the older token).
	persistence_reset(); persistence_stage( array( $newrow ), 'first' );
	$pid = pcntl_fork();
	if ( 0 === $pid ) {
		$wpdb = new FPW_Persistence_DB( $path ); $wpdb->pause = $dir . '/locked';
		file_put_contents( $dir . '/first-result', json_encode( fpw_sales_confirm( 'first' ) ) ); exit( 0 );
	}
	if ( $pid < 0 ) { throw new RuntimeException( 'fork failed' ); }
	$deadline = microtime( true ) + 5;
	while ( ! is_file( $dir . '/locked' ) && microtime( true ) < $deadline ) { usleep( 10000 ); }
	check( is_file( $dir . '/locked' ), 'confirmation reached locked write boundary' );
	$second = $newrow; $second['invoice'] = '789'; persistence_stage( array( $second ), 'second' );
	pcntl_waitpid( $pid, $status );
	check( json_decode( file_get_contents( $dir . '/first-result' ), true )['ok'] && 'second' === fpw_sales_pending_batch()['token'], 'new upload survives concurrent older confirmation' );
	check( fpw_sales_confirm( 'second' )['ok'] && count( fpw_sales_register()['sales'] ) === 2, 'different batches serialize without lost sale' );
	persistence_stage( array( $newrow ), 'update-fault' );
	$before = fpw_sales_register();
	$wpdb->fail = 'register';
	check( ! fpw_sales_confirm( 'update-fault' )['ok'] && $before === fpw_sales_register() && null === fpw_sales_receipt( 'update-fault' ), 'existing register update failure rolls back receipt' );
	$wpdb->fail = '';
	persistence_stage( array( $newrow ), 'corrupt' );
	$wpdb->db->exec( "UPDATE wp_options SET option_value='not json' WHERE option_name='fpw_sales_register'" );
	check( ! fpw_sales_confirm( 'corrupt' )['ok'] && null === fpw_sales_receipt( 'corrupt' ), 'corrupt stored register fails closed, never replaced' );
	echo 'sales workbook persistence: ' . ( $assertions - $start ) . " real-SQLite checks passed\n";
} finally {
	$wpdb = null;
	foreach ( glob( $dir . '/*' ) as $file ) { unlink( $file ); }
	rmdir( $dir );
}
