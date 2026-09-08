<?php
/** Native no-results template, shared by the block and PHP archive paths. */
defined( 'ABSPATH' ) || exit;
$fp_query_text = get_search_query( false );
echo fp_catalog_result_count( 0, $fp_query_text );
wc_get_template( 'loop/orderby.php', array( 'orderby' => fp_catalog_orderby() ) );
?>
<div class="empty-state">
	<span class="empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" width="30" height="30"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4 4"/></svg></span>
	<h2><?php echo esc_html( $fp_query_text ? 'No encontramos «' . $fp_query_text . '».' : 'No encontramos productos.' ); ?></h2>
	<p>Prueba con otro nombre o vuelve a explorar el catálogo.</p>
	<div class="action-row">
		<a class="fp-btn" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>">Ver todos los productos</a>
		<button class="fp-text-button" type="button" data-fp-dialog="fp-help">Consultar con ventas</button>
	</div>
</div>
