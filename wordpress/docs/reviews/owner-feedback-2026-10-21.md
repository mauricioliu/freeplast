# Owner feedback — quantities and dispatch fields (DEV)

Scope authorized in session: «ok, has esos cambios», following Ignacio's messages and the screenshot confirming removal of **Comuna de despacho** and **Enlace de ubicación**, retaining the Google search and delivery address. Initial scope was implementation/DEV only. A subsequent separately authorized staging DATA release is recorded in [2026-10-21-data-863dc6](../releases/2026-10-21-data-863dc6.md); production remains excluded.

## Changes

- Frutera and Merlucera buttons: ±5. All three traversas (G1, UPC and G2, whose stable slug remains `traversa-para-bins-tipo-romano`): ±1. Other products: ±10, including Frutillera.
- One PHP quantity policy resolves native product slugs; variations inherit the parent policy. This avoids relying on divergent/stale `_fp_quote_step` metadata or display titles. Cards, sheets and the native basket receive that same policy. HTML `step=1`, arbitrary positive manual integers and native stock bounds remain intact.
- The three traversa minimums were changed from 50 to **20** in native Woo metadata, not overridden at render time. They remain editable catalog values and informative request minimums, not enforced multiples or a hard validation floor.
- Removed both public destination inputs and their independent requirements, including rendered form rows, draft/session restoration and field validation. Google search, delivery address and access references remain.
- New records discard submitted/stale commune and location URL values. A Chilean commune is derived only from Google's server-confirmed `administrative_area_level_3` component while the selected address still matches. A missing component, provider failure or manual edit never invents a commune or blocks an otherwise valid manual address. Historical record fields, internal review and projections are preserved.
- Asset versions: theme 1.0.29, adapter 1.12.13, fields.js 1.0.6, places.js 1.2.1.

## DEV data operation

`wordpress/local/traversa-minimums.php` is explicit, DEV-only and dry-run by default (`plan`). Apply requires the reviewed plan token plus a verified private backup acknowledgement. It validates the three published seeded native identities, requires a single existing positive minimum row per product, saves a private before-state receipt, uses an InnoDB transaction and locked compare-and-swap updates, clears caches and reads back all three values. Unknown arguments and missing tokens are rejected. A failed update rolls back; an uncertain commit is reported without claiming rollback or success.

The first plan refused because it required imported `_fp_source_id` values, which DEV's original seeded products do not carry. No writes occurred. Identity checking was corrected to the native published slug plus the existing local seed marker (the established local migration convention), then the plan was reviewed again.

Private backup: `~/.local/state/freeplast-owner-feedback-20261021/dev-before.sql`, SHA-256 `561a28192ab0df957e41d11b72ba3307c67064f78050340c06cc660d211f5d06`. Completed operation receipt: `/var/freeplast/receipts/traversa-minimums-20261021-140024-3a92b17c.json` in the capture volume. Only the three existing `_fp_quote_min_qty` rows changed. No seed/reset, customer request, real email or external integration was executed by this operation.

These three minimum changes require a separately approved sealed DATA migration for staging; the DEV-only helper must never run there. Deploying code alone changes button policy and fields, **not** staging's stored minimums. This requirement was subsequently fulfilled by the separately authorized release linked above, using `wordpress/scripts/owner-feedback-release/` and `wp-release-owner-feedback.json`.

## Verification

- Complete fresh-stack suite passed: 887 real-stack checks and 3,148 syntax/dependency/deployment checks. Photo checks also passed (99 native, seven sealed migration and five CLI checks).
- 15 dedicated offline migration checks: plan/no writes, usage/version, staging refusal, backup gate, altered/stale token, success, idempotence, locked-row drift, update failure rollback and uncertain commit handling.
- Dedicated browser: all nine Home controls plus five affected product sheets and related cards (29 controls) followed the expected ±1/±5/±10 policy. Each traversa sheet and Home card started at 20.
- Rendered DEV request form, with dispatch selected, had zero commune/location inputs, retained editable required address and optional references, and had no horizontal overflow at 1440 and 412 CSS pixels. Captures were saved privately under `.scratch/quantity-step-review/owner-feedback-*.png`. The first full-page desktop capture had repeated paint content; a viewport capture confirmed the real form layout. This is not physical phone or comprehensive visual acceptance.
- One synthetic G2 line was added to the dedicated DEV basket to inspect the real form, then removed. Header count returned to zero; no submission occurred and no other user's basket was touched.
- Places browser transport and server confirmation are covered by fixtures. DEV deliberately suppresses live Google, so real Google/API-key operation is not newly certified by these checks.

## Final test result

**PASS** — `.scratch/quantity-step-review/owner-feedback-full-fresh.log` ends with `owner_feedback_full_gate: PASS`. The first attempt to run the native harness directly against the reused repository `.build` refused its URL mismatch (existing 8091 versus requested 8098); no live DEV/staging database was used. The final runner provisioned a fresh private clone at 8098 with real mail disabled, reused only pinned tools/dependencies, and removed its own disposable directory afterward. Earlier fixture assertions tied to the removed commune requirement and old fields.js version were updated to the approved contract, not skipped. Real native POST journeys now cover submission without a public commune field, server-derived commune persistence and discarding a stale unsafe location URL.
