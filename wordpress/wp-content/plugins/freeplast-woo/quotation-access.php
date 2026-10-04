<?php
/** Quotation-only account: no Woo administration capability is granted. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function fpw_can_manage_quotations(): bool {
	return current_user_can( 'manage_woocommerce' ) || current_user_can( 'fpw_manage_quotations' );
}

function fpw_quotation_only_user( $user = null ): bool {
	$user = $user ?? wp_get_current_user();
	return $user && in_array( 'fpw_quotation_manager', (array) $user->roles, true );
}

function fpw_data_only_user( $user = null ): bool {
	$user = $user ?? wp_get_current_user();
	return $user && in_array( 'fpw_data_manager', (array) $user->roles, true );
}

function fpw_can_manage_data(): bool {
	return current_user_can( 'manage_woocommerce' ) || current_user_can( 'fpw_manage_data' );
}

function fpw_restricted_user( $user = null ): bool {
	return fpw_quotation_only_user( $user ) || fpw_data_only_user( $user );
}

function fpw_quotation_screen_capability(): string {
	return current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'fpw_manage_quotations';
}

add_action( 'init', static function () {
	$shapes = array(
		'fpw_quotation_manager' => array( 'Gestión de cotizaciones', array( 'read' => true, 'fpw_manage_quotations' => true ) ),
		'fpw_data_manager'      => array( 'Mantenedor de datos', array( 'read' => true, 'fpw_manage_data' => true ) ),
	);
	foreach ( $shapes as $name => [ $label, $allowed ] ) {
		$role = get_role( $name );
		if ( ! $role ) { add_role( $name, $label, $allowed ); continue; }
		foreach ( $role->capabilities as $cap => $value ) {
			if ( ! isset( $allowed[ $cap ] ) ) { $role->remove_cap( $cap ); }
		}
		foreach ( $allowed as $cap => $value ) {
			if ( ! $role->has_cap( $cap ) ) { $role->add_cap( $cap ); }
		}
	}
}, 5 );

add_filter( 'woocommerce_prevent_admin_access', static function ( $prevent ) {
	return fpw_restricted_user() ? false : $prevent;
} );
add_filter( 'login_redirect', static function ( $redirect, $requested, $user ) {
	if ( $user instanceof WP_User && fpw_quotation_only_user( $user ) ) { return fpw_workspace_url(); }
	if ( $user instanceof WP_User && fpw_data_only_user( $user ) ) { return fpw_data_hub_url(); }
	return $redirect;
}, 20, 3 );

/** Friendly entry only: the authenticated workspace and its guards remain native WordPress. */
function fpw_quotation_entry_url(): string {
	return home_url( '/cotizaciones/' );
}

function fpw_data_entry_url(): string {
	return home_url( '/mantenedor/' );
}

/** Shared entry core: method, cache, authentication, then the per-route decision. */
function fpw_entry_dispatch( string $request_path, callable $decide ): void {
	global $wp;
	// WP has already stripped any installation subdirectory and trailing slash.
	if ( $request_path !== ( $wp->request ?? null ) ) { return; }
	nocache_headers();
	if ( ! in_array( $_SERVER['REQUEST_METHOD'] ?? '', array( 'GET', 'HEAD' ), true ) ) {
		header( 'Allow: GET, HEAD' );
		wp_die( 'Abre este enlace directamente en el navegador.', '', array( 'response' => 405 ) );
	}
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( wp_login_url( home_url( '/' . $request_path . '/' ) ) ); exit;
	}
	$decide();
}

function fpw_quotation_entry(): void {
	fpw_entry_dispatch( 'cotizaciones', static function () {
		if ( ! fpw_can_manage_quotations() ) {
			wp_die( 'Tu cuenta no tiene permiso para gestionar cotizaciones.', '', array( 'response' => 403 ) );
		}
		wp_safe_redirect( fpw_workspace_url() ); exit;
	} );
}
add_action( 'template_redirect', 'fpw_quotation_entry', 0 );

function fpw_data_entry(): void {
	fpw_entry_dispatch( 'mantenedor', static function () {
		// The data hub stays owner-side: the quotation-only role never enters.
		if ( ! fpw_can_manage_data() ) {
			wp_die( 'El mantenedor de datos es privado del dueño.', '', array( 'response' => 403 ) );
		}
		wp_safe_redirect( fpw_data_hub_url() ); exit;
	} );
}
add_action( 'template_redirect', 'fpw_data_entry', 0 );

