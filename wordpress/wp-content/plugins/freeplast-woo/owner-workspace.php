<?php
/** Owner workspace A. Presentation composes the existing draft/preview/issuance interfaces. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/workspace-chrome.php';
require_once __DIR__ . '/workspace-offer.php';
require_once __DIR__ . '/workspace-requests.php';

add_action( 'admin_menu', 'fpw_workspace_register' );
function fpw_workspace_register(): void {
	if ( fpw_can_manage_quotations() ) {
		add_menu_page( 'Solicitudes de clientes', 'Cotizaciones', fpw_quotation_screen_capability(), 'fpw-quotations', 'fpw_render_workspace', 'dashicons-media-document' );
	}
}

function fpw_workspace_url(): string { return admin_url( 'admin.php?page=fpw-quotations' ); }

/** Assets and chrome changes are confined to these two owner surfaces. */
add_action( 'admin_enqueue_scripts', 'fpw_workspace_assets' );
function fpw_workspace_assets(): void {
	if ( ! fpw_workspace_surface() ) { return; }
	wp_enqueue_style( 'fpw-workspace', plugins_url( 'assets/owner-workspace.css', __FILE__ ), array(), '1.12.17' );
	wp_enqueue_script( 'fpw-workspace', plugins_url( 'assets/owner-workspace.js', __FILE__ ), array(), '1.12.17', true );
	wp_add_inline_style( 'fpw-workspace', '@font-face{font-family:FPWManrope;src:url("' . esc_url( get_theme_file_uri( 'assets/fonts/manrope.woff2' ) ) . '") format("woff2");font-weight:200 800;font-display:swap}' );
}
add_filter( 'admin_title', static function ( $title ) {
	return fpw_can_manage_quotations() && FPW_DRAFT_SCREEN === ( $_GET['page'] ?? '' ) && '1' === ( $_GET['workspace'] ?? '' ) ? 'Cotización · Freeplast' : $title;
} );
add_filter( 'admin_body_class', static function ( $classes ) {
	if ( fpw_workspace_surface() ) { $classes .= ' fpw-workspace-page'; }
	return $classes;
} );

function fpw_workspace_shell( string $body, ?int $id = null, ?int $total = null, bool $editable = false ): string {
	$header = fpw_workspace_header() . fpw_workspace_navigation();
	$nav = $editable ? '<nav class="fpw-workspace-dock" aria-label="Preparar cotización"><a href="#fpw-summary"><span data-fpw-total-label>Total guardado</span><strong data-fpw-dock-total>' . fpw_workspace_money( $total ) . '</strong></a><button type="submit" name="fpw_work_save" value="1" form="fpw-work-form" data-fpw-dock-save hidden>Guardar borrador</button><button type="submit" name="fpw_work_preview" value="1" form="fpw-work-form" data-fpw-preview data-fpw-dock-preview>Revisar cotización</button></nav>' : '';
	return '<div class="fpw-workspace' . ( $editable ? ' fpw-workspace-editable' : '' ) . '">' . $header . '<div class="fpw-workspace-body">' . $body . '</div>' . $nav . '</div>';
}

function fpw_workspace_money( ?int $amount ): string {
	return null === $amount ? 'Por definir' : esc_html( number_format( $amount, 0, ',', '.' ) . ' CLP' );
}

