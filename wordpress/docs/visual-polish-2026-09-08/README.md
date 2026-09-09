# Pulido visual — implementación local, tema 1.0.16

## Acuerdo y límites

Después de aprobar visualmente la tanda desplegada 1.0.15, el responsable aprobó
estas decisiones en una sesión de preguntas y confirmó implementar y probar
**localmente**, dejando **commit, push y despliegue para otra autorización**:

- Pulir la versión actual; A es referencia, no obligación de igualdad píxel a píxel.
- Resumen compacto: cantidades → CTA nativa → aclaración → enlace secundario.
- Avisos contextuales, coherentes y sin confirmaciones anticipadas/duplicadas.
- Un bloque comercial por pantalla del recorrido, separado de privacidad.
- Fotografías y navegación fuera de alcance. Woo sigue siendo dueño de
  cantidades, sesiones, persistencia, formularios y operaciones.

No aparecen nuevos términos de dominio ni decisiones arquitectónicas: no se
modificaron `CONTEXT.md` ni ADR-0001. Productos a Cotizar sigue siendo selección
temporal; enviar el formulario produce una Solicitud de cotización, no una compra.

## Implementación

### Resumen y textos

- `assets/css/woo.css`: elimina márgenes acumulados y huecos de contenedores
  nativos vacíos/de totales ya ocultos. No oculta contenido arbitrario de
  extensiones. Mantiene 16px entre números/CTA y CTA/aclaración; enlace secundario
  con objetivo de 44px. Los números pueden envolver sin comprimir controles.
- `assets/js/basket-count.js`: elimina la explicación redundante del siguiente
  paso y coloca la aclaración antes de «Seguir agregando productos».
- `inc/quote-presentation.php`: filtro `render_block_core/html` **solo en la ruta
  del carrito** para quitar la frase duplicada del párrafo histórico exacto.
  Las páginas ya guardadas reciben el ajuste sin migración ni edición de DB.
  Otros bloques/rutas y un párrafo editado por el comerciante quedan intactos.
- `woocommerce/checkout/form-checkout.php`: quita la repetición del precio del
  resumen e incluye el texto aprobado al pie. Privacidad sigue siendo salida
  nativa payment → terms, una sola vez y separada.
- `woocommerce/checkout/thankyou.php`: los pasos describen la revisión/contacto;
  la explicación comercial queda una sola vez al pie. No se altera qué envía
  el formulario, la referencia de solicitud ni los datos mostrados.

Texto aprobado:

> Esta solicitud no es una compra ni reserva stock. Ventas confirmará precios,
> disponibilidad y condiciones.

### Avisos

- `assets/js/loop-added-count.js`: quitar desde una tarjeta deja una confirmación
  contextual **fuera** del fragmento que acaba de vaciarse. El indicador nativo
  de unidades sigue siendo la única confirmación propia al agregar; no se añade
  un toast ni otro mensaje de éxito de agregado.
- La confirmación exige el fragmento completo del adaptador y una cantidad
  válida para el producto involucrado; evento sin snapshot, cantidad inválida,
  agregado sin producto o eliminación con producto todavía presente conservan
  un aviso de incertidumbre y el enlace «Revisar selección».
- Un error de transporte se anuncia una vez, incluso con tarjetas duplicadas.
  Una operación sobre B no borra el error de A. La recuperación confirmada
  limpia los errores correspondientes; un refresco que vuelve a mostrar el
  producto retira una confirmación obsoleta de eliminación.
- `basket-count.js`: en Productos a Cotizar los errores de eliminación se
  conservan por clave de línea. Una nueva operación u otro éxito no los borra;
  la recuperación de esa misma línea sí. La cantidad del error se describe
  como **última comprobación**, no como una lectura actual indefinida.
- Los mensajes de selección siguen junto a la lista e identifican producto y
  configuración: una línea eliminada ya no existe para alojar su mensaje.
  Se conservan los controles/foco y guardas nativas existentes.
- Estilo común para resultados propios, estado de ficha y avisos clásicos de
  Woo: colores del tema, tipografía, bordes y radios coherentes. Los errores
  nativos no se suprimen, interceptan ni se convierten en éxito. El enlace de
  recuperación tiene línea propia y objetivo de 44px. Se mantienen roles
  `alert`/`status`; no hay caducidad automática de errores.

Tema **1.0.15 → 1.0.16** en `style.css` y `functions.php`. Adaptador y dependencias
sin cambios. No se empaquetaron ZIPs.

## Evidencia local

- Prueba nueva roja antes del cambio: `settled removal leaves a contextual
  confirmation outside the deleted slot`; verde después.
- `FREEPLAST_SKIP_STACK=1 npm test`: pasa. Incluye 504 aserciones del adaptador,
  74 de tarjetas con los handlers originales de Woo 11.1.0 y transporte
  interceptado, 23 de presentación/foco/errores de selección, 7 del filtro con
  dispatcher real de WP, 53 de formulario y 28 de confirmación. El resumen
  agregado informa 451 comprobaciones; no es la suma de todas las suites.
- `git diff --check`: limpio.
- Reproducción **inerta file://** en Chrome existente: **494/494 comprobaciones**,
  4 páginas × 13 anchos de 320 a 1440 = **52 escenarios**. Incluye CSS de
  dependencias fijadas y `cart.css` agregado al final para probar la cascada.
- Inspeccionados el resumen a ancho móvil/PC y el error contextual móvil.
  Durante la inspección se detectó que el enlace pegaba visualmente con la
  última frase; se pasó a línea propia y se agregó la aserción correspondiente.
- `layout-results.json` contiene mediciones; `offline.txt` contiene la salida
  del último gate. Las tres capturas guardadas son reproducciones locales,
  **no capturas del sitio desplegado**. Fotografías y marca se reemplazan por
  rectángulos, y los conteos del encabezado del catálogo son stubs históricos.

### Reproducción

```bash
FREEPLAST_SKIP_STACK=1 npm test
node wordpress/scripts/quote-polish-fixture.mjs
```

El segundo comando devuelve un directorio temporal con `manifest.json` y los
HTML. Sobre una pestaña propia del harness, ejecutar mediante
`browser_run_script` el archivo `wordpress/scripts/layout-cascade-browser.js`
con `manifestPath`, `targetId`, `out` (directorio existente) y **`polish: true`**.

El generador reutiliza los DOM sanitizados anteriores, ejecuta el JS actual del
resumen con un store modelado, consume el lead del filtro PHP probado y copia
la nota actual del formulario. Los estados de error/quitado de tarjetas se
modelan para geometría; sus eventos se prueban por separado con handlers
nativos y transporte interceptado. CSP bloquea red/formularios; fuentes son
locales e imágenes `data:`. No inicia ningún servidor.

## Pendiente / no realizado

- No commit, push, deploy, GitHub writes, DB/catalog changes ni reempaquetado.
- No navegador nuevo, dev server, adb ni dispositivos; se cerró la pestaña propia.
- No solicitudes reales, entrada de datos personales, correo ni operaciones en
  staging/producción. El sitio desplegado permanece en el checkpoint anterior.
- Falta comprobar el recorrido nativo desplegado, lector de pantalla y revisión
  humana en PC/teléfono después de una publicación autorizada. Las pruebas
  modeladas y capturas **no son aceptación visual ni validación en hardware**.
- No cierre de #48 ni afirmación de paridad completa con A.
