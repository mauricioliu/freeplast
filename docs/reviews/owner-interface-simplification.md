# Freeplast owner interface: scoped visual simplification

## Status

**Published once to staging; technical verification passed. Authenticated mobile/desktop review is pending (no owner browser session).** Base: `c57ba51`. Owner confirmed LOGIC and staging-only publication with normal `wp-release.json`, no data migration/import or business actions, no push and production excluded. Adapter patch increment: `1.12.6 → 1.12.7` (header and existing workspace asset version strings only). The preceding local-validation phase performed no staging/production access, release, real CSV import, migration or outbound mail. Existing unrelated edits in `docs/reviews/sales-history-enrichment.md` and the untracked release record `wordpress/docs/releases/2026-10-10-data-4d88ad.md` were preserved.

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

## Authorized publication plan (executed under renewed authorization; results below)

1. Owner has explicitly authorized **staging only** and confirmed **LOGIC** for this change. No previous release authorization is reused. Production and push are not authorized.
2. Commit only this task's three runtime files, three tests, this report and the adapter version header. Leave unrelated changes untouched. Because the main checkout intentionally retains unrelated edits, run the unchanged driver/gate from a fresh clean clone of this exact local commit, with existing pinned tools/dependencies and the existing release ledger (never a historical baseline). No automatic stash/reset or foreign commit.
3. Use ordinary `wp-release.json`, **not** `wp-release-sales-enrichment.json`: no migration declaration or CSV payload. Proposed tier **LOGIC**, because the ordinary config classifies PHP plugin changes as logic; confirm the final committed diff/tier before execution.
4. Follow `wordpress/docs/releases/native-staging-runbook.md`: fresh isolated native release gate, paired backup with current protected-state fingerprint, verified restore rehearsal, trial upgrade against the restored copy, then approved maintenance install and version/hash/fingerprint checks. Preserve dedicated cron, staging mail protection and noindex. Never reuse a prior baseline or weaken guards.
5. Read-only staging visual checks of these three screens at mobile/desktop widths: no upload, confirmation, draft save, refresh, approval or distance request merely to prove layout. Confirm original data/history and protected fingerprint remain unchanged. Record evidence and release cleanup.
6. If a check fails, stop and preserve a recovery checkpoint. No blind retry, import, migration or rollback. Production `freeplast.cl` remains excluded; any production release requires a separate approval.

## Historical preflight checkpoint: stopped before renewal

Local scoped source commit: `070701a`, adapter candidate `1.12.7`; exactly the three presentation files, three tests, this report and the version-header file. No unrelated edits or prior commits were altered, and no push was made.

Reserved release ID: `20261011T025515Z-572715`. The release driver was **not started**; this is not a completed or installed release. Ordinary `wp-release.json` remains unchanged, with no migration declaration. LOGIC is confirmed by the owner; driver classification and native gate remain pending.

Revalidation performed:
- Prior local evidence checksums and unrelated-file checksums passed; `git diff --check` and the full offline suite passed again (1905 syntax/dependency/deployment checks).
- Release safety receipt matches the installed driver sources.
- Current staging home and noindex passed. Current installed source hashes still match the previous completed release; adapter `1.12.6`, theme `1.0.22`.
- Independent mail shim SHA-256 remained `71164446f3f7432311ed917e412c161f8076acda57a4b3eb02b095a63009d5f1`.
- Dedicated cron present, SHA-256 `acdce0f08554cf34c4a62c0316b0dff873ec24ec61d125befe7240184f270285`, root:root 0644; no cron worker observed. Maintenance, release lock and temporary guard were absent at preflight.
- Count-only read: 356 purchases, 34 Key Logistics, no pending import; current register digest `bdcdf1963f13de55b8b1987733d784c80e1592c2acf00dfbac91e5d3a1131a48`. This is a register summary, **not** the complete protected fingerprint.

Blocking evidence: the auxiliary read-only `wp eval-file /dev/stdin` could not open that path in the native WP-CLI invocation. It emitted a PHP warning but returned zero; the fingerprint output is empty and therefore invalid. No historical digest was substituted. As instructed, publication stopped without a retry. This does not establish a protected-data divergence.

Private evidence: `.scratch/interface-simplification/publication/{staging-preflight.log,current-fingerprint-preflight,current-sales-summary.json,preflight-fingerprint-failure.log,offline-revalidation.log,unrelated.sha256}`. Reserved private work directory: `~/.cache/freeplast-visual-release-A6YpRA`; no release process, backup, rehearsal, trial or new release lock was created.

