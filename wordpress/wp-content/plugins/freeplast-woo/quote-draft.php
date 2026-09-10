<?php
/**
 * Borrador privado de cotización — issue #50 + #51, cortes 1–2 de #49.
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
 * or a nonce grants nothing. Cut 1 rendered GET-only — no state change, hence
 * no CSRF surface; cut 2's save action carries and verifies its own nonce.
 *
 * Cut 2 (issue #51) adds the owner's manual completion on the same screen: per
 * line working quantities and net CLP prices, a working destination and the
 * dispatch amount. The owner's saved work lives in its OWN row,
 * fpw_draft_work_<order_id> (ADR-0005) — the receipt snapshot row above stays
 * immutable, so what the buyer asked for remains separated from what the owner
 * adjusts. Saves are authorized (capability first), CSRF-checked (nonce) and
 * guarded by optimistic concurrency: the form carries the revision it was
 * rendered from and a save is applied as an exact compare-and-set, so stale or
 * concurrent edits are refused instead of silently overwriting newer work.
 * A left-empty amount stays pending — never zero — and a zero is refused
 * server-side: no unapproved gratuity policy. Saving never approves or sends
 * anything: the record stays untouched, nothing is notified or issued.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'FPW_DRAFT_PREFIX', 'fpw_draft_' );
define( 'FPW_DRAFT_SCREEN', 'fpw-quote-draft' );
define( 'FPW_DRAFT_WORK_PREFIX', 'fpw_draft_work_' );
define( 'FPW_DRAFT_WORK_MAX_QUANTITY', 1000000 );
define( 'FPW_DRAFT_WORK_MAX_AMOUNT', 99999999 );

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

/** The durable write: a plain INSERT; an existing unique option_name is never rewritten. The expected duplicate-key error is suppressed — the defeat is the invariant, not a fault. */
function fpw_insert_options_row( string $name, string $value ): bool {
	global $wpdb;
	$was_suppressed = $wpdb->suppress_errors();
	$result = $wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )",
			$name,
			$value
		)
	);
	$wpdb->suppress_errors( $was_suppressed );
	return false !== $result && null !== $result;
}

function fpw_insert_draft_row( int $order_id, array $payload ): bool {
	return fpw_insert_options_row( fpw_draft_row_name( $order_id ), wp_json_encode( $payload ) );
}

/** The draft is born at the durable receipt moment — before any email renders, so the owner notice can point at it. */
add_action( 'woocommerce_checkout_order_created', 'fpw_create_request_draft', 5, 1 );

/* ===== The owner's saved work on one draft (issue #51, ADR-0005) =====
 * Working quantities, net CLP prices, working destination and dispatch amount
 * live in their OWN row so the receipt snapshot above stays immutable. The row
 * is rewritten ONLY through the revision-guarded save below; it is created by
 * the first save (plain INSERT — a concurrent first save loses cleanly) and
 * never by reading, rendering or any other path.
 */

/** The saved-work row name of one request. */
function fpw_draft_work_row_name( int $order_id ): string {
	return FPW_DRAFT_WORK_PREFIX . $order_id;
}

/** The nonce action of one draft's save: scoped to the request it edits. */
function fpw_draft_save_action( int $order_id ): string {
	return 'fpw-draft-save-' . $order_id;
}

/** Whether the receipt snapshot's request asked for dispatch ('si'). */
function fpw_draft_requests_dispatch( array $draft ): bool {
	$destination = is_array( $draft['destination'] ?? null ) ? $draft['destination'] : array();
	return 'si' === ( $destination['dispatch'] ?? '' );
}

/** The raw stored saved-work row of one request, read straight from the database — never through the per-request options cache. */
function fpw_read_draft_work_raw( int $order_id ): ?string {
	if ( $order_id <= 0 ) { return null; }
	global $wpdb;
	$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", fpw_draft_work_row_name( $order_id ) ) );
	return is_string( $raw ) && '' !== $raw ? $raw : null;
}

/** The stored saved work of one request, decoded. */
function fpw_read_draft_work( int $order_id ): ?array {
	$raw = fpw_read_draft_work_raw( $order_id );
	if ( null === $raw ) { return null; }
	$work = json_decode( $raw, true );
	return is_array( $work ) ? $work : null;
}

