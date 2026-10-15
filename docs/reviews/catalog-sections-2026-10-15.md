# Catálogo por secciones (DEV, 2026-10-15)

## Solicitud y evidencia

Referencia del dueño: `/home/mauricio-liu/Videos/screenrecording-2026-10-15_13-18-35.mp4` (18,27 s). Inspección dirigida local con `watch-local`, ID `28e5896f461cdde5f17c`, intervalo 0–18,26 s: 19 candidatos, 11 fotogramas seleccionados, sin voz. Se revisaron además los fotogramas de Agrícola y Carnes. La referencia muestra Agrícola (~3–6 s), Carnes (~6–11 s), Productos del mar (~11–13 s) y Otros (~13–14 s). Caja Universal pertenece a las tres primeras secciones. No se ejecutaron acciones del sitio de producción.

## Implementación

- Catálogo usa `details/summary` nativo en PC y móvil: click, teclado y fallback sin JS; mejora de hover con mouse en PC, Escape, cierre al salir del foco y click fuera.
- Cinco destinos: Ver todo y cuatro categorías. URLs desde términos nativos, sin asumir el prefijo de enlaces permanentes.
- Cada página usa nombre y descripción del término nativo; descripción única, búsqueda, ordenamiento y filtros existentes preservados. No hay otro catálogo ni productos duplicados.
- Las descripciones breves fueron redactadas para esta implementación, no transcritas del video; quedan editables en las categorías de WooCommerce.
- No se cambia el JSON de bootstrap como segunda autoridad ni se repite seed. La clasificación editable queda en los términos nativos de DEV.

## Actualización DEV

Helper local explícito: `wordpress/local/catalog-sections.php` (`plan` / `apply`), guardado por WP-CLI, entorno local, URL exacta, captura privada activa y procedencia `/opt/freeplast`. Apply exige respaldo verificado. Valida todos los productos sembrados antes de escribir, conserva categorías personalizadas ajenas a las cuatro secciones y descripciones editoriales existentes no vacías. Crea un recibo privado del estado previo antes de modificar términos y relaciones. No instala un migrador automático en staging/producción.

Respaldo privado de BD, SHA-256 comprobado antes de apply:
`~/.local/state/freeplast-local/backups/catalog-sections-20261015T132329/`.

Recibo privado dentro del volumen de captura:
`/var/freeplast/catalog-sections/20261015-162418-e0b8d52b.json`.

17 productos conservados. Distribución DEV:

- Agrícola (13): cinco cajas agrícolas existentes, cuatro universales, tote y tres traversas.
- Carnes (5): cuatro universales y pollera.
- Productos del mar (5): cuatro universales y merlucera.
- Otros (2): bases para pediluvios y ladrillo plástico.

Los conteos se superponen, no se suman para calcular Todos (17). Las variantes y cantidades mínimas no se modifican. Staging y producción intactos.

## Verificación

- `php wordpress/scripts/catalog-tools-test.php`: 24 checks de formularios, URLs, términos y SQL.
- `php wordpress/scripts/catalog-native-frame-test.php`: 52 checks del camino ClassicTemplate y fallback PHP.
- `runChromeNavTests()` en `wordpress/scripts/chrome-nav-test.mjs`: 41 checks, incluidos dropdown, Escape, hover y equivalencia móvil/PC.
- HTTP DEV de las cuatro categorías: 200, título correcto, una descripción y cantidad de tarjetas esperada (13/5/5/2).
- Chrome real, 1440×960 y 390×844: menú desplegado, categoría activa, enlaces y ausencia de overflow horizontal. Capturas en `/tmp/freeplast-submenu-desktop.png` y `/tmp/freeplast-submenu-mobile.png`.
- No se enviaron solicitudes ni correos en estas verificaciones. La aceptación visual del dueño permanece separada de estas comprobaciones.
