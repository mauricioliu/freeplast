<?php
/** Bounded, stored-value-only XLSX reader and private preview. Never applies sales. */
require_once __DIR__ . '/sales-workbook-contract.php';

function fpw_workbook_headers(): array {
	return explode( '|', 'RUT|Fecha|Mes|CLIENTE|Producto|T|C|U|M|Sueltas|Total Cajas|Precio|Neto|Fact.|Total|Precio referencia|Transporte|Total con flete|Descuento Flete / otros|Utilidad Neta|Utilidad|N° OC|N° Factura|Fecha de entrega|Estado Pago|fecha de Pago|Lugar origen Venta|Entrega|Obs' );
}

/** No DTD, entity expansion, network access or recovery of malformed XML. */
function fpw_workbook_xml( string $text ): DOMDocument {
	if ( preg_match( '/<!DOCTYPE|<!ENTITY/i', $text ) || str_contains( $text, "\0" ) ) { throw new RuntimeException( 'XML no permitido.' ); }
	$previous = libxml_use_internal_errors( true );
	try {
		$doc = new DOMDocument();
		if ( ! $doc->loadXML( $text, LIBXML_NONET ) || $doc->doctype ) { throw new RuntimeException( 'XML inválido.' ); }
		return $doc;
	} finally { libxml_clear_errors(); libxml_use_internal_errors( $previous ); }
}

function fpw_workbook_xpath( DOMDocument $doc ): DOMXPath {
	$xp = new DOMXPath( $doc );
	$xp->registerNamespace( 's', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );
	return $xp;
}

/** Excel serial dates: reject fictitious 1900-02-29, fractions and overflow. */
function fpw_workbook_date( string $raw, bool $epoch1904 ): string {
	if ( ! preg_match( '/^\d{1,7}(?:\.0+)?$/D', $raw ) ) { throw new RuntimeException( 'Fecha almacenada inválida.' ); }
	$days = (int) $raw;
	if ( $days < 0 || $days > ( $epoch1904 ? 2957003 : 2958465 ) || ( ! $epoch1904 && $days < 1 ) || ( ! $epoch1904 && 60 === $days ) ) { throw new RuntimeException( 'Fecha fuera de rango.' ); }
	return ( new DateTimeImmutable( $epoch1904 ? '1904-01-01' : '1899-12-31', new DateTimeZone( 'UTC' ) ) )->modify( '+' . ( $days - ( ! $epoch1904 && $days > 60 ? 1 : 0 ) ) . ' days' )->format( 'Y-m-d' );
}

