<?php
/**
 * The persistent, anonymous Quote Basket (issue #6).
 *
 * A guest selects a quantity and adds a synchronized Product to a secure
 * server-side basket, then inspects it through the header count and the mini
 * basket across refreshes:
 *
 *   - Anonymous and cookie-based, including while a WordPress staff account
 *     happens to be logged in. There is no customer-account linking and no
 *     guest-to-login merge behavior.
 *   - The cookie carries only a random 256-bit opaque token (Secure,
 *     HttpOnly, SameSite=Lax, 30 days) — never product, option or customer
 *     data. Only its sha256 hash is persisted server-side, in the versioned
 *     basket_sessions table (migration 4). Sessions expire 30 days after
 *     the last activity.
 *   - Adding is an authoritative POST (admin-post.php, action
 *     fp_basket_add) guarded by a nonce. Every mutation revalidates session
 *     identity, Product lifecycle/visibility, option identity and positive
 *     whole-unit quantity (confirmed minimum/step rules are enforced when
 *     present; while minimums stay unconfirmed any positive whole unit is
 *     accepted and no minimum is claimed).
 *   - Invalid nonce, session, Product, option or quantity mutate nothing and
 *     return a recoverable message — POST-redirect-GET for the plain flow,
 *     JSON for the progressive JavaScript enhancement (the server remains
 *     authoritative; the script only mirrors the state it returns).
 *   - Re-adding the same Product/option merges quantities; different
 *     options stay separate lines. The header count is the number of
 *     distinct lines, not total units.
 *
 * Markup (versioned public fpcq- classes, v1) — semantic and functional
 * under a stock block theme; the freeplast theme supplies the v6 look:
 *
 *   freeplast/basket-button  header widget: Cotización (n) + mini basket
 *   freeplast/basket         the full /cotizacion/ view
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
	public const NONCE_ACTION = 'fp_basket_add';

	/** Server-side sessions expire 30 days after the last activity. */
	private const SESSION_TTL = 30 * DAY_IN_SECONDS;

	/** Defensive upper bound for one submitted whole-unit quantity. */
	private const MAX_QUANTITY = 1000000;

	public static function register(): void {
		add_action( 'admin_post_fp_basket_add', array( self::class, 'handle_add' ) );
		add_action( 'admin_post_nopriv_fp_basket_add', array( self::class, 'handle_add' ) );

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
			array( 'render_callback' => array( self::class, 'render_view' ) )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Schema                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * The versioned session table (migration 4). Stores only token hashes
	 * and line data — never the opaque token itself.
	 */
	public static function create_table(): void {
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

		$lines = json_decode( (string) $row->basket_lines, true );
		return array(
			'hash'  => $hash,
			'lines' => is_array( $lines ) ? $lines : array(),
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

	/** Merge the line (same Product + option) or append it as a new line. */
	private static function merge_line( array $session, string $product_id, string $option_id, int $quantity ): void {
		global $wpdb;

		$lines  = $session['lines'];
		$merged = false;
		foreach ( $lines as $index => $line ) {
			if ( ( $line['product'] ?? '' ) === $product_id && ( $line['option'] ?? '' ) === $option_id ) {
				$lines[ $index ]['quantity'] = (int) $line['quantity'] + $quantity;
				$merged                      = true;
				break;
			}
		}
		if ( ! $merged ) {
			$lines[] = array(
				'product'  => $product_id,
				'option'   => $option_id,
				'quantity' => $quantity,
			);
		}

		$wpdb->update(
			$wpdb->prefix . 'basket_sessions',
			array( 'basket_lines' => wp_json_encode( array_values( $lines ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
			array( 'session_hash' => $session['hash'] ),
			array( '%s' ),
			array( '%s' )
		);
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

	/** The reviewed label of one option, or '' when the id is unknown to the product. */
	private static function option_label( WP_Post $product, string $option_id ): string {
		if ( '' === $option_id ) {
			return '';
		}
		$options = json_decode( (string) get_post_meta( $product->ID, '_fp_options', true ), true );
		if ( ! is_array( $options ) ) {
			return '';
		}
		foreach ( $options as $option ) {
			if ( is_array( $option ) && ( $option['id'] ?? '' ) === $option_id ) {
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
	/* The authoritative add operation                                     */
	/* ------------------------------------------------------------------ */

	public static function handle_add(): void {
		$enhanced = isset( $_POST['fp_enhanced'] ) && '1' === (string) wp_unslash( $_POST['fp_enhanced'] );

		/* 1. Nonce — every state change is nonce-guarded (recoverable, never a die page). */
		$nonce = isset( $_POST['fp_basket_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['fp_basket_nonce'] ) ) : '';
		if ( '' === $nonce || false === wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
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

		/* 4. Option — a submitted option must be one of the reviewed options. */
		$option_id = isset( $_POST['fp_option'] ) ? sanitize_text_field( wp_unslash( $_POST['fp_option'] ) ) : '';
		if ( '' !== $option_id && '' === self::option_label( $product, $option_id ) ) {
			self::fail( 'option', $enhanced );
		}

		/* 5. Quantity — a positive whole unit (confirmed rules when present). */
		$raw      = isset( $_POST['fp_quantity'] ) ? wp_unslash( (string) $_POST['fp_quantity'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below
		$quantity = filter_var( $raw, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1, 'max_range' => self::MAX_QUANTITY ) ) );
		if ( false === $quantity || ! self::quantity_allowed( $product, (int) $quantity ) ) {
			self::fail( 'quantity', $enhanced );
		}

		/* Everything validated: only now is any state created or changed. */
		if ( null === $session ) {
			$session = self::create_session();
		}

		self::merge_line( $session, $product_id, $option_id, (int) $quantity );
		self::touch( $session['hash'] );

		if ( $enhanced ) {
			wp_send_json(
				array(
					'ok'      => true,
					'count'   => self::count(),
					'message' => self::notice_message( 'added', $product_id ),
					'mini'    => self::render_mini_lines(),
				)
			);
		}

		self::redirect(
			array(
				'fpcq_notice' => 'added',
				'fpcq_item'   => $product_id,
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
			case 'nonce':
				return 'Tu solicitud venció. Inténtalo de nuevo.';
			case 'session':
				return 'No encontramos tu cotización anterior. Vuelve a agregar tus productos.';
			case 'product':
				return 'Ese producto no está disponible para cotizar.';
			case 'option':
				return 'Esa opción no está disponible para este producto.';
			case 'quantity':
				return 'La cantidad debe ser un número entero mayor que cero.';
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
	 * a cotización never adds an unseen quantity.
	 */
	public static function render_add_form( string $source_id, string $return_url ): string {
		return sprintf(
			'<form class="fpcq-basket-add" method="post" action="%1$s"><label class="fpcq-add-label"><span>Cantidad</span><input class="fpcq-add-qty" type="number" name="fp_quantity" value="1" min="1" step="1" inputmode="numeric" autocomplete="off"></label><input type="hidden" name="action" value="fp_basket_add"><input type="hidden" name="fp_product" value="%2$s"><input type="hidden" name="_wp_http_referer" value="%3$s"><input type="hidden" name="fp_basket_nonce" value="%4$s"><button class="fpcq-add-submit" type="submit">Agregar a cotización</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( $source_id ),
			esc_url( $return_url ),
			esc_attr( wp_create_nonce( self::NONCE_ACTION ) )
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

	/**
	 * The full /cotización/ view. Read-only in this slice: updating and
	 * removing lines arrives with the basket-editing slice (issue #7) and
	 * the request form with issue #8 — this page remains the sole
	 * quotation surface.
	 */
	public static function render_view(): string {
		$lines = self::resolved_lines();

		if ( array() === $lines ) {
			return sprintf(
				'<section class="fpcq-basketview" data-fpcq-version="1"><h2 class="fpcq-basketview-title">%s</h2><p class="fpcq-basketview-note">Explora la tienda y agrega productos para solicitar una cotización mayorista.</p><a class="fpcq-basketview-cta" href="%s">Explorar la tienda</a></section>',
				'Tu cotización está vacía',
				esc_url( home_url( '/tienda/' ) )
			);
		}

		$items = '';
		foreach ( $lines as $line ) {
			$option = '' === $line['option_label'] ? '' : sprintf( '<span class="fpcq-basketview-option">%s</span>', esc_html( $line['option_label'] ) );
			$items .= sprintf(
				'<li class="fpcq-basketview-line"><a class="fpcq-basketview-line-title" href="%1$s">%2$s</a>%3$s<span class="fpcq-basketview-qty">%4$s</span></li>',
				esc_url( get_permalink( $line['product'] ) ),
				esc_html( get_the_title( $line['product'] ) ),
				$option,
				esc_html( self::quantity_label( $line['quantity'] ) )
			);
		}

		return sprintf(
			'<section class="fpcq-basketview" data-fpcq-version="1"><h2 class="fpcq-basketview-title">Tu cotización</h2><ul class="fpcq-basketview-lines">%s</ul><p class="fpcq-basketview-note">Revisa tus productos y cantidades. Editar líneas y enviar tu solicitud a Freeplast se habilitan en el próximo paso.</p><a class="fpcq-basketview-cta" href="%s">Seguir explorando la tienda</a></section>',
			$items,
			esc_url( home_url( '/tienda/' ) )
		);
	}
}
