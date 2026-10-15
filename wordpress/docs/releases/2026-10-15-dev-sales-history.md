# DEV: ventas históricas desde staging (2026-10-15)

Importación adicional solicitada expresamente por el dueño después de la copia de precios. No implica despliegue ni cambio de código.

## Resultado

- Fuente de solo lectura: `https://freeplast.mliu.site`, SSH `hetzner-vps`, `/var/www/freeplast`, WP-CLI.
- Destino: `http://mliu:8131`, entorno `local`, captura privada de correo comprobada.
- DEV no tenía opciones `fpw_sales_*` ni lote pendiente.
- Copiadas exactamente las 3 filas aplicadas: `fpw_sales_register` y sus 2 recibos `fpw_sales_receipt_*`.
- **356 ventas, 118 RUT distintos, fechas 2020-08-31 a 2026-08-27.** Se conservan IDs de origen, detalle comercial, asociaciones por RUT, enriquecimientos y procedencia original. No se copian usuarios, pedidos, archivos Excel ni cargas pendientes; no se reasignan ventas a clientes ficticios por nombre/correo.
- El registro usa IDs de venta/RUT, no IDs de productos WordPress: no requiere el remapeo utilizado para precios.

## Guardias y verificación

Script de operación de un solo uso: contención DEV, fuente exacta, esquema 1, límite del registro, opciones permitidas, IDs únicos, RUT normalizados, fechas y tipos de cantidades/importes. Plan antes de aplicar. Transacción, bloqueo/rechazo de cualquier opción de ventas que aparezca desde el plan, INSERT sin sobrescritura y lectura byte-exacta de cada fila antes del COMMIT.

Los 118 historiales por RUT se resolvieron mediante la API real, sumando las 356 ventas. La pantalla DEV renderiza el total y los 2 recibos. Pruebas offline del registro: 142 checks.

Segunda lectura de staging idéntica a la primera. Comparación SQL DEV: solo añadidas las 3 opciones de ventas; lista de precios y demás opciones comerciales intactas. Productos, solicitudes, borradores y documentos emitidos sin cambios. Las sesiones de WooCommerce pueden evolucionar durante las verificaciones; no se restauran ni modifican deliberadamente.

## Evidencia privada y recuperación

`~/.local/state/freeplast-local/sales-import-20261015/` (0700; SQL/JSON 0600): dump completo `dev-before.sql` (933151 bytes, DDL/datos y marcador de finalización comprobados), `dev-after.sql`, exportaciones antes/después de staging, script, recibo anterior y `SHA256SUMS`. Sin restauración de ensayo. Datos identificables permanecen fuera del repositorio y del webroot.

Recibo adicional en `/var/freeplast/sales-import-20261015-before.json` del contenedor. Reversión puntual: verificar primero que las tres filas siguen siendo idénticas a la fuente importada y eliminar exclusivamente esas tres opciones, que antes estaban ausentes. Si hubo ediciones/importaciones nuevas, detenerse y replanificar; no restaurar la BD completa encima de trabajo posterior. El script rechaza reejecuciones sobre datos existentes.
