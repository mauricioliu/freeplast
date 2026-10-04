<?php
/** Owner-only offer presentation and retrieval. Never recompute or regenerate an issued document. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'admin_post_fpw_quotation_pdf', 'fpw_workspace_download_pdf' );

function fpw_workspace_pdf_bytes( ?array $version ): ?string {
	if ( 1 !== ( $version['schema'] ?? null ) || 'ready' !== ( $version['document'] ?? null ) || ! is_string( $version['pdf_base64'] ?? null ) ) { return null; }
	$bytes = base64_decode( $version['pdf_base64'], true );
	return is_string( $bytes ) && fpw_quotation_pdf_is_valid( $bytes ) ? $bytes : null;
}

/** Authorization is checked even with a valid nonce; every download is bound to one request/version. */
function fpw_workspace_pdf_response( int $id, int $number, string $nonce ): array {
	if ( ! fpw_can_manage_quotations() || $id <= 0 || $number <= 0 || ! wp_verify_nonce( $nonce, 'fpw-pdf-' . $id . '-' . $number ) ) {
		wp_die( 'No tienes permiso para descargar esta cotización.', '', array( 'response' => 403 ) );
	}
	$version = fpw_read_quotation_version( $id );
	$bytes = fpw_workspace_pdf_bytes( $version );
	if ( $id !== ( $version['order_id'] ?? null ) || $number !== ( $version['version'] ?? null ) || ! wc_get_order( $id ) || null === $bytes ) {
		wp_die( 'El PDF guardado no está disponible. Vuelve a la cotización; no se generó ni envió otro documento.', '', array( 'response' => 404 ) );
	}
	return array( 'bytes' => $bytes, 'filename' => 'cotizacion-' . $id . '-v' . $number . '.pdf' );
}

function fpw_workspace_download_pdf(): void {
	$id = is_string( $_GET['request'] ?? null ) && ctype_digit( $_GET['request'] ) ? (int) $_GET['request'] : 0;
	$number = is_string( $_GET['version'] ?? null ) && ctype_digit( $_GET['version'] ) ? (int) $_GET['version'] : 0;
	$nonce = is_string( $_GET['_wpnonce'] ?? null ) ? $_GET['_wpnonce'] : '';
	$response = fpw_workspace_pdf_response( $id, $number, $nonce );
	nocache_headers();
	header( 'Cache-Control: private, no-store, no-cache, must-revalidate' );
	header( 'Content-Type: application/pdf' );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Content-Disposition: attachment; filename="' . $response['filename'] . '"' );
	header( 'Content-Length: ' . strlen( $response['bytes'] ) );
	echo $response['bytes'];
	exit;
}

function fpw_workspace_pdf_link( array $version ): string {
	if ( null === fpw_workspace_pdf_bytes( $version ) ) { return '<p>PDF pendiente o no disponible. No se ha generado otro documento.</p>'; }
	$id = (int) $version['order_id'];
	$number = (int) $version['version'];
	$url = add_query_arg( array( 'action' => 'fpw_quotation_pdf', 'request' => $id, 'version' => $number, '_wpnonce' => wp_create_nonce( 'fpw-pdf-' . $id . '-' . $number ) ), admin_url( 'admin-post.php' ) );
	return '<a class="fpw-button fpw-primary" href="' . esc_url( $url ) . '">Descargar PDF aprobado</a>';
}

/** Amounts always belong to the supplied saved projection. No browser or presentation arithmetic. */
function fpw_workspace_totals_html( array $projection ): string {
	$html = '<dl class="fpw-offer-totals">';
	foreach ( array( 'subtotal' => 'Productos netos', 'dispatch' => 'Despacho neto', 'tax' => 'IVA', 'total' => 'Total' ) as $key => $label ) {
		if ( 'dispatch' === $key && empty( $projection['dispatch_requested'] ) ) { continue; }
		if ( 'tax' === $key && is_int( $projection['tax_rate_permille'] ?? null ) ) { $label = 'IVA (' . fpw_draft_tax_rate_percent_html( $projection['tax_rate_permille'] ) . '%)'; }
		$html .= '<div' . ( 'total' === $key ? ' class="fpw-grand-total"' : '' ) . '><dt>' . $label . '</dt><dd>' . fpw_workspace_money( $projection[ $key ] ?? null ) . '</dd></div>';
	}
	return $html . '</dl>';
}

