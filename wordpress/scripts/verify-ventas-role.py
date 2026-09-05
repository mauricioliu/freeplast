#!/usr/bin/env python3
"""Repeatable restricted-session check for the Ventas Freeplast role (issue #25).

STAGING ONLY (--execute-staging). Provisions a temporary ventas_freeplast
account with a random password (never printed), runs a REAL restricted
session through the allowed surfaces (orders list, search, detail, private
note) and the direct-route denials (catalog, settings, users, plugins,
deletion, priced-quote actions, email resend), then removes the account.

Notes it adds persist on one existing TECHNICAL request (billing email on
example.*, or --order-id). Never point at production. No cookies, nonces,
passwords or personal data are printed.
"""
import secrets, sys, re, json, urllib.request, urllib.parse, urllib.error, http.cookiejar
BASE = 'https://freeplast.mliu.site'
if sys.argv[1:] in ([], ['--help']):
    print(__doc__)
    print('help: FREEPLAST_SALES_ADMIN_USER=... FREEPLAST_SALES_ADMIN_PASS=... python3 wordpress/scripts/verify-ventas-role.py --execute-staging [--order-id N]')
    print('warning: the temporary sales account is provisioned and removed by this script; verify staging mail containment first')
    raise SystemExit(0)
if sys.argv[1:] and sys.argv[1:] != ['--execute-staging'] and '--order-id' not in sys.argv[1:]:
    print('error: unknown arguments\nhelp: supported flags are --help, --execute-staging, --order-id N')
    raise SystemExit(2)
if '--execute-staging' not in sys.argv:
    print('error: staging execution must be explicit\nhelp: add --execute-staging')
    raise SystemExit(2)
ADMIN_USER = __import__('os').environ.get('FREEPLAST_SALES_ADMIN_USER')
ADMIN_PASS = __import__('os').environ.get('FREEPLAST_SALES_ADMIN_PASS')
if not ADMIN_USER or not ADMIN_PASS:
    print('error: set FREEPLAST_SALES_ADMIN_USER and FREEPLAST_SALES_ADMIN_PASS (staging admin, never printed)')
    raise SystemExit(2)
order_id = int(sys.argv[sys.argv.index('--order-id') + 1]) if '--order-id' in sys.argv else 0

results = {}
def ok(name, condition, detail=''):
    results[name] = bool(condition)
    if not condition:
        print('FAILED: %s %s' % (name, detail))
    return bool(condition)

class Session:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.nonce_header = None
    def request(self, path, data=None, retry_login=None):
        body = urllib.parse.urlencode(data).encode() if data is not None else None
        req = urllib.request.Request(BASE + path, data=body, headers={'Content-Type': 'application/x-www-form-urlencoded'} if body else {})
        try:
            response = self.opener.open(req, timeout=60)
        except urllib.error.HTTPError as e:
            response = e
        return response.getcode(), response.read().decode('utf-8', 'replace')

def login(session, user, password):
    session.request('/wp-login.php')  # sets the test cookie
    session.request('/wp-login.php', {'log': user, 'pwd': password, 'redirect_to': BASE + '/wp-admin/', 'wp-submit': 'Log In', 'testcookie': '1'})
    code2, body2 = session.request('/wp-admin/profile.php')
    return code2 == 200 and '<title>Log In' not in body2

admin = Session()
if not ok('admin_login', login(admin, ADMIN_USER, ADMIN_PASS)):
    print(json.dumps(results, indent=1)); raise SystemExit(1)

# 1. Provision the temporary ventas account (role ventas_freeplast, random password, no notification).
sales_login = 'fp-ventas-check-' + secrets.token_hex(4)
sales_pass = secrets.token_urlsafe(20)
code, body = admin.request('/wp-admin/user-new.php')
nonce = re.search(r'name="_wpnonce_create-user" value="([a-f0-9]+)"', body)
code, body = admin.request('/wp-admin/user-new.php', {
    'action': 'createuser', '_wpnonce_create-user': nonce.group(1) if nonce else '',
    'user_login': sales_login, 'email': sales_login + '@example.invalid',
    'first_name': 'PRUEBA', 'last_name': 'NO COMERCIAL', 'url': '',
    'role': 'ventas_freeplast', 'pass1': sales_pass, 'pass2': sales_pass, 'pw_weak': '1',
    'createuser': 'Add New User'})
_, body = admin.request('/wp-admin/users.php?s=' + urllib.parse.quote(sales_login))
user_id = re.search(r'user-edit\.php\?user_id=(\d+)', body)
if not ok('sales_account_provisioned', nonce and user_id, 'user-new.php failed'):
    print(json.dumps(results, indent=1)); raise SystemExit(1)
user_id = int(user_id.group(1))

