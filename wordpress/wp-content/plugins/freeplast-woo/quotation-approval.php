<?php
/**
 * Aprobar y enviar la primera versión de una cotización — issue #56, corte 7 de #49.
 *
 * Approval is the owner's explicit commercial act over the review cut 6 left
 * in place: «Aprobar y enviar» consumes the stored Quotation Preview and can
 * only ever issue exactly what the owner reviewed. The guards, in order, are
 * the invariants ADR-0009 names: a stored preview must exist, it must still
 * sit on the saved work's CURRENT revision (any later commercial save made it
 * obsolete), its projection must be complete, and the shared projection
 * recalculated right now from the same saved work must be IDENTICAL to the
 * stored one — nothing unreviewed and no parallel math can ever be issued.
 *
 * The approved version is ONE durable row, fpw_quotation_<order_id>, created
 * by a plain INSERT against the unique option_name — the same durable-binding
 * decision as the draft row (ADR-0004). The INSERT decides the race at the
 * durable operation level: a double click, two concurrent approvals and known
 * retries of the same approval converge — exactly one version is created and
 * only the winner proceeds to document and mail; every loser answers with the
 * standing version and mails nothing. This cut issues only the FIRST version;
 * issuing a changed offer as a new version is a separate, later decision.
 *
 * The frozen version carries the request reference and attempt identity, the
 * reviewed revision, the buyer identity, and the projection VERBATIM — never
 * re-derived from later list changes, rules or routes. Its PDF is generated
 * once from those frozen values and stored with the version (base64), so any
 * later recovery hands over the same bytes.
 *
 * The document is a real PDF built by the minimal in-repo writer below. The
 * project's dependency contract pins wordpress.org zips only and no external
 * PDF library or paid license is approved, so issuance renders its own PDF
 * 1.4 document (core fonts, exact integer amounts) instead of bundling a
 * third-party library — a consequential trade-off recorded in ADR-0011, with
 * the renderer replaceable through the fpw_quotation_document_bytes filter
 * (absent by default, like every configuration seam). The delivered bytes are
 * validated before use: an unusable document is a pending document, never a
 * broken attachment. No legal clauses, bank data, delivery promises or logo
 * are invented: the document states exactly the reviewed offer.
 *
 * Delivery stages are named separately and honestly: approval (the version
 * stands), document (ready/pending) and mail (accepted by the transport /
 * rejected / unknown). A document failure never attempts the mail. Mail
 * acceptance is not buyer receipt; an unknown outcome is never retried
 * automatically — explicit recovery is the next cut. Issuing creates no
 * purchase, payment, invoice, stock movement or historical sale: the native
 * request record is untouched, and the buyer document carries no purchase
 * history, internal notes, carrier costs or dispatch-estimate breakdown — by
 * construction, because it renders only the frozen projection.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'FPW_QUOTATION_ROW_PREFIX', 'fpw_quotation_' );

/** The approved-version row name of one request: unique option_name → one first version, ever. */
function fpw_quotation_row_name( int $order_id ): string {
	return FPW_QUOTATION_ROW_PREFIX . $order_id;
}

/** The nonce action of one draft's approval: scoped to the request it approves. */
function fpw_quotation_approve_action( int $order_id ): string {
	return 'fpw-draft-approve-' . $order_id;
}

/** The stored approved version of one request, decoded — like the preview reader it guards on $wpdb so offline render contexts stay callable. */
function fpw_read_quotation_version( int $order_id ): ?array {
	if ( $order_id <= 0 ) { return null; }
	global $wpdb;
	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) { return null; }
	$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", fpw_quotation_row_name( $order_id ) ) );
	if ( ! is_string( $raw ) || '' === $raw ) { return null; }
	$version = json_decode( $raw, true );
	return is_array( $version ) && isset( $version['version'] ) ? $version : null;
}

/**
 * The approval guards (issue #56): what must already be true before any
 * approval. Work must exist; a stored preview must exist; it must still be
 * bound to the saved work's current revision (not obsolete); its projection
 * must be complete (every named faltante resolved); and the shared
 * projection recalculated NOW from the same saved work must be exactly the
 * stored one — the preview can never be used to issue different values.
 *
 * @return array{state:'ok'|'sin-trabajo'|'sin-vista-previa'|'vista-previa-obsoleta'|'oferta-incompleta'|'proyeccion-divergente', missing?:string[]}
 */
