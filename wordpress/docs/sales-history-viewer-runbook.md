# Visor de ventas históricas: verificación y activación DEV

## Alcance

Ruta privada: `/wp-admin/admin.php?page=fpw-sales-history`, desde **Mantenedores → Ventas históricas**. **Importar ventas** conserva `/wp-admin/admin.php?page=fpw-sales-import`; abrirlo no implica importar. No subir archivos, confirmar/cancelar lotes, resolver pendientes, editar, exportar ni borrar datos durante esta verificación.

El visor lee el registro almacenado: 25 ventas por página, fecha descendente y desempate por ID estable descendente. El detalle usa `sale_id`, nunca la factura como identidad. Nombres únicamente de CLIENTE D original conservado; ausencia/variantes explícitas. Los importes permanecen cadenas decimales exactas. No se vuelve a leer el Excel.

Parámetros propios: `history_search` (hasta 100 caracteres), `history_from`, `history_to` (fechas reales ISO inclusivas), `history_page`, `sale_id`. Buscar inicia página 1. Abrir/volver conserva filtros y página; limpiar elimina ambos. Las ventas sin fecha se excluyen cuando hay cualquier límite de fecha. La búsqueda de RUT admite puntos/guion y K en ambos casos; nombres sin normalización de acentos. Buscar un nombre conservado en CLIENTE D incluye también ventas antiguas del mismo RUT normalizado, aunque no guarden nombre; esa asociación se resuelve antes de limitar fechas. Los homónimos mantienen sus RUT independientes y cada registro conserva su propio nombre original o «No disponible en origen». Sin evidencia de nombre ni RUT utilizable no se infieren asociaciones; buscar por RUT o factura permite encontrar registros sin nombre. El CSV de enriquecimiento antiguo no conservaba CLIENTE: la consulta no reconstruye ese dato ni vuelve a abrir el Excel. Sin JavaScript, formularios GET, enlaces y disclosures siguen disponibles.

## Gates reproducibles, sin datos reales

Con dependencias ya preparadas, sin instalar ni descargar:

```sh
php wordpress/scripts/sales-history-test.php
node --check wordpress/scripts/sales-history-native.mjs
FREEPLAST_SKIP_STACK=1 npm test
```

El test PHP recorre 1.322 registros **sintéticos**, valida filtros, decimales, escape, permisos, enlaces y ausencia de escrituras. La suite offline conserva regresiones CSV, XLSX, persistencia e historial en cotizaciones. Los checks de CSS/semántica no prueban geometría ni foco real.

**Solo coordinador, sobre entorno nativo desechable verificado:** ejecutar `npm test` sin `FREEPLAST_SKIP_STACK=1`, con cachés locales completas. Para diagnóstico acotado puede usar `FREEPLAST_WORKSPACE_ONLY=1 npm test`; esto no sustituye la suite completa. No apuntar el harness a DEV ni a una base existente. No usar bootstrap que descargue dependencias faltantes.

`owner-workspace-native.mjs` llama `sales-history-native.mjs` con sesiones sintéticas. El caso requiere que no exista la fila del registro, crea 30 ventas y un recibo sintéticos directamente, y elimina exclusivamente esas filas en `finally`. No ejecuta el importador. Comprueba:

- Destino real del menú, estado vacío y primera página acotada.
- Formulario GET → búsqueda con fechas → siguiente → detalle multilínea → volver → limpiar.
- Orden exacto sin omisiones/duplicados; RUT/factura, límites inclusivos, fechas inválidas, arrays, ID inexistente y legado sin líneas.
- Escape de nombres/productos, importes separados por factura/línea, procedencia y contexto validado.
- Acceso dueño y rol de datos; rechazo directo de listado/detalle a ventas y rol solo cotizaciones; invitado redirigido sin revelar venta.
- Importador accesible por separado, sin envío de formulario.
- Huella SHA256 antes/después: valores crudos de opciones `fpw_*` (incluye ventas, recibos, borradores y documentos), `woocommerce_*`, `qwc_*`, roles, posts/meta, comentarios/meta, taxonomías, ítems de pedidos y tablas HPOS presentes. Se verifica también ausencia de tablas, no solo sus filas.

