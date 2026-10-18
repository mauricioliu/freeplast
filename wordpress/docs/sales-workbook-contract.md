# Ventas Freeplast XLSX: contrato verificable v2

Contrato de mapeo puro: `wp-content/plugins/freeplast-woo/sales-workbook-contract.php`.
Prueba: `php wordpress/scripts/sales-workbook-contract-test.php` desde raíz.
No está conectado todavía al upload ni a la persistencia. CSV v1 queda intacto.

## Entrada y procedencia

Primera hoja, encabezado fila 1. Columnas A:AC:
`RUT, Fecha, Mes, CLIENTE, Producto, T, C, U, M, Sueltas, Total Cajas,
Precio, Neto, Fact., Total, Precio referencia, Transporte, Total con flete,
Descuento Flete / otros, Utilidad Neta, Utilidad, N° OC, N° Factura,
Fecha de entrega, Estado Pago, fecha de Pago, Lugar origen Venta, Entrega, Obs`.
AD en adelante no son columnas comerciales reconocidas; conservar celdas no vacías
como procedencia y advertir, nunca importarlas como identidades/importes.

El lector seguro (ticket 2) entrega valores **almacenados**, no evalúa fórmulas.
Debe rechazar encabezados incompatibles, macros, referencias externas, ZIP/XML
no acotado, celdas duplicadas, fechas inválidas y cachés de fórmula ausentes.
La validación del contenedor no pertenece al mapper. No rellenar hacia abajo
RUT, factura, fecha o producto. No transformar error/ausencia en cero.
Convertir B según epoch del libro a ISO; jamás usar formato visual para interpretar
importes. Filas completamente vacías no son registros; devolver su conteo.

Entrada `fpw_workbook_map(rows, legacy, existing)`:

- Cada fila lleva `source_row`, `invoice` (W), `date` (B ISO), `rut` (A),
  `producto` (E), `total_cajas` (K), `precio` (L), `neto` (M), `total` (O),
  `payment` (Y), `notes` (AC). `raw` conserva todas las celdas, incluyendo
  cliente, empaque, flete, descuento, OC, entrega y pago, sin reinterpretarlas.
- `legacy`: evidencia validada previamente, indexada por ID antiguo, con
  `date,rut,total,producto,total_cajas,precio,neto`. `existing`: registro actual
  por ID. La evidencia privada NO se publica en el repositorio ni se acepta
  desde parámetros del navegador. No sintetizar `legacy` copiando `existing`:
  la concordancia de ambos con el libro anterior/CSV realmente aplicado debe
  estar establecida. El ticket 3 debe transportar esta evidencia de forma
  privada/autenticada antes de permitir confirmación; su ausencia bloquea
  conservadoramente las identidades anteriores, no crea una venta alternativa.
- Retorna un resultado por grupo o fila sin factura demostrable, con `rows`
  originales, `status`, `reasons` y, cuando hay factura, `lines`, `movements`,
  `id`, `invoice`, `rut`, `date`, `total`. Todas las filas aparecen exactamente
  una vez. Pendientes no proponen ninguna escritura; su `total` es null.

## Identidades y agrupación

El namespace es **este registro del emisor Freeplast**, no cualquier Excel.
Factura W debe ser entero positivo canónico de hasta 12 dígitos. No se asigna
identidad desde fila, nombre, fecha, RUT o importe. Factura vacía, `S/F`,
`x fact` u otro texto queda pendiente. No inferir boleta ni nota de crédito.

Agrupar todas las apariciones de una factura antes de decidir. Una factura con
varios RUT o fechas queda íntegramente pendiente: no separar por fecha/RUT para
hacerla parecer válida. RUT se normaliza como CSV v1 (forma, no dígito verificador).
RUT ausente/inusable queda pendiente, sin asociación por nombre.

- Factura nueva no ambigua: `xlsx:freeplast:invoice:<W>`. Fecha/RUT son atributos
  comprobados, no parte de identidad. Revisar también colisiones con el registro
  completo en ticket 3, incluidas ventas XLSX ya aplicadas.
- Factura anterior: conservar ID exacto solamente si evidencia, registro actual
  y fila coinciden en los siete campos. Una factura anterior de una línea que
  ahora tiene varias queda pendiente: nunca ampliar/sustituir automáticamente.
- Las líneas carecen de identificador independiente en el Excel. Se conservan
  como **snapshot inmutable de la factura**, ordenado por `fingerprint` de su
  contenido normalizado. Este digest NO es identidad comercial ni autoriza
  append/update de líneas. Si cambia el snapshot de una factura ya poblada,
  conflicto de entidad completa. Líneas idénticas indistinguibles: pendiente,
  no adivinar si son duplicación accidental o dos ventas legítimas.
