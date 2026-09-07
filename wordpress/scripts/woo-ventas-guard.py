#!/usr/bin/env python3
"""Real-stack regression for the Ventas Freeplast record boundary (issue #33).

Runs against the DISPOSABLE local WordPress + SQLite + WooCommerce stack
(woo-stack-harness.mjs): loopback only, no staging, no external host, mail is
blocked by the harness mu-plugin, and the only record ever touched is the
synthetic request THIS script creates through real checkout HTTP (billing email
on example.invalid, marked NO ATENDER, identity verified from its own provenance
before any mutation). Historical/client records are never used.

guarded    — a REAL restricted ventas_freeplast session (credentials supplied by
             the harness) walks the kept surfaces (login, list, search, editor,
             private note through the native flow) and then attempts every
             protected operation with a VALID session and a VALID nonce — the
             nonce string the native UI itself would carry for that actor,
             minted for the current user by a disposable test mu-plugin (the UI
             rightly no longer offers denied actions, so the mint replaces the
             scraped string without weakening the request). Each denial must be
             a server 403 that leaves the record unchanged. One CSRF control
             documents that an INVALID nonce is NOT counted as authorization
             evidence.
guard-off  — with the adapter's guards lifted by a disposable mu-plugin, the
             same probes MUST mutate the record: the regression the guards
             prevent, and the proof that this suite fails if a guard is
             withdrawn or the boundary is widened.
recheck    — guards restored: the quick-status probe is denied again.
"""
import http.cookiejar
import json
import os
import re
import secrets
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser

if __name__ != '__main__':
    raise SystemExit('run as a script')
args = sys.argv[1:]
if '--base' not in args or '--mode' not in args:
    print(__doc__)
    print('help: woo-stack-harness.mjs drives this script; manual use: '
          "python3 wordpress/scripts/woo-ventas-guard.py --base http://127.0.0.1:8091 --mode guarded")
    raise SystemExit(0)
BASE = args[args.index('--base') + 1].rstrip('/')
MODE = args[args.index('--mode') + 1]
if MODE not in ('guarded', 'guard-off', 'recheck'):
    print('error: unknown mode %s' % MODE)
    raise SystemExit(2)
VENTAS_USER = os.environ.get('FREEPLAST_VENTAS_USER', '')
VENTAS_PASS = os.environ.get('FREEPLAST_VENTAS_PASS', '')
if not VENTAS_USER or not VENTAS_PASS:
    print('error: set FREEPLAST_VENTAS_USER and FREEPLAST_VENTAS_PASS (harness-provisioned ventas account)')
    raise SystemExit(2)
# Per-run provenance (#37): the harness mints a fresh run token for every
# execution and passes it to ALL modes; the guarded run binds the newly created
# fixture to it (billing email), and the mutating modes verify that binding
# before touching anything. Missing or malformed provenance is rejected.
RUN = os.environ.get('FREEPLAST_VENTAS_RUN', '')
if not re.fullmatch(r'[a-f0-9]{12,40}', RUN):
    print('error: harness-generated FREEPLAST_VENTAS_RUN is required')
    raise SystemExit(2)
RUN_EMAIL = 'ventas-%s@example.invalid' % RUN
# Coupon codes normalize to lower case in Woo; keep one canonical constant.
COUPON = os.environ.get('FREEPLAST_VENTAS_COUPON', 'ventaslocal10')

failures = []
results = {}
def ok(name, condition, detail=''):
    results[name] = bool(condition)
    if not condition:
        failures.append(name)
        print('FAILED: %s (response details withheld)' % name)
    return bool(condition)


def abort_if_broken(order, name, condition, detail=''):
    if not ok(name, condition, detail) or failures:
        print(json.dumps({'order': order, 'checks': results, 'failures': failures}))
        raise SystemExit(1)


def native_state(order):
    """Complete native data digest via a disposable-only read-only CLI helper.
    No shell, no endpoint, and no record values/confirmation keys in output.
    """
    command = json.loads(os.environ.get('FREEPLAST_VENTAS_STATE_COMMAND', '[]'))
    if not isinstance(command, list) or not command or not all(isinstance(x, str) for x in command):
        raise RuntimeError('Missing disposable fixture snapshot command')
    result = subprocess.run(command + [str(order), RUN], capture_output=True, text=True, timeout=30)
    if result.returncode:
        raise RuntimeError('Fixture snapshot failed (output withheld)')
    state = json.loads(result.stdout)
    if not re.fullmatch(r'[a-f0-9]{64}', state.get('digest', '')) or not state.get('item_ids'):
        raise RuntimeError('Incomplete fixture snapshot')
    return state


