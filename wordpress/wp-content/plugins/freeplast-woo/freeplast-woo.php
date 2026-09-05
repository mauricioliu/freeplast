<?php
/**
 * Plugin Name: Freeplast WooCommerce Integration
 * Description: Local quote-only rules and Chilean fields. WooCommerce owns cart, checkout, orders and administration.
 * Version: 1.3.0
 * Requires Plugins: woocommerce, quotes-for-woocommerce
 * Requires PHP: 8.1
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'before_woocommerce_init', static function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

// Request-only site: no shipping price, stock reservation, payment or account flow.
add_filter( 'woocommerce_cart_needs_shipping', '__return_false', 999 );
add_filter( 'woocommerce_hold_stock_for_checkout', '__return_false', 999 );
add_filter( 'woocommerce_can_reduce_order_stock', '__return_false', 999 );
add_filter( 'woocommerce_order_needs_payment', '__return_false', 999 );
add_filter( 'woocommerce_checkout_registration_enabled', '__return_false', 999 );
add_filter( 'woocommerce_checkout_registration_required', '__return_false', 999 );
add_filter( 'woocommerce_available_payment_gateways', static function ( $gateways ) {
	return isset( $gateways['quotes-gateway'] ) ? array( 'quotes-gateway' => $gateways['quotes-gateway'] ) : array();
}, 999 );
add_action( 'template_redirect', static function () {
	if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
		wp_safe_redirect( home_url( '/contacto/' ) ); exit;
	}
} );
// No priced offers or fictional stock availability in public structured data.
add_filter( 'woocommerce_structured_data_product', static function ( $data ) {
	unset( $data['offers'], $data['aggregateRating'], $data['review'] ); return $data;
}, 999 );
add_filter( 'woocommerce_get_price_html', '__return_empty_string', 9999 );
add_filter( 'woocommerce_order_get_formatted_order_total', static fn() => 'Por cotizar', 999 );
add_filter( 'woocommerce_get_order_item_totals', '__return_empty_array', 999 );
add_filter('wc_order_statuses', static function($statuses) {
	$statuses['wc-pending'] = 'Solicitud recibida';
	return $statuses;
});
add_filter('woocommerce_get_privacy_policy_text', static fn() => 'Usaremos tus datos para preparar y responder tu solicitud de cotización. Consulta nuestra <a href="'.esc_url(home_url('/politica-de-privacidad/')).'">política de privacidad</a>.');
add_filter('wc_add_to_cart_message_html', static function($message,$products) {
	$parts=array(); foreach($products as $id=>$quantity) { $parts[]=get_the_title($id).' × '.(int)$quantity; }
	return esc_html(implode(', ',$parts)).' se agregó a Productos a Cotizar. <a class="button wc-forward" href="'.esc_url(wc_get_cart_url()).'">Ver Productos a Cotizar</a>';
}, 1000, 2);
add_action('woocommerce_single_product_summary', static function() {
	global $product;
	if ($product && '1' === get_post_meta($product->get_id(),'_fp_image_provisional',true)) {
		echo '<p class="fp-photo-note">Fotografía referencial. La imagen puede no representar el color o la configuración seleccionados.</p>';
	}
}, 25);

/**
 * Header Productos a Cotizar count: distinct cart lines, never units. Every
 * variant/colour is its own line (Woo keys each cart entry by product +
 * variation + attributes), so counting cart entries is the contract-v6 line
 * definition. Zero when Woo or the cart is unavailable, so every route renders
 * a coherent state without extra per-request queries beyond the session cart
 * Woo already loads.
 */
function fpw_cart_line_count(): int {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) { return 0; }
	return count( WC()->cart->get_cart() );
}

/**
 * Productos destacados on Home (issue #27, finding WA-04). The template renders
 * the featured grid through a wp:shortcode block, and WordPress' core/shortcode
 * renderer runs wpautop() over the shortcode's EXPANDED output — the native Woo
 * loop markup is split at its internal blank lines and `</p>`/`<p>` pairs land
 * inside the product link (the unnamed-link defect measured by axe). This
 * plugin-rendered dynamic block (PRD: "plugin-rendered dynamic blocks or
 * equivalent stable rendering APIs") executes the SAME native Woo
 * [products] shortcode inside do_blocks, where no wpautop runs: the delivered
 * card markup is byte-for-byte Woo's own loop, whose link always carries the
 * product title text and the alt-bearing image. No card markup is owned here.
 */
