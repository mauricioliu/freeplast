<?php
/**
 * Quote-only review table for Datos y envío.
 *
 * Overrides woocommerce/checkout/review-order.php of the pinned
 * WooCommerce 11.1.0 (base template @version below). The cart's technical
 * price zero is not a commercial offer, so this table never renders amounts:
 * it calls no Woo price/total renderer at all. Products, chosen options and
 * quantities only; the totals zone states the intention («Por cotizar») —
 * the same wording the freeplast-woo adapter returns for formatted order
 * totals — coherent with the no-purchase/no-stock disclaimer. The classic
 * checkout re-renders this same template on every update_order_review AJAX
 * pass, so first paint and refreshes are both fixed at the render origin,
 * not hidden by CSS.
 *
 * The native product/class/visibility filters and the review-table actions
 * are kept for extension compatibility.
 *
 * @package Freeplast
 * @version 11.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<table class="shop_table woocommerce-checkout-review-order-table">
	<thead>
		<tr>
			<th scope="col" class="product-name">Producto</th>
			<th scope="col" class="product-quantity">Cantidad</th>
		</tr>
	</thead>
	<tbody>
		<?php do_action( 'woocommerce_review_order_before_cart_contents' ); ?>
		<?php foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) : ?>
			<?php $_product = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key ); ?>
			<?php if (
				$_product instanceof WC_Product
				&& $_product->exists()
				&& $cart_item['quantity'] > 0
				&& apply_filters( 'woocommerce_checkout_cart_item_visible', true, $cart_item, $cart_item_key )
			) : ?>
				<tr class="<?php echo esc_attr( apply_filters( 'woocommerce_cart_item_class', 'cart_item', $cart_item, $cart_item_key ) ); ?>">
					<td class="product-name">
						<?php echo wp_kses_post( apply_filters( 'woocommerce_cart_item_name', $_product->get_name(), $cart_item, $cart_item_key ) ); ?>
						<?php echo wc_get_formatted_cart_item_data( $cart_item ); ?>
					</td>
					<td class="product-quantity"><strong>&times;&nbsp;<?php echo esc_html( $cart_item['quantity'] ); ?></strong></td>
				</tr>
			<?php endif; ?>
		<?php endforeach; ?>
		<?php do_action( 'woocommerce_review_order_after_cart_contents' ); ?>
	</tbody>
	<tfoot>
		<?php do_action( 'woocommerce_review_order_before_order_total' ); ?>
		<tr class="order-total">
			<th scope="row">Total</th>
			<td>Por cotizar</td>
		</tr>
		<?php do_action( 'woocommerce_review_order_after_order_total' ); ?>
	</tfoot>
</table>
