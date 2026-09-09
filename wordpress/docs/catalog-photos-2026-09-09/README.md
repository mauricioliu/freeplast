# Fotografías y navegación — trabajo local del 2026-09-09

**Este documento registra la implementación local inicial, no un release. Tema 1.0.17.**

**Actualización tras el «confirmo» del responsable:** DATA autorizado solo para
staging. La integración sellada de migración y fingerprint por delta esperado
ya está implementada; el gate ahora comprueba capacidad/configuración en vez de
bloquear siempre. El importador ad hoc sigue restringido a local; el driver usa
`migrate-catalog-photos-release.php` bajo su propio guard y baseline de backup.
Pruebas actualizadas: 795 checks agregados del gate completo (181 HTTP de fixture),
99 de medios/delta + 7 del hook sellado + 5 de CLI. Estado de publicación y nueva
certificación en [el registro de integración](../releases/2026-09-09-photo-migration/README.md).
Las afirmaciones de no contacto/no modificación del driver más abajo describen
únicamente la **primera tanda**, antes de esa confirmación.

El responsable pidió «trabaja en todo lo faltante» sobre fotografías, avisos,
espaciado del resumen, textos repetidos y coherencia de navegación móvil/PC.
Los tres ajustes de cotización ya estaban en 1.0.16; esta tanda añade navegación,
material fotográfico e importación nativa probada en una copia desechable.

## Estado por ítem

| Ítem | Implementación / límite |
| --- | --- |
| Fotografías | 17 archivos preparados; importados y comprobados solo en fixture. Pendiente publicación e imágenes originales. |
| Avisos | Implementación 1.0.16 conservada; regresiones offline verdes. Pendiente recorrido publicado y aceptación humana. |
| Resumen compacto | Implementación 1.0.16 conservada; regresiones offline verdes. Pendiente revisión humana. |
| Textos repetidos | Implementación 1.0.16 conservada; regresiones offline verdes. Pendiente revisión humana. |
| Navegación | Mismos destinos, orden y etiqueta «Cómo cotizar»; Nosotros disponible en PC. Pendiente publicación y revisión humana. |

## Fotografías encontradas (corrige el diagnóstico anterior)

El PDF local `docs/Catálogo Freeplast 2026.pdf` sí contiene fotografías de Caja
Paltera y Traversa Romano, además de cuatro imágenes distintas para Universal.
Los placeholders y la repetición no significaban que ese material no existiera:
no se había aprovechado el PDF para las imágenes del catálogo importado.

Se recuperaron **15 fotografías** de las páginas 6–20; Pediluvio y Ladrillo,
ausentes de esas fichas, conservan la fuente anterior. Total **17 archivos WebP**
(aprox. 540 KiB incluyendo manifiesto), en `wordpress/data/catalog-photos/`.

- Ventanas de recorte excluyen títulos, especificaciones y decoración del PDF.
- Encuadre cuadrado centrado, margen consistente y lienzo blanco, hasta 960px.
- Nunca se amplían imágenes pequeñas ni se inventan detalles mediante IA.
- Se conservan proporciones, color, marcas de agua y píxeles del producto.
- Un umbral calcula únicamente límites de encuadre; **no borra ni sustituye
  píxeles**. Los rectángulos gris claro de algunas fuentes siguen presentes.
  No afirmar que todos los fondos internos quedaron idénticos.
- `manifest.json` registra SKU de origen, hash antiguo permitido, hash nuevo,
  dimensiones, alt/caption, archivo fuente, hash fuente, página y recortes.
- Cuatro archivos diferentes: Universal Cerrada Negra, Cerrada Color,
  Ventilada Negra, Ventilada Color. La imagen antigua común era ventilada;
  ya no se usa para representar una caja cerrada en el lote preparado.
- Las fotos de las dos Universal Color muestran **rojo**. Alt y pie lo dicen
  explícitamente, sin afirmar que muestran blanco/amarillo/azul/verde.

`contact-sheet.jpg` muestra los **archivos preparados**, no el sitio desplegado.
Durante la inspección se detectó texto técnico en el borde de Merlucera; se
corrigió la ventana de recorte y se volvió a generar el lote.

### Material que todavía debe entregar el cliente

1. Originales de mayor resolución y sin marca de agua de los productos.
2. Fotografías reales de Universal Cerrada Color y Ventilada Color en **blanco,
   amarillo, azul y verde** (o confirmación de que se seguirá mostrando solo
   una referencia roja claramente rotulada).
3. Fuentes mejores para Pediluvio y Ladrillo, no presentes en las fichas del PDF.

No se modificaron medidas, material, especificaciones, disponibilidad, variaciones
ni descripciones de productos. El JSON histórico sigue siendo procedencia de
bootstrap, no una segunda autoridad editable frente a WooCommerce.

## Importación nativa, no sustitución visual oculta

`wordpress/scripts/lib/catalog-photos.php` usa la Media Library de WordPress y
su `_thumbnail_id`: tarjetas, galería, Store API y referencias de variantes
consumen las imágenes por las rutas nativas, no por una tabla JS paralela.

La entrada `wordpress/scripts/import-catalog-photos.php`:

- Por defecto produce un **plan de solo lectura**.
- Busca una única identidad `_fp_source_id` y exige el mismo SKU nativo.
- Verifica cada archivo local, dimensiones, formato y hash antes de escribir.
- Solo reemplaza los bytes del origen histórico esperado. Rechaza fotos
  cambiadas/eliminadas por el comerciante, aunque quede metadato antiguo.
- `apply` exige token fresco del plan y URL `home` idéntica; lock excluyente.
- Importa todas las nuevas imágenes antes de cambiar productos, revalida el
  plan y verifica el resultado. Un fallo revierte las asignaciones y limpia
  únicamente sus adjuntos nuevos; nunca elimina los originales.