function fpw_quotation_approval_check( array $draft, ?array $work, ?array $preview ): array {
	if ( ! is_array( $work ) ) { return array( 'state' => 'sin-trabajo' ); }
	if ( ! is_array( $preview ) ) { return array( 'state' => 'sin-vista-previa' ); }
	if ( fpw_draft_preview_is_obsolete( $preview, $work ) ) { return array( 'state' => 'vista-previa-obsoleta' ); }
	$projection = is_array( $preview['projection'] ?? null ) ? $preview['projection'] : null;
	if ( null === $projection || empty( $projection['complete'] ) ) {
		return array( 'state' => 'oferta-incompleta', 'missing' => array_map( 'strval', (array) ( $projection['missing'] ?? array() ) ) );
	}
	if ( fpw_quotation_projection( $draft, $work ) !== $projection ) { return array( 'state' => 'proyeccion-divergente' ); }
	return array( 'state' => 'ok' );
}

/**
 * Approve and send the first version (issue #56). The guard must already
 * have passed; the durable INSERT is the authority for the race: the winner
 * freezes the version, the losers of a double click, concurrency or a known
 * retry answer with the standing version and never mail anything.
 *
 * @return array{state:'already'|'storage-failed'|'document-pending'|'mail-rejected'|'mail-unknown'|'sent'|'refused', version:?array, reason?:string, missing?:string[]}
 */
function fpw_quotation_approve_and_send( int $order_id, array $draft, ?array $work, ?array $preview, int $user_id ): array {
	$guard = fpw_quotation_approval_check( $draft, $work, $preview );
	if ( 'ok' !== $guard['state'] ) {
		return array( 'state' => 'refused', 'version' => null, 'reason' => $guard['state'], 'missing' => $guard['missing'] ?? array() );
	}
	$existing = fpw_read_quotation_version( $order_id );
	if ( is_array( $existing ) ) { return array( 'state' => 'already', 'version' => $existing ); }
	$identity = is_array( $draft['identity'] ?? null ) ? $draft['identity'] : array();
	$version  = array(
		'schema'        => 1,
		'order_id'      => $order_id,
		'version'       => 1,
		'reference'     => (string) ( $draft['reference'] ?? '' ),
		'attempt'       => (string) ( $draft['attempt'] ?? '' ),
		'work_revision' => max( 0, (int) ( $preview['revision'] ?? 0 ) ),
		'approved_at'   => time(),
		'approved_by'   => $user_id,
		'buyer'         => array(
			'name'    => (string) ( $identity['name'] ?? '' ),
			'company' => (string) ( $identity['company'] ?? '' ),
			'email'   => (string) ( $identity['email'] ?? '' ),
		),
		'projection'    => $preview['projection'],
		'document'      => 'pending',
		'delivery'      => array( 'state' => null, 'at' => null ),
	);
	// THE durable approval: a plain INSERT against the unique row name decides.
	// The loser's payload is dropped whole — no version, no document, no mail.
	if ( ! fpw_insert_options_row( fpw_quotation_row_name( $order_id ), wp_json_encode( $version ) ) ) {
		$existing = fpw_read_quotation_version( $order_id );
		return array( 'state' => is_array( $existing ) ? 'already' : 'storage-failed', 'version' => $existing );
	}
	$document = fpw_quotation_render_document( $version );
	if ( ! fpw_quotation_pdf_is_valid( $document ) ) {
		// Document pending: the approved version stands preserved, no mail is
		// attempted — an incomplete offer is never sent.
		return array( 'state' => 'document-pending', 'version' => $version );
	}
	$approved = $version;
	$version['document']   = 'ready';
	$version['pdf_base64'] = base64_encode( $document );
	// Write-ahead uncertainty: a crash or failed outcome write after wp_mail
	// must not leave a durable "never attempted" state that invites a resend.
	$version['delivery'] = array( 'state' => 'unknown', 'at' => time() );
	if ( ! fpw_update_options_row( fpw_quotation_row_name( $order_id ), wp_json_encode( $version ) ) ) {
		return array( 'state' => 'document-pending', 'version' => $approved );
	}
	$unconfirmed = $version;
	$delivery = fpw_quotation_deliver( $version );
	$version['delivery'] = array( 'state' => $delivery, 'at' => time() );
	if ( ! fpw_update_options_row( fpw_quotation_row_name( $order_id ), wp_json_encode( $version ) ) ) {
		return array( 'state' => 'mail-unknown', 'version' => $unconfirmed );
	}
	return array( 'state' => 'accepted' === $delivery ? 'sent' : ( 'unknown' === $delivery ? 'mail-unknown' : 'mail-rejected' ), 'version' => $version );
}

