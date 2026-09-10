<?php
/**
 * Mantenedor de precios — issue #52, corte 3 de #49.
 *
 * The owner maintains the current net CLP prices of products and options in
 * WordPress on this private, unlisted wp-admin screen. WordPress is the
 * authority for those prices — never a spreadsheet, never the last historical
 * sale price — and the identity of every entry is the NATIVE one: the product
 * id for simple products, the variation id (with its parent as fallback) for
 * each option of a variable product.
 *
 * Storage follows the adapter's durable options-row pattern: ONE row,
 * fpw_price_list, holding the whole maintained map. Nothing else is written:
 * saving prices never touches product posts, product meta, photos, requests,
 * drafts or the Sales Register, so the catalog's technical zero prices stay
 * technical sentinels and nothing commercial reaches any public surface.
 *
 * The mantenedor only PREFILLS: a new draft's editing form carries the
 * maintained value where one exists and stays pending where none does; the
 * draft's saved work distinguishes a price adopted from the suggestion
 * (price_source 'suggested') from the owner's own choice ('manual'); changing
 * the list never rewrites saved drafts or issued documents, and adopting new
 * suggestions requires the draft's explicit refresh action, which conserves
 * every manual entry.
 *
 * Authorization and CSRF are enforced server-side: the screen and its save are
 * keyed on manage_woocommerce (Ventas' four approved caps do not open it), and
 * the save is front-doored at admin_init — before wp-admin prints its header —
 * so a nonce failure is an explicit 403. Knowing the link or presenting a
 * nonce grants nothing.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'FPW_PRICE_SCREEN', 'fpw-price-list' );
define( 'FPW_PRICE_ROW', 'fpw_price_list' );
define( 'FPW_PRICE_NONCE_SAVE', 'fpw_price_save' );
define( 'FPW_PRICE_MAX_ENTRIES', 500 );

/** The private screen's address. */
function fpw_price_screen_url(): string {
	return admin_url( 'admin.php?page=' . FPW_PRICE_SCREEN );
}

/** The row key of one native identity: the variation id when there is one, the product id otherwise. */
function fpw_price_key( int $product_id, int $variation_id ): string {
	return $variation_id > 0 ? 'v:' . $variation_id : 'p:' . $product_id;
}

/** The maintained list, read straight from the database — never through the per-request options cache. Degrades to an explicit empty list without a database (read-only render paths in offline harnesses). */
function fpw_price_list(): array {
	global $wpdb;
	if ( ! isset( $wpdb ) ) { return array( 'schema' => 1, 'updated_at' => 0, 'updated_by' => '', 'prices' => array() ); }
	$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", FPW_PRICE_ROW ) );
	if ( ! is_string( $raw ) || '' === $raw ) { return array( 'schema' => 1, 'updated_at' => 0, 'updated_by' => '', 'prices' => array() ); }
	$payload = json_decode( $raw, true );
	if ( ! is_array( $payload ) || ! isset( $payload['prices'] ) || ! is_array( $payload['prices'] ) ) { return array( 'schema' => 1, 'updated_at' => 0, 'updated_by' => '', 'prices' => array() ); }
	return array(
		'schema'     => (int) ( $payload['schema'] ?? 1 ),
		'updated_at' => (int) ( $payload['updated_at'] ?? 0 ),
		'updated_by' => (string) ( $payload['updated_by'] ?? '' ),
		'prices'     => $payload['prices'],
	);
}

/** Upsert the ONE price row (the screen is the authority editor: what it saves is what the list holds). */
function fpw_price_write_row( array $payload ): void {
	global $wpdb;
	$json = (string) wp_json_encode( $payload );
	if ( null === $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", FPW_PRICE_ROW ) ) ) {
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )", FPW_PRICE_ROW, $json ) );
		return;
	}
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", $json, FPW_PRICE_ROW ) );
}

/** Replace the whole maintained map with one validated, provenance-stamped payload. */
function fpw_price_save_entries( array $entries, string $actor ): array {
	$payload = array(
		'schema'     => 1,
		'updated_at' => time(),
		'updated_by' => $actor,
		'prices'     => $entries,
	);
	fpw_price_write_row( $payload );
	return $payload;
}

