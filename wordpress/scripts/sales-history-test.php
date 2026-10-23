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
check( str_contains( $html, 'role="region" aria-label="Ventas históricas" tabindex="0"' ), 'scrolling table region named and keyboard focusable' );
check( str_contains( $html, '.fpw-history a,.fpw-history button{min-height:44px;min-width:44px;' ), 'viewer links and buttons retain 44px targets' );
check( str_contains( $html, '.fpw-history a.button,.fpw-history button.button{min-height:44px;display:inline-flex;align-items:center}' ), 'all viewer buttons, including import and pagination, override WordPress two-class 40px rule' );
check( str_contains( $html, '.fpw-history summary{min-height:44px;' ) && str_contains( $html, '.fpw-history :focus-visible{outline:2px solid #100090;outline-offset:2px}' ), 'disclosures have touch targets and all viewer controls visible focus' );
check( str_contains( $html, '.fpw-history-table{max-width:100%;overflow-x:auto}' ) && str_contains( $html, 'gap:4px;min-width:0;max-width:100%' ), 'table scroll and shrinking filter labels scoped to viewer' );
$html = fpw_sales_history_markup( array( $multi ), array( 'sale_id' => $multi['id'] ) );
check( ! str_contains( $html, '<script>' ) && ! str_contains( $html, '<b>Original' ), 'source content escaped' );
check( 2 === substr_count( $html, 'Total línea: 15,0579625 CLP' ) && str_contains( $html, '30,115925 CLP' ), 'exact decimal line and invoice totals kept distinct' );
check( str_contains( $html, 'Archivo: No disponible' ), 'missing provenance explicit' );
$html = fpw_sales_history_markup( $sales, array( 'sale_id' => 'sale-1', 'history_page' => '2', 'action' => 'evil' ) );
check( str_contains( $html, 'history_page=2' ) && ! str_contains( $html, 'action=evil' ), 'return keeps page only' );
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
// Synthetic search evidence only: no customer lookup or importer writes.
$search_sales = array(
 array( 'id' => 'named', 'date' => '2024-02-29', 'rut' => '12.345.678-5', 'invoice' => 'FAC-007', 'workbook_source' => array( 'rows' => array( array( 'raw' => array( 'D' => 'Árbol Sur' ) ) ) ) ),
 array( 'id' => 'legacy', 'date' => '2024-03-01', 'rut' => '76543210K', 'invoice' => 'FAC-008', 'customer_name' => 'Inventado' ),
 array( 'id' => 'undated', 'rut' => '123456785', 'invoice' => 'FAC-009' ),
);
foreach ( array( 'árbol', 'SUR', '12.345.678-5', '12345678-5', '123456785', 'FAC-007', '007' ) as $term ) {
 $matches = fpw_sales_history_filter( $search_sales, array( 'history_search' => $term ) );
 check( 'named' === $matches[0]['id'], 'stored name, invoice and normalized RUT search: ' . $term );
}
check( 'legacy' === fpw_sales_history_filter( $search_sales, array( 'history_search' => '76.543.210-k' ) )[0]['id'], 'legacy nameless record found by RUT with lowercase K' );
check( ! fpw_sales_history_filter( $search_sales, array( 'history_search' => 'Inventado' ) ), 'unproven customer name never searched' );
check( count( fpw_sales_history_filter( array( $multi ), array( 'history_search' => 'Otra variante' ) ) ) === 1, 'all original name variants searchable' );
check( count( fpw_sales_history_filter( $search_sales, array( 'history_from' => '2024-02-29', 'history_to' => '2024-03-01' ) ) ) === 2, 'date boundaries inclusive; undated excluded' );
check( count( fpw_sales_history_filter( $search_sales, array( 'history_to' => '2024-02-29' ) ) ) === 1, 'upper bound alone' );
check( count( fpw_sales_history_filter( $search_sales, array( 'history_from' => '2024-03-01' ) ) ) === 1, 'lower bound alone' );
check( count( fpw_sales_history_filter( $search_sales, array( 'history_search' => 'árbol', 'history_from' => '2024-02-29', 'history_to' => '2024-02-29' ) ) ) === 1, 'combined search and same-day range' );
check( ! fpw_sales_history_filter( $search_sales, array( 'history_search' => 'árbol', 'history_from' => '2024-03-01' ) ), 'search AND date range' );
foreach ( array( array( 'history_search' => array() ), array( 'history_search' => null ), array( 'history_search' => 123 ), array( 'history_search' => str_repeat( 'á', 101 ) ), array( 'history_search' => "bad\nquery" ), array( 'history_search' => "\xff" ), array( 'history_from' => array() ), array( 'history_to' => null ), array( 'history_from' => '2023-02-29' ), array( 'history_to' => '2024-04-31' ), array( 'history_from' => '0000-01-01' ), array( 'history_to' => '2024-3-01' ), array( 'history_from' => '2024-03-01', 'history_to' => '2024-02-29' ), array( 'history_page' => null ) ) as $query ) {
 check( str_contains( fpw_sales_history_markup( $search_sales, $query ), 'Parámetros inválidos' ), 'invalid filters rejected without type errors' );
}
check( array( 'history_search' => 'árbol' ) === fpw_sales_history_filters( array( 'history_search' => ' árbol ', 'history_from' => '', 'action' => 'evil' ) ), 'trim search; discard empty and foreign parameters' );
check( null !== fpw_sales_history_filters( array( 'history_search' => str_repeat( 'á', 100 ) ) ), '100 Unicode characters accepted' );
check( str_contains( fpw_sales_history_markup( $search_sales, array( 'history_search' => 'absent' ) ), 'No hay ventas que coincidan' ), 'no matches distinct from empty history' );
check( str_contains( fpw_sales_history_markup( array(), array( 'history_search' => 'absent' ) ), 'Aún no hay ventas' ), 'empty underlying history remains explicit with filters' );
$html = fpw_sales_history_markup( $search_sales, array( 'history_search' => '\"><script>bad</script>' ) );
check( ! str_contains( $html, '<script>' ), 'reflected search escaped' );