/**
 * The mail stage: the buyer receives the frozen document attached, with the
 * buyer-facing projection as the body — nothing internal (history, notes,
 * carrier costs, kilometers/breakdown) exists in the payload to leak. The
 * document stages through a private temp file that never survives the send
 * and is never a public URL. Transport acceptance, rejection and an unknown
 * outcome (exception mid-send) are named as what they are; an unknown result
 * is NEVER retried here.
 *
 * @return 'accepted'|'rejected'|'unknown'
 */
function fpw_quotation_deliver( array $version ): string {
	$document = base64_decode( (string) ( $version['pdf_base64'] ?? '' ), true );
	$to       = (string) ( $version['buyer']['email'] ?? '' );
	if ( ! is_string( $document ) || '' === $document || '' === $to ) { return 'rejected'; }
	$safe_reference = preg_replace( '/[^A-Za-z0-9._-]/', '', (string) ( $version['reference'] ?? '' ) );
	require_once __DIR__ . '/quotation-document.php';
	$dir = null;
	try {
		$dir = fpw_quotation_temp_dir();
		$path = $dir . '/cotizacion-' . $safe_reference . '-v' . max( 1, (int) ( $version['version'] ?? 1 ) ) . '.pdf';
		if ( strlen( $document ) !== file_put_contents( $path, $document ) ) { return 'rejected'; }
		try {
			$sent = wp_mail(
				$to,
				'Cotización ' . (string) ( $version['reference'] ?? '' ) . ' · versión ' . max( 1, (int) ( $version['version'] ?? 1 ) ),
				fpw_quotation_email_html( $version ),
				array( 'Content-Type: text/html; charset=UTF-8' ),
				array( $path )
			);
		} catch ( Throwable ) {
			return 'unknown';
		}
		return $sent ? 'accepted' : 'rejected';
	} catch ( Throwable ) {
		return 'rejected';
	} finally {
		if ( is_string( $dir ) ) { fpw_quotation_remove_temp( $dir ); }
	}
}

/** The buyer-facing mail body, rendered ONLY from the frozen projection — the same values the approved PDF shows. */
function fpw_quotation_email_html( array $version ): string {
	$projection = is_array( $version['projection'] ?? null ) ? $version['projection'] : array();
	$rows = '';
	foreach ( (array) ( $projection['lines'] ?? array() ) as $line ) {
		$line = is_array( $line ) ? $line : array();
		$options = '';
		foreach ( (array) ( $line['options'] ?? array() ) as $option ) {
			$options .= ' — ' . wc_attribute_label( (string) ( $option['key'] ?? '' ) ) . ': ' . (string) ( $option['value'] ?? '' );
		}
		$price = is_int( $line['price'] ?? null ) ? fpw_draft_clp_html( (int) $line['price'] ) : fpw_draft_pending_html();
		$total = is_int( $line['line_total'] ?? null ) ? fpw_draft_clp_html( (int) $line['line_total'] ) : fpw_draft_pending_html();
		$rows .= '<tr><td>' . esc_html( (string) ( $line['name'] ?? '' ) ) . esc_html( $options ) . '</td><td>' . (int) ( $line['quantity'] ?? 0 ) . '</td><td>' . $price . '</td><td>' . $total . '</td></tr>';
	}
	$rows .= '<tr><td colspan="3">Subtotal (neto)</td><td>' . ( is_int( $projection['subtotal'] ?? null ) ? fpw_draft_clp_html( (int) $projection['subtotal'] ) : fpw_draft_pending_html() ) . '</td></tr>';
	if ( ! empty( $projection['dispatch_requested'] ) ) {
		$rows .= '<tr><td colspan="3">Despacho (neto)</td><td>' . ( is_int( $projection['dispatch'] ?? null ) ? fpw_draft_clp_html( (int) $projection['dispatch'] ) : fpw_draft_pending_html() ) . '</td></tr>';
	}
	$rows .= null === ( $projection['tax_rate_permille'] ?? null )
		? '<tr><td colspan="3">IVA</td><td>' . fpw_draft_pending_html() . '</td></tr>'
		: '<tr><td colspan="3">IVA (' . fpw_draft_tax_rate_percent_html( (int) $projection['tax_rate_permille'] ) . '%)</td><td>' . fpw_draft_clp_html( (int) $projection['tax'] ) . '</td></tr>';
	$rows .= '<tr><td colspan="3"><strong>Total</strong></td><td>' . ( is_int( $projection['total'] ?? null ) ? fpw_draft_clp_html( (int) $projection['total'] ) : fpw_draft_pending_html() ) . '</td></tr>';
	$destination = ! empty( $projection['dispatch_requested'] ) && '' !== (string) ( $projection['destination'] ?? '' )
		? '<p>Destino de la oferta: ' . esc_html( (string) $projection['destination'] ) . '</p>'
		: '';
	return '<p>Enviamos la cotización de la solicitud ' . esc_html( (string) ( $version['reference'] ?? '' ) ) . ' (versión ' . max( 1, (int) ( $version['version'] ?? 1 ) ) . ') en el documento PDF adjunto.</p>'
		. '<table cellspacing="0" cellpadding="8" border="1" style="width:100%;border-collapse:collapse"><caption>Productos ofertados</caption><thead><tr><th scope="col">Producto</th><th scope="col">Cantidad</th><th scope="col">Precio neto</th><th scope="col">Total línea</th></tr></thead><tbody>' . $rows . '</tbody></table>'
		. $destination
		. '<p><strong>Vigencia de la oferta: ' . (int) ( $projection['validity_days'] ?? 0 ) . ' días</strong> a contar de su aprobación, para los productos, cantidades y destino revisados.</p>';
}

