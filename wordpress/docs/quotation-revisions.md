# Revised quotations

## Owner workflow

- Issued quotations open with **Ver**. Confirmed sent quotations also offer **Editar**.
- Editing copies quantities, net prices, dispatch/destination, pickup selection and validity from the frozen sent projection, not the original working draft or today's price list. Tax calculation keeps the base version's frozen policy.
- **Revisar cotización** saves the isolated working copy and creates a revision-bound preview. **Aprobar y enviar** explicitly issues the changed offer and mails its new PDF. An unchanged projection cannot trigger another send.
- The commercial quotation number is retained; the version increases (1, 2, 3…). Existing unnumbered legacy quotations receive a number on the first revised issuance.
- **Ver** shows the latest issued document with links to every historical version. Historical PDFs are the exact saved bytes, served through the same capability/nonce-protected endpoint.

## Persistence and safety

`revise=N` is scoped to the workspace route and request. Working state and previews use `fpw_draft_work_<id>_after_N` and `fpw_draft_preview_<id>_after_N`; save/preview/refresh/approval nonces carry the same scope. Viewing an editor only builds a virtual revision-zero seed: GET performs no writes or sends.

First issuance keeps the existing unique INSERT. A revised issuance uses an exact compare-and-set on `fpw_quotation_<id>`. In that single durable write, the next version becomes current and the previous complete snapshot moves into a flat `history` array, without recursive history. Its projection, recipient, approval, delivery outcome and PDF bytes are preserved. Only the CAS winner renders/sends, so concurrent approvals and retries converge without duplicate mail.

This is an additive JSON format: existing version-1 records remain readable without migration. The trade-off is a larger row and CAS payload proportional to the number of versions/PDFs; history must not be silently truncated to reduce its size.

A stale base cannot save or approve. A revised document failure preserves history and sends nothing. An unknown transport outcome is persisted before/after the mail attempt and never retried automatically. Another edit is offered only after a confirmed sent result. Recovery for failed/unknown issuance remains a separate operation, not an implicit resend via Editar. A request with previously sent history cannot be archived, even if the latest version failed.

## Verification

```sh
wordpress/.tools/php/php wordpress/scripts/quotation-revisions-test.php
wordpress/.tools/php/php wordpress/scripts/workspace-archive-test.php
python wordpress/scripts/workspace-request-query-test.py
node wordpress/scripts/owner-workspace-js-test.mjs
```

The revision contract includes frozen prefilling despite changed original work/tax configuration, no GET side effects, review/nonce/token guards, unchanged-offer rejection, invalid/stale edits, exact original PDF recovery, publication/document/transport failures, history authorization and an interleaved competing approval.

These tests use offline WordPress/storage/mail stubs. They do not send real mail or establish live MySQL/WooCommerce integration coverage. Browser fixtures can validate the rendered desktop/mobile layout without approving a real quotation.