function fpw_render_featured_products( $attributes ): string {
	if ( ! function_exists( 'WC' ) || ! function_exists( 'do_shortcode' ) ) { return ''; }
	$limit   = max( 1, absint( $attributes['limit'] ?? 8 ) );
	$columns = max( 1, absint( $attributes['columns'] ?? 4 ) );
	return do_shortcode( '[products limit="' . $limit . '" columns="' . $columns . '" visibility="featured" orderby="menu_order"]' );
}

add_action( 'init', static function () {
	if ( ! function_exists( 'register_block_type' ) || ! function_exists( 'WC' ) ) { return; }
	register_block_type( 'freeplast-woo/featured-products', array(
		'api_version'     => 2,
		'attributes'      => array(
			'limit'   => array( 'type' => 'number', 'default' => 8 ),
			'columns' => array( 'type' => 'number', 'default' => 4 ),
		),
		'render_callback' => 'fpw_render_featured_products',
	) );
}, 20 );

/** The header count participates in Woo's native add-to-cart fragment refresh. */
add_filter( 'woocommerce_add_to_cart_fragments', static function ( $fragments ) {
	$fragments['span.fpw-basket-count'] = '<span class="fpw-basket-count">' . fpw_cart_line_count() . '</span>';
	return $fragments;
} );

/** One definition for local fields; native Woo validates and persists the billing fields. */
function fpw_checkout_fields( $fields ) {
	$fields['billing'] = array(
		'billing_first_name' => array( 'label'=>'Nombre', 'required'=>true, 'type'=>'text', 'autocomplete'=>'name', 'class'=>array('form-row-wide'), 'priority'=>10 ),
		'billing_phone' => array( 'label'=>'Teléfono', 'required'=>true, 'type'=>'tel', 'autocomplete'=>'tel', 'class'=>array('form-row-wide'), 'priority'=>20 ),
		'billing_email' => array( 'label'=>'Email', 'required'=>true, 'type'=>'email', 'autocomplete'=>'email', 'validate'=>array('email'), 'class'=>array('form-row-wide'), 'priority'=>30 ),
		'billing_company' => array( 'label'=>'Nombre Empresa', 'required'=>true, 'type'=>'text', 'autocomplete'=>'organization', 'class'=>array('form-row-wide'), 'priority'=>40 ),
		'billing_fp_rut' => array( 'label'=>'RUT Empresa', 'required'=>true, 'type'=>'text', 'placeholder'=>'76.123.456-7', 'class'=>array('form-row-wide'), 'priority'=>50 ),
		'billing_fp_giro' => array( 'label'=>'Giro', 'required'=>true, 'type'=>'text', 'placeholder'=>'Ej.: producción agrícola', 'class'=>array('form-row-wide'), 'priority'=>60 ),
		'billing_fp_dispatch' => array( 'label'=>'¿Necesitas despacho?', 'required'=>true, 'type'=>'select', 'options'=>array(''=>'Selecciona una opción','si'=>'Con despacho','no'=>'Sin despacho'), 'class'=>array('form-row-wide'), 'priority'=>70 ),
		'billing_fp_address' => array( 'label'=>'Dirección de despacho', 'required'=>false, 'type'=>'textarea', 'placeholder'=>'Calle, número, comuna y región', 'class'=>array('form-row-wide'), 'priority'=>80 ),
	);
	$fields['shipping'] = array();
	$fields['order']['order_comments']['label'] = 'Mensaje';
	$fields['order']['order_comments']['placeholder'] = 'Información adicional para ventas (opcional)';
	return $fields;
}
add_filter( 'woocommerce_checkout_fields', 'fpw_checkout_fields', 20000 );

function fpw_validate_checkout( $data, $errors ) {
	$dispatch = $data['billing_fp_dispatch'] ?? '';
	if ( ! in_array( $dispatch, array('si','no'), true ) ) { $errors->add('billing_fp_dispatch', 'Selecciona si necesitas despacho.', array('id'=>'billing_fp_dispatch')); }
	if ( 'si' === $dispatch && '' === trim( $data['billing_fp_address'] ?? '' ) ) { $errors->add('billing_fp_address', 'Indica la dirección completa de despacho.', array('id'=>'billing_fp_address')); }
	foreach ( array('billing_first_name','billing_company','billing_fp_rut','billing_fp_giro','billing_phone','billing_email','billing_fp_address') as $key ) {
		if ( strlen( $data[$key] ?? '' ) > ( 'billing_fp_address' === $key ? 800 : 240 ) ) { $errors->add($key, 'El campo es demasiado largo.', array('id'=>$key)); }
	}
	if ( 'quotes-gateway' !== ( $data['payment_method'] ?? '' ) ) { $errors->add('payment_method', 'Este sitio recibe solicitudes de cotización, no pagos.'); }
}
add_action( 'woocommerce_after_checkout_validation', 'fpw_validate_checkout', 10, 2 );

