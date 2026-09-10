<?php
/**
 * Borrador privado de cotización — issue #50, corte 1 de #49.
 *
 * One durably received Quote Request keeps exactly ONE initial draft. The
 * durable relationship is a dedicated unique options-table row,
 * fpw_draft_<order_id>, written as a plain INSERT at
 * woocommerce_checkout_order_created — the same durable-binding pattern as the
 * attempt lookup rows (unique option_name: the second insert loses, so double
 * activation, repeated processing and recovery after a lost response can never
 * create another draft; a new legitimate request has its own order id and its
 * own row). Drafts are never rewritten by later runs.
 *
 * The snapshot is built only from the native record the request persisted:
 * its items, chosen options and quantities, its identity and destination, its
 * Submitted Details and its attempt identity. What the record does not carry
 * yet — prices, purchase history, dispatch estimate — is named PENDING, never
 * a zero price and never a customer verdict ("sin historial" is not this
 * screen's word).
 *
 * The owner notice stays the quotes extension's single admin email: the draft
 * link rides the adapter's own request-email.php template for $sent_to_admin —
 * no second notification surface is registered, and folded attempts (whose
 * notification hook is already removed) never re-fire it. The link opens a
 * private wp-admin screen whose only key is the manage_woocommerce capability
 * (Ventas' approved caps do not include it): knowing the link, the request id
 * or a nonce grants nothing. Cut 1 renders GET-only — there is no state
 * change, hence no CSRF surface; later cuts must add nonces with their actions.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'FPW_DRAFT_PREFIX', 'fpw_draft_' );
define( 'FPW_DRAFT_SCREEN', 'fpw-quote-draft' );

/** The private screen's address for one request's draft. */
function fpw_draft_screen_url( int $order_id ): string {
	return admin_url( 'admin.php?page=' . FPW_DRAFT_SCREEN . '&request=' . $order_id );
}

/** The draft row name of one request: unique option_name → one draft, ever. */
function fpw_draft_row_name( int $order_id ): string {
	return FPW_DRAFT_PREFIX . $order_id;
}

/** The stored initial draft of one request, read straight from the database — never through the per-request options cache. */
function fpw_read_request_draft( int $order_id ): ?array {
	if ( $order_id <= 0 ) { return null; }
	global $wpdb;
	$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", fpw_draft_row_name( $order_id ) ) );
	if ( ! is_string( $raw ) || '' === $raw ) { return null; }
	$payload = json_decode( $raw, true );
	return is_array( $payload ) ? $payload : null;
}

/**
 * The receipt snapshot, built ONLY from the native record the request
 * persisted. Deliberately no prices: the record is unpriced by design
 * (ADR-0001), and a missing value must never masquerade as zero.
 *
 * @return array The draft payload (schema 1).
 */
function fpw_build_request_draft_payload( $order ): array {
	$created = $order->get_date_created();
	$items = array();
	foreach ( $order->get_items() as $item ) {
		$items[] = array(
			'name'         => (string) $item->get_name(),
			'product_id'   => (int) $item->get_product_id(),
			'variation_id' => (int) $item->get_variation_id(),
			'quantity'     => (int) $item->get_quantity(),
			'options'      => fpw_draft_line_options( $item ),
		);
	}
	return array(
		'schema'            => 1,
		'order_id'          => (int) $order->get_id(),
		'reference'         => (string) $order->get_order_number(),
		'received_at'       => $created ? (int) $created->getTimestamp() : time(),
		'attempt'           => (string) $order->get_meta( '_fpw_attempt' ),
		'identity'          => array(
			'name'    => (string) $order->get_billing_first_name(),
			'company' => (string) $order->get_billing_company(),
			'rut'     => (string) $order->get_meta( '_billing_fp_rut' ),
			'giro'    => (string) $order->get_meta( '_billing_fp_giro' ),
			'phone'   => (string) $order->get_billing_phone(),
			'email'   => (string) $order->get_billing_email(),
		),
		'destination'       => array(
			'dispatch' => (string) $order->get_meta( '_billing_fp_dispatch' ),
			'address'  => (string) $order->get_meta( '_billing_fp_address' ),
		),
		'items'             => $items,
		'submitted_details' => $order->get_meta( '_fp_submitted_details' ),
		'enrichment'        => array( 'prices' => 'pending', 'history' => 'pending', 'dispatch' => 'pending' ),
	);
}

