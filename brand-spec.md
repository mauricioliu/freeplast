# Freeplast — Brand Spec (captured 2026-02, from freeplast.cl + local assets)

## Identity assets (real, local — reference these, never redraw)

| Asset | Path | Notes |
|---|---|---|
| Logo (webp) | `assets/logo.webp` | "free" blue + "plast" green, two circular recycle arrows (blue ↻ green). 365×227 |
| Logo (jpg) | `assets/logo.jpg` | Same mark, JPEG |
| Caja Universal | `assets/universal.webp` | Black recycled crate, 3/4 view on white |
| Caja Cosechera | `assets/cosechera.webp` | |
| Caja Merlucera | `assets/merlucera.webp` | |
| Tote | `assets/tote.webp` | |
| Traversa UPC | `assets/traversa-upc.webp` | |
| Traversa G1 | `assets/traversa-g1.webp` | |
| Ladrillo plástico | `assets/ladrillo.webp` | |
| Warehouse | `assets/warehouse.webp` | Context shot (nosotros) |

Live logo URL: https://freeplast.cl/wp-content/uploads/2020/07/cropped-logo-freeplast.jpg

## Color tokens (sampled from live site + logo pixels)

| Role | Value | Source |
|---|---|---|
| Brand blue (primary) | `#100090` | Logo "free" + arrows (pixel sample #100090/#100080) |
| Brand blue (heading) | `#0B078C` | Live `h2` computed color rgb(11,7,140) |
| Brand green (accent) | `#306020` | Logo "plast" + arrows (pixel sample #306020/#2c5c22) |
| Green (soft) | `#558948` | Prior draft tint of logo green |
| Ink / product black | `#17181C` | Crates are near-black plastic |
| Text | `#3A3A3A` | Live theme body text |
| Paper | `#FFFFFF` | Live body background |
| Paper (tint) | `#F4F5F7` | Live section background |

Rule: every hue in the new design derives from blue #100090 / green #306020 / neutrals above (oklch tints allowed, no new hues).

## Typography (live site)

System stack (`-apple-system…`), no brand typeface → free to introduce one. Display must not be Inter/Roboto/system-ui.

## Content contracts (preserve)

- Voice: "Plástico que vuelve a servir" · "Venta mayorista de productos plásticos" · "Estamos en la VI Región y en Santiago. Servicio rápido y confiable"
- Products (12): Tote · Caja Cosechera 3/4 · Caja Merlucera · Caja Universal · Traversas Bins UPC · Traversas Bins G1 · Caja Tomatera · Caja Pollera · Bases para pediluvios · Caja Frutillera · Caja Frutera · Ladrillo plástico
- Contact data (real): Camino El Arrayán 52, San Francisco de Mostazal, VI Región · +56 9 6844 4265 · ventas@freeplast.cl
- Live site has NO contact form (only address/phone/email) → the proposal's form is an addition, fields: nombre, email, teléfono, producto, cantidad, mensaje.

## References captured

- `/tmp/ref-desktop-home.png` (browser MCP), `/tmp/ref-mobile-home.png`, `/tmp/ref-mobile-contacto.png` (412×900, chrome-devtools-axi)
- Live stack: WordPress + Astra + WooCommerce + Elementor (home = product grid only; contacto = photo hero + 3 info blocks)
