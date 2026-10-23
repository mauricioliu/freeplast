<?php
/** Inbox over native received requests, including reversible archiving. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Chilean civil time, independent of the WordPress/server default; DST is automatic. */
function fpw_workspace_date( string $format, int $timestamp ): string {
	return wp_date( $format, $timestamp, new DateTimeZone( 'America/Santiago' ) );
}

function fpw_workspace_stages(): array {
	// Do not reuse the old `sent` key, which meant NOT sent in the first-pending filter.
	return array( 'pendientes' => 'Pendientes', 'sent-quotes' => 'Enviadas', 'archived' => 'Archivadas', 'all' => 'Todas' );
}

/** Matching CPT/HPOS read models. Native request marker is authority; no receipt/draft is created on GET. */
function fpw_workspace_request_query_parts( string $search, string $stage, bool $hpos ): array {
	global $wpdb;
	$orders = $hpos ? $wpdb->prefix . 'wc_orders' : $wpdb->posts;
	$meta = $hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
	$id = $hpos ? 'o.id' : 'o.ID';
	$meta_id = $hpos ? 'order_id' : 'post_id';
	$date = $hpos ? 'o.date_created_gmt' : 'o.post_date_gmt';
	$type = $hpos ? 'o.type' : 'o.post_type';
	$status = $hpos ? 'o.status' : 'o.post_status';
	$source = " FROM $orders o LEFT JOIN {$wpdb->options} d ON d.option_name = CONCAT('fpw_draft_', $id) LEFT JOIN {$wpdb->options} v ON v.option_name = CONCAT('fpw_quotation_', $id)";
	$where = " WHERE $type = 'shop_order' AND $status NOT IN ('trash', 'auto-draft', 'wc-checkout-draft') AND EXISTS (SELECT 1 FROM $meta marker WHERE marker.$meta_id = $id AND marker.meta_key = '_fp_request' AND marker.meta_value = 'yes')";
	$version = "CASE WHEN JSON_VALID(v.option_value) THEN v.option_value ELSE '{}' END";
	// Issued AND its real mail handoff accepted: the one automatic sent state.
	$sent = "JSON_EXTRACT($version, '$.schema') = 1 AND JSON_EXTRACT($version, '$.order_id') = $id AND JSON_EXTRACT($version, '$.version') >= 1 AND JSON_EXTRACT($version, '$.document') IN ('ready', '\"ready\"') AND JSON_EXTRACT($version, '$.delivery.state') IN ('accepted', '\"accepted\"')";
	// Readable issued row (any delivery state): neither pending nor lost — only Todas shows it.
	$readable = "JSON_VALID($version) AND JSON_EXTRACT($version, '$.schema') = 1 AND JSON_EXTRACT($version, '$.order_id') = $id AND JSON_EXTRACT($version, '$.version') >= 1";
	// A later accepted send always wins over an earlier archive marker.
	$archived = "EXISTS (SELECT 1 FROM $meta archived WHERE archived.$meta_id = $id AND archived.meta_key = '_fp_workspace_archived' AND archived.meta_value = 'yes') AND NOT COALESCE(($sent), 0) AND JSON_EXTRACT($version, '$.history[0]') IS NULL";
	if ( 'archived' === $stage ) { $where .= " AND ($archived)"; }
	if ( 'pendientes' === $stage ) { $where .= " AND NOT ($archived)"; }
	if ( 'sent-quotes' === $stage ) {
		$where .= " AND $sent";
	}
	if ( 'pendientes' === $stage ) {
		// No version row yet (still to quote), or a readable one that is NOT confirmed sent.
		// Unreadable/corrupt rows belong to neither view; Todas names them honestly.
		$where .= " AND ( v.option_value IS NULL OR ( $readable AND NOT ( $sent ) ) )";
	}
	if ( '' !== $search ) {
		$like = '%' . $wpdb->esc_like( $search ) . '%';
		$draft = "CASE WHEN JSON_VALID(d.option_value) THEN d.option_value ELSE '{}' END";
		$matches = $wpdb->prepare( "JSON_EXTRACT($draft, '$.identity.company') LIKE %s OR JSON_EXTRACT($draft, '$.identity.rut') LIKE %s OR JSON_EXTRACT($draft, '$.reference') LIKE %s", $like, $like, $like );
		$matches .= $wpdb->prepare( " OR EXISTS (SELECT 1 FROM $meta m WHERE m.$meta_id = $id AND m.meta_key IN ('_billing_company', '_billing_fp_rut', '_fpq_reference') AND m.meta_value LIKE %s)", $like );
		if ( $hpos ) { $matches .= $wpdb->prepare( " OR EXISTS (SELECT 1 FROM {$wpdb->prefix}wc_order_addresses a WHERE a.order_id = $id AND a.address_type = 'billing' AND a.company LIKE %s)", $like ); }
		if ( preg_match( '/^(?:FP-\d{4}-)?(\d+)$/iD', $search, $match ) ) { $matches .= $wpdb->prepare( " OR $id = %d", (int) $match[1] ); }
		$where .= ' AND (' . $matches . ')';
	}
	return array( 'source' => $source . $where, 'id' => $id, 'date' => $date );
}

