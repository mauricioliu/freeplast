#!/usr/bin/env python3
"""LEAD-RUN HTTP diagnostic for the native Ventas gate discrepancy
(plan-mv6vrtm2-rsaa). The worker cannot start servers; the lead executes this
runner, which:

  1. boots the EXISTING disposable .build stack on the unused loopback port
     127.0.0.1:18091 (php -S + scripts/router.php, like the harness);
  2. installs a disposable observables mu-plugin EXCLUSIVELY (fails if one is
     already present) that APPENDS bounded, non-PII JSONL evidence (actor cap
     booleans, guard-hook presence, action transport keys, wp_die tag/code)
     to .build/ventas-http-diag.jsonl, tagged with this run's token — earlier
     evidence in that file is never truncated or overwritten. Its wp_die
     handlers log, then call core's own (no behavior change);
  3. creates a run-owned synthetic ventas user + shop_order (billing email
     ventas-<run>@example.invalid), authenticates as that actor over real
     HTTP, and attempts the exact guarded surfaces the failing matrix used:
     editor save, quick status, bulk status, note delete (valid minted
     nonces), recording the actual HTTP status and the state digest delta;
  4. prints a CLI-vs-HTTP actor/hook comparison for the same user;
  5. cleans up ONLY what it created: fixture order, user, option token,
     mu-plugin, its own server process group. Every cleanup step's outcome is
     reported explicitly; any cleanup failure (or a port that stays bound)
     makes the run exit nonzero instead of being swallowed.

Printed summary contains statuses, booleans, hook priorities and tags only —
no response bodies, cookies, passwords or nonce strings. This is a diagnostic,
not a gate: it asserts nothing about the guards and changes no security code.

Usage:
  python3 wordpress/scripts/ventas-http-diag.py                     isolated probe (default)
  python3 wordpress/scripts/ventas-http-diag.py --mode guarded        bounded repro of the harness context: exact harness server args
      (max_execution_time=10, auto_prepend_file=.build/fpw-trace-prepend.php,
      PHP_CLI_SERVER_WORKERS=8), harness-identical fpw-stack-nonces/maillog
      mu-plugins written if missing (mail stays blocked), guard-off remnant
      aborts, then the REAL wordpress/scripts/woo-ventas-guard.py --mode
      guarded runs against a fresh run-owned ventas user (or
      FREEPLAST_VENTAS_USER/PASS from the environment — never logged);
      run-bound fixture orders are purged afterwards.
  python3 wordpress/scripts/ventas-http-diag.py --install-instrumentation
      writes the append-only observables mu-plugin (survives bootstrap's
      wp-content re-sync, which only replaces themes/freeplast and
      plugins/freeplast-woo) and activates it with a fresh run token, then
      prints the token — so the LEAD's full `npm test` gets observed.
  python3 wordpress/scripts/ventas-http-diag.py --read-instrumentation --run <token>
      prints that run's JSONL evidence + tally (limited=false, hooks missing,
      wp_die tags), removes the option and the mu-plugin.
Exit:   0 diagnostic + cleanup ok; 2 setup/infra failure; 4 cleanup failure.
"""
import http.cookiejar
import json
import os
import re
import secrets
import signal
import socket
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser

HERE = os.path.dirname(os.path.abspath(__file__))
WORDPRESS_DIR = os.path.dirname(HERE)
PHP = os.path.join(WORDPRESS_DIR, '.tools', 'php', 'php')
WPCLI = os.path.join(WORDPRESS_DIR, '.tools', 'cache', 'wp-cli.phar')
WP_DIR = os.path.join(WORDPRESS_DIR, '.build', 'wp')
ROUTER = os.path.join(HERE, 'router.php')
FIXTURE = os.path.join(HERE, 'ventas-http-diag-fixture.php')
STATE_PHP = os.path.join(HERE, 'woo-ventas-state.php')
MU = os.path.join(WP_DIR, 'wp-content', 'mu-plugins', 'fpw-ventas-http-diag.php')
JSONL = os.path.join(WORDPRESS_DIR, '.build', 'ventas-http-diag.jsonl')
SERVER_LOG = os.path.join(WORDPRESS_DIR, '.build', 'ventas-http-diag-server.log')
HOST, PORT = '127.0.0.1', 18091
BASE = 'http://%s:%d' % (HOST, PORT)
OPTION = 'fpw_ventas_http_diag_run'

