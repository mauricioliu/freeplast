<?php
/** Archive contract, synthetic native order metadata only. */
define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../wp-content/plugins/freeplast-woo/workspace-requests.php';
$allowed = true;
$offer = array( 'version' => null, 'unavailable' => false );
class ArchiveOrder {
	public array $meta = array( '_fp_request' => 'yes' );
	public int $saves = 0;
	public function get_meta( $key ) { return $this->meta[$key] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[$key] = $value; }
	public function save_meta_data() { ++$this->saves; }
}
$order = new ArchiveOrder();
function fpw_can_manage_quotations() { return $GLOBALS['allowed']; }
function wc_get_order( $id ) { return 91 === $id ? $GLOBALS['order'] : false; }
function fpw_read_request_draft( $id ) { return array( 'order_id' => $id ); }
function fpw_read_draft_work( $id ) { return null; }
function fpw_workspace_read_offer( $draft, $work ) { return $GLOBALS['offer']; }
function wp_nonce_field( $action, $name, $referer, $echo ) { return '<input name="' . $name . '" value="' . $action . '">'; }
$count = 0;
function check_archive( $ok, $message ) { ++$GLOBALS['count']; if ( ! $ok ) { throw new RuntimeException( $message ); } }
check_archive( 'Solicitud archivada.' === fpw_workspace_set_archived( 91, true ) && 'yes' === $order->meta['_fp_workspace_archived'], 'unsent request archives' );
check_archive( 'Solicitud desarchivada.' === fpw_workspace_set_archived( 91, false ) && 'no' === $order->meta['_fp_workspace_archived'], 'archive is reversible' );
$version = array( 'schema' => 1, 'order_id' => 91, 'version' => 1, 'document' => 'ready', 'delivery' => array( 'state' => 'accepted' ) );
foreach ( array( 'accepted', 'unknown', 'rejected', null ) as $state ) {
	$offer['version'] = $version;
	$offer['version']['delivery']['state'] = $state;
	$before = $order->saves;
	fpw_workspace_set_archived( 91, true );
	check_archive( $order->saves === $before + ( in_array( $state, array( null, 'rejected' ), true ) ? 1 : 0 ), 'server enforces send state ' . ( $state ?? 'pending' ) );
}
$offer['unavailable'] = true;
$before = $order->saves;
fpw_workspace_set_archived( 91, true );
check_archive( $before === $order->saves, 'unavailable/corrupt offer blocks archive' );
fpw_workspace_set_archived( 91, false );
check_archive( 'no' === $order->meta['_fp_workspace_archived'], 'restore remains possible for unavailable offer' );
$allowed = false;
$before = $order->saves;
fpw_workspace_set_archived( 91, false );
check_archive( $before === $order->saves, 'unauthorized user cannot restore' );
$allowed = true;
$order->meta['_fp_request'] = 'no';
fpw_workspace_set_archived( 91, true );
check_archive( $before === $order->saves, 'ordinary order cannot archive' );
check_archive( '' === fpw_workspace_archive_html( 91, false, false ), 'sent quotation has no archive control' );
check_archive( str_contains( fpw_workspace_archive_html( 91, false, true ), '>Archivar</button>' ), 'unsent quotation has archive control' );
$restore = fpw_workspace_archive_html( 91, true, false );
check_archive( str_contains( $restore, '>Desarchivar</button>' ) && str_contains( $restore, 'fpw_archive_91' ) && str_contains( $restore, 'value="restore"' ), 'archived request has nonce-bound restore form' );
$archived_state = fpw_workspace_request_state_html( 91, null, false, true );
check_archive( str_contains( $archived_state, 'Solicitud archivada' ) && str_contains( $archived_state, 'data-fpw-request-state="archived"' ) && !str_contains( $archived_state, 'Solicitud recibida' ), 'archived receipt shows its archive state in every inbox view' );
function wp_date( $format, $timestamp, $timezone = null ) { return ( new DateTimeImmutable( '@' . (int) $timestamp ) )->setTimezone( $timezone ?? new DateTimeZone( 'UTC' ) )->format( $format ); }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
$sent_version = array( 'schema' => 1, 'order_id' => 91, 'version' => 1, 'document' => 'ready', 'approved_at' => 1704024000, 'delivery' => array( 'state' => 'accepted', 'at' => 1704110400 ) );
$sent_html = fpw_workspace_request_state_html( 91, $sent_version, false, false );
check_archive( str_contains( $sent_html, 'Cotización enviada' ) && str_contains( $sent_html, 'datetime="2024-01-01T09:00:00-03:00"' ) && str_contains( $sent_html, '01/01/2024 · 09:00' ) && !str_contains( $sent_html, '31/12/2023' ), 'sent row shows the recorded mail handoff moment with date and time' );
$undated = $sent_version;
unset( $undated['delivery']['at'] );
$undated_html = fpw_workspace_request_state_html( 91, $undated, false, false );
check_archive( str_contains( $undated_html, 'Cotización enviada' ) && str_contains( $undated_html, 'Fecha de envío no disponible' ) && !str_contains( $undated_html, '<time' ), 'sent without a recorded date says so explicitly instead of substituting another timestamp' );
$string_at = $sent_version;
$string_at['delivery']['at'] = '2024-01-01';
check_archive( str_contains( fpw_workspace_request_state_html( 91, $string_at, false, false ), 'Fecha de envío no disponible' ), 'non-integer delivery date is treated as unknown' );
$approved_only = $sent_version;
$approved_only['delivery'] = array( 'state' => null, 'at' => null );
check_archive( !str_contains( fpw_workspace_request_state_html( 91, $approved_only, false, false ), 'Cotización enviada' ) && !str_contains( fpw_workspace_request_state_html( 91, $approved_only, false, false ), 'Fecha de envío' ), 'approval without mail acceptance is never presented as sent' );
$revised_sent = $sent_version;
$revised_sent['version'] = 2;
$revised_sent['history'] = array( array_merge( $sent_version, array( 'version' => 1, 'delivery' => array( 'state' => 'accepted', 'at' => 1704024000 ) ) ) );
$revised_html = fpw_workspace_request_state_html( 91, $revised_sent, false, false );
check_archive( str_contains( $revised_html, '01/01/2024 · 09:00' ) && !str_contains( $revised_html, '31/12/2023 · 09:00' ), 'a revised quotation shows the standing version send moment, not the superseded one' );
$unavailable_html = fpw_workspace_request_state_html( 91, $sent_version, true, false );
check_archive( str_contains( $unavailable_html, 'Estado no disponible' ) && !str_contains( $unavailable_html, '<time' ), 'unreadable version keeps no send moment' );
$received_html = fpw_workspace_request_state_html( 91, null, false, false );
check_archive( str_contains( $received_html, 'Solicitud recibida' ) && !str_contains( $received_html, '<time' ) && !str_contains( $received_html, 'envío' ), 'unsent receipt shows no send moment at all' );
check_archive( str_contains( fpw_workspace_request_state_html( 91, null, false, false ), 'Solicitud recibida' ), 'restoring recovers received state' );
check_archive( str_contains( fpw_workspace_request_state_html( 91, $version, false, true ), 'Cotización enviada' ), 'accepted send wins over obsolete archive marker' );
$revised = $version;
$revised['delivery']['state'] = 'rejected';
$revised['history'] = array( $version );
check_archive( !str_contains( fpw_workspace_request_state_html( 91, $revised, false, true ), 'Solicitud archivada' ), 'previously sent revision cannot look archived' );
echo "workspace archive: $count checks passed\n";
