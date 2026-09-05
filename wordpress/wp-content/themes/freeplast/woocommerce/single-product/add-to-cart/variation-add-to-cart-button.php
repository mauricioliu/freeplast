<?php
/**
 * Accessible-state variation cart button for the product sheet.
 *
 * Overrides woocommerce/single-product/add-to-cart/variation-add-to-cart-button.php
 * of the pinned WooCommerce 11.1.0 (base template @version below). Woo renders
 * this button with no state semantics at all: the unavailable look exists only
 * as a `.disabled` class its JavaScript applies after init, so the
 * accessibility tree reads an available control and contrast tools score its
 * dimmed text as active UI (post-migration review of 2026-09-05, finding
 * WA-05 / issue #28).
 *
 * This override renders the initial unavailable state at the origin — the
 * same classes Woo's own variation form applies on init and on
 * selection-clear — plus aria-disabled, an aria-describedby link to a visible
 * instruction naming the variation attributes, so the state is honest on
 * first paint, without JavaScript, and to assistive technology. It is
 * presentation only: availability stays WooCommerce's (the classes are the
 * ones its variation form keeps toggling, mirrored into the aria attributes
 * at runtime by assets/js/variation-button-state.js), the real disabled
 * attribute is deliberately never used so the no-JS flow stays operable and
 * server validation keeps rejecting submissions without a required
 * variation, simple products are untouched (no simple.php override), and the
 * referential-photo notice and product availability information are not
 * modified.
 *
 * @package Freeplast
 * @version 10.5.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $product;

// The instruction must speak the form's real attributes, not a hard-coded one.
$fp_variation_labels = array();
foreach ( $product->get_attributes() as $fp_attribute ) {
	if ( $fp_attribute instanceof WC_Product_Attribute && $fp_attribute->get_variation() ) {
		$fp_variation_labels[] = wc_attribute_label( $fp_attribute->get_name(), $product );
	}
}
if ( empty( $fp_variation_labels ) ) {
	$fp_selection = 'las opciones';
} elseif ( 1 === count( $fp_variation_labels ) ) {
	$fp_selection = $fp_variation_labels[0];
} else {
	$fp_last_label = array_pop( $fp_variation_labels );
	$fp_selection = implode( ', ', $fp_variation_labels ) . ' y ' . $fp_last_label;
}
$fp_element_class = wc_wp_theme_get_element_class_name( 'button' );
$fp_hint_id = 'fp-variation-hint-' . absint( $product->get_id() );
?>
<div class="woocommerce-variation-add-to-cart variations_button">
	<?php do_action( 'woocommerce_before_add_to_cart_button' ); ?>

	<?php
	do_action( 'woocommerce_before_add_to_cart_quantity' );

	woocommerce_quantity_input(
		array(
			'min_value'   => $product->get_min_purchase_quantity(),
			'max_value'   => $product->get_max_purchase_quantity(),
			'input_value' => isset( $_POST['quantity'] ) ? wc_stock_amount( wp_unslash( $_POST['quantity'] ) ) : $product->get_min_purchase_quantity(), // WPCS: CSRF ok, input var ok.
		)
	);

	do_action( 'woocommerce_after_add_to_cart_quantity' );
	?>

	<button type="submit" class="single_add_to_cart_button button alt<?php echo $fp_element_class ? ' ' . esc_attr( $fp_element_class ) : ''; ?> wc-variation-selection-needed disabled" aria-disabled="true" aria-describedby="<?php echo esc_attr( $fp_hint_id ); ?>"><?php echo esc_html( $product->single_add_to_cart_text() ); ?></button>

	<?php do_action( 'woocommerce_after_add_to_cart_button' ); ?>

	<p class="fp-variation-hint" id="<?php echo esc_attr( $fp_hint_id ); ?>" data-fp-variation-hint>Selecciona <?php echo esc_html( $fp_selection ); ?> para agregar este producto a Productos a Cotizar.</p>

	<input type="hidden" name="add-to-cart" value="<?php echo absint( $product->get_id() ); ?>" />
	<input type="hidden" name="product_id" value="<?php echo absint( $product->get_id() ); ?>" />
	<input type="hidden" name="variation_id" class="variation_id" value="0" />
</div>