/** Allowlist is a server-side boundary, not menu hiding. PDF retains its own nonce check. */
function fpw_quotation_route_allowed( string $script, array $query ): bool {
	if ( 'admin-post.php' === $script ) { return 'fpw_quotation_pdf' === ( $query['action'] ?? null ); }
	if ( 'admin.php' !== $script || isset( $query['action'] ) || isset( $query['import'] ) ) { return false; }
	return 'fpw-quotations' === ( $query['page'] ?? null )
		|| ( 'fpw-quote-draft' === ( $query['page'] ?? null ) && '1' === ( $query['workspace'] ?? null ) );
}

/** The data maintainer's exact surfaces: the hub, its two screens and their same-URL POSTs. */
function fpw_data_route_allowed( string $script, array $query ): bool {
	if ( 'profile.php' === $script ) { return true; }
	if ( 'admin.php' !== $script || isset( $query['action'] ) || isset( $query['import'] ) ) { return false; }
	return in_array( $query['page'] ?? null, array( 'fpw-data', 'fpw-price-list', 'fpw-sales-import' ), true );
}

add_action( 'admin_init', static function () {
	if ( ! fpw_quotation_only_user() ) { return; }
	global $pagenow;
	if ( 'index.php' === $pagenow && 'GET' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		wp_safe_redirect( fpw_workspace_url() ); exit;
	}
	// Do not let an admin action piggyback on an allowed page via POST.
	if ( isset( $_POST['action'] ) || ! fpw_quotation_route_allowed( (string) $pagenow, $_GET ) ) {
		wp_die( 'Esta cuenta solo tiene acceso a las cotizaciones.', '', array( 'response' => 403 ) );
	}
}, -100 );

add_action( 'admin_init', static function () {
	if ( ! fpw_data_only_user() ) { return; }
	global $pagenow;
	if ( 'index.php' === $pagenow && 'GET' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		wp_safe_redirect( fpw_data_hub_url() ); exit;
	}
	if ( isset( $_POST['action'] ) || ! fpw_data_route_allowed( (string) $pagenow, $_GET ) ) {
		wp_die( 'Esta cuenta solo administra el mantenedor de datos.', '', array( 'response' => 403 ) );
	}
}, -100 );

/**
 * The quotation role's ONLY REST surface: reading and changing the lines of its
 * own Productos a Cotizar through the Store API cart routes Woo's own basket page
 * uses. Reading its cart and adding/updating/removing its own lines is it —
 * coupons, checkout, orders, products, batch and every administrative or
 * third-party-data route stay denied; Woo's native session binding and its
 * wc_store_api nonce still govern these routes exactly as for any customer.
 */
function fpw_quotation_own_basket_rest_route( string $route, string $method ): bool {
	$route  = '/' . trim( $route, '/' );
	$method = strtoupper( trim( $method ) );
	if ( '/wc/store/v1/cart' === $route ) { return 'GET' === $method; }
	return 'POST' === $method && in_array( $route, array( '/wc/store/v1/cart/add-item', '/wc/store/v1/cart/update-item', '/wc/store/v1/cart/remove-item' ), true );
}

/** Pure REST-boundary decision, kept free of WP globals so the denial contract is provable offline. */
function fpw_restricted_rest_route_denied( array $roles, string $route, string $method ): bool {
	if ( ! array_intersect( $roles, array( 'fpw_quotation_manager', 'fpw_data_manager' ) ) ) { return false; }
	return ! ( in_array( 'fpw_quotation_manager', $roles, true ) && fpw_quotation_own_basket_rest_route( $route, $method ) );
}

add_filter( 'rest_pre_dispatch', static function ( $result, $rest_server = null, $request = null ) {
	$user  = wp_get_current_user();
	$roles = (array) ( is_object( $user ) ? ( $user->roles ?? array() ) : array() );
	$route  = is_object( $request ) && is_callable( array( $request, 'get_route' ) ) ? (string) $request->get_route() : '';
	$method = is_object( $request ) && is_callable( array( $request, 'get_method' ) ) ? (string) $request->get_method() : '';
	if ( ! fpw_restricted_rest_route_denied( $roles, $route, $method ) ) { return $result; }
	return new WP_Error( 'fpw_restricted_account', 'Esta cuenta no tiene acceso a la API de administración.', array( 'status' => 403 ) );
}, 10, 3 );
add_filter( 'wp_is_application_passwords_available_for_user', static function ( $available, $user ) {
	return fpw_restricted_user( $user ) ? false : $available;
}, 10, 2 );