/**
 * The current suggestion for one native identity: the variation's own price
 * when maintained, otherwise its product's; null when nothing applies. Only
 * this list suggests — never a historical sale price, never a public amount.
 */
function fpw_price_for( int $product_id, int $variation_id ): ?int {
	$prices = fpw_price_list()['prices'];
	if ( $variation_id > 0 ) {
		$v = $prices[ 'v:' . $variation_id ] ?? null;
		if ( is_int( $v ) && $v > 0 ) { return $v; }
	}
	$p = $prices[ 'p:' . $product_id ] ?? null;
	return ( is_int( $p ) && $p > 0 ) ? $p : null;
}

/** The entry of ONE identity itself, without any fallback: what the mantenedor's inputs show and save. */
function fpw_price_own_entry( int $product_id, int $variation_id ): ?int {
	$key = fpw_price_key( $product_id, $variation_id );
	$v = fpw_price_list()['prices'][ $key ] ?? null;
	return ( is_int( $v ) && $v > 0 ) ? $v : null;
}

/**
 * The native catalog the mantenedor edits: published products with their
 * variations (each option's own identity and name). Read-only — the builder
 * never writes anything.
 */
function fpw_price_catalog(): array {
	if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'get_posts' ) ) { return array(); }
	$cap = (int) apply_filters( 'fpw_price_max_products', 500 );
	$ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'numberposts' => max( 1, $cap ), 'fields' => 'ids', 'orderby' => 'title', 'order' => 'ASC' ) );
	$catalog = array();
	foreach ( (array) $ids as $id ) {
		$product = wc_get_product( (int) $id );
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) { continue; }
		$entry = array(
			'product_id' => (int) $product->get_id(),
			'name'       => method_exists( $product, 'get_name' ) ? (string) $product->get_name() : '',
			'variations' => array(),
		);
		if ( method_exists( $product, 'is_type' ) && $product->is_type( 'variable' ) && method_exists( $product, 'get_children' ) ) {
			foreach ( (array) $product->get_children() as $variation_id ) {
				$variation = wc_get_product( (int) $variation_id );
				if ( is_object( $variation ) && method_exists( $variation, 'get_id' ) ) {
					$entry['variations'][] = array(
						'variation_id' => (int) $variation->get_id(),
						'name'         => method_exists( $variation, 'get_name' ) ? (string) $variation->get_name() : '',
					);
				}
			}
		}
		$catalog[] = $entry;
	}
	return $catalog;
}

/**
 * Parse one save's posted prices against the catalog. Every entry must name a
 * real native identity; a left-empty input means "not maintained" (removes the
 * entry); amounts follow the site's one strict CLP rule — a zero is refused
 * (the catalog's technical zero is never converted into a commercial price).
 * All-or-nothing: any error refuses the whole save.
 *
 * @return array{errors:string[],entries:array<string,int>}
 */
function fpw_price_parse_input( array $catalog, array $posted ): array {
	$allowed = array();
	foreach ( $catalog as $entry ) {
		$allowed[ fpw_price_key( (int) ( $entry['product_id'] ?? 0 ), 0 ) ] = true;
		foreach ( ( is_array( $entry['variations'] ?? null ) ? $entry['variations'] : array() ) as $variation ) {
			$allowed[ fpw_price_key( (int) ( $entry['product_id'] ?? 0 ), (int) ( $variation['variation_id'] ?? 0 ) ) ] = true;
		}
	}
	$errors  = array();
	$entries = array();
	foreach ( $posted as $key => $raw ) {
		$key = (string) $key;
		if ( ! isset( $allowed[ $key ] ) ) {
			$errors[] = 'Identidad desconocida («' . $key . '»): el mantenedor solo recibe identidades nativas de producto y variación del catálogo.';
			continue;
		}
		$amount = fpw_parse_draft_amount( $raw );
		if ( 'zero' === $amount ) {
			$errors[] = '«' . $key . '»: un precio de 0 no está permitido; deja el campo vacío mientras no haya un precio mantenido.';
			continue;
		}
		if ( 'invalid' === $amount ) {
			$errors[] = '«' . $key . '»: ingresa un precio neto entero en CLP (1 a 99.999.999) o deja el campo vacío.';
			continue;
		}
		if ( null !== $amount ) { $entries[ $key ] = $amount; }
	}
	if ( count( $entries ) > FPW_PRICE_MAX_ENTRIES ) {
		$errors[] = 'El lote supera el máximo de ' . FPW_PRICE_MAX_ENTRIES . ' precios mantenidos.';
	}
	return array( 'errors' => $errors, 'entries' => $entries );
}

