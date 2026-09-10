# #56 — primera ejecución nativa y decisión PDF pendiente

## Autorización y límites

El dueño pidió en esta sesión: «continua con biblioteca/activos PDF y pruebas nativas con base de datos real». Se ejecutó el harness desechable del worktree `freeplast-issue56-fix`, rama `ralph/issue-56`, sobre la corrección `45b0a54`. No se tocó staging, producción, Ralph, dispositivos, datos comerciales ni otras ramas.

La issue [#64](https://github.com/mauricioliu/freeplast/issues/64), consultada junto al padre [#56](https://github.com/mauricioliu/freeplast/issues/56), exige una decisión humana registrada en el padre antes de alinear el PDF. No se eligió ni instaló una dependencia en nombre del dueño; abajo queda una propuesta, no una decisión aprobada.

## Ejecución nativa observada

- Instalación nueva y propia bajo `wordpress/.build/wp`; WordPress 7.1, WooCommerce 11.1.0, Quotes for WooCommerce 2.13, PHP 8.3.32 y SQLite mediante el drop-in del proyecto. Son procesos PHP y una base de datos real, no stubs del test PHP. **No es MariaDB/MySQL ni evidencia de equivalencia con producción.**
- Datos de prueba creados por el harness, sesiones HTTP reales y servidor PHP de ocho workers. Google simulado en su transporte.
- Correo interceptado por el mu-plugin del harness. Protección independiente adicional durante toda la provisión y ejecución: `PHPRC` privado con `sendmail_path=/bin/false`, comprobado antes de arrancar. No había un plugin SMTP externo en la instalación nueva. `umask 077` para configuración y logs locales.
- URL desechable: `http://mliu:8096`. Primera ejecución falló antes de los recorridos: el servidor ignoraba el hostname configurado y escuchaba solamente en loopback. Se corrigió el binding para usar el hostname de `FREEPLAST_TEST_URL`; los defaults de bootstrap y harness pasan a `mliu`.
- Segunda ejecución: **588 real-stack checks passed**, incluyendo los recorridos existentes de #56, el formulario ligado a la vista previa, rechazo de pestaña antigua, persistencia de la versión/PDF, correo interceptado, permisos, rechazo/resultado desconocido y documento inválido.
- Ambos intentos terminaron por el `finally` del harness; después de la ejecución correcta no había listener en el puerto 8096. No se dejó un servidor de previsualización.
- Comprobaciones finales: `node --check` en bootstrap/harness, `git diff --check` y `FREEPLAST_SKIP_STACK=1 npm test` correctos (654 checks finales del checker, más las suites que reporta por separado). Esta última corrida es offline; las 588 comprobaciones nativas provienen de la ejecución distinta descrita arriba.
- Revisión local del delta contra `45b0a54`: solo dirección de escucha/defaults y este informe. Sin cambios comerciales ni dependencia PDF nueva. No hubo revisión independiente por subagentes; siguen vigentes las carencias de aceptación enumeradas abajo.

Comando (el directorio privado de esta sesión contiene el ini que bloquea sendmail):

```sh
PHPRC="$RUN_DIR/php.ini" FREEPLAST_TEST_URL=http://mliu:8096 \
  node --input-type=module -e \
  'import {runStackHarness} from "./wordpress/scripts/woo-stack-harness.mjs"; await runStackHarness();'
```

Evidencia local no versionada: consultar `/tmp/freeplast-issue56-native-current` para el directorio privado de esta sesión (`native-baseline.log`, `native-bind-fixed.log` y `php.ini`). Los logs no se suben: la instalación contiene credenciales de cuentas desechables y registros sintéticos.

## Lo que este verde NO acredita

- La prueba de dos POST de aprobación de #56 empieza cuando ya existe la versión aprobada. Acredita reintentos concurrentes sobre una versión existente, **no dos primeras aprobaciones concurrentes ganando/perdiendo el INSERT**. La concurrencia del checkout probada por otros recorridos tampoco sustituye esta prueba.
- Los nuevos fallos de INSERT, UPDATE antes del correo y UPDATE después del correo de `45b0a54` siguen probados con el harness offline. No se inyectaron aún en el stack nativo.
- El test del documento sigue utilizando el writer heredado y comprobaciones de su contenido; no certifica conformidad con una biblioteca aprobada ni aceptación visual del PDF.
- No hubo navegador, prueba de teclado/foco ni revisión humana del documento o interfaz. No se declara #56 aceptada, cerrada o lista para merge.

## Propuesta PDF para decisión del dueño

**Propuesta, no implementada:** cumplir el criterio original, no flexibilizarlo para justificar el writer propio.

1. **Dompdf 3.1.6**, gratuito, licencia LGPL-2.1. Fijar versión, archivo de distribución y SHA-256; inventariar las dependencias y conservar sus licencias. No actualizar dinámicamente al emitir. La versión 3.1.6 corrige vulnerabilidades publicadas de validación de rutas y manejo de imágenes; eso no elimina la necesidad de configuración restrictiva.
2. **Activos actuales del sitio:** marca `wordpress/wp-content/themes/freeplast/assets/img/mark.svg` y familia Manrope (`assets/fonts/manrope.woff2`, licencia OFL). Preparar y fijar una variante embebible compatible con el renderer si hace falta, conservando procedencia/licencia. Sin imágenes generadas, fotografías de productos, marcas inventadas ni asumir que el diseño de un prototipo ya fue aprobado para el PDF.
3. **Documento:** composición documental sobria con los datos congelados de la oferta; sin condiciones legales, datos bancarios o promesas nuevas. Presentarlo después al dueño: decidir biblioteca/activos no equivale a aprobar su aspecto final.
4. **Aislamiento:** recursos remotos, ejecución PHP y JavaScript deshabilitados; acceso local limitado a activos autorizados; temporales privados; contenido del comprador escapado. Falta de dependencia/activo o error de render conserva el documento pendiente, sin fallback al writer propio.
5. **Pruebas siguientes, en las mismas interfaces ya acordadas:** aprobación HTTP de una oferta revisada → PDF parseado por herramienta independiente y adjunto interceptado; dos primeras aprobaciones realmente solapadas; fallos de persistencia antes/después del transporte sobre la base nativa; permisos, idempotencia y ausencia de efectos comerciales. El PDF debe corresponder a la versión fija, incluso si después cambian la lista o el trabajo.

Fuentes primarias consultadas:

- [Release Dompdf 3.1.6](https://github.com/dompdf/dompdf/releases/tag/v3.1.6).
- [composer.json fijado](https://github.com/dompdf/dompdf/blob/v3.1.6/composer.json): PHP, extensiones y licencia.
- [LGPL distribuida](https://github.com/dompdf/dompdf/blob/v3.1.6/LICENSE.LGPL).

Falta confirmación del dueño y registro de esa decisión en #56 antes de cambiar dependencia, activos o ADR-0011. El padre permanece abierto, sin push ni merge automáticos.