/** Input capped before ZIP open; never extract any member to a filesystem path. */
function fpw_sales_parse_xlsx( string $content ): array {
	if ( strlen( $content ) > FPW_SALES_MAX_BYTES || ! str_starts_with( $content, "PK\x03\x04" ) ) { return array( 'ok' => false, 'error' => 'XLSX inválido o mayor de 2 MB.' ); }
	if ( ! class_exists( 'ZipArchive' ) || ! class_exists( 'DOMDocument' ) ) { return array( 'ok' => false, 'error' => 'El servidor requiere las extensiones ZIP y DOM para leer XLSX.' ); }
	$tmp = tempnam( sys_get_temp_dir(), 'fpw-xlsx-' );
	if ( false === $tmp ) { return array( 'ok' => false, 'error' => 'No se pudo preparar la lectura privada.' ); }
	$zip = new ZipArchive(); $opened = false;
	try {
		chmod( $tmp, 0600 );
		if ( strlen( $content ) !== file_put_contents( $tmp, $content ) || true !== $zip->open( $tmp, ZipArchive::RDONLY ) ) { throw new RuntimeException( 'ZIP inválido.' ); }
		$opened = true; $names = array(); $expanded = 0;
		if ( $zip->numFiles > 256 ) { throw new RuntimeException( 'Demasiadas entradas ZIP.' ); }
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i ); $name = $stat['name'];
			if ( isset( $names[$name] ) || preg_match( '~(^/|\\\\|(?:^|/)\.\.(?:/|$))~', $name ) || ! empty( $stat['encryption_method'] ) ) { throw new RuntimeException( 'Entrada ZIP no permitida.' ); }
			$names[$name] = true; $expanded += $stat['size'];
			if ( $stat['size'] > 8388608 || $expanded > 16777216 || $stat['size'] > max( 1, $stat['comp_size'] ) * 200 ) { throw new RuntimeException( 'Expansión ZIP excedida.' ); }
			if ( preg_match( '~vba|externalLinks|embeddings/|activeX/|\.bin$~i', $name ) ) { throw new RuntimeException( 'Macros, objetos o referencias externas no permitidos.' ); }
		}
		// Inspect every XML/relationship part, including non-selected sheets.
		foreach ( array_keys( $names ) as $name ) {
			if ( ! preg_match( '/\.(xml|rels|vml)$/i', $name ) ) { continue; }
			$text = $zip->getFromName( $name );
			if ( false === $text ) { throw new RuntimeException( 'Entrada ZIP ilegible.' ); }
			$doc = fpw_workbook_xml( $text );
			if ( preg_match( '/macroEnabled|vbaProject|externalLink/i', $text ) ) { throw new RuntimeException( 'Contenido activo no permitido.' ); }
			foreach ( $doc->getElementsByTagName( 'Relationship' ) as $rel ) {
				if ( 'external' === strtolower( $rel->getAttribute( 'TargetMode' ) ) || preg_match( '~^[a-z][a-z0-9+.-]*:|^//~i', $rel->getAttribute( 'Target' ) ) ) { throw new RuntimeException( 'Referencias externas no permitidas.' ); }
			}
			foreach ( array_merge( iterator_to_array( $doc->getElementsByTagName( 'f' ) ), iterator_to_array( $doc->getElementsByTagName( 'definedName' ) ) ) as $formula ) {
				if ( preg_match( '/\[|\]|https?:|file:|WEBSERVICE|HYPERLINK|DDE|\|/i', $formula->textContent ) ) { throw new RuntimeException( 'Fórmula con referencia externa no permitida.' ); }
			}
		}
		$read = static function ( string $name ) use ( $zip ): DOMDocument {
			$text = $zip->getFromName( $name );
			if ( false === $text ) { throw new RuntimeException( 'Falta una parte obligatoria del XLSX.' ); }
			return fpw_workbook_xml( $text );
		};
		$wb = fpw_workbook_xpath( $read( 'xl/workbook.xml' ) );
		$sheet = $wb->query( '/s:workbook/s:sheets/s:sheet' )->item( 0 );
		if ( ! $sheet ) { throw new RuntimeException( 'Falta la primera hoja.' ); }
		$rid = $sheet->getAttributeNS( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id' );
		$target = '';
		foreach ( $read( 'xl/_rels/workbook.xml.rels' )->getElementsByTagName( 'Relationship' ) as $rel ) {
			if ( $rid === $rel->getAttribute( 'Id' ) && str_ends_with( $rel->getAttribute( 'Type' ), '/worksheet' ) ) { $target = $rel->getAttribute( 'Target' ); }
		}
		if ( ! preg_match( '~^(?:/xl/)?worksheets/[A-Za-z0-9_-]+\.xml$~D', $target ) ) { throw new RuntimeException( 'Ruta de hoja no permitida.' ); }
		$path = str_starts_with( $target, '/' ) ? substr( $target, 1 ) : 'xl/' . $target;
		$epoch = $wb->evaluate( 'string(/s:workbook/s:workbookPr/@date1904)' );
		if ( ! in_array( $epoch, array( '', '0', '1', 'false', 'true' ), true ) ) { throw new RuntimeException( 'Epoch inválido.' ); }
		$strings = array();
		if ( isset( $names['xl/sharedStrings.xml'] ) ) {
			$sxp = fpw_workbook_xpath( $read( 'xl/sharedStrings.xml' ) );
			foreach ( $sxp->query( '/s:sst/s:si' ) as $si ) {
				$value = ''; foreach ( $sxp->query( './/s:t', $si ) as $t ) { $value .= $t->textContent; }
				if ( strlen( $value ) > 8192 || count( $strings ) >= 100000 ) { throw new RuntimeException( 'Texto XLSX excedido.' ); }
				$strings[] = $value;
			}
		}
		$xp = fpw_workbook_xpath( $read( $path ) ); $rows = array(); $seen = array(); $blank = 0; $extras = array(); $header = false;
		foreach ( $xp->query( '/s:worksheet/s:sheetData/s:row' ) as $row ) {
			$rn = $row->getAttribute( 'r' );
			if ( ! preg_match( '/^[1-9]\d{0,4}$/D', $rn ) || (int) $rn > FPW_SALES_MAX_ROWS + 1 || isset( $seen[$rn] ) ) { throw new RuntimeException( 'Fila duplicada o fuera de límite.' ); }
			$seen[$rn] = true; $raw = array(); $cells = array();
			foreach ( $xp->query( 's:c', $row ) as $cell ) {
				$ref = $cell->getAttribute( 'r' );
				if ( ! preg_match( '/^([A-Z]{1,2})([1-9]\d*)$/D', $ref, $m ) || $m[2] !== $rn || isset( $cells[$ref] ) ) { throw new RuntimeException( 'Celda duplicada o inválida.' ); }
				$cells[$ref] = true; $col = $m[1]; $type = $cell->getAttribute( 't' );
				$v = $xp->query( 's:v', $cell ); $f = $xp->query( 's:f', $cell );
				if ( $v->length > 1 || $f->length > 1 || ( $f->length && ( ! $v->length || '' === $v->item( 0 )->textContent ) ) ) { throw new RuntimeException( 'Fórmula sin valor almacenado o celda ambigua: ' . $ref ); }
				$value = $v->length ? $v->item( 0 )->textContent : '';
				if ( 's' === $type ) {
					if ( ! preg_match( '/^\d+$/D', $value ) || ! isset( $strings[(int) $value] ) ) { throw new RuntimeException( 'Índice de texto inválido.' ); }
					$value = $strings[(int) $value];
				} elseif ( 'inlineStr' === $type ) {
					$value = ''; foreach ( $xp->query( 's:is//s:t', $cell ) as $t ) { $value .= $t->textContent; }
				} elseif ( ! in_array( $type, array( '', 'n', 'str', 'b' ), true ) ) { throw new RuntimeException( 'Tipo/error de celda no permitido: ' . $ref ); }
				if ( strlen( $value ) > 8192 ) { throw new RuntimeException( 'Celda demasiado larga.' ); }
				if ( '' !== $value ) { $raw[$col] = $value; }
			}
			if ( '1' === $rn ) {
				foreach ( fpw_workbook_headers() as $i => $label ) {
					$col = $i < 26 ? chr( 65 + $i ) : 'A' . chr( 65 + $i - 26 );
					if ( ( $raw[$col] ?? '' ) !== $label ) { throw new RuntimeException( 'Encabezado incompatible en ' . $col . '1.' ); }
				}
				$header = true; continue;
			}
			if ( ! $raw ) { $blank++; continue; }
			foreach ( $raw as $col => $value ) { if ( strlen( $col ) === 2 && strcmp( $col, 'AC' ) > 0 ) { $extras[$col] = true; } }
			$mapped = array( 'source_row' => (int) $rn, 'raw' => $raw );
			foreach ( array( 'invoice' => 'W', 'rut' => 'A', 'producto' => 'E', 'total_cajas' => 'K', 'precio' => 'L', 'neto' => 'M', 'total' => 'O', 'payment' => 'Y', 'notes' => 'AC' ) as $field => $col ) { $mapped[$field] = $raw[$col] ?? ''; }
			$mapped['date'] = fpw_workbook_date( $raw['B'] ?? '', in_array( $epoch, array( '1', 'true' ), true ) );
			$rows[] = $mapped;
		}
		if ( ! $header ) { throw new RuntimeException( 'Falta el encabezado.' ); }
		return array( 'ok' => true, 'batch' => array( 'format' => 'xlsx', 'rows' => $rows, 'blank_rows' => $blank, 'extra_columns' => array_keys( $extras ), 'source_sha256' => hash( 'sha256', $content ), 'sheet' => $sheet->getAttribute( 'name' ) ) );
	} catch ( RuntimeException $e ) {
		return array( 'ok' => false, 'error' => 'XLSX rechazado: ' . $e->getMessage() );
	} finally { if ( $opened ) { $zip->close(); } unlink( $tmp ); }
}

