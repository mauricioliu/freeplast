<?php
/** Independent owner-recorded milestones; never a Woo payment or fulfillment action. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function fpw_tracking_labels(): array {
	return array( 'accepted' => 'Aceptada por el cliente', 'paid' => 'Pago realizado', 'dispatched' => 'Productos despachados' );
}

function fpw_tracking_read( int $id ): array {
	global $wpdb;
	$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'fpw_tracking_' . $id ) );
	$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
	$valid = '' === ( $wpdb->last_error ?? '' ) && ( null === $raw || ( is_array( $data ) && 1 === ( $data['schema'] ?? null ) && $id === ( $data['order_id'] ?? null ) && is_int( $data['revision'] ?? null ) && $data['revision'] > 0 && is_array( $data['events'] ?? null ) ) );
	if ( $valid && is_array( $data ) ) {
		$valid = count( $data['events'] ) === count( fpw_tracking_labels() );
		foreach ( fpw_tracking_labels() as $key => $label ) {
			$date = $data['events'][ $key ] ?? null;
			$valid = $valid && array_key_exists( $key, $data['events'] ) && ( null === $date || fpw_tracking_valid_date( $date ) );
		}
	}
	return array( 'raw' => $raw, 'valid' => $valid, 'revision' => $valid ? ( $data['revision'] ?? 0 ) : 0, 'events' => $valid ? ( $data['events'] ?? array() ) : array() );
}

function fpw_tracking_valid_date( $date ): bool {
	if ( ! is_string( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $date ) || substr( $date, 0, 4 ) < '0001' ) { return false; }
	$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
	return $parsed && $parsed->format( 'Y-m-d' ) === $date;
}

function fpw_tracking_notice( ?array $set = null ): ?array {
	static $notice = null;
	if ( null !== $set ) { $notice = $set; }
	return $notice;
}

function fpw_handle_tracking_post(): void {
	if ( FPW_DRAFT_SCREEN !== ( $_GET['page'] ?? '' ) || ! isset( $_POST['fpw_tracking_save'] ) ) { return; }
	if ( ! current_user_can( 'manage_woocommerce' ) ) { fpw_die_draft_forbidden(); }
	$order = fpw_draft_screen_order();
	$id = $order ? (int) $order->get_id() : 0;
	if ( ! $id || ! fpw_read_request_draft( $id ) ) { wp_die( 'Solicitud no encontrada.', '', array( 'response' => 404 ) ); }
	$nonce = $_POST['fpw_tracking_nonce'] ?? null;
	if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'fpw-tracking-' . $id ) ) { wp_die( 'Tu sesión expiró. Vuelve a cargar la ficha.', '', array( 'response' => 403 ) ); }
	$current = fpw_tracking_read( $id );
	$revision = $_POST['fpw_tracking_revision'] ?? null;
	$message = 'No se guardó el seguimiento. Revisa las fechas y vuelve a intentarlo.';
	$ok = false;
	if ( ! $current['valid'] ) {
		$message = 'El seguimiento guardado no se puede leer. Nada se sobrescribió; solicita revisión técnica.';
	} elseif ( ! is_string( $revision ) || ! ctype_digit( $revision ) || (string) $current['revision'] !== $revision ) {
		$message = 'Este seguimiento tiene una revisión más reciente. Revisa los valores guardados antes de volver a editar.';
	} else {
		$posted = wp_unslash( $_POST['fpw_tracking'] ?? array() );
		$events = array();
		$valid = is_array( $posted ) && ! array_diff( array_keys( $posted ), array_keys( fpw_tracking_labels() ) );
		foreach ( fpw_tracking_labels() as $key => $label ) {
			$entry = is_array( $posted[ $key ] ?? null ) ? $posted[ $key ] : array();
			$done = $entry['done'] ?? '';
			$valid = $valid && in_array( $done, array( '', '1' ), true );
			$date = $entry['date'] ?? '';
			if ( '1' === $done && ! fpw_tracking_valid_date( $date ) ) { $valid = false; }
			$events[ $key ] = '1' === $done ? $date : null;
		}
		if ( $valid ) {
			$next = array( 'schema' => 1, 'order_id' => $id, 'revision' => $current['revision'] + 1, 'updated_at' => time(), 'updated_by' => get_current_user_id(), 'events' => $events );
			$name = 'fpw_tracking_' . $id;
			global $wpdb;
			$ok = null === $current['raw'] ? fpw_insert_options_row( $name, wp_json_encode( $next ) ) : 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", wp_json_encode( $next ), $name, $current['raw'] ) );
			$message = $ok ? 'Seguimiento guardado. La cotización y los otros hitos no se modificaron.' : 'No se guardó el seguimiento: hubo un conflicto o un fallo de almacenamiento. Recarga la ficha antes de intentarlo de nuevo.';
		}
	}
	fpw_tracking_notice( array( 'order_id' => $id, 'class' => $ok ? 'ok' : 'error', 'title' => $message ) );
}
add_action( 'admin_init', 'fpw_handle_tracking_post', 9 );

/** The sent milestone is derived only from durable mail handoff, never from approval or manual checkboxes. */
function fpw_commercial_events( int $id, ?array $version = null ): array {
	$tracking = fpw_tracking_read( $id );
	$events = array_merge( array_fill_keys( array_keys( fpw_tracking_labels() ), null ), $tracking['events'] );
	$delivery = $version['delivery'] ?? array();
	$events['sent'] = 'accepted' === ( $delivery['state'] ?? null ) && is_int( $delivery['at'] ?? null ) ? wp_date( 'Y-m-d', $delivery['at'] ) : null;
	$events['send_unknown'] = 'unknown' === ( $delivery['state'] ?? null );
	$events['tracking_unavailable'] = ! $tracking['valid'];
	return $events;
}

