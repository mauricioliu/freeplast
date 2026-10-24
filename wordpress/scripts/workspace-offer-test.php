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
// SP-2: exercise active review/issued consumers, not only the legacy draft renderer.
$rural_draft = fpw_read_request_draft(91);
$rural_draft['destination']['commune'] = 'Mostazal <rural>';
$rural_draft['destination']['location_url'] = 'https://maps.example.test/Predio%20Ana%2Fsur?q=a%26b&z=1';
$rural_projection = fpw_quotation_projection($rural_draft, fpw_read_draft_work(91));
$rural_version = $version;
$rural_version['projection'] = $rural_projection;
$rural_preview = fpw_read_draft_preview(91);
$rural_preview['projection'] = $rural_projection;
foreach (array(
	fpw_workspace_preview_html($rural_draft, fpw_read_draft_work(91), $rural_preview),
	fpw_quotation_email_html($rural_version),
) as $surface) {
	check(str_contains($surface, 'Comuna de despacho: Mostazal &lt;rural&gt;'), 'active review/issued/email escapes structured commune');
	check(str_contains($surface, 'Enlace de ubicación: ' . esc_html($rural_projection['location_url'])), 'active review/issued/email preserves encoded location');
}
foreach (array(fpw_workspace_offer_html($legacy_projection), fpw_quotation_email_html($version)) as $surface) {
	check(!str_contains($surface, 'Enlace de ubicación:') && !str_contains($surface, 'Comuna de despacho:'), 'historical absent fields introduce no invented destination');
}
$rural_version['projection']['dispatch_requested'] = false;
foreach (array(fpw_workspace_offer_html($rural_version['projection']), fpw_quotation_email_html($rural_version)) as $surface) {
	check(!str_contains($surface, 'Enlace de ubicación:') && !str_contains($surface, 'Comuna de despacho:'), 'no-dispatch surfaces exclude destination extras');
}
check($GLOBALS['fpwd_table'] === $before_rows && count($GLOBALS['fpwd_mail_calls']) === $before_mail, 'destination presentation neither rewrites issued bytes nor sends mail');
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
$header_draft = $draft;
$header_draft['identity'] = array( 'company' => 'Empresa <agrícola>', 'name' => 'Ana <Liu>', 'rut' => '14.442.480-8', 'giro' => 'Agrícola', 'phone' => '+56994313727', 'email' => 'ana@example.test' );
$header_draft['destination']['dispatch'] = 'no';
$header_work = array( 'updated_at' => 1760542200 );
$header = fpw_workspace_request_header( $header_draft, $header_work, null );
foreach ( array( 'Empresa &lt;agrícola&gt;', 'Ana &lt;Liu&gt;', '14.442.480-8', 'Agrícola', '+56994313727', 'ana@example.test', 'Sin despacho', 'Borrador guardado: ' . fpw_workspace_date( 'd/m/Y · H:i', 1760542200 ) ) as $fact ) {
	check( str_contains( $header, $fact ), 'receipt header exposes escaped persisted fact: ' . $fact );
}
check( !str_contains( $header, '<details' ) && !str_contains( $header, 'Ver solicitud' ), 'header has neither original disclosure nor redundant link' );
check( str_contains( fpw_workspace_request_header( $header_draft, null, null ), 'Cotización aún sin guardar' ), 'unsaved quotation invents no timestamp' );
$header_version = array( 'approved_at' => 1760550000 );
check( str_contains( fpw_workspace_request_header( $header_draft, $header_work, $header_version ), 'Cotización aprobada: ' . fpw_workspace_date( 'd/m/Y · H:i', 1760550000 ) ), 'approved header uses frozen approval time, not later work' );
$header_draft['destination'] = array( 'dispatch' => 'si', 'address' => 'Camino <52>', 'commune' => 'Mostazal', 'references' => 'Portón <azul>', 'location_url' => 'https://maps.example.test/location', 'source' => 'asistida', 'scope' => 'amplia' );
$header = fpw_workspace_request_header( $header_draft, null, null );
foreach ( array( 'Camino &lt;52&gt;', 'Mostazal', 'Portón &lt;azul&gt;', 'https://maps.example.test/location', 'Revisa el número y la comuna de entrega.' ) as $fact ) {
	check( str_contains( $header, $fact ), 'dispatch header preserves historical destination and warning: ' . $fact );
}
$issued = fpw_workspace_detail_markup( wc_get_order( 91 ), $draft, $work, null );
check( str_contains( $issued, 'Descargar PDF' ) && str_contains( $issued, '<iframe' ) && str_contains( $issued, 'view=1' ), 'issued review embeds its private saved PDF and offers a download' );
foreach ( array( 'Volver a ajustar', 'Generar vista previa', 'nada fue aprobado', 'fpw_work_approve', 'data-fpw-work', 'class="fpw-workspace-dock"' ) as $absent ) {
	check( ! str_contains( $issued, $absent ), 'issued review excludes misleading draft action/copy: ' . $absent );
}
check( str_contains( $issued, 'Volver a solicitudes' ) && ! str_contains( $issued, 'fpw-request-header' ) && ! str_contains( $issued, 'fpw-offer-lines' ) && ! str_contains( $issued, 'Historial de compras' ), 'issued screen contains no duplicate header, lines or purchase history' );
$sent_version = $version;
$sent_version['delivery']['state'] = 'accepted';
$sent_version['buyer']['email'] = 'cliente+<prueba>@example.test';
$sent_html = fpw_workspace_issued_html( $sent_version );
check( 1 === substr_count( $sent_html, 'Volver a solicitudes' ) && str_contains( $sent_html, 'class="fpw-button fpw-sent-back"' ) && strpos( $sent_html, 'Volver a solicitudes' ) < strpos( $sent_html, '<h1>' ) && strpos( $sent_html, 'Volver a solicitudes' ) < strpos( $sent_html, '<iframe' ), 'return action keeps its text and is a single button above confirmation and PDF' );
check( str_contains( $sent_html, 'Cotización enviada a cliente+&lt;prueba&gt;@example.test' ), 'confirmation uses escaped frozen buyer email' );
foreach ( array( 'rejected' => 'No se pudo enviar', 'unknown' => 'sin confirmar', '' => 'envío pendiente' ) as $state => $expected ) {
	$sent_version['delivery']['state'] = $state;
	$state_html = fpw_workspace_issued_html( $sent_version );
	check( str_contains( $state_html, $expected ) && ! str_contains( $state_html, 'Cotización enviada a' ) && str_contains( $state_html, '<iframe' ), 'unsuccessful delivery preserves PDF without false success: ' . $state );
}
$sent_version['document'] = 'pending';
check( ! str_contains( fpw_workspace_issued_html( $sent_version ), '<iframe' ) && str_contains( fpw_workspace_issued_html( $sent_version ), 'PDF no disponible' ), 'missing stored PDF never renders a broken iframe or regenerates' );
check( str_contains( fpw_workspace_pdf_url( $version, true ), 'view=1' ) && !str_contains( fpw_workspace_pdf_url( $version ), 'view=' ), 'inline and attachment URLs share protected endpoint but retain separate presentation' );
check( $GLOBALS['fpwd_table'] === $before_rows && count( $GLOBALS['fpwd_mail_calls'] ) === $before_mail, 'issued presentation neither saves nor sends or regenerates' );
$changed_work = $work; $changed_work['lines'][0]['price'] = 999999;
check( $issued === fpw_workspace_detail_markup( wc_get_order( 91 ), $draft, $changed_work, null ), 'issued review ignores later draft price changes completely' );
unset( $_GET['review'] );
$detail = fpw_workspace_detail_markup( wc_get_order( 91 ), $draft, $work, null );
check( ! str_contains( $detail, 'data-fpw-work' ) && ! str_contains( $detail, 'data-fpw-tracking' ), 'approved detail is static offer without a manual tracking form' );
check( ! str_contains( $detail, 'Seguimiento comercial' ) && str_contains( $detail, '<iframe' ), 'approved detail goes directly to the saved PDF' );

