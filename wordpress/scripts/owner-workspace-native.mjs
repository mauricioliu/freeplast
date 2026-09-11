import { randomUUID } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

// Agreed seam: authenticated WordPress screens and their real form actions.
// Setup creates only run-owned synthetic native records; no customer/import data.
export async function runOwnerWorkspaceTests({ wpEval, makeCookieFetch, wpLogin, check }) {
  const suffix = randomUUID().replaceAll('-', '').slice(0, 12);
  const fixture = JSON.parse(wpEval(`
    $password = wp_generate_password(32, false);
    $owner = wp_create_user('workspace-owner-${suffix}', $password, 'owner-${suffix}@example.invalid');
    $staff = wp_create_user('workspace-staff-${suffix}', $password, 'staff-${suffix}@example.invalid');
    (new WP_User($owner))->set_role('shop_manager');
    (new WP_User($staff))->set_role('ventas_freeplast');
    $product = new WC_Product_Simple(); $product->set_name('Caja prueba workspace ${suffix}');
    $product->set_regular_price('0'); $product->save();
    $order = wc_create_order(); $order->set_billing_company('Empresa workspace ${suffix}');
    $order->set_billing_first_name('Prueba'); $order->set_billing_email('buyer-${suffix}@example.invalid');
    $order->update_meta_data('_fp_request', 'yes'); $order->update_meta_data('_fpw_attempt', hash('sha256', '${suffix}'));
    $order->update_meta_data('_billing_fp_dispatch', 'no'); $order->update_meta_data('_billing_fp_rut', '76.543.210-K');
    $order->add_product($product, 2); $order->save();
    do_action('woocommerce_checkout_order_created', $order);
    echo wp_json_encode(array('owner'=>$owner,'staff'=>$staff,'password'=>$password,'product'=>$product->get_id(),'order'=>$order->get_id()));
  `));
  const owner = makeCookieFetch(), staff = makeCookieFetch(), guest = makeCookieFetch();
  const inbox = '/wp-admin/admin.php?page=fpw-quotations';
  const document = html => new JSDOM(html).window.document;
  try {
    check((await guest('/wp-content/database/.ht.sqlite')).status === 404, 'workspace: disposable database cannot be downloaded');
    check(await wpLogin(owner, `workspace-owner-${suffix}`, fixture.password) === 302, 'workspace: owner authenticates');
    check(await wpLogin(staff, `workspace-staff-${suffix}`, fixture.password) === 302, 'workspace: restricted staff authenticates');
    const page = await owner(inbox);
    check(page.status === 200, 'workspace: owner can open the quotation inbox');
    const doc = document(await page.text());
    check(doc.querySelector('h1')?.textContent === 'Cotizaciones', 'workspace: visible quotation inbox heading');
    const link = [...doc.querySelectorAll('a')].find(a => a.textContent.includes(`Empresa workspace ${suffix}`));
    check(!!link, 'workspace: inbox links the real received request, not a demo');
    check((await staff(inbox)).status === 403, 'workspace: staff cannot read the inbox');
    check((await guest(inbox)).status === 302, 'workspace: guest must authenticate');
    const detailUrl = link.getAttribute('href').replace(/^https?:\/\/[^/]+/, '');
    const detail = document(await (await owner(detailUrl)).text());
    const tracking = detail.querySelector('form[data-fpw-tracking]');
    check(!!tracking, 'workspace: detail offers the independent commercial tracking form');
    const formValues = form => Object.fromEntries([...form.querySelectorAll('input[name]')].filter(i => i.type !== 'checkbox' || i.checked).map(i => [i.name, i.value]));
    const post = (actor, fields) => actor(detailUrl, {method:'POST',headers:{'content-type':'application/x-www-form-urlencoded'},body:new URLSearchParams(fields).toString()});
    const original = formValues(tracking);
    const paidOnly = {...original, 'fpw_tracking[paid][done]':'1', 'fpw_tracking[paid][date]':'2026-09-10'};
    check((await post(owner, paidOnly)).status === 200, 'workspace: owner saves a paid milestone without prerequisites');
    const reopened = document(await (await owner(detailUrl)).text());
    check(reopened.querySelector('[name="fpw_tracking[paid][done]"]').checked, 'workspace: paid milestone survives reload');
    check(!reopened.querySelector('[name="fpw_tracking[accepted][done]"]').checked && !reopened.querySelector('[name="fpw_tracking[dispatched][done]"]').checked, 'workspace: payment does not mark acceptance or dispatch');
    check(reopened.querySelector('[data-fpw-milestone="sent"]').textContent.includes('Pendiente'), 'workspace: manual tracking cannot claim a quotation was sent');
    const stale = document(await (await post(owner, original)).text());
    check(stale.body.textContent.includes('seguimiento tiene una revisión más reciente'), 'workspace: stale tracking submission reports conflict');
    check(stale.querySelector('[name="fpw_tracking[paid][done]"]').checked, 'workspace: stale submission preserves accepted tracking state');
    const mintResponse = await staff('/wp-admin/admin-ajax.php?action=fpw_test_nonce&for=fpw-tracking-' + fixture.order);
    const staffNonce = (await mintResponse.json()).data.nonce;
    check((await post(staff, {...paidOnly, fpw_tracking_nonce:staffNonce})).status === 403, 'workspace: valid staff nonce grants no tracking power');
    check((await post(owner, {...paidOnly, fpw_tracking_nonce:'forged'})).status === 403, 'workspace: forged tracking nonce refused');
    const freshTracking = formValues(reopened.querySelector('form[data-fpw-tracking]'));
    const invalid = document(await (await post(owner, {...freshTracking, 'fpw_tracking[paid][date]':'2026-02-31'})).text());
    check(invalid.body.textContent.includes('Revisa las fechas') && invalid.querySelector('[name="fpw_tracking[paid][date]"]').value === '2026-09-10', 'workspace: invalid calendar date preserves saved data');
    const corrected = document(await (await post(owner, {...freshTracking, 'fpw_tracking[paid][done]':''})).text());
    check(!corrected.querySelector('[name="fpw_tracking[paid][done]"]').checked, 'workspace: owner can correct a milestone without changing the quotation');
    const work = corrected.querySelector('form[data-fpw-work]');
    check(!!work, 'workspace: A exposes a real quotation editing form');
    const values = {...formValues(work), 'fpw_work[lines][0][quantity]':'3', 'fpw_work[lines][0][price]':'1500', 'fpw_work[validity_days]':'7'};
    const saved = document(await (await post(owner, values)).text());
    check(saved.body.textContent.includes('Cambios guardados'), 'workspace: quotation save uses the existing guarded action');
    const fresh = document(await (await owner(detailUrl)).text());
    check(fresh.querySelector('[name="fpw_work[lines][0][price]"]').value === '1500', 'workspace: offered price survives reload');
    const inspectSeconds = Math.min(300, Number(process.env.FREEPLAST_WORKSPACE_INSPECT_SECONDS || 0));
    if (inspectSeconds > 0 && process.env.FREEPLAST_WORKSPACE_INSPECT_FILE) {
      writeFileSync(process.env.FREEPLAST_WORKSPACE_INSPECT_FILE, JSON.stringify({owner:fixture.owner,request:fixture.order,path:detailUrl}), {mode:0o600});
      console.log('owner workspace: bounded native inspection window open');
      await new Promise(resolve => setTimeout(resolve, inspectSeconds * 1000));
    }
    const conflict = document(await (await post(owner, {...values, 'fpw_work[lines][0][price]':'1900'})).text());
    check(conflict.body.textContent.includes('revisión más reciente') && conflict.querySelector('[name="fpw_work[lines][0][price]"]').value === '1500', 'workspace: stale price edit does not overwrite');
    const previewForm = fresh.querySelector('form[data-fpw-preview]');
    check(!!previewForm, 'workspace: preview is a separate explicit action');
    const preview = document(await (await post(owner, formValues(previewForm))).text());
    check(preview.body.textContent.includes('5.355 CLP'), 'workspace: server preview agrees with 3 × 1500 plus the configured synthetic tax');
    const approval = [...preview.querySelectorAll('form')].find(f => f.querySelector('[name="fpw_approve_preview"]'));
    check(!!approval, 'workspace: reviewed preview offers the bound approval action');
    await post(owner, formValues(approval));
    const issued = document(await (await owner(detailUrl)).text());
    check(issued.querySelector('[name="fpw_work[lines][0][price]"]').matches(':disabled'), 'workspace: issued price is read-only');
    check(issued.querySelector('[data-fpw-milestone="sent"] time'), 'workspace: sent milestone comes from durable transport handoff');
    check(!issued.querySelector('[name="fpw_tracking[accepted][done]"]').checked, 'workspace: transport acceptance is not customer acceptance');
    const dom = new JSDOM(fresh.documentElement.outerHTML, {runScripts:'outside-only'});
    try {
      dom.window.eval(readFileSync(new URL('../wp-content/plugins/freeplast-woo/assets/owner-workspace.js', import.meta.url), 'utf8'));
      const input = dom.window.document.querySelector('[name="fpw_work[lines][0][quantity]"]');
      input.value = '350'; input.dispatchEvent(new dom.window.Event('input', {bubbles:true}));
      check(dom.window.document.querySelector('[data-fpw-save-state]').textContent.includes('sin guardar'), 'workspace: editing names unsaved totals, without a second browser calculator');
      check(dom.window.document.querySelector('[data-fpw-preview] button').disabled, 'workspace: unsaved values cannot be mistaken for the preview');
      check(dom.window.document.querySelector('[name="fpw_work[lines][0][price]"]').value === '1500', 'workspace: quantity does not change offered price');
    } finally { dom.window.close(); }
    const searched = document(await (await owner(inbox + '&q=' + encodeURIComponent(`workspace ${suffix}`))).text());
    check(searched.querySelectorAll('[data-fpw-request]').length === 1, 'workspace: server search finds this company across requests');
    const empty = document(await (await owner(inbox + '&q=unmatched-' + suffix)).text());
    check(empty.body.textContent.includes('No hay coincidencias'), 'workspace: empty search offers recovery');
    const accepting = document(await (await owner(inbox + '&stage=accepted&q=' + suffix)).text());
    check(accepting.querySelectorAll('[data-fpw-request]').length === 1, 'workspace: handed-off quotation filters as awaiting customer acceptance');
    const notComplete = document(await (await owner(inbox + '&stage=complete&q=' + suffix)).text());
    check(notComplete.querySelectorAll('[data-fpw-request]').length === 0, 'workspace: handoff alone is not a completed commercial journey');
    wpEval(`$work = fpw_read_draft_work(${fixture.order}); $work['lines'][0]['price'] = 9999; update_option('fpw_draft_work_${fixture.order}', wp_json_encode($work));`);
    const frozen = document(await (await owner(detailUrl)).text());
    check(frozen.querySelector('[name="fpw_work[lines][0][price]"]').value === '1500', 'workspace: issued fields read the approved projection, not later working data');
    wpEval(`$version=fpw_read_quotation_version(${fixture.order}); unset($version['projection']); update_option('fpw_quotation_${fixture.order}', wp_json_encode($version));`);
    const unavailable = document(await (await owner(detailUrl)).text());
    check(unavailable.body.textContent.includes('Versión aprobada no disponible') && !unavailable.querySelector('[data-fpw-work]'), 'workspace: unreadable approved projection never falls back to working amounts');
    wpEval(`update_option('fpw_quotation_${fixture.order}', 'invalid-json');`);
    const corrupt = document(await (await owner(detailUrl)).text());
    check(corrupt.body.textContent.includes('Versión aprobada no disponible') && !corrupt.querySelector('[data-fpw-work]'), 'workspace: corrupt issuance row is not mistaken for an editable draft');
  } finally {
    wpEval(`
      require_once ABSPATH . 'wp-admin/includes/user.php';
      wp_delete_user(${fixture.owner}); wp_delete_user(${fixture.staff});
      $order = wc_get_order(${fixture.order}); if ($order) $order->delete(true);
      wp_delete_post(${fixture.product}, true);
      foreach (array('fpw_draft_', 'fpw_draft_work_', 'fpw_draft_preview_', 'fpw_quotation_', 'fpw_tracking_') as $prefix) delete_option($prefix . ${fixture.order});
    `);
  }
}
