<?php
/**
 * Plugin Name: Freeplast local review mail capture (LOCAL TESTING ONLY)
 *
 * H0 of the 2026-10-03 interaction review: a disposable local environment must
 * prove the full quotation mail continuity — owner notice on request receipt,
 * buyer offer with its exact PDF — with auditable captures and NO possibility
 * of a real delivery, while the workspace visibly identifies the simulated mode.
 *
 * INSTALLATION (lead, disposable copy only — never the shared/staging tree):
 *   1. Copy this file into the disposable install's wp-content/mu-plugins/
 *      and REMOVE any older suppression-only mu-plugin (its unconditional
 *      pre_wp_mail=true would override this plugin's honest rejections — the
 *      banner warns when such a foreign filter is detected).
 *   2. Create a PRIVATE capture directory OUTSIDE the served tree, mode 0700
 *      (no group/world access), e.g. ~/.local/state/<review>/mail-capture.
 *      It must NOT live under the WP root, the document root or uploads;
 *      symlinks are resolved to their real target before deciding.
 *   3. Define in that install's wp-config.php:
 *          define( 'FPW_LOCAL_CAPTURE_DIR', '<that absolute path>' );
 *   4. Verify the banner appears in the workspace; run the scenarios; read
 *      index.jsonl there. Nothing here creates accounts, sends mail or opens
 *      outbound HTTP.
 *
 * Safety contract (independent walls, fail-closed; 2026-10-04 lead review):
 *   - HOST ALLOWLIST gates EVERYTHING: fully inert unless the site's home host
 *     is localhost / 127.0.0.1 / ::1 / mliu (or listed in
 *     FPW_LOCAL_CAPTURE_ALLOWED_HOSTS). Never on freeplast.mliu.site or others.
 *   - On an allowed host, OUTBOUND HTTP IS BLOCKED UNCONDITIONALLY
 *     (pre_http_request), independent of the capture configuration.
 *   - The capture dir must exist, be writable, be PRIVATE (no group/world
 *     permission bits) and lie OUTSIDE the WP root (ABSPATH), the document
 *     root and the uploads dir — each compared after realpath() resolution.
 *   - wp_mail NEVER delivers, and a simulated acceptance requires COMPLETE
 *     evidence: the JSON record, every attachment byte (SHA-256 recorded,
 *     homonymous attachments captured as separate files) and the index.jsonl
 *     line must all be written with private permissions, or the whole capture
 *     is rolled back and the send is REJECTED (wp_mail false) — never a fake
 *     success and never a real delivery. Recipients keep their exact posted
 *     shape (array stays array, string stays string).
 *   - A rejected capture leaves a private failure trace outside the webroot
 *     (temp dir) so an overridden verdict remains observable, and the banner
 *     warns when a foreign pre_wp_mail filter could override this verdict.
 *   - The banner (admin_notices, normal document flow, never an overlay) is NOT
 *     .notice markup, which
 *     the workspace chrome hides.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Hosts this plugin may serve. Loopback and the review workstation only. */
function fpw_local_capture_allowed_hosts(): array {
	$hosts = array( 'localhost', '127.0.0.1', '::1', 'mliu' );
	if ( defined( 'FPW_LOCAL_CAPTURE_ALLOWED_HOSTS' ) && is_array( FPW_LOCAL_CAPTURE_ALLOWED_HOSTS ) ) {
		$hosts = array_values( array_unique( array_merge( $hosts, array_map( 'strval', FPW_LOCAL_CAPTURE_ALLOWED_HOSTS ) ) ) );
	}
	return $hosts;
}

/** True only on an allowed local host: the gate for every behavior below. */
function fpw_local_capture_host_allowed( ?string $home_host = null ): bool {
	if ( null === $home_host ) {
		$parsed = function_exists( 'wp_parse_url' ) ? wp_parse_url( (string) get_option( 'home' ), PHP_URL_HOST ) : null;
		$home_host = is_string( $parsed ) ? strtolower( $parsed ) : '';
	}
	return in_array( strtolower( (string) $home_host ), array_map( 'strtolower', fpw_local_capture_allowed_hosts() ), true );
}

