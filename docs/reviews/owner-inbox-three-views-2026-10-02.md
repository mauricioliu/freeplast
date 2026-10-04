# Owner inbox — three views, 2026-10-02 (local)

Supersedes the two-view decision of `owner-workspace-simple-inbox-2026-09-12.md` (owner requested it in this session). No staging/production deployment; running only in the isolated local reviewer.

Views, in the owner's requested order, **Pendientes · Enviadas · Todas**; `Pendientes` is the default landing view and the fallback for obsolete filter keys (previously `all`).

Server-side predicates over the native request read model, shared by count and page queries:

- **Pendientes** — requests with no quotation version row yet, plus readable versions whose real mail handoff was not accepted (pending/rejected/unknown delivery). Not "new only": anything not confirmed sent and still workable belongs here.
- **Enviadas** — unchanged accepted-handoff predicate.
- **Todas** — everything, including the honest "Estado no disponible" rows that belong to neither view: corrupt JSON, schema-mismatched or misbound version rows are excluded from Pendientes and Enviadas so a possibly-sent quotation is never shown as pending.

Old `sent` key keeps falling back (now to the default view); `sent-quotes` still means Enviadas. Empty state for a clean Pendientes tab reads "No hay solicitudes pendientes. Todo está cotizado." with a link to Todas.

## Evidence

- Fixture SQL suite grew to 20 CPT/HPOS cases: pending membership (draft-only, rejected, unknown, pending-delivery), exclusion of corrupt/misbound/schema-broken rows, three-view registration (exact labels), both storage layouts. `FREEPLAST_SKIP_STACK=1 npm test` passed (aggregate checker 1,243).
- Real local HTTP suite: 44 checks — default view lists only pending synthetic requests; Enviadas shows only the accepted one; Todas shows all three; tab labels render; all prior access/boundary checks unchanged.
- `check-review.py` harness updated for the new default. One-time MagicDNS resolution flakes were made retry-tolerant inside the test only (test-infra, no product change); the actual defect it exposed first was a test-URL double-prefix, fixed in the test.
- Chrome at 412×915: three tabs render, Pendientes active by default with the two pending synthetic rows; screenshot `/tmp/freeplast-tabs-mobile.png`. One visual batch; no polish loop, no device/owner acceptance claimed.