add_action( 'woocommerce_checkout_create_order', static function ( $order, $data ) {
	$order->update_meta_data( '_qwc_quote', '1' );
	$order->update_meta_data( '_quote_status', 'quote-pending' );
	$order->update_meta_data( '_fp_request', 'yes' );
	foreach ( array('rut','giro','dispatch','address') as $field ) {
		$value = $data['billing_fp_'.$field] ?? '';
		if ( 'address' === $field && 'si' !== ( $data['billing_fp_dispatch'] ?? '' ) ) { $value = ''; }
		$order->update_meta_data( '_billing_fp_'.$field, sanitize_textarea_field( $value ) );
	}
	$order->update_meta_data( '_fp_submitted_details', array_intersect_key( $data, array_flip(array('billing_first_name','billing_company','billing_phone','billing_email','billing_fp_rut','billing_fp_giro','billing_fp_dispatch')) ) + array('billing_fp_address'=>'si' === ($data['billing_fp_dispatch'] ?? '') ? ($data['billing_fp_address'] ?? '') : '') );
}, 20, 2 );
add_filter( 'woocommerce_order_number', static function ( $number, $order ) {
	$legacy = $order->get_meta( '_fpq_reference' );
	return $legacy ?: ( 'yes' === $order->get_meta('_fp_request') ? 'FP-'.($order->get_date_created() ? $order->get_date_created()->date('Y') : gmdate('Y')).'-'.sprintf('%06d',$order->get_id()) : $number );
}, 10, 2 );

// Reuse Woo's checkout session for back-navigation. No separate session/cart system.
function fpw_draft_keys() { return array_merge(array_keys(fpw_checkout_fields(array())['billing']),array('order_comments')); }
add_action( 'woocommerce_checkout_update_order_review', static function ( $serialized ) {
	parse_str( $serialized, $data );
	foreach ( fpw_draft_keys() as $key ) {
		if ( isset($data[$key]) && is_string($data[$key]) ) { WC()->session->set( 'fpw_'.$key, mb_substr(sanitize_textarea_field($data[$key]),0,4000) ); }
	}
} );
add_filter( 'woocommerce_checkout_get_value', static function ( $value, $key ) {
	return null === $value && WC()->session ? WC()->session->get('fpw_'.$key, $value) : $value;
}, 10, 2 );
add_action( 'woocommerce_cart_emptied', static function () {
	if (WC()->session) { foreach (fpw_draft_keys() as $key) { WC()->session->__unset('fpw_'.$key); } }
}, 100 );

add_action( 'wp_enqueue_scripts', static function () {
	if ( function_exists('is_checkout') && is_checkout() && ! is_order_received_page() ) {
		wp_enqueue_script('fpw-fields', plugins_url('fields.js', __FILE__), array('jquery','wc-checkout'), '1.0.2', true);
	}
} );

/**
 * Ventas Freeplast — least-privilege sales access over Woo's own order
 * administration (issue #25, finding WA-02). The role carries only `read`,
 * the retained sales capability, and the two Woo caps the native Pedidos
 * surfaces verifiably require (pinned Woo 11.1.0 + WP map_meta_cap):
 *   - edit_shop_orders: the Orders list/search screen (the CPT's edit_posts)
 *     and the private-note AJAX (WC_AJAX::add_order_note).
 *   - edit_others_shop_orders: the top-level WooCommerce menu and the order
 *     detail (orders have no author, so edit_post maps here).
 * Deletes, catalog, coupons, terms, settings, reports and the quotes
 * extension's own priced actions (manage_woocommerce) stay ungranted.
 */
define( 'FPW_SALES_ROLE', 'ventas_freeplast' );

/** The approved capability set — nothing else is granted or kept. */
function fpw_sales_role_caps(): array {
	return array(
		'read'                    => true,
		'manage_freeplast_quotes' => true,
		'edit_shop_orders'        => true,
		'edit_others_shop_orders' => true,
	);
}