/** The line's chosen options as the record persisted them: public meta only — internal keys and non-scalar values stay out of the snapshot. */
function fpw_draft_line_options( $item ): array {
	$collected = array();
	foreach ( $item->get_meta_data() as $meta ) {
		$data = ( is_object( $meta ) && method_exists( $meta, 'get_data' ) ) ? $meta->get_data() : array();
		$key   = (string) ( $data['key'] ?? '' );
		$value = $data['value'] ?? null;
		if ( '' === $key || str_starts_with( $key, '_' ) || ! is_scalar( $value ) ) { continue; }
		$collected[] = array( 'key' => $key, 'value' => (string) $value );
	}
	return $collected;
}

/**
 * Create the request's initial draft — once. A plain INSERT against the unique
 * option_name decides: the winner stores the receipt snapshot, every later
 * caller loses and changes nothing. A snapshot failure reports cleanly and
 * leaves no partial row: the checkout that received the request must never
 * break, and the request stays readable in its native record.
 *
 * @return bool Whether THIS call created the draft.
 */
function fpw_create_request_draft( $order ): bool {
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) { return false; }
	if ( 'yes' !== (string) $order->get_meta( '_fp_request' ) ) { return false; }
	$order_id = (int) $order->get_id();
	if ( $order_id <= 0 || fpw_read_request_draft( $order_id ) ) { return false; }
	try {
		$payload = fpw_build_request_draft_payload( $order );
	} catch ( Throwable ) {
		return false;
	}
	return fpw_insert_draft_row( $order_id, $payload );
}

/** The durable write: a plain INSERT; an existing row is never rewritten. The expected duplicate-key error is suppressed — the defeat is the one-draft invariant, not a fault. */
function fpw_insert_draft_row( int $order_id, array $payload ): bool {
	global $wpdb;
	$was_suppressed = $wpdb->suppress_errors();
	$result = $wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )",
			fpw_draft_row_name( $order_id ),
			wp_json_encode( $payload )
		)
	);
	$wpdb->suppress_errors( $was_suppressed );
	return false !== $result && null !== $result;
}

/** The draft is born at the durable receipt moment — before any email renders, so the owner notice can point at it. */
add_action( 'woocommerce_checkout_order_created', 'fpw_create_request_draft', 5, 1 );

/** The private screen: unlisted (the owner notice's link is the access), keyed on the owner capability. */
add_action( 'admin_menu', 'fpw_quote_draft_register_screen' );
function fpw_quote_draft_register_screen(): void {
	add_submenu_page( null, 'Borrador de cotización', 'Borrador de cotización', 'manage_woocommerce', FPW_DRAFT_SCREEN, 'fpw_render_quote_draft_screen' );
}

/** The uniform denial: private to the owner, stated in Spanish, 403 — attributable to permissions, never to a nonce. */
function fpw_die_draft_forbidden(): void {
	wp_die( 'Este borrador de cotización es privado del dueño: requiere una sesión con permisos de administración de WooCommerce.', '', array( 'response' => 403 ) );
}

/** The screen callback: capability first, then resolve honestly — the request, its draft, or a state that invents nothing. */
function fpw_render_quote_draft_screen(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) { fpw_die_draft_forbidden(); }
	$order_id = isset( $_GET['request'] ) ? absint( wp_unslash( $_GET['request'] ) ) : 0;
	$order    = $order_id ? wc_get_order( $order_id ) : null;
	$order    = ( is_object( $order ) && method_exists( $order, 'get_id' ) ) ? $order : null;
	$draft    = $order ? fpw_read_request_draft( (int) $order->get_id() ) : null;
	echo fpw_quote_draft_markup( $order, $draft );
}