class Session:
    """Cookie jar + Store API nonce handling, mirroring woo-checkout-race.py."""
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.nonce = ''
    def request(self, path, data=None, api=False, headers=None, timeout=120):
        body, extra = None, {}
        if data is not None:
            body = (json.dumps(data) if api else urllib.parse.urlencode(data)).encode()
            extra['Content-Type'] = 'application/json' if api else 'application/x-www-form-urlencoded'
        if api and self.nonce:
            extra['Nonce'] = self.nonce
        if headers:
            extra.update(headers)
        req = urllib.request.Request(BASE + path, data=body, headers=extra)
        response = None
        for attempt in (0, 1):  # the dev server occasionally drops a connection
            try:
                response = self.opener.open(req, timeout=timeout)
                break
            except urllib.error.HTTPError as e:
                response = e
                break
            except (urllib.error.URLError, OSError):
                if attempt:
                    raise
        if response.headers.get('Nonce'):
            self.nonce = response.headers['Nonce']
        raw = response.read().decode('utf-8', 'replace')
        if api:
            try:
                raw = json.loads(raw)
            except ValueError:
                pass
        return response.getcode(), raw

class HiddenInputs(HTMLParser):
    def __init__(self):
        super().__init__()
        self.values = {}
    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == 'input' and a.get('type') == 'hidden' and a.get('name'):
            self.values[a['name']] = a.get('value', '')


def wp_login(session, user, password):
    session.request('/wp-login.php')  # sets the test cookie
    session.request('/wp-login.php', {'log': user, 'pwd': password,
                                      'redirect_to': BASE + '/wp-admin/edit.php?post_type=shop_order',
                                      'wp-submit': 'Log In', 'testcookie': '1'})
    code, body = session.request('/wp-admin/profile.php')
    return code == 200 and 'id="your-profile"' in body and 'name="log"' not in body


def mint(session, action):
    """The current user's nonce for an admin action — the string the native UI
    would carry for this actor (a CSRF-valid request, nothing more)."""
    code, body = session.request('/wp-admin/admin-ajax.php?action=fpw_test_nonce&for=' + urllib.parse.quote(action))
    if isinstance(body, str):
        try:
            body = json.loads(body)
        except ValueError:
            body = None
    nonce = ''
    if isinstance(body, dict):
        nonce = str((body.get('data') or {}).get('nonce', ''))
    if not nonce:
        raise RuntimeError('nonce mint failed for %s (HTTP %s)' % (action, code))
    return nonce


def order_row_status(list_html, order_id):
    """The delivered <tr> (tag included, so its post-<id> anchor is checkable)
    of one order's row on the native list."""
    row = re.search(r'<tr[^>]*\sid=["\']post-%d["\'][^>]*>(.*?)</tr>' % order_id, list_html, re.S)
    return row.group(0) if row else ''


def ensure_fixture_identity(session, order, email, expect_qty='5'):
    """Identity gate for EVERY mutating phase: the record must be THIS run's
    fixture — FP-reference built from this id, this run's email binding, and
    the expected quantity line — verified BEFORE any mutation request. On
    mismatch the run prints its failures and EXITS; there is no fallback to an
    arbitrary record."""
    code, editor = session.request('/wp-admin/post.php?post=%d&action=edit' % order)
    good = (code == 200
            and re.search(r'FP-\d{4}-%06d' % order, editor) is not None
            and email in editor
            and re.search(r'order_item_qty\[\d+\][^>]*value="%s"' % expect_qty, editor) is not None)
    if not ok('identity_verified_before_mutating', good, 'order %d does not carry this run\'s binding' % order):
        print(json.dumps({'order': order, 'run': RUN, 'checks': results, 'failures': failures}, ensure_ascii=False))
        raise SystemExit(1)
    native_state(order)  # independent exact stored run binding check
    return editor


ORDER = int(os.environ.get('FREEPLAST_VENTAS_ORDER', '0'))
MARKER = 'PRUEBA LOCAL VENTAS'
NOTE_TEXT = 'PRUEBA TÉCNICA nota de ventas %s — NO ATENDER' % secrets.token_hex(3)
READ_ONLY = 'solo consulta'

ventas = Session()

