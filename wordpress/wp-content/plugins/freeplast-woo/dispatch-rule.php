<?php
/**
 * Regla de despacho — issue #61, corte 12 de #49.
 *
 * The owner maintains ONE explicit dispatch-pricing rule on this private,
 * unlisted wp-admin screen: cargo fijo + CLP/km × started whole kilometers,
 * never below the cobro mínimo. The kilometers are the consulted Dispatch
 * Distance's (issue #60) — each STARTED kilometer counts complete (the meters
 * round up), the only interpretation this shape documents. The shape carries
 * no return, toll or doubling terms: if the contrast with real Carrier charges
 * ever shows they belong, adding them is a separate approved decision, not a
 * silent extension. The coefficients are the OWNER's calibrated inputs — the
 * code ships none — and the calibration itself is an external prerequisite
 * (historical Carrier charges with destination and amount): until it exists
 * the rule stays unconfigured and dispatch stays pending or manual, never an
 * invented estimate.
 *
 * Storage follows the adapter's durable options-row pattern: ONE row,
 * fpw_dispatch_rule. Absent by default; a row that is unreadable or does not
 * hold exactly the three strict positive CLP integers degrades to absent —
 * it never becomes authority for an invented amount. The rule only SUGGESTS:
 * its amount and breakdown render beside a successful distance consultation
 * for the owner's decision — never stored, never the chosen amount (which
 * only the owner's manual entry creates, #51), never buyer-facing (the buyer
 * sees one separate dispatch amount, #55), and changing the rule rewrites
 * nothing: drafts, saved work, previews and any issued document stand.
 *
 * Authorization and CSRF are enforced server-side: the screen and its save
 * are keyed on manage_woocommerce (Ventas' four approved caps do not open
 * it), and the save is front-doored at admin_init — before wp-admin prints
 * its header — so a nonce failure is an explicit 403. Knowing the link or
 * presenting a nonce grants nothing.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'FPW_RULE_SCREEN', 'fpw-dispatch-rule' );
define( 'FPW_RULE_ROW', 'fpw_dispatch_rule' );
define( 'FPW_RULE_NONCE_SAVE', 'fpw_rule_save' );
define( 'FPW_RULE_MAX_AMOUNT', 99999999 );

/** The private screen's address. */
function fpw_rule_screen_url(): string {
	return admin_url( 'admin.php?page=' . FPW_RULE_SCREEN );
}

/**
 * The maintained rule, read straight from the database — never through the
 * per-request options cache. Null when the row is absent, unreadable, or its
 * payload is not exactly the one shape: three strict positive CLP integers.
 * An invalid rule degrades to absent instead of becoming authority.
 *
 * @return null|array{schema:int,updated_at:int,updated_by:string,rule:array{base_fee:int,per_km:int,minimum:int}}
 */
function fpw_rule_read(): ?array {
	global $wpdb;
	if ( ! isset( $wpdb ) ) { return null; }
	$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", FPW_RULE_ROW ) );
	if ( ! is_string( $raw ) || '' === $raw ) { return null; }
	$payload = json_decode( $raw, true );
	if ( ! is_array( $payload ) || ! is_array( $payload['rule'] ?? null ) ) { return null; }
	foreach ( array( 'base_fee', 'per_km', 'minimum' ) as $key ) {
		$value = $payload['rule'][ $key ] ?? null;
		if ( ! is_int( $value ) || $value < 1 || $value > FPW_RULE_MAX_AMOUNT ) { return null; }
	}
	return array(
		'schema'     => (int) ( $payload['schema'] ?? 1 ),
		'updated_at' => (int) ( $payload['updated_at'] ?? 0 ),
		'updated_by' => (string) ( $payload['updated_by'] ?? '' ),
		'rule'       => array(
			'base_fee' => $payload['rule']['base_fee'],
			'per_km'   => $payload['rule']['per_km'],
			'minimum'  => $payload['rule']['minimum'],
		),
	);
}

/** Upsert the ONE rule row; a null rule stores the explicit removal (suggestions stop, manual pricing serves). */
function fpw_rule_write( ?array $rule, string $actor ): void {
	global $wpdb;
	$json = (string) wp_json_encode( array(
		'schema'     => 1,
		'updated_at' => time(),
		'updated_by' => $actor,
		'rule'       => $rule,
	) );
	if ( null === $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", FPW_RULE_ROW ) ) ) {
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )", FPW_RULE_ROW, $json ) );
		return;
	}
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", $json, FPW_RULE_ROW ) );
}

