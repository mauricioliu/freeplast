<?php
/**
 * A · Directa related block (issue #44).
 *
 * Theme override of Woo's related.php: same native selection rules and the
 * same A card the catalog renders, with the reference's section framing
 * («Sigue completando tu selección» + Ver catálogo).
 *
 * @package Freeplast
 */

defined( 'ABSPATH' ) || exit;

if ( $related_products ) :
	if ( function_exists( 'wp_increase_content_media_count' ) ) {
		$content_media_count = wp_increase_content_media_count( 0 );
		if ( $content_media_count < wp_omit_loading_attr_threshold() ) {
			wp_increase_content_media_count( wp_omit_loading_attr_threshold() - $content_media_count );
		}
	}
	?>

	<section class="related fp-related-block">

		<div class="fp-section-title">
			<h2>Sigue completando tu selección</h2>
			<a class="fp-text-link" href="<?php echo esc_url( home_url( '/tienda/' ) ); ?>">Ver catálogo</a>
		</div>

		<?php woocommerce_product_loop_start(); ?>

			<?php foreach ( $related_products as $related_product ) : ?>

					<?php
					$post_object = get_post( $related_product->get_id() );

					setup_postdata( $GLOBALS['post'] = $post_object ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, Squiz.PHP.DisallowMultipleAssignments.Found

					wc_get_template_part( 'content', 'product' );
					?>

			<?php endforeach; ?>

		<?php woocommerce_product_loop_end(); ?>

	</section>
	<?php
endif;

wp_reset_postdata();
