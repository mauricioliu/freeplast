import { randomUUID } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';
import { runSalesHistoryTests } from './sales-history-native.mjs';

// Agreed seam: authenticated WordPress screens and their real form actions.
// Setup creates only run-owned synthetic native records; no customer/import data.
export async function runOwnerWorkspaceTests({ wpEval, makeCookieFetch, wpLogin, check, siteUrl }) {
  const suffix = randomUUID().replaceAll('-', '').slice(0, 12);
  const fixture = JSON.parse(wpEval(`
    $password = wp_generate_password(32, false);
    $owner = wp_create_user('workspace-owner-${suffix}', $password, 'owner-${suffix}@example.invalid');
    $staff = wp_create_user('workspace-staff-${suffix}', $password, 'staff-${suffix}@example.invalid');
    (new WP_User($owner))->set_role('shop_manager');
    (new WP_User($staff))->set_role('ventas_freeplast');
    $data = wp_create_user('workspace-data-${suffix}', $password, 'data-${suffix}@example.invalid');
    $quote = wp_create_user('workspace-quote-${suffix}', $password, 'quote-${suffix}@example.invalid');
    (new WP_User($data))->set_role('fpw_data_manager');
    (new WP_User($quote))->set_role('fpw_quotation_manager');
    $product = new WC_Product_Simple(); $product->set_name('Caja prueba workspace ${suffix}');
    $product->set_regular_price('0'); $product->save();
    $order = wc_create_order(); $order->set_billing_company('Empresa workspace ${suffix}');
    $order->set_billing_first_name('Prueba'); $order->set_billing_email('buyer-${suffix}@example.invalid');
    $order->update_meta_data('_fp_request', 'yes'); $order->update_meta_data('_fpw_attempt', hash('sha256', '${suffix}'));
    $order->update_meta_data('_billing_fp_dispatch', 'no'); $order->update_meta_data('_billing_fp_rut', '76.543.210-K');
    $order->add_product($product, 2); $order->save();
    do_action('woocommerce_checkout_order_created', $order);
    $legacy = wc_create_order(); $legacy->set_billing_company('Antigua ${suffix}');
    $legacy->update_meta_data('_fp_request', 'yes'); $legacy->save();
    echo wp_json_encode(array('owner'=>$owner,'staff'=>$staff,'data'=>$data,'quote'=>$quote,'password'=>$password,'product'=>$product->get_id(),'order'=>$order->get_id(),'legacy'=>$legacy->get_id()));
  `));
  const owner = makeCookieFetch(), staff = makeCookieFetch(), guest = makeCookieFetch();
  const inbox = '/wp-admin/admin.php?page=fpw-quotations';
  const document = html => new JSDOM(html).window.document;
  /* The fetch helpers take site-relative paths; JSDOM `.href` is absolute. Normalize
     against the tested SITE_URL (single source, passed by the harness) and refuse
     anything that is not same-origin before converting to pathname+search. */
  const siteOrigin = new URL(siteUrl).origin;
  const asSitePath = (href) => {
    const url = new URL(href, siteUrl);
    if (url.origin !== siteOrigin) { throw new Error('workspace link leaves the tested origin: ' + href); }
    return url.pathname + url.search;
  };
  try {
    check((await guest('/wp-content/database/.ht.sqlite')).status === 404, 'workspace: disposable database cannot be downloaded');
    check(await wpLogin(owner, `workspace-owner-${suffix}`, fixture.password) === 302, 'workspace: owner authenticates');
    check(await wpLogin(staff, `workspace-staff-${suffix}`, fixture.password) === 302, 'workspace: restricted staff authenticates');
    const page = await owner(inbox);
    check(page.status === 200, `workspace: owner can open the quotation inbox (HTTP ${page.status}; redirect path ${page.headers.get('location') ? new URL(page.headers.get('location'), siteUrl).pathname : 'none'})`);
    const doc = document(await page.text());
    // Submenus must register AFTER their parent: native WP derives callback hooks
    // from the parent's title. Offline rendering alone cannot prove this seam.
    for (const [slug, active, field] of [
      ['fpw-data', 'Mantenedores', '.fpw-data-row'],
      ['fpw-price-list', 'Precios', '[name="fpw_price_nonce"]'],
      ['fpw-sales-history', 'Ventas históricas', '.fpw-history'],
      ['fpw-sales-import', 'Importar ventas', '[name="fpw_sales_nonce"]'],
    ]) {
      const response = await owner('/wp-admin/admin.php?page=' + slug);
      check(response.status === 200, 'workspace: native maintenance submenu opens: ' + slug);
      const screen = document(await response.text());
      check(screen.querySelectorAll('.fpw-workspace-header').length === 1 && screen.querySelector('.fpw-workspace-header img')?.getAttribute('src') === doc.querySelector('.fpw-workspace-header img')?.getAttribute('src'), 'workspace: same banner on ' + slug);
      const current = screen.querySelectorAll('.fpw-workspace-navigation [aria-current="page"]');
      check(current.length === 1 && current[0].textContent === active, 'workspace: maintenance active navigation ' + slug);
      if (slug !== 'fpw-data') {
        check(asSitePath(current[0].href) === '/wp-admin/admin.php?page=' + slug, 'workspace: active maintenance link targets its own screen ' + slug);
      }
      check(!!screen.querySelector(field), 'workspace: native maintenance callback and form render: ' + slug);
      check([...screen.querySelectorAll('.fpw-workspace-navigation a')].some(a => asSitePath(a.href) === inbox), 'workspace: maintenance links back to inbox ' + slug);
      check((await staff('/wp-admin/admin.php?page=' + slug)).status === 403, 'workspace: unrelated sales staff stays denied ' + slug);
    }
    const dataActor = makeCookieFetch(), quoteActor = makeCookieFetch();
    check(await wpLogin(dataActor, `workspace-data-${suffix}`, fixture.password) === 302, 'workspace: data maintainer authenticates');
    check(await wpLogin(quoteActor, `workspace-quote-${suffix}`, fixture.password) === 302, 'workspace: quotation manager authenticates');
    for (const slug of ['fpw-data', 'fpw-price-list', 'fpw-sales-history', 'fpw-sales-import']) {
      const response = await dataActor('/wp-admin/admin.php?page=' + slug);
      check(response.status === 200, 'workspace: data-only account opens native submenu ' + slug);
      const screen = document(await response.text());
      check(screen.querySelector('.fpw-workspace-header')?.textContent.includes('Cerrar sesión') && !screen.querySelector('.fpw-workspace-navigation a[href$="page=fpw-quotations"]'), 'workspace: data-only navigation stays within permissions ' + slug);
      check((await quoteActor('/wp-admin/admin.php?page=' + slug)).status === 403, 'workspace: quotation-only account denied data screen ' + slug);
    }
    check((await dataActor(inbox)).status === 403, 'workspace: data-only account denied quotations');
    const quotePage = await quoteActor(inbox);
    check(quotePage.status === 200, 'workspace: quotation-only inbox remains reachable');
    const quoteDoc = document(await quotePage.text());
    check(!quoteDoc.querySelector('.fpw-workspace-maintenance') && !quoteDoc.querySelector('.fpw-maintenance-menu') && quoteDoc.querySelectorAll('.fpw-workspace-navigation a:not(.fpw-workspace-exit)').length === 1, 'workspace: quotation-only inbox offers no forbidden maintenance links');
    check(!doc.querySelector('.fpw-workspace-maintenance') && doc.querySelectorAll('.fpw-workspace-navigation > a:not(.fpw-workspace-exit)').length === 1 && doc.querySelectorAll('.fpw-maintenance-menu').length === 1, 'workspace: inbox has one maintenance dropdown, no duplicate sidebar or top-level price links');
    for (const screen of [quoteDoc, doc]) {
      const exits = screen.querySelectorAll('.fpw-workspace-navigation .fpw-workspace-exit');
      check(exits.length === 1 && exits[0].textContent === 'Salir' && asSitePath(exits[0].href) === '/', 'workspace: Salir returns both roles to storefront home without logging out');
    }
    const maintenance = [...doc.querySelectorAll('.fpw-maintenance-options a')];
    check(maintenance.length === 3 && !doc.querySelector('.fpw-maintenance-menu').hasAttribute('open'), 'workspace: exactly three maintenance options start collapsed');
    check(JSON.stringify(maintenance.map(a => [a.textContent, asSitePath(a.href)])) === JSON.stringify([
      ['Precios', '/wp-admin/admin.php?page=fpw-price-list'],
      ['Ventas históricas', '/wp-admin/admin.php?page=fpw-sales-history'],
      ['Importar ventas', '/wp-admin/admin.php?page=fpw-sales-import'],
    ]), 'workspace: history opens viewer by default and importer stays separately reachable');
    await runSalesHistoryTests({ wpEval, check, owner, dataActor, quoteActor, staff, guest, asSitePath, suffix,
      maintenanceUrl: asSitePath(maintenance.find(a => a.textContent === 'Ventas históricas').href) });
    check(doc.querySelector('h1')?.textContent === 'Solicitudes de clientes', 'workspace: visible quotation inbox heading');
    const link = [...doc.querySelectorAll('a')].find(a => a.textContent.includes(`Empresa workspace ${suffix}`));
    check(!!link, 'workspace: inbox links the real received request, not a demo');
    const legacyPage = document(await (await owner(inbox + '&q=' + encodeURIComponent(`Antigua ${suffix}`))).text());
    check(legacyPage.querySelectorAll('[data-fpw-request]').length === 1 && legacyPage.querySelector(`[data-fpw-request="${fixture.legacy}"]`), 'workspace: all requests includes and searches pre-draft native receipts');
    check(wpEval(`echo fpw_read_request_draft(${fixture.legacy}) ? 'created' : 'absent';`) === 'absent', 'workspace: listing an old receipt never creates a draft');
    check((await staff(inbox)).status === 403, 'workspace: staff cannot read the inbox');
    check((await guest(inbox)).status === 302, 'workspace: guest must authenticate');
    const detailUrl = link.getAttribute('href').replace(/^https?:\/\/[^/]+/, '');
    const detail = document(await (await owner(detailUrl)).text());
    check(!detail.querySelector('form[data-fpw-tracking]') && !detail.body.textContent.includes('Seguimiento comercial'), 'workspace: no manual status selectors remain');
    // Owner inbox now includes reversible archiving as a fourth distinct view.
    const views = [...doc.querySelectorAll('.fpw-request-views a')];
    check(views.length === 4, 'workspace: exactly four owner views are offered (Pendientes/Enviadas/Archivadas/Todas)');
    check(views.some(a => a.textContent.trim() === 'Pendientes' && a.search.includes('stage=pendientes'))
      && views.some(a => a.textContent.trim() === 'Enviadas' && a.search.includes('stage=sent-quotes'))
      && views.some(a => a.textContent.trim() === 'Archivadas' && a.search.includes('stage=archived'))
      && views.some(a => a.textContent.trim() === 'Todas' && a.search.includes('stage=all'))
      && new Set(views.map(a => a.search)).size === 4, 'workspace: each view is labeled in Spanish and targets a distinct stage filter');
    check(views.find(a => a.getAttribute('aria-current') === 'page')?.textContent.trim() === 'Pendientes', 'workspace: the unfiltered inbox defaults to pending work');
    const pendingDefault = document(await (await owner(inbox + '&q=' + encodeURIComponent(`workspace ${suffix}`))).text());
    check(pendingDefault.querySelectorAll('[data-fpw-request]').length === 1, 'workspace: the pending default view lists the new unsent request');
    const formValues = (form, submitter) => Object.fromEntries(new form.ownerDocument.defaultView.FormData(form, submitter));
    const post = (actor, fields) => actor(detailUrl, {method:'POST',headers:{'content-type':'application/x-www-form-urlencoded'},body:new URLSearchParams(fields).toString()});
    const beforeSending = document(await (await owner(inbox + '&stage=sent-quotes&q=' + suffix)).text());
    check(beforeSending.querySelectorAll('[data-fpw-request]').length === 0, 'workspace: received request is not automatically sent');
    const work = detail.querySelector('form[data-fpw-work]');
    check(!!work, 'workspace: A exposes a real quotation editing form');
    const values = {...formValues(work, detail.querySelector('button[name="fpw_work_save"]')), 'fpw_work[lines][0][quantity]':'3', 'fpw_work[lines][0][price]':'1500', 'fpw_work[validity_days]':'7', 'fpw_work[pickup_location]':'mostazal'};
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
    check(conflict.body.textContent.includes('cambios más recientes') && conflict.querySelector('[name="fpw_work[lines][0][price]"]').value === '1500', 'workspace: stale price edit does not overwrite');
    const previewButton = fresh.querySelector('button[data-fpw-preview], [data-fpw-preview] button');
    check(!!previewButton, 'workspace: preview is a separate explicit action');
    const changedQuantity = fresh.querySelector('[name="fpw_work[lines][0][quantity]"]');
    changedQuantity.value = '350';
    check((await post(owner, {...formValues(previewButton.form, previewButton), fpw_preview_nonce:'forged'})).status === 403, 'workspace: no-JS unsaved guard checks the preview nonce before considering changes');
    const refreshFields = {...formValues(previewButton.form), fpw_price_refresh:'1'};
    check(!!refreshFields.fpw_refresh_nonce && !fresh.querySelector('[data-fpw-refresh]'), 'workspace: refresh remains a nonce-guarded handler without a technical UI button');
    check((await post(owner, {...refreshFields, fpw_refresh_nonce:'forged'})).status === 403, 'workspace: explicit refresh handler rejects a forged nonce before considering changes');
    const state = () => wpEval(`echo wp_json_encode(array('work'=>fpw_read_draft_work(${fixture.order}),'preview'=>fpw_read_draft_preview(${fixture.order}),'version'=>fpw_read_quotation_version(${fixture.order})));`);
    const savedState = state();
    const refused = document(await (await post(owner, refreshFields)).text());
    check(refused.body.textContent.includes('Cambios sin guardar') && refused.querySelector('[name="fpw_work[lines][0][quantity]"]')?.value === '350', 'workspace: refresh preserves unsaved inputs without saving');
    const recovery = refused.querySelector('button[name="fpw_work_save"]');
    const recoverFields = formValues(recovery.form, recovery);
    check(recoverFields['fpw_work[lines][0][quantity]'] === '350' && recoverFields.fpw_work_save === '1' && !recoverFields.fpw_work_preview && !recoverFields.fpw_price_refresh, 'workspace: rejected refresh offers save without carrying its rejected action');
    check(state() === savedState, 'workspace: rejected refresh changes neither work nor preview');
    const reviewFields = formValues(previewButton.form, previewButton);
    check((await post(owner, {...reviewFields, fpw_draft_nonce:'forged'})).status === 403 && state() === savedState, 'workspace: saving review edits requires a valid save nonce');
    const invalid = document(await (await post(owner, {...reviewFields, 'fpw_work[lines][0][quantity]':'0', 'fpw_work[lines][0][price]':'', 'fpw_work[pickup_location]':'', 'fpw_work[validity_days]':'366'})).text());
    check(invalid.querySelector('[data-fpw-work]')?.noValidate && !invalid.querySelector('.fpw-workspace-preview'), 'workspace: invalid no-JS review stays in the editor');
    for (const name of ['lines][0][quantity', 'lines][0][price', 'pickup_location', 'validity_days']) {
      check(invalid.querySelector(`[name="fpw_work[${name}]"]`)?.getAttribute('aria-invalid') === 'true', 'workspace: server highlights invalid field ' + name);
    }
    check(invalid.querySelector('[name="fpw_work[validity_days]"]').value === '366' && state() === savedState, 'workspace: malformed review preserves entered values without saving');
    const incomplete = document(await (await post(owner, {...reviewFields, 'fpw_work[lines][0][price]':'', 'fpw_work[pickup_location]':''})).text());
    const pending = JSON.parse(state());
    check(incomplete.querySelector('[data-fpw-work]') && !incomplete.querySelector('.fpw-workspace-preview') && pending.work.lines[0].price === null && pending.preview === null && pending.version === null, 'workspace: incomplete review cannot create preview or issuance');
    check(incomplete.querySelector('[data-fpw-review-error]')?.textContent.includes('precio neto') && incomplete.querySelector('[data-fpw-review-error]')?.textContent.includes('retiro'), 'workspace: no-JS summary details all missing data');
    const direct = document(await (await owner(detailUrl + '&review=1')).text());
    check(direct.querySelector('[data-fpw-work]') && direct.querySelector('[aria-invalid="true"]') && !direct.querySelector('.fpw-workspace-preview'), 'workspace: direct review cannot bypass missing-data validation');
    const completeButton = direct.querySelector('[data-fpw-preview]');
    const completeFields = {...formValues(completeButton.form, completeButton), 'fpw_work[lines][0][price]':'1500', 'fpw_work[pickup_location]':'mostazal'};
    const completed = document(await (await post(owner, completeFields)).text());
    const reviewed = JSON.parse(state());
    check(completed.querySelector('.fpw-workspace-preview') && reviewed.work.lines[0].quantity === 350 && reviewed.preview.revision === reviewed.work.revision && reviewed.preview.projection.complete && reviewed.version === null, 'workspace: complete review saves edits and binds the preview without approval');
    const reviewedState = state();
    const stale = document(await (await post(owner, completeFields)).text());
    check(stale.body.textContent.includes('cambios más recientes') && state() === reviewedState, 'workspace: stale review cannot overwrite reviewed work');
    const adjust = document(await (await owner(detailUrl)).text());
    const adjustButton = adjust.querySelector('[data-fpw-preview]');
    const preview = document(await (await post(owner, {...formValues(adjustButton.form, adjustButton), 'fpw_work[lines][0][quantity]':'3'})).text());
    check(preview.body.textContent.includes('5.355 CLP'), 'workspace: server preview agrees with 3 × 1500 plus the configured synthetic tax');
    const approval = [...preview.querySelectorAll('form')].find(f => f.querySelector('[name="fpw_approve_preview"]'));
    check(!!approval, 'workspace: reviewed preview offers the bound approval action');
    await post(owner, formValues(approval));
    const issued = document(await (await owner(detailUrl)).text());
    check(!issued.querySelector('[data-fpw-work], [data-fpw-unit-price], .fpw-request-header, .fpw-workspace-history') && issued.querySelector('iframe'), 'workspace: issued screen shows only delivery confirmation and PDF, without duplicate editable or commercial facts');
    const pdfUrl = [...issued.querySelectorAll('a[href*="action=fpw_quotation_pdf"]')].find(a => !new URL(a.href).searchParams.has('view'))?.href;
    check(!!pdfUrl, 'workspace: approved document has an authenticated download');
    const pdfPath = asSitePath(pdfUrl);
    const pdf = await owner(pdfPath);
    check(pdf.status === 200 && pdf.headers.get('content-type') === 'application/pdf' && pdf.headers.get('cache-control').includes('no-store') && pdf.headers.get('content-disposition').startsWith('attachment;'), 'workspace: PDF download is private, attachment and non-cacheable');
    const inlinePath = asSitePath(issued.querySelector('iframe').src.split('#')[0]);
    const inlinePdf = await owner(inlinePath);
    check(inlinePdf.status === 200 && inlinePdf.headers.get('content-disposition').startsWith('inline;') && inlinePdf.headers.get('cache-control').includes('no-store'), 'workspace: authenticated viewer uses private inline PDF');
    const frozenBytes = wpEval(`echo fpw_read_quotation_version(${fixture.order})['pdf_base64'];`);
    check(Buffer.from(await pdf.arrayBuffer()).toString('base64') === frozenBytes && Buffer.from(await inlinePdf.arrayBuffer()).toString('base64') === frozenBytes, 'workspace: downloaded and inline bytes exactly match the approved stored PDF');
    check((await staff(inlinePath)).status === 403, 'workspace: inline PDF never bypasses owner authorization');
    check((await staff(pdfPath)).status === 403, 'workspace: PDF URL does not grant staff owner capability');
    const forgedPdfUrl = new URL(pdfPath, siteUrl); forgedPdfUrl.searchParams.set('_wpnonce', 'forged');
    check((await owner(asSitePath(forgedPdfUrl.href))).status === 403, 'workspace: forged download nonce refused');
    const wrongVersionUrl = new URL(pdfPath, siteUrl); wrongVersionUrl.searchParams.set('version', '2');
    check((await owner(asSitePath(wrongVersionUrl.href))).status === 403, 'workspace: PDF nonce is bound to the displayed version');
    const issuedReview = document(await (await owner(detailUrl + '&review=1')).text());
    check(!issuedReview.querySelector('[name="fpw_work_preview"], [name="fpw_work_approve"]') && !issuedReview.body.textContent.includes('nada fue aprobado'), 'workspace: issued review never offers draft actions or contradicts approval');
    check(!issued.querySelector('[data-fpw-tracking], [name="fpw_tracking_save"]'), 'workspace: issued page does not reintroduce manual status controls');
    const dom = new JSDOM(fresh.documentElement.outerHTML, {runScripts:'outside-only'});
    try {
      dom.window.eval(readFileSync(new URL('../wp-content/plugins/freeplast-woo/assets/owner-workspace.js', import.meta.url), 'utf8'));
      const input = dom.window.document.querySelector('[name="fpw_work[lines][0][quantity]"]');
      input.value = '350'; input.dispatchEvent(new dom.window.Event('input', {bubbles:true}));
      check(dom.window.document.querySelector('[data-fpw-total-label]').textContent.includes('sin guardar') && !dom.window.document.querySelector('[data-fpw-save-state]').textContent.includes('Importes estimados; al revisar'), 'workspace: transient total remains labeled without removed explanatory copy');
      check(!dom.window.document.querySelector('[data-fpw-preview]').disabled, 'workspace: unsaved edits remain reviewable through guarded save-and-review');
      check(dom.window.document.querySelector('[name="fpw_work[lines][0][price]"]').value === '1500', 'workspace: quantity does not change offered price');
    } finally { dom.window.close(); }
    const searched = document(await (await owner(inbox + '&stage=all&q=' + encodeURIComponent(`workspace ${suffix}`))).text());
    check(searched.querySelectorAll('[data-fpw-request]').length === 1, 'workspace: server search finds this company across ALL requests (an issued request no longer sits in the pending default)');
    const empty = document(await (await owner(inbox + '&q=unmatched-' + suffix)).text());
    check(empty.body.textContent.includes('No hay coincidencias'), 'workspace: empty search offers recovery');
    const sent = document(await (await owner(inbox + '&stage=sent-quotes&q=' + suffix)).text());
    check(sent.querySelectorAll('[data-fpw-request]').length === 1 && sent.querySelector('[data-fpw-request-state="sent"] time'), 'workspace: successful transport automatically puts the request in sent quotations');
    wpEval(`update_option('fpw_tracking_${fixture.order}', wp_json_encode(array('events'=>array('paid'=>'2026-09-10','accepted'=>'2026-09-10','dispatched'=>'2026-09-10'))));`);
    const stillSent = document(await (await owner(inbox + '&stage=sent-quotes&q=' + suffix)).text());
    check(stillSent.querySelectorAll('[data-fpw-request]').length === 1, 'workspace: historical manual marks do not remove sent quotations');
    wpEval(`$work = fpw_read_draft_work(${fixture.order}); $work['lines'][0]['price'] = 9999; update_option('fpw_draft_work_${fixture.order}', wp_json_encode($work));`);
    const frozen = document(await (await owner(detailUrl)).text());
    const frozenPdf = await owner(asSitePath(frozen.querySelector('iframe').src.split('#')[0]));
    check(Buffer.from(await frozenPdf.arrayBuffer()).toString('base64') === frozenBytes && !frozen.querySelector('[data-fpw-unit-price]'), 'workspace: viewer preserves frozen PDF bytes after later working-data changes');
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
      wp_delete_user(${fixture.data}); wp_delete_user(${fixture.quote});
      $legacy=wc_get_order(${fixture.legacy}); if($legacy){$legacy->delete(true);}
      $order = wc_get_order(${fixture.order}); if ($order) $order->delete(true);
      wp_delete_post(${fixture.product}, true);
      foreach (array('fpw_draft_', 'fpw_draft_work_', 'fpw_draft_preview_', 'fpw_quotation_', 'fpw_tracking_') as $prefix) delete_option($prefix . ${fixture.order});
    `);
  }
}