/**
 * Parse one save's posted rule against the ONE shape. All-or-nothing: the
 * three parameters are strict positive CLP integers (the site's one strict
 * amount rule — a 0 component invents a gratuity nobody approved) and any
 * error refuses the whole save. All three left empty is the explicit removal;
 * a mix of empty and filled is refused; unknown posted keys never reach the
 * rule.
 *
 * @return array{errors:string[],rule:?array{base_fee:int,per_km:int,minimum:int}}
 */
function fpw_rule_parse_input( array $posted ): array {
	$fields = array(
		'base_fee' => 'El cargo fijo',
		'per_km'   => 'El monto por kilómetro',
		'minimum'  => 'El cobro mínimo',
	);
	$errors = array();
	$values = array();
	$empty  = 0;
	foreach ( $fields as $key => $label ) {
		$amount = fpw_parse_draft_amount( (string) ( $posted[ $key ] ?? '' ) );
		if ( null === $amount ) { $empty++; continue; }
		if ( 'zero' === $amount ) {
			$errors[] = $label . ' no puede ser 0: ningún componente gratuito está aprobado. Para quitar la regla, deja los tres campos vacíos.';
			continue;
		}
		if ( 'invalid' === $amount ) {
			$errors[] = $label . ' debe ser un entero en CLP (1 a 99.999.999).';
			continue;
		}
		$values[ $key ] = $amount;
	}
	if ( $empty > 0 && $empty < count( $fields ) ) {
		$errors[] = 'La regla se guarda completa: completa cargo fijo, monto por kilómetro y cobro mínimo, o deja los tres vacíos para quitarla.';
	}
	if ( ! empty( $errors ) || count( $fields ) === $empty ) {
		return array( 'errors' => $errors, 'rule' => null );
	}
	return array(
		'errors' => array(),
		'rule'   => array( 'base_fee' => $values['base_fee'], 'per_km' => $values['per_km'], 'minimum' => $values['minimum'] ),
	);
}

/** The shape's linear term — cargo fijo + CLP/km × started kilometers: the one arithmetic every render shares. */
function fpw_rule_linear_amount( array $rule, int $km ): int {
	return $rule['base_fee'] + $rule['per_km'] * $km;
}

/**
 * The ONE shape's arithmetic on one maintained rule and one consulted
 * distance: cargo fijo + CLP/km × started whole kilometers, never below the
 * cobro mínimo — in exact integer CLP, never a float. A non-positive distance
 * is no route reference at all: nothing is suggested.
 *
 * @param array $rule The maintained shape's three parameters: base_fee, per_km, minimum.
 * @return array{state:'sin_regla'}|array{state:'ok',amount:int,km:int,minimum_applied:bool}
 */
function fpw_rule_suggestion_for( array $rule, int $distance_meters ): array {
	if ( $distance_meters < 1 ) { return array( 'state' => 'sin_regla' ); }
	$km              = intdiv( $distance_meters + 999, 1000 );   // each started kilometer counts complete
	$linear          = fpw_rule_linear_amount( $rule, $km );
	$minimum_applied = $rule['minimum'] > $linear;
	return array(
		'state'           => 'ok',
		'amount'          => $minimum_applied ? $rule['minimum'] : $linear,
		'km'              => $km,
		'minimum_applied' => $minimum_applied,
	);
}

/**
 * The maintained rule's suggestion for one consulted distance (issue #61).
 * It answers for the distance of the CURRENT consultation only: no stored
 * distance exists to feed it (issue #60 stores nothing), and it writes nothing.
 */
function fpw_rule_suggestion( int $distance_meters ): array {
	$rule = fpw_rule_read();
	return null === $rule ? array( 'state' => 'sin_regla' ) : fpw_rule_suggestion_for( $rule['rule'], $distance_meters );
}

/** One exact integer CLP amount as the rule speaks it — deterministic grouped text. */
function fpw_rule_clp( int $amount ): string {
	return number_format( $amount, 0, ',', '.' );
}

/**
 * The suggestion block rendered under a successful distance consultation
 * (issue #61): with a maintained rule, the internal Dispatch Estimate
 * Breakdown and the monetary suggestion — clearly a SUGGESTION for the
 * owner's decision, never the chosen amount, never buyer-facing. Without a
 * maintained rule the honest state names the manual path; no amount is
 * invented. A fresh consultation may break differently: this is not a
 * reconstruction of any approved historical breakdown.
 */