/** A standing issuance can only supply its own frozen projection, never a draft fallback. */
function fpw_workspace_read_offer( array $draft, ?array $work ): array {
	$id = (int) $draft['order_id'];
	$version = fpw_read_quotation_version( $id );
	if ( null === $version ) {
		global $wpdb;
		$standing = $wpdb->get_var( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name = %s", fpw_quotation_row_name( $id ) ) );
		if ( null === $standing && '' === ( $wpdb->last_error ?? '' ) ) {
			return array( 'version' => null, 'projection' => fpw_quotation_projection( $draft, $work ), 'unavailable' => false );
		}
	}
	$p = $version['projection'] ?? null;
	$valid = 1 === ( $version['schema'] ?? null ) && $id === ( $version['order_id'] ?? null ) && is_int( $version['version'] ?? null ) && $version['version'] > 0 && is_int( $version['approved_at'] ?? null )
		&& is_array( $p ) && 1 === ( $p['schema'] ?? null ) && true === ( $p['complete'] ?? null ) && empty( $p['missing'] ) && is_array( $p['lines'] ?? null ) && ! empty( $p['lines'] )
		&& array_is_list( $p['lines'] ) && count( $p['lines'] ) === count( $draft['items'] ) && array_key_exists( 'dispatch', $p )
		&& is_int( $p['validity_days'] ?? null ) && $p['validity_days'] > 0 && is_bool( $p['dispatch_requested'] ?? null ) && is_string( $p['destination'] ?? null );
	foreach ( array( 'subtotal', 'tax', 'total' ) as $key ) { $valid = $valid && is_int( $p[ $key ] ?? null ) && $p[ $key ] >= 0; }
	if ( $valid && $p['dispatch_requested'] ) { $valid = is_int( $p['dispatch'] ?? null ) && $p['dispatch'] > 0; }
	if ( $valid ) {
		foreach ( $p['lines'] as $line ) {
			$valid = $valid && is_array( $line ) && is_int( $line['quantity'] ?? null ) && $line['quantity'] > 0 && is_int( $line['price'] ?? null ) && $line['price'] > 0 && is_int( $line['line_total'] ?? null );
		}
	}
	return array( 'version' => $version, 'projection' => $valid ? $p : null, 'unavailable' => ! $valid );
}

/** Review saves posted edits through the existing CAS guard; refresh never discards them. Works without JavaScript. */
function fpw_workspace_guard_clean_action( int $id, array $draft, string $action ): bool {
	if ( ! in_array( $action, array( 'preview', 'refresh' ), true ) || ! isset( $_POST['fpw_work'] ) ) { return true; }
	$nonce = 'preview' === $action ? 'fpw_preview_nonce' : 'fpw_refresh_nonce';
	$scope = 'preview' === $action ? fpw_draft_preview_action( $id ) : fpw_draft_refresh_action( $id );
	if ( ! wp_verify_nonce( (string) ( $_POST[ $nonce ] ?? '' ), $scope ) ) { wp_die( 'El formulario no es válido. Vuelve a cargar la cotización.', '', array( 'response' => 403 ) ); }
	$work = fpw_read_draft_work( $id );
	if ( (int) ( $_POST['fpw_work_revision'] ?? -1 ) !== fpw_draft_work_revision( $work ) ) {
		fpw_pending_draft_outcome( array( 'order_id' => $id, 'result' => array( 'state' => 'conflict', 'work' => $work, 'stored_revision' => fpw_draft_work_revision( $work ) ) ) );
		return false;
	}
	$posted = fpw_parse_draft_work_input( $draft, wp_unslash( $_POST ) );
	if ( ! empty( $posted['errors'] ) ) {
		fpw_pending_draft_outcome( array( 'order_id' => $id, 'result' => array( 'state' => 'workspace-invalid', 'errors' => $posted['errors'] ) ) );
		return false;
	}
	$stored = fpw_draft_work_values( $draft, $work );
	$changed = false;
	foreach ( array( 'destination', 'pickup_location', 'dispatch_amount', 'validity_days' ) as $key ) { $changed = $changed || $posted[ $key ] !== $stored[ $key ]; }
	foreach ( $stored['lines'] as $index => $line ) { $changed = $changed || $posted['lines'][ $index ]['quantity'] !== $line['quantity'] || $posted['lines'][ $index ]['price'] !== $line['price']; }
	if ( 'preview' === $action && ( $changed || null === fpw_read_draft_work_raw( $id ) ) ) {
		if ( ! wp_verify_nonce( (string) ( $_POST['fpw_draft_nonce'] ?? '' ), fpw_draft_save_action( $id ) ) ) { wp_die( 'El formulario no es válido. Vuelve a cargar la cotización.', '', array( 'response' => 403 ) ); }
		$result = fpw_save_draft_work( $id, $draft, wp_unslash( $_POST ), get_current_user_id() );
		if ( 'saved' !== ( $result['state'] ?? '' ) ) {
			fpw_pending_draft_outcome( array( 'order_id' => $id, 'result' => $result ) );
			return false;
		}
		return true;
	}
	if ( ! $changed ) { return true; }
	fpw_pending_draft_outcome( array( 'order_id' => $id, 'result' => array( 'state' => 'workspace-unsaved', 'values' => $posted ) ) );
	return false;
}

function fpw_render_workspace(): void {
	if ( ! fpw_can_manage_quotations() ) { fpw_die_draft_forbidden(); }
	$archive_notice = '';
	if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['fpw_archive_request'] ) ) {
		$id = is_scalar( $_POST['fpw_archive_request'] ) ? absint( $_POST['fpw_archive_request'] ) : 0;
		$action = $_POST['fpw_archive_action'] ?? '';
		$nonce = is_string( $_POST['fpw_archive_nonce'] ?? null ) ? $_POST['fpw_archive_nonce'] : '';
		if ( ! $id || ! in_array( $action, array( 'archive', 'restore' ), true ) || ! wp_verify_nonce( $nonce, 'fpw_archive_' . $id ) ) {
			wp_die( 'El formulario no es válido. Vuelve a cargar las solicitudes.', '', array( 'response' => 403 ) );
		}
		$archive_notice = fpw_workspace_set_archived( $id, 'archive' === $action );
	}
	$search = is_string( $_GET['q'] ?? null ) ? mb_substr( sanitize_text_field( wp_unslash( $_GET['q'] ) ), 0, 100 ) : '';
	$stage = is_string( $_GET['stage'] ?? null ) && isset( fpw_workspace_stages()[ $_GET['stage'] ] ) ? $_GET['stage'] : 'pendientes';
	$sort = 'oldest' === ( $_GET['sort'] ?? '' ) ? 'ASC' : 'DESC';
	$page = is_scalar( $_GET['paged'] ?? null ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	$result = fpw_workspace_query( $search, $stage, $sort, $page );
	$body = '<h1>Solicitudes de clientes</h1>' . ( '' !== $archive_notice ? '<p role="status">' . esc_html( $archive_notice ) . '</p>' : '' ) . '<nav class="fpw-request-views" aria-label="Ver solicitudes">';
	foreach ( fpw_workspace_stages() as $key => $label ) {
		$body .= '<a href="' . esc_url( add_query_arg( array( 'stage' => $key, 'q' => $search, 'sort' => 'ASC' === $sort ? 'oldest' : 'newest' ), fpw_workspace_url() ) ) . '"' . ( $key === $stage ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
	}
	$body .= '</nav><form method="get" class="fpw-workspace-search"><input type="hidden" name="page" value="fpw-quotations"><input type="hidden" name="stage" value="' . esc_attr( $stage ) . '">'
		. '<label class="fpw-search-query">Empresa, RUT o referencia<input type="search" name="q" maxlength="100" value="' . esc_attr( $search ) . '"></label><button type="submit">Buscar</button>'
		. '<input type="hidden" name="sort" value="' . ( 'ASC' === $sort ? 'oldest' : 'newest' ) . '"></form>';
	if ( $result['error'] ) {
		$body .= '<section class="fpw-workspace-empty"><h2>No se pudo cargar la bandeja</h2><p>No se modificó ninguna solicitud. Recarga la página para volver a intentarlo.</p></section>';
	} elseif ( ! $result['rows'] ) {
		$body .= '<section class="fpw-workspace-empty"><h2>' . ( '' !== $search || 'pendientes' !== $stage ? 'No hay coincidencias' : 'No hay solicitudes pendientes. Todo está cotizado.' ) . '</h2><a href="' . esc_url( add_query_arg( 'stage', 'all', fpw_workspace_url() ) ) . '">Ver todas las solicitudes</a></section>';
	} else {
		$body .= '<p class="fpw-muted">' . $result['total'] . ( 1 === $result['total'] ? ' solicitud · página ' : ' solicitudes · página ' ) . $result['page'] . ' de ' . $result['pages'] . '</p><div class="fpw-workspace-inbox">';
		$body .= '<div class="fpw-inbox-head" aria-hidden="true"><span>Solicitud</span><span>Estado del envío</span><span>Total</span><span></span></div>';
		foreach ( $result['rows'] as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order ) { continue; }
			$draft = fpw_read_request_draft( $id );
			$offer = $draft ? fpw_workspace_read_offer( $draft, fpw_read_draft_work( $id ) ) : array( 'version' => fpw_read_quotation_version( $id ), 'projection' => null, 'unavailable' => false );
			$version = $offer['version'];
			$projection = $offer['projection'];
			$reference = $draft['reference'] ?? $order->get_order_number();
			$company = $draft['identity']['company'] ?? $order->get_billing_company();
			$created = $order->get_date_created();
			$received = $draft['received_at'] ?? ( $created ? $created->getTimestamp() : null );
			$url = $draft ? fpw_draft_screen_url( $id ) : fpw_draft_request_admin_url( $id );
			$body .= '<article data-fpw-request="' . $id . '" class="fpw-workspace-row"><div><h2><a href="' . esc_url( $url ) . '">' . esc_html( $company ?: $reference ) . '</a></h2><p class="fpw-muted">' . esc_html( $reference ) . ( null !== $received ? ' · ' . esc_html( fpw_workspace_date( 'd/m/Y · H:i', (int) $received ) ) : '' ) . '</p></div>'
				. fpw_workspace_request_state_html( $id, $version, $offer['unavailable'], 'yes' === $order->get_meta( '_fp_workspace_archived' ) ) . '<div class="fpw-workspace-row-total"><span>' . ( $offer['unavailable'] ? 'Versión no disponible' : ( $version ? 'Total aprobado' : 'Total guardado' ) ) . '</span><strong>' . fpw_workspace_money( $projection['total'] ?? null ) . '</strong></div><div class="fpw-request-actions"><a class="fpw-button" href="' . esc_url( $url ) . '">' . ( $version ? 'Ver' : 'Abrir' ) . '<span class="screen-reader-text"> ' . esc_html( $reference ) . '</span></a>'
				. ( ! $offer['unavailable'] && fpw_quotation_can_revise( $id, $version ) ? '<a class="fpw-button" href="' . esc_url( fpw_quotation_edit_url( $id, $version['version'] ) ) . '">Editar<span class="screen-reader-text"> ' . esc_html( $reference ) . '</span></a>' : '' )
				. fpw_workspace_archive_html( $id, 'yes' === $order->get_meta( '_fp_workspace_archived' ), null !== $draft && fpw_workspace_can_archive( $id, $version, $offer['unavailable'] ) ) . '</div></article>';
		}
		$body .= '</div><nav class="fpw-workspace-pagination" aria-label="Páginas de cotizaciones">';
		foreach ( array( -1 => 'Anterior', 1 => 'Siguiente' ) as $delta => $label ) {
			$next = $result['page'] + $delta;
			if ( $next >= 1 && $next <= $result['pages'] ) { $body .= '<a class="fpw-button" href="' . esc_url( add_query_arg( array( 'paged' => $next, 'q' => $search, 'stage' => $stage, 'sort' => 'ASC' === $sort ? 'oldest' : 'newest' ), fpw_workspace_url() ) ) . '">' . $label . '</a>'; }
		}
		$body .= '</nav>';
	}
	$body .= '<p class="fpw-muted">El estado de envío se actualiza automáticamente. Enviada no confirma recepción del cliente.</p>';
	echo fpw_workspace_shell( $body );
}