/** Whether one delivered byte string is a usable PDF document: real header, catalog, and a closed xref/EOF — garbage never becomes an attachment. */
function fpw_quotation_pdf_is_valid( string $bytes ): bool {
	return strlen( $bytes ) > 300
		&& str_starts_with( $bytes, '%PDF-' )
		&& str_contains( $bytes, '/Type /Catalog' )
		&& str_contains( $bytes, 'trailer' )
		&& str_ends_with( rtrim( $bytes ), '%%EOF' );
}

/** The document stage uses the approved Dompdf distribution. Configured errors stay pending; no legacy-writer fallback. */
function fpw_quotation_render_document( array $version ): string {
	try {
		$custom = apply_filters( 'fpw_quotation_document_bytes', null, $version );
		if ( null !== $custom ) { return is_string( $custom ) ? $custom : ''; }
		require_once __DIR__ . '/quotation-document.php';
		return fpw_quotation_dompdf_render( $version );
	} catch ( Throwable ) {
		return '';
	}
}

/* ===== The screen's approval surface ===== */

/** Bind the form to the exact preview rendered, including its projection, not just its working revision. Not an authorization token. */
function fpw_quotation_preview_token( array $preview ): string {
	return hash( 'sha256', wp_json_encode( $preview ) );
}

/** The approval action carries the preview actually rendered; never reread it while building the form. */
function fpw_draft_approve_html( int $order_id, array $preview ): string {
	return '<form method="post" action="' . esc_url( fpw_draft_screen_url( $order_id ) ) . '">'
		. '<input type="hidden" name="fpw_work_approve" value="1" />'
		. '<input type="hidden" name="fpw_approve_preview" value="' . esc_attr( fpw_quotation_preview_token( $preview ) ) . '" />'
		. wp_nonce_field( fpw_quotation_approve_action( $order_id ), 'fpw_approve_nonce', true, false )
		. '<button type="submit">Aprobar y enviar</button>'
		. '<span class="fpw-draft__origin">Congela esta revisión como la primera versión de la cotización, genera su PDF e intenta enviarlo por correo al comprador. Se resuelve en el servidor: un doble clic no crea versiones ni envíos extra.</span>'
		. '</form>';
}

/** The delivery state of one approved version, named for what it is — acceptance is never buyer receipt. */
function fpw_quotation_delivery_state_html( array $delivery ): string {
	switch ( $delivery['state'] ?? null ) {
		case 'accepted':
			return 'Aceptado por el transporte (no prueba la recepción del comprador)';
		case 'rejected':
			return 'Rechazado por el transporte';
		case 'unknown':
			return 'Resultado desconocido — no se reintenta automáticamente';
	}
	return 'Pendiente';
}