function fpw_milestones_html( array $events ): string {
	$html = '<dl class="fpw-milestones">';
	foreach ( array( 'sent' => 'Cotización enviada' ) + fpw_tracking_labels() as $key => $label ) {
		$date = $events[ $key ] ?? null;
		$text = is_string( $date ) ? '<time datetime="' . esc_attr( $date ) . '">' . esc_html( substr( $date, 8, 2 ) . '/' . substr( $date, 5, 2 ) . '/' . substr( $date, 0, 4 ) ) . '</time>' : 'Pendiente';
		if ( 'sent' === $key && ! empty( $events['send_unknown'] ) ) { $text = 'Sin confirmar'; }
		if ( 'sent' !== $key && ! empty( $events['tracking_unavailable'] ) ) { $text = 'No disponible'; }
		$html .= '<div data-fpw-milestone="' . esc_attr( $key ) . '"><dt>' . esc_html( $label ) . '</dt><dd' . ( is_string( $date ) ? ' class="is-done"' : '' ) . '>' . $text . '</dd></div>';
	}
	return $html . '</dl>';
}

function fpw_tracking_form_html( int $id ): string {
	$current = fpw_tracking_read( $id );
	if ( ! $current['valid'] ) { return '<p>Seguimiento no disponible. Solicita revisión técnica antes de editarlo.</p>'; }
	$html = '<details class="fpw-tracking"><summary>Actualizar aceptación, pago o despacho</summary><p>Registro manual del dueño. Cada hito es independiente. Desmarcar corrige el registro; no mueve dinero ni cambia pedidos.</p><form method="post" data-fpw-tracking action="' . esc_url( fpw_draft_screen_url( $id ) ) . '">'
		. '<input type="hidden" name="fpw_tracking_save" value="1"><input type="hidden" name="fpw_tracking_revision" value="' . (int) $current['revision'] . '">'
		. wp_nonce_field( 'fpw-tracking-' . $id, 'fpw_tracking_nonce', true, false );
	foreach ( fpw_tracking_labels() as $key => $label ) {
		$date = $current['events'][ $key ] ?? null;
		$html .= '<div class="fpw-tracking-field"><label><input type="checkbox" name="fpw_tracking[' . $key . '][done]" value="1"' . ( $date ? ' checked' : '' ) . '> ' . esc_html( $label ) . '</label>'
			. '<label>Fecha · ' . esc_html( $label ) . '<input type="date" name="fpw_tracking[' . $key . '][date]" value="' . esc_attr( $date ?? '' ) . '"></label></div>';
	}
	return $html . '<button type="submit">Guardar seguimiento</button> <button type="reset" class="fpw-secondary">Descartar cambios sin guardar</button></form></details>';
}
