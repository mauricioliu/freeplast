#!/usr/bin/env python3
"""Staging-only Ventas verifier. Never searches for an existing request to mutate.

Operator preconditions: separately authorized staging run, backup, independently
contained mail, and a NEW pending NO ATENDER fixture. See verification-safety.md.
Pass its run receipt with --fixture FILE; --order-id is deliberately unsupported.
Only exact stored provenance + recent native creation time authorize probes.
Temporary accounts are run-owned; fixture cleanup belongs to its provisioning
operator (this verifier never deletes a fixture on an identity failure).
"""
import argparse
import contextlib
from datetime import datetime, timezone, timedelta
import http.cookiejar
import json
import os
from pathlib import Path
import re
import secrets
import sys
import urllib.error
import urllib.parse
import urllib.request

BASE = 'https://freeplast.mliu.site'

class VerificationError(Exception):
    """Safe, value-free error name; never propagate dependency bodies."""

class Session:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.nonce = ''

    def request(self, path, data=None, *, method=None, api=False):
        headers = {}
        if api:
            headers['Content-Type'] = 'application/json'
            if self.nonce: headers['X-WP-Nonce'] = self.nonce
        body = None if data is None else (json.dumps(data) if api else urllib.parse.urlencode(data)).encode()
        request = urllib.request.Request(BASE + path, data=body, headers=headers, method=method)
        try:
            response = self.opener.open(request, timeout=30)
        except urllib.error.HTTPError as error:
            response = error
        except (OSError, urllib.error.URLError):
            raise VerificationError('network_request_failed') from None
        with contextlib.closing(response):
            raw = response.read().decode('utf-8', 'replace')
            if api:
                try: raw = json.loads(raw)
                except ValueError: raise VerificationError('unexpected_api_response') from None
            return response.getcode(), raw


def require(condition, code):
    if not condition: raise VerificationError(code)


def utc(value):
    require(isinstance(value, str), 'invalid_fixture_time')
    require(re.fullmatch(r'\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|\+00:00)?', value), 'invalid_fixture_time')
    try:
        return datetime.fromisoformat(value.replace('Z', '+00:00')).replace(tzinfo=timezone.utc)
    except ValueError:
        raise VerificationError('invalid_fixture_time') from None


def load_receipt(path, now):
    try: receipt = json.loads(Path(path).read_text())
    except (OSError, ValueError): raise VerificationError('invalid_fixture_receipt') from None
    require(isinstance(receipt, dict), 'invalid_fixture_receipt')
    require(type(receipt.get('order_id')) is int and receipt['order_id'] > 0, 'invalid_fixture_id')
    require(isinstance(receipt.get('run'), str) and re.fullmatch(r'[a-f0-9]{32}', receipt['run']), 'invalid_fixture_run')
    started = utc(receipt.get('started_at'))
    require(now - timedelta(hours=1) <= started <= now, 'fixture_receipt_expired')
    return receipt


def login(session, user, password):
    session.request('/wp-login.php')
    session.request('/wp-login.php', {'log': user, 'pwd': password, 'testcookie': '1', 'wp-submit': 'Log In'})
    code, nonce = session.request('/wp-admin/admin-ajax.php?action=rest-nonce')
    require(code == 200 and isinstance(nonce, str) and re.fullmatch(r'[a-f0-9]{10}', nonce.strip()), 'login_failed')
    session.nonce = nonce.strip()
    code, me = session.request('/wp-json/wp/v2/users/me?context=edit', api=True)
    require(code == 200 and isinstance(me, dict) and me.get('username') == user and type(me.get('id')) is int, 'login_identity_failed')
    return me['id']