if MODE == 'guarded':
    # ---------------------------------------------------------- synthetic request
    # Created by a fresh customer session through real checkout HTTP; the ONLY
    # record the suite mutates, and only after its identity is verified.
    cust = Session()
    cust.request('/wp-json/wc/store/v1/cart', api=True)  # seeds the Store API nonce
    code, products = cust.request('/wp-json/wc/store/v1/products?slug=caja-universal-prueba', api=True)
    ok('product_exists', code == 200 and isinstance(products, list) and products, 'HTTP %s' % code)
    pid = products[0]['id']
    code, _ = cust.request('/wp-json/wc/store/v1/cart/add-item', {'id': pid, 'quantity': 5}, api=True)
    ok('cart_add', code in (200, 201), 'HTTP %s' % code)
    code, html = cust.request('/checkout/')
    form = HiddenInputs()
    form.feed(html)
    values = dict(form.values)
    ok('checkout_form_present', 'woocommerce-process-checkout-nonce' in values)
    values.update({
        'billing_first_name': MARKER, 'billing_phone': '+56 9 5555 5555',
        'billing_email': RUN_EMAIL, 'billing_company': 'PRUEBA NO COMERCIAL',
        'billing_fp_rut': '76.555.555-5', 'billing_fp_giro': 'Prueba del rol Ventas',
        'billing_fp_dispatch': 'no', 'billing_fp_address': '',
        'payment_method': 'quotes-gateway', 'order_comments': 'Prueba técnica automatizada del rol Ventas (no atender)'})
    code, body = cust.request('/?wc-ajax=checkout', values)
    try:
        body = json.loads(body)
    except (TypeError, ValueError):
        body = {}
    match = re.search(r'/order-received/(\d+)', str(body.get('redirect', '')))
    ok('order_created', body.get('result') == 'success' and match, str(body)[:200])
    ORDER = int(match.group(1)) if match else 0
    if not ORDER:
        print(json.dumps({'order': 0, 'failures': failures}, ensure_ascii=False))
        raise SystemExit(1)

    # --------------------------------------------------------- restricted session
    abort_if_broken(ORDER, 'ventas_login', wp_login(ventas, VENTAS_USER, VENTAS_PASS))

    code, dash = ventas.request('/wp-admin/index.php')
    ok('menu_shows_orders', 'edit.php?post_type=shop_order' in dash)
    for denied_menu in ('edit.php?post_type=product', 'admin.php?page=wc-settings', 'plugins.php', 'users.php'):
        ok('menu_hides_' + denied_menu.split('?')[-1].split('=')[-1], denied_menu not in dash, denied_menu)

    code, listing = ventas.request('/wp-admin/edit.php?post_type=shop_order')
    ok('orders_list_ok', code == 200 and 'shop_order' in listing)
    code, found = ventas.request('/wp-admin/edit.php?post_type=shop_order&s=%s' % urllib.parse.quote(MARKER))
    ok('orders_search_ok', code == 200 and 'FP-' in found and ('post-%d' % ORDER) in found)

    EDITOR = '/wp-admin/post.php?post=%d&action=edit' % ORDER
    # Identity gate BEFORE the note creation and every protected operation:
    # this run's fixture only (see ensure_fixture_identity) — its failure
    # EXITS the run before any write request; login failures likewise.
    editor = ensure_fixture_identity(ventas, ORDER, RUN_EMAIL)
    ok('detail_reads_items', 'Caja Universal' in editor and 'value="5"' in editor)
    ok('detail_reads_dispatch_meta', 'RUT Empresa' in editor and '76.555.555-5' in editor)

    abort_if_broken(ORDER, 'fixture_reads_complete', True)
    # Allowed: a private sales note through the native flow, with a VALID nonce.
    note_nonce = mint(ventas, 'add-order-note')
    code, note_html = ventas.request('/wp-admin/admin-ajax.php', {
        'action': 'woocommerce_add_order_note', 'security': note_nonce,
        'post_id': ORDER, 'note': NOTE_TEXT, 'note_type': ''})
    ok('note_added', code == 200 and NOTE_TEXT in note_html, 'HTTP %s' % code)
    ok('note_private', 'customer-note' not in note_html, 'the note must not be customer-facing')
    ok('note_keeps_author_and_date', 'exact-date' in note_html and ('by %s' % VENTAS_USER) in note_html)
    note_id_m = re.search(r'<li rel="(\d+)"', note_html)
    note_id = int(note_id_m.group(1)) if note_id_m else 0
    code, editor = ventas.request(EDITOR)
    ok('note_persists', NOTE_TEXT in editor)
    abort_if_broken(ORDER, 'note_id_known_before_mutation', note_id > 0, 'the note prerequisite failed; refusing to continue')

    def record_snapshot():
        """Hash complete native order/item/meta/note data, independent of HTML.
        Only core editor lock metadata is excluded. Values are never printed.
        """
        return native_state(ORDER)

    BASELINE = record_snapshot()

    def unchanged(context='after attempt'):
        now = record_snapshot()
        changed = [key for key in BASELINE if BASELINE[key] != now[key]]
        ok('record_unchanged_%s' % context, not changed, 'changed keys: %s' % (changed or 'none'))

    # Denied: the general editor save (contact data + status), VALID session and
    # VALID editor nonces — the adapter must answer 403 before anything is written.
    code, editor = ventas.request(EDITOR)
    fields = HiddenInputs()
    fields.feed(editor)
    save = dict(fields.values)
    save.update({'action': 'editpost', 'post_ID': str(ORDER), 'order_status': 'wc-processing',
                 '_billing_first_name': 'VENTAS CAMBIO', 'content': 'VENTAS CAMBIO',
                 '_payment_method': 'quotes-gateway', '_payment_method_title': 'quotes-gateway',
                 'save': 'Update', 'original_publish': 'Update', 'visibility': 'public'})
    code, body = ventas.request('/wp-admin/post.php?post=%d' % ORDER, save)
    ok('editor_save_denied', code == 403 and READ_ONLY in body, 'HTTP %s' % code)
    unchanged('editor_save')

    # Denied: quick status change with a VALID quick-status nonce.
    code, body = ventas.request('/wp-admin/admin-ajax.php?action=woocommerce_mark_order_status'
                                '&status=processing&order_id=%d&_wpnonce=%s'
                                % (ORDER, mint(ventas, 'woocommerce-mark-order-status')))
    ok('quick_status_denied', code == 403 and READ_ONLY in body, 'HTTP %s' % code)
    unchanged('quick_status')

    # Denied: bulk status change and bulk trash with a VALID bulk nonce.
    bulk_nonce = mint(ventas, 'bulk-posts')
    code, body = ventas.request('/wp-admin/edit.php?post_type=shop_order', {
        'action': 'mark_processing', 'post[]': str(ORDER), '_wpnonce': bulk_nonce,
        '_wp_http_referer': '/wp-admin/edit.php?post_type=shop_order'})
    ok('bulk_status_denied', code == 403 and READ_ONLY in body, 'HTTP %s' % code)
    unchanged('bulk_status')
    code, body = ventas.request('/wp-admin/edit.php?post_type=shop_order', {
        'action': 'trash', 'post[]': str(ORDER), '_wpnonce': bulk_nonce,
        '_wp_http_referer': '/wp-admin/edit.php?post_type=shop_order'})
    ok('bulk_trash_denied', 'not allowed' in body or 'Sorry' in body, 'WP must answer the delete-cap denial')
    unchanged('bulk_trash')

    # Denied: the single trash route with a VALID trash nonce (WordPress' own
    # delete-cap authorization wall — the record must survive it).
    code, body = ventas.request('/wp-admin/post.php?action=trash&post=%d&_wpnonce=%s'
                                % (ORDER, mint(ventas, 'trash-post_%d' % ORDER)))
    ok('trash_route_denied', 'not allowed' in body, 'HTTP %s' % code)
    unchanged('trash_route')

    # Denied: deleting the sales note (the history stays intact) with a VALID nonce.
    ok('note_id_known', note_id > 0, 'note id not parsed')
    code, body = ventas.request('/wp-admin/admin-ajax.php', {
        'action': 'woocommerce_delete_order_note', 'security': mint(ventas, 'delete-order-note'),
        'note_id': str(note_id)})
    ok('note_delete_denied', code == 403 and READ_ONLY in body, 'HTTP %s' % code)
    unchanged('note_delete')

    # -------------------------------------------------- issue #37 additions ----
    # Denied: native TAX RECALCULATION with a VALID calc-totals nonce — the
    # pinned TaxesController saves the submitted items (here quantity 999)
    # before recalculating; the front door must die before any write.
    LINE_ID = next((int(m.group(1)) for m in re.finditer(r'order_item_qty\[(\d+)\][^>]*value="(\d+)"', editor)), 0)
    abort_if_broken(ORDER, 'line_id_resolved', LINE_ID in native_state(ORDER)['item_ids'])
    code, body = ventas.request('/wp-admin/admin-ajax.php', {
        'action': 'woocommerce_calc_line_taxes', 'security': mint(ventas, 'calc-totals'),
        'order_id': str(ORDER), 'country': 'CL', 'state': '', 'postcode': '', 'city': '',
        'items': 'order_item_id[]=%d&order_item_qty[%d]=999' % (LINE_ID, LINE_ID)})
    ok('tax_recalc_denied', code == 403 and READ_ONLY in body, 'HTTP %s %s' % (code, str(body)[:160]))
    code, editor = ventas.request(EDITOR)
    ok('tax_recalc_no_quantity_write', 'value="999"' not in editor, 'the submitted quantity 999 must never appear on the record')
    unchanged('tax_recalc')

    # Denied: the coupon-discount route with a VALID order-item nonce.
    code, body = ventas.request('/wp-admin/admin-ajax.php', {
        'action': 'woocommerce_add_coupon_discount', 'security': mint(ventas, 'order-item'),
        'order_id': str(ORDER), 'coupon': COUPON})
    ok('coupon_discount_denied', code == 403 and READ_ONLY in body, 'HTTP %s %s' % (code, str(body)[:160]))
    unchanged('coupon_discount')

    # Denied: CORE comment routes rewriting the Sales Note (append-only). WP
    # maps edit_comment onto edit_post of the ORDER, which this role carries —
    # the adapter's scoped guard must answer 403 before core writes.
    REWRITTEN = 'NOTA REESCRITA %s' % secrets.token_hex(3)
    code, body = ventas.request('/wp-admin/admin-ajax.php', {
        'action': 'edit-comment', '_ajax_nonce-replyto-comment': mint(ventas, 'replyto-comment'),
        'comment_ID': str(note_id), 'content': REWRITTEN})
    ok('core_note_edit_denied', code == 403 and READ_ONLY in body, 'HTTP %s %s' % (code, str(body)[:160]))
    code, editor = ventas.request(EDITOR)
    ok('core_note_content_intact', NOTE_TEXT in editor and REWRITTEN not in editor, 'the note keeps author, timestamp and original content')
    code, body = ventas.request('/wp-admin/admin-ajax.php', {
        'action': 'delete-comment', '_ajax_nonce': mint(ventas, 'delete-comment_%d' % note_id),
        'id': str(note_id), 'trash': '1'})
    ok('core_note_delete_denied', code == 403 and READ_ONLY in body, 'HTTP %s %s' % (code, str(body)[:160]))
    unchanged('core_note_delete')
    code, body = ventas.request('/wp-admin/comment.php?action=editcomment&c=%d&_wpnonce=%s'
                                % (note_id, mint(ventas, 'edit-comment_%d' % note_id)))
    ok('comment_php_read_view_allowed', code == 200 and NOTE_TEXT in body,
       'HTTP %s: the read-only editcomment VIEW stays allowed (only WRITE actions are denied)' % code)
    unchanged('comment_php_read')

    # #37 native ADVERSARIAL target-resolution controls (matching the offline
    # red-gates): benign decoy fields beside the canonical one, suffixed ids
    # that native absint() still resolves to the note, and comment.php's
    # dt=trash effective-action override.
    code, body = ventas.request('/wp-admin/admin-ajax.php', {
        'action': 'delete-comment', '_ajax_nonce': mint(ventas, 'delete-comment_%d' % note_id),
        'comment_ID': '999999', 'id': str(note_id), 'trash': '1'})
    ok('adversarial_delete_comment_decoy_comment_id', code == 403 and READ_ONLY in body,
       'HTTP %s: delete-comment resolves id only; the benign comment_ID decoy must not mask the note target' % code)
    code, body = ventas.request('/wp-admin/admin-ajax.php', {
        'action': 'edit-comment', '_ajax_nonce-replyto-comment': mint(ventas, 'replyto-comment'),
        'comment_ID': str(note_id), 'id': '999999', 'content': 'NOTA DECOY'})
    ok('adversarial_edit_comment_decoy_id', code == 403 and READ_ONLY in body,
       'HTTP %s: edit-comment resolves comment_ID only; the benign id decoy must not mask the note target' % code)
    code, body = ventas.request('/wp-admin/admin-ajax.php', {
        'action': 'edit-comment', '_ajax_nonce-replyto-comment': mint(ventas, 'replyto-comment'),
        'comment_ID': '%djunk' % note_id, 'content': 'NOTA SUFIJO'})
    ok('adversarial_suffixed_comment_id_denied', code == 403 and READ_ONLY in body,
       'HTTP %s: comment_ID=%djunk resolves like native absint() to the ORDER note' % (code, note_id))
    code, body = ventas.request('/wp-admin/comment.php?action=editedcomment&c=%d&dt=trash&_wpnonce=%s'
                                % (note_id, mint(ventas, 'delete-comment_%d' % note_id)))
    ok('adversarial_dt_override_denied', code == 403 and READ_ONLY in body,
       'HTTP %s: comment.php derives the EFFECTIVE action (dt=trash -> TRASH of c), never the posted alias' % code)
    code, body = ventas.request('/wp-admin/comment.php', {
        'action': 'editedcomment', 'comment_ID': str(note_id), 'comment_post_ID': str(ORDER),
        'content': 'NOTA EDITADA comment.php', '_wpnonce': mint(ventas, 'update-comment_%d' % note_id)})
    ok('adversarial_comment_php_editedcomment', code == 403 and READ_ONLY in body,
       'HTTP %s: comment.php POST editedcomment resolves comment_ID (not c)' % code)
    code, body = ventas.request('/wp-admin/edit-comments.php', {
        'action2': 'trash', 'delete_comments[]': str(note_id), '_wpnonce': mint(ventas, 'bulk-comments')})
    # The ventas role carries no edit_posts primitive, so WordPress core denies
    # the page itself at the menu stage (wp-admin/includes/menu.php) — BEFORE
    # do_action('admin_init'), where the adapter's bulk guard sits. Either 403
    # body is an honest server denial of the same POST: core's capability gate
    # or the adapter's Spanish read-only die (readable only by an actor that
    # clears the menu gate). The adapter's own action OR action2 (+ the -1
    # no-op fallback) target resolution is proven offline in test-woo-adapter.php.
    ok('adversarial_bulk_action2', code == 403 and (READ_ONLY in body or 'not allowed to access this page' in body),
       'HTTP %s: the bottom action2 bulk select resolves like action' % code)
    code, editor = ventas.request(EDITOR)
    ok('adversarial_note_history_intact', NOTE_TEXT in editor
       and all(junk not in editor for junk in ('NOTA DECOY', 'NOTA SUFIJO', 'NOTA OVERRIDE', 'NOTA EDITADA comment.php')))
    unchanged('adversarial')

    # Denied: core custom metadata on the order post (add-meta) with a VALID nonce.
    code, body = ventas.request('/wp-admin/admin-ajax.php', {
        'action': 'add-meta', '_ajax_nonce-add-meta': mint(ventas, 'add-meta'),
        'post_id': str(ORDER), 'metakeyinput': 'fp_test_meta', 'metavalue': 'VENTAS'})
    ok('core_add_meta_denied', code == 403 and READ_ONLY in body, 'HTTP %s %s' % (code, str(body)[:160]))
    unchanged('core_add_meta')

    # Denied: REST order update / batch with a VALID wp_rest nonce.
    code, rest_nonce = ventas.request('/wp-admin/admin-ajax.php?action=rest-nonce')
    rest_nonce = (rest_nonce or '').strip()
    ok('rest_nonce_delivered', bool(rest_nonce))
    code, body = ventas.request('/wp-json/wc/v3/orders/%d' % ORDER,
                                {'billing': {'first_name': 'VENTAS REST CAMBIO'}, 'status': 'processing'},
                                api=True, headers={'X-WP-Nonce': rest_nonce})
    ok('rest_update_denied', code in (401, 403), 'HTTP %s %s' % (code, str(body)[:160]))
    code, body = ventas.request('/wp-json/wc/v3/orders/batch',
                                {'update': [{'id': ORDER, 'status': 'completed'}]},
                                api=True, headers={'X-WP-Nonce': rest_nonce})
    ok('rest_batch_denied', code in (401, 403), 'HTTP %s %s' % (code, str(body)[:160]))
    unchanged('rest')

    # Denied: the quotes extension's priced actions with their REAL nonces — the
    # extension itself hands them to anyone who can open the editor, so a denial
    # here is the manage_woocommerce authorization, never the nonce.
    code, editor = ventas.request(EDITOR)
    qwc_status = re.search(r'"qwc_status_nonce":"([a-f0-9]+)"', editor)
    qwc_send = re.search(r'"qwc_send_nonce":"([a-f0-9]+)"', editor)
    ok('qwc_nonces_delivered', bool(qwc_status and qwc_send), 'the editor must ship the priced-action nonces to the role it opens for')
    if qwc_status:
        code, body = ventas.request('/wp-admin/admin-ajax.php', {
            'action': 'qwc_update_status', 'security_nonce': qwc_status.group(1),
            'order_id': str(ORDER), 'status': 'quote-complete'})
        ok('priced_quote_denied', 'Invalid security token' in str(body) and 'quote-complete' not in str(body), str(body)[:160])
    if qwc_send:
        code, body = ventas.request('/wp-admin/admin-ajax.php', {
            'action': 'qwc_send_quote', 'security_nonce': qwc_send.group(1), 'order_id': str(ORDER)})
        ok('quote_email_denied', 'Invalid security token' in str(body), str(body)[:160])
    unchanged('priced_quote')

    # Denied: email resend crafted as an order save with the REAL editor nonces.
    code, body = ventas.request('/wp-admin/post.php?post=%d' % ORDER, dict(save, wc_order_action='send_order_details'))
    ok('email_resend_denied', code == 403 and READ_ONLY in body, 'HTTP %s' % code)
    ok('resend_not_offered', 'send_order_details' not in editor, 'the order-actions select must not offer resends')

    # CSRF control (NOT authorization evidence by itself): an INVALID nonce gets
    # exactly the same 403 as the valid one above — the guard denies the ACTOR,
    # so its denial cannot be the nonce check. The protected-operation evidence
    # is the valid-nonce pair above; this only proves the response does not
    # depend on nonce validity.
    code, body = ventas.request('/wp-admin/admin-ajax.php?action=woocommerce_mark_order_status'
                                '&status=processing&order_id=%d&_wpnonce=invalid-on-purpose' % ORDER)
    ok('csrf_control_invalid_nonce_same_denial', code == 403 and READ_ONLY in body, 'HTTP %s' % code)
    results['denials_attributable_to_permissions_not_csrf'] = True

    # The restricted interface itself: the denied controls are not offered.
    code, editor = ventas.request(EDITOR)
    ok('read_only_notice_shown', READ_ONLY in editor and 'notas de ventas privadas' in editor)
    ok('no_delete_button', 'submitdelete' not in editor)
    ok('denied_controls_hidden_css', 'button.save_order' in editor and '#qwc_send_quote' in editor and '.delete_note' in editor)
    # #37: the items editor's denied mutation controls are hidden too, while the
    # lines, options, values and quantities stay fully readable.
    ok('items_editor_denied_controls_hidden', 'button.calculate-action' in editor and 'button.add-line-item' in editor and 'a.edit-order-item' in editor,
       'the style must hide Recalculate/Add-item(s)/per-line edit-delete for ventas')
    ok('items_editor_still_readable', 'value="5"' in editor and 'Caja Universal' in editor,
       'the line values and quantities must stay readable behind the hidden controls')
    code, body = ventas.request('/wp-admin/users.php')
    ok('users_denied', code == 403 or 'Sorry, you are not allowed' in body or 'not allowed' in body)

    unchanged('final')
    print(json.dumps({'order': ORDER, 'note_id': note_id, 'checks': results, 'failures': failures},
                     ensure_ascii=False))
    raise SystemExit(1 if failures else 0)

