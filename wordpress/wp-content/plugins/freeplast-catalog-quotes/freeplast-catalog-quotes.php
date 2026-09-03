<?php
/**
 * Plugin Name: Freeplast Catalog & Quotes
 * Plugin URI: https://freeplast.mliu.site/
 * Description: Private plugin for the Freeplast staging site. Owns product records, catalog synchronization, quote baskets, quote requests, notifications and the sales workflow. WooCommerce is not installed or required. This baseline registers the shell routes and the versioned migration boundary; later slices add the catalog, basket and request behavior.
 * Version: 0.1.0
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

define( 'FREEPLAST_CQ_VERSION', '0.1.0' );
define( 'FREEPLAST_CQ_DB_VERSION', 1 );

require_once __DIR__ . '/includes/class-migrations.php';
require_once __DIR__ . '/includes/class-shell.php';

/**
 * Run migrations and seed the shell state on activation.
 */
function freeplast_cq_activate() {
	Freeplast_CQ_Migrations::run();
	Freeplast_CQ_Shell::seed();
}
register_activation_hook( __FILE__, 'freeplast_cq_activate' );

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
