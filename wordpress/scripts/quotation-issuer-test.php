<?php
/** Issuer snapshots and historical bytes, with fictitious buyers and stub mail only. */
require __DIR__ . '/quote-draft-test.php';
$issued = fpw_read_quotation_version(91);
$issuer = $issued['issuer'];
check($issuer['name'] === 'Freeplast SPA' && $issuer['rut'] === '76.766.847-3', 'verified issuer frozen');
check($issuer['bank'] === 'Banco de Chile' && $issuer['account_type'] === 'Cuenta corriente' && $issuer['account_number'] === '390400602', 'verified bank frozen');
check($issuer['address'] === 'P Picarquín sitio 52, San Fco de Mostazal, VI Región' && $issuer['email'] === 'ventas@freeplast.cl', 'approved new address and email frozen');
check(hash('sha256', base64_decode($issuer['logo_base64'], true)) === $issuer['logo_sha256'], 'official converted logo bytes frozen');
$h = fpw_quotation_document_html($issued, '', 'sans-serif');
check(str_contains($h, 'data:image/png;base64,') && str_contains($h, 'Banco de Chile') && str_contains($h, '390400602'), 'PDF HTML uses frozen logo and bank');
check(str_contains($h, 'stock disponible') && str_contains($h, 'Pago contado') && !str_contains($h, 'retiro en bodega'), 'dispatch offer has stock/cash terms, not pickup');
$pickup = $issued;
$pickup['projection']['dispatch_requested'] = false;
$pickup['issuer'] = fpw_quotation_issuer_snapshot(false);
check(str_contains(fpw_quotation_document_html($pickup, '', 'sans-serif'), 'retiro en bodega'), 'pickup term only without dispatch');
$copy = $issued;
$copy['issuer']['name'] = '<em>Frozen test</em>';
$copy['issuer']['account_number'] = 'FROZEN-ACCOUNT';
$copy['issuer']['payment_terms'] = 'FROZEN-TERMS';
$render = fpw_quotation_document_html($copy, '', 'sans-serif');
check(str_contains($render, '&lt;em&gt;Frozen test&lt;/em&gt;') && str_contains($render, 'FROZEN-ACCOUNT') && str_contains($render, 'FROZEN-TERMS') && !str_contains($render, '390400602'), 'renderer only reads escaped snapshot, never mutable issuer defaults');
$copy['issuer']['logo_base64'] = base64_encode('not a PNG');
try { fpw_quotation_document_html($copy, '', 'sans-serif'); check(false,'bad frozen logo refused'); } catch (RuntimeException $e) { check(true,'bad frozen logo refused'); }
$legacy = $issued;
unset($legacy['issuer']);
$legacy['projection']['validity_days'] = 7;
$legacy_html = fpw_quotation_document_html($legacy, '', 'sans-serif');
check(!str_contains($legacy_html, '390400602') && !str_contains($legacy_html, 'Datos para transferencia') && str_contains($legacy_html, 'Vigencia de la oferta: 7'), 'legacy version is never enriched from current issuer or defaults');
$legacy['pdf_base64'] = base64_encode(fpw_quotation_dompdf_render($legacy));
$legacy_row = wp_json_encode($legacy);
$GLOBALS['fpwd_table']['fpw_quotation_91'] = $legacy_row;
$mail_count = count($GLOBALS['fpwd_mail_calls']);
fpwd_approve_and_render(91);
check($GLOBALS['fpwd_table']['fpw_quotation_91'] === $legacy_row && count($GLOBALS['fpwd_mail_calls']) === $mail_count, 'legacy retry preserves entire version and exact PDF bytes without resend/backfill');
$GLOBALS['fpwd_table']['fpw_quotation_91'] = wp_json_encode($issued);
$GLOBALS['registered_filters']['fpw_quotation_default_validity_days'] = array(fn()=>30);
check(fpw_read_quotation_version(91) === $issued, 'later default changes cannot rewrite approved version');
$GLOBALS['registered_filters']['fpw_quotation_default_validity_days'] = array();
check(fpw_draft_validity_days(array('validity_days'=>7)) === 7, 'explicit saved validity is preserved');
$probe = fpw_test_pdf_probe(base64_decode($issued['pdf_base64'], true));
$text = preg_replace('/\s+/u', ' ', $probe['text']);
foreach (array('Freeplast SPA', '76.766.847-3', '390400602', 'Banco de Chile', 'P Picarquín', 'stock disponible', 'Pago contado') as $value) { check(str_contains($text,$value),'real PDF contains '.$value); }
check(!str_contains($text,'retiro en bodega'), 'real dispatch PDF has no pickup promise');
echo "quotation issuer: $assertions cumulative offline checks passed; no real mail\n";
