#!/usr/bin/env node
/**
 * Real-stack offline regression harness (issue #1 — Woo-side ports of #24/#27).
 *
 * Boots the disposable WordPress + SQLite + WooCommerce installation from
 * scripts/bootstrap.mjs, serves it with the PHP built-in server (multi-worker,
 * loopback only) and drives scripts/woo-checkout-race.py through the Home
 * featured-grid contract (WA-04) and the concurrent-checkout attempt claim
 * (WA-01). WordPress order state is re-checked through WP-CLI afterwards.
 *
 * Everything targets http://127.0.0.1:<port> — no staging, no external host,
 * no mail configured. The first run pays the one-off bootstrap cost; later
 * runs re-sync wp-content only. FREEPLAST_SKIP_STACK=1 skips this harness
 * (documented escape hatch).
 */
import { spawn, spawnSync } from 'node:child_process';
import { closeSync, existsSync, mkdirSync, openSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { randomUUID } from 'node:crypto';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const WORDPRESS_DIR = dirname(HERE);
const PHP = join(WORDPRESS_DIR, '.tools', 'php', 'php');
const WPCLI = join(WORDPRESS_DIR, '.tools', 'cache', 'wp-cli.phar');
const WP_DIR = join(WORDPRESS_DIR, '.build', 'wp');
const SITE_URL = process.env.FREEPLAST_TEST_URL || 'http://127.0.0.1:8091';
const PORT = Number(new URL(SITE_URL).port) || 80;

function sh(cmd, args, opts = {}) {
  const result = spawnSync(cmd, args, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], ...opts });
  if (result.status !== 0) throw Error(`${cmd} ${args.join(' ')} failed:\n${result.stderr || result.stdout}`);
  return result.stdout ? result.stdout.trim() : '';
}

function sleep(ms) {
  Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, false, ms);
}

async function fetchCode(path) {
  try {
    const response = await fetch(SITE_URL + path, { redirect: 'manual', signal: AbortSignal.timeout(60_000) });
    return response.status;
  } catch {
    return 0;
  }
}

let checks = 0;
function check(ok, message) {
  checks++;
  if (!ok) throw Error(`stack harness: ${message}`);
}