/**
 * Idempotent, self-healing definition of the sales role: create when
 * missing, restore any missing approved capability, strip anything else
 * (least privilege), and never touch other roles. Cheap enough to run on
 * every request; writes only when the stored definition drifted.
 *
 * @return bool Whether the stored role definition changed.
 */
function fpw_sync_sales_role(): bool {
	$caps = fpw_sales_role_caps();
	$role = get_role( FPW_SALES_ROLE );
	if ( null === $role ) {
		add_role( FPW_SALES_ROLE, 'Ventas Freeplast', $caps );
		return true;
	}
	$changed = false;
	foreach ( array_keys( $caps ) as $cap ) {
		if ( ! $role->has_cap( $cap ) ) {
			$role->add_cap( $cap );
			$changed = true;
		}
	}
	foreach ( array_keys( $role->capabilities ) as $cap ) {
		if ( ! isset( $caps[ $cap ] ) ) {
			$role->remove_cap( $cap );
			$changed = true;
		}
	}
	return $changed;
}
add_action( 'init', 'fpw_sync_sales_role', 20 );
register_activation_hook( __FILE__, 'fpw_sync_sales_role' );

/**
 * Order-limited staff: holds the order caps without general Woo
 * administration. Every ventas guard below keys off this predicate, so
 * administrators/shop_managers (manage_woocommerce) keep Woo's full
 * native behavior everywhere.
 */
function fpw_is_order_limited_staff(): bool {
	return current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' );
}

/**
 * Ventas may enter wp-admin: by default Woo redirects users without the
 * WordPress primitive edit_posts (or manage_woocommerce) to My Account
 * (WC_Admin::prevent_admin_access), and the sales role deliberately does
 * not carry edit_posts — it would grant wide post/page editing. The two
 * order caps are the narrower key to the same door; every screen beyond
 * it stays capability-checked by WordPress itself.
 */
function fpw_allow_sales_admin_access( $prevent ): bool {
	return $prevent && ! fpw_is_order_limited_staff();
}
add_filter( 'woocommerce_prevent_admin_access', 'fpw_allow_sales_admin_access' );

/**
 * Sales notes written by ventas are private at the origin: the native
 * note metabox posts a visibility choice, and for order-limited staff it
 * is normalized to private before Woo's own AJAX handler reads it
 * (WC_AJAX::add_order_note → add_order_note with is_customer_note = 0).
 * Server-side enforcement — the matching UI is reduced below, but this
 * normalization does not depend on it.
 */
function fpw_force_private_sales_note(): void {
	if ( ! wp_doing_ajax() || 'woocommerce_add_order_note' !== ( $_REQUEST['action'] ?? '' ) || ! fpw_is_order_limited_staff() ) {
		return;
	}
	$_REQUEST['note_type'] = '';
	$_POST['note_type']    = '';
}
add_action( 'admin_init', 'fpw_force_private_sales_note', 0 );

/** Email resends are outside the approved scope: removed from the order-actions select for ventas. */
function fpw_sales_order_actions( array $actions ): array {
	if ( fpw_is_order_limited_staff() ) {
		unset( $actions['send_order_details'], $actions['send_order_details_admin'], $actions['regenerate_download_permissions'] );
	}
	return $actions;
}
add_filter( 'woocommerce_order_actions', 'fpw_sales_order_actions' );

/**
 * …and denied on the server even for a crafted post: both email resends
 * fire this hook before sending, and the manual invoice email bypasses
 * Woo's enabled-check (WC_Email::send_if_recipient), so the disabled-email
 * filters alone cannot be the guard.
 */
function fpw_deny_sales_email_resend(): void {
	if ( fpw_is_order_limited_staff() ) {
		wp_die( 'No tienes permisos para reenviar correos de esta solicitud.', '', array( 'response' => 403 ) );
	}
}
add_action( 'woocommerce_before_resend_order_emails', 'fpw_deny_sales_email_resend', 0 );

/** The note-visibility select is inert for ventas (normalized above): keep the UI honest by not offering it. */
function fpw_sales_note_visibility_style(): void {
	if ( ! fpw_is_order_limited_staff() ) {
		return;
	}
	echo '<style>.order_note_visibility{display:none}</style>';
}
add_action( 'admin_head', 'fpw_sales_note_visibility_style' );

// Request-only site: no note-to-customer email — completes the disabled set below.
add_filter( 'woocommerce_email_enabled_customer_note', '__return_false', 999 );

