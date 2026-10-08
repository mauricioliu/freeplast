# Auditoría visual desktop — 2026-10-08

Baseline `2e383153fdc728fcfc1b1ce682a36600a7e7142f` (limpio). Sitio: WordPress activo `freeplast.mliu.site` (solo GET público) + preview aislado del lead `127.0.0.1:8094` (SQLite, catálogo sintético 17 refs, carrito bloque, checkout clásico, mail/red/procesos PHP bloqueados). Sin deploy, commit ni push. **UI modificada ≠ aceptación final**: el lead debe re-validar en emulación; sin validación de hardware (todo es emulación Chrome).

Evidencia: `.scratch/herd-web-design/evidence/` (`*-before-*`, `*-after-*`, comparativas `cmp-*.png`, `before-metrics.txt`, `after-metrics.txt`, `cart-name-cascade.txt`, `checkout-geometry.txt`). Copias exactas pre-edición + sha256: `.scratch/herd-web-design/baseline/` (`files.json`, `new-files.json`, `HEAD`, `status` vacío = árbol limpio).

## Findings

| # | Grav. | Ruta | Viewport | Evidencia (before → after) | Causa | Fix | Archivo |
|---|---|---|---|---|---|---|---|
| 1 | Alta | `/?s=<sin resultados>`, categoría vacía | 1280/1440/1920 (768 parcial) | `cmp-empty-search-1440.png`, `local-empty-search-{before,after}-*-full.png` | Conteo y `orderby` nativos flotan sin contenedor; el panel vacío se mete entre ellos y queda centrado/estrecho, solapando la fila | Envolver conteo+orderby en `.results-toolbar` (mismo contenedor que el listado poblado) antes del `.empty-state` | `woocommerce/loop/no-products-found.php` |
| 2 | Media | `/cotizacion/` carrito vacío | todos | `cmp-empty-cart-1440.png`, `cmp-empty-cart-mobile.png` | Reglas `.empty-state` limitadas a `body.woocommerce`; la página carrito sólo tiene `.woocommerce-cart` → estado vacío sin panel (viola DESIGN.md: "tinted bordered panels for every empty state") | Selector `body:is(.woocommerce, .woocommerce-cart)`; `overflow-wrap:anywhere` en h2 (query larga) | `style.css` |
| 3 | Media | `/cotizacion/` con líneas | todos (más visible desktop) | `local-basket-before-1440.png` vs `local-basket-after-1440-full.png`; cascada en `cart-name-cascade.txt` | `cart.css` de Woo (`table.wc-block-cart-items … .wc-block-components-product-name`, 14px/500) gana en especificidad a `.wc-block-cart-item__product a` (17–18px/750) del tema; padding de `td` también lo ganaba Woo | Selectores del tema con especificidad suficiente apuntando a `.wc-block-components-product-name` y `td` (mismos valores ya declarados) | `assets/css/woo.css` |
| 4 | Media | `/cotizacion/`, `/datos-y-envio/` | todos | `cmp-basket-1440.png`, `cmp-checkout-1440.png` | Stepper pegado al hairline del header sticky (0px) | `padding-top:24px` en `.fp-cart-page:has(> .fp-steps)` y `.fp-checkout-page` | `style.css` |
| 5 | Media | `/datos-y-envio/` | ≥1000 (desktop) | `local-checkout-before-1440.png` vs `local-checkout-after-1440.png`, `checkout-geometry.txt` | Radios de despacho con `justify-content` heredado → texto empujado a la derecha, lejos del radio | `justify-content:flex-start` (selector con especificidad del form) | `style.css` |
| 6 | Baja | `/datos-y-envio/` | ≥1000 | mismo par; `#order_comments_field` medía 384px | Mensaje (opcional) ocupaba media columna dejando hueco | `#order_comments_field` a `grid-column:1/-1` en la media query desktop existente | `style.css` |
| 7 | Baja | `/datos-y-envio/` resumen | ≥1000 | mismo par | Tabla nativa de revisión con borde exterior de Woo dentro del panel tintado (doble caja) | `border:0` en la tabla | `style.css` |
| 8 | Media | `/datos-y-envio/` tras envío inválido | todos | `cmp-errors.png` (`local-errors-{before,after}-{1440,390}.png`, after 1280) | Errores en rojo `#a84337/#742c25` + panel rosado: viola "No-Red-Alarm Rule"/"No-New-Hues" de DESIGN.md | Resumen: panel `--fp-tint` + borde `--fp-line-strong` + tinta; inline: borde 2px y texto `--fp-blue`; labels inválidos en tinta. Texto/ARIA/validación sin tocar | `style.css` |
| 9 | Media | `/producto/caja-universal-cerrada-color/` (variable) | todos | `cmp-variable-1440.png`, `local-variable-selected-after-1440.png` | Con selector de color mejorado la tabla nativa `.variations` queda con todas sus filas `hidden` pero conserva margen + gap flex → hueco muerto antes de cantidad | `display:none` sólo si `:has([data-fp-color-superseded])` y ninguna `tr` visible; sin JS o con otro atributo nativo la tabla sigue visible (cubierto por test). Medido: fieldset→cantidad 26px | `style.css` |
| 10 | Info | caché | — | `?ver=1.0.21` ya desplegado | CSS cambia con la misma versión → visitantes con caché verían CSS viejo | Bump coherente tema **1.0.22** (header + `FREEPLAST_THEME_VERSION`; test existente exige igualdad) | `style.css`, `functions.php` |

