# Quotation-only account — local first, 2026-10-02

Owner asked for a username/password used only to manage quotations, and confirmed local first. No staging/production connection or deployment occurred.

## Local access

- Entrada: **`http://mliu:8096/cotizaciones/`** (Tailscale). Redirige al login si falta sesión y al panel nativo si la cuenta tiene permiso. La barra de dirección pasa a la URL de WordPress tras entrar; no es un reemplazo del enrutamiento interno.
- Panel interno: `http://mliu:8096/wp-admin/admin.php?page=fpw-quotations`.
- Existing synthetic reviewer `revision` changed from `shop_manager` to **`fpw_quotation_manager`**, not given another additive role. Password unchanged, stored in pass `freeplast/local-owner-review` (JSON `password`). No credential in this document.
- Role has exactly `read` and `fpw_manage_quotations`. Native user check verified that Woo configuration, site settings, products, native orders, user editing and plugin activation/installation are denied.
- Login always lands at the inbox, even if a technical destination was requested. Dashboard GET redirects there. Quotation header offers logout instead of Administration; price-maintenance link hidden. Existing administrator/shop-manager access is retained.

## Implementation

New `quotation-access.php` owns the role, quotation permission helper, login redirect and server-side route allowlist. Inbox, draft save/review/approval, dispatch consultation and issued PDF accept the narrow capability without granting `manage_woocommerce`.

For the dedicated role: only the inbox, workspace draft and nonce-protected PDF handler are allowed through wp-admin. Direct settings/products/users/plugins/profile/native-order/import/price-list pages, arbitrary admin-post/admin-ajax and injected admin actions return 403. Authenticated REST is denied and application passwords disabled. Blocking is not just menu hiding. Legacy manual tracking and list/import administration are not newly granted.

## Evidence

- `FREEPLAST_SKIP_STACK=1 npm test` passed, including 25 new offline access-boundary assertions. Aggregate checker reports 1,232; per-suite PHP totals are reported separately, not added here.
- `quotation-access-http-test.py` passed **32 real local HTTP checks**: login/redirects, both inbox tabs, explicit denied routes/POSTs, forged save/PDF nonce, permitted save and preview, frozen PDF download, and authenticated REST rejection with the actual `fpw_quotation_only` error.
- HTTP test saves unchanged fields of synthetic draft 64 and advances its revision; issued synthetic request 66 is only read. Credentials arrive via stdin and are not printed. The reviewer-only MU plugin exposes a REST nonce meta tag to prove the API test is authenticated; this test seam is not shipping plugin code.
- The local account's exact role and seven broader denied capabilities verified independently through WP-CLI.
- No full disposable native suite, MySQL/HPOS dual-runtime test or new visual acceptance claimed. This does not clear the separate earlier security hold or authorize staging release.

Runtime remains under `~/.local/state/freeplast-owner-review/`; service `freeplast-owner-review` is bound to the Tailscale IP and expires 12 hours after its initial start. It has synthetic data, blocked WordPress mail and blocked outbound WordPress HTTP. The running plugin copy includes these access changes. Source changes are uncommitted.

## Friendly entry follow-up

Owner approved `/cotizaciones/` locally. Exact WordPress-relative path (with or without trailing slash) is intercepted before canonical redirects; no page creation or rewrite flush required. `/cotizacion/` remains the customer basket. The entry supports GET/HEAD only, sends no-store/private headers, rejects authenticated accounts without quotation permission, and ignores supplied redirect destinations. Permissions and native screen URLs are unchanged.

After this addition: offline suite passed (43 access assertions), and the local HTTP test passed 41 checks including guest login routing, safe return URL, authenticated entry, singular basket availability and POST rejection. HEAD response independently verified 302, private/no-store headers and fixed login return. Full disposable-stack regression was not rerun; staging remains unchanged.