/** Packaging is catalog information, not a volume-pricing policy. */
function fpw_workspace_product_html( array $line, array $value, int $index, ?int $line_total, bool $readonly ): string {
	$product = wc_get_product( (int) ( $line['variation_id'] ?: $line['product_id'] ) );
	$reference = fpw_price_reference_for( (int) $line['product_id'], (int) $line['variation_id'] );
	$pack = $product ? $product->get_attribute( 'Unidades por pallet' ) : '';
	$units = $reference['units'] ?? ( ctype_digit( $pack ) && (int) $pack > 0 ? (int) $pack : null );
	$qty = (int) $value['quantity'];
	$minimum = fpw_quote_minimum( (int) $line['product_id'] );
	$minimum_text = null === $minimum ? 'Mínimo por confirmar: revisa la cantidad con el comprador antes de aprobar la oferta.' : 'Mínimo por solicitud: ' . number_format( $minimum, 0, ',', '.' ) . ' unidades — ' . ( $qty >= $minimum ? 'cubierto.' : 'revisa la cantidad con el comprador.' );
	$pallets = null === $units ? '' : intdiv( $qty, $units ) . ( 1 === intdiv( $qty, $units ) ? ' pallet completo + ' : ' pallets completos + ' ) . ( $qty % $units ) . ' un. · ' . $units . ' un. por pallet';
	$photo = $product && $product->get_image_id() ? $product->get_image( 'woocommerce_thumbnail', array( 'alt' => '', 'loading' => 'lazy' ) ) : '<span class="fpw-photo-missing">Sin foto</span>';
	$options = '';
	foreach ( $line['options'] ?? array() as $option ) { $options .= '<span>' . esc_html( wc_attribute_label( $option['key'] ) . ': ' . $option['value'] ) . '</span> '; }
	$price = $value['price'] ?? $value['suggested'];
	$suggested = $value['suggested'];
	$origin = null === $value['price'] ? ( null === $suggested ? '' : 'Precio de lista, aún sin guardar.' ) : ( 'suggested' === $value['price_source'] ? 'Precio guardado de la lista.' : 'Precio guardado para esta oferta.' );
	$prefix = 'fpw_work[lines][' . $index . ']';
	return '<section class="fpw-workspace-product"><div class="fpw-product-heading"><div class="fpw-product-photo">' . $photo . '</div><div><h3>' . esc_html( $line['name'] ) . '</h3><p>' . $options . '</p><p class="fpw-muted">Pedido: ' . (int) $line['quantity'] . ' unidades</p></div></div>'
		. '<div class="fpw-product-inputs"><label>Cantidad <span class="screen-reader-text">de ' . esc_html( $line['name'] ) . '</span><span class="fpw-muted">unidades</span>' . fpw_draft_quantity_input_html( $prefix . '[quantity]', $qty ) . '</label><label class="fpw-offered-price">Precio ofrecido <span class="screen-reader-text">de ' . esc_html( $line['name'] ) . '</span><span class="fpw-muted">CLP netos / unidad</span>' . fpw_draft_amount_input_html( $prefix . '[price]', $price ) . '</label></div>'
		. ( null !== $units ? '<p class="fpw-muted" data-fpw-pallets="' . $index . '" data-units="' . (int) $units . '">' . esc_html( $pallets ) . '</p>' : '' )
		. '<p class="fpw-muted" data-fpw-minimum="' . $index . '" data-minimum="' . esc_attr( null === $minimum ? '' : (string) $minimum ) . '">' . esc_html( $minimum_text ) . '</p><p class="fpw-muted" data-fpw-origin="' . $index . '"' . ( '' === $origin ? ' hidden' : '' ) . '>' . $origin . '</p>'
		. fpw_workspace_references_html( $reference, $index, $readonly, $suggested )
		. '<div class="fpw-line-total"><span data-fpw-line-label>Neto guardado</span><strong data-fpw-line-total="' . $index . '">' . fpw_workspace_money( $line_total ) . '</strong></div></section>';
}

