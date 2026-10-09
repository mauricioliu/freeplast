<?php
/** Shared presentation only. Authorization and POST handlers stay in each screen. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function fpw_workspace_surface(): bool {
	$page = $_GET['page'] ?? '';
	if ( ! is_string( $page ) ) { return false; }
	if ( in_array( $page, array( 'fpw-data', 'fpw-price-list', 'fpw-sales-import' ), true ) ) { return fpw_can_manage_data(); }
	return fpw_can_manage_quotations() && ( 'fpw-quotations' === $page || ( 'fpw-quote-draft' === $page && '1' === ( $_GET['workspace'] ?? '' ) ) );
}

function fpw_workspace_menu_parent(): string {
	return fpw_can_manage_quotations() ? 'fpw-quotations' : 'fpw-data';
}

function fpw_workspace_navigation(): string {
	$page = $_GET['page'] ?? '';
	$links = '';
	if ( fpw_can_manage_quotations() ) {
		$current = in_array( $page, array( 'fpw-quotations', 'fpw-quote-draft' ), true );
		$links .= '<a href="' . esc_url( fpw_workspace_url() ) . '"' . ( $current ? ' aria-current="page"' : '' ) . '>Cotizaciones</a>';
	}
	if ( fpw_can_manage_data() ) {
		$active = in_array( $page, array( 'fpw-data', 'fpw-price-list', 'fpw-sales-import' ), true );
		$links .= '<details class="fpw-maintenance-menu"><summary aria-controls="fpw-maintenance-options"' . ( $active ? ' data-active="true"' : '' ) . ( 'fpw-data' === $page ? ' aria-current="page"' : '' ) . '>Mantenedores<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></summary><div id="fpw-maintenance-options" class="fpw-maintenance-options">';
		foreach ( array(
			array( 'fpw-price-list', 'Precios', fpw_price_screen_url() ),
			array( 'fpw-sales-import', 'Ventas Históricas', fpw_sales_import_screen_url() ),
		) as [ $slug, $label, $url ] ) {
			$links .= '<a href="' . esc_url( $url ) . '"' . ( $page === $slug ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		$links .= '</div></details>';
	}
	return '<nav class="fpw-workspace-navigation" aria-label="Cotizaciones y mantenedores">' . $links . '</nav>';
}

function fpw_workspace_header(): string {
	$home = fpw_can_manage_quotations() ? fpw_workspace_url() : fpw_data_hub_url();
	$account = fpw_restricted_user()
		? '<a href="' . esc_url( wp_logout_url( wp_login_url() ) ) . '">Cerrar sesión</a>'
		: '<a href="' . esc_url( admin_url() ) . '">Administración</a>';
	return '<header class="fpw-workspace-header"><a href="' . esc_url( $home ) . '" aria-label="Freeplast · Área del dueño"><img src="' . esc_url( plugins_url( 'assets/brand.webp', __FILE__ ) ) . '" alt="Freeplast" width="108" height="64"></a><span>Área del dueño</span>' . $account . '</header>';
}

