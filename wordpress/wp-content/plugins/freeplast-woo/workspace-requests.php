<?php
/** Read-only inbox over native received requests, including those predating quotation drafts. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function fpw_workspace_stages(): array {
	// Do not reuse the old `sent` key, which meant NOT sent in the first-pending filter.
	return array( 'pendientes' => 'Pendientes', 'sent-quotes' => 'Enviadas', 'all' => 'Todas' );
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

/** Sending is automatic. Manual payment/acceptance/dispatch records do not participate. */
function fpw_workspace_request_state_html( int $id, ?array $version, bool $unavailable = false ): string {
	$unavailable = $unavailable || ( null !== $version && ( 1 !== ( $version['schema'] ?? null ) || $id !== ( $version['order_id'] ?? null ) || ! is_int( $version['version'] ?? null ) || $version['version'] < 1 ) );
	$sent = ! $unavailable && 'ready' === ( $version['document'] ?? null ) && 'accepted' === ( $version['delivery']['state'] ?? null );
	$label = $unavailable ? 'Estado no disponible' : ( $sent ? 'Cotización enviada' : ( null === $version ? 'Solicitud recibida' : 'Cotización sin envío confirmado' ) );
	$at = $sent && is_int( $version['delivery']['at'] ?? null ) ? $version['delivery']['at'] : null;
	return '<div class="fpw-request-state' . ( $sent ? ' is-sent' : '' ) . '" data-fpw-request-state="' . ( $sent ? 'sent' : 'received' ) . '"><span>' . $label . '</span>'
		. ( null !== $at ? '<time datetime="' . esc_attr( wp_date( 'c', $at ) ) . '">' . esc_html( wp_date( 'd/m/Y', $at ) ) . '</time>' : '' ) . '</div>';
}