function fpw_workspace_history( array $draft ): string {
	$history = fpw_sales_history_for_rut( fpw_sales_normalize_rut( (string) ( $draft['identity']['rut'] ?? '' ) ) );
	$count = count( $history['sales'] ?? array() );
	$html = '<details id="fpw-history" class="fpw-workspace-history"><summary>Historial de compras (' . $count . ')</summary>';
	if ( ! $history || ! $history['sales'] ) {
		return $html . '<p><strong>Sin historial asociado.</strong> No hay compras vinculadas al RUT de esta solicitud. Esto no indica que el cliente sea nuevo.</p></details>';
	}
	$html .= '<div class="fpw-history-list"><div class="fpw-history-head" aria-hidden="true"><span>Fecha</span><span>Factura</span><span>Total</span><span></span></div><ul aria-label="Compras registradas">';
	foreach ( $history['sales'] as $sale ) {
		$date = (string) ( $sale['date'] ?? '' );
		$date_label = preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $date ) ? implode( '/', array_reverse( explode( '-', $date ) ) ) : ( $date ?: '—' );
		$invoice = isset( $sale['invoice'] ) && '' !== (string) $sale['invoice'] ? 'N.º ' . $sale['invoice'] : 'Sin número';
		$html .= '<li><details class="fpw-history-purchase"><summary><span><span class="screen-reader-text">Fecha: </span>' . esc_html( $date_label ) . '</span><span><span class="screen-reader-text">Factura: </span>' . esc_html( $invoice ) . '</span><strong><span class="screen-reader-text">Total: </span>' . esc_html( fpw_workspace_history_money( $sale['total'] ?? null ) ) . '</strong><svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg></summary>';
		$html .= '<div class="fpw-history-detail">';
		$labels = array( 'sale' => 'Venta', 'adjustment' => 'Ajuste', 'cancellation' => 'Anulación', 'return' => 'Devolución' );
		$movements = array_map( static fn( $type ) => $labels[$type] ?? $type, $sale['movements'] ?? array() );
		if ( $movements ) { $html .= '<p class="fpw-muted">Movimiento: ' . esc_html( implode( ', ', $movements ) ) . '</p>'; }
		// Workbook lines and legacy CSV detail stay commercial; provenance remains stored, not rendered here.
		$lines = ! empty( $sale['lines'] ) ? $sale['lines'] : array( $sale );
		foreach ( $lines as $line ) {
			$fields = array();
			if ( '' !== ( $line['producto'] ?? '' ) ) { $fields['Producto'] = $line['producto']; }
			if ( null !== ( $line['total_cajas'] ?? null ) ) { $fields['Cantidad (cajas)'] = number_format( (int) $line['total_cajas'], 0, ',', '.' ); }
			foreach ( array( 'precio' => 'Precio unitario', 'neto' => 'Neto' ) as $key => $label ) {
				if ( null !== ( $line[$key] ?? null ) ) { $fields[$label] = fpw_workspace_history_money( $line[$key] ); }
			}
			if ( ! empty( $sale['lines'] ) && null !== ( $line['total'] ?? null ) ) { $fields['Total línea'] = fpw_workspace_history_money( $line['total'] ); }
			if ( ! $fields ) { $html .= '<p class="fpw-muted">Sin detalle comercial disponible.</p>'; continue; }
			$html .= '<dl>';
			foreach ( $fields as $label => $value ) { $html .= '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>'; }
			$html .= '</dl>';
		}
		$html .= '</div></details></li>';
	}
	$html .= '</ul></div>';
	if ( is_array( $history['freshness'] ?? null ) ) { $html .= '<p class="fpw-muted fpw-history-updated">Última actualización: ' . esc_html( fpw_workspace_date( 'd/m/Y', (int) $history['freshness']['at'] ) ) . '</p>'; }
	return $html . '</details>';
}

