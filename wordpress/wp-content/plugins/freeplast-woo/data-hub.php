<?php
/** The owner's data-maintenance hub: one entry for everything maintained privately. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function fpw_data_hub_url(): string {
	return admin_url( 'admin.php?page=fpw-data' );
}

/** Each entry is an existing private screen; nothing new is reachable through the hub itself. */
function fpw_data_hub_entries(): array {
	return apply_filters( 'fpw_data_hub_entries', array(
		array(
			'title'       => 'Precios',
			'description' => 'Lista vigente, referencias 1–4 y 5+ pallets por producto.',
			'url'         => fpw_price_screen_url(),
		),
		array(
			'title'       => 'Ventas',
			'description' => 'Carga de planillas históricas e historial de compras por RUT.',
			'url'         => fpw_sales_import_screen_url(),
		),
	) );
}

function fpw_data_screen_capability(): string {
	return current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'fpw_manage_data';
}

add_action( 'admin_menu', static function () {
	if ( fpw_can_manage_quotations() ) {
		add_submenu_page( 'fpw-quotations', 'Mantenedor de datos', 'Mantenedores', fpw_data_screen_capability(), 'fpw-data', 'fpw_render_data_hub' );
	} elseif ( fpw_can_manage_data() ) {
		add_menu_page( 'Mantenedor de datos', 'Cotizaciones', fpw_data_screen_capability(), 'fpw-data', 'fpw_render_data_hub', 'dashicons-media-document' );
	}
}, 20 );

function fpw_render_data_hub(): void {
	if ( ! fpw_can_manage_data() ) { wp_die( 'El mantenedor de datos es privado del dueño.', '', array( 'response' => 403 ) ); }
	$rows = '';
	foreach ( fpw_data_hub_entries() as $entry ) {
		$rows .= '<a class="fpw-data-row" href="' . esc_url( (string) $entry['url'] ) . '"><strong>' . esc_html( (string) $entry['title'] ) . '</strong><span>' . esc_html( (string) $entry['description'] ) . '</span></a>';
	}
	echo fpw_workspace_shell( '<div class="wrap fpw-data-hub"><style>'
		. '.fpw-data-hub{max-width:640px;font-size:16px;line-height:1.5}'
		. '.fpw-data-hub h1{font-size:24px;line-height:1.2;margin:4px 0 2px}'
		. '.fpw-data-hub__kicker{color:#60626d;margin:0 0 14px}'
		. '.fpw-data-row{display:grid;gap:2px;min-height:44px;padding:12px 14px;margin:0 0 10px;border:1px solid #dcdcde;border-radius:8px;background:#fff;text-decoration:none}'
		. '.fpw-data-row strong{color:#100090;font-size:16px}'
		. '.fpw-data-row span{color:#60626d;font-size:14px}'
		. '.fpw-data-row:hover{background:#eeedf8;border-color:#aca7d1}'
		. '.fpw-data-row:focus-visible{outline:2px solid #100090;outline-offset:2px}'
		. '</style><h1>Mantenedor de datos</h1><p class="fpw-data-hub__kicker">Privado del dueño · aquí vive todo lo que se mantiene: precios, ventas y lo que venga.</p>' . $rows . '</div>' );
}
