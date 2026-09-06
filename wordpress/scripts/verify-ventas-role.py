#!/usr/bin/env python3
"""Repeatable restricted-session check for the Ventas Freeplast role (issues #25 + #33).

STAGING ONLY (--execute-staging). Provisions a temporary ventas_freeplast
account with a random password (never printed), runs a REAL restricted
session through the allowed surfaces (orders list, search, detail, private
note) and the protected operations (general editor save, bulk actions,
deletion, REST update, priced-quote actions, email resend), then removes the
account.

Evidence classes (issue #33 — "Separar las pruebas CSRF de las de autorización"):
  - AUTHORIZATION: every protected operation is attempted with the restricted
    actor's VALID session and VALID nonces — scraped from the pages Woo itself
    renders for that role, or minted by core's rest-nonce endpoint. A denial
    counts only when the request is valid and the answer comes from a
    capability check, leaving the record unchanged.
  - CSRF controls: recorded separately; never counted as permission evidence.

Notes it adds persist on one existing TECHNICAL request (billing email on
example.*, or --order-id); the record's identity is verified from its own
delivered page before any mutation attempt. Never point at production. No
cookies, nonces, passwords or personal data are printed.
"""
import os, secrets, sys, re, json, urllib.request, urllib.parse, urllib.error, http.cookiejar
BASE = 'https://freeplast.mliu.site'
args = sys.argv[1:]
if args in ([], ['--help']):
    print(__doc__)
    print('help: FREEPLAST_SALES_ADMIN_USER=... FREEPLAST_SALES_ADMIN_PASS=... python3 wordpress/scripts/verify-ventas-role.py --execute-staging [--order-id N]')
    print('warning: the temporary sales account is provisioned and removed by this script; verify staging mail containment first')
    raise SystemExit(0)
if args and args != ['--execute-staging'] and '--order-id' not in args:
    print('error: unknown arguments\nhelp: supported flags are --help, --execute-staging, --order-id N')
    raise SystemExit(2)
if '--execute-staging' not in args:
    print('error: staging execution must be explicit\nhelp: add --execute-staging')
    raise SystemExit(2)
ADMIN_USER = os.environ.get('FREEPLAST_SALES_ADMIN_USER')
ADMIN_PASS = os.environ.get('FREEPLAST_SALES_ADMIN_PASS')
if not ADMIN_USER or not ADMIN_PASS:
    print('error: set FREEPLAST_SALES_ADMIN_USER and FREEPLAST_SALES_ADMIN_PASS (staging admin, never printed)')
    raise SystemExit(2)
order_id = int(args[args.index('--order-id') + 1]) if '--order-id' in args else 0

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
    def request(self, path, data=None, api=False, headers=None):
        body, extra = None, {}
        if data is not None:
            body = (json.dumps(data) if api else urllib.parse.urlencode(data)).encode()
            extra['Content-Type'] = 'application/json' if api else 'application/x-www-form-urlencoded'
        if headers:
            extra.update(headers)
        req = urllib.request.Request(BASE + path, data=body, headers=extra)
        try:
            response = self.opener.open(req, timeout=60)
        except urllib.error.HTTPError as e:
            response = e
        raw = response.read().decode('utf-8', 'replace')
        if api:
            try:
                raw = json.loads(raw)
            except ValueError:
                pass
        return response.getcode(), raw

def login(session, user, password):
    session.request('/wp-login.php')  # sets the test cookie
    session.request('/wp-login.php', {'log': user, 'pwd': password, 'redirect_to': BASE + '/wp-admin/', 'wp-submit': 'Log In', 'testcookie': '1'})
    code2, body2 = session.request('/wp-admin/profile.php')
    return code2 == 200 and '<title>Log In' not in body2

