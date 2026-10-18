<?php
require __DIR__ . '/sales-register-test.php';
$before = $GLOBALS['fpws_table'];
$sales = array();
for ( $i = 1; $i <= 1322; $i++ ) {
 $sales[] = array( 'id' => 'sale-' . $i, 'date' => '2026-01-' . str_pad( (string) ( $i % 28 + 1 ), 2, '0', STR_PAD_LEFT ), 'rut' => '123456785', 'total' => '123456789012.123456789012' );
}
$seen = array(); $previous = null;
for ( $page = 1; $page <= 53; $page++ ) {
 $result = fpw_sales_history_page( $sales, $page );
 check( count( $result['sales'] ) <= 25 && 53 === $result['pages'], 'bounded pages' );
 foreach ( $result['sales'] as $sale ) {
  $key = array( $sale['date'], $sale['id'] );
  check( null === $previous || $previous >= $key, 'date descending with stable ID tie break' );
  $previous = $key; $seen[] = $sale['id'];
 }
}
check( 1322 === count( array_unique( $seen ) ), 'all 1322 records visited exactly once' );
check( fpw_sales_history_page( array_reverse( $sales ), 1 ) === fpw_sales_history_page( $sales, 1 ), 'ordering independent of insertion order' );
check( 53 === fpw_sales_history_page( $sales, 999 )['page'], 'page overflow clamped' );
$line = array( 'producto' => '<script>product</script>', 'total_cajas' => '1.25', 'precio' => '10.123', 'neto' => '12.65375', 'total' => '15.0579625' );
$multi = array( 'id' => 'invoice/id<&', 'invoice' => '77', 'date' => '2026-03-01', 'total' => '30.115925', 'lines' => array( $line, $line ), 'workbook_source' => array( 'rows' => array( array( 'source_row' => 2, 'raw' => array( 'D' => '<b>Original</b>' ) ), array( 'source_row' => 3, 'raw' => array( 'D' => 'Otra variante' ) ) ) ) );
check( 2 === count( fpw_sales_history_names( $multi ) ), 'original name variants retained' );
check( str_starts_with( fpw_sales_history_client( $multi ), 'Variantes en origen:' ), 'variants not guessed identity' );
$html = fpw_sales_history_markup( array_merge( array( $multi ), $sales ), array() );
check( ! str_contains( $html, 'Total línea' ) && ! str_contains( $html, 'Fila original' ), 'list never renders line or provenance detail' );
check( str_contains( $html, 'sale_id=invoice%2Fid%3C%26' ), 'stable ID URL encoded' );
$html = fpw_sales_history_markup( array( $multi ), array( 'sale_id' => $multi['id'] ) );
check( ! str_contains( $html, '<script>' ) && ! str_contains( $html, '<b>Original' ), 'source content escaped' );
check( 2 === substr_count( $html, 'Total línea: 15,0579625 CLP' ) && str_contains( $html, '30,115925 CLP' ), 'exact decimal line and invoice totals kept distinct' );
check( str_contains( $html, 'Archivo: No disponible' ), 'missing provenance explicit' );
$html = fpw_sales_history_markup( $sales, array( 'sale_id' => 'sale-1', 'history_page' => '2', 'action' => 'evil' ) );
check( str_contains( $html, 'history_page=2' ) && ! str_contains( $html, 'action=' ), 'return keeps page only' );
check( str_contains( $html, 'Desglose por líneas no disponible' ) && str_contains( $html, 'No disponible en origen' ), 'legacy omissions explicit' );
check( str_contains( $html, '123.456.789.012,123456789012 CLP' ), 'large decimal exact' );
foreach ( array( array( 'sale_id' => array() ), array( 'sale_id' => '' ), array( 'history_page' => array() ), array( 'history_page' => '-1' ), array( 'history_page' => '1e2' ), array( 'sale_id' => str_repeat( 'a', FPW_SALES_MAX_ID + 1 ) ) ) as $query ) {
 check( str_contains( fpw_sales_history_markup( $sales, $query ), 'Parámetros inválidos' ), 'reject malformed parameter' );
}
check( str_contains( fpw_sales_history_markup( $sales, array( 'sale_id' => 'missing' ) ), 'Registro no encontrado' ), 'unknown ID explicit' );
check( str_contains( fpw_sales_history_markup( array(), array() ), 'Aún no hay ventas' ), 'empty history explicit' );
foreach ( array( array(), array( 'fpw_manage_quotations' => true ) ) as $caps ) {
 $GLOBALS['fpws_caps'] = $caps;
 foreach ( array( array(), array( 'sale_id' => 'sale-1' ) ) as $query ) {
  $_GET = $query;
  try { fpw_render_sales_history_screen(); check( false, 'must deny' ); }
  catch ( FPWS_Die $e ) { check( '403' === $e->getMessage(), 'list and detail permission denied' ); }
 }
}
foreach ( array( array( 'fpw_manage_data' => true ), array( 'manage_woocommerce' => true ) ) as $caps ) {
 $GLOBALS['fpws_caps'] = $caps;
 $_GET = array( 'page' => 'fpw-sales-history' );
 ob_start(); fpw_render_sales_history_screen(); $rendered = ob_get_clean();
 check( str_contains( $rendered, '<h1>Ventas históricas</h1>' ), 'authorized owner and data role render stored history' );
}
$legacy_source = array( 'id' => 'legacy', 'workbook_source' => $multi['workbook_source'] );
check( str_contains( fpw_sales_history_detail( $legacy_source ), 'Fila original 2' ), 'available raw provenance remains visible without lines' );
check( $before === $GLOBALS['fpws_table'], 'no persisted rows changed by browsing' );
echo "sales history: {$assertions} checks passed (including register regression)\n";
