# DEV: simplified issued quotation view — 2026-10-21

Owner approved the standalone `.scratch/sent-proposal/preview/` concept and requested DEV activation only.

## Scope

Issued quotations now bypass the draft header, original notices, purchase history and duplicated commercial summary, including on `review=1`. The screen reads the frozen buyer email and stored delivery result, and embeds the exact approved PDF. Draft editing and pre-approval review are unchanged.

Accepted delivery shows “Cotización enviada a …”; the contained local capture runtime explicitly shows simulated delivery instead. Rejected, unknown, pending and missing/corrupt PDF states do not claim success. Rendering never approves, sends, regenerates or changes a quotation.

The existing owner-only, nonce/request/version-bound PDF endpoint defaults to attachment. `view=1` requests inline disposition with identical stored bytes and private/no-store headers. Both paths retain the same authorization. Open/download links remain usable when native browser PDF embedding is unsupported.

DEV Apache CSP permits only same-origin frames (`frame-src 'self'`); objects remain blocked and other containment directives unchanged. Applied the mounted config with Apache syntax check and graceful reload, without recreating containers. Staging and production untouched.

## Evidence and limits

- Private pre-change DB/code backup: `~/.local/state/freeplast-sent-view-20261021T180912/`; dump SHA-256 `ee04060a6eca49b58181b6305bd438d87adf5a04bbe4abcbc938139f59782acb`.
- Offline suite passed (`FREEPLAST_SKIP_STACK=1 npm test`): 2,262 syntax/dependency/deployment checks and component suites; workspace offer suite: 147 checks.
- Authenticated HTTP against existing DEV request 72 verified simulated confirmation, simplified issued and review screens, inline disposition, attachment disposition, no-store and byte identity with the approved stored PDF. Anonymous PDF requests refused. No new quotation, approval, resend or customer email.
- Added native regression coverage for inline/attachment byte identity, owner authorization and frozen document after draft changes. Disposable native harness did **not** run: its configured port 8091 was already owned by another service; did not interrupt it or rebuild the shared disposable directory.
- Browser rendering, mobile native PDF support and human visual acceptance remain unverified. HTTP/content tests do not constitute visual approval.