/** The native request record's editor link, in either Woo storage mode. */
function fpw_draft_request_admin_url( int $order_id ): string {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && method_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
		return admin_url( 'admin.php?page=wc-orders&id=' . $order_id . '&action=edit' );
	}
	return admin_url( 'post.php?post=' . $order_id . '&action=edit' );
}

/** The pending badge — the honest marker for what the record does not carry yet. */
function fpw_draft_pending_html(): string {
	return '<em class="fpw-draft__pending">Pendiente</em>';
}

/** One fact row of the draft's <dl>s: a fixed label beside record data. */
function fpw_draft_fact_html( string $label, string $value ): string {
	return '<div class="fpw-draft__fact"><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>';
}

/** One fact row whose value is the pending badge itself. */
function fpw_draft_pending_fact_html( string $label ): string {
	return '<div class="fpw-draft__fact"><dt>' . esc_html( $label ) . '</dt><dd>' . fpw_draft_pending_html() . '</dd></div>';
}

/** The identity and destination facts; each renders only when the record carried it. */
function fpw_draft_facts_html( array $identity, array $destination, bool $with_dispatch ): string {
	$facts = array(
		array( 'Contacto', (string) ( $identity['name'] ?? '' ) ),
		array( 'Empresa', (string) ( $identity['company'] ?? '' ) ),
		array( 'RUT empresa', (string) ( $identity['rut'] ?? '' ) ),
		array( 'Giro', (string) ( $identity['giro'] ?? '' ) ),
		array( 'Teléfono', (string) ( $identity['phone'] ?? '' ) ),
		array( 'Correo', (string) ( $identity['email'] ?? '' ) ),
		array( 'Despacho', $with_dispatch ? 'Con despacho' : 'Sin despacho' ),
	);
	if ( $with_dispatch ) { $facts[] = array( 'Dirección de despacho', (string) ( $destination['address'] ?? '' ) ); }
	$html = '';
	foreach ( $facts as [ $label, $value ] ) {
		if ( '' !== $value ) { $html .= fpw_draft_fact_html( $label, $value ); }
	}
	return $html;
}