/** Preserve exact imported CLP decimals, including explicit zero. */
function fpw_workspace_history_money( $amount ): string {
	return null === $amount ? '—' : '$' . str_replace( ' CLP', '', fpw_sales_format_clp( $amount ) );
}

/** Decorate our native editor controls; preserve rejected input without changing saved work. */
function fpw_workspace_review_fields( string $html, bool $validate, bool $posted, bool $stale ): string {
	$first = true;
	$raw = $posted && is_array( $_POST['fpw_work'] ?? null ) ? wp_unslash( $_POST['fpw_work'] ) : array();
	$pickup = $posted ? ( $raw['pickup_location'] ?? '' ) : ( preg_match( '/name="fpw_work\[pickup_location\]" value="([^"]*)" checked/', $html, $selection ) ? $selection[1] : '' );
	return preg_replace_callback( '/<(input|textarea)\b[^>]*name="(fpw_work\[[^"]+)"[^>]*>(?:[^<]*<\/textarea>)?/s', static function ( $match ) use ( $validate, $posted, $raw, $pickup, $stale, &$first ) {
		$tag = $match[1]; $name = $match[2]; $control = $match[0];
		preg_match_all( '/\[([^\]]+)\]/', $name, $parts );
		$value = $raw;
		foreach ( $parts[1] as $part ) { $value = is_array( $value ) ? ( $value[ $part ] ?? '' ) : ''; }
		if ( $posted ) {
			$value = is_scalar( $value ) ? (string) $value : '';
			if ( str_contains( $control, 'type="radio"' ) ) {
				$control = str_replace( ' checked', '', $control );
				if ( str_contains( $control, 'value="' . esc_attr( $value ) . '"' ) ) { $control = str_replace( 'type="radio"', 'type="radio" checked', $control ); }
			} elseif ( 'input' === $tag ) { $control = preg_replace_callback( '/value="[^"]*"/', static fn() => 'value="' . esc_attr( $value ) . '"', $control ); }
			else { $control = preg_replace_callback( '/>.*<\/textarea>/s', static fn() => '>' . esc_html( $value ) . '</textarea>', $control ); }
		}
		if ( 'input' === $tag ) { preg_match( '/value="([^"]*)"/', $control, $value ); $value = html_entity_decode( $value[1] ?? '', ENT_QUOTES ); }
		else { $value = html_entity_decode( strip_tags( $control ), ENT_QUOTES ); }
		$message = '';
		if ( str_ends_with( $name, '[quantity]' ) && ! is_int( fpw_parse_draft_quantity( $value ) ) ) { $message = 'Ingresa una cantidad entera entre 1 y 1.000.000.'; }
		elseif ( ( str_ends_with( $name, '[price]' ) || str_ends_with( $name, '[dispatch_amount]' ) ) && ! is_int( fpw_parse_draft_amount( $value ) ) ) { $message = 'Ingresa un monto entero entre 1 y 99.999.999 CLP.'; }
		elseif ( str_ends_with( $name, '[destination]' ) && ( '' === trim( $value ) || strlen( $value ) > 800 ) ) { $message = 'Completa el destino (máximo 800 caracteres).'; }
		elseif ( str_ends_with( $name, '[pickup_location]' ) && ! isset( fpw_pickup_locations()[ is_scalar( $pickup ) ? $pickup : '' ] ) ) { $message = 'Selecciona el punto de retiro.'; }
		elseif ( str_ends_with( $name, '[validity_days]' ) && 'invalid' === fpw_parse_draft_validity( $value ) ) { $message = 'Ingresa una vigencia entre 1 y 365 días, o deja el valor por defecto.'; }
		if ( $stale && str_ends_with( $name, '[dispatch_amount]' ) ) { $message = 'Revisa el monto de despacho: cambió el destino o las cantidades.'; }
		$attributes = ' data-fpw-review-field';
		if ( $validate && $message ) {
			$id = 'fpw-error-' . md5( $name . $value );
			$attributes .= ' aria-invalid="true" aria-describedby="' . $id . '" data-fpw-review-note="' . $id . '"' . ( $first ? ' autofocus' : '' );
			$first = false;
			$control .= '<span class="fpw-field-error" id="' . $id . '">' . esc_html( $message ) . '</span>';
		}
		return preg_replace( '/^<' . $tag . '\b/', '<' . $tag . $attributes, $control );
	}, $html );
}

