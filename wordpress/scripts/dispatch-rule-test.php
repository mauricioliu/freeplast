<?php
/** Issue #61 — corte 12 de #49: sugerir despacho mediante una regla calibrada y editable.
 * Offline contract of the owner's dispatch pricing rule (Regla de despacho): ONE
 * explicit shape — cargo fijo + CLP/km × started whole kilometers, never below
 * the cobro mínimo — maintained on a private unlisted screen with strict
 * all-or-nothing validation; ABSENT by default (no shipped coefficient: without
 * a valid rule the consultation suggests no amount and dispatch stays pending
 * or manual); the suggestion is an owner-facing reference computed from the
 * CURRENT consultation only — never stored, never the chosen amount, never
 * buyer-facing; changing the rule rewrites nothing (drafts, previews, records)
 * and the screen explains the rule's effect and its external calibration
 * prerequisite (real Carrier charges), never inventing precision. */
define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
$registered_actions = array();
$registered_filters = array();
function add_action( ...$args ) { global $registered_actions; $registered_actions[ $args[0] ][] = $args[1] ?? null; }
function add_filter( ...$args ) { global $registered_filters; $registered_filters[ $args[0] ][] = $args[1] ?? null; }
function register_activation_hook( ...$args ) {}
function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags ); }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
class WP_Error {
	public array $codes = array();
	public function add( $code, $message, $data = null ) { $this->codes[] = $code; }
}
require __DIR__ . '/../wp-content/plugins/freeplast-woo/freeplast-woo.php';

$assertions = 0;
function check( $ok, $message ) { global $assertions; $assertions++; if ( ! $ok ) { throw new RuntimeException( $message ); } }

