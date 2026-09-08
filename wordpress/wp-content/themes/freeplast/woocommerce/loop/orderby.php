<?php
/** A ordering over Woo's actual template arguments and native form handler. */
defined( 'ABSPATH' ) || exit;
// Woo passes $orderby and $catalog_orderby_options; there is no
// wc_get_catalog_ordering_options() function. Search adds relevance to those
// options, but this scoped journey deliberately offers only A's two choices.
$fp_options = array( 'menu_order' => 'Destacados', 'title' => 'Nombre A–Z' );
$fp_current = isset( $orderby ) && isset( $fp_options[$orderby] ) ? $orderby : 'menu_order';
?>
<form class="woocommerce-ordering fp-catalog-ordering" method="get">
	<label class="sort">Ordenar
		<select name="orderby" class="orderby" aria-label="Ordenar productos">
			<?php foreach ( $fp_options as $fp_id => $fp_name ) : ?>
				<option value="<?php echo esc_attr( $fp_id ); ?>" <?php selected( $fp_id, $fp_current ); ?>><?php echo esc_html( $fp_name ); ?></option>
			<?php endforeach; ?>
		</select>
	</label>
	<input type="hidden" name="paged" value="1" />
	<?php wc_query_string_form_fields( null, array( 'orderby', 'submit', 'paged', 'product-page' ) ); ?>
	<noscript><button type="submit" name="submit" value="1" class="fp-btn">Aplicar</button></noscript>
</form>
