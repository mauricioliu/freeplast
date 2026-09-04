<?php
/**
 * The sales administration workflow for Quote Requests (issue #9).
 *
 * Cotizaciones turns the persisted records into a focused, least-privilege
 * sales workspace on top of the submission slice (issue #8):
 *
 *   - Access: the dedicated sales capability (manage_freeplast_quotes,
 *     granted to administrators by migration 6) plus the Ventas Freeplast
 *     role created by migration 7 — exactly read + the sales capability,
 *     nothing else. Every page and every state-changing operation is
 *     capability-guarded and nonce-guarded (logged-out attempts are
 *     denied too).
 *   - The list sorts by Request Reference, company, email, created date
 *     and Request Status (the current contact details feed the company/
 *     email columns) and searches by reference, company or email, with a
 *     status filter and an explicit empty state. No bulk CSV export
 *     exists.
 *   - The detail separates the immutable Submitted Details from the
 *     editable Current Contact Details. A correction updates the current
 *     copy (and the denormalized list/search columns) while the submitted
 *     record stays untouched; the appended history event names the
 *     changed fields, the time and the staff identity — never the PII
 *     values. Invalid corrections retain the entered values and explain
 *     the problem inline.
 *   - Internal Sales Notes append with a timestamp and the author and
 *     never render outside the administration (the records are
 *     non-public).
 *   - Request Status: new → contacted → quoted → won/lost with permitted
 *     skips (any strictly forward move), cancelled from new/contacted/
 *     quoted. The terminal won/lost/cancelled states leave only through
 *     the explicit reopen action, which returns the request to
 *     contacted.
 *   - Every state change (correction, note, transition, reopen) validates
 *     the per-object nonce and the capability, and records the staff
 *     identity/time in the history.
 *
 * @package Freeplast_Catalog_Quotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Freeplast_CQ_Admin {

	/** The admin page slugs (stable since the issue #8 slice). */
	public const LIST_SLUG   = 'fp-quotes';
	public const DETAIL_SLUG = 'fp-quote';

	/** The four guarded state-changing operations. */
	public const ACTION_CONTACT = 'fp_quote_update_contact';
	public const ACTION_NOTE    = 'fp_quote_add_note';
	public const ACTION_STATUS  = 'fp_quote_set_status';
	public const ACTION_REOPEN  = 'fp_quote_reopen';

	/** The Request Status vocabulary (see the PRD). */
	public const STATUSES = array( 'new', 'contacted', 'quoted', 'won', 'lost', 'cancelled' );

	/** Spanish display labels of the Request Status vocabulary. */
	private const STATUS_LABELS = array(
		'new'       => 'nueva',
		'contacted' => 'contactada',
		'quoted'    => 'cotizada',
		'won'       => 'ganada',
		'lost'      => 'perdida',
		'cancelled' => 'cancelada',
	);

	/** Forward ranks — won and lost share the terminal rank. */
	private const STATUS_RANKS = array( 'new' => 0, 'contacted' => 1, 'quoted' => 2, 'won' => 3, 'lost' => 3 );

	/** The statuses that leave only through the explicit reopen operation. */
	private const TERMINAL_STATUSES = array( 'won', 'lost', 'cancelled' );

	private const MAX_NOTE = 2000;

	/** The correctable contact fields: form key => stored current key. */
	private const CONTACT_KEYS = array(
		'nombre'     => 'nombre',
		'telefono'   => 'telefono',
		'email'      => 'email',
		'empresa'    => 'empresa',
		'rut'        => 'rut',
		'giro'       => 'giro',
		'direccion'  => 'direccion_despacho',
	);

	public static function register(): void {
		/* Menu pages register on the admin_menu hook — the canonical seam
	   after WordPress has built the core menus (registering earlier makes
	   the re-parent loop rewrite the top-level slug and breaks the detail
	   page's access resolution). The operations attach immediately. */
		add_action( 'admin_menu', array( self::class, 'register_pages' ) );

		/* Logged-out attempts hit the same handlers and die on the
		   capability guard — no blank admin-post response. */
		$operations = array(
			self::ACTION_CONTACT => 'handle_update_contact',
			self::ACTION_NOTE    => 'handle_add_note',
			self::ACTION_STATUS  => 'handle_set_status',
			self::ACTION_REOPEN  => 'handle_reopen',
		);
		foreach ( $operations as $action => $handler ) {
			add_action( 'admin_post_' . $action, array( self::class, $handler ) );
			add_action( 'admin_post_nopriv_' . $action, array( self::class, $handler ) );
		}
	}

	/** The Cotizaciones pages (top-level list + detail submenu). */
	public static function register_pages(): void {
		add_menu_page(
			'Cotizaciones',
			'Cotizaciones',
			Freeplast_CQ_Request::CAPABILITY,
			self::LIST_SLUG,
			array( self::class, 'render_list' ),
			'dashicons-clipboard',
			26
		);
		add_submenu_page(
			self::LIST_SLUG,
			'Solicitud',
			'Solicitud',
			Freeplast_CQ_Request::CAPABILITY,
			self::DETAIL_SLUG,
			array( self::class, 'render_detail' )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Status model                                                        */
	/* ------------------------------------------------------------------ */

	private static function status_label( string $status ): string {
		return self::STATUS_LABELS[ $status ] ?? $status;
	}

	/** The forward rank of a status (won/lost share the terminal rank). */
	private static function status_rank( string $status ): int {
		return self::STATUS_RANKS[ $status ] ?? -1;
	}

	private static function is_terminal( string $status ): bool {
		return in_array( $status, self::TERMINAL_STATUSES, true );
	}

	/**
	 * The permitted direct targets: any strictly forward move (so
	 * intermediate steps may be skipped) plus cancelled from the
	 * non-terminal states. Terminal statuses have no direct target — the
	 * explicit reopen operation is the only way out (back to contacted).
	 */
	private static function permitted_targets( string $status ): array {
		if ( self::is_terminal( $status ) ) {
			return array();
		}
		$targets = array();
		foreach ( array( 'contacted', 'quoted', 'won', 'lost' ) as $target ) {
			if ( self::status_rank( $target ) > self::status_rank( $status ) ) {
				$targets[] = $target;
			}
		}
		$targets[] = 'cancelled';
		return $targets;
	}

	/* ------------------------------------------------------------------ */
	/* Record access                                                       */
	/* ------------------------------------------------------------------ */

	private static function json_meta( int $post_id, string $key ): array {
		$decoded = json_decode( (string) get_post_meta( $post_id, $key, true ), true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/** The immutable Submitted Details as stored at submission time. */
	private static function submitted_details( int $post_id ): array {
		return self::json_meta( $post_id, '_fpq_customer' );
	}

	/**
	 * The current (correctable) contact details: the stored current copy,
	 * falling back to the submitted details for records persisted before
	 * this slice (migration 7 backfills the copy; this keeps rendering
	 * total either way).
	 */
	private static function current_details( int $post_id ): array {
		$current = self::json_meta( $post_id, '_fpq_current' );
		if ( array() !== $current ) {
			return $current;
		}
		return self::submitted_details( $post_id );
	}

	/** Append one history event (field names only — never PII values). */
	private static function append_history( int $post_id, array $event ): void {
		$history   = self::json_meta( $post_id, '_fpq_history' );
		$history[] = $event;
		update_post_meta( $post_id, '_fpq_history', Freeplast_CQ_Request::encode_meta( $history ) );
	}

	/** The acting staff identity (user id + display name). */
	private static function staff_identity(): array {
		$user_id = get_current_user_id();
		$user    = $user_id > 0 ? get_userdata( $user_id ) : null;
		return array(
			'staff'      => $user_id,
			'staff_name' => null !== $user ? $user->display_name : '',
		);
	}

	/* ------------------------------------------------------------------ */
	/* Guards                                                              */
	/* ------------------------------------------------------------------ */

	/** The dedicated sales capability is required for every surface. */
	private static function require_capability(): void {
		if ( ! current_user_can( Freeplast_CQ_Request::CAPABILITY ) ) {
			wp_die( 'Lo sentimos, no tienes permisos para administrar cotizaciones.', '', array( 'response' => 403 ) );
		}
	}

	private static function require_post(): WP_Post {
		$id   = isset( $_POST['p'] ) ? absint( $_POST['p'] ) : 0;
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post instanceof WP_Post || Freeplast_CQ_Request::POST_TYPE !== $post->post_type ) {
			wp_die( 'Solicitud no encontrada.', '', array( 'response' => 404 ) );
		}
		return $post;
	}

	/** Per-object, per-operation nonce verification. */
	private static function verified_nonce( WP_Post $post, string $verb, string $field ): bool {
		$nonce = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
		return '' !== $nonce && false !== wp_verify_nonce( $nonce, 'fp-quote-' . $verb . '-' . $post->ID );
	}

	/**
	 * The guard shared by every state-changing operation: capability,
	 * fp_quote record, then the per-object nonce — a bad nonce redirects
	 * back to the detail (which exits) and never falls through.
	 */
	private static function guarded_post( string $verb, string $nonce_field ): WP_Post {
		self::require_capability();
		$post = self::require_post();
		if ( ! self::verified_nonce( $post, $verb, $nonce_field ) ) {
			self::redirect_detail( $post->ID, 'nonce' );
		}
		return $post;
	}

	private static function redirect_detail( int $post_id, string $code ): void {
		wp_safe_redirect(
			add_query_arg(
				'fpqa_notice',
				$code,
				admin_url( 'admin.php?page=' . self::DETAIL_SLUG . '&p=' . $post_id )
			)
		);
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* State-changing operations                                           */
	/* ------------------------------------------------------------------ */

	public static function handle_update_contact(): void {
		$post = self::guarded_post( 'contact', 'fp_contact_nonce' );

		$validated = self::validated_contact();
		if ( array() !== $validated['errors'] ) {
			self::store_contact_attempt( $post->ID, $validated['values'], $validated['errors'] );
			self::redirect_detail( $post->ID, 'contact_invalid' );
		}
		$values = $validated['values'];

		/* The canonical current copy, one shared shape: the corrected values
		   renamed onto their stored keys (CONTACT_KEYS), then derived through
		   Freeplast_CQ_Request::current_contact_copy. */
		$corrected = array();
		foreach ( self::CONTACT_KEYS as $form_key => $stored_key ) {
			$corrected[ $stored_key ] = $values[ $form_key ];
		}
		$current = Freeplast_CQ_Request::current_contact_copy( $corrected );

		/* Which stored fields actually change (names only — the history
		   never copies values). */
		$previous = self::current_details( $post->ID );
		$changed  = array();
		foreach ( $current as $key => $value ) {
			if ( (string) ( $previous[ $key ] ?? '' ) !== (string) $value ) {
				$changed[] = $key;
			}
		}

		update_post_meta( $post->ID, '_fpq_current', Freeplast_CQ_Request::encode_meta( $current ) );
		update_post_meta( $post->ID, '_fpq_empresa', $current['empresa'] ); /* the list/search columns follow the current details */
		update_post_meta( $post->ID, '_fpq_email', $current['email'] );
		delete_transient( self::attempt_key( $post->ID ) );

		if ( array() !== $changed ) {
			self::append_history(
				$post->ID,
				array_merge(
					array( 'type' => 'contact', 'fields' => $changed, 'time' => current_time( 'mysql' ) ),
					self::staff_identity()
				)
			);
		}
		self::redirect_detail( $post->ID, 'contact_updated' );
	}

	public static function handle_add_note(): void {
		$post = self::guarded_post( 'note', 'fp_note_nonce' );

		$text = isset( $_POST['fp_nota'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['fp_nota'] ) ) ) : '';
		if ( '' === $text || mb_strlen( $text ) > self::MAX_NOTE ) {
			self::redirect_detail( $post->ID, 'note_invalid' );
		}

		$notes   = self::json_meta( $post->ID, '_fpq_notes' );
		$notes[] = array_merge(
			array( 'time' => current_time( 'mysql' ), 'text' => $text ),
			self::staff_identity()
		);
		update_post_meta( $post->ID, '_fpq_notes', Freeplast_CQ_Request::encode_meta( $notes ) );
		self::redirect_detail( $post->ID, 'note_added' );
	}

	public static function handle_set_status(): void {
		$post = self::guarded_post( 'status', 'fp_status_nonce' );

		$target  = isset( $_POST['fp_status'] ) ? sanitize_key( wp_unslash( $_POST['fp_status'] ) ) : '';
		$current = (string) get_post_meta( $post->ID, '_fpq_status', true );
		if ( ! in_array( $target, self::permitted_targets( $current ), true ) ) {
			self::redirect_detail( $post->ID, 'bad_transition' );
		}

		update_post_meta( $post->ID, '_fpq_status', $target );
		self::append_history(
			$post->ID,
			array_merge(
				array( 'type' => 'status', 'from' => $current, 'to' => $target, 'time' => current_time( 'mysql' ) ),
				self::staff_identity()
			)
		);
		self::redirect_detail( $post->ID, 'status_updated' );
	}

	public static function handle_reopen(): void {
		$post = self::guarded_post( 'reopen', 'fp_reopen_nonce' );

		$current = (string) get_post_meta( $post->ID, '_fpq_status', true );
		if ( ! self::is_terminal( $current ) ) {
			self::redirect_detail( $post->ID, 'bad_transition' );
		}

		update_post_meta( $post->ID, '_fpq_status', 'contacted' );
		self::append_history(
			$post->ID,
			array_merge(
				array( 'type' => 'reopen', 'from' => $current, 'to' => 'contacted', 'time' => current_time( 'mysql' ) ),
				self::staff_identity()
			)
		);
		self::redirect_detail( $post->ID, 'reopened' );
	}

	/* ------------------------------------------------------------------ */
	/* Correction validation (mirrors the submission rules, contact subset) */
	/* ------------------------------------------------------------------ */

	/**
	 * Validate the correction fields: the same business rules as the
	 * submission form (Freeplast_CQ_Request), restricted to the
	 * correctable contact subset (the delivery address stays optional —
	 * a request without dispatch carries none). Values are kept for
	 * retention even when invalid.
	 *
	 * @return array{values: array, errors: array}
	 */
	private static function validated_contact(): array {
		$field = static function ( string $key ): string {
			$name = 'fp_' . $key;
			return isset( $_POST[ $name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) : '';
		};

		$values = array();
		$errors = array();
		foreach ( Freeplast_CQ_Request::TEXT_FIELDS as $key => $rule ) {
			$value = trim( $field( $key ) );
			if ( '' === $value ) {
				$errors[ $key ] = sprintf( '%s es obligatorio.', $rule['label'] );
			} elseif ( mb_strlen( $value ) > $rule['max'] ) {
				$errors[ $key ] = sprintf( '%s es demasiado largo (máximo %d caracteres).', $rule['label'], $rule['max'] );
			} else {
				$values[ $key ] = $value;
			}
		}

		if ( ! isset( $errors['email'] ) ) {
			$email = sanitize_email( $values['email'] );
			if ( false === is_email( $email ) ) {
				$errors['email'] = 'Ingresa un email válido.';
			} else {
				$values['email'] = $email;
			}
		}
		if ( ! isset( $errors['telefono'] ) && 1 !== preg_match( '/^\+?[0-9()\-\s.]{4,39}$/', $values['telefono'] ) ) {
			$errors['telefono'] = 'Ingresa un teléfono válido (por ejemplo +56 9 6844 4265).';
		}
		if ( ! isset( $errors['rut'] ) && 1 !== preg_match( '/^[0-9kK.\-\s]+$/', $values['rut'] ) ) {
			$errors['rut'] = 'Ingresa un RUT válido (por ejemplo 76.335.888-6).';
		}

		$direccion = trim( isset( $_POST['fp_direccion'] ) ? sanitize_textarea_field( wp_unslash( $_POST['fp_direccion'] ) ) : '' );
		if ( mb_strlen( $direccion ) > Freeplast_CQ_Request::MAX_DIRECCION ) {
			$errors['direccion'] = sprintf( 'La dirección de despacho es demasiado larga (máximo %d caracteres).', Freeplast_CQ_Request::MAX_DIRECCION );
		} else {
			$values['direccion'] = $direccion;
		}

		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}

	private static function attempt_key( int $post_id ): string {
		return 'fpqa_contact_' . $post_id . '_' . get_current_user_id();
	}

	private static function store_contact_attempt( int $post_id, array $values, array $errors ): void {
		set_transient( self::attempt_key( $post_id ), array( 'values' => $values, 'errors' => $errors ), 15 * MINUTE_IN_SECONDS );
	}

	/** The retained correction attempt: entered values + inline errors. */
	private static function read_contact_attempt( int $post_id ): array {
		$attempt = get_transient( self::attempt_key( $post_id ) );
		if ( ! is_array( $attempt ) ) {
			return array(
				'values' => array(),
				'errors' => array(),
			);
		}
		return array(
			'values' => is_array( $attempt['values'] ?? null ) ? $attempt['values'] : array(),
			'errors' => is_array( $attempt['errors'] ?? null ) ? $attempt['errors'] : array(),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Rendering (fpqa- v1)                                                */
	/* ------------------------------------------------------------------ */

	public static function render_list(): void {
		self::require_capability();

		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$estado  = isset( $_GET['estado'] ) ? sanitize_key( wp_unslash( $_GET['estado'] ) ) : '';
		$estado  = in_array( $estado, self::STATUSES, true ) ? $estado : '';
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'creada';
		$orderby = in_array( $orderby, array( 'referencia', 'empresa', 'email', 'estado', 'creada' ), true ) ? $orderby : 'creada';
		$order   = isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ? 'ASC' : 'DESC';

		$args = array(
			'post_type'        => Freeplast_CQ_Request::POST_TYPE,
			'post_status'      => 'private',
			'posts_per_page'   => 100,
			'no_found_rows'    => true,
			'suppress_filters' => true,
		);

		/* Ordering — the meta-sorted columns use one named EXISTS clause;
		   the record ID breaks ties deterministically. */
		$meta_query = array();
		if ( 'referencia' === $orderby ) {
			$args['orderby'] = array( 'title' => $order, 'ID' => $order );
		} elseif ( 'creada' === $orderby ) {
			$args['orderby'] = array( 'date' => $order, 'ID' => $order );
		} else {
			$sort_keys          = array(
				'empresa' => '_fpq_empresa',
				'email'   => '_fpq_email',
				'estado'  => '_fpq_status',
			);
			$meta_query['orden'] = array( 'key' => $sort_keys[ $orderby ] );
			$args['orderby']     = array( 'orden' => $order, 'ID' => $order );
		}

		/* Filtering — the search matches reference/company/email (current
		   details); the status filter matches the Request Status exactly. */
		if ( '' !== $search ) {
			$meta_query[] = array(
				'relation' => 'OR',
				array( 'key' => '_fpq_reference', 'value' => $search, 'compare' => 'LIKE' ),
				array( 'key' => '_fpq_empresa', 'value' => $search, 'compare' => 'LIKE' ),
				array( 'key' => '_fpq_email', 'value' => $search, 'compare' => 'LIKE' ),
			);
		}
		if ( '' !== $estado ) {
			$meta_query[] = array( 'key' => '_fpq_status', 'value' => $estado );
		}
		if ( array() !== $meta_query ) {
			$meta_query['relation'] = 'AND';
			$args['meta_query']     = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		$posts = get_posts( $args );

		$rows = '';
		foreach ( $posts as $post ) {
			$current  = self::current_details( $post->ID );
			$customer = self::submitted_details( $post->ID );
			$rows    .= sprintf(
				'<tr><td><a href="%1$s"><strong>%2$s</strong></a></td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td><td>%7$s</td></tr>',
				esc_url( admin_url( 'admin.php?page=' . self::DETAIL_SLUG . '&p=' . $post->ID ) ),
				esc_html( (string) get_post_meta( $post->ID, '_fpq_reference', true ) ),
				esc_html( (string) ( $current['empresa'] ?? '' ) ),
				esc_html( (string) ( $current['email'] ?? '' ) ),
				'si' === (string) ( $customer['con_despacho'] ?? '' ) ? 'Sí' : 'No',
				esc_html( self::status_label( (string) get_post_meta( $post->ID, '_fpq_status', true ) ) ),
				esc_html( mysql2date( 'd/m/Y H:i', $post->post_date ) )
			);
		}
		if ( '' === $rows ) {
			$rows = '<tr><td colspan="6">Sin solicitudes que coincidan con la búsqueda.</td></tr>';
		}

		/* Sortable column headers keep the active search and filter. */
		$header = static function ( string $key, string $label ) use ( $orderby, $order, $search, $estado ): string {
			$active = $orderby === $key;
			$next   = $active && 'ASC' === $order ? 'desc' : 'asc';
			$url    = add_query_arg(
				array_filter(
					array(
						'page'    => self::LIST_SLUG,
						's'       => $search,
						'estado'  => $estado,
						'orderby' => $key,
						'order'   => $next,
					)
				),
				admin_url( 'admin.php' )
			);
			$arrow  = '';
			$sorted = '';
			if ( $active ) {
				$arrow  = 'ASC' === $order ? ' ▲' : ' ▼';
				$sorted = sprintf( ' aria-sort="%s"', 'ASC' === $order ? 'ascending' : 'descending' );
			}
			return sprintf( '<th scope="col"%1$s><a href="%2$s">%3$s%4$s</a></th>', $sorted, esc_url( $url ), esc_html( $label ), $arrow );
		};

		$options = '';
		foreach ( self::STATUSES as $status ) {
			$options .= sprintf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $status ),
				selected( $estado, $status, false ),
				esc_html( ucfirst( self::status_label( $status ) ) )
			);
		}

		printf(
			'<div class="wrap"><h1>Cotizaciones</h1><p class="description">Flujo operativo de ventas: busca y ordena las solicitudes, corrige los datos de contacto actuales, agrega notas internas y avanza el estado de cada solicitud.</p><form class="fpqa-filters" method="get" action="%1$s"><input type="hidden" name="page" value="%2$s"><label class="screen-reader-text" for="fpqa-search">Buscar solicitudes</label><input type="search" id="fpqa-search" name="s" value="%3$s" placeholder="Referencia, empresa o email"><label class="screen-reader-text" for="fpqa-estado">Filtrar por estado</label><select id="fpqa-estado" name="estado"><option value="">Todos los estados</option>%4$s</select><button class="button" type="submit">Filtrar</button></form><table class="widefat striped"><thead><tr>%5$s%6$s%7$s%8$s%9$s%10$s</tr></thead><tbody>%11$s</tbody></table></div>',
			esc_url( admin_url( 'admin.php' ) ),
			esc_attr( self::LIST_SLUG ),
			esc_attr( $search ),
			$options, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- options are fully escaped by the builder
			$header( 'referencia', 'Referencia' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by the escaped helper
			$header( 'empresa', 'Empresa' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$header( 'email', 'Email' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'<th scope="col">Despacho</th>',
			$header( 'estado', 'Estado' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$header( 'creada', 'Creada' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$rows // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rows are fully escaped by the builder
		);
	}

	public static function render_detail(): void {
		self::require_capability();

		$id   = isset( $_GET['p'] ) ? absint( $_GET['p'] ) : 0;
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post instanceof WP_Post || Freeplast_CQ_Request::POST_TYPE !== $post->post_type ) {
			wp_die( 'Solicitud no encontrada.', '', array( 'response' => 404 ) );
		}

		$reference = (string) get_post_meta( $post->ID, '_fpq_reference', true );
		$status    = (string) get_post_meta( $post->ID, '_fpq_status', true );
		$customer  = self::submitted_details( $post->ID );
		$items     = self::json_meta( $post->ID, '_fpq_items' );

		$dispatched = 'si' === (string) ( $customer['con_despacho'] ?? '' );

		$notice = self::render_notice( isset( $_GET['fpqa_notice'] ) ? sanitize_key( wp_unslash( $_GET['fpqa_notice'] ) ) : '' );

		/* Estado y transiciones. */
		$status_box = sprintf(
			'<p>Estado actual: <strong>%1$s</strong></p>%2$s',
			esc_html( self::status_label( $status ) ),
			self::is_terminal( $status )
				? '<p class="description">Estado terminal: solo la reapertura explícita puede cambiarlo.</p>' . self::operation_form( $post->ID, self::ACTION_REOPEN, array(), 'fp_reopen_nonce', 'reopen', 'Reabrir solicitud (vuelve a contactada)' )
				: self::transition_forms( $post->ID, $status )
		);

		/* Datos enviados (inmutables). */
		$telefono     = (string) ( $customer['telefono'] ?? '' );
		$normalizado  = (string) ( $customer['telefono_normalizado'] ?? '' );
		$telefono_row = '' !== $normalizado ? trim( $telefono . ' (normalizado: ' . $normalizado . ')' ) : $telefono;
		$mensaje      = (string) ( $customer['mensaje'] ?? '' );

		$detail_rows = array(
			array( 'Nombre', (string) ( $customer['nombre'] ?? '' ) ),
			array( 'Teléfono', $telefono_row ),
			array( 'Email', (string) ( $customer['email'] ?? '' ) ),
			array( 'Nombre Empresa', (string) ( $customer['empresa'] ?? '' ) ),
			array( 'Rut Empresa', (string) ( $customer['rut'] ?? '' ) ),
			array( 'Giro', (string) ( $customer['giro'] ?? '' ) ),
			array( 'Con despacho', $dispatched ? 'Sí' : 'No' ),
			array( 'Dirección de despacho', $dispatched ? (string) ( $customer['direccion_despacho'] ?? '' ) : '—' ),
			array( 'Mensaje', '' !== $mensaje ? $mensaje : '—' ),
		);
		$details = '';
		foreach ( $detail_rows as $row ) {
			$details .= sprintf( '<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html( $row[0] ), esc_html( $row[1] ) );
		}

		/* Datos de contacto actuales (corregibles). */
		$contact_form = self::render_contact_form( $post->ID );

		/* Snapshot inmutable. */
		$lines = '';
		foreach ( $items as $item ) {
			$item         = is_array( $item ) ? $item : array();
			$option_label = (string) ( $item['option_label'] ?? '' );
			$option       = '' !== $option_label ? $option_label : '—';
			$rules        = sprintf(
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

		/* Historial (eventos sin valores de PII). */
		$history_rows = '';
		foreach ( array_reverse( self::json_meta( $post->ID, '_fpq_history' ) ) as $event ) {
			$event = is_array( $event ) ? $event : array();
			$history_rows .= sprintf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td></tr>',
				esc_html( (string) ( $event['time'] ?? '' ) ),
				esc_html( self::history_description( $event ) ),
				esc_html( self::staff_label( $event ) )
			);
		}
		if ( '' === $history_rows ) {
			$history_rows = '<tr><td colspan="3">Sin eventos registrados.</td></tr>';
		}

		/* Notas internas. */
		$note_rows = '';
		foreach ( self::json_meta( $post->ID, '_fpq_notes' ) as $note ) {
			$note = is_array( $note ) ? $note : array();
			$note_rows .= sprintf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td></tr>',
				esc_html( (string) ( $note['time'] ?? '' ) ),
				esc_html( self::staff_label( $note ) ),
				esc_html( (string) ( $note['text'] ?? '' ) )
			);
		}
		if ( '' === $note_rows ) {
			$note_rows = '<tr><td colspan="3">Sin notas todavía.</td></tr>';
		}
		$note_form = self::operation_form_wrap(
			self::ACTION_NOTE,
			$post->ID,
			'<label class="screen-reader-text" for="fp_nota">Nueva nota interna</label><textarea class="fpqa-textarea" id="fp_nota" name="fp_nota" rows="3" maxlength="' . (int) self::MAX_NOTE . '"></textarea>',
			'fp_note_nonce',
			'note',
			'Agregar nota'
		);

		printf(
			'<div class="wrap"><h1>Solicitud %1$s</h1>%2$s<p class="description">Recibida: %3$s · Estado: <strong>%4$s</strong> · Los datos enviados y las líneas son inmutables.</p><h2>Estado de la solicitud</h2>%5$s<h2>Datos enviados</h2><p class="description">Tal como los envió el cliente — nunca se sobrescriben.</p><table class="widefat striped"><tbody>%6$s</tbody></table><h2>Datos de contacto actuales</h2><p class="description">Corregibles por ventas. Las correcciones no alteran los datos enviados; se registra qué campos cambiaron, cuándo y quién (nunca los valores).</p>%7$s<h2>Productos solicitados (snapshot inmutable)</h2><table class="widefat striped"><thead><tr><th>Producto</th><th>Opción</th><th>Cantidad</th><th>Reglas usadas</th><th>Especificaciones</th><th>URL canónica</th></tr></thead><tbody>%8$s</tbody></table>%13$s<h2>Historial</h2><table class="widefat striped"><thead><tr><th>Cuándo</th><th>Evento</th><th>Quién</th></tr></thead><tbody>%9$s</tbody></table><h2>Notas de ventas (internas)</h2><p class="description">Nunca visibles para el cliente.</p><table class="widefat striped"><thead><tr><th>Cuándo</th><th>Quién</th><th>Nota</th></tr></thead><tbody>%10$s</tbody></table>%11$s<p><a class="button" href="%12$s">← Volver a Cotizaciones</a></p></div>',
			esc_html( $reference ),
			$notice, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fully escaped by the builder
			esc_html( mysql2date( 'd/m/Y H:i', $post->post_date ) ),
			esc_html( self::status_label( $status ) ),
			$status_box, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fully escaped by the builder
			$details, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rows are fully escaped by the builder
			$contact_form, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fully escaped by the builder
			$lines, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rows are fully escaped by the builder
			$history_rows, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rows are fully escaped by the builder
			$note_rows, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rows are fully escaped by the builder
			$note_form, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fully escaped by the builder
			esc_url( admin_url( 'admin.php?page=' . self::LIST_SLUG ) ),
			Freeplast_CQ_Notifications::render_detail( $post ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the section escapes its own output
		);
	}

	/** One nonce-guarded admin-post operation form (fpqa- v1). */
	private static function operation_form( int $post_id, string $action, array $fields, string $nonce_field, string $verb, string $label ): string {
		return self::operation_form_wrap( $action, $post_id, self::hidden_inputs( $fields ), $nonce_field, $verb, $label );
	}

	private static function operation_form_wrap( string $action, int $post_id, string $inner, string $nonce_field, string $verb, string $label ): string {
		return sprintf(
			'<form class="fpqa-operation" method="post" action="%1$s"><input type="hidden" name="action" value="%2$s"><input type="hidden" name="p" value="%3$d">%4$s<input type="hidden" name="%5$s" value="%6$s"><button class="button" type="submit">%7$s</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( $action ),
			$post_id,
			$inner, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by escaped helpers
			esc_attr( $nonce_field ),
			esc_attr( wp_create_nonce( 'fp-quote-' . $verb . '-' . $post_id ) ),
			esc_html( $label )
		);
	}

	private static function hidden_inputs( array $fields ): string {
		$inputs = '';
		foreach ( $fields as $name => $value ) {
			$inputs .= sprintf( '<input type="hidden" name="%s" value="%s">', esc_attr( (string) $name ), esc_attr( (string) $value ) );
		}
		return $inputs;
	}

	/** The permitted direct transitions of one request. */
	private static function transition_forms( int $post_id, string $status ): string {
		$labels = array(
			'contacted' => 'Marcar contactada',
			'quoted'    => 'Marcar cotizada',
			'won'       => 'Marcar ganada',
			'lost'      => 'Marcar perdida',
			'cancelled' => 'Cancelar solicitud',
		);
		$forms = '';
		foreach ( self::permitted_targets( $status ) as $target ) {
			$forms .= self::operation_form( $post_id, self::ACTION_STATUS, array( 'fp_status' => $target ), 'fp_status_nonce', 'status', $labels[ $target ] );
		}
		return '<div class="fpqa-transitions">' . $forms . '</div>';
	}

	/** The correction form: current values (or the retained attempt) with inline errors. */
	private static function render_contact_form( int $post_id ): string {
		$attempt = self::read_contact_attempt( $post_id );
		$current = self::current_details( $post_id );

		$value_of = static function ( string $key ) use ( $attempt, $current ): string {
			if ( array_key_exists( $key, $attempt['values'] ) ) {
				return (string) $attempt['values'][ $key ];
			}
			return (string) ( $current[ self::CONTACT_KEYS[ $key ] ] ?? '' );
		};
		$error_of = static function ( string $key ) use ( $attempt ): ?string {
			return isset( $attempt['errors'][ $key ] ) ? (string) $attempt['errors'][ $key ] : null;
		};
		$field = static function ( string $key, string $value, ?string $error ): string {
			$rule   = Freeplast_CQ_Request::TEXT_FIELDS[ $key ];
			$inline = null === $error ? '' : sprintf( '<p class="fpqa-field-error" id="fp-qa-%1$s-error">%2$s</p>', esc_attr( $key ), esc_html( $error ) );
			return sprintf(
				'<div class="fpqa-field%1$s"><label class="fpqa-field-label" for="fp-qa-%2$s">%3$s</label><input class="regular-text" type="%4$s" id="fp-qa-%2$s" name="fp_%2$s" value="%5$s" maxlength="%6$d"%7$s>%8$s</div>',
				null === $error ? '' : ' form-invalid',
				esc_attr( $key ),
				esc_html( $rule['label'] ),
				esc_attr( $rule['type'] ),
				esc_attr( $value ),
				$rule['max'],
				null === $error ? '' : ' aria-describedby="fp-qa-' . esc_attr( $key ) . '-error" aria-invalid="true"',
				$inline
			);
		};

		$fields = '';
		foreach ( array_keys( Freeplast_CQ_Request::TEXT_FIELDS ) as $key ) {
			$fields .= $field( $key, $value_of( $key ), $error_of( $key ) );
		}
		$direccion_error = $error_of( 'direccion' );
		$fields         .= sprintf(
			'<div class="fpqa-field%1$s"><label class="fpqa-field-label" for="fp-qa-direccion">Dirección de despacho <span class="description">(si corresponde)</span></label><textarea class="fpqa-textarea" id="fp-qa-direccion" name="fp_direccion" rows="2" maxlength="%2$d"%3$s>%4$s</textarea>%5$s</div>',
			null === $direccion_error ? '' : ' form-invalid',
			Freeplast_CQ_Request::MAX_DIRECCION,
			null === $direccion_error ? '' : ' aria-describedby="fp-qa-direccion-error" aria-invalid="true"',
			esc_textarea( $value_of( 'direccion' ) ),
			null === $direccion_error ? '' : sprintf( '<p class="fpqa-field-error" id="fp-qa-direccion-error">%s</p>', esc_html( $direccion_error ) )
		);

		return sprintf(
			'<form class="fpqa-contact-form" method="post" action="%1$s"><input type="hidden" name="action" value="%2$s"><input type="hidden" name="p" value="%3$d"><div class="fpqa-form-grid">%4$s</div><input type="hidden" name="fp_contact_nonce" value="%5$s"><button class="button button-primary" type="submit">Guardar datos de contacto</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( self::ACTION_CONTACT ),
			$post_id,
			$fields, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by escaped helpers
			esc_attr( wp_create_nonce( 'fp-quote-contact-' . $post_id ) )
		);
	}

	private static function render_notice( string $code ): string {
		$messages = array(
			'contact_updated' => array( 'Datos de contacto actuales actualizados.', 'success' ),
			'contact_invalid' => array( 'Revisa los datos de contacto: hay campos inválidos.', 'error' ),
			'note_added'      => array( 'Nota interna agregada.', 'success' ),
			'note_invalid'    => array( 'La nota no puede estar vacía (máximo 2000 caracteres).', 'error' ),
			'status_updated'  => array( 'Estado actualizado.', 'success' ),
			'bad_transition'  => array( 'Esa transición de estado no está permitida.', 'error' ),
			'reopened'        => array( 'Solicitud reabierta: volvió a contactada.', 'success' ),
			'nonce'           => array( 'El enlace expiró o no es válido. Inténtalo de nuevo.', 'error' ),
		);
		if ( ! isset( $messages[ $code ] ) ) {
			return '';
		}
		list( $text, $type ) = $messages[ $code ];
		return sprintf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $text ) );
	}

	/** Human description of one history event (no PII values by construction). */
	private static function history_description( array $event ): string {
		switch ( (string) ( $event['type'] ?? '' ) ) {
			case 'created':
				return 'Solicitud recibida';
			case 'status':
				return sprintf(
					'Estado: %s → %s',
					self::status_label( (string) ( $event['from'] ?? '' ) ),
					self::status_label( (string) ( $event['to'] ?? '' ) )
				);
			case 'reopen':
				return sprintf(
					'Reapertura explícita: %s → %s',
					self::status_label( (string) ( $event['from'] ?? '' ) ),
					self::status_label( (string) ( $event['to'] ?? '' ) )
				);
			case 'contact':
				$labels = array(
					'nombre'               => 'Nombre',
					'telefono'             => 'Teléfono',
					'telefono_normalizado' => 'Teléfono (normalizado)',
					'email'                => 'Email',
					'empresa'              => 'Nombre Empresa',
					'rut'                  => 'Rut Empresa',
					'giro'                 => 'Giro',
					'direccion_despacho'   => 'Dirección de despacho',
				);
				$fields = is_array( $event['fields'] ?? null ) ? (array) $event['fields'] : array();
				$named  = array();
				foreach ( $fields as $field ) {
					$named[] = $labels[ (string) $field ] ?? (string) $field;
				}
				return array() !== $named ? 'Datos de contacto corregidos: ' . implode( ', ', $named ) : 'Datos de contacto corregidos';
			default:
				return (string) ( $event['type'] ?? '' );
		}
	}

	/** The staff identity of one recorded event. */
	private static function staff_label( array $event ): string {
		$staff = (int) ( $event['staff'] ?? 0 );
		$name  = (string) ( $event['staff_name'] ?? '' );
		if ( 0 === $staff ) {
			return '' !== $name ? $name : 'Cliente (formulario)';
		}
		return '' !== $name ? sprintf( '%s (#%d)', $name, $staff ) : sprintf( '#%d', $staff );
	}
}