- Una recuperación fallida conserva lock y adjuntos, y exige respaldo pareado.
- Repetir una importación completada es un no-op.
- **`apply` está bloqueado fuera de `WP_ENVIRONMENT_TYPE=local`.** No cambiar
  el entorno del servidor para eludirlo ni invocar la biblioteca directamente.

Comandos locales:

```bash
python3 wordpress/scripts/prepare-catalog-photos.py
FREEPLAST_SKIP_STACK=1 npm test
node wordpress/scripts/catalog-photos-native-test.mjs
```

Para inspeccionar un fixture mediante WP-CLI:

```text
wp eval-file wordpress/scripts/import-catalog-photos.php plan /ruta/manifest.json
wp eval-file wordpress/scripts/import-catalog-photos.php apply /ruta/manifest.json <token-del-plan> <home-del-fixture>
```

## Navegación / presentación

- `parts/header.html`: Catálogo → Nosotros → Contacto → Cómo cotizar en ambos
  tamaños. Productos a Cotizar sigue siendo CTA independiente en el encabezado
  y destino explícito del menú móvil.
- `style.css`: gaps adaptables de navegación en PC sin reducir objetivos de
  44px; las superficies fotográficas usan el token blanco existente.
- `functions.php` y ficha: pie procedente del adjunto nativo, limpiado y escapado.
  Reemplazar un adjunto no hereda el pie del PDF anterior; permanece el fallback
  referencial cuando no existe caption. Fotos realmente ausentes siguen pendientes.
- Versión del tema **1.0.17**. Adaptador sin cambios.

## Pruebas y límites

- `offline-tests.txt`: **611 checks agregados** del gate offline, incluyendo
  **144 comprobaciones nuevas** de manifiesto/fuentes/archivos; suites PHP/JS
  detalladas aparte. Navegación comprueba destinos, orden, textos, aria-current,
  diálogos y foco mediante jsdom. Ficha: 35 comprobaciones PHP.
- `native-tests.txt`: **73 checks nativos + 5 de la CLI** con WordPress + Woo y Media Library reales,
  sin servidor, en una copia temporal de `.build/wp`. El runner prepara SOLO
  esa copia con la identidad del import histórico, bloquea HTTP/correo y cron,
  ejecuta el caso, y elimina su directorio al terminar. También se comprueban
  ayuda, versión, comando inválido, apply incompleto y bloqueo en entorno staging.
  Para ayuda propia usar `help`/`version`: WP-CLI consume sus flags globales
  `--help`/`--version` antes de entregarlos al archivo.
- Casos nativos: plan, token obsoleto, target incorrecto, lock ajeno, fallo del
  tercer sideload, fallo de segunda asignación con rollback, import completo,
  no-op, fotos nuevas con alt/caption/thumbnail nativos, cuatro Universal
  distintas y rechazo de foto modificada por comerciante.
- Comparación de datos nativos antes/después: todos los posts no-adjuntos,
  metadatos no-adjuntos salvo `_thumbnail_id`, líneas y metadatos de solicitudes
  idénticos. No se cambian campos comerciales para importar fotos.
- `git diff --check`: limpio.

No se arrancó dev server, usó adb, tocó hardware ni abrió un navegador. No se
repitió el gate HTTP completo. Estas pruebas y la inspección de archivos de
imagen **no son aceptación visual ni validación de PC/teléfono**.

## Bloqueo detectado en la primera tanda (histórico; integración resuelta)

`release-plan.txt` propone **DATA**, comparando con `1e00767` (checkpoint anterior
al pulido 1.0.16). Es propuesta, **no confirmación del responsable**.

Dos contratos actuales impiden publicar las fotos correctamente:

1. `wp-release` instala ZIPs de tema/plugins, pero no tiene un hook de migración
   sellado ejecutado tanto en **trial** como en **install** bajo mantenimiento.
   El lote de medios y su importador no forman parte del bundle instalado.
2. `wordpress/scripts/record-state.php` protege actualmente también adjuntos y
   `_thumbnail_id`. Una importación legítima cambia ese fingerprint. No se puede
   recalcular el baseline o quitar la comparación para que el release pase.

Por seguridad `wp-release.json` añade `photo-release-readiness.mjs` al gate:
**bloquea localmente antes de package/transfer**. No es un fallo de las pruebas
ni del hardening anterior. Evita un release falsamente completo que publique
solo el tema y deje las imágenes antiguas. No hay flag de bypass.

### Continuación identificada entonces

La implementación posterior resuelve los dos contratos sin quitar protecciones:
ver [ADR-0003](../../../docs/adr/0003-photo-migration-expected-delta.md) y el registro
actual enlazado al inicio. Esta lista conserva el diagnóstico previo:


- Diseñar/implementar en el driver un hook sellado de migración con los mismos
  archivos en ensayo e instalación; probar también fallo/reanudación/rollback.
- Definir una verificación de cambios esperados de medios, conservando la
  protección íntegra de solicitudes y de todo dato de catálogo no autorizado.
  No convertir el hook `verifyState` de solo lectura en importador.
- Probar la importación contra la copia restaurada por la ceremonia, no solo
  contra este fixture. Solo entonces retirar el guard temporal/local-only.
- Pedir confirmación **DATA** y autorización fresca para `https://freeplast.mliu.site`.
- Ejecutar gate completo, bundle, backup, restore rehearsal, trial, install,
  verify, smoke, record; después revisión humana de PC/teléfono.

El skill global `wp-release` **no se modificó en esta tanda**. Se conservaron
los cambios previos del responsable en DEPLOYMENT y la evidencia de hardening.
No commit, push, deploy, contacto remoto ni cambio de catálogo real.
