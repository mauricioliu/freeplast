# Freeplast Editorial — design system

> **LOGIC:** When building a page, first check `pages/[page-name].md`.
> If it exists, its rules override this file. Otherwise follow the rules below.

**Project:** Freeplast Editorial (propuesta 4)
**Generated:** 2026-09-01
**Design Dials:** Variance 8/10 (Bold / Asymmetric) · Motion 4/10 (Subtle–Standard) · Density 5/10

The ui-ux-pro-max run recommended Brutalism + Inter + pink accent. Those three are **rejected**: Inter is banned by `brand-spec.md`, pink is a new hue, and raw anti-design would undercut a mayorista industrial. What is kept from the run: sharp geometry, visible grid, oversized type, asymmetric catalog, hero + features + CTA, and a single accent used only on the primary action.

---

## Direction

A mobile-first **Swiss / editorial catalog**. The page should read like a printed issue of a product catalog, not like a WooCommerce grid, a conversion landing, or a dark spec-sheet.

- Paper, hairline rules, 0-radius.
- Type does the hierarchy. Photography sits as plates on a visible grid.
- Brand blue is the ink of the page. Brand green is the only CTA color.
- No pills, island nav, blueprint overlay, marquee, or star ratings.

## Brand tokens (locked — do not substitute)

```css
--blue: #100090;
--blue-head: #0B078C;
--blue-deep: #09043f;
--blue-soft: #e9e7fb;
--green: #306020;
--green-soft: #558948;
--green-mist: #edf4ea;
--ink: #17181c;
--text: #3a3a3a;
--paper: #ffffff;
--paper-tint: #f4f5f7;
--line: color-mix(in oklab, #100090 16%, #ffffff);
--error: #dc2626;
```

No additional hues. Error red is reserved for validation feedback only. The generic skill palette (`#EC4899`, `#18181B`) is not used.

## Typography

- Display: **Newsreader** (opsz 6–72, 600–700). Editorial serif for the H1 and section titles only.
- UI / body: **Outfit** 400–800. Geometric grotesque for nav, body, labels, buttons, specs.
- Display must not be Inter, Roboto, or system-ui.
- Body ≥ 16px. Specs use `tabular-nums`. Headings use `text-wrap: balance`.

## Shape and depth

- Radius: **0**. No pills.
- Shadows: none. Separation is hairline rules and paper/blue fields.
- Grid: 1px brand-blue gaps between catalog plates.
- Buttons: rectangular, min-height 48px.

## Spacing (density 5)

| Token | Value |
|---|---|
| `--space-xs` | 4px |
| `--space-sm` | 8px |
| `--space-md` | 16px |
| `--space-lg` | 24px |
| `--space-xl` | 32px |
| `--space-2xl` | 48px |
| `--space-3xl` | 64px |

## Page structure

1. Sticky masthead: mark + wordmark, Cotizar
2. Hero: issue kicker, oversized serif headline, dek, two CTAs (form + phone)
3. Value strip: three real facts (12 moldes, 1 pallet, Mostazal + Santiago)
4. Catalog: featured plate + 12-product mosaic, each plate preselects the form
5. Nosotros: misión / visión reales + warehouse photo
6. Contacto: low-friction form + real address / phone / email / hours
7. Footer

## Interaction

- Motion: CSS fade/translate 12px, 350ms, `ease-out`. No GSAP overshoot (`back.out` is forbidden on informational UI).
- Reveal once on enter. Skip entirely under `prefers-reduced-motion`.
- Press states: color/opacity only. Never translate the layout.
- Focus: 3px solid `--blue` offset 2px.
- Icon-only controls have `aria-label`.

## Form

Fields: nombre*, email*, teléfono, producto*, cantidad, mensaje.
On failed submit: inline errors **and** a focused error summary that links to each field.
Product plates preselect the matching option and move focus to the form.
Do not fake a network send.

## Avoid

- Generic eco-green palettes or unsupported sustainability claims
- Invented testimonials, logos, or round statistics
- Empty star ratings (the live site’s 0/5)
- Auto-rotating hero
- Emoji as icons
- Translucent purple overlays on photography (the live hero)
