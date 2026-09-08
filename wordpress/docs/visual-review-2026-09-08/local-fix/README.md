# Corrección local posterior a la revisión del dueño

El dueño confirmó haber visto los problemas y pidió corregirlos. Tema local
**1.0.15**, basado en `0713770`. **Sin commit, push ni despliegue.** Staging
continúa en 1.0.14 hasta una operación de publicación posterior.

## Causas y cambios

- **V01/V02 — Tarjetas:** Woo conserva `width:48%` en móvil y porcentajes por
  número de columnas en relacionados. Dentro de CSS Grid estos porcentajes
  se aplicaban de nuevo a cada pista. Se incrementó la especificidad **solo**
  del reset width/float/margin, dejando intactos los cambios de composición
  en 600/1000. La regla común cubre catálogo, Home y relacionados.
- **V03 — Cotización:** anchos nativos 65%/35% comprimían las columnas; el
  reset móvil eliminaba padding. Además **Quotes** declara `position:absolute;
  top:100%; right:40%` desde 700 px: incluso con ancho corregido desplazaba el
  resumen encima de los productos. El grid ahora controla tamaños, padding,
  posición e insets. El botón de eliminar conserva borde/44px incluso si
  `cart.css` llega después. A 320 px sus controles pueden envolver sin producir
  scroll horizontal. También se corrigió la precedencia del CTA pendiente.
- **V04 — Catálogo:** `ClassicTemplate::render_archive_product()` no incluye
  el override PHP. La composición se instaló en los hooks nativos compartidos
  con el fallback PHP, sin cambiar consultas, callbacks del bloque ni assets.
  Regresan intro, buscador, categorías, márgenes y estado vacío; hooks de
  extensiones y paginación se preservan. En checkout se elimina el límite
  externo de 1000px de Woo **solo** cuando contiene `.fp-checkout-page`.
- **V05 — Privacidad:** se eliminó la copia propia; queda la que emite el
  bloque de pago/terms nativo, también durante actualizaciones AJAX. Los
  estilos apuntan a ese aviso. No se cambió consentimiento, nonce o envío.
- **V06 — Sin color:** el estado `aria-disabled=true` ahora supera la regla
  azul de la ficha, incluyendo hover; fondo tenue, texto gris y cursor inactivo.

## Comprobaciones realizadas

- Repro original sobre catálogo desplegado a 412 px: tarjetas **190.55px** en
  una pista de 397px, CTA **26px**, fuera de tarjeta → FAIL.
- Mismo chequeo sobre replay con CSS local y el shell recuperado: tarjetas
  **365px**, CTA **175px**, contenido dentro de tarjeta → PASS.
- Test del método **real** de Woo para archivos: primero rojo por falta del
  shell; después **47 checks**. Cubre shop/category/search, vacío/poblado y
  fallback PHP, sin boot de WP ni DB.
- Test de formulario con terms nativo: primero rojo por aviso duplicado;
  después **51 checks**.
- `FREEPLAST_SKIP_STACK=1 npm test`: verde; 504 assertions del adaptador,
  423 checks agregados de sintaxis/dependencias/despliegue y suites detalladas
  en [`offline.txt`](offline.txt). No se lanzó el stack HTTP.
- Replays de catálogo, ficha/relacionados, carrito y formulario en 13 anchos
  (320,375,412,599,600,601,768,769,999,1000,1001,1024,1440), alto915, DPR1:
  **364/364 comprobaciones geométricas en 52 casos**. Se repitió con los CSS
  de `0713770`: **240 fallos**, mismos fixtures/assertions. Resultados en
  [`layout-results.json`](layout-results.json) y
  [`baseline-layout-results.json`](baseline-layout-results.json).
- Se inspeccionaron capturas locales de tarjetas móviles, relacionados PC,
  resumen móvil/PC y formulario PC. El resumen PC mide **340px**, queda en su
  columna derecha sin superponerse, y el formulario recupera el centrado.
- `git diff --check` limpio.

Las capturas `*-after.jpeg` de esta carpeta son **replays inertes con CSS local**,
NO capturas de un deploy. El catálogo usa conteos stub en su encabezado; el
script modela clases responsive y el estado aria del botón. Detalle reproducible:
[`fixtures/visual-cascade/README.md`](../../../scripts/fixtures/visual-cascade/README.md).
La corrección CSS común no sustituye repetir Home nativo después del deploy.

## Límites y efectos

Se agregó temporalmente una Cosechera ×1 en la sesión de prueba para capturar
el HTML nativo de carrito/formulario. Se retiró esa única línea propia y se
observó la selección vacía antes de salir. Sin solicitudes, datos personales,
correo, cambios en catálogo, migraciones, dispositivos o servidor de desarrollo.

**No aprobación visual ni paridad A declarada.** El dueño confirmó los defectos
anteriores, no estos cambios nuevos. Pendiente despliegue autorizado, recorrido
nativo con el código corregido y revisión humana en PC/teléfono. #48 no se cierra.
