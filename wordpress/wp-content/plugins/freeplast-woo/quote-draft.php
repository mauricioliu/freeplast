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
 *
 * Cut 6 (issue #55) adds the review before any approval: ONE shared
 * server-side projection (fpw_quotation_projection) computes, with exact
 * integer CLP arithmetic, the buyer-facing lines, subtotal, dispatch, IVA and
 * total from the saved working state — the same calculation the later issuance
 * (cut 7) must consume, never parallel math per screen. The fiscal policy is a
 * configuration seam ABSENT by default (fpw_quotation_tax_config): no rate is
 * invented, and without a delivered policy the IVA and total stay pending and
 * the offer stays incomplete. The offer validity defaults to seven days
 * (filterable) and is editable per draft in the working state. "Generar vista
 * previa" stores the projection of the revision the owner actually reviewed in
 * its own row, fpw_draft_preview_<order_id>; any later commercial save makes
 * that preview obsolete — no future approval may use it to issue different
 * values. Previewing issues nothing: no approved version, no PDF, no mail.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'FPW_DRAFT_PREFIX', 'fpw_draft_' );
define( 'FPW_DRAFT_SCREEN', 'fpw-quote-draft' );
define( 'FPW_DRAFT_WORK_PREFIX', 'fpw_draft_work_' );
define( 'FPW_DRAFT_WORK_MAX_QUANTITY', 1000000 );
define( 'FPW_DRAFT_WORK_MAX_AMOUNT', 99999999 );
define( 'FPW_DRAFT_PREVIEW_PREFIX', 'fpw_draft_preview_' );
define( 'FPW_DRAFT_MAX_VALIDITY_DAYS', 365 );
define( 'FPW_QUOTATION_MAX_RATE_PERMILLE', 5000 );

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
		'schema'            => 2,
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
			'source'   => (string) $order->get_meta( '_billing_fp_address_source' ),
			'place_id' => (string) $order->get_meta( '_billing_fp_place_id' ),
			'scope'    => (string) $order->get_meta( '_billing_fp_place_scope' ),
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

/** One strict offer validity in days: null when left empty (the default applies), the integer when valid, 'invalid' when refused. */
function fpw_parse_draft_validity( $raw ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) { return null; }
	if ( ! preg_match( '/^\d{1,3}$/', $raw ) ) { return 'invalid'; }
	$days = (int) $raw;
	return ( $days >= 1 && $days <= FPW_DRAFT_MAX_VALIDITY_DAYS ) ? $days : 'invalid';
}

/**
 * Parse and validate one save's posted values against the receipt snapshot.
 * All-or-nothing: any invalid field refuses the whole save. A left-empty
 * amount stays pending; a zero is refused — the site holds no approved
 * gratuity policy, so a price of 0 can never masquerade as a decision. A
 * left-empty validity falls back to the default; an out-of-range one is
 * refused (issue #55).
 *
 * @return array{errors:string[],lines:array[],destination:string,dispatch_amount:?int,validity_days:?int}
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
	$validity = fpw_parse_draft_validity( $work_in['validity_days'] ?? '' );
	if ( 'invalid' === $validity ) {
		$errors[] = 'La vigencia de la oferta debe ser un entero entre 1 y ' . FPW_DRAFT_MAX_VALIDITY_DAYS . ' días, o quedar vacía para usar la vigencia por defecto.';
	}
	return array( 'errors' => $errors, 'lines' => $lines, 'destination' => $destination, 'dispatch_amount' => $dispatch_amount, 'validity_days' => 'invalid' === $validity ? null : $validity );
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
		'schema'              => 2,
		'order_id'            => $order_id,
		'revision'            => $stored_revision + 1,
		'updated_at'          => time(),
		'updated_by'          => $user_id,
		'lines'               => $parsed['lines'],
		'destination'         => $parsed['destination'],
		'dispatch_amount'     => $parsed['dispatch_amount'],
		'dispatch_conditions' => $conditions,
		'validity_days'       => $parsed['validity_days'],
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

/** The nonce action of one draft's preview generation: scoped to the request it previews. */
function fpw_draft_preview_action( int $order_id ): string {
	return 'fpw-draft-preview-' . $order_id;
}

/** The stored-preview row name of one request. */
function fpw_draft_preview_row_name( int $order_id ): string {
	return FPW_DRAFT_PREVIEW_PREFIX . $order_id;
}