// A separate saved, unapproved request; no production/runtime data is used.
$id = 195; $draft = fpw_read_request_draft( $id ); $work = fpw_read_draft_work( $id ); $preview = fpw_read_draft_preview( $id );
$detail = fpw_workspace_detail_markup( wc_get_order( $id ), $draft, $work, null );
check( str_contains( $detail, 'id="fpw-products"' ) && ! str_contains( $detail, 'data-fpw-tracking' ) && ! str_contains( $detail, 'Seguimiento comercial' ), 'draft keeps products and removes manual tracking entirely' );
check( ! str_contains( $detail, 'fpw-price-references' ), 'offer without reference amounts omits the whole reference section' );
$dispatch_draft = $draft; $dispatch_draft['destination']['dispatch'] = 'si';
$dispatch_detail = fpw_workspace_detail_markup( wc_get_order( $id ), $dispatch_draft, $work, null );
$broad_draft = $dispatch_draft;
$broad_draft['destination']['source'] = 'asistida'; $broad_draft['destination']['scope'] = 'amplia';
check( str_contains( fpw_workspace_detail_markup( wc_get_order( $id ), $broad_draft, $work, null ), 'Revisa el número y la comuna de entrega.' ), 'workspace retains broad-destination commercial warning' );
check( str_contains( $dispatch_detail, 'Mínimo por solicitud:' ) || str_contains( $dispatch_detail, 'Mínimo por confirmar:' ), 'workspace renders minimum review without JavaScript' );
$no_dispatch_draft = $draft; $no_dispatch_draft['destination']['dispatch'] = 'no';
foreach ( array( $dispatch_detail, fpw_workspace_detail_markup( wc_get_order( $id ), $no_dispatch_draft, $work, null ) ) as $simplified ) {
	check( ! str_contains( $simplified, '<summary>Mantenimiento de precios</summary>' ) && ! str_contains( $simplified, 'class="fpw-distance"' ) && ! str_contains( $simplified, 'Consultar distancia de despacho' ), 'quotation omits only the two requested secondary accordions, with or without dispatch' );
	check( str_contains( $simplified, 'class="fpw-request-header"' ) && str_contains( $simplified, 'Historial de compras' ) && ! str_contains( $simplified, 'Opciones de precios' ) && ! str_contains( $simplified, 'fpw-original' ) && ! str_contains( $simplified, 'Ver solicitud' ), 'visible contact header and history remain without original disclosure or redundant links' );
	check( str_contains( $simplified, 'page=fpw-price-list' ) && str_contains( $simplified, 'page=fpw-sales-import' ), 'independent maintenance screens stay reachable through navigation' );
}
check( str_contains( $dispatch_detail, 'name="fpw_work[destination]"' ) && str_contains( $dispatch_detail, 'name="fpw_work[dispatch_amount]"' ), 'removing distance disclosure preserves the editable destination and freight amount' );
check( str_contains( $detail, 'data-fpw-origin="0"' ) && str_contains( $detail, 'Precio guardado para esta oferta.' ), 'H6: each price line ships its origin legend bound for unsaved-value feedback' );
check( str_contains( $detail, 'data-fpw-dock-save' ) && str_contains( $detail, 'form="fpw-work-form"' ), 'mobile save submits the existing guarded native form' );
check( ! str_contains( $detail, 'Importes guardados · revisión' ) && ! str_contains( $detail, 'Refrescar precios' ), 'editor hides internal revision and refresh' );
check( str_contains( $detail, 'id="fpw-history"' ) && str_contains( $detail, 'id="fpw-quotation-history"' ), 'active editor presents purchase and quotation histories in separate disclosures' );
check( ! str_contains( $issued, 'fpw-quotation-history' ), 'issued offer remains free of internal customer quotation history' );
foreach ( array( 'fpw_work_revision', 'fpw_draft_nonce', 'fpw_preview_nonce', 'fpw_refresh_nonce', 'data-fpw-calculation', 'data-fpw-minimum', 'data-fpw-amount="total"' ) as $required ) {
	check( str_contains( $detail, $required ), 'editor preserves controls and calculation hooks: ' . $required );
}
check( !str_contains( $detail, 'class="fpw-back"' ), 'editable quotation removes redundant Solicitudes back link' );
$_GET['review'] = '1';
$review_page = fpw_workspace_detail_markup( wc_get_order( $id ), $draft, $work, array( 'class' => 'ok', 'title' => 'Vista previa generada.', 'lines' => array( 'Redundant preview explanation' ) ) );
check( strpos( $review_page, 'Volver a ajustar' ) < strpos( $review_page, 'class="fpw-preview-title"' ) && strpos( $review_page, 'class="fpw-preview-title"' ) < strpos( $review_page, 'class="fpw-request-header"' ), 'review orders adjust link, simple title, then client header' );
check( str_contains( $review_page, '>Vista previa</h1>' ) && !str_contains( $review_page, 'Vista previa generada.' ) && !str_contains( $review_page, 'Redundant preview explanation' ) && !str_contains( $review_page, 'class="fpw-back"' ), 'review replaces success notice and Solicitudes link with plain heading' );
$warning_page = fpw_workspace_detail_markup( wc_get_order( $id ), $draft, $work, array( 'class' => 'warn', 'title' => 'Advertencia importante', 'lines' => array( 'No ocultar recuperación' ) ) );
check( str_contains( $warning_page, 'Advertencia importante' ) && str_contains( $warning_page, 'No ocultar recuperación' ), 'review retains non-success warning and recovery notices' );
unset( $_GET['review'] );
$review = fpw_workspace_preview_html( $draft, $work, $preview );
check( !str_contains( $review, 'Qué incluye la cotización' ) && !str_contains( $review, 'Al aprobar, se guarda esta versión' ) && !str_contains( $review, 'Aún no se ha enviado.' ), 'review removes approved explanatory paragraphs and disclosure' );
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
check( str_contains( $source, "fpw_workspace_date( 'd/m/Y · H:i', (int) \$received )" ), 'inbox uses explicit Chilean timezone' );
check( fpw_workspace_date( 'd/m/Y · H:i', strtotime( '2026-10-22 00:02:00 UTC' ) ) === '21/10/2026 · 21:02', 'Chilean summer time crosses date boundary correctly' );
check( fpw_workspace_date( 'd/m/Y · H:i', strtotime( '2026-06-22 00:02:00 UTC' ) ) === '21/06/2026 · 20:02', 'Chilean winter time follows DST rules, not a fixed offset' );
check( !str_contains( fpw_workspace_request_header( $header_draft, $header_work, null ), 'GMT' ), 'header omits timezone suffix' );
// T7 (plan-mvlk7z1x-y6s8): the inbox row keeps reference and received time as distinct, machine-readable data.
class FPWD_Inbox_wpdb extends FPWD_Fake_wpdb {
	public string $last_error = '';
	public string $posts = 'wp_posts';
	public string $postmeta = 'wp_postmeta';
	public function get_var( $sql ) { return str_starts_with( $sql, 'SELECT COUNT(*)' ) ? 1 : parent::get_var( $sql ); }
	public function get_col( $sql ) { return str_contains( $sql, 'ORDER BY' ) ? array( 91 ) : parent::get_col( $sql ); }
}
$wpdb_save = $GLOBALS['wpdb'];
$GLOBALS['wpdb'] = new FPWD_Inbox_wpdb();
$_GET = array();
ob_start();
fpw_render_workspace();
$inbox_html = (string) ob_get_clean();
$GLOBALS['wpdb'] = $wpdb_save;
$received_at = (int) ( fpw_read_request_draft( 91 )['received_at'] ?? 0 );
$meta_start = strpos( $inbox_html, 'fpw-request-meta' );
$meta_line = substr( $inbox_html, (int) $meta_start, 800 );
check( false !== $meta_start && str_contains( $meta_line, '<span class="fpw-request-ref">FP-2026-000091</span>' ), 'inbox row names the reference in its own element' );
check( str_contains( $meta_line, '<span class="screen-reader-text">Recibida: </span>' ) && str_contains( $meta_line, '<span aria-hidden="true">· </span>' ), 'received time carries a spoken label, separator is decorative only' );
check( str_contains( $meta_line, '<time datetime="' . esc_attr( fpw_workspace_date( 'c', $received_at ) ) . '">' . esc_html( fpw_workspace_date( 'd/m/Y · H:i', $received_at ) ) . '</time>' ), 'received time is machine-readable in site time' );
check( str_contains( $inbox_html, 'data-fpw-request="91"' ) && str_contains( $inbox_html, 'Ver<span class="screen-reader-text"> FP-2026-000091</span>' ) && str_contains( $inbox_html, 'class="fpw-workspace-row-total"' ), 'readability markup keeps rows, totals and actions intact' );
$css = file_get_contents( __DIR__ . '/../wp-content/plugins/freeplast-woo/assets/owner-workspace.css' );
check( str_contains( $css, '.fpw-request-meta .fpw-request-ref{color:var(--ink);font-weight:650}' ) && str_contains( $css, '.fpw-request-received{white-space:nowrap}' ), 'reference gains ink weight and the received time never splits across lines' );
check( str_contains( $source, "'1.12.17'" ), 'inbox stylesheet version bumped for the new rules' );
check( !str_contains( $detail, 'Al revisar se guardan' ) && !str_contains( $detail, 'Importes guardados.' ) && !str_contains( $detail, 'Cambios sin guardar.' ), 'summary omits removed explanatory copy' );
check( !str_contains( fpw_draft_approve_html( 91, $preview ?? array(), true ), 'La oferta aprobada' ), 'workspace approval omits removed disclaimer' );
$timestamp = ( new DateTimeImmutable( '2026-10-13 14:28:00', new DateTimeZone( 'UTC' ) ) )->getTimestamp();
check( ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( new DateTimeZone( 'America/Santiago' ) )->format( 'd/m/Y · H:i' ) === '13/10/2026 · 11:28', 'documented Chile timezone produces requested local date/time' );
// Clean HTML remains server-authoritative; the browser receives confirmed policy even when totals are incomplete.
$tax_filters = $GLOBALS['registered_filters']['fpw_quotation_tax_config'];
foreach ( array( array(), array( 'rate_permille' => 0, 'applies_to_dispatch' => false ), array( 'rate_permille' => 5000, 'applies_to_dispatch' => true ) ) as $policy ) {
	$GLOBALS['registered_filters']['fpw_quotation_tax_config'] = array( fn() => $policy );
	$html = fpw_workspace_detail_markup( wc_get_order( $id ), $draft, null, null );
	preg_match( '/data-fpw-calculation="([^"]+)"/', $html, $match );
	$config = json_decode( html_entity_decode( $match[1], ENT_QUOTES ), true );
	check( $config['tax'] === $policy, 'confirmed tax policy independent of incomplete projection rate' );
	check( str_contains( $html, 'Por definir' ) && ! str_contains( $html, 'name="fpw_work_approve"' ), 'initial incomplete totals stay server-rendered, no editor approval' );
}
$GLOBALS['registered_filters']['fpw_quotation_tax_config'] = $tax_filters;
$posted_values = fpw_draft_work_values( $draft, $work );
$_POST = array( 'fpw_work' => $posted_values, 'fpw_work_revision' => fpw_draft_work_revision( $work ), 'fpw_preview_nonce' => 'offline-nonce', 'fpw_draft_nonce' => 'offline-nonce', 'fpw_refresh_nonce' => 'offline-nonce' );
check( fpw_workspace_guard_clean_action( $id, $draft, 'preview' ), 'no-JS unchanged form can review saved work' );
$_POST['fpw_work']['lines'][0]['price']++;
check( ! fpw_workspace_guard_clean_action( $id, $draft, 'refresh' ), 'price refresh still refuses to discard unsaved edits' );
$_GET['review'] = '1';
$rejected = fpw_workspace_detail_markup( wc_get_order( $id ), $draft, $work, null );
check( str_contains( $rejected, 'data-fpw-unsaved="1"' ) && str_contains( $rejected, 'data-fpw-discard-work' ) && ! str_contains( $rejected, 'name="fpw_work_approve"' ), 'rejected POST remains editor with explicit discard link and no approval' );
unset( $_GET['review'] );
fpw_pending_draft_outcome( null );
$_GET['workspace'] = '1';
$_POST['fpw_work_preview'] = '1';
$before_work = fpw_read_draft_work( $id );
$before_mail = count( $GLOBALS['fpwd_mail_calls'] );
fpw_handle_draft_action_request( wc_get_order( $id ), $draft, 'preview' );
$review_work = fpw_read_draft_work( $id );
check( fpw_pending_draft_outcome()['result']['state'] === 'previewed', 'one review POST saves edits and generates preview without an earlier save' );
check( $review_work['revision'] === $before_work['revision'] + 1 && $review_work['lines'][0]['price'] === (int) $_POST['fpw_work']['lines'][0]['price'], 'review persists the actual posted price through a new working revision' );
check( fpw_read_draft_preview( $id )['revision'] === $review_work['revision'] && fpw_read_draft_preview( $id )['projection'] === fpw_quotation_projection( $draft, $review_work ), 'review binds the saved revision and server calculation' );
check( count( $GLOBALS['fpwd_mail_calls'] ) === $before_mail && null === fpw_read_quotation_version( $id ), 'review neither sends mail nor issues a quotation' );
$_POST['fpw_work_revision'] = $review_work['revision'];
fpw_handle_draft_action_request( wc_get_order( $id ), $draft, 'preview' );
check( fpw_read_draft_work( $id )['revision'] === $review_work['revision'], 'unchanged review does not create an unnecessary save' );
$clean_post = $_POST;
$_POST['fpw_work']['lines'][0]['price']++;
$_POST['fpw_draft_nonce'] = 'forged';
try { fpw_workspace_guard_clean_action( $id, $draft, 'preview' ); check( false, 'review save requires its save nonce' ); }
catch ( FPWD_Die $e ) { check( '403' === $e->getMessage() && fpw_read_draft_work( $id ) === $review_work, 'forged save nonce cannot persist review edits' ); }
$_POST = $clean_post;
$_POST['fpw_work']['lines'][0]['price'] = '0';
$prior_preview = fpw_read_draft_preview( $id );
fpw_handle_draft_action_request( wc_get_order( $id ), $draft, 'preview' );
check( fpw_pending_draft_outcome()['result']['state'] === 'workspace-invalid' && fpw_read_draft_work( $id ) === $review_work && fpw_read_draft_preview( $id ) === $prior_preview, 'invalid review changes neither saved work nor prior preview' );
$_POST = $clean_post;
$_POST['fpw_work_revision']--;
check( ! fpw_workspace_guard_clean_action( $id, $draft, 'preview' ) && fpw_pending_draft_outcome()['result']['state'] === 'conflict', 'stale revision still fails closed' );
$_POST['fpw_preview_nonce'] = 'forged';
try { fpw_workspace_guard_clean_action( $id, $draft, 'preview' ); check( false, 'forged preview nonce must fail' ); }
catch ( FPWD_Die $e ) { check( '403' === $e->getMessage(), 'forged preview nonce forbidden' ); }
$saved_rows = $GLOBALS['fpwd_table'];
unset( $GLOBALS['fpwd_table'][fpw_draft_work_row_name( $id )] );
$_POST = array( 'fpw_work' => fpw_draft_work_values( $draft, null ), 'fpw_work_revision' => 0, 'fpw_preview_nonce' => 'offline-nonce', 'fpw_draft_nonce' => 'offline-nonce' );
fpw_pending_draft_outcome( null );
fpw_handle_draft_action_request( wc_get_order( $id ), $draft, 'preview' );
check( fpw_pending_draft_outcome()['result']['state'] === 'workspace-invalid' && fpw_read_draft_work( $id )['revision'] === 1 && fpw_read_draft_preview( $id ) === $prior_preview, 'first incomplete review saves draft values but cannot replace the preview' );
$_GET['review'] = '1'; $_POST = array(); fpw_pending_draft_outcome( array() );
$incomplete = fpw_workspace_detail_markup( wc_get_order( $id ), $draft, fpw_read_draft_work( $id ), null );
check( str_contains( $incomplete, 'data-fpw-work' ) && str_contains( $incomplete, 'aria-invalid="true"' ) && str_contains( $incomplete, 'autofocus' ) && ! str_contains( $incomplete, 'class="fpw-workspace-preview"' ), 'direct incomplete review stays in highlighted editor without JS' );
check( str_contains( $incomplete, 'data-fpw-review-error' ) && str_contains( $incomplete, 'Falta el precio neto' ), 'server summary details missing product prices' );
$_POST = array( 'fpw_work' => array( 'lines' => array( array( 'quantity' => '0', 'price' => '0' ) ), 'pickup_location' => '', 'validity_days' => '366' ) );
fpw_pending_draft_outcome( array( 'order_id' => $id, 'result' => array( 'state' => 'workspace-invalid' ) ) );
$invalid_html = fpw_workspace_detail_markup( wc_get_order( $id ), $draft, fpw_read_draft_work( $id ), null );
check( preg_match( '/<form\\b[^>]*id="fpw-work-form"[^>]*\\bnovalidate\\b/', $invalid_html ) === 1, 'all invalid fields reach custom validation instead of one native popup' );
check( substr_count( $invalid_html, 'aria-invalid="true"' ) >= 4 && str_contains( $invalid_html, 'value="366"' ) && str_contains( $invalid_html, '[price]" value="0"' ), 'all invalid fields marked and entered values preserved' );
$GLOBALS['fpwd_table'] = $saved_rows;
fpw_pending_draft_outcome( array() ); $_POST = array(); unset( $_GET['review'], $_GET['workspace'] );
/* Ticket 9 — Volver keeps the origin inbox context on every draft/editor surface. */
$_GET = array( 'stage' => 'sent-quotes', 'q' => 'Freeplast SpA', 'sort' => 'oldest', 'paged' => '3' );
$context_detail = html_entity_decode( fpw_workspace_detail_markup( wc_get_order( $id ), fpw_read_request_draft( $id ), fpw_read_draft_work( $id ), null ), ENT_QUOTES );
check( str_contains( $context_detail, 'request=' . $id . '&workspace=1&stage=sent-quotes&q=Freeplast+SpA&sort=oldest&paged=3"' ), 'editor form action posts back to the contextful draft URL' );
$_GET['review'] = '1';
$context_review = html_entity_decode( fpw_workspace_detail_markup( wc_get_order( $id ), fpw_read_request_draft( $id ), fpw_read_draft_work( $id ), null ), ENT_QUOTES );
check( str_contains( $context_review, 'href="https://freeplast.test/wp-admin/admin.php?page=fpw-quote-draft&request=' . $id . '&workspace=1&stage=sent-quotes&q=Freeplast+SpA&sort=oldest&paged=3"' ), 'Volver a ajustar returns to the draft with the inbox context' );
unset( $_GET['review'] );
$context_issued = html_entity_decode( fpw_workspace_detail_markup( wc_get_order( 91 ), fpw_read_request_draft( 91 ), fpw_read_draft_work( 91 ), null ), ENT_QUOTES );
check( str_contains( $context_issued, 'fpw-sent-back' ) && str_contains( $context_issued, 'page=fpw-quotations&stage=sent-quotes&q=Freeplast+SpA&sort=oldest&paged=3' ), 'issued view back link restores the origin tab, search, order and page' );
$_GET = array();
$plain_detail = html_entity_decode( fpw_workspace_detail_markup( wc_get_order( $id ), fpw_read_request_draft( $id ), fpw_read_draft_work( $id ), null ), ENT_QUOTES );
check( ! str_contains( $plain_detail, 'stage=' ) && ! str_contains( $plain_detail, 'paged=' ) && str_contains( $plain_detail, 'request=' . $id . '&workspace=1&' ), 'direct editor access keeps clean internal URLs' );
// Contact editor remains separate from commercial inputs and frozen review.
fpwd_review_for_approval(2211);
$id = 2211; $draft = fpw_read_request_draft($id); $work = fpw_read_draft_work($id);
$_GET = array('page' => FPW_DRAFT_SCREEN, 'workspace' => '1', 'request' => (string)$id); $_POST = array();
$before_contact = $GLOBALS['fpwd_table'];
$detail = fpw_workspace_detail_markup(wc_get_order($id), $draft, $work, null);
check(str_contains($detail, 'Editar empresa y contacto') && str_contains($detail, '<svg') && str_contains($detail, 'data-fpw-identity'), 'pencil contact editor is discoverable and server-first');
foreach (array('company','name','email','phone','giro') as $field) { check(str_contains($detail, 'name="fpw_identity[' . $field . ']"'), 'only supported editable contact field: ' . $field); }
check(!str_contains($detail, 'name="fpw_identity[rut]"') && !str_contains($detail, 'name="fpw_identity[address]"'), 'RUT and delivery have no contact edit controls');
check($GLOBALS['fpwd_table'] === $before_contact && str_contains($detail, 'data-fpw-identity-cancel href="' . esc_url(fpw_draft_screen_url($id)) . '"'), 'GET/cancel renders saved state without writes');
$_POST = array('fpw_identity_save' => '1', 'fpw_work_revision' => (string)$work['revision'], 'fpw_draft_nonce' => 'offline-nonce', 'fpw_identity' => array('company'=>'Empresa corregida', 'name'=>'Ana', 'email'=>'invalid', 'phone'=>'+56987654321', 'giro'=>'Agrícola'));
fpw_handle_draft_posted_action();
$invalid = fpw_workspace_detail_markup(wc_get_order($id), $draft, $work, fpw_draft_outcome_notice(fpw_pending_draft_outcome()['result']));
check(str_contains($invalid, 'class="fpw-identity-editor" open') && str_contains($invalid, 'aria-describedby="fpw-identity-error-email"') && str_contains($invalid, 'value="invalid"'), 'invalid contact stays open with retained input and accessible inline error');
check($GLOBALS['fpwd_table'] === $before_contact, 'invalid contact render and save preserve all rows');
$_POST['fpw_identity']['email'] = 'nuevo@example.test';
fpw_handle_draft_posted_action(); $work = fpw_read_draft_work($id);
$_POST = array(); fpw_pending_draft_outcome(null);
$detail = fpw_workspace_detail_markup(wc_get_order($id), $draft, $work, null);
check(str_contains($detail, '>Empresa corregida</h1>') && str_contains($detail, 'nuevo@example.test'), 'reloaded working header shows corrected company and contact');
$_GET['review'] = '1';
$stale = fpw_workspace_detail_markup(wc_get_order($id), $draft, $work, null);
check(str_contains($stale, 'Vista previa obsoleta') && !str_contains($stale, 'Empresa corregida') && !str_contains($stale, 'data-fpw-identity'), 'obsolete review shows frozen buyer, not later working identity or editing controls');
$_POST = array('fpw_work_preview'=>'1','fpw_preview_nonce'=>'offline-nonce');
fpw_handle_draft_posted_action(); $_POST = array();
$review = fpw_workspace_detail_markup(wc_get_order($id), $draft, $work, null);
check(str_contains($review, 'Empresa corregida') && str_contains($review, 'Se enviará a:</strong> nuevo@example.test'), 'fresh review shows corrected frozen company and exact recipient');
$unsafe = $work; $unsafe['identity']['company'] = '<script>alert(1)</script>';
check(str_contains(fpw_workspace_request_header($draft, $unsafe, null), '&lt;script&gt;') && !str_contains(fpw_draft_identity_form($draft, $unsafe), 'value="<script>'), 'corrected contact output is HTML escaped');
echo 'workspace offer: ' . ( $assertions - $start ) . " offline checks passed\n";