/** Trusted deployment config only: private JSON + independently pinned SHA256. No request parameters. */
function fpw_sales_workbook_legacy(): array {
	if ( ! defined( 'FPW_SALES_LEGACY_FILE' ) || ! defined( 'FPW_SALES_LEGACY_SHA256' ) ) { return array(); }
	$path = realpath( FPW_SALES_LEGACY_FILE );
	$roots = array_filter( array( realpath( ABSPATH ), realpath( $_SERVER['DOCUMENT_ROOT'] ?? ABSPATH ) ) );
	if ( false === $path || ! is_file( $path ) || ( fileperms( $path ) & 0077 ) || filesize( $path ) > 2097152 ) { return array(); }
	foreach ( $roots as $root ) { if ( str_starts_with( $path, rtrim( $root, '/' ) . '/' ) ) { return array(); } }
	$raw = @file_get_contents( $path ); // A missing private mount must not disclose its path in PHP warnings.
	if ( false === $raw || ! hash_equals( FPW_SALES_LEGACY_SHA256, hash( 'sha256', $raw ) ) ) { return array(); }
	$data = json_decode( $raw, true );
	if ( ! is_array( $data ) ) { return array(); }
	foreach ( $data as $id => $fields ) {
		if ( ! preg_match( '/^[1-9]\d{0,11}$/D', (string) $id ) || ! is_array( $fields ) ) { return array(); }
		foreach ( array( 'date', 'rut', 'total', 'producto', 'total_cajas', 'precio', 'neto' ) as $key ) {
			if ( ! isset( $fields[$key] ) || ! is_scalar( $fields[$key] ) ) { return array(); }
		}
	}
	return $data;
}