function fpw_workspace_offer_html( array $projection ): string {
	$html = '<div class="fpw-offer-lines">';
	foreach ( $projection['lines'] ?? array() as $index => $line ) {
		$html .= '<section data-fpw-frozen-line="' . (int) $index . '"><h3>' . esc_html( $line['name'] ?? '' ) . '</h3>';
		foreach ( $line['options'] ?? array() as $option ) { $html .= '<p class="fpw-muted">' . esc_html( wc_attribute_label( $option['key'] ) . ': ' . $option['value'] ) . '</p>'; }
		$html .= '<div class="fpw-offer-line"><span>' . (int) $line['quantity'] . ' × <span data-fpw-unit-price>' . fpw_workspace_money( $line['price'] ?? null ) . '</span><small>netos por unidad</small></span><strong>' . fpw_workspace_money( $line['line_total'] ?? null ) . '</strong></div></section>';
	}
	$html .= '</div>' . fpw_workspace_totals_html( $projection );
	$html .= '<p><strong>Vigencia: ' . (int) ( $projection['validity_days'] ?? 0 ) . ' días desde la aprobación.</strong></p>';
	$html .= ! empty( $projection['dispatch_requested'] ) ? '<p>Destino: ' . esc_html( $projection['destination'] ?? '' ) . '</p>' : '<p>Sin despacho solicitado. No incluye flete.</p>';
	return $html;
}

function fpw_workspace_issued_html( array $version ): string {
	return '<section class="fpw-issued"><h2>Cotización aprobada</h2><p class="fpw-muted">Versión ' . (int) $version['version'] . ' · ' . esc_html( wp_date( 'd/m/Y', (int) $version['approved_at'] ) ) . ' · importes fijos</p>'
		. fpw_workspace_pdf_link( $version ) . '<p><strong>Correo al comprador:</strong> ' . fpw_quotation_delivery_state_html( $version['delivery'] ?? array() ) . '</p>'
		. fpw_workspace_offer_html( $version['projection'] ) . '<p class="fpw-muted">Esta oferta no se puede editar. El estado de envío se registra automáticamente.</p></section>';
}

/** Review reads only the stored preview and preserves the existing revision/token-bound approval. */
function fpw_workspace_preview_html( array $draft, ?array $work, ?array $preview ): string {
	$id = (int) $draft['order_id'];
	$html = '<section class="fpw-draft__preview"><h2>Revisar antes de enviar</h2>';
	if ( ! is_array( $preview['projection'] ?? null ) ) {
		return $html . '<p>Aún no hay una vista previa disponible. Genérala con los datos guardados.</p>' . fpw_draft_preview_generate_html( $id ) . '</section>';
	}
	$projection = $preview['projection'];
	$obsolete = fpw_draft_preview_is_obsolete( $preview, $work );
	$html .= '<p class="fpw-muted">Guardada el ' . esc_html( wp_date( 'd/m/Y', (int) $preview['created_at'] ) ) . ' · revisión ' . (int) $preview['revision'] . '</p>';
	if ( $obsolete ) { $html .= '<p class="fpw-workspace-notice">Vista previa obsoleta. El borrador cambió: genera una nueva y revísala antes de aprobar.</p>'; }
	if ( ! empty( $projection['missing'] ) ) {
		$html .= '<p>Por completar antes de aprobar:</p><ul>' . implode( '', array_map( static fn( $item ) => '<li>' . esc_html( $item ) . '</li>', $projection['missing'] ) ) . '</ul>';
	}
	$html .= fpw_workspace_offer_html( $projection );
	if ( $obsolete ) { $html .= fpw_draft_preview_generate_html( $id ); }
	elseif ( ! empty( $projection['complete'] ) && ! is_array( fpw_read_quotation_version( $id ) ) ) {
		$html .= '<p>Al aprobar, se guarda esta versión, se genera su PDF y se intenta enviar al correo del comprador. Aún no se ha enviado.</p>';
		/* H4 (2026-10-03 review): the exact recipient the approval will freeze — read from
		 * the SAME request-draft identity fpw_quotation_approve_and_send() freezes — so the
		 * owner can verify it before committing, with no edit affordance on this screen. */
		$recipient = (string) ( $draft['identity']['email'] ?? '' );
		$html .= '' !== $recipient
			? '<p class="fpw-recipient-line"><strong>Se enviará a:</strong> ' . esc_html( $recipient ) . '</p>'
			: '<p class="fpw-recipient-line">El correo del comprador no está disponible. Revisa los datos de la solicitud antes de aprobar.</p>';
		$html .= fpw_draft_approve_html( $id, $preview, true );
	}
	return $html . '<details><summary>Qué incluye la cotización</summary><p>Solo los productos, cantidades, precios y condiciones revisados. No incluye historial de compras, notas internas ni costos del transportista. La aceptación del correo por el transporte no confirma su recepción.</p></details></section>';
}