/** The stored preview of one request, decoded — the reviewed projection and the work revision it was rendered from. */
function fpw_read_draft_preview( int $order_id ): ?array {
	if ( $order_id <= 0 ) { return null; }
	global $wpdb;
	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) { return null; }
	$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", fpw_draft_preview_row_name( $order_id ) ) );
	if ( ! is_string( $raw ) || '' === $raw ) { return null; }
	$preview = json_decode( $raw, true );
	return is_array( $preview ) && is_array( $preview['projection'] ?? null ) ? $preview : null;
}

/**
 * Write one draft's stored preview: an unconditional UPDATE (the newest
 * reviewed preview replaces the previous one — it is a review aid, not a
 * receipt), falling back to a plain INSERT and, when a concurrent first write
 * won that insert, one replacing UPDATE. Last write wins by design; the
 * binding invariant lives on the read: a preview answers only while the work
 * revision it names is still the stored one.
 */
function fpw_write_draft_preview_row( int $order_id, array $payload ): bool {
	global $wpdb;
	$update = static function () use ( $wpdb, $order_id, $payload ): bool {
		$was_suppressed = $wpdb->suppress_errors();
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s",
				wp_json_encode( $payload ),
				fpw_draft_preview_row_name( $order_id )
			)
		);
		$wpdb->suppress_errors( $was_suppressed );
		return is_numeric( $updated ) && 0 < (int) $updated;
	};
	if ( $update() ) { return true; }
	if ( fpw_insert_options_row( fpw_draft_preview_row_name( $order_id ), wp_json_encode( $payload ) ) ) { return true; }
	return $update();
}

/** The preview's server-side half: CSRF (its own nonce), then the shared projection of the CURRENT saved work, frozen with its revision; the outcome is stashed for the screen. Previewing approves nothing, issues no document and sends no mail. */
function fpw_handle_draft_preview_request( $order, array $draft ): void {
	if ( ! wp_verify_nonce( (string) ( $_POST['fpw_preview_nonce'] ?? '' ), fpw_draft_preview_action( (int) $order->get_id() ) ) ) {
		wp_die( 'Tu sesión expiró o el formulario no es válido: vuelve a cargar el borrador e inténtalo de nuevo.', '', array( 'response' => 403 ) );
	}
	$order_id   = (int) $order->get_id();
	$work       = fpw_read_draft_work( $order_id );
	$projection = fpw_quotation_projection( $draft, $work );
	$payload    = array(
		'schema'     => 1,
		'order_id'   => $order_id,
		'revision'   => $work ? max( 0, (int) ( $work['revision'] ?? 0 ) ) : 0,
		'created_at' => time(),
		'created_by' => get_current_user_id(),
		'projection' => $projection,
	);
	$written = fpw_write_draft_preview_row( $order_id, $payload );
	fpw_pending_draft_save( array( 'order_id' => $order_id, 'result' => array( 'state' => $written ? 'previewed' : 'preview-failed', 'preview' => $payload ) ) );
}

/** The posted screen action, when any: a draft save or a preview generation. */
function fpw_draft_posted_action(): ?string {
	if ( ! empty( $_POST['fpw_work_save'] ) ) { return 'save'; }
	if ( ! empty( $_POST['fpw_work_preview'] ) ) { return 'preview'; }
	return null;
}

/** One screen action's server-side half: CSRF (its own nonce), then the guarded save or the preview generation; the outcome is stashed for the screen. */
function fpw_handle_draft_save_request( $order, array $draft, string $action = 'save' ): void {
	if ( 'preview' === $action ) { fpw_handle_draft_preview_request( $order, $draft ); return; }
	if ( ! wp_verify_nonce( (string) ( $_POST['fpw_draft_nonce'] ?? '' ), fpw_draft_save_action( (int) $order->get_id() ) ) ) {
		wp_die( 'Tu sesión expiró o el formulario no es válido: vuelve a cargar el borrador e inténtalo de nuevo.', '', array( 'response' => 403 ) );
	}
	$result = fpw_save_draft_work( (int) $order->get_id(), $draft, wp_unslash( $_POST ), get_current_user_id() );
	fpw_pending_draft_save( array( 'order_id' => (int) $order->get_id(), 'result' => $result ) );
}

