<?php
/** Full offline journey: immutable original -> isolated edit -> review -> versioned PDF/mail. */
require __DIR__ . '/workspace-offer-test.php';
$start_revisions = $assertions;
$_GET = $_POST = array();
$GLOBALS['fpwd_nonce_ok'] = true;
$GLOBALS['fpwd_mail_mode'] = 'ok';
$GLOBALS['fpwd_caps'] = array( 'manage_woocommerce' => true );

function revision_route( int $id, int $base ): void {
	$_GET = array( 'page' => FPW_DRAFT_SCREEN, 'request' => (string) $id, 'workspace' => '1', 'revise' => (string) $base );
	$_POST = array();
	fpw_pending_draft_outcome( array() );
}
function revision_review( int $id, int $price ): array {
	$draft = fpw_read_request_draft( $id );
	$work = fpw_read_draft_work( $id );
	$values = fpw_draft_work_values( $draft, $work );
	$values['lines'][0]['price'] = $price;
	$_POST = array( 'fpw_work_preview' => '1', 'fpw_work_revision' => fpw_draft_work_revision( $work ), 'fpw_work' => $values,
		'fpw_draft_nonce' => 'offline-nonce', 'fpw_preview_nonce' => 'offline-nonce' );
	fpw_handle_draft_action_request( wc_get_order( $id ), $draft, 'preview' );
	check( 'previewed' === fpw_pending_draft_outcome()['result']['state'], 'adjusted offer is saved and reviewed through the real handler' );
	$preview = fpw_read_draft_preview( $id );
	$_POST = array( 'fpw_work_approve' => '1', 'fpw_approve_nonce' => 'offline-nonce', 'fpw_approve_preview' => fpw_quotation_preview_token( $preview ) );
	return $preview;
}

$id = 301;
fpwd_review_for_approval( $id, '1000' );
$draft = fpw_read_request_draft( $id );
$first = fpw_quotation_approve_and_send( $id, $draft, fpw_read_draft_work( $id ), fpw_read_draft_preview( $id ), 1 );
check( 'sent' === $first['state'], 'fixture starts with an actual issued/sent quotation' );
$original = fpw_read_quotation_version( $id );
$original_pdf = fpw_workspace_pdf_bytes( $original );
$original_raw = fpw_read_quotation_version_raw( $id );
$initial_work_raw = fpw_read_draft_work_raw( $id );
$initial_preview_raw = $GLOBALS['fpwd_table'][fpw_draft_preview_row_name( $id )];
$receipt_raw = $GLOBALS['fpwd_table'][fpw_draft_row_name( $id )];
$first_mail_count = count( $GLOBALS['fpwd_mail_calls'] );
$sequence_count = count( $GLOBALS['fpwd_sequence_allocations'] );
$original_nonce_scope = fpw_quotation_approve_action( $id );

// A stale original draft is NOT the source of the adjusted offer.
$corrupt_work = fpw_read_draft_work( $id );
$corrupt_work['lines'][0]['price'] = 9999;
$GLOBALS['fpwd_table'][fpw_draft_work_row_name( $id )] = wp_json_encode( $corrupt_work );
$unrelated_work_raw = fpw_read_draft_work_raw( $id );
revision_route( $id, 1 );
$before_get = $GLOBALS['fpwd_table'];
$seed = fpw_read_draft_work( $id );
$editor = fpw_workspace_detail_markup( wc_get_order( $id ), $draft, $seed, null );
check( $seed['revision'] === 0 && $seed['lines'][0]['price'] === 1000 && $seed['validity_days'] === 7, 'editor seeds sent values, never changed original work or list prices' );
check( str_contains( $editor, 'Editar cotización · Versión 2' ) && str_contains( $editor, '[price]" value="1000"' ) && str_contains( $editor, 'revise=1' ) && str_contains( $editor, 'data-fpw-work' ), 'Edit opens the usable prefilled editor, retaining the revision route' );
check( $before_get === $GLOBALS['fpwd_table'] && $first_mail_count === count( $GLOBALS['fpwd_mail_calls'] ), 'GET editor has zero persistence, issuance or mail side effects' );
check( str_ends_with( fpw_draft_work_row_name( $id ), '_after_1' ) && str_ends_with( fpw_draft_preview_row_name( $id ), '_after_1' ), 'working copy and preview are isolated per base version' );
check( $original_nonce_scope !== fpw_quotation_approve_action( $id ) && str_ends_with( fpw_draft_save_action( $id ), '_after_1' ), 'revision nonces are isolated from original forms' );
check( null === fpw_read_draft_preview( $id ), 'original preview never authorizes the adjusted version' );

