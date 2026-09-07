# Verification safety and evidence — #37–#39

## Subsequent deployment (separate owner authorization)

The owner subsequently requested publication to `freeplast.mliu.site` on
2026-09-07. Adapter1.6.3/theme1.0.7 are deployed after paired backup + isolated
restore, 29 installed-source hashes, 100 native read-only state checks and public
GET route/asset checks. A second same-day release (20260907T215925Z) then shipped
adapter1.6.4/theme1.0.8 (catalog search fix + owner card UI) from the committed
tree after a fresh paired backup/restore rehearsal, 30 installed-source hashes,
100 state checks and an identical pre/post record fingerprint; public GET checks
now also cover the search contract (`/?s=caja`, `/?s=cajas`, native empty
message). No native mutation scenario or visual/device review was
run; none of the safety prerequisites below are waived. Full record:
[WOO-MIGRATION.md](WOO-MIGRATION.md). There is currently no Ventas-role account
on staging; the deployment did not create one.

## Implementation-phase evidence (offline closeout before deployment)

No server, browser, device, staging, production, mail or database run was made
for this closeout. `FREEPLAST_SKIP_STACK=1 npm test` executes:

- PHP adapter boundaries including pinned native tax/coupon controller tripwires;
- Python operator **control flow** with all network/subprocess I/O mocked;
- full native-data digest tests (same-length item/options/contact/meta changes,
  note changes beyond 400 characters, visibility and author);
- the actual pinned Woo cart store + shipped watcher with real streamed Responses.

A forced failed Python assertion now exits nonzero. The earlier worker self-test
cleared its accumulated failures and falsely exited zero; that test was replaced,
not accepted. Its HTML snapshot also missed native `class="item "` rows and was
removed. `woo-ventas-state.php` instead hashes native order data, every item and
its metadata, and full order-note data/metadata. Only `_edit_lock`/`_edit_last`
(editor presence) are excluded. No record values or confirmation keys are
returned by that helper. It is read-only WP-CLI plumbing, **not a web endpoint**.

## Disposable native #37 walkthrough — operator only

The existing `woo-stack-harness.mjs` owns the isolated stack, mail suppression,
synthetic catalog, account and a fresh run token. Its guarded/off/recheck phases
receive the same run token and the exact newly created request ID. Missing run
provenance is a usage failure; native snapshots also check the exact stored email
`ventas-<run>@example.invalid` before any protected-operation test. Guard removal
is confined to the disposable harness; never install its test mu-plugins on a
shared site. Tax requests use real item IDs; a valid synthetic lowercase coupon
supports the positive control. Existing-order notes are edited only on that run's
new request. The final phase restores guards and checks tax/status/note denial.

These HTTP scenarios are **prepared, not executed**. The administrator and
manager controls, native rendered form fields, notes, line readability and hidden
mutation controls still need an operator run. Offline tests are not proof of
native HTTP authorization or visual acceptance.

## Staging #38 walkthrough — separately authorized operator only

The repaired `scripts/verify-ventas-role.py` will not search for a historical
request or accept `--order-id`. Preparation and execution require a new synthetic
operator fixture and **independent mail containment** (SMTP sink/blocked delivery,
including WordPress user-account notifications). A flag is acknowledgment, not
proof of that containment. Back up staging and obtain separate authorization
before running anything below. Do not run against production.

1. Verify the native synthetic product `caja-universal-prueba` exists and does
   not manage stock. Do not repurpose a historical request.
2. In staging's operator WP-CLI environment, run the new fixture helper with
   the synthetic product ID. This creates one native unpaid pending quote,
   marks it `NO ATENDER`, assigns random run provenance, and prints a receipt
   containing only its ID, run marker and creation-start time:

   ```sh
   umask 077
   FREEPLAST_MAIL_CONTAINED=1 wp eval-file scripts/provision-ventas-fixture.php <synthetic-product-id> > /private/path/fixture.json
   ```

   Run within one hour. A partial provisioning failure reports the new synthetic
   record ID for operator inspection; do not reuse it as a successful receipt.
3. Supply administrator credentials through the process environment (never CLI
   arguments, logs or receipt files). Then:

   ```sh
   python3 wordpress/scripts/verify-ventas-role.py --execute-staging --mail-contained --fixture /private/path/fixture.json
   ```

   The verifier creates a random temporary Ventas account using native WordPress
   REST. Before note/mutation probes, it independently reads the exact native
   order using the administrator session and checks ID, unique run metadata,
   exact run email/name, native creation time after the receipt start, pending
   unpaid quote state, and lines. It then checks the restricted user's own
   identity and delivered request page. A mismatch aborts, without a fallback.
4. The verifier deletes **only** its temporary username/email (proven absent
   before creation), even after identity rejection, failed login, ambiguous user
   creation or later errors. Cleanup failure is reported alongside the original
   failure, never as success. It never deletes the supplied fixture; its
   provisioning operator owns review and cleanup of that explicitly new record.
5. Permission evidence: private note + native editor denial + cookie-auth REST
   denial + list/search reads. The record is compared before/after denied
   operations. Trash is explicitly **not run / nonce not offered** on staging;
   there is no nonce-less CSRF request mislabeled as authorization. Tax/coupon,
   core metadata/history routes, valid-nonce trash and guard-off controls remain
   in the **disposable** matrix, not this shared-site verifier.

## Quantity settlement #39

Theme 1.0.7 observes Woo 11.1.0's actual `Response.json()` consumption without
cloning/reading a second body, fetching or writing cart data. The count stays
positive through body success/failure and is released in the next task, after
Woo's promise continuations apply the response and clear pending flags. A shared
cart-wide pending predicate gates **both** notices and CTA activation. The 50ms
notice debounce is not evidence of network completion; pending work is not polled.

Offline scenarios independently release headers and stream bytes for success,
HTTP error, invalid JSON and body interruption, then check saved quantity,
variant/other-line preservation and retry. Pointer/keyboard click simulation uses
minimal DOM plumbing: rendered blocks, actual keyboard behavior, reload/checkout
HTTP persistence and physical-mobile judgment remain operator-owned. Existing
visual tokens and focus behavior are unchanged. No hardware approval is claimed.
