# Dispatch selection: mandatory Google selection, released to staging

Plan `plan-mv3x64hv-sml2`. Source `044605f` was published only to staging by completed release `20261013T010349Z-272948` (adapter 1.12.9 / theme 1.0.23). The full native gate, paired-backup restoration, trial, installed hashes/state/fingerprint and public smoke passed; dedicated cron was restored byte-identically. Both restricted keys are configured and an earlier submission-free server lookup succeeded. Limited post-release browser checks passed, but successful Google suggestion selection remains unverified: the tested public queries did not produce selectable suggestions in that browser. [Completed release](releases/2026-10-13-logic-272948.md) · [Recovery history](releases/2026-10-13-mandatory-dispatch-held.md) · [Native test evidence](../../docs/reviews/dispatch-google-selection-2026-10-12.md).
The approved plan replaces ADR-0007's original optional/manual-fallback policy for **new checkout submissions**. Historical manual requests, stored drafts, approved versions and PDFs are not migrated or rewritten.

## Scope and behavior

- Checkout requires a Google suggestion with dispatch. Street/sector matches in Chile remain accepted without a street number. Without dispatch, address, references and place carriers are excluded server-side.
- Existing form styling retained. Canonical address is a copyable, readonly two-line textarea. Search edits/clears, provider errors, dispatch changes and newer selections invalidate outstanding details responses.
- Optional `billing_fp_references` remains separate (800-byte server limit). It never travels to Places or Routes.
- Server confirmation uses only a validated Place ID at the fixed Places v1 endpoint, an 8-second timeout, no redirects and a 32 KiB response limit. It requires matching ID, Chile country component, types and safe nonempty canonical address. Client address and scope are replaced. Failures block dispatch, not no-dispatch requests.
- Confirmation is cached only within one PHP request, including failures; no per-keystroke server requests or automatic retries.

### Reference surfaces covered

Native `_billing_fp_references`; `_fp_submitted_details`; Woo session form draft; receipt snapshot `destination.references`; native request administration; original-request facts in private draft/workspace; customer receipt; quotation projection/preview; private generated/issued offer; quotation email; PDF HTML source. Issuance freezes references in the reviewed projection, without joining them into the canonical address. Existing versions without references still render and return their original stored PDF bytes. Notes are not an editable sales working-state field.

### Local evidence

`FREEPLAST_SKIP_STACK=1 npm test`: offline gate, including mocked Places, pinned Woo/jQuery DOM lifecycle and PHP validation/render tests. Google/browser/device behavior is **unverified**. No server was started.

Tests cover missing/forged IDs and client addresses, canonical replacement, CL broad matches, unsafe/mismatched provider data, auth/quota/network errors, absent server key, no-dispatch exclusion, reference persistence/escaping, legacy projection compatibility, async stale A after edit/toggle/new B, loader timeout and `gmp-error`.

Disposable native-stack fixtures now intercept Places through `pre_http_request` with synthetic keys/IDs. Unknown fixture IDs fail closed. The original worker did not run the full stack gate. The completion attempt subsequently passed the full isolated native release gate (752 real-stack checks, 2671 aggregate checks and native photo tests). No real Google credentials are used by these fixtures.

## Configuration and staging release checklist

Dispatch fails closed unless both configurations are ready. Both keys are configured in staging; release restoration/trial/install/verification completed without changing the sealed baseline. The checklist below remains applicable to later releases; production stays excluded. Post-release DOM checks confirmed a readonly canonical field, separate references, blocking of an unconfirmed dispatch selection and exclusion/hiding with no dispatch. A synthetic submit was intercepted before Woo checkout handlers; no real order, quotation or mail was submitted. The temporary test cart was emptied and the protected fingerprint rechecked. Successful real selection/replacement, mobile layout and human acceptance are still pending.

1. Lead integrates/reviews steps 1–7; restores required external-tool eligibility and obtains staging authorization. Do not touch `freeplast.cl`.
2. Provision a **separate** `FREEPLAST_GOOGLE_PLACES_SERVER_KEY` in private server configuration, restricted to Places API (New) and staging server egress IP. Never use the browser key, commit a key, put it in script localization or log provider payloads. Confirm approved quota/billing separately.
3. Keep `FREEPLAST_GOOGLE_API_KEY` (or `fpw_places_config` browser filter) restricted by HTTP referrer for the intended staging host and the necessary browser APIs. The browser receives only this browser configuration.
4. Run full native `npm test` in the authorized disposable local stack. Inspect new dispatch=yes fixtures, rejected manual/malformed requests and stored canonical data/references. Worker ran offline gate only.
5. Before staging writes: record current release/plugin/theme versions and private configuration inventory, back up database and current release files using the established staging procedure. Restore backup into an isolated disposable target and verify record counts, historical manual requests, draft/issued PDF hashes and configuration. A backup file alone is not restoration evidence.
6. Deploy integrated adapter 1.12.9 / theme 1.0.23 to staging only. Keep backup and previous release available. On failure follow the release recovery rules, retaining any owned maintenance/cron hold and obtaining a fresh recovery decision. Never restore a database over later writes or assume release approval authorizes rollback.
7. Authorized real-browser review: keyboard suggestion selection and focus, copy-only address, mobile two-line layout, edit/clear invalidation, rapid A→B selection, toggle while details load, street/rural CL matches, references, Google load/auth/quota failure, server failure then explicit retry, no-dispatch path, back navigation, request/draft/offer/email/PDF references. Do not send real mail or place live commercial requests without separate authorization.
8. Record backup, restore, native, provider and browser evidence before declaring step 8 complete. Publication, credentials/IP restrictions, server-provider transport and restoration are verified for this staging release. Successful Google suggestion selection/replacement and keyboard/mobile/human acceptance remain **unverified**, not established by fixtures or the release's HTTP smoke.
