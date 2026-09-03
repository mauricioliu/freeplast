# Freeplast — propuesta 04 «Catálogo editorial»

Cuarta dirección de diseño para freeplast.cl, construida con el skill `ui-ux-pro-max`
(`--design-system`, variance 8, motion 4, density 5). Las tres anteriores cubren orgánico
suave, landing conversiva plana y spec-sheet industrial oscuro. Esta apuesta por un
**catálogo suizo / editorial**.

## De dónde sale

El skill devolvió Brutalism + Inter + acento rosa. Se rechazó:

- Inter (prohibido por `brand-spec.md`)
- Rosa `#EC4899` (hue nuevo; la paleta está cerrada)
- Anti-diseño crudo (no le sirve a un mayorista de cajas)

Se conservó: geometría viva, retícula visible, tipo sobredimensionado, catálogo
asimétrico, patrón Hero + Features + CTA, un solo acento en la acción primaria.

Tipografía tomada de la búsqueda de dominio `typography` («Bauhaus Geometric» +
«News Editorial»): **Newsreader** en titulares, **Outfit** en UI. Paleta tomada de
`brand-spec.md`, no del CSV.

Sistema persistido en `design-system/freeplast-editorial/MASTER.md`.

## Concepto

La página se lee como un número de catálogo impreso:

1. Franja verde de 4 px (el verde del logo, no la barra de contacto del sitio vivo)
2. Hero de papel: titular serif enorme, dos CTAs (formulario + teléfono)
3. Tres hechos reales, no testimonios inventados
4. Lámina 01 (Caja Universal) + mosaico de 12 productos con hendidura azul de 1 px
5. Misión / visión textuales del sitio actual + foto de bodega
6. Cierre azul profundo con formulario obligatorio

## Restricciones del encargo

- Paleta actual, nada más
- Formulario de contacto obligatorio
- No tiene que ser WooCommerce
- Móvil primero (~412 px)

## Formulario

Campos: nombre*, email*, teléfono, producto*, cantidad, mensaje.
Cada placa del catálogo preselecciona el producto.
Validación local con errores inline **y** resumen enlazado con foco.
No simula envío de red.

## Pendiente

No está validada en hardware. Lo observado al construir es el código y las capturas
de referencia del sitio vivo (desktop Browser MCP + móvil 412×915 emulado).
