<?php
/**
 * Explicit, non-destructive uninstall behavior (issue #13).
 *
 * Uninstalling the plugin must never destroy business records: Products,
 * Quote Requests, their histories/notes/notification jobs, the basket
 * session table and the stored configuration all survive, so installing
 * the plugin again reattaches to the same data (the PRD forbids implicit
 * destructive uninstall behavior).
 *
 * Only ephemeral operational state is cleaned up, because nothing else
 * can serve it once the plugin's code is gone:
 *
 *   - our scheduled events (the daily basket sweep and the pending
 *     notification delivery events — the durable jobs themselves stay on
 *     the records and can be resent after a reinstall), and
 *   - the expiring submission transients (idempotency tokens with their
 *     render times, retained invalid-attempt values, rate counters,
 *     confirmation markers and address-confirmation state).
 *
 * @package Freeplast_Catalog_Quotes
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) || ! WP_UNINSTALL_PLUGIN ) {
	exit;
}

/* 1. Unschedule our events (single and recurring), whatever their args. */
$cron = get_option( 'cron', array() );
if ( is_array( $cron ) ) {
	foreach ( $cron as $timestamp => $entries ) {
		if ( ! is_array( $entries ) ) {
			continue;
		}
		foreach ( $entries as $hook => $bins ) {
			$is_our_hook = is_string( $hook ) && ( str_starts_with( $hook, 'fpcq_' ) || str_starts_with( $hook, 'freeplast_cq_' ) );
			if ( ! $is_our_hook ) {
				continue;
			}
			foreach ( $bins as $bin ) {
				if ( is_array( $bin ) ) {
					wp_unschedule_event( (int) $timestamp, $hook, isset( $bin['args'] ) ? $bin['args'] : array() );
				}
			}
		}
	}
}

/* 2. Delete the expiring fpcq- transients (never records or options). */
global $wpdb;
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\\_transient\\_fpcq\\_%'
	    OR option_name LIKE '\\_transient\\_timeout\\_fpcq\\_%'"
);

/* 3. Drop the rewrite-flush request flag (meaningless without the plugin). */
delete_option( 'fp_flush_rewrite_rules' );

/*
 * Deliberately PRESERVED (business records and configuration):
 *   - fp_product and fp_quote posts with all their meta (Submitted
 *     Details, current contact copies, immutable snapshots, histories,
 *     Sales Notes, notification jobs/logs, destinations, distances),
 *   - the <prefix>basket_sessions table (anonymous sessions),
 *   - fp_shell_pages, fp_dispatch_origin, fp_db_version,
 *     fp_plugin_version and the runtime-expectation options.
 */
