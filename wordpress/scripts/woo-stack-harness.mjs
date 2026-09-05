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
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
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

  /* 2. Serve (multi-worker so two checkout POSTs can genuinely overlap). */
  const server = spawn(PHP, ['-S', `127.0.0.1:${PORT}`, join(HERE, 'router.php')], {
    cwd: WORDPRESS_DIR,
    env: { ...process.env, PHP_CLI_SERVER_WORKERS: '8' },
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  let serverLog = '';
  server.stdout.on('data', (chunk) => { serverLog += chunk; });
  server.stderr.on('data', (chunk) => { serverLog += chunk; });

  try {
    let home = 0;
    for (let attempt = 0; attempt < 60 && home !== 200; attempt++) {
      home = await fetchCode('/');
      if (home !== 200) sleep(500);
    }
    check(home === 200, `the disposable stack never answered 200 (last ${home})\n${serverLog.slice(-800)}`);

    /* 3. Home + race + replay + isolation scenarios over real HTTP. */
    const py = process.env.PYTHON || 'python3';
    const scenario = spawnSync(py, [join(HERE, 'woo-checkout-race.py'), '--base', SITE_URL], { encoding: 'utf8', timeout: 300_000 });
    check(scenario.status === 0, `checkout scenarios failed:\n${scenario.stdout || ''}\n${scenario.stderr || ''}`);
    const outcomes = JSON.parse(scenario.stdout.trim().split('\n').pop());
    const raceOrder = Number(outcomes.race_order);
    const isolateOrder = Number(outcomes.isolate_order);
    check(outcomes.home.total_links > 0 && outcomes.home.unnamed === 0, `Home delivered ${outcomes.home.unnamed} unnamed links of ${outcomes.home.total_links}`);
    check(outcomes.home.no_shortcode_wrapper, 'Home still routes the grid through the wp:shortcode wpautop renderer');
    check(outcomes.race_results.every((result) => result === 'success'), 'not every concurrent checkout succeeded');
    check(Number.isInteger(raceOrder), 'the race did not produce an order id');
    check(Number.isInteger(isolateOrder) && isolateOrder !== raceOrder, 'a different attempt must never fold into the race order');

    /* 4. WordPress state behind the responses (WP-CLI, read-only). */
    const phpCode = `
      global $wpdb;
      $out = array();
      $ids = array(${raceOrder}, ${isolateOrder});
      foreach ($ids as $id) {
        $order = wc_get_order($id);
        $out[$id] = array(
          'status' => $order->get_status(),
          'qwc' => (string) $order->get_meta('_qwc_quote'),
          'attempt' => (string) $order->get_meta('_fpw_attempt'),
          'billing_email' => $order->get_billing_email(),
        );
      }
      $lookup = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'fpw_attempt_' . $out[${raceOrder}]['attempt']));
      $out['lookup_row'] = array('id' => (int) (string) $lookup, 'for_order' => ${raceOrder});
      echo wp_json_encode($out);
    `;
    const state = sh(PHP, [WPCLI, 'eval', phpCode, `--url=${SITE_URL}`, `--path=${WP_DIR}`, '--user=1']);
    const parsed = JSON.parse(state.split('\n').pop());
    for (const [id, entry] of Object.entries(parsed)) {
      if (id === 'lookup_row') continue;
      check(entry.status === 'pending', `order ${id} left the pending quote state (status ${entry.status})`);
      check(entry.qwc === '1', `order ${id} lost the quote meta`);
      check(entry.attempt.length === 64, `order ${id} does not carry a bound attempt hash`);
    }
    check(parsed.lookup_row.id === parsed.lookup_row.for_order, `the durable lookup row does not resolve to the race order (${JSON.stringify(parsed.lookup_row)})`);
    check(parsed[isolateOrder].billing_email !== parsed[raceOrder].billing_email, 'the isolated attempt must keep its own submitted details');
  } finally {
    server.kill('SIGTERM');
    sleep(300);
    if (!server.killed) server.kill('SIGKILL');
  }

  console.log(`stack harness: ${checks} real-stack checks passed (Home card contract + concurrent checkout claim + isolation) on ${SITE_URL}`);
  return checks;
}