sales = Session()
try:
    # 2. Real restricted session.
    ok('sales_login', login(sales, sales_login, sales_pass))

    # Allowed: visible navigation carries the native orders menu and none of the denied menus.
    _, body = sales.request('/wp-admin/index.php')
    ok('menu_shows_orders', 'edit.php?post_type=shop_order' in body)
    for denied_menu in ('edit.php?post_type=product', 'admin.php?page=wc-settings', 'plugins.php', 'users.php', 'theme-install.php'):
        ok('menu_hides_' + denied_menu.split('?')[-1].split('=')[-1], denied_menu not in body, denied_menu)

    # Allowed: list, search, and open a quote request (FP reference) natively.
    code, body = sales.request('/wp-admin/edit.php?post_type=shop_order')
    ok('orders_list_ok', code == 200 and 'shop_order' in body)
    code, body = sales.request('/wp-admin/edit.php?post_type=shop_order&s=PRUEBA')
    ok('orders_search_ok', code == 200 and 'FP-' in body, 'reference missing from list')
    if not order_id:
        row = re.search(r'post\.php\?post=(\d+)(?:&#038;|&amp;|&)action=edit', body)
        order_id = int(row.group(1)) if row else 0
    code, editor = sales.request('/wp-admin/post.php?post=%d&action=edit' % order_id if order_id else '/wp-admin/edit.php?post_type=shop_order')
    ok('order_detail_ok', order_id and code == 200 and 'Datos originales recibidos' in editor and 'FP-' in editor, 'order %s' % order_id)
    note_nonce = re.search(r'"add_order_note_nonce":"([a-f0-9]+)"', editor)
    ok('note_nonce_delivered', bool(note_nonce))
    ok('no_visible_delete_action', 'submitdelete' not in editor and 'send_order_details' not in editor, 'editor must not offer delete or email resends')

    # Allowed: add a note through the native flow — crafted as a CUSTOMER note;
    # the adapter must normalize it to private before Woo stores it.
    marker = 'PRUEBA TÉCNICA NOTA DE VENTAS %s' % secrets.token_hex(3)
    code, body = sales.request('/wp-admin/admin-ajax.php', {
        'action': 'woocommerce_add_order_note', 'security': note_nonce.group(1) if note_nonce else '',
        'post_id': order_id, 'note': marker, 'note_type': 'customer'})
    ok('note_added', code == 200 and marker in body, 'admin-ajax response')
    ok('note_forced_private', code == 200 and 'customer-note' not in body, 'crafted customer note must be stored private')
    ok('note_keeps_author_and_date', 'exact-date' in body)

    # Allowed: the note persists on reload.
    code, editor = sales.request('/wp-admin/post.php?post=%d&action=edit' % order_id)
    ok('note_persists', marker in editor)

    # Denied: direct routes (server-side, not hidden menus).
    def denied(path):
        code, body = sales.request(path)
        return code == 403 or 'Sorry, you are not allowed' in body or 'You need a higher level of permission' in body
    ok('catalog_denied', denied('/wp-admin/edit.php?post_type=product'))
    ok('settings_denied', denied('/wp-admin/admin.php?page=wc-settings'))
    ok('users_denied', denied('/wp-admin/users.php'))
    ok('plugins_denied', denied('/wp-admin/plugin-install.php'))

    # Denied: deleting the request — the trash route must not delete.
    sales.request('/wp-admin/post.php?action=trash&post=%d' % order_id)
    code, editor = sales.request('/wp-admin/post.php?post=%d&action=edit' % order_id)
    ok('delete_denied', code == 200, 'request %d must survive a delete attempt' % order_id)

    # Denied: the quotes extension's priced actions demand manage_woocommerce.
    code, body = sales.request('/wp-admin/admin-ajax.php', {'action': 'qwc_update_status', 'security_nonce': 'x', 'order_id': order_id, 'status': 'quote-complete'})
    ok('priced_quote_denied', 'Invalid security' in body and 'quote-complete' not in body)

    # Denied: email resend by crafted order-save POST — the adapter's guard answers 403.
    nonces = re.findall(r'name="_wpnonce" value="([a-f0-9]+)"', editor) or ['']
    meta_nonce = re.search(r'name="woocommerce_meta_nonce" value="([a-f0-9]+)"', editor)
    resent = False
    for candidate in nonces:
        code, resend_body = sales.request('/wp-admin/post.php?post=%d' % order_id, {
            'action': 'editpost', 'post_ID': order_id, '_wpnonce': candidate,
            'woocommerce_meta_nonce': meta_nonce.group(1) if meta_nonce else '',
            'wc_order_action': 'send_order_details', 'content': 'PRUEBA TÉCNICA',
            'order_status': 'wc-pending', '_payment_method': 'quotes-gateway',
            '_payment_method_title': 'quotes-gateway', 'save': 'Update',
            'visibility': 'public', 'original_publish': 'Update'})
        if 'reenviar correos' in resend_body:
            resent = True
            break
    ok('email_resend_denied', resent, 'crafted resend must hit the adapter 403 guard')
finally:
    # 3. Cleanup: remove the temporary account (no credentials or accounts left behind).
    _, users_page = admin.request('/wp-admin/users.php')
    list_nonce = re.search(r'name="_wpnonce" value="([a-f0-9]+)"', users_page)
    _, confirm_page = admin.request('/wp-admin/users.php?action=delete&user=%d&_wpnonce=%s' % (user_id, list_nonce.group(1) if list_nonce else 'x'))
    confirm_nonce = re.search(r'name="_wpnonce" value="([a-f0-9]+)"', confirm_page)
    admin.request('/wp-admin/users.php', {'action': 'dodelete', 'users[]': user_id, '_wpnonce': confirm_nonce.group(1) if confirm_nonce else 'x', 'new_role': '', 'delete_option[%d]' % user_id: 'delete'})
    _, after = admin.request('/wp-admin/users.php?s=' + urllib.parse.quote(sales_login))
    ok('sales_account_removed', 'user-edit.php?user_id=%d' % user_id not in after)

print('sales_account: %s (removed after the run) technical_order: %d' % (sales_login, order_id))
print(json.dumps(results, indent=1))
if not all(results.values()):
    raise SystemExit(1)
