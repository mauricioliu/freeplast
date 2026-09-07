#!/usr/bin/env python3
"""Full verifier flow with all network I/O mocked; no servers or credentials."""
import copy
from datetime import datetime, timezone, timedelta
import importlib.util
import json
from pathlib import Path
import tempfile
import sys
import unittest
from unittest.mock import patch

sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('verifier', Path(__file__).with_name('verify-ventas-role.py'))
v = importlib.util.module_from_spec(spec)
spec.loader.exec_module(v)
NOW = datetime(2026, 9, 7, 16, 0, tzinfo=timezone.utc)
RUN = 'a' * 32
RECEIPT = {'order_id': 42, 'run': RUN, 'started_at': (NOW-timedelta(minutes=2)).isoformat()}
ORDER = {'id':42,'billing':{'email':f'ventas-{RUN}@example.invalid','first_name':f'NO ATENDER {RUN}'},
         'meta_data':[{'key':'_fp_verification_run','value':RUN},{'key':'_qwc_quote','value':'1'}],
         'date_created_gmt':'2026-09-07T15:59:00','status':'pending','date_paid':None,'line_items':[{'id':88,'quantity':5}]}

class Network:
    def __init__(self, order=None, *, login_fail=False, later_error=False, cleanup_fail=False, creation_ambiguous=False, editor_invalid=False):
        self.order=copy.deepcopy(ORDER if order is None else order)
        self.calls=[]; self.user=None; self.note=''; self.login_fail=login_fail
        self.later_error=later_error; self.cleanup_fail=cleanup_fail; self.creation_ambiguous=creation_ambiguous; self.editor_invalid=editor_invalid
    def session(self):
        network=self
        class Session:
            nonce=''
            user='admin'
            def request(self, path, data=None, *, api=False, method=None):
                network.calls.append((self.user,path,copy.deepcopy(data),method))
                if path=='/wp-login.php':
                    if data: self.user=data['log']
                    return 200,''
                if path.endswith('action=rest-nonce'): return 200,'abc123abcd'
                if path.startswith('/wp-json/wp/v2/users/me'):
                    if network.login_fail and self.user!='admin': return 401,{}
                    return 200,{'id':1 if self.user=='admin' else 77,'username':self.user}
                if path.startswith('/wp-json/wp/v2/users?'):
                    return 200,[copy.deepcopy(network.user)] if network.user else []
                if path=='/wp-json/wp/v2/users' and data:
                    network.user={'id':77,'username':data['username'],'email':data['email']}
                    if network.creation_ambiguous: raise v.VerificationError('network_request_failed')
                    return 201,copy.deepcopy(network.user)
                if path.startswith('/wp-json/wp/v2/users/77?') and method=='DELETE':
                    if network.cleanup_fail: return 500,{}
                    network.user=None; return 200,{'deleted':True}
                if path=='/wp-json/wc/v3/orders/42':
                    if method=='PUT': return 403,{'code':'denied'}
                    return (404,{}) if network.order=={} else (200,copy.deepcopy(network.order))
                if path.startswith('/wp-admin/post.php?'):
                    if network.editor_invalid: return 200,'Unexpected page'
                    return 200,(f'FP-2026-000042 NO ATENDER {RUN} Datos originales recibidos '
                        '"add_order_note_nonce":"abc123abcd" '
                        '<input name="_wpnonce" value="abc123abcd">'
                        '<input name="woocommerce_meta_nonce" value="abc123abcd">'+network.note)
                if path=='/wp-admin/admin-ajax.php' and data:
                    if network.later_error: raise v.VerificationError('note_request_failed')
                    network.note=data['note']; return 200,network.note+' exact-date'
                if path=='/wp-admin/post.php' and data: return 403,'solo consulta'
                if path.startswith('/wp-admin/edit.php'): return 200,'post-42'
                raise AssertionError('Unexpected request '+path)
        return Session()
    def mutations(self):
        return [c for c in self.calls if c[2] and (c[1]=='/wp-admin/admin-ajax.php' or c[1]=='/wp-admin/post.php' or '/wc/v3/orders' in c[1])]

class VerifierTest(unittest.TestCase):
    def run_flow(self, net):
        return v.run(copy.deepcopy(RECEIPT),'admin','dummy',session_factory=net.session,now=NOW)
    def test_matching_new_fixture_reaches_permission_probes_and_cleanup(self):
        n=Network(); result,status=self.run_flow(n)
        self.assertEqual(status,0,result); self.assertEqual(len(n.mutations()),3)
        self.assertIsNone(n.user); self.assertTrue(result['checks']['temporary_account_removed'])
    def test_bad_native_provenance_stops_before_every_mutation(self):
        cases=[{},dict(ORDER,id=99),dict(ORDER,meta_data=[]),dict(ORDER,meta_data=[{'key':'_fp_verification_run','value':'old'}]),
               dict(ORDER,billing={'email':'historical@example.invalid','first_name':'NO ATENDER'}),
               dict(ORDER,date_created_gmt='2025-01-01T00:00:00'),dict(ORDER,status='processing'),dict(ORDER,date_created_gmt='malformed')]
        for order in cases:
            with self.subTest(order=order):
                n=Network(order); result,status=self.run_flow(n)
                self.assertEqual(status,1); self.assertEqual(n.mutations(),[])
                self.assertIsNone(n.user); self.assertTrue(result['checks']['temporary_account_removed'])
    def test_failed_sales_login_and_later_error_cleanup(self):
        for options in ({'login_fail':True},{'later_error':True},{'creation_ambiguous':True},{'editor_invalid':True}):
            n=Network(**options); result,status=self.run_flow(n)
            self.assertEqual(status,1); self.assertIsNone(n.user)
            if not options.get('later_error'): self.assertEqual(n.mutations(),[])
    def test_cleanup_failure_preserves_original_failure(self):
        n=Network({},cleanup_fail=True); result,status=self.run_flow(n)
        self.assertEqual(status,1); self.assertIn('fixture_not_found',result['errors'])
        self.assertIn('account_cleanup_failed',result['errors']); self.assertFalse(result['checks']['temporary_account_removed'])
        self.assertEqual(n.mutations(),[])
    def test_cleanup_never_targets_unrelated_user(self):
        n=Network({}); result,status=self.run_flow(n)
        deletes=[c[1] for c in n.calls if c[3]=='DELETE']
        self.assertEqual(deletes,['/wp-json/wp/v2/users/77?force=true&reassign=1'])
    def test_absent_malformed_and_expired_receipts_rejected_offline(self):
        for obj in ({},dict(RECEIPT,order_id='42'),dict(RECEIPT,run=''),dict(RECEIPT,started_at='2025-01-01T00:00:00Z')):
            with tempfile.TemporaryDirectory() as temp:
                path=Path(temp)/'receipt.json';path.write_text(json.dumps(obj))
                with self.assertRaises(v.VerificationError): v.load_receipt(path,NOW)
    def test_historical_id_flag_rejected_before_network(self):
        with patch('urllib.request.build_opener',side_effect=AssertionError('network forbidden')):
            with self.assertRaises(SystemExit) as error: v.main(['--execute-staging','--order-id','42'])
            self.assertEqual(error.exception.code,2)

if __name__=='__main__': unittest.main()
