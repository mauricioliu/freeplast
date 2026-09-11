# Owner quotation workspace A — implementation contract

## Authority and scope

The owner selected A, requested its refinement and polish, then requested implementation of “the prototype in freeplast.cl”. After the agent proposed real-WordPress test seams and staging first, the owner confirmed **“si, staging primero”**. Production remains untouched. This does not approve Excel mapping, tier rules, fiscal policy, the PDF's visual result, or broaden Ventas permissions.

Visual source: `prototype/owner-quotations-2026-09-10` at `1ae9e20`, `docs/reviews/owner-quotation-workspace-A-refined.prototype.{html,template.html,md}`. Preserve this design rather than reopening A/B/C. Do not ship demo customers, amounts, histories, dates or simulated actions.

Implementation starts at `6b51c41`. Existing #56 fixes through `361d8c5` are integrated locally in `c74f5e6` as a dependency, not marked human-approved, released, or closed. Workspace-specific review base: `c74f5e6`; dependency evidence remains in `docs/reviews/issue56-pdf-and-native-2026-09-10.md`.

## Required slice

- A visible owner-only **Cotizaciones** inbox, containing received requests with durable drafts; link to preserved older native requests without silently creating drafts.
- Search by company/RUT/reference, pagination and order, and filtering by the first pending commercial milestone. Grouping is presentation, not a prerequisite for manual marks.
- A private, product-first detail with original contact/receipt information secondary, working quantity and offered net unit price together, current maintained suggestion separate, saved line totals and a prominent server-calculated summary.
- Save and resume through the existing guarded draft action; detect stale edits. Do not duplicate fiscal arithmetic in JavaScript or modify the original request.
- Explicit preview and bound approval using #56; approved values are read-only and come from the frozen projection. Transport accepted, rejected, unknown and document-pending are distinct; unknown is not retried by the UI.
- Four visible commercial milestones. Sent derives only from recorded mail handoff. Acceptance by the customer, payment and dispatch are separate owner-entered dates with explicit save and correction. Payment does not imply acceptance; dispatch does not imply receipt. No Woo payment/status/stock action or sale import follows a manual mark.
- Tracking has its own CSRF and optimistic revision boundary, independent of quotation working revision. Record acting user and update time; a full evidence/partial-payment/audit-history policy is not invented.
- Keep owner-only capabilities and native authentication. Valid staff nonces do not grant access. Keep the public request journey unpriced.
- Preserve progressive enhancement: forms and navigation work without JavaScript. JavaScript may apply a maintained suggestion, manage focus and warn about unsaved values, not calculate authoritative amounts.

## Honest differences from the demo

- The existing Price List supports a current price, not an approved two-tier model. Show only the maintained value. No invented 1–4/5+ list, automatic threshold, or Excel import.
- Units per pallet come only from the product's actual catalog attribute, otherwise unresolved. They are packaging, not a minimum or pricing rule.
- Existing Purchase History supplies date/source identity/optional total, not approved item/factoring/invoice mappings. Show those supported fields with provenance and explicit unmatched state. Never reconstruct fictitious invoice lines.
- Totals update upon saving, using the server projection. While editing, name the unsaved state and prevent preview/refresh from silently discarding it.
- No numeric fiscal rate is adopted from the prototype. Missing fiscal configuration keeps IVA/total pending and approval blocked.
- Existing unflagged draft URLs retain the legacy presentation for compatibility; generated owner links and the inbox lead to A using `workspace=1`, with the same server actions and guards.

## Confirmed test seams

The owner agreed to tests through real WordPress/Woo screens in a disposable installation with synthetic records and blocked mail:

1. Owner access versus guest and restricted staff, including valid actor nonces.
2. Inbox → detail → save → reload, stale editing and preserved original data.
3. Independent manual tracking, persistence and stale/conflicting tracking saves.
4. Preview → approval → frozen prices/PDF; transport handoff is not client acceptance.

Supplement the screen seam with the shipped browser enhancement over real rendered HTML. Keep the pre-existing request/privacy/PDF regressions. Focused native cases run during red/green; the full aggregate runs at the end. Browser observations are bounded and do not constitute hardware or human visual acceptance.

## Release boundary

Prepare staging only. The old `wp-release.json` includes a photo migration: do not reuse it for this feature. A separate plugin-only release configuration must preserve catalog/media/requests and commercial rows, with backup, restore rehearsal and logic-tier trial. The owner still confirms the final risk classification before target contact/install. No production configuration, cutover, real mail, real workbook import or external provider spend is implied.
