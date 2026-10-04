<?php
/** Manual volume references in the existing private Price List, never a discount engine. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function fpw_price_reference_normalize( $entry ): array {
	if ( ! is_array( $entry ) ) { return array(); }
	$result = array();
	foreach ( array( 'units', 'small', 'bulk' ) as $key ) {
		$value = $entry[ $key ] ?? null;
		$result[ $key ] = is_int( $value ) && $value > 0 && $value <= ( 'units' === $key ? 1000000 : 99999999 ) ? $value : null;
	}
	foreach ( array( 'source', 'note' ) as $key ) {
		$result[ $key ] = is_string( $entry[ $key ] ?? null ) ? mb_substr( $entry[ $key ], 0, 300 ) : '';
	}
	return $result;
}

/** A variation's explicit record (including pending values) wins as a whole. */
function fpw_price_reference_for( int $product_id, int $variation_id = 0 ): array {
	$references = fpw_price_list()['references'];
	foreach ( array_unique( array( fpw_price_key( $product_id, $variation_id ), fpw_price_key( $product_id, 0 ) ) ) as $key ) {
		if ( array_key_exists( $key, $references ) ) { return fpw_price_reference_normalize( $references[ $key ] ); }
	}
	return array();
}

/** Validate the maintainer's reference fields independently of legacy single prices. */
function fpw_price_references_parse( array $catalog, array $posted, array $previous ): array {
	$allowed = array();
	foreach ( $catalog as $entry ) {
		$id = (int) $entry['product_id'];
		$allowed[ fpw_price_key( $id, 0 ) ] = true;
		foreach ( $entry['variations'] ?? array() as $variation ) { $allowed[ fpw_price_key( $id, (int) $variation['variation_id'] ) ] = true; }
	}
	$errors = array(); $entries = array();
	foreach ( $posted as $key => $raw ) {
		if ( ! isset( $allowed[ $key ] ) || ! is_array( $raw ) || array_diff( array_keys( $raw ), array( 'units', 'small', 'bulk' ) ) ) {
			$errors[] = 'Referencia de precio no válida: revisa la identidad y sus campos.'; continue;
		}
		$entry = array();
		foreach ( array( 'units', 'small', 'bulk' ) as $field ) {
			$value = $raw[ $field ] ?? '';
			if ( '' === $value ) { $entry[ $field ] = null; continue; }
			$limit = 'units' === $field ? 1000000 : 99999999;
			if ( ! is_scalar( $value ) || ! preg_match( '/^[1-9][0-9]*$/D', (string) $value ) || (float) $value > $limit ) {
				$errors[] = 'Referencias: usa enteros positivos o deja el campo vacío (sin precio).'; continue;
			}
			$entry[ $field ] = (int) $value;
		}
		if ( count( $entry ) !== 3 ) { continue; }
		$old = fpw_price_reference_normalize( $previous[ $key ] ?? null );
		$unchanged = $old && $entry === array_intersect_key( $old, $entry );
		if ( array_filter( $entry, static fn( $v ) => null !== $v ) || ( $unchanged && ! empty( $old['note'] ) ) ) {
			$entry['source'] = $unchanged ? $old['source'] : 'Ajuste manual en el mantenedor';
			$entry['note'] = $unchanged ? $old['note'] : '';
			$entries[ $key ] = $entry;
		}
	}
	if ( count( $entries ) > FPW_PRICE_MAX_ENTRIES ) { $errors[] = 'Demasiadas referencias de precios.'; }
	return array( 'entries' => $entries, 'errors' => $errors );
}

function fpw_price_reference_editor( string $key, array $entry ): string {
	$html = '<fieldset class="fpw-reference-editor"><legend>Referencias por volumen · elección manual</legend>';
	foreach ( array( 'units' => 'Unidades por pallet', 'small' => '1 a 4 pallets · CLP netos / unidad', 'bulk' => '5 o más pallets · CLP netos / unidad' ) as $field => $label ) {
		$html .= '<label class="fpw-prices__field">' . $label . '<input type="number" inputmode="numeric" min="1" max="' . ( 'units' === $field ? 1000000 : 99999999 ) . '" step="1" name="fpw_references[' . esc_attr( $key ) . '][' . $field . ']" value="' . ( isset( $entry[ $field ] ) ? (int) $entry[ $field ] : '' ) . '" placeholder="Por confirmar"></label>';
	}
	return $html . '<p class="fpw-prices__note">' . esc_html( trim( ( $entry['source'] ?? '' ) . ' · ' . ( $entry['note'] ?? '' ), ' ·' ) ) . '</p></fieldset>';
}

/** References are informational. Buttons only copy a number into the editable offer field. */
function fpw_workspace_references_html( array $reference, int $index, bool $readonly, ?int $legacy ): string {
	$html = '<div class="fpw-price-references"><p class="fpw-references-heading">Precios de referencia por volumen</p><p class="fpw-muted">CLP netos por unidad. Elige un precio o escribe otro; no se aplica ningún descuento automáticamente.</p>';
	foreach ( array( 'small' => '1 a 4 pallets', 'bulk' => '5 o más pallets' ) as $key => $label ) {
		$price = $reference[ $key ] ?? null;
		$html .= '<div class="fpw-list-reference"><div><span>' . $label . '</span><strong>' . fpw_workspace_money( $price ) . '</strong></div>';
		if ( null !== $price && ! $readonly ) { $html .= '<button type="button" hidden data-fpw-apply-price="' . $index . '" data-price="' . $price . '">Usar precio<span class="screen-reader-text"> de ' . $label . '</span></button>'; }
		$html .= '</div>';
	}
	if ( null !== $legacy ) {
		$html .= '<div class="fpw-list-reference"><div><span>Precio base anterior · sin tramo</span><strong>' . fpw_workspace_money( $legacy ) . '</strong></div>'
			. ( ! $readonly ? '<button type="button" hidden data-fpw-apply-price="' . $index . '" data-price="' . $legacy . '">Usar precio base</button>' : '' ) . '</div>';
	}
	if ( ! empty( $reference['source'] ) ) { $html .= '<p class="fpw-muted">Referencia: ' . esc_html( $reference['source'] ) . '.</p>'; }
	if ( ! empty( $reference['note'] ) ) { $html .= '<p class="fpw-muted">' . esc_html( $reference['note'] ) . '</p>'; }
	return $html . '<p class="fpw-muted">No combinamos pallets ni elegimos tramos automáticamente. El precio escrito solo se guarda al guardar el borrador.</p><noscript><p>Para usar una referencia, escribe su importe en Precio ofrecido y guarda el borrador.</p></noscript></div>';
}