/** Contact values come from working state; receipt and delivery facts remain immutable. */
function fpw_workspace_request_header( array $draft, ?array $work, ?array $version ): string {
	$identity = $version ? array_replace( $draft['identity'], $version['buyer'] ?? array() ) : fpw_draft_identity( $draft, $work );
	$with_dispatch = fpw_draft_requests_dispatch( $draft );
	$quoted_at = (int) ( $version ? ( $version['approved_at'] ?? 0 ) : ( $work['updated_at'] ?? 0 ) );
	$html = '<section class="fpw-request-header" aria-labelledby="fpw-request-company"><div class="fpw-request-heading"><h1 id="fpw-request-company">' . esc_html( $identity['company'] ?: $draft['reference'] ) . '</h1><p class="fpw-muted">Solicitud ' . esc_html( $draft['reference'] ) . ' · ' . esc_html( fpw_workspace_date( 'd/m/Y · H:i', (int) $draft['received_at'] ) ) . '</p><p class="fpw-muted fpw-quotation-time">' . ( $quoted_at > 0 ? ( $version ? 'Cotización aprobada: ' : 'Borrador guardado: ' ) . esc_html( fpw_workspace_date( 'd/m/Y · H:i', $quoted_at ) ) : 'Cotización aún sin guardar' ) . '</p></div><div class="fpw-request-facts">';
	$groups = array(
		'Contacto' => array( 'Nombre' => $identity['name'] ?? '', 'Teléfono' => $identity['phone'] ?? '', 'Correo' => $identity['email'] ?? '' ),
		'Datos de empresa' => array( 'RUT' => $identity['rut'] ?? '', 'Giro' => $identity['giro'] ?? '' ),
		'Despacho' => array( 'Modalidad' => $with_dispatch ? 'Con despacho' : 'Sin despacho' ),
	);
	if ( $with_dispatch ) {
		foreach ( array( 'address' => 'Dirección', 'commune' => 'Comuna', 'location_url' => 'Enlace de ubicación', 'references' => 'Referencias para llegar' ) as $key => $label ) {
			$groups['Despacho'][ $label ] = $draft['destination'][ $key ] ?? '';
		}
	}
	foreach ( $groups as $heading => $facts ) {
		$html .= '<div class="fpw-request-group"><h2>' . esc_html( $heading ) . '</h2><dl>';
		foreach ( $facts as $label => $value ) {
			if ( '' !== (string) $value ) { $html .= fpw_draft_fact_html( $label, (string) $value ); }
		}
		if ( 'Despacho' === $heading && $with_dispatch ) { $html .= fpw_draft_provenance_facts_html( $draft['destination'] ); }
		$html .= '</dl></div>';
	}
	return $html . '</div></section>';
}

