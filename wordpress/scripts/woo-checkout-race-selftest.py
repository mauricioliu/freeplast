#!/usr/bin/env python3
"""Offline self-test of the native plain-route helper (issue #36 final red-gate).

NO HTTP, NO server, NO WordPress: a mocked Session records the payload the
helper would POST, and the recorded payload is checked against the NATIVE
dispatch condition — the pinned WooCommerce 11.1.0
WC_Form_Handler::checkout_action dispatches WC()->checkout()->process_checkout()
ONLY when $_POST carries woocommerce_checkout_place_order (the place-order
submit trigger) or woocommerce_checkout_update_totals (deliberately unused: it
skips order processing and would make the plain-route probe vacuous).

Executed by check-woo.mjs as part of the offline gate; the helper source is
extracted from woo-checkout-race.py without executing that scenario module.
"""
import json
import pathlib
import sys

SOURCE = pathlib.Path(__file__).with_name('woo-checkout-race.py').read_text()
START = SOURCE.index('def post_checkout_plain(')
END = SOURCE.index('\ndef ', START + 1)
NAMESPACE = {}
exec(compile(SOURCE[START:END], 'post_checkout_plain', 'exec'), NAMESPACE)
post_checkout_plain = NAMESPACE['post_checkout_plain']


class MockSession:
    """Records every request the helper makes; answers a static body."""

    def __init__(self):
        self.calls = []

    def request(self, path, data=None, **_):
        self.calls.append({'path': path, 'data': dict(data or {})})
        return 200, '<html>Tu solicitud anterior ya fue recibida.</html>'


failures = []


def ok(name, condition):
    if not condition:
        failures.append(name)
        print(f'FAILED: {name}')


values = {
    'woocommerce-process-checkout-nonce': 'nonce-value',
    'fpw_attempt': 'a' * 40,
    'billing_first_name': 'Cliente',
    'billing_fp_dispatch': 'no',
    'payment_method': 'quotes-gateway',
}
original = dict(values)
session = MockSession()
code, body = post_checkout_plain(session, values)

ok('helper_returns_response', code == 200 and 'ya fue recibida' in body)
call = session.calls[0] if len(session.calls) == 1 else None
ok('single_plain_route_post', call is not None and call['path'] == '/checkout/')
ok('place_order_trigger_present', call is not None and call['data'].get('woocommerce_checkout_place_order') not in (None, ''))
ok('no_update_totals_shortcut', call is not None and 'woocommerce_checkout_update_totals' not in call['data'])
ok('original_values_unchanged', values == original)
ok('payload_carries_original_values', call is not None and all(call['data'].get(key) == value for key, value in original.items()))

# The NATIVE dispatch predicate (pinned class-wc-form-handler.php,
# WC_Form_Handler::checkout_action): process_checkout runs only when the
# trigger is present in the posted payload — evaluated exactly as the native
# isset() pair does, against the payload the helper actually POSTs.
posted = call['data'] if call else {}
native_dispatches = 'woocommerce_checkout_place_order' in posted or 'woocommerce_checkout_update_totals' in posted
ok('native_checkout_action_dispatches', native_dispatches and 'woocommerce_checkout_place_order' in posted)

if failures:
    print(json.dumps({'failures': failures}))
    sys.exit(1)
print('post_checkout_plain self-test: 7 checks passed (place-order trigger present, no update_totals shortcut, caller values unchanged, native checkout_action dispatch predicate satisfied)')