/** The exact compare-and-set write: applied only while the stored row still holds the value this save was rendered from. A concurrent or interleaved write makes it lose — the revision conflict, not a fault. */
function fpw_cas_draft_work_row( int $order_id, string $previous_raw, array $next ): bool {
	global $wpdb;
	$was_suppressed = $wpdb->suppress_errors();
	$result = $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
			wp_json_encode( $next ),
			fpw_draft_work_row_name( $order_id ),
			$previous_raw
		)
	);
	$wpdb->suppress_errors( $was_suppressed );
	return is_numeric( $result ) && 0 < (int) $result;
}

/** One strict CLP amount: null when left empty (pending), the integer when valid, 'zero' or 'invalid' when refused. */
function fpw_parse_draft_amount( $raw ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) { return null; }
	if ( ! preg_match( '/^\d{1,8}$/', $raw ) ) { return 'invalid'; }
	$amount = (int) $raw;
	return $amount >= 1 ? $amount : 'zero';
}

/** One strict working quantity: a positive integer, or 'invalid'. */
function fpw_parse_draft_quantity( $raw ) {
	$raw = trim( (string) $raw );
	if ( ! preg_match( '/^\d{1,7}$/', $raw ) ) { return 'invalid'; }
	$quantity = (int) $raw;
	return ( $quantity >= 1 && $quantity <= FPW_DRAFT_WORK_MAX_QUANTITY ) ? $quantity : 'invalid';
}

/**
 * Parse and validate one save's posted values against the receipt snapshot.
 * All-or-nothing: any invalid field refuses the whole save. A left-empty
 * amount stays pending; a zero is refused — the site holds no approved
 * gratuity policy, so a price of 0 can never masquerade as a decision.
 *
 * @return array{errors:string[],lines:array[],destination:string,dispatch_amount:?int}
 */
function fpw_parse_draft_work_input( array $draft, array $posted ): array {
	$items          = is_array( $draft['items'] ?? null ) ? $draft['items'] : array();
	$work_in        = is_array( $posted['fpw_work'] ?? null ) ? $posted['fpw_work'] : array();
	$lines_in       = is_array( $work_in['lines'] ?? null ) ? $work_in['lines'] : array();
	$with_dispatch  = fpw_draft_requests_dispatch( $draft );
	$errors         = array();
	$lines          = array();
	foreach ( $items as $i => $item ) {
		$label = 'Línea ' . ( $i + 1 ) . ' (' . (string) ( $item['name'] ?? '' ) . ')';
		$quantity = fpw_parse_draft_quantity( $lines_in[ $i ]['quantity'] ?? ( $item['quantity'] ?? 1 ) );
		if ( is_string( $quantity ) ) {
			$errors[] = $label . ': la cantidad debe ser un entero entre 1 y ' . number_format( FPW_DRAFT_WORK_MAX_QUANTITY, 0, ',', '.' ) . '.';
			$quantity = max( 1, (int) ( $item['quantity'] ?? 1 ) );
		}
		$price = fpw_parse_draft_amount( $lines_in[ $i ]['price'] ?? '' );
		if ( 'zero' === $price ) {
			$errors[] = $label . ': un precio de 0 no está permitido; deja el campo vacío mientras el precio siga pendiente.';
			$price = null;
		} elseif ( 'invalid' === $price ) {
			$errors[] = $label . ': ingresa un precio neto entero en CLP (1 a ' . number_format( FPW_DRAFT_WORK_MAX_AMOUNT, 0, ',', '.' ) . ') o deja el campo vacío.';
			$price = null;
		}
		$lines[] = array(
			'index'        => $i,
			'product_id'   => (int) ( $item['product_id'] ?? 0 ),
			'variation_id' => (int) ( $item['variation_id'] ?? 0 ),
			'quantity'     => $quantity,
			'price'        => $price,
			'price_source' => null === $price ? 'pending' : 'manual',
		);
	}
	$destination = '';
	$dispatch_amount = null;
	if ( $with_dispatch ) {
		$destination = sanitize_textarea_field( (string) ( $work_in['destination'] ?? '' ) );
		if ( strlen( $destination ) > 800 ) { $errors[] = 'El destino de trabajo es demasiado largo (máximo 800 caracteres).'; }
		$dispatch_amount = fpw_parse_draft_amount( $work_in['dispatch_amount'] ?? '' );
		if ( 'zero' === $dispatch_amount ) {
			$errors[] = 'El monto de despacho no puede ser 0; deja el campo vacío mientras siga pendiente.';
			$dispatch_amount = null;
		} elseif ( 'invalid' === $dispatch_amount ) {
			$errors[] = 'El monto de despacho debe ser un entero en CLP (1 a ' . number_format( FPW_DRAFT_WORK_MAX_AMOUNT, 0, ',', '.' ) . ') o quedar vacío.';
			$dispatch_amount = null;
		}
	}
	return array( 'errors' => $errors, 'lines' => $lines, 'destination' => $destination, 'dispatch_amount' => $dispatch_amount );
}