export async function runStackHarness() {
  if (process.env.FREEPLAST_SKIP_STACK === '1') {
    console.log('stack harness: skipped (FREEPLAST_SKIP_STACK=1)');
    return 0;
  }

  if (!existsSync(PHP) || !existsSync(WPCLI)) {
    sh('bash', [join(HERE, 'fetch-tools.sh')], { stdio: 'inherit' });
  }

  /* 0. The port must not be owned by a foreign server before we bind it. */
  {
    const code = await fetchCode('/');
    if (code !== 0) throw Error(`stack harness: ${SITE_URL} already answers (HTTP ${code}) — a foreign server owns the port`);
  }

  /* 1. Provision (idempotent) and verify the disposable stack. */
  sh(process.execPath, [join(HERE, 'bootstrap.mjs')], { stdio: 'inherit', env: { ...process.env, FREEPLAST_TEST_URL: SITE_URL } });
  const provisioned = JSON.parse(readFileSync(join(WORDPRESS_DIR, '.build', '.provisioned.json'), 'utf8'));
  check(provisioned.siteUrl === SITE_URL, `provisioned URL ${provisioned.siteUrl} does not match ${SITE_URL}`);

  /* 1b. Mail containment + notification-event log for this run (issue #31):
     the disposable stack must never deliver anything, and every mail ATTEMPT
     is the observable record-notification event the acceptance criteria
     count. The mu-plugin is written straight into the disposable install and
     never exists in the repository's wp-content. */
  const mailLog = join(WORDPRESS_DIR, '.build', 'mail-log.jsonl');
  rmSync(mailLog, { force: true });
  mkdirSync(join(WP_DIR, 'wp-content', 'mu-plugins'), { recursive: true });
  writeFileSync(
    join(WP_DIR, 'wp-content', 'mu-plugins', 'fpw-stack-maillog.php'),
    `<?php
/** Disposable-stack only (written by woo-stack-harness.mjs): block every mail
 * attempt and record it — the offline substitute for the receipt-notification
 * events. Counts subjects only; no bodies, no recipients. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
add_filter( 'pre_wp_mail', static function ( $result, $atts ) {
	$entry = wp_json_encode( array( 'subject' => (string) ( $atts['subject'] ?? '' ) ) );
	if ( is_string( $entry ) ) { file_put_contents( dirname( ABSPATH ) . '/mail-log.jsonl', $entry . "\\n", FILE_APPEND ); }
	return true; // blocked: the disposable stack never delivers mail.
}, PHP_INT_MAX, 2 );
`,
  );
  writeFileSync(
    join(WP_DIR, 'wp-content', 'mu-plugins', 'fpw-stack-nonces.php'),
    `<?php
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
`,
  );

  /* 2. Serve (multi-worker so two checkout POSTs can genuinely overlap). The
     spawn is detached so the whole process GROUP (master + every worker) can
     be signalled: a plain master SIGTERM has been observed to leave workers
     holding the listening socket, poisoning every later run on the port. */
  const traceFile = join(WORDPRESS_DIR, '.build', 'fatal-trace.log');
  rmSync(traceFile, { force: true });
  writeFileSync(join(WORDPRESS_DIR, '.build', 'fpw-trace-prepend.php'), `<?php
register_shutdown_function( static function () {
	$e = error_get_last();
	if ( $e && E_ERROR === $e['type'] ) {
		file_put_contents( dirname( ABSPATH ) . '/fatal-trace.log', $e['message'] . ' in ' . $e['file'] . ':' . $e['line'] . "\n" . ( new Exception() )->getTraceAsString() . "\n====\n", FILE_APPEND );
	}
} );
`);
  const serverLogFile = join(WORDPRESS_DIR, '.build', 'server.log');
  rmSync(serverLogFile, { force: true });
  /* The server output MUST go to a file, not to pipes: node blocks its event
     loop inside spawnSync while the race/matrix subprocesses run, and nobody
     drains a pipe then — once the 64KB pipe buffer filled, every worker
     blocked on its access-log write and the whole stack stalled mid-suite. */
  const serverLogFd = openSync(serverLogFile, 'a');
  const server = spawn(PHP, ['-d', 'max_execution_time=10', '-d', `auto_prepend_file=${join(WORDPRESS_DIR, '.build', 'fpw-trace-prepend.php')}`, '-S', `127.0.0.1:${PORT}`, join(HERE, 'router.php')], {
    cwd: WORDPRESS_DIR,
    env: { ...process.env, PHP_CLI_SERVER_WORKERS: '8' },
    stdio: ['ignore', serverLogFd, serverLogFd],
    detached: true,
  });

  try {
    let home = 0;
    for (let attempt = 0; attempt < 60 && home !== 200; attempt++) {
      home = await fetchCode('/');
      if (home !== 200) sleep(500);
    }
    check(home === 200, `the disposable stack never answered 200 (last ${home})\n${readFileSync(serverLogFile, 'utf8').slice(-800)}`);

    /* 3. Home + race(repeated) + replay + correct + renew + lost + inflight
       + preserve + stranger + isolation over real HTTP. */
    const py = process.env.PYTHON || 'python3';
    const scenario = spawnSync(py, [join(HERE, 'woo-checkout-race.py'), '--base', SITE_URL], { encoding: 'utf8', timeout: 300_000 });
    check(scenario.status === 0, `checkout scenarios failed:\n${scenario.stdout || ''}\n${scenario.stderr || ''}`);
    const outcomes = JSON.parse(scenario.stdout.trim().split('\n').pop());
    const raceOrders = outcomes.race_orders.map(Number);
    const raceOrder = raceOrders[raceOrders.length - 1];
    const renewOrder = Number(outcomes.renew_order);
    const correctOrder = Number(outcomes.correct_order);
    const isolateOrder = Number(outcomes.isolate_order);
    check(outcomes.home.total_links > 0 && outcomes.home.unnamed === 0, `Home delivered ${outcomes.home.unnamed} unnamed links of ${outcomes.home.total_links}`);
    check(outcomes.home.no_shortcode_wrapper, 'Home still routes the grid through the wp:shortcode wpautop renderer');
    check(raceOrders.length === 3, `the concurrent test must run its bounded three rounds (got ${raceOrders.length})`);
    check(raceOrders.every((id) => Number.isInteger(id) && id > 0), `a race round produced no order: ${raceOrders}`);
    check(new Set(raceOrders).size === raceOrders.length, `the bounded rounds interfered with each other: ${raceOrders}`);
    check(outcomes.replay_recovered_order === raceOrder, `the replay of the landed attempt must recover the SAME request (${outcomes.replay_recovered_order} vs ${raceOrder})`);
    check(outcomes.attempt_token_rotated === true, 'the checkout form must rotate the attempt token after a landing');
    check(Number.isInteger(renewOrder) && renewOrder > 0, 'the identical rebuild produced no new request');
    check(renewOrder !== raceOrder && renewOrder !== correctOrder, `a new submission after a completion must NOT return the previous request (renew ${renewOrder} vs race ${raceOrder}/correct ${correctOrder})`);
    check(Number.isInteger(isolateOrder) && isolateOrder !== raceOrder, 'a different attempt must never fold into the race order');
    const lostOrder = Number(outcomes.lost_order);
    const inflightOrder = Number(outcomes.inflight_order);
    check(Number.isInteger(lostOrder) && lostOrder > 0, 'the lost-response scenario produced no order');
    check(lostOrder !== raceOrder && lostOrder !== correctOrder && lostOrder !== renewOrder && lostOrder !== isolateOrder,
          `the lost-response retry must return its own original request, never another one (lost ${lostOrder})`);
    check(Number.isInteger(inflightOrder) && inflightOrder > 0 && inflightOrder !== lostOrder,
          `the in-flight scenario produced no distinct order (inflight ${outcomes.inflight_order})`);
    check(outcomes.inflight_retry_recoverable === true, 'the in-flight retry answered unrecoverably');
    check(outcomes.preserve_recovers_original === true, 'the old form retry with a new selection must recover the original confirmation');
    check(outcomes.preserve_cart_lines === 1, `the recovery must not vacate a new unrelated selection (${outcomes.preserve_cart_lines} lines left)`);
    check(outcomes.unknown_token_safe === true && outcomes.foreign_session_safe === true,
          'unknown or foreign-session attempts must receive the safe native rejection, never the recovered reference');

    /* 4. WordPress state behind the responses (WP-CLI, read-only): each new
       request keeps its own record — pending status, quote meta, its own
       attempt identity, its own lines and its own submitted details — while
       the identical rebuild (renew) carries DIFFERENT identity with EQUAL
       content. The notification-event count closes the criterion: exactly one
       sales + one customer notification per new request, never duplicated for
       folds/replays. */
    const phpCode = `
      global $wpdb;
      $out = array();
      $ids = array('race' => ${raceOrder}, 'renew' => ${renewOrder}, 'correct' => ${correctOrder}, 'isolate' => ${isolateOrder}, 'lost' => ${lostOrder}, 'inflight' => ${inflightOrder});
      foreach ($ids as $key => $id) {
        $order = wc_get_order($id);
        $quantities = array();
        foreach ($order->get_items() as $item) { $quantities[] = $item->get_quantity(); }
        $details = $order->get_meta('_fp_submitted_details');
        $out[$key] = array(
          'id' => $id,
          'status' => $order->get_status(),
          'qwc' => (string) $order->get_meta('_qwc_quote'),
          'attempt' => (string) $order->get_meta('_fpw_attempt'),
          'billing_email' => $order->get_billing_email(),
          'quantities' => $quantities,
          'has_details' => is_array($details) && !empty($details),
        );
      }
      $lookup = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'fpw_attempt_' . $out['race']['attempt']));
      $out['lookup_row'] = array('id' => (int) (string) $lookup, 'for_order' => ${raceOrder});
      echo wp_json_encode($out);
    `;
    const state = sh(PHP, [WPCLI, 'eval', phpCode, `--url=${SITE_URL}`, `--path=${WP_DIR}`, '--user=1']);
    const parsed = JSON.parse(state.split('\n').pop());
    const totalUnits = (entry) => entry.quantities.reduce((a, b) => a + b, 0);
    for (const [key, entry] of Object.entries(parsed)) {
      if (key === 'lookup_row') continue;
      check(entry.status === 'pending', `request ${entry.id} left the pending quote state (status ${entry.status})`);
      check(entry.qwc === '1', `request ${entry.id} lost the quote meta`);
      check(entry.attempt.length === 64, `request ${entry.id} does not carry a bound attempt identity`);
      check(entry.has_details, `request ${entry.id} lost its submitted details`);
      check(entry.quantities.length > 0, `request ${entry.id} has no lines`);
    }
    check(totalUnits(parsed.race) === 70 && totalUnits(parsed.renew) === 70, 'the rebuild must reproduce the identical selection (70 units)');
    check(parsed.race.billing_email === parsed.renew.billing_email, 'the identical rebuild must carry the same submitted details (content repeats; requests must not)');
    check(parsed.race.attempt !== parsed.renew.attempt, 'the identical rebuild must carry a DIFFERENT attempt identity');
    check(parsed.correct.billing_email === parsed.renew.billing_email, 'the corrected retry keeps the same customer details');
    check(parsed.lookup_row.id === parsed.lookup_row.for_order, `the durable lookup row does not resolve to the race order (${JSON.stringify(parsed.lookup_row)})`);

    /* 5. Notification events: exactly one sales + one customer notification
       per NEW request (3 race rounds + correct + renew + lost + inflight
       + isolate = 8), never duplicated for folds, replays or recoveries. */
    const mails = existsSync(mailLog) ? readFileSync(mailLog, 'utf8').trim().split('\n').filter(Boolean) : [];
    check(mails.length === 16, `expected exactly 16 notification events (2 per new request × 8), got ${mails.length}:\n${mails.join('\n')}`);
    const subjects = mails.map((line) => { try { return JSON.parse(line).subject ?? ''; } catch { return '?'; } });
    check(subjects.every((s) => s.length > 0), 'every notification event carries a subject');

    /* 6. Issue #33: the restricted ventas session over real HTTP. Every protected
       operation is attempted with a VALID session and a VALID nonce (minted for
       the restricted user by the disposable mu-plugin above) and each denial
       must be a server 403 that leaves the synthetic record unchanged. Positive
       controls with the guards lifted prove the probes detect a removed guard —
       the authorization-regression criterion — and a final recheck proves the
       denial returns when the guards are restored. The temporary ventas account
       lives only inside this run. */
    const ventasUser = 'fp-ventas-check';
    const ventasPass = `vcheck-${randomUUID().replace(/-/g, '').slice(0, 18)}`;
    const wpArgs = [`--path=${WP_DIR}`, `--url=${SITE_URL}`];
    try {
      sh(PHP, [WPCLI, 'user', 'create', ventasUser, `${ventasUser}@example.invalid`, '--role=ventas_freeplast', `--user_pass=${ventasPass}`, ...wpArgs, '--quiet']);
    } catch {
      sh(PHP, [WPCLI, 'user', 'update', ventasUser, '--role=ventas_freeplast', `--user_pass=${ventasPass}`, ...wpArgs, '--quiet']);
    }
    const ventasEnv = { ...process.env, FREEPLAST_VENTAS_USER: ventasUser, FREEPLAST_VENTAS_PASS: ventasPass };
    const ventasArgs = [join(HERE, 'woo-ventas-guard.py'), '--base', SITE_URL];
    const guardOffMu = join(WP_DIR, 'wp-content', 'mu-plugins', 'fpw-stack-guard-off.php');
    try {
      const guarded = spawnSync(py, [...ventasArgs, '--mode', 'guarded'], { encoding: 'utf8', timeout: 300_000, env: ventasEnv });
      check(guarded.status === 0, `ventas guarded matrix failed:\n${guarded.stdout || ''}\n${guarded.stderr || ''}\n--- server log tail ---\n${readFileSync(serverLogFile, 'utf8').slice(-2000)}`);
      const { order } = JSON.parse(guarded.stdout.trim().split('\n').pop());
      writeFileSync(
        guardOffMu,
        `<?php
/** Disposable-stack only (written by woo-stack-harness.mjs, issue #33): lifts the
 * adapter's ventas record guards for the positive-controls run — the removed-guard
 * world the regression must detect. Deleted right after the run; never shipped. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
add_action( 'init', static function () {
	remove_action( 'admin_init', 'fpw_deny_sales_record_mutation', 0 );
	remove_filter( 'woocommerce_process_shop_order_meta', 'fpw_deny_sales_order_save', 0 );
	remove_filter( 'woocommerce_bulk_action_ids', 'fpw_deny_sales_bulk_actions', 0 );
	remove_filter( 'woocommerce_rest_check_permissions', 'fpw_deny_sales_rest_mutation', 10 );
}, 0 );
`,
      );
      const off = spawnSync(py, [...ventasArgs, '--mode', 'guard-off'], { encoding: 'utf8', timeout: 300_000, env: { ...ventasEnv, FREEPLAST_VENTAS_ORDER: String(order) } });
      check(off.status === 0, `ventas guard-off positive controls failed:\n${off.stdout || ''}\n${off.stderr || ''}`);
      rmSync(guardOffMu, { force: true });
      const recheck = spawnSync(py, [...ventasArgs, '--mode', 'recheck'], { encoding: 'utf8', timeout: 120_000, env: { ...ventasEnv, FREEPLAST_VENTAS_ORDER: String(order) } });
      check(recheck.status === 0, `ventas recheck failed:\n${recheck.stdout || ''}\n${recheck.stderr || ''}`);
    } finally {
      rmSync(guardOffMu, { force: true });
      spawnSync(PHP, [WPCLI, 'user', 'delete', ventasUser, '--yes', ...wpArgs], { stdio: 'ignore' });
    }
    checks += 3;
  } finally {
    closeSync(serverLogFd);
    /* Kill the whole server process group, then verify the port is actually
       freed — a surviving worker would silently serve stale state to the next
       run and make every scenario on it nondeterministic. */
    try { process.kill(-server.pid, 'SIGTERM'); } catch { server.kill('SIGTERM'); }
    sleep(500);
    try { process.kill(-server.pid, 'SIGKILL'); } catch { /* already gone */ }
    let lingering = 0;
    for (let attempt = 0; attempt < 20; attempt++) {
      lingering = await fetchCode('/');
      if (lingering === 0) break;
      try { process.kill(-server.pid, 'SIGKILL'); } catch { /* already gone */ }
      sleep(250);
    }
    check(lingering === 0, `the disposable stack still answers on ${SITE_URL} after shutdown (HTTP ${lingering}) — a server instance survived`);
  }

  console.log(`stack harness: ${checks} real-stack checks passed (Home card contract + bounded concurrent-race repetition + same-attempt retry recovery + lost-response confirmation recovery + identical-rebuild-new-reference + per-request records + notification-event count + restricted-ventas record boundary) on ${SITE_URL}`);
  return checks;
}
