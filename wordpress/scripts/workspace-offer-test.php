<?php
/** Reuse the quotation contract's WordPress stubs and real, frozen PDF fixtures. */
require __DIR__ . '/quote-draft-test.php';
$start = $assertions;
if ( ! function_exists( 'plugins_url' ) ) { function plugins_url( $path, $file = '' ) { return 'https://freeplast.test/plugins/' . $path; } }
if ( ! function_exists( 'wc_get_product' ) ) { function wc_get_product( $id ) { return false; } }
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $key, $value, $url = null ) {
		$args = is_array( $key ) ? $key : array( $key => $value );
		$base = is_array( $key ) ? $value : $url;
		return $base . ( str_contains( $base, '?' ) ? '&' : '?' ) . http_build_query( $args );
	}
}
$GLOBALS['fpwd_caps'] = array( 'manage_woocommerce' => true );
$GLOBALS['fpwd_nonce_ok'] = true;
fpwd_review_for_approval( 195 );
$version = fpw_read_quotation_version( 91 );
$before_rows = $GLOBALS['fpwd_table'];
$before_mail = count( $GLOBALS['fpwd_mail_calls'] );
$response = fpw_workspace_pdf_response( 91, 1, 'offline-nonce' );
check( $response['bytes'] === base64_decode( $version['pdf_base64'], true ), 'download returns the exact approved bytes, not a rendered replacement' );
check( $response['filename'] === 'cotizacion-91-v1.pdf', 'download filename contains only server-bound numeric IDs' );
check( fpw_workspace_pdf_response( 91, 1, 'offline-nonce' ) === $response, 'repeated download is stable' );
foreach ( array( array( 91, 2, 'offline-nonce', 404 ), array( 999999, 1, 'offline-nonce', 404 ), array( 91, 1, 'forged', 403 ), array( 0, 1, 'offline-nonce', 403 ), array( 97, 1, 'offline-nonce', 404 ) ) as [ $id, $number, $nonce, $status ] ) {
	try { fpw_workspace_pdf_response( $id, $number, $nonce ); check( false, 'invalid download must fail' ); }
	catch ( FPWD_Die $e ) { check( (string) $status === $e->getMessage(), 'missing/pending/mismatched PDF and forged nonce fail closed' ); }
}
foreach ( array( array(), array( 'read' => true, 'edit_shop_orders' => true ) ) as $caps ) {
	$GLOBALS['fpwd_caps'] = $caps;
	try { fpw_workspace_pdf_response( 91, 1, 'offline-nonce' ); check( false, 'non-owner download must fail' ); }
	catch ( FPWD_Die $e ) { check( '403' === $e->getMessage(), 'guest or staff with a valid nonce cannot download' ); }
}
$GLOBALS['fpwd_caps'] = array( 'manage_woocommerce' => true );
foreach ( array( 'invalid base64', base64_encode( '<html>Not a PDF</html>' ) ) as $bad ) {
	$corrupt = $version; $corrupt['pdf_base64'] = $bad;
	check( null === fpw_workspace_pdf_bytes( $corrupt ), 'corrupt stored bytes are never served as a PDF' );
}
$misbound = $version; $misbound['order_id'] = 92;
$GLOBALS['fpwd_table']['fpw_quotation_91'] = wp_json_encode( $misbound );
try { fpw_workspace_pdf_response( 91, 1, 'offline-nonce' ); check( false, 'misbound row must fail' ); }
catch ( FPWD_Die $e ) { check( '404' === $e->getMessage(), 'row identity must match the requested order' ); }
$GLOBALS['fpwd_table'] = $before_rows;

$_GET = array( 'workspace' => '1', 'review' => '1' ); $_POST = array();
$draft = fpw_read_request_draft( 91 );
$work = fpw_read_draft_work( 91 );
$issued = fpw_workspace_detail_markup( wc_get_order( 91 ), $draft, $work, null );
check( str_contains( $issued, 'Descargar PDF aprobado' ) && str_contains( $issued, 'Cotización aprobada' ), 'issued review offers its saved document' );
foreach ( array( 'Volver a ajustar', 'Generar vista previa', 'nada fue aprobado', 'fpw_work_approve', 'data-fpw-work', 'class="fpw-workspace-dock"' ) as $absent ) {
	check( ! str_contains( $issued, $absent ), 'issued review excludes misleading draft action/copy: ' . $absent );
}
check( str_contains( $issued, 'Volver a la cotización' ) && ! str_contains( $issued, 'September' ), 'issued return label and numeric date fit its state and language' );
$changed_work = $work; $changed_work['lines'][0]['price'] = 999999;
check( $issued === fpw_workspace_detail_markup( wc_get_order( 91 ), $draft, $changed_work, null ), 'issued review ignores later draft price changes completely' );
unset( $_GET['review'] );
$detail = fpw_workspace_detail_markup( wc_get_order( 91 ), $draft, $work, null );
check( ! str_contains( $detail, 'data-fpw-work' ) && ! str_contains( $detail, 'data-fpw-tracking' ), 'approved detail is static offer without a manual tracking form' );
check( ! str_contains( $detail, 'Seguimiento comercial' ) && str_contains( $detail, 'Cotización aprobada' ), 'approved detail goes directly to the offer' );

