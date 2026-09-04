<?php
/**
 * Delivery Address confirmation and Dispatch Distance (issue #11).
 *
 * When Con Despacho is Sí the customer may confirm the Delivery Address
 * with Google assistance — and always retains the manual fallback:
 *
 *   - Assistance appears only inside the dispatch-conditional address
 *     block of the request form (hidden without dispatch, exactly like the
 *     manual field) and only while a provider client is configured. The
 *     assistance is server-mediated: a nonce+session-guarded admin-post
 *     operation performs the provider lookup, so the billable credential
 *     never reaches the page. A plain Buscar submit (POST-redirect-GET)
 *     keeps the flow fully functional without JavaScript; the progressive
 *     enhancement only refreshes the suggestion list in place.
 *   - Select → review → explicit confirm is a server-side state machine
 *     scoped to the anonymous basket session (a 15-minute transient keyed
 *     by the session hash — never the URL): picking a Chilean suggestion
 *     resolves its formatted destination, the customer reviews it on the
 *     re-rendered form and confirms it explicitly; Cambiar drops the state
 *     and restores the manual path.
 *   - The manual Dirección de despacho always remains available (rural or
 *     unrecognized destinations); with a confirmed destination it simply
 *     stops being required.
 *   - Distance: Google Routes calculates the driving distance from the
 *     configured Warehouse (the provisional origin Camino El Arrayán 52,
 *     San Francisco de Mostazal — a stored option so the client's pending
 *     origin answer can be applied without code changes). The result is an
 *     internal sales fact stored on the Quote Request (status
 *     ok/pending/error, meters, origin, provider, calculation time) —
 *     never a customer-facing shipping price.
 *   - Provider failures never reject a request: a confirmation or
 *     calculation failure is a recoverable notice / a persisted
 *     pending|error distance state with the destination preserved, and
 *     authorized sales staff can retry the calculation through a
 *     nonce+capability-guarded operation.
 *   - The Google client sits behind a narrow adapter boundary
 *     (freeplast_cq_google_client) so automated tests replace the provider
 *     without touching business logic. Credentials are environment-supplied
 *     (FREEPLAST_GOOGLE_API_KEY) and never stored in the database or the
 *     repository; the key must be restricted in the Google console to the
 *     Places and Routes APIs.
 *
 * @package Freeplast_Catalog_Quotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Freeplast_CQ_Address {

	/** Nonce action guarding every public address operation. */
	public const NONCE_ACTION = 'fp_address';

	/** Nonce action guarding the staff distance-retry operation. */
	public const RETRY_NONCE_ACTION = 'fp_distance_retry';

	/** Option holding the configured Warehouse origin (migration 7). */
	public const ORIGIN_OPTION = 'fp_dispatch_origin';

	/** The provisional Warehouse origin pending the client's answer. */
	public const DEFAULT_ORIGIN = 'Camino El Arrayán 52, San Francisco de Mostazal';

	/** The customer-confirmed destination stored on the Quote Request. */
	public const META_DESTINATION = '_fpq_destination';

	/** The Dispatch Distance state stored on the Quote Request. */
	public const META_DISTANCE = '_fpq_distance';

	/** Environment variable carrying the billable Google credential. */
	public const API_KEY_ENV = 'FREEPLAST_GOOGLE_API_KEY';

	/** Address state lives as long as a retained form attempt. */
	private const STATE_TTL = 15 * MINUTE_IN_SECONDS;

	/** Suggestion lists are short-lived scratch state. */
	private const SUGGESTION_TTL = 5 * MINUTE_IN_SECONDS;

	public static function register(): void {
		foreach ( array( 'search', 'suggest', 'pick', 'confirm', 'clear' ) as $operation ) {
			add_action( "admin_post_fp_address_{$operation}", array( self::class, "handle_{$operation}" ) );
			add_action( "admin_post_nopriv_fp_address_{$operation}", array( self::class, "handle_{$operation}" ) );
		}

		/* Staff-only: the distance retry never runs for anonymous callers. */
		add_action( 'admin_post_fp_distance_retry', array( self::class, 'handle_retry' ) );
	}

	/* ------------------------------------------------------------------ */
	/* The Google adapter boundary                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * The environment-supplied credential — never an option, never in the
	 * repository. The Google console restriction (Places API + Routes API)
	 * bounds what a leaked value could do.
	 */
	public static function api_key(): string {
		$key = (string) getenv( self::API_KEY_ENV );
		return trim( (string) apply_filters( 'freeplast_cq_google_api_key', $key ) );
	}

	/**
	 * The Google client, or null while assistance is unavailable. The
	 * filter is the narrow boundary the automated checks replace; the real
	 * HTTP client is only built when a credential exists.
	 */
	public static function client(): ?object {
		$client = apply_filters( 'freeplast_cq_google_client', null );
		if ( is_object( $client ) ) {
			return $client;
		}
		$key = self::api_key();
		return '' === $key ? null : new Freeplast_CQ_Google_Http( $key );
	}

	/**
	 * The configured Warehouse origin of every distance calculation. The
	 * stored option (migration 7) keeps origin selection a configuration
	 * decision pending the client's answer about Santiago as a second
	 * dispatch origin.
	 */
	public static function origin(): string {
		$origin = (string) get_option( self::ORIGIN_OPTION, self::DEFAULT_ORIGIN );
		return trim( (string) apply_filters( 'freeplast_cq_dispatch_origin', $origin ) );
	}

	/* ------------------------------------------------------------------ */
	/* Session-scoped address state (expiring transients — never the URL)  */
	/* ------------------------------------------------------------------ */

	private static function state_key( string $hash ): string {
		return 'fpcq_addr_' . $hash;
	}

	private static function suggestion_key( string $hash ): string {
		return 'fpcq_addrsugg_' . $hash;
	}

	/**
	 * The address state of one basket session: null until a suggestion is
	 * picked, then a review or confirmed destination.
	 *
	 * @return array|null {stage, place_id, formatted, lat, lng, resolved_at, confirmed_at?}
	 */
	public static function session_state( string $hash ): ?array {
		$state = get_transient( self::state_key( $hash ) );
		return is_array( $state ) && isset( $state['stage'] ) ? $state : null;
	}

	/** The explicitly confirmed destination of one session, or null. */
	public static function confirmed( string $hash ): ?array {
		$state = self::session_state( $hash );
		return null !== $state && 'confirmed' === (string) $state['stage'] ? $state : null;
	}

	/** Drop the session's address state (basket cleared, address changed). */
	public static function clear_session_state( string $hash ): void {
		delete_transient( self::state_key( $hash ) );
	}

	private static function store_suggestions( string $hash, array $suggestions ): void {
		set_transient( self::suggestion_key( $hash ), $suggestions, self::SUGGESTION_TTL );
	}

	/**
	 * The stored suggestion list of one session (rendered for the no-JS
	 * flow; the enhancement rebuilds the same list from JSON).
	 *
	 * @return array[] each: {id, description}
	 */
	public static function suggestions( string $hash ): array {
		$suggestions = get_transient( self::suggestion_key( $hash ) );
		return is_array( $suggestions ) ? $suggestions : array();
	}

	/** Normalize provider suggestions to the bounded public shape. */
	private static function clean_suggestions( array $suggestions ): array {
		$clean = array();
		foreach ( $suggestions as $suggestion ) {
			if ( ! is_array( $suggestion ) ) {
				continue;
			}
			$id          = (string) ( $suggestion['id'] ?? '' );
			$description = sanitize_text_field( (string) ( $suggestion['description'] ?? '' ) );
			if ( ! self::is_place_id( $id ) || '' === $description || mb_strlen( $description ) > 300 ) {
				continue;
			}
			$clean[] = array(
				'id'          => $id,
				'description' => $description,
			);
			if ( 5 === count( $clean ) ) {
				break;
			}
		}
		return $clean;
	}

	/* ------------------------------------------------------------------ */
	/* The public address operations                                       */
	/* ------------------------------------------------------------------ */

	/** Provider place ids are opaque slugs: bounded charset, bounded length. */
	private static function is_place_id( string $place ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_\-]{1,512}$/', $place );
	}

	/**
	 * The shared guard: nonce → session (recoverable — JSON for the
	 * enhancement, POST-redirect-GET otherwise).
	 */
	private static function begin( bool $json ): array {
		$nonce = isset( $_POST['fp_address_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['fp_address_nonce'] ) ) : '';
		if ( '' === $nonce || false === wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			self::fail( 'nonce', $json );
		}

		$session = Freeplast_CQ_Basket::current_session();
		if ( null === $session ) {
			self::fail( 'session', $json );
		}
		return $session;
	}

	private static function fail( string $code, bool $json ): void {
		if ( $json ) {
			wp_send_json(
				array(
					'ok'      => false,
					'message' => Freeplast_CQ_Basket::notice_message( $code ),
				)
			);
		}
		self::back( array( 'fpcq_notice' => $code ) );
	}

	/** Back to the sole submission surface, focused on the address field. */
	private static function back( array $args ): void {
		wp_safe_redirect( add_query_arg( $args, home_url( '/cotizacion/' ) ) . '#fp-direccion' );
		exit;
	}

	/** The submitted search text, when it is a usable query. */
	private static function submitted_query(): string {
		$query = isset( $_POST['fp_query'] ) ? sanitize_text_field( wp_unslash( $_POST['fp_query'] ) ) : '';
		return mb_strlen( $query ) >= 3 && mb_strlen( $query ) <= 200 ? $query : '';
	}

	/** No-JS path: Buscar stores the suggestion list and round-trips once. */
	public static function handle_search(): void {
		$session = self::begin( false );

		$query = self::submitted_query();
		if ( '' === $query ) {
			self::back( array( 'fpcq_notice' => 'address_query' ) );
		}

		$client = self::client();
		if ( null === $client ) {
			self::back( array( 'fpcq_notice' => 'address_unavailable' ) );
		}

		self::store_suggestions( $session['hash'], self::clean_suggestions( (array) $client->suggestions( $query ) ) );
		self::back( array() );
	}

	/** JS enhancement: the same lookup answered as JSON for in-place rendering. */
	public static function handle_suggest(): void {
		$session = self::begin( true );

		$query = self::submitted_query();
		if ( '' === $query ) {
			wp_send_json(
				array(
					'ok'          => true,
					'suggestions' => array(),
				)
			);
		}

		$client = self::client();
		if ( null === $client ) {
			wp_send_json(
				array(
					'ok'          => true,
					'unavailable' => true,
					'suggestions' => array(),
				)
			);
		}

		$suggestions = self::clean_suggestions( (array) $client->suggestions( $query ) );
		self::store_suggestions( $session['hash'], $suggestions );
		wp_send_json(
			array(
				'ok'          => true,
				'suggestions' => $suggestions,
			)
		);
	}

	/**
	 * Select: resolve the picked suggestion into its formatted destination
	 * and present it for review (nothing is confirmed yet).
	 */
	public static function handle_pick(): void {
		$session = self::begin( false );

		$place = isset( $_POST['fp_place'] ) ? sanitize_text_field( wp_unslash( $_POST['fp_place'] ) ) : '';
		if ( ! self::is_place_id( $place ) ) {
			self::back( array( 'fpcq_notice' => 'address_error' ) );
		}

		$client = self::client();
		if ( null === $client ) {
			self::back( array( 'fpcq_notice' => 'address_unavailable' ) );
		}

		$resolved = $client->resolve( $place );
		if ( ! is_array( $resolved ) || '' === (string) ( $resolved['formatted'] ?? '' ) ) {
			self::back( array( 'fpcq_notice' => 'address_error' ) );
		}

		$lat = isset( $resolved['lat'] ) && is_numeric( $resolved['lat'] ) ? (float) $resolved['lat'] : null;
		$lng = isset( $resolved['lng'] ) && is_numeric( $resolved['lng'] ) ? (float) $resolved['lng'] : null;

		set_transient(
			self::state_key( $session['hash'] ),
			array(
				'stage'        => 'review',
				'place_id'     => (string) ( $resolved['place_id'] ?? $place ),
				'formatted'    => sanitize_text_field( (string) $resolved['formatted'] ),
				'lat'          => $lat,
				'lng'          => $lng,
				'resolved_at'  => current_time( 'mysql' ),
				'confirmed_at' => '',
			),
			self::STATE_TTL
		);
		self::back( array( 'fpcq_notice' => 'address_review' ) );
	}

	/** The explicit confirmation of the reviewed destination. The form
	 * posts the reviewed destination's place id as a hidden field, and the
	 * posted value is validated against the session state: a tampered or
	 * stale post is rejected without mutating anything (issue #21). */
	public static function handle_confirm(): void {
		$session = self::begin( false );

		$state = self::session_state( $session['hash'] );
		if ( null === $state || 'confirmed' === (string) $state['stage'] || '' === (string) $state['formatted'] ) {
			self::back( array( 'fpcq_notice' => 'address_error' ) );
		}

		$place = isset( $_POST['fp_place'] ) ? sanitize_text_field( wp_unslash( $_POST['fp_place'] ) ) : '';
		if ( ! self::is_place_id( $place ) || $place !== (string) $state['place_id'] ) {
			self::back( array( 'fpcq_notice' => 'address_error' ) );
		}

		$state['stage']        = 'confirmed';
		$state['confirmed_at'] = current_time( 'mysql' );
		set_transient( self::state_key( $session['hash'] ), $state, self::STATE_TTL );
		self::back( array( 'fpcq_notice' => 'address_confirmed' ) );
	}

	/** Cambiar: drop the state and restore search + manual entry. */
	public static function handle_clear(): void {
		$session = self::begin( false );
		self::clear_session_state( $session['hash'] );
		self::back( array( 'fpcq_notice' => 'address_cleared' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Dispatch Distance                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Calculate and store the Dispatch Distance of one Quote Request from
	 * the configured Warehouse. Never throws, never rejects the request:
	 * a failure persists an error state with the destination preserved so
	 * staff can retry.
	 *
	 * @return array The stored distance state.
	 */
	public static function calculate_and_store( int $post_id ): array {
		$destination = Freeplast_CQ_Codec::decode( (string) get_post_meta( $post_id, self::META_DESTINATION, true ) );

		$result = array(
			'status'        => 'pending',
			'meters'        => null,
			'origin'        => self::origin(),
			'provider'      => 'google-routes',
			'calculated_at' => current_time( 'mysql' ),
			'error'         => '',
		);

		if ( array() === $destination || '' === (string) ( $destination['address'] ?? '' ) ) {
			$result['error'] = 'destination_missing';
		} else {
			$client = self::client();
			if ( null === $client ) {
				$result['error'] = 'provider_unavailable';
			} else {
				$route = $client->route(
					array( 'address' => self::origin() ),
					self::route_destination( $destination )
				);
				if ( ! is_array( $route ) || ! isset( $route['meters'] ) || (int) $route['meters'] < 0 ) {
					$result['status'] = 'error';
					$result['error']  = 'route_unavailable';
				} else {
					$result['status'] = 'ok';
					$result['meters'] = (int) $route['meters'];
				}
			}
		}

		update_post_meta( $post_id, self::META_DISTANCE, Freeplast_CQ_Codec::encode( $result ) );
		return $result;
	}

	/**
	 * The Routes waypoint for one stored destination: the confirmed
	 * coordinates when the provider supplied them, the address text
	 * otherwise (manual addresses route by text).
	 */
	private static function route_destination( array $destination ): array {
		if ( isset( $destination['lat'], $destination['lng'] ) && is_numeric( $destination['lat'] ) && is_numeric( $destination['lng'] ) ) {
			return array(
				'lat' => (float) $destination['lat'],
				'lng' => (float) $destination['lng'],
			);
		}
		return array( 'address' => (string) ( $destination['address'] ?? '' ) );
	}

	/**
	 * Staff retry of a failed (or provider-unavailable pending) distance
	 * calculation. Capability + nonce guarded, like every staff operation.
	 */
	public static function handle_retry(): void {
		if ( ! current_user_can( Freeplast_CQ_Request::CAPABILITY ) ) {
			wp_die( 'Lo sentimos, no tienes permisos para esta acción.', '', array( 'response' => 403 ) );
		}

		$nonce = isset( $_POST['fp_distance_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['fp_distance_nonce'] ) ) : '';
		if ( '' === $nonce || false === wp_verify_nonce( $nonce, self::RETRY_NONCE_ACTION ) ) {
			wp_die( 'Tu solicitud venció. Inténtalo de nuevo.', '', array( 'response' => 403 ) );
		}

		$id   = isset( $_POST['p'] ) ? absint( $_POST['p'] ) : 0;
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post instanceof WP_Post || Freeplast_CQ_Request::POST_TYPE !== $post->post_type ) {
			wp_die( 'Solicitud no encontrada.', '', array( 'response' => 404 ) );
		}

		$result  = self::calculate_and_store( $post->ID );
		$outcome = 'ok' === $result['status'] ? 'ok' : 'error';
		wp_safe_redirect( admin_url( 'admin.php?page=fp-quote&p=' . $post->ID . '&fp_dist=' . $outcome ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Rendering (versioned public fpcq- markup, v1)                       */
	/* ------------------------------------------------------------------ */

	/**
	 * The dispatch-conditional address block of the request form: the
	 * Google-assisted search (only while a client is configured), the
	 * suggestion/review/confirmed state, and the manual fallback textarea.
	 * Hidden without dispatch, exactly like the issue #8 address field.
	 */
	public static function render_address_block( string $manual_value, ?string $error, bool $dispatched, string $hash ): string {
		$state     = self::session_state( $hash );
		$confirmed = null !== $state && 'confirmed' === (string) $state['stage'];

		$class = 'fpcq-field fpcq-field-wide fpcq-field-address';
		if ( null !== $error ) {
			$class .= ' fpcq-field-invalid';
		}
		if ( ! $dispatched ) {
			$class .= ' fpcq-hidden';
		}

		/* The manual field is required only while dispatched without a
		   confirmed destination (the JS reveal mirrors this dataset). */
		$manual_required = $dispatched && ! $confirmed;

		$inline = null === $error ? '' : sprintf( '<p class="fpcq-field-error" id="fp-direccion-error">%s</p>', esc_html( $error ) );

		return sprintf(
			'<div class="%1$s" data-fpcq-address-field><label class="fpcq-field-label" for="fp-direccion">Dirección de despacho</label>%2$s<textarea class="fpcq-textarea" id="fp-direccion" name="fp_direccion" rows="2" maxlength="%3$d" data-fpcq-manual-required="%4$s"%5$s%6$s>%7$s</textarea>%8$s</div>',
			esc_attr( $class ),
			self::render_assist( $hash, $state ),
			Freeplast_CQ_Request::MAX_DIRECCION,
			$manual_required ? '1' : '0',
			$manual_required ? ' required' : '',
			null === $error ? '' : ' aria-describedby="fp-direccion-error" aria-invalid="true"',
			esc_textarea( $manual_value ),
			$inline
		);
	}

	/**
	 * The assistance markup (search, suggestions, review or confirmed
	 * destination) — empty while no provider client is configured, so the
	 * manual fallback is the only address path.
	 */
	private static function render_assist( string $hash, ?array $state ): string {
		if ( null === self::client() ) {
			return '';
		}

		if ( null === $state ) {
			$inner = self::render_search() . self::render_suggestions( self::suggestions( $hash ) );
		} elseif ( 'review' === (string) $state['stage'] ) {
			$inner = self::render_review( $state );
		} else {
			$inner = self::render_confirmed( $state );
		}

		return sprintf( '<div class="fpcq-address-assist">%s</div>', $inner );
	}

	/** The search form — a plain POST the enhancement merely pre-fetches. */
	private static function render_search(): string {
		return sprintf(
			'<form class="fpcq-address-search" method="post" action="%1$s" data-fpcq-address-search><label class="fpcq-address-search-label" for="fp-direccion-buscar">Buscar tu dirección</label><div class="fpcq-address-search-row"><input class="fpcq-input" type="search" id="fp-direccion-buscar" name="fp_query" placeholder="Calle y número, comuna" maxlength="200" autocomplete="off"><button class="fpcq-address-search-submit" type="submit">Buscar</button></div><input type="hidden" name="action" value="fp_address_search"><input type="hidden" name="_wp_http_referer" value="%2$s"><input type="hidden" name="fp_address_nonce" value="%3$s"></form><p class="fpcq-address-manual-note">¿Dirección rural o no aparece? Escríbela manualmente abajo.</p>',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_url( home_url( '/cotizacion/' ) ),
			esc_attr( wp_create_nonce( self::NONCE_ACTION ) )
		);
	}

	/** The stored suggestion list of one session — each entry posts the pick
	 *   operation. The container always renders (empty without results) so
	 *   the enhancement can refresh it in place. */
	private static function render_suggestions( array $suggestions ): string {
		$items = '';
		foreach ( $suggestions as $suggestion ) {
			$items .= sprintf(
				'<li>%s</li>',
				self::render_step_form(
					'fpcq-address-pick',
					sprintf( '<button class="fpcq-address-pick-submit" type="submit">%s</button>', esc_html( (string) $suggestion['description'] ) ),
					'fp_address_pick',
					(string) $suggestion['id']
				)
			);
		}

		return sprintf( '<ul class="fpcq-address-suggestions" data-fpcq-address-suggestions>%s</ul>', $items );
	}

	/** The reviewed formatted destination with its explicit confirmation. */
	private static function render_review( array $state ): string {
		$actions = sprintf(
			'<div class="fpcq-address-review-actions">%s%s</div>',
			self::render_step_form(
				'fpcq-address-confirm',
				'<button class="fpcq-address-confirm-submit" type="submit">Confirmar dirección</button>',
				'fp_address_confirm',
				(string) $state['place_id']
			),
			self::render_step_form(
				'fpcq-address-clear',
				'<button class="fpcq-address-clear-submit" type="submit">Buscar otra</button>',
				'fp_address_clear',
				''
			)
		);

		return sprintf(
			'<div class="fpcq-address-review" role="status"><p class="fpcq-address-review-label">Dirección encontrada — revísala y confírmala:</p><p class="fpcq-address-review-text">%s</p>%s</div>',
			esc_html( (string) $state['formatted'] ),
			$actions
		);
	}

	/** The customer-confirmed destination (reviewed, then confirmed). */
	private static function render_confirmed( array $state ): string {
		return sprintf(
			'<div class="fpcq-address-confirmed" data-fpcq-address-confirmed role="status"><p class="fpcq-address-confirmed-label">Dirección confirmada</p><p class="fpcq-address-confirmed-text">%s</p>%s</div>',
			esc_html( (string) $state['formatted'] ),
			self::render_step_form(
				'fpcq-address-clear',
				'<button class="fpcq-address-clear-submit" type="submit">Cambiar dirección</button>',
				'fp_address_clear',
				''
			)
		);
	}

	/** One address step form: operation + place id + referer + nonce. */
	private static function render_step_form( string $class, string $inner, string $action, string $place ): string {
		return sprintf(
			'<form class="%1$s" method="post" action="%2$s">%3$s<input type="hidden" name="action" value="%4$s"><input type="hidden" name="fp_place" value="%5$s"><input type="hidden" name="_wp_http_referer" value="%6$s"><input type="hidden" name="fp_address_nonce" value="%7$s"></form>',
			esc_attr( $class ),
			esc_url( admin_url( 'admin-post.php' ) ),
			$inner, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- buttons carry escaped text only
			esc_attr( $action ),
			esc_attr( $place ),
			esc_url( home_url( '/cotizacion/' ) ),
			esc_attr( wp_create_nonce( self::NONCE_ACTION ) )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Staff rendering (nonce + capability protected)                      */
	/* ------------------------------------------------------------------ */

	/**
	 * The internal Dispatch Distance section of the admin detail: the
	 * stored destination, the distance state and the staff retry. An
	 * internal sales fact — never presented as a shipping price.
	 */
	public static function render_admin_distance( WP_Post $post ): string {
		$destination = Freeplast_CQ_Codec::decode( (string) get_post_meta( $post->ID, self::META_DESTINATION, true ) );
		if ( array() === $destination ) {
			return '<h2>Distancia de despacho</h2><p class="description">Sin despacho solicitado — no aplica distancia.</p>';
		}

		$distance = Freeplast_CQ_Codec::decode( (string) get_post_meta( $post->ID, self::META_DISTANCE, true ) );

		$status = (string) ( $distance['status'] ?? 'pending' );
		if ( 'ok' === $status ) {
			$state_text = sprintf( 'Calculada: %s', number_format( ( (int) ( $distance['meters'] ?? 0 ) ) / 1000, 1, ',', '.' ) . ' km por carretera' );
		} elseif ( 'error' === $status ) {
			$state_text = sprintf( 'Error de cálculo (%s) — reintenta más abajo', (string) ( $distance['error'] ?? '' ) );
		} else {
			$state_text = sprintf( 'Pendiente (%s) — reintenta más abajo', (string) ( $distance['error'] ?? '' ) );
		}

		$mode = 'google' === (string) ( $destination['mode'] ?? '' ) ? 'Confirmada con Google' : 'Manual (sin confirmación Google)';
		$rows = '';
		foreach ( array(
			array( 'Destino de despacho', (string) ( $destination['address'] ?? '' ) ),
			array( 'Modo', $mode ),
			array( 'Google place id', '' !== (string) ( $destination['place_id'] ?? '' ) ? (string) $destination['place_id'] : '—' ),
			array( 'Origen (bodega)', (string) ( $distance['origin'] ?? self::origin() ) ),
			array( 'Estado', $state_text ),
			array( 'Último cálculo', (string) ( $distance['calculated_at'] ?? '' ) ),
		) as $row ) {
			$rows .= sprintf( '<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html( $row[0] ), esc_html( $row[1] ) );
		}

		$retry = sprintf(
			'<form class="fpcq-distance-retry" method="post" action="%1$s"><button class="button" type="submit">Recalcular distancia</button><input type="hidden" name="action" value="fp_distance_retry"><input type="hidden" name="p" value="%2$d"><input type="hidden" name="_wp_http_referer" value="%3$s"><input type="hidden" name="fp_distance_nonce" value="%4$s"></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			$post->ID,
			esc_url( admin_url( 'admin.php?page=fp-quote&p=' . $post->ID ) ),
			esc_attr( wp_create_nonce( self::RETRY_NONCE_ACTION ) )
		);

		$notice = '';
		$outcome = isset( $_GET['fp_dist'] ) ? sanitize_key( wp_unslash( $_GET['fp_dist'] ) ) : '';
		if ( '' !== $outcome ) {
			$notice = sprintf(
				'<div class="notice notice-%1$s inline"><p>%2$s</p></div>',
				'ok' === $outcome ? 'success' : 'warning',
				'ok' === $outcome ? 'Distancia recalculada.' : 'No pudimos calcular la distancia en este intento.'
			);
		}

		return sprintf(
			'<h2>Distancia de despacho (uso interno de ventas)</h2>%s<table class="widefat striped"><tbody>%s</tbody></table>%s<p class="description">Distancia interna de referencia calculada desde la bodega — no es un precio de envío automático ni una promesa comercial.</p>',
			$notice, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fully escaped by the builder
			$rows,  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rows are fully escaped by the builder
			$retry  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts
		);
	}
}

/**
 * The real Google HTTP client (Places API (New) + Routes API).
 *
 * Only instantiated when an environment credential exists; the automated
 * checks replace this whole client at the freeplast_cq_google_client
 * boundary, so no test ever performs network calls.
 */
class Freeplast_CQ_Google_Http {

	private const PLACES_BASE = 'https://places.googleapis.com/v1/';
	private const ROUTES_URL  = 'https://routes.googleapis.com/directions/v2:computeRoutes';

	/** Short bound: a slow provider may delay, never stall, a submission. */
	private const TIMEOUT = 6;

	public function __construct( private string $api_key ) {}

	/**
	 * Chilean address suggestions for one query.
	 *
	 * @return array[] each: {id, description}
	 */
	public function suggestions( string $query ): array {
		$response = wp_remote_post(
			self::PLACES_BASE . 'places:autocomplete',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Content-Type'   => 'application/json',
					'X-Goog-Api-Key' => $this->api_key,
				),
				'body'    => wp_json_encode(
					array(
						'input'               => $query,
						'includedRegionCodes' => array( 'CL' ),
						'languageCode'        => 'es',
						'maxResultCount'      => 5,
					)
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$out  = array();
		foreach ( is_array( $body['suggestions'] ?? null ) ? $body['suggestions'] : array() as $suggestion ) {
			$prediction   = is_array( $suggestion['placePrediction'] ?? null ) ? $suggestion['placePrediction'] : array();
			$place_id     = (string) ( $prediction['placeId'] ?? '' );
			$description  = (string) ( $prediction['text']['text'] ?? '' );
			if ( '' !== $place_id && '' !== $description ) {
				$out[] = array(
					'id'          => $place_id,
					'description' => $description,
				);
			}
		}
		return $out;
	}

	/**
	 * The formatted destination of one place: address text and, as the
	 * provider supplies them for route calculation, the coordinates.
	 *
	 * @return array|null {place_id, formatted, lat, lng}
	 */
	public function resolve( string $place_id ): ?array {
		$response = wp_remote_get(
			esc_url_raw( self::PLACES_BASE . 'places/' . rawurlencode( $place_id ) ),
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'X-Goog-Api-Key'  => $this->api_key,
					'X-Goog-FieldMask' => 'id,formattedAddress,location',
					'Accept'           => 'application/json',
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || '' === (string) ( $body['formattedAddress'] ?? '' ) ) {
			return null;
		}

		$location = is_array( $body['location'] ?? null ) ? $body['location'] : array();
		return array(
			'place_id'  => (string) ( $body['id'] ?? $place_id ),
			'formatted' => (string) $body['formattedAddress'],
			'lat'       => isset( $location['latitude'] ) ? (float) $location['latitude'] : null,
			'lng'       => isset( $location['longitude'] ) ? (float) $location['longitude'] : null,
		);
	}

	/**
	 * The driving distance in meters between origin and destination, or
	 * null when the provider failed.
	 *
	 * @return array|null {meters}
	 */
	public function route( array $origin, array $destination ): ?array {
		$waypoint = static function ( array $point ): array {
			if ( isset( $point['lat'], $point['lng'] ) ) {
				return array(
					'location' => array(
						'latLng' => array(
							'latitude'  => (float) $point['lat'],
							'longitude' => (float) $point['lng'],
						),
					),
				);
			}
			return array( 'address' => (string) ( $point['address'] ?? '' ) );
		};

		$response = wp_remote_post(
			self::ROUTES_URL,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Content-Type'   => 'application/json',
					'X-Goog-Api-Key' => $this->api_key,
				),
				'body'    => wp_json_encode(
					array(
						'origin'      => $waypoint( $origin ),
						'destination' => $waypoint( $destination ),
						'travelMode'  => 'DRIVE',
						'units'       => 'METRIC',
					)
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		$meters = $body['routes'][0]['distanceMeters'] ?? null;
		return is_numeric( $meters ) ? array( 'meters' => (int) $meters ) : null;
	}
}
