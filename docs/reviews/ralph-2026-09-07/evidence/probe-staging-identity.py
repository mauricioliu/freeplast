"""Offline execution of the staging verifier with ALL network I/O replaced.
The order page deliberately fails identity verification. Stop at first note POST.
No actual credentials, HTTP, server, database, or repository writes.
"""
import os
import runpy
import sys
import urllib.parse
import urllib.request

class MutationReached(RuntimeError):
    pass

class Reply:
    def getcode(self):
        return 200
    def read(self):
        # Enough to provision the fake account/login, but NO order reference or
        # synthetic email: identity_verified must be false.
        return (b'<input name="_wpnonce_create-user" value="abc123">'
                b'<a href="user-edit.php?user_id=999">fixture</a>'
                b'<input name="_wpnonce" value="abc123">'
                b'"add_order_note_nonce":"abc123"')

class Opener:
    def open(self, request, timeout=None):
        data = urllib.parse.parse_qs((request.data or b'').decode())
        if data.get('action') == ['woocommerce_add_order_note']:
            print('MUTATION_REACHED_AFTER_FAILED_IDENTITY: order_id=' + data['post_id'][0])
            raise MutationReached('stopped before any write; all network mocked')
        return Reply()

urllib.request.build_opener = lambda *args, **kwargs: Opener()
os.environ['FREEPLAST_SALES_ADMIN_USER'] = 'offline-fixture'
os.environ['FREEPLAST_SALES_ADMIN_PASS'] = 'offline-fixture-not-a-real-credential'
sys.argv = ['verify-ventas-role.py', '--execute-staging', '--order-id', '77']
try:
    runpy.run_path('/home/mauricio-liu/Projects/freeplast/wordpress/scripts/verify-ventas-role.py', run_name='__main__')
except MutationReached as exc:
    print(str(exc))
