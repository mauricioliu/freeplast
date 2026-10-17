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

/** Verified source data is frozen with each new version, never read to enrich old versions. */
function fpw_quotation_issuer_snapshot( bool $dispatch ): array {
	$logo = file_get_contents( __DIR__ . '/pdf-assets/logo.png' );
	$sha = 'a3e3db4a40679b15121e3a59e676c86fe51341d48147306beac30b75e23a3523';
	if ( ! is_string( $logo ) || ! hash_equals( $sha, hash( 'sha256', $logo ) ) ) { throw new RuntimeException( 'Approved issuer logo unavailable' ); }
	return array(
		'name' => 'Freeplast SPA', 'rut' => '76.766.847-3',
		'address' => 'P Picarquín sitio 52, San Fco de Mostazal, VI Región',
		'phone' => '+56 9 6844 4265', 'email' => 'ventas@freeplast.cl',
		'bank' => 'Banco de Chile', 'account_type' => 'Cuenta corriente', 'account_number' => '390400602',
		'stock_terms' => 'Cotización sujeta a stock disponible.',
		'payment_terms' => 'Pago contado vía transferencia electrónica.',
		'pickup_terms' => $dispatch ? '' : 'Cotización para retiro en bodega de Freeplast.',
		'logo_base64' => base64_encode( $logo ), 'logo_sha256' => $sha,
	);
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
		$options->setAllowedProtocols( array( 'file://', 'data://' ) );
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
		$rows .= '<tr><td class="number">' . number_format( (int) $line['quantity'], 0, ',', '.' ) . '</td><td>' . $name . '</td><td class="number">' . $clp( $line['price'] ) . '</td><td class="number">' . $clp( $line['line_total'] ) . '</td></tr>';
	}
	$summary = '<tr><td>Subtotal (neto)</td><td>' . $clp( $p['subtotal'] ) . '</td></tr>';
	if ( $p['dispatch_requested'] ) { $summary .= '<tr><td>Despacho (neto)</td><td>' . $clp( $p['dispatch'] ) . '</td></tr>'; }
	$summary .= '<tr><td>IVA (' . $esc( fpw_draft_tax_rate_percent_html( $p['tax_rate_permille'] ) ) . '%)</td><td>' . $clp( $p['tax'] ) . '</td></tr>';
	$summary .= '<tr class="total"><td>Total</td><td>' . $clp( $p['total'] ) . '</td></tr>';
	$base = 'file://' . $assets . '/';
	$brand = '<div class="brand"><img src="' . $esc( $base . 'mark.svg' ) . '" alt="">Freeplast</div>';
	$issuer_html = $terms = '';
	if ( isset( $version['issuer'] ) ) {
		$issuer = $version['issuer'];
		foreach ( array( 'name', 'rut', 'address', 'phone', 'email', 'bank', 'account_type', 'account_number', 'stock_terms', 'payment_terms', 'pickup_terms', 'logo_base64', 'logo_sha256' ) as $key ) {
			if ( ! is_array( $issuer ) || ! is_string( $issuer[ $key ] ?? null ) ) { throw new RuntimeException( 'Incomplete frozen issuer' ); }
		}
		$logo = base64_decode( $issuer['logo_base64'], true );
		if ( ! is_string( $logo ) || ! str_starts_with( $logo, "\x89PNG\r\n\x1a\n" ) || ! hash_equals( $issuer['logo_sha256'], hash( 'sha256', $logo ) ) ) { throw new RuntimeException( 'Invalid frozen issuer logo' ); }
		$brand = '<div class="brand"><img style="width:90pt;height:auto" src="data:image/png;base64,' . $esc( $issuer['logo_base64'] ) . '" alt="Freeplast"></div>';
		$issuer_html = '<table class="details issuer"><tr><th>Empresa</th><td>' . $esc( $issuer['name'] ) . '</td></tr><tr><th>RUT</th><td>' . $esc( $issuer['rut'] ) . '</td></tr><tr><th>Dirección</th><td>' . $esc( $issuer['address'] ) . '</td></tr><tr><th>Teléfonos</th><td>' . $esc( $issuer['phone'] ) . '</td></tr><tr><th>E-mail</th><td>' . $esc( $issuer['email'] ) . '</td></tr></table>';
		$terms = '<p>' . $esc( $issuer['stock_terms'] ) . '<br>' . $esc( $issuer['payment_terms'] ) . ( $issuer['pickup_terms'] !== '' ? '<br>' . $esc( $issuer['pickup_terms'] ) : '' ) . '</p>'
			. '<h2>Datos para transferencia</h2><p>' . $esc( $issuer['name'] ) . ' · RUT ' . $esc( $issuer['rut'] ) . '<br>' . $esc( $issuer['bank'] ) . ' · ' . $esc( $issuer['account_type'] ) . ' Nº ' . $esc( $issuer['account_number'] ) . '<br>' . $esc( $issuer['email'] ) . '</p>';
	}
	return '<!doctype html><html lang="es"><head><meta charset="utf-8"><title>Cotización ' . $esc( $version['reference'] ) . '</title><style>