/**
 * Save one revision of the owner's work, guarded end to end. The submission
 * names the revision it was rendered from: anything else is a stale or
 * concurrent edit and is refused as a conflict — the stored (accepted) edit is
 * preserved and re-shown, never silently overwritten. The write itself is an
 * exact compare-and-set (or the first plain INSERT), so two saves of the same
 * revision cannot both land. Invalid input refuses the whole save.
 *
 * @return array{state:'saved'|'conflict'|'invalid',work?:?array,errors?:string[],stored_revision?:int}
 */
function fpw_save_draft_work( int $order_id, array $draft, array $posted, int $user_id ): array {
	$parsed = fpw_parse_draft_work_input( $draft, $posted );
	$raw    = fpw_read_draft_work_raw( $order_id );
	$stored = null;
	if ( null !== $raw ) {
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) { $stored = $decoded; } else { $raw = null; }
	}
	$stored_revision = is_array( $stored ) ? (int) ( $stored['revision'] ?? 0 ) : 0;
	$base = isset( $posted['fpw_work_revision'] ) ? (int) $posted['fpw_work_revision'] : -1;
	if ( $base !== $stored_revision ) {
		return array( 'state' => 'conflict', 'work' => $stored, 'stored_revision' => $stored_revision );
	}
	if ( ! empty( $parsed['errors'] ) ) {
		return array( 'state' => 'invalid', 'errors' => $parsed['errors'] );
	}
	// The dispatch amount keeps the conditions (destination + quantities) it was
	// ENTERED for. A save that changes destination or quantities without
	// changing the amount leaves those conditions standing — the screen then
	// marks the amount for review instead of silently re-blessing it.
	$amount_changed = null === $stored || $parsed['dispatch_amount'] !== ( $stored['dispatch_amount'] ?? null );
	$conditions = null;
	if ( null !== $parsed['dispatch_amount'] ) {
		$kept = is_array( $stored['dispatch_conditions'] ?? null ) ? $stored['dispatch_conditions'] : null;
		$conditions = ( $amount_changed || null === $kept )
			? array( 'destination' => $parsed['destination'], 'quantities' => array_map( static fn( $line ) => (int) $line['quantity'], $parsed['lines'] ) )
			: $kept;
	}
	$next = array(
		'schema'              => 1,
		'order_id'            => $order_id,
		'revision'            => $stored_revision + 1,
		'updated_at'          => time(),
		'updated_by'          => $user_id,
		'lines'               => $parsed['lines'],
		'destination'         => $parsed['destination'],
		'dispatch_amount'     => $parsed['dispatch_amount'],
		'dispatch_conditions' => $conditions,
	);
	// First save: plain INSERT (a concurrent first save loses cleanly). Later
	// saves: the exact compare-and-set. Either write losing is the conflict.
	$written = null === $raw
		? fpw_insert_options_row( fpw_draft_work_row_name( $order_id ), wp_json_encode( $next ) )
		: fpw_cas_draft_work_row( $order_id, $raw, $next );
	if ( ! $written ) {
		return array( 'state' => 'conflict', 'work' => fpw_read_draft_work( $order_id ), 'stored_revision' => $stored_revision );
	}
	return array( 'state' => 'saved', 'work' => $next );
}

/**
 * Whether the saved dispatch amount no longer matches the conditions it was
 * entered for (destination changed, quantities changed, or its row lost the
 * conditions). True only for an actually saved amount — a pending dispatch is
 * not stale, it is pending.
 */
function fpw_draft_dispatch_stale( ?array $work ): bool {
	if ( ! is_array( $work ) || ! is_int( $work['dispatch_amount'] ?? null ) ) { return false; }
	$conditions = is_array( $work['dispatch_conditions'] ?? null ) ? $work['dispatch_conditions'] : null;
	if ( null === $conditions ) { return true; }
	$quantities = array_map( static fn( $line ) => (int) ( $line['quantity'] ?? 0 ), is_array( $work['lines'] ?? null ) ? $work['lines'] : array() );
	return (string) ( $conditions['destination'] ?? '' ) !== (string) ( $work['destination'] ?? '' )
		|| array_map( 'intval', (array) ( $conditions['quantities'] ?? array() ) ) !== $quantities;
}

