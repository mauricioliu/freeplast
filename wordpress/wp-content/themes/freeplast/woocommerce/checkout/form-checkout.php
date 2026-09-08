<?php
/**
 * A · Directa details form (issues #46–#47).
 *
 * Theme override of Woo's checkout/form-checkout.php. ONE classic native
 * form keeps everything authoritative: field names/ids, the registered
 * checkout fields (draft values through Woo's own session mechanism), the
 * nonce, the hidden submitted-attempt identity (adapter hook
 * woocommerce_after_order_notes), native validation/sanitization and the
 * native place-order trigger. This override only regroups presentation:
 * Contacto / Empresa / Despacho / Mensaje sections, the mobile-disclosure
 * summary (the native review table inside Woo's own
 * .woocommerce-checkout-review-order-table fragment target, so
 * update_order_review refreshes it in place) and the native payment/submit
 * block at the form's foot.
 *
 * @package Freeplast
 * @version 9.4.0
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_checkout_form', $checkout );

if ( ! $checkout->is_registration_enabled() && $checkout->is_registration_required() && ! is_user_logged_in() ) {
	echo esc_html( apply_filters( 'woocommerce_checkout_must_be_logged_in_message', __( 'You must be logged in to checkout.', 'woocommerce' ) ) );
	return;
}

$fp_billing = $checkout->get_checkout_fields( 'billing' );
// Replace only native layout callbacks for this render, never extension hooks.
$fp_extensions = static function ( $hook, $callback ) {
	$priority = has_action( $hook, $callback );
	if ( false !== $priority ) { remove_action( $hook, $callback, $priority ); }
	try { do_action( $hook ); }
	finally { if ( false !== $priority ) { add_action( $hook, $callback, $priority ); } }
};

$fp_lines = 0;
$fp_units = 0;
if ( function_exists( 'WC' ) && WC()->cart ) {
	foreach ( WC()->cart->get_cart() as $fp_item ) {
		if ( (int) $fp_item['quantity'] <= 0 ) { continue; }
		$fp_lines++;
		$fp_units += (int) $fp_item['quantity'];
	}
}
$fp_count_line = $fp_lines . ( 1 === $fp_lines ? ' producto' : ' productos' ) . ' · ' . number_format_i18n( $fp_units ) . ( 1 === $fp_units ? ' unidad' : ' unidades' );
?>
<div class="fp-shell fp-catalog fp-checkout-page">
<ol class="fp-steps" aria-label="Pasos de la solicitud">
<li><span class="step-number">1</span><span>Productos</span></li>
<li class="active" aria-current="step"><span class="step-number">2</span><span>Tus datos</span></li>
<li><span class="step-number">3</span><span>Solicitud</span></li>
</ol>
<div class="fp-page-heading">
<p class="fp-eyebrow">El último paso antes de solicitar</p>
<h1>Tus datos y despacho.</h1>
<p class="fp-page-lead">Cuéntanos a quién responder y si necesitas despacho. Sin registro ni pago en línea.</p>
</div>

<form name="checkout" method="post" class="checkout woocommerce-checkout" action="<?php echo esc_url( wc_get_checkout_url() ); ?>" enctype="multipart/form-data" aria-label="<?php echo esc_attr__( 'Checkout', 'woocommerce' ); ?>">

	<?php do_action( 'woocommerce_checkout_before_customer_details' ); ?>

	<?php do_action( 'woocommerce_checkout_before_order_review_heading' ); ?>
	<?php do_action( 'woocommerce_checkout_before_order_review' ); ?>
	<aside class="fp-checkout-summary" aria-label="Resumen de tu selección">
		<div class="summary-card">
			<details class="fp-summary-details" data-fp-summary-details>
				<summary><span class="fp-summary-count"><?php echo esc_html( $fp_count_line ); ?></span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" width="15" height="15"><path d="m5 9 7 7 7-7"/></svg></summary>
				<div class="fp-summary-body">
					<?php $fp_extensions( 'woocommerce_checkout_order_review', 'woocommerce_checkout_payment' ); ?>
					<p class="fp-fine">El precio se confirma con ventas.</p>
				</div>
			</details>
			<a class="fp-text-link fp-edit-products" href="<?php echo esc_url( wc_get_cart_url() ); ?>">Editar productos</a>
		</div>
	</aside>

	<div class="fp-checkout-form">
	<p class="fp-required-hint fp-fine">Todos los campos son obligatorios, salvo Mensaje.</p>

	<?php $fp_extensions( 'woocommerce_checkout_billing', array( $checkout, 'checkout_form_billing' ) ); ?>
	<?php do_action( 'woocommerce_before_checkout_billing_form', $checkout ); ?>
	<fieldset class="fp-form-section">
		<legend><span class="fp-section-number">01</span> Contacto</legend>
		<div class="fp-fields">
			<?php
			foreach ( array( 'billing_first_name', 'billing_phone', 'billing_email' ) as $fp_key ) {
				if ( isset( $fp_billing[ $fp_key ] ) ) {
					woocommerce_form_field( $fp_key, $fp_billing[ $fp_key ], $checkout->get_value( $fp_key ) );
				}
			}
			?>
		</div>
	</fieldset>

	<fieldset class="fp-form-section">
		<legend><span class="fp-section-number">02</span> Empresa</legend>
		<p class="fp-section-hint">Estos datos se utilizarán para una eventual facturación.</p>
		<div class="fp-fields">
			<?php
			foreach ( array( 'billing_company', 'billing_fp_rut', 'billing_fp_giro' ) as $fp_key ) {
				if ( isset( $fp_billing[ $fp_key ] ) ) {
					woocommerce_form_field( $fp_key, $fp_billing[ $fp_key ], $checkout->get_value( $fp_key ) );
				}
			}
			?>
		</div>
	</fieldset>

	<fieldset class="fp-form-section">
		<legend><span class="fp-section-number">03</span> Despacho</legend>
		<div class="fp-fields">
			<div class="fp-dispatch-options" id="fp-dispatch-options">
				<?php
				if ( isset( $fp_billing['billing_fp_dispatch'] ) ) {
					woocommerce_form_field( 'billing_fp_dispatch', $fp_billing['billing_fp_dispatch'], $checkout->get_value( 'billing_fp_dispatch' ) );
				}
				?>
			</div>
			<div class="fp-address-slot">
				<?php
				if ( isset( $fp_billing['billing_fp_address'] ) ) {
					woocommerce_form_field( 'billing_fp_address', $fp_billing['billing_fp_address'], $checkout->get_value( 'billing_fp_address' ) );
				}
				?>
			</div>
		</div>
	</fieldset>

	<?php do_action( 'woocommerce_after_checkout_billing_form', $checkout ); ?>
	<?php $fp_extensions( 'woocommerce_checkout_shipping', array( $checkout, 'checkout_form_shipping' ) ); ?>
	<?php do_action( 'woocommerce_before_order_notes', $checkout ); ?>
	<fieldset class="fp-form-section">
		<legend><span class="fp-section-number">04</span> Algo más que debamos saber</legend>
		<div class="fp-fields">
			<?php
			$fp_notes = $checkout->get_checkout_fields( 'order' );
			if ( isset( $fp_notes['order_comments'] ) ) {
				woocommerce_form_field( 'order_comments', $fp_notes['order_comments'], $checkout->get_value( 'order_comments' ) );
			}
			?>
		</div>
		<?php do_action( 'woocommerce_after_order_notes', $checkout ); ?>
	</fieldset>

	<?php do_action( 'woocommerce_checkout_after_customer_details' ); ?>

	<div class="fp-form-submit">
		<?php // Native payment → terms owns the privacy notice, including AJAX refreshes.
		woocommerce_checkout_payment(); ?>
		<p class="fp-submit-note fp-fine"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" width="14" height="14"><path d="M12 2 3 6v6c0 5 9 10 9 10s9-5 9-10V6l-9-4Z"/><path d="m8 12 3 3 5-6"/></svg> Sin pagos ni reserva de stock.</p>
		<?php do_action( 'woocommerce_checkout_after_order_review' ); ?>
	</div>
	</div>

</form>
</div>
<?php
do_action( 'woocommerce_after_checkout_form', $checkout );
