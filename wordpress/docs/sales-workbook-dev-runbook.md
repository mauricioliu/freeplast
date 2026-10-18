# Official workbook: scoped DEV rehearsal and release

DEV only: `http://mliu:8131`, database `freeplast_local`. Never staging/production.
Lead owns service startup, source activation, browser and final review. Operator owns
exclusive data operations only after explicit release. Never automatically retry an
uncertain write or restore over DEV.

## Preconditions and backup

1. Freeze sales/commercial writers. Verify official workbook SHA256
   `4b52e5bcba620e8f5a9b2211275f0b182c0f413851ad565c3e8c0ee7f61a7572`.
2. Private0700 artifact directory, files0600, outside repository/webroot. Capture
   complete transactional SQL dump, complete WP files, readable private capture and
   runtime config. Record hashes, target, time and explicit unreadable exclusions.
   Test archives and restore them to disposable storage before activation.
3. Run `sales-workbook-dev-snapshot.php` through `docker exec --user www-data -i`
   with PHP stdin. It uses direct readonly SQL, no WordPress bootstrap. Every table,
   every non-sales option/column, IDs and autoload are protected. Save JSON privately.
   Sales register, pending slot and receipts remain separately available for exact
   delta validation. Require356 previous sales and no preexisting pending batch.
   Strict digests always retain the scheduler lock. A second projection normalizes
   **only its value**, retaining row identity/autoload/all other columns, and records
   both raw lock values plus capture clocks for the bounded audit below.
4. Authenticate `mapping-input.json`'s **legacy object**, not the legacy list or browser
   fields. Private file0600 outside ABSPATH, independently pinned SHA256 in trusted
   `FPW_SALES_LEGACY_FILE` / `FPW_SALES_LEGACY_SHA256` constants. Never commit evidence.

## Disposable rehearsal (lead starts; operator restores/applies)

Use an isolated internal Docker network, a new MariaDB11.4 container (OS user mysql),
new tmpfs database and a PHP8.3 runner (OS user www-data) with no HTTP listener/ports,
no cron, no outbound network. Keep restored URLs unchanged; network isolation is
mandatory. Do not attach trial to real DEV networks or reuse DEV data volumes.

Restore complete SQL using the application DB account and WP/private archives into
empty trial tmpfs. Verify restored DB inventory equals the captured baseline and
WP-file contents match archive before overlaying candidate code. Use candidate
plugin source only after recovery validation. Trial config keeps local containment,
mail suppression and pinned private evidence. Check ZIP/DOM/mysqli before importing.

The bounded operator `scripts/sales-workbook-dev-operation.php` supports:
- `preview`: readonly candidate plan, full private output;
- `stage`: require356 sales and exactly966 new/319 enrich/15 conflicts/161 pending;
  refuse any existing pending preview;
- `cancel TOKEN`: remove only matching staged preview;
- `confirm TOKEN`: recheck exact workbook rows/hash, target and expected counts;
  invoke production transactional confirmation once.

Writes additionally require `FPW_DEV_WRITE_RELEASE=plan-mvdqvx1t-tlr8`. This is an
operator interlock, **not authorization**. Lead release remains necessary. CLI uses
SHORTINIT and real WordPress database APIs; it does not load plugins/cron/mail.
It bypasses HTTP handlers, so browser authorization/CSRF/UX checks remain separate.
Private input locations: `/var/freeplast/sales-tlr8/source.xlsx` and `legacy.json`.
Trusted constants must exist before operator execution. Preserve the private evidence
file and reviewed Compose evidence override on every future WordPress recreation:
plain recreation without the override loses the constants and fails legacy linking
closed. Keep file0600 owned by the runtime user and its independently pinned hash.
Never regenerate legacy proof from the now-imported register; source/evidence changes
require fresh reconciliation and deliberate trusted configuration review.

Trial sequence: snapshot; stage; save exact staged preview; cancel; verify register
and receipts unchanged; stage again; save exact preview; confirm once; snapshot;
validate full delta; readonly repeat preview must propose0 new/0 enrich, preserving
15 conflicts/161 pending. Never confirm a second batch. Save pending reasons and
conflicts from receipt privately. A failed response means inspect receipt/register
and stop, not repeat.

Exact comparison:
```
python3 wordpress/scripts/sales-workbook-dev-verify.py \
  before.json after.json staged-preview.json confirm.json > private-delta.json
```
`staged-preview.json` is the `preview` object from successful staging output (or a
readonly preview while that exact token is pending). Verifier requires all old
fields/provenance unchanged, only planned empty fields filled, appended audit,
exact new entities, unchanged prior receipts, consumed slot, matching receipt and
no non-sales DB drift, except the explicitly audited operational renewal below.
Expected result356 preserved +966 new =1322,319 enriched.