/** The Submitted Details, preserved verbatim behind a collapsible; empty when the record carried none. */
function fpw_draft_submitted_details_html( $details ): string {
	if ( ! is_array( $details ) || empty( $details ) ) { return ''; }
	return '<details style="margin-top:10px"><summary>Datos originales recibidos</summary><pre>' . esc_html( (string) wp_json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre></details>';
}

/** The requested lines: name, chosen options behind their native labels, quantity — never a price. */
function fpw_draft_items_html( array $items ): string {
	$html = '';
	foreach ( $items as $line ) {
		$options = '';
		foreach ( ( is_array( $line['options'] ?? null ) ? $line['options'] : array() ) as $option ) {
			$options .= '<span class="fpw-draft__option">' . esc_html( wc_attribute_label( (string) $option['key'] ) . ': ' . (string) $option['value'] ) . '</span> ';
		}
		$quantity = max( 0, (int) ( $line['quantity'] ?? 0 ) );
		$html .= '<li><strong>' . esc_html( (string) ( $line['name'] ?? '' ) ) . '</strong>'
			. ( '' !== $options ? '<div>' . trim( $options ) . '</div>' : '' )
			. '<div class="fpw-draft__line"><span class="fpw-draft__qty">' . $quantity . ' ' . esc_html( 1 === $quantity ? 'unidad' : 'unidades' ) . '</span><span>Precio: ' . fpw_draft_pending_html() . '</span></div></li>';
	}
	return $html;
}

/** The screen shell: the mobile-first styles and the stable region the read-stability check measures. */
function fpw_draft_screen_shell( string $inner ): string {
	return '<div class="wrap fpw-draft"><style>'
		. '.fpw-draft{max-width:960px;font-size:16px;line-height:1.5}'
		. '.fpw-draft h1{font-size:24px;line-height:1.2;margin:4px 0 4px}'
		. '.fpw-draft__kicker{color:#60626d;margin:12px 0 0}'
		. '.fpw-draft__status{display:inline-block;margin:8px 0 0;padding:2px 10px;border-radius:999px;background:#f0e6d2;color:#5f4b1d;font-weight:600;font-size:13px}'
		. '.fpw-draft__summary{margin:10px 0 0;color:#3c4356}'
		. '.fpw-draft__guard{margin:10px 0 0;padding:10px 12px;border-left:3px solid #b7893c;background:#fdf8ee}'
		. '.fpw-draft__grid{display:grid;gap:16px;grid-template-columns:1fr;margin-top:16px}'
		. '.fpw-draft section{border:1px solid #dcdcde;border-radius:8px;padding:14px 16px;background:#fff;margin:0;min-width:0}'
		. '.fpw-draft h2{font-size:16px;margin:0 0 10px}'
		. '.fpw-draft dl{display:grid;grid-template-columns:1fr;gap:6px;margin:0}'
		. '.fpw-draft dt{font-weight:600;font-size:13px;color:#60626d}'
		. '.fpw-draft dd{margin:0;overflow-wrap:anywhere}'
		. '.fpw-draft__items{list-style:none;margin:0;padding:0;display:grid;gap:10px}'
		. '.fpw-draft__items li{border:1px solid #e4e4e8;border-radius:6px;padding:10px 12px;display:grid;gap:4px}'
		. '.fpw-draft__items .fpw-draft__option{color:#60626d}'
		. '.fpw-draft__line{display:flex;justify-content:space-between;gap:12px;align-items:baseline}'
		. '.fpw-draft__qty{font-weight:700;white-space:nowrap}'
		. '.fpw-draft__pending{font-weight:600;color:#8a6d1a;font-style:normal}'
		. '.fpw-draft__no-history{font-weight:700}'
		. '.fpw-draft__history{list-style:none;margin:0;padding:0;display:grid;gap:6px}'
		. '.fpw-draft__history li{border:1px solid #e4e4e8;border-radius:6px;padding:8px 10px;overflow-wrap:anywhere}'
		. '.fpw-draft__sale-date{font-weight:600;white-space:nowrap}'
		. '.fpw-draft__sale-total{font-weight:700;white-space:nowrap}'
		. '.fpw-draft__aside-note{color:#60626d;font-size:14px;margin:6px 0 0}'
		. '.fpw-draft pre{white-space:pre-wrap;overflow-wrap:anywhere}'
		. '@media (min-width: 782px){.fpw-draft__grid{grid-template-columns:minmax(0,3fr) minmax(0,2fr)}.fpw-draft aside{display:grid;gap:16px;align-content:start}.fpw-draft dl{grid-template-columns:auto 1fr}.fpw-draft dl dt{padding-right:12px}}'
		. '</style>' . $inner . '<!-- fpw-draft:end --></div>';
}

/** The honest no-draft state: the record exists, the initial draft does not — nothing is invented. */
function fpw_draft_no_draft_html( $order ): string {
	$order_id  = (int) $order->get_id();
	$reference = is_string( $order->get_order_number() ) ? $order->get_order_number() : '';
	return fpw_draft_screen_shell(
		'<h1>Borrador de cotización</h1>'
		. '<p class="fpw-draft__kicker">Solicitud ' . esc_html( $reference ) . '</p>'
		. '<section><h2>Sin borrador</h2><p>Esta solicitud no tiene borrador inicial guardado: se creó antes de este registro automático o su creación falló. La solicitud sigue intacta y legible en su registro nativo; no se inventa ningún dato.</p>'
		. '<p><a href="' . esc_url( fpw_draft_request_admin_url( $order_id ) ) . '">Ver solicitud completa</a></p></section>'
	);
}

/**
 * The live Purchase History beside the draft (issue #54, corte 5 de #49):
 * computed at READ time from the imported Sales Register — the stored receipt
 * snapshot is never rewritten — and matched primarily by the normalized
 * company RUT. Shows only the fields the import contract supplies (date,
 * source id, optional total) plus the provenance/freshness of its supplying
 * import. A missing, invalid or unmatched RUT stays unresolved: «Sin
 * historial asociado», never the customer verdict «Cliente nuevo». Per-product
 * detail is not supported by the source and is never promised.
 */
function fpw_draft_history_section( array $draft ): string {
	if ( ! function_exists( 'fpw_sales_history_for_rut' ) ) { return '<section><h2>Historial de compras</h2><p>' . fpw_draft_pending_html() . '</p></section>'; }
	$identity   = is_array( $draft['identity'] ?? null ) ? $draft['identity'] : array();
	$rut        = (string) ( $identity['rut'] ?? '' );
	$normalized = fpw_sales_normalize_rut( $rut );
	$history    = fpw_sales_history_for_rut( $normalized );
	$import_url = '<p class="fpw-draft__aside-note"><a href="' . esc_url( fpw_sales_import_screen_url() ) . '">Importar ventas</a></p>';
	if ( null === $history ) {
		return '<section><h2>Historial de compras</h2><p><strong class="fpw-draft__no-history">Sin historial asociado</strong></p>'
			. '<p class="fpw-draft__aside-note">La solicitud no trae un RUT de empresa utilizable, así que ninguna compra importada puede asociarse. Eso no indica que el cliente sea nuevo ni conocido.</p>'
			. $import_url . '</section>';
	}
	if ( empty( $history['sales'] ) ) {
		return '<section><h2>Historial de compras</h2><p><strong class="fpw-draft__no-history">Sin historial asociado</strong></p>'
			. '<p class="fpw-draft__aside-note">Ninguna venta importada coincide con el RUT ' . esc_html( $rut ) . '. No se inventó ninguna asociación: esto no indica que el cliente sea nuevo.</p>'
			. $import_url . '</section>';
	}
	$lines = '';
	foreach ( $history['sales'] as $sale ) {
		$total = null === ( $sale['total'] ?? null ) ? '—' : number_format( (int) $sale['total'], 0, ',', '.' ) . ' CLP';
		$lines .= '<li><span class="fpw-draft__sale-date">' . esc_html( (string) ( $sale['date'] ?? '' ) ) . '</span> · <span class="fpw-draft__sale-id">' . esc_html( (string) ( $sale['id'] ?? '' ) ) . '</span> · <span class="fpw-draft__sale-total">' . esc_html( $total ) . '</span></li>';
	}
	$fresh      = $history['freshness'];
	$fresh_note = is_array( $fresh )
		? 'Última carga aplicada el ' . esc_html( date_i18n( get_option( 'date_format' ), (int) $fresh['at'] ) ) . ' por ' . esc_html( $fresh['actor'] ) . ' (' . esc_html( $fresh['filename'] ) . '). '
		: '';
	return '<section><h2>Historial de compras</h2>'
		. '<p>' . count( $history['sales'] ) . ' ' . esc_html( 1 === count( $history['sales'] ) ? 'venta importada' : 'ventas importadas' ) . ' para el RUT ' . esc_html( $rut ) . ':</p>'
		. '<ul class="fpw-draft__history">' . $lines . '</ul>'
		. '<p class="fpw-draft__aside-note">Fuente: importaciones del Registro de ventas. ' . $fresh_note . 'El detalle por producto no está disponible en la fuente importada.</p>'
		. $import_url . '</section>';
}

/**
 * The draft screen markup. Mobile-first: the base layout is the ~412px phone
 * the owner reads on; the desktop grid is the enhancement. Everything shown
 * comes from the stored receipt snapshot; every gap is named Pendiente.
 *
 * @param object|null $order The request's native record, when it exists.
 * @param array|null  $draft The stored initial draft, when one exists.
 */
function fpw_quote_draft_markup( $order, ?array $draft ): string {
	if ( ! $draft ) {
		if ( ! $order || ! method_exists( $order, 'get_id' ) ) {
			return fpw_draft_screen_shell( '<h1>Borrador de cotización</h1><p><strong>Solicitud no encontrada.</strong> El enlace está incompleto o la solicitud no existe. Ningún dato se muestra sin su registro nativo.</p>' );
		}
		return fpw_draft_no_draft_html( $order );
	}
	$identity    = is_array( $draft['identity'] ?? null ) ? $draft['identity'] : array();
	$destination = is_array( $draft['destination'] ?? null ) ? $draft['destination'] : array();
	$items       = is_array( $draft['items'] ?? null ) ? $draft['items'] : array();
	$units       = 0;
	foreach ( $items as $line ) { $units += max( 0, (int) ( $line['quantity'] ?? 0 ) ); }
	$with_dispatch = 'si' === ( $destination['dispatch'] ?? '' );

	$heading = '<p class="fpw-draft__kicker">Solicitud <strong>' . esc_html( (string) ( $draft['reference'] ?? '' ) ) . '</strong> · recibida el ' . esc_html( date_i18n( get_option( 'date_format' ), (int) ( $draft['received_at'] ?? 0 ) ) ) . '</p>'
		. '<h1>Borrador de cotización</h1>'
		. '<span class="fpw-draft__status">Borrador inicial · pendiente de completar</span>'
		. '<p class="fpw-draft__summary">' . count( $items ) . ' ' . esc_html( 1 === count( $items ) ? 'producto' : 'productos' ) . ' · ' . $units . ' ' . esc_html( 1 === $units ? 'unidad' : 'unidades' ) . ' · ' . ( $with_dispatch ? 'con despacho' : 'sin despacho' ) . '</p>'
		. '<p class="fpw-draft__guard">Este borrador es privado y aún no constituye una cotización emitida: el comprador no ha recibido precios ni documentos.</p>';

	$sections = '<div style="display:grid;gap:16px;min-width:0">'
		. '<section><h2>Solicitud original</h2><dl>' . fpw_draft_facts_html( $identity, $destination, $with_dispatch ) . '</dl>' . fpw_draft_submitted_details_html( $draft['submitted_details'] ?? '' ) . '</section>'
		. '<section><h2>Productos solicitados</h2><ul class="fpw-draft__items">' . fpw_draft_items_html( $items ) . '</ul></section>'
		. '<section><h2>Despacho</h2>'
		. ( $with_dispatch
			? '<dl>' . fpw_draft_fact_html( 'Destino', (string) ( $destination['address'] ?? '' ) ) . fpw_draft_pending_fact_html( 'Estimación de despacho' ) . '</dl>'
			: '<p>La solicitud no pide despacho; los detalles originales se conservan.</p>' )
		. '</section>'
		. '</div>';

	$aside = '<aside style="display:grid;gap:16px;min-width:0;align-content:start">'
		. fpw_draft_history_section( $draft )
		. '<section><h2>Estado del borrador</h2><dl>'
		. fpw_draft_pending_fact_html( 'Precios' )
		. fpw_draft_pending_fact_html( 'Historial' )
		. fpw_draft_pending_fact_html( 'Estimación de despacho' )
		. '</dl><p class="fpw-draft__aside-note"><a href="' . esc_url( fpw_draft_request_admin_url( (int) ( $draft['order_id'] ?? 0 ) ) ) . '">Ver solicitud completa</a></p></section>'
		. '</aside>';

	return fpw_draft_screen_shell(
		$heading
		. '<div class="fpw-draft__grid">'
		. $sections
		. $aside
		. '</div>'
	);
}
