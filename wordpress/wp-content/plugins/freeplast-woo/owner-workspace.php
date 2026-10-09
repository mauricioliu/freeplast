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
	wp_enqueue_style( 'fpw-workspace', plugins_url( 'assets/owner-workspace.css', __FILE__ ), array(), '1.12.4' );
	wp_enqueue_script( 'fpw-workspace', plugins_url( 'assets/owner-workspace.js', __FILE__ ), array(), '1.12.4', true );
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
	$nav = $editable ? '<nav class="fpw-workspace-dock" aria-label="Preparar cotización"><a href="#fpw-summary">Total guardado<strong data-fpw-dock-total>' . fpw_workspace_money( $total ) . '</strong></a><button type="submit" name="fpw_work_save" value="1" form="fpw-work-form" data-fpw-dock-save>Guardar borrador</button><button type="submit" name="fpw_work_preview" value="1" form="fpw-work-form" data-fpw-preview data-fpw-dock-preview hidden>Revisar cotización</button></nav>' : '';
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

/** Pricing actions submit the editor's actual controls, even without JavaScript. */
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
	foreach ( array( 'destination', 'dispatch_amount', 'validity_days' ) as $key ) { $changed = $changed || $posted[ $key ] !== $stored[ $key ]; }
	foreach ( $stored['lines'] as $index => $line ) { $changed = $changed || $posted['lines'][ $index ]['quantity'] !== $line['quantity'] || $posted['lines'][ $index ]['price'] !== $line['price']; }
	if ( ! $changed ) { return true; }
	fpw_pending_draft_outcome( array( 'order_id' => $id, 'result' => array( 'state' => 'workspace-unsaved', 'values' => $posted ) ) );
	return false;
}

