# Freeplast owner interface: scoped visual simplification

## Status

**Staging publication explicitly authorized; release preparation in progress.** Base: `c57ba51`. Owner confirmed LOGIC and staging-only publication with normal `wp-release.json`, no data migration/import or business actions, no push and production excluded. Adapter patch increment: `1.12.6 → 1.12.7` (header and existing workspace asset version strings only). The preceding local-validation phase performed no staging/production access, release, real CSV import, migration or outbound mail. Existing unrelated edits in `docs/reviews/sales-history-enrichment.md` and the untracked release record `wordpress/docs/releases/2026-10-10-data-4d88ad.md` were preserved.

## Scope and changes

The five supplied screenshots mark exactly four blocks:

| Surface | Removed |
|---|---|
| Mantenedor de precios | Complete “Cómo funciona esta lista” section, including its body, also for an empty catalog |
| Importar ventas | Complete “Contrato de importación (v1)” section, including column descriptions and final paragraphs |
| Quotation workspace | Complete “Mantenimiento de precios” accordion |
| Quotation workspace | Complete “Consultar distancia de despacho” accordion |

Only three runtime files changed: `price-list.php`, `sales-register.php`, `owner-workspace.php` under `wordpress/wp-content/plugins/freeplast-woo/`. The upload grid now has one desktop column instead of leaving the removed explanation's empty half. No JS, shared CSS, data schema, CSV parser, write handler, role, nonce, projection or dispatch computation changed.

Preserved: independent Precios and Ventas Históricas navigation; price inputs, volume references and save action; CSV upload, preview, confirmation, cancellation, receipts, strict parsing and enrichment semantics; offered prices, price refresh, original contact/request, history, working destination/freight amount, validity, guarded save/review/approval and stored issued offers. Distance helpers/configuration/authorization remain intact; only their secondary workspace disclosure is omitted. No replacement link or hidden copy was added.

## Tests

- Three targeted regressions failed against the original UI, then passed after the deletion (`red.log`).
- Price list: 116 checks, including CLP validation, privacy, product non-mutation and saved-price stability. Only obsolete explanatory-copy expectations changed; added checks retain the neighbor editor and empty-catalog case.
- Sales register: 141 checks, including CSV identity, limits, conflict handling, enrichment, repeat/cancel, permissions, nonces and receipt/history details. Only removed explanatory-copy expectations changed; added the desktop single-column assertion.
- Quotation draft: 339 checks; workspace offer: 53 checks. Added with/without-dispatch absence checks and positive checks for original request, history, price options, independent navigation and editable freight fields. Rendering remains non-mutating.
- Dispatch distance: all 58 original checks retained, including configuration, destination/provenance, failure states, owner/nonce enforcement and no persistence.
- Workspace chrome and owner-workspace JS regressions passed unchanged.
- `FREEPLAST_SKIP_STACK=1 npm test`: exit 0, 1905 syntax/dependency/deployment checks, with all offline PHP/Python/JS suites passing. `git diff --check` passed.

### Browser evidence

Real PHP renderers reused existing synthetic in-memory test fixtures, with the unchanged owner CSS/JS, Manrope and real logo, served only on loopback. Forms in the disposable preview are submission-blocked. No WordPress database or customer credentials were used.

**14/14 combinations passed** in Chrome: 412 × 915 mobile/touch emulation and 1440 × 1000 desktop, across populated/empty prices, upload/CSV-preview sales, dispatch/no-dispatch quotations and an issued quotation. Verified the four blocks absent; adjacent content and hidden form guards retained; no page-level horizontal overflow; maintenance menu Enter/Escape and focus return; price edit/unsaved legend and reset.

Full-page captures and JSON results are saved locally. Inspected mobile and desktop captures. The existing dense mobile receipts table was deliberately left untouched (outside the marked scope). A test-stub logo URL was normalized only in the disposable preview, not in application code.

**Limits:** browser evidence is local synthetic rendering, not an authenticated live WordPress journey or physical-device approval. The native HTTP/database stack was not rerun. No acceptance or current state of staging/production is asserted.

## Evidence and checkpoint

Private, ignored directory: `.scratch/interface-simplification/`:

- `references/`: copies of the five marked screenshots.
- `red.log`, `focused.log`, `offline-suite.log`, `offline-exit-code`.
- `render-fixture.php`, `browser-check.js`, `preview/*.html` (synthetic, no application backend).
- `browser-results.jsonl`: final 14-case assertions.
- `captures/{mobile,desktop}-*.png`: full-page captures and review crops.
- `change.patch`: only this task's six code/test files, excluding unrelated changes.
- `evidence.sha256`, `checkpoint.json`.

## Authorized publication plan (completion recorded below after execution)

1. Owner has explicitly authorized **staging only** and confirmed **LOGIC** for this change. No previous release authorization is reused. Production and push are not authorized.
2. Commit only this task's three runtime files, three tests, this report and the adapter version header. Leave unrelated changes untouched. Because the main checkout intentionally retains unrelated edits, run the unchanged driver/gate from a fresh clean clone of this exact local commit, with existing pinned tools/dependencies and the existing release ledger (never a historical baseline). No automatic stash/reset or foreign commit.
3. Use ordinary `wp-release.json`, **not** `wp-release-sales-enrichment.json`: no migration declaration or CSV payload. Proposed tier **LOGIC**, because the ordinary config classifies PHP plugin changes as logic; confirm the final committed diff/tier before execution.
4. Follow `wordpress/docs/releases/native-staging-runbook.md`: fresh isolated native release gate, paired backup with current protected-state fingerprint, verified restore rehearsal, trial upgrade against the restored copy, then approved maintenance install and version/hash/fingerprint checks. Preserve dedicated cron, staging mail protection and noindex. Never reuse a prior baseline or weaken guards.
5. Read-only staging visual checks of these three screens at mobile/desktop widths: no upload, confirmation, draft save, refresh, approval or distance request merely to prove layout. Confirm original data/history and protected fingerprint remain unchanged. Record evidence and release cleanup.
6. If a check fails, stop and preserve a recovery checkpoint. No blind retry, import, migration or rollback. Production `freeplast.cl` remains excluded; any production release requires a separate approval.
