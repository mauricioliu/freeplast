<?php
/**
 * Plugin Name: Freeplast Catalog & Quotes
 * Plugin URI: https://freeplast.mliu.site/
 * This slice registers the shell routes (complete v6 content since migration 5: Contacto details + CTA, privacy disclosure), the versioned migration boundary, the hidden-editor fp_product record type, the WP-CLI catalog synchronizer, the catalog discovery blocks (Home featured, Tienda grid/filter, search), the editable persistent anonymous Quote Basket (quantity/option choosers, secure cookie session, header count, mini basket, line update/remove, expiry sweep), the Quote Request submission (non-public fp_quote records with immutable snapshots, permanent FP-YYYY-NNNNNN references, idempotency), the Google-assisted Delivery Address confirmation with the internal Dispatch Distance (Chilean suggestions with an explicit confirm, manual fallback, server-mediated provider adapter, configurable Warehouse origin, staff-only distance and retry), the restricted sales administration workflow (Ventas Freeplast role, sortable/searchable Cotizaciones list, current-contact corrections, internal Sales Notes, Request Status transitions with explicit reopening, nonce+capability-guarded operations) and the durable sales/customer notifications (jobs committed with the record, idempotent delivery decoupled from receipt, staging mail containment, staff resend).
 * Version: 0.7.0
 * Requires at least: 7.0
 * Requires PHP: 8.1
 * Author: Freeplast
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: freeplast-cq
 *
 * @package Freeplast_Catalog_Quotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FREEPLAST_CQ_VERSION', '0.7.0' );
define( 'FREEPLAST_CQ_DB_VERSION', 7 );
define( 'FREEPLAST_CQ_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-migrations.php';
require_once __DIR__ . '/includes/class-shell.php';
require_once __DIR__ . '/includes/class-products.php';
require_once __DIR__ . '/includes/class-catalog-source.php';
require_once __DIR__ . '/includes/class-catalog-sync.php';
require_once __DIR__ . '/includes/class-discovery.php';
require_once __DIR__ . '/includes/class-basket.php';
require_once __DIR__ . '/includes/class-request.php';
require_once __DIR__ . '/includes/class-admin.php';
require_once __DIR__ . '/includes/class-notifications.php';
require_once __DIR__ . '/includes/class-address.php';

/**
 * Register the product record type, its metadata, the public
 * product-detail, catalog-discovery and quote-basket blocks, the catalog
 * synchronization command, the Quote Request submission, the sales
 * administration workflow and the Delivery Address confirmation and
 * Dispatch Distance operations.
 */
add_action( 'init', array( 'Freeplast_CQ_Products', 'register' ) );
add_action( 'init', array( 'Freeplast_CQ_Discovery', 'register' ) );
add_action( 'init', array( 'Freeplast_CQ_Basket', 'register' ) );
add_action( 'init', array( 'Freeplast_CQ_Request', 'register' ) );
add_action( 'init', array( 'Freeplast_CQ_Admin', 'register' ) );
add_action( 'init', array( 'Freeplast_CQ_Notifications', 'register' ) );
add_action( 'init', array( 'Freeplast_CQ_Address', 'register' ) );
Freeplast_CQ_Catalog_Sync::register();

/**
 * Run migrations and seed the shell state on activation.
 */
function freeplast_cq_activate() {
	Freeplast_CQ_Migrations::run();
	Freeplast_CQ_Shell::seed();
	/* Request a rewrite flush: during a late plugin activation the
	   fp_product post type is not yet registered in the running process,
	   so the flush must happen on the next init instead (migration 2 gives
	   the /tienda/ archive to fp_product). */
	update_option( 'fp_flush_rewrite_rules', 1 );
}
register_activation_hook( __FILE__, 'freeplast_cq_activate' );

/**
 * Keep the schema current even when the plugin files are updated without a
 * reactivation (migrations are idempotent and version-guarded).
 */
add_action( 'init', array( 'Freeplast_CQ_Migrations', 'run' ), 1 );
add_action( 'init', array( 'Freeplast_CQ_Migrations', 'flush_if_needed' ), 99 );

/**
 * Record the declared version expectations for operational reporting.
 * (The automated check reports these against the running environment.)
 */
add_action(
	'init',
	static function () {
		if ( false === get_option( 'fp_plugin_version', false ) ) {
			$header = get_file_data(
				__FILE__,
				array(
					'version'      => 'Version',
					'requires_wp'  => 'Requires at least',
					'requires_php' => 'Requires PHP',
				)
			);
			add_option( 'fp_plugin_version', $header['version'] );
			add_option( 'fp_requires_wp', $header['requires_wp'] );
			add_option( 'fp_requires_php', $header['requires_php'] );
		}
	}
);
