# Excel oficial: carga, confirmación e historial

`freeplast-woo/sales-register.php` conserva el formulario, capacidades, nonces por
acción, slot privado de lote pendiente y cancelación del CSV. Ahora despacha XLSX
por extensión o firma ZIP a `sales-workbook.php`; CSV v1 conserva su parser y
confirmación. El libro original nunca se mueve a uploads ni se publica.

## Alcance y límites

- Solo valores almacenados de primera hoja; ninguna fórmula se ejecuta.
- ZIP máximo 2 MiB, 256 entradas, 8 MiB por entrada, 16 MiB expandidos,
  relación máxima 200:1. Lectura sin extracción; temporal 0600 eliminado en
  `finally`. DOM sin red, DTD ni entidades; XML malformado se rechaza.
- Macros, objetos incrustados, referencias/relaciones externas y fórmulas o
  nombres definidos externos se rechazan, incluso en partes no seleccionadas.
- Hasta 5000 filas de datos, coordenadas A:ZZ, texto de celda hasta 8192 bytes.
  Filas/celdas duplicadas, cabeceras incompatibles, tipos de error, cachés de
  fórmula ausentes y fechas inválidas rechazan el libro completo, sin sustituir
  un lote pendiente anterior. Fechas seriales enteras, epoch 1900/1904; no se
  acepta el ficticio 1900-02-29. Importes comerciales inválidos quedan pendientes
  con razones del mapper; suma de factura fuera de rango también queda pendiente.
- AD:ZZ no vacías se conservan y advierten, sin convertirse en datos comerciales.
- Batch conserva `format=xlsx`, `source_sha256`, `sheet`, `rows` (incluido `raw`),
  `blank_rows`, `extra_columns` y metadatos habituales del actor/token/fecha.
  El slot no es un archivo público; no se exponen rutas privadas ni evidencia.
- Cada factura/fila sin identidad tiene un desplegable nativo accesible con
  clasificación, razones, movimientos y detalle original escapado. Todos los
  grupos son consultables; no hay truncamiento silencioso a las primeras 40 filas.
  Totales se imprimen como strings exactos, sin `number_format` ni floats.

**Confirmación XLSX habilitada con nonce y capacidad existentes.** Carga,
confirmación y cancelación se serializan sobre el slot pendiente y el registro.
Recibo, merge y consumo del lote son una sola transacción; requiere InnoDB en
MySQL o el drop-in SQLite. Fallos antes de commit revierten todo; un commit sin
confirmación se informa como incierto, sin reintento automático. Un token obsoleto
no aplica ni cancela otro lote. La revisión visual/HTTP queda para el lead.

## Evidencia histórica privada y autenticada

`fpw_sales_workbook_legacy()` solo lee configuración confiable del servidor:

```php
// Configuración privada fuera del repositorio, no campos POST ni opciones de UI.
define( 'FPW_SALES_LEGACY_FILE', '/ruta-privada/legacy-by-id.json' );
define( 'FPW_SALES_LEGACY_SHA256', '<sha256 verificado por operador>' );
```

El archivo debe estar fuera de `ABSPATH` y `DOCUMENT_ROOT`, sin permisos para
 grupo/otros (0600 recomendado), máximo 2 MiB. El hash fijado independientemente
se compara con los bytes leídos antes de decodificar. No basta con acompañar el
archivo de un hash enviado por el navegador. JSON esperado: objeto indexado por
ID anterior, cada valor con `date,rut,total,producto,total_cajas,precio,neto`.
El operador obtiene ese objeto del campo `legacy` del `mapping-input.json`
privado verificado del ticket 1, **no del registro actual**. `legacy-356.json`
es otro artefacto (lista): no montarlo directamente como este objeto.

Ausencia, permisos inseguros, hash incorrecto, ruta pública o forma inválida:
retorna evidencia vacía. El mapper bloquea los IDs anteriores presentes en el
registro sin fabricar identidades alternativas. La pantalla avisa su ausencia.
No se configura ni copia evidencia real en estos tickets. La confirmación vuelve a
leer esta evidencia y el registro bajo bloqueo; nunca confía en un plan recibido
por POST ni en la clasificación guardada de una vista previa obsoleta.

## Persistencia y consulta

`fpw_sales_workbook_plan(rows, legacy, sales)` devuelve entidades del contrato,
con estados UI `new`, `existing`, `enrich`, `conflict`, `pending`. Compara por ID,
atributos núcleo y snapshot de líneas; cualquier diferencia poblada bloquea la
entidad. `fill_fields` describe campos vacíos para la vista previa, **no una
operación de escritura autorizada**. `fpw_sales_workbook_changes()` recompone el
delta al confirmar: solo nuevas y enriquecimientos sin conflicto. Compara fecha,
RUT, total, líneas, factura y movimientos; ninguna línea se añade individualmente.

Las ventas conservan schema1 e IDs anteriores. XLSX añade `invoice`, `lines`,
`movements` y `workbook_source` (hash, hoja, archivo, lote, filas/celdas originales).
No sustituye campos comerciales ni procedencia poblados. `enriched` añade un
asiento por enriquecimiento; no borra los anteriores. Raw/celdas son procedencia,
no identidad: reordenar filas produce no-op comercial y conserva el primer origen.
Recibos durables guardan conteos, cambios y pendientes con filas, razones y tipo de
movimiento; ya no se eliminan los anclajes de confirmación antiguos. La pantalla
muestra las diez cargas más recientes y sus casos no aplicados.

Ambos historiales del cotizador consultan solo ventas importadas por RUT y abren
líneas/procedencia en desplegables nativos. El historial del workspace ya no limita
silenciosamente a50 facturas. Total factura y total línea tienen etiquetas distintas;
CLP fraccional se formatea como texto exacto, sin conversión float/int. Ajustes,
anulaciones y devoluciones ambiguos quedan en recibos como pendientes, nunca
sumados como compras. Las filas originales se mantienen aunque el mapper colapse
líneas indistinguibles en el snapshot pendiente.

Conciliación readonly oficial: mapper coincide exactamente con ticket 1
(319 conciliables, 966 nuevas, 176 pendientes; 1700 filas, 53 vacías).
UI separa esos 176 pendientes en **15 conflictos +161 pendientes**; los 319
conciliables muestran **por enriquecer** por carecer de snapshot `lines`.
Suma O exacta: `3183676213.921`. No significa que se haya aplicado ningún cambio.

## Pruebas

```sh
php wordpress/scripts/sales-workbook-test.php
php wordpress/scripts/sales-workbook-contract-test.php
php wordpress/scripts/sales-workbook-persistence-test.php
php wordpress/scripts/sales-enrichment-migration-test.php
python3 wordpress/scripts/sales-history-workbook-test.py
FREEPLAST_SKIP_STACK=1 npm test
```

El nuevo test reutiliza 142 checks CSV/permisos/CSRF y agrega casos XLSX sintéticos;
el runner offline los incluye. Persistencia usa conexiones/procesos SQLite reales:
doble confirmación concurrente, reemplazo concurrente de upload, token obsoleto,
rollback de inicio/bloqueo/recibo/insert-update/consumo/commit y respuesta perdida
después de commit. Incluye conservación histórica, evidencia privada, snapshot
reordenado, conflictos de entidad, RUT/fechas y decimales exactos.
Los datos identificables reales no son fixtures. Native WP/MySQL/HTTP queda como
gate adicional del lead; este test SQLite no lo sustituye.
