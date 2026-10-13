# Catálogo PDF 2026: staging para revisión

**Publicado solo en [staging](https://freeplast.mliu.site/tienda/). Producción intacta.** Release `20261013T183512Z-763967`, fuente `a62b1a8`, tema1.0.25/adaptador1.12.10. [Registro de ceremonia y recuperación](2026-10-13-data-763967.md).

15 fichas existentes enriquecidas: material, medidas exteriores/interiores, peso, capacidad conocida, colores, uso, características y pallet. Se conservaron IDs/URLs, diez variaciones, categorías, fotografías, precios, mínimos/pasos y política comercial. JSON sigue siendo referencia; Woo es la autoridad editable. [Cambios por producto](../catalog-pdf-2026/changes.md) · [Fuentes y ausencias](../catalog-pdf-2026/transcription.md).

## Fichas publicadas

| Producto | Staging |
|---|---|
| Tote | [Abrir](https://freeplast.mliu.site/producto/tote/) |
| Cosechera 3/4 | [Abrir](https://freeplast.mliu.site/producto/caja-cosechera-3-4/) |
| Universal Cerrada Negra | [Abrir](https://freeplast.mliu.site/producto/caja-universal-cerrada-negra/) |
| Universal Cerrada Color | [Abrir](https://freeplast.mliu.site/producto/caja-universal-cerrada-color/) |
| Universal Ventilada Negra | [Abrir](https://freeplast.mliu.site/producto/caja-universal-ventilada-negra/) |
| Universal Ventilada Color | [Abrir](https://freeplast.mliu.site/producto/caja-universal-ventilada-color/) |
| Tomatera | [Abrir](https://freeplast.mliu.site/producto/caja-tomatera/) |
| Frutillera | [Abrir](https://freeplast.mliu.site/producto/caja-frutillera/) |
| Frutera | [Abrir](https://freeplast.mliu.site/producto/caja-frutera/) |
| Paltera | [Abrir](https://freeplast.mliu.site/producto/caja-paltera/) |
| Traversa G1 | [Abrir](https://freeplast.mliu.site/producto/traversa-para-bins-tipo-g1/) |
| Traversa Romano | [Abrir](https://freeplast.mliu.site/producto/traversa-para-bins-tipo-romano/) |
| Traversa UPC | [Abrir](https://freeplast.mliu.site/producto/traversa-para-bins-tipo-upc/) |
| Pollera | [Abrir](https://freeplast.mliu.site/producto/caja-pollera/) |
| Merlucera | [Abrir](https://freeplast.mliu.site/producto/caja-merlucera/) |

## Verificado

- Gate completo del driver, paquete determinista, respaldo DB+archivos, restauración MariaDB aislada, trial, instalación con maintenance,393 hashes instalados y10 smokes verdes.
- Las15 fichas coinciden en Woo y HTML con los datos revisados; diez variaciones y119 archivos de uploads intactos. Resumen compacto de cuatro datos; detalles adicionales en «Ficha técnica completa».
- Una solicitud sintética nueva **FP-2026-000075**, marcada **NO ATENDER**, pendiente/no pagada, con borrador propio. Cosechera30 + Universal Cerrada Rojo10 + Universal Ventilada Azul10. Cambio de cantidad, persistencia al recargar, variantes, confirmación y cesta vacía correctos. Sin despacho ni Maps; dos avisos bloqueados, no enviados.
- Todos los registros protegidos preexistentes conservaron sus hashes tras esa prueba; la única opción preexistente alterada fue el contador esperado de correo bloqueado21→23. Se conservan la nueva solicitud, borrador y registros de intento. **No se afirma fingerprint global idéntico después de crear el fixture; no se reiniciaron contadores.**
- Cron restaurado root:root0644 con bytes originales; correo independiente/noindex intactos; sin maintenance, lock ni guard temporal.
- Lead informó34 vistas de navegador:15 fichas a320/1440 y Universal Cerrada Color/Tote también375/768; sin overflow del documento, resumen4datos, filas técnicas presentes. Contact sheet móvil inspeccionada; Space abre/cierra details a375. Evidencia `~/.herd/plan-mode/plan-mv6vrtm2-rsaa.visual`. También verificó los10 colores (IDs17–21/parent16 y24–28/parent23), cantidad10 estable y Agregar habilitado. En su cesta anónima vacía agregó Azul27×20, cambió a30, recargó conservando identidad/color/cantidad y retiró solo esa línea; no creó solicitud permanente.

## Decisiones que no se inventaron

G2 no se equiparó a Romano; categorías conflictivas quedaron como estaban. Tote interior conserva «unidad no indicada en PDF». Frutera retiene44l del resumen p.3, no atribuidos a la ficha p.14. Paltera mantiene30l literales del fabricante; capacidades ausentes no se dedujeron. Unidades por pallet son embalaje, nunca mínimo de solicitud.

## Verificación independiente del delta SQL

Solo lectura, también válida después del fixture. Compara las15 filas de producto y sus filas de atributos con el resultado exacto del **MIGRATION-BASELINE original**, sin aceptar un nuevo baseline ni ignorar campos. No imprime filas privadas:

```sh
ssh hetzner-vps 'sudo -u freeplast /usr/local/bin/wp --path=/var/www/freeplast --skip-plugins --skip-themes eval '"'"'
require "/var/lib/freeplast-release/bundles/20261013T183512Z-763967/migration/policy.php";
$b = fp_pdf_release_validate(json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR), "20261013T183512Z-763967");
if (fp_pdf_release_state($b) !== "after") { throw new RuntimeException("Raw product delta mismatch"); }
$rows = array_map(static fn($p) => fp_pdf_release_read((int)$p["before"]["post"]["ID"]), $b["products"]);
echo count($rows) . " products exact_expected " . fp_pdf_release_hash($rows) . "\n";
'"'"' < /root/freeplast-backups/releases/20261013T183512Z-763967/MIGRATION-BASELINE'
```

Resultado esperado: `15 products exact_expected a5843215971c2a1275abe89e728dd03d8e85a32e681ceffeec1df7cd6915db0b`.
Recibo privado ejecutado: `~/.herd/plan-mode/plan-mv6vrtm2-rsaa.stage-private/raw-products-after-functional.json`.

| Evidencia | SHA256 |
|---|---|
| MIGRATION-BASELINE original | `d8c5141ab886526bb097e92d6918a811ba0c800ca48a44839fd50180de70d31c` |
| products.json | `a88c84feddf1ad54f10587d23cd99a3e1d3c8d110b1d88768350d7a7e84e9f3a` |
| review.json | `4c028e9022b2328d958b937b88c70a0ac3feb625bfec15fa3b337a71e3ab7ea0` |
| migration/run.php | `1425e26ec328a42e045600a234fc53dba5ad9a9b0aaafc2c9e7cce3c97cc40d0` |
| migration/policy.php | `9109a428c8715dc2743a071abc1ec0dba5a37aa686a873fa25e9df62c646f742` |
| catalog-pdf-release-fingerprint.php | `afeb3eae461590561fa8484fe5b51bae1cc44907246ee0ed5780b9f40de42590` |
| content-single-product.php | `65c2bce2dda54b6f84bab6269152607191265ca77ce58fb8c654c0098a00e992` |
| INSTALLED-SHA256SUMS (393 archivos) | `260f349e80636b97e5645bc6bd560d4be03ad000f1f4955b8f6199088c757684` |
| Cron restaurado | `acdce0f08554cf34c4a62c0316b0dff873ec24ec61d125befe7240184f270285` |
| Mail shim sin cambios | `71164446f3f7432311ed917e412c161f8076acda57a4b3eb02b095a63009d5f1` |

Respaldo privado: `/root/freeplast-backups/releases/20261013T183512Z-763967`.
Bundle sellado: `/var/lib/freeplast-release/bundles/20261013T183512Z-763967`.
Cron original/evidencia: `/root/freeplast-release-operations/catalog-pdf-20261013T183512Z-763967`.
Fingerprint protegido de la ceremonia, **antes del fixture**: `2c734949b0ed010cdeb7bcb699ac773d63b93e375c231bc3b3c6f37e50d92966`.

## Verificación independiente del lead

Lead confirmó60 aserciones nativas contra el preview original:15 productos/10variaciones, cero diferencias técnicas pendientes, campos ajenos a los datos técnicos y variaciones completas intactos, staging/noindex/tema1.0.25. Ejecutó independientemente el comando SQL:15 productos `exact_expected`, hash `a5843215971c2a1275abe89e728dd03d8e85a32e681ceffeec1df7cd6915db0b`. Baseline original, cron root:root0644, policy/run de migración y plantilla instalada coinciden con los hashes documentados; maintenance ausente. Evidencia: `~/.herd/plan-mode/plan-mv6vrtm2-rsaa.lead-native-data-gate.json` y `~/.herd/plan-mode/plan-mv6vrtm2-rsaa.lead-raw-gate.txt`. Sin nuevo despliegue.

## Límites y aprobación

Pendiente aprobación humana del contenido/presentación y autorización separada de producción. Emulación y HTML no equivalen a aprobación en teléfono físico. No prueba de entrega real de correo, ninguna operación Maps ni rollback en vivo. El trial probó aplicación transaccional exitosa; no se inyectó un fallo a mitad de la transacción. Restaurar el respaldo completo descartaría el fixture y escrituras posteriores: requiere decisión explícita de recuperación, nunca replay automático.