/** The standing approved version in one state line for the status board. */
function fpw_quotation_version_state_html( array $version ): string {
	$document = 'ready' === ( $version['document'] ?? '' ) ? 'documento listo' : 'documento pendiente';
	$mail = match ( $version['delivery']['state'] ?? null ) {
		'accepted' => 'aceptado',
		'rejected' => 'rechazado',
		'unknown'  => 'desconocido',
		default    => 'pendiente',
	};
	return 'Versión ' . max( 1, (int) ( $version['version'] ?? 1 ) ) . ' · ' . $document . ' · correo ' . $mail;
}

/** The approved-version section: what stands, its document and its delivery — recovery operates on exactly this. */
function fpw_quotation_version_section_html( ?array $version ): string {
	if ( ! is_array( $version ) ) { return ''; }
	$document = 'ready' === ( $version['document'] ?? '' )
		? 'Generado con los valores aprobados'
		: 'Pendiente — no se intentó enviar ninguna oferta sin su documento';
	return '<section class="fpw-draft__version"><h2>Versión aprobada</h2><dl>'
		. '<div class="fpw-draft__fact"><dt>Versión</dt><dd>Versión ' . max( 1, (int) ( $version['version'] ?? 1 ) ) . ' · aprobada el ' . esc_html( date_i18n( get_option( 'date_format' ), (int) ( $version['approved_at'] ?? 0 ) ) ) . ' sobre la revisión ' . max( 0, (int) ( $version['work_revision'] ?? 0 ) ) . ' del trabajo</dd></div>'
		. '<div class="fpw-draft__fact"><dt>Documento</dt><dd>' . esc_html( $document ) . '</dd></div>'
		. '<div class="fpw-draft__fact"><dt>Correo al comprador</dt><dd>' . fpw_quotation_delivery_state_html( is_array( $version['delivery'] ?? null ) ? $version['delivery'] : array() ) . '</dd></div>'
		. '</dl><p class="fpw-draft__aside-note">La versión quedó congelada con los valores revisados: cambiar precios o condiciones exige una nueva versión revisada. La recuperación explícita del envío opera sobre esta versión; la aceptación del transporte no prueba la recepción del comprador.</p></section>';
}

/** The approval's outcome notice, in the screen's own language: approval, document and mail named separately. */
function fpw_approval_outcome_notice( array $result ): array {
	$state   = (string) ( $result['state'] ?? '' );
	$version = is_array( $result['version'] ?? null ) ? $result['version'] : array();
	$vlabel  = 'Versión ' . max( 1, (int) ( $version['version'] ?? 1 ) );
	if ( 'approval-refused' === $state ) {
		$reason = (string) ( $result['reason'] ?? '' );
		if ( 'vista-previa-no-revisada' === $reason ) {
			return array( 'class' => 'warn', 'title' => 'Nada se aprobó: el formulario no corresponde a la vista previa actual.', 'lines' => array( 'Recarga el borrador y revisa la vista previa actual antes de aprobarla.' ) );
		}
		if ( 'sin-vista-previa' === $reason ) {
			return array( 'class' => 'warn', 'title' => 'Nada se aprobó: este borrador no tiene una vista previa guardada.', 'lines' => array( 'Genera una vista previa y revísala: ninguna aprobación puede emitir valores que no fueron revisados.' ) );
		}
		if ( 'vista-previa-obsoleta' === $reason ) {
			return array( 'class' => 'warn', 'title' => 'Nada se aprobó: la vista previa guardada quedó obsoleta.', 'lines' => array( 'El trabajo del borrador cambió después de la vista previa revisada. Genera una nueva, revísala y aprueba sobre ella.' ) );
		}
		if ( 'oferta-incompleta' === $reason ) {
			return array( 'class' => 'warn', 'title' => 'Nada se aprobó: la oferta está incompleta.', 'lines' => array_merge( array_map( 'strval', $result['missing'] ?? array() ), array( 'Resuelve estos faltantes, genera una nueva vista previa y revísala antes de aprobar.' ) ) );
		}
		if ( 'sin-trabajo' === $reason ) {
			return array( 'class' => 'warn', 'title' => 'Nada se aprobó: este borrador no tiene trabajo guardado.', 'lines' => array( 'Completa los precios y condiciones, genera una vista previa y revísala antes de aprobar.' ) );
		}
		return array( 'class' => 'warn', 'title' => 'Nada se aprobó: la proyección guardada ya no coincide con el cálculo compartido.', 'lines' => array( 'Lo emitido debe coincidir exactamente con lo revisado. Genera una nueva vista previa, revísala y aprueba sobre ella.' ) );
	}
	if ( 'approval-storage-failed' === $state ) {
		return array( 'class' => 'error', 'title' => 'No se pudo confirmar la aprobación.', 'lines' => array( 'No se intentó enviar desde esta solicitud. Recarga el borrador para comprobar su estado antes de volver a aprobar.' ) );
	}
	if ( 'approval-already' === $state ) {
		return array( 'class' => 'ok', 'title' => 'Este borrador ya tiene su primera versión aprobada (' . $vlabel . ').', 'lines' => array( 'No se creó otra versión ni se reenvió nada: repetir la aprobación responde siempre con la versión existente. La recuperación del envío es una acción explícita aparte.' ) );
	}
	if ( 'approval-sent' === $state ) {
		return array( 'class' => 'ok', 'title' => 'Cotización aprobada y enviada (' . $vlabel . ').', 'lines' => array(
			'La versión quedó congelada con los valores revisados, el documento se generó con esos mismos valores y el correo fue aceptado por el transporte.',
			'La aceptación del transporte no prueba la recepción del comprador; no hay reintento automático.',
		) );
	}
	if ( 'approval-document-pending' === $state ) {
		return array( 'class' => 'error', 'title' => 'Cotización aprobada (' . $vlabel . '), pero el documento no se pudo generar o guardar.', 'lines' => array( 'No se intentó enviar ninguna oferta sin su documento. La versión aprobada queda conservada para la recuperación posterior.' ) );
	}
	if ( 'approval-mail-rejected' === $state ) {
		return array( 'class' => 'error', 'title' => 'Cotización aprobada (' . $vlabel . ') con documento listo, pero el correo fue rechazado por el transporte.', 'lines' => array( 'Nada llegó al comprador por este intento. La versión aprobada queda conservada; la recuperación explícita del envío es la operación siguiente.' ) );
	}
	if ( 'approval-mail-unknown' === $state ) {
		return array( 'class' => 'error', 'title' => 'Cotización aprobada (' . $vlabel . ') con documento listo, pero el resultado del correo es desconocido.', 'lines' => array( 'No hay un resultado confirmado y guardado: no se afirma recepción ni rechazo, y no se reintenta automáticamente. La recuperación explícita es la operación siguiente.' ) );
	}
	return array( 'class' => 'error', 'title' => 'No se pudo completar la aprobación.', 'lines' => array() );
}