def verify_record(admin, receipt, now):
    code, order = admin.request('/wp-json/wc/v3/orders/%d' % receipt['order_id'], api=True)
    require(code == 200 and isinstance(order, dict) and order.get('id') == receipt['order_id'], 'fixture_not_found')
    meta = order.get('meta_data', [])
    require(isinstance(meta, list), 'fixture_provenance_missing')
    runs = [v.get('value') for v in meta if isinstance(v, dict) and v.get('key') == '_fp_verification_run']
    require(runs == [receipt['run']], 'fixture_provenance_mismatch')
    require(order.get('billing', {}).get('email') == 'ventas-' + receipt['run'] + '@example.invalid', 'fixture_email_mismatch')
    require(order.get('billing', {}).get('first_name') == 'NO ATENDER ' + receipt['run'], 'fixture_marker_mismatch')
    created = utc(order.get('date_created_gmt'))
    require(utc(receipt['started_at']) <= created <= now and now - created <= timedelta(hours=1), 'fixture_not_new')
    require(order.get('status') == 'pending' and not order.get('date_paid') and order.get('line_items'), 'fixture_not_pending')
    require(any(v.get('key') == '_qwc_quote' and str(v.get('value')) == '1' for v in meta if isinstance(v, dict)), 'fixture_not_quote')
    return order


def probe_sales(sales, receipt):
    """Called only after native provenance verification. No arbitrary ID fallback."""
    order_id = receipt['order_id']
    editor_path = '/wp-admin/post.php?post=%d&action=edit' % order_id
    code, editor = sales.request(editor_path)
    require(code == 200 and isinstance(editor, str) and re.search(r'FP-\d{4}-%06d\b' % order_id, editor)
            and ('NO ATENDER ' + receipt['run']) in editor and 'Datos originales recibidos' in editor,
            'restricted_fixture_read_failed')
    nonce = re.search(r'"add_order_note_nonce":"([a-f0-9]+)"', editor)
    require(nonce is not None, 'note_nonce_unavailable')
    marker = 'NO ATENDER prueba privada ' + receipt['run']
    code, body = sales.request('/wp-admin/admin-ajax.php', {'action': 'woocommerce_add_order_note',
        'security': nonce.group(1), 'post_id': order_id, 'note': marker, 'note_type': 'customer'})
    require(code == 200 and marker in body and 'customer-note' not in body and 'exact-date' in body, 'private_note_failed')
    code, editor = sales.request(editor_path)
    require(code == 200 and marker in editor, 'note_not_persisted')
    # Use delivered native editor nonces. Missing nonce is UNAVAILABLE, not a
    # successful authorization check. Full route/positive-control matrix lives
    # in the disposable woo-ventas-guard.py, not on this shared staging site.
    fields = dict(re.findall(r'name="([^"]+)"[^>]*value="([^"]*)"', editor))
    require(fields.get('_wpnonce') and fields.get('woocommerce_meta_nonce'), 'editor_nonce_unavailable')
    fields.update(action='editpost', post_ID=str(order_id), order_status='wc-processing',
                  _billing_first_name='VENTAS CAMBIO', save='Update')
    code, body = sales.request('/wp-admin/post.php', fields)
    require(code == 403 and 'solo consulta' in body, 'editor_permission_denial_failed')
    code, body = sales.request('/wp-json/wc/v3/orders/%d' % order_id,
                              {'status': 'processing'}, api=True, method='PUT')
    require(code in (401, 403), 'rest_permission_denial_failed')
    code, listing = sales.request('/wp-admin/edit.php?post_type=shop_order')
    require(code == 200 and ('post-%d' % order_id) in listing, 'list_read_failed')
    code, search = sales.request('/wp-admin/edit.php?post_type=shop_order&s=' + urllib.parse.quote(receipt['run']))
    require(code == 200 and ('post-%d' % order_id) in search, 'search_read_failed')
    # The role has no delete capability and native UI omits its trash nonce.
    # Do NOT issue a nonce-less trash request and mislabel CSRF as authorization.
    return {'private_note': True, 'editor_denied': True, 'rest_denied': True,
            'list_search_read': True, 'trash_authorization': 'not_run_nonce_not_offered'}


