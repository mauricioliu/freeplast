<?php
/**
 * The persistent, anonymous Quote Basket (issues #6–#7).
 *
 * A guest selects a quantity — and one reviewed option where the Product
 * declares one (the Caja Universal Color configurations) — and manages a
 * secure server-side basket, then inspects it through the header count and
 * the mini basket across refreshes:
 *
 *   - Anonymous and cookie-based, including while a WordPress staff account
 *     happens to be logged in. There is no customer-account linking and no
 *     guest-to-login merge behavior.
 *   - The cookie carries only a random 256-bit opaque token (Secure,
 *     HttpOnly, SameSite=Lax, 30 days) — never product, option or customer
 *     data. Only its sha256 hash is persisted server-side, in the versioned
 *     basket_sessions table (migration 4). Sessions expire 30 days after
 *     the last activity; a daily sweep collects the expired rows and a
 *     presented-but-dead cookie is cleared once with a recoverable notice.
 *   - Every mutation — add, update, remove — is an authoritative POST
 *     (admin-post.php) guarded by its own nonce. The handler revalidates
 *     session identity, Product lifecycle/visibility, option identity and
 *     positive whole-unit quantity (confirmed minimum/step rules are
 *     enforced when present; while minimums stay unconfirmed any positive
 *     whole unit is accepted and no minimum is claimed). Only then is any
 *     state created or changed; the mutation always rewrites the whole
 *     stored line list, so header count, mini basket and full view agree.
 *   - Re-adding the same Product/option merges quantities; different
 *     options stay separate lines. The header count is the number of
 *     distinct lines, not total units.
 *   - Invalid nonce, session, Product, option, quantity or line mutate
 *     nothing and return a recoverable message — POST-redirect-GET for the
 *     plain flow, JSON (count, mini basket, full view, message) for the
 *     progressive JavaScript enhancement (the server remains authoritative;
 *     the script only mirrors the state it returns).
 *
 * Markup (versioned public fpcq- classes, v1) — semantic and functional
 * under a stock block theme; the freeplast theme supplies the v6 look:
 *
 *   freeplast/basket-button  header widget: Cotización (n) + mini basket
 *   freeplast/basket         the full /cotización/ page: the basket view
 *                            plus the request form below it (issue #8,
 *                            Freeplast_CQ_Request) or, after a successful
 *                            submission, the confirmation with the Request
 *                            Reference.
 *
 * @package Freeplast_Catalog_Quotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Freeplast_CQ_Basket {

	/** Cookie carrying the opaque session token (never basket data). */
	public const COOKIE_NAME = 'fpcq_basket';

	/** Nonce action guarding the add operation. */
	public const NONCE_ADD_ACTION = 'fp_basket_add';

	/** Nonce action guarding the update-line operation. */
	public const NONCE_UPDATE_ACTION = 'fp_basket_update';

	/** Nonce action guarding the remove-line operation. */
	public const NONCE_REMOVE_ACTION = 'fp_basket_remove';

	/** Daily event collecting sessions expired 30 days after last activity. */
	public const GC_EVENT = 'fpcq_basket_gc';

	/** Server-side sessions expire 30 days after the last activity. */
	private const SESSION_TTL = 30 * DAY_IN_SECONDS;

	/** Defensive upper bound for one submitted whole-unit quantity. */
	private const MAX_QUANTITY = 1000000;

	public static function register(): void {
		add_action( 'admin_post_fp_basket_add', array( self::class, 'handle_add' ) );
		add_action( 'admin_post_nopriv_fp_basket_add', array( self::class, 'handle_add' ) );
		add_action( 'admin_post_fp_basket_update', array( self::class, 'handle_update' ) );
		add_action( 'admin_post_nopriv_fp_basket_update', array( self::class, 'handle_update' ) );
		add_action( 'admin_post_fp_basket_remove', array( self::class, 'handle_remove' ) );
		add_action( 'admin_post_nopriv_fp_basket_remove', array( self::class, 'handle_remove' ) );

		/* A presented-but-dead cookie is cleared once, with a recoverable
	   notice, so an expired basket degrades into the empty state. */
		add_action( 'template_redirect', array( self::class, 'reap_expired_cookie' ) );

		/* Expiry housekeeping: anonymous sessions never accumulate forever. */
		add_action( self::GC_EVENT, array( self::class, 'gc' ) );
		if ( ! wp_next_scheduled( self::GC_EVENT ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::GC_EVENT );
		}

		add_action(
			'wp_enqueue_scripts',
			static function () {
				wp_enqueue_script(
					'fpcq-basket',
					plugins_url( 'assets/js/basket.js', FREEPLAST_CQ_FILE ),
					array(),
					FREEPLAST_CQ_VERSION,
					true
				);
			}
		);

		register_block_type(
			'freeplast/basket-button',
			array( 'render_callback' => array( self::class, 'render_button' ) )
		);
		register_block_type(
			'freeplast/basket',
			array( 'render_callback' => array( self::class, 'render_page' ) )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Schema                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * The versioned session table (migration 4). Stores only token hashes
	 * and line data — never the opaque token itself. Returns whether the
	 * table is usable afterwards, so a migration that cannot create it can
	 * fail safely into the maintenance state instead of half-migrating
	 * (issue #13).
	 */
	public static function create_table(): bool {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = $wpdb->prefix . 'basket_sessions';
		$collate = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				session_hash varchar(64) NOT NULL,
				basket_lines longtext NOT NULL,
				created_at datetime NOT NULL,
				last_activity datetime NOT NULL,
				PRIMARY KEY  (session_hash),
				KEY last_activity (last_activity)
			) {$collate};"
		);

		$ready = strtolower( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) === strtolower( $table );
		return (bool) apply_filters( 'freeplast_cq_schema_ready', $ready );
	}

	/* ------------------------------------------------------------------ */
	/* Session                                                             */
	/* ------------------------------------------------------------------ */

	/** The presented opaque token, when it is well-formed. */
	private static function current_token(): ?string {
		if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return null;
		}
		$token = (string) $_COOKIE[ self::COOKIE_NAME ]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- format-checked below
		return 1 === preg_match( '/^[0-9a-f]{64}$/', $token ) ? $token : null;
	}

	/**
	 * Resolve the presented cookie to a live session row, or null. Expired
	 * sessions (30 days without activity) resolve as absent.
	 *
	 * @return array|null {hash: string, lines: array}
	 */
	private static function resolve(): ?array {
		global $wpdb;

		$token = self::current_token();
		if ( null === $token ) {
			return null;
		}
		$hash = hash( 'sha256', $token );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT basket_lines, last_activity FROM ' . $wpdb->prefix . 'basket_sessions WHERE session_hash = %s',
				$hash
			)
		);
		if ( null === $row || self::is_expired( (string) $row->last_activity ) ) {
			return null;
		}

		$lines = Freeplast_CQ_Codec::decode( (string) $row->basket_lines );
		return array(
			'hash'  => $hash,
			'lines' => $lines,
		);
	}

	private static function is_expired( string $last_activity ): bool {
		$at = DateTime::createFromFormat( 'Y-m-d H:i:s', $last_activity, new DateTimeZone( 'UTC' ) );
		return false === $at || ( $at->getTimestamp() + self::SESSION_TTL ) < time();
	}

	/**
	 * Create a fresh anonymous session (random 256-bit opaque token) and
	 * hand the token to the browser. Called only after the request fully
	 * validated, so invalid requests never create sessions.
	 */
	private static function create_session(): array {
		global $wpdb;

		$token = bin2hex( random_bytes( 32 ) );
		$hash  = hash( 'sha256', $token );
		$now   = gmdate( 'Y-m-d H:i:s' );

		$wpdb->insert(
			$wpdb->prefix . 'basket_sessions',
			array(
				'session_hash'  => $hash,
				'basket_lines'  => '[]',
				'created_at'    => $now,
				'last_activity' => $now,
			),
			array( '%s', '%s', '%s', '%s' )
		);

		self::set_cookie( $token );
		/* Make the fresh session resolvable within this same request. */
		$_COOKIE[ self::COOKIE_NAME ] = $token;

		return array(
			'hash'  => $hash,
			'lines' => array(),
		);
	}

	/** Persist the complete line list of a session (one authoritative write). */
	private static function save_lines( array $session, array $lines ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'basket_sessions',
			array( 'basket_lines' => Freeplast_CQ_Codec::encode( array_values( $lines ) ) ),
			array( 'session_hash' => $session['hash'] ),
			array( '%s' ),
			array( '%s' )
		);
	}

	/** The stored index of one Product+option line, or -1 when absent. */
	private static function line_index( array $lines, string $product_id, string $option_id ): int {
		foreach ( $lines as $index => $line ) {
			if ( ( $line['product'] ?? '' ) === $product_id && ( $line['option'] ?? '' ) === $option_id ) {
				return (int) $index;
			}
		}
		return -1;
	}

	/** Merge the line (same Product + option) or append it as a new line. */
	private static function merge_line( array $session, string $product_id, string $option_id, int $quantity ): void {
		$lines = $session['lines'];
		$index = self::line_index( $lines, $product_id, $option_id );
		if ( -1 !== $index ) {
			$lines[ $index ]['quantity'] = (int) $lines[ $index ]['quantity'] + $quantity;
		} else {
			$lines[] = array(
				'product'  => $product_id,
				'option'   => $option_id,
				'quantity' => $quantity,
			);
		}
		self::save_lines( $session, $lines );
	}

	/** Replace the quantity of one stored line; false when the line is absent. */
	private static function update_line( array $session, string $product_id, string $option_id, int $quantity ): bool {
		$lines = $session['lines'];
		$index = self::line_index( $lines, $product_id, $option_id );
		if ( -1 === $index ) {
			return false;
		}
		$lines[ $index ]['quantity'] = $quantity;
		self::save_lines( $session, $lines );
		return true;
	}

	/** Drop one stored line; false when the line is absent. */
	private static function remove_line( array $session, string $product_id, string $option_id ): bool {
		$lines = $session['lines'];
		$index = self::line_index( $lines, $product_id, $option_id );
		if ( -1 === $index ) {
			return false;
		}
		unset( $lines[ $index ] );
		self::save_lines( $session, $lines );
		return true;
	}

	/** Slide the activity window forward on a successful mutation. */
	private static function touch( string $hash ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'basket_sessions',
			array( 'last_activity' => gmdate( 'Y-m-d H:i:s' ) ),
			array( 'session_hash' => $hash ),
			array( '%s' ),
			array( '%s' )
		);
	}

	private static function set_cookie( string $token ): void {
		self::send_cookie( $token, time() + self::SESSION_TTL );
	}

	/** Clear a stale cookie so the guest can simply retry. */
	private static function clear_cookie(): void {
		self::send_cookie( '', time() - YEAR_IN_SECONDS );
	}

	private static function send_cookie( string $value, int $expires ): void {
		setcookie(
			self::COOKIE_NAME,
			$value,
			array(
				'expires'  => $expires,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'secure'   => true, // staging serves TLS; loopback is a trustworthy origin
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Catalog resolution                                                  */
	/* ------------------------------------------------------------------ */

	/** The published fp_product for an immutable source id (archived ones never resolve). */
	private static function published_product( string $source_id ): ?WP_Post {
		if ( '' === $source_id ) {
			return null;
		}
		$posts = get_posts(
			array(
				'post_type'        => 'fp_product',
				'post_status'      => 'publish',
				'posts_per_page'   => 1,
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_key'         => '_fp_source_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $source_id,      // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return $posts[0] ?? null;
	}

	/** The reviewed option list of one Product (synchronized source order). */
	private static function product_options( WP_Post $product ): array {
		$options = Freeplast_CQ_Codec::decode( (string) get_post_meta( $product->ID, '_fp_options', true ) );
		$reviewed = array();
		foreach ( $options as $option ) {
			if ( is_array( $option ) && '' !== (string) ( $option['id'] ?? '' ) ) {
				$reviewed[] = $option;
			}
		}
		return $reviewed;
	}

	/** The reviewed label of one option, or '' when the id is unknown to the product. */
	private static function option_label( WP_Post $product, string $option_id ): string {
		foreach ( self::product_options( $product ) as $option ) {
			if ( (string) $option['id'] === $option_id ) {
				return (string) ( $option['label'] ?? '' );
			}
		}
		return '';
	}

	/**
	 * Basket lines resolved against the live Catalog: only currently
	 * published Products render, count or submit. Stored order is kept.
	 *
	 * @return array[] each: product (WP_Post), option_id, option_label, quantity
	 */
	public static function resolved_lines(): array {
		$session = self::resolve();
		if ( null === $session ) {
			return array();
		}

		$resolved = array();
		foreach ( $session['lines'] as $line ) {
			$product = self::published_product( (string) ( $line['product'] ?? '' ) );
			$qty     = (int) ( $line['quantity'] ?? 0 );
			if ( null === $product || 1 > $qty ) {
				continue; // archived/missing products never render
			}
			$option_id   = (string) ( $line['option'] ?? '' );
			$resolved[] = array(
				'product'      => $product,
				'option_id'    => $option_id,
				'option_label' => self::option_label( $product, $option_id ),
				'quantity'     => $qty,
			);
		}
		return $resolved;
	}

	/** The number of distinct lines — never the total unit quantity. */
	public static function count(): int {
		return count( self::resolved_lines() );
	}

	/* ------------------------------------------------------------------ */
	/* Session access for the Quote Request flow (issue #8)                */
	/* ------------------------------------------------------------------ */

	/** The resolved live session (hash + stored lines), or null. */
	public static function current_session(): ?array {
		return self::resolve();
	}

	/** Clear a presented-but-dead cookie so the guest can simply retry. */
	public static function clear_stale_cookie(): void {
		self::clear_cookie();
	}

	/**
	 * Clear the basket of a live session (one authoritative write of an
	 * empty line list). Called only after a Quote Request durably
	 * persisted — the session itself stays alive, so the next visit is a
	 * fresh empty basket rather than an apparent expiry.
	 */
	public static function clear_basket( array $session ): void {
		self::save_lines( $session, array() );
	}

	private static function quantity_label( int $quantity ): string {
		return 1 === $quantity ? '1 unidad' : sprintf( '%d unidades', $quantity );
	}

	/**
	 * Confirmed commercial rules apply when present; while minimums stay
	 * unconfirmed (absent) any positive whole unit is valid.
	 */
	private static function quantity_allowed( WP_Post $product, int $quantity ): bool {
		$minimum = (int) get_post_meta( $product->ID, '_fp_quote_min_qty', true );
		$step    = (int) get_post_meta( $product->ID, '_fp_quote_step', true );
		if ( 0 < $minimum ) {
			if ( $quantity < $minimum || ( 0 < $step && 0 !== ( $quantity - $minimum ) % $step ) ) {
				return false;
			}
		}
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* The authoritative operations (add, update, remove)                  */
	/* ------------------------------------------------------------------ */

	/**
	 * The shared validate-everything-first prelude: nonce → session (a
	 * presented-but-dead cookie is rejected AND cleared so a retry starts
	 * fresh) → Product (published only) → option. Returns the validated
	 * pieces (the session is null when no cookie was presented at all); a
	 * failing step never returns.
	 *
	 * @return array{session: array|null, product: WP_Post, product_id: string, option_id: string}
	 */
	private static function begin( string $nonce_action, bool $enhanced ): array {
		/* 1. Nonce — every state change is nonce-guarded (recoverable, never a die page). */
		$nonce = isset( $_POST['fp_basket_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['fp_basket_nonce'] ) ) : '';
		if ( '' === $nonce || false === wp_verify_nonce( $nonce, $nonce_action ) ) {
			self::fail( 'nonce', $enhanced );
		}

		/* 2. Session identity — a presented cookie that no longer resolves to a
		   live session never mutates anything; it is cleared so the guest can
		   simply retry. */
		$session        = self::resolve();
		$cookie_present = ! empty( $_COOKIE[ self::COOKIE_NAME ] );
		if ( $cookie_present && null === $session ) {
			self::clear_cookie();
			self::fail( 'session', $enhanced );
		}

		/* 3. Product — a currently published catalog record only. */
		$product_id = isset( $_POST['fp_product'] ) ? sanitize_text_field( wp_unslash( $_POST['fp_product'] ) ) : '';
		$product    = self::published_product( $product_id );
		if ( null === $product ) {
			self::fail( 'product', $enhanced );
		}

		/* 4. Option — a Product with reviewed options requires one of them (a
		   Color line always identifies its color); a Product without options
		   accepts none. */
		$option_id = isset( $_POST['fp_option'] ) ? sanitize_text_field( wp_unslash( $_POST['fp_option'] ) ) : '';
		$options   = self::product_options( $product );
		if ( array() === $options ) {
			$option_valid = '' === $option_id;
		} else {
			$option_valid = '' !== $option_id && '' !== self::option_label( $product, $option_id );
		}
		if ( ! $option_valid ) {
			self::fail( 'option', $enhanced );
		}

		return array(
			'session'    => $session,
			'product'    => $product,
			'product_id' => $product_id,
			'option_id'  => $option_id,
		);
	}

	/** 5. Quantity — a positive whole unit (confirmed rules when present). */
	private static function validated_quantity( WP_Post $product, bool $enhanced ): int {
		$raw      = isset( $_POST['fp_quantity'] ) ? wp_unslash( (string) $_POST['fp_quantity'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below
		$quantity = filter_var( $raw, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1, 'max_range' => self::MAX_QUANTITY ) ) );
		if ( false === $quantity || ! self::quantity_allowed( $product, (int) $quantity ) ) {
			self::fail( 'quantity', $enhanced );
		}
		return (int) $quantity;
	}

	public static function handle_add(): void {
		$enhanced = self::is_enhanced();

		[
			'session'    => $session,
			'product'    => $product,
			'product_id' => $product_id,
			'option_id'  => $option_id,
		] = self::begin( self::NONCE_ADD_ACTION, $enhanced );
		$quantity = self::validated_quantity( $product, $enhanced );

		/* Everything validated: only now is any state created or changed. */
		if ( null === $session ) {
			$session = self::create_session();
		}

		self::merge_line( $session, $product_id, $option_id, $quantity );
		self::touch( $session['hash'] );

		self::respond( 'added', $product_id, $enhanced );
	}

	/** Update the quantity of one stored line (same identity, new quantity). */
	public static function handle_update(): void {
		$enhanced = self::is_enhanced();

		[
			'session'    => $session,
			'product'    => $product,
			'product_id' => $product_id,
			'option_id'  => $option_id,
		] = self::begin( self::NONCE_UPDATE_ACTION, $enhanced );
		if ( null === $session ) {
			self::fail( 'session', $enhanced );
		}
		$quantity = self::validated_quantity( $product, $enhanced );

		if ( ! self::update_line( $session, $product_id, $option_id, $quantity ) ) {
			self::fail( 'line', $enhanced );
		}
		self::touch( $session['hash'] );

		self::respond( 'updated', $product_id, $enhanced );
	}

	/** Drop one stored line from the basket. */
	public static function handle_remove(): void {
		$enhanced = self::is_enhanced();

		[
			'session'    => $session,
			'product_id' => $product_id,
			'option_id'  => $option_id,
		] = self::begin( self::NONCE_REMOVE_ACTION, $enhanced );
		if ( null === $session ) {
			self::fail( 'session', $enhanced );
		}

		if ( ! self::remove_line( $session, $product_id, $option_id ) ) {
			self::fail( 'line', $enhanced );
		}
		self::touch( $session['hash'] );

		self::respond( 'removed', $product_id, $enhanced );
	}

	/** The progressive enhancement flag of the running request. */
	private static function is_enhanced(): bool {
		return isset( $_POST['fp_enhanced'] ) && '1' === (string) wp_unslash( $_POST['fp_enhanced'] );
	}

	/** Success: the authoritative state (count, mini basket, full view) after the mutation. */
	private static function respond( string $code, string $item, bool $enhanced ): void {
		if ( $enhanced ) {
			wp_send_json(
				array(
					'ok'      => true,
					'count'   => self::count(),
					'message' => self::notice_message( $code, $item ),
					'mini'    => self::render_mini_lines(),
					'view'    => self::render_view(),
				)
			);
		}

		self::redirect(
			array(
				'fpcq_notice' => $code,
				'fpcq_item'   => $item,
			)
		);
	}

	/** Recoverable failure: no mutation happened; redirect back with a message. */
	private static function fail( string $code, bool $enhanced ): void {
		if ( $enhanced ) {
			wp_send_json(
				array(
					'ok'      => false,
					'message' => self::notice_message( $code ),
				)
			);
		}
		self::redirect( array( 'fpcq_notice' => $code ) );
	}

	/**
	 * Collect sessions that expired 30 days after their last activity.
	 * Runs daily (fpcq_basket_gc) and is idempotent; Quote Requests are
	 * never affected — only anonymous basket sessions expire.
	 *
	 * @return int Deleted session rows.
	 */
	public static function gc(): int {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::SESSION_TTL );
		return (int) $wpdb->query(
			$wpdb->prepare( 'DELETE FROM ' . $wpdb->prefix . 'basket_sessions WHERE last_activity < %s', $cutoff )
		);
	}

	/**
	 * A front-end request presenting a cookie that no longer resolves (an
	 * expired or swept session) is cleared once and bounced to the same URL
	 * with a recoverable notice: the guest lands on the empty state with a
	 * route back to Tienda instead of a silently invisible basket.
	 */
	public static function reap_expired_cookie(): void {
		if ( null === self::current_token() || null !== self::resolve() ) {
			return;
		}
		self::clear_cookie();
		if ( isset( $_GET['fpcq_notice'] ) ) {
			return; /* this URL is already the bounce — never loop it */
		}
		wp_safe_redirect( add_query_arg( array( 'fpcq_notice' => 'expired' ), self::current_url() ) );
		exit;
	}

	private static function redirect( array $args ): void {
		$target = wp_get_referer() ?: home_url( '/' );
		wp_safe_redirect( add_query_arg( $args, $target ) );
		exit;
	}

	/** The human message for one recoverable outcome (server-side text only). */
	public static function notice_message( string $code, string $item = '' ): ?string {
		switch ( $code ) {
			case 'added':
				$product = '' !== $item ? self::published_product( $item ) : null;
				return $product
					? sprintf( '«%s» se agregó a tu cotización.', get_the_title( $product ) )
					: 'Tu producto se agregó a la cotización.';
			case 'updated':
				return 'Tu cotización se actualizó.';
			case 'removed':
				$product = '' !== $item ? self::published_product( $item ) : null;
				return $product
					? sprintf( '«%s» se quitó de tu cotización.', get_the_title( $product ) )
					: 'El producto se quitó de tu cotización.';
			case 'nonce':
				return 'Tu solicitud venció. Inténtalo de nuevo.';
			case 'session':
				return 'No encontramos tu cotización anterior. Vuelve a agregar tus productos.';
			case 'expired':
				return 'Tu cotización anterior expiró. Explora la tienda y vuelve a agregar tus productos.';
			case 'product':
				return 'Ese producto no está disponible para cotizar.';
			case 'option':
				return 'Esa opción no está disponible para este producto.';
			case 'quantity':
				return 'La cantidad debe ser un número entero mayor que cero.';
			case 'line':
				return 'Esa línea ya no está en tu cotización.';
			case 'basket':
				return 'Tu cotización está vacía. Agrega productos antes de enviar tu solicitud.';
			case 'token':
				return 'Tu solicitud ya no es válida. Vuelve a cargar la página e inténtalo de nuevo.';
			case 'spam':
				return 'No pudimos aceptar tu solicitud. Completa el formulario y envíalo de nuevo.';
			case 'too_fast':
				return 'Tómate un momento para completar el formulario y vuelve a enviarlo.';
			case 'throttled':
				return 'Has enviado varias solicitudes en poco tiempo. Espera un momento antes de enviar otra.';
			case 'request_invalid':
				return 'Revisa el formulario: hay campos que necesitan tu atención.';
			case 'request_failed':
				return 'No pudimos guardar tu solicitud. Tu cotización sigue activa; inténtalo de nuevo.';
			case 'address_unavailable':
				return 'La confirmación asistida de direcciones no está disponible en este momento; escribe la dirección de despacho manualmente.';
			case 'address_query':
				return 'Escribe al menos 3 caracteres para buscar una dirección.';
			case 'address_review':
				return 'Revisa la dirección encontrada y confírmala antes de enviar.';
			case 'address_confirmed':
				return 'Dirección confirmada. Continúa con el resto de tus datos.';
			case 'address_cleared':
				return 'Dirección descartada. Busca otra o escríbela manualmente.';
			case 'address_error':
				return 'No pudimos confirmar esa dirección. Escríbela manualmente abajo.';
		}
		return null;
	}

	/* ------------------------------------------------------------------ */
	/* Rendering (versioned public fpcq- markup, v1)                       */
	/* ------------------------------------------------------------------ */

	/**
	 * The shared quantity chooser: an authoritative POST form. Catalog cards
	 * wrap it in a <details> disclosure (opening it is choosing a quantity);
	 * the product page exposes it directly (approved v7 variant A). Agregar
	 * a cotización never adds an unseen quantity. A Product with reviewed
	 * options (the Caja Universal Color configurations) also requires one
	 * reviewed option — the radio group is required, so no Color line is
	 * ever added without identifying its color.
	 */
	public static function render_add_form( WP_Post $product, string $return_url ): string {
		return sprintf(
			'<form class="fpcq-basket-add" method="post" action="%1$s"><label class="fpcq-add-label"><span>Cantidad</span><input class="fpcq-add-qty" type="number" name="fp_quantity" value="1" min="1" step="1" inputmode="numeric" autocomplete="off"></label>%2$s<input type="hidden" name="action" value="fp_basket_add"><input type="hidden" name="fp_product" value="%3$s"><input type="hidden" name="_wp_http_referer" value="%4$s"><input type="hidden" name="fp_basket_nonce" value="%5$s"><button class="fpcq-add-submit" type="submit">Agregar a cotización</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			self::render_option_chooser( self::product_options( $product ) ),
			esc_attr( (string) get_post_meta( $product->ID, '_fp_source_id', true ) ),
			esc_url( $return_url ),
			esc_attr( wp_create_nonce( self::NONCE_ADD_ACTION ) )
		);
	}

	/** The required option group of a chooser (one reviewed option per line). */
	private static function render_option_chooser( array $options ): string {
		if ( array() === $options ) {
			return '';
		}

		$group_labels = array( 'color' => 'Color' );
		$group        = (string) ( $options[0]['group'] ?? 'color' );
		$legend      = $group_labels[ $group ] ?? ucfirst( $group );

		$radios = '';
		foreach ( $options as $option ) {
			$radios .= sprintf(
				'<label class="fpcq-add-option"><input type="radio" name="fp_option" value="%s" required><span>%s</span></label>',
				esc_attr( (string) $option['id'] ),
				esc_html( (string) ( $option['label'] ?? $option['id'] ) )
			);
		}

		return sprintf(
			'<fieldset class="fpcq-add-options"><legend class="fpcq-add-options-legend">%s</legend>%s</fieldset>',
			esc_html( $legend ),
			$radios
		);
	}

	/** Best-effort absolute URL of the current front-end request (the PRG target). */
	public static function current_url(): string {
		$request = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		return home_url( $request );
	}

	/**
	 * Header widget: the Cotización (n) count (distinct lines, not units)
	 * opening the mini basket, plus the recoverable status notice.
	 */
	public static function render_button(): string {
		return sprintf(
			'<div class="fpcq-basket-widget" data-fpcq-version="1"><details class="fpcq-basket"><summary class="fp-btn fp-btn-sm fpcq-basket-toggle"><span class="fpcq-basket-label" data-fpcq-basket-count>%s</span></summary><div class="fpcq-basket-mini"><div class="fpcq-basket-mini-lines" data-fpcq-basket-mini>%s</div><a class="fpcq-basket-view" href="%s">Ver cotización completa</a></div></details>%s</div>',
			esc_html( sprintf( 'Cotización (%d)', self::count() ) ),
			self::render_mini_lines(),
			esc_url( home_url( '/cotizacion/' ) ),
			self::render_notice()
		);
	}

	/** Mini basket lines — Product, quantity, option; empty state otherwise. */
	private static function render_mini_lines(): string {
		$lines = self::resolved_lines();
		if ( array() === $lines ) {
			return '<p class="fpcq-basket-empty">Tu cotización está vacía.</p>';
		}

		$items = '';
		foreach ( $lines as $line ) {
			$option = '' === $line['option_label'] ? '' : sprintf( '<span class="fpcq-basket-line-option">%s</span>', esc_html( $line['option_label'] ) );
			$items .= sprintf(
				'<li class="fpcq-basket-line"><a class="fpcq-basket-line-title" href="%1$s">%2$s</a>%3$s<span class="fpcq-basket-line-qty">%4$s</span></li>',
				esc_url( get_permalink( $line['product'] ) ),
				esc_html( get_the_title( $line['product'] ) ),
				$option,
				esc_html( self::quantity_label( $line['quantity'] ) )
			);
		}

		return sprintf( '<ul class="fpcq-basket-lines">%s</ul>', $items );
	}

	/** The status notice for the PRG flow (absent without a notice in the URL). */
	private static function render_notice(): string {
		$code = isset( $_GET['fpcq_notice'] ) ? sanitize_key( wp_unslash( $_GET['fpcq_notice'] ) ) : '';
		$item = isset( $_GET['fpcq_item'] ) ? sanitize_text_field( wp_unslash( $_GET['fpcq_item'] ) ) : '';
		if ( '' === $code ) {
			return '';
		}
		$message = self::notice_message( $code, $item );
		if ( null === $message ) {
			return '';
		}
		return sprintf( '<p class="fpcq-basket-status" role="status" data-fpcq-basket-status>%s</p>', esc_html( $message ) );
	}

	/** One line's edit forms: update the quantity, or remove the line. */
	private static function render_edit_forms( array $line ): string {
		$source_id = (string) get_post_meta( $line['product']->ID, '_fp_source_id', true );
		$option_id = $line['option_id'];
		$referer   = home_url( '/cotizacion/' );

		$update = sprintf(
			'<form class="fpcq-basket-update" method="post" action="%1$s"><label class="fpcq-edit-label"><span>Cantidad</span><input class="fpcq-edit-qty" type="number" name="fp_quantity" value="%2$d" min="1" step="1" inputmode="numeric" autocomplete="off"></label><input type="hidden" name="action" value="fp_basket_update"><input type="hidden" name="fp_product" value="%3$s"><input type="hidden" name="fp_option" value="%4$s"><input type="hidden" name="_wp_http_referer" value="%5$s"><input type="hidden" name="fp_basket_nonce" value="%6$s"><button class="fpcq-edit-submit" type="submit">Actualizar</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			$line['quantity'],
			esc_attr( $source_id ),
			esc_attr( $option_id ),
			esc_url( $referer ),
			esc_attr( wp_create_nonce( self::NONCE_UPDATE_ACTION ) )
		);

		$remove = sprintf(
			'<form class="fpcq-basket-remove" method="post" action="%1$s"><input type="hidden" name="action" value="fp_basket_remove"><input type="hidden" name="fp_product" value="%2$s"><input type="hidden" name="fp_option" value="%3$s"><input type="hidden" name="_wp_http_referer" value="%4$s"><input type="hidden" name="fp_basket_nonce" value="%5$s"><button class="fpcq-remove-submit" type="submit">Quitar</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( $source_id ),
			esc_attr( $option_id ),
			esc_url( $referer ),
			esc_attr( wp_create_nonce( self::NONCE_REMOVE_ACTION ) )
		);

		return sprintf( '<div class="fpcq-basketview-actions">%s%s</div>', $update, $remove );
	}

	/**
	 * The full /cotización/ basket view: every live line with its own update
	 * and remove forms (issue #7), so the basket is fully editable with or
	 * without JavaScript. This method renders only the mirrored basket
	 * section; the /cotización/ page itself is composed by render_page()
	 * (the request form below, the confirmation above).
	 */
	public static function render_view(): string {
		$lines = self::resolved_lines();

		if ( array() === $lines ) {
			return sprintf(
				'<section class="fpcq-basketview" data-fpcq-version="1" data-fpcq-basket-view><h2 class="fpcq-basketview-title">%s</h2><p class="fpcq-basketview-note">Explora la tienda y agrega productos para solicitar una cotización mayorista.</p><a class="fpcq-basketview-cta" href="%s">Explorar la tienda</a></section>',
				'Tu cotización está vacía',
				esc_url( home_url( '/tienda/' ) )
			);
		}

		$items = '';
		foreach ( $lines as $line ) {
			$option = '' === $line['option_label'] ? '' : sprintf( '<span class="fpcq-basketview-option">%s</span>', esc_html( $line['option_label'] ) );
			$items .= sprintf(
				'<li class="fpcq-basketview-line"><div class="fpcq-basketview-line-info"><a class="fpcq-basketview-line-title" href="%1$s">%2$s</a>%3$s<span class="fpcq-basketview-qty">%4$s</span></div>%5$s</li>',
				esc_url( get_permalink( $line['product'] ) ),
				esc_html( get_the_title( $line['product'] ) ),
				$option,
				esc_html( self::quantity_label( $line['quantity'] ) ),
				self::render_edit_forms( $line )
			);
		}

		return sprintf(
			'<section class="fpcq-basketview" data-fpcq-version="1" data-fpcq-basket-view><h2 class="fpcq-basketview-title">Tu cotización</h2><ul class="fpcq-basketview-lines">%s</ul><p class="fpcq-basketview-note">Revisa tus productos y cantidades; el formulario de envío está más abajo en esta misma página.</p></section>',
			$items
		);
	}

	/**
	 * The /cotización/ page — the sole final submission surface: after a
	 * successful submission the confirmation with the Request Reference;
	 * otherwise the editable basket view with the request form below it
	 * (rendered only while basket lines exist).
	 */
	public static function render_page(): string {
		$confirmation = Freeplast_CQ_Request::render_confirmation();
		if ( '' !== $confirmation ) {
			return $confirmation;
		}

		return self::render_view() . Freeplast_CQ_Request::render_form();
	}
}