// Tax configuration/list changes cannot silently alter an untouched sent offer.
$tax_filters = $GLOBALS['registered_filters']['fpw_quotation_tax_config'] ?? array();
$tax_tag = 'fpw_quotation_tax_config';
$GLOBALS['registered_filters'][$tax_tag] = array( static fn() => array( 'rate_permille' => 200, 'applies_to_dispatch' => false ) );
check( fpw_quotation_projection( $draft, $seed ) === $original['projection'], 'editing preserves the full sent projection even after fiscal configuration changes' );
check( fpw_quotation_offer_tax_config( $id )['rate_permille'] === $original['projection']['tax_rate_permille'], 'client-side calculator and server share the frozen revision tax policy' );
// First unchanged preview materializes the virtual seed but must not resend the original.
revision_review( $id, 1000 );
fpw_handle_draft_action_request( wc_get_order( $id ), $draft, 'approve' );
check( 'sin-cambios' === fpw_pending_draft_outcome()['result']['reason'] && fpw_read_quotation_version_raw( $id ) === $original_raw && $first_mail_count === count( $GLOBALS['fpwd_mail_calls'] ), 'unchanged offer cannot issue/resend another version' );

$GLOBALS['registered_filters'][$tax_tag] = $tax_filters;
// Invalid edits and stale working revisions fail before touching the sent snapshot.
$valid_work = fpw_read_draft_work( $id );
$bad_values = fpw_draft_work_values( $draft, $valid_work );
$bad_values['lines'][0]['price'] = '0';
$_POST = array( 'fpw_work' => $bad_values, 'fpw_work_revision' => $valid_work['revision'], 'fpw_draft_nonce' => 'offline-nonce', 'fpw_preview_nonce' => 'offline-nonce' );
$before_invalid = $GLOBALS['fpwd_table'];
fpw_handle_draft_action_request( wc_get_order( $id ), $draft, 'preview' );
check( $before_invalid === $GLOBALS['fpwd_table'] && 'workspace-invalid' === fpw_pending_draft_outcome()['result']['state'], 'invalid revised prices cannot change work, preview or original issuance' );
$_POST['fpw_work_revision']--;
fpw_handle_draft_action_request( wc_get_order( $id ), $draft, 'preview' );
check( $before_invalid === $GLOBALS['fpwd_table'] && 'conflict' === fpw_pending_draft_outcome()['result']['state'], 'stale revised working form cannot overwrite a newer save' );
$review = revision_review( $id, 1250 );
check( $review['projection']['lines'][0]['price'] === 1250 && $review['revision'] === fpw_read_draft_work( $id )['revision'], 'preview freezes the exact adjusted values and work revision' );
$review_html = fpw_workspace_preview_html( $draft, fpw_read_draft_work( $id ), $review );
check( str_contains( $review_html, 'Aprobar y enviar' ) && str_contains( $review_html, 'compras@prueba.invalid' ), 'revision review shows explicit approval and original recipient' );
$before_approval = $GLOBALS['fpwd_table'];
$_POST['fpw_approve_preview'] = 'old-preview-token';
fpw_handle_draft_action_request( wc_get_order( $id ), $draft, 'approve' );
check( 'vista-previa-no-revisada' === fpw_pending_draft_outcome()['result']['reason'] && $before_approval === $GLOBALS['fpwd_table'], 'unviewed or old approval token writes and sends nothing' );
$_POST['fpw_approve_preview'] = fpw_quotation_preview_token( $review );
$_POST['fpw_approve_nonce'] = 'forged';
try { fpw_handle_draft_action_request( wc_get_order( $id ), $draft, 'approve' ); check( false, 'forged approval must fail' ); }
catch ( FPWD_Die $e ) { check( '403' === $e->getMessage() && $before_approval === $GLOBALS['fpwd_table'], 'forged approval is forbidden before any publication' ); }
$_POST['fpw_approve_nonce'] = 'offline-nonce';