/**
 * One attempt, one Quote Request (issue #24, finding WA-01): two concurrent
 * POSTs of the same checkout (same session, form token and lines) both passed
 * every native check and created two orders — the browser's disabled button is
 * not server idempotency. Woo keeps owning cart, session and orders
 * (ADR-0001); the adapter adds only an atomic per-attempt claim and recovery
 * of the winner's own confirmation, so a repeated probe returns ONE order.
 *
 * Discipline ported from the reviewed legacy implementation: the claim is a
 * single options row keyed by the attempt's idempotency hash, inserted as a
 * plain INSERT — the unique option_name rejects the second insert, so exactly
 * one concurrent request owns the attempt. The option API is bypassed on
 * purpose (add_option() is an upsert that answers later reads from the
 * per-request cache, hiding defeats). The loser waits a bounded moment and
 * recovers the winner's order through Woo's own woocommerce_create_order
 * short-circuit: Woo itself loads that order, runs its own gateway step and
 * returns the winner's confirmation URL — no parallel submission system. The
 * landed order is also bound durably in a dedicated lookup row (unique
 * option_name → order id), so replays fold into the original request even
 * after the claim row is swept. The order also carries its attempt hash as
 * meta for administration; meta is never used for lookups (Woo's posts store
 * silently ignores meta_query since 9.2).
 */
define( 'FPW_CLAIM_PREFIX', 'fpw_claim_' );
define( 'FPW_ATTEMPT_PREFIX', 'fpw_attempt_' );
define( 'FPW_CLAIM_WAIT_SECONDS', 3 );
define( 'FPW_CLAIM_POLL_MICROSECONDS', 100000 );
define( 'FPW_CLAIM_TAKEOVER_SECONDS', 30 );
define( 'FPW_CLAIM_MAX_AGE', 7 * DAY_IN_SECONDS );

/** The attempt under way in this request ('' when this request owns no claim). */
function fpw_pending_attempt( ?string $hash = null ): string {
	static $pending = '';
	return null === $hash ? $pending : ( $pending = $hash );
}

/** Opaque session fingerprint: the attempt hash already binds the session; the row stores its own copy for the takeover check. */
function fpw_session_fingerprint(): string {
	$customer_id = ( function_exists( 'WC' ) && WC()->session ) ? (string) WC()->session->get_customer_id() : '';
	return hash( 'sha256', $customer_id );
}

/**
 * The idempotency hash of one checkout attempt: session + cart hash + the
 * normalized posted attempt fields. Two concurrent POSTs of the same attempt
 * produce the same hash; any different session, cart or field produces a
 * different one. Empty when the session/cart Woo would key the attempt on is
 * unavailable — the claim then stays out of the way (Woo proceeds natively).
 */
function fpw_checkout_attempt_hash( $checkout ): string {
	if ( ! function_exists( 'WC' ) || ! WC()->session || ! WC()->cart || ! is_object( $checkout ) || ! method_exists( $checkout, 'get_posted_data' ) ) { return ''; }
	$fields = array();
	$posted = $checkout->get_posted_data();
	foreach ( array_merge( fpw_draft_keys(), array( 'payment_method' ) ) as $key ) {
		$fields[ $key ] = (string) ( $posted[ $key ] ?? '' );
	}
	ksort( $fields );
	return hash( 'sha256', (string) wp_json_encode( array( fpw_session_fingerprint(), (string) WC()->cart->get_cart_hash(), $fields ) ) );
}

/** The fresh claim row of one attempt, written/read straight from the database. */
function fpw_claim_row(): array {
	return array( 'session' => fpw_session_fingerprint(), 'order_id' => 0, 'started' => time() );
}

/** The atomic test-and-set: a plain INSERT fails on the unique option_name when the attempt is claimed. The expected duplicate-key error is suppressed — the defeat is information, not a fault. */
function fpw_insert_claim_row( string $hash ): bool {
	global $wpdb;
	$was_suppressed = $wpdb->suppress_errors(); // the no-arg call enables suppression and returns the prior state
	$result = $wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )",
			FPW_CLAIM_PREFIX . $hash,
			wp_json_encode( fpw_claim_row() )
		)
	);
	$wpdb->suppress_errors( $was_suppressed );
	return false !== $result && null !== $result;
}