class SetupError(RuntimeError):
    pass

MU_PLUGIN = r'''<?php
/** Disposable-stack diagnostic only (written/removed by ventas-http-diag.py,
 * plan-mv6vrtm2-rsaa): APPENDS bounded non-PII observables for the ventas
 * matrix routes to .build/ventas-http-diag.jsonl, tagged with the active run
 * token option. Changes no behavior: the wp_die handlers log, then call
 * core's own. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
function fpw_diag_token(): string { return (string) get_option( 'fpw_ventas_http_diag_run', '' ); }
function fpw_diag_write( array $entry ): void {
	$token = fpw_diag_token();
	if ( '' === $token || ! preg_match( '/^[a-f0-9]{12}$/D', $token ) ) { return; }
	$entry['run'] = $token;
	$line = wp_json_encode( $entry );
	if ( is_string( $line ) ) { @file_put_contents( dirname( ABSPATH ) . '/ventas-http-diag.jsonl', $line . "\n", FILE_APPEND ); }
}
function fpw_diag_clean( $value ): string {
	$value = is_scalar( $value ) ? (string) $value : '';
	return preg_match( '/^[a-zA-Z0-9_.\-]{0,80}/', $value, $m ) ? $m[0] : '';
}
function fpw_diag_transport(): array {
	global $pagenow;
	return array(
		'method' => strtoupper( fpw_diag_clean( $_SERVER['REQUEST_METHOD'] ?? '' ) ),
		'pagenow' => fpw_diag_clean( $pagenow ?? '' ),
		'doing_ajax' => (bool) wp_doing_ajax(),
		'request_action' => fpw_diag_clean( $_REQUEST['action'] ?? '' ),
		'post_action' => fpw_diag_clean( $_POST['action'] ?? '' ),
		'post_id' => (int) absint( $_POST['post_ID'] ?? $_REQUEST['order_id'] ?? $_REQUEST['post_id'] ?? 0 ),
		'get_page' => fpw_diag_clean( $_GET['page'] ?? '' ),
	);
}
function fpw_diag_actor(): array {
	$user = wp_get_current_user();
	$report = array( 'logged_in' => is_user_logged_in() );
	foreach ( array( 'edit_shop_orders', 'edit_others_shop_orders', 'manage_woocommerce' ) as $cap ) {
		$report['raw'][ $cap ] = ! empty( $user->allcaps[ $cap ] );
		$report['effective'][ $cap ] = current_user_can( $cap );
	}
	$report['limited'] = function_exists( 'fpw_is_order_limited_staff' ) ? (bool) fpw_is_order_limited_staff() : null;
	return $report;
}
function fpw_diag_hooks(): array {
	return array(
		'adapter_present' => function_exists( 'fpw_is_order_limited_staff' ),
		'front' => has_action( 'admin_init', 'fpw_deny_sales_record_mutation' ),
		'note_meta' => has_action( 'admin_init', 'fpw_deny_sales_order_note_and_meta_mutation' ),
		'save' => has_filter( 'woocommerce_process_shop_order_meta', 'fpw_deny_sales_order_save' ),
		'bulk' => has_filter( 'woocommerce_bulk_action_ids', 'fpw_deny_sales_bulk_actions' ),
	);
}
add_action( 'admin_init', static function (): void {
	fpw_diag_write( array( 'phase' => 'admin_init_end', 'transport' => fpw_diag_transport(), 'actor' => fpw_diag_actor(), 'hooks' => fpw_diag_hooks() ) );
}, PHP_INT_MAX );
// The two handler filters, spelled out (log, then core's own handler — no behavior change):
add_filter( 'wp_die_handler', static function () {
	return static function ( $message, $title = '', $args = array() ) {
		$text = is_scalar( $message ) ? (string) $message : '';
		fpw_diag_write( array( 'phase' => 'wp_die', 'core' => '_default_wp_die_handler', 'code' => (int) ( $args['response'] ?? 0 ),
			'tag' => str_contains( $text, 'solo consulta' ) ? 'sales-read-only' : 'other-denial',
			'transport' => fpw_diag_transport(), 'actor' => fpw_diag_actor() ) );
		_default_wp_die_handler( $message, $title, $args );
	};
} );
add_filter( 'wp_die_ajax_handler', static function () {
	return static function ( $message, $title = '', $args = array() ) {
		$text = is_scalar( $message ) ? (string) $message : '';
		fpw_diag_write( array( 'phase' => 'wp_die', 'core' => '_ajax_wp_die_handler', 'code' => (int) ( $args['response'] ?? 0 ),
			'tag' => str_contains( $text, 'solo consulta' ) ? 'sales-read-only' : 'other-denial',
			'transport' => fpw_diag_transport(), 'actor' => fpw_diag_actor() ) );
		_ajax_wp_die_handler( $message, $title, $args );
	};
} );
add_action( 'wp_ajax_fpw_diag_state', static function (): void {
	if ( ! is_user_logged_in() ) { wp_send_json_error( array( 'reason' => 'not-logged-in' ), 403 ); }
	wp_send_json_success( array( 'actor' => fpw_diag_actor(), 'hooks' => fpw_diag_hooks() ) );
} );
add_action( 'wp_ajax_fpw_diag_nonce', static function (): void {
	if ( ! is_user_logged_in() ) { wp_send_json_error( array( 'reason' => 'not-logged-in' ), 403 ); }
	$for = (string) ( $_REQUEST['for'] ?? '' );
	if ( '' === $for || strlen( $for ) > 100 || ! preg_match( '/^[a-z0-9_\-]+$/iD', $for ) ) { wp_send_json_error( array( 'reason' => 'bad-action' ), 400 ); }
	wp_send_json_success( array( 'nonce' => wp_create_nonce( $for ) ) );
} );
'''

