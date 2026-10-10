# Sales history enrichment (2026-10-09)

**Initial phase: local preparation and independent review. Subsequent owner authorization permits publishing and completing only these 356 sales on Freeplast staging.** The lead's read-only live reconciliation confirms 356 existing sales, 356 enrichments (1424 missing fields), no new IDs, no conflicts, no pending import and 34 Key Logistics sales. Application uses the sealed DATA release described below; no production access is permitted.

## Problem

Sales imported earlier kept only `id_venta/fecha/rut/total`. Re-uploading a file with `producto`, `total_cajas`, `precio`, `neto` hit the "changed content = conflict" rule, so legacy rows could never gain detail. The workspace UI was already correct; the data was never loaded.

## Code changes

- `wordpress/wp-content/plugins/freeplast-woo/sales-register.php`
  - New `fpw_sales_plan()`: one classifier shared by preview and confirmation. Match by source `id_venta` only. Same id: `fecha`, normalized RUT and `total` must be exactly equal, otherwise conflict. Then per detail field: incoming absent (null/empty) changes nothing; stored missing (null/absent/`''`) + incoming present (zero counts as present) fills; both populated and different is a conflict. Any conflict rejects the whole row (no partial fill). No present incoming detail to add = "ya importada". New ids append.
  - Confirmation applies the plan only after the exactly-once receipt INSERT succeeds (unchanged order). Original `batch`/`imported_at` are preserved; each enrichment adds `enriched: [{batch, at, fields}]` to the sale.
  - Receipt gains `enriquecidas`, `campos_enriquecidos`, `cambios` (first 50: id + field values filled; "before" was always missing by rule). Old receipts/old pending batches lack these keys and still work.
  - Preview (computed live against the current register, also for old pending batches) shows counts new/enrich/same/conflict and a table of proposed fills (`— → value`). Receipts table has an "Enriquecidas" column. Cancel, nonces, roles, bounded reads and CSV validation untouched. Nothing runs on plugin activation.
  - Behaviour change to note: a stored detail value with the incoming file **omitting** that column is now a no-op (previously a conflict), per "absent never erases".
  - A file that omits `total` does not match a stored row that has a total (core field) and is a conflict; converter output always includes `total`.
- `wordpress/scripts/sales-register-test.php`: 109 -> 140 checks (enrichment, preview/receipt counts, cancel, repeat, exactly-once, zero vs null, conflict no partial fill (PF-1/PF-2: stored rows with one populated and other fields missing, incoming with one conflicting and fillable fields), core mismatch of date/rut/total, absent fields, old pending batch). Regression was red first (missing plan/preview), then green. Legacy checks unchanged and green.
- `wordpress/scripts/sales-history-workbook.py` (+ `sales-history-workbook-test.py`, wired in `check-woo.mjs`): offline converter, below.

## Converter (axi-style CLI: counts-only stdout, next-step line, exit 0/1/2)

`python3 wordpress/scripts/sales-history-workbook.py WORKBOOK --expected-workbook-sha256 SHA --applied-csv CSV --identity-map JSON --out NEWDIR`
Reads stored cell values from the XLSX through a bounded ZIP/XML read (member size and cell caps, external links refused); formulas are never evaluated; workbook hash checked before and after; `--out` must be a new directory outside the repo (created 0700, files 0600). Columns: A RUT, B Fecha (Excel serial -> ISO), E Producto, K Total Cajas, L Precio, M Neto, O Total. Non-integer/negative/oversized numbers are left empty and listed, never rounded. Synthetic tests only (5 tests: incl. detail-only workbook change and duplicate ids rejected).

## Source identity evidence

Identity is **proven from prior-import artifacts**, not guessed from date/RUT/amount/offsets (private, mode 0700 dir `~/.local/state/freeplast-sales-import/`):

- `preparation-final/candidate-001.csv` sha256 `08951af0...950309b`. Evidence that this is the file applied on 2026-10-09 is **circumstantial, not byte-proof**: `staging-applied-20261009/before.json` (and the identical `staging-backup-20261009/before.json`) record `csv_sha256` equal to it plus `expected_new: 356` as the pre-apply plan; `preview.json`/`confirmation.json`/`verified.json` record only receipt token `b08bba99...`, a file name ("Ventas historicas aprobadas 2026-10-02 (356 sin flete).csv", not `candidate-001.csv`), 356 rows, total 829.352.650 CLP and 356 applied with 0 conflicts. No artifact records the hash of the bytes actually uploaded. Count and total match the candidate file and `reconciliation.json`.
- `preparation-final/candidates.json` (sha256 `4c39a668...689fb31`) maps each `id_venta` to its single workbook `source_rows`.
- The workbook `docs/Ventas con RUT en primera columna (2026-10-02) (1).xlsx` has sha256 `2d78d3a4...3be66c`, **identical** to `source_sha256_before/after` recorded at that preparation, so the row numbers refer to the same bytes.
- The converter now **requires** `--expected-workbook-sha256` and exits before writing anything when the workbook differs (a detail-only edit with unchanged date/RUT/total would otherwise pass). It also rejects duplicate `id_venta` in the applied CSV or the map. Use `2d78d3a4...3be66c` from the preparation summary.
- Per row, the converter re-reads date/RUT/total from the workbook and requires equality with the applied CSV. Result: 356/356 proven, 0 identity-unproven, 0 core mismatches; `id_venta` also equals the `N° Factura` cell on all 356.
- Residual assumption (also that the applied bytes equal `candidate-001.csv`, per the circumstantial evidence above): the shared site's register still holds exactly the 356 rows applied on 2026-10-09 (backup/before-state in `staging-backup-20261009`). The live preview must confirm it; any drift appears as new/conflict counts.

