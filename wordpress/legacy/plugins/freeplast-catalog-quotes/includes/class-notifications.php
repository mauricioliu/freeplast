<?php
/**
 * Durable sales and customer notifications (issue #10).
 *
 * Notifying sales and acknowledging the customer is decoupled from
 * successful Quote Request receipt:
 *
 *   - The two notification jobs (one sales notification, one customer
 *     acknowledgement) are durable state on the fp_quote record itself,
 *     written by the very same insert that persists the request
 *     (Freeplast_CQ_Request::persist). A record can never exist without
 *     its jobs and vice versa — they commit together or fail together
 *     (the filter seam freeplast_cq_notification_jobs proves a failing
 *     job creation aborts the whole submission).
 *   - Receipt is persistence: the confirmation and the cleared basket
 *     never wait for mail. After durable persistence one delivery event
 *     (freeplast_cq_notify) is scheduled; a failure of the scheduler or
 *     the transport changes nothing about the received request.
 *   - Delivery is idempotent. A channel is only attempted while its job
 *     is pending or (below the automatic attempt cap) failed; sent and
 *     suppressed jobs are never attempted again, so cron retries, double
 *     events and staff resends cannot duplicate a delivery — and a mail
 *     outage can never duplicate the Quote Request (the idempotency token
 *     already guards the record).
 *   - Both messages carry the Request Reference and every product line
 *     with its option and quantity, built only from the immutable
 *     submitted record. The sales message adds the operational customer
 *     and dispatch details. Sales Reply-To points to the customer; the
 *     customer Reply-To points to the configured sales address.
 *   - Staging containment: every non-live mail mode adds the visible
 *     [STAGING] subject prefix and enforces the configured recipient
 *     override (redirect), the approved-recipient allowlist, or
 *     non-delivery (suppress). Environment configuration always wins
 *     over the options, and an unconfigured environment fails closed
 *     (suppress) so an unconfigured host can never contact a real
 *     recipient. Production/staging set FREEPLAST_CQ_MAIL_MODE.
 *   - The single external adapter seam is freeplast_cq_send_mail
 *     (default: wp_mail). Everything above it is business logic;
 *     automated tests replace it and assert business outcomes.
 *   - Authorized staff see the per-channel delivery state in the
 *     Cotizaciones detail and can safely resend a failed notification
 *     (nonce + manage_freeplast_quotes guarded admin-post operation).
 *   - The event log (_fpq_notify_log) records only IDs, event type and
 *     delivery state — never a customer field value or email address.
 *
 * @package Freeplast_Catalog_Quotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Freeplast_CQ_Notifications {

	/** Cron event delivering one reference's pending notification jobs. */
	public const EVENT = 'freeplast_cq_notify';

	/** The two durable jobs created with every Quote Request. */
	public const CHANNELS = array( 'sales', 'customer' );

	/** Post meta carrying the per-channel durable delivery jobs. */
	public const META_JOBS = '_fpq_notifications';

	/** Post meta carrying the bounded event/delivery log (no customer values). */
	public const META_LOG = '_fpq_notify_log';

	/** Admin-post action of the staff resend operation. */
	public const RESEND_ACTION = 'fp_notify_resend';

	/** Nonce action guarding the staff resend operation. */
	public const NONCE_RESEND_ACTION = 'fp_notify_resend';

	/** Visible subject prefix of every restricted (staging) mail mode. */
	public const STAGING_PREFIX = '[STAGING]';

	/** Automatic deliveries stop after this many attempts (staff resend bypasses the cap). */
	private const MAX_AUTO_ATTEMPTS = 5;

	/** Backoff between automatic retries of a failed delivery. */
	private const RETRY_DELAY = 5 * MINUTE_IN_SECONDS;

	/** Bounded event-log length per record. */
	private const LOG_MAX = 25;

	public static function register(): void {
		add_action( self::EVENT, array( self::class, 'process' ), 10, 1 );
		add_action( 'admin_post_' . self::RESEND_ACTION, array( self::class, 'handle_resend' ) );
	}

	/* ------------------------------------------------------------------ */
	/* The durable jobs                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * The initial pending job state of both channels. This is the value
	 * Freeplast_CQ_Request::persist stores with the record insert itself,
	 * which is what makes request and jobs commit together.
	 */
	public static function initial_state(): array {
		$state = array();
		foreach ( self::CHANNELS as $channel ) {
			$state[ $channel ] = self::channel_defaults();
		}
		return $state;
	}

	/** The initial pending job state, encoded as stored meta (the submission insert and the migration backfill share it). */
	public static function initial_state_json(): string {
		return Freeplast_CQ_Codec::encode( self::initial_state() );
	}

	/** Schedule the delivery event of one reference (fire-and-forget: a scheduling failure leaves the jobs pending for retries and the staff resend). */
	public static function schedule_delivery( string $reference ): void {
		if ( '' !== $reference ) {
			wp_schedule_single_event( time(), self::EVENT, array( $reference ) );
		}
	}

	/** The persisted job state of one record (pre-slice records read as pending until migration 7 backfills them). */
	public static function states( int $post_id ): array {
		$decoded = Freeplast_CQ_Codec::decode( (string) get_post_meta( $post_id, self::META_JOBS, true ) );

		$states = array();
		foreach ( self::CHANNELS as $channel ) {
			$states[ $channel ] = array_merge(
				self::channel_defaults(),
				is_array( $decoded[ $channel ] ?? null ) ? $decoded[ $channel ] : array()
			);
		}
		return $states;
	}

	private static function channel_defaults(): array {
		return array(
			'state'        => 'pending',
			'attempts'     => 0,
			'last_attempt' => null,
			'sent_at'      => null,
			'code'         => '',
		);
	}

	private static function update_channel( int $post_id, string $channel, array $fields ): void {
		$states             = self::states( $post_id );
		$states[ $channel ] = array_merge( $states[ $channel ], $fields );
		update_post_meta( $post_id, self::META_JOBS, Freeplast_CQ_Codec::encode( $states ) );
	}

	/* ------------------------------------------------------------------ */
	/* Delivery                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Attempt every unfinished job of one reference (the cron handler).
	 * Idempotent: sent and suppressed channels are skipped, so re-running
	 * the event — or arriving at it twice — never duplicates a delivery.
	 *
	 * @return array channel => outcome ('sent'|'failed'|'suppressed'|'retry_exhausted').
	 */
	public static function process( string $reference ): array {
		$post = self::post_by_reference( $reference );
		if ( null === $post ) {
			return array();
		}

		$outcomes = array();
		$states   = self::states( $post->ID );
		foreach ( self::CHANNELS as $channel ) {
			$job = $states[ $channel ];

			/* Only pending jobs — and failed jobs below the automatic attempt
			   cap — are attempted; delivered and suppressed jobs are final. */
			$attemptable = 'pending' === $job['state']
				|| ( 'failed' === $job['state'] && (int) $job['attempts'] < self::MAX_AUTO_ATTEMPTS );
			if ( $attemptable ) {
				$outcomes[ $channel ] = self::attempt( $post, $channel );
				continue;
			}

			if ( in_array( $job['state'], array( 'sent', 'suppressed' ), true ) ) {
				$outcomes[ $channel ] = $job['state'];
			} else {
				$outcomes[ $channel ] = 'retry_exhausted';
			}
		}
		return $outcomes;
	}

	/**
	 * One delivery attempt of one channel. The attempt (count, time) is
	 * recorded before the transport call; the outcome state after it, so
	 * even a crash between the two leaves an inspectable trail.
	 */
	private static function attempt( WP_Post $post, string $channel ): string {
		$states    = self::states( $post->ID );
		$reference = (string) get_post_meta( $post->ID, '_fpq_reference', true );

		self::update_channel(
			$post->ID,
			$channel,
			array(
				'attempts'     => (int) $states[ $channel ]['attempts'] + 1,
				'last_attempt' => time(),
			)
		);

		$message = self::message( $post, $channel );
		$plan    = self::policy( $message );

		if ( ! $plan['deliver'] ) {
			self::update_channel( $post->ID, $channel, array( 'state' => 'suppressed', 'code' => $plan['code'] ) );
			self::log_event( $post->ID, $channel, 'suppressed', $plan['code'] );
			return 'suppressed';
		}

		if ( self::send( $plan['message'] ) ) {
			self::update_channel( $post->ID, $channel, array( 'state' => 'sent', 'code' => '', 'sent_at' => time() ) );
			self::log_event( $post->ID, $channel, 'sent', '' );
			return 'sent';
		}

		self::update_channel( $post->ID, $channel, array( 'state' => 'failed', 'code' => 'delivery_failed' ) );
		self::log_event( $post->ID, $channel, 'failed', 'delivery_failed' );
		self::schedule_retry( $reference ); /* automatic backoff retry — never a duplicate (state-checked) */
		return 'failed';
	}

	private static function schedule_retry( string $reference ): void {
		if ( '' !== $reference ) {
			wp_schedule_single_event( time() + self::RETRY_DELAY, self::EVENT, array( $reference ) );
		}
	}

	/**
	 * Staff resend of one channel: bypasses the automatic-attempt cap but
	 * remains idempotent — a channel already delivered is never sent again.
	 *
	 * @return string outcome code for the admin redirect.
	 */
	public static function resend( WP_Post $post, string $channel ): string {
		$states = self::states( $post->ID );
		if ( 'sent' === $states[ $channel ]['state'] ) {
			return 'noop';
		}
		return self::attempt( $post, $channel );
	}

	/* ------------------------------------------------------------------ */
	/* The messages                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * The logical message of one channel, built only from the immutable
	 * submitted record. Both messages carry the Request Reference and the
	 * product lines with options and quantities; the sales message adds
	 * the operational customer and dispatch details.
	 *
	 * @return array{channel: string, to: string, subject: string, body: string, reply_to: string, headers: string[]}
	 */
	private static function message( WP_Post $post, string $channel ): array {
		$reference = (string) get_post_meta( $post->ID, '_fpq_reference', true );
		$customer  = Freeplast_CQ_Codec::decode( (string) get_post_meta( $post->ID, '_fpq_customer', true ) );
		$items     = Freeplast_CQ_Codec::decode( (string) get_post_meta( $post->ID, '_fpq_items', true ) );

		$nombre   = (string) ( $customer['nombre'] ?? '' );
		$email    = (string) ( $customer['email'] ?? '' );
		$lines    = self::item_lines( $items );
		$sales_to = self::sales_recipient();

		if ( 'sales' === $channel ) {
			$telefono      = (string) ( $customer['telefono'] ?? '' );
			$normalizado   = (string) ( $customer['telefono_normalizado'] ?? '' );
			$telefono_row  = '' !== $normalizado ? sprintf( '%s (normalizado: %s)', $telefono, $normalizado ) : $telefono;
			$empresa       = (string) ( $customer['empresa'] ?? '' );
			$rut           = (string) ( $customer['rut'] ?? '' );
			$giro          = (string) ( $customer['giro'] ?? '' );
			$dispatched    = 'si' === (string) ( $customer['con_despacho'] ?? '' );
			$direccion_row = $dispatched
				? 'Dirección de despacho: ' . (string) ( $customer['direccion_despacho'] ?? '' )
				: 'Dirección de despacho: —';
			$mensaje       = (string) ( $customer['mensaje'] ?? '' );
			$mensaje_block = '' !== $mensaje ? "Mensaje del cliente\n{$mensaje}\n\n" : '';
			$despacho      = $dispatched ? 'Sí' : 'No';
			$received      = mysql2date( 'd/m/Y H:i', $post->post_date );

			$body = <<<BODY
			Nueva solicitud de cotización recibida desde el sitio.

			Referencia: {$reference}
			Recibida: {$received}

			Datos del cliente
			- Nombre: {$nombre}
			- Teléfono: {$telefono_row}
			- Email: {$email}
			- Empresa: {$empresa}
			- RUT: {$rut}
			- Giro: {$giro}

			Despacho: {$despacho}
			{$direccion_row}
			{$mensaje_block}Productos solicitados
			{$lines}
			Gestiona esta solicitud en la administración Cotizaciones del sitio.

			BODY;

			return array(
				'channel'  => $channel,
				'to'       => $sales_to,
				'subject'  => sprintf( 'Nueva solicitud de cotización %s', $reference ),
				'body'     => $body,
				'reply_to' => sanitize_email( $email ),
				'headers'  => array(),
			);
		}

		$body = <<<BODY
			Hola {$nombre},

			Recibimos tu solicitud de cotización. Tu número de referencia es {$reference} — guárdalo para consultarla cuando nos contactes.

			Productos solicitados
			{$lines}Freeplast preparará tu cotización con estos productos y cantidades y te contactará al email y teléfono que registraste.

			Si tienes preguntas, responde a este correo o escríbenos a {$sales_to}.

			BODY;

		return array(
			'channel'  => $channel,
			'to'       => sanitize_email( $email ),
			'subject'  => sprintf( 'Recibimos tu solicitud de cotización %s', $reference ),
			'body'     => $body,
			'reply_to' => $sales_to,
			'headers'  => array(),
		);
	}

	/** The product lines block shared by both messages. */
	private static function item_lines( array $items ): string {
		$lines = '';
		foreach ( $items as $item ) {
			$item   = is_array( $item ) ? $item : array();
			$title  = (string) ( $item['title'] ?? '' );
			$option = (string) ( $item['option_label'] ?? '' );
			$label  = '' !== $option ? sprintf( '%s (%s)', $title, $option ) : $title;
			$lines .= sprintf( "- %s: %d unidades\n", $label, (int) ( $item['quantity'] ?? 0 ) );
		}
		return $lines;
	}

	/* ------------------------------------------------------------------ */
	/* Staging containment (mail policy)                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * The effective mail mode: live delivers as addressed; redirect,
	 * allowlist and suppress are the staging modes — every one of them
	 * prefixes the subject visibly and restricts the recipients.
	 *
	 * Environment configuration always wins over the options; an
	 * unconfigured environment fails closed to non-delivery.
	 */
	public static function mode(): string {
		$mode = strtolower( self::config( 'freeplast_cq_mail_mode', 'FREEPLAST_CQ_MAIL_MODE' ) );
		return in_array( $mode, array( 'live', 'redirect', 'allowlist', 'suppress' ), true ) ? $mode : 'suppress';
	}

	/** The configured sales address (production configuration: ventas@freeplast.cl). */
	public static function sales_recipient(): string {
		return sanitize_email( (string) apply_filters( 'freeplast_cq_sales_recipient', 'ventas@freeplast.cl' ) );
	}

	/** Environment first, option second: staging sets the environment, the option serves operations/tests on unconfigured hosts. */
	private static function config( string $option, string $env ): string {
		$value = trim( (string) getenv( $env ) );
		return '' !== $value ? $value : (string) get_option( $option, '' );
	}

	/**
	 * Apply the mail policy to one logical message: decides whether it is
	 * delivered at all, to which effective recipient, with which subject.
	 *
	 * @return array{deliver: bool, message: array, code: string}
	 */
	private static function policy( array $message ): array {
		$mode = self::mode();

		/* Every staging mode prefixes the subject visibly. */
		if ( 'live' !== $mode ) {
			$message['subject'] = self::STAGING_PREFIX . ' ' . $message['subject'];
		}

		switch ( $mode ) {
			case 'live':
				if ( false === is_email( $message['to'] ) ) {
					return self::suppression( $message, 'invalid_recipient' );
				}
				break;
			case 'redirect':
				$override = self::config( 'freeplast_cq_mail_to', 'FREEPLAST_CQ_MAIL_TO' );
				if ( false === is_email( $override ) ) {
					return self::suppression( $message, 'no_redirect_target' );
				}
				$message['to'] = $override;
				break;
			case 'allowlist':
				if ( ! self::is_allowlisted( $message['to'] ) ) {
					return self::suppression( $message, 'not_allowlisted' );
				}
				break;
			default: /* suppress */
				return self::suppression( $message, 'non_delivery_mode' );
		}

		$message['headers'] = self::reply_to_headers( $message['reply_to'] );
		return array(
			'deliver' => true,
			'message' => $message,
			'code'    => '',
		);
	}

	/** The policy result refusing one message with the given suppression code. */
	private static function suppression( array $message, string $code ): array {
		return array(
			'deliver' => false,
			'message' => $message,
			'code'    => $code,
		);
	}

	/** Whether one addressee is on the configured approved-recipient allowlist. */
	private static function is_allowlisted( string $to ): bool {
		$allow = array_map(
			static function ( $address ): string {
				return strtolower( trim( $address ) );
			},
			explode( ',', self::config( 'freeplast_cq_mail_allow', 'FREEPLAST_CQ_MAIL_ALLOW' ) )
		);
		return in_array( strtolower( $to ), $allow, true );
	}

	/** The wp_mail headers of one message (Reply-To only; From stays the environment's concern). */
	private static function reply_to_headers( string $reply_to ): array {
		return false === is_email( $reply_to ) ? array() : array( 'Reply-To: ' . $reply_to );
	}

	/* ------------------------------------------------------------------ */
	/* The external adapter seam                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * The single external transport boundary. Automated tests replace it
	 * (filter freeplast_cq_send_mail, receiving the final message and
	 * returning a bool) and assert the business outcomes around it.
	 */
	private static function send( array $message ): bool {
		$result = apply_filters( 'freeplast_cq_send_mail', null, $message );
		if ( null !== $result ) {
			return (bool) $result;
		}
		try {
			return wp_mail( $message['to'], $message['subject'], $message['body'], $message['headers'] );
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/* ------------------------------------------------------------------ */
	/* The event log (IDs and state only — never customer field values)    */
	/* ------------------------------------------------------------------ */

	private static function log_event( int $post_id, string $channel, string $state, string $code ): void {
		$logs = Freeplast_CQ_Codec::decode( (string) get_post_meta( $post_id, self::META_LOG, true ) );

		$logs[] = array(
			'time'    => time(),
			'channel' => $channel,
			'state'   => $state,
			'code'    => $code,
		);
		if ( count( $logs ) > self::LOG_MAX ) {
			$logs = array_slice( $logs, -self::LOG_MAX );
		}
		update_post_meta( $post_id, self::META_LOG, Freeplast_CQ_Codec::encode( $logs ) );
	}

	/* ------------------------------------------------------------------ */
	/* Staff surface                                                       */
	/* ------------------------------------------------------------------ */

	/** The nonce-guarded, capability-protected staff resend operation. */
	public static function handle_resend(): void {
		if ( ! current_user_can( Freeplast_CQ_Request::CAPABILITY ) ) {
			wp_die( 'Lo sentimos, no tienes permisos para esta acción.', '', array( 'response' => 403 ) );
		}

		$nonce = isset( $_POST['fp_notify_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['fp_notify_nonce'] ) ) : '';
		if ( '' === $nonce || false === wp_verify_nonce( $nonce, self::NONCE_RESEND_ACTION ) ) {
			wp_die( 'Lo sentimos, tu enlace ha expirado. Vuelve a intentarlo.', '', array( 'response' => 403 ) );
		}

		$id      = isset( $_POST['fp_quote'] ) ? absint( $_POST['fp_quote'] ) : 0;
		$post    = $id > 0 ? get_post( $id ) : null;
		$channel = isset( $_POST['fp_channel'] ) ? sanitize_key( wp_unslash( $_POST['fp_channel'] ) ) : '';
		if ( ! $post instanceof WP_Post || Freeplast_CQ_Request::POST_TYPE !== $post->post_type || ! in_array( $channel, self::CHANNELS, true ) ) {
			wp_die( 'Solicitud no encontrada.', '', array( 'response' => 404 ) );
		}

		$code = self::resend( $post, $channel );
		wp_safe_redirect(
			add_query_arg(
				array( 'fpcq_notify' => $code ),
				admin_url( 'admin.php?page=fp-quote&p=' . $post->ID )
			)
		);
		exit;
	}

	/** The Notificaciones section of the Cotizaciones detail (rendered by Freeplast_CQ_Request). */
	public static function render_detail( WP_Post $post ): string {
		$states = self::states( $post->ID );
		$mode   = self::mode();

		$customer = Freeplast_CQ_Codec::decode( (string) get_post_meta( $post->ID, '_fpq_customer', true ) );
		$recipients = array(
			'sales'    => self::sales_recipient(),
			'customer' => (string) ( $customer['email'] ?? '' ),
		);

		$state_labels = array(
			'pending'    => 'pendiente',
			'sent'       => 'enviada',
			'failed'     => 'fallida',
			'suppressed' => 'suprimida (staging)',
		);
		$channel_labels = array(
			'sales'    => 'Ventas',
			'customer' => 'Cliente',
		);

		$rows = '';
		foreach ( self::CHANNELS as $channel ) {
			$job = $states[ $channel ];
			$rows .= sprintf(
				'<tr><td>%1$s</td><td>%2$s</td><td><strong>%3$s</strong>%4$s</td><td>%5$d</td><td>%6$s</td><td>%7$s</td></tr>',
				esc_html( $channel_labels[ $channel ] ),
				esc_html( $recipients[ $channel ] ),
				esc_html( $state_labels[ $job['state'] ] ?? $job['state'] ),
				'' !== (string) $job['code'] ? sprintf( ' <code>%s</code>', esc_html( (string) $job['code'] ) ) : '',
				(int) $job['attempts'],
				(int) $job['last_attempt'] > 0 ? esc_html( mysql2date( 'd/m/Y H:i', gmdate( 'Y-m-d H:i:s', (int) $job['last_attempt'] ) ) ) : '—',
				self::render_resend_form( $post, $channel, $job['state'] )
			);
		}

		$mode_note = 'live' === $mode
			? 'Los envíos usan los destinatarios reales.'
			: sprintf( 'Modo restringido: los envíos llevan el prefijo %s y se aplican las restricciones configuradas (receptor de reemplazo, lista aprobada o no entrega).', self::STAGING_PREFIX );

		return sprintf(
			'%1$s<h2>Notificaciones</h2><p class="description">%2$s · %3$s El registro de eventos guarda estados y códigos, nunca datos del cliente.</p><table class="widefat striped"><thead><tr><th>Canal</th><th>Destinatario</th><th>Estado</th><th>Intentos</th><th>Último intento</th><th>Acción</th></tr></thead><tbody>%4$s</tbody></table>',
			self::render_resend_notice(),
			esc_html( sprintf( 'Modo de correo: %s.', $mode ) ),
			esc_html( $mode_note ),
			$rows // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rows are fully escaped by the builder
		);
	}

	/** The staff notice of one completed resend (driven by the fpcq_notify redirect parameter). */
	private static function render_resend_notice(): string {
		$notices = array(
			'sent'       => 'Notificación enviada.',
			'failed'     => 'El reenvío falló de nuevo; queda registrado como fallida.',
			'noop'       => 'La notificación ya estaba enviada; no se reenvió (una entrega exitosa nunca se duplica).',
			'suppressed' => 'El modo de correo actual suprime los envíos (staging).',
		);
		$code = isset( $_GET['fpcq_notify'] ) ? sanitize_key( wp_unslash( $_GET['fpcq_notify'] ) ) : '';
		if ( '' === $code || ! isset( $notices[ $code ] ) ) {
			return '';
		}
		return sprintf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $notices[ $code ] ) );
	}

	/** The staff resend form of one channel (offered while a delivery is still needed). */
	private static function render_resend_form( WP_Post $post, string $channel, string $state ): string {
		if ( 'sent' === $state ) {
			return '—';
		}

		$label = 'failed' === $state ? 'Reintentar envío' : 'Enviar ahora';
		return sprintf(
			'<form method="post" action="%1$s"><input type="hidden" name="action" value="%2$s"><input type="hidden" name="fp_quote" value="%3$d"><input type="hidden" name="fp_channel" value="%4$s"><input type="hidden" name="_wp_http_referer" value="%5$s"><input type="hidden" name="fp_notify_nonce" value="%6$s"><button type="submit" class="button button-small">%7$s</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( self::RESEND_ACTION ),
			$post->ID,
			esc_attr( $channel ),
			esc_url( admin_url( 'admin.php?page=fp-quote&p=' . $post->ID ) ),
			esc_attr( wp_create_nonce( self::NONCE_RESEND_ACTION ) ),
			esc_html( $label )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Lookup                                                              */
	/* ------------------------------------------------------------------ */

	private static function post_by_reference( string $reference ): ?WP_Post {
		if ( 1 !== preg_match( '/^FP-\d{4}-\d{6}$/', $reference ) ) {
			return null;
		}
		$posts = get_posts(
			array(
				'post_type'        => Freeplast_CQ_Request::POST_TYPE,
				'post_status'      => 'private',
				'posts_per_page'   => 1,
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_key'         => '_fpq_reference', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $reference, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return $posts[0] ?? null;
	}
}
