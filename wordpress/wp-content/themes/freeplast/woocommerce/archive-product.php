<?php
/**
 * A · Directa catalog archive (issue #43).
 *
 * Theme override of Woo's archive-product.php: the shop root, category
 * archives and product search results render the reference composition —
 * catalog intro, search + category tools, results toolbar (native count +
 * native ordering), the A card grid, native pagination and A's no-results
 * state. Queries stay 100% native: the search form posts Woo's own ?s=
 * route, filters are the canonical category routes, ordering is Woo's own
 * orderby select (menu_order/title), and the adapter's existing search
 * hook keeps results product-only. get_header/get_footer calls match
 * Woo's own template file; inside block themes the legacy-template block
 * neutralizes them.
 *
 * @package Freeplast
 */

defined( 'ABSPATH' ) || exit;

get_header( 'shop' );

// A starts at the catalog intro; preserve extension hooks, not the extra
// native breadcrumb that would precede that intro.
$fp_breadcrumb_priority = has_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb' );
if ( false !== $fp_breadcrumb_priority ) { remove_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', $fp_breadcrumb_priority ); }
do_action( 'woocommerce_before_main_content' );
if ( false !== $fp_breadcrumb_priority ) { add_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', $fp_breadcrumb_priority ); }
?>
<div class="fp-shell fp-catalog">
	<section class="catalog-intro">
		<div class="catalog-heading-row">
			<div>
				<p class="eyebrow">Catálogo mayorista · Freeplast</p>
				<h1>Elige tus productos.</h1>
				<p class="intro-copy">Agrega las cantidades que necesitas. Nosotros preparamos tu cotización.</p>
			</div>
			<div class="intro-note">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" width="28" height="28"><path d="M12 2 3 6v6c0 5 9 10 9 10s9-5 9-10V6l-9-4Z"/><path d="m8 12 3 3 5-6"/></svg>
				<div><strong>Una solicitud. Sin compromiso de compra.</strong>Sin registro ni pago en línea.</div>
			</div>
		</div>
	</section>
	<div class="catalog-tools">
		<form class="search-form" id="fp-catalog-search-form" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true" width="18" height="18"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4 4"/></svg>
			<label class="fp-sr-only" for="fp-catalog-search">Buscar productos</label>
			<input id="fp-catalog-search" type="search" name="s" value="<?php echo esc_attr( get_search_query( false ) ); ?>" placeholder="Busca una caja, traversa…" autocomplete="off">
			<input type="hidden" name="post_type" value="product">
			<input type="hidden" name="orderby" value="<?php echo esc_attr( fp_catalog_orderby() ); ?>">
			<?php if ( get_query_var( 'product_cat' ) ) : ?><input type="hidden" name="product_cat" value="<?php echo esc_attr( get_query_var( 'product_cat' ) ); ?>"><?php endif; ?>
			<button aria-label="Buscar productos" type="submit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" width="16" height="16"><path d="M4 12h15M13 5l7 7-7 7"/></svg></button>
		</form>
		<?php fp_catalog_filters(); ?>
	</div>
	<?php
	if ( woocommerce_product_loop() ) {
		do_action( 'woocommerce_before_shop_loop' );
		woocommerce_product_loop_start();
		if ( wc_get_loop_prop( 'total' ) ) {
			while ( have_posts() ) {
				the_post();
				do_action( 'woocommerce_shop_loop' );
				wc_get_template_part( 'content', 'product' );
			}
		}
		woocommerce_product_loop_end();
		do_action( 'woocommerce_after_shop_loop' );
	} else {
		/**
		 * Hook: woocommerce_no_products_found.
		 *
		 * @hooked wc_no_products_found - 10 (superseded by the A state below)
		 */
		$fp_notice_priority = has_action( 'woocommerce_no_products_found', 'wc_no_products_found' );
		if ( false !== $fp_notice_priority ) { remove_action( 'woocommerce_no_products_found', 'wc_no_products_found', $fp_notice_priority ); }
		do_action( 'woocommerce_no_products_found' );
		if ( false !== $fp_notice_priority ) { add_action( 'woocommerce_no_products_found', 'wc_no_products_found', $fp_notice_priority ); }
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
		<?php
	}
	?>
</div>
<?php
do_action( 'woocommerce_after_main_content' );
do_action( 'woocommerce_sidebar' );

get_footer( 'shop' );