/** Resolved filesystem roots a capture dir must never live inside (webroot, doc root, uploads). */
function fpw_local_capture_forbidden_roots(): array {
	$roots = array();
	$candidates = array( rtrim( ABSPATH, '/\\' ) );
	if ( is_string( $_SERVER['DOCUMENT_ROOT'] ?? null ) && '' !== $_SERVER['DOCUMENT_ROOT'] ) { $candidates[] = rtrim( $_SERVER['DOCUMENT_ROOT'], '/\\' ); }
	if ( function_exists( 'wp_get_upload_dir' ) ) {
		$uploads = wp_get_upload_dir();
		if ( is_string( $uploads['basedir'] ?? null ) && '' !== $uploads['basedir'] ) { $candidates[] = rtrim( $uploads['basedir'], '/\\' ); }
	}
	foreach ( $candidates as $candidate ) {
		$resolved = realpath( $candidate );
		if ( is_string( $resolved ) ) { $roots[] = $resolved; }
	}
	return array_values( array_unique( $roots ) );
}

/** Containment test on resolved paths: is $dir inside any of $roots? Symlinks resolve first. */
function fpw_local_capture_dir_within( string $dir, array $roots ): bool {
	$resolved = realpath( $dir );
	if ( ! is_string( $resolved ) ) { return false; }
	foreach ( $roots as $root ) {
		$root = (string) $root;
		if ( $resolved === $root || str_starts_with( $resolved, $root . DIRECTORY_SEPARATOR ) ) { return true; }
	}
	return false;
}

/** The dir is private only when no group/world permission bit survives. */
function fpw_local_capture_dir_private( string $dir ): bool {
	$perms = @fileperms( $dir );
	return is_int( $perms ) && 0 === ( $perms & 0007 ) && 0 === ( $perms & 0070 );
}

/**
 * Validate one candidate capture dir (or the configured one when null):
 * existing, writable, private, and outside every forbidden root after realpath.
 * Returns the resolved dir, or null — capture stays off and sends are rejected.
 */
function fpw_local_capture_dir_for( ?string $path = null ): ?string {
	if ( null === $path ) {
		if ( ! defined( 'FPW_LOCAL_CAPTURE_DIR' ) || ! is_string( FPW_LOCAL_CAPTURE_DIR ) || '' === trim( FPW_LOCAL_CAPTURE_DIR ) ) { return null; }
		$path = FPW_LOCAL_CAPTURE_DIR;
	}
	if ( ! is_string( $path ) || '' === trim( $path ) ) { return null; }
	$resolved = realpath( $path );
	if ( ! is_string( $resolved ) || ! is_dir( $resolved ) || ! is_writable( $resolved ) ) { return null; }
	if ( ! fpw_local_capture_dir_private( $resolved ) ) { return null; }
	if ( fpw_local_capture_dir_within( $resolved, fpw_local_capture_forbidden_roots() ) ) { return null; }
	return $resolved;
}

/** Capture-ready only on an allowed host with a valid dir: simulated accepts begin. */
function fpw_local_capture_dir(): ?string { return fpw_local_capture_dir_for(); }

function fpw_local_capture_active(): bool {
	return fpw_local_capture_host_allowed() && null !== fpw_local_capture_dir_for();
}

/** JSON encoding through core when present (calling wp_json_encode is fine; defining wp_* is not). */
function fpw_local_capture_json( $value ): ?string {
	$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value ) : json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	return is_string( $encoded ) ? $encoded : null;
}

/** Random lowercase suffix for unique capture file names. */
function fpw_local_capture_suffix( int $length ): string {
	$alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
	$out = '';
	for ( $i = 0; $i < $length; $i++ ) {
		try { $out .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ]; }
		catch ( Throwable ) { return bin2hex( random_bytes( (int) ceil( $length / 2 ) ) ); }
	}
	return $out;
}

