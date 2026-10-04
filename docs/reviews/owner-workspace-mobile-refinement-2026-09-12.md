# Owner workspace A — phone-first quotation refinement

## Owner decision

After the 23/40 critique, the owner chose **reviewing requests and generating quotations from the phone** as the main job; prices change infrequently. Authorized addressing all five critique priorities. This is refinement of A, not a new aesthetic direction, import authorization, deployment, or visual acceptance.

Critique: `.impeccable/critique/2026-09-12T00-56-32Z__plugins-freeplast-woo-owner-workspace-php-97a5f6a1.md`.

## Changes

1. Approved detail/review now use the frozen offer, with no disabled editing form, no regenerate-preview control, and no contradictory “nothing approved” copy. An owner-only, request/version-bound nonce download returns the stored PDF bytes. Missing/corrupt documents fail closed; no rendering, mail, or mutation follows a download. Responses carry private/no-store, application/pdf, attachment and nosniff headers.
2. Draft DOM order: identity → products/quantities/unit prices → dispatch/validity → summary → original receipt/contact → independent tracking. Product facts remain together; current list maintenance is collapsed. Approved detail prioritizes tracking instead. Original records and authoritative server arithmetic unchanged.
3. Removed the inbox self-dock and all desktop/preview/issued docks. Editable mobile dock shows saved total and Review for saved clean work, or Save for new/dirty work. Native form association preserves no-JS Save; JS only selects visible action and manages unsaved state. Inline controls remain available.
4. Compact search and native complete filter selector (all six first-pending classifications), plus ordering. Desktop shared milestone headings reduce repeated labels while per-row accessible terms remain available. Explanation of independent milestones is retained in disclosure.
5. Buyer review uses readable product rows, prominent total, validity/destination, numeric dates and short approval copy. The existing bound approval action/token is retained. Stale or incomplete previews still cannot approve; no invented prices, tier thresholds, fiscal rates, or receipt claims.

Implementation: `wordpress/wp-content/plugins/freeplast-woo/{owner-workspace.php,workspace-offer.php,assets/owner-workspace.css,assets/owner-workspace.js}` and an optional concise-copy argument to `fpw_draft_approve_html` in `quotation-approval.php` (legacy default unchanged).

## Observed / checked

- `FREEPLAST_SKIP_STACK=1 npm test`: passed. Aggregate reports 1230 syntax/dependency/deployment checks; includes the complete 339-check quotation offline suite, 36 workspace-offer checks and 18 workspace JS checks. This is **not** a rerun of the native disposable stack.
- Native stack regression updated in `wordpress/scripts/owner-workspace-native.mjs` for static approved facts, download bytes, private headers, role/nonce/version refusal and issued review state. These new stack cases were **not executed** in this turn; no new stack/test server was started.
- Existing isolated reviewer on port8096: real authenticated GETs of inbox, draft141, saved preview142, approved143 and approved review143. Filter “Por aceptar” returned only143. Draft quantity100→101 left price1500, marked totals unsaved and disabled preview; native reset restored100/232050. No Save, Preview-generation, Approval or Tracking POST was performed.
- Authenticated PDF GET200; application/pdf + attachment + private/no-store; forged nonce403. Download SHA256 `bb8fd595e3c40477b676a124a481393e69e50df7e3143d65349a737073aad9aa`, identical to the previously approved sample. Browser Web Crypto is unavailable on this HTTP origin, so hash verification used Node over fetched bytes.
- All132 `fpw_%` options rows remained byte-for-byte identical before/after browser review, including saved drafts, previews, issued versions and commercial tracking. Local SQLite read-only fingerprints archived.
- One eight-capture inspection batch at412×915 and1440×1000, one four-capture confirmation batch after a single refinement batch (compact search, secondary inline Save, shorter origin copy, configured IVA label). No further polish loop. Captures and test output: `.impeccable/review/owner-workspace-mobile-20260912/`.
- Layout detector before/after returned `[]`. The known FPWManrope alias warning from the prior full detector is not a new font. No browser detector injection/server was repeated in this implementation turn.
- DOM checks showed no page-wide overflow in the inspected412px inbox/draft. Four independent milestones and real document states retained. Browser observations are not hardware or human visual acceptance. No screen-reader, soft-keyboard, full concurrency/error, or real mail test was newly performed.

## Local handoff / boundaries

Panel: http://mliu:8096/wp-admin/admin.php?page=fpw-quotations

Existing runtime reused: `/tmp/freeplast-local-review-20260911.ggawl5bp`; no fixtures recreated. Prior plugin files backed up under `before-mobile-refinement-20260912/`. Same database, restricted reviewer account, blocked mail/external WordPress requests and router safeguards preserved. The separate sample-PDF server is no longer needed for the new in-panel authenticated download.

No deployment, staging/production contact, Excel import, price-list import, release packaging, commit or push. Independent security hold remains. Owner review on their actual phone is next.
