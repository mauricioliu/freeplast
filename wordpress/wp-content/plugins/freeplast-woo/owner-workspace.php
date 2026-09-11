<?php
/** Owner workspace A. Presentation composes the existing draft/preview/issuance interfaces. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'admin_menu', 'fpw_workspace_register' );
function fpw_workspace_register(): void {
	add_submenu_page( 'woocommerce', 'Cotizaciones', 'Cotizaciones', 'manage_woocommerce', 'fpw-quotations', 'fpw_render_workspace' );
}

function fpw_workspace_url(): string { return admin_url( 'admin.php?page=fpw-quotations' ); }

/** Assets and chrome changes are confined to these two owner surfaces. */
add_action( 'admin_enqueue_scripts', 'fpw_workspace_assets' );
function fpw_workspace_assets(): void {
	$page = $_GET['page'] ?? '';
	if ( ! current_user_can( 'manage_woocommerce' ) || ( 'fpw-quotations' !== $page && ( FPW_DRAFT_SCREEN !== $page || '1' !== ( $_GET['workspace'] ?? '' ) ) ) ) { return; }
	wp_enqueue_style( 'fpw-workspace', plugins_url( 'assets/owner-workspace.css', __FILE__ ), array(), '1.11.0' );
	wp_enqueue_script( 'fpw-workspace', plugins_url( 'assets/owner-workspace.js', __FILE__ ), array(), '1.11.0', true );
	wp_add_inline_style( 'fpw-workspace', '@font-face{font-family:FPWManrope;src:url("' . esc_url( get_theme_file_uri( 'assets/fonts/manrope.woff2' ) ) . '") format("woff2");font-weight:200 800;font-display:swap}' );
}
add_filter( 'admin_title', static function ( $title ) {
	return current_user_can( 'manage_woocommerce' ) && FPW_DRAFT_SCREEN === ( $_GET['page'] ?? '' ) && '1' === ( $_GET['workspace'] ?? '' ) ? 'Cotización · Freeplast' : $title;
} );
add_filter( 'admin_body_class', static function ( $classes ) {
	if ( current_user_can( 'manage_woocommerce' ) && ( 'fpw-quotations' === ( $_GET['page'] ?? '' ) || ( FPW_DRAFT_SCREEN === ( $_GET['page'] ?? '' ) && '1' === ( $_GET['workspace'] ?? '' ) ) ) ) { $classes .= ' fpw-workspace-page'; }
	return $classes;
} );

