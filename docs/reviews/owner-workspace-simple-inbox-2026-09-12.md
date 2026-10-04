# Requests inbox — two views, automatic sending state

## New owner decision (supersedes the four-milestone workspace)

The owner prioritizes (1) all requests received from customers, (2) requests whose quotations have already been sent. They explicitly requested removing the manual status section and simplifying the page. The earlier requirement for visible manual acceptance/payment/dispatch controls no longer governs this workspace.

## Implemented locally

- Two visible native navigation links: **Todas las solicitudes** / **Cotizaciones enviadas**. Search remains; newest-first is the default. Removed six-way next-milestone selection, visible sorting control, repeated four-milestone columns and explanatory accordion.
- One automatic sending state per row. An approved PDF alone, rejected transport, unknown outcome or historical manual checkmarks cannot put a request in Sent. Recorded accepted mail handoff is not a claim of buyer receipt.
- Removed the entire manual tracking section from both draft and approved detail, its copy and workspace-only CSS/JS handling. Preserved saved quotations, guarded save/review/approval, immutable approved PDF downloads and unsaved-work protection for remaining forms.
- The inbox now starts from native WooCommerce requests marked `_fp_request=yes`, not draft-option rows. Old requests without drafts appear and link to their original native record; GET never manufactures a draft. Ordinary orders, trashed records and abandoned checkout drafts are excluded.
- Read model supports native CPT and HPOS table layouts, retains 25-item pagination and search by company/RUT/reference. IDs/date ordering is stable; SQL count and page reads share the same membership predicate. New `stage=sent-quotes` avoids silently reversing the former `stage=sent` meaning (“Por enviar”); obsolete filter keys fall back to All.
- Historical manual tracking rows and guarded backend compatibility functions are retained, not erased or used in the new filter. No import or migration ran.

Sources: `wordpress/wp-content/plugins/freeplast-woo/{owner-workspace.php,workspace-requests.php,workspace-offer.php,assets/owner-workspace.css,assets/owner-workspace.js}`.

## Evidence and limits

- `FREEPLAST_SKIP_STACK=1 npm test` passed: aggregate1231 checks; quotation339, workspace-offer43, workspace-JS18 reported; new SQL suite18 cases across CPT/HPOS schema-shaped in-memory SQLite databases. Tests include old/no-draft receipt membership/search, ordinary/deleted exclusions, accepted/rejected/unknown/pending/corrupt/misbound versions, historical manual marks, obsolete filter keys and quoted search input. Fixture also checks bounded pagination and two-view contract.
- Native regression updated for the new UI and a synthetic pre-draft native request. Native stack suite was **not run**; there is no new live MySQL or HPOS-environment execution claim.
- Existing authenticated isolated reviewer: All returned143/142/141; Sent returned143 only. Real GET searches: All/Agrícola→141, Sent/Agrícola→empty, Sent/Muestra→143, unmatched→empty. Old accepted-filter bookmark returnedAll. No manual controls in inbox, draft141 or issued143; PDF access remains present.
- One six-capture browser inspection batch: All/Sent at412×915 and1440×1000, draft/issued at412. Final labels/state hardening followed by one DOM-only confirmation. No visual polish loop, no hardware/soft-keyboard/screen-reader validation or human acceptance.
- Existing runtime `/tmp/freeplast-local-review-20260911.ggawl5bp` reused. Backup: `before-simple-inbox-20260912/`. No new fixtures in this reviewer, no saves/approvals/mail, no new review server in this turn. Commercial fingerprint checked separately; captures/log under `.impeccable/review/owner-workspace-simple-inbox-20260912/`.

## Handoff

http://mliu:8096/wp-admin/admin.php?page=fpw-quotations

Local only. Independent security hold remains; no staging/production contact, import, packaging, deploy, commit or push. Owner should judge whether the two-view page now matches their phone workflow.
