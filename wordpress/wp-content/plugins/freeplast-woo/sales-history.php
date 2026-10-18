<?php
/** Read-only browsing of the stored register; never opens the source workbook. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function fpw_sales_history_url( array $params = array() ): string {
	return admin_url( 'admin.php?' . http_build_query( array( 'page' => 'fpw-sales-history' ) + $params ) );
}

add_action( 'admin_menu', static function () {
	add_submenu_page( fpw_workspace_menu_parent(), 'Ventas históricas', 'Ventas históricas', fpw_data_screen_capability(), 'fpw-sales-history', 'fpw_render_sales_history_screen' );
}, 30 );

function fpw_render_sales_history_screen(): void {
	if ( ! fpw_can_manage_data() ) { wp_die( 'El historial de ventas es privado del dueño.', '', array( 'response' => 403 ) ); }
	echo fpw_workspace_shell( fpw_sales_history_markup( fpw_sales_register()['sales'], wp_unslash( $_GET ) ) );
}

/** Names are source evidence, not customer identity. D is the official CLIENTE column. */
function fpw_sales_history_names( array $sale ): array {
	$names = array();
	foreach ( $sale['workbook_source']['rows'] ?? array() as $row ) {
		$name = $row['raw']['D'] ?? '';
		if ( is_string( $name ) && '' !== trim( $name ) ) { $names[] = trim( $name ); }
	}
	return array_values( array_unique( $names ) );
}

function fpw_sales_history_client( array $sale ): string {
	$names = fpw_sales_history_names( $sale );
	return $names ? ( count( $names ) > 1 ? 'Variantes en origen: ' : '' ) . implode( ' / ', $names ) : 'No disponible en origen';
}

function fpw_sales_history_page( array $sales, int $page ): array {
	usort( $sales, static function ( $a, $b ) {
		return strcmp( (string) ( $b['date'] ?? '' ), (string) ( $a['date'] ?? '' ) ) ?: strcmp( (string) $b['id'], (string) $a['id'] );
	} );
	$pages = max( 1, (int) ceil( count( $sales ) / 25 ) );
	$page = max( 1, min( $pages, $page ) );
	return array( 'sales' => array_slice( $sales, ( $page - 1 ) * 25, 25 ), 'page' => $page, 'pages' => $pages, 'total' => count( $sales ) );
}

function fpw_sales_history_detail( array $sale ): string {
	$html = '<h2>Registro ' . esc_html( $sale['id'] ) . '</h2><dl>';
	foreach ( array( 'Fecha' => $sale['date'] ?? '', 'Cliente en origen' => fpw_sales_history_client( $sale ), 'RUT' => $sale['rut'] ?? '', 'Factura' => $sale['invoice'] ?? '', 'Total de factura' => fpw_sales_format_clp( $sale['total'] ?? null ) ) as $label => $value ) {
		$html .= '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( '' === (string) $value ? 'No disponible' : $value ) . '</dd>';
	}
	$html .= '</dl>';
	if ( ! empty( $sale['lines'] ) ) {
		$detail = $sale;
		if ( ! isset( $detail['invoice'] ) || '' === (string) $detail['invoice'] ) { $detail['invoice'] = 'No disponible'; }
		$html .= fpw_sales_detail_html( $detail );
	} else {
		$html .= '<p>Desglose por líneas no disponible en este registro antiguo.</p><dl>';
		foreach ( array( 'producto' => 'Producto registrado', 'total_cajas' => 'Cajas registradas', 'precio' => 'Precio registrado', 'neto' => 'Neto registrado' ) as $key => $label ) {
			$value = $sale[$key] ?? '';
			if ( '' !== (string) $value && in_array( $key, array( 'precio', 'neto' ), true ) ) { $value = fpw_sales_format_clp( $value ); }
			$html .= '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( '' === (string) $value ? 'No disponible' : $value ) . '</dd>';
		}
		$html .= '</dl>';
	}
	$source = $sale['workbook_source'] ?? array();
	foreach ( array( 'filename' => 'Archivo', 'sheet' => 'Hoja', 'sha256' => 'SHA256', 'batch' => 'Lote' ) as $key => $label ) {
		$value = $source[$key] ?? ( 'batch' === $key ? ( $sale['batch'] ?? '' ) : '' );
		$html .= '<p>' . esc_html( $label . ': ' . ( '' === (string) $value ? 'No disponible' : $value ) ) . '</p>';
	}
	if ( empty( $source['rows'] ) ) { $html .= '<p>Filas originales no disponibles.</p>'; }
	elseif ( empty( $sale['lines'] ) ) {
		foreach ( $source['rows'] as $row ) {
			$html .= '<details><summary>Fila original ' . (int) $row['source_row'] . '</summary><dl>';
			foreach ( $row['raw'] ?? array() as $column => $value ) { $html .= '<dt>' . esc_html( $column ) . '</dt><dd>' . esc_html( $value ) . '</dd>'; }
			$html .= '</dl></details>';
		}
	}
	return $html;
}

