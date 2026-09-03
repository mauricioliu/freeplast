<?php
/**
 * Versioned database migrations for the Freeplast plugin.
 *
 * Every schema change bumps FREEPLAST_CQ_DB_VERSION and gets its own case
 * below. The applied version is stored in the fp_db_version option and is
 * reported by the automated check (`npm test`).
 *
 * Migration 1 — baseline: no plugin tables yet. The quote-session table
 *               arrives with the basket slice (issue #6/#7).
 * Migration 2 — catalog slice (issue #3): the fp_product archive owns
 *               /tienda/, so the seeded placeholder page is retired
 *               (trashed, reversible). Only the page recorded in the
 *               fp_shell_pages option is ever touched — human content is
 *               never destroyed by a migration.
 *
 * @package Freeplast_Catalog_Quotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Freeplast_CQ_Migrations {

	/**
	 * Apply pending migrations up to FREEPLAST_CQ_DB_VERSION.
	 *
	 * Idempotent: already-applied migrations never run twice.
	 */
	public static function run(): void {
		$previous = (int) get_option( 'fp_db_version', 0 );
		$applied  = $previous;

		if ( $applied < 1 ) {
			// Migration 1 — baseline marker; no schema yet.
			$applied = 1;
		}

		if ( $applied < 2 ) {
			// Migration 2 — the fp_product archive replaces the /tienda/ placeholder.
			$shell_pages = get_option( 'fp_shell_pages', array() );
			if ( ! empty( $shell_pages['tienda'] ) ) {
				$placeholder = get_post( (int) $shell_pages['tienda'] );
				if ( $placeholder instanceof WP_Post && 'page' === $placeholder->post_type && 'tienda' === $placeholder->post_name ) {
					wp_trash_post( $placeholder->ID );
				}
			}
			$applied = 2;
		}

		if ( $applied < FREEPLAST_CQ_DB_VERSION ) {
			// Future migrations run here, in ascending order.
			$applied = FREEPLAST_CQ_DB_VERSION;
		}

		$changed = $previous !== $applied;
		update_option( 'fp_db_version', $applied );

		/* Migrations that change routing request a rewrite flush; the flush
	   itself happens on init once post types are registered
	   (see flush_if_needed) — flushing during a late plugin activation
	   would write rules without the fp_product archive. */
		if ( $changed ) {
			update_option( 'fp_flush_rewrite_rules', 1 );
		}
	}

	/**
	 * Flush rewrite rules on init (after post types are registered) when a
	 * migration or the activation hook requested it. Safe to call on every
	 * request: the flag only survives until the flush completes.
	 */
	public static function flush_if_needed(): void {
		if ( get_option( 'fp_flush_rewrite_rules' ) ) {
			update_option( 'fp_flush_rewrite_rules', 0 );
			flush_rewrite_rules();
		}
	}
}