// Follow actual generated links through search -> page -> detail -> back.
$filters = array( 'history_search' => '12.345.678-5', 'history_from' => '2026-01-01', 'history_to' => '2026-01-28' );
$html = fpw_sales_history_markup( $sales, $filters + array( 'action' => 'evil', 'import' => 'evil', 'redirect_to' => 'https://evil.invalid' ) );
check( str_contains( $html, '1322 registros' ) && substr_count( $html, '>Abrir sale-' ) === 25, 'filter before pagination with bounded results and count' );
check( preg_match( '/href="([^"]+)">Siguiente<\/a>/', $html, $link ) === 1, 'next link available' );
parse_str( parse_url( html_entity_decode( $link[1] ), PHP_URL_QUERY ), $next_query );
check( $next_query === array( 'page' => 'fpw-sales-history' ) + $filters + array( 'history_page' => '2' ), 'next link keeps only owned context' );
$html = fpw_sales_history_markup( $sales, $next_query );
check( preg_match( '/href="([^"]+)">Abrir sale-/', $html, $link ) === 1, 'page two detail link available' );
parse_str( parse_url( html_entity_decode( $link[1] ), PHP_URL_QUERY ), $detail_query );
check( '2' === $detail_query['history_page'] && $filters === array_intersect_key( $detail_query, $filters ), 'detail keeps search dates and page' );
$html = fpw_sales_history_markup( $sales, $detail_query );
check( preg_match( '/href="([^"]+)">Volver al historial<\/a>/', $html, $link ) === 1, 'detail has return link' );
parse_str( parse_url( html_entity_decode( $link[1] ), PHP_URL_QUERY ), $back_query );
check( $back_query === $next_query, 'return exactly restores filtered page without sale ID' );
check( str_contains( fpw_sales_history_markup( $sales, $back_query ), 'Página 2 de 53' ), 'returned filtered page renders' );
check( preg_match( '/href="([^"]+)">Limpiar filtros<\/a>/', $html, $link ) === 1, 'clear link available' );
parse_str( parse_url( html_entity_decode( $link[1] ), PHP_URL_QUERY ), $clear_query );
check( array( 'page' => 'fpw-sales-history' ) === $clear_query, 'clear removes filters page and selected sale' );
$filtered = fpw_sales_history_filter( $sales, array( 'history_from' => '2026-01-28', 'history_to' => '2026-01-28' ) );
check( 47 === count( $filtered ) && 22 === count( fpw_sales_history_page( $filtered, 2 )['sales'] ), 'date subset paginated only after filtering' );
check( str_contains( $html, 'method="get"' ) && ! str_contains( $html, '<script' ), 'filter form and links work without JavaScript' );
// A name exists only on the newer workbook row; older stored purchases still belong to its RUT.
$associated = array();
for ( $i = 1; $i <= 31; $i++ ) {
 $associated[] = array( 'id' => 'older-' . $i, 'date' => '2020-01-01', 'rut' => '12345678-5', 'producto' => 'Caja antigua', 'total_cajas' => 0, 'precio' => 0 );
}
$associated[] = array( 'id' => 'named-newer', 'date' => '2026-01-01', 'rut' => '12.345.678-5', 'workbook_source' => array( 'rows' => array( array( 'raw' => array( 'D' => 'Empresa Gemela' ) ) ) ) );
$associated[] = array( 'id' => 'same-name-other-rut', 'date' => '2020-01-01', 'rut' => '76543210K', 'workbook_source' => array( 'rows' => array( array( 'raw' => array( 'D' => 'Empresa Gemela' ) ) ) ) );
$associated[] = array( 'id' => 'unrelated', 'date' => '2020-01-01', 'rut' => '761234567', 'invoice' => '987654321', 'customer_name' => 'Empresa Gemela' );
$associated[] = array( 'id' => 'nameless-no-rut', 'date' => '2020-01-01' );
$name_filters = array( 'history_search' => 'empresa gemela', 'history_to' => '2020-12-31' );
$matches = fpw_sales_history_filter( $associated, $name_filters );
check( 32 === count( $matches ), 'name search recovers older purchases by normalized RUT even when naming evidence is outside date range' );
$seen = array();
for ( $page = 1; $page <= 2; $page++ ) {
 foreach ( fpw_sales_history_page( $matches, $page )['sales'] as $sale ) { $seen[] = $sale['id']; }
}
check( 32 === count( $seen ) && 32 === count( array_unique( $seen ) ), 'associated results survive pagination without omissions or duplicates' );
foreach ( array( '123456785', '12.345.678-5', '12345678-5' ) as $rut ) {
 $matched = fpw_sales_history_filter( $associated, array( 'history_search' => $rut ) );
 check( 32 === count( $matched ) && ! array_diff( array_column( $matched, 'rut' ), array( '12345678-5', '12.345.678-5' ) ), 'full RUT search never associates a namesake with another RUT' );
}
check( array( 'unrelated' ) === array_column( fpw_sales_history_filter( $associated, array( 'history_search' => '761234567' ) ), 'id' ), 'unproven customer_name never establishes identity' );
check( array( 'unrelated' ) === array_column( fpw_sales_history_filter( $associated, array( 'history_search' => '987654321' ) ), 'id' ), 'numeric invoice search remains available even when digits have RUT shape' );
$no_rut_named = array( 'id' => 'named-no-rut', 'workbook_source' => array( 'rows' => array( array( 'raw' => array( 'D' => 'Sin identidad' ) ) ) ) );
check( array( 'named-no-rut' ) === array_column( fpw_sales_history_filter( array_merge( $associated, array( $no_rut_named ) ), array( 'history_search' => 'Sin identidad' ) ), 'id' ), 'name evidence without a usable RUT never associates other unidentified rows' );
$legacy_html = fpw_sales_history_markup( $associated, $name_filters + array( 'sale_id' => 'older-1', 'history_page' => '2' ) );
check( str_contains( $legacy_html, 'Caja antigua' ) && str_contains( $legacy_html, '0 CLP' ) && str_contains( $legacy_html, 'No disponible en origen' ) && str_contains( $legacy_html, 'Desglose por líneas no disponible' ), 'recovered detail shows stored legacy values and honest source omissions, never an inferred name' );
check( str_contains( $legacy_html, 'history_search=empresa+gemela' ) && str_contains( $legacy_html, 'history_page=2' ), 'recovered detail keeps filtered return context' );
check( $before === $GLOBALS['fpws_table'], 'no persisted rows changed by browsing' );
echo "sales history: {$assertions} checks passed (including register regression)\n";