function fpw_sales_history_markup( array $sales, array $query ): string {
	$page = $query['history_page'] ?? '1';
	$id = $query['sale_id'] ?? null;
	$invalid = ! is_string( $page ) || ! preg_match( '/^[1-9][0-9]{0,8}$/D', $page )
		|| ( array_key_exists( 'sale_id', $query ) && ( ! is_string( $id ) || '' === $id || mb_strlen( $id ) > FPW_SALES_MAX_ID ) );
	$inner = '<h1>Ventas históricas</h1><p>Consulta de registros cargados · Solo lectura.</p><p><a class="button" href="' . esc_url( fpw_sales_import_screen_url() ) . '">Importar ventas</a></p>';
	if ( $invalid ) {
		$inner .= '<p role="alert">Parámetros inválidos. <a href="' . esc_url( fpw_sales_history_url() ) . '">Volver al historial</a></p>';
	} else {
		$result = fpw_sales_history_page( $sales, (int) $page );
		if ( null !== $id ) {
			$inner .= '<p><a href="' . esc_url( fpw_sales_history_url( array( 'history_page' => $result['page'] ) ) ) . '">Volver al historial</a></p>';
			$selected = null;
			foreach ( $sales as $sale ) { if ( (string) $sale['id'] === $id ) { $selected = $sale; break; } }
			$inner .= '<section>' . ( null === $selected ? '<h2>Registro no encontrado</h2><p>La venta solicitada no está disponible.</p>' : fpw_sales_history_detail( $selected ) ) . '</section>';
		} else {
			$inner .= '<p>' . $result['total'] . ' registros · Página ' . $result['page'] . ' de ' . $result['pages'] . '</p>';
			if ( ! $sales ) { $inner .= '<p>Aún no hay ventas históricas cargadas.</p>'; }
			else {
				$inner .= '<div class="fpw-history-table" role="region" aria-label="Ventas históricas" tabindex="0"><table><thead><tr><th scope="col">Fecha</th><th scope="col">Cliente en origen</th><th scope="col">RUT</th><th scope="col">Factura</th><th scope="col">Total de factura</th><th scope="col">Registro</th></tr></thead><tbody>';
				foreach ( $result['sales'] as $sale ) {
					$inner .= '<tr>';
					foreach ( array( $sale['date'] ?? '', fpw_sales_history_client( $sale ), $sale['rut'] ?? '', $sale['invoice'] ?? '', fpw_sales_format_clp( $sale['total'] ?? null ) ) as $value ) { $inner .= '<td>' . esc_html( '' === (string) $value ? 'No disponible' : $value ) . '</td>'; }
					$inner .= '<td><a href="' . esc_url( fpw_sales_history_url( array( 'history_page' => $result['page'], 'sale_id' => $sale['id'] ) ) ) . '">Abrir ' . esc_html( $sale['id'] ) . '</a></td></tr>';
				}
				$inner .= '</tbody></table></div><nav aria-label="Páginas del historial" class="fpw-sales__actions">';
				foreach ( array( $result['page'] - 1 => 'Anterior', $result['page'] + 1 => 'Siguiente' ) as $next => $label ) {
					if ( $next >= 1 && $next <= $result['pages'] ) { $inner .= '<a class="button" href="' . esc_url( fpw_sales_history_url( array( 'history_page' => $next ) ) ) . '">' . $label . '</a>'; }
				}
				$inner .= '</nav>';
			}
		}
	}
	return fpw_sales_screen_shell( '<div class="fpw-history"><style>.fpw-history{min-width:0;overflow-wrap:anywhere}.fpw-history-table{max-width:100%;overflow-x:auto}.fpw-history-table table{min-width:640px}.fpw-history a:focus-visible,.fpw-history-table:focus-visible{outline:2px solid #100090;outline-offset:2px}.fpw-history td{font-variant-numeric:tabular-nums}</style>' . $inner . '</div></div>' );
}
