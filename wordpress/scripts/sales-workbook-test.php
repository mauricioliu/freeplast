<?php
/** Synthetic XLSX boundary tests; reuse CSV runtime fakes and regression checks. */
require __DIR__ . '/sales-register-test.php';
$start = $assertions;

function workbook_zip( array $parts ): string {
	$p = tempnam( sys_get_temp_dir(), 'fpw-test-' ); $z = new ZipArchive();
	try {
		$z->open( $p, ZipArchive::OVERWRITE );
		foreach ( $parts as $name => $text ) { $z->addFromString( $name, $text ); }
		$z->close(); return file_get_contents( $p );
	} finally { unlink( $p ); }
}
function workbook_cell( string $ref, string $text ): string {
	return '<c r="' . $ref . '" t="inlineStr"><is><t>' . htmlspecialchars( $text, ENT_XML1 ) . '</t></is></c>';
}
function workbook_parts(): array {
	$header = '';
	foreach ( fpw_workbook_headers() as $i => $label ) { $header .= workbook_cell( ( $i < 26 ? chr( 65 + $i ) : 'A' . chr( 65 + $i - 26 ) ) . '1', $label ); }
	$rows = '';
	foreach ( array( 2, 3 ) as $rn ) {
		$cells = array( 'A' => '76.123.456-7', 'B' => '45292', 'E' => 'Caja ' . $rn, 'K' => '1', 'L' => '10.125', 'M' => '10.125', 'O' => '12.04875', 'W' => '123', 'Y' => '', 'AC' => '', 'AD' => '<script>alert(1)</script>' );
		$rows .= '<row r="' . $rn . '">';
		foreach ( $cells as $col => $text ) { $rows .= workbook_cell( $col . $rn, $text ); }
		$rows .= '</row>';
	}
	return array(
		'[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>',
		'xl/workbook.xml' => '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><workbookPr/><sheets><sheet name="Ventas" sheetId="1" r:id="rId1"/></sheets></workbook>',
		'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
		'xl/worksheets/sheet1.xml' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1">' . $header . '</row>' . $rows . '<row r="4"/></sheetData></worksheet>',
	);
}
function reject_workbook( array $parts, string $message ): void { check( ! fpw_sales_parse_xlsx( workbook_zip( $parts ) )['ok'], $message ); }
$parts = workbook_parts(); $xlsx = workbook_zip( $parts );
$result = fpw_sales_parse_xlsx( $xlsx );
check( $result['ok'], 'synthetic workbook opens' );
$b = $result['batch'];
check( 2 === count( $b['rows'] ) && 1 === $b['blank_rows'], 'all nonblank rows retained, blank counted' );
check( '2024-01-01' === $b['rows'][0]['date'], '1900 epoch dates decoded' );
check( '12.04875' === $b['rows'][0]['total'], 'stored decimals exact' );
check( array( 'AD' ) === $b['extra_columns'], 'extra source column warned and retained' );
$mapped = fpw_sales_workbook_plan( $b['rows'], array(), array() );
check( 1 === count( $mapped ) && 'new' === $mapped[0]['status'] && '24.0975' === $mapped[0]['total'], 'multiline invoice totals count O once' );
$overflow = $b['rows']; foreach ( $overflow as &$line ) { $line['total'] = '999999999999'; } unset( $line );
check( 'pending' === fpw_sales_workbook_plan( $overflow, array(), array() )[0]['status'], 'oversized exact invoice sum becomes pending, never crashes preview' );
foreach ( array( '1e3', 'NaN', '1,25', '0.1234567890123', '-1' ) as $invalid ) {
	$bad = $b['rows']; $bad[0]['total'] = $invalid;
	check( 'pending' === fpw_sales_workbook_plan( $bad, array(), array() )[0]['status'], 'unsupported/signed decimal remains pending: ' . $invalid );
}
$old = $mapped[0];
check( 'existing' === fpw_sales_workbook_plan( $b['rows'], array(), array( $old ) )[0]['status'], 'existing snapshot recognized' );
$old['lines'][0]['precio'] = '99';
check( 'conflict' === fpw_sales_workbook_plan( $b['rows'], array(), array( $old ) )[0]['status'], 'changed snapshot whole-entity conflict' );
unset( $old['lines'] );
check( array( 'lines' ) === fpw_sales_workbook_plan( $b['rows'], array(), array( $old ) )[0]['fill_fields'], 'empty snapshot classified enrichment' );
$legacy = array( '123' => array( 'id' => '123', 'rut' => '761234567', 'date' => '2024-01-01', 'total' => '12.04875', 'producto' => 'Caja 2', 'precio' => '10.125', 'neto' => '10.125', 'total_cajas' => '1' ) );
$one = array( $b['rows'][0] );
check( 'pending' === fpw_sales_workbook_plan( $one, array(), array_values( $legacy ) )[0]['status'], 'missing trusted evidence blocks old ID, not a new identity' );
check( 'enrich' === fpw_sales_workbook_plan( $one, $legacy, array_values( $legacy ) )[0]['status'], 'proven legacy identity can preview missing snapshot' );
$changed = $one; $changed[0]['rut'] = '761234568';
check( 'conflict' === fpw_sales_workbook_plan( $changed, $legacy, array_values( $legacy ) )[0]['status'], 'legacy RUT mismatch separate conflict' );
check( array() === fpw_sales_workbook_legacy(), 'no configured evidence fails closed' );
$_POST['legacy'] = $legacy;
check( array() === fpw_sales_workbook_legacy(), 'browser-supplied evidence ignored' );
unset( $_POST['legacy'] );
$protected = $GLOBALS['fpws_table'];
check( fpw_sales_process_upload_string( $xlsx, 'ventas.xlsx' )['ok'], 'XLSX stage through existing upload seam' );
$pending = fpw_sales_pending_batch();
check( 'xlsx' === $pending['format'] && hash( 'sha256', $xlsx ) === $pending['source_sha256'], 'batch binds source hash and format' );
$html = fpw_sales_pending_preview_html();
check( str_contains( $html, '24.0975' ) && str_contains( $html, '12.04875' ), 'exact entity and line decimals displayed without rounding' );
check( str_contains( $html, '&lt;script&gt;' ) && ! str_contains( $html, '<script>' ), 'source cells escaped' );
check( ! str_contains( $html, 'value="confirm"' ) && str_contains( $html, 'good-fpw_sales_cancel' ), 'only nonce-protected cancel, no XLSX confirm control' );
check( ! fpw_sales_confirm( $pending['token'] )['ok'], 'forged direct XLSX confirm blocked' );
check( fpw_sales_cancel( $pending['token'] )['ok'], 'XLSX cancels' );
check( $protected === $GLOBALS['fpws_table'], 'preview, blocked confirm and cancel leave protected state identical' );
$sheet = 'xl/worksheets/sheet1.xml';
$variants = array(
	'wrong header' => str_replace( '>RUT<', '>Wrong<', $parts[$sheet] ),
	'duplicate cell' => str_replace( '</row><row r="3">', workbook_cell( 'A2', 'duplicate' ) . '</row><row r="3">', $parts[$sheet] ),
	'duplicate row' => str_replace( '<row r="4"/>', '<row r="4"/><row r="4"/>', $parts[$sheet] ),
	'row limit' => str_replace( '<row r="4"/>', '<row r="5002"/>', $parts[$sheet] ),
	'uncached formula' => str_replace( workbook_cell( 'O2', '12.04875' ), '<c r="O2"><f>M2*1.19</f></c>', $parts[$sheet] ),
	'empty cache' => str_replace( workbook_cell( 'O2', '12.04875' ), '<c r="O2"><f>M2*1.19</f><v/></c>', $parts[$sheet] ),
	'cached error' => str_replace( workbook_cell( 'O2', '12.04875' ), '<c r="O2" t="e"><f>1/0</f><v>#DIV/0!</v></c>', $parts[$sheet] ),
	'external formula' => str_replace( workbook_cell( 'O2', '12.04875' ), '<c r="O2"><f>[other.xlsx]Sheet1!A1</f><v>1</v></c>', $parts[$sheet] ),
	'invalid date' => str_replace( '>45292<', '>2024-02-30<', $parts[$sheet] ),
	'fractional date' => str_replace( '>45292<', '>45292.5<', $parts[$sheet] ),
	'fictitious date' => str_replace( '>45292<', '>60<', $parts[$sheet] ),
	'oversized cell' => str_replace( '>Caja 2<', '>' . str_repeat( 'a', 8193 ) . '<', $parts[$sheet] ),
	'bad XML' => $parts[$sheet] . '<invalid>',
	'DTD/entity' => '<!DOCTYPE worksheet [<!ENTITY x SYSTEM "file:///etc/passwd">]>' . $parts[$sheet],
);
foreach ( $variants as $reason => $xml ) { $p = $parts; $p[$sheet] = $xml; reject_workbook( $p, $reason ); }
foreach ( array( 'xl/vbaProject.bin', 'xl/externalLinks/externalLink1.xml', '../escape.xml', 'xl/embeddings/ole.bin' ) as $name ) { $p = $parts; $p[$name] = '<x/>'; reject_workbook( $p, 'forbidden ZIP member ' . $name ); }
$p = $parts; $p['extra.xml'] = str_repeat( 'x', 9000000 ); reject_workbook( $p, 'ZIP expansion/ratio bounded' );
$p = $parts; for ( $i = 0; $i < 257; $i++ ) { $p['extra' . $i . '.xml'] = '<x/>'; } reject_workbook( $p, 'ZIP member count bounded' );
$p = $parts; $p['xl/_rels/workbook.xml.rels'] = str_replace( 'Target="worksheets/sheet1.xml"', 'Target="https://example.test/sheet.xml" TargetMode="External"', $p['xl/_rels/workbook.xml.rels'] ); reject_workbook( $p, 'external relationship' );
$p = $parts; $p['xl/workbook.xml'] = str_replace( '</workbook>', '<definedNames><definedName name="remote">[remote.xlsx]Sheet1!A1</definedName></definedNames></workbook>', $p['xl/workbook.xml'] ); reject_workbook( $p, 'external defined name' );
$p = $parts; $p['[Content_Types].xml'] = str_replace( 'spreadsheetml.sheet', 'ms-excel.sheet.macroEnabled', $p['[Content_Types].xml'] ); reject_workbook( $p, 'macro-enabled content type' );
$p = $parts; $p[$sheet] = str_replace( workbook_cell( 'O2', '12.04875' ), '<c r="O2"><f>M2*1.19</f><v>12.04875</v></c>', $p[$sheet] );
check( '12.04875' === fpw_sales_parse_xlsx( workbook_zip( $p ) )['batch']['rows'][0]['total'], 'formula reads cached exact value, no evaluation' );
$p = $parts; $p['xl/workbook.xml'] = str_replace( '<workbookPr/>', '<workbookPr date1904="1"/>', $p['xl/workbook.xml'] ); $p[$sheet] = str_replace( '>45292<', '>0<', $p[$sheet] );
check( '1904-01-01' === fpw_sales_parse_xlsx( workbook_zip( $p ) )['batch']['rows'][0]['date'], '1904 epoch supported' );
$p = $parts; $p['xl/sharedStrings.xml'] = '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><r><t>Caja </t></r><r><t>2</t></r></si></sst>';
$p[$sheet] = str_replace( workbook_cell( 'E2', 'Caja 2' ), '<c r="E2" t="s"><v>0</v></c>', $p[$sheet] );
check( 'Caja 2' === fpw_sales_parse_xlsx( workbook_zip( $p ) )['batch']['rows'][0]['producto'], 'shared rich strings concatenate' );
$p[$sheet] = str_replace( '<v>0</v>', '<v>99</v>', $p[$sheet] ); reject_workbook( $p, 'invalid shared string index' );
check( ! fpw_sales_parse_xlsx( str_repeat( 'x', FPW_SALES_MAX_BYTES + 1 ) )['ok'], 'compressed input limit' );
check( ! fpw_sales_parse_xlsx( 'not zip' )['ok'], 'non-ZIP refused as XLSX' );
check( ! fpw_sales_process_upload_string( 'not zip', 'bad.xlsx' )['ok'], 'filename routes malformed XLSX safely' );
check( $protected === $GLOBALS['fpws_table'], 'rejected upload never stages or changes history' );
// Evidence seam: synthetic private file with independent config digest; tampering fails closed.
$evidence = tempnam( sys_get_temp_dir(), 'fpw-evidence-test-' );
try {
	file_put_contents( $evidence, json_encode( $legacy ) ); chmod( $evidence, 0600 );
	define( 'FPW_SALES_LEGACY_FILE', $evidence ); define( 'FPW_SALES_LEGACY_SHA256', hash_file( 'sha256', $evidence ) );
	check( $legacy === fpw_sales_workbook_legacy(), 'private pinned evidence loaded' );
	$_SERVER['DOCUMENT_ROOT'] = dirname( $evidence );
	check( array() === fpw_sales_workbook_legacy(), 'evidence inside served root refused even with valid digest' );
	unset( $_SERVER['DOCUMENT_ROOT'] );
	file_put_contents( $evidence, '{}' );
	check( array() === fpw_sales_workbook_legacy(), 'tampered evidence fails closed' );
	file_put_contents( $evidence, json_encode( $legacy ) ); chmod( $evidence, 0644 ); clearstatcache();
	check( array() === fpw_sales_workbook_legacy(), 'public-readable evidence refused' );
} finally { unlink( $evidence ); }
echo 'sales workbook: ' . ( $assertions - $start ) . " XLSX checks passed (plus CSV regression)\n";
