<?php
/** Step 7 concurrency worker: mirrors the runtime allocation semantics against a REAL SQLite database — one engine-atomic autoincrement INSERT per number, then the durable version write whose UNIQUE name decides the approval race. Runs as a separate process; no WordPress, no stubs for the SQL semantics under test. */
if ( $argc !== 4 ) { fwrite( STDERR, "usage: php quotation-sequence-worker.php <sqlite-db> <order-id> <iterations>\n" ); exit( 2 ); }
list( , $db_path, $order_id, $iterations ) = $argv;
$pdo = new PDO( 'sqlite:' . $db_path, null, null, array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 60 ) );
$pdo->exec( 'PRAGMA busy_timeout=60000' );
for ( $i = 0; $i < (int) $iterations; $i++ ) {
	/* Allocation: one INSERT, the engine serializes the key (autocommit, no lock window to guard). */
	$pdo->exec( 'INSERT INTO fp_fpw_quotation_sequence ( allocated_at ) VALUES ( ' . time() . ' )' );
	$number = (int) $pdo->lastInsertId( 'fp_fpw_quotation_sequence' );
	/* The durable version write: the unique option_name decides the race — the loser's number is burned, never reused, never a second version. */
	$stmt = $pdo->prepare( 'INSERT OR IGNORE INTO wp_options_sim ( option_name, option_value ) VALUES ( ?, ? )' );
	$stmt->execute( array( 'fpw_quotation_' . (int) $order_id, json_encode( array( 'version' => 1, 'commercial' => array( 'number' => $number ) ), JSON_THROW_ON_ERROR ) ) );
	echo json_encode( array( 'number' => $number, 'won' => 1 === $stmt->rowCount() ), JSON_THROW_ON_ERROR ), "\n";
}