/** The stored claim row, read straight from the database — the per-request options cache would answer with the reader's own write. */
function fpw_read_claim_row( string $hash ): ?array {
	global $wpdb;
	$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", FPW_CLAIM_PREFIX . $hash ) );
	if ( ! is_string( $raw ) || '' === $raw ) { return null; }
	$claim = json_decode( $raw, true );
	return is_array( $claim ) ? $claim : null;
}

/** Record the winner's order on the claim and in the durable lookup — the recovery data a concurrent retry reads. Only a claim that still carries no order is updated. Claim rows are JSON accessed by direct SQL, never through the options API. */
function fpw_finalize_attempt_claim( string $hash, int $order_id ): void {
	global $wpdb;
	$held = fpw_read_claim_row( $hash );
	if ( is_array( $held ) && empty( $held['order_id'] ) ) {
		$held['order_id'] = $order_id;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", wp_json_encode( $held ), FPW_CLAIM_PREFIX . $hash ) );
	}
	if ( $order_id && 0 === fpw_attempt_order_id( $hash ) ) {
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )",
				FPW_ATTEMPT_PREFIX . $hash,
				(string) $order_id
			)
		);
	}
}

/** Release an unfinalized claim (Woo failed to create the order) so a retry of the attempt starts clean. */
function fpw_release_attempt_claim( string $hash ): void {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", FPW_CLAIM_PREFIX . $hash ) );
}

/** The order an already-persisted attempt produced: the durable binding is a dedicated lookup row (unique option_name → order id), written at finalization and read by direct SQL — never an order-meta query, which Woo's posts store silently ignores since 9.2, and never hand-written SQL against Woo's own storage (ADR-0001). */
function fpw_attempt_order_id( string $hash ): int {
	global $wpdb;
	$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", FPW_ATTEMPT_PREFIX . $hash ) );
	return is_string( $raw ) && '' !== $raw ? absint( $raw ) : 0;
}

/** Expired claim rows are swept opportunistically — they only serve the short concurrent window; the lookup rows stay as the durable replay binding (permanent, like the reviewed legacy record meta). */
function fpw_sweep_attempt_claims(): int {
	global $wpdb;
	$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( FPW_CLAIM_PREFIX ) . '%' ) );
	$swept = 0;
	foreach ( (array) $names as $name ) {
		$raw  = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		$held = json_decode( (string) $raw, true );
		if ( is_array( $held ) && ( (int) ( $held['started'] ?? 0 ) < time() - FPW_CLAIM_MAX_AGE ) ) {
			$swept += $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $name ) ) ? 1 : 0;
		}
	}
	return $swept;
}

/**
 * The bounded claim/recovery state machine. 'owned': this request may create
 * the order. 'recovered': the attempt already produced an order — its id is
 * returned and Woo's own flow folds this attempt into it. 'busy': another
 * request of the same attempt is still in flight past the wait budget — fail
 * recoverably; a retry lands on the winner's confirmation.
 *
 * @return array{state:'owned'|'recovered'|'busy', order_id?:int}
 */
function fpw_checkout_attempt_claim( string $hash, ?float $wait_seconds = null ): array {
	fpw_sweep_attempt_claims();
	$landed = fpw_attempt_order_id( $hash );
	if ( $landed ) { return array( 'state' => 'recovered', 'order_id' => $landed ); }
	$give_up_at = microtime( true ) + ( $wait_seconds ?? FPW_CLAIM_WAIT_SECONDS );
	$fingerprint = fpw_session_fingerprint();
	while ( true ) {
		if ( fpw_insert_claim_row( $hash ) ) { return array( 'state' => 'owned' ); }
		$held = fpw_read_claim_row( $hash );
		if ( is_array( $held ) ) {
			$order_id = (int) ( $held['order_id'] ?? 0 );
			$ours     = hash_equals( (string) ( $held['session'] ?? '' ), $fingerprint );
			if ( $order_id ) {
				return $ours ? array( 'state' => 'recovered', 'order_id' => $order_id ) : array( 'state' => 'busy' );
			}
			if ( $ours && ( (int) ( $held['started'] ?? 0 ) + FPW_CLAIM_TAKEOVER_SECONDS ) < time() ) {
				// An unfinalized claim past the grace period belongs to a winner that died mid-flight: the same session first recovers a record that landed without its finalization, else resumes the attempt itself (a deliberate rewrite — unreachable for genuinely concurrent requests, which arrive seconds apart).
				$landed = fpw_attempt_order_id( $hash );
				if ( $landed ) { fpw_finalize_attempt_claim( $hash, $landed ); return array( 'state' => 'recovered', 'order_id' => $landed ); }
				fpw_release_attempt_claim( $hash );
				if ( fpw_insert_claim_row( $hash ) ) { return array( 'state' => 'owned' ); }
			}
		}
		if ( microtime( true ) >= $give_up_at ) { return array( 'state' => 'busy' ); }
		usleep( FPW_CLAIM_POLL_MICROSECONDS );
	}
}