Pinned Woo admin shutdown renews `action_scheduler_lock_async-request-runner` before
attempting its contained loopback dispatch. Lead authorized this **one value-only**
transition: row exists before/after; all other columns and every other non-sales row
(including queue tables) identical; both values match native uniqid/expiry encoding;
old lock expired before renewal; new expiry minus pinned60s lies within baseline-to-
postcapture clock window. Deletion, rename, other option changes, malformed or
non-monotonic/out-of-window values fail. Verifier reports `strict_equal: false`, both
raw values and exact delta when this occurs; never claim strict digest equality or
blanket-exclude transient/auth data. No global scheduler settings are changed.

## Real DEV release

After rehearsal and integrated gates pass, lead performs guarded source activation
only after fresh backup and exact dirty-baseline preservation. Worker never edits
original checkout. Lead configures evidence constants without exposing private data.
Fingerprint immediately before/after activation and stop on unexpected drift.

Lead handles browser upload/cancel/confirmation/history verification in coordination
with exclusive operator. Do not both confirm. If CLI confirms, lead must separately
record that browser confirmation itself was not verified. Capture login/session
metadata before import baseline; never broadly exempt usermeta/transients to hide
drift. Any expected auth changes require exact key-level evidence and lead ruling.

Repeat rehearsal's exact gates on DEV. Preserve complete private delta, receipt,
new readonly preview, target/version/source hashes and protected fingerprints.
Recheck source workbook hash. Do not overwrite populated conflicts or decide
ambiguous identities. Pending business decisions are not import failures.

## Recovery and cleanup

Backup restoration is proved only in disposable storage. Never restore over DEV
automatically: first determine committed state from durable receipt and register.
An uncertain result blocks writes. A destructive rollback needs separate approval.
Lead removes only this run's trial containers/network/tmpfs after evidence retained.
After successful application and repeat-preview checks, verify hashes and remove only
this run's disposable `/var/freeplast/sales-tlr8/source.xlsx` operations copy. Keep
`legacy.json` authenticated for future uploads; never alter the original Downloads
workbook. PHP upload/reader temporary binaries are not retained; persisted invoice
lines/raw-row provenance and receipts remain intentionally available in private DB.
Keep backups private under the existing retention policy; never publish raw sales.
Release report must distinguish offline/native/browser checks, actual application,
unverified items and global review. No production readiness claim follows from DEV.

## DEV execution record (2026-10-18; not a production release)

- Restored48-table backup and11731 WP-file entries in isolated trial; import and
  cancellation gates passed before real activation. Four unreadable historical
  provenance JSON files explicitly excluded from private capture, never touched.
- Lead browser uploaded/cancelled, then uploaded and confirmed **once**. Independent
  database verification:356 originals preserved,966 new,319 enriched,1322 total;
  15 conflicts and161 pending (240 source rows) remain unapplied. The37 unenriched
  originals were retained, not replaced. Receipt/delta/provenance match staged data.
- Readonly repeat proposes0 new/0 enrich,1285 existing,15 conflicts/161 pending;
  no second confirmation or pending slot.141 multiline invoices preserved;2 special
  movements remain pending. Source hash unchanged; runtime XLSX copy removed,
  authenticated legacy evidence retained.
- Full non-sales row protection passed. Strict digests differed only where the
  separately bounded native scheduler lock renewed; raw deltas remain private.
- Existing live drafts have no matching historical RUT. Lead checked draft71's
  truthful empty-history state; no draft/request was created or edited. Matched
  history evidence uses unchanged production rendering against restored trial data
  and an in-memory identity:11 invoices,12 lines,1 multiline invoice, local CSS/font.
  Lead checked that private static artifact at1280x900 and412x915: no page overflow;
  mobile table scroll remains internal. This is explicitly **not** matched history
  on a live saved draft.
- Private backups, exact deltas, receipt, pending reasons, audit hashes and rendering
  evidence live outside Git/webroot. Lead removed only the trial containers/network;
  DEV and its DB remain healthy. Single global review/release remains lead-owned;
  DEV success is not staging/production authorization.

## Small operational check

`python3 wordpress/scripts/sales-workbook-dev-verify-test.py` checks exact delta and
rejects protected drift, duplicate/missing IDs, overwritten populated values,
wrong audit, receipt mismatch and an unconsumed preview. It also accepts a bounded
scheduler renewal and rejects13 unrelated/invalid lock changes. Existing importer tests
remain authoritative for parsing, access control and transaction failure/concurrency.