def wp(args, timeout=90, check=True):
    cmd = [PHP, WPCLI, '--path=' + WP_DIR, '--url=' + BASE] + args
    r = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout, cwd=WORDPRESS_DIR)
    if check and r.returncode:
        raise SetupError('wp-cli failed (rc=%d): %s' % (r.returncode, (r.stderr or r.stdout)[:400]))
    return r.stdout.strip()

def port_answers():
    with socket.socket() as s:
        s.settimeout(1.0)
        return s.connect_ex((HOST, PORT)) == 0

class Session:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
    def request(self, path, data=None, timeout=120):
        body, ctype = None, {}
        if data is not None:
            body = urllib.parse.urlencode(data).encode()
            ctype = {'Content-Type': 'application/x-www-form-urlencoded'}
        req = urllib.request.Request(BASE + path, data=body, headers=ctype)
        response = None
        for attempt in (0, 1):
            try:
                response = self.opener.open(req, timeout=timeout)
                break
            except urllib.error.HTTPError as e:
                response = e
                break
            except (urllib.error.URLError, OSError):
                if attempt:
                    raise
        raw = response.read().decode('utf-8', 'replace')
        return response.getcode(), raw

class HiddenInputs(HTMLParser):
    def __init__(self):
        super().__init__()
        self.values = {}
    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == 'input' and a.get('type') == 'hidden' and a.get('name'):
            self.values[a['name']] = a.get('value', '')

def mint(session, action):
    code, body = session.request('/wp-admin/admin-ajax.php?action=fpw_diag_nonce&for=' + urllib.parse.quote(action))
    try:
        body = json.loads(body)
    except ValueError:
        body = None
    nonce = str(((body or {}).get('data') or {}).get('nonce', ''))
    if not nonce:
        raise SetupError('nonce mint failed for %s (HTTP %s)' % (action, code))
    return nonce

def login(session, user, password):
    session.request('/wp-login.php')
    session.request('/wp-login.php', {'log': user, 'pwd': password,
                                      'redirect_to': BASE + '/wp-admin/edit.php?post_type=shop_order',
                                      'wp-submit': 'Log In', 'testcookie': '1'})
    code, body = session.request('/wp-admin/profile.php')
    return code == 200 and 'id="your-profile"' in body and 'name="log"' not in body