if MODE == 'guard-off':
    if not ok('ventas_login', wp_login(ventas, VENTAS_USER, VENTAS_PASS)):
        print(json.dumps({'order': ORDER, 'run': RUN, 'checks': results, 'failures': failures}, ensure_ascii=False))
        raise SystemExit(1)
    # Identity gating FIRST, bound to THIS run's provenance (the harness-minted
    # run token the guarded run bound the fixture to): reference built from
    # this id, this run's email, the expected quantity line. On mismatch the
    # run fails and stops BEFORE any write — no arbitrary-record fallback.
    EDITOR = '/wp-admin/post.php?post=%d&action=edit' % ORDER
    editor = ensure_fixture_identity(ventas, ORDER, RUN_EMAIL)
    LINE_ID = next((int(m.group(1)) for m in re.finditer(r'order_item_qty\[(\d+)\][^>]*value="5"', editor)), 0)
    abort_if_broken(ORDER, 'guard_off_line_id_resolved', LINE_ID > 0, 'no fixture line resolved; refusing to mutate')
    def off_snapshot():
        return native_state(ORDER)
    BEFORE = off_snapshot()
    # Positive control 1 (#37): with the guard removed, the minted calc-totals
    # nonce MUTATES the record through the REAL TaxesController — using the
    # fixture's real line id and the complete native field set.
    code, body = ventas.request('/wp-admin/admin-ajax.php', {
        'action': 'woocommerce_calc_line_taxes', 'security': mint(ventas, 'calc-totals'),
        'order_id': str(ORDER), 'country': 'CL', 'state': '', 'postcode': '', 'city': '',
        'items': 'order_item_id[]=%d&order_item_qty[%d]=999' % (LINE_ID, LINE_ID)})
    ok('tax_recalc_allowed_when_guard_removed', code == 200, 'HTTP %s %s' % (code, str(body)[:160]))
    code, editor = ventas.request(EDITOR)
    ok('tax_recalc_actually_mutated', 'value="999"' in editor, 'the probe must detect the real quantity write on line %d' % LINE_ID)
    # Positive control 1b (#37): with the guard removed, CORE edit-comment
    # REWRITES an existing Sales Note (map_meta_cap edit_comment -> edit_post of
    # the order, which the role carries) — the append-only boundary is detectable.
    note_nonce_off = re.search(r'"add_order_note_nonce":"([a-f0-9]+)"', editor)
    ok('guard_off_note_nonce_available', bool(note_nonce_off))
    if note_nonce_off:
        code, note_html = ventas.request('/wp-admin/admin-ajax.php', {
            'action': 'woocommerce_add_order_note', 'security': note_nonce_off.group(1),
            'post_id': str(ORDER), 'note': 'NOTA CONTROL %s NO ATENDER' % secrets.token_hex(3), 'note_type': ''})
        note_id_off_m = re.search(r'<li rel="(\d+)"', note_html)
        ok('guard_off_note_created', note_id_off_m is not None, str(note_html)[:160])
        if note_id_off_m:
            note_id_off = int(note_id_off_m.group(1))
            code, body = ventas.request('/wp-admin/admin-ajax.php', {
                'action': 'edit-comment', '_ajax_nonce-replyto-comment': mint(ventas, 'replyto-comment'),
                'comment_ID': str(note_id_off), 'content': 'NOTA REESCRITA CONTROL'})
            code, editor = ventas.request(EDITOR)
            ok('core_note_edit_allowed_when_guard_removed', 'NOTA REESCRITA CONTROL' in editor,
               'the probe must detect the historical note edit the guard prevents')
    # Positive control 1c (#37): with the guard removed, a VALID native coupon
    # (created by the harness for this disposable fixture only, LOWERCASE like
    # Woo normalizes coupon codes) applies through the REAL CouponsController.
    code, body = ventas.request('/wp-admin/admin-ajax.php', {
        'action': 'woocommerce_add_coupon_discount', 'security': mint(ventas, 'order-item'),
        'order_id': str(ORDER), 'country': 'CL', 'state': '', 'postcode': '', 'city': '',
        'coupon': COUPON})
    code, editor = ventas.request(EDITOR)
    ok('coupon_applied_when_guard_removed', COUPON in editor,
       'the valid fixture coupon must apply through the real controller — the probe detects the rewrite')
    AFTER = off_snapshot()
    changed = [k for k in BEFORE if BEFORE[k] != AFTER[k]]
    ok('guard_off_native_state_changed', 'digest' in changed,
       'the verified fixture must actually change when guards are removed')
    # Positive control 2: the minted quick-status nonce MUTATES the record —
    # with the guard lifted the denial disappears entirely.
    code, body = ventas.request('/wp-admin/admin-ajax.php?action=woocommerce_mark_order_status'
                                '&status=processing&order_id=%d&_wpnonce=%s'
                                % (ORDER, mint(ventas, 'woocommerce-mark-order-status')))
    ok('quick_status_allowed_when_guard_removed', code == 200, 'HTTP %s' % code)
    code, listing = ventas.request('/wp-admin/edit.php?post_type=shop_order')
    ok('status_actually_mutated', 'status-processing' in order_row_status(listing, ORDER), 'the probe must detect real mutation')
    # Positive control 3: a REST order update rewrites the record.
    code, rest_nonce = ventas.request('/wp-admin/admin-ajax.php?action=rest-nonce')
    code, body = ventas.request('/wp-json/wc/v3/orders/%d' % ORDER,
                                {'billing': {'first_name': 'VENTAS REST CAMBIO'}},
                                api=True, headers={'X-WP-Nonce': (rest_nonce or '').strip()})
    ok('rest_update_allowed_when_guard_removed', code in (200, 201), 'HTTP %s %s' % (code, str(body)[:160]))
    code, editor = ventas.request(EDITOR)
    ok('record_actually_mutated', 'VENTAS REST CAMBIO' in editor, 'the probe must detect real mutation')
    print(json.dumps({'order': ORDER, 'run': RUN, 'checks': results, 'failures': failures}, ensure_ascii=False))
    raise SystemExit(1 if failures else 0)