/** The private screen: unlisted (the owner notice's link is the access), keyed on the owner capability. */
add_action( 'admin_menu', 'fpw_quote_draft_register_screen' );
function fpw_quote_draft_register_screen(): void {
	add_submenu_page( null, 'Borrador de cotización', 'Borrador de cotización', 'manage_woocommerce', FPW_DRAFT_SCREEN, 'fpw_render_quote_draft_screen' );
}

/** The uniform denial: private to the owner, stated in Spanish, 403 — attributable to permissions, never to a nonce. */
function fpw_die_draft_forbidden(): void {
	wp_die( 'Este borrador de cotización es privado del dueño: requiere una sesión con permisos de administración de WooCommerce.', '', array( 'response' => 403 ) );
}

/** The order the draft screen targets, resolved from its request id: null when absent, unknown or not a real record. */
function fpw_draft_screen_order() {
	$order_id = isset( $_GET['request'] ) ? absint( wp_unslash( $_GET['request'] ) ) : 0;
	$order    = $order_id ? wc_get_order( $order_id ) : null;
	return ( is_object( $order ) && method_exists( $order, 'get_id' ) ) ? $order : null;
}

/**
 * Request-scoped stash for the save outcome (ADR-0005): admin_init runs the
 * save BEFORE wp-admin renders (so a CSRF refusal is a real 403, not a 200
 * after headers); the screen callback reads the outcome back to render its
 * notice. Direct invocation without the admin_init pass (offline tests) is
 * supported: the callback runs the same handler itself when the stash is
 * empty.
 */
function fpw_pending_draft_save( ?array $set = null ): ?array {
	static $pending = null;
	return null === $set ? $pending : ( $pending = $set );
}

/** The save's server-side half: CSRF, then the guarded save; the outcome is stashed for the screen. */
function fpw_handle_draft_save_request( $order, array $draft ): void {
	if ( ! wp_verify_nonce( (string) ( $_POST['fpw_draft_nonce'] ?? '' ), fpw_draft_save_action( (int) $order->get_id() ) ) ) {
		wp_die( 'Tu sesión expiró o el formulario no es válido: vuelve a cargar el borrador e inténtalo de nuevo.', '', array( 'response' => 403 ) );
	}
	$result = fpw_save_draft_work( (int) $order->get_id(), $draft, wp_unslash( $_POST ), get_current_user_id() );
	fpw_pending_draft_save( array( 'order_id' => (int) $order->get_id(), 'result' => $result ) );
}

/**
 * The save front door, ahead of wp-admin's own header render: authorization
 * first (the capability — never the nonce — grants access), then CSRF, then
 * the guarded save. Requests without a resolved draft edit nothing; the
 * screen answers them honestly.
 */
function fpw_handle_draft_save(): void {
	fpw_pending_draft_save( null );   // a fresh request starts with no outcome
	if ( FPW_DRAFT_SCREEN !== (string) ( $_GET['page'] ?? '' ) || empty( $_POST['fpw_work_save'] ) ) { return; }
	if ( ! current_user_can( 'manage_woocommerce' ) ) { fpw_die_draft_forbidden(); }
	$order = fpw_draft_screen_order();
	$draft = $order ? fpw_read_request_draft( (int) $order->get_id() ) : null;
	if ( ! $draft ) { return; }
	fpw_handle_draft_save_request( $order, $draft );
}
add_action( 'admin_init', 'fpw_handle_draft_save' );

/** The save outcome's screen notice, in the screen's own language. */
function fpw_draft_save_notice( array $result ): array {
	if ( 'saved' === $result['state'] ) {
		return array(
			'class' => 'ok',
			'title' => 'Cambios guardados (revisión ' . (int) ( $result['work']['revision'] ?? 0 ) . ').',
			'lines' => array( 'Al volver a este borrador recuperarás estos valores. Guardar no aprueba ni envía ninguna cotización.' ),
		);
	}
	if ( 'conflict' === $result['state'] ) {
		$stored = (int) ( $result['stored_revision'] ?? 0 );
		return array(
			'class' => 'warn',
			'title' => 'Tu envío no se guardó: este borrador tiene una revisión más reciente guardada' . ( $stored > 0 ? ' (la ' . $stored . ').' : '.' ),
			'lines' => array( 'Los valores que se muestran son los ya guardados y quedan conservados; nada fue sobrescrito. Revísalos y vuelve a guardar si corresponde.' ),
		);
	}
	return array(
		'class' => 'error',
		'title' => 'No se guardó nada: revisa estos datos.',
		'lines' => array_merge( array_map( 'strval', $result['errors'] ?? array() ), array( 'Los valores ya guardados siguen intactos.' ) ),
	);
}

