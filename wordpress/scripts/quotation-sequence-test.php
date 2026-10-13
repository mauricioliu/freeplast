<?php
/** Step 7 — the commercial quotation sequence: allocator boundaries offline, then a REAL multi-process concurrency trial (parallel PHP workers over a real SQLite engine — no stubbed SQL semantics) proving unique allocation across concurrent approvals/double clicks/retries, the one-winner version race, and no reseed on redeploy. */
require __DIR__ . '/quote-draft-test.php';

/* Allocator boundaries under the offline harness (the harness's own approvals already consumed a dynamic count of numbers). */
check( fpw_quotation_sequence_table() === 'fp_fpw_quotation_sequence', 'the series lives in its own dedicated table' );
$allocated = $GLOBALS['fpwd_sequence_allocations'];
$last_used = (int) end( $allocated );
check( fpw_quotation_allocate_number() === $last_used + 1 && fpw_quotation_allocate_number() === $last_used + 2, 'allocation is monotonic and integer, separate from the request reference' );
$GLOBALS['fpwd_query_fault'] = static fn( $sql ) => str_contains( $sql, 'INSERT INTO fp_fpw_quotation_sequence' ) ? false : null;
check( fpw_quotation_allocate_number() === null, 'a failed durable allocation answers null — never a number, nothing half-written' );
$GLOBALS['fpwd_query_fault'] = static fn( $sql ) => str_contains( $sql, 'CREATE TABLE IF NOT EXISTS' ) ? false : null;
check( fpw_quotation_allocate_number() === null, 'a failed bootstrap answers null' );
$GLOBALS['fpwd_query_fault'] = null;
check( fpw_quotation_allocate_number() === $last_used + 3, 'after insert failures the series only moves forward: no reuse, no reset, safe recovery by retry' );

/* The substantive trial: 8 parallel processes allocating 6 numbers each and racing the SAME order's version row. */
$workers = 8;
$iterations = 6;
$db = sys_get_temp_dir() . '/freeplast-sequence-' . getmypid() . '.sqlite';
@unlink( $db );
/* The deployment bootstrap, run once by the parent: WAL plus the idempotent CREATE IF NOT EXISTS and the 1760 last-used marker seeding the AUTOINCREMENT series at 1761. */
$setup = new PDO( 'sqlite:' . $db, null, null, array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
$setup->exec( 'PRAGMA journal_mode=WAL' );
$setup->exec( 'CREATE TABLE IF NOT EXISTS fp_fpw_quotation_sequence ( id INTEGER PRIMARY KEY AUTOINCREMENT, allocated_at INTEGER NOT NULL )' );
$setup->exec( 'CREATE TABLE IF NOT EXISTS wp_options_sim ( option_name TEXT PRIMARY KEY, option_value TEXT NOT NULL )' );
$setup->exec( 'INSERT OR IGNORE INTO fp_fpw_quotation_sequence ( id, allocated_at ) VALUES ( 1760, 0 )' );
$setup = null;
$procs = array();
$pipes = array();
for ( $w = 0; $w < $workers; $w++ ) {
	$spec = array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
	$procs[ $w ] = proc_open( PHP_BINARY . ' ' . escapeshellarg( __DIR__ . '/quotation-sequence-worker.php' ) . ' ' . escapeshellarg( $db ) . ' 501 ' . $iterations, $spec, $pipes[ $w ] );
	check( is_resource( $procs[ $w ] ), 'worker ' . $w . ' launched' );
	fclose( $pipes[ $w ][0] );
}
$lines = array();
foreach ( $pipes as $set ) { $lines = array_merge( $lines, array_filter( array_map( 'trim', explode( "\n", (string) stream_get_contents( $set[1] ) ) ) ) ); fclose( $set[1] ); $stderr = stream_get_contents( $set[2] ); fclose( $set[2] ); check( '' === trim( (string) $stderr ), 'worker stderr stays empty: ' . $stderr ); }
foreach ( $procs as $index => $proc ) { $code = proc_close( $proc ); check( 0 === $code, 'worker ' . $index . ' exits cleanly (code ' . $code . ')' ); }

$numbers = array();
$wins = 0;
foreach ( $lines as $line ) {
	$event = json_decode( $line, true );
	check( is_array( $event ) && isset( $event['number'], $event['won'] ), 'worker reports a parseable outcome' );
	$numbers[] = (int) $event['number'];
	$wins += (int) $event['won'];
}
check( count( $numbers ) === $workers * $iterations, 'every concurrent allocation completed (' . count( $numbers ) . '/' . ( $workers * $iterations ) . ')' );
check( count( $numbers ) === count( array_unique( $numbers ) ), 'no duplicate commercial number exists under real concurrency' );
check( min( $numbers ) === FPW_QUOTATION_SEQUENCE_START && max( $numbers ) - min( $numbers ) + 1 === count( $numbers ), 'the series started at 1761 and every number is contiguous' );
check( 1 === $wins, 'exactly one concurrent approval won the shared order version row (' . $wins . ' winners)' );

$pdo = new PDO( 'sqlite:' . $db, null, null, array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
$row = $pdo->query( "SELECT option_value FROM wp_options_sim WHERE option_name = 'fpw_quotation_501'" )->fetch( PDO::FETCH_ASSOC );
$version = is_array( $row ) ? json_decode( $row['option_value'], true ) : null;
check( is_array( $version ) && in_array( (int) ( $version['commercial']['number'] ?? 0 ), $numbers, true ), 'the standing version froze exactly one of the allocated numbers' );
check( (int) $pdo->query( 'SELECT COUNT(*) FROM wp_options_sim' )->fetchColumn() === 1, 'the loser approvals stored no second version row' );
/* Redeployment: the same idempotent bootstrap again must never reseed the counter. */
$pdo->exec( 'CREATE TABLE IF NOT EXISTS fp_fpw_quotation_sequence ( id INTEGER PRIMARY KEY AUTOINCREMENT, allocated_at INTEGER NOT NULL )' );
$pdo->exec( 'INSERT OR IGNORE INTO fp_fpw_quotation_sequence ( id, allocated_at ) VALUES ( 1760, 0 )' );
$pdo->exec( 'INSERT INTO fp_fpw_quotation_sequence ( allocated_at ) VALUES ( 0 )' );
check( (int) $pdo->lastInsertId( 'fp_fpw_quotation_sequence' ) === FPW_QUOTATION_SEQUENCE_START + $workers * $iterations, 'a redeploy continues the series instead of reseeding it' );
$pdo = null;
@unlink( $db );
echo "quotation sequence: $assertions cumulative offline checks passed; real multi-process concurrency trial green\n";
