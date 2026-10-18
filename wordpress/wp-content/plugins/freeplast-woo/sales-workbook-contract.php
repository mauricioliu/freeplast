<?php
/** Pure official-workbook mapping; no WordPress reads or writes. See docs/sales-workbook-contract.md. */

/** Stored XML decimal, never a localized display value or a float. */
function fpw_workbook_decimal( ?string $raw ): ?string {
	if ( null === $raw || '' === trim( $raw ) ) { return null; }
	$raw = trim( $raw );
	if ( ! preg_match( '/^-?\d{1,12}(?:\.\d{1,12})?$/D', $raw ) ) { throw new InvalidArgumentException( 'invalid_decimal' ); }
	$negative = str_starts_with( $raw, '-' );
	$parts = explode( '.', ltrim( $raw, '-' ) );
	$whole = ltrim( $parts[0], '0' );
	$fraction = rtrim( $parts[1] ?? '', '0' );
	$value = ( '' === $whole ? '0' : $whole ) . ( '' === $fraction ? '' : '.' . $fraction );
	return ( $negative && '0' !== $value ? '-' : '' ) . $value;
}

/** Non-negative exact addition; source decimals remain strings. */
function fpw_workbook_sum( array $values ): string {
	$scale = 0;
	foreach ( $values as $value ) {
		if ( null === $value || str_starts_with( $value, '-' ) ) { throw new InvalidArgumentException( 'invalid_sum' ); }
		$scale = max( $scale, strlen( explode( '.', $value )[1] ?? '' ) );
	}
	$total = '0';
	foreach ( $values as $value ) {
		$parts = explode( '.', $value );
		$digits = $parts[0] . str_pad( $parts[1] ?? '', $scale, '0' );
		$width = max( strlen( $total ), strlen( $digits ) );
		$a = str_pad( $total, $width, '0', STR_PAD_LEFT );
		$b = str_pad( $digits, $width, '0', STR_PAD_LEFT );
		$total = ''; $carry = 0;
		for ( $i = $width - 1; $i >= 0; $i-- ) {
			$n = (int) $a[$i] + (int) $b[$i] + $carry;
			$total = (string) ( $n % 10 ) . $total; $carry = intdiv( $n, 10 );
		}
		if ( $carry ) { $total = '1' . $total; }
	}
	$total = str_pad( $total, $scale + 1, '0', STR_PAD_LEFT );
	if ( $scale ) { $total = substr( $total, 0, -$scale ) . '.' . substr( $total, -$scale ); }
	return fpw_workbook_decimal( $total );
}

/**
 * Input rows: source_row, invoice, date (ISO), rut, producto, total_cajas,
 * precio, neto, total, payment, notes, plus raw cells/provenance retained verbatim.
 * Legacy evidence: prior id => {date,rut,total,producto,total_cajas,precio,neto}.
 * Existing register is passed separately: evidence alone never proves current state.
 * Every input row belongs to exactly one result; pending groups propose no writes.
 */