## Private artifacts (outside repo, not committed)

- `~/.local/state/freeplast-sales-import/enrichment-20261009/enrichment.csv` (356 rows, supported columns, sha in audit)
- `~/.local/state/freeplast-sales-import/enrichment-20261009/audit.json` (hashes, counts, issues)
- `~/.local/state/freeplast-sales-import/enrichment-20261009-v2/` regenerated with the hash-gated converter; `enrichment.csv` byte-identical (sha256 `e2968dad...5606180`). The first directory was not overwritten; either may be used.

Results: 356 proposed, all with product/boxes/price/net (no issues listed). Key Logistics (RUT 96930440-6): 34 sales, all with detail; the two reference sales reconcile exactly (2025-01-14 CO 2000 x 1880 net 3.760.000 total 4.474.400; 2025-02-11 CO 70 x 2100 net 147.000 total 174.930). `Ventas (2).xlsx` was not used (different hash; no identity proof against it).

Offline rehearsal (fake in-memory DB seeded from the applied CSV): preview new 0 / enrich 356 / same 0 / conflict 0; confirm enriched 356 (1424 fields), sales stay 356; repeat = 356 already imported, 0 enriched.

## What the lead must approve before a real update

1. Deploy the importer change (separate approved release; this change is uncommitted and unreleased).
2. Backup/protected-state check of the target register, then upload `enrichment.csv` in the owner screen and **compare the preview** with: 0 new, 356 enrich, 0 same, 0 conflicts. Stop and investigate if different.
3. Explicit owner confirmation, then verify receipt counts, repeat = no-op, and Key Logistics history in the workspace.
4. Confirm `Ventas (2).xlsx` stays out of scope and that the enrichment CSV sha256 in `audit.json` (`e2968dad...5606180`) is the file uploaded.

## Lead verification

Lead independently reviewed the importer, converter and source artifacts; requested and verified corrections for expected-source-hash enforcement, duplicate source IDs, truthful prior-application evidence and the partial-fill conflict regression. Focused lead gates passed: 140 PHP sales-register checks, five synthetic converter tests and `git diff --check`. Local artifact hashes, 356 unique proposal identities, 34 Key Logistics rows and both supplied screenshot examples were independently reconciled. The actual uploaded bytes and current live register are still unverified and require pre-apply reconciliation; no source-row mapping is substituted for that check.

Full lead-run `npm test` passed against a fresh private Git/source snapshot using an isolated disposable WordPress stack at `http://127.0.0.1:8098`: 744 real-stack checks and 2649 syntax/dependency/deployment checks. Native approval/fault tests also passed, with no real mail. Final log and zero exit code: `/home/mauricio-liu/.cache/freeplast-enrichment-native-7ZfzXj/{final-gate.log,final-exit-code}`. Earlier gate attempts stopped because an existing disposable DB had the wrong URL and because a Git archive lacked the historical Git evidence required by the fingerprint tests; neither failure was waived. The final clone retained Git history and fresh disposable storage.

## Not checked

Browser rendering of the new preview text, current shared-site register contents, mobile layout and human acceptance. Native gates cover a disposable synthetic site, not a real sales update. The owner subsequently authorized the scoped staging release and enrichment without another approval prompt. The former approval steps above describe the preparation checkpoint; the approved execution now uses a sealed migration with a fresh immutable live baseline, paired backup and restored trial. Actual execution evidence belongs in the generated release record.

## Authorized sealed DATA release

Dedicated one-shot config: `wp-release-sales-enrichment.json`; ordinary future code releases keep using `wp-release.json`. Generated closure `.build/sales-enrichment-migration/` is ignored by Git and private locally. It contains only the reviewed `run.php`/`policy.php`, exact approved `enrichment.csv` and `SEAL.json` (source 356, target RUT 969304406 with 34 rows, minimum one enrichment; safe populated-detail conflicts are excluded/reported, whereas missing IDs/core mismatches/foreign targets abort).

`capture` independently reconciles the live register and emits an immutable private baseline with its full original digest. `apply`/`verify` rederive the exact expected delta from the sealed source and require the operation-owned maintenance guard. The options table must be InnoDB; START, locked read, binary compare-and-swap, deterministic receipt insertion, exact state and COMMIT are checked. Idempotent retry accepts only the exact completed register and matching receipt. The original import batch/time, every populated sale field, IDs/dates/RUTs/totals, other sales and old receipts remain unchanged. Only missing detail and one provenance receipt are added; no pending batch is replaced or receipts swept.

The fingerprint retains the original table/option contract and rederives the baseline from the closure without plugins. It projects only the coupled untouched state or exact expected register-plus-receipt back to the original values. All unauthorized deltas remain visible; the baseline is never recaptured. Native apply and expected-delta checks run first against the restored paired backup, then under staging maintenance. Migration regression suite: 157 lead-run synthetic checks, in addition to 140 importer checks and five converter tests. The full offline suite passes. No browser or production acceptance is implied by these tests.