def state_digest(order, run):
    r = subprocess.run([PHP, WPCLI, '--path=' + WP_DIR, '--url=' + BASE, '--user=1',
                        'eval-file', STATE_PHP, str(order), run],
                       capture_output=True, text=True, timeout=60, cwd=WORDPRESS_DIR)
    if r.returncode:
        raise SetupError('state snapshot failed: %s' % (r.stderr or r.stdout)[:300])
    return json.loads(r.stdout)['digest']

def own_jsonl(run):
    entries = []
    if os.path.exists(JSONL):
        with open(JSONL, encoding='utf-8') as fh:
            for line in fh:
                line = line.strip()
                if line:
                    try:
                        entry = json.loads(line)
                    except ValueError:
                        continue
                    if entry.get('run') == run:
                        entries.append(entry)
    return entries

TRACE_PREPEND = os.path.join(WORDPRESS_DIR, '.build', 'fpw-trace-prepend.php')
TRACE_PREPEND_CONTENT = '''<?php
register_shutdown_function( static function () {
	$e = error_get_last();
	if ( $e && E_ERROR === $e['type'] ) {
		file_put_contents( dirname( ABSPATH ) . '/fatal-trace.log', $e['message'] . ' in ' . $e['file'] . ':' . $e['line'] . "\n" . ( new Exception() )->getTraceAsString() . "\n====\n", FILE_APPEND );
	}
} );
'''
MU_DIR = os.path.join(WP_DIR, 'wp-content', 'mu-plugins')
GUARD_OFF_MU = os.path.join(MU_DIR, 'fpw-stack-guard-off.php')
NONCES_MU = os.path.join(MU_DIR, 'fpw-stack-nonces.php')
MAILLOG_MU = os.path.join(MU_DIR, 'fpw-stack-maillog.php')
# Harness-identical mu-plugin bodies (from woo-stack-harness.mjs) so the guarded
# verifier runs with the same context the full gate has; written only if absent.
NONCES_MU_CONTENT = '''<?php
/** Disposable-stack only (written by woo-stack-harness.mjs, issue #33): mints the
 * CURRENT user's nonce for a requested admin action — the exact string the native
 * UI would carry for that actor. It lets the restricted-session regression prove
 * denials are PERMISSIONS (valid session + valid nonce) rather than CSRF, even
 * where the interface rightly no longer offers the denied control. The mint only
 * produces a nonce string; it grants nothing. Never shipped in the repository's
 * wp-content. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
add_action( 'wp_ajax_fpw_test_nonce', static function () {
	if ( ! is_user_logged_in() ) { wp_send_json_error( array( 'reason' => 'not-logged-in' ), 403 ); }
	$for = (string) ( $_REQUEST['for'] ?? '' );
	if ( '' === $for || strlen( $for ) > 100 ) { wp_send_json_error( array( 'reason' => 'bad-action' ), 400 ); }
	wp_send_json_success( array( 'nonce' => wp_create_nonce( $for ) ) );
} );
'''
MAILLOG_MU_CONTENT = '''<?php
/** Disposable-stack diagnostic copy of woo-stack-harness.mjs' mail containment
 * (ventas-http-diag.py guarded mode): blocks every mail attempt on the disposable
 * stack. Kept byte-equivalent in behavior: no mail can ever leave. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
add_filter( 'pre_wp_mail', static function ( $result, $atts ) {
	$entry = wp_json_encode( array( 'subject' => (string) ( $atts['subject'] ?? '' ) ) );
	if ( is_string( $entry ) ) { file_put_contents( dirname( ABSPATH ) . '/mail-log.jsonl', $entry . "\n", FILE_APPEND ); }
	return true;
}, PHP_INT_MAX, 2 );
'''

def write_if_missing(path, content):
    if os.path.exists(path):
        return 'preexisting'
    with open(path, 'w', encoding='utf-8') as fh:
        fh.write(content)
    return 'written'

