<?php
/** The owner-approved PDF renderer. No transport, mutable commercial reads or remote assets. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** A run-owned private directory, never an upload or a public document URL. */
function fpw_quotation_temp_dir(): string {
	$dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/fpw-document-' . bin2hex( random_bytes( 16 ) );
	if ( ! mkdir( $dir, 0700 ) ) { throw new RuntimeException( 'Private document storage unavailable' ); }
	return $dir;
}

/** Remove only the directory this operation created; do not follow symbolic links. */
function fpw_quotation_remove_temp( string $dir ): void {
	foreach ( scandir( $dir ) ?: array() as $name ) {
		if ( '.' === $name || '..' === $name ) { continue; }
		$path = $dir . '/' . $name;
		if ( is_dir( $path ) && ! is_link( $path ) ) { fpw_quotation_remove_temp( $path ); }
		else { unlink( $path ); }
	}
	rmdir( $dir );
}

/** Render only the fixed offer; failure is caught by the issuance document stage. */
function fpw_quotation_dompdf_render( array $version ): string {
	$vendor = __DIR__ . '/vendor/dompdf';
	$assets = __DIR__ . '/pdf-assets';
	$lock = json_decode( (string) file_get_contents( __DIR__ . '/quotation-pdf.lock.json' ), true );
	if ( ! is_array( $lock ) || ! is_file( $vendor . '/autoload.inc.php' ) || trim( (string) file_get_contents( $vendor . '/VERSION' ) ) !== '3.1.6' ) {
		throw new RuntimeException( 'Approved PDF dependency unavailable' );
	}
	foreach ( array( 'mark.svg', 'Manrope-Regular.ttf', 'Manrope-Bold.ttf', 'OFL.txt' ) as $name ) {
		$sha = $lock['assets'][ $name ] ?? null;
		if ( ! is_string( $sha ) || ! is_file( $assets . '/' . $name ) || ! hash_equals( $sha, hash_file( 'sha256', $assets . '/' . $name ) ) ) {
			throw new RuntimeException( 'Approved PDF asset unavailable' );
		}
	}
	// Never combine our distribution with another plugin's already-loaded PDF classes.
	$prefixes = array( 'Dompdf\\', 'FontLib\\', 'Svg\\', 'Masterminds\\', 'Sabberworm\\' );
	foreach ( get_declared_classes() as $class ) {
		foreach ( $prefixes as $prefix ) {
			if ( str_starts_with( $class, $prefix ) && ! str_starts_with( (string) ( new ReflectionClass( $class ) )->getFileName(), $vendor . '/' ) ) {
				throw new RuntimeException( 'Conflicting PDF dependency' );
			}
		}
	}
	require_once $vendor . '/autoload.inc.php';
	$dir = fpw_quotation_temp_dir();
	try {
		$options = new \Dompdf\Options();
		$options->setIsRemoteEnabled( false );
		$options->setIsPhpEnabled( false );
		$options->setIsJavascriptEnabled( false );
		$options->setAllowedProtocols( array( 'file://' ) );
		$options->setChroot( array( $assets ) );
		$options->setTempDir( $dir );
		$options->setFontDir( $dir );
		$options->setFontCache( $dir );
		$options->setIsFontSubsettingEnabled( true );
		$pdf = new \Dompdf\Dompdf( $options );
		$pdf->setPaper( 'letter' );
		// Dompdf caches family -> file paths across instances. A per-render CSS alias
		// prevents a later issuance from reusing paths in an already removed private cache.
		$pdf->loadHtml( fpw_quotation_document_html( $version, $assets, 'FPWManrope-' . basename( $dir ) ), 'UTF-8' );
		$pdf->render();
		return $pdf->output();
	} finally { fpw_quotation_remove_temp( $dir ); }
}

