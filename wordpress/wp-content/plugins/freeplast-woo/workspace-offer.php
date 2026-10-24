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
	$version = fpw_read_quotation_version( $id, $number );
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
	$disposition = '1' === ( $_GET['view'] ?? null ) ? 'inline' : 'attachment';
	header( 'Content-Disposition: ' . $disposition . '; filename="' . $response['filename'] . '"' );
	header( 'Content-Length: ' . strlen( $response['bytes'] ) );
	echo $response['bytes'];
	exit;
}

function fpw_workspace_pdf_url( array $version, bool $inline = false ): string {
	$id = (int) $version['order_id'];
	$number = (int) $version['version'];
	$args = array( 'action' => 'fpw_quotation_pdf', 'request' => $id, 'version' => $number, '_wpnonce' => wp_create_nonce( 'fpw-pdf-' . $id . '-' . $number ) );
	if ( $inline ) { $args['view'] = '1'; }
	return add_query_arg( $args, admin_url( 'admin-post.php' ) );
}

function fpw_workspace_pdf_link( array $version ): string {
	if ( null === fpw_workspace_pdf_bytes( $version ) ) { return '<p>PDF pendiente o no disponible. No se ha generado otro documento.</p>'; }
	return '<a class="fpw-button fpw-primary" href="' . esc_url( fpw_workspace_pdf_url( $version ) ) . '">Descargar PDF aprobado</a>';
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
	$html .= ! empty( $projection['dispatch_requested'] ) ? '<p>Destino: ' . esc_html( $projection['destination'] ?? '' ) . '</p>' : '<p>Retiro - Sin Despacho' . ( ! empty( $projection['pickup_address'] ) ? ': ' . esc_html( $projection['pickup_address'] ) : ' (punto de retiro pendiente)' ) . '. No incluye flete.</p>';
	if ( ! empty( $projection['dispatch_requested'] ) ) {
		foreach ( array( 'commune' => 'Comuna de despacho', 'location_url' => 'Enlace de ubicación', 'references' => 'Referencias para llegar' ) as $key => $label ) {
			if ( ! empty( $projection[$key] ) ) { $html .= '<p>' . $label . ': ' . esc_html( $projection[$key] ) . '</p>'; }
		}
	}
	return $html;
}

/** The issued screen reads the frozen recipient, delivery result and exact stored PDF only. */
function fpw_workspace_issued_html( array $version ): string {
	$ready = null !== fpw_workspace_pdf_bytes( $version );
	$state = $version['delivery']['state'] ?? null;
	$recipient = (string) ( $version['buyer']['email'] ?? '' );
	$simulated = function_exists( 'fpw_local_capture_active' ) && fpw_local_capture_active();
	$title = 'Cotización aprobada; envío pendiente';
	$note = 'No hay un envío confirmado para esta cotización.';
	if ( ! $ready ) {
		$title = 'Cotización aprobada; PDF no disponible';
		$note = 'No se puede mostrar el documento guardado. Solicita revisión técnica; no se generó ni envió otro PDF.';
	} elseif ( 'accepted' === $state ) {
		$title = ( $simulated ? 'Envío simulado de cotización' : 'Cotización enviada' ) . ( '' !== $recipient ? ' a ' . $recipient : '' );
		$note = $simulated ? 'El correo quedó capturado en DEV. No se envió al cliente.' : '';
	} elseif ( 'rejected' === $state ) {
		$title = 'No se pudo enviar la cotización';
		$note = 'El correo fue rechazado. El PDF aprobado está disponible; no se reintenta automáticamente.';
	} elseif ( 'unknown' === $state ) {
		$title = 'Envío de cotización sin confirmar';
		$note = 'El resultado del envío es desconocido. No se reintenta automáticamente.';
	}
	$html = '<section class="fpw-sent-view"><a class="fpw-button fpw-sent-back" href="' . esc_url( fpw_workspace_back_url() ) . '">Volver a solicitudes</a><div class="fpw-sent-confirmation"><h1>' . esc_html( $title ) . '</h1>' . ( '' !== $note ? '<p class="fpw-muted">' . esc_html( $note ) . '</p>' : '' ) . '</div>';
	$html .= fpw_quotation_history_html( (int) $version['order_id'], (int) $version['version'] );
	if ( $ready ) {
		$view_url = fpw_workspace_pdf_url( $version, true );
		$label = ( isset( $version['commercial']['number'] ) ? 'Cotización N.º ' . (int) $version['commercial']['number'] : 'Cotización ' . (string) $version['reference'] ) . ' · Versión ' . (int) $version['version'];
		$html .= '<section class="fpw-pdf-viewer" aria-label="Visor de cotización PDF"><div class="fpw-pdf-toolbar"><h2>' . esc_html( $label ) . '</h2><div><a href="' . esc_url( $view_url ) . '" target="_blank" rel="noopener">Abrir PDF</a><a href="' . esc_url( fpw_workspace_pdf_url( $version ) ) . '">Descargar PDF</a></div></div><iframe src="' . esc_url( $view_url . '#view=FitH' ) . '" title="PDF de la cotización aprobada"></iframe><p class="fpw-pdf-help">Si el visor no carga, <a href="' . esc_url( $view_url ) . '" target="_blank" rel="noopener">abre el PDF en otra pestaña</a>.</p></section>';
	}
	return $html . '</section>';
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
	$html .= '<p class="fpw-muted">Guardada el ' . esc_html( fpw_workspace_date( 'd/m/Y', (int) $preview['created_at'] ) ) . '</p>';
	if ( $obsolete ) { $html .= '<p class="fpw-workspace-notice">Vista previa obsoleta. El borrador cambió: genera una nueva y revísala antes de aprobar.</p>'; }
	if ( ! empty( $projection['missing'] ) ) {
		$html .= '<p>Por completar antes de aprobar:</p><ul>' . implode( '', array_map( static fn( $item ) => '<li>' . esc_html( $item ) . '</li>', $projection['missing'] ) ) . '</ul>';
	}
	$html .= fpw_workspace_offer_html( $projection );
	if ( $obsolete ) { $html .= fpw_draft_preview_generate_html( $id ); }
	elseif ( ! empty( $projection['complete'] ) && ( ! is_array( fpw_read_quotation_version( $id ) ) || fpw_quotation_revision_is_current( $id ) ) ) {
		// Approval freezes this reviewed identity; legacy previews use the immutable receipt.
		$recipient = (string) ( ( $projection['identity'] ?? $draft['identity'] )['email'] ?? '' );
		$html .= '' !== $recipient
			? '<p class="fpw-recipient-line"><strong>Se enviará a:</strong> ' . esc_html( $recipient ) . '</p>'
			: '<p class="fpw-recipient-line">El correo del comprador no está disponible. Revisa los datos de la solicitud antes de aprobar.</p>';
		$html .= fpw_draft_approve_html( $id, $preview, true );
	}
	return $html . '</section>';
}