/** Private-by-default file write that also VERIFIES the final permissions. */
function fpw_local_capture_write_private( string $path, string $bytes, int $mode = 0600 ): bool {
	$written = @file_put_contents( $path, $bytes );
	if ( ! is_int( $written ) || $written !== strlen( $bytes ) ) { return false; }
	if ( ! @chmod( $path, $mode ) ) { return false; }
	clearstatcache( true, $path );
	$perms = @fileperms( $path );
	return is_int( $perms ) && $mode === ( $perms & 0777 );
}

/** Durable rejection trace — ONLY inside the valid private capture dir; no
 *  shared predictable global path (symlink/shared temp hazard). Without a
 *  configured dir the count lives in request-scoped data for this request's banner. */
function fpw_local_capture_trace_path( ?string $dir = null ): ?string {
	$dir = $dir ?? fpw_local_capture_dir_for();
	return null === $dir ? null : $dir . '/failures.json';
}

function fpw_local_capture_note_failure( string $reason ): void {
	$GLOBALS['fpw_local_capture_request_failures'] = (int) ( $GLOBALS['fpw_local_capture_request_failures'] ?? 0 ) + 1;
	$GLOBALS['fpw_local_capture_request_last_failure'] = $reason;
	$path = fpw_local_capture_trace_path();
	if ( null === $path ) { return; }
	$trace = json_decode( (string) @file_get_contents( $path ), true );
	$trace = is_array( $trace ) ? $trace : array();
	$trace['schema'] = 1;
	$trace['count'] = (int) ( $trace['count'] ?? 0 ) + 1;
	$trace['last'] = $reason;
	$trace['at'] = gmdate( 'c' );
	$encoded = fpw_local_capture_json( $trace );
	if ( is_string( $encoded ) ) { fpw_local_capture_write_private( $path, $encoded ); }
}

/**
 * Capture one outgoing mail attempt. Complete evidence or honest failure:
 *  - captured-simulated: JSON record + every attachment byte + index line, all private.
 *  - capture-failed:     nothing partial left behind; the caller must REJECT.
 * Recipients keep their exact posted shape; attachments keep exact bytes with
 * SHA-256; homonymous attachments land in separate files.
 */
function fpw_local_capture_mail( $to, string $subject, string $message, array $headers, array $attachments, ?int $user_id = null ): array {
	$dir = fpw_local_capture_dir_for();
	if ( null === $dir || ! fpw_local_capture_host_allowed() ) { return array( 'state' => 'capture-failed' ); }
	$fail = static function ( string $reason ) use ( $dir ): array {
		@unlink( $dir . '/mail-' . ( $GLOBALS['fpw_local_capture_stamp'] ?? '' ) . '.json' );
		fpw_local_capture_note_failure( $reason );
		return array( 'state' => 'capture-failed' );
	};
	$stamp = gmdate( 'Ymd-His' ) . '-' . fpw_local_capture_suffix( 8 );
	$GLOBALS['fpw_local_capture_stamp'] = $stamp;
	$record = array(
		'schema'      => 1,
		'at'          => gmdate( 'c' ),
		'to'          => is_array( $to ) ? array_values( array_filter( array_map( static fn( $address ) => (string) $address, $to ), static fn( $address ) => '' !== $address ) ) : (string) $to,
		'subject'     => $subject,
		'message'     => $message,
		'headers'     => array_map( 'strval', $headers ),
		'attachments' => array(),
		'user_id'     => $user_id,
		'simulated'   => true,
	);
	$attachments_dir = $dir . '/attachments';
	if ( ! is_dir( $attachments_dir ) && ! @mkdir( $attachments_dir, 0700, true ) ) { return $fail( 'attachments-dir' ); }
	$used = array();
	foreach ( $attachments as $path ) {
		$path = (string) $path;
		if ( '' === $path || ! is_file( $path ) ) { return $fail( 'attachment-missing' ); }
		$bytes = file_get_contents( $path );
		if ( ! is_string( $bytes ) ) { return $fail( 'attachment-unreadable' ); } // false is failure, never a silent ''
		$name = basename( $path );
		$target = $name;
		$serial = 1;
		while ( isset( $used[ $dir . '/attachments/' . $stamp . '-' . $target ] ) || ( $serial > 1 && is_file( $dir . '/attachments/' . $stamp . '-' . $target ) ) ) {
			$serial++;
			$target = preg_replace( '/(\.[^.]+)?$/', '-' . $serial . '$1', $name ) ?? ( $name . '-' . $serial );
		}
		$dest = $dir . '/attachments/' . $stamp . '-' . $target;
		$used[ $dest ] = true;
		if ( ! fpw_local_capture_write_private( $dest, $bytes ) ) { return $fail( 'attachment-write' ); }
		$record['attachments'][] = array( 'name' => $name, 'source_path' => $path, 'bytes' => strlen( $bytes ), 'sha256' => hash( 'sha256', $bytes ), 'captured_as' => $dest );
	}
	$file = $dir . '/mail-' . $stamp . '.json';
	$json = fpw_local_capture_json( $record );
	if ( null === $json || ! fpw_local_capture_write_private( $file, $json ) ) { return $fail( 'record-write' ); }
	$line = fpw_local_capture_json( array( 'at' => $record['at'], 'to' => $record['to'], 'subject' => $subject, 'state' => 'captured-simulated', 'file' => basename( $file ), 'attachments' => count( $record['attachments'] ) ) );
	// Evidence completeness: a send is simulated ONLY when its audit index line exists too.
	if ( null === $line || false === @file_put_contents( $dir . '/index.jsonl', $line . "\n", FILE_APPEND | LOCK_EX ) || ! @chmod( $dir . '/index.jsonl', 0600 ) ) {
		@unlink( $file );
		foreach ( $record['attachments'] as $captured ) { @unlink( $captured['captured_as'] ); }
		return $fail( 'index-write' );
	}
	return array( 'state' => 'captured-simulated', 'file' => $file );
}