function fpw_workspace_shell( string $body, ?int $id = null, ?int $total = null ): string {
	$header = '<header class="fpw-workspace-header"><a href="' . esc_url( fpw_workspace_url() ) . '" aria-label="Freeplast · Cotizaciones"><img src="' . esc_url( plugins_url( 'assets/brand.webp', __FILE__ ) ) . '" alt="Freeplast" width="108" height="64"></a><span>Área del dueño</span><a href="' . esc_url( admin_url() ) . '">Administración</a></header>';
	$nav = '<nav class="fpw-workspace-dock" aria-label="Navegar cotizaciones"><a href="' . esc_url( fpw_workspace_url() ) . '">Bandeja</a>'
		. ( null !== $id ? '<a href="#fpw-summary">Resumen <strong data-fpw-dock-total>' . fpw_workspace_money( $total ) . '</strong></a>' : '' ) . '</nav>';
	return '<div class="fpw-workspace">' . $header . '<div class="fpw-workspace-body">' . $body . '</div>' . $nav . '</div>';
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
	$valid = is_array( $p ) && 1 === ( $p['schema'] ?? null ) && true === ( $p['complete'] ?? null ) && empty( $p['missing'] ) && is_array( $p['lines'] ?? null ) && ! empty( $p['lines'] )
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

function fpw_workspace_stages(): array {
	return array( 'all' => 'Todas', 'sent' => 'Por enviar', 'accepted' => 'Por aceptar', 'paid' => 'Pago pendiente', 'dispatched' => 'Despacho pendiente', 'complete' => 'Completas' );
}

/** Bounded SQL read model of our own receipt rows; no Woo status is treated as a commercial milestone.
 * JSON extraction keeps PDF blobs out of the result. Both MySQL and the native SQLite harness are exercised.
 */
function fpw_workspace_query( string $search, string $stage, string $direction, int $page ): array {
	global $wpdb;
	$source = " FROM {$wpdb->options} d LEFT JOIN {$wpdb->options} t ON t.option_name = CONCAT('fpw_tracking_', SUBSTR(d.option_name, 11)) LEFT JOIN {$wpdb->options} v ON v.option_name = CONCAT('fpw_quotation_', SUBSTR(d.option_name, 11))";
	$tracking = "CASE WHEN JSON_VALID(t.option_value) THEN t.option_value ELSE '{}' END";
	$version = "CASE WHEN JSON_VALID(v.option_value) THEN v.option_value ELSE '{}' END";
	$sent = "COALESCE(JSON_EXTRACT($version, '$.delivery.state'), '') IN ('accepted', '\"accepted\"')";
	$classification = "CASE WHEN NOT ($sent) THEN 'sent'";
	foreach ( array_keys( fpw_tracking_labels() ) as $key ) {
		$classification .= " WHEN COALESCE(JSON_EXTRACT($tracking, '$.events.$key'), 'null') = 'null' THEN '$key'";
	}
	$classification .= " ELSE 'complete' END";
	$where = " WHERE d.option_name REGEXP '^fpw_draft_[0-9]+$' AND JSON_VALID(d.option_value)";
	if ( '' !== $search ) {
		$like = '%' . $wpdb->esc_like( $search ) . '%';
		$where .= $wpdb->prepare( " AND (JSON_EXTRACT(d.option_value, '$.identity.company') LIKE %s OR JSON_EXTRACT(d.option_value, '$.reference') LIKE %s OR JSON_EXTRACT(d.option_value, '$.identity.rut') LIKE %s)", $like, $like, $like );
	}
	if ( 'all' !== $stage && isset( fpw_workspace_stages()[ $stage ] ) ) { $where .= $wpdb->prepare( " AND ($classification) = %s", $stage ); }
	$count = $wpdb->get_var( 'SELECT COUNT(*)' . $source . $where );
	if ( '' !== $wpdb->last_error ) { return array( 'error' => true, 'total' => 0, 'rows' => array(), 'page' => 1, 'pages' => 1 ); }
	$pages = max( 1, (int) ceil( (int) $count / 25 ) );
	$page = min( max( 1, $page ), $pages );
	$sort = 'ASC' === $direction ? 'ASC' : 'DESC';
	$rows = $wpdb->get_col( 'SELECT d.option_value' . $source . $where . " ORDER BY d.option_id $sort" . $wpdb->prepare( ' LIMIT %d OFFSET %d', 25, ( $page - 1 ) * 25 ) );
	return array( 'error' => '' !== $wpdb->last_error, 'total' => (int) $count, 'rows' => array_map( static fn( $raw ) => json_decode( $raw, true ), $rows ?? array() ), 'page' => $page, 'pages' => $pages );
}

function fpw_render_workspace(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) { fpw_die_draft_forbidden(); }
	$search = is_string( $_GET['q'] ?? null ) ? mb_substr( sanitize_text_field( wp_unslash( $_GET['q'] ) ), 0, 100 ) : '';
	$stage = is_string( $_GET['stage'] ?? null ) && isset( fpw_workspace_stages()[ $_GET['stage'] ] ) ? $_GET['stage'] : 'all';
	$sort = 'oldest' === ( $_GET['sort'] ?? '' ) ? 'ASC' : 'DESC';
	$page = is_scalar( $_GET['paged'] ?? null ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	$result = fpw_workspace_query( $search, $stage, $sort, $page );
	$body = '<h1>Cotizaciones</h1><p>Envío, aceptación del cliente, pago y despacho: cuatro hitos independientes.</p>'
		. '<form method="get" class="fpw-workspace-search"><input type="hidden" name="page" value="fpw-quotations"><input type="hidden" name="stage" value="' . esc_attr( $stage ) . '">'
		. '<label>Buscar por empresa, RUT o referencia<input type="search" name="q" maxlength="100" value="' . esc_attr( $search ) . '"></label>'
		. '<label>Ordenar<select name="sort"><option value="newest"' . ( 'DESC' === $sort ? ' selected' : '' ) . '>Más recientes primero</option><option value="oldest"' . ( 'ASC' === $sort ? ' selected' : '' ) . '>Más antiguas primero</option></select></label><button type="submit">Buscar</button></form><nav class="fpw-workspace-filters" aria-label="Filtrar por próximo hito pendiente">';
	foreach ( fpw_workspace_stages() as $key => $label ) {
		$body .= '<a href="' . esc_url( add_query_arg( array( 'stage' => $key, 'q' => $search, 'sort' => 'ASC' === $sort ? 'oldest' : 'newest' ), fpw_workspace_url() ) ) . '"' . ( $key === $stage ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
	}
	$body .= '</nav>';
	if ( $result['error'] ) {
		$body .= '<section class="fpw-workspace-empty"><h2>No se pudo cargar la bandeja</h2><p>No se modificó ninguna solicitud. Recarga la página para volver a intentarlo.</p></section>';
	} elseif ( ! $result['rows'] ) {
		$body .= '<section class="fpw-workspace-empty"><h2>' . ( '' !== $search || 'all' !== $stage ? 'No hay coincidencias' : 'Aún no hay borradores de cotización' ) . '</h2><a href="' . esc_url( fpw_workspace_url() ) . '">Ver todas las cotizaciones</a></section>';
	} else {
		$body .= '<p class="fpw-muted">' . $result['total'] . ( 1 === $result['total'] ? ' solicitud con borrador · página ' : ' solicitudes con borrador · página ' ) . $result['page'] . ' de ' . $result['pages'] . '</p><div class="fpw-workspace-inbox">';
		foreach ( $result['rows'] as $draft ) {
			$id = (int) ( $draft['order_id'] ?? 0 );
			$order = wc_get_order( $id );
			if ( ! $order ) { continue; }
			$offer = fpw_workspace_read_offer( $draft, fpw_read_draft_work( $id ) );
			$version = $offer['version'];
			$projection = $offer['projection'];
			$body .= '<article data-fpw-request="' . $id . '" class="fpw-workspace-row"><div><h2><a href="' . esc_url( fpw_draft_screen_url( $id ) ) . '">' . esc_html( $draft['identity']['company'] ?: $draft['reference'] ) . '</a></h2><p class="fpw-muted">' . esc_html( $draft['reference'] ) . ' · ' . esc_html( wp_date( 'd/m/Y', (int) $draft['received_at'] ) ) . '</p></div>'
				. fpw_milestones_html( fpw_commercial_events( $id, $version ) ) . '<div class="fpw-workspace-row-total"><span>' . ( $offer['unavailable'] ? 'Versión no disponible' : ( $version ? 'Total aprobado' : 'Total guardado' ) ) . '</span><strong>' . fpw_workspace_money( $projection['total'] ?? null ) . '</strong></div><a class="fpw-button" href="' . esc_url( fpw_draft_screen_url( $id ) ) . '">Abrir<span class="screen-reader-text"> ' . esc_html( $draft['reference'] ) . '</span></a></article>';
		}
		$body .= '</div><nav class="fpw-workspace-pagination" aria-label="Páginas de cotizaciones">';
		foreach ( array( -1 => 'Anterior', 1 => 'Siguiente' ) as $delta => $label ) {
			$next = $result['page'] + $delta;
			if ( $next >= 1 && $next <= $result['pages'] ) { $body .= '<a class="fpw-button" href="' . esc_url( add_query_arg( array( 'paged' => $next, 'q' => $search, 'stage' => $stage, 'sort' => 'ASC' === $sort ? 'oldest' : 'newest' ), fpw_workspace_url() ) ) . '">' . $label . '</a>'; }
		}
		$body .= '</nav>';
	}
	$body .= '<p class="fpw-muted">Las solicitudes anteriores sin borrador se conservan en <a href="' . esc_url( admin_url( 'edit.php?post_type=shop_order' ) ) . '">Solicitudes originales</a>. La aceptación del correo por el transporte no prueba que el cliente lo haya recibido.</p>';
	echo fpw_workspace_shell( $body );
}

/** Packaging is catalog information, not a volume-pricing policy. */
function fpw_workspace_product_html( array $line, array $value, int $index, ?int $line_total, bool $readonly ): string {
	$product = wc_get_product( (int) ( $line['variation_id'] ?: $line['product_id'] ) );
	$pack = $product ? $product->get_attribute( 'Unidades por pallet' ) : '';
	$units = ctype_digit( $pack ) && (int) $pack > 0 ? (int) $pack : null;
	$qty = (int) $value['quantity'];
	$pallets = null === $units ? 'Unidades por pallet por confirmar.' : intdiv( $qty, $units ) . ' pallets completos + ' . ( $qty % $units ) . ' un. · ' . $units . ' un. por pallet';
	$photo = $product && $product->get_image_id() ? $product->get_image( 'woocommerce_thumbnail', array( 'alt' => '', 'loading' => 'lazy' ) ) : '<span class="fpw-photo-missing">Sin foto</span>';
	$options = '';
	foreach ( $line['options'] ?? array() as $option ) { $options .= '<span>' . esc_html( wc_attribute_label( $option['key'] ) . ': ' . $option['value'] ) . '</span> '; }
	$price = $value['price'] ?? $value['suggested'];
	$suggested = $value['suggested'];
	$origin = $readonly ? 'Precio aprobado · ' . fpw_workspace_money( $price ) : fpw_draft_line_price_html( $value['price'], $suggested, $value['price_source'] );
	$prefix = 'fpw_work[lines][' . $index . ']';
	return '<section class="fpw-workspace-product"><div class="fpw-product-heading"><div class="fpw-product-photo">' . $photo . '</div><div><h3>' . esc_html( $line['name'] ) . '</h3><p>' . $options . '</p><p class="fpw-muted">Pedido: ' . (int) $line['quantity'] . ' unidades</p></div></div>'
		. '<div class="fpw-product-inputs"><label>Cantidad <span class="screen-reader-text">de ' . esc_html( $line['name'] ) . '</span><span class="fpw-muted">unidades</span>' . fpw_draft_quantity_input_html( $prefix . '[quantity]', $qty ) . '</label><label class="fpw-offered-price">Precio ofrecido <span class="screen-reader-text">de ' . esc_html( $line['name'] ) . '</span><span class="fpw-muted">CLP netos / unidad</span>' . fpw_draft_amount_input_html( $prefix . '[price]', $price ) . '</label></div>'
		. '<p class="fpw-muted">' . esc_html( $pallets ) . '</p><p class="fpw-muted">' . $origin . '</p>'
		. '<div class="fpw-list-reference"><div><span>Lista actual · neto por unidad</span><strong>' . fpw_workspace_money( $suggested ) . '</strong></div>'
		. ( null !== $suggested && ! $readonly ? '<button type="button" hidden data-fpw-apply-price="' . $index . '" data-price="' . (int) $suggested . '">Aplicar precio</button>' : '' ) . '</div>'
		. '<p class="fpw-muted">Tramos por volumen sin configurar. El precio de la oferta se decide manualmente.</p><div class="fpw-line-total"><span>Neto guardado de la línea</span><strong>' . fpw_workspace_money( $line_total ) . '</strong></div></section>';
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
	if ( ! $draft || ! $order ) { return fpw_workspace_shell( '<a href="' . esc_url( fpw_workspace_url() ) . '">Volver a cotizaciones</a>' . fpw_quote_draft_markup( $order, $draft, $work, $notice ) ); }
	$id = (int) $order->get_id();
	$offer = fpw_workspace_read_offer( $draft, $work );
	if ( $offer['unavailable'] ) {
		return fpw_workspace_shell( '<h1>Versión aprobada no disponible</h1><p>Sus importes guardados no se pueden leer. No se sustituyeron por valores del borrador. Solicita revisión técnica antes de continuar.</p><a class="fpw-button" href="' . esc_url( fpw_workspace_url() ) . '">Volver a cotizaciones</a>' );
	}
	$version = $offer['version'];
	$projection = $offer['projection'];
	$values = fpw_draft_work_values( $draft, $work );
	if ( $version ) {
		foreach ( $projection['lines'] as $index => $line ) {
			$values['lines'][ $index ]['quantity'] = $line['quantity'];
			$values['lines'][ $index ]['price'] = $line['price'];
			$values['lines'][ $index ]['price_source'] = 'approved';
		}
		$values['destination'] = $projection['destination'];
		$values['dispatch_amount'] = $projection['dispatch'];
		$values['validity_days'] = $projection['validity_days'];
	}
	$tracking_notice = fpw_tracking_notice();
	$body = '<a class="fpw-back" href="' . esc_url( fpw_workspace_url() ) . '">Cotizaciones</a><h1>' . esc_html( $draft['identity']['company'] ?: $draft['reference'] ) . '</h1><p class="fpw-muted">Solicitud ' . esc_html( $draft['reference'] ) . ' · ' . esc_html( wp_date( 'd/m/Y', (int) $draft['received_at'] ) ) . '</p>' . fpw_draft_notice_html( $notice );
	if ( ( $tracking_notice['order_id'] ?? 0 ) === $id ) { $body .= fpw_draft_notice_html( $tracking_notice ); }
	$review = '1' === ( $_GET['review'] ?? '' ) || isset( $_POST['fpw_work_preview'] ) || isset( $_POST['fpw_work_approve'] );
	if ( $review ) {
		$body .= '<a class="fpw-button" href="' . esc_url( fpw_draft_screen_url( $id ) ) . '">Volver a ajustar</a><div id="fpw-summary" tabindex="-1" class="fpw-workspace-preview">' . fpw_draft_preview_html( $draft, $work, fpw_read_draft_preview( $id ) ) . fpw_quotation_version_section_html( $version ) . '</div>';
		return fpw_workspace_shell( $body, $id, $projection['total'] ?? null );
	}
	$body .= '<p>' . esc_html( $draft['identity']['name'] ) . ' · ' . ( fpw_draft_requests_dispatch( $draft ) ? 'Con despacho' : 'Sin despacho' ) . '</p><details class="fpw-original"><summary>Datos de contacto y solicitud original</summary><dl>' . fpw_draft_facts_html( $draft['identity'], $draft['destination'], fpw_draft_requests_dispatch( $draft ) ) . fpw_draft_provenance_facts_html( $draft['destination'] ) . '</dl>' . fpw_draft_submitted_details_html( $draft['submitted_details'] ?? array() ) . '<a href="' . esc_url( fpw_draft_request_admin_url( $id ) ) . '">Ver solicitud completa</a></details>'
		. '<section class="fpw-workspace-tracking"><h2>Seguimiento comercial</h2>' . fpw_milestones_html( fpw_commercial_events( $id, $version ) ) . '<p class="fpw-muted">Enviada indica aceptación del correo por el transporte, no recepción ni aceptación del cliente.</p>' . fpw_tracking_form_html( $id ) . '</section>';
	if ( $version ) { $body .= fpw_quotation_version_section_html( $version ) . '<p class="fpw-workspace-notice">Oferta aprobada: sus importes ya no se editan. El seguimiento comercial sigue disponible.</p>'; }
	$body .= '<div class="fpw-workspace-editor"><form id="fpw-work-form" data-fpw-work method="post" action="' . esc_url( fpw_draft_screen_url( $id ) ) . '"><input type="hidden" name="fpw_work_save" value="1"><input type="hidden" name="fpw_work_revision" value="' . fpw_draft_work_revision( $work ) . '">'
		. wp_nonce_field( fpw_draft_save_action( $id ), 'fpw_draft_nonce', true, false ) . '<fieldset' . ( $version ? ' disabled' : '' ) . '><legend class="screen-reader-text">Preparar cotización</legend><section id="fpw-products" tabindex="-1"><h2>Productos y precios</h2><p class="fpw-muted">CLP netos por unidad. Guardar actualiza los importes del resumen; no aprueba ni envía.</p>';
	foreach ( $draft['items'] as $index => $line ) { $body .= fpw_workspace_product_html( $line, $values['lines'][ $index ], $index, $projection['lines'][ $index ]['line_total'] ?? null, null !== $version ); }
	$body .= '</section><section class="fpw-workspace-dispatch"><h2>Despacho</h2>' . fpw_draft_dispatch_html( fpw_draft_requests_dispatch( $draft ), $values['destination'], $values['dispatch_amount'], ! $version && fpw_draft_dispatch_stale( $work ) ) . '</section><label>Vigencia · días desde la aprobación' . fpw_draft_validity_input_html( 'fpw_work[validity_days]', $values['validity_days'] ) . '</label></fieldset></form>';
	$body .= '<aside id="fpw-summary" tabindex="-1" class="fpw-workspace-summary"><h2>Resumen de tu propuesta</h2><p data-fpw-save-state class="fpw-muted" role="status">Importes ' . ( $version ? 'aprobados' : 'del trabajo guardado · revisión ' . fpw_draft_work_revision( $work ) ) . '</p><dl>';
	foreach ( array( 'subtotal' => 'Productos netos', 'dispatch' => 'Despacho neto', 'tax' => 'IVA', 'total' => 'Total' ) as $key => $label ) {
		if ( 'dispatch' === $key && ! fpw_draft_requests_dispatch( $draft ) ) { continue; }
		$body .= '<div' . ( 'total' === $key ? ' class="fpw-grand-total"' : '' ) . '><dt>' . $label . '</dt><dd>' . fpw_workspace_money( $projection[ $key ] ?? null ) . '</dd></div>';
	}
	$body .= '</dl>';
	if ( ! empty( $projection['missing'] ) ) { $body .= '<p>Por completar:</p><ul>' . implode( '', array_map( static fn( $item ) => '<li>' . esc_html( $item ) . '</li>', $projection['missing'] ) ) . '</ul>'; }
	if ( ! $version ) {
		$body .= '<button type="submit" form="fpw-work-form">Guardar borrador</button><button type="reset" form="fpw-work-form" class="fpw-secondary">Descartar cambios sin guardar</button>'
			. '<form method="post" data-fpw-preview action="' . esc_url( fpw_draft_screen_url( $id ) ) . '"><input type="hidden" name="fpw_work_preview" value="1">' . wp_nonce_field( fpw_draft_preview_action( $id ), 'fpw_preview_nonce', true, false ) . '<button type="submit" class="fpw-secondary">Vista previa</button></form><p class="fpw-muted">Guardar no aprueba ni envía. La aprobación es una acción separada en la vista previa.</p>'
			. '<details><summary>Actualizar sugerencias</summary><p>Refresca desde la lista actual conservando ajustes manuales. No guarda cambios sin enviar de este formulario.</p><form method="post" data-fpw-refresh action="' . esc_url( fpw_draft_screen_url( $id ) ) . '"><input type="hidden" name="fpw_price_refresh" value="1">' . wp_nonce_field( fpw_draft_refresh_action( $id ), 'fpw_refresh_nonce', true, false ) . '<button type="submit" class="fpw-secondary">Refrescar precios</button></form></details>';
	}
	$body .= '<a class="fpw-button" href="' . esc_url( add_query_arg( 'review', '1', fpw_draft_screen_url( $id ) ) ) . '">Ver vista previa guardada</a><a href="' . esc_url( fpw_price_screen_url() ) . '">Mantenedor de precios</a></aside></div>'
		. ( fpw_draft_requests_dispatch( $draft ) ? '<details class="fpw-distance"><summary>Consultar distancia de despacho</summary>' . fpw_draft_distance_section_html( $draft, $work ) . '</details>' : '' ) . fpw_workspace_history( $draft );
	return fpw_workspace_shell( $body, $id, $projection['total'] ?? null );
}
