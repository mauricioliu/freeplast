---
name: Freeplast
description: Wholesale recycled-plastic storefront (A · Directa) where every path leads to the Solicitud de cotización
colors:
  blue: "#100090"
  blue-heading: "#0b078c"
  blue-soft: "#eeedf8"
  green: "#306020"
  green-soft: "#558948"
  green-tint: "#eef5ea"
  ink: "#17181c"
  text: "#3a3a3a"
  paper: "#ffffff"
  tint: "#f4f5f7"
  muted: "#6b6b72"
  muted-dark: "#9b9b9b"
  dark: "#181818"
  dark-raised: "#272727"
  line: "rgba(16, 0, 144, 0.14)"
  line-strong: "rgba(16, 0, 144, 0.3)"
  line-dark: "rgba(255, 255, 255, 0.16)"
  chrome-line: "#dedfe6"
  chrome-border: "#a0a2af"
  chrome-muted: "#60626d"
typography:
  display:
    fontFamily: "Manrope, ui-sans-serif, system-ui, sans-serif"
    fontSize: "36px"
    fontWeight: 700
    lineHeight: "40px"
    letterSpacing: "-0.02em"
  headline:
    fontFamily: "Manrope, ui-sans-serif, system-ui, sans-serif"
    fontSize: "30px"
    fontWeight: 700
    lineHeight: "36px"
    letterSpacing: "-0.02em"
  title:
    fontFamily: "Manrope, ui-sans-serif, system-ui, sans-serif"
    fontSize: "24px"
    fontWeight: 700
    lineHeight: "32px"
    letterSpacing: "-0.02em"
  body:
    fontFamily: "Manrope, ui-sans-serif, system-ui, sans-serif"
    fontSize: "16px"
    fontWeight: 400
    lineHeight: "24px"
  label:
    fontFamily: "Manrope, ui-sans-serif, system-ui, sans-serif"
    fontSize: "14px"
    fontWeight: 650
    lineHeight: "20px"
  kicker:
    fontFamily: "Manrope, ui-sans-serif, system-ui, sans-serif"
    fontSize: "11px"
    fontWeight: 750
    lineHeight: "16px"
    letterSpacing: "0.05em"
rounded:
  sm: "4px"
  md: "6px"
  lg: "8px"
  card: "12px"
  panel: "16px"
  hero: "24px"
  full: "9999px"
spacing:
  1: "4px"
  2: "8px"
  3: "12px"
  4: "16px"
  5: "24px"
  6: "32px"
  7: "48px"
  8: "64px"
  9: "96px"
components:
  button-primary:
    backgroundColor: "{colors.blue}"
    textColor: "#ffffff"
    rounded: "{rounded.lg}"
    padding: "8px 12px"
    height: "44px"
  button-primary-hover:
    backgroundColor: "{colors.blue-heading}"
    textColor: "#ffffff"
  button-ghost:
    backgroundColor: "transparent"
    textColor: "{colors.blue}"
    rounded: "{rounded.lg}"
    padding: "8px 12px"
    height: "44px"
  button-secondary:
    backgroundColor: "{colors.paper}"
    textColor: "{colors.blue}"
    rounded: "{rounded.lg}"
    height: "44px"
  button-secondary-hover:
    backgroundColor: "{colors.blue-soft}"
    textColor: "{colors.blue}"
  input:
    backgroundColor: "{colors.paper}"
    textColor: "{colors.ink}"
    rounded: "{rounded.lg}"
    padding: "12px 16px"
    height: "48px"
  basket-pill:
    backgroundColor: "{colors.blue-soft}"
    textColor: "{colors.blue}"
    rounded: "{rounded.full}"
  product-card:
    backgroundColor: "{colors.paper}"
    textColor: "{colors.ink}"
    rounded: "{rounded.card}"
    padding: "16px"
  empty-state:
    backgroundColor: "{colors.tint}"
    textColor: "{colors.text}"
    rounded: "{rounded.md}"
    padding: "32px"
---

# Design System: Freeplast

## Overview

**Creative North Star: "La Bodega Directa"**

Freeplast's interface behaves like an open wholesale warehouse: the product is visible, the
facts are exact, and nothing decorative stands between a business buyer and their Solicitud
de cotización. The buyer is on a phone, in a field or a fish plant, comparing crates by the
pallet — so the system is thumb-honest (every target ≥44px), spec-forward, and built from
real product photography on calm paper whites. Blue acts; green tells the recycled story;
hairlines do the structural work that other systems delegate to shadows.

