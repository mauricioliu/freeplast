<?php
/**
 * The Quote Request submission (issue #8).
 *
 * /cotizacion/ is the sole final submission surface: the customer reviews
 * the shared Quote Basket, enters the current Freeplast business fields and
 * submits once. The outcome is one durable, non-public Quote Request.
 *
 *   - The request form renders below the basket lines on /cotizacion/
 *     (inside the freeplast/basket block, outside the JS-mirrored basket
 *     view so typed values survive basket mutations). It is a plain POST
 *     to admin-post.php (action=fp_request_submit) guarded by its own
 *     nonce — fully functional without JavaScript; the same fields are
 *     revalidated server-side, always.
 *   - Required fields mirror the current Freeplast form: Nombre, Teléfono,
 *     Email, Nombre Empresa, Rut Empresa, Giro and Con Despacho (exactly
 *     Sí or No). Mensaje is optional and bounded. A manual Dirección de
 *     despacho appears and is required only while Con Despacho is Sí
 *     (the Google-assisted address enhancement arrives separately,
 *     issue #9); without dispatch the address is omitted entirely.
 *   - Product/options/quantities never arrive as request fields: they are
 *     read from the authenticated server basket session, re-resolved
 *     against the live Catalog (only currently published Products with
 *     their reviewed options submit) at submission time.
 *   - Invalid submissions retain every entered field plus the basket and
 *     return to /cotizacion/ with a focused, linked error summary and
 *     inline per-field errors (attempt values live in a short-lived
 *     transient keyed by the session hash — never in the URL or logs).
 *   - A successful submission persists exactly one fp_quote record
 *     (private, non-public, no REST) carrying the Submitted Details and
 *     immutable per-line Product snapshots (identity, title, option,
 *     quantity, commercial rules used, specs, canonical URL), then —
 *     only then — clears the basket and redirects to a confirmation
 *     carrying the permanent unique Request Reference (FP-YYYY-NNNNNN).
 *     A persistence failure (filter seam freeplast_cq_request_persist,
 *     or a failed insert) shows no success and retains the basket.
 *   - Idempotency: each rendered form carries a session-scoped random
 *     token; a submission with a token that already served a persisted
 *     request redirects back to that request's confirmation without
 *     creating a second record — refresh, back navigation and retry
 *     cannot duplicate a Quote Request.
 *   - A minimal capability-protected administration surface
 *     (Cotizaciones → fp-quotes / fp-quote, capability
 *     manage_freeplast_quotes granted to administrators by migration 6)
 *     makes the persisted record inspectable: Submitted Details, dispatch
 *     information and the immutable item snapshots. Since issue #9 the
 *     operational sales workflow (list sorting/search, current-contact
 *     corrections, Sales Notes, Request Status transitions, history) is
 *     owned by Freeplast_CQ_Admin, whose detail also renders — since the
 *     durable notifications slice (issue #10) — the per-channel
 *     notification delivery state with its safe staff resend
 *     (Freeplast_CQ_Notifications). No price, Quotation, Order, checkout
 *     or customer account is ever created.
 *
 * @package Freeplast_Catalog_Quotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Freeplast_CQ_Request {

	/** The non-public Quote Request record type (see TARGET.md). */
	public const POST_TYPE = 'fp_quote';

	/** Nonce action guarding the submission operation. */
	public const NONCE_ACTION = 'fp_request_submit';

	/** The dedicated sales capability protecting the admin surface. */
	public const CAPABILITY = 'manage_freeplast_quotes';

	/**
	 * Required single-line business fields: key => (label, max length,
	 * input type, autocomplete). Shared with the sales correction form
	 * (Freeplast_CQ_Admin) — one place to add a business field.
	 */
	public const TEXT_FIELDS = array(
		'nombre'   => array( 'label' => 'Nombre', 'max' => 120, 'type' => 'text', 'autocomplete' => 'name' ),
		'telefono' => array( 'label' => 'Teléfono', 'max' => 40, 'type' => 'tel', 'autocomplete' => 'tel' ),
		'email'    => array( 'label' => 'Email', 'max' => 190, 'type' => 'email', 'autocomplete' => 'email' ),
		'empresa'  => array( 'label' => 'Nombre Empresa', 'max' => 190, 'type' => 'text', 'autocomplete' => 'organization' ),
		'rut'      => array( 'label' => 'Rut Empresa', 'max' => 20, 'type' => 'text', 'autocomplete' => 'off' ),
		'giro'     => array( 'label' => 'Giro', 'max' => 190, 'type' => 'text', 'autocomplete' => 'off' ),
	);

	public const MAX_DIRECCION = 400;
	private const MAX_MENSAJE   = 2000;

	public static function register(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => 'Cotizaciones',
					'singular_name' => 'Cotización',
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_rest'        => false,
				'show_in_nav_menus'   => false,
				'exclude_from_search' => true,
				'hierarchical'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'supports'            => array( 'title' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);

		add_action( 'admin_post_fp_request_submit', array( self::class, 'handle_submit' ) );
		add_action( 'admin_post_nopriv_fp_request_submit', array( self::class, 'handle_submit' ) );

		/* The Cotizaciones administration surface is owned by
	   Freeplast_CQ_Admin since the issue #9 sales workflow slice. */
	}

	/* ------------------------------------------------------------------ */
	/* The authoritative submission                                        */
	/* ------------------------------------------------------------------ */

	public static function handle_submit(): void {
		/* 1. Nonce — every state change is nonce-guarded (recoverable, never a die page). */
		$nonce = isset( $_POST['fp_request_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['fp_request_nonce'] ) ) : '';
		if ( '' === $nonce || false === wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			self::fail( 'nonce' );
		}

		/* 2. Authenticated server basket session — the submission is bound to it. */
		$session = Freeplast_CQ_Basket::current_session();
		if ( null === $session ) {
			if ( ! empty( $_COOKIE[ Freeplast_CQ_Basket::COOKIE_NAME ] ) ) {
				Freeplast_CQ_Basket::clear_stale_cookie(); /* a presented dead cookie is cleared so a retry starts fresh */
			}
			self::fail( 'session' );
		}

		/* 3. Idempotency token — issued to this session by the rendered form. */
		$token = isset( $_POST['fp_request_token'] ) ? sanitize_text_field( wp_unslash( $_POST['fp_request_token'] ) ) : '';
		if ( 1 !== preg_match( '/^[0-9a-f]{32}$/', $token ) ) {
			self::fail( 'token' );
		}

		/* 4. Duplicate: this token already served a persisted request — never a second record. */
		$existing = self::find_by_token( $token );
		if ( null !== $existing ) {
			set_transient( self::confirm_key( $session['hash'] ), $existing, DAY_IN_SECONDS );
			self::redirect( array( 'fpcq_submitted' => $existing ) );
		}

		if ( get_transient( self::token_key( $session['hash'] ) ) !== $token ) {
			self::fail( 'token' );
		}

		/* 5. The request lines come from the authenticated basket only —
		   re-resolved against the live Catalog, so archived Products drop
		   out and options/quantities are the reviewed server state. */
		$lines = Freeplast_CQ_Basket::resolved_lines();
		if ( array() === $lines ) {
			self::fail( 'basket' );
		}

		/* 6. Customer fields — validated server-side, always. */
		$validated = self::validated_fields();
		if ( array() !== $validated['errors'] ) {
			self::store_attempt( $session['hash'], $validated['values'], $validated['errors'], '' );
			self::fail( 'request_invalid' );
		}
		$values = $validated['values'];

		/* 7. Persistence seam — a failing store never claims success and
		   never clears the basket (the values stay retained). */
		$failure = 'No pudimos guardar tu solicitud en este momento. Inténtalo de nuevo.';
		if ( ! apply_filters( 'freeplast_cq_request_persist', true, $values, $lines ) ) {
			self::store_attempt( $session['hash'], $values, array(), $failure );
			self::fail( 'request_failed' );
		}

		/* 8. Persist exactly one record with the immutable snapshots — and its
		   two durable notification jobs, in the very same insert: request and
		   jobs commit together or fail together (issue #10). */
		$reference = self::persist( $session, $token, $values, $lines );
		if ( null === $reference ) {
			self::store_attempt( $session['hash'], $values, array(), $failure );
			self::fail( 'request_failed' );
		}

		/* 9. Schedule the decoupled notification delivery (one sales
		   notification, one customer acknowledgement). Receipt is already
		   durable: a scheduling or transport failure changes nothing about
		   this confirmation — the jobs stay pending for retries and the
		   staff resend. */
		Freeplast_CQ_Notifications::schedule_delivery( $reference );

		/* 10. Success — only now is the basket cleared (the session stays
		   alive so the next visit does not look like an expiry). */
		Freeplast_CQ_Basket::clear_basket( $session );
		delete_transient( self::token_key( $session['hash'] ) );
		delete_transient( self::attempt_key( $session['hash'] ) );
		set_transient( self::confirm_key( $session['hash'] ), $reference, DAY_IN_SECONDS );

		self::redirect( array( 'fpcq_submitted' => $reference ) );
	}

	/**
	 * Validate the submitted customer fields. Returns the sanitized values
	 * (kept for retention even when invalid) and the per-field errors.
	 *
	 * @return array{values: array, errors: array}
	 */
	private static function validated_fields(): array {
		$values = self::empty_values();
		$errors = array();

		$text = static function ( string $key ): string {
			$name = 'fp_' . $key;
			return isset( $_POST[ $name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) : '';
		};
		$area = static function ( string $key ): string {
			$name = 'fp_' . $key;
			return isset( $_POST[ $name ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $name ] ) ) : '';
		};

		/* Required single-line fields. */
		foreach ( self::TEXT_FIELDS as $key => $field ) {
			$value = trim( $text( $key ) );
			if ( '' === $value ) {
				$errors[ $key ] = sprintf( '%s es obligatorio.', $field['label'] );
				continue;
			}
			if ( mb_strlen( $value ) > $field['max'] ) {
				$errors[ $key ] = sprintf( '%s es demasiado largo (máximo %d caracteres).', $field['label'], $field['max'] );
				continue;
			}
			$values[ $key ] = $value;
		}

		/* Email — standard validity checks. */
		if ( ! isset( $errors['email'] ) ) {
			$email = sanitize_email( $values['email'] );
			if ( false === is_email( $email ) ) {
				$errors['email'] = 'Ingresa un email válido.';
			} else {
				$values['email'] = $email;
			}
		}

		/* Telephone — accepts international formatting, keeps the entered text. */
		if ( ! isset( $errors['telefono'] ) && 1 !== preg_match( '/^\+?[0-9()\-\s.]{4,39}$/', $values['telefono'] ) ) {
			$errors['telefono'] = 'Ingresa un teléfono válido (por ejemplo +56 9 6844 4265).';
		}

		/* Rut Empresa — required business identity, format kept as entered. */
		if ( ! isset( $errors['rut'] ) && 1 !== preg_match( '/^[0-9kK.\-\s]+$/', $values['rut'] ) ) {
			$errors['rut'] = 'Ingresa un RUT válido (por ejemplo 76.335.888-6).';
		}

		/* Con Despacho — exactly Sí or No. */
		$despacho = $text( 'despacho' );
		if ( ! in_array( $despacho, array( 'si', 'no' ), true ) ) {
			$errors['despacho'] = 'Selecciona si necesitas despacho.';
		} else {
			$values['despacho'] = $despacho;
		}

		/* Dirección de despacho — required only with dispatch, omitted otherwise. */
		if ( 'si' === $values['despacho'] ) {
			$direccion = trim( $area( 'direccion' ) );
			if ( '' === $direccion ) {
				$errors['direccion'] = 'La dirección de despacho es obligatoria cuando solicitas despacho.';
			} elseif ( mb_strlen( $direccion ) > self::MAX_DIRECCION ) {
				$errors['direccion'] = sprintf( 'La dirección de despacho es demasiado larga (máximo %d caracteres).', self::MAX_DIRECCION );
			} else {
				$values['direccion'] = $direccion;
			}
		}

		/* Mensaje — optional and bounded. */
		$mensaje = $area( 'mensaje' );
		if ( mb_strlen( $mensaje ) > self::MAX_MENSAJE ) {
			$errors['mensaje'] = sprintf( 'El mensaje es demasiado largo (máximo %d caracteres).', self::MAX_MENSAJE );
		} else {
			$values['mensaje'] = $mensaje;
		}

		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}

	/** A normalized copy of the entered telephone when one is derivable. */
	public static function normalized_phone( string $entered ): string {
		$plus   = str_starts_with( $entered, '+' );
		$digits = preg_replace( '/\D+/', '', $entered );
		if ( null === $digits || 8 > strlen( $digits ) ) {
			return '';
		}
		return ( $plus ? '+' : '' ) . $digits;
	}

	/**
	 * The correctable current-contact copy (issue #9), derived from the
	 * Submitted Details: the contact fields under their stored keys plus
	 * the normalized telephone. One definition shared by the submission,
	 * the migration 7 backfill and the sales corrections.
	 */
	public static function current_contact_copy( array $customer ): array {
		return array(
			'nombre'               => (string) ( $customer['nombre'] ?? '' ),
			'telefono'             => (string) ( $customer['telefono'] ?? '' ),
			'telefono_normalizado' => self::normalized_phone( (string) ( $customer['telefono'] ?? '' ) ),
			'email'                => (string) ( $customer['email'] ?? '' ),
			'empresa'              => (string) ( $customer['empresa'] ?? '' ),
			'rut'                  => (string) ( $customer['rut'] ?? '' ),
			'giro'                 => (string) ( $customer['giro'] ?? '' ),
			'direccion_despacho'   => (string) ( $customer['direccion_despacho'] ?? '' ),
		);
	}

	/**
	 * Encode one fp_quote metadata value. Slashes and unicode stay
	 * unescaped so the stored form is stable: update_post_meta()
	 * unslashes scalar values, so escaped forms would not round-trip
	 * byte for byte.
	 */
	public static function encode_meta( array $value ): string {
		return (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/* ------------------------------------------------------------------ */
	/* Persistence                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Persist exactly one Quote Request: a private fp_quote record titled
	 * with its permanent reference, carrying the Submitted Details and the
	 * immutable item snapshots. Null when the insert fails.
	 */
	private static function persist( array $session, string $token, array $values, array $lines ): ?string {
		$customer = array(
			'nombre'               => $values['nombre'],
			'telefono'             => $values['telefono'],
			'telefono_normalizado' => self::normalized_phone( $values['telefono'] ),
			'email'                => $values['email'],
			'empresa'              => $values['empresa'],
			'rut'                  => $values['rut'],
			'giro'                 => $values['giro'],
			'con_despacho'         => $values['despacho'],
			'direccion_despacho'   => 'si' === $values['despacho'] ? $values['direccion'] : '',
			'mensaje'              => $values['mensaje'],
		);

		$items = array();
		foreach ( $lines as $line ) {
			$items[] = self::snapshot( $line );
		}


		/* Durable-job creation seam: the record and its notification jobs
		   are one unit — when the jobs cannot be created, the whole
		   persistence aborts (no record, no success, basket retained). */
		if ( ! apply_filters( 'freeplast_cq_notification_jobs', true, $values, $lines ) ) {
			return null;
		}

		/* The correctable current contact details start as a copy of the
		   submitted ones (issue #9); the denormalized empresa/email columns
		   feed the Cotizaciones list sort/search and follow corrections. */
		$current = self::current_contact_copy( $customer );

		$history = array(
			array(
				'type'  => 'created',
				'time'  => current_time( 'mysql' ),
				'staff' => 0,
			),
		);

		$idempotency = hash( 'sha256', $token );

		/* Retry with a fresh reference allocation: the sequence is derived,
		   never reserved, so a concurrent submission may consume it between
		   reading it and inserting. */
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$reference = self::next_reference();
			$post_id   = wp_insert_post(
				array(
					'post_type'   => self::POST_TYPE,
					'post_status' => 'private',
					'post_title'  => $reference,
					'post_author' => 0,
					'meta_input'  => array(
						'_fpq_reference'     => $reference,
						'_fpq_status'        => 'new',
						'_fpq_customer'      => self::encode_meta( $customer ),
						'_fpq_items'         => self::encode_meta( $items ),
						'_fpq_notifications' => Freeplast_CQ_Notifications::initial_state_json(),
						'_fpq_current'       => self::encode_meta( $current ),
						'_fpq_empresa'       => $current['empresa'],
						'_fpq_email'         => $current['email'],
						'_fpq_history'       => self::encode_meta( $history ),
						'_fpq_idempotency'   => $idempotency,
						'_fpq_session'       => $session['hash'],
					),
				),
				true
			);
			if ( is_int( $post_id ) && $post_id > 0 ) {
				return $reference;
			}
		}

		return null;
	}

	/**
	 * The immutable Product snapshot of one basket line: source/Product
	 * identity, canonical title, selected option, quantity, the commercial
	 * rules used at submission time, the relevant specifications and the
	 * canonical URL.
	 */
	private static function snapshot( array $line ): array {
		$product = $line['product'];
		$meta    = static function ( string $key ) use ( $product ): string {
			return (string) get_post_meta( $product->ID, $key, true );
		};

		$minimum = (int) $meta( '_fp_quote_min_qty' );
		$step    = (int) $meta( '_fp_quote_step' );

		return array(
			'source_id'    => $meta( '_fp_source_id' ),
			'post_id'      => $product->ID,
			'title'        => get_the_title( $product ),
			'option_id'    => $line['option_id'],
			'option_label' => $line['option_label'],
			'quantity'     => $line['quantity'],
			'minimum'      => $minimum > 0 ? $minimum : null,
			'step'         => $step > 0 ? $step : null,
			'material'     => $meta( '_fp_material' ),
			'dimensions'   => $meta( '_fp_dimensions' ),
			'weight'       => $meta( '_fp_weight_text' ),
			'url'          => (string) get_permalink( $product ),
		);
	}

	/**
	 * The next permanent Request Reference: FP-<year>-<NNNNNN>, allocated
	 * from the highest sequence already persisted for the year (internal
	 * database IDs are never exposed as business references).
	 */
	private static function next_reference(): string {
		$year   = (int) current_time( 'Y' );
		$prefix = sprintf( 'FP-%d-', $year );

		$posts = get_posts(
			array(
				'post_type'              => self::POST_TYPE,
				'post_status'            => 'private',
				'posts_per_page'         => 500,
				'orderby'                => 'ID',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'suppress_filters'       => true,
				'update_post_meta_cache' => true,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_fpq_reference',
						'value'   => $prefix,
						'compare' => 'LIKE',
					),
				),
			)
		);

		$highest = 0;
		foreach ( $posts as $id ) {
			$reference = (string) get_post_meta( (int) $id, '_fpq_reference', true );
			if ( 1 === preg_match( '/^FP-(\d{4})-(\d{6})$/', $reference, $matches ) && (int) $matches[1] === $year ) {
				$highest = max( $highest, (int) $matches[2] );
			}
		}

		return sprintf( 'FP-%d-%06d', $year, $highest + 1 );
	}

	/** The reference of the request a token already served, or null. */
	private static function find_by_token( string $token ): ?string {
		$posts = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'private',
				'posts_per_page'   => 1,
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'fields'           => 'ids',
				'meta_key'         => '_fpq_idempotency', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => hash( 'sha256', $token ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		if ( array() === $posts ) {
			return null;
		}
		return (string) get_post_meta( (int) $posts[0], '_fpq_reference', true );
	}

	/* ------------------------------------------------------------------ */
	/* Session-scoped state (expiring transients — never the URL)          */
	/* ------------------------------------------------------------------ */

	private static function token_key( string $hash ): string {
		return 'fpcq_reqtok_' . $hash;
	}

	private static function attempt_key( string $hash ): string {
		return 'fpcq_attempt_' . $hash;
	}

	private static function confirm_key( string $hash ): string {
		return 'fpcq_confirm_' . $hash;
	}

	/** The session's submission token (created on first form render). */
	private static function ensure_token( string $hash ): string {
		$token = get_transient( self::token_key( $hash ) );
		if ( ! is_string( $token ) || 1 !== preg_match( '/^[0-9a-f]{32}$/', $token ) ) {
			$token = bin2hex( random_bytes( 16 ) );
			set_transient( self::token_key( $hash ), $token, DAY_IN_SECONDS );
		}
		return $token;
	}

	private static function store_attempt( string $hash, array $values, array $errors, string $general ): void {
		set_transient(
			self::attempt_key( $hash ),
			array(
				'values'  => array_intersect_key( $values, self::empty_values() ),
				'errors'  => $errors,
				'general' => $general,
			),
			15 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * The retained attempt of one session: entered values, per-field errors
	 * and an optional general failure message (empty defaults otherwise).
	 */
	private static function read_attempt( string $hash ): array {
		$attempt = get_transient( self::attempt_key( $hash ) );
		if ( ! is_array( $attempt ) ) {
			return array(
				'values'  => self::empty_values(),
				'errors'  => array(),
				'general' => '',
			);
		}

		$defaults = self::empty_values();
		$values   = is_array( $attempt['values'] ?? null ) ? array_intersect_key( $attempt['values'], $defaults ) : array();
		$errors   = is_array( $attempt['errors'] ?? null ) ? $attempt['errors'] : array();

		return array(
			'values'  => array_merge( $defaults, $values ),
			'errors'  => $errors,
			'general' => (string) ( $attempt['general'] ?? '' ),
		);
	}

	private static function empty_values(): array {
		return array(
			'nombre'    => '',
			'telefono'  => '',
			'email'     => '',
			'empresa'   => '',
			'rut'       => '',
			'giro'      => '',
			'despacho'  => '',
			'direccion' => '',
			'mensaje'   => '',
		);
	}

	private static function fail( string $code ): void {
		$fragment = 'request_invalid' === $code ? '#fpcq-form-errors' : '';
		self::redirect( array( 'fpcq_notice' => $code ), $fragment );
	}

	private static function redirect( array $args, string $fragment = '' ): void {
		wp_safe_redirect( add_query_arg( $args, home_url( '/cotizacion/' ) ) . $fragment );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Rendering (versioned public fpcq- markup, v1)                       */
	/* ------------------------------------------------------------------ */

	/**
	 * The confirmation section: shown on /cotizacion/ after a successful
	 * submission, only for the session that owns it (the reference never
	 * renders from the URL alone).
	 */
	public static function render_confirmation(): string {
		$reference = isset( $_GET['fpcq_submitted'] ) ? sanitize_text_field( wp_unslash( $_GET['fpcq_submitted'] ) ) : '';
		if ( 1 !== preg_match( '/^FP-\d{4}-\d{6}$/', $reference ) ) {
			return '';
		}

		$session = Freeplast_CQ_Basket::current_session();
		if ( null === $session || get_transient( self::confirm_key( $session['hash'] ) ) !== $reference ) {
			return '';
		}

		return sprintf(
			'<section class="fpcq-confirmation" data-fpcq-version="1"><p class="fpcq-confirmation-kicker">Solicitud recibida</p><h2 class="fpcq-confirmation-title">Gracias. Tu solicitud de cotización fue enviada.</h2><p class="fpcq-confirmation-label">Guarda tu número de referencia:</p><p class="fpcq-confirmation-reference">%1$s</p><p class="fpcq-confirmation-note">Freeplast preparará tu cotización con los productos y cantidades que enviaste y te contactará al email y teléfono que registraste.</p><div class="fpcq-confirmation-actions"><a class="fpcq-confirmation-cta" href="%2$s">Volver a la tienda</a><a class="fpcq-confirmation-link" href="%3$s">Contactar a Freeplast</a></div></section>',
			esc_html( $reference ),
			esc_url( home_url( '/tienda/' ) ),
			esc_url( home_url( '/contacto/' ) )
		);
	}

	/**
	 * The request form: rendered below the basket lines on /cotizacion/ —
	 * the sole final submission surface. Nothing renders without basket
	 * lines. Products and quantities are never form fields; they come from
	 * the authenticated basket reviewed above the form.
	 */
	public static function render_form(): string {
		$lines   = Freeplast_CQ_Basket::resolved_lines();
		$session = Freeplast_CQ_Basket::current_session();
		if ( array() === $lines || null === $session ) {
			return '';
		}

		$attempt    = self::read_attempt( $session['hash'] );
		$values     = $attempt['values'];
		$errors     = $attempt['errors'];
		$token      = self::ensure_token( $session['hash'] );
		$dispatched = 'si' === $values['despacho'];

		$fields = '';
		foreach ( array_keys( self::TEXT_FIELDS ) as $key ) {
			$fields .= self::render_text_field( $key, $values[ $key ], $errors[ $key ] ?? null );
		}
		$fields .= self::render_despacho( $values['despacho'], $errors['despacho'] ?? null );
		$fields .= self::render_direccion( $values['direccion'], $errors['direccion'] ?? null, $dispatched );
		$fields .= self::render_mensaje( $values['mensaje'], $errors['mensaje'] ?? null );

		return sprintf(
			'<section class="fpcq-request" data-fpcq-version="1" data-fpcq-request-form><h2 class="fpcq-request-title">Envía tu solicitud</h2><p class="fpcq-request-intro">Completa tus datos para que Freeplast prepare tu cotización. Los productos y cantidades provienen de la cotización que revisaste arriba.</p><form class="fpcq-request-form" method="post" action="%1$s">%2$s<div class="fpcq-form-grid">%3$s</div><input type="hidden" name="action" value="fp_request_submit"><input type="hidden" name="fp_request_token" value="%4$s"><input type="hidden" name="_wp_http_referer" value="%5$s"><input type="hidden" name="fp_request_nonce" value="%6$s"><button class="fpcq-request-submit" type="submit">Enviar solicitud</button><p class="fpcq-request-privacy">Al enviar aceptas que Freeplast use estos datos únicamente para preparar y responder tu solicitud. Más información en la <a href="%7$s">Política de privacidad</a>.</p></form></section>',
			esc_url( admin_url( 'admin-post.php' ) ),
			self::render_summary( $errors, $attempt['general'] ),
			$fields,
			esc_attr( $token ),
			esc_url( home_url( '/cotizacion/' ) ),
			esc_attr( wp_create_nonce( self::NONCE_ACTION ) ),
			esc_url( home_url( '/politica-de-privacidad/' ) )
		);
	}

	/** Focused linked error summary + optional general failure message. */
	private static function render_summary( array $errors, string $general ): string {
		if ( array() === $errors && '' === $general ) {
			return '';
		}

		$items = '';
		foreach ( $errors as $key => $message ) {
			$items .= sprintf( '<li><a href="#fp-%1$s">%2$s</a></li>', esc_attr( (string) $key ), esc_html( (string) $message ) );
		}

		return sprintf(
			'<div class="fpcq-form-errors" id="fpcq-form-errors" role="alert" tabindex="-1"><p class="fpcq-form-errors-title">%s</p><ul>%s</ul>%s</div>',
			'Revisa tu solicitud antes de enviarla:',
			$items,
			'' !== $general ? sprintf( '<p class="fpcq-form-errors-general">%s</p>', esc_html( $general ) ) : ''
		);
	}

	/** One required single-line field with its inline error. */
	private static function render_text_field( string $key, string $value, ?string $error ): string {
		$field     = self::TEXT_FIELDS[ $key ];
		$described = null === $error ? '' : sprintf( ' aria-describedby="fp-%s-error" aria-invalid="true"', $key );
		$inline    = null === $error ? '' : sprintf( '<p class="fpcq-field-error" id="fp-%s-error">%s</p>', esc_attr( $key ), esc_html( $error ) );

		return sprintf(
			'<div class="fpcq-field%1$s"><label class="fpcq-field-label" for="fp-%2$s">%3$s</label><input class="fpcq-input" type="%4$s" id="fp-%2$s" name="fp_%2$s" value="%5$s" maxlength="%6$d" autocomplete="%7$s" required%8$s>%9$s</div>',
			null === $error ? '' : ' fpcq-field-invalid',
			esc_attr( $key ),
			esc_html( $field['label'] ),
			esc_attr( $field['type'] ),
			esc_attr( $value ),
			$field['max'],
			esc_attr( $field['autocomplete'] ),
			$described,
			$inline
		);
	}

	/** Con Despacho — exactly one of Sí/No (required radio group). */
	private static function render_despacho( string $value, ?string $error ): string {
		$radios = '';
		foreach ( array( 'si' => 'Sí', 'no' => 'No' ) as $key => $label ) {
			$radios .= sprintf(
				'<label class="fpcq-choice"><input type="radio" name="fp_despacho" value="%s"%s required><span>%s</span></label>',
				esc_attr( $key ),
				checked( $value, $key, false ),
				esc_html( $label )
			);
		}

		$inline = null === $error ? '' : sprintf( '<p class="fpcq-field-error" id="fp-despacho-error">%s</p>', esc_html( $error ) );

		return sprintf(
			'<fieldset class="fpcq-field fpcq-field-despacho%s" id="fp-despacho"><legend class="fpcq-field-label">%s</legend><div class="fpcq-choices">%s</div>%s</fieldset>',
			null === $error ? '' : ' fpcq-field-invalid',
			'Con despacho',
			$radios,
			$inline
		);
	}

	/**
	 * Dirección de despacho — present and required only while Con Despacho
	 * is Sí (revealed progressively; without JavaScript a Sí submission
	 * round-trips once through validation, which re-renders it visible).
	 */
	private static function render_direccion( string $value, ?string $error, bool $dispatched ): string {
		$inline = null === $error ? '' : sprintf( '<p class="fpcq-field-error" id="fp-direccion-error">%s</p>', esc_html( $error ) );

		$class = 'fpcq-field fpcq-field-wide';
		if ( null !== $error ) {
			$class .= ' fpcq-field-invalid';
		}
		if ( ! $dispatched ) {
			$class .= ' fpcq-hidden';
		}

		return sprintf(
			'<div class="%1$s" data-fpcq-address-field><label class="fpcq-field-label" for="fp-direccion">Dirección de despacho</label><textarea class="fpcq-textarea" id="fp-direccion" name="fp_direccion" rows="2" maxlength="%2$d"%3$s%4$s>%5$s</textarea>%6$s</div>',
			esc_attr( $class ),
			self::MAX_DIRECCION,
			$dispatched ? ' required' : '',
			null === $error ? '' : ' aria-describedby="fp-direccion-error" aria-invalid="true"',
			esc_textarea( $value ),
			$inline
		);
	}

	/** Mensaje — optional, bounded. */
	private static function render_mensaje( string $value, ?string $error ): string {
		$inline = null === $error ? '' : sprintf( '<p class="fpcq-field-error" id="fp-mensaje-error">%s</p>', esc_html( $error ) );

		return sprintf(
			'<div class="fpcq-field fpcq-field-wide%s"><label class="fpcq-field-label" for="fp-mensaje">Mensaje <span class="fpcq-optional">(opcional)</span></label><textarea class="fpcq-textarea" id="fp-mensaje" name="fp_mensaje" rows="3" maxlength="%d"%s>%s</textarea>%s</div>',
			null === $error ? '' : ' fpcq-field-invalid',
			self::MAX_MENSAJE,
			null === $error ? '' : ' aria-describedby="fp-mensaje-error" aria-invalid="true"',
			esc_textarea( $value ),
			$inline
		);
	}
}
