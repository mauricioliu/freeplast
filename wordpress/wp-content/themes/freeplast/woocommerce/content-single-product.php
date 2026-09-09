<?php
/**
 * A · Directa product sheet (issue #44).
 *
 * Theme override of Woo's content-single-product.php. The native machinery
 * stays authoritative: Woo's breadcrumb, product gallery (lightbox), the
 * variation form with its selects/ids/hidden inputs, the quantity input and
 * the add-to-cart submit, related products through the native query — the
 * same A card the catalog renders. Presentation composes A's hierarchy:
 * breadcrumb › heading › gallery/summary › technical disclosure › related.
 * The color controls are an enhancement layer over the single variation
 * select (assets/js/product-color-options.js), never a second form.
 *
 * @package Freeplast
 */

defined( 'ABSPATH' ) || exit;

global $product;

if ( empty( $product ) ) { return; }

do_action( 'woocommerce_before_single_product' );
// Preserve Woo's direct-product password boundary, including hidden-catalog
// products that are legitimately reachable through their native permalink.
if ( post_password_required() ) {
	echo get_the_password_form();
	return;
}

/* Category kicker (same source as the card). */
$fp_category = '';
foreach ( wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) ) as $fp_name ) {
	$fp_category = $fp_name;
	if ( 'Otros' !== $fp_name ) { break; }
}

/* Facts, with the reference's neutral handling of unconfirmed values. */
$fp_known = static fn( $value ) => '' !== $value && 'Consultar' !== $value ? $value : '';
$fp_spec_fields = array(
	'Material'          => $fp_known( $product->get_attribute( 'Material' ) ),
	'Medidas'           => $fp_known( $product->get_attribute( 'Medidas' ) ),
	'Peso propio'       => $fp_known( $product->get_attribute( 'Peso propio' ) ),
	'Uso'               => $fp_known( $product->get_attribute( 'Uso' ) ),
	'Unidades por pallet' => $fp_known( $product->get_attribute( 'Unidades por pallet' ) ),
);
$fp_facts = array_filter( $fp_spec_fields, static fn( $value ) => '' !== $value );

/* A never presents internal review prose or pending copy as a fact. */
$fp_description = (string) $product->get_short_description();
if ( '' === trim( wp_strip_all_tags( $fp_description ) ) ) { $fp_description = (string) $product->get_description(); }
$fp_pending_copy = 'Las especificaciones de este producto están pendientes de confirmación. Puedes incluirlo en tu solicitud para consultar con ventas.';
if ( preg_match( '/cliente|sin descripci|provisional/iu', $fp_description ) || '' === trim( wp_strip_all_tags( $fp_description ) ) ) {
	$fp_description = $fp_pending_copy;
}

$fp_has_image = fp_theme_has_product_photo( $product );
?>
<div class="fp-product-shell">
<div id="product-<?php the_ID(); ?>" <?php wc_product_class( 'fp-single-product', $product ); ?>>

	<div class="fp-breadcrumb">
		<?php woocommerce_breadcrumb(); ?>
	</div>

	<div class="product-heading">
		<p class="fp-eyebrow"><?php echo esc_html( $fp_category ? $fp_category . ' · Freeplast' : 'Freeplast' ); ?></p>
		<h1 class="product_title"><?php the_title(); ?></h1>
	</div>

	<div class="product-layout">

		<div class="product-gallery">
			<div class="fp-detail-image">
				<?php
				if ( $fp_has_image ) {
					woocommerce_show_product_images();
				} else {
					?>
					<div class="missing-detail"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" width="30" height="30"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8" cy="8" r="1"/><path d="m3 17 6-6 4 4 3-3 5 5"/></svg><span>Fotografía pendiente</span></div>
					<?php
				}
				?>
			</div>
			<p class="image-caption"><?php echo esc_html( fp_theme_product_photo_caption( $product ) ); ?></p>
		</div>

		<div class="product-summary">
			<h2 class="summary-title">Detalles que importan.</h2>
			<div class="detail-description"><?php echo wpautop( wp_kses_post( $fp_description ) ); ?></div>

			<?php if ( $fp_facts ) : ?>
				<dl class="fp-spec-grid">
					<?php foreach ( array_slice( $fp_spec_fields, 0, 4, true ) as $fp_label => $fp_value ) : ?>
						<div><dt><?php echo esc_html( $fp_label ); ?></dt><dd><?php echo esc_html( '' !== $fp_value ? $fp_value : 'Por confirmar' ); ?></dd></div>
					<?php endforeach; ?>
				</dl>
			<?php else : ?>
				<p class="fp-notice">Las especificaciones técnicas están por confirmar con ventas.</p>
			<?php endif; ?>

			<div class="product-options">
				<?php woocommerce_template_single_add_to_cart(); ?>
				<p class="product-help"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" width="15" height="15"><path d="M12 2 3 6v6c0 5 9 10 9 10s9-5 9-10V6l-9-4Z"/><path d="m8 12 3 3 5-6"/></svg> Sin compra ni reserva de stock.</p>
			</div>

			<?php echo fp_theme_detail_added( $product->get_id() ); ?>
	<div class="product-facts">
		<details>
			<summary>Ficha técnica completa</summary>
			<table class="fp-spec-table">
				<tbody>
					<?php foreach ( $fp_spec_fields as $fp_label => $fp_value ) : ?>
						<tr><th scope="row"><?php echo esc_html( $fp_label ); ?></th><td><?php echo esc_html( '' !== $fp_value ? $fp_value : 'Por confirmar' ); ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( '' !== $fp_spec_fields['Unidades por pallet'] ) : ?>
				<p class="fp-fine">Unidades por pallet es un dato de embalaje, no una cantidad mínima de solicitud.</p>
			<?php endif; ?>
		</details>
	</div>
		</div>
	</div>

	<?php
	/**
	 * Hook: woocommerce_after_single_product.
	 */
	do_action( 'woocommerce_after_single_product' );
	?>
</div>

<?php woocommerce_output_related_products(); ?>
</div>
