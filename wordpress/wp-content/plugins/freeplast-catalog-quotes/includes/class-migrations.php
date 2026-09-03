<?php
/**
 * Versioned database migrations for the Freeplast plugin.
 *
 * Every schema change bumps FREEPLAST_CQ_DB_VERSION and gets its own case
 * below. The applied version is stored in the fp_db_version option and is
 * reported by the automated check (`npm test`).
 *
 * Migration 1 — baseline: no plugin tables yet.
 * Migration 2 — catalog slice (issue #3): the fp_product archive owns
 *               /tienda/, so the seeded placeholder page is retired
 *               (trashed, reversible). Only the page recorded in the
 *               fp_shell_pages option is ever touched — human content is
 *               never destroyed by a migration.
 * Migration 3 — catalog discovery (issue #5): the Tienda category filter
 *               routes /tienda/categoria/<categoria>/ (registered on init
 *               by Freeplast_CQ_Discovery) become resolvable; this version
 *               bump schedules the flag-based rewrite flush that writes
 *               them. No schema or content changes.
 * Migration 4 — quote basket (issue #6): the versioned basket_sessions
 *               table (opaque-token hashes + lines, see
 *               Freeplast_CQ_Basket) and the plugin takes over the
 *               /cotizacion/ page: the seeded empty-state placeholder is
 *               replaced by the freeplast/basket block. Only the exact
 *               seeded placeholder is replaced — human content edits are
 *               never clobbered.
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

		if ( $applied < 3 ) {
			// Migration 3 — catalog discovery routes (see class-discovery.php);
			// the flush flag written below makes the rewrite rule resolvable.
			$applied = 3;
		}

		if ( $applied < 4 ) {
			// Migration 4 — the quote-basket session table and the /cotizacion/
			// takeover (see class-basket.php and class-shell.php).
			Freeplast_CQ_Basket::create_table();

			$shell_pages = get_option( 'fp_shell_pages', array() );
			if ( ! empty( $shell_pages['cotizacion'] ) ) {
				$page = get_post( (int) $shell_pages['cotizacion'] );
				if (
					$page instanceof WP_Post &&
					'page' === $page->post_type &&
					'cotizacion' === $page->post_name &&
					Freeplast_CQ_Shell::legacy_cotizacion_placeholder() === $page->post_content
				) {
					wp_update_post(
						array(
							'ID'           => $page->ID,
							'post_content' => "<!-- wp:freeplast/basket /-->\n",
						)
					);
				}
			}
			$applied = 4;
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