/** A folded attempt must not re-fire the request notifications: the winner's notification already covers the record. Removes the quotes extension's own processed-order hook for this request only. */
function fpw_dedupe_request_notifications(): void {
	foreach ( $GLOBALS['wp_filter']['woocommerce_checkout_order_processed']?->callbacks ?? array() as $priority => $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$callable = $callback['function'] ?? null;
			if ( is_array( $callable ) && is_object( $callable[0] ) && 'qwc_init_quote_emails' === ( $callable[1] ?? '' ) ) {
				remove_action( 'woocommerce_checkout_order_processed', $callable, $priority );
			}
		}
	}
}

/** Whether this request is folding into the winner's existing order (set at recovery, read by the needs-payment guard). */
function fpw_is_folding_attempt( ?bool $set = null ): bool {
	static $folding = false;
	return null === $set ? $folding : ( $folding = $set );
}

/** The folded attempt leaves one honest trace on the record it joins. */
function fpw_note_folded_attempt( int $order_id ): void {
	if ( function_exists( 'wc_add_order_note' ) && $order_id ) {
		wc_add_order_note( $order_id, 'Solicitud duplicada (reintento concurrente) fusionada en este pedido por el control de intentos de Freeplast.', false );
	}
}

/**
 * A fold-in request runs Woo's flow on the winner's order with an ALREADY
 * EMPTIED cart, so Woo's own branching (`woocommerce_cart_needs_payment`)
 * would take the no-payment path and move the request out of pending into a
 * commercial status. Forcing the payment path sends it through the quotes
 * gateway — Woo's own route that records the quote meta, keeps the request
 * pending and returns the winner's confirmation URL.
 */
add_filter( 'woocommerce_cart_needs_payment', static function ( $needs_payment ) {
	return fpw_is_folding_attempt() ? true : $needs_payment;
}, 20 );

/**
 * Woo's own short-circuit for order creation: return the winner's order id and
 * Woo itself skips the duplicate creation, runs its own flow and sends the
 * customer to the winner's confirmation. Owned attempts proceed natively.
 */
function fpw_checkout_claim( $order_id, $checkout ) {
	$hash = fpw_checkout_attempt_hash( $checkout );
	if ( '' === $hash ) { return $order_id; }
	$claim = fpw_checkout_attempt_claim( $hash );
	if ( 'owned' === $claim['state'] ) {
		fpw_pending_attempt( $hash );
		return $order_id;
	}
	if ( 'recovered' === $claim['state'] ) {
		fpw_dedupe_request_notifications();
		fpw_note_folded_attempt( (int) $claim['order_id'] );
		fpw_is_folding_attempt( true );
		return (int) $claim['order_id'];
	}
	throw new Exception( 'Tu solicitud se está procesando. Espera unos segundos e inténtalo de nuevo: no se creará una solicitud duplicada.' );
}
add_filter( 'woocommerce_create_order', 'fpw_checkout_claim', 10, 2 );

/** The attempt identity is bound durably on the order, so replays fold into the original request even after the claim row is swept. */
add_action( 'woocommerce_checkout_create_order', static function ( $order, $data ) {
	$hash = fpw_pending_attempt();
	if ( '' !== $hash ) { $order->update_meta_data( '_fpw_attempt', $hash ); }
}, 15, 2 );

/** The claim records the created order — the recovery data a concurrent retry reads. */
add_action( 'woocommerce_checkout_order_created', static function ( $order ) {
	$hash = fpw_pending_attempt();
	if ( '' !== $hash ) { fpw_finalize_attempt_claim( $hash, (int) $order->get_id() ); }
} );

/** Woo failed to create the order: the attempt is released so a retry starts clean. */
add_action( 'woocommerce_checkout_order_exception', static function ( $order ) {
	$hash = fpw_pending_attempt();
	if ( '' !== $hash ) { fpw_release_attempt_claim( $hash ); }
} );