function fpw_rule_suggestion_html( int $distance_meters ): string {
	$maintained = fpw_rule_read();
	$suggestion = null === $maintained ? array( 'state' => 'sin_regla' ) : fpw_rule_suggestion_for( $maintained['rule'], $distance_meters );
	if ( 'sin_regla' === $suggestion['state'] ) {
		return '<p>La regla de despacho no está configurada en este sitio: ninguna distancia se convierte en monto y el despacho se define manualmente — queda pendiente o fijas tú el importe en el formulario de despacho. <a href="' . esc_url( fpw_rule_screen_url() ) . '">Mantenedor de la regla de despacho</a></p>';
	}
	$rule = $maintained['rule'];
	$minimum_note = $suggestion['minimum_applied']
		? '; el cálculo no alcanza el cobro mínimo, así que se aplica el mínimo de ' . fpw_rule_clp( $rule['minimum'] ) . ' CLP neto.'
		: '; el cobro mínimo de ' . fpw_rule_clp( $rule['minimum'] ) . ' CLP no se aplica porque el cálculo ya lo supera.';
	$linear    = fpw_rule_linear_amount( $rule, $suggestion['km'] );
	$breakdown = 'Desglose interno: cargo fijo ' . fpw_rule_clp( $rule['base_fee'] )
		. ' + ' . fpw_rule_clp( $rule['per_km'] ) . ' CLP/km × ' . $suggestion['km'] . ' km (kilómetros de ruta iniciados) = ' . fpw_rule_clp( $linear ) . ' CLP neto'
		. $minimum_note;
	return '<p><strong>Sugerencia de la regla: ' . esc_html( fpw_rule_clp( $suggestion['amount'] ) ) . ' CLP neto</strong> — es una referencia interna para tu decisión: no es el monto elegido ni algo que el comprador vea. Para ofrecerlo, fíjalo tú en el campo de monto de despacho; ningún recálculo ni cambio de regla reemplaza tu ingreso manual.</p>'
		. '<p>' . esc_html( $breakdown ) . '</p>'
		. '<p>Limitaciones de la referencia: la ruta es de conducción para vehículo menor — no certifica el acceso de un camión, no incluye peajes, retorno ni el cobro real del transportista, y el desglose de una nueva consulta puede diferir del anterior.</p>';
}

/** The acting owner's login, for the rule's consultable provenance. */
function fpw_rule_actor(): string {
	if ( ! function_exists( 'wp_get_current_user' ) ) { return 'sistema'; }
	$user = wp_get_current_user();
	return ( is_object( $user ) && ! empty( $user->user_login ) ) ? (string) $user->user_login : 'sistema';
}

/** Request-scoped stash for the save outcome (the admin_init pass runs before the screen renders). */
function fpw_pending_rule_save( ?array $set = null ): ?array {
	static $pending = null;
	return null === $set ? $pending : ( $pending = $set );
}

/** The save's server-side half: CSRF first, then all-or-nothing validation and write. */
function fpw_rule_handle_save_request(): array {
	if ( ! wp_verify_nonce( (string) ( $_POST['fpw_rule_nonce'] ?? '' ), FPW_RULE_NONCE_SAVE ) ) {
		wp_die( 'Tu sesión expiró o el formulario no es válido: vuelve a cargar el mantenedor e inténtalo de nuevo. Nada se guardó.', '', array( 'response' => 403 ) );
	}
	$posted = isset( $_POST['fpw_rule'] ) && is_array( $_POST['fpw_rule'] ) ? wp_unslash( $_POST['fpw_rule'] ) : array();
	$parsed = fpw_rule_parse_input( $posted );
	if ( ! empty( $parsed['errors'] ) ) {
		$extra = count( $parsed['errors'] ) > 1 ? ' (y ' . ( count( $parsed['errors'] ) - 1 ) . ' otros problemas.)' : '';
		return array( 'ok' => false, 'message' => 'No se guardó nada: ' . $parsed['errors'][0] . $extra, 'errors' => $parsed['errors'] );
	}
	if ( null === $parsed['rule'] ) {
		fpw_rule_write( null, fpw_rule_actor() );
		return array( 'ok' => true, 'message' => 'Regla quitada: las consultas de distancia vuelven a no sugerir ningún monto y el despacho se define manualmente.' );
	}
	fpw_rule_write( $parsed['rule'], fpw_rule_actor() );
	return array(
		'ok'      => true,
		'message' => 'Regla de despacho guardada: cargo fijo ' . fpw_rule_clp( $parsed['rule']['base_fee'] ) . ' + ' . fpw_rule_clp( $parsed['rule']['per_km'] ) . ' CLP/km (kilómetro iniciado), mínimo ' . fpw_rule_clp( $parsed['rule']['minimum'] ) . '. Solo sugiere: nunca reemplaza el monto que fijas.',
		'rule'    => $parsed['rule'],
	);
}

