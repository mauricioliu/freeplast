# Freeplast — webpage proposal

A standalone, mobile-first redesign proposal for Freeplast.

## Proposals

- `index.html` + `styles.css` + `script.js` — proposal 1, soft organic (Plus Jakarta Sans)
- `landing/` — proposal 2, flat conversion landing built with the
  [`landing-page-design`](https://github.com/elayadesign/ai-design-skills/tree/main/skills/landing-page-design)
  skill. Manrope, fluid island nav, spec-sheet catalog, word-by-word tagline reveal, contact
  form. Read `landing/DESIGN-BRIEF.md` for the intake assumptions, the section map and every
  Part B rule that was applied.
- `propuesta-industrial/` — proposal 3, dark industrial spec-sheet (Archivo + IBM Plex Mono,
  Tailwind v4) — see `propuesta-industrial/DESIGN-NOTES.md`
- `propuesta-editorial/` — proposal 4, Swiss/editorial catalog (Newsreader + Outfit) built
  with the `ui-ux-pro-max` skill. Brand palette locked; contact form required. See
  `propuesta-editorial/DESIGN-NOTES.md` and `design-system/freeplast-editorial/MASTER.md`.
- `propuesta-textos-originales/` — client-selected flat direction, revised to use only the
  current copy from `freeplast.cl` (home, product excerpts, About and Contact). Published as
  immutable review version 6 at `https://mliu.site/freeplast/v6/`.
- `product-page-prototype/` — throwaway product-detail prototype for Caja Cosechera 3/4 with
  three structurally different directions selected through `?variant=A|B|C`. Published as
  immutable review version 7 at `https://mliu.site/freeplast/v7/?variant=A`.

## Files

- `index.html` — semantic page structure and contact form
- `styles.css` — responsive visual system, brand colors, reduced-motion/transparency modes
- `script.js` — mobile navigation, reveal states, pointer-responsive hero, momentum product
  rail, product-to-form mapping, and accessible form validation
- `design-system/freeplast/MASTER.md` — applied visual and interaction direction from the
  ui-ux-pro-max workflow
- `assets/` — optimized copies of Freeplast's existing logo and product photography

## Preview locally

```bash
python3 -m http.server 4173 --bind 0.0.0.0
```

Open `http://mliu:4173/`. Each proposal is served from its own path: `http://mliu:4173/landing/`
for proposal 2, `http://mliu:4173/propuesta-industrial/` for proposal 3,
`http://mliu:4173/propuesta-editorial/` for proposal 4 and
`http://mliu:4173/propuesta-textos-originales/` for the approved direction with current copy
and `http://mliu:4173/product-page-prototype/?variant=A` for the product-page prototype.

## Agent runbook

**WordPress build:** when provisioning the OpenClaw catalog, implementing the v5 design or
building the private catalog/quote plugin, start at
`docs/agents/freeplast-wordpress/RUNBOOK.md`.

## Implementation note

These are front-end proposals. The forms validate and demonstrate their completion state, but
intentionally do not pretend to send data. Connect the submit handler in `script.js` (and
`ENDPOINT` in `landing/script.js`) to the production sales endpoint before launch.
