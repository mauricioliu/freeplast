#!/usr/bin/env python3
"""Offline operator CONTROL FLOW tests. All HTTP and subprocess I/O mocked.
No selective helper extraction and no clearing of failures. unittest owns exits.
"""
import contextlib
import io
import json
import os
from pathlib import Path
import runpy
import sys
import unittest
from unittest.mock import patch
from urllib.parse import parse_qs, urlsplit

SCRIPT = Path(__file__).with_name('woo-ventas-guard.py')
RUN = 'abcdef123456'
EMAIL = f'ventas-{RUN}@example.invalid'
EDITOR = f'''FP-2026-000042 Caja Universal Datos originales recibidos RUT Empresa 76.555.555-5 <input name="_billing_email" value="{EMAIL}">
<tr class="item " data-order_item_id="88"><td><input name="order_item_qty[88]" value="5"></td></tr>'''

class MutationReached(Exception):
    pass

class Reply:
    headers = {}
    def __init__(self, body, code=200): self.body, self.code = body, code
    def read(self): return self.body.encode()
    def getcode(self): return self.code

class FlowTest(unittest.TestCase):
    def execute(self, *, editor=EDITOR, login=True, run=RUN, snapshot_ok=True, mode='guard-off'):
        calls = []
        class Opener:
            def open(self, request, timeout=None):
                path = urlsplit(request.full_url)
                data = parse_qs((request.data or b'').decode())
                calls.append((path.path, data))
                if path.path == '/wp-json/wc/store/v1/cart': return Reply('{}')
                if path.path == '/wp-json/wc/store/v1/products': return Reply('[{"id":22}]')
                if path.path == '/wp-json/wc/store/v1/cart/add-item': return Reply('{}')
                if path.path == '/checkout/': return Reply('<input type="hidden" name="woocommerce-process-checkout-nonce" value="fixture">')
                if path.path == '/': return Reply('{"result":"success","redirect":"/order-received/42/"}')
                if path.path == '/wp-admin/index.php': return Reply('edit.php?post_type=shop_order')
                if path.path == '/wp-admin/edit.php': return Reply('shop_order FP-2026-000042 post-42')
                if path.path == '/wp-login.php': return Reply('')
                if path.path == '/wp-admin/profile.php': return Reply('<form id="your-profile">' if login else '<input name="log">')
                if path.path == '/wp-admin/post.php': return Reply(editor)
                query = parse_qs(path.query)
                if query.get('action') == ['fpw_test_nonce']: return Reply('{"data":{"nonce":"fixture"}}')
                raise MutationReached()
        def snapshot(*args, **kwargs):
            return type('Result', (), {'returncode': 0 if snapshot_ok else 1,
                       'stdout': json.dumps({'digest': 'a'*64, 'item_ids': [88]})})()
        env = {'FREEPLAST_VENTAS_USER': 'fixture', 'FREEPLAST_VENTAS_PASS': 'dummy',
               'FREEPLAST_VENTAS_RUN': run, 'FREEPLAST_VENTAS_ORDER': '42',
               'FREEPLAST_VENTAS_STATE_COMMAND': '["mock-cli"]'}
        output = io.StringIO()
        with patch.dict(os.environ, env), patch.object(sys, 'argv', [str(SCRIPT), '--base', 'http://mliu:8091', '--mode', mode]), \
             patch('urllib.request.build_opener', return_value=Opener()), patch('subprocess.run', side_effect=snapshot), \
             contextlib.redirect_stdout(output):
            try:
                runpy.run_path(str(SCRIPT), run_name='__main__')
            except (SystemExit, MutationReached, RuntimeError) as e:
                e.details = output.getvalue()
                return e, calls
        self.fail('expected bounded stop')

    def test_negative_provenance_and_login_never_reach_mutation(self):
        cases = [dict(editor=EDITOR.replace(EMAIL, 'historical@example.invalid')),
                 dict(editor='unexpected page'), dict(editor=EDITOR.replace('000042', '000099')),
                 dict(editor=EDITOR.replace('value="5"', 'value=""')),
                 dict(login=False), dict(run=''), dict(run='malformed'), dict(snapshot_ok=False),
                 dict(mode='guarded', editor=EDITOR.replace(EMAIL, 'historical@example.invalid'))]
        for case in cases:
            with self.subTest(case=case):
                error, calls = self.execute(**case)
                self.assertNotIsInstance(error, MutationReached)
                self.assertFalse(any(p == '/wp-admin/admin-ajax.php' and d for p, d in calls))
                if isinstance(error, SystemExit): self.assertNotEqual(error.code, 0)

    def test_verified_control_reaches_actual_tax_payload(self):
        error, calls = self.execute()
        self.assertIsInstance(error, MutationReached)
        payload = calls[-1][1]
        self.assertEqual(payload['action'], ['woocommerce_calc_line_taxes'])
        self.assertIn('order_item_qty[88]=999', payload['items'][0])
        error, calls = self.execute(mode='guarded')
        self.assertIsInstance(error, MutationReached, error.details)
        self.assertEqual(calls[-1][1]['action'], ['woocommerce_add_order_note'])

    def test_recheck_missing_line_cannot_fallback_to_one(self):
        error, calls = self.execute(mode='recheck', editor=EDITOR.replace('value="5"', 'value=""'))
        self.assertIsInstance(error, SystemExit)
        self.assertFalse(any(p == '/wp-admin/admin-ajax.php' and d for p, d in calls))

if __name__ == '__main__': unittest.main()