- `source_row`, hash del archivo, hoja y celdas originales son procedencia,
  nunca identidad. Reordenar filas no cambia el snapshot comercial.

## Importes y movimientos

Decimales XML como strings canónicos, máximo 12 dígitos enteros y 12 fraccionales;
rechazar notación exponencial/localizada/no finita fuera de este contrato.
Sin float, conversión a entero ni redondeo silencioso. K es cantidad entera;
L/M/O pueden tener fracción. `fpw_workbook_sum` suma exactamente valores positivos.
Los valores antiguos enteros se comparan por su representación decimal exacta.

O es total de **línea**, no total repetido de factura: las fórmulas almacenadas
inspeccionadas usan `M*(1+N*0.19)` y M usa `L*K`. Total factura = suma de O una sola
vez por línea. No recalcular impuestos ni sustituir cachés. R incluye flete en
parte del libro y está vacío en otras filas: no sumarlo junto con O, ni usarlo
como total comercial alternativo. Q/S/R permanecen detalle de origen. Descuento
de flete negativo en S no convierte por sí solo una compra en devolución.

Pago vacío, pendiente o abonado NO invalida la venta. Notes vacías/`OK` no
bloquean. Otras observaciones requieren revisión conservadora, incluso si parecen
logística/pago: no decidir automáticamente su significado comercial. Marcadores
`anul/cancel`, `devol/devuel`, `crédito/ajuste` en observación/pago se distinguen
como `cancellation`, `return`, `adjustment`, pero quedan pendientes de identidad y
relación documental explícitas, nunca entran al total de compras. Valores
comerciales negativos también requieren revisión. No deducir un movimiento de
cero ni de factura ausente. La fila original conserva evidencia para el dueño.

## Conciliación oficial (lectura DEV, 2026-10-18)

Evidencia identificable privada: `~/.local/state/freeplast-sales-import/reconciliation-tlr8/`.
Libro actual SHA-256 `4b52e5bcba620e8f5a9b2211275f0b182c0f413851ad565c3e8c0ee7f61a7572`,
403676 bytes, **1700 registros**, 53 filas XML vacías y un encabezado.
Libro anterior: 1660 registros, SHA-256
`2d78d3a4783e66b71e3543654ab966dd4f16a2f29f89cbd0a4f56e13353be66c`.
No asumir que los 40 registros netos son 40 ventas nuevas: cambiaron posiciones,
RUT y contenido. El inventario de facturas tiene 12 apariciones menos y 52 más
(neto +40); no equivale a autorizar borrados ni a identificar filas renumeradas.
La suma O anterior era 3104747222.821: diferencia exacta +78928991.100.
El informe privado conserva cada diferencia de inventario sin inferir identidad
entre filas sin documento estable.

| Clase de mapeo | Entidades | Filas | Suma exacta O |
|---|---:|---:|---:|
| Conciliable y habilitada | 319 | 319 | 762785716 |
| Nueva demostrable | 966 | 1126 | 1984839361.321 |
| Pendiente, sin aplicar | 176 | 255 | 436051136.6 |
| Total | 1461 | 1700 | 3183676213.921 |

141 facturas nuevas habilitadas son multilínea. Los conteos pendientes incluyen
filas sin factura como entidades de revisión, no como ventas identificadas.
Razones pueden superponerse: 27 sin identidad documental, 91 observaciones,
35 grupos con líneas indistinguibles, 18 con fechas contradictorias, uno con
RUT contradictorios, dos movimientos especiales y 15 conflictos de RUT antiguo.

Las **356 ventas DEV** coinciden exactamente con los campos del CSV aplicado y
enriquecimiento anterior; se conserva su registro y procedencia. Todas tienen
factura localizable en el libro actual. 341 coinciden en campos comerciales:
319 habilitadas y 22 pendientes por observaciones. Otras 15 cambiaron RUT y
quedan bloqueadas, con ambos valores en evidencia privada. **No sobrescribirlas
ni importarlas con ID nuevo.** No son 356 enriquecimientos habilitados.

Delta máximo conservador inicial: añadir 966 entidades, conservar las 356
existentes (1322 en total). Enriquecimientos de procedencia/detalle requieren
comparación de campos vacíos en ticket 3; no están autorizados por un conteo.
Repetición debe producir cero entidades nuevas. Nuevos archivos pueden tener
otro delta: comparar preview completo contra el hash/conciliación vigente.

## Fuera de este corte

No upload, UI, schema migration, confirmación, cancelación, navegación ni cambios
DEV. Tickets 2/3 deben integrar el mapper, persistir decimales/snapshot sin romper
historial CSV antiguo, comparar entidades completas, bloquear conflictos sin
parcialidad, conservar recibos y procedencia y mostrar pendientes al dueño.
Ticket 4 debe verificar hash, respaldo, ensayo y delta antes de escribir DEV.
