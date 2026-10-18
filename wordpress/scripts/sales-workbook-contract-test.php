<?php
require __DIR__ . '/../wp-content/plugins/freeplast-woo/sales-workbook-contract.php';
$count = 0;
function check( bool $ok, string $label ): void {
	global $count; $count++;
	if ( ! $ok ) { throw new RuntimeException( $label ); }
}
$row = array( 'source_row' => 2, 'invoice' => '42', 'date' => '2025-01-02', 'rut' => '12.345.678-5', 'producto' => 'A', 'total_cajas' => '2', 'precio' => '10.25', 'neto' => '20.50', 'total' => '24.395', 'payment' => '', 'notes' => '' );
$map = fn( $rows, $legacy = array(), $existing = array() ) => fpw_workbook_map( $rows, $legacy, $existing );
$r = $map( array( $row ) )[0];
check( 'new' === $r['status'] && '123456785' === $r['rut'], 'empty payment allowed; normalized RUT' );
check( '24.395' === $r['total'], 'stored precision retained' );
$second = array_replace( $row, array( 'producto' => 'B', 'source_row' => 3 ) );
$r = $map( array( $row, $second ) )[0];
check( 'new' === $r['status'] && 2 === count( $r['lines'] ) && '48.79' === $r['total'], 'multiline invoice sums line totals once' );
check( $r['lines'] === $map( array( $second, array_replace( $row, array( 'source_row' => 99 ) ) ) )[0]['lines'], 'order and position not identity' );
check( 'pending' === $map( array( $row, $row ) )[0]['status'], 'indistinguishable lines withheld' );
foreach ( array( 'rut' => '987654321', 'date' => '2025-01-03' ) as $key => $value ) {
	check( 'pending' === $map( array( $row, array_replace( $second, array( $key => $value ) ) ) )[0]['status'], 'contradictory invoice ' . $key );
}
foreach ( array( 'Se anuló' => 'cancellation', 'Devolución' => 'return', 'Nota de crédito' => 'adjustment' ) as $note => $movement ) {
	$r = $map( array( array_replace( $row, array( 'notes' => $note ) ) ) )[0];
	check( 'pending' === $r['status'] && array( $movement ) === $r['movements'], 'special movement ' . $movement );
}
foreach ( array( 'notes', 'payment' ) as $field ) {
	foreach ( array( 'ANULACIÓN' => 'cancellation', 'DEVOLUCIÓN' => 'return', 'NOTA DE CRÉDITO' => 'adjustment', 'AJUSTE' => 'adjustment' ) as $marker => $movement ) {
		foreach ( array( '42', '', 'S/F', '042' ) as $invoice ) {
			$r = $map( array( array_replace( $row, array( $field => $marker, 'invoice' => $invoice ) ) ) )[0];
			check( 'pending' === $r['status'] && array( $movement ) === $r['movements'] && in_array( 'special_movement_review', $r['reasons'], true ), 'explicit Unicode movement retained: ' . $field . '/' . $marker . '/' . $invoice );
			check( null === ( $r['total'] ?? null ) && ( '42' === $invoice || ( ! isset( $r['id'] ) && in_array( 'invoice_unproven', $r['reasons'], true ) ) ), 'unproven invoice never gains identity or total' );
		}
	}
}
$r = $map( array( array_replace( $row, array( 'invoice' => '' ) ) ) )[0];
check( array() === $r['movements'] && array( 'invoice_unproven' ) === $r['reasons'] && ! isset( $r['id'], $r['total'] ), 'missing identity alone never infers a movement' );
check( 'pending' === $map( array( array_replace( $row, array( 'notes' => 'deben saldo' ) ) ) )[0]['status'], 'ambiguous observation' );
foreach ( array( 'invoice' => '', 'rut' => '', 'total' => '1,25', 'precio' => '-2', 'date' => '2025-02-30', 'total_cajas' => '1.5' ) as $key => $value ) {
	check( 'pending' === $map( array( array_replace( $row, array( $key => $value ) ) ) )[0]['status'], 'invalid ' . $key );
}
$evidence = array( '42' => array( 'date' => '2025-01-02', 'rut' => '123456785', 'total' => '24.395', 'producto' => 'A', 'total_cajas' => '2', 'precio' => '10.25', 'neto' => '20.5' ) );
$r = $map( array( $row ), $evidence, $evidence )[0];
check( '42' === $r['id'] && 'reconciled' === $r['status'], 'legacy id preserved with matching evidence and current state' );
check( 'pending' === $map( array( $row ), array(), $evidence )[0]['status'], 'current id alone insufficient evidence' );
check( 'pending' === $map( array( $row ), $evidence, array() )[0]['status'], 'missing current legacy sale not recreated' );
$conflict = $evidence; $conflict['42']['total'] = '99';
check( 'pending' === $map( array( $row ), $evidence, $conflict )[0]['status'], 'populated conflict withheld' );
check( 'pending' === $map( array( $row, $second ), $evidence, $evidence )[0]['status'], 'legacy single row cannot silently become multiline' );
check( '0' === fpw_workbook_decimal( '-00.000' ) && '12.34' === fpw_workbook_decimal( '0012.3400' ), 'canonical decimal' );
check( '1000000000.000000000001' === fpw_workbook_sum( array( '999999999.999999999999', '0.000000000002' ) ), 'exact carry beyond float precision' );
foreach ( array( 'NaN', 'INF', '1e3', '1.234.567', ' 1,2 ', '0.1234567890123' ) as $bad ) {
	try { fpw_workbook_decimal( $bad ); check( false, 'must reject decimal' ); }
	catch ( InvalidArgumentException $e ) { check( true, 'invalid decimal rejected' ); }
}
echo "PASS: $count workbook contract checks\n";