/* Wall 1 — outbound HTTP never leaves an allowed local host, whatever the capture config. */
function fpw_local_capture_block_http( $result ) {
	if ( ! fpw_local_capture_host_allowed() ) { return $result; }
	return new WP_Error( 'fpw_local_review_http_blocked', 'Entorno local de revisión: HTTP saliente bloqueado.', array( 'status' => 503 ) );
}
add_filter( 'pre_http_request', 'fpw_local_capture_block_http', PHP_INT_MAX );

/* Wall 2 — mail is captured and answered as simulation, or honestly rejected. */
function fpw_local_capture_pre_wp_mail( $result, $atts ) {
	if ( ! fpw_local_capture_host_allowed() ) { return $result; } // inert everywhere else
	$user = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
	$capture = fpw_local_capture_mail(
		$atts['to'] ?? '',
		(string) ( $atts['subject'] ?? '' ),
		(string) ( $atts['message'] ?? '' ),
		array_values( (array) ( $atts['headers'] ?? array() ) ),
		array_values( (array) ( $atts['attachments'] ?? array() ) ),
		is_object( $user ) && isset( $user->ID ) ? (int) $user->ID : null
	);
	if ( 'captured-simulated' !== $capture['state'] ) {
		fpw_local_capture_note_failure( 'capture-failed' );
		return false; // rejected: never a simulated success, never a delivery
	}
	return true;
}
add_filter( 'pre_wp_mail', 'fpw_local_capture_pre_wp_mail', 5, 2 );