The world is deliberately quiet so that the two living hues carry all the expression:
Azul Bodega (#100090) owns every primary action and link, and Verde Reciclaje (#306020)
marks categories, facts, and the added-to-basket state — the material story of "plástico
que vuelve a servir". Manrope is the only voice, loaded locally with real weights only;
numbers align in tabular figures because quantities are the product. Comfortable density,
generous white space around tightly spec'd cards.

Confirmed visual rejection: the obsolete v5 editorial direction (Newsreader/Outfit faces,
square geometry, hairline paper grid) is hash-frozen as obsolete in
`wordpress/design/DECISIONS.md` and must not resurface. The current chrome is the owner-
selected **A · Directa** contract (compact 76/84px bar), which superseded the v6 glass pill
island for shared navigation; v6 tokens still govern corporate page bodies.

**Key Characteristics:**
- Flat surfaces structured by hairlines; shadows reserved for dialog overlays and hover response
- Two hues only — blue for action, green for the recycled accent — plus a strict neutral set
- Manrope everywhere, tabular numerals, headings in deep blue (#0b078c)
- 44px minimum touch targets; visible 2px blue focus ring everywhere
- Server-first: navigation and quoting work with JavaScript disabled
- Real photography in white/tint wells; tinted bordered panels for every empty or failed state

## Colors

A cool paper world structured by blue-tinted hairlines, with exactly two living hues: a
deep electric blue that acts and an agricultural green that tells the recycled story.

### Primary
- **Azul Bodega** (#100090): The one voice of action — primary buttons, links, the basket
  count badge, the brand wordmark. If it is blue, it does something.
- **Azul Profundo** (#0b078c): Every heading, and the hover state of primary controls —
  the same blue pressed one degree deeper.
- **Azul Calmo** (#eeedf8): The soft blue of the quotation chrome — the "Productos a
  Cotizar" pill, secondary-button hover, quiet chrome fills.

### Secondary
- **Verde Reciclaje** (#306020): The accent and the material story — category kickers,
  essential facts, mission labels, the green action variant, the added-state bar. Accent
  only, never the default CTA.
- **Verde Suave** (#558948): Soft green — focus outline on dark surfaces, hover of the
  green accent variant.
- **Verde Veladura** (#eef5ea): Green tint fill for chrome moments that reference the accent.

### Neutral
- **Papel** (#ffffff): Page background and card surface.
- **Velo** (#f4f5f7): Tint bands, photo wells, empty states, status pills, summary cards.
- **Tinta Producto** (#17181c): Near-black of the actual crates — input text, card titles.
- **Texto** (#3a3a3a): Body copy.
- **Gris Suspenso** (#6b6b72): Muted text on light; **Gris Nocturno** (#9b9b9b): muted text
  reserved for dark surfaces.
- **Carbón** (#181818) / **Carbón Levantado** (#272727): Reserved dark-band tokens (v6);
  the current shared-chrome footer is a light band.
- **Filigrana** (#dedfe6): The chrome hairline (header/footer borders); **Filigrana
  Fuerte** (#a0a2af): firmer chrome border; **Gris Detalle** (#60626d): chrome detail text.
- **Línea Azulada** (rgba(16, 0, 144, 0.14) / 0.3): Content hairlines and strong lines —
  blue-tinted, never grey, so structure stays inside the brand.
- **Línea Clara** (rgba(255, 255, 255, 0.16)): Hairline reserved for dark surfaces.

### Named Rules
**The No-New-Hues Rule.** Every color derives from Azul Bodega, Verde Reciclaje, or the
neutral set above. Tints and softs of those two hues are allowed; new hues are not. This is
a frozen contract, not a preference.

**The No-Red-Alarm Rule.** Failure is never red-only: errors render as a tint panel with
ink text and a blue-tinted border, inline validation uses blue/tint. The system never
shouts in a color the brand does not own.

**The One Voice Rule.** Blue is the only primary-action color; green accents and reports
(the ✓ added state) but never replaces the CTA.

## Typography

**Display Font:** Manrope (variable 200–800, loaded locally as OFL-licensed woff2;
fallbacks ui-sans-serif, system-ui, sans-serif)
**Body Font:** Manrope (same family — one voice)

**Character:** Geometric-humanist with slightly squared terminals: modern and technical
without coldness, wide enough for small-screen Spanish. Font synthesis is disabled —
only real weights render (200–800).

### Hierarchy
- **Display** (700, 36px/40px mobile → 48px ≥768 → 60px ≥1280, -0.02em): Hero H1 only.
- **Headline** (700, 30px/36px, -0.02em, Azul Profundo #0b078c): Page and section headings.
- **Title** (700, 24px/32px, -0.02em): Card-group and dialog titles.
- **Body** (400, 16px/24px; shared chrome line-height 1.6, corporate bodies 1.5): All copy;
  keep measures near the 680px reading width.
- **Label** (650, 14px/20px): Desktop navigation, small controls; small buttons 13px with
  +0.06em tracking.
- **Kicker** (750, 11px/16px, +0.05em, uppercase, Verde Reciclaje): Category eyebrow on
  cards and detail pages.
- **Card title** (750, 17px/1.35, -0.02em, Tinta Producto): Product names in the catalog
  grid; hover turns them Azul Bodega.

### Named Rules
**The Tabular Rule.** Quantities, measurements, and FP references render in
tabular-nums — the numbers are the product; they must line up.

**The Straight Weights Rule.** Manrope loads with real weights only (font-synthesis: none);
no faux-bold, no faux-light.

## Layout

Designed at ~412px first; adaptation happens exclusively through min-width media queries
(600 / 768 / 900 / 1000 / 1024px). The shared chrome shell is `min(1200px, 100% − 32px)`
(48px gutters ≥600px); corporate page bodies use the 1152px shell; reading measures cap at
680px. The header is a compact persistent bar — 76px tall on mobile, 84px from 1000px —
sticky with a hairline bottom border. Section rhythm breathes at 64px mobile / 96px
desktop block padding. The catalog grid steps 1 column → 2 at 600px → 3 at 1000px with
14/18/22px gaps.

## Elevation & Depth

Flat by default. Hairlines — chrome grey in the shell, blue-tinted inside content — carry
all structure; depth is communicated by border shifts and small transforms on interaction,
never by resting shadows. Shadows exist only on true overlays.

### Shadow Vocabulary
- **Dialog overlay** (`box-shadow: 0 20px 100px rgba(0, 0, 0, 0.2)`; backdrop
  rgba(20, 20, 40, 0.45) + blur(3px)): Native `<dialog>` surfaces only (menu, help).
- **Island glow** (`0 4px 24px rgba(16, 0, 144, 0.1)`): Reserved from the v6 pill island;
  not part of the current A · Directa chrome. Do not apply elsewhere.
- **Hover response**: Product cards shift border color (#aca7d1); buttons lift
  translateY(-2px); cards may lift translateY(-4px) in v6-governed bodies; press is
  scale(0.98).

### Named Rules
**The Border-First Rule.** Surfaces are flat at rest; a hairline structures, a border-color
shift responds. If a resting element needs a shadow to be understood, the layout is wrong.

## Shapes

The nested-radius principle governs: outer containers round one step above what they
embed. Controls (buttons, inputs, steppers) are 8px; compact product cards 12px outside
with 8px photo wells; panels and dialogs 16px (the mobile menu sheet takes a 12px inset);
hero visuals 24px. Full pills (9999px) are reserved for the basket pill, added-state pill,
and count badges — never for navigation links in the current chrome. Hairlines are 1px
everywhere; there are no decorative borders, gradients, or clips.

## Components

### Buttons
- **Shape:** Softly rounded rectangles (8px radius), no borders on primaries.
- **Primary:** Azul Bodega background (#100090), white text, 44px minimum height,
  8px/12px padding, weight 600. Hover: Azul Profundo (#0b078c) + 2px lift.
- **Ghost:** Transparent, blue text, 1px rgba(16, 0, 144, 0.3) border; hover fills Azul
  Bodega with white text.
- **Secondary:** Paper background, blue text, #b8b4d7 border; hover to Azul Calmo
  (#eeedf8).
- **Small:** 36px minimum, 13px, +0.06em tracking.
- **Green variant:** Verde Reciclaje background (hover Verde Suave) — accent moments only,
  never the default CTA.
- **Woo submit:** Full-width, 50px, weight 700, blue — the "Continuar con mis datos" step.

### Quantity Stepper
Border-wrapped group (1px #b5b5c2, 7px radius): 44px −/+ buttons flanking a 54px
white input, tabular-nums, centered; wraps WooCommerce's native input so the no-JS path
stays native.

### Inputs / Fields
- **Style:** 12px/16px padding, 1px blue-tinted hairline (native Woo inputs 1px #767676),
  8px radius, paper background, ink text, 48px minimum.
- **Focus:** Blue border + 3px rgba(16, 0, 144, 0.14) ring; global :focus-visible is a 2px
  solid blue outline offset 2px (Verde Suave on dark).

### Basket Pill ("Productos a Cotizar")
Azul Calmo (#eeedf8) full pill, blue text, with a circular Azul Bodega count badge (24px
minimum, weight 800). The accessible text is the readable label («Productos a Cotizar 2»).

### Product Card (signature)
Compact A · Directa card: 1px Filigrana border, 12px radius, 16px padding, white ground.
Grid: 94px photo well (mobile; full-width ≥600px, aspect 1 / 1.35 / 1.55, object-fit
contain on paper/tint) + info column — green uppercase kicker (11px), 17px/750 ink title
that turns blue on hover, 13px chrome-muted spec line (Medidas → Material → Peso). Footer
row: native quantity stepper + Agregar. Added state: a green ✓ bar flush to the card
bottom («✓ N unidades agregadas» + Quitar). Hover: border shifts to #aca7d1 — no shadow.

### Empty & Status States
Tinted bordered panels (#f4f5f7, 1px blue-tinted hairline, 6–8px radius, 32px padding)
that name the situation in a heading and always offer a recovery link back to the catalog.
Status notes follow the same vocabulary (✓ confirmation included); failures never use red.

### Navigation (A · Directa chrome)
Compact sticky bar, 76/84px, white rgba(255, 255, 255, 0.97) with a 1px #dedfe6 bottom
hairline. Brand wordmark 18→23px blue weight 800; desktop links 14px/650 with a 2px blue
underline marking the current section; the basket pill and a 44px icon button (menu,
hidden ≥1000px) complete the row. Mobile menu: native `<dialog>` — full-height 12px-inset
sheet on phones, 400px right sheet ≥600px; a 520px help dialog shares the treatment
(16px radius, dialog overlay shadow).

### Footer
Light border-top band (Filigrana hairline): brand, tagline, help text-button (underlined
blue, 44px target), and a 12px chrome-muted legal line carrying the privacy link.

### Confirmation (Quote Request)
The system's moment of trust: a confirmation hero (40px padding, 38px H1) presenting the
permanent FP-YYYY-NNNNNN reference, persisted lines, stored dispatch fact, and next steps
— rendered only from the stored request; it never invents receipt.

## Do's and Don'ts

### Do:
- **Do** keep every hue inside Azul Bodega #100090, Verde Reciclaje #306020, and the
  neutral set; tints of those hues are the only stretching allowed.
- **Do** reserve blue for actions and links; let green accent (kickers, facts, the ✓ added
  state) — it never leads the CTA.
- **Do** keep every interactive target ≥44px with a visible 2px blue focus ring (offset
  2px; Verde Suave on dark).
- **Do** set quantities, measurements, and FP references in tabular-nums.
- **Do** use real product photography only (`assets/`, the 2026 catalog PDF); missing
  photos get the tint placeholder well, honestly labeled.
- **Do** write empty/error states as tinted bordered panels that name the situation and
  link the recovery path.
- **Do** keep journeys server-first: navigation and quoting must work without JavaScript.
- **Do** design at ~412px and adapt only with min-width media queries.

### Don't:
- **Don't** introduce new hues, display faces, or the v5 editorial vocabulary
  (Newsreader/Outfit, square geometry, hairline paper grid) — rejected and hash-frozen.
- **Don't** signal failure with red alone.
- **Don't** put resting shadows on controls or cards; depth is hairlines plus hover
  response (dialog overlays excepted).
- **Don't** invent prices, stock levels, testimonials, or product photos — none exist by
  design (PRODUCT.md).
- **Don't** port one-page prototype behaviors (scroll-spy, scroll-reveal, parallax hero) —
  they have no multi-page equivalent and were deliberately not carried over.
- **Don't** resurrect the v6 glass pill island or uppercase letter-spaced nav — the A ·
  Directa compact bar superseded them for shared chrome.
