<?php
/**
 * Plugin Name: Freeplast Catalog & Quotes
 * Plugin URI: https://freeplast.mliu.site/
 * This slice registers the shell routes (complete v6 content since migration 5: Contacto details + CTA, privacy disclosure), the versioned migration boundary with a safe maintenance state for public routes, the hidden-editor fp_product record type, the WP-CLI catalog synchronizer, the catalog discovery blocks (Home featured, Tienda grid/filter, search), the editable persistent anonymous Quote Basket (quantity/option choosers, secure cookie session, header count, mini basket, line update/remove, expiry sweep), the Quote Request submission (non-public fp_quote records with immutable snapshots, permanent FP-YYYY-NNNNNN references, idempotency, honeypot/minimum-completion-time/bounded throttling), the Google-assisted Delivery Address confirmation with the internal Dispatch Distance (Chilean suggestions with an explicit confirm, manual fallback, server-mediated provider adapter, configurable Warehouse origin, staff-only distance and retry), the restricted sales administration workflow (Ventas Freeplast role, sortable/searchable Cotizaciones list, current-contact corrections, internal Sales Notes, Request Status transitions with explicit reopening, nonce+capability-guarded operations) and the durable sales/customer notifications (jobs committed with the record, idempotent delivery decoupled from receipt, staging mail containment, staff resend).
 * Version: 0.8.0
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

define( 'FREEPLAST_CQ_VERSION', '0.8.0' );
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
 * Public routes answer a clear maintenance state while a versioned
 * migration could not complete (issue #13): no half-migrated store is
 * ever rendered as a working one. The state is self-healing — the
 * pending migrations retry on every request and the flag clears as soon
 * as they complete — and nothing is destroyed while it is active.
 */
function freeplast_cq_maintenance_guard(): void {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
		return;
	}
	if ( ! Freeplast_CQ_Migrations::in_maintenance() ) {
		return;
	}

	status_header( 503 );
	nocache_headers();
	header( 'Retry-After: 300' );

	printf(
		'<!DOCTYPE html><html lang="es-CL"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>Freeplast — Sitio en mantención</title></head><body style="font-family: system-ui, sans-serif; margin: 0; display: grid; place-items: center; min-height: 100vh; background: #f4f5f7; color: #17181c;"><main style="max-width: 32rem; margin: 2rem 1rem; padding: 2rem; background: #ffffff; border-radius: 8px;"><h1 style="margin: 0 0 .5rem; font-size: 1.5rem; color: #0b078c;">Sitio en mantención</h1><p style="margin: 0 0 1rem;">Estamos actualizando el catálogo y el sistema de cotizaciones de Freeplast. Vuelve a intentarlo en unos minutos; tus datos no se han perdido.</p><p style="margin: 0;"><a href="mailto:ventas@freeplast.cl" style="color: #100090;">ventas@freeplast.cl</a> · +56 9 6844 4265</p></main></body></html>'
	);
	exit;
}
add_action( 'template_redirect', 'freeplast_cq_maintenance_guard', 0 );

/**
 * Administration surfaces explain the maintenance state to the staff
 * instead of failing silently (the restricted Cotizaciones pages stay
 * reachable for inspection — only public routes show the notice).
 */
function freeplast_cq_maintenance_notice(): void {
	if ( ! Freeplast_CQ_Migrations::in_maintenance() ) {
		return;
	}
	printf(
		'<div class="notice notice-error"><p><strong>Freeplast:</strong> una migración pendiente no pudo completarse y el sitio público está en mantención (503). La migración se reintenta en cada solicitud; revisa los permisos de la base de datos.</p></div>'
	);
}
add_action( 'admin_notices', 'freeplast_cq_maintenance_notice' );

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