function fpw_workspace_detail_markup( $order, ?array $draft, ?array $work, ?array $notice ): string {
	if ( ! $draft || ! $order ) { return fpw_workspace_shell( '<a href="' . esc_url( fpw_workspace_url() ) . '">Volver a solicitudes</a>' . fpw_quote_draft_markup( $order, $draft, $work, $notice ) ); }
	$id = (int) $order->get_id();
	$offer = fpw_workspace_read_offer( $draft, $work );
	if ( $offer['unavailable'] ) {
		return fpw_workspace_shell( '<h1>Versión aprobada no disponible</h1><p>Sus importes guardados no se pueden leer. No se sustituyeron por valores del borrador. Solicita revisión técnica antes de continuar.</p><a class="fpw-button" href="' . esc_url( fpw_workspace_url() ) . '">Volver a solicitudes</a>' );
	}
	$version = $offer['version'];
	$projection = $offer['projection'];
	$base = fpw_quotation_revision_base( $id );
	$editing = $base && fpw_quotation_revision_is_current( $id );
	if ( $version && ! $editing ) {
		$number = $_GET['version'] ?? null;
		if ( is_string( $number ) && ctype_digit( $number ) ) {
			$selected = fpw_read_quotation_version( $id, (int) $number );
			if ( ! $selected ) { return fpw_workspace_shell( '<h1>Versión no disponible</h1><a class="fpw-button" href="' . esc_url( fpw_workspace_url() ) . '">Volver a solicitudes</a>' ); }
			$version = $selected;
		}
		return fpw_workspace_shell( fpw_workspace_issued_html( $version ), $id );
	}
	if ( $editing ) {
		$version = null;
		$projection = fpw_quotation_projection( $draft, $work );
	}
	$values = fpw_draft_work_values( $draft, $work );
	$pending = fpw_pending_draft_outcome();
	$unsaved = ! $version && $id === ( $pending['order_id'] ?? null ) && 'workspace-unsaved' === ( $pending['result']['state'] ?? '' );
	if ( $unsaved ) {
		$posted = $pending['result']['values'];
		foreach ( array( 'destination', 'pickup_location', 'dispatch_amount', 'validity_days' ) as $key ) { $values[ $key ] = $posted[ $key ]; }
		foreach ( $posted['lines'] as $index => $line ) { $values['lines'][ $index ] = array_replace( $values['lines'][ $index ], $line ); }
	}
	$body = '';
	$blocked = $id === ( $pending['order_id'] ?? null ) && in_array( $pending['result']['state'] ?? '', array( 'workspace-unsaved', 'workspace-invalid', 'invalid', 'conflict' ), true );
	$review = ! $blocked && ( '1' === ( $_GET['review'] ?? '' ) || isset( $_POST['fpw_work_preview'] ) || isset( $_POST['fpw_work_approve'] ) );
	$validation_work = $work;
	$review_invalid = ! $version && ( ( $id === ( $pending['order_id'] ?? null ) && in_array( $pending['result']['state'] ?? '', array( 'workspace-invalid', 'invalid' ), true ) ) || ( $review && ! $projection['complete'] ) );
	if ( $review_invalid ) {
		$review = false;
		$errors = $projection['missing'];
		if ( is_array( $_POST['fpw_work'] ?? null ) ) {
			$parsed = fpw_parse_draft_work_input( $draft, wp_unslash( $_POST ) );
			$validation_work = array_replace( $work ?? array(), $parsed );
			// A newly entered amount has no stale saved basis; validation never writes it.
			if ( $parsed['dispatch_amount'] !== ( $work['dispatch_amount'] ?? null ) ) { $validation_work['dispatch_conditions'] = null; }
			$errors = array_unique( array_merge( $parsed['errors'], fpw_quotation_projection( $draft, $validation_work )['missing'] ) );
		}
		$body .= '<div class="fpw-workspace-notice" role="alert" tabindex="-1" data-fpw-review-error><p>No se puede revisar la cotización. Completa los datos señalados:</p><ul>';
		foreach ( $errors as $error ) { $body .= '<li>' . esc_html( $error ) . '</li>'; }
		$body .= '</ul></div>';
	}
	$header_draft = $draft;
	$header_work = $work;
	if ( $review ) {
		$header_draft['identity'] = fpw_read_draft_preview( $id )['projection']['identity'] ?? $draft['identity'];
		if ( is_array( $header_work ) ) { unset( $header_work['identity'] ); }
	}
	$header = fpw_workspace_request_header( $header_draft, $header_work, $version );
	// Only the redundant success notice disappears; failures and recovery messages remain.
	$notice_html = $review && 'Vista previa generada.' === ( $notice['title'] ?? '' ) ? '' : fpw_draft_notice_html( $notice );
	if ( $review ) {
		$header = str_replace( array( '<h1 ', '</h1>' ), array( '<h2 ', '</h2>' ), $header );
		$body = '<a class="fpw-button" href="' . esc_url( fpw_draft_screen_url( $id ) ) . '">Volver a ajustar</a><h1 class="fpw-preview-title">Vista previa</h1>' . $header . $notice_html . $body . '<div id="fpw-summary" tabindex="-1" class="fpw-workspace-preview">' . fpw_workspace_preview_html( $draft, $work, fpw_read_draft_preview( $id ) ) . '</div>';
		return fpw_workspace_shell( $body, $id, $projection['total'] ?? null );
	}
	$body = ( $editing ? '<a class="fpw-button" href="' . esc_url( fpw_quotation_view_url( $id, $base ) ) . '">Ver versión enviada</a><h2>Editar cotización · Versión ' . ( $base + 1 ) . '</h2>' : '' ) . $header . $notice_html . fpw_draft_identity_form( $draft, $work ) . $body;
	$preserve_post = $review_invalid && is_array( $_POST['fpw_work'] ?? null );
	$unsaved = $unsaved || $preserve_post;
	$calculation = array( 'tax' => fpw_quotation_offer_tax_config( $id ), 'dispatch' => fpw_draft_requests_dispatch( $draft ), 'savedAmount' => $work['dispatch_amount'] ?? null, 'conditions' => $work['dispatch_conditions'] ?? null );
	$body .= '<div class="fpw-workspace-editor"><form id="fpw-work-form" novalidate data-fpw-calculation="' . esc_attr( wp_json_encode( $calculation ) ) . '" data-fpw-work data-fpw-has-saved="' . ( null !== $work ? '1' : '0' ) . '"' . ( $unsaved ? ' data-fpw-unsaved="1"' : '' ) . ' method="post" action="' . esc_url( fpw_draft_screen_url( $id ) ) . '"><input type="hidden" name="fpw_work_revision" value="' . fpw_draft_work_revision( $work ) . '">'
		. wp_nonce_field( fpw_draft_save_action( $id ), 'fpw_draft_nonce', true, false ) . wp_nonce_field( fpw_draft_preview_action( $id ), 'fpw_preview_nonce', true, false ) . wp_nonce_field( fpw_draft_refresh_action( $id ), 'fpw_refresh_nonce', true, false ) . '<fieldset><legend class="screen-reader-text">Preparar cotización</legend><section id="fpw-products" tabindex="-1"><h2>Productos a cotizar</h2><p class="fpw-muted">Revisa cantidades y precios netos por unidad.</p>';
	foreach ( $draft['items'] as $index => $line ) { $body .= fpw_workspace_product_html( $line, $values['lines'][ $index ], $index, $projection['lines'][ $index ]['line_total'] ?? null, false ); }
	$body .= '</section><section class="fpw-workspace-dispatch"><h2>' . ( fpw_draft_requests_dispatch( $draft ) ? 'Despacho' : 'Retiro - Sin Despacho' ) . '</h2>' . ( fpw_draft_requests_dispatch( $draft ) ? fpw_draft_dispatch_html( true, $values['destination'], $values['dispatch_amount'], fpw_draft_dispatch_stale( $work ), (string) ( $draft['destination']['references'] ?? '' ) ) : fpw_draft_pickup_html( $values['pickup_location'] ) ) . '<p data-fpw-dispatch-warning hidden></p></section><label>Vigencia · días desde la aprobación' . fpw_draft_validity_input_html( 'fpw_work[validity_days]', $values['validity_days'] ) . '</label></fieldset></form>';
	$body .= '<aside id="fpw-summary" tabindex="-1" class="fpw-workspace-summary"><h2>Resumen de cotización</h2><p data-fpw-save-state class="fpw-muted" role="status"></p><dl>';
	foreach ( array( 'subtotal' => 'Productos netos', 'dispatch' => 'Despacho neto', 'tax' => 'IVA', 'total' => 'Total' ) as $key => $label ) {
		if ( 'dispatch' === $key && ! fpw_draft_requests_dispatch( $draft ) ) { continue; }
		$body .= '<div' . ( 'total' === $key ? ' class="fpw-grand-total"' : '' ) . '><dt>' . $label . '</dt><dd data-fpw-amount="' . $key . '">' . fpw_workspace_money( $projection[ $key ] ?? null ) . '</dd></div>';
	}
	$body .= '</dl>';
	$body .= '<button type="submit" name="fpw_work_save" value="1" form="fpw-work-form" class="fpw-secondary">Guardar borrador</button>'
			. ( $unsaved ? '<a class="fpw-button fpw-secondary" data-fpw-discard-work href="' . esc_url( fpw_draft_screen_url( $id ) ) . '">Descartar cambios sin guardar</a>' : '<button type="reset" form="fpw-work-form" data-fpw-discard class="fpw-secondary">Descartar cambios sin guardar</button>' )
			. '<button type="submit" name="fpw_work_preview" value="1" form="fpw-work-form" data-fpw-preview>Revisar cotización</button>';
	$body .= '<noscript><p>Guarda el borrador antes de cambiar de página.</p></noscript></aside></div>'
		. fpw_workspace_history( $draft );
	$body = fpw_workspace_review_fields( $body, $review_invalid, $preserve_post, fpw_draft_dispatch_stale( $validation_work ) );
	return fpw_workspace_shell( $body, $id, $projection['total'] ?? null, true );
}