def hooks_missing_entry(hooks):
    """has_action()/has_filter() return the priority (0 is a VALID, present
    hook); only null/False mean absent."""
    hooks = hooks or {}
    return any(hooks.get(k) is None or hooks.get(k) is False for k in ('front', 'note_meta', 'save', 'bulk'))

def install_instrumentation():
    missing = [p for p in (PHP, WPCLI, os.path.join(WP_DIR, 'wp-load.php')) if not os.path.exists(p)]
    if missing:
        print(json.dumps({'error': 'missing paths', 'paths': missing}))
        return 2
    if not os.path.exists(MU):
        with open(MU, 'x', encoding='utf-8') as fh:
            fh.write(MU_PLUGIN)
    token = secrets.token_hex(6)
    wp(['option', 'update', OPTION, token, '--quiet'])
    print(json.dumps({'instrumentation': 'active', 'run': token, 'mu': MU,
                      'next': 'lead runs the full native gate (npm test); then: %s --read-instrumentation --run %s'
                              % (os.path.abspath(__file__), token)}))
    return 0

def read_instrumentation(token):
    if not re.fullmatch(r'[a-f0-9]{12}', token or ''):
        print(json.dumps({'error': 'bad run token'}))
        return 2
    entries = own_jsonl(token)
    tally = {'entries': len(entries),
             'limited_false': sum(1 for e in entries if (e.get('actor') or {}).get('limited') is False),
             'caps_false': sum(1 for e in entries if (e.get('actor') or {}).get('effective', {}).get('edit_shop_orders') is False
                               or (e.get('actor') or {}).get('effective', {}).get('manage_woocommerce') is True),
             'hooks_missing': sum(1 for e in entries if hooks_missing_entry(e.get('hooks'))),
             'wp_die_tags': {}, 'actions': {}}
    for e in entries:
        tag = e.get('tag')
        if tag:
            tally['wp_die_tags'][tag] = tally['wp_die_tags'].get(tag, 0) + 1
        action = (e.get('transport') or {}).get('request_action') or (e.get('transport') or {}).get('post_action')
        if action:
            tally['actions'][action] = tally['actions'].get(action, 0) + 1
    print(json.dumps({'run': token, 'tally': tally, 'entries': entries, 'jsonl': JSONL}, ensure_ascii=False, indent=1))
    wp(['option', 'delete', OPTION, '--quiet'], check=False)
    try:
        os.unlink(MU)
    except OSError:
        pass
    return 0