/** Preview compares existing snapshots without proposing partial changes. */
function fpw_sales_workbook_plan( array $rows, array $legacy, array $sales ): array {
	$existing = array_column( $sales, null, 'id' );
	$entities = fpw_workbook_map( $rows, $legacy, $existing );
	foreach ( $entities as &$entity ) {
		if ( 'pending' === $entity['status'] ) {
			if ( array_filter( $entity['reasons'], static fn( $r ) => str_starts_with( $r, 'legacy_conflict_' ) ) ) { $entity['status'] = 'conflict'; }
			continue;
		}
		$old = $existing[$entity['id']] ?? null;
		if ( null === $old ) { continue; }
		$entity['status'] = 'existing'; $fill = array();
		foreach ( array( 'date', 'rut', 'total', 'lines' ) as $key ) {
			if ( ! isset( $old[$key] ) || '' === $old[$key] ) { $fill[] = $key; continue; }
			$equal = 'lines' === $key ? $old[$key] === $entity[$key] : (string) $old[$key] === (string) $entity[$key];
			if ( ! $equal ) { $entity['reasons'][] = 'existing_conflict_' . $key; }
		}
		$entity['status'] = $entity['reasons'] ? 'conflict' : ( $fill ? 'enrich' : 'existing' );
		$entity['fill_fields'] = $fill;
	}
	unset( $entity );
	return $entities;
}

/** All entities accessible using native disclosures; exact strings, escaped source detail. */
function fpw_sales_workbook_preview_html( array $pending ): string {
	$legacy = fpw_sales_workbook_legacy();
	$entities = fpw_sales_workbook_plan( $pending['rows'], $legacy, fpw_sales_register()['sales'] );
	$labels = array( 'new' => 'Nuevas', 'existing' => 'Existentes', 'enrich' => 'Por enriquecer', 'conflict' => 'Conflictos', 'pending' => 'Pendientes' );
	$counts = array_count_values( array_column( $entities, 'status' ) );
	$column_labels = array();
	foreach ( fpw_workbook_headers() as $i => $label ) { $column_labels[$i < 26 ? chr( 65 + $i ) : 'A' . chr( 65 + $i - 26 )] = $label; }
	$html = '<section><h2>Vista previa XLSX</h2><p>Solo lectura: confirmación XLSX aún bloqueada. Cancelar elimina esta vista previa, sin cambiar ventas.</p><p>';
	foreach ( $labels as $status => $label ) { $html .= esc_html( $label ) . ': ' . ( $counts[$status] ?? 0 ) . '. '; }
	$html .= '</p><p>' . count( $pending['rows'] ) . ' filas; ' . (int) $pending['blank_rows'] . ' filas vacías. Importes exactos en CLP; total por factura suma O una vez por línea.</p>';
	if ( ! $legacy ) { $html .= '<p>Evidencia histórica privada no disponible: identidades anteriores quedan bloqueadas.</p>'; }
	if ( $pending['extra_columns'] ) { $html .= '<p>Columnas adicionales conservadas solo como procedencia: ' . esc_html( implode( ', ', $pending['extra_columns'] ) ) . '.</p>'; }
	foreach ( $entities as $entity ) {
		$html .= '<details><summary>' . esc_html( $labels[$entity['status']] . ' · Factura ' . ( $entity['invoice'] ?? 'sin identidad demostrable' ) . ' · ' . count( $entity['rows'] ) . ' líneas · Total: ' . ( $entity['total'] ?? 'no aplicable' ) ) . '</summary>';
		$html .= '<p>' . esc_html( implode( ', ', $entity['reasons'] ) ) . '</p><p>' . esc_html( implode( ', ', $entity['movements'] ?? array() ) ) . '</p>';
		if ( ! empty( $entity['fill_fields'] ) ) { $html .= '<p>Campos vacíos por completar: ' . esc_html( implode( ', ', $entity['fill_fields'] ) ) . '.</p>'; }
		foreach ( $entity['rows'] as $row ) {
			$html .= '<details><summary>Fila ' . (int) $row['source_row'] . ': ' . esc_html( $row['producto'] . ' · Total O: ' . $row['total'] ) . '</summary><dl>';
			foreach ( $row['raw'] as $col => $value ) { $html .= '<dt>' . esc_html( $col . ' · ' . ( $column_labels[$col] ?? 'Adicional' ) ) . '</dt><dd>' . esc_html( $value ) . '</dd>'; }
			$html .= '</dl></details>';
		}
		$html .= '</details>';
	}
	return $html . '<form action="' . esc_url( fpw_sales_import_screen_url() ) . '" method="post"><input type="hidden" name="fpw_sales_action" value="cancel"><input type="hidden" name="fpw_sales_token" value="' . esc_attr( $pending['token'] ) . '">' . wp_nonce_field( FPW_SALES_NONCE_CANCEL, 'fpw_sales_nonce', true, false ) . '<button type="submit" class="button">Cancelar sin efectos</button></form></section>';
}
