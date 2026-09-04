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
 * Migration 5 — v6 content and navigation (issue #12): the /contacto/ and
 *               /politica-de-privacidad/ pages gain their complete
 *               content — Contacto: current phone, email, WhatsApp,
 *               warehouse/map and hours plus one CTA into Cotización (no
 *               inquiry form); Política de privacidad: the basic
 *               collection/submission disclosure without a consent
 *               checkbox. Only the exact legacy placeholder is replaced;
 *               no schema change.
 * Migration 6 — quote-request submission (issue #8): the non-public
 *               fp_quote record type (registered on init by
 *               Freeplast_CQ_Request — no new table: Submitted Details,
 *               immutable snapshots and the idempotency hash live on the
 *               records; submission attempts/tokens live in expiring
 *               transients) and the dedicated sales capability
 *               (manage_freeplast_quotes) granted to administrators so
 *               the minimal admin detail is capability-protected.
 * Migration 7 — durable notifications (issue #10): every fp_quote record
 *               persisted before this slice gains its two pending
 *               notification jobs (_fpq_notifications — new records
 *               carry them from the submission insert itself) and a
 *               scheduled delivery event, so a pre-slice record can
 *               never sit undelivered forever. No new table: the jobs,
 *               their delivery state and the PII-free event log are meta
 *               on the records (see Freeplast_CQ_Notifications).
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

			self::take_over_page(
				get_option( 'fp_shell_pages', array() ),
				'cotizacion',
				Freeplast_CQ_Shell::legacy_cotizacion_placeholder(),
				Freeplast_CQ_Shell::COTIZACION_CONTENT
			);
			$applied = 4;
		}

		if ( $applied < 5 ) {
			// Migration 5 — the complete v6 Contacto and Política de
			// privacidad content (see class-shell.php). Byte-compared takeovers:
			// only the exact legacy placeholder is replaced, so human edits made
			// since issue #2 survive untouched.
			$shell_pages = get_option( 'fp_shell_pages', array() );
			self::take_over_page(
				$shell_pages,
				'contacto',
				Freeplast_CQ_Shell::legacy_contacto_placeholder(),
				Freeplast_CQ_Shell::contacto_content()
			);
			self::take_over_page(
				$shell_pages,
				'politica-de-privacidad',
				Freeplast_CQ_Shell::legacy_privacy_placeholder(),
				Freeplast_CQ_Shell::privacy_content()
			);
			$applied = 5;
		}

		if ( $applied < 6 ) {
			// Migration 6 — the Quote Request submission slice (see
			// class-request.php): no table, but the dedicated sales capability
			// guarding the minimal Cotizaciones admin surface is granted to
			// administrators exactly once.
			$administrator = get_role( 'administrator' );
			if ( null !== $administrator && ! $administrator->has_cap( Freeplast_CQ_Request::CAPABILITY ) ) {
				$administrator->add_cap( Freeplast_CQ_Request::CAPABILITY );
			}
			$applied = 6;
		}

		if ( $applied < 7 ) {
			// Migration 7 — durable notifications (see class-notifications.php):
			// backfill the two pending jobs and a delivery event onto every
			// fp_quote record persisted before the slice. Records created from
			// now on carry the jobs in the submission insert itself; this
			// backfill only ever touches records without the meta, so already
			// delivered state is never reset.
			$legacy = get_posts(
				array(
					'post_type'        => Freeplast_CQ_Request::POST_TYPE,
					'post_status'      => 'private',
					'posts_per_page'   => -1,
					'orderby'          => 'ID',
					'order'            => 'ASC',
					'no_found_rows'    => true,
					'suppress_filters' => true,
					'fields'           => 'ids',
				)
			);
			foreach ( $legacy as $id ) {
				if ( '' === (string) get_post_meta( (int) $id, Freeplast_CQ_Notifications::META_JOBS, true ) ) {
					update_post_meta( (int) $id, Freeplast_CQ_Notifications::META_JOBS, Freeplast_CQ_Notifications::initial_state_json() );
					Freeplast_CQ_Notifications::schedule_delivery( (string) get_post_meta( (int) $id, '_fpq_reference', true ) );
				}
			}
			$applied = 7;
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
	 * Replace a seeded shell page's content, but only when the recorded page
	 * still carries the exact legacy placeholder: a page edited by a human
	 * is never clobbered by a migration.
	 *
	 * @param array  $shell_pages    The fp_shell_pages option (slug => page ID).
	 * @param string $slug           Expected page slug.
	 * @param string $legacy_content Placeholder content that may be replaced.
	 * @param string $content        Replacement content.
	 */
	private static function take_over_page( array $shell_pages, string $slug, string $legacy_content, string $content ): void {
		if ( empty( $shell_pages[ $slug ] ) ) {
			return;
		}

		$page = get_post( (int) $shell_pages[ $slug ] );
		if (
			$page instanceof WP_Post &&
			'page' === $page->post_type &&
			$slug === $page->post_name &&
			$legacy_content === $page->post_content
		) {
			wp_update_post(
				array(
					'ID'           => $page->ID,
					'post_content' => $content,
				)
			);
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
