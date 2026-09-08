# Matched-state visual matrix & authorized browser procedure (#48 — PREPARED, UNRUN)

Nothing in this file has been executed. It is a prepared observation matrix,
not an executable browser-fixture setup (see the blockers in commands.md), and the observation list the owner repeats on real PC and
phone. Agents must not fabricate captures or comparisons; any unresolved
difference is recorded in `differences.md`, never hidden by normalizing it
away.

## 0. Preconditions

1. Record the integrated fingerprint (`fingerprint.sh`) before and after.
2. Separately authorized live fixture per commands.md procedure B. Procedure
   A stops its server; a loopback binding alone cannot serve another device.
   Listener and all fixture origins must agree with `http://mliu:<port>/…`.
3. Frozen reference open at `?variant=A` from the preserved prototype
   worktree (`npm run preview` inside the prototype directory binds only the
   Tailscale IP; or the approved temporary preview helper). Same browser,
   same font loading, zoom 100%, device pixel ratio 1 for the comparison
   pass.
4. Identical content/state on both sides (recipes below); screenshots only
   from the live sessions.

## 1. Viewports

320, 375, 412, 768, 1024, 1440 CSS px (full-page AND at least one scrolled
position each); plus probe captures immediately around **600** and **1000**
(e.g. 599/600/601, 999/1000/1001) for every composed surface below.

## 2. State recipes (identical on reference and implementation)

| Recipe | Setup |
| --- | --- |
| catalog-empty | fresh session on `/tienda/` |
| catalog-added | one simple product added (qty 70); one variable product page visited (Elegir color) — cards show own units, header count 1 |
| catalog-search | `/?s=cajá` (accent) and a no-match term |
| filter | Agrícola category archive |
| sheet-simple | follow the real catalog permalink for caja-cosechera-3-4; unselected |
| sheet-color-unselected | follow the real permalink for caja-universal-cerrada-color; no color |
| sheet-color-selected | same, Azul selected |
| disclosure | sheet-color-selected with Ficha técnica completa open |
| sheet-pending-photo | follow the real caja-paltera permalink (pending photo) |
| basket-populated | 1 simple (70) + 2 colors (25 Azul, 30 Rojo) — distinct lines/units; long name product included |
| basket-empty | fresh session `/cotizacion/` |
| qty-pending / qty-error / qty-recovered | on the hydrated Cart block: controlled owned response-body hold → pending; contained failure → independently verify persisted qty; retry → recovered. Throttling alone does not prove body settlement |
| details-mobile-summary | `/datos-y-envio/` closed and open |
| details-both-dispatch | Sí with address filled; No after having typed an address |
| details-errors | submit with all required empty → linked summary + field errors (values retained) |
| submit-pending | valid form, submit with request held (busy state) |
| submit-known-failure | contained native pre-validation rejection, independently prove no new record/event; HTTP500 alone is NOT that proof |
| submit-uncertain | request killed after send (lost response) → honest uncertain copy |
| confirmation | stored request with real reference; a new identical request must get a separate record/reference, with the same A composition |
| chrome-states | header on scroll (sticky), mobile menu open/closed, help dialog open, footer, empty-selection dock hidden / selection dock visible on catalog & sheet only |

Content normalizations — the ONLY allowed ones: review-only prototype
tooling (notice bar, comparison bar, Escenarios/lab) and its reserved space;
genuine dynamic references/dates and recorded operational-message replacements;
real destinations replacing demo wiring. Products, images, counts, quantities
and states must be MATCHED, not masked away. DECISIONS is not owner approval
for additional visual differences. Everything else must be compared.

## 3. Comparison axes (record per state × viewport)

Composition (blocks/order), **actually-loaded Manrope** (computed font +
rendered metrics; document any fallback), type sizes/weights/line-heights,
colors (tokens), borders/radii, spacing/gutters (32/48 split; 600/1000
transitions), control geometry (≥44px targets, 16px inputs), image
treatment (tint, contain, aspect 1/1.35/1.55, pending placeholder), state
presentation (selected/disabled/busy), sticky/scrolled states, mobile dock
position incl. safe-area behavior. No pixel thresholds; name every
difference explicitly.

## 4. Keyboard & interaction protocol (prepared — still needs a human)

Tab order and visible focus: verify WordPress's native skip link/target
(the pinned implementation is covered offline; browser focus remains UNRUN), search/filters/sort, quantity steppers
(arrows + buttons), color selection, removal (focus lands on a logical
survivor), Editar productos round trip, menu/help dialogs (Esc native,
focus return), error summary links reaching the right control, submit busy
guard against double activation. `prefers-reduced-motion` (transitions/
spinner), 200% text zoom (no clipping/horizontal scroll), on-screen
keyboard overlap with dock/CTA/inputs.

## 5. Projection consistency sweep (on the live stack)

After add / repeat-add / update / remove / empty / navigation / back-
forward / reload: header count (distinct lines), card badges (own units),
dock (lines+units), basket summary numbers, checkout summary count and
review table, confirmation stored lines — all reconcile; two colors remain
separate lines with separate keys; units never mistaken for line counts.

## 6. Screen-reader & hardware (OWNER steps — not performed, not faked)

A real screen-reader pass (NVDA/VoiceOver) over the same recipe list —
announcements for pending/success/failure/uncertain states, dialog
semantics, error summary reading order, busy submit. Physical review on a
real phone: sticky header/dock with browser chrome, on-screen keyboard,
touch targets, safe-area. These observations are inputs to
`differences.md`/acceptance, not steps an agent may execute or claim.
