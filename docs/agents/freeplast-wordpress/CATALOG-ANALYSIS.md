# Catálogo Freeplast 2026 — extraction and reconciliation

Analysis of [`docs/Catálogo Freeplast 2026.pdf`](../../Cat%C3%A1logo%20Freeplast%202026.pdf), performed 2026-09-03.

## Source characteristics

- 20-page, image-only PDF created 2026-08-19; it contains no extractable text layer.
- Pages 2–5 provide category overviews; pages 6–20 provide one specification sheet per product.
- The values below were recovered through OCR and checked visually against the page images.
- The PDF provides no prices despite its cover line “Mejores productos y a mejores precios.”
- “Unidades por pallet” is recorded as packaging information only. It must not be treated as a minimum or quantity step until Freeplast confirms that commercial rule.

## Recoverable catalog

| Product | Material | Dimensions | Weight / capacity | Other supported facts | Units per pallet |
|---|---|---|---|---|---:|
| Tote | PEAD recycled | Exterior 500 × 280 × 190 mm; interior 475 × 260 × 175 mm | 800 g; 21 L | Black; cherry and other fruit harvest; pallet 1.00 × 1.20 × 2.30 m | 120 |
| Caja Cosechera 3/4 | Recycled HDPE (overview page) | Exterior 600 × 400 × 180 mm (overview); interior 570 × 370 × 170 mm | 1,250 g | Black; stackable; fruit, grape and avocado harvest; pallet 1.00 × 1.20 × 2.60 m | 70 |
| Caja Universal Cerrada Negra | Recycled PEAD | Exterior 625 × 444 × 226 mm | 1,480 g; 46 L | Black; nestable and stackable; logistics, seafood, cold cuts and meat; pallet 1.00 × 1.20 × 2.10 m | 100 |
| Caja Universal Cerrada Color | Virgin PEAD | Exterior 625 × 444 × 226 mm | 1,520 g; 46 L | White, red, yellow, blue and green; nestable and stackable; logistics, seafood, cold cuts and meat; pallet 1.00 × 1.20 × 2.10 m | 100 |
| Caja Universal Ventilada Negra | Recycled PEAD | Exterior 625 × 444 × 226 mm | 1,800 g; 46 L | Black; nestable and stackable; ventilated uses including seafood, cold cuts and meat; pallet 1.00 × 1.20 × 2.10 m | 100 |
| Caja Universal Ventilada Color | Virgin PEAD | Exterior 625 × 444 × 226 mm | 1,870 g; 46 L | White, red, yellow, blue and green; nestable and stackable; ventilated uses including seafood, cold cuts and meat; pallet 1.00 × 1.20 × 2.10 m | 100 |
| Caja Tomatera | PP | Exterior 470 × 340 × 270 mm; interior 450 × 320 × 260 mm | 840 g; 38 L | Black; stackable; fruit and vegetables; pallet 1.00 × 1.20 × 2.80 m | 128 |
| Caja Frutillera | Recycled PP | Exterior 500 × 300 × 110 mm; interior 480 × 280 × 85 mm | Approx. 300 g | Black; strawberry harvest; pallet 1.00 × 1.20 × 2.50 m | 200 |
| Caja Frutera | Recycled PEAD | Exterior 600 × 400 × 210 mm | 1,450 g | Black; fruit and vegetable transport; pallet 1.00 × 1.20 × 2.80 m | 65 |
| Caja Paltera | Virgin PEAD | Exterior 500 × 300 × 150 mm | Approx. 330 g; 30 L | Transparent; foldable; pallet 1.00 × 1.20 × 2.10 m | 540 |
| Traversa Tipo G1 | Recycled PP | Length 1,215 mm; height 136 mm | 2,520 g | Black; replacement of damaged bin traverses; pallet 1.00 × 1.20 × 1.90 m | 132 |
| Traversa para Bins Tipo Romano | Recycled PP | Length 1,220 mm; height 107 mm | Not stated | Black; replacement of damaged bin traverses; pallet dimensions not stated | 200 |
| Traversa Tipo UPC | Recycled PP | Length 1,220 mm; height 129 mm | 2,050 g | Black; replacement of damaged bin traverses; pallet 1.00 × 1.20 × 1.90 m | 132 |
| Caja Pollera | Recovered HDPE | Exterior 600 × 400 × 180 mm; interior 570 × 370 × 170 mm | Approx. 1,350 g | Black; stackable; chicken, meat and cold-cut transport; pallet 1.00 × 1.20 × 2.80 m | 90 |
| Caja Merlucera | Recycled PEAD | Exterior 660 × 445 × 145 mm | 2,260 g; 40 L | Black; stackable; fish and other seafood transport; pallet 1.00 × 1.20 × 2.30 m | 75 |

## Reconciliation with the current website

The current WordPress endpoint exposes 12 products. Ten correspond to products in the PDF:

- Tote
- Caja Cosechera 3/4
- Caja Universal (corresponding to Cerrada Negra)
- Caja Tomatera
- Caja Frutillera
- Caja Frutera
- Caja Pollera
- Caja Merlucera
- Traversa Tipo G1
- Traversa Tipo UPC

The PDF adds five independently presented products:

- Caja Universal Cerrada Color
- Caja Universal Ventilada Negra
- Caja Universal Ventilada Color
- Caja Paltera
- Traversa para Bins Tipo Romano

The website has two products absent from the 2026 PDF:

- Bases plásticas para pediluvios
- Ladrillo plástico

The owner decided not to retire either existing product yet. Both remain publicly visible with their current limited content and “Consultar” for missing details. The working Catalog therefore contains the union of both sources: **17 products**, pending client clarification of the two legacy products and the G2/Romano naming conflict.

The PDF resolves the website's Caja Merlucera material conflict in favor of **recycled PEAD**. It also supplies dimensions, capacity, weight, color, pallet quantity and pallet dimensions absent from the website excerpt.

## Remaining source conflicts and gaps

1. The overview calls one traverse **“Traversa Tipo G2”**, while its detailed sheet calls it **“Traversa para Bins Tipo Romano.”** These may be aliases or different products.
2. The PDF does not state quote minimums or quantity steps. Pallet quantities cannot safely be promoted to sales rules without confirmation.
3. The Tipo Romano sheet omits weight and pallet dimensions.
4. The PDF does not provide stable source IDs, canonical slugs, alt text, related-product choices, or full customer-facing descriptions.
5. Product photography is embedded in composed, watermarked PDF pages. Original image files are preferable for the website.
6. The PDF and old website disagree on some measurements and wording. As the newer business artifact, the PDF can resolve these only if the owner confirms that it supersedes the old website.

## Proposed reconciliation rule

Treat the 2026 PDF as the business source for structured specifications and new products. Retain the two old-site-only products until the client explicitly retires them. Use the old website as migration provenance and as a source of usable prose only where it does not contradict the PDF. Record every intentional text correction in the reviewed Catalog Source rather than silently preserving or silently fixing old-site errors.
