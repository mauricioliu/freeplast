import { createHash, randomUUID } from 'node:crypto';
import { existsSync, mkdtempSync, readFileSync, renameSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { JSDOM } from 'jsdom';

/** Additional #56 acceptance through real HTTP, mail interception and SQLite engine faults.
 * Only called by the disposable harness; no production adapter test switches. */
export async function runQuotationNativeFailures({ owner, makeCookieFetch, mint, editUrl, wpEval, postForm, approvePost, versionRow, mailLines, wpDir, check }) {
  check(wpEval('echo wp_get_environment_type();') === 'local', 'quotation fault probes require the disposable local stack');
  const database = join(wpDir, 'wp-content/database/.ht.sqlite');
  check(readFileSync(database).subarray(0, 15).toString() === 'SQLite format 3', 'fault probes target the actual run-owned SQLite database');
  const token = randomUUID().replaceAll('-', '');
  const sql = (statement) => wpEval(`$pdo = new PDO(${JSON.stringify('sqlite:' + database)}); $pdo->exec(${JSON.stringify(statement)});`);
  const product = Number(wpEval(`$p = wc_get_products(array('type'=>'simple','status'=>'publish','limit'=>1)); echo $p[0]->get_id();`));
  check(product > 0, 'native quotation fixtures have a real simple product');

  const controls = (html) => {
    const dom = new JSDOM(html);
    try { return Object.fromEntries([...dom.window.document.querySelectorAll('input[type=hidden][name]')].map(n => [n.name, n.value])); }
    finally { dom.window.close(); }
  };
  const prepare = async (label) => {
    const buyer = makeCookieFetch();
    const add = await buyer('/?wc-ajax=add_to_cart', { method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' }, body: `product_id=${product}&quantity=2` });
    check(add.status === 200, `quotation ${label}: native basket add`);
    const fields = controls(await (await buyer('/datos-y-envio/')).text());
    const response = await buyer('/?wc-ajax=checkout', { method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ ...fields,
      billing_first_name: 'PRUEBA NO ATENDER', billing_phone: '+56 9 1234 5678',
      billing_email: `pdf-${label}-${token}@example.invalid`, billing_company: 'Agrícola Ñandú — PRUEBA LOCAL',
      billing_fp_rut: '76.123.456-7', billing_fp_giro: 'Prueba', billing_fp_dispatch: 'no', billing_fp_address: '',
      payment_method: 'quotes-gateway', order_comments: 'NO COMERCIAL; correo interceptado',
    }).toString() });
    const result = await response.json();
    const id = Number(String(result.redirect || '').match(/order-received\/(\d+)/)?.[1]);
    check(result.result === 'success' && id > 0, `quotation ${label}: a fresh native receipt exists`);
    const saved = await postForm(owner, id, {
      fpw_work_save: '1', fpw_work_revision: '0', fpw_draft_nonce: await mint(owner, `fpw-draft-save-${id}`),
      'fpw_work[lines][0][quantity]': '2', 'fpw_work[lines][0][price]': '3210',
      'fpw_work[destination]': '', 'fpw_work[dispatch_amount]': '', 'fpw_work[validity_days]': '12',
    });
    check((await saved.text()).includes('Cambios guardados (revisión 1)'), `quotation ${label}: owner completes the offer over HTTP`);
    await postForm(owner, id, { fpw_work_preview: '1', fpw_preview_nonce: await mint(owner, `fpw-draft-preview-${id}`) });
    const form = controls(await (await owner(editUrl(id))).text());
    check(form.fpw_approve_preview?.length === 64 && versionRow(id) === null, `quotation ${label}: complete reviewed offer has not yet been approved`);
    return { id, form };
  };
  const approve = ({ id, form }) => approvePost(owner, id, form.fpw_approve_nonce, form.fpw_approve_preview);
  const screen = async (id) => (await owner(editUrl(id))).text();

  // Genuine overlap: two PHP workers reach their first INSERT before either can execute it.
  const race = await prepare('first-race');
  const raceDir = mkdtempSync(join(dirname(wpDir), 'approval-race-'));
  const arrivals = join(raceDir, 'arrivals');
  const mu = join(wpDir, 'wp-content/mu-plugins/fpw-first-approval-barrier.php');
  writeFileSync(mu, `<?php
add_filter('query', static function ($query) {
  if (!str_starts_with($query, 'INSERT INTO') || !str_contains($query, "'fpw_quotation_${race.id}'")) { return $query; }
  $file = ${JSON.stringify(arrivals)};
  file_put_contents($file, getmypid()."\\n", FILE_APPEND | LOCK_EX);
  $until = microtime(true) + 5;
  do {
    $arrived = array_unique(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    if (count($arrived) >= 2) { return $query; }
    usleep(10000);
  } while (microtime(true) < $until);
  throw new RuntimeException('Two independent approval workers did not reach the barrier');
}, PHP_INT_MAX);
`);
  const beforeRace = mailLines().length;
  try {
    const first = approve(race);
    // Do not let the PHP development server pre-accept both sockets into one worker.
    // Start B only when A is already blocked BEFORE its INSERT, not after it finishes.
    for (let i = 0; i < 60 && !existsSync(arrivals); i++) { await new Promise(resolve => setTimeout(resolve, 50)); }
    const responses = await Promise.all([first, approve(race)]);
    const pages = await Promise.all(responses.map(r => r.text()));
    check(responses.every(r => r.status === 200), `both first-approval HTTP requests complete (${responses.map(r => r.status).join('/')})`);
    check(new Set(readFileSync(arrivals, 'utf8').trim().split('\n')).size === 2, 'two distinct PHP processes reached the initial INSERT barrier');
    check(pages.filter(p => p.includes('Cotización aprobada y enviada')).length === 1 && pages.filter(p => p.includes('ya tiene su primera versión aprobada')).length === 1, 'exactly one initial approval wins; the concurrent loser reports the standing version');
    check(mailLines().length === beforeRace + 1, 'first-approval concurrency attempts exactly one buyer email');
  } finally { rmSync(mu, { force: true }); } // Keep the PID-only barrier evidence in the ignored build.
  const fixed = versionRow(race.id);
  check(fixed.version === 1 && fixed.document === 'ready' && fixed.delivery.state === 'accepted', 'first concurrent approval persists a complete version');
  const bytes = Buffer.from(fixed.pdf_base64, 'base64');
  check(mailLines().at(-1).attachment_sha256[0] === createHash('sha256').update(bytes).digest('hex'), 'the race winner mails exactly the frozen PDF bytes');
  writeFileSync(join(dirname(wpDir), 'quotation-review.pdf'), bytes, { mode: 0o600 });
  const fixedJson = JSON.stringify(fixed);
  await approve(race);
  check(JSON.stringify(versionRow(race.id)) === fixedJson && mailLines().length === beforeRace + 1, 'retry after the initial race never rewrites the version or resends');

  // SQLite triggers reject the REAL writes. No wpdb fake or patched return values.
  for (const stage of ['insert', 'pdf', 'delivery']) {
    const fixture = await prepare(stage);
    const before = mailLines().length;
    const trigger = `fpw_test_${stage}_${token}`;
    const operation = stage === 'insert' ? 'INSERT' : 'UPDATE';
    const condition = stage === 'pdf' ? ` AND json_extract(NEW.option_value, '$.document') = 'ready'` : stage === 'delivery' ? ` AND json_extract(NEW.option_value, '$.delivery.state') = 'accepted'` : '';
    const table = wpEval('global $wpdb; echo $wpdb->options;');
    check(/^\w+$/.test(table), 'SQLite options identifier is bounded');
    sql(`CREATE TRIGGER ${trigger} BEFORE ${operation} ON ${table} WHEN NEW.option_name = 'fpw_quotation_${fixture.id}'${condition} BEGIN SELECT RAISE(ABORT, 'controlled quotation ${stage} failure'); END`);
    try {
      const response = await approve(fixture);
      const page = await response.text();
      check(response.status === 200 && !page.includes('Cotización aprobada y enviada'), `native ${stage} write failure never reports sent`);
      const row = versionRow(fixture.id);
      if (stage === 'insert') {
        check(row === null && page.includes('No se pudo confirmar la aprobación'), 'native INSERT failure creates no standing version');
      } else if (stage === 'pdf') {
        check(row.document === 'pending' && !row.pdf_base64 && page.includes('el documento no se pudo generar o guardar'), 'native failed PDF write keeps the approved version pending');
      } else {
        check(row.document === 'ready' && row.delivery.state === 'unknown' && page.includes('el resultado del correo es desconocido'), 'native post-mail write failure preserves the write-ahead unknown state');
      }
      const expected = before + (stage === 'delivery' ? 1 : 0);
      check(mailLines().length === expected, `native ${stage} failure attempts mail only when the document was durably stored`);
      const beforeRetry = JSON.stringify(row);
      await approve(fixture);
      check(mailLines().length === expected && JSON.stringify(versionRow(fixture.id)) === beforeRetry, `retry after native ${stage} failure cannot invent or duplicate an issuance`);
      if (stage !== 'insert') { check((await screen(fixture.id)).includes('Versión aprobada'), 'a new HTTP read preserves the standing version after the failing request ends'); }
    } finally { sql(`DROP TRIGGER IF EXISTS ${trigger}`); }
    if (stage === 'insert') {
      const recovered = await approve(fixture);
      check((await recovered.text()).includes('Cotización aprobada y enviada') && mailLines().length === before + 1, 'an explicit retry after storage recovers creates the first version once');
    }
  }

  // Remove only run-owned installed files, never repository sources. Restore even on assertion failure.
  for (const relative of ['pdf-assets/mark.svg', 'pdf-assets/Manrope-Regular.ttf', 'vendor/dompdf/autoload.inc.php']) {
    const fixture = await prepare('missing-' + relative.split('/').at(-1).replaceAll('.', '-'));
    const path = join(wpDir, 'wp-content/plugins/freeplast-woo', relative);
    const held = join(dirname(wpDir), 'held-' + token);
    const before = mailLines().length;
    renameSync(path, held);
    try {
      const page = await (await approve(fixture)).text();
      const row = versionRow(fixture.id);
      check(page.includes('el documento no se pudo generar o guardar') && row.document === 'pending' && !row.pdf_base64 && mailLines().length === before, `missing approved ${relative} leaves document pending without fallback or mail`);
    } finally { renameSync(held, path); }
  }
  for (const scenario of ['pdf-throw', 'pdf-empty']) {
    const fixture = await prepare(scenario);
    const before = mailLines().length;
    wpEval(`update_option('fpw_stack_delivery_scenario', ${JSON.stringify(scenario)});`);
    try {
      const page = await (await approve(fixture)).text();
      check(page.includes('el documento no se pudo generar o guardar') && versionRow(fixture.id).document === 'pending' && mailLines().length === before, `native ${scenario} is contained without an incomplete offer or a replacement document`);
    } finally { wpEval("update_option('fpw_stack_delivery_scenario', 'ok');"); }
  }
  console.log('quotation native: independent-worker first approval + SQLite INSERT/PDF/result faults + missing approved assets/library + renderer failures passed; no real mail');
}