// Simulate a durable publication failure: no PDF/mail or lost history.
$GLOBALS['fpwd_query_fault'] = static function( $sql ) use ( $id ) {
	return str_contains( $sql, "option_name = 'fpw_quotation_$id' AND option_value" ) ? 0 : null;
};
fpw_handle_draft_action_request( wc_get_order( $id ), $draft, 'approve' );
unset( $GLOBALS['fpwd_query_fault'] );
check( $before_approval === $GLOBALS['fpwd_table'] && $first_mail_count === count( $GLOBALS['fpwd_mail_calls'] ) && 'approval-storage-failed' === fpw_pending_draft_outcome()['result']['state'], 'lost CAS cannot publish, render a replacement or send; failure is reported honestly' );

fpw_handle_draft_action_request( wc_get_order( $id ), $draft, 'approve' );
$second = fpw_read_quotation_version( $id );
check( 'approval-sent' === fpw_pending_draft_outcome()['result']['state'] && $second['version'] === 2, 'explicit reviewed approval issues and sends version 2' );
check( $second['projection'] === $review['projection'] && $second['buyer'] === $original['buyer'], 'issued version equals reviewed projection and retains recipient' );
check( $second['commercial'] === $original['commercial'] && $sequence_count === count( $GLOBALS['fpwd_sequence_allocations'] ), 'same commercial quotation number; revision does not consume another number' );
check( $second['history'] === array( $original ) && fpw_read_quotation_version( $id, 1 ) === $original, 'original complete snapshot is unchanged, not recursively nested or reconstructed' );
check( fpw_workspace_pdf_response( $id, 1, 'offline-nonce' )['bytes'] === $original_pdf, 'original protected PDF URL still returns the exact original bytes' );
$new_pdf = fpw_workspace_pdf_response( $id, 2, 'offline-nonce' )['bytes'];
check( $new_pdf !== $original_pdf && end( $GLOBALS['fpwd_mail_calls'] )['attachment_bytes'][0] === $new_pdf, 'version 2 mails its new frozen PDF, never the original bytes' );
check( $first_mail_count + 1 === count( $GLOBALS['fpwd_mail_calls'] ), 'one explicit revised approval creates exactly one new mail' );
$sent = fpw_workspace_detail_markup( wc_get_order( $id ), $draft, fpw_read_draft_work( $id ), null );
check( str_contains( $sent, 'Versiones de la cotización' ) && str_contains( $sent, 'version=1' ) && str_contains( $sent, 'version=2' ) && !str_contains( $sent, 'data-fpw-work' ), 'after approval shows sent PDF plus navigable history, not an obsolete editor' );
$before_replay = $GLOBALS['fpwd_table'];
fpw_handle_draft_action_request( wc_get_order( $id ), $draft, 'approve' );
fpw_handle_draft_action_request( wc_get_order( $id ), $draft, 'save' );
check( $before_replay === $GLOBALS['fpwd_table'] && $first_mail_count + 1 === count( $GLOBALS['fpwd_mail_calls'] ), 'repeated approval/stale version-1 forms cannot save, republish or resend' );
$_GET = array( 'page' => FPW_DRAFT_SCREEN, 'request' => (string) $id, 'workspace' => '1', 'version' => '1' ); $_POST = array();
$historical = fpw_workspace_detail_markup( wc_get_order( $id ), $draft, fpw_read_draft_work( $id ), null );
check( str_contains( $historical, '· Versión 1' ) && str_contains( $historical, 'version=1' ), 'Ver historical version shows the selected original, not the newest document' );
check( fpw_read_draft_work_raw( $id ) === $unrelated_work_raw && $GLOBALS['fpwd_table'][fpw_draft_preview_row_name( $id )] === $initial_preview_raw && $GLOBALS['fpwd_table'][fpw_draft_row_name( $id )] === $receipt_raw, 'revision never rewrites request receipt, original work or original preview' );

// Two competing approvals race: nested contender wins the CAS before outer contender.
revision_route( $id, 2 );
check( fpw_read_draft_work( $id )['lines'][0]['price'] === 1250, 'next editing cycle starts from latest SENT values' );
$review3 = revision_review( $id, 1500 );
$before_race = count( $GLOBALS['fpwd_mail_calls'] );
$GLOBALS['fpwd_query_fault'] = static function( $sql ) use ( $id, $draft, $review3 ) {
	if ( !str_contains( $sql, "option_name = 'fpw_quotation_$id' AND option_value" ) ) { return null; }
	unset( $GLOBALS['fpwd_query_fault'] );
	$GLOBALS['revision_race_winner'] = fpw_quotation_approve_and_send( $id, $draft, fpw_read_draft_work( $id ), $review3, 2 );
	return null;
};
$loser = fpw_quotation_approve_and_send( $id, $draft, fpw_read_draft_work( $id ), $review3, 1 );
check( 'already' === $loser['state'] && 'sent' === $GLOBALS['revision_race_winner']['state'] && $before_race + 1 === count( $GLOBALS['fpwd_mail_calls'] ), 'competing approvals converge to one durable revised version and exactly one mail' );
$third = fpw_read_quotation_version( $id );
check( 3 === $third['version'] && $third['history'] === array( $original, array_diff_key( $second, array( 'history' => true ) ) ), 'third version has exact nonrecursive history of versions 1 and 2' );