/** The approval's server-side half: CSRF (its own nonce), the standing version first, then the guards, then the guarded approval; the outcome is stashed for the screen. */
function fpw_handle_draft_approve_request( int $order_id, array $draft ): void {
	if ( ! wp_verify_nonce( (string) ( $_POST['fpw_approve_nonce'] ?? '' ), fpw_quotation_approve_action( $order_id ) ) ) {
		wp_die( 'Tu sesión expiró o el formulario no es válido: vuelve a cargar el borrador e inténtalo de nuevo. Nada se aprobó ni se envió.', '', array( 'response' => 403 ) );
	}
	$existing = fpw_read_quotation_version( $order_id );
	if ( is_array( $existing ) ) {
		fpw_pending_draft_outcome( array( 'order_id' => $order_id, 'result' => array( 'state' => 'approval-already', 'version' => $existing ) ) );
		return;
	}
	$work    = fpw_read_draft_work( $order_id );
	$preview = fpw_read_draft_preview( $order_id );
	$guard   = fpw_quotation_approval_check( $draft, $work, $preview );
	if ( 'ok' !== $guard['state'] ) {
		fpw_pending_draft_outcome( array( 'order_id' => $order_id, 'result' => array( 'state' => 'approval-refused', 'reason' => $guard['state'], 'missing' => $guard['missing'] ?? array() ) ) );
		return;
	}
	$viewed = $_POST['fpw_approve_preview'] ?? null;
	if ( ! is_string( $viewed ) || ! hash_equals( fpw_quotation_preview_token( $preview ), $viewed ) ) {
		fpw_pending_draft_outcome( array( 'order_id' => $order_id, 'result' => array( 'state' => 'approval-refused', 'reason' => 'vista-previa-no-revisada' ) ) );
		return;
	}
	$outcome = fpw_quotation_approve_and_send( $order_id, $draft, $work, $preview, get_current_user_id() );
	$outcome['state'] = 'approval-' . $outcome['state'];
	fpw_pending_draft_outcome( array( 'order_id' => $order_id, 'result' => $outcome ) );
}