/** The index line count + last outcome, for the visible banner. */
function fpw_local_capture_status(): array {
	$dir = fpw_local_capture_dir_for();
	$count = 0; $last = null;
	if ( null !== $dir && is_file( $dir . '/index.jsonl' ) ) {
		foreach ( file( $dir . '/index.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ?: array() as $line ) {
			$entry = json_decode( (string) $line, true );
			if ( is_array( $entry ) ) { $count++; $last = $entry; }
		}
	}
	$failures = null;
	$trace_path = fpw_local_capture_trace_path( $dir );
	if ( null !== $trace_path && is_file( $trace_path ) ) {
		$trace = json_decode( (string) @file_get_contents( $trace_path ), true );
		if ( is_array( $trace ) ) { $failures = $trace; }
	}
	if ( null === $failures && ! empty( $GLOBALS['fpw_local_capture_request_failures'] ) ) {
		$failures = array( 'count' => (int) $GLOBALS['fpw_local_capture_request_failures'], 'last' => $GLOBALS['fpw_local_capture_request_last_failure'] ?? null );
	}
	return array( 'count' => $count, 'last' => $last, 'failures' => $failures, 'capture_ready' => null !== $dir );
}

/** Count foreign pre_wp_mail filters that could override this plugin's verdict. */
function fpw_local_capture_foreign_mail_filters(): int {
	$hook = $GLOBALS['wp_filter']['pre_wp_mail'] ?? null;
	if ( ! is_object( $hook ) && ! is_array( $hook ) ) { return 0; }
	$callbacks = is_object( $hook ) && property_exists( $hook, 'callbacks' ) ? (array) $hook->callbacks : array();
	if ( is_array( $hook ) && isset( $hook['callbacks'] ) ) { $callbacks = (array) $hook['callbacks']; } // minimal stub shape used by the offline self-test
	$foreign = 0;
	foreach ( $callbacks as $priority_callbacks ) {
		foreach ( (array) $priority_callbacks as $key => $entry ) {
			$function = is_array( $entry ) ? ( $entry['function'] ?? null ) : $entry;
			$name = is_string( $function ) ? $function : ( is_array( $function ) ? implode( '::', array_map( 'strval', $function ) ) : ( is_object( $function ) ? get_class( $function ) : '' ) );
			if ( 'fpw_local_capture_pre_wp_mail' !== $name ) { $foreign++; }
		}
	}
	return $foreign;
}

/** In-flow banner marking the simulated mode (2026-10-04 lead review: a fixed
 *  overlay covered the mobile dock). Rendered through admin_notices — normal
 *  document flow at the top of the admin content, so nothing is overlapped and
 *  no global CSS changes — while deliberately NOT using .notice markup, which
 *  the workspace chrome hides. */
function fpw_local_capture_banner_html(): string {
	if ( ! fpw_local_capture_host_allowed() ) { return ''; }
	$status = fpw_local_capture_status();
	$mode = $status['capture_ready']
		? 'Entorno local de prueba: el correo NO se envía. Cada envío se captura y se responde como simulación.'
		: 'Entorno local de prueba: captura no configurada (FPW_LOCAL_CAPTURE_DIR privado, fuera del webroot); los envíos se RECHAZAN. Nada se envía.';
	$stats = $status['capture_ready'] ? ' Capturas registradas: ' . (int) $status['count'] . '.' : '';
	$failures = is_array( $status['failures'] ) ? ' Envíos rechazados por captura incompleta: ' . (int) ( $status['failures']['count'] ?? 0 ) . '.' : '';
	$last = $status['last'] ? ' Última captura: ' . (string) ( $status['last']['subject'] ?? '' ) . ' → ' . ( is_array( $status['last']['to'] ?? null ) ? implode( ', ', (array) $status['last']['to'] ) : (string) ( $status['last']['to'] ?? '' ) ) . '.' : '';
	$foreign = fpw_local_capture_foreign_mail_filters();
	$warning = $foreign > 0 ? ' AVISO: ' . $foreign . ' otro filtro pre_wp_mail puede sobrescribir el resultado honesto; retira el mu-plugin de supresión anterior.' : '';
	$text = htmlspecialchars( $mode . $stats . $failures . $last . $warning, ENT_QUOTES );
	return '<div role="status" class="fpw-local-capture-banner" style="position:static;box-sizing:border-box;display:block;width:100%;margin:0 0 12px;padding:8px 12px;background:#100090;color:#fff;font:600 13px/1.4 system-ui,sans-serif;text-align:center">' . $text . '</div>';
}
add_action( 'admin_notices', static function () { echo fpw_local_capture_banner_html(); }, PHP_INT_MAX );