function fpw_workbook_map( array $rows, array $legacy, array $existing ): array {
	$groups = array(); $results = array();
	foreach ( $rows as $row ) {
		$invoice = trim( (string) ( $row['invoice'] ?? '' ) );
		if ( ! preg_match( '/^[1-9]\d{0,11}$/D', $invoice ) ) {
			$results[] = array( 'status' => 'pending', 'reasons' => array( 'invoice_unproven' ), 'rows' => array( $row ) );
			continue;
		}
		$groups[$invoice][] = $row;
	}
	foreach ( $groups as $invoice => $group ) {
		$invoice = (string) $invoice;
		$reasons = array(); $ruts = array(); $dates = array(); $lines = array(); $movements = array();
		foreach ( $group as $row ) {
			$rut = strtoupper( preg_replace( '/[\s.\-]/', '', trim( (string) ( $row['rut'] ?? '' ) ) ) );
			if ( ! preg_match( '/^\d{7,8}[0-9K]$/D', $rut ) ) { $reasons[] = 'rut_unproven'; }
			$ruts[] = $rut;
			$date = (string) ( $row['date'] ?? '' ); $dates[] = $date;
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $date ) || ! checkdate( (int) substr( $date, 5, 2 ), (int) substr( $date, 8, 2 ), (int) substr( $date, 0, 4 ) ) ) { $reasons[] = 'invalid_date'; }
			$note = trim( (string) ( $row['notes'] ?? '' ) );
			$marker = strtolower( $note . ' ' . ( $row['payment'] ?? '' ) );
			$movement = 'sale';
			if ( preg_match( '/anul|cancel/u', $marker ) ) { $movement = 'cancellation'; }
			elseif ( preg_match( '/devol|devuel/u', $marker ) ) { $movement = 'return'; }
			elseif ( preg_match( '/cr[eé]dito|ajuste/u', $marker ) ) { $movement = 'adjustment'; }
			if ( 'sale' !== $movement ) { $reasons[] = 'special_movement_review'; }
			elseif ( '' !== $note && 'OK' !== strtoupper( $note ) ) { $reasons[] = 'observation_review'; }
			$movements[] = $movement;
			$line = array( 'producto' => trim( (string) ( $row['producto'] ?? '' ) ) );
			if ( '' === $line['producto'] ) { $reasons[] = 'missing_product'; }
			foreach ( array( 'total_cajas', 'precio', 'neto', 'total' ) as $field ) {
				try { $line[$field] = fpw_workbook_decimal( isset( $row[$field] ) ? (string) $row[$field] : null ); }
				catch ( InvalidArgumentException $e ) { $line[$field] = null; $reasons[] = 'invalid_' . $field; }
				if ( null === $line[$field] ) { $reasons[] = 'missing_' . $field; }
				elseif ( str_starts_with( $line[$field], '-' ) ) { $reasons[] = 'signed_movement_review'; }
			}
			if ( isset( $line['total_cajas'] ) && str_contains( $line['total_cajas'], '.' ) ) { $reasons[] = 'fractional_quantity'; }
			// Content fingerprint compares immutable snapshots; never an appendable line identity.
			$line['fingerprint'] = hash( 'sha256', json_encode( $line, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ) );
			if ( isset( $lines[$line['fingerprint']] ) ) { $reasons[] = 'indistinguishable_lines'; }
			$lines[$line['fingerprint']] = $line;
		}
		if ( 1 !== count( array_unique( $ruts ) ) ) { $reasons[] = 'invoice_multiple_ruts'; }
		if ( 1 !== count( array_unique( $dates ) ) ) { $reasons[] = 'invoice_multiple_dates'; }
		$id = 'xlsx:freeplast:invoice:' . $invoice;
		$status = 'new';
		if ( isset( $legacy[$invoice] ) || isset( $existing[$invoice] ) ) {
			$id = $invoice; $status = 'reconciled';
			if ( ! isset( $legacy[$invoice], $existing[$invoice] ) || 1 !== count( $group ) ) { $reasons[] = 'legacy_identity_unproven'; }
			else {
				$actual = array( 'date' => $dates[0], 'rut' => $ruts[0] ) + reset( $lines );
				foreach ( array( 'date', 'rut', 'total', 'producto', 'total_cajas', 'precio', 'neto' ) as $field ) {
					if ( (string) ( $actual[$field] ?? '' ) !== (string) ( $legacy[$invoice][$field] ?? '' ) || (string) ( $actual[$field] ?? '' ) !== (string) ( $existing[$invoice][$field] ?? '' ) ) { $reasons[] = 'legacy_conflict_' . $field; }
				}
			}
		}
		$reasons = array_values( array_unique( $reasons ) );
		ksort( $lines );
		$results[] = array( 'id' => $id, 'invoice' => $invoice, 'status' => $reasons ? 'pending' : $status, 'reasons' => $reasons,
			'rut' => $ruts[0], 'date' => $dates[0], 'movements' => array_values( array_unique( $movements ) ),
			'lines' => array_values( $lines ), 'total' => $reasons ? null : fpw_workbook_sum( array_column( $lines, 'total' ) ), 'rows' => $group );
	}
	return $results;
}
