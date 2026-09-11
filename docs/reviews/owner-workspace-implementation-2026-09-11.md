# Owner workspace A — implementation evidence, 2026-09-11

Status: implemented locally for staging preparation, **not deployed or visually accepted**.

Contract: [implementation scope and authorization](owner-workspace-implementation-spec-2026-09-11.md). The owner's confirmation was “si, staging primero”; production, workbook imports, tier/fiscal decisions and real delivery remain excluded.

## Commits and review boundary

- Starting main: `6b51c41`; issuance dependency integrated in `c74f5e6` (existing [#56 evidence](issue56-pdf-and-native-2026-09-10.md), not an issue closure or human PDF approval).
- `c7e72b7`: real A inbox/detail, independent tracking, scoped assets, native regression module, staging-only release preparation.
- `3de03e5`: fail closed on unreadable issuance, accessible text-link targets, glossary and [ADR-0012](../adr/0012-independent-commercial-tracking.md).
- `c0af38a`: no-JavaScript pricing-action protection and isolated test-mail ledgers.
- Workspace review fixed point: `c74f5e6`. Unrelated pre-existing untracked files were not staged.

## Independent code review — two axes

Separate read-only Codex reviewer processes were launched in parallel. Neither edited or ran tests. Standards completed against `c7e72b7`. The first Spec process timed out and is **not** counted as a completed review; a bounded independent retry completed against `3de03e5`. Fixes below were subsequently tested by the implementing agent, not claimed independently re-reviewed.

| Standards | Disposition |
| --- | --- |
| **P1:** inbox/detail fell back to current work when a standing version lacked its projection, violating ADR-0011. | Reproduced red through authenticated native HTTP. One validated offer reader now distinguishes absence from a corrupt/unreadable standing row; issued projections never fall back to working amounts. Missing projection and invalid JSON regressions pass. |
| **P2:** several plain links lacked the 44px target; other constants differed from the public storefront's DESIGN.md recipes (header geometry, breakpoints, border/heading/focus/button treatments). | Text-link targets increased. The remaining recipes were not mechanically imposed on the selected A **private workspace**: A is the explicit visual source, not the storefront header/button layout wholesale. This interpretation and the final visual result still need the owner's screen review; no blanket DESIGN compliance claim. |
| **P3, judgement:** duplicate version/projection choice. | Consolidated in the validated offer reader. |

| Spec | Disposition |
| --- | --- |
| **Blocker:** separate preview/refresh forms could discard edited work when JavaScript did not load. | Reproduced red using native form serialization of real WP HTML with scripts disabled. All three pricing actions now submit the same editor controls, with distinct submitters and CSRF checks. The server refuses preview/refresh if values are unsaved, retains valid submitted values in the editor and offers explicit save/discard; it writes neither work nor preview. Invalid/stale submissions receive explicit errors/conflicts. The editable view has no separate saved-preview link that bypasses this action. |

## Verification

- During development: focused native red/green runs and individual PHP/JS syntax checks; the project's `typecheck` alias runs the same `check-woo.mjs` checker, not an additional PHP type system. Earlier offline pass: 1,211 checks.
- Final `npm test` against `http://mliu:8097`: **1,917 aggregate checks**, including **706 disposable real-stack checks**. Do not add the native count a second time. This includes the existing 339 offline draft checks and the independent-worker/SQLite-fault/PDF probes inherited from #56.
- The first final aggregate attempt exposed a test-isolation error: the new workspace's one intercepted quotation mail remained in the independent checkout ledger (29 versus 28 expected). The harness now asserts that the workspace emits exactly one mail, archives that evidence and starts the checkout ledger separately. The rerun passed; this was not an actual duplicate checkout delivery.
- Native coverage includes owner/guest/restricted staff, valid staff nonce denial, manual payment without acceptance or dispatch, revision conflict/date correction, save/reload/stale work, no-JS unsaved preview/refresh and nonce rejection, recovery submitter payload, frozen fields, corrupt issuance, search/pending filters, JS dirty state and one intercepted mail.
- A separate read-only release-fingerprint probe on the disposable database produced a bare digest, detected a run-owned commercial option and recovered the original digest after cleanup. It is **not** a staging restore/trial rehearsal.
- PHP lint for the two release hooks and JS syntax checks passed. The manual Impeccable detector returned no findings during the bounded visual pass. Diff whitespace check passed for the workspace changes (not a reformat of upstream PDF vendor).

Browser observations were bounded to desktop 1440px, mobile 412px and the selected detail/inbox/tracking views. Manrope and the workspace logo loaded; no horizontal page overflow was observed. One correction batch removed WordPress's unused 46px mobile top padding, restored readable paragraph sizing, simplified filter wrapping, reused the existing `assets/logo.webp` brand asset and improved accessible labels. The subsequent no-JS form/guard fixes were verified natively, not followed by another cosmetic screenshot loop. **No phone/hardware test or human visual acceptance.**

## Honest boundaries and release handoff

- Only real maintained suggestions and supported purchase-history fields are displayed. No real Excel/customer/sales data was imported or published; no two-tier rules, mixed-pallet policy or numerical fiscal policy was inferred. Totals are authoritative only after a server save.
- The native test installation is synthetic, with mail intercepted and real sending disabled. Its run-owned workspace users/order/product were removed by the test harness. The temporary server and owned browser tab were closed; the pre-existing `.build` was restored after the final suite. The disposable build remains in private evidence storage.
- `wp-release.owner-workspace.json` packages **only** the plugin. It does not use the existing photo migration or install theme changes. The local plan proposes **LOGIC**, from release-registry base `6e14a7cc111b36b9cfd63a237927f1b707f6b7ac`, targeting `https://freeplast.mliu.site` (not `freeplast.cl`).
- No SSH/target contact, staging installation, production change, push, issue closure or release record occurred. Before target contact/install, obtain the owner's explicit risk-tier confirmation, then follow the release gate, deterministic bundle, paired backup, restore rehearsal, logic trial, maintenance, read-only verification, smoke, record and rollback ceremony.
- Staging's independent mail shim suppresses delivery while returning success to permit testing. A staging “sent” result therefore must **not** be treated as real customer delivery. Staging acceptance should make that environment distinction clear.
- Remaining human gates: the private surface and PDF, keyboard/mobile behavior on the actual device, final staging review; MySQL/MariaDB trial equivalence has not been demonstrated by these SQLite tests.