Sin hallazgos que exigieran cambios (GET público + preview, 1280/1440/1920): header/nav sticky, home, catálogo poblado y filtros de categoría, búsqueda con resultados, ficha simple, footer, páginas Nosotros/Contacto/Cómo cotizar (`cmp-public-desktop.png`; imágenes en blanco del catálogo = lazy-load en captura full-page). Overflow horizontal: `scrollWidth == innerWidth` en todas las rutas/anchos medidos (`before-metrics.txt`, `after-metrics.txt`); `img.zoomImg` del zoom de Woo aparece como candidato pero queda contenido (sin scroll horizontal).

## Matriz desktop/móvil (emulación Chrome)

| Cambio | 1280 | 1440 | 1920 | 768 | 412 | 390 | 360 | Impacto móvil |
|---|---|---|---|---|---|---|---|---|
| 1 empty search toolbar | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | Igual estructura; toolbar arriba del panel (antes ya apilado) |
| 2 empty cart panel | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | Intencional: ahora panel tintado también en móvil (antes texto suelto); h2 parte en 2 líneas a 360 |
| 3 nombre línea carrito | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | Intencional: nombre 17px/750 (valor ya declarado por el tema); "Caja Universal Cerrada Color · Azul" pasa a 2 líneas a 360–412 |
| 4 stepper +24px | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | +24px arriba; corrige el mismo pegado en móvil |
| 5 radios despacho | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | Texto junto al radio también en móvil |
| 6 mensaje ancho completo | ✔ | ✔ | ✔ | n/a | n/a | n/a | n/a | Sólo media query desktop |
| 7 tabla resumen sin borde | ✔ | ✔ | ✔ | n/a (resumen colapsado) | n/a | n/a | n/a | — |
| 8 errores no-rojo | ✔ | ✔ | — | — | — | ✔ | — | Mismo tratamiento; validado 1280/1440/390 |
| 9 hueco variable | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | Menor hueco; sin pérdida de controles |

✔ = captura after revisada (`local-*-after-*-full.png`, comparativas `cmp-*-mobile.png`). Before de carrito poblado a móvil fue capturado en skeleton de carga (evidencia débil); after sí muestra líneas.

## Tests

- Nuevo `wordpress/scripts/desktop-visual-test.mjs` (14 contratos CSS/selector con jsdom, incluido que la tabla de variaciones no se oculte sin JS ni con otro atributo), enganchado en `check-woo.mjs`.
- `catalog-native-frame-test.php`: el vacío agrupa conteo+orden en `.results-toolbar` antes del panel (50 checks).
- Focalizados PASS: desktop-visual 14, native frame 50, `test-woo-adapter.php` 513 (versión header==constante).
- `FREEPLAST_SKIP_STACK=1 npm test` PASS (1896; log `.scratch/herd-web-design/npm-test-final.log`); `git diff --check` limpio. Stack nativo no levantado.

## Comprobación independiente del lead

Tras autorización del propietario para publicar con nivel LOGIC, el lead reejecutó `FREEPLAST_SKIP_STACK=1 npm test`: PASS 1896; `git diff --check` limpio. Revisó las comparativas móvil de checkout/carrito y capturas frescas del preview: checkout 1440/390, carrito poblado 390 y búsqueda vacía 1440/360. Sin overflow en los viewports medidos; CSS servido con `?ver=1.0.22`, Mensaje a 786px en desktop, radios `flex-start`, nombres de carrito 17px/750 en móvil, toolbar arriba del panel vacío. Evidencia `.scratch/herd-web-design/lead-evidence/`. Aceptación acotada de estos ajustes en emulación, no certificación integral ni hardware. La suite nativa completa y ceremonia de publicación siguen siendo gates separados.

## Pendientes / riesgos

- Aceptación visual final y validación independiente del lead pendientes; sin teléfono físico.
- Capturas full-page: el header sticky aparece a mitad de página y las imágenes lazy/galería salen en blanco (artefacto de captura; verificado en viewport que cargan).
- Página de confirmación de solicitud (paso 3) no cubierta: requeriría envío válido; no se envió ninguna solicitud válida.
- Estados hover/focus revisados sólo puntualmente (foco del resumen de errores: regla `:focus-visible` intacta; clic con mouse no la activa).
- Observado sin tocar (fuera de alcance o pre-existente): en móvil 360 el CTA del header "Productos a Cotizar" parte en 2 líneas; en carrito desktop la nota "Las cantidades se actualizan…" queda lejos bajo la lista; el dock CTA fijo móvil del carrito se superpone en capturas full-page.
- Versión tema 1.0.22 preparada, no desplegada; el release lo gestiona el lead (no se invocó wp-release).
- Navegador: el bridge reinició a mitad de sesión y perdió cookie del carrito; se reconstruyó carrito sintético en 8094 (2 líneas, 10 u. c/u). Sólo se usaron pestañas propias del preview/sitio público.