function fpw_workspace_query( string $search, string $stage, string $direction, int $page ): array {
	global $wpdb;
	$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	$query = fpw_workspace_request_query_parts( $search, $stage, $hpos );
	$count = $wpdb->get_var( 'SELECT COUNT(*)' . $query['source'] );
	if ( '' !== $wpdb->last_error ) { return array( 'error' => true, 'total' => 0, 'rows' => array(), 'page' => 1, 'pages' => 1 ); }
	$pages = max( 1, (int) ceil( (int) $count / 25 ) );
	$page = min( max( 1, $page ), $pages );
	$sort = 'ASC' === $direction ? 'ASC' : 'DESC';
	$rows = $wpdb->get_col( 'SELECT ' . $query['id'] . $query['source'] . " ORDER BY {$query['date']} $sort, {$query['id']} $sort" . $wpdb->prepare( ' LIMIT %d OFFSET %d', 25, ( $page - 1 ) * 25 ) );
	return array( 'error' => '' !== $wpdb->last_error, 'total' => (int) $count, 'rows' => array_map( 'intval', $rows ?? array() ), 'page' => $page, 'pages' => $pages );
}

/** Unknown/corrupt delivery records cannot establish that a quotation was NOT sent. */
function fpw_workspace_can_archive( int $id, ?array $version, bool $unavailable = false ): bool {
	if ( $unavailable || ! empty( $version['history'] ) ) { return false; }
	if ( null === $version ) { return true; }
	return 1 === ( $version['schema'] ?? null ) && $id === ( $version['order_id'] ?? null )
		&& is_int( $version['version'] ?? null ) && $version['version'] > 0
		&& in_array( $version['delivery']['state'] ?? null, array( null, 'rejected' ), true );
}

/** Only archive metadata changes: no request, draft, PDF or delivery is rewritten. */
function fpw_workspace_set_archived( int $id, bool $archived ): string {
	if ( ! fpw_can_manage_quotations() ) { return 'No tienes permiso para modificar solicitudes.'; }
	$order = wc_get_order( $id );
	if ( ! $order || 'yes' !== $order->get_meta( '_fp_request' ) ) { return 'Solicitud no disponible.'; }
	if ( $archived ) {
		$draft = fpw_read_request_draft( $id );
		if ( ! $draft ) { return 'Abre la solicitud antes de archivarla para comprobar su estado.'; }
		$offer = fpw_workspace_read_offer( $draft, fpw_read_draft_work( $id ) );
		if ( ! fpw_workspace_can_archive( $id, $offer['version'], $offer['unavailable'] ) ) {
			return 'Solo se pueden archivar cotizaciones no enviadas y con estado comprobable.';
		}
	}
	$order->update_meta_data( '_fp_workspace_archived', $archived ? 'yes' : 'no' );
	$order->save_meta_data();
	return $archived ? 'Solicitud archivada.' : 'Solicitud desarchivada.';
}

function fpw_workspace_archive_html( int $id, bool $archived, bool $allowed ): string {
	if ( ! $archived && ! $allowed ) { return ''; }
	return '<form method="post" class="fpw-archive-form">'
		. wp_nonce_field( 'fpw_archive_' . $id, 'fpw_archive_nonce', true, false )
		. '<input type="hidden" name="fpw_archive_request" value="' . $id . '">'
		. '<button class="fpw-secondary" type="submit" name="fpw_archive_action" value="' . ( $archived ? 'restore' : 'archive' ) . '">'
		. ( $archived ? 'Desarchivar' : 'Archivar' ) . '</button></form>';
}

/** Sending is automatic. Manual payment/acceptance/dispatch records do not participate. */
function fpw_workspace_request_state_html( int $id, ?array $version, bool $unavailable = false, bool $archived = false ): string {
	$unavailable = $unavailable || ( null !== $version && ( 1 !== ( $version['schema'] ?? null ) || $id !== ( $version['order_id'] ?? null ) || ! is_int( $version['version'] ?? null ) || $version['version'] < 1 ) );
	$sent = ! $unavailable && 'ready' === ( $version['document'] ?? null ) && 'accepted' === ( $version['delivery']['state'] ?? null );
	// Match inbox membership: a confirmed send (including history) wins over an old archive marker.
	$archived = $archived && ! $sent && empty( $version['history'] );
	$label = $archived ? 'Solicitud archivada' : ( $unavailable ? 'Estado no disponible' : ( $sent ? 'Cotización enviada' : ( null === $version ? 'Solicitud recibida' : 'Cotización sin envío confirmado' ) ) );
	// The send moment is the recorded mail handoff (delivery.at) only — never approval,
	// creation or row update; a missing date is named, never substituted.
	$at = $sent && is_int( $version['delivery']['at'] ?? null ) ? $version['delivery']['at'] : null;
	$moment = null !== $at
		? '<time datetime="' . esc_attr( fpw_workspace_date( 'c', $at ) ) . '">' . esc_html( fpw_workspace_date( 'd/m/Y · H:i', $at ) ) . '</time>'
		: ( $sent ? '<span class="fpw-muted">Fecha de envío no disponible</span>' : '' );
	return '<div class="fpw-request-state' . ( $sent ? ' is-sent' : '' ) . '" data-fpw-request-state="' . ( $archived ? 'archived' : ( $sent ? 'sent' : 'received' ) ) . '"><span>' . $label . '</span>' . $moment . '</div>';
}