/** Buyer-facing HTML for the PDF. Formatting is not a second commercial calculator. */
function fpw_quotation_document_html( array $version, string $assets, string $font_family ): string {
	$p = $version['projection'] ?? null;
	if ( ! is_array( $p ) || empty( $p['complete'] ) ) { throw new RuntimeException( 'Incomplete approved projection' ); }
	$esc = static fn( $text ): string => htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	$clp = static function ( $amount ): string {
		if ( ! is_int( $amount ) ) { throw new RuntimeException( 'Incomplete approved amount' ); }
		return number_format( $amount, 0, ',', '.' ) . ' CLP';
	};
	$rows = '';
	foreach ( $p['lines'] as $line ) {
		$name = $esc( $line['name'] );
		foreach ( (array) ( $line['options'] ?? array() ) as $option ) {
			$name .= '<br><span class="option">' . $esc( wc_attribute_label( $option['key'] ) ) . ': ' . $esc( $option['value'] ) . '</span>';
		}
		$rows .= '<tr><td>' . $name . '</td><td class="number">' . (int) $line['quantity'] . '</td><td class="number">' . $clp( $line['price'] ) . '</td><td class="number">' . $clp( $line['line_total'] ) . '</td></tr>';
	}
	$summary = '<tr><td>Subtotal (neto)</td><td>' . $clp( $p['subtotal'] ) . '</td></tr>';
	if ( $p['dispatch_requested'] ) { $summary .= '<tr><td>Despacho (neto)</td><td>' . $clp( $p['dispatch'] ) . '</td></tr>'; }
	$summary .= '<tr><td>IVA (' . $esc( fpw_draft_tax_rate_percent_html( $p['tax_rate_permille'] ) ) . '%)</td><td>' . $clp( $p['tax'] ) . '</td></tr>';
	$summary .= '<tr class="total"><td>Total</td><td>' . $clp( $p['total'] ) . '</td></tr>';
	$base = 'file://' . $assets . '/';
	return '<!doctype html><html lang="es"><head><meta charset="utf-8"><title>Cotización ' . $esc( $version['reference'] ) . '</title><style>
@font-face{font-family:' . $font_family . ';src:url("' . $esc( $base . 'Manrope-Regular.ttf' ) . '");font-weight:400}
@font-face{font-family:' . $font_family . ';src:url("' . $esc( $base . 'Manrope-Bold.ttf' ) . '");font-weight:700}
@page{margin:40pt}body{font-family:' . $font_family . ',sans-serif;font-size:10pt;line-height:1.5;color:#202a20}
.brand{font-size:17pt;font-weight:700;margin-bottom:22pt}.brand img{width:28pt;height:28pt;vertical-align:middle;margin-right:8pt}
h1{font-size:22pt;line-height:1.15;margin:0 0 6pt}p{margin:6pt 0 12pt}.reference{font-size:11pt;margin-bottom:18pt}
table{border-collapse:collapse;width:100%;table-layout:fixed}thead{display:table-header-group}tr{page-break-inside:avoid}
th{text-align:left;font-size:9pt;border-bottom:1pt solid #306020;padding:8pt 4pt}td{padding:9pt 4pt;border-bottom:.5pt solid #d4dad1;vertical-align:top;overflow-wrap:break-word}
.number{text-align:right;white-space:nowrap;font-size:9pt}.option{color:#485448;font-size:9pt}
.summary{margin:18pt 0 20pt 42%;width:58%;page-break-inside:avoid}.summary td{padding:6pt 4pt}.summary td+td{text-align:right;white-space:nowrap}
.total{font-size:12pt;font-weight:700}h2{font-size:11pt;margin:20pt 0 6pt}.conditions{page-break-inside:avoid}
</style></head><body><div class="brand"><img src="' . $esc( $base . 'mark.svg' ) . '" alt="">Freeplast</div>'
		. '<h1>Cotización</h1><p class="reference">' . $esc( $version['reference'] ) . ' · Versión ' . (int) $version['version'] . '</p>'
		. '<p>Fecha de aprobación: ' . $esc( wp_date( 'd/m/Y', $version['approved_at'] ) ) . '<br>Señores: ' . $esc( $version['buyer']['company'] ) . '</p>'
		. '<table><colgroup><col style="width:43%"><col style="width:11%"><col style="width:23%"><col style="width:23%"></colgroup><thead><tr><th>Producto</th><th class="number">Cantidad</th><th class="number">Precio neto</th><th class="number">Total línea</th></tr></thead><tbody>' . $rows . '</tbody></table>'
		. '<table class="summary">' . $summary . '</table><div class="conditions">'
		. ( $p['dispatch_requested'] ? '<h2>Destino de la oferta</h2><p>' . $esc( $p['destination'] ) . '</p>' : '' )
		. '<p><strong>Vigencia de la oferta: ' . (int) $p['validity_days'] . ' días</strong> a contar de su aprobación, para los productos, cantidades y destino revisados.</p></div></body></html>';
}
