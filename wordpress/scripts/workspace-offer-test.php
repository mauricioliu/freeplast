<?php
/** Reuse the quotation contract's WordPress stubs and real, frozen PDF fixtures. */
require __DIR__ . '/quote-draft-test.php';
$start = $assertions;
$notes_draft = fpw_read_request_draft(91);
$notes_draft['destination']['references'] = 'Portón azul <entrada>';
$notes_projection = fpw_quotation_projection($notes_draft, fpw_read_draft_work(91));
check($notes_projection['references'] === 'Portón azul <entrada>', 'references join frozen projection independently of destination');
check(str_contains(fpw_draft_dispatch_html(true, 'Working address', 1000, false, $notes_projection['references']), 'Referencias para llegar (cliente)</dt><dd>Portón azul &lt;entrada&gt;'), 'working destination shows original references distinctly');
$notes_version = fpw_read_quotation_version(91);
$notes_version['projection'] = $notes_projection;
foreach (array(
	fpw_workspace_offer_html($notes_projection),
	fpw_quotation_email_html($notes_version),
	fpw_quotation_document_html($notes_version, '', 'sans-serif'),
) as $surface) {
	check(str_contains($surface, 'Referencias para llegar: Portón azul &lt;entrada&gt;'), 'offer/email/PDF source escapes and labels references separately');
}
$notes_draft['destination']['dispatch'] = 'no';
check(!isset(fpw_quotation_projection($notes_draft, null)['references']), 'no-dispatch projection excludes references');
$legacy_projection = fpw_quotation_projection(fpw_read_request_draft(91), fpw_read_draft_work(91));
check(!isset($legacy_projection['references']), 'old previews keep original projection shape, no backfill');
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
check( ! str_contains( $detail, 'fpw-price-references' ), 'offer without reference amounts omits the whole reference section' );
$dispatch_draft = $draft; $dispatch_draft['destination']['dispatch'] = 'si';
$dispatch_detail = fpw_workspace_detail_markup( wc_get_order( $id ), $dispatch_draft, $work, null );
$no_dispatch_draft = $draft; $no_dispatch_draft['destination']['dispatch'] = 'no';
foreach ( array( $dispatch_detail, fpw_workspace_detail_markup( wc_get_order( $id ), $no_dispatch_draft, $work, null ) ) as $simplified ) {
	check( ! str_contains( $simplified, '<summary>Mantenimiento de precios</summary>' ) && ! str_contains( $simplified, 'class="fpw-distance"' ) && ! str_contains( $simplified, 'Consultar distancia de despacho' ), 'quotation omits only the two requested secondary accordions, with or without dispatch' );
	check( str_contains( $simplified, 'Datos de contacto y solicitud original' ) && str_contains( $simplified, 'Historial de compras' ) && str_contains( $simplified, 'Opciones de precios' ), 'neighboring contact, history and price options remain' );
	check( str_contains( $simplified, 'page=fpw-price-list' ) && str_contains( $simplified, 'page=fpw-sales-import' ), 'independent maintenance screens stay reachable through navigation' );
}
check( str_contains( $dispatch_detail, 'name="fpw_work[destination]"' ) && str_contains( $dispatch_detail, 'name="fpw_work[dispatch_amount]"' ), 'removing distance disclosure preserves the editable destination and freight amount' );
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
// Compact editing retains amounts and safety, not repeated pending explanations.
$empty_detail = fpw_workspace_detail_markup( wc_get_order( $id ), $draft, null, null );
check( ! str_contains( $empty_detail, 'Por completar:' ) && str_contains( $empty_detail, 'Por definir' ), 'editor omits missing list without turning pending totals into zero' );
check( ! str_contains( $empty_detail, 'Unidades por pallet por confirmar' ) && ! str_contains( $empty_detail, 'Precio por definir.' ), 'unknown packaging and pending-price legends are absent' );
check( str_contains( $empty_detail, 'Neto guardado' ) && str_contains( $empty_detail, 'Revisar cotización' ), 'line subtotal and review action survive' );
foreach ( array( array(), array( 'small' => null, 'bulk' => null, 'source' => 'Fixture', 'note' => 'Pending' ) ) as $reference ) {
	check( '' === fpw_workspace_references_html( $reference, 0, false, null ), 'no amounts means no reference heading or wrapper' );
}
foreach ( array( array( 'small' => 1300, 'bulk' => 1100 ), array( 'small' => 1300 ), array( 'bulk' => 1100 ) ) as $reference ) {
	$html = fpw_workspace_references_html( $reference + array( 'source' => 'Private source', 'note' => 'Private note' ), 2, false, null );
	check( substr_count( $html, 'class="fpw-list-reference"' ) === count( $reference ) && substr_count( $html, 'data-fpw-apply-price="2"' ) === count( $reference ), 'only priced tiers have rows and explicit buttons' );
	check( ! str_contains( $html, 'Por definir' ) && ! str_contains( $html, 'Private' ) && ! str_contains( $html, 'automáticamente' ), 'no pending tiers, source, notes or repeated instructions' );
	check( str_contains( $html, '<noscript>' ), 'manual no-JS reference instructions remain' );
	check( ! str_contains( fpw_workspace_references_html( $reference, 2, true, null ), '<button' ), 'readonly references cannot apply prices' );
}
$legacy_html = fpw_workspace_references_html( array(), 0, false, 1200 );
check( str_contains( $legacy_html, '1.200 CLP' ) && str_contains( $legacy_html, 'Usar precio base' ) && ! str_contains( $legacy_html, '1 a 4 pallets' ), 'legacy-only amount and action survive without empty tiers' );
$line = $draft['items'][0];
$values = fpw_draft_work_values( $draft, null )['lines'][0];
$values['price'] = null; $values['suggested'] = null;
$product_html = fpw_workspace_product_html( $line, $values, 0, null, false );
check( str_contains( $product_html, 'data-fpw-origin="0" hidden' ) && ! str_contains( $product_html, 'data-fpw-pallets' ), 'pending origin reserves only a hidden feedback node, unknown packaging no node' );
$price_rows = $GLOBALS['fpwd_table'];
$GLOBALS['fpwd_table'][FPW_PRICE_ROW] = wp_json_encode( array( 'prices' => array(), 'references' => array( fpw_price_key( $line['product_id'], $line['variation_id'] ) => array( 'units' => 70 ) ) ) );
$values['quantity'] = 141;
$known_packaging = fpw_workspace_product_html( $line, $values, 0, null, false );
check( str_contains( $known_packaging, '2 pallets completos + 1 un. · 70 un. por pallet' ), 'known packaging remains even without reference prices' );
$GLOBALS['fpwd_table'] = $price_rows;
$source = file_get_contents( __DIR__ . '/../wp-content/plugins/freeplast-woo/owner-workspace.php' );
check( str_contains( $source, "wp_date( 'd/m/Y · H:i', (int) \$received )" ), 'inbox formats existing receipt timestamp through WordPress site timezone' );
$timestamp = ( new DateTimeImmutable( '2026-10-13 14:28:00', new DateTimeZone( 'UTC' ) ) )->getTimestamp();
check( ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( new DateTimeZone( 'America/Santiago' ) )->format( 'd/m/Y · H:i' ) === '13/10/2026 · 11:28', 'documented Chile timezone produces requested local date/time' );
echo 'workspace offer: ' . ( $assertions - $start ) . " offline checks passed\n";
