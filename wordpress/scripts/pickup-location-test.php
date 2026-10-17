<?php
/** Pickup selection is an owner fact, frozen with the approved commercial projection. */
require __DIR__ . '/quote-draft-test.php';
$start = $assertions;
$draft = fpw_read_request_draft(92);
$work = fpw_read_draft_work(92);
$stored_work = $work;
$expected = array(
    'los-paltos' => 'Los Paltos 2794, La Pintana',
    'mostazal' => 'Camino El Arrayán 52, San Francisco de Mostazal',
    'fresia' => 'Fresia 1610, La Pintana',
);
check(fpw_pickup_locations() === $expected, 'exactly three approved addresses retain existing IDs and add Fresia');
$issued_rows = $GLOBALS['fpwd_table'];
foreach ($expected as $key => $address) {
    $input = array('fpw_work' => array('pickup_location' => $key, 'lines' => array(array('quantity' => '10', 'price' => '990'))));
    $parsed = fpw_parse_draft_work_input($draft, $input);
    check(empty($parsed['errors']) && $parsed['pickup_location'] === $key, 'known pickup is accepted');
    $work['pickup_location'] = $key;
    $projection = fpw_quotation_projection($draft, $work);
    check($projection['complete'] && $projection['pickup_address'] === $address && $projection['dispatch'] === null, 'pickup is frozen without freight');
    $version = fpw_read_quotation_version(92);
    $version['projection'] = $projection;
    foreach (array(fpw_workspace_offer_html($projection), fpw_quotation_email_html($version), fpw_quotation_document_html($version, '', 'sans-serif')) as $html) {
        check(str_contains($html, htmlspecialchars($address, ENT_QUOTES, 'UTF-8')) && str_contains($html, 'Retiro - Sin Despacho'), 'offer, email and PDF show selected pickup');
    }
    $radios = fpw_draft_pickup_html($key);
    check(substr_count($radios, 'type="radio"') === 3 && substr_count($radios, ' checked') === 1 && str_contains($radios, 'value="' . $key . '" checked'), 'three radio cards retain the exact saved selection');
    check(str_contains($radios, htmlspecialchars($address, ENT_QUOTES, 'UTF-8')), 'owner review shows corrected address before approval');
    $probe = fpw_test_pdf_probe(fpw_quotation_render_document($version));
    check(str_contains(preg_replace('/\s+/u', ' ', $probe['text']), $address), 'real synthetic PDF carries exact selected pickup');
}
check(fpw_read_draft_work(92) === $stored_work, 'projection and rendering never change saved pickup selection');
check($GLOBALS['fpwd_table'] === $issued_rows, 'new projections and PDFs leave every issued row and its bytes untouched');

// A historical issued projection keeps its old address, even under a retained pickup ID.
$historical = fpw_read_quotation_version(93);
$historical['projection']['pickup_address'] = 'San Francisco de Mostazal (Fresia 1610, La Pintana)';
$historical['pdf_base64'] = base64_encode(fpw_quotation_render_document($historical));
$GLOBALS['fpwd_table']['fpw_quotation_93'] = wp_json_encode($historical);
$frozen_row = $GLOBALS['fpwd_table']['fpw_quotation_93'];
$frozen = fpw_read_quotation_version(93);
foreach (array(fpw_workspace_offer_html($frozen['projection']), fpw_quotation_email_html($frozen), fpw_quotation_document_html($frozen, '', 'sans-serif')) as $html) {
    check(str_contains($html, $historical['projection']['pickup_address']), 'historical output uses frozen address, not corrected lookup');
}
$probe = fpw_test_pdf_probe(base64_decode($frozen['pdf_base64'], true));
check(str_contains(preg_replace('/\s+/u', ' ', $probe['text']), $historical['projection']['pickup_address']), 'historical PDF retains original address');
fpw_quotation_projection(fpw_read_request_draft(93), fpw_read_draft_work(93));
check($GLOBALS['fpwd_table']['fpw_quotation_93'] === $frozen_row && fpw_read_quotation_version(93)['pdf_base64'] === $historical['pdf_base64'], 'new review cannot rewrite historical issued bytes');
$work['pickup_location'] = '';
check(!fpw_quotation_projection($draft, $work)['complete'], 'missing pickup blocks review and approval');
$work['pickup_location'] = 'forged';
check(!fpw_quotation_projection($draft, $work)['complete'], 'unknown stored pickup cannot issue');
$bad = fpw_parse_draft_work_input($draft, array('fpw_work' => array('pickup_location' => '<script>')));
check(!empty($bad['errors']) && $bad['pickup_location'] === '', 'unknown posted pickup is rejected');
$dispatch = fpw_read_request_draft(91);
$parsed = fpw_parse_draft_work_input($dispatch, array('fpw_work' => array('pickup_location' => 'los-paltos')));
check($parsed['pickup_location'] === '', 'dispatch ignores pickup');
check(fpw_quotation_projection($dispatch, fpw_read_draft_work(91))['pickup_address'] === '', 'dispatch has no pickup address');
echo 'pickup locations: ' . ($assertions - $start) . " checks passed\n";