/**
 * The actions front door, ahead of wp-admin's own header render: authorization
 * first (the capability — never the nonce — grants access), then each action's
 * own CSRF nonce, then the guarded save or the preview generation. Requests
 * without a resolved draft edit nothing; the screen answers them honestly.
 */
function fpw_handle_draft_save(): void {
	fpw_pending_draft_save( null );   // a fresh request starts with no outcome
	if ( FPW_DRAFT_SCREEN !== (string) ( $_GET['page'] ?? '' ) ) { return; }
	$action = fpw_draft_posted_action();
	if ( null === $action ) { return; }
	if ( ! current_user_can( 'manage_woocommerce' ) ) { fpw_die_draft_forbidden(); }
	$order = fpw_draft_screen_order();
	$draft = $order ? fpw_read_request_draft( (int) $order->get_id() ) : null;
	if ( ! $draft ) { return; }
	fpw_handle_draft_save_request( $order, $draft, $action );
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
	if ( 'previewed' === $result['state'] ) {
		$projection = is_array( $result['preview']['projection'] ?? null ) ? $result['preview']['projection'] : array();
		return array(
			'class' => 'ok',
			'title' => 'Vista previa generada (revisión ' . (int) ( $result['preview']['revision'] ?? 0 ) . ').',
			'lines' => array(
				empty( $projection['missing'] )
					? 'La proyección quedó guardada y ligada a esta revisión, y está completa: revisa sus importes, IVA, total y vigencia abajo.'
					: 'La proyección quedó guardada y ligada a esta revisión, e identifica faltantes que bloquean la oferta completa: revisa la vista previa abajo.',
				'La vista previa no aprueba ni envía nada al comprador: ninguna versión, ningún documento, ningún correo.',
			),
		);
	}
	if ( 'preview-failed' === $result['state'] ) {
		return array(
			'class' => 'error',
			'title' => 'No se pudo guardar la vista previa: inténtalo de nuevo.',
			'lines' => array( 'El borrador quedó exactamente como estaba.' ),
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
 * untouched stored values. The same holds for a preview generation.
 */
function fpw_render_quote_draft_screen(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) { fpw_die_draft_forbidden(); }
	$order    = fpw_draft_screen_order();
	$draft    = $order ? fpw_read_request_draft( (int) $order->get_id() ) : null;
	$work     = $order ? fpw_read_draft_work( (int) $order->get_id() ) : null;
	$notice   = null;
	$handled  = fpw_pending_draft_save();
	if ( $order && $draft && null !== fpw_draft_posted_action() && (int) ( $handled['order_id'] ?? 0 ) !== (int) $order->get_id() ) {
		// No admin_init pass on this request: run the same server-side process here.
		fpw_handle_draft_save_request( $order, $draft, fpw_draft_posted_action() );
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

/** The delivery-address provenance facts, honestly naming what the record carries (issue #59): an assistant selection is a recorded claim for review — never a certified delivery point; a manual entry is plainly manual; a record older than the feature carries no invented provenance. */
function fpw_draft_provenance_facts_html( array $destination ): string {
	$source = (string) ( $destination['source'] ?? '' );
	if ( '' === $source ) { return fpw_draft_fact_html( 'Procedencia de la dirección', 'Sin registro' ); }
	if ( 'asistida' === $source ) {
		$scope = 'amplia' === ( $destination['scope'] ?? '' )
			? 'Coincidencia amplia — revisar número y comuna'
			: 'Coincidencia exacta';
		return fpw_draft_fact_html( 'Procedencia de la dirección', 'Confirmada con el asistente de direcciones' )
			. fpw_draft_fact_html( 'Alcance de la coincidencia', $scope )
			. fpw_draft_fact_html( 'Identificación del lugar (Place ID)', (string) ( $destination['place_id'] ?? '' ) );
	}
	return fpw_draft_fact_html( 'Procedencia de la dirección', 'Ingresada manualmente' );
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
		'validity_days'   => null,
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
	$validity = $work['validity_days'] ?? null;
	if ( is_int( $validity ) && $validity >= 1 && $validity <= FPW_DRAFT_MAX_VALIDITY_DAYS ) { $values['validity_days'] = $validity; }
	return $values;
}

/** The offer validity in force for one draft: the owner's saved choice, else the default (issue #55). */
function fpw_draft_validity_days( ?array $work ): int {
	$saved = is_array( $work ) ? ( $work['validity_days'] ?? null ) : null;
	return ( is_int( $saved ) && $saved >= 1 && $saved <= FPW_DRAFT_MAX_VALIDITY_DAYS ) ? $saved : fpw_quotation_default_validity_days();
}

/** The default Quotation Validity in days, configurable through the homonymous filter; a delivered value outside 1–365 falls back to seven. */
function fpw_quotation_default_validity_days(): int {
	$days = apply_filters( 'fpw_quotation_default_validity_days', 7 );
	if ( ! is_int( $days ) || $days < 1 || $days > FPW_DRAFT_MAX_VALIDITY_DAYS ) { return 7; }
	return $days;
}

/**
 * The confirmed fiscal policy (issue #55): a configuration delivered ONLY
 * through this filter, exactly like the Places key — the owner's confirmed
 * IVA rate and the fiscal treatment of dispatch. ABSENT by default: the 19%
 * of the prototype and its arithmetic are not a fiscal approval, so no rate
 * is ever invented here — without a delivered policy the projection's IVA and
 * total stay pending and the offer stays incomplete. A malformed delivery
 * (non-integer rate, out of 0–5000‰) degrades to absent instead of becoming
 * authority.
 *
 * @return array{rate_permille:int, applies_to_dispatch:bool}|array{} Empty when no confirmed policy is configured.
 */
function fpw_quotation_tax_config(): array {
	$config = apply_filters( 'fpw_quotation_tax_config', array() );
	if ( ! is_array( $config ) ) { return array(); }
	$rate = $config['rate_permille'] ?? null;
	if ( ! is_int( $rate ) || $rate < 0 || $rate > FPW_QUOTATION_MAX_RATE_PERMILLE ) { return array(); }
	return array( 'rate_permille' => $rate, 'applies_to_dispatch' => ! empty( $config['applies_to_dispatch'] ) );
}

/**
 * The deterministic tax kernel: exact integer arithmetic only — never a
 * float. `base × rate_permille / 1000` with nearest (half-up) rounding,
 * computed in two integer steps so the intermediate product cannot overflow
 * for any accepted input. One function because the preview and the later
 * issuance must round identically (issue #55).
 */
function fpw_quotation_tax_amount( int $base, int $rate_permille ): int {
	if ( $base <= 0 || $rate_permille <= 0 ) { return 0; }
	$whole = intdiv( $base, 1000 ) * $rate_permille;
	$rest  = intdiv( ( $base % 1000 ) * $rate_permille + 500, 1000 );
	return $whole + $rest;
}

/**
 * THE quotation projection (issue #55, corte 6 de #49): ONE deterministic
 * server-side calculation of the buyer-facing offer from the draft's saved
 * working state — per-line net amounts, subtotal, dispatch, IVA and total,
 * plus the offer validity. The preview renders it and the later issuance
 * (cut 7) must consume the SAME projection: there are no parallel per-screen
 * calculations. Every gap is named in `missing` and stays pending — never a
 * zero; an incomplete projection can never pass for a complete offer.
 *
 * Completeness needs: every line priced, and — when dispatch is requested —
 * an entered, fresh dispatch amount AND a working destination; plus a
 * confirmed fiscal policy. Missing purchase history or address assistance
 * never blocks: the commercial data is what the offer carries.
 *
 * @return array{schema:int,lines:array[],subtotal:?int,dispatch_requested:bool,dispatch:?int,validity_days:int,destination:string,tax_rate_permille:?int,tax_applies_to_dispatch:bool,taxable_base:?int,tax:?int,total:?int,missing:string[],complete:bool}
 */
function fpw_quotation_projection( array $draft, ?array $work ): array {
	$items          = is_array( $draft['items'] ?? null ) ? $draft['items'] : array();
	$values         = fpw_draft_work_values( $draft, $work );
	$with_dispatch  = fpw_draft_requests_dispatch( $draft );
	$stale_dispatch = fpw_draft_dispatch_stale( $work );
	$missing        = array();
	$lines          = array();
	$subtotal       = null;
	$all_priced     = ! empty( $values['lines'] );
	foreach ( $values['lines'] as $i => $work_line ) {
		$item      = is_array( $items[ $i ] ?? null ) ? $items[ $i ] : array();
		$quantity  = (int) $work_line['quantity'];
		$price     = $work_line['price'] ?? null;
		$line_total = null;
		if ( null === $price ) {
			$all_priced = false;
			$missing[] = 'Falta el precio neto de «' . (string) ( $item['name'] ?? ( 'Línea ' . ( $i + 1 ) ) ) . '»: la línea queda pendiente, no en cero.';
		} else {
			$line_total = $quantity * $price;
			$subtotal   = ( $subtotal ?? 0 ) + $line_total;
		}
		$lines[] = array(
			'index'        => $i,
			'name'         => (string) ( $item['name'] ?? '' ),
			'options'      => is_array( $item['options'] ?? null ) ? $item['options'] : array(),
			'quantity'     => $quantity,
			'price'        => $price,
			'line_total'   => $line_total,
		);
	}
	$destination = (string) $values['destination'];
	$dispatch    = null;
	if ( $with_dispatch ) {
		if ( '' === $destination ) {
			$missing[] = 'Falta el destino de entrega de trabajo: la oferta de despacho necesita su destino.';
		}
		if ( null === $values['dispatch_amount'] ) {
			$missing[] = 'Falta el monto de despacho: el despacho solicitado sigue sin valorizar (no es cero).';
		} elseif ( $stale_dispatch ) {
			$missing[] = 'El monto de despacho requiere revisión: fue ingresado para otro destino u otras cantidades.';
		} else {
			$dispatch = $values['dispatch_amount'];
		}
	}
	$tax_config = fpw_quotation_tax_config();
	$tax_rate   = null;
	$tax_base   = null;
	$tax        = null;
	$total      = null;
	if ( empty( $tax_config ) ) {
		$missing[] = 'Falta la política fiscal confirmada (tasa de IVA): sin ella el IVA y el total quedan pendientes.';
	} elseif ( $all_priced && ( ! $with_dispatch || null !== $dispatch ) ) {
		$tax_rate = (int) $tax_config['rate_permille'];
		$tax_base = $subtotal + ( $with_dispatch && $tax_config['applies_to_dispatch'] ? ( $dispatch ?? 0 ) : 0 );
		$tax      = fpw_quotation_tax_amount( $tax_base, $tax_rate );
		$total    = $subtotal + ( $with_dispatch ? ( $dispatch ?? 0 ) : 0 ) + $tax;
	}
	return array(
		'schema'                  => 1,
		'lines'                   => $lines,
		'subtotal'                => $all_priced ? $subtotal : null,
		'dispatch_requested'      => $with_dispatch,
		'dispatch'                => $dispatch,
		'validity_days'           => fpw_draft_validity_days( $work ),
		'destination'             => $with_dispatch ? $destination : '',
		'tax_rate_permille'       => $tax_rate,
		'tax_applies_to_dispatch' => ! empty( $tax_config['applies_to_dispatch'] ),
		'taxable_base'            => $tax_base,
		'tax'                     => $tax,
		'total'                   => $total,
		'missing'                 => $missing,
		'complete'                => empty( $missing ),
	);
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

/** One exact integer CLP amount as the offer states it — deterministic text for a deterministic amount. */
function fpw_draft_clp_html( int $amount ): string {
	return esc_html( number_format( $amount, 0, ',', '.' ) ) . ' CLP';
}

/** The offer-validity input: pre-filled with the saved days, else empty with the default as its placeholder. */
function fpw_draft_validity_input_html( string $name, ?int $value ): string {
	$default = fpw_quotation_default_validity_days();
	return '<input type="number" inputmode="numeric" min="1" max="' . FPW_DRAFT_MAX_VALIDITY_DAYS . '" step="1" name="' . esc_attr( $name ) . '" value="' . ( null !== $value ? $value : '' ) . '" placeholder="' . $default . ' (por defecto)" />';
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
		. '.fpw-draft__no-history{font-weight:700}'
		. '.fpw-draft__history{list-style:none;margin:0;padding:0;display:grid;gap:6px}'
		. '.fpw-draft__history li{border:1px solid #e4e4e8;border-radius:6px;padding:8px 10px;overflow-wrap:anywhere}'
		. '.fpw-draft__sale-date{font-weight:600;white-space:nowrap}'
		. '.fpw-draft__sale-total{font-weight:700;white-space:nowrap}'
		. '.fpw-draft__aside-note{color:#60626d;font-size:14px;margin:6px 0 0}'
		. '.fpw-draft__preview form{display:grid;gap:8px;justify-items:start;margin-top:12px}'
		. '.fpw-draft__preview button{font-size:16px;font-weight:600;padding:10px 18px;border-radius:6px;background:#1f2a44;color:#fff;border:0;cursor:pointer}'
		. '.fpw-draft__preview-ok{color:#1d5a2e;font-weight:600;margin:8px 0 0}'
		. '.fpw-draft__preview-state{margin:8px 0 0;font-weight:600}'
		. '.fpw-draft__preview-state.is-incomplete{color:#8a1d1d}'
		. '.fpw-draft__missing{margin:6px 0 0 18px;padding:0;display:grid;gap:4px}'
		. '.fpw-draft__missing li{color:#8a1d1d}'
		. '.fpw-draft__preview-dest{margin:10px 0 0}'
		. '.fpw-draft__preview-validity{margin:10px 0 0}'
		. '.fpw-draft table{width:100%;border-collapse:collapse;margin:10px 0 0;font-size:14px}'
		. '.fpw-draft table caption{text-align:left;font-size:13px;color:#60626d;padding-bottom:4px}'
		. '.fpw-draft table th,.fpw-draft table td{border:1px solid #e4e4e8;padding:6px 8px;text-align:left;overflow-wrap:anywhere;vertical-align:top}'
		. '.fpw-draft table th{background:#f6f7f7;font-weight:600}'
		. '.fpw-draft__line-total{font-weight:700;white-space:nowrap}'
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
	$identity    = is_array( $draft['identity'] ?? null ) ? $draft['identity'] : array();
	$rut         = (string) ( $identity['rut'] ?? '' );
	$normalized  = fpw_sales_normalize_rut( $rut );
	$history     = fpw_sales_history_for_rut( $normalized );
	$import_link = '<p class="fpw-draft__aside-note"><a href="' . esc_url( fpw_sales_import_screen_url() ) . '">Importar ventas</a></p>';
	$heading     = '<section><h2>Historial de compras</h2><p><strong class="fpw-draft__no-history">Sin historial asociado</strong></p>';
	if ( null === $history ) {
		return $heading . '<p class="fpw-draft__aside-note">La solicitud no trae un RUT de empresa utilizable, así que ninguna compra importada puede asociarse. Eso no indica que el cliente sea nuevo ni conocido.</p>'
			. $import_link . '</section>';
	}
	if ( empty( $history['sales'] ) ) {
		return $heading . '<p class="fpw-draft__aside-note">Ninguna venta importada coincide con el RUT ' . esc_html( $rut ) . '. No se inventó ninguna asociación: esto no indica que el cliente sea nuevo.</p>'
			. $import_link . '</section>';
	}
	$lines = '';
	foreach ( $history['sales'] as $sale ) {
		$total = fpw_sales_format_clp( $sale['total'] ?? null );
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
		. $import_link . '</section>';
}

/** The preview-generation control: its own form, its own nonce, its own action — never the work-save form. */
function fpw_draft_preview_generate_html( int $order_id ): string {
	return '<form method="post" action="' . esc_url( fpw_draft_screen_url( $order_id ) ) . '">'
		. '<input type="hidden" name="fpw_work_preview" value="1" />'
		. wp_nonce_field( fpw_draft_preview_action( $order_id ), 'fpw_preview_nonce', true, false )
		. '<button type="submit">Generar vista previa</button>'
		. '<span class="fpw-draft__origin">Usa el trabajo guardado de este momento y queda ligado a su revisión; no aprueba ni envía nada al comprador.</span>'
		. '</form>';
}

/** One projection line row: what the buyer would read, with the working quantity and the net amounts — pending lines named, never zero. */
function fpw_draft_preview_line_html( array $line ): string {
	$options = '';
	foreach ( ( is_array( $line['options'] ?? null ) ? $line['options'] : array() ) as $option ) {
		$options .= '<span class="fpw-draft__option">' . esc_html( wc_attribute_label( (string) $option['key'] ) . ': ' . (string) $option['value'] ) . '</span> ';
	}
	$price = is_int( $line['price'] ?? null ) ? fpw_draft_clp_html( (int) $line['price'] ) : fpw_draft_pending_html();
	$total = is_int( $line['line_total'] ?? null ) ? fpw_draft_clp_html( (int) $line['line_total'] ) : fpw_draft_pending_html();
	return '<tr><th scope="row"><strong>' . esc_html( (string) ( $line['name'] ?? '' ) ) . '</strong>' . ( '' !== $options ? '<br>' . trim( $options ) : '' ) . '</th>'
		. '<td>' . (int) $line['quantity'] . ' × ' . $price . '</td><td class="fpw-draft__line-total">' . $total . '</td></tr>';
}

/**
 * The stored preview of the buyer-facing projection (issue #55): rendered
 * EXACTLY as it was reviewed — from the stored row, never recomputed — and
 * marked obsolete the moment the working state moved past its revision. It
 * carries the products, quantities, net prices, subtotal, dispatch, IVA,
 * total and validity the owner reviewed; it excludes purchase history,
 * internal notes, carrier costs and the dispatch estimate breakdown, and it
 * is not an issued document: nothing was approved or sent.
 */
function fpw_draft_preview_html( array $draft, ?array $work, ?array $preview ): string {
	$order_id = (int) ( $draft['order_id'] ?? 0 );
	$heading  = '<section class="fpw-draft__preview"><h2>Vista previa para el comprador</h2>';
	if ( ! is_array( $preview ) ) {
		return $heading . '<p>Aún no hay vista previa guardada: genera una para revisar la proyección que recibiría el comprador con el trabajo guardado hasta ahora.</p>' . fpw_draft_preview_generate_html( $order_id ) . '</section>';
	}
	$projection = is_array( $preview['projection'] ?? null ) ? $preview['projection'] : null;
	if ( null === $projection ) {
		return $heading . '<p>' . fpw_draft_pending_html() . '</p>' . fpw_draft_preview_generate_html( $order_id ) . '</section>';
	}
	$revision = max( 0, (int) ( $preview['revision'] ?? 0 ) );
	$current  = $work ? max( 0, (int) ( $work['revision'] ?? 0 ) ) : 0;
	$obsolete = $revision !== $current;
	$html = $heading
		. ( $obsolete
			? '<p class="fpw-draft__stale">Vista previa obsoleta: el trabajo del borrador cambió después de esta vista previa (quedó en la revisión ' . $current . ', esta revisó la ' . $revision . '). Ya no refleja lo guardado: genera una nueva. Ninguna aprobación futura puede usar esta vista previa para emitir valores distintos a los revisados.</p>'
			: '<p class="fpw-draft__preview-ok">Vista previa de la revisión ' . $revision . ' · generada el ' . esc_html( date_i18n( get_option( 'date_format' ), (int) ( $preview['created_at'] ?? 0 ) ) ) . '.</p>' )
		. ( empty( $projection['missing'] )
			? '<p class="fpw-draft__preview-state">La proyección está completa: todos los importes, el IVA y el total quedaron calculados.</p>'
			: '<p class="fpw-draft__preview-state is-incomplete">La proyección identifica faltantes que bloquean la oferta completa:</p><ul class="fpw-draft__missing">'
				. implode( '', array_map( static fn( $item ) => '<li>' . esc_html( (string) $item ) . '</li>', (array) $projection['missing'] ) ) . '</ul>' );
	$rows = '';
	foreach ( (array) ( $projection['lines'] ?? array() ) as $line ) { $rows .= fpw_draft_preview_line_html( is_array( $line ) ? $line : array() ); }
	$html .= '<table><caption>Lo que revisa el comprador</caption><tbody>' . $rows;
	$html .= '<tr><th scope="row">Subtotal (neto)</th><td></td><td class="fpw-draft__line-total">' . ( is_int( $projection['subtotal'] ?? null ) ? fpw_draft_clp_html( (int) $projection['subtotal'] ) : fpw_draft_pending_html() ) . '</td></tr>';
	if ( ! empty( $projection['dispatch_requested'] ) ) {
		$html .= '<tr><th scope="row">Despacho (neto)</th><td></td><td class="fpw-draft__line-total">' . ( is_int( $projection['dispatch'] ?? null ) ? fpw_draft_clp_html( (int) $projection['dispatch'] ) : fpw_draft_pending_html() ) . '</td></tr>';
	}
	$html .= null === ( $projection['tax_rate_permille'] ?? null )
		? '<tr><th scope="row">IVA</th><td></td><td class="fpw-draft__line-total">' . fpw_draft_pending_html() . '</td></tr>'
		: '<tr><th scope="row">IVA (' . esc_html( rtrim( rtrim( number_format( (int) $projection['tax_rate_permille'] / 10, 1, ',', '.' ), '0' ), ',' ) ) . '%)</th><td></td><td class="fpw-draft__line-total">' . fpw_draft_clp_html( (int) $projection['tax'] ) . '</td></tr>';
	$html .= '<tr><th scope="row">Total</th><td></td><td class="fpw-draft__line-total">' . ( is_int( $projection['total'] ?? null ) ? fpw_draft_clp_html( (int) $projection['total'] ) : fpw_draft_pending_html() ) . '</td></tr>'
		. '</tbody></table>';
	if ( ! empty( $projection['dispatch_requested'] ) && '' !== (string) ( $projection['destination'] ?? '' ) ) {
		$html .= '<p class="fpw-draft__preview-dest">Destino de la oferta: ' . esc_html( (string) $projection['destination'] ) . '</p>';
	}
	$html .= '<p class="fpw-draft__preview-validity"><strong>Vigencia de la oferta: ' . (int) ( $projection['validity_days'] ?? 0 ) . ' días</strong> a contar de su aprobación, para los productos, cantidades y destino revisados.</p>';
	$html .= '<p class="fpw-draft__aside-note">Esta vista previa no incluye historial de compras, notas internas, costos de transportista ni el desglose interno de la estimación de despacho. No es un documento emitido: nada fue aprobado ni enviado al comprador.</p>'
		. fpw_draft_preview_generate_html( $order_id )
		. '</section>';
	return $html;
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
	$preview         = fpw_read_draft_preview( $order_id );
	$validity_days   = fpw_draft_validity_days( $work );

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
		. '<section><h2>Solicitud original</h2><dl>' . fpw_draft_facts_html( $identity, $destination, $with_dispatch ) . ( $with_dispatch ? fpw_draft_provenance_facts_html( $destination ) : '' ) . fpw_draft_submitted_details_html( $draft['submitted_details'] ?? '' ) . '</section>'
		. $form_open
		. '<section><h2>Productos solicitados</h2><ul class="fpw-draft__items">' . fpw_draft_items_html( $items, $values ) . '</ul></section>'
		. '<section><h2>Despacho</h2>' . fpw_draft_dispatch_html( $with_dispatch, $values['destination'], $dispatch_amount, $dispatch_stale ) . '</section>'
		. '<section><h2>Vigencia de la oferta</h2><label class="fpw-draft__field">Días de vigencia (1 a ' . FPW_DRAFT_MAX_VALIDITY_DAYS . ')' . fpw_draft_validity_input_html( 'fpw_work[validity_days]', $values['validity_days'] ) . '</label>'
		. '<p class="fpw-draft__origin">Los precios ofrecidos valen por estos días a contar de la aprobación de la oferta. La vigencia por defecto es de ' . fpw_quotation_default_validity_days() . ' días.</p></section>'
		. $save_section
		. '</form>'
		. fpw_draft_preview_html( $draft, $work, $preview )
		. '</div>';

	$prices_state = $all_priced ? 'Ingresados manualmente por el dueño' : fpw_draft_pending_html();
	$dispatch_state = ! $with_dispatch
		? 'No requerida (sin despacho)'
		: ( null !== $dispatch_amount ? ( $dispatch_stale ? 'Requiere revisión' : 'Ingresada manualmente' ) : fpw_draft_pending_html() );
	$preview_state = ! is_array( $preview )
		? fpw_draft_pending_html()
		: ( (int) ( $preview['revision'] ?? -1 ) !== $revision
			? 'Obsoleta — el trabajo cambió después de la revisión ' . (int) ( $preview['revision'] ?? 0 )
			: 'Generada (revisión ' . $revision . ')' );

	$aside = '<aside style="display:grid;gap:16px;min-width:0;align-content:start">'
		. fpw_draft_history_section( $draft )
		. '<section><h2>Estado del borrador</h2><dl>'
		. fpw_draft_fact_raw_html( 'Precios', $prices_state )
		. fpw_draft_pending_fact_html( 'Historial' )
		. fpw_draft_fact_raw_html( 'Estimación de despacho', $dispatch_state )
		. fpw_draft_fact_html( 'Vigencia de la oferta', $validity_days . ' días' )
		. fpw_draft_fact_raw_html( 'Vista previa', $preview_state )
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
