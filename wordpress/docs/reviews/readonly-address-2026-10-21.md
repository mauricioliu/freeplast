# Read-only Google delivery address — implementation, not deployed

Owner supplied a staging screenshot marking «Dirección de despacho» and requested it grey and non-editable, then explicitly removed «Dirección manual. Incluye el predio o calle y la comuna en la dirección.»

- Public template now renders the result `readonly` before JavaScript runs; it is not `disabled`, so native POST/FormData still includes the address. The existing `--fp-tint` read-only background and non-resizable styling apply without a new visual treatment.
- Configured Google enhancement keeps the textarea read-only, including loading/provider errors. A new search, replacement selection or provider error clears the previous result and provenance instead of submitting a stale address. The official widget remains editable and fills the result after selection; access references remain editable.
- Removed the manual-address status and public helper inviting manual edits. Messages direct corrections to Google, with reload advice on provider failure.
- Preserved the existing guarded captured-local DEV manual exception (no live Google is available there). Server canonicalization and historical/manual record compatibility were not redefined: readonly is a public UI control, not an authorization or trusted provenance boundary.
- Theme 1.0.30, adapter 1.12.14, places.js 1.2.2 prepared for cache invalidation on a later release. No staging/production install or database change performed for this refinement.

Verification: offline project suite passed, including pinned Woo/jQuery checkout lifecycle tests. Focused Places fixtures cover read-only state, grey background, editable references, selected-result fill, actual native FormData serialization, absence of the manual prompt, stale-result clearing and provider failure without unlocking the field. Native PHP render checks cover readonly before JS and the contained local-only exception. No new browser run was started after the previous staging browser timeout; live Google, hardware and screen review are not newly certified.
