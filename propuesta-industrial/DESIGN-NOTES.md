# Freeplast — propuesta «Catálogo Industrial»

Tercera dirección de diseño para freeplast.cl, construida con el skill `ui-styling`
(Tailwind CSS + lenguaje de componentes shadcn). A diferencia de las dos propuestas
anteriores (orgánica suave y landing conversiva plana), esta apuesta por un lenguaje
**industrial editorial con ADN de ficha técnica**.

## Concepto

El plástico negro reciclado es el protagonista: el sitio usa campos de color de marca
como movimientos escénicos —

1. **Hero azul profundo** (`#09043F`/`#100090`) con retícula blueprint y headline
   monumental en Archivo Expanded (contorno tipo plano técnico)
2. **Catálogo blanco** — 12 fichas con datos reales (material, medidas, peso,
   unidades por pallet, mínimo) tomados de las fichas vivas del sitio
3. **Movimiento verde** (`#306020`, el verde del logo a campo completo) — el ciclo
   circular en 3 pasos
4. **Contacto azul profundo** — formulario de cotización obligatorio + datos reales

## Decisiones de sistema

| Decisión | Valor |
|---|---|
| Tipografía display | **Archivo** variable (wdth 62–125), expanded + uppercase para títulos |
| Tipografía datos | **IBM Plex Mono** — etiquetas, fichas, botones (aire de spec sheet) |
| Radios | 2 px — esquinas casi vivas, opuesto a las propuestas redondeadas previas |
| Paleta | Solo tokens de `brand-spec.md`: `#100090 #0B078C #306020 #558948 #17181C #3A3A3A #FFFFFF #F4F5F7` + derivaciones ya validadas (`#09043F #0D075D #E9E7FB #EDF4EA`) |
| Fotografía | Las fotos de producto (fondo blanco) se enmarcan como «láminas de catálogo»: placa blanca con borde, esquinas de registro verdes y etiqueta mono — el fondo blanco se vuelve decisión, no defecto |
| Logo en oscuro | Versión monocroma blanca (`brightness-0 invert`) — el logo tiene fondo transparente |
| Motion | Marquee CSS lenta, revelados con IntersectionObserver, todo bajo `prefers-reduced-motion` |

## Implementación

- **Tailwind CSS v4** vía CDN (`@tailwindcss/browser@4`) con tokens en `@theme`
  (`--color-fp-*`, `--font-display`, `--font-mono`) y utilidades propias
  (`font-wide`, `bg-grid-dark`, `animate-marquee`)
- Un solo archivo autocontenido: `index.html` (JS inline: menú móvil, producto→formulario,
  revelado, validación accesible con `aria-invalid`/`aria-describedby`/`role="status"`)
- Diseñado primero a **412 px** (mobile); desktop es la adaptación

## Formulario (obligatorio del encargo)

Campos: nombre*, email*, teléfono, cantidad (u), producto* (los 12 + consulta general),
mensaje. Los botones «Cotizar» de cada ficha preseleccionan el producto.
La validación es local y **no simula envío de red** — conectar el `submit` con el
endpoint de ventas antes de publicar.

## Verificado con capturas de referencia (Chrome, 412×915 y 1440×900)

Hero, lámina con anotaciones, catálogo, sección verde, nosotros, contacto/formulario,
estados de error, preselección de producto y menú móvil. Sin errores de consola.

**Pendiente de validación humana en hardware real** (teléfono compartido — no es paso
de este agente). Esta propuesta no está «validada»: lo anterior solo describe lo
observado en el navegador.

## Previsualizar

```bash
python3 -m http.server 4188 --bind 0.0.0.0   # desde la raíz del repo
# → http://mliu:4188/propuesta-industrial/
```