/** The acting owner's login, for the list's consultable provenance. */
function fpw_price_actor(): string {
	if ( ! function_exists( 'wp_get_current_user' ) ) { return 'sistema'; }
	$user = wp_get_current_user();
	return ( is_object( $user ) && ! empty( $user->user_login ) ) ? (string) $user->user_login : 'sistema';
}

/** Request-scoped stash for the save outcome (the admin_init pass runs before the screen renders). */
function fpw_pending_price_save( ?array $set = null ): ?array {
	static $pending = null;
	return null === $set ? $pending : ( $pending = $set );
}

/** The save's server-side half: CSRF first, then all-or-nothing validation and write. */
function fpw_price_handle_save_request( array $catalog ): array {
	if ( ! wp_verify_nonce( (string) ( $_POST['fpw_price_nonce'] ?? '' ), FPW_PRICE_NONCE_SAVE ) ) {
		wp_die( 'Tu sesión expiró o el formulario no es válido: vuelve a cargar el mantenedor e inténtalo de nuevo. Nada se guardó.', '', array( 'response' => 403 ) );
	}
	$posted = isset( $_POST['fpw_prices'] ) && is_array( $_POST['fpw_prices'] ) ? wp_unslash( $_POST['fpw_prices'] ) : array();
	$parsed = fpw_price_parse_input( $catalog, $posted );
	if ( ! empty( $parsed['errors'] ) ) {
		$extra = count( $parsed['errors'] ) > 1 ? ' (y ' . ( count( $parsed['errors'] ) - 1 ) . ' otros problemas.)' : '';
		return array( 'ok' => false, 'message' => 'No se guardó nada: ' . $parsed['errors'][0] . $extra, 'errors' => $parsed['errors'] );
	}
	$count = count( $parsed['entries'] );
	fpw_price_save_entries( $parsed['entries'], fpw_price_actor() );
	return array(
		'ok'      => true,
		'message' => 'Lista de precios guardada: ' . $count . ' ' . ( 1 === $count ? 'precio mantenido' : 'precios mantenidos' ) . '. Los borradores ya guardados conservan sus importes.',
		'count'   => $count,
	);
}

/** The uniform denial: private to the owner, stated in Spanish, 403. */
function fpw_die_price_forbidden(): void {
	wp_die( 'El mantenedor de precios es privado del dueño: requiere una sesión con permisos de administración de WooCommerce.', '', array( 'response' => 403 ) );
}

/** The save front door, ahead of wp-admin's own header render: authorization first, then CSRF. */
function fpw_price_maybe_handle_save(): void {
	if ( FPW_PRICE_SCREEN !== (string) ( $_GET['page'] ?? '' ) || empty( $_POST['fpw_price_save'] ) ) { return; }
	if ( ! current_user_can( 'manage_woocommerce' ) ) { fpw_die_price_forbidden(); }
	fpw_pending_price_save( fpw_price_handle_save_request( fpw_price_catalog() ) );
}
add_action( 'admin_init', 'fpw_price_maybe_handle_save', 0 );

/** The private screen: unlisted, keyed on the owner capability. */
add_action( 'admin_menu', 'fpw_price_register_screen' );
function fpw_price_register_screen(): void {
	add_submenu_page( null, 'Mantenedor de precios', 'Mantenedor de precios', 'manage_woocommerce', FPW_PRICE_SCREEN, 'fpw_render_price_screen' );
}

/** The screen callback: capability first, then the stored state and the save outcome. */
function fpw_render_price_screen(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) { fpw_die_price_forbidden(); }
	$banner = fpw_pending_price_save();
	echo fpw_price_screen_markup( is_array( $banner ) ? $banner : array() );
}