/** The uniform denial: private to the owner, stated in Spanish, 403. */
function fpw_rule_die_forbidden(): void {
	wp_die( 'La regla de despacho es privada del dueño: requiere una sesión con permisos de administración de WooCommerce.', '', array( 'response' => 403 ) );
}

/** The save front door, ahead of wp-admin's own header render: authorization first, then CSRF. */
function fpw_rule_maybe_handle_save(): void {
	if ( FPW_RULE_SCREEN !== (string) ( $_GET['page'] ?? '' ) || empty( $_POST['fpw_rule_save'] ) ) { return; }
	if ( ! current_user_can( 'manage_woocommerce' ) ) { fpw_rule_die_forbidden(); }
	fpw_pending_rule_save( fpw_rule_handle_save_request() );
}
add_action( 'admin_init', 'fpw_rule_maybe_handle_save', 0 );

/** The private screen: unlisted, keyed on the owner capability. */
add_action( 'admin_menu', 'fpw_rule_register_screen' );
function fpw_rule_register_screen(): void {
	add_submenu_page( null, 'Regla de despacho', 'Regla de despacho', 'manage_woocommerce', FPW_RULE_SCREEN, 'fpw_rule_render_screen' );
}

/** The screen callback: capability first, then the stored state and the save outcome. */
function fpw_rule_render_screen(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) { fpw_rule_die_forbidden(); }
	$banner = fpw_pending_rule_save();
	echo fpw_rule_screen_markup( is_array( $banner ) ? $banner : array() );
}

/** The action-result banner. */
function fpw_rule_banner_html( array $banner ): string {
	if ( ! isset( $banner['message'] ) ) { return ''; }
	$class = ! empty( $banner['ok'] ) ? 'notice-success' : 'notice-error';
	return '<div class="notice ' . $class . '"><p>' . esc_html( (string) $banner['message'] ) . '</p></div>';
}

/** One rule parameter as the owner edits it: plain integer, empty means unset. */
function fpw_rule_input_html( string $name, string $label, ?int $value ): string {
	return '<label class="fpw-rule__field">' . esc_html( $label )
		. '<input type="number" inputmode="numeric" min="1" max="' . FPW_RULE_MAX_AMOUNT . '" step="1" name="fpw_rule[' . esc_attr( $name ) . ']" value="' . ( null !== $value && $value > 0 ? $value : '' ) . '" placeholder="Sin valor" /></label>';
}

/** The mobile-first screen shell and its styles. */
function fpw_rule_screen_shell( string $inner ): string {
	return '<div class="wrap fpw-rule"><style>'
		. '.fpw-rule{max-width:960px;font-size:16px;line-height:1.5}'
		. '.fpw-rule h1{font-size:24px;line-height:1.2;margin:4px 0 4px}'
		. '.fpw-rule__kicker{color:#60626d;margin:12px 0 0}'
		. '.fpw-rule section{border:1px solid #dcdcde;border-radius:8px;padding:14px 16px;background:#fff;margin:16px 0;min-width:0}'
		. '.fpw-rule h2{font-size:16px;margin:0 0 10px}'
		. '.fpw-rule ul{margin:4px 0 0 18px;padding:0;display:grid;gap:6px}'
		. '.fpw-rule__field{display:grid;gap:4px;font-size:13px;font-weight:600;color:#60626d;margin:0 0 10px}'
		. '.fpw-rule__field input{font-size:16px;padding:8px 10px;border:1px solid #c3c4c7;border-radius:4px;width:100%;max-width:16rem;box-sizing:border-box;background:#fff;font-family:inherit}'
		. '.fpw-rule form button{font-size:16px;font-weight:600;padding:10px 18px;border-radius:6px;cursor:pointer}'
		. '.fpw-rule__note{color:#60626d;font-size:14px;margin:8px 0 0}'
		. '.fpw-rule__example{border-left:3px solid #72aee6;background:#f0f6fc;padding:10px 12px;border-radius:0 6px 6px 0;margin:10px 0 0}'
		. '@media (min-width: 782px){.fpw-rule__grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}}'
		. '</style>' . $inner . '<!-- fpw-rule:end --></div>';
}

/**
 * The worked example of the maintained rule's effect: one consultation at
 * 29.400 m of route — computed by the shape's own arithmetic, never a
 * separate formula that could drift from it.
 */