@font-face{font-family:' . $font_family . ';src:url("' . $esc( $base . 'Manrope-Regular.ttf' ) . '");font-weight:400}
@font-face{font-family:' . $font_family . ';src:url("' . $esc( $base . 'Manrope-Bold.ttf' ) . '");font-weight:700}
@page{margin:36pt}body{font-family:' . $font_family . ',sans-serif;font-size:9pt;line-height:1.15;color:#17181c}
.brand{font-size:17pt;font-weight:700}.brand img{width:28pt;height:28pt;vertical-align:middle;margin-right:8pt}
h1{font-size:16pt;line-height:1.2;margin:0 0 14pt}p{margin:5pt 0 9pt}.reference{font-size:8pt;color:#555;margin:6pt 0 0}
table{border-collapse:collapse;width:100%;table-layout:fixed}thead{display:table-header-group}tr{page-break-inside:avoid}
th{text-align:left;font-size:8pt;padding:5pt}td{padding:5pt;vertical-align:top;overflow-wrap:break-word}
.header{margin-bottom:18pt;page-break-inside:avoid}.header td{border:0;padding:0}.document-meta{text-align:right}.date{background:#edf0f8;padding:4pt 8pt;border:0.6pt solid #17181c}
.details{margin-bottom:14pt;page-break-inside:avoid}.details th,.details td{border:0.6pt solid #555;padding:2pt 5pt;line-height:1.1}.details th{width:19%;font-size:9pt}.client-title th,.client-title td{background:#e2edda;font-weight:700}
.items th{background:#e2edda;color:#203f15;font-weight:700}.items td,.items th{border:0.6pt solid #555}.items td{padding:7pt 5pt}
.number{text-align:right;white-space:nowrap;font-size:8pt}.option{color:#485448;font-size:8pt}
.totals-layout{margin-bottom:14pt;page-break-inside:avoid}.totals-layout>tbody>tr>td{padding:0;border:0}.pickup-details{padding:8pt 12pt 8pt 0}.summary{margin:0;width:100%;page-break-inside:avoid}.summary td{padding:4pt 5pt;border:0.6pt solid #555}.summary td+td{text-align:right;white-space:nowrap}
.total{font-size:11pt;font-weight:700;background:#e2edda}h2{font-size:9pt;margin:10pt 0 4pt}.conditions{border:0.6pt solid #555;padding:7pt;page-break-inside:avoid}.conditions p:last-child{margin-bottom:0}
</style></head><body><table class="header"><tr><td style="width:36%">' . $brand . '</td><td class="document-meta"><h1>Cotización' . ( isset( $version['commercial']['number'] ) ? ' Nº ' . (int) $version['commercial']['number'] : '' ) . '</h1><span class="date">Fecha: ' . $esc( wp_date( 'd/m/Y', $version['approved_at'] ) ) . '</span><p class="reference">' . $esc( $version['reference'] ) . ' · Versión ' . (int) $version['version'] . '</p></td></tr></table>' . $issuer_html
		. '<table class="details client"><tr class="client-title"><th>Cliente</th><td>' . $esc( $version['buyer']['company'] ) . '</td></tr>'
		. ( ! empty( $version['buyer']['rut'] ) ? '<tr><th>RUT</th><td>' . $esc( $version['buyer']['rut'] ) . '</td></tr>' : '' )
		. '<tr><th>Contacto</th><td>' . $esc( $version['buyer']['name'] ?? '' ) . '</td></tr>'
		. ( ! empty( $version['buyer']['phone'] ) ? '<tr><th>Teléfonos</th><td>' . $esc( $version['buyer']['phone'] ) . '</td></tr>' : '' )
		. '<tr><th>E-mail</th><td>' . $esc( $version['buyer']['email'] ?? '' ) . '</td></tr></table>'
		. '<table class="items"><colgroup><col style="width:11%"><col style="width:43%"><col style="width:23%"><col style="width:23%"></colgroup><thead><tr><th class="number" style="width:11%">Cant.</th><th style="width:43%">Artículos</th><th class="number" style="width:23%">Valor unit. neto</th><th class="number" style="width:23%">Valor neto</th></tr></thead><tbody>' . $rows . '</tbody></table>'
		. '<table class="totals-layout"><tr><td style="width:48%">' . ( ! $p['dispatch_requested'] && ! empty( $p['pickup_address'] ) ? '<div class="pickup-details"><h2>Retiro - Sin Despacho</h2><p>' . $esc( $p['pickup_address'] ) . '</p><p>No incluye flete.</p></div>' : '' ) . '</td><td style="width:52%"><table class="summary">' . $summary . '</table></td></tr></table><div class="conditions">'
		. ( $p['dispatch_requested'] ? '<h2>Destino de la oferta</h2><p>' . $esc( $p['destination'] ) . '</p>' : '' )
		. ( $p['dispatch_requested'] && ! empty( $p['commune'] ) ? '<p>Comuna de despacho: ' . $esc( $p['commune'] ) . '</p>' : '' )
		. ( $p['dispatch_requested'] && ! empty( $p['location_url'] ) ? '<p>Enlace de ubicación: ' . $esc( $p['location_url'] ) . '</p>' : '' )
		. ( $p['dispatch_requested'] && ! empty( $p['references'] ) ? '<p>Referencias para llegar: ' . $esc( $p['references'] ) . '</p>' : '' )
		. '<p><strong>Vigencia de la oferta: ' . (int) $p['validity_days'] . ' días</strong> a contar de su aprobación, para los productos, cantidades y destino revisados.</p>' . $terms . '</div></body></html>';
}