/* --- WordPress runtime stubs the feature needs beyond plugin load --- */
class FPRW_Die extends RuntimeException {}
function wp_die( $message = '', $title = '', $args = array() ) { throw new FPRW_Die( (string) ( $args['response'] ?? 0 ) ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_url( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function get_current_user_id(): int { return 1; }
$GLOBALS['fprw_user_login'] = 'dueña';
function wp_get_current_user() { $u = new stdClass(); $u->user_login = $GLOBALS['fprw_user_login']; return $u; }
$GLOBALS['fprw_nonce_ok'] = true;
function wp_create_nonce( $action = -1 ) { return 'offline-nonce'; }
function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ) {
	$html = '<input type="hidden" name="' . esc_attr( (string) $name ) . '" value="offline-nonce" />';
	if ( $echo ) { echo $html; }
	return $html;
}
function wp_verify_nonce( $nonce, $action = -1 ) { return $GLOBALS['fprw_nonce_ok'] && 'offline-nonce' === (string) $nonce ? 1 : false; }
function admin_url( $path = '' ) { return 'https://freeplast.test/wp-admin/' . $path; }
function date_i18n( $format, $timestamp ) { return gmdate( 'Y-m-d', (int) $timestamp ); }
function wp_unslash( $value ) { return $value; }
function do_action( ...$args ): void {}
function apply_filters( $tag, $value ) { return $value; }
$GLOBALS['fprw_options'] = array( 'date_format' => 'j F Y' );
function get_option( $name, $default = false ) { return $GLOBALS['fprw_options'][ $name ] ?? $default; }
$GLOBALS['fprw_caps'] = array();
function current_user_can( string $cap ): bool { return ! empty( $GLOBALS['fprw_caps'][ $cap ] ); }
$GLOBALS['fprw_submenu'] = null;
function add_submenu_page( $parent, $page_title, $menu_title, $capability, $slug, $callback ) {
	$GLOBALS['fprw_submenu'] = compact( 'parent', 'page_title', 'menu_title', 'capability', 'slug', 'callback' );
	return $slug;
}

/* Fake wpdb with the real unique option_name semantics: INSERT onto an existing
 * name loses; both UPDATE shapes behave as SQL does; reads bypass any cache. */
class FPRW_Fake_wpdb {
	public string $prefix = 'fp_';
	public string $options = 'wp_options';
	public function suppress_errors( $set = null ) { return false; }
	public function esc_like( $text ) { return addcslashes( $text, '_%\\' ); }
	public function prepare( $sql, ...$args ) {
		foreach ( $args as $arg ) { $pos = strpos( $sql, '%s' ); $sql = substr( $sql, 0, $pos ) . "'" . $arg . "'" . substr( $sql, $pos + 2 ); }
		return $sql;
	}
	public function query( $sql ) {
		if ( preg_match( "/INSERT INTO \{?\w*options\}? \( option_name, option_value, autoload \) VALUES \( '(.+?)', '(.*)', 'off' \)$/s", $sql, $m ) ) {
			if ( array_key_exists( $m[1], $GLOBALS['fprw_table'] ) ) { return false; }
			$GLOBALS['fprw_table'][ $m[1] ] = $m[2]; return 1;
		}
		if ( preg_match( "/^UPDATE \{?\w*options\}? SET option_value = '(.*)' WHERE option_name = '(.+?)' AND option_value = '(.*)'$/s", $sql, $m ) ) {
			if ( ! array_key_exists( $m[2], $GLOBALS['fprw_table'] ) || $GLOBALS['fprw_table'][ $m[2] ] !== $m[3] ) { return 0; }
			$GLOBALS['fprw_table'][ $m[2] ] = $m[1]; return 1;
		}
		if ( preg_match( "/^UPDATE \{?\w*options\}? SET option_value = '(.*)' WHERE option_name = '(.+)'$/s", $sql, $m ) ) {
			if ( ! array_key_exists( $m[2], $GLOBALS['fprw_table'] ) ) { return 0; }
			$GLOBALS['fprw_table'][ $m[2] ] = $m[1]; return 1;
		}
		return 0;
	}
	public function get_var( $sql ) {
		if ( preg_match( "/SELECT option_value FROM \{?\w*options\}? WHERE option_name = '(.+)'$/", $sql, $m ) ) {
			return $GLOBALS['fprw_table'][ $m[1] ] ?? null;
		}
		return null;
	}
}
$GLOBALS['fprw_table'] = array();
$GLOBALS['wpdb'] = new FPRW_Fake_wpdb();

function rule_row( int $base, int $per_km, int $minimum, string $by = 'dueña' ): string {
	return wp_json_encode( array( 'schema' => 1, 'updated_at' => 1700000000, 'updated_by' => $by, 'rule' => array( 'base_fee' => $base, 'per_km' => $per_km, 'minimum' => $minimum ) ) );
}

/* ----------------------------------------------------------------------- */
/* Absent by default: without a maintained row nothing suggests an amount.  */
check( fpw_rule_read() === null, 'without a row there is no rule at all (no coefficient ships by default)' );
check( fpw_rule_suggestion( 61200 ) === array( 'state' => 'sin_regla' ), 'an unconfigured rule turns no distance into money' );
$absent_html = fpw_rule_suggestion_html( 61200 );
check( str_contains( $absent_html, 'no está configurada' ) && str_contains( $absent_html, 'manualmente' ), 'the absent-rule state names the manual path honestly' );
check( str_contains( $absent_html, 'fpw-dispatch-rule' ) && ! str_contains( $absent_html, 'Sugerencia de la regla' ), 'the absent-rule state links the mantenedor and never renders a suggestion' );

/* The stored rule degrades to absent unless it is exactly the one shape:
 * three strict positive CLP integers — never a guessed authority. */
$GLOBALS['fprw_table']['fpw_dispatch_rule'] = 'not json at all';
check( fpw_rule_read() === null, 'an unreadable row is no rule' );
$GLOBALS['fprw_table']['fpw_dispatch_rule'] = wp_json_encode( array( 'schema' => 1, 'updated_at' => 0, 'updated_by' => '' ) );
check( fpw_rule_read() === null, 'a row without a rule payload is no rule' );
$GLOBALS['fprw_table']['fpw_dispatch_rule'] = wp_json_encode( array( 'schema' => 1, 'rule' => array( 'base_fee' => 15000 ) ) );
check( fpw_rule_read() === null, 'a rule missing parameters is no rule' );
$GLOBALS['fprw_table']['fpw_dispatch_rule'] = wp_json_encode( array( 'schema' => 1, 'rule' => array( 'base_fee' => 15000, 'per_km' => 0, 'minimum' => 20000 ) ) );
check( fpw_rule_read() === null, 'a zero parameter is not a valid rule component' );
$GLOBALS['fprw_table']['fpw_dispatch_rule'] = wp_json_encode( array( 'schema' => 1, 'rule' => array( 'base_fee' => -5, 'per_km' => 2500, 'minimum' => 20000 ) ) );
check( fpw_rule_read() === null, 'a negative parameter is not a valid rule component' );
$GLOBALS['fprw_table']['fpw_dispatch_rule'] = wp_json_encode( array( 'schema' => 1, 'rule' => array( 'base_fee' => '15000', 'per_km' => 2500, 'minimum' => 20000 ) ) );
check( fpw_rule_read() === null, 'a non-integer stored parameter degrades to absent instead of becoming authority' );
$GLOBALS['fprw_table']['fpw_dispatch_rule'] = wp_json_encode( array( 'schema' => 1, 'updated_at' => 1700000000, 'updated_by' => 'dueña', 'rule' => array( 'base_fee' => 15000, 'per_km' => 2500, 'minimum' => 20000, 'return_fee' => 9000 ) ) );
$read = fpw_rule_read();
check( is_array( $read ) && $read['rule'] === array( 'base_fee' => 15000, 'per_km' => 2500, 'minimum' => 20000 ), 'a valid rule reads back normalized to its one shape (no extra terms)' );
check( $read['updated_by'] === 'dueña', 'the rule keeps its consultable provenance' );

/* The ONE approved shape, exact integer arithmetic: cargo fijo + CLP/km ×
 * started kilometers, never below the cobro mínimo. */
$GLOBALS['fprw_table']['fpw_dispatch_rule'] = rule_row( 15000, 2500, 20000 );
check( fpw_rule_suggestion( 61200 ) === array( 'state' => 'ok', 'amount' => 170000, 'km' => 62, 'minimum_applied' => false ), '61.2 route km count as 62 started kilometers: 15.000 + 2.500 × 62 = 170.000' );
check( fpw_rule_suggestion( 61000 )['km'] === 61, 'an exact kilometer boundary counts as its own started kilometer (61.000 m → 61 km)' );
check( fpw_rule_suggestion( 61001 )['km'] === 62, 'one meter into the next kilometer starts it (61.001 m → 62 km)' );
check( fpw_rule_suggestion( 999 ) === array( 'state' => 'ok', 'amount' => 20000, 'km' => 1, 'minimum_applied' => true ), 'a sub-kilometer route still starts one kilometer and the minimum applies (17.500 → 20.000)' );
check( fpw_rule_suggestion( 1000 )['minimum_applied'] === true, 'the minimum applies while the linear calculation stays below it' );
check( fpw_rule_suggestion( 1 )['amount'] === 20000, 'even one meter yields the minimum, never a zero amount' );
$GLOBALS['fprw_table']['fpw_dispatch_rule'] = rule_row( 15000, 2500, 170000 );
check( fpw_rule_suggestion( 61200 ) === array( 'state' => 'ok', 'amount' => 170000, 'km' => 62, 'minimum_applied' => false ), 'a minimum equal to the linear result is not "applied": the calculation itself stands' );
$GLOBALS['fprw_table']['fpw_dispatch_rule'] = rule_row( 99999999, 99999999, 99999999 );
check( fpw_rule_suggestion( 10000000 )['amount'] === 1000099989999, 'the worst-case suggestion stays an exact integer (no float drift)' );
$GLOBALS['fprw_table']['fpw_dispatch_rule'] = rule_row( 15000, 2500, 20000 );
check( fpw_rule_suggestion( 0 )['state'] === 'sin_regla' && fpw_rule_suggestion( -3 )['state'] === 'sin_regla', 'a non-positive distance is no route reference at all: no suggestion' );

/* Nothing is stored: the suggestion is computed from the CURRENT consultation
 * only — no durable row changes anywhere. */
$rows_before = $GLOBALS['fprw_table'];
fpw_rule_suggestion( 61200 );
fpw_rule_suggestion_html( 61200 );
check( $GLOBALS['fprw_table'] === $rows_before, 'computing or rendering a suggestion writes nothing anywhere' );

/* The rendered suggestion: internal breakdown, a SUGGESTION distinguishable
 * from the chosen amount, and the route-reference limitations. */
$suggestion_html = fpw_rule_suggestion_html( 61200 );
check( str_contains( $suggestion_html, 'Sugerencia de la regla: 170.000 CLP neto' ), 'the suggestion renders its monetary amount labelled as the rule\'s suggestion' );
check( str_contains( $suggestion_html, 'no es el monto elegido' ) && str_contains( $suggestion_html, 'comprador' ), 'the suggestion names that it is neither the chosen amount nor buyer-facing' );
check( str_contains( $suggestion_html, 'cargo fijo 15.000' ) && str_contains( $suggestion_html, '2.500 CLP/km × 62 km' ) && str_contains( $suggestion_html, 'iniciados' ), 'the breakdown names its inputs and the started-kilometer interpretation' );
check( str_contains( $suggestion_html, 'no se aplica' ), 'the breakdown states the minimum was not reached' );
check( str_contains( $suggestion_html, 'no certifica el acceso de un camión' ) && str_contains( $suggestion_html, 'peajes' ) && str_contains( $suggestion_html, 'puede diferir' ), 'the route-reference limitations ride the suggestion (no certification, no tolls/return, fresh consultations may differ)' );
$GLOBALS['fprw_table']['fpw_dispatch_rule'] = rule_row( 15000, 2500, 200000 );
$min_html = fpw_rule_suggestion_html( 61200 );
check( str_contains( $min_html, 'se aplica el mínimo de 200.000' ), 'when the minimum binds, the breakdown says so explicitly' );

/* Parsing one save: all-or-nothing, the site's one strict CLP rule, and the
 * explicit removal (all three empty). */
$parsed = fpw_rule_parse_input( array( 'base_fee' => ' 15000 ', 'per_km' => '2500', 'minimum' => '20000' ) );
check( $parsed === array( 'errors' => array(), 'rule' => array( 'base_fee' => 15000, 'per_km' => 2500, 'minimum' => 20000 ) ), 'a complete valid submission parses (trimmed integers)' );
check( fpw_rule_parse_input( array( 'base_fee' => '15000', 'per_km' => '0', 'minimum' => '20000' ) )['errors'] !== array(), 'a zero component is refused: no invented gratuity' );
check( fpw_rule_parse_input( array( 'base_fee' => '12.000', 'per_km' => '2500', 'minimum' => '20000' ) )['rule'] === null, 'a formatted amount is refused: integers only' );
check( fpw_rule_parse_input( array( 'base_fee' => '100000000', 'per_km' => '2500', 'minimum' => '20000' ) )['rule'] === null, 'an out-of-bound amount is refused' );
$partial = fpw_rule_parse_input( array( 'base_fee' => '15000', 'per_km' => '', 'minimum' => '20000' ) );
check( $partial['rule'] === null && str_contains( implode( ' ', $partial['errors'] ), 'completa' ), 'a partial submission refuses the whole save: the rule is one shape or none' );
$removal = fpw_rule_parse_input( array( 'base_fee' => '', 'per_km' => '', 'minimum' => '' ) );
check( $removal === array( 'errors' => array(), 'rule' => null ), 'all three empty is the explicit removal, not an error' );
check( fpw_rule_parse_input( array( 'base_fee' => '15000', 'per_km' => '2500', 'minimum' => '20000', 'hacked' => '1' ) )['rule'] === array( 'base_fee' => 15000, 'per_km' => 2500, 'minimum' => 20000 ), 'unknown posted keys never reach the rule' );

/* The save handler: writes the validated rule with its provenance, refuses
 * whole on any error, and removes explicitly. */
unset( $GLOBALS['fprw_table']['fpw_dispatch_rule'] );
$_POST = array( 'fpw_rule_nonce' => 'offline-nonce', 'fpw_rule' => array( 'base_fee' => '15000', 'per_km' => '2500', 'minimum' => '20000' ) );
$banner = fpw_rule_handle_save_request();
check( $banner['ok'] === true && str_contains( $banner['message'], 'Regla de despacho guardada' ), 'a valid save lands with its explicit banner' );
check( fpw_rule_read()['rule'] === array( 'base_fee' => 15000, 'per_km' => 2500, 'minimum' => 20000 ) && fpw_rule_read()['updated_by'] === 'dueña', 'the saved rule persists with its actor' );
$_POST = array( 'fpw_rule_nonce' => 'offline-nonce', 'fpw_rule' => array( 'base_fee' => '15000', 'per_km' => 'x', 'minimum' => '20000' ) );
$rows_before = $GLOBALS['fprw_table'];
$banner = fpw_rule_handle_save_request();
check( $banner['ok'] === false && str_contains( $banner['message'], 'No se guardó nada' ), 'an invalid save is refused with the explicit nothing-saved banner' );
check( $GLOBALS['fprw_table'] === $rows_before, 'the refused save wrote nothing' );
$_POST = array( 'fpw_rule_nonce' => 'offline-nonce', 'fpw_rule' => array( 'base_fee' => '', 'per_km' => '', 'minimum' => '' ) );
$banner = fpw_rule_handle_save_request();
check( $banner['ok'] === true && str_contains( $banner['message'], 'Regla quitada' ), 'the empty save removes the rule explicitly' );
check( fpw_rule_read() === null, 'after the removal no suggestion exists again' );
$GLOBALS['fprw_nonce_ok'] = false;
$_POST = array( 'fpw_rule_nonce' => 'forged', 'fpw_rule' => array( 'base_fee' => '1', 'per_km' => '1', 'minimum' => '1' ) );
try {
	fpw_rule_handle_save_request();
	check( false, 'a save failing CSRF must be refused' );
} catch ( FPRW_Die $e ) {
	check( $e->getMessage() === '403', 'a CSRF failure is an explicit 403' );
}
check( fpw_rule_read() === null, 'the refused CSRF save wrote nothing' );
$GLOBALS['fprw_nonce_ok'] = true;

/* The front door: authorization first (the capability — never the nonce —
 * grants access), then CSRF. */
$_GET = array( 'page' => 'fpw-dispatch-rule' );
$GLOBALS['fprw_caps'] = array( 'read' => true, 'manage_freeplast_quotes' => true, 'edit_shop_orders' => true, 'edit_others_shop_orders' => true );
$_POST = array( 'fpw_rule_save' => '1', 'fpw_rule_nonce' => 'offline-nonce', 'fpw_rule' => array( 'base_fee' => '15000', 'per_km' => '2500', 'minimum' => '20000' ) );
try {
	fpw_rule_maybe_handle_save();
	check( false, 'a valid ventas session must be denied the rule save' );
} catch ( FPRW_Die $e ) {
	check( $e->getMessage() === '403', 'ventas receives the 403 permission denial whatever nonce it presents' );
}
$GLOBALS['fprw_caps'] = array( 'manage_woocommerce' => true );
$GLOBALS['fprw_nonce_ok'] = false;
try {
	fpw_rule_maybe_handle_save();
	check( false, 'a save with an invalid nonce must be refused' );
} catch ( FPRW_Die $e ) {
	check( $e->getMessage() === '403', 'a save failing the CSRF check is refused 403' );
}
$GLOBALS['fprw_nonce_ok'] = true;
$rows_before = $GLOBALS['fprw_table'];
$_GET = array( 'page' => 'fpw-quote-draft' );
fpw_rule_maybe_handle_save();
check( $GLOBALS['fprw_table'] === $rows_before, 'a save posted to another screen touches nothing' );
$_GET = array( 'page' => 'fpw-dispatch-rule' );
fpw_rule_maybe_handle_save();
check( fpw_rule_read()['rule'] === array( 'base_fee' => 15000, 'per_km' => 2500, 'minimum' => 20000 ), 'the authorized save lands through the front door' );

/* Changing the rule rewrites NOTHING else: drafts, saved work and stored
 * previews stand byte-identical — a new suggestion is never retroactive. */
$GLOBALS['fprw_table']['fpw_draft_7'] = '{"schema":2,"order_id":7}';
$GLOBALS['fprw_table']['fpw_draft_work_7'] = '{"schema":2,"revision":3,"dispatch_amount":90000}';
$GLOBALS['fprw_table']['fpw_draft_preview_7'] = '{"schema":1,"revision":3,"projection":{"dispatch":90000}}';
$rows_before = $GLOBALS['fprw_table'];
$_POST = array( 'fpw_rule_nonce' => 'offline-nonce', 'fpw_rule' => array( 'base_fee' => '99000', 'per_km' => '9999', 'minimum' => '123456' ) );
fpw_rule_handle_save_request();
$after = $GLOBALS['fprw_table'];
unset( $after['fpw_dispatch_rule'], $rows_before['fpw_dispatch_rule'] );   // only the rule row itself may differ
check( $after === $rows_before, 'changing the rule touches no draft, work or preview row' );
check( fpw_rule_suggestion( 61200 )['amount'] === 718938, 'the new rule answers only for fresh consultations (99.000 + 9.999 × 62)' );

/* The mantenedor screen: explains the shape, its effect and the external
 * calibration prerequisite; empty when absent, worked example when saved. */
$_POST = array();
$GLOBALS['fprw_submenu'] = null;
fpw_rule_register_screen();
check( $GLOBALS['fprw_submenu']['capability'] === 'manage_woocommerce' && $GLOBALS['fprw_submenu']['slug'] === 'fpw-dispatch-rule', 'the screen registers unlisted and owner-only' );
check( str_contains( fpw_rule_screen_url(), 'page=fpw-dispatch-rule' ), 'the screen url names its page' );

$GLOBALS['fprw_caps'] = array( 'read' => true, 'manage_freeplast_quotes' => true );
try {
	fpw_rule_render_screen();
	check( false, 'a valid ventas session must be denied the rule screen' );
} catch ( FPRW_Die $e ) {
	check( $e->getMessage() === '403', 'ventas receives the 403 permission denial on the screen' );
}
$GLOBALS['fprw_caps'] = array( 'manage_woocommerce' => true );
unset( $GLOBALS['fprw_table']['fpw_dispatch_rule'] );
$empty_screen = fpw_rule_screen_markup();
check( str_contains( $empty_screen, 'Regla de despacho' ) && str_contains( $empty_screen, 'Sin regla configurada' ), 'the empty mantenedor names its subject and its absent state' );
check( str_contains( $empty_screen, 'name="fpw_rule[base_fee]"' ) && str_contains( $empty_screen, 'name="fpw_rule[per_km]"' ) && str_contains( $empty_screen, 'name="fpw_rule[minimum]"' ), 'the mantenedor offers exactly the three parameters of the one shape' );
check( str_contains( $empty_screen, 'value=""' ) && ! str_contains( $empty_screen, 'sugeriría' ), 'the empty mantenedor ships no coefficient and invents no worked example' );
check( str_contains( $empty_screen, 'iniciados' ) && str_contains( $empty_screen, 'cobro mínimo' ), 'the mantenedor documents the kilometer interpretation and the minimum' );
check( str_contains( $empty_screen, 'fletes reales' ) && str_contains( $empty_screen, 'pendiente' ), 'the mantenedor names the external calibration prerequisite (real carrier charges) still pending' );
check( str_contains( $empty_screen, 'comprador' ) && str_contains( $empty_screen, 'no reescribe' ), 'the mantenedor states the buyer never sees the rule and changes rewrite nothing' );
check( str_contains( $empty_screen, 'ningún coeficiente se inventa' ), 'the mantenedor states plainly that no coefficient is invented' );

$GLOBALS['fprw_table']['fpw_dispatch_rule'] = rule_row( 15000, 2500, 20000 );
$saved_screen = fpw_rule_screen_markup();
check( str_contains( $saved_screen, 'name="fpw_rule[base_fee]" value="15000"' ) && str_contains( $saved_screen, 'name="fpw_rule[per_km]" value="2500"' ) && str_contains( $saved_screen, 'name="fpw_rule[minimum]" value="20000"' ), 'the mantenedor recovers its saved values' );
check( str_contains( $saved_screen, 'sugeriría' ) && str_contains( $saved_screen, '90.000 CLP neto' ), 'the mantenedor explains the effect with a worked example from the saved values (15.000 + 2.500 × 30 km)' );
check( str_contains( $saved_screen, 'por dueña' ), 'the mantenedor shows the rule\'s provenance' );

echo "dispatch rule: $assertions offline checks passed (issue #61: one editable shape absent by default, exact suggestion arithmetic, all-or-nothing saves, boundaries, nothing stored, nothing rewritten)\n";
