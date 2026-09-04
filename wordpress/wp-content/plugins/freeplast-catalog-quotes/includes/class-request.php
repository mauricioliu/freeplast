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
 *     information and the immutable item snapshots. No price, Quotation,
 *     Order, checkout or customer account is ever created — notifications
 *     arrive separately (issue #11).
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

	/** Required single-line business fields: key => (label, max length). */
	private const TEXT_FIELDS = array(
		'nombre'   => array( 'label' => 'Nombre', 'max' => 120 ),
		'telefono' => array( 'label' => 'Teléfono', 'max' => 40 ),
		'email'    => array( 'label' => 'Email', 'max' => 190 ),
		'empresa'  => array( 'label' => 'Nombre Empresa', 'max' => 190 ),
		'rut'      => array( 'label' => 'Rut Empresa', 'max' => 20 ),
		'giro'     => array( 'label' => 'Giro', 'max' => 190 ),
	);

	private const MAX_DIRECCION = 400;
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

		add_action( 'admin_menu', array( self::class, 'register_admin_pages' ) );
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

		/* 8. Persist exactly one record with the immutable snapshots. */
		$reference = self::persist( $session, $token, $values, $lines );
		if ( null === $reference ) {
			self::store_attempt( $session['hash'], $values, array(), $failure );
			self::fail( 'request_failed' );
		}

		/* 9. Success — only now is the basket cleared (the session stays
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
	private static function normalized_phone( string $entered ): string {
		$plus   = str_starts_with( $entered, '+' );
		$digits = preg_replace( '/\D+/', '', $entered );
		if ( null === $digits || 8 > strlen( $digits ) ) {
			return '';
		}
		return ( $plus ? '+' : '' ) . $digits;
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

		$idempotency = hash( 'sha256', $token );

		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$reference = self::next_reference();
			$post_id   = wp_insert_post(
				array(
					'post_type'   => self::POST_TYPE,
					'post_status' => 'private',
					'post_title'  => $reference,
					'post_author' => 0,
					'meta_input'  => array(
						'_fpq_reference'   => $reference,
						'_fpq_status'      => 'new',
						'_fpq_customer'    => wp_json_encode( $customer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
						'_fpq_items'       => wp_json_encode( $items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
						'_fpq_idempotency' => $idempotency,
						'_fpq_session'     => $session['hash'],
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

		$fields  = self::render_text_field( 'nombre', $values['nombre'], $errors['nombre'] ?? null );
		$fields .= self::render_text_field( 'telefono', $values['telefono'], $errors['telefono'] ?? null );
		$fields .= self::render_text_field( 'email', $values['email'], $errors['email'] ?? null );
		$fields .= self::render_text_field( 'empresa', $values['empresa'], $errors['empresa'] ?? null );
		$fields .= self::render_text_field( 'rut', $values['rut'], $errors['rut'] ?? null );
		$fields .= self::render_text_field( 'giro', $values['giro'], $errors['giro'] ?? null );
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
			'<div class="fpcq-form-errors" id="fpcq-form-errors" role="alert" tabindex="-1"><p class="fpcq-form-errors-title">%s</p>%s%s</div>',
			'Revisa tu solicitud antes de enviarla:',
			$items,
			'' !== $general ? sprintf( '<p class="fpcq-form-errors-general">%s</p>', esc_html( $general ) ) : ''
		);
	}

	private const INPUT_TYPES = array(
		'nombre'   => 'text',
		'telefono' => 'tel',
		'email'    => 'email',
		'empresa'  => 'text',
		'rut'      => 'text',
		'giro'     => 'text',
	);

	private const AUTOCOMPLETE = array(
		'nombre'   => 'name',
		'telefono' => 'tel',
		'email'    => 'email',
		'empresa'  => 'organization',
		'rut'      => 'off',
		'giro'     => 'off',
	);

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
			esc_attr( self::INPUT_TYPES[ $key ] ),
			esc_attr( $value ),
			$field['max'],
			esc_attr( self::AUTOCOMPLETE[ $key ] ),
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

	/* ------------------------------------------------------------------ */
	/* Minimal capability-protected administration (issue #8 slice)         */
	/* ------------------------------------------------------------------ */

	public static function register_admin_pages(): void {
		add_menu_page(
			'Cotizaciones',
			'Cotizaciones',
			self::CAPABILITY,
			'fp-quotes',
			array( self::class, 'render_admin_list' ),
			'dashicons-clipboard',
			26
		);
		add_submenu_page(
			'fp-quotes',
			'Solicitud',
			'Solicitud',
			self::CAPABILITY,
			'fp-quote',
			array( self::class, 'render_admin_detail' )
		);
	}

	/** The dedicated sales capability is required for every admin surface. */
	private static function guard(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( 'Lo sentimos, no tienes permisos para acceder a esta página.', '', array( 'response' => 403 ) );
		}
	}

	/** Cotizaciones — the latest persisted Quote Requests. */
	public static function render_admin_list(): void {
		self::guard();

		$posts = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'private',
				'posts_per_page'   => 100,
				'orderby'          => 'ID',
				'order'            => 'DESC',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);

		$rows = '';
		foreach ( $posts as $post ) {
			$customer = json_decode( (string) get_post_meta( $post->ID, '_fpq_customer', true ), true );
			$customer = is_array( $customer ) ? $customer : array();
			$rows    .= sprintf(
				'<tr><td><a href="%1$s"><strong>%2$s</strong></a></td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td><td>%7$s</td></tr>',
				esc_url( admin_url( 'admin.php?page=fp-quote&p=' . $post->ID ) ),
				esc_html( (string) get_post_meta( $post->ID, '_fpq_reference', true ) ),
				esc_html( (string) ( $customer['empresa'] ?? '' ) ),
				esc_html( (string) ( $customer['email'] ?? '' ) ),
				'si' === (string) ( $customer['con_despacho'] ?? '' ) ? 'Sí' : 'No',
				esc_html( self::status_label( (string) get_post_meta( $post->ID, '_fpq_status', true ) ) ),
				esc_html( mysql2date( 'd/m/Y H:i', $post->post_date ) )
			);
		}

		printf(
			'<div class="wrap"><h1>Cotizaciones</h1><p class="description">Solicitudes de cotización recibidas desde el sitio. La administración completa (estados, notas, historial) llega con el slice de ventas; esta vista permite inspeccionar cada solicitud persistida.</p><table class="widefat striped"><thead><tr><th>Referencia</th><th>Empresa</th><th>Email</th><th>Despacho</th><th>Estado</th><th>Creada</th></tr></thead><tbody>%s</tbody></table></div>',
			$rows // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rows are fully escaped by the builder
		);
	}

	/** The detail of one persisted Quote Request (read-only at this slice). */
	public static function render_admin_detail(): void {
		self::guard();

		$id   = isset( $_GET['p'] ) ? absint( $_GET['p'] ) : 0;
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
			wp_die( 'Solicitud no encontrada.', '', array( 'response' => 404 ) );
		}

		$reference = (string) get_post_meta( $post->ID, '_fpq_reference', true );
		$status    = (string) get_post_meta( $post->ID, '_fpq_status', true );
		$customer  = json_decode( (string) get_post_meta( $post->ID, '_fpq_customer', true ), true );
		$customer  = is_array( $customer ) ? $customer : array();
		$items     = json_decode( (string) get_post_meta( $post->ID, '_fpq_items', true ), true );
		$items     = is_array( $items ) ? $items : array();

		$dispatched = 'si' === (string) ( $customer['con_despacho'] ?? '' );

		$detail_rows = array(
			array( 'Nombre', (string) ( $customer['nombre'] ?? '' ) ),
			array( 'Teléfono', trim( ( (string) ( $customer['telefono'] ?? '' ) ) . ( '' !== (string) ( $customer['telefono_normalizado'] ?? '' ) ? sprintf( ' (normalizado: %s)', (string) $customer['telefono_normalizado'] ) : '' ) ) ),
			array( 'Email', (string) ( $customer['email'] ?? '' ) ),
			array( 'Nombre Empresa', (string) ( $customer['empresa'] ?? '' ) ),
			array( 'Rut Empresa', (string) ( $customer['rut'] ?? '' ) ),
			array( 'Giro', (string) ( $customer['giro'] ?? '' ) ),
			array( 'Con despacho', $dispatched ? 'Sí' : 'No' ),
			array( 'Dirección de despacho', $dispatched ? (string) ( $customer['direccion_despacho'] ?? '' ) : '—' ),
			array( 'Mensaje', '' !== (string) ( $customer['mensaje'] ?? '' ) ? (string) ( $customer['mensaje'] ?? '' ) : '—' ),
		);

		$details = '';
		foreach ( $detail_rows as $row ) {
			$details .= sprintf( '<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html( $row[0] ), esc_html( $row[1] ) );
		}

		$lines = '';
		foreach ( $items as $item ) {
			$item   = is_array( $item ) ? $item : array();
			$option = '' === (string) ( $item['option_label'] ?? '' ) ? '—' : (string) ( $item['option_label'] ?? '' );
			$rules  = sprintf(
				'%s / %s',
				null === ( $item['minimum'] ?? null ) ? 'sin mínimo confirmado' : sprintf( 'mínimo %d', (int) $item['minimum'] ),
				null === ( $item['step'] ?? null ) ? 'sin paso confirmado' : sprintf( 'paso %d', (int) $item['step'] )
			);
			$lines .= sprintf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$d</td><td>%4$s</td><td>%5$s · %6$s · %7$s</td><td><a href="%8$s" target="_blank" rel="noopener">%8$s</a></td></tr>',
				esc_html( (string) ( $item['title'] ?? '' ) ),
				esc_html( $option ),
				(int) ( $item['quantity'] ?? 0 ),
				esc_html( $rules ),
				esc_html( (string) ( $item['material'] ?? '' ) ),
				esc_html( (string) ( $item['dimensions'] ?? '' ) ),
				esc_html( (string) ( $item['weight'] ?? '' ) ),
				esc_url( (string) ( $item['url'] ?? '' ) )
			);
		}

		printf(
			'<div class="wrap"><h1>Solicitud %1$s</h1><p class="description">Estado: <strong>%2$s</strong> · Recibida: %3$s · Los detalles enviados y las líneas son inmutables; la administración de estados, notas e historial llega con el slice de ventas.</p><h2>Datos enviados</h2><table class="widefat striped"><tbody>%4$s</tbody></table><h2>Productos solicitados (snapshot inmutable)</h2><table class="widefat striped"><thead><tr><th>Producto</th><th>Opción</th><th>Cantidad</th><th>Reglas usadas</th><th>Especificaciones</th><th>URL canónica</th></tr></thead><tbody>%5$s</tbody></table><p><a class="button" href="%6$s">← Volver a Cotizaciones</a></p></div>',
			esc_html( $reference ),
			esc_html( self::status_label( $status ) ),
			esc_html( mysql2date( 'd/m/Y H:i', $post->post_date ) ),
			$details, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rows are fully escaped by the builder
			$lines,  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rows are fully escaped by the builder
			esc_url( admin_url( 'admin.php?page=fp-quotes' ) )
		);
	}

	private static function status_label( string $status ): string {
		$labels = array(
			'new'       => 'nueva',
			'contacted' => 'contactada',
			'quoted'    => 'cotizada',
			'won'       => 'ganada',
			'lost'      => 'perdida',
			'cancelled' => 'cancelada',
		);
		return $labels[ $status ] ?? $status;
	}
}
