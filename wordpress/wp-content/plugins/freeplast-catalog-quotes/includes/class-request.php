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
 *     Sí or No). Mensaje is optional and bounded. The Dirección de
 *     despacho appears and is required only while Con Despacho is Sí —
 *     with the Google-assisted confirmation and Dispatch Distance of
 *     issue #11 (Freeplast_CQ_Address) the confirmed destination is the
 *     dispatch address and the manual field is the fallback; without
 *     dispatch the address is omitted entirely.
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
 *   - Abuse resistance (issue #13) without a CAPTCHA: the form carries
 *     an off-screen honeypot field (a filled decoy is automated spam —
 *     rejected before anything else mutates), a plausible minimum
 *     completion time (the server-side render time of the idempotency
 *     token — a submission faster than a human fill is rejected
 *     recoverably with the values and basket retained) and bounded
 *     throttling (a rolling cap of persisted requests per session inside
 *     an hour, keyed by the opaque session hash — never a raw IP or
 *     email — where ordinary retries never count: invalid attempts,
 *     idempotent replays and failed persistences increment nothing).
 *   - Idempotency: each rendered form carries a session-scoped random
 *     token; a submission with a token that already served a persisted
 *     request redirects back to that request's confirmation without
 *     creating a second record — refresh, back navigation and retry
 *     cannot duplicate a Quote Request (issue #8). The recovery is bound
 *     to the submitting session (issue #24): a copied token in another
 *     session recovers nothing. Two truly concurrent POSTs of the same
 *     attempt (two tabs, a retry fired while the first is in flight) are
 *     serialized by an atomic per-attempt claim, so exactly one record,
 *     one reference and one set of receipt-notification jobs ever exist;
 *     the losing POST recovers the winner's confirmation (or fails
 *     recoverably with basket and values retained while the winner is
 *     still in flight) and a later resubmission of the same attempt
 *     always recovers the original confirmation.
 *   - A minimal capability-protected administration surface
 *     (Cotizaciones → fp-quotes / fp-quote, capability
 *     manage_freeplast_quotes granted to administrators by migration 6)
 *     makes the persisted record inspectable: Submitted Details, dispatch
 *     information, the immutable item snapshots and — for dispatch
 *     requests — the internal Dispatch Distance with its staff retry
 *     (issue #11). Since issue #9 the operational sales workflow (list
 *     sorting/search, current-contact corrections, Sales Notes, Request
 *     Status transitions, history) is owned by Freeplast_CQ_Admin, whose
 *     detail also renders — since the durable notifications slice
 *     (issue #10) — the per-channel notification delivery state with its
 *     safe staff resend (Freeplast_CQ_Notifications). No price,
 *     Quotation, Order, checkout or customer account is ever created.
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

	/** Shared maximum length of the manual Dirección de despacho (rendered by Freeplast_CQ_Address). */
	public const MAX_DIRECCION = 400;
	private const MAX_MENSAJE   = 2000;

	/** Plausible minimum seconds between the form render and a human submission. */
	public const MIN_COMPLETION_SECONDS = 2;

	/** Persisted requests allowed per session inside the rolling throttle window. */
	public const RATE_LIMIT = 5;

	/** The throttle window (one expiring counter per opaque session hash — never raw PII). */
	private const RATE_WINDOW = HOUR_IN_SECONDS;

	/** Options-table key prefix of the atomic per-attempt claims (issue #24). */
	private const CLAIM_PREFIX = 'fpcq_claim_';

	/** How long a concurrent POST of an in-flight attempt waits for the owner before failing recoverably. */
	private const CLAIM_WAIT_SECONDS = 3;

	/** The poll interval of that wait. */
	private const CLAIM_POLL_MICROSECONDS = 100000;

	/** How long an unfinalized claim may sit before the same session may take it over (a winner died mid-flight). */
	private const CLAIM_TAKEOVER_SECONDS = 30;

	/** Claim rows older than this are swept by the daily housekeeping event; the record meta stays the durable layer. */
	private const CLAIM_MAX_AGE = 7 * DAY_IN_SECONDS;

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

		/* Attempt-claim housekeeping rides the existing daily sweep event
		   (issue #24): the claims only serve the short concurrent window. */
		add_action( Freeplast_CQ_Basket::GC_EVENT, array( self::class, 'sweep_claims' ) );

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

		/* 1b. Honeypot — the off-screen decoy field stays empty for humans; a
		   filled one is automated spam and mutates nothing. */
		$decoy = isset( $_POST['fp_referencia'] ) ? sanitize_text_field( wp_unslash( $_POST['fp_referencia'] ) ) : '';
		if ( '' !== $decoy ) {
			self::fail( 'spam' );
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

		/* 4. Duplicate: this token already served a persisted request — never a
		   second record. The recovery is bound to the submitting session
		   (issue #24): the record remembers its owning session hash, so a
		   copied token presented by another session recovers nothing (the
		   flow below rejects it as an unknown token) and no reference or
		   confirmation ever crosses sessions. */
		$existing = self::find_by_token( $token, $session['hash'] );
		if ( null !== $existing ) {
			self::recover( $session['hash'], $existing );
		}

		$issued = self::issued_token( $session['hash'] );
		if ( null === $issued || $issued['token'] !== $token ) {
			self::fail( 'token' );
		}

		/* 5. The request lines come from the authenticated basket only —
		   re-resolved against the live Catalog, so archived Products drop
		   out and options/quantities are the reviewed server state. */
		$lines = Freeplast_CQ_Basket::resolved_lines();
		if ( array() === $lines ) {
			self::fail( 'basket' );
		}

		/* 6. Customer fields — validated server-side, always. A confirmed
		   Google destination (issue #11) stands in for the manual address. */
		$validated = self::validated_fields( Freeplast_CQ_Address::confirmed( $session['hash'] ) );
		if ( array() !== $validated['errors'] ) {
			self::store_attempt( $session['hash'], $validated['values'], $validated['errors'], '' );
			self::fail( 'request_invalid' );
		}
		$values    = $validated['values'];
		$confirmed = $validated['confirmed'];

		/* 6b. Plausible minimum completion time: the token records when this
		   form instance was rendered; a faster-than-human fill is rejected
		   recoverably (values and basket retained) — an ordinary retry a
		   moment later succeeds. */
		if ( ( time() - $issued['started'] ) < self::MIN_COMPLETION_SECONDS ) {
			self::store_attempt( $session['hash'], $values, array(), 'Tómate un momento para completar el formulario y vuelve a enviarlo.' );
			self::fail( 'too_fast' );
		}

		/* 6c. Bounded throttling: too many persisted requests from one session
		   inside the window is abuse. Only successful persistences count, so
		   invalid attempts, idempotent replays and failed persistences never
		   block an ordinary retry; the counter is keyed by the opaque session
		   hash and expires with the window. */
		if ( self::throttled( $session['hash'] ) ) {
			self::store_attempt( $session['hash'], $values, array(), 'Has enviado varias solicitudes en poco tiempo. Espera un momento antes de enviar otra.' );
			self::fail( 'throttled' );
		}

		/* 7. Persistence seam — a failing store never claims success and
		   never clears the basket (the values stay retained). */
		$failure = 'No pudimos guardar tu solicitud en este momento. Inténtalo de nuevo.';
		if ( ! apply_filters( 'freeplast_cq_request_persist', true, $values, $lines ) ) {
			self::store_attempt( $session['hash'], $values, array(), $failure );
			self::fail( 'request_failed' );
		}


		/* 8. Persist exactly one record with the immutable snapshots (the
		   confirmed destination is stored with it, issue #11) — and its
		   two durable notification jobs, in the very same insert: request and
		   jobs commit together or fail together (issue #10).

		   The attempt itself is claimed atomically first (issue #24): two
		   concurrent POSTs of the same form must never both reach the
		   insert. The claim is a plain INSERT into the options table keyed
		   by the attempt's idempotency hash — the unique option_name
		   rejects the second insert on either database engine, so only the
		   owner persists; a racing POST of the same attempt recovers the
		   owner's confirmation instead of persisting a second copy of the
		   lines. */
		$idempotency = hash( 'sha256', $token );
		$claim = self::claim_attempt( $idempotency, $session['hash'] );
		if ( 'recovered' === $claim['state'] ) {
			self::recover( $session['hash'], $claim['reference'] );
		}
		if ( 'owned' !== $claim['state'] ) {
			/* The owner is still in flight ('busy') or holds a foreign
			   session ('foreign'): nothing was persisted, so the recoverable
			   failure retains the values and the basket — the customer's
			   resubmission of this attempt recovers the original request. */
			self::store_attempt( $session['hash'], $values, array(), $failure );
			self::fail( 'request_failed' );
		}

		$stored = self::persist( $session, $idempotency, $values, $lines, $confirmed );
		if ( null === $stored ) {
			self::release_claim( $idempotency );
			self::store_attempt( $session['hash'], $values, array(), $failure );
			self::fail( 'request_failed' );
		}
		self::finalize_claim( $idempotency, $stored['reference'] );

		/* 8b. Dispatch Distance (issue #11): calculated after durable
		   persistence — a provider failure records a pending/error state
		   and never rejects the request. */
		if ( 'si' === $values['despacho'] ) {
			Freeplast_CQ_Address::calculate_and_store( $stored['id'] );
		}

		/* 9. Schedule the decoupled notification delivery (one sales
		   notification, one customer acknowledgement). Receipt is already
		   durable: a scheduling or transport failure changes nothing about
		   this confirmation — the jobs stay pending for retries and the
		   staff resend. */
		Freeplast_CQ_Notifications::schedule_delivery( $stored['reference'] );

		/* 10. Success — only now is the basket cleared (the session stays
		   alive so the next visit does not look like an expiry). */
		Freeplast_CQ_Basket::clear_basket( $session );
		delete_transient( self::token_key( $session['hash'] ) );
		delete_transient( self::attempt_key( $session['hash'] ) );
		Freeplast_CQ_Address::clear_session_state( $session['hash'] );
		set_transient( self::confirm_key( $session['hash'] ), $stored['reference'], DAY_IN_SECONDS );

		/* The throttle counter sees only durable persistences. */
		self::count_persist( $session['hash'] );

		self::redirect( array( 'fpcq_submitted' => $stored['reference'] ) );
	}

	/**
	 * The shared contact-format validation (issue #18): the acceptance
	 * rules and user-facing messages of the text fields that carry a
	 * format check — Email, Teléfono and Rut Empresa — are defined here
	 * exactly once and applied by both surfaces, the Quote Request intake
	 * (validated_fields) and the sales contact correction
	 * (Freeplast_CQ_Admin::validated_contact), so the outcomes and the
	 * messages for the same input are identical by construction.
	 *
	 * Skips fields already carrying an earlier (required/length) error —
	 * a missing value in $values implies its error is present. The valid
	 * email is stored in its WordPress-sanitized form; Teléfono and Rut
	 * Empresa keep the entered text.
	 *
	 * @param array $values Validated text values.
	 * @param array $errors Per-field errors collected so far.
	 * @return array{values: array, errors: array}
	 */
	public static function validated_contact_formats( array $values, array $errors ): array {
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

		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}

	/**
	 * Validate the submitted customer fields. Returns the sanitized values
	 * (kept for retention even when invalid), the per-field errors and the
	 * confirmed destination standing in for the manual address (null when
	 * none is confirmed).
	 *
	 * @return array{values: array, errors: array, confirmed: array|null}
	 */
	private static function validated_fields( ?array $confirmed ): array {
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

		/* Email, Teléfono and Rut Empresa — the shared contact-format
		   rules (issue #18), one definition for both surfaces. */
		$formats = self::validated_contact_formats( $values, $errors );
		$values  = $formats['values'];
		$errors  = $formats['errors'];

		/* Con Despacho — exactly Sí or No. */
		$despacho = $text( 'despacho' );
		if ( ! in_array( $despacho, array( 'si', 'no' ), true ) ) {
			$errors['despacho'] = 'Selecciona si necesitas despacho.';
		} else {
			$values['despacho'] = $despacho;
		}

		/* Dirección de despacho — required only with dispatch and without a
		   confirmed destination, omitted entirely without dispatch (the
		   confirmed Google destination is the dispatch address, issue #11;
		   the manual field is the rural/unrecognized fallback). */
		if ( 'si' === $values['despacho'] ) {
			$direccion = trim( $area( 'direccion' ) );
			if ( null === $confirmed && '' === $direccion ) {
				$errors['direccion'] = 'La dirección de despacho es obligatoria cuando solicitas despacho (confírmala con Google o escríbela manualmente).';
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
			'values'    => $values,
			'errors'    => $errors,
			'confirmed' => $confirmed,
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

	/* ------------------------------------------------------------------ */
	/* Persistence                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Persist exactly one Quote Request: a private fp_quote record titled
	 * with its permanent reference, carrying the Submitted Details, the
	 * immutable item snapshots and the dispatch destination (issue #11).
	 * Null when the insert fails.
	 *
	 * @param string $idempotency The attempt's idempotency hash (issue #24 —
	 *                            derived once in handle_submit and shared with
	 *                            the atomic attempt claim).
	 * @return array{id: int, reference: string}|null
	 */
	private static function persist( array $session, string $idempotency, array $values, array $lines, ?array $confirmed ): ?array {
		/* The dispatch destination: the customer-confirmed Google result,
		   or the manual fallback text (null without dispatch). Provider
		   terms: place ids are stored without limitation; the formatted
		   address and coordinates are the operational delivery record. */
		$destination = null;
		if ( 'si' === $values['despacho'] ) {
			if ( null !== $confirmed ) {
				$destination = array(
					'mode'         => 'google',
					'address'      => (string) $confirmed['formatted'],
					'place_id'     => (string) ( $confirmed['place_id'] ?? '' ),
					'lat'          => isset( $confirmed['lat'] ) && is_numeric( $confirmed['lat'] ) ? (float) $confirmed['lat'] : null,
					'lng'          => isset( $confirmed['lng'] ) && is_numeric( $confirmed['lng'] ) ? (float) $confirmed['lng'] : null,
					'provider'     => 'google',
					'confirmed_at' => (string) ( $confirmed['confirmed_at'] ?? '' ),
				);
			} else {
				$destination = array(
					'mode'         => 'manual',
					'address'      => $values['direccion'],
					'place_id'     => '',
					'lat'          => null,
					'lng'          => null,
					'provider'     => '',
					'confirmed_at' => '',
				);
			}
		}

		$customer = array(
			'nombre'               => $values['nombre'],
			'telefono'             => $values['telefono'],
			'telefono_normalizado' => self::normalized_phone( $values['telefono'] ),
			'email'                => $values['email'],
			'empresa'              => $values['empresa'],
			'rut'                  => $values['rut'],
			'giro'                 => $values['giro'],
			'con_despacho'         => $values['despacho'],
			'direccion_despacho'   => null !== $destination ? $destination['address'] : '',
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

		$meta = array(
			'_fpq_status'        => 'new',
			'_fpq_customer'      => Freeplast_CQ_Codec::encode( $customer ),
			'_fpq_items'         => Freeplast_CQ_Codec::encode( $items ),
			'_fpq_notifications' => Freeplast_CQ_Notifications::initial_state_json(),
			'_fpq_current'       => Freeplast_CQ_Codec::encode( $current ),
			'_fpq_empresa'       => $current['empresa'],
			'_fpq_email'         => $current['email'],
			'_fpq_history'       => Freeplast_CQ_Codec::encode( $history ),
			'_fpq_idempotency'   => $idempotency,
			'_fpq_session'       => $session['hash'],
		);
		if ( null !== $destination ) {
			/* Dispatch-only data: requests without despacho carry no destination. */
			$meta['_fpq_destination'] = Freeplast_CQ_Codec::encode( $destination );
		}

		/* Retry with a fresh reference allocation: the sequence is derived,
		   never reserved, so a concurrent submission may consume it between
		   reading it and inserting. */
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$reference = self::next_reference();
			$meta['_fpq_reference'] = $reference;
			$post_id   = wp_insert_post(
				array(
					'post_type'   => self::POST_TYPE,
					'post_status' => 'private',
					'post_title'  => $reference,
					'post_author' => 0,
					'meta_input'  => $meta,
				),
				true
			);
			if ( is_int( $post_id ) && $post_id > 0 ) {
				return array(
					'id'        => $post_id,
					'reference' => $reference,
				);
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

	/**
	 * The reference of the request a token already served to the presenting
	 * session, or null. The lookup is bound to the owning session (issue
	 * #24): the record remembers the session hash that submitted it, so a
	 * token copied to another session resolves as unknown instead of
	 * handing over the foreign reference.
	 */
	private static function find_by_token( string $token, string $hash ): ?string {
		return self::find_by_idempotency( hash( 'sha256', $token ), $hash );
	}

	/**
	 * The reference the idempotency hash already produced for this session,
	 * or null (absent, or owned by a different session — issue #24).
	 */
	private static function find_by_idempotency( string $idempotency, string $hash ): ?string {
		$posts = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'private',
				'posts_per_page'   => 1,
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'fields'           => 'ids',
				'meta_key'         => '_fpq_idempotency', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $idempotency, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		if ( array() === $posts ) {
			return null;
		}
		$post_id = (int) $posts[0];
		if ( ! hash_equals( (string) get_post_meta( $post_id, '_fpq_session', true ), $hash ) ) {
			return null;
		}
		return (string) get_post_meta( $post_id, '_fpq_reference', true );
	}

	/* ------------------------------------------------------------------ */
	/* The atomic per-attempt claim (issue #24)                            */
	/* ------------------------------------------------------------------ */

	/** The options-table claim key of one attempt. */
	private static function claim_key( string $idempotency ): string {
		return self::CLAIM_PREFIX . $idempotency;
	}

	/**
	 * Claim one submission attempt atomically: two concurrent POSTs of the
	 * same form (two tabs, a retry fired while the first is in flight) must
	 * produce exactly one Quote Request. The claim is a single options row
	 * keyed by the attempt's idempotency hash, inserted as a plain INSERT:
	 * the unique option_name rejects the second insert on either database
	 * engine, so exactly one concurrent request owns the attempt and the
	 * loser never reaches the record insert. The option API is bypassed on
	 * purpose here — add_option() writes an upsert (ON DUPLICATE KEY
	 * UPDATE) and answers later reads from the per-request cache, either of
	 * which would hide a defeat.
	 *
	 * The loser waits a bounded moment for the owner to finalize and then
	 * recovers the winner's confirmation ('recovered'); when the owner
	 * holds a foreign session ('foreign') or is still in flight past the
	 * wait budget ('busy') nothing is persisted and the submission fails
	 * recoverably. An empty claim older than the takeover grace period
	 * belongs to a winner that died mid-flight: the same session first
	 * recovers a record that landed without its finalization, else resumes
	 * the attempt itself (a deliberate update_option — unreachable for
	 * genuinely concurrent requests, which arrive seconds apart), so a
	 * crashed request can never wedge the token.
	 *
	 * @return array{state: 'owned'|'recovered'|'foreign'|'busy', reference?: string}
	 */
	private static function claim_attempt( string $idempotency, string $hash ): array {
		$key     = self::claim_key( $idempotency );
		$attempt = static function () use ( $hash ): array {
			return array(
				'session'   => $hash,
				'reference' => '',
				'started'   => time(),
			);
		};
		$give_up_at = microtime( true ) + self::CLAIM_WAIT_SECONDS;

		while ( true ) {
			if ( self::insert_claim_row( $key, $attempt() ) ) {
				return array( 'state' => 'owned' );
			}

			$held = self::read_claim_row( $key );
			if ( is_array( $held ) ) {
				$ours      = hash_equals( (string) ( $held['session'] ?? '' ), $hash );
				$reference = (string) ( $held['reference'] ?? '' );
				if ( '' !== $reference ) {
					return $ours
						? array(
							'state'     => 'recovered',
							'reference' => $reference,
						)
						: array( 'state' => 'foreign' );
				}
				if ( $ours && ( (int) ( $held['started'] ?? 0 ) + self::CLAIM_TAKEOVER_SECONDS ) < time() ) {
					$landed = self::find_by_idempotency( $idempotency, $hash );
					if ( null !== $landed ) {
						self::finalize_claim( $idempotency, $landed );
						return array(
							'state'     => 'recovered',
							'reference' => $landed,
						);
					}
					update_option( $key, $attempt(), false );
					return array( 'state' => 'owned' );
				}
			}
			if ( microtime( true ) >= $give_up_at ) {
				return array( 'state' => 'busy' );
			}
			usleep( self::CLAIM_POLL_MICROSECONDS );
		}
	}

	/**
	 * The atomic test-and-set of one attempt claim: the plain INSERT fails
	 * against the options table's unique option_name when the attempt is
	 * already claimed. The expected duplicate-key error of the losing
	 * request is suppressed — the defeat is information, not a fault.
	 */
	private static function insert_claim_row( string $key, array $claim ): bool {
		global $wpdb;
		$suppress = $wpdb->suppress_errors();
		$wpdb->suppress_errors( true );
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )",
				$key,
				maybe_serialize( $claim )
			)
		);
		$wpdb->suppress_errors( $suppress );
		return false !== $result && null !== $result;
	}

	/**
	 * The stored claim row of one attempt, read straight from the database —
	 * the per-request options cache would answer with the reader's own
	 * write, and the inspection must see the stored row.
	 *
	 * @return array|null The decoded claim, or null when the row is absent.
	 */
	private static function read_claim_row( string $key ): ?array {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );
		if ( null === $raw || false === $raw || '' === $raw ) {
			return null;
		}
		$claim = maybe_unserialize( (string) $raw );
		return is_array( $claim ) ? $claim : null;
	}

	/** Record the persisted reference on the attempt claim (the recovery data a concurrent retry reads). */
	private static function finalize_claim( string $idempotency, string $reference ): void {
		$key  = self::claim_key( $idempotency );
		$held = get_option( $key );
		if ( is_array( $held ) && '' === (string) ( $held['reference'] ?? '' ) ) {
			$held['reference'] = $reference;
			update_option( $key, $held, false );
		}
	}

	/** Release an unfinalized claim (the persistence failed) so a retry of the attempt starts clean. */
	private static function release_claim( string $idempotency ): void {
		delete_option( self::claim_key( $idempotency ) );
	}

	/**
	 * Collect attempt claims older than a week (runs with the daily basket
	 * sweep): the durable idempotency binding lives on the record meta, so
	 * the claim rows only serve the short concurrent window and their
	 * namespace stays bounded.
	 *
	 * @return int Deleted claim rows.
	 */
	public static function sweep_claims(): int {
		global $wpdb;
		$names = $wpdb->get_col(
			$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::CLAIM_PREFIX ) . '%' )
		);
		$swept = 0;
		foreach ( $names as $name ) {
			$claim = get_option( (string) $name );
			if ( is_array( $claim ) && ( (int) ( $claim['started'] ?? 0 ) < time() - self::CLAIM_MAX_AGE ) ) {
				$swept += delete_option( (string) $name ) ? 1 : 0;
			}
		}
		return $swept;
	}

	/**
	 * The confirmation recovery (issue #24): the retrying client of an
	 * already-persisted attempt is sent back to the original request's
	 * confirmation — the reference never crosses sessions because every
	 * recovery path verified the owning session hash first.
	 */
	private static function recover( string $hash, string $reference ): void {
		set_transient( self::confirm_key( $hash ), $reference, DAY_IN_SECONDS );
		self::redirect( array( 'fpcq_submitted' => $reference ) );
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

	private static function rate_key( string $hash ): string {
		return 'fpcq_rate_' . $hash;
	}

	/** True when this session already persisted the cap of requests inside the rolling window. */
	private static function throttled( string $hash ): bool {
		return (int) get_transient( self::rate_key( $hash ) ) >= self::RATE_LIMIT;
	}

	/** Count one durable persistence against the session's rolling cap (bounded: one expiring transient). */
	private static function count_persist( string $hash ): void {
		$key = self::rate_key( $hash );
		set_transient( $key, (int) get_transient( $key ) + 1, self::RATE_WINDOW );
	}

	/**
	 * The session's issued form token with its server-side render time
	 * (the completion-time reference), or null when none is valid.
	 *
	 * @return array{token: string, started: int}|null
	 */
	private static function issued_token( string $hash ): ?array {
		$stored = get_transient( self::token_key( $hash ) );
		if ( is_array( $stored ) && 1 === preg_match( '/^[0-9a-f]{32}$/', (string) ( $stored['token'] ?? '' ) ) ) {
			return array(
				'token'   => (string) $stored['token'],
				'started' => (int) ( $stored['started'] ?? 0 ),
			);
		}
		return null;
	}

	/** The session's submission token (created with its render time on first form render). */
	private static function ensure_token( string $hash ): string {
		$issued = self::issued_token( $hash );
		if ( null === $issued || 0 === $issued['started'] ) {
			$issued = array(
				'token'   => bin2hex( random_bytes( 16 ) ),
				'started' => time(),
			);
			set_transient( self::token_key( $hash ), $issued, DAY_IN_SECONDS );
		}
		return $issued['token'];
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
		/* Recoverable, value-retaining failures focus the summary so a
		   keyboard user lands on the explanation. */
		$focused = in_array( $code, array( 'request_invalid', 'too_fast', 'throttled' ), true );
		$fragment = $focused ? '#fpcq-form-errors' : '';
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
		/* Dirección de despacho — the dispatch-conditional block rendered by
		   Freeplast_CQ_Address (issue #11): Google-assisted confirmation with
		   the manual fallback, hidden without dispatch and revealed
		   progressively (the server stays the authority). */
		$fields .= Freeplast_CQ_Address::render_address_block( $values['direccion'], $errors['direccion'] ?? null, $dispatched, $session['hash'] );
		$fields .= self::render_mensaje( $values['mensaje'], $errors['mensaje'] ?? null );
		$fields .= self::render_honeypot();

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

	/**
	 * The off-screen honeypot (issue #13): visually and programmatically
	 * hidden from humans (off-screen inline styles keep it self-contained
	 * under any theme, including a stock block theme); a filled field is
	 * automated spam and the submission is rejected before any mutation.
	 */
	private static function render_honeypot(): string {
		return '<div class="fpcq-hp" aria-hidden="true" style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;"><label for="fp-referencia">No completar este campo</label><input type="text" id="fp-referencia" name="fp_referencia" value="" tabindex="-1" autocomplete="off"></div>';
	}
}
