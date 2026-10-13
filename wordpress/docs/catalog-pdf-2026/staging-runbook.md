# Actualización técnica PDF: procedimiento pendiente

**Estado: preparada localmente, NO aplicada ni entregada en staging.** Ningún acceso remoto, servidor de desarrollo, navegador, dispositivo o producción durante este trabajo. Herramienta manual: `wordpress/scripts/update-catalog-pdf.php`, biblioteca `scripts/lib/catalog-pdf.php`. No se registra en WordPress, cron ni bootstrap. No usar `migrate-to-woo.php` ni el sincronizador retirado.

## Alcance exacto

15 productos existentes, resueltos por `_fp_source_id` único + SKU idéntico + slug + tipo simple/variable. Se rechaza cualquier identidad ausente/ambigua, sin crear productos ni recurrir a títulos. Los dos productos fuera del PDF no participan.

Lista permitida: descripción y extracto revisados; atributos locales Material, Medidas, Peso propio, Uso, Unidades por pallet, Capacidad, Medidas interiores, Color (solo simples), Características, Medidas del pallet. Se conservan atributos adicionales y flags de atributos existentes. Taxonomía o atributo de variación en un campo técnico: conflicto fatal. Color de productos variables y todas las variaciones intactos. Datos desconocidos no borran datos nativos. No modifica títulos, slugs, categorías, SKU, fotos, precios, mínimos, pasos, pedidos, solicitudes ni política comercial.

Referencia sellada por SHA256 dentro del entry point. Cambiar JSON exige revisar el nuevo patch y pruebas, no cambiar el sello para pasar un error. `plan` produce archivo exclusivo 0600 fuera de webroot con diff completo, snapshot de productos/variaciones y token ligado a estado+fuente. Nunca sobrescribe un recibo. `apply` exige el mismo token y estado nativo, identidad exacta de staging, maintenance, reconocimiento de respaldo/rehearsal y escritores pausados. Lock asesor impide dos instancias del tool; transacción InnoDB y locks de filas; relectura antes de guardar; comprobación posterior de diff vacío, variaciones y campos protegidos. Resultado incierto: detener, inspeccionar, no repetir automáticamente.

## Antes de ejecutar (operador autorizado por separado)

1. Obtener aprobación de los datos y autorización expresa para staging; resolver qué hacer con pendientes de `comparison.md`. No asumir que G2 = Romano ni cambiar categorías. Confirmar retención de Frutera 44 l desde resumen p.3 y Paltera 30 l literal.
2. Gate completo `npm test` en entorno autorizado a levantar stack desechable. Este worker solo ejecutó `FREEPLAST_SKIP_STACK=1 npm test`. Trial de la herramienta en copia aislada MariaDB del staging todavía **no ejecutado**; los tests PHP usan dobles de frontera nativa y no certifican persistencia real.
3. Seguir `../releases/native-staging-runbook.md`: Hetzner nativo, no comandos históricos OpenClaw/Compose. Usar ceremonia DATA de `wp-release`, respaldo pareado DB+archivos con hashes y restore rehearsal. Copiar el script/lib y JSON sellado al bundle privado revisado. No hay integración nueva en `wp-release.json`: preparar/revisar hook de migración y política de delta exacto antes de un release automatizado; jamás excluir catálogo entero del fingerprint para hacerlo pasar.
4. Aislar correo mediante shim existente byte-idéntico; noindex; revisar plugins/hooks de guardado para impedir efectos externos en trial. Suspender solo cron de Freeplast y toda escritura administrativa/checkout, conservar bytes del cron; no parar daemon compartido. Maintenance no bloquea WP-CLI ni jobs: las variables del tool son reconocimiento, no prueba del aislamiento.
5. Capturar fingerprint protegido completo, inventario 17 productos/10 variaciones y solicitudes, fotos, cantidades, precios y políticas. Los IDs nativos aún no se consultaron aquí. Conservar backup y recibos privados fuera de webroot/Git. Registrar rutas/hashes del respaldo y restore rehearsal. No usar una exportación de productos como sustituto del respaldo pareado.

## Comandos preparados, NO ejecutados sobre WordPress

Ejecutar como usuario de aplicación en copia trial y luego, solo con aprobación y respaldo, en staging. Paths del bundle y recibo deben ser absolutos. El tool admite solo home/siteurl `https://freeplast.mliu.site` y environment `staging`; el trial aislado conserva esa identidad, con red/correo bloqueados y sin servidor HTTP.

```sh
wp --path=/var/www/freeplast eval-file /private/bundle/wordpress/scripts/update-catalog-pdf.php help
wp --path=/var/www/freeplast eval-file /private/bundle/wordpress/scripts/update-catalog-pdf.php plan /private/operation/catalog-plan.json
```

Inspeccionar `rows[].diff` **completo** y snapshots del recibo: comparan Woo actual, no solo referencia antigua. Si hay ediciones del dueño incompatibles, detener; no sobrescribirlas porque el PDF sea más nuevo. El token lo copia el operador solo después de revisar. Generar un plan nuevo tras cualquier drift y aprobarlo nuevamente.

```sh
FREEPLAST_CATALOG_BACKUP_VERIFIED=1 FREEPLAST_CATALOG_WRITERS_PAUSED=1 \
wp --path=/var/www/freeplast eval-file /private/bundle/wordpress/scripts/update-catalog-pdf.php apply /private/operation/catalog-plan.json '<reviewed-token>'
wp --path=/var/www/freeplast eval-file /private/bundle/wordpress/scripts/update-catalog-pdf.php plan /private/operation/catalog-after.json
```

Segundo plan debe mostrar `changed: 0`. Un recibo anterior a cambios no es reutilizable. El tool deja maintenance intacto incluso al terminar. Validación independiente obligatoria: delta exacto en solo los campos permitidos de esos IDs (y timestamps/lookup técnico derivados por Woo); identidad, medios, variaciones, solicitudes, precios y políticas invariantes. No tomar nuevo baseline tras escribir para esconder cambios. Trial: comprobar rollback por fallo inyectado a mitad del lote y repetición idempotente con plan nuevo. Si falla COMMIT o conexión, tratar estado como incierto; leer DB/recibo y no repetir automáticamente.

## Revisión y recuperación

- Instalar plantilla revisada mediante release de tema y verificar hashes. UI cambia al mostrar filas opcionales; resumen sigue compacto. Revisar las 15 fichas con anchos 320/375/768/1440, disclosure, unidades/colores y texto largo, teclado, foto intacta.
- Seleccionar/cambiar/limpiar las cinco variaciones de ambos Universal; comprobar producto, opción y cantidad conservados en Productos a Cotizar. Solicitud sintética solo con autorización adicional y correo contenido, siguiendo `verification-safety.md`. Ninguna solicitud real como fixture.
- Solo restaurar cron/retirar maintenance después de verificar datos, rutas y aislamiento. Solicitar aprobación humana antes de producción.
- Ante error: mantener maintenance y escritores pausados. Transacción intenta ROLLBACK, pero no prueba revertir efectos de hooks/cachés externos. Conservar evidencia; inspección independiente y recuperación pareada del backup solo con autorización, considerando escrituras posteriores. No existe restauración automática destructiva ni replay automático. Si hubiera cambios posteriores legítimos, preparar corrección limitada usando snapshot en vez de restaurar DB completa a ciegas.

## Límites presentes

Pruebas offline no prueban InnoDB/Woo real, rollback nativo, mobile, navegador, cotización o staging. El gate completo y la ceremonia DATA con delta sellado siguen pendientes; este runbook no es autorización de ejecución. Maps/AGY no usados ni desbloqueados.