def main():
    argv = sys.argv[1:]
    if '--install-instrumentation' in argv:
        return install_instrumentation()
    if '--read-instrumentation' in argv:
        token = argv[argv.index('--run') + 1] if '--run' in argv and argv.index('--run') + 1 < len(argv) else ''
        return read_instrumentation(token)
    guarded = '--mode' in argv and argv.index('--mode') + 1 < len(argv) and argv[argv.index('--mode') + 1] == 'guarded'
    missing = [p for p in (PHP, WPCLI, os.path.join(WP_DIR, 'wp-load.php'), ROUTER, FIXTURE, STATE_PHP) if not os.path.exists(p)]
    if missing:
        print(json.dumps({'error': 'missing paths', 'paths': missing,
                          'hint': 'bootstrap first: FREEPLAST_TEST_URL=http://127.0.0.1:18091 node scripts/bootstrap.mjs (run from wordpress/)'}))
        return 2
    if port_answers():
        print(json.dumps({'error': 'port %d already answers; refusing to touch a server the run does not own' % PORT}))
        return 2
    provisioned = os.path.join(WORDPRESS_DIR, '.build', '.provisioned.json')
    if os.path.exists(provisioned):
        site_url = json.load(open(provisioned)).get('siteUrl')
        if site_url != BASE:
            print(json.dumps({'error': '.build provisioned for %s, not %s' % (site_url, BASE),
                              'hint': 're-provision: cd wordpress && FREEPLAST_TEST_URL=http://127.0.0.1:18091 node scripts/bootstrap.mjs'}))
            return 2

    run = secrets.token_hex(6)  # 12 hex chars
    user = 'fp-diag-' + run
    password = secrets.token_urlsafe(24)
    order = 0
    server = None
    logfh = None
    mu_installed = option_set = user_created = False
    created_mu_files = []          # harness-identical mu-plugins this run wrote
    trace_status = None
    probes = []
    cleanup_report = []
    cleanup_failures = []
    diag_code = None

    def cleanup_step(name, func):
        try:
            func()
            cleanup_report.append({'step': name, 'ok': True})
        except Exception as e:
            cleanup_report.append({'step': name, 'ok': False, 'error': str(e)[:200]})
            cleanup_failures.append(name)

    try:
        # 1. own disposable server on the unused loopback port
        os.makedirs(os.path.dirname(SERVER_LOG), exist_ok=True)
        logfh = open(SERVER_LOG, 'ab')
        os.makedirs(MU_DIR, exist_ok=True)
        prep = {}
        if guarded:
            # guard-off remnant = harness crash leftover: it REMOVES the ventas
            # guards for every request. Report and refuse to run on top of it.
            if os.path.exists(GUARD_OFF_MU):
                raise SetupError('fpw-stack-guard-off.php present — a crashed harness run left the guards REMOVED; this is a finding, not a fixture')
            trace_status = write_if_missing(TRACE_PREPEND, TRACE_PREPEND_CONTENT)
            prep['trace_prepend'] = trace_status
            prep['nonces_mu'] = write_if_missing(NONCES_MU, NONCES_MU_CONTENT)
            if prep['nonces_mu'] == 'written':
                created_mu_files.append(NONCES_MU)
            prep['maillog_mu'] = write_if_missing(MAILLOG_MU, MAILLOG_MU_CONTENT)
            if prep['maillog_mu'] == 'written':
                created_mu_files.append(MAILLOG_MU)
            server_args = [PHP, '-d', 'max_execution_time=10',
                           '-d', 'auto_prepend_file=' + TRACE_PREPEND,
                           '-S', '%s:%d' % (HOST, PORT), ROUTER]
            workers = '8'
        else:
            server_args = [PHP, '-d', 'max_execution_time=30', '-S', '%s:%d' % (HOST, PORT), ROUTER]
            workers = '4'
        env = dict(os.environ, PHP_CLI_SERVER_WORKERS=workers)
        server = subprocess.Popen(server_args,
                                  cwd=WORDPRESS_DIR, env=env, stdin=subprocess.DEVNULL,
                                  stdout=logfh, stderr=subprocess.STDOUT, start_new_session=True)
        for _ in range(120):
            if port_answers():
                break
            if server.poll() is not None:
                raise SetupError('server exited rc=%s (log: %s)' % (server.returncode, SERVER_LOG))
            time.sleep(0.5)
        else:
            raise SetupError('server never answered (log: %s)' % SERVER_LOG)

        # 2. bounded instrumentation — EXCLUSIVE mu-plugin, APPEND-only JSONL
        os.makedirs(os.path.dirname(MU), exist_ok=True)
        try:
            mu_fd = os.open(MU, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o644)
        except FileExistsError:
            raise SetupError('mu-plugin %s already present (previous crashed run?); refusing to overwrite' % MU)
        with os.fdopen(mu_fd, 'w', encoding='utf-8') as fh:
            fh.write(MU_PLUGIN)
        mu_installed = True
        stale = wp(['option', 'get', OPTION, '--format=plain'], check=False)
        if stale:
            raise SetupError('option %s still set to %r (previous crashed run?); refusing to run concurrently' % (OPTION, stale[:20]))
        wp(['option', 'update', OPTION, run, '--quiet'])
        option_set = True

        if guarded:
            # bounded repro: the REAL guarded verifier under the harness context
            env_user = os.environ.get('FREEPLAST_VENTAS_USER', '')
            env_pass = os.environ.get('FREEPLAST_VENTAS_PASS', '')
            if env_user and env_pass:
                ventas_user, ventas_pass, owns_user = env_user, env_pass, False
            else:
                ventas_user = 'fp-ventas-check'
                ventas_pass = 'vcheck-' + secrets.token_hex(9)
                r = subprocess.run([PHP, WPCLI, '--path=' + WP_DIR, '--url=' + BASE, 'user', 'create',
                                    ventas_user, ventas_user + '@example.invalid', '--role=ventas_freeplast',
                                    '--user_pass=' + ventas_pass, '--quiet'],
                                   capture_output=True, text=True, timeout=90, cwd=WORDPRESS_DIR)
                if r.returncode:  # harness falls back to user update
                    r = subprocess.run([PHP, WPCLI, '--path=' + WP_DIR, '--url=' + BASE, 'user', 'update',
                                        ventas_user, '--role=ventas_freeplast', '--user_pass=' + ventas_pass, '--quiet'],
                                       capture_output=True, text=True, timeout=90, cwd=WORDPRESS_DIR)
                    if r.returncode:
                        raise SetupError('cannot provision the ventas verifier user')
                owns_user = True
            user = ventas_user
            user_created = owns_user
            guard_env = dict(os.environ,
                             FREEPLAST_VENTAS_USER=ventas_user,
                             FREEPLAST_VENTAS_PASS=ventas_pass,
                             FREEPLAST_VENTAS_RUN=run,
                             FREEPLAST_VENTAS_STATE_COMMAND=json.dumps(
                                 [PHP, WPCLI, '--path=' + WP_DIR, '--url=' + BASE, '--user=1',
                                  'eval-file', STATE_PHP]))
            r = subprocess.run([sys.executable, os.path.join(HERE, 'woo-ventas-guard.py'),
                                '--base', BASE, '--mode', 'guarded'],
                               capture_output=True, text=True, timeout=300, env=guard_env, cwd=WORDPRESS_DIR)
            print('--- woo-ventas-guard.py (guarded) rc=%d ---\n%s\n%s' % (r.returncode, r.stdout, r.stderr))
            try:
                summary = json.loads(r.stdout.strip().split('\n')[-1])
            except (ValueError, IndexError):
                summary = None
            probes.append({'probe': 'guarded_matrix_rc', 'status': r.returncode,
                           'failures': (summary or {}).get('failures')})
            print(json.dumps({'run': run, 'base': BASE, 'mode': 'guarded', 'prep': prep,
                              'probes': probes, 'guard_summary': summary,
                              'owns_user': owns_user,
                              'jsonl_evidence': JSONL, 'server_log': SERVER_LOG},
                             ensure_ascii=False, indent=1))
            print('own-run jsonl entries: %s' % json.dumps(own_jsonl(run), ensure_ascii=False), file=sys.stderr)
            diag_code = 0 if r.returncode == 0 else 3
        else:
            wp(['user', 'create', user, user + '@example.invalid', '--role=ventas_freeplast',
                '--user_pass=' + password, '--quiet'])
            user_created = True
            order = json.loads(wp(['eval-file', FIXTURE, 'create', run, '--user=1', '--quiet']))['order']
            uid = int(wp(['user', 'get', user, '--field=ID', '--quiet']))

            # 3. real HTTP as the synthetic restricted actor
            ventas = Session()
            if not login(ventas, user, password):
                raise SetupError('ventas login failed (run %s)' % run)

            code, body = ventas.request('/wp-admin/admin-ajax.php?action=fpw_diag_state')
            try:
                http_actor = json.loads(body).get('data')
            except ValueError:
                http_actor = None
            probes.append({'probe': 'diag_state', 'status': code})

            EDITOR = '/wp-admin/post.php?post=%d&action=edit' % order
            code, editor = ventas.request(EDITOR)
            probes.append({'probe': 'editor_read', 'status': code})

            code, note_html = ventas.request('/wp-admin/admin-ajax.php', {
                'action': 'woocommerce_add_order_note', 'security': mint(ventas, 'add-order-note'),
                'post_id': order, 'note': 'DIAGNOTA %s NO ATENDER' % run, 'note_type': ''})
            note_id_m = re.search(r'<li rel="(\d+)"', note_html)
            probes.append({'probe': 'note_add_allowed_surface', 'status': code, 'note_id_parsed': bool(note_id_m)})

            baseline = state_digest(order, run)
            def snapshot(name, status):
                probes.append({'probe': name, 'status': status,
                               'digest_unchanged': state_digest(order, run) == baseline})

            # the four guarded surfaces the failing matrix used, valid minted nonces
            fields = HiddenInputs()
            fields.feed(editor)
            save = dict(fields.values)
            save.update({'action': 'editpost', 'post_ID': str(order), 'order_status': 'wc-processing',
                         '_billing_first_name': 'DIAG CAMBIO', 'save': 'Update',
                         'original_publish': 'Update', 'visibility': 'public'})
            code, _ = ventas.request('/wp-admin/post.php?post=%d' % order, save)
            snapshot('editor_save', code)

            code, _ = ventas.request('/wp-admin/admin-ajax.php?action=woocommerce_mark_order_status'
                                     '&status=processing&order_id=%d&_wpnonce=%s'
                                     % (order, mint(ventas, 'woocommerce-mark-order-status')))
            snapshot('quick_status', code)

            code, _ = ventas.request('/wp-admin/edit.php?post_type=shop_order', {
                'action': 'mark_processing', 'post[]': str(order), '_wpnonce': mint(ventas, 'bulk-posts'),
                '_wp_http_referer': '/wp-admin/edit.php?post_type=shop_order'})
            snapshot('bulk_status', code)

            if note_id_m:
                code, _ = ventas.request('/wp-admin/admin-ajax.php', {
                    'action': 'woocommerce_delete_order_note', 'security': mint(ventas, 'delete-order-note'),
                    'note_id': note_id_m.group(1)})
                snapshot('note_delete', code)

            cli_report = wp(['eval-file', FIXTURE, 'cli-report', run, str(uid), '--quiet'])
            print(json.dumps({'run': run, 'base': BASE, 'order': order, 'user_role': 'ventas_freeplast',
                              'probes': probes, 'http_diag_state': http_actor,
                              'cli_report': json.loads(cli_report),
                              'jsonl_evidence': JSONL, 'server_log': SERVER_LOG,
                              'note': 'statuses/tags/caps only; no bodies, cookies, passwords or nonces logged'},
                             ensure_ascii=False, indent=1))
            print('own-run jsonl entries: %s' % json.dumps(own_jsonl(run), ensure_ascii=False), file=sys.stderr)
            diag_code = 0
    except SetupError as e:
        print(json.dumps({'error': str(e), 'run': run}))
        diag_code = 2
    finally:
        # 4. clean ONLY own fixture/instrumentation/server — every outcome explicit
        if guarded:
            cleanup_step('purge_run_orders', lambda: wp(['eval-file', FIXTURE, 'cleanup-email', run, '--user=1', '--quiet'], timeout=180))
            for path in created_mu_files:
                cleanup_step('mu_remove_' + os.path.basename(path), lambda p=path: os.unlink(p))
        else:
            if order:
                cleanup_step('fixture_cleanup', lambda: wp(['eval-file', FIXTURE, 'cleanup', run, '--user=1', '--quiet'], timeout=180))
        if user_created:
            cleanup_step('user_delete', lambda: wp(['user', 'delete', user, '--yes', '--quiet']))
        if option_set:
            cleanup_step('option_delete', lambda: wp(['option', 'delete', OPTION, '--quiet']))
        if mu_installed:
            def _rm_mu():
                os.unlink(MU)
            cleanup_step('mu_plugin_remove', _rm_mu)
        if server is not None:
            def _stop_server():
                try:
                    os.killpg(os.getpgid(server.pid), signal.SIGTERM)
                except (ProcessLookupError, PermissionError):
                    pass
                time.sleep(1.0)
                for _ in range(20):
                    if not port_answers():
                        return
                    try:
                        os.killpg(os.getpgid(server.pid), signal.SIGKILL)
                    except (ProcessLookupError, PermissionError):
                        pass
                    time.sleep(0.5)
                if port_answers():
                    raise RuntimeError('port %d still answers after SIGKILL of the own server group' % PORT)
            cleanup_step('server_stop_port_freed', _stop_server)
        if logfh is not None:
            logfh.close()
        print('cleanup: %s' % json.dumps(cleanup_report))
        if cleanup_failures:
            diag_code = 4
    return diag_code

if __name__ == '__main__':
    sys.exit(main())