/** One price input as the owner edits it: plain integer, empty means not maintained. */
function fpw_price_input_html( string $key, ?int $value ): string {
	return '<input type="number" inputmode="numeric" min="1" max="99999999" step="1" name="fpw_prices[' . esc_attr( $key ) . ']" value="' . ( null !== $value && $value > 0 ? $value : '' ) . '" placeholder="Sin precio mantenido" />';
}

/** One catalog row: the identity beside its name, the input beside its maintained value. */
function fpw_price_row_html( string $key, string $name, string $identity_note, ?int $value ): string {
	return '<div class="fpw-prices__row"><div class="fpw-prices__who"><strong>' . esc_html( $name ) . '</strong><span class="fpw-prices__id">' . esc_html( $identity_note ) . '</span></div>'
		. '<label class="fpw-prices__field">Precio neto unitario (CLP)' . fpw_price_input_html( $key, $value ) . '</label></div>';
}

/** The mobile-first screen shell and its styles. */
function fpw_price_screen_shell( string $inner ): string {
	return '<div class="wrap fpw-prices"><style>'
		. '.fpw-prices{max-width:960px;font-size:16px;line-height:1.5}'
		. '.fpw-prices h1{font-size:24px;line-height:1.2;margin:4px 0 4px}'
		. '.fpw-prices__kicker{color:#60626d;margin:12px 0 0}'
		. '.fpw-prices section{border:1px solid #dcdcde;border-radius:8px;padding:14px 16px;background:#fff;margin:16px 0;min-width:0}'
		. '.fpw-prices h2{font-size:16px;margin:0 0 10px}'
		. '.fpw-prices__row{display:grid;gap:8px;border:1px solid #e4e4e8;border-radius:6px;padding:10px 12px;margin:0 0 10px}'
		. '.fpw-prices__variations{margin:2px 0 6px;display:grid;gap:10px}'
		. '.fpw-prices__variations .fpw-prices__row{border-style:dashed;margin:0}'
		. '.fpw-prices__who{display:grid;gap:2px}'
		. '.fpw-prices__id{color:#60626d;font-size:13px;overflow-wrap:anywhere}'
		. '.fpw-prices__field{display:grid;gap:4px;font-size:13px;font-weight:600;color:#60626d}'
		. '.fpw-prices__field input{font-size:16px;padding:8px 10px;border:1px solid #c3c4c7;border-radius:4px;width:100%;max-width:16rem;box-sizing:border-box;background:#fff;font-family:inherit}'
		. '.fpw-prices__note{color:#60626d;font-size:14px;margin:8px 0 0}'
		. '.fpw-prices__form{margin:12px 0 0}'
		. '.fpw-prices form button{font-size:16px;font-weight:600;padding:10px 18px;border-radius:6px;cursor:pointer}'
		. '.fpw-prices ul{margin:4px 0 0 18px}'
		. '@media (min-width: 782px){.fpw-prices__row{grid-template-columns:1fr 240px;align-items:end}.fpw-prices__variations{margin-left:20px}.fpw-prices__group{margin-bottom:14px}}'
		. '</style>' . $inner . '<!-- fpw-prices:end --></div>';
}

/** The action-result banner. */
function fpw_price_banner_html( array $banner ): string {
	if ( ! isset( $banner['message'] ) ) { return ''; }
	$class = ! empty( $banner['ok'] ) ? 'notice-success' : 'notice-error';
	return '<div class="notice ' . $class . '"><p>' . esc_html( (string) $banner['message'] ) . '</p></div>';
}

/**
 * The mantenedor screen markup: the contract (authority, privacy, stability,
 * mutation boundary), then one editable row per native identity.
 */
