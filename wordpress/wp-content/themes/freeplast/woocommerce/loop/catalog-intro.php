<?php
/** Shared catalog introduction for native ClassicTemplate and PHP archives. */
defined( 'ABSPATH' ) || exit;
?>
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