// Unknown delivery is durable and never automatically retried or offered for editing.
revision_route( $id, 3 );
revision_review( $id, 1750 );
$GLOBALS['fpwd_mail_mode'] = 'throw';
fpw_handle_draft_action_request( wc_get_order( $id ), $draft, 'approve' );
$unknown = fpw_read_quotation_version( $id );
check( 4 === $unknown['version'] && 'unknown' === $unknown['delivery']['state'] && count( $unknown['history'] ) === 3, 'unknown transport preserves the new document and all original history' );
check( !fpw_quotation_can_revise( $id, $unknown ) && !fpw_workspace_can_archive( $id, $unknown ), 'unknown revision cannot invite another send or archive a previously sent request' );
$before_unknown_replay = count( $GLOBALS['fpwd_mail_calls'] );
fpw_handle_draft_action_request( wc_get_order( $id ), $draft, 'approve' );
check( $before_unknown_replay === count( $GLOBALS['fpwd_mail_calls'] ) && fpw_workspace_pdf_response( $id, 1, 'offline-nonce' )['bytes'] === $original_pdf, 'unknown replay sends nothing and original PDF is still exact' );
$GLOBALS['fpwd_mail_mode'] = 'ok';
// A renderer failure after atomic publication preserves history and attempts no mail.
$_GET = $_POST = array();
$doc_id = 302;
fpwd_review_for_approval( $doc_id );
$doc_draft = fpw_read_request_draft( $doc_id );
fpw_quotation_approve_and_send( $doc_id, $doc_draft, fpw_read_draft_work( $doc_id ), fpw_read_draft_preview( $doc_id ), 1 );
$doc_original = fpw_read_quotation_version( $doc_id );
revision_route( $doc_id, 1 );
revision_review( $doc_id, 1200 );
$before_doc_mail = count( $GLOBALS['fpwd_mail_calls'] );
$document_filters = $GLOBALS['registered_filters']['fpw_quotation_document_bytes'] ?? array();
$GLOBALS['registered_filters']['fpw_quotation_document_bytes'] = array( static fn() => 'not-a-pdf' );
fpw_handle_draft_action_request( wc_get_order( $doc_id ), $doc_draft, 'approve' );
$GLOBALS['registered_filters']['fpw_quotation_document_bytes'] = $document_filters;
$pending_doc = fpw_read_quotation_version( $doc_id );
check( 'approval-document-pending' === fpw_pending_draft_outcome()['result']['state'] && 2 === $pending_doc['version'] && 'pending' === $pending_doc['document'], 'failed revised renderer leaves an honest pending document' );
check( $before_doc_mail === count( $GLOBALS['fpwd_mail_calls'] ) && $pending_doc['history'] === array( $doc_original ) && fpw_workspace_pdf_response( $doc_id, 1, 'offline-nonce' )['bytes'] === fpw_workspace_pdf_bytes( $doc_original ), 'document failure sends nothing and preserves original PDF/history exactly' );
$GLOBALS['fpwd_caps'] = array();
$_POST = array( 'fpw_work_save' => '1' );
$before_denied = $GLOBALS['fpwd_table'];
try { fpw_handle_draft_posted_action(); check( false, 'revision actions require capability' ); }
catch ( FPWD_Die $e ) { check( '403' === $e->getMessage() && $before_denied === $GLOBALS['fpwd_table'], 'revision front door denies unauthorized mutation independently of nonces' ); }
try { fpw_workspace_pdf_response( $id, 1, 'offline-nonce' ); check( false, 'historical PDFs remain private' ); }
catch ( FPWD_Die $e ) { check( '403' === $e->getMessage(), 'history does not weaken PDF authorization' ); }
$GLOBALS['fpwd_caps'] = array( 'manage_woocommerce' => true );
$_GET = $_POST = array();
fpw_pending_draft_outcome( array() );
echo 'quotation revisions: ' . ( $assertions - $start_revisions ) . " offline checks passed\n";
