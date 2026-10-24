<?php
/** Ticket 9 — Volver keeps the origin inbox context (tab, search, order, page) through whitelisted params only. */
define( 'ABSPATH', __DIR__ );
$GLOBALS['fpwb_caps'] = array( 'manage_woocommerce' => true );
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function current_user_can( string $cap ): bool { return ! empty( $GLOBALS['fpwb_caps'][ $cap ] ); }
function fpw_can_manage_quotations() { return current_user_can( 'manage_woocommerce' ) || current_user_can( 'fpw_manage_quotations' ); }
function fpw_can_manage_data() { return current_user_can( 'manage_woocommerce' ) || current_user_can( 'fpw_manage_data' ); }
function fpw_restricted_user() { return ! current_user_can( 'manage_woocommerce' ); }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }
function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }
function esc_url( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function is_email( $v ) { return false !== filter_var( (string) $v, FILTER_VALIDATE_EMAIL ); }
function wp_unslash( $v ) { return $v; }
function admin_url( $path = '' ) { return 'https://freeplast.test/wp-admin/' . $path; }
function home_url( $path = '' ) { return 'https://freeplast.test' . $path; }
function plugins_url( $path, $file = '' ) { return 'https://freeplast.test/plugins/' . $path; }
function get_theme_file_uri( $path = '' ) { return 'https://freeplast.test/theme/' . $path; }
function wp_login_url() { return 'https://freeplast.test/wp-login.php'; }
function wp_logout_url( $u ) { return 'https://freeplast.test/logout'; }
function wp_nonce_field( $action, $name, $referer = true, $echo = true ) { return '<input name="' . $name . '">'; }
function wp_date( $format, $timestamp, $tz = null ) { return gmdate( $format, (int) $timestamp ); }
function fpw_price_screen_url() { return admin_url( 'admin.php?page=fpw-price-list' ); }
function fpw_sales_history_url( $args = array() ) { return admin_url( 'admin.php?page=fpw-sales-history' ); }
function fpw_sales_import_screen_url() { return admin_url( 'admin.php?page=fpw-sales-import' ); }
/* WordPress merge semantics: array form keeps untouched params, false values remove. */
function add_query_arg( $key, $value = null, $url = null ) {
	$args = is_array( $key ) ? $key : array( (string) $key => $value );
	$base = is_array( $key ) ? ( null === $value ? null : $value ) : $url;
	if ( null === $base ) { throw new RuntimeException( 'test harness needs an explicit base URL' ); }
	$parts = parse_url( (string) $base );
	parse_str( $parts['query'] ?? '', $params );
	foreach ( $args as $k => $v ) { if ( false === $v ) { unset( $params[ $k ] ); } else { $params[ $k ] = $v; } }
	$query = http_build_query( $params );
	return $parts['scheme'] . '://' . $parts['host'] . ( $parts['path'] ?? '' ) . ( $query ? '?' . $query : '' );
}
class FPWB_Order {
	public function __construct( private int $id ) {}
	public function get_order_number() { return (string) $this->id; }
	public function get_billing_company() { return 91 === $this->id ? 'Frutas Andinas' : 'Agrícola Sur'; }
	public function get_date_created() { return null; }
	public function get_meta( $key ) { return ''; }
}
function wc_get_order( $id ) { return in_array( (int) $id, array( 91, 92 ), true ) ? new FPWB_Order( (int) $id ) : null; }
function fpw_read_quotation_version( $id, $number = null ) {
	if ( isset( $GLOBALS['fpwb_version'] ) && 92 === (int) $id ) { return $GLOBALS['fpwb_version']; }
	return 92 === (int) $id && null === $number ? array( 'schema' => 1, 'order_id' => 92, 'version' => 3, 'document' => 'ready', 'delivery' => array( 'state' => 'accepted' ) ) : null;
}
function fpw_quotation_row_name( $id ) { return 'fpw_quotation_' . $id; }
/* Minimal wpdb: the inbox read model itself is covered by workspace-request-query-test.py. */
class FPWB_wpdb {
	public string $prefix = 'wp_';
	public string $posts = 'wp_posts';
	public string $postmeta = 'wp_postmeta';
	public string $options = 'wp_options';
	public string $last_error = '';
	public function get_var( $sql ) {
		if ( str_contains( (string) $sql, 'fpw_draft_91' ) ) {
			return json_encode( array( 'order_id' => 91, 'reference' => 'FP-2025-000091', 'identity' => array( 'company' => 'Frutas Andinas', 'name' => 'Ana', 'email' => 'ana@andinas.cl', 'phone' => '+56911112222', 'giro' => 'Frutícola' ), 'items' => array(), 'received_at' => 0 ) );
		}
		return str_contains( (string) $sql, 'option_value' ) ? null : 2;
	}
	public function get_col( $sql ) { return array( 91, 92 ); }
	public function prepare( $sql, ...$args ) { return $args ? $sql . ' ' . implode( ' ', $args ) : $sql; }
	public function esc_like( $text ) { return addcslashes( $text, '_%\\' ); }
}
$GLOBALS['wpdb'] = new FPWB_wpdb();
require __DIR__ . '/../wp-content/plugins/freeplast-woo/owner-workspace.php';
require __DIR__ . '/../wp-content/plugins/freeplast-woo/quotation-revisions.php';
require __DIR__ . '/../wp-content/plugins/freeplast-woo/quote-draft.php';

$n = 0;
function check( $ok, $label ) { global $n; $n++; if ( ! $ok ) { throw new RuntimeException( $label ); } }

/* --- whitelist extraction --- */
$_GET = array();
check( array() === fpw_workspace_context_args(), 'direct access carries no context' );
$_GET = array( 'stage' => 'sent-quotes', 'q' => 'Freeplast SpA', 'sort' => 'oldest', 'paged' => '3' );
check( array( 'stage' => 'sent-quotes', 'q' => 'Freeplast SpA', 'sort' => 'oldest', 'paged' => 3 ) === fpw_workspace_context_args(), 'tab, search, order and page all survive' );
$_GET = array( 'stage' => 'pendientes', 'sort' => 'newest', 'paged' => '1', 'q' => '' );
check( array() === fpw_workspace_context_args(), 'default values stay implicit' );
$_GET = array( 'stage' => 'evil', 'paged' => array( 99 ), 'q' => array( 'x' ), 'sort' => 'RANDOM', 'redirect_to' => 'https://evil.test', 'url' => '//evil.test' );
check( array() === fpw_workspace_context_args(), 'unknown keys, arrays and hostile params are dropped' );
$_GET = array( 'q' => str_repeat( 'a', 150 ) );
check( 100 === mb_strlen( fpw_workspace_context_args()['q'] ), 'search stays bounded at 100 characters' );
$_GET = array( 'q' => '<b>Frutas</b> <script>alert(1)</script> Andinas' );
check( 'Frutas alert(1) Andinas' === fpw_workspace_context_args()['q'], 'search text is stripped before entering any URL' );

/* --- internal-only return URL --- */
$_GET = array( 'stage' => 'archived', 'q' => 'mostazal', 'sort' => 'oldest', 'paged' => '4' );
$back = fpw_workspace_back_url();
check( str_starts_with( $back, 'https://freeplast.test/wp-admin/admin.php?page=fpw-quotations' ), 'back URL stays on the internal inbox' );
check( str_contains( $back, 'stage=archived' ) && str_contains( $back, 'q=mostazal' ) && str_contains( $back, 'sort=oldest' ) && str_contains( $back, 'paged=4' ), 'back URL keeps tab, search, order and page' );
$_GET = array( 'stage' => 'sent-quotes', 'redirect_to' => 'https://evil.test/x', 'return' => '//evil.test' );
$hostile = fpw_workspace_back_url();
check( ! str_contains( $hostile, 'evil.test' ) && str_starts_with( $hostile, 'https://freeplast.test/' ), 'hostile params cannot turn the return into an external redirect' );
$_GET = array();
check( 'https://freeplast.test/wp-admin/admin.php?page=fpw-quotations' === fpw_workspace_back_url(), 'no context falls back to the plain inbox' );

/* --- inbox rows open detail/edit with their origin context --- */
$_GET = array( 'stage' => 'sent-quotes', 'q' => 'Freeplast SpA', 'sort' => 'oldest', 'paged' => '3' );
ob_start();
fpw_render_workspace();
$list = ob_get_clean();
check( str_contains( $list, '<article data-fpw-request="91"' ) && str_contains( $list, '<article data-fpw-request="92"' ), 'inbox renders both fixture rows' );
$draft_row = substr( $list, strpos( $list, '<article data-fpw-request="91"' ) );
$draft_row = substr( $draft_row, 0, strpos( $draft_row, '</article>' ) );
foreach ( array( 'request=91', 'workspace=1', 'stage=sent-quotes', 'q=Freeplast+SpA', 'sort=oldest', 'paged=3' ) as $part ) {
	check( str_contains( $draft_row, $part ), 'draft link carries ' . $part );
}
$issued_row = substr( $list, strpos( $list, '<article data-fpw-request="92"' ) );
$issued_row = substr( $issued_row, 0, strpos( $issued_row, '</article>' ) );
$issued_row = html_entity_decode( $issued_row, ENT_QUOTES );
check( str_contains( $issued_row, 'post.php?post=92&action=edit&stage=sent-quotes' ) && str_contains( $issued_row, 'q=Freeplast+SpA' ) && str_contains( $issued_row, 'paged=3' ), 'request without draft opens its native admin edit with context' );
check( str_contains( $issued_row, 'revise=3&stage=sent-quotes' ) && str_contains( $issued_row, 'q=Freeplast+SpA' ) && str_contains( $issued_row, 'paged=3' ), 'edit link carries tab, search and page' );

/* --- direct access renders the same inbox without stray params --- */
$_GET = array( 'page' => 'fpw-quotations' );
ob_start();
fpw_render_workspace();
$plain = ob_get_clean();
$plain_row = substr( $plain, strpos( $plain, '<article data-fpw-request="91"' ) );
$plain_row = html_entity_decode( substr( $plain_row, 0, strpos( $plain_row, '</article>' ) ), ENT_QUOTES );
check( str_contains( $plain_row, 'request=91&workspace=1"' ) && ! str_contains( $plain_row, 'stage=' ) && ! str_contains( $plain_row, 'paged=' ), 'without context the row links stay clean' );

/* --- version history keeps the return context across hops --- */
$_GET = array( 'stage' => 'sent-quotes', 'q' => 'Freeplast SpA', 'paged' => '3' );
$GLOBALS['fpwb_version'] = array( 'schema' => 1, 'order_id' => 92, 'version' => 2, 'history' => array( array( 'schema' => 1, 'order_id' => 92, 'version' => 1 ) ) );
$history = html_entity_decode( fpw_quotation_history_html( 92, 2 ), ENT_QUOTES );
check( str_contains( $history, 'version=1&stage=sent-quotes' ) && str_contains( $history, 'version=2&stage=sent-quotes' ) && str_contains( $history, 'q=Freeplast+SpA' ), 'version history links carry the inbox context' );
check( str_contains( $history, 'aria-current="page"' ), 'the open version stays marked' );

/* --- contact editor form and cancel link preserve whitelisted context and drop invalid context --- */
$_GET = array( 'stage' => 'all', 'q' => 'QA Y6S8', 'sort' => 'oldest', 'paged' => '2', 'redirect_to' => 'https://evil.test', 'evil' => '1' );
$draft_91 = fpw_read_request_draft( 91 );
$contact_html = html_entity_decode( fpw_draft_identity_form( $draft_91, null ), ENT_QUOTES );
$expected_contact = 'https://freeplast.test/wp-admin/admin.php?page=fpw-quote-draft&request=91&workspace=1&stage=all&q=QA+Y6S8&sort=oldest&paged=2';
check( str_contains( $contact_html, 'data-fpw-identity method="post" action="' . $expected_contact . '"' ), 'contact form action keeps stage, q, sort and paged' );
check( str_contains( $contact_html, 'data-fpw-identity-cancel href="' . $expected_contact . '"' ), 'contact cancel link keeps stage, q, sort and paged' );
check( ! str_contains( $contact_html, 'evil' ), 'contact form and cancel drop invalid/hostile context params' );
$_POST = array( 'fpw_identity_save' => '1', 'fpw_identity' => array( 'company' => 'Frutas Andinas', 'name' => 'Ana', 'email' => 'bad', 'phone' => '+56911112222', 'giro' => 'Frutícola' ) );
fpw_pending_draft_outcome( array( 'order_id' => 91, 'result' => array( 'state' => 'invalid' ) ) );
$invalid_contact = html_entity_decode( fpw_draft_identity_form( $draft_91, null ), ENT_QUOTES );
check( str_contains( $invalid_contact, 'class="fpw-identity-editor" open' ) && str_contains( $invalid_contact, 'action="' . $expected_contact . '"' ) && str_contains( $invalid_contact, 'data-fpw-identity-cancel href="' . $expected_contact . '"' ), 'invalid contact re-render preserves whitelisted inbox context' );
$_POST = array();
fpw_pending_draft_outcome( null );
$_GET = array( 'stage' => 'evil', 'sort' => 'bad', 'paged' => '1', 'redirect_to' => '//evil.test' );
$plain_contact = html_entity_decode( fpw_draft_identity_form( $draft_91, null ), ENT_QUOTES );
check( str_contains( $plain_contact, 'action="https://freeplast.test/wp-admin/admin.php?page=fpw-quote-draft&request=91&workspace=1"' ) && str_contains( $plain_contact, 'data-fpw-identity-cancel href="https://freeplast.test/wp-admin/admin.php?page=fpw-quote-draft&request=91&workspace=1"' ) && ! str_contains( $plain_contact, 'evil' ), 'invalid-only context falls back to clean draft URL on contact form and cancel' );

echo "workspace back navigation: $n checks passed\n";