Esta huella acotada **no prueba identidad de toda la base**: sesiones/usermeta, cachés y otras opciones quedan fuera. La comparación completa y la explicación de diferencias de autenticación/caché siguen siendo gate del coordinador. Un fallo aborta la aceptación; no reintentar efectos inciertos.

## Navegador: gate pendiente de ejecución por coordinador

Sobre fixture sintético aislado, escritorio y móvil (incluido 320 px), luego 200% de zoom:

1. Desde Mantenedores, llegar al visor con teclado. Verificar Tab/Shift+Tab y Enter, foco azul visible, controles de al menos 44 × 44 px, sin trampas.
2. Buscar, introducir fechas, paginar, abrir y volver conservando valores/página. Repetir con JavaScript desactivado.
3. En tabla, enfocar región «Ventas históricas» y desplazar horizontalmente con teclado. La tabla puede desplazar internamente; el documento no debe superar el ancho de viewport.
4. Abrir detalle y disclosures con Enter/Espacio. Revisar nombres/productos/ID muy largos, totales decimales y procedencia extensa; nada debe quedar inaccesible por recorte.
5. Revisar estados vacío/sin coincidencias/inválido/no encontrado y recuperación. Importador separado; nunca confirmar una importación real.
6. Capturar URL, viewport, resultados de geometría/foco y archivos de evidencia verificados. No declarar aprobado basándose solo en screenshots o sintaxis.

## Activación exclusivamente DEV: propiedad del coordinador

1. Confirmar host/URL/base DEV local y límites del plan. Inventariar Git, archivos modificados/no rastreados, hashes, permisos y cambios previos del usuario. No tocar staging/producción.
2. Antes de activar, guardar respaldo privado verificable de base y archivos afectados. Registrar manifiesto y hashes de cada archivo previo; respaldo no equivale a permiso para restaurar datos comerciales.
3. Tomar instantánea completa consistente de base más inventario/huella comercial cruda (ventas, recibos, precios, solicitudes, cotizaciones y documentos incluidos). No usar sin cambios `owner-workspace-fingerprint.php`: está deliberadamente restringido a staging.
4. Integrar implementación completa y ejecutar gates objetivos aislados. Una única revisión global del snapshot integrado, Standards y Spec por separado, cobertura completa de ambos e inventario/hash sin deriva. Como máximo un ciclo de corrección del mismo revisor, seguido de pruebas objetivas. Sin release si cobertura incompleta, pruebas fallidas o efectos inexplicados.
5. Aplicar únicamente archivos aprobados, preservando byte a byte trabajo previo. Si coincide una ruta con cambios del usuario, no sobrescribir: resolver alcance con coordinador y verificar contenido preservado.
6. Abrir DEV solo en consulta. Confirmar el conteo existente (1.322 si la instantánea inicial lo confirma), paginación, filtros y detalle sin reimportar. No convertir un conteo sintético en evidencia de registros reales.
7. Repetir instantánea/huella. Para diferencias de autenticación/caché, registrar tabla, clave, motivo y evidencia antes/después por separado; no excluir tablas enteras para conseguir igualdad. Toda diferencia comercial o inexplicada bloquea.
8. Auditar hashes finales de archivos aprobados, trabajo previo y datos. Detener servicios desechables creados por el plan y verificar puertos/procesos; conservar ramas/worktrees y respaldos. Si activación falla, detenerse: no restaurar una base sobre posibles escrituras posteriores.

Entrega final del coordinador: URL DEV real, commit integrado, inventario/hash, respaldo privado, logs offline/nativos, evidencia escritorio/móvil/teclado/sin JS, comparación de datos y servicios detenidos. Hasta entonces, implementación no equivale a activación ni aceptación del ticket 3.
