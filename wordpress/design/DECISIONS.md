# Freeplast — frozen v6 design contract decisions

Recorded 2026-09-03 (issue #12). This file freezes the approved visual
contract the WordPress theme implements. `design-tokens.json` is the
machine-readable extraction; this file records the sources, the hashes and
the adaptation decisions. The obsolete v5 editorial direction
(`propuesta-editorial/`, `design-system/freeplast-editorial/`,
https://mliu.site/freeplast/v5/) is **rejected** — none of its rules
(Newsreader display face, Outfit UI face, square geometry, hairline-rule
paper grid) may be used by the theme.

## Approved sources (immutable, hash-frozen)

| Reference | Repository prototype | Published | SHA-256 |
| --- | --- | --- | --- |
| v6 storefront | `propuesta-textos-originales/index.html` | https://mliu.site/freeplast/v6/ | `c464ac476f52a7cba2dc4c34ee2dbbbad0a3092a02e14f6570b2b6a76e08b855` |
| v6 storefront styles | `propuesta-textos-originales/styles.css` | (same) | `fd2dc626cecb8224c373444cbc991055d7c73ceef32e9cda1f68eb1c19c84e86` |
| v6 storefront script | `propuesta-textos-originales/script.js` | (same) | `95380ef9171261a043d36c3eb4dcf97be3aeaf7efde0b6a5a4fea2698748329d` |
| v7 product page (variant A) | `product-page-prototype/index.html` | https://mliu.site/freeplast/v7/?variant=A | `a3ecf4186c43d349d546538cb2860036eef7f89935eac223432547e7d413efa3` |
| v7 product page styles | `product-page-prototype/styles.css` | (same) | `21b997dc70604a346f6c212dccca63017932b636807747694427765ef2d27247` |
| v7 product page script | `product-page-prototype/script.js` | (same) | `0bf3ffcd2dccc0a50a3890559d35a856d7a604c520c1270260cba2c1e3fff0c4` |

`npm test` recomputes every hash on each run: drift in an approved
prototype is a failure, not a silent decision. Variants B/C of the product
page, prototype switching, fake submission and review-artifact links are
not part of the contract.

## Governing decision — “v6 tokens, v7-A structure”

- **Storefront**: the v6 flat direction — Manrope, preserved Freeplast
  palette (blue `#100090` primary, green `#306020` accent), soft radii,
  island navigation, tint bands, dark footer. See `design-tokens.json`.
- **Product page**: approved v7 variant A hierarchy — breadcrumb, gallery +
  summary, description, quick specs, quote action, specification table,
  related products — presented with the same v6 site chrome and tokens.
- **Mobile-first**: designed around ~412 px; adaptation only through
  `min-width` media queries (768 / 1024 px in the theme).

## Theme adaptations (recorded, not drift)

The WordPress theme is a server-rendered block theme, so a few prototype
behaviors adapt by design. Each adaptation is v6-derived:

1. **Primary control color is blue.** v6's `.btn` is `#100090` with a
   `#0b078c` hover; green (`#306020`) is the accent (kickers, facts,
   mission/vision labels), available as the `.btn-green` variant. The
   earlier green-rectangular-CTA styling came from the obsolete v5 TARGET
   contract and is corrected in this slice.
2. **Sticky island, not fixed.** The v6 floating pill island
   (`border-radius: 9999px`, `rgba(255,255,255,.82)` glass + backdrop blur)
   is kept, but `position: sticky` replaces `position: fixed` so pages do
   not need prototype hero top-padding compensation.
3. **Compact catalog cards use the nested-radius principle.** v6 pads cards
   above their nested radii; WordPress cards embed quantity-chooser forms,
   so they use the v6 `--r-lg` (8 px) radius and compact paddings instead
   of `--r-3xl` (24 px) cards.
4. **One-page scroll behaviors are not ported.** Scroll-spy, scroll-reveal,
   the progress bar and the pointer/momentum hero of the single-page
   prototype have no multi-page equivalent; the only progressive script is
   the burger sheet (theme) and the basket enhancement (plugin).
5. **Dark surfaces use the v6 dark tokens.** Footer and dark bands use
   `#181818` (`--dark`) with `--muted-dark` text.

## Pages governed by the contract

Home (hero, concise Nosotros, eight Featured Products, Cotiza Online basket
summary/CTA, concise contact), Nosotros, Tienda (+ category filter), product
detail (v7-A), Cotización (the sole quotation surface), Contacto (details +
one CTA into Cotización, no inquiry record), Política de privacidad, search
and 404 — each with the shared island header, basket widget and footer.
