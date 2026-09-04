# Freeplast v6 — WordPress implementation brief

Behavioral brief for the pages governed by the frozen v6 design contract
(`design-tokens.json`, `DECISIONS.md`). Visual tokens live there; this file
records what each route must do. Approved 2026-09-03 (issue #12).

## Composition

Mobile-first (~412 px design width), adaptation only through `min-width`
media queries. Shared chrome on every route: island header (logo, INICIO,
NOSOTROS, TIENDA, CONTACTO, Cotización count/mini basket, burger), the v6
dark footer, and a working skip path for keyboard users.

## Routes

| Route | Behavior |
| --- | --- |
| `/` | v6 hero (current copy), eight Featured Products (plugin block), concise Nosotros section, Cotiza Online **basket summary/CTA** into `/cotizacion/` (never a second submission form), concise contact section. |
| `/nosotros/` | Current mission and vision as editable WordPress page content; baseline layout from the theme template, never from Site Editor overrides. |
| `/tienda/` (+ `/categoria/<c>/`) | Full catalog grid + URL-backed Todos/Agrícola/Otros filters (plugin). |
| `/producto/<slug>/` | v7 variant A hierarchy with v6 chrome (plugin-rendered). |
| `/cotizacion/` | The sole quotation surface: basket view (read-only until #7/#8). |
| `/contacto/` | Current phone, email, WhatsApp, warehouse/map, hours + exactly one CTA into Cotización. No inquiry record, no form. |
| `/politica-de-privacidad/` | Basic collection/submission disclosure. No acknowledgement checkbox. |
| search / 404 | Usable navigation and empty states that recover into the catalog. |

## Interaction intent

- Everything primary is a server-rendered link or authoritative POST;
  JavaScript only enhances (burger sheet, basket mirrors).
- Empty/error states are tinted, bordered, named, and offer recovery.
- Focus is always visible; motion respects `prefers-reduced-motion`.
- The header basket count and mini basket are accurate on every route.