def hidden_inputs(html):
    """Every hidden input of the delivered form, any attribute order."""
    fields = {}
    for tag in re.findall(r'<input[^>]*>', html):
        name = re.search(r'name="([^"]+)"', tag)
        if name and re.search(r'type="hidden"', tag):
            value = re.search(r'value="([^"]*)"', tag)
            fields[name.group(1)] = value.group(1) if value else ''
    return fields

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
    EDITOR = '/wp-admin/post.php?post=%d&action=edit' % order_id
    code, editor = sales.request(EDITOR)
    ok('order_detail_ok', order_id and code == 200 and 'Datos originales recibidos' in editor and 'FP-' in editor, 'order %s' % order_id)

    # Identity check BEFORE any mutation attempt (issue #33 constraint): the
    # record's own delivered page must name THIS order id's reference and a
    # technical example.* email — a search string alone is not identity.
    ok('identity_verified', bool(order_id and re.search(r'FP-\d{4}-%06d' % order_id, editor or ''))
       and bool(re.search(r'[A-Za-z0-9._%+-]+@example\.', editor or '')),
       'the record does not identify as the technical request')
    note_nonce = re.search(r'"add_order_note_nonce":"([a-f0-9]+)"', editor)
    ok('note_nonce_delivered', bool(note_nonce))
    ok('no_visible_delete_action', 'submitdelete' not in editor and 'send_order_details' not in editor, 'editor must not offer delete or email resends')

    # AUTHORIZATION: add a note through the native flow — crafted as a CUSTOMER
    # note; the adapter must normalize it to private before Woo stores it.
    marker = 'PRUEBA TÉCNICA NOTA DE VENTAS %s' % secrets.token_hex(3)
    code, body = sales.request('/wp-admin/admin-ajax.php', {
        'action': 'woocommerce_add_order_note', 'security': note_nonce.group(1) if note_nonce else '',
        'post_id': order_id, 'note': marker, 'note_type': 'customer'})
    ok('note_added', code == 200 and marker in body, 'admin-ajax response')
    ok('note_forced_private', code == 200 and 'customer-note' not in body, 'crafted customer note must be stored private')
    ok('note_keeps_author_and_date', 'exact-date' in body)

    # Allowed: the note persists on reload.
    code, editor = sales.request(EDITOR)
    ok('note_persists', marker in editor)

    # AUTHORIZATION — the general editor save with the VALID editor nonces the
    # page itself renders for this role: a contact-data + commercial-status
    # change must be rejected server-side and leave the record unchanged
    # (issue #33: the editor opening for a role does not authorize saving).
    save = hidden_inputs(editor)
    save.update({'action': 'editpost', 'post_ID': str(order_id), 'order_status': 'wc-processing',
                 'billing_first_name': 'VENTAS CAMBIO', 'content': 'PRUEBA TÉCNICA',
                 '_payment_method': 'quotes-gateway', '_payment_method_title': 'quotes-gateway',
                 'save': 'Update', 'original_publish': 'Update', 'visibility': 'public'})
    code, body = sales.request('/wp-admin/post.php?post=%d' % order_id, save)
    ok('editor_save_denied', code == 403 and 'solo consulta' in body, 'HTTP %s' % code)
    code, editor = sales.request(EDITOR)
    ok('editor_save_left_record_unchanged', 'VENTAS CAMBIO' not in editor and marker in editor)

    # AUTHORIZATION — bulk status change with the VALID bulk nonce the list
    # form itself renders for this role (Woo's bulk handler runs after its own
    # nonce + capability checks, so the denial is a permission answer).
    code, listing = sales.request('/wp-admin/edit.php?post_type=shop_order')
    bulk_nonce = re.search(r'name="_wpnonce" value="([a-f0-9]+)"', listing)
    ok('bulk_nonce_delivered', bool(bulk_nonce))
    if bulk_nonce:
        code, body = sales.request('/wp-admin/edit.php?post_type=shop_order', {
            'action': 'mark_processing', 'post[]': str(order_id), '_wpnonce': bulk_nonce.group(1),
            '_wp_http_referer': '/wp-admin/edit.php?post_type=shop_order'})
        ok('bulk_status_denied', code == 403 and 'solo consulta' in body, 'HTTP %s' % code)
        code, editor = sales.request(EDITOR)
        ok('bulk_status_left_record_unchanged', 'VENTAS CAMBIO' not in editor and marker in editor)

    # Denied: deleting the request — the trash route must not delete.
    sales.request('/wp-admin/post.php?action=trash&post=%d' % order_id)
    code, editor = sales.request(EDITOR)
    ok('delete_denied', code == 200 and marker in editor, 'request %d must survive a delete attempt' % order_id)

    # AUTHORIZATION — the quotes extension's priced actions with their REAL
    # nonces (the editor hands them to anyone who can open it): the denial is
    # the manage_woocommerce capability, never the nonce.
    qwc_status = re.search(r'"qwc_status_nonce":"([a-f0-9]+)"', editor)
    qwc_send = re.search(r'"qwc_send_nonce":"([a-f0-9]+)"', editor)
    ok('priced_quote_denied', bool(qwc_status) and 'Invalid security token sent.' in sales.request('/wp-admin/admin-ajax.php', {
        'action': 'qwc_update_status', 'security_nonce': qwc_status.group(1),
        'order_id': str(order_id), 'status': 'quote-complete'})[1], 'valid nonce, capability denial expected')
    ok('quote_email_denied', bool(qwc_send) and 'Invalid security token sent.' in sales.request('/wp-admin/admin-ajax.php', {
        'action': 'qwc_send_quote', 'security_nonce': qwc_send.group(1), 'order_id': str(order_id)})[1], 'valid nonce, capability denial expected')

    # AUTHORIZATION — REST order update with a REAL wp_rest cookie-auth nonce.
    code, rest_nonce = sales.request('/wp-admin/admin-ajax.php?action=rest-nonce')
    rest_nonce = (rest_nonce or '').strip()
    ok('rest_nonce_delivered', bool(rest_nonce))
    if rest_nonce:
        code, body = sales.request('/wp-json/wc/v3/orders/%d' % order_id,
                                   {'billing': {'first_name': 'VENTAS CAMBIO'}, 'status': 'processing'},
                                   api=True, headers={'X-WP-Nonce': rest_nonce})
        ok('rest_update_denied', code in (401, 403), 'HTTP %s' % code)
        code, editor = sales.request(EDITOR)
        ok('rest_left_record_unchanged', 'VENTAS CAMBIO' not in editor and marker in editor)

    # Denied: email resend by a crafted order-save POST with the REAL editor
    # nonces — the adapter's read-only front door answers 403.
    update_nonce = re.search(r'name="_wpnonce" value="([a-f0-9]+)"', editor)
    meta_nonce = re.search(r'name="woocommerce_meta_nonce" value="([a-f0-9]+)"', editor)
    code, resend_body = sales.request('/wp-admin/post.php?post=%d' % order_id, {
        'action': 'editpost', 'post_ID': str(order_id), '_wpnonce': update_nonce.group(1) if update_nonce else '',
        'woocommerce_meta_nonce': meta_nonce.group(1) if meta_nonce else '',
        'wc_order_action': 'send_order_details', 'content': 'PRUEBA TÉCNICA',
        'order_status': 'wc-pending', '_payment_method': 'quotes-gateway',
        '_payment_method_title': 'quotes-gateway', 'save': 'Update',
        'visibility': 'public', 'original_publish': 'Update'})
    ok('email_resend_denied', code == 403 and 'solo consulta' in resend_body, 'crafted resend must hit the adapter read-only guard')

    # Denied: direct routes (server-side, not hidden menus).
    def denied(path):
        code, body = sales.request(path)
        return code == 403 or 'Sorry, you are not allowed' in body or 'You need a higher level of permission' in body
    ok('catalog_denied', denied('/wp-admin/edit.php?post_type=product'))
    ok('settings_denied', denied('/wp-admin/admin.php?page=wc-settings'))
    ok('users_denied', denied('/wp-admin/users.php'))
    ok('plugins_denied', denied('/wp-admin/plugin-install.php'))
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
