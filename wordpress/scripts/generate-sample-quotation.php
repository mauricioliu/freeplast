<?php
/** Step 8 (offline preparation): regenerates the FICTITIOUS quotation sample — reference MUESTRA-NO-EMITIDA, commercial Nº 1761 quoted directly into the fictitious version (NO allocation call, NO mail, no runtime), current issuer/bank/terms snapshot, minimum-valid quantities, default 15-day validity. Also regenerates the ignored multi-page pagination sample. Manual, reproducible: php scripts/generate-sample-quotation.php */
require __DIR__ . '/quote-draft-test.php';

/** One fictitious projection line with a minimum-valid quantity and fictitious integer CLP prices. */
function fpw_sample_line( string $name, int $quantity, int $price, array $options = array() ): array {
	return array( 'name' => $name, 'quantity' => $quantity, 'price' => $price, 'line_total' => $quantity * $price, 'options' => $options );
}

/** The fictitious sample version: every field explicit, nothing derived from runtime state. */
function fpw_sample_version( bool $dispatch, array $lines ): array {
	$subtotal = array_sum( array_map( static fn( $line ) => $line['line_total'], $lines ) );
	$dispatch_amount = $dispatch ? 1000 : 0;
	$tax = (int) round( ( $subtotal + $dispatch_amount ) * 0.19 );
	return array(
		'schema' => 1, 'order_id' => 0, 'version' => 1,
		'reference' => 'MUESTRA-NO-EMITIDA',
		/* Quoted directly: the sample allocates NOTHING — a real number only ever comes from fpw_quotation_allocate_number() at a real approval. */
		'commercial' => array( 'number' => 1761, 'allocated_at' => time() ),
		'attempt' => str_repeat( 'ab', 32 ), 'work_revision' => 1, 'approved_at' => time(), 'approved_by' => 0,
		'buyer' => array( 'name' => 'Muestra', 'company' => 'Empresa ficticia (muestra, no emitida)', 'email' => 'muestra@invalid' ),
		'projection' => array(
			'lines' => $lines, 'subtotal' => $subtotal,
			'dispatch_requested' => $dispatch, 'dispatch' => $dispatch ? $dispatch_amount : null,
			'destination' => $dispatch ? 'Destino ficticio de muestra, Mostazal' : '', 'references' => $dispatch ? 'Referencias ficticias' : '',
			'tax_rate_permille' => 190, 'tax' => $tax, 'total' => $subtotal + $dispatch_amount + $tax,
			'validity_days' => 15, 'complete' => true, 'missing' => array(),
		),
		'issuer' => fpw_quotation_issuer_snapshot( $dispatch ),
		'document' => 'ready',
	);
}

/* Two-line dispatch sample: minimum-valid quantities (Cosechera 70/70, Universal 100/100), fictitious prices. */
$sample = fpw_sample_version( true, array(
	fpw_sample_line( 'Caja Cosechera 3/4', 70, 340 ),
	fpw_sample_line( 'Caja Universal Cerrada Color', 100, 2190, array( array( 'key' => 'pa_color', 'value' => 'Azul' ) ) ),
) );
$pdf = fpw_quotation_dompdf_render( $sample );
if ( ! fpw_quotation_pdf_is_valid( $pdf ) ) { fwrite( STDERR, "sample render invalid\n" ); exit( 1 ); }
$path = __DIR__ . '/../../docs/reviews/commercial-update/sample-quotation.pdf';
if ( strlen( $pdf ) !== file_put_contents( $path, $pdf ) ) { fwrite( STDERR, "sample write incomplete\n" ); exit( 1 ); }
echo "sample: $path (" . strlen( $pdf ) . " bytes, Nº 1761, MUESTRA-NO-EMITIDA)\n";

/* Multi-page pagination sample: 40 long-named fictitious lines, pickup terms (no dispatch). */
$long_lines = array();
for ( $i = 1; $i <= 40; $i++ ) {
	$long_lines[] = fpw_sample_line( 'Caja agrícola ficticia de muestra con nombre largo para revisar su presentación — PIEZA' . str_pad( (string) $i, 3, '0', STR_PAD_LEFT ), 50, 1000 );
}
$multipage = fpw_sample_version( false, $long_lines );
$pdf = fpw_quotation_dompdf_render( $multipage );
if ( ! fpw_quotation_pdf_is_valid( $pdf ) ) { fwrite( STDERR, "multipage render invalid\n" ); exit( 1 ); }
$dir = __DIR__ . '/../../.scratch/commercial-update';
if ( ! is_dir( $dir ) ) { mkdir( $dir, 0700, true ); }
$path = $dir . '/sample-multipage.pdf';
if ( strlen( $pdf ) !== file_put_contents( $path, $pdf ) ) { fwrite( STDERR, "multipage write incomplete\n" ); exit( 1 ); }
echo "multipage: $path (" . strlen( $pdf ) . " bytes)\n";