/**
 * The screen callback: capability first, then resolve honestly — the request,
 * its draft, its saved work, or a state that invents nothing. A POST save was
 * already processed by admin_init ahead of the header render; the callback
 * renders its outcome and always re-renders the form from the stored state —
 * on a conflict that is the preserved accepted edit, on an invalid save the
 * untouched stored values.
 */
function fpw_render_quote_draft_screen(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) { fpw_die_draft_forbidden(); }
	$order    = fpw_draft_screen_order();
	$draft    = $order ? fpw_read_request_draft( (int) $order->get_id() ) : null;
	$work     = $order ? fpw_read_draft_work( (int) $order->get_id() ) : null;
	$notice   = null;
	$handled  = fpw_pending_draft_save();
	if ( $order && $draft && isset( $_POST['fpw_work_save'] ) && (int) ( $handled['order_id'] ?? 0 ) !== (int) $order->get_id() ) {
		// No admin_init pass on this request: run the same server-side process here.
		fpw_handle_draft_save_request( $order, $draft );
		$handled = fpw_pending_draft_save();
	}
	if ( $handled && (int) ( $handled['order_id'] ?? 0 ) === (int) ( $order ? $order->get_id() : 0 ) ) {
		$result = $handled['result'];
		$notice = fpw_draft_save_notice( $result );
		if ( in_array( $result['state'], array( 'saved', 'conflict' ), true ) && array_key_exists( 'work', $result ) ) {
			$work = $result['work'];
		}
	}
	echo fpw_quote_draft_markup( $order, $draft, $work, $notice );
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

