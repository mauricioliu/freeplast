# Excel oficial: carga y vista previa (ticket 2)

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

**Confirmación XLSX bloqueada en servidor y ausente del formulario.** Ticket 3
es dueño de la persistencia, concurrencia, recibos y habilitación de confirmación.
Cancelar solo elimina el lote pendiente. No cambiar el bloqueo hasta implementar
el contrato completo de persistencia. La revisión visual/HTTP queda para el lead.

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
No se configura ni copia evidencia real en este ticket. Ticket 3 debe volver a
leer esta evidencia y el registro al confirmar; nunca confiar en un plan recibido
por POST o en la clasificación guardada de una vista previa obsoleta.

## Seam para ticket 3

`fpw_sales_workbook_plan(rows, legacy, sales)` devuelve entidades del contrato,
con estados UI `new`, `existing`, `enrich`, `conflict`, `pending`. Compara por ID,
atributos núcleo y snapshot de líneas; cualquier diferencia poblada bloquea la
entidad. `fill_fields` describe campos vacíos para la vista previa, **no una
operación de escritura autorizada**. Proveniencia/recibos adicionales corresponden
al ticket 3. Las filas originales se mantienen aunque el mapper colapse líneas
indistinguibles en el snapshot pendiente.

Conciliación readonly oficial: mapper coincide exactamente con ticket 1
(319 conciliables, 966 nuevas, 176 pendientes; 1700 filas, 53 vacías).
UI separa esos 176 pendientes en **15 conflictos +161 pendientes**; los 319
conciliables muestran **por enriquecer** por carecer de snapshot `lines`.
Suma O exacta: `3183676213.921`. No significa que se haya aplicado ningún cambio.

## Pruebas

```sh
php wordpress/scripts/sales-workbook-test.php
php wordpress/scripts/sales-workbook-contract-test.php
php wordpress/scripts/sales-enrichment-migration-test.php
python3 wordpress/scripts/sales-history-workbook-test.py
FREEPLAST_SKIP_STACK=1 npm test
```

El nuevo test reutiliza 142 checks CSV/permisos/CSRF y agrega casos XLSX sintéticos;
el runner offline los incluye. Los datos identificables reales no son fixtures.