/** Contact fields displayed in Woo administration and native email metadata hooks. */
function fpw_details( $order ) {
	return array(
		'RUT Empresa' => $order->get_meta('_billing_fp_rut'),
		'Giro' => $order->get_meta('_billing_fp_giro'),
		'Despacho' => 'si' === $order->get_meta('_billing_fp_dispatch') ? 'Con despacho' : 'Sin despacho',
		'Dirección de despacho' => $order->get_meta('_billing_fp_address'),
	);
}
add_action( 'woocommerce_admin_order_data_after_billing_address', static function ( $order ) {
	foreach ( fpw_details($order) as $label=>$value ) { if ( '' !== $value ) { echo '<p><strong>'.esc_html($label).':</strong> '.esc_html($value).'</p>'; } }
	if ( $order->get_meta('_fp_legacy_post_id') ) {
		echo '<p>Solicitud histórica conservada: '.esc_html($order->get_meta('_fpq_reference')).'.</p>';
	}
	$original = $order->get_meta('_fp_submitted_details');
	if ( $original ) { echo '<details><summary>Datos originales recibidos</summary><pre style="white-space:pre-wrap">'.esc_html(wp_json_encode($original, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre></details>'; }
} );
add_filter( 'woocommerce_email_order_meta_fields', static function ( $fields, $sent, $order ) {
	foreach ( fpw_details($order) as $label=>$value ) { if ($value !== '') { $fields['fpw_'.sanitize_title($label)] = array('label'=>$label,'value'=>$value); } }
	return $fields;
}, 10, 3 );

// Quote-request emails are supplied by the extension. Never send a priced quote or invoice in v1.
foreach ( array('customer_invoice','customer_processing_order','customer_completed_order','customer_on_hold_order','new_order','qwc_send_quote') as $email_id ) {
	add_filter( 'woocommerce_email_enabled_'.$email_id, '__return_false', 999 );
}
add_filter( 'woocommerce_locate_template', static function ( $template, $name ) {
	if ( in_array($name, array('emails/request-new-quote.php','emails/new-quote-request-sent-customer.php'), true) ) { return __DIR__.'/request-email.php'; }
	return $template;
}, 999, 2 );

// Localized task language, not a new checkout implementation.
add_filter( 'gettext', static function ( $translated, $text, $domain ) {
	if ( ! in_array($domain, array('woocommerce','quote-wc'), true) ) { return $translated; }
	$labels = array('Billing details'=>'Datos de contacto y empresa','Your order'=>'Productos solicitados','Place order'=>'Solicitar cotización','Proceed to checkout'=>'Datos y envío','Proceed to Checkout'=>'Datos y envío','Cart'=>'Productos a Cotizar','Checkout'=>'Datos y envío','Additional information'=>'Información adicional');
	return $labels[$text] ?? $translated;
}, 20, 3 );

// Preserve old links while Woo owns the catalog routes.
add_action('template_redirect', static function () {
	if ( ! is_404() ) { return; }
	$path = wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
	$aliases = get_option('fpw_legacy_paths', array());
	if ( isset($aliases[$path]) ) { wp_safe_redirect(get_permalink((int)$aliases[$path]), 301); exit; }
} );

// No price/rating sorting for an unpriced request-only catalog.
add_filter('woocommerce_catalog_orderby', static fn($options) => array_intersect_key($options, array_flip(array('menu_order','date'))));
add_filter('loop_shop_per_page', static fn() => 24);
add_filter('woocommerce_product_add_to_cart_description', static function($text,$product) {
	return $product->is_type('variable') ? 'Elegir color para '.$product->get_name() : 'Agregar '.$product->get_name().' a Productos a Cotizar';
}, 10, 2);
add_filter('woocommerce_product_add_to_cart_text', static function($text,$product) {
	return $product->is_type('variable') ? 'Elegir color' : 'Agregar a Productos a Cotizar';
}, 1000, 2);
add_action('init', static function() {
	load_textdomain('quote-wc', WP_PLUGIN_DIR.'/quotes-for-woocommerce/languages/quote-wc-es_ES.mo');
}, 20);

// Conservative plural normalization, not unverified product-use recommendations.
add_action('pre_get_posts', static function($query) {
	if (is_admin() || !$query->is_main_query() || !$query->is_search()) { return; }
	$query->set('post_type', 'product');
	$term = $query->get('s');
	$query->set('s', preg_replace('/\bcajas\b/iu', 'caja', $term));
});
