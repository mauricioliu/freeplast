<?php
/**
 * A · Directa product card (issue #42).
 *
 * Theme override of Woo's loop card. Native semantics stay Woo's own: the
 * li classes come from wc_product_class(), the loop add-to-cart renders
 * through woocommerce_template_loop_add_to_cart() so the adapter's
 * woocommerce_loop_add_to_cart_link filter (quantity input + native anchor
 * + cart projection) keeps composing it, and the extension hooks stay
 * callable around the card. The adapter's projection slot also serves the
 * variable card, whose only action is Elegir color → the native product
 * page (never a card-level color picker).
 *
 * @package Freeplast
 */

defined( 'ABSPATH' ) || exit;

global $product;

if ( empty( $product ) || ! $product->is_visible() ) {
	return;
}

/* Extension surface without Woo's single whole-card link: A composes photo,
 * info and actions as separate regions, so the default link open/close
 * callbacks are detached for this render only. */
$fp_loop_callbacks = array(
	array( 'woocommerce_before_shop_loop_item', 'woocommerce_template_loop_product_link_open' ),
	array( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_product_link_close' ),
	array( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart' ),
);
foreach ( $fp_loop_callbacks as &$fp_callback ) {
	$fp_callback[] = has_action( $fp_callback[0], $fp_callback[1] );
	if ( false !== $fp_callback[2] ) { remove_action( $fp_callback[0], $fp_callback[1], $fp_callback[2] ); }
}
unset( $fp_callback );

$fp_card_link   = $product->get_permalink();
$fp_is_variable = $product->is_type( 'variable' );
$fp_can_add     = $product->is_type( 'simple' ) && $product->is_purchasable() && $product->is_in_stock();
$fp_colors      = array();
if ( $fp_is_variable ) {
	foreach ( $product->get_variation_attributes() as $attribute_name => $values ) {
		if ( in_array( sanitize_title( $attribute_name ), array( 'color', 'pa_color' ), true ) && is_array( $values ) ) {
			$fp_colors = $values;
			break;
		}
	}
}
$fp_category = '';
foreach ( wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) ) as $fp_name ) {
	$fp_category = $fp_name; // Product carries one Catalog category.
	if ( 'Otros' !== $fp_name ) { break; }
}

/* A's essential fact: measures first (without Woo's "(exteriores)" suffix
 * when it merely repeats the reference's normalization), then material,
 * then weight — never invented facts. */
$fp_spec = '';
foreach ( array( 'Medidas', 'Material', 'Peso propio' ) as $fp_attr_name ) {
	$fp_value = $product->get_attribute( $fp_attr_name );
	if ( '' === $fp_value || 'Consultar' === $fp_value ) { continue; }
	if ( 'Medidas' === $fp_attr_name ) { $fp_value = str_replace( ' (exteriores)', '', $fp_value ); }
	$fp_spec = $fp_value;
	break;
}

$fp_has_image = function_exists( 'fp_theme_has_product_photo' ) ? fp_theme_has_product_photo( $product ) : (bool) $product->get_image_id();
$fp_selection = function_exists( 'fpw_card_selection' ) && function_exists( 'WC' ) && WC()->cart
	? fpw_card_selection( $product->get_id(), WC()->cart->get_cart() )
	: '<div class="fpw-card-selection" data-product-id="' . esc_attr( $product->get_id() ) . '"></div>';
?>
<li <?php wc_product_class( 'product-card', $product ); ?>>
	<?php do_action( 'woocommerce_before_shop_loop_item' ); ?>
	<a class="photo-link" href="<?php echo esc_url( $fp_card_link ); ?>">
		<span class="product-photo">
			<?php
			if ( $fp_has_image ) {
				do_action( 'woocommerce_before_shop_loop_item_title' );
			} else {
				?>
				<span class="placeholder"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" width="24" height="24"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8" cy="8" r="1"/><path d="m3 17 6-6 4 4 3-3 5 5"/></svg><span>Foto pendiente</span></span>
				<?php
			}
			?>
		</span>
	</a>
	<div class="product-info">
		<?php if ( $fp_category ) : ?>
			<span class="product-category"><?php echo esc_html( $fp_category ); ?></span>
		<?php endif; ?>
		<h2 class="woocommerce-loop-product__title"><a href="<?php echo esc_url( $fp_card_link ); ?>"><?php echo esc_html( $product->get_name() ); ?></a></h2>
		<p class="product-spec"><?php echo esc_html( $fp_spec ? $fp_spec : 'Ficha técnica por confirmar' ); ?></p>
	</div>
	<?php if ( $fp_is_variable ) : ?>
		<div class="fpw-loop-add product-controls variant-controls" data-fpw-loop-add>
			<span class="color-hint"><span class="color-dots" aria-hidden="true"><i class="color-dot"></i><i class="color-dot red"></i><i class="color-dot yellow"></i><i class="color-dot blue"></i><i class="color-dot green"></i></span><?php echo esc_html( count( $fp_colors ) ? count( $fp_colors ) . ' colores · sujetos a disponibilidad' : 'Colores sujetos a disponibilidad' ); ?></span>
			<a class="button secondary" href="<?php echo esc_url( $fp_card_link ); ?>">Elegir color <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" width="16" height="16"><path d="M4 12h15M13 5l7 7-7 7"/></svg></a>
			<?php echo $fp_selection; ?>
		</div>
	<?php elseif ( $fp_can_add ) : ?>
		<?php woocommerce_template_loop_add_to_cart(); ?>
	<?php else : ?>
		<div class="fpw-loop-add product-controls" data-fpw-loop-add>
			<a class="button secondary" href="<?php echo esc_url( $fp_card_link ); ?>">Ver producto <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" width="16" height="16"><path d="M4 12h15M13 5l7 7-7 7"/></svg></a>
			<?php echo $fp_selection; ?>
		</div>
	<?php endif; ?>
	<?php do_action( 'woocommerce_after_shop_loop_item' ); ?>
</li>
<?php
foreach ( $fp_loop_callbacks as $fp_callback ) {
	if ( false !== $fp_callback[2] ) { add_action( $fp_callback[0], $fp_callback[1], $fp_callback[2] ); }
}