function fpw_rule_worked_example_html( array $rule ): string {
	$worked = fpw_rule_suggestion_for( $rule, 29400 );   // 29.400 m → 30 started kilometers
	return '<div class="fpw-rule__example"><strong>Efecto con estos valores:</strong> un destino consultado a 29.400 m de ruta (30 kilómetros iniciados) sugeriría '
		. esc_html( fpw_rule_clp( $rule['base_fee'] ) ) . ' + ' . esc_html( fpw_rule_clp( $rule['per_km'] ) ) . ' × ' . $worked['km'] . ' = <strong>' . esc_html( fpw_rule_clp( $worked['amount'] ) ) . ' CLP neto</strong>'
		. ( $worked['minimum_applied'] ? ' (se aplica el cobro mínimo).' : '.' )
		. ' La sugerencia real aparece junto a cada consulta de distancia y usa los kilómetros consultados de ese momento.</div>';
}

/**
 * The mantenedor screen markup: the contract (what the rule is and is not,
 * its external calibration prerequisite, its privacy and mutation boundary),
 * then the three-parameter form and the worked example of its current effect.
 */
function fpw_rule_screen_markup( array $banner = array() ): string {
	$stored = fpw_rule_read();
	$saved  = null === $stored ? null : $stored['rule'];   // the shape's three parameters, when a rule is maintained
	$nonce  = wp_nonce_field( FPW_RULE_NONCE_SAVE, 'fpw_rule_nonce', true, false );

	$contract = '<section><h2>Cómo funciona esta regla</h2><ul>'
		. '<li>La regla produce <strong>solo una sugerencia interna para ti</strong>, junto a la consulta de distancia de un borrador: nunca calcula el despacho por sí sola, nunca llena el monto elegido y jamás llega al comprador — el comprador ve únicamente el monto comercial que fijas.</li>'
		. '<li>Una sola forma de regla: <strong>cargo fijo + monto por kilómetro × kilómetros iniciados, nunca bajo el cobro mínimo</strong>. Los kilómetros son los de la consulta de ruta del borrador y cada kilómetro iniciado se cuenta completo (los metros se redondean hacia arriba). Esta forma no incluye retorno ni peajes: si el contraste con cobros reales mostrara que corresponden, incorporarlos sería una decisión aparte.</li>'
		. '<li>Los parámetros deben salir de <strong>contrastar fletes reales del transportista</strong> (destino y cobro). Esa calibración es un insumo externo aún pendiente: hasta que exista, mantén la regla sin configurar — sin regla el despacho queda pendiente o lo fijas manualmente, nunca un monto inventado.</li>'
		. '<li>Cambiar esta regla <strong>no reescribe</strong> borradores guardados, vistas previas ni documentos emitidos, y el desglose de una nueva consulta puede diferir: no reconstruye ningún histórico aprobado.</li>'
		. '<li>Guardar aquí solo cambia esta regla privada: no toca productos, solicitudes, borradores ni nada público, y no envía ninguna notificación.</li>'
		. '</ul></section>';

	$status = null !== $saved
		? 'Regla vigente desde el ' . esc_html( date_i18n( get_option( 'date_format' ), $stored['updated_at'] ) ) . ' por ' . esc_html( $stored['updated_by'] ) . '.'
		: 'Sin regla configurada: las consultas de distancia no sugieren ningún monto y el despacho se define manualmente.';

	$example = null !== $saved
		? fpw_rule_worked_example_html( $saved )
		: '<div class="fpw-rule__example">Sin valores no hay ejemplo que mostrar: ningún coeficiente se inventa aquí.</div>';

	return fpw_rule_screen_shell(
		'<h1>Regla de despacho</h1>'
		. '<p class="fpw-rule__kicker">Sugerencia de flete · referencia interna del dueño</p>'
		. fpw_rule_banner_html( $banner )
		. $contract
		. '<section><h2>Parámetros de la regla</h2><p class="fpw-rule__note">' . $status . '</p>'
		. '<form method="post" action="' . esc_url( fpw_rule_screen_url() ) . '">'
		. '<input type="hidden" name="fpw_rule_save" value="1" />'
		. $nonce
		. '<div class="fpw-rule__grid">'
		. fpw_rule_input_html( 'base_fee', 'Cargo fijo (CLP)', $saved['base_fee'] ?? null )
		. fpw_rule_input_html( 'per_km', 'Monto por kilómetro (CLP/km)', $saved['per_km'] ?? null )
		. fpw_rule_input_html( 'minimum', 'Cobro mínimo (CLP)', $saved['minimum'] ?? null )
		. '</div>'
		. '<p><button type="submit" class="button button-primary">Guardar regla de despacho</button></p>'
		. '<p class="fpw-rule__note">Los tres parámetros se guardan juntos: un valor faltante o inválido no guarda nada. Dejar los tres vacíos quita la regla (las consultas dejan de sugerir montos). Un 0 no se acepta: ningún componente gratuito está aprobado.</p>'
		. '</form>'
		. $example
		. '</section>'
	);
}
