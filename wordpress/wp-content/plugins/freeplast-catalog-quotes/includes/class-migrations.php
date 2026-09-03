<?php
/**
 * Versioned database migrations for the Freeplast plugin.
 *
 * Every schema change bumps FREEPLAST_CQ_DB_VERSION and gets its own case
 * below. The applied version is stored in the fp_db_version option and is
 * reported by the automated check (`npm test`).
 *
 * Migration 1 — baseline: no plugin tables yet. The quote-session table
 * arrives with the basket slice (issue #6/#7).
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
		$applied = (int) get_option( 'fp_db_version', 0 );

		if ( $applied < 1 ) {
			// Migration 1 — baseline marker; no schema yet.
			$applied = 1;
		}

		if ( $applied < FREEPLAST_CQ_DB_VERSION ) {
			// Future migrations run here, in ascending order.
			$applied = FREEPLAST_CQ_DB_VERSION;
		}

		update_option( 'fp_db_version', $applied );
	}
}