def run(receipt, admin_user, admin_pass, *, session_factory=Session, now=None):
    now = now or datetime.now(timezone.utc)
    admin, sales = session_factory(), session_factory()
    owner_id = login(admin, admin_user, admin_pass)
    username = 'fp-check-' + secrets.token_hex(12)
    email = username + '@example.invalid'
    query = '/wp-json/wp/v2/users?context=edit&search=' + urllib.parse.quote(username)
    code, existing = admin.request(query, api=True)
    require(code == 200 and isinstance(existing, list) and not existing, 'temporary_username_not_free')
    result, errors = {}, []
    attempted = False
    try:
        attempted = True
        password = secrets.token_urlsafe(32)
        code, user = admin.request('/wp-json/wp/v2/users', {'username': username, 'email': email,
            'password': password, 'roles': ['ventas_freeplast']}, api=True)
        require(code == 201 and isinstance(user, dict) and user.get('username') == username
                and user.get('email') == email and type(user.get('id')) is int, 'temporary_account_creation_failed')
        verified = verify_record(admin, receipt, now)
        require(login(sales, username, password) == user['id'], 'sales_login_failed')
        result = probe_sales(sales, receipt)
        after = verify_record(admin, receipt, now)
        # Notes are separate resources. All existing native record values must
        # survive the denied operations; only native modification time can move
        # when the permitted note was appended.
        for item in (verified, after):
            item.pop('date_modified', None); item.pop('date_modified_gmt', None)
        require(verified == after, 'record_changed')
    except VerificationError as error:
        errors.append(str(error))
    except Exception:
        errors.append('verification_failed_output_withheld')
    finally:
        if attempted:
            try:
                # Also resolves an ambiguous creation response. This exact,
                # unpredictable username+email did not exist at preflight.
                code, found = admin.request(query, api=True)
                require(code == 200 and isinstance(found, list), 'account_cleanup_lookup_failed')
                owned = [u for u in found if u.get('username') == username and u.get('email') == email]
                require(len(owned) <= 1 and all(type(u.get('id')) is int and u['id'] != owner_id for u in owned), 'account_cleanup_identity_failed')
                for account in owned:
                    code, removed = admin.request('/wp-json/wp/v2/users/%d?force=true&reassign=%d' % (account['id'], owner_id), method='DELETE', api=True)
                    require(code == 200 and isinstance(removed, dict) and removed.get('deleted') is True, 'account_cleanup_failed')
                code, found = admin.request(query, api=True)
                require(code == 200 and isinstance(found, list) and not any(u.get('username') == username for u in found), 'account_cleanup_incomplete')
                result['temporary_account_removed'] = True
            except Exception:
                errors.append('account_cleanup_failed')
                result['temporary_account_removed'] = False
    return {'checks': result, 'errors': errors}, (1 if errors else 0)


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--execute-staging', action='store_true')
    parser.add_argument('--mail-contained', action='store_true', help='operator acknowledges independent mail containment')
    parser.add_argument('--fixture', help='run receipt JSON from a newly provisioned operator fixture')
    args = parser.parse_args(argv)
    if not (args.execute_staging and args.mail_contained and args.fixture):
        parser.error('--execute-staging --mail-contained --fixture FILE are required; --order-id is not supported')
    try:
        now = datetime.now(timezone.utc)
        receipt = load_receipt(args.fixture, now)
        user, password = os.environ.get('FREEPLAST_SALES_ADMIN_USER'), os.environ.get('FREEPLAST_SALES_ADMIN_PASS')
        require(user and password, 'admin_credentials_missing')
        result, status = run(receipt, user, password, now=now)
    except VerificationError as error:
        result, status = {'errors': [str(error)]}, 1
    except Exception:
        result, status = {'errors': ['verification_failed_output_withheld']}, 1
    print(json.dumps(result))
    return status

if __name__ == '__main__': sys.exit(main())