function fpw_render_workspace(): void {
	if ( ! fpw_can_manage_quotations() ) { fpw_die_draft_forbidden(); }
	$search = is_string( $_GET['q'] ?? null ) ? mb_substr( sanitize_text_field( wp_unslash( $_GET['q'] ) ), 0, 100 ) : '';
	$stage = is_string( $_GET['stage'] ?? null ) && isset( fpw_workspace_stages()[ $_GET['stage'] ] ) ? $_GET['stage'] : 'pendientes';
	$sort = 'oldest' === ( $_GET['sort'] ?? '' ) ? 'ASC' : 'DESC';
	$page = is_scalar( $_GET['paged'] ?? null ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	$result = fpw_workspace_query( $search, $stage, $sort, $page );
	$body = '<h1>Solicitudes de clientes</h1><nav class="fpw-request-views" aria-label="Ver solicitudes">';
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
			$body .= '<article data-fpw-request="' . $id . '" class="fpw-workspace-row"><div><h2><a href="' . esc_url( $url ) . '">' . esc_html( $company ?: $reference ) . '</a></h2><p class="fpw-muted">' . esc_html( $reference ) . ( null !== $received ? ' · ' . esc_html( wp_date( 'd/m/Y', (int) $received ) ) : '' ) . '</p></div>'
				. fpw_workspace_request_state_html( $id, $version, $offer['unavailable'] ) . '<div class="fpw-workspace-row-total"><span>' . ( $offer['unavailable'] ? 'Versión no disponible' : ( $version ? 'Total aprobado' : 'Total guardado' ) ) . '</span><strong>' . fpw_workspace_money( $projection['total'] ?? null ) . '</strong></div><a class="fpw-button" href="' . esc_url( $url ) . '">Abrir<span class="screen-reader-text"> ' . esc_html( $reference ) . '</span></a></article>';
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
	$pallets = null === $units ? 'Unidades por pallet por confirmar.' : intdiv( $qty, $units ) . ( 1 === intdiv( $qty, $units ) ? ' pallet completo + ' : ' pallets completos + ' ) . ( $qty % $units ) . ' un. · ' . $units . ' un. por pallet';
	$photo = $product && $product->get_image_id() ? $product->get_image( 'woocommerce_thumbnail', array( 'alt' => '', 'loading' => 'lazy' ) ) : '<span class="fpw-photo-missing">Sin foto</span>';
	$options = '';
	foreach ( $line['options'] ?? array() as $option ) { $options .= '<span>' . esc_html( wc_attribute_label( $option['key'] ) . ': ' . $option['value'] ) . '</span> '; }
	$price = $value['price'] ?? $value['suggested'];
	$suggested = $value['suggested'];
	$origin = null === $value['price'] ? ( null === $suggested ? 'Precio por definir.' : 'Precio de lista, aún sin guardar.' ) : ( 'suggested' === $value['price_source'] ? 'Precio guardado de la lista.' : 'Precio guardado para esta oferta.' );
	$prefix = 'fpw_work[lines][' . $index . ']';
	return '<section class="fpw-workspace-product"><div class="fpw-product-heading"><div class="fpw-product-photo">' . $photo . '</div><div><h3>' . esc_html( $line['name'] ) . '</h3><p>' . $options . '</p><p class="fpw-muted">Pedido: ' . (int) $line['quantity'] . ' unidades</p></div></div>'
		. '<div class="fpw-product-inputs"><label>Cantidad <span class="screen-reader-text">de ' . esc_html( $line['name'] ) . '</span><span class="fpw-muted">unidades</span>' . fpw_draft_quantity_input_html( $prefix . '[quantity]', $qty ) . '</label><label class="fpw-offered-price">Precio ofrecido <span class="screen-reader-text">de ' . esc_html( $line['name'] ) . '</span><span class="fpw-muted">CLP netos / unidad</span>' . fpw_draft_amount_input_html( $prefix . '[price]', $price ) . '</label></div>'
		. '<p class="fpw-muted" data-fpw-pallets="' . $index . '" data-units="' . (int) $units . '">' . esc_html( $pallets ) . '</p><p class="fpw-muted" data-fpw-origin="' . $index . '">' . $origin . '</p>'
		. fpw_workspace_references_html( $reference, $index, $readonly, $suggested )
		. '<div class="fpw-line-total"><span>Neto guardado</span><strong>' . fpw_workspace_money( $line_total ) . '</strong></div></section>';
}

function fpw_workspace_history( array $draft ): string {
	$history = fpw_sales_history_for_rut( fpw_sales_normalize_rut( (string) ( $draft['identity']['rut'] ?? '' ) ) );
	$html = '<details id="fpw-history" class="fpw-workspace-history"><summary>Historial de compras</summary>';
	if ( ! $history || ! $history['sales'] ) {
		return $html . '<p><strong>Sin historial asociado.</strong> No hay compras vinculadas al RUT de esta solicitud. Esto no indica que el cliente sea nuevo.</p></details>';
	}
	$html .= '<p>Registros importados asociados por RUT. No son solicitudes ni cotizaciones. El detalle por producto no está disponible en la fuente importada.</p><table><caption>Compras registradas</caption><thead><tr><th scope="col">Fecha</th><th scope="col">Registro</th><th scope="col">Total registrado</th></tr></thead><tbody>';
	foreach ( array_slice( $history['sales'], 0, 50 ) as $sale ) {
		$html .= '<tr><td>' . esc_html( $sale['date'] ) . '</td><th scope="row">' . esc_html( $sale['id'] ) . '</th><td class="fpw-numeric">' . esc_html( fpw_sales_format_clp( $sale['total'] ?? null ) ) . '</td></tr>';
	}
	$html .= '</tbody></table><p class="fpw-muted">Mostrando hasta 50 de ' . count( $history['sales'] ) . ' registros.';
	if ( is_array( $history['freshness'] ?? null ) ) { $html .= ' Última carga: ' . esc_html( wp_date( 'd/m/Y', (int) $history['freshness']['at'] ) ) . ' · ' . esc_html( $history['freshness']['filename'] ) . '.'; }
	return $html . '</p><a href="#fpw-products">Volver a productos</a></details>';
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
	$values = fpw_draft_work_values( $draft, $work );
	$pending = fpw_pending_draft_outcome();
	$unsaved = ! $version && $id === ( $pending['order_id'] ?? null ) && 'workspace-unsaved' === ( $pending['result']['state'] ?? '' );
	if ( $unsaved ) {
		$posted = $pending['result']['values'];
		foreach ( array( 'destination', 'dispatch_amount', 'validity_days' ) as $key ) { $values[ $key ] = $posted[ $key ]; }
		foreach ( $posted['lines'] as $index => $line ) { $values['lines'][ $index ] = array_replace( $values['lines'][ $index ], $line ); }
	}
	$body = '<a class="fpw-back" href="' . esc_url( fpw_workspace_url() ) . '">Solicitudes</a><h1>' . esc_html( $draft['identity']['company'] ?: $draft['reference'] ) . '</h1><p class="fpw-muted">Solicitud ' . esc_html( $draft['reference'] ) . ' · ' . esc_html( wp_date( 'd/m/Y', (int) $draft['received_at'] ) ) . '</p>' . fpw_draft_notice_html( $notice );
	$blocked = $id === ( $pending['order_id'] ?? null ) && in_array( $pending['result']['state'] ?? '', array( 'workspace-unsaved', 'workspace-invalid', 'conflict' ), true );
	$review = ! $blocked && ( '1' === ( $_GET['review'] ?? '' ) || isset( $_POST['fpw_work_preview'] ) || isset( $_POST['fpw_work_approve'] ) );
	if ( $review ) {
		$body .= '<a class="fpw-button" href="' . esc_url( fpw_draft_screen_url( $id ) ) . '">' . ( $version ? 'Volver a la cotización' : 'Volver a ajustar' ) . '</a><div id="fpw-summary" tabindex="-1" class="fpw-workspace-preview">' . ( $version ? fpw_workspace_issued_html( $version ) : fpw_workspace_preview_html( $draft, $work, fpw_read_draft_preview( $id ) ) ) . '</div>';
		return fpw_workspace_shell( $body, $id, $projection['total'] ?? null );
	}
	$body .= '<p class="fpw-contact-line">' . esc_html( $draft['identity']['name'] ) . ' · ' . ( fpw_draft_requests_dispatch( $draft ) ? 'Con despacho' : 'Sin despacho' ) . ' · <a href="#fpw-original">Ver solicitud</a></p>';
	$contact = '<details id="fpw-original" class="fpw-original"><summary>Datos de contacto y solicitud original</summary><dl>' . fpw_draft_facts_html( $draft['identity'], $draft['destination'], fpw_draft_requests_dispatch( $draft ) ) . fpw_draft_provenance_facts_html( $draft['destination'] ) . '</dl>' . fpw_draft_submitted_details_html( $draft['submitted_details'] ?? array() ) . '<a href="' . esc_url( fpw_draft_request_admin_url( $id ) ) . '">Ver solicitud completa</a></details>';
	if ( $version ) {
		return fpw_workspace_shell( $body . '<div id="fpw-summary" class="fpw-workspace-preview" tabindex="-1">' . fpw_workspace_issued_html( $version ) . '</div>' . $contact . fpw_workspace_history( $draft ), $id, $projection['total'] );
	}
	$body .= '<div class="fpw-workspace-editor"><form id="fpw-work-form" data-fpw-work data-fpw-has-saved="' . ( null !== $work ? '1' : '0' ) . '"' . ( $unsaved ? ' data-fpw-unsaved="1"' : '' ) . ' method="post" action="' . esc_url( fpw_draft_screen_url( $id ) ) . '"><input type="hidden" name="fpw_work_revision" value="' . fpw_draft_work_revision( $work ) . '">'
		. wp_nonce_field( fpw_draft_save_action( $id ), 'fpw_draft_nonce', true, false ) . wp_nonce_field( fpw_draft_preview_action( $id ), 'fpw_preview_nonce', true, false ) . wp_nonce_field( fpw_draft_refresh_action( $id ), 'fpw_refresh_nonce', true, false ) . '<fieldset><legend class="screen-reader-text">Preparar cotización</legend><section id="fpw-products" tabindex="-1"><h2>Productos a cotizar</h2><p class="fpw-muted">Revisa cantidades y precios netos por unidad.</p>';
	foreach ( $draft['items'] as $index => $line ) { $body .= fpw_workspace_product_html( $line, $values['lines'][ $index ], $index, $projection['lines'][ $index ]['line_total'] ?? null, false ); }
	$body .= '</section><section class="fpw-workspace-dispatch"><h2>Despacho</h2>' . ( fpw_draft_requests_dispatch( $draft ) ? fpw_draft_dispatch_html( true, $values['destination'], $values['dispatch_amount'], fpw_draft_dispatch_stale( $work ) ) : '<p>Sin despacho solicitado. No incluye flete.</p>' ) . '</section><label>Vigencia · días desde la aprobación' . fpw_draft_validity_input_html( 'fpw_work[validity_days]', $values['validity_days'] ) . '</label></fieldset></form>';
	$body .= '<aside id="fpw-summary" tabindex="-1" class="fpw-workspace-summary"><h2>Resumen de cotización</h2><p data-fpw-save-state class="fpw-muted" role="status">' . ( $unsaved ? 'Cambios sin guardar. ' : '' ) . ( null === $work ? 'Guarda para confirmar los importes.' : 'Importes guardados · revisión ' . fpw_draft_work_revision( $work ) ) . '</p><dl>';
	foreach ( array( 'subtotal' => 'Productos netos', 'dispatch' => 'Despacho neto', 'tax' => 'IVA', 'total' => 'Total' ) as $key => $label ) {
		if ( 'dispatch' === $key && ! fpw_draft_requests_dispatch( $draft ) ) { continue; }
		$body .= '<div' . ( 'total' === $key ? ' class="fpw-grand-total"' : '' ) . '><dt>' . $label . '</dt><dd>' . fpw_workspace_money( $projection[ $key ] ?? null ) . '</dd></div>';
	}
	$body .= '</dl>';
	if ( ! empty( $projection['missing'] ) ) { $body .= '<p>Por completar:</p><ul>' . implode( '', array_map( static fn( $item ) => '<li>' . esc_html( $item ) . '</li>', $projection['missing'] ) ) . '</ul>'; }
	$body .= '<button type="submit" name="fpw_work_save" value="1" form="fpw-work-form" class="fpw-secondary">Guardar borrador</button>'
			. ( $unsaved ? '<a class="fpw-button fpw-secondary" data-fpw-discard-work href="' . esc_url( fpw_draft_screen_url( $id ) ) . '">Descartar cambios sin guardar</a>' : '<button type="reset" form="fpw-work-form" data-fpw-discard class="fpw-secondary">Descartar cambios sin guardar</button>' )
			. '<button type="submit" name="fpw_work_preview" value="1" form="fpw-work-form" data-fpw-preview>Revisar cotización</button><p class="fpw-muted">Guarda los cambios y revisa la oferta. Solo se envía al aprobar.</p>'
			. '<details><summary>Opciones de precios</summary><p>Refresca desde la lista actual conservando ajustes manuales. Guarda o descarta antes cualquier cambio pendiente.</p><button type="submit" name="fpw_price_refresh" value="1" form="fpw-work-form" data-fpw-refresh class="fpw-secondary">Refrescar precios</button></details>';
	$body .= '<noscript><p>Guarda el borrador antes de cambiar de página.</p></noscript></aside></div>'
		. $contact . ( current_user_can( 'manage_woocommerce' ) ? '<details><summary>Mantenimiento de precios</summary><a class="fpw-button" href="' . esc_url( fpw_price_screen_url() ) . '">Abrir lista de precios</a></details>' : '' )
		. ( fpw_draft_requests_dispatch( $draft ) ? '<details class="fpw-distance"><summary>Consultar distancia de despacho</summary>' . fpw_draft_distance_section_html( $draft, $work ) . '</details>' : '' ) . fpw_workspace_history( $draft );
	return fpw_workspace_shell( $body, $id, $projection['total'] ?? null, true );
}
