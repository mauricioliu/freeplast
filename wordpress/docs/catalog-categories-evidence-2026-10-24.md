# Categorías: contraste público vs local (ticket 3)

Fecha: 2026-10-24 · Método: lectura de solo lectura de www.freeplast.cl (archivos de
categoría nativos de WooCommerce, tema público) e inventario local provisto por el
lead (`plan-mvlk7z1x-y6s8/catalog-local-inventory.json`, http://mliu:8131).

## Evidencia pública (source URLs)

| Categoría (URL) | Resultados listados | Contador |
|---|---|---|
| [Agrícola](https://www.freeplast.cl/categoria-producto/agricola/) | Tote, Caja Cosechera 3/4, Caja Universal, Traversas para Bins Tipo UPC, Traversas para Bins Tipo G1, Caja Tomatera, Caja Frutillera, Caja Frutera | "Showing all 8 results" |
| [Carnes](https://www.freeplast.cl/categoria-producto/carnes/) | Caja Universal, Caja Pollera | "Showing all 2 results" |
| [Productos del mar](https://www.freeplast.cl/categoria-producto/productos-del-mar/) | Caja Merlucera, Caja Universal | "Showing all 2 results" |
| [Otros](https://www.freeplast.cl/categoria-producto/otros/) | Ladrillo plástico, Bases plásticas para pediluvios | "Showing all 2 results" |

El menú público "Tienda" no tiene listado propio (enlace `#`); no existe página
"Todos" pública. Nota: "Caja Universal" es un único producto público; localmente se
representa como cuatro productos (negra cerrada/ventilada + color cerrada/ventilada)
con variantes de color — pertenencia equivalente.

## Correspondencia local (inventario del lead)

- **Agrícola** (13): cosechera 3/4, tomatera, frutillera, frutera, paltera, tote,
  traversas UPC/G1/G2, 4× caja universal → público (8) está contenido; extras abajo.
- **Carnes** (5): pollera + 4× universal → coincide con {Universal, Pollera}.
- **Productos del mar** (5): merlucera + 4× universal → coincide con {Merlucera, Universal}.
- **Otros** (2): ladrillo + pediluvios → coincide exactamente.
- Multi-pertenencia permitida y usada (universales en 3 categorías); ninguna
  categoría suprimida, ningún producto retirado.

## Productos ambiguos — reportados, NO recategorizados

1. **caja-paltera** (Agrícola local): no aparece en el listado público de Agrícola
   ni en el [sitemap de productos](https://freeplast.cl/product-sitemap.xml).
   No hay evidencia pública ni para moverla ni para retirarla. Se conserva.
2. **traversa-para-bins-tipo-romano / "Traversa para Bins Tipo G2"** (Agrícola
   local): el público lista solo UPC y G1 en Agrícola; los sitemaps mencionan
   páginas "tipo W" y "traversas-para-bines". Se conserva (decisión de dueño).

El actualizador DEV existente (`wordpress/local/catalog-sections.php`, plan/apply
con respaldo) ya implementa el mapeo deseado y preserva categorías ajenas; no se
cambió metadato local alguno en este ticket.

## Corrección de consultas/contadores (código)

`inc/catalog.php::fp_catalog_filters()` mezclaba dos fuentes de conteo: "Todos"
con una consulta nativa viva (`wc_get_products` publish+catalog) y cada categoría
con `$term->count`, que en front-end Woo reemplaza por el meta `product_count_*`
cacheado en el transiente `wc_term_counts` (mes de TTL; ver
`wc_change_term_counts` en woocommerce 11.1.0). Tras ediciones programáticas de
categorías esos contadores caducan y desmienten el listado que tienen debajo.

Fix: `fp_catalog_product_count()` — una sola fuente viva por pestaña con el mismo
contrato de visibilidad que el archivo nativo (publish + no oculto del catálogo,
productos distintos; variantes de color cuentan una sola vez). Categoría vacía
sigue navegable con 0 honesto. Pruebas: `catalog-tools-test.php` (inventario de 17
productos espejo del snapshot del lead, con borrador+oculto, universales
multi-categoría y variables de 5 colores) y `catalog-selection-test.php`
(multi-pertenencia preservada, categoría faltante bloquea plan sin escrituras,
producto archivado sobrevive).

Nota de validación: el ticket nombra `discovery-test.php`; ese archivo prueba los
encabezados Link REST (`inc/discovery.php`) y no carga funciones de catálogo. Los
casos de categorías múltiples/vacías/archivados de filtros y contadores viven en
`catalog-tools-test.php` (docblock "Offline discovery boundary"), que es el que se
extendió. `discovery-test.php` se re-ejecutó sin cambios: verde.

Validación de runtime local (enlaces, resultados, contadores renderizados) queda
para el lead/visual tras integración — sin servidor ni navegador en este worker.