/** One fact row whose value is rendered markup (pending badges, states) rather than plain text. */
function fpw_draft_fact_raw_html( string $label, string $value_html ): string {
	return '<div class="fpw-draft__fact"><dt>' . esc_html( $label ) . '</dt><dd>' . $value_html . '</dd></div>';
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

/** The saved-work values the screen shows for one draft: the owner's saved adjustments over the receipt snapshot's defaults (identity-checked per line). */
function fpw_draft_work_values( array $draft, ?array $work ): array {
	$with_dispatch = fpw_draft_requests_dispatch( $draft );
	$address       = (string) ( is_array( $draft['destination'] ?? null ) ? ( $draft['destination']['address'] ?? '' ) : '' );
	$values = array(
		'destination'     => $with_dispatch ? $address : '',
		'dispatch_amount' => null,
		'lines'           => array(),
	);
	$items = is_array( $draft['items'] ?? null ) ? $draft['items'] : array();
	foreach ( $items as $i => $line ) {
		$values['lines'][ $i ] = array( 'quantity' => max( 1, (int) ( $line['quantity'] ?? 1 ) ), 'price' => null );
	}
	if ( ! is_array( $work ) ) { return $values; }
	$saved_lines = is_array( $work['lines'] ?? null ) ? $work['lines'] : array();
	foreach ( $values['lines'] as $i => $default ) {
		$saved = is_array( $saved_lines[ $i ] ?? null ) ? $saved_lines[ $i ] : null;
		$same_identity = is_array( $saved )
			&& (int) ( $saved['product_id'] ?? -1 ) === (int) ( $items[ $i ]['product_id'] ?? -1 )
			&& (int) ( $saved['variation_id'] ?? -1 ) === (int) ( $items[ $i ]['variation_id'] ?? -1 );
		if ( ! $same_identity ) { continue; }
		$values['lines'][ $i ]['quantity'] = max( 1, (int) ( $saved['quantity'] ?? $default['quantity'] ) );
		$price = $saved['price'] ?? null;
		if ( is_int( $price ) && $price > 0 ) { $values['lines'][ $i ]['price'] = $price; }
	}
	if ( $with_dispatch ) {
		$values['destination'] = (string) ( $work['destination'] ?? $values['destination'] );
		$amount = $work['dispatch_amount'] ?? null;
		if ( is_int( $amount ) && $amount > 0 ) { $values['dispatch_amount'] = $amount; }
	}
	return $values;
}

/** One amount as the owner entered it: plain integer input, pending placeholder when absent. */
function fpw_draft_amount_input_html( string $name, ?int $value ): string {
	return '<input type="number" inputmode="numeric" min="1" max="' . FPW_DRAFT_WORK_MAX_AMOUNT . '" step="1" name="' . esc_attr( $name ) . '" value="' . ( null !== $value && $value > 0 ? $value : '' ) . '" placeholder="Pendiente" />';
}

/** One working quantity as the owner set it: a plain integer input, never a placeholder. */
function fpw_draft_quantity_input_html( string $name, int $value ): string {
	return '<input type="number" inputmode="numeric" min="1" max="' . FPW_DRAFT_WORK_MAX_QUANTITY . '" step="1" name="' . esc_attr( $name ) . '" value="' . $value . '" />';
}

/** One entered amount with its manual origin, or the pending badge. */
function fpw_draft_amount_value_html( ?int $amount, string $pending_note = '' ): string {
	if ( null === $amount ) { return fpw_draft_pending_html() . ( '' !== $pending_note ? '<span> — ' . esc_html( $pending_note ) . '</span>' : '' ); }
	return '<span>' . esc_html( number_format( $amount, 0, ',', '.' ) ) . ' CLP neto · ingreso manual</span>';
}

/** The requested lines: name, chosen options behind their native labels, the ORIGINAL quantity kept apart from the working inputs — never a fabricated price. */
function fpw_draft_items_html( array $items, array $values ): string {
	$html = '';
	foreach ( $items as $i => $line ) {
		$options = '';
		foreach ( ( is_array( $line['options'] ?? null ) ? $line['options'] : array() ) as $option ) {
			$options .= '<span class="fpw-draft__option">' . esc_html( wc_attribute_label( (string) $option['key'] ) . ': ' . (string) $option['value'] ) . '</span> ';
		}
		$quantity   = max( 0, (int) ( $line['quantity'] ?? 0 ) );
		$work_qty   = (int) ( $values['lines'][ $i ]['quantity'] ?? $quantity );
		$work_price = $values['lines'][ $i ]['price'] ?? null;
		$html .= '<li><strong>' . esc_html( (string) ( $line['name'] ?? '' ) ) . '</strong>'
			. ( '' !== $options ? '<div>' . trim( $options ) . '</div>' : '' )
			. '<div class="fpw-draft__line"><span class="fpw-draft__qty">Pedido: ' . $quantity . ' ' . esc_html( 1 === $quantity ? 'unidad' : 'unidades' ) . '</span><span>Precio: ' . fpw_draft_amount_value_html( $work_price ) . '</span></div>'
			. '<div class="fpw-draft__edit">'
			. '<label>Cantidad de trabajo' . fpw_draft_quantity_input_html( 'fpw_work[lines][' . $i . '][quantity]', $work_qty ) . '</label>'
			. '<label>Precio neto unitario (CLP)' . fpw_draft_amount_input_html( 'fpw_work[lines][' . $i . '][price]', $work_price ) . '</label>'
			. '<span class="fpw-draft__origin">Ajuste manual del dueño: afecta solo a este borrador.</span>'
			. '</div></li>';
	}
	return $html;
}

/** The dispatch block: the working destination and amount for a requested dispatch; the explicit no-dispatch contract otherwise. */
function fpw_draft_dispatch_html( bool $with_dispatch, string $destination, ?int $amount, bool $stale ): string {
	if ( ! $with_dispatch ) {
		return '<p>La solicitud no pide despacho. Sin despacho es distinto de despacho aún no valorizado: la oferta de trabajo no incluye destino de entrega ni flete; los detalles originales se conservan.</p>';
	}
	return '<dl>'
		. '<div class="fpw-draft__fact"><dt>Destino de trabajo</dt><dd><label class="fpw-draft__field">Destino de entrega (trabajo)<textarea name="fpw_work[destination]" maxlength="800" rows="2" placeholder="Calle, número, comuna y región">' . esc_textarea( $destination ) . '</textarea></label>'
		. '<span class="fpw-draft__origin">Ajuste manual del dueño: la dirección original de la solicitud se conserva histórica arriba.</span></dd></div>'
		. '<div class="fpw-draft__fact"><dt>Monto de despacho</dt><dd>' . fpw_draft_amount_value_html( $amount, 'despacho aún no valorizado' )
		. ( $stale ? '<em class="fpw-draft__stale">Requiere revisión: el destino o las cantidades cambiaron después de guardar este monto; revísalo antes de ofrecerlo.</em>' : '' )
		. '<label class="fpw-draft__field">Monto de despacho (CLP neto)' . fpw_draft_amount_input_html( 'fpw_work[dispatch_amount]', $amount ) . '</label>'
		. '</dd></div></dl>';
}

/** The save outcome notice: saved, conflict (preserved accepted edit) or refused validation. */
function fpw_draft_notice_html( ?array $notice ): string {
	if ( ! is_array( $notice ) || empty( $notice['title'] ) ) { return ''; }
	$class = in_array( $notice['class'] ?? '', array( 'ok', 'error', 'warn' ), true ) ? $notice['class'] : 'warn';
	$lines = '';
	foreach ( (array) ( $notice['lines'] ?? array() ) as $line ) { $lines .= '<li>' . esc_html( (string) $line ) . '</li>'; }
	return '<div class="fpw-draft__notice is-' . esc_attr( $class ) . '"><strong>' . esc_html( (string) $notice['title'] ) . '</strong>'
		. ( '' !== $lines ? '<ul>' . $lines . '</ul>' : '' ) . '</div>';
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
		. '.fpw-draft__work-status{color:#60626d;font-size:14px;margin:8px 0 0}'
		. '.fpw-draft__notice{margin:12px 0 0;padding:10px 12px;border-left:3px solid #72aee6;background:#f0f6fc}'
		. '.fpw-draft__notice strong{display:block;margin-bottom:4px}'
		. '.fpw-draft__notice ul{margin:0 0 0 18px;padding:0}'
		. '.fpw-draft__notice.is-ok{border-left-color:#46b450;background:#f0fdf2}'
		. '.fpw-draft__notice.is-error{border-left-color:#dc3232;background:#fdf0f0}'
		. '.fpw-draft__notice.is-warn{border-left-color:#b7893c;background:#fdf8ee}'
		. '.fpw-draft__edit{display:grid;gap:8px;margin-top:10px;border-top:1px dashed #e4e4e8;padding-top:10px}'
		. '.fpw-draft__field,.fpw-draft__edit label{display:grid;gap:4px;font-size:13px;font-weight:600;color:#60626d}'
		. '.fpw-draft__edit input,.fpw-draft__field input,.fpw-draft__field textarea{font-size:16px;padding:8px 10px;border:1px solid #c3c4c7;border-radius:4px;width:100%;max-width:24rem;box-sizing:border-box;background:#fff;font-family:inherit}'
		. '.fpw-draft__origin{font-size:12px;font-weight:400;color:#60626d}'
		. '.fpw-draft__stale{display:block;margin-top:6px;font-weight:600;color:#8a1d1d;font-style:normal}'
		. '.fpw-draft__save{display:grid;gap:8px;justify-items:start}'
		. '.fpw-draft__save button{font-size:16px;font-weight:600;padding:10px 18px;border-radius:6px;background:#1f2a44;color:#fff;border:0;cursor:pointer}'
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
 * The draft screen markup. Mobile-first: the base layout is the ~412px phone
 * the owner reads on; the desktop grid is the enhancement. Everything shown
 * comes from the stored receipt snapshot and the owner's saved work; every gap
 * is named Pendiente, never a zero. The editing form (cut 2) posts the
 * revision it was rendered from, its own nonce, and never approves or sends
 * anything by itself.
 *
 * @param object|null $order  The request's native record, when it exists.
 * @param array|null  $draft  The stored initial draft, when one exists.
 * @param array|null  $work   The owner's saved work, when any exists.
 * @param array|null  $notice The save outcome to report, when a save ran.
 */
function fpw_quote_draft_markup( $order, ?array $draft, ?array $work = null, ?array $notice = null ): string {
	if ( ! $draft ) {
		if ( ! $order || ! method_exists( $order, 'get_id' ) ) {
			return fpw_draft_screen_shell( '<h1>Borrador de cotización</h1><p><strong>Solicitud no encontrada.</strong> El enlace está incompleto o la solicitud no existe. Ningún dato se muestra sin su registro nativo.</p>' );
		}
		return fpw_draft_no_draft_html( $order );
	}
	$identity        = is_array( $draft['identity'] ?? null ) ? $draft['identity'] : array();
	$destination     = is_array( $draft['destination'] ?? null ) ? $draft['destination'] : array();
	$items           = is_array( $draft['items'] ?? null ) ? $draft['items'] : array();
	$units           = 0;
	foreach ( $items as $line ) { $units += max( 0, (int) ( $line['quantity'] ?? 0 ) ); }
	$with_dispatch   = fpw_draft_requests_dispatch( $draft );
	$revision        = $work ? max( 0, (int) ( $work['revision'] ?? 0 ) ) : 0;
	$values          = fpw_draft_work_values( $draft, $work );
	$dispatch_stale  = fpw_draft_dispatch_stale( $work );
	$dispatch_amount = $values['dispatch_amount'];
	$order_id        = (int) ( $draft['order_id'] ?? 0 );
	$all_priced      = ! empty( $values['lines'] );
	foreach ( $values['lines'] as $work_line ) { if ( null === ( $work_line['price'] ?? null ) ) { $all_priced = false; } }

	$heading = '<p class="fpw-draft__kicker">Solicitud <strong>' . esc_html( (string) ( $draft['reference'] ?? '' ) ) . '</strong> · recibida el ' . esc_html( date_i18n( get_option( 'date_format' ), (int) ( $draft['received_at'] ?? 0 ) ) ) . '</p>'
		. '<h1>Borrador de cotización</h1>'
		. '<span class="fpw-draft__status">Borrador inicial · pendiente de completar</span>'
		. ( $revision > 0 ? '<p class="fpw-draft__work-status">Ajustes guardados · revisión ' . $revision . ' · ' . esc_html( date_i18n( get_option( 'date_format' ), (int) ( $work['updated_at'] ?? 0 ) ) ) . '</p>' : '' )
		. '<p class="fpw-draft__summary">' . count( $items ) . ' ' . esc_html( 1 === count( $items ) ? 'producto' : 'productos' ) . ' · ' . $units . ' ' . esc_html( 1 === $units ? 'unidad' : 'unidades' ) . ' · ' . ( $with_dispatch ? 'con despacho' : 'sin despacho' ) . '</p>'
		. '<p class="fpw-draft__guard">Este borrador es privado y aún no constituye una cotización emitida: el comprador no ha recibido precios ni documentos.</p>';

	$form_open = '<form method="post" action="' . esc_url( fpw_draft_screen_url( $order_id ) ) . '">'
		. '<input type="hidden" name="fpw_work_save" value="1" />'
		. '<input type="hidden" name="fpw_work_revision" value="' . $revision . '" />'
		. wp_nonce_field( fpw_draft_save_action( $order_id ), 'fpw_draft_nonce', true, false );
	$save_section = '<section class="fpw-draft__save"><h2>Guardar trabajo</h2><button type="submit">Guardar cambios del borrador</button>'
		. '<p class="fpw-draft__aside-note">Guardar solo conserva tu trabajo: no aprueba ni envía ninguna cotización al comprador. Cada valor que ingresas es un ajuste manual que afecta solo a este borrador, nunca a una lista general ni a otro cliente.</p></section>';

	$sections = fpw_draft_notice_html( $notice )
		. '<div style="display:grid;gap:16px;min-width:0">'
		. '<section><h2>Solicitud original</h2><dl>' . fpw_draft_facts_html( $identity, $destination, $with_dispatch ) . '</dl>' . fpw_draft_submitted_details_html( $draft['submitted_details'] ?? '' ) . '</section>'
		. $form_open
		. '<section><h2>Productos solicitados</h2><ul class="fpw-draft__items">' . fpw_draft_items_html( $items, $values ) . '</ul></section>'
		. '<section><h2>Despacho</h2>' . fpw_draft_dispatch_html( $with_dispatch, $values['destination'], $dispatch_amount, $dispatch_stale ) . '</section>'
		. $save_section
		. '</form>'
		. '</div>';

	$prices_state = $all_priced ? 'Ingresados manualmente por el dueño' : fpw_draft_pending_html();
	$dispatch_state = ! $with_dispatch
		? 'No requerida (sin despacho)'
		: ( null !== $dispatch_amount ? ( $dispatch_stale ? 'Requiere revisión' : 'Ingresada manualmente' ) : fpw_draft_pending_html() );

	$aside = '<aside style="display:grid;gap:16px;min-width:0;align-content:start">'
		. '<section><h2>Historial de compras</h2><p>' . fpw_draft_pending_html() . '</p><p class="fpw-draft__aside-note">Aún no hay historial disponible para este borrador. Eso no indica que el cliente sea nuevo ni conocido.</p></section>'
		. '<section><h2>Estado del borrador</h2><dl>'
		. fpw_draft_fact_raw_html( 'Precios', $prices_state )
		. fpw_draft_pending_fact_html( 'Historial' )
		. fpw_draft_fact_raw_html( 'Estimación de despacho', $dispatch_state )
		. '</dl><p class="fpw-draft__aside-note"><a href="' . esc_url( fpw_draft_request_admin_url( $order_id ) ) . '">Ver solicitud completa</a></p></section>'
		. '</aside>';

	return fpw_draft_screen_shell(
		$heading
		. '<div class="fpw-draft__grid">'
		. $sections
		. $aside
		. '</div>'
	);
}
