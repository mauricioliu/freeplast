# Freeplast — propuesta de landing (skill `landing-page-design`)

Aplicación del sistema `elayadesign/ai-design-skills/skills/landing-page-design`
(Part A estrategia, Part B sistema visual). Restricción del encargo: **mantener la
paleta actual**, todo lo demás cambia, **el formulario de contacto es obligatorio**.

## A1 Intake (supuestos declarados, no se detuvo la construcción)

| Pregunta | Respuesta usada |
|---|---|
| Acción única primaria | Pedir cotización por formulario |
| Oferta | 12 productos de plástico reciclado, venta mayorista, mínimo 1 pallet |
| Conversión | Envío del formulario (o llamada/WhatsApp desde la misma sección) |
| ICP | Compras y jefes de bodega de agrícola, carne, mar, avícola y distribución |
| Problema | No saben si el molde, el pallet y el mínimo les sirven antes de llamar |
| Objeciones reales | Mínimo alto, precio sin IVA publicado, resistencia del material reciclado, despacho, stock |
| Tráfico | Búsqueda "caja plástica / cosechera / tote" + directo + WhatsApp → **se indexa** |
| Voz | Profesional directo, imperativo en "tú" (igual al sitio actual) |
| Móvil | Prioridad: se diseñó a 412 px primero |

**Prueba social:** no hay testimonios reales verificables. El skill prohíbe inventar
nombres ni marcas (B8), así que la sección de prueba se construyó con **datos reales
de ficha técnica** (peso, medidas, unidades por pallet, resistencia 18 kgs, mínimos).
Los espacios de testimonio quedan deliberadamente fuera: hay que pedirlos a clientes
reales antes de publicar.

## A2 Estructura de página

1. Nav isla flotante (B7)
2. Hero: headline + subheadline + CTA único + línea de prueba + foto real de pallet encintado
3. Barra de confianza (4 datos reales)
4. Problema → solución
5. Catálogo filtrable, 12 productos con ficha real y enlace a la ficha viva
6. Revelado de tagline (B11, obligatorio)
7. Cinco beneficios orientados a resultado
8. Cómo funciona, 3 pasos
9. Tabla comparativa de especificaciones (la "captura de producto" de este rubro)
10. Nosotros: misión y visión textuales del sitio actual
11. FAQ, 10 preguntas (objeciones)
12. Inversión de riesgo
13. Contacto: formulario + datos reales
14. Footer con enlaces legales

## A3 Layout elegido

**A. Hero clásico con secciones.** El producto se entiende con una foto. Se le agregó
la tabla de especificaciones como sustituto del screenshot de producto: en un
mayorista sin precios publicados, la ficha técnica **es** la demo.

## A4/A7 Conversión y SEO

- Un solo CTA arriba de la doblez: `Cotizar mi pallet`. El teléfono vive como dato, no como segundo botón.
- Números específicos por todas partes (840 g, 46 litros, 128 unidades, 1,00 x 1,20 m).
- Inversión de riesgo: cotización sin costo y sin compromiso.
- `index, follow` + FAQ en texto plano para AEO.

## B Sistema visual (lo que se aplicó)

| Regla | Decisión |
|---|---|
| B1 tipografía | **Manrope**, un solo tipo, pesos 400 a 700, sin itálicas, `tabular-nums` para datos (descarta Geist Mono, no hace falta) |
| B1 escala | Pasos Tailwind exactos: 12/14/16/18/20/24/30/36/48/60 |
| B2 espaciado | Tokens 0 2 4 8 12 16 24 32 40 48 64 80 96 |
| B3 radios | Tailwind 2/4/6/8/12/16/24/full con fórmula de anidado (interno = externo − gap) |
| B4 bordes/fondos | Bordes perimetrales o nada; fondos planos; banda oscura `#181818` (lista permitida) |
| B5 hero | Gradiente solo en el texto del H1: `#0B078C` → `#3A3A3A`. Adaptación del `#000 → #666` del skill a los neutrales de la paleta Freeplast, que es la restricción del encargo |
| B6 iconos | **Phosphor** (regular UI, bold features), sprite SVG local inline |
| B7 motion | `cubic-bezier(0.32,0.72,0,1)`, 700 ms; revelados con `IntersectionObserver`; nav isla + morph a X + revelado escalonado |
| B8 realismo | Sin Lorem Ipsum, sin marcas inventadas, sin números redondos falsos: los datos vienen de las fichas vivas de freeplast.cl |
| B9 estados | hover, active, focus ring visible, skeleton de catálogo, vacío de filtro, error inline, éxito |
| B10 ship | 404 propia, política de privacidad real, validación de formulario, skip link, favicon, meta y OG, alt, HTML semántico |

## Ajustes de layout que salieron del review en navegador

- **Tarjetas de producto en móvil**: en columna apilada la página medía 20.954 px de alto a
  412 px. Las tarjetas pasaron a fila compacta (foto 108 px a la izquierda, ficha a la derecha)
  por debajo de 600 px y la página bajó a 16.654 px. En escritorio siguen siendo tarjetas
  verticales de tres columnas.
- **Logo de la barra**: `logo.webp` recortado en círculo mostraba un pedazo ilegible de la
  palabra "free". Se reemplazó por `assets/mark.svg`, la marca de las dos flechas, que sí
  aguanta 26 px.
- **Revelado de tagline**: la primera versión usaba `IntersectionObserver` por palabra con una
  banda de disparo del 4 %. Saltando o con scroll rápido quedaban palabras apagadas para
  siempre. Ahora hay una línea de disparo fija en el 62 % del alto, offsets cacheados y
  repintado en el mismo frame del `requestAnimationFrame` del progreso.
- **Radios anidados (B3)**: `card`, `benefit` e `info` subieron a radio 24 px con 32 px de
  padding interno para que la fórmula `interno = externo − gap` no obligue a esquinas cuadradas.

## Paleta conservada (viene de `brand-spec.md`)

`#100090` azul primario · `#0B078C` azul de encabezado · `#306020` verde ·
`#558948` verde suave · `#17181C` tinta · `#3A3A3A` texto · `#FFFFFF` papel ·
`#F4F5F7` tinte. Sin tonos nuevos.

## Hallazgos del sitio actual (referencia capturada con Chrome)

- `ref-desktop-home.png`, `ref2-mobile-home.png`, `ref2-mobile-fold.png` en `/tmp`
- Fuente real de encabezados: **Arvo** 36 px, color `rgb(11,7,140)`; cuerpo system-ui
- Home = grilla de 12 tarjetas WooCommerce con "Rated 0 out of 5" en rojo en 11 de 12
- El menú "Tienda" apunta a `#` (enlace muerto); `/tienda/` es 404, la tienda vive en `/shop/` y su `<title>` es "¡Hola, mundo!"
- Móvil: el logo ocupa ~25 % del alto de la pantalla y no hay headline ni CTA sobre la doblez
- No hay formulario de contacto: la página actual solo da dirección, teléfono y correo

## Pendientes antes de publicar

1. Conectar el `ENDPOINT` de `script.js` al correo o CRM de ventas.
2. Reemplazar la nota "propuesta" del footer.
3. Conseguir 2 o 3 testimonios reales de clientes (con nombre y empresa verificables).
4. Definir si se publican precios o se mantiene solo cotización.