# recheck: guards restored, the denial is back — identity-gated like every
# mutating phase, and the real line id is REQUIRED (no fallback).
if not ok('ventas_login', wp_login(ventas, VENTAS_USER, VENTAS_PASS)):
    print(json.dumps({'order': ORDER, 'run': RUN, 'checks': results, 'failures': failures}, ensure_ascii=False))
    raise SystemExit(1)
editor = ensure_fixture_identity(ventas, ORDER, RUN_EMAIL, expect_qty='999')
recheck_line = next((int(m.group(1)) for m in re.finditer(r'order_item_qty\[(\d+)\]', editor)), 0)
abort_if_broken(ORDER, 'recheck_line_resolved', recheck_line > 0, 'no fixture line resolved; refusing the probe')
code, body = ventas.request('/wp-admin/admin-ajax.php?action=woocommerce_mark_order_status'
                            '&status=completed&order_id=%d&_wpnonce=%s'
                            % (ORDER, mint(ventas, 'woocommerce-mark-order-status')))
ok('quick_status_denied_again', code == 403 and READ_ONLY in body, 'HTTP %s' % code)
code, body = ventas.request('/wp-admin/admin-ajax.php', {
    'action': 'woocommerce_calc_line_taxes', 'security': mint(ventas, 'calc-totals'),
    'order_id': str(ORDER), 'country': 'CL', 'state': '', 'postcode': '', 'city': '',
    'items': 'order_item_id[]=%d&order_item_qty[%d]=777' % (recheck_line, recheck_line)})
ok('tax_recalc_denied_again', code == 403 and READ_ONLY in body, 'HTTP %s' % code)
# Restored append-only boundary on the note created by the guard-off control.
code, editor = ventas.request('/wp-admin/post.php?post=%d&action=edit' % ORDER)
control_notes = [int(m.group(1)) for m in re.finditer(r'<li rel="(\d+)"[^>]*>(.*?)</li>', editor, re.S)
                 if 'NOTA REESCRITA CONTROL' in m.group(2)]
abort_if_broken(ORDER, 'recheck_note_resolved', len(control_notes) == 1)
before_note = native_state(ORDER)
code, body = ventas.request('/wp-admin/admin-ajax.php', {'action':'edit-comment',
    '_ajax_nonce-replyto-comment':mint(ventas,'replyto-comment'),
    'comment_ID':str(control_notes[0]), 'content':'NO DEBE CAMBIAR'})
ok('core_note_denied_again', code == 403 and READ_ONLY in body)
ok('core_note_unchanged_again', before_note == native_state(ORDER))
print(json.dumps({'order': ORDER, 'run': RUN, 'checks': results, 'failures': failures}, ensure_ascii=False))
raise SystemExit(1 if failures else 0)