// A separate saved, unapproved request; no production/runtime data is used.
$id = 195; $draft = fpw_read_request_draft( $id ); $work = fpw_read_draft_work( $id ); $preview = fpw_read_draft_preview( $id );
$detail = fpw_workspace_detail_markup( wc_get_order( $id ), $draft, $work, null );
check( str_contains( $detail, 'id="fpw-products"' ) && ! str_contains( $detail, 'data-fpw-tracking' ) && ! str_contains( $detail, 'Seguimiento comercial' ), 'draft keeps products and removes manual tracking entirely' );
check( str_contains( $detail, 'fpw-price-references' ), 'stable price maintenance is secondary, still available' );
check( str_contains( $detail, 'data-fpw-origin="0"' ) && str_contains( $detail, 'Precio guardado para esta oferta.' ), 'H6: each price line ships its origin legend bound for unsaved-value feedback' );
check( str_contains( $detail, 'data-fpw-dock-save' ) && str_contains( $detail, 'form="fpw-work-form"' ), 'mobile save submits the existing guarded native form' );
$review = fpw_workspace_preview_html( $draft, $work, $preview );
check( str_contains( $review, 'fpw_approve_preview' ) && str_contains( $review, fpw_quotation_preview_token( $preview ) ), 'current complete review carries the exact approval token' );
// H4 (2026-10-03 review): the review names the exact recipient the approval will freeze —
// sourced from the same request-draft identity fpw_quotation_approve_and_send() freezes.
check( str_contains( $review, 'Se enviará a:</strong> ' . esc_html( (string) $draft['identity']['email'] ) ), 'H4: the approval review shows the exact frozen recipient before committing' );
$no_recipient = $draft; $no_recipient['identity']['email'] = '';
check( str_contains( fpw_workspace_preview_html( $no_recipient, $work, $preview ), 'El correo del comprador no está disponible' ), 'H4: a missing recipient is named as pending instead of promising a send' );
check( str_contains( $review, 'class="fpw-grand-total"' ) && ! str_contains( $review, 'September' ), 'review gives total a distinct semantic class and numeric date' );
check( ! str_contains( $review, 'Generar vista previa' ), 'current review has one approval action, no redundant regeneration' );
$obsolete = $preview; $obsolete['revision'] = -1;
$stale = fpw_workspace_preview_html( $draft, $work, $obsolete );
check( str_contains( $stale, 'Vista previa obsoleta' ) && ! str_contains( $stale, 'fpw_work_approve' ) && str_contains( $stale, 'Generar vista previa' ), 'stale preview keeps recovery, blocks approval' );
$incomplete = $preview; $incomplete['projection']['complete'] = false; $incomplete['projection']['missing'] = array( 'IVA por configurar' );
$blocked = fpw_workspace_preview_html( $draft, $work, $incomplete );
check( str_contains( $blocked, 'IVA por configurar' ) && ! str_contains( $blocked, 'fpw_work_approve' ), 'incomplete review explains missing data without approval' );
check( ! str_contains( fpw_workspace_shell( '<h1>Cotizaciones</h1>' ), 'fpw-workspace-dock' ), 'inbox has no redundant self-navigation dock' );
check( str_contains( fpw_workspace_request_state_html( 91, $version ), 'data-fpw-request-state="sent"' ), 'recorded handoff labels a quotation sent automatically' );
check( str_contains( fpw_workspace_request_state_html( 195, null ), 'Solicitud recibida' ), 'unissued receipt is not marked sent' );
foreach ( array( 'unknown', 'rejected', null ) as $state ) {
	$not_sent = $version; $not_sent['delivery']['state'] = $state;
	check( ! str_contains( fpw_workspace_request_state_html( 91, $not_sent ), 'data-fpw-request-state="sent"' ), 'unconfirmed or failed handoff cannot claim sending' );
}
$not_sent = $version; $not_sent['document'] = 'pending';
check( ! str_contains( fpw_workspace_request_state_html( 91, $not_sent ), 'data-fpw-request-state="sent"' ), 'approval without a ready document is not sent' );
check( str_contains( fpw_workspace_request_state_html( 999, $version ), 'Estado no disponible' ), 'misbound sending record cannot label another request sent' );
check( $before_rows === $GLOBALS['fpwd_table'] && $before_mail === count( $GLOBALS['fpwd_mail_calls'] ), 'rendering and downloads never mutate quotation rows or attempt mail' );
echo 'workspace offer: ' . ( $assertions - $start ) . " offline checks passed\n";