function fpw_price_screen_markup( array $banner = array() ): string {
	$catalog = fpw_price_catalog();
	$list    = fpw_price_list();
	$nonce   = wp_nonce_field( FPW_PRICE_NONCE_SAVE, 'fpw_price_nonce', true, false );

	$contract = '<section><h2>Cómo funciona esta lista</h2><ul>'
		. '<li>Estos precios son <strong>privados</strong> y son la autoridad vigente en WordPress: prellenan los borradores de cotización y jamás aparecen en páginas públicas, endpoints, confirmaciones ni correos del comprador.</li>'
		. '<li>La identidad es la nativa de producto y variación; cada opción puede tener su propio precio. Ni una planilla externa ni el último precio histórico de venta sugieren valores aquí.</li>'
		. '<li>Los precios técnicos 0 del catálogo son centinelas, no precios comerciales: este mantenedor nunca los publica ni los convierte en ofertas.</li>'
		. '<li>Cambiar esta lista <strong>no reescribe</strong> los borradores ya guardados ni los documentos emitidos: cada borrador conserva sus importes hasta que el dueño refresque sus precios de forma explícita, y sus ajustes manuales se conservan siempre.</li>'
		. '<li>Guardar aquí solo cambia esta lista privada: no toca productos, fotos, solicitudes, historial de ventas ni nada público.</li>'
		. '</ul></section>';

	if ( empty( $catalog ) ) {
		return fpw_price_screen_shell(
			'<h1>Mantenedor de precios</h1>'
			. '<p class="fpw-prices__kicker">Lista de precios vigentes · netos CLP</p>'
			. fpw_price_banner_html( $banner )
			. $contract
			. '<section><h2>Catálogo</h2><p>El catálogo no tiene productos publicados todavía: cuando los haya, cada identidad nativa aparecerá aquí con su campo de precio.</p></section>'
		);
	}

	$rows = '';
	$maintained = 0;
	foreach ( $catalog as $entry ) {
		$product_id = (int) ( $entry['product_id'] ?? 0 );
		$product_price = fpw_price_own_entry( $product_id, 0 );
		if ( null !== $product_price ) { $maintained++; }
		$row = fpw_price_row_html( fpw_price_key( $product_id, 0 ), (string) ( $entry['name'] ?? '' ), 'Producto · ID #' . $product_id, $product_price );
		$variations = is_array( $entry['variations'] ?? null ) ? $entry['variations'] : array();
		if ( ! empty( $variations ) ) {
			$variation_rows = '';
			foreach ( $variations as $variation ) {
				$variation_id = (int) ( $variation['variation_id'] ?? 0 );
				$variation_price = fpw_price_own_entry( $product_id, $variation_id );
				if ( null !== $variation_price ) { $maintained++; }
				$row_html = fpw_price_row_html( fpw_price_key( $product_id, $variation_id ), (string) ( $variation['name'] ?? '' ), 'Opción de ' . (string) ( $entry['name'] ?? '' ) . ' · ID #' . $variation_id, $variation_price );
				if ( null === $variation_price && null !== $product_price ) {
					$row_html .= '<p class="fpw-prices__note">Sin precio propio: mientras tanto sugerirá el precio del producto (' . esc_html( number_format( $product_price, 0, ',', '.' ) ) . ' CLP neto).</p>';
				}
				$variation_rows .= $row_html;
			}
			$row .= '<div class="fpw-prices__variations">' . $variation_rows . '</div>';
		}
		$rows .= '<div class="fpw-prices__group">' . $row . '</div>';
	}

	$updated = $list['updated_at'] > 0
		? 'Actualizada el ' . esc_html( date_i18n( get_option( 'date_format' ), $list['updated_at'] ) ) . ' por ' . esc_html( $list['updated_by'] ) . ' · ' . $maintained . ' ' . ( 1 === $maintained ? 'precio mantenido' : 'precios mantenidos' )
		: 'Aún no tiene precios mantenidos: los borradores nuevos dejarán estos valores como pendientes.';

	return fpw_price_screen_shell(
		'<h1>Mantenedor de precios</h1>'
		. '<p class="fpw-prices__kicker">Lista de precios vigentes · netos CLP</p>'
		. '<p class="fpw-prices__note">' . $updated . '</p>'
		. fpw_price_banner_html( $banner )
		. $contract
		. '<section><h2>Precios por producto y opción</h2>'
		. '<form class="fpw-prices__form" method="post" action="' . esc_url( fpw_price_screen_url() ) . '">'
		. '<input type="hidden" name="fpw_price_save" value="1" />'
		. $nonce
		. $rows
		. '<p><button type="submit" class="button button-primary">Guardar lista de precios</button></p>'
		. '<p class="fpw-prices__note">Dejar un campo vacío quita ese precio de la lista; los borradores nuevos lo mostrarán como pendiente. Un precio de 0 no se acepta: el cero del catálogo es un centinela técnico.</p>'
		. '</form></section>'
	);
}