Pending: corrected read transport under a renewed go-ahead, clean committed-source clone, driver tier classification, fresh native gate, fresh paired backup/fingerprint, isolated restore rehearsal and trial, one guarded staging install, post-install versions/hashes/fingerprint and read-only mobile/desktop checks of the actual three staging screens. No real import, enrichment, data migration, business action or production access occurred. Cron was never suspended; maintenance was never opened. Final report additions are left uncommitted; the only local commit remains the scoped source commit.

## Final publication result

- Staging: https://freeplast.mliu.site.
- Exact deployed source: `070701a8a4a2118f2c17d87eb3fbb622c3cf8463`; adapter `1.12.7`, unchanged theme `1.0.22`.
- Release `20261011T025515Z-572715`: **complete, LOGIC, no migration**, driver exit 0. Previously reserved/not executed; fresh inspection found no state/bundle/backup/operations/lock, so this was one new supported run, not an installation retry.
- Native fresh gate passed: 744 real-stack and 2649 syntax/dependency/deployment checks; photos 99 + sealed 7 + CLI 5, blocked network and no real mail.
- Fresh paired backup `/root/freeplast-backups/releases/20261011T025515Z-572715`, checksum verification, isolated restore and isolated trial all passed before the one install phase. Backup/restored LIVE-CHECK 22 is supporting evidence only, not the fingerprint.
- Fresh protected fingerprint `4a823636f206794d91a8bc6eaba7441e5c33e7fb8308e8d5d4c11502825774b7` matched backup, trial, install, verify and final read after attempted browser review; strictly 64 hex, no stderr and exit 0. Never substituted a historical digest or register/count summary.
- Bundle and installed hashes, versions, protected mail shim and noindex passed. Complete component inventory (392 files) contained no foreign extras/symlinks; all 131 upload files stayed byte-identical to the fresh backup.
- Dedicated cron restored with identical bytes/owner/mode; shared daemon untouched. Maintenance/release lock/temporary guard absent; created driver/supervisor and isolated rehearsal resources gone on fresh inspection.
- No real CSV import, enrichment, data migration, business action, credential/session creation, operational restore/rollback, push or production access.

### Transport and frozen-source continuity

The old `/dev/stdin` failure was diagnosed as taking WP-CLI's regular-file branch, which could not read that path in the native invocation. The documented `eval-file -` branch reads `php://stdin`: a harmless sentinel fixture first passed with exact stdout, empty stderr and exit 0, then the unchanged fingerprint hook produced a valid fresh digest. No remote temporary script or driver/fingerprint changes were required.

The exact candidate was cloned separately so foreign WIP remained untouched. Private Git metadata recognized only the dependency/tool/ledger symlinks corresponding to already ignored cache directories; no source was excluded. A local supervisor initially assumed absent `/usr/bin/node`, and failed before spawning any driver (no PID/state/effect). The installed Node executable was explicitly resolved, with no driver/gate/candidate change; the one actual driver run then started at 03:46:31Z and completed at 03:55:26Z. Transparent local wrappers preserved argv/streams/exits and journaled before/after SSH/Docker effects plus gate output. No process/lock was adopted from an old PID.

### Authenticated screen review: blocked, not waived

The first actual mobile price-screen GET redirected to `wp-login.php?...&reauth=1`. No authenticated owner session existed in this Chrome session. The attempt was stopped; no new login/session, credential changes, form submissions, price refresh, draft save, offer approval, CSV confirmation or distance request occurred. Protected fingerprint remained unchanged afterward.

**Pending:** authenticated read-only review of prices, import and quotation screens at mobile/desktop widths, plus physical-device/human acceptance. The prior 14 local synthetic cases and native gate do not replace that live private-screen review. This is a technically successful staging release, not production approval.

Final record: [2026-10-11 LOGIC release](../../wordpress/docs/releases/2026-10-11-logic-572715.md). Evidence/checkpoint remain under `.scratch/interface-simplification/publication/` and `~/.cache/freeplast-visual-release-A6YpRA/`. Deployed source commit is unchanged. The owner subsequently authorized `commit, push` for repository publication of the source and this closure documentation; that instruction does not authorize another deployment or establish authenticated visual acceptance.
