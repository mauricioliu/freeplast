# Actualización comercial: mínimos, contacto/dirección y cotización (pasos 2, 4, 6)

Registro de los cambios sin confirmar del worker impl-1. Todo verificado offline; sin stack, navegador, correo ni producción.

## Paso 2 — Mínimos por producto (`wordpress/data/products.json`, SHA256 `1b3ef16a027b3f68b4704efb6c9d9f2d5e164a93c19f938fdf85cb0ecb38d088`)

- 12 productos con unidades/pallet confirmadas por OCR (paso 1): `minimum_quantity = units_per_pallet` (mínimo un pallet).
- 3 traversas (UPC, G1, Romano): mínimo 50 unidades, excepción confirmada por el propietario.
- Pediluvios y ladrillo plástico: `minimum_quantity` se mantiene `null` (sin dato en el catálogo); solicitables con «mínimo por confirmar» y revisión de línea por el propietario. No se archiva ni se inventa mínimo.
- `quantity_step = 1` en los 17: solo mínimos, nunca múltiplos obligatorios.
- El release técnico sellado se preserva inmutable en `wordpress/scripts/catalog-pdf-release/products.json` (SHA256 `a88c84feddf1ad54f10587d23cd99a3e1d3c8d110b1d88768350d7a7e84e9f3a`); `package-catalog-pdf.py` y `update-catalog-pdf.php` empaquetan/leen ese sellado, no el comercial.
- Migración one-shot a staging: `wordpress/scripts/update-catalog-minimums.php` (WP-CLI, solo staging Freeplast, plan/apply con token, respaldo verificado, maintenance y escritores pausados). Fija el hash actual de `products.json`: cualquier edición posterior al JSON exige revisar un plan nuevo.
- Pruebas: `wordpress/scripts/catalog-minimums-test.php` (17 identidades, excepción traversa, desconocidos sin mínimo, drift de merchant rechazado, commit incierto nunca re-ejecutado) y `catalog-pdf-test.py` (delta exacto contra el sellado).

## Paso 4 — Contacto y dirección

- `front-page.html`: «Visítanos» ahora muestra **P Picarquín sitio 52, San Fco de Mostazal, VI Región** sin el enlace de mapas retirado (apuntaba al Arrayán); WhatsApp y correo ya estaban. Guard offline: `commercial-source-test.py`; guard de stack: `check.mjs` 14.3 actualizado (exige la dirección nueva y la ausencia del enlace retirado).
- Snapshot de emisor (paso 6) usa la misma dirección aprobada.
- **Origen de despachos sin cambiar**: `FPW_DISTANCE_ORIGIN` sigue «Camino El Arrayán 52, San Francisco de Mostazal» en `dispatch-distance.php`. La instrucción fue revisarlo por separado; no hay coordenadas verificadas para Picarquín, así que no se cambió ni se inventó. Decisión pendiente del propietario.
- **Superficie DB /contacto/**: el contenido de la página vive en la base (sembrado por el plugin retirado; ver `wordpress/legacy/…/class-shell.php`). Sigue mostrando la dirección y el enlace antiguos; vía custodiada preparada más abajo («/contacto/: vía de migración custodiada»), pendiente de ejecución con aprobación.

## Paso 6 — Cotización completa (`quotation-document.php`, `quotation-approval.php`)

- Snapshot de emisor congelado al aprobar (`fpw_quotation_issuer_snapshot`): razón social, RUT, dirección, teléfono, correo, banco (Banco de Chile, cuenta corriente 390400602) y términos (stock sujeto, contado transferencia; retiro solo cuando no hay despacho), todos verificados del adjunto Atama y del contrato.
- Logo oficial: `pdf-assets/logo.png` (365×227) es conversión **pixel-idéntica** de `assets/logo.webp` del repo (diferencia media 0 vía ImageMagick; coincidencia visual con el logotipo del adjunto ya documentada en `quotation-source-review/README.md`). SHA256 `a3e3db4a40679b15121e3a59e676c86fe51341d48147306beac30b75e23a3523`, fijado en `quotation-pdf.lock.json` y dentro de cada snapshot.
- Inmutabilidad: el render lee solo el snapshot de la versión (`logo_base64` + `logo_sha256` validados, escape de todos los campos); las versiones históricas sin `issuer` se renderizan como siempre (marca `mark.svg`, sin banco) y jamás se enriquecen; un reintento sobre versión existente preserva los bytes exactos del PDF y no reenvía correo. Cambios posteriores de defaults (p. ej. vigencia) no reescriben versiones aprobadas.
- Vigencia por defecto 15 días (filtro `fpw_quotation_default_validity_days`), confirmada por el propietario.
- `data://` habilitado en dompdf únicamente para el logo embebido desde el snapshot.

## Muestras

- `sample-quotation.pdf` (2 páginas): comprador ficticio «MUESTRA-NO-EMITIDA», NÚM 1761 citado (sin asignación real), despacho, vigencia 15 días, logo y banco embebidos; cantidades válidas por mínimo. Regenerable con `wordpress/scripts/generate-sample-quotation.php` (ver sección de paso 8).
- `.scratch/commercial-update/sample-multipage.pdf` (8 páginas, ignorado): retiro, 40 líneas ficticias; verifica paginación útil: cabeceras de tabla repetidas por página y bloque final resumen + términos + banco junto. PNG de revisión visual junto al PDF (este worker no pudo inspeccionarlos visualmente; solo verificación estructural de límites de página).

## Gates

- `PHP_BINARY=/home/mauricio-liu/.local/bin/php FREEPLAST_SKIP_STACK=1 npm test`: PASS, 1952 checks + suites nativas.
- `git diff --check`: PASS.
- Stack/nativo HTTP no ejecutado (restricción del contrato).

## /contacto/: vía de migración custodiada (paso 4, nivel fuente)

La página vive en la base (sembrada por el plugin retirado, migración 5) y sigue mostrando la dirección y el enlace antiguos. Preparada, **no ejecutada** contra staging: `wordpress/scripts/update-contacto-address.php` (WP-CLI `plan|apply|rollback`, ayuda con `help`). El plugin retirado es solo historia fuente: los bytes sembrados exactos se citan en `wordpress/scripts/lib/contacto-address.php`.

**Release (solo staging, tras aprobación del propietario):**
1. Respaldo pareado DB+archivos y verificación independiente (el flag reconoce evidencia, no la prueba).
2. `touch <ABSPATH>/.maintenance` y pausar escritores.
3. `FREEPLAST_CONTACTO_BACKUP_VERIFIED=1 wp eval-file wordpress/scripts/update-contacto-address.php plan /private/contacto-review.json` → revisar `before`/`after` del receipt (debe decir estado sembrado; `partial`/`unknown` → revisión manual, nada se escribe).
4. `wp eval-file wordpress/scripts/update-contacto-address.php apply /private/contacto-review.json <token>` → `status: applied`.
5. Verificar: `wp post get <page_id> --field=post_content` y el check 14.5 de `check.mjs` (Picarquín presente, sin enlace retirado).
6. Retirar `.maintenance` solo tras la verificación independiente.

**Rollback:** mismo receipt y token: `wp eval-file wordpress/scripts/update-contacto-address.php rollback /private/contacto-review.json <token>` → restaura los bytes exactos originales; repetirlo es no-op (`already original`). Si el contenido cambió tras el apply, el rollback se niega (drift): revisar contra el respaldo pareado, nunca re-ejecutar a ciegas.

**Garantías (probadas offline en `contacto-address-test.php`):** contenido merchant arbitrario preservado byte a byte fuera de los dos bloques sembrados, barras invertidas y comillas incluidas (escritura SQL literal preparada, nunca `wp_update_post`, que las eliminaría); negativa ante drift post-plan, estado parcial o desconocido; esquema de receipt íntegramente validado (identidad actual, hashes recomputados, token ligado, `after` = SOLO la transformación aprobada de `before`); identidad de página (tipo/slug/ID actual) verificada; receipt privado 0600 fuera del webroot, ruta nueva exclusiva. **Escritura atómica**: un único `UPDATE … WHERE ID = %d AND post_content = CAST(%s AS BINARY)` bajo autocommit (compare-and-swap custodiado por los bytes viejos esperados, byte-exacto bajo cualquier intercalación): una edición concurrente nunca se pisa — el CAS falla y exige replanificar; resultado `false` se reporta como incierto, nunca se re-ejecuta a ciegas. Sin ventana transaccional ni locks consultivos. Re-ejecución siempre no-op; rollback restaura los bytes exactos originales.

Nota de gate: `check.mjs` 14.3/14.5 ahora exigen el estado aprobado; el gate de stack exige aplicar esta migración antes de pasar.

## Paso 3 — Mínimos consistentes en el flujo público y privado

Fuente: `fpw_quote_minimum()` lee la meta nativa `_fp_quote_min_qty` (entregada por la migración one-shot ya revisada, aún no aplicada a staging); ausente = **desconocido** (null), jamás 1 confirmado. `fpw_quote_minimum_parent_id()` resuelve SIEMPRE la identidad del padre: las variaciones no tienen meta propia.

**Superficies vivas** (la canasta es el bloque nativo Cart de Woo 11.1.0, Store API):
- **Ficha**: `woocommerce_single_product_summary` → «Mínimo por solicitud: N unidades» o «Mínimo por confirmar…» explícito.
- **Canasta (bloque Cart)**: `woocommerce_get_item_data` — el seam nativo de presentación por línea que el bloque renderiza (CartItemSchema) — agrega la fila «Mínimo por solicitud: N unidades» / «Mínimo: por confirmar…».
- **Pisos de cantidad**: clásicos (`woocommerce_quantity_input_args` en ficha/canasta clásica, `woocommerce_available_variation`, selectores del loop) con paso 1 y mínimo resuelto del padre. En canasta clásica, una línea histórica bajo el piso conserva su propia cantidad como piso del input: nunca se reescribe silenciosamente hacia arriba.
- **Store API (el seam real del bloque)**: `woocommerce_store_api_product_quantity_minimum` alimenta tanto `quantity_limits` (UI) como `validate_cart_item_quantity` (update-item): el piso de la línea es SIEMPRE el resto del mínimo del producto tras sus líneas hermanas (agregación prospectiva entre variaciones), SIN relajación para líneas históricas bajo mínimo — leer conserva la cantidad almacenada intacta (el schema fijado la reporta verbatim; el seam solo entrega un mínimo), pero una actualización explícita bajo el piso se rechaza y al mínimo se acepta.
- **Servidor, agregar**: `woocommerce_add_to_cart_validation` decide por el agregado RESULTANTE (reposición de 1 sobre canasta satisfecha 70/70 pasa; canasta histórica insatisfecha no puede quedar bajo el mínimo al agregar).
- **Servidor, modificar (clásico)**: `woocommerce_update_cart_validation` usa el payload nativo `cart[clave]['qty']` (mapa por clave de ítem; la identidad de fila es la clave del array) — cantidades publicadas prospectivas para las hermanas y la entrante de la propia fila, nunca cantidades rancias.
- **Servidor, enviar**: `fpw_validate_checkout` valida la canasta completa agregada por producto entre variaciones (sin relajar).
- **Borrador privado**: cada línea muestra mínimo cubierto / faltante con cantidad pedida / «Mínimo por confirmar: revisa la cantidad con el comprador antes de aprobar».
- Registros enviados e históricos intactos; propiedad nativa Woo (meta nativa + filtros nativos).

**Pruebas con el código fijado real**: `storeapi-minimums-test.php` ejecuta el StoreApi PINNADO 11.1.0 (QuantityLimits + CartController + wc_get_quantity_input_args, copiados byte a byte a `.build`, hashes en el manifiesto) contra el plugin cargado: validación real de update-item (rechaza bajo mínimo con `invalid_quantity`, acepta exacto y no múltiplos superiores), agregación prospectiva entre hermanas (5+60<70 rechazado EN el seam, sin mutar la cantidad persistida), piso de padre para variación, histórica 30 con piso completo 70: 35 y 30 rechazados sin mutación, 70 aceptado, lectura conserva 30 por construcción (schema fijado reporta verbatim; el seam no contiene relajación a persistida), desconocidos nativos, límites de agregado al agregar (reposiciones), multi-fila clásica nativa cart[clave][qty] (35+65=100 pasa; 35+60=95 rechazado; línea sola 100 pasa/99 rechaza), y presentación por línea vía `woocommerce_get_item_data` (con prueba de que el CartItemSchema fijado realmente invoca ese seam). Ciclo rojo→verde: la suite falló primero en el hook no cableado, luego del cambio de runtime pasó.

## Paso 7 — Correlativo comercial

- `quotation-sequence.php`: serie entera propia, arranque 1761 (último usado 1760, confirmado), en tabla dedicada `{$prefix}fpw_quotation_sequence` AUTO_INCREMENT. `CREATE TABLE IF NOT EXISTS` jamás toca un contador existente: **un redeploy no reseedea** y ninguna ruta de código reescribe el valor.
- **Asignación**: un solo INSERT — el motor serializa la clave; aprobaciones concurrentes, doble clic y reintentos jamás toman el mismo número (sin ventana transaccional que custodiar). Un fallo del INSERT de la versión tras asignar consume el número (hueco documentado, nunca reutilizado ni renumerado); un fallo de asignación no deja nada escrito (state storage-failed) y el reintento asigna uno nuevo.
- **Congelado**: el número viaja dentro de la versión emitida — PDF («Cotización Nº 1761» junto a la referencia FP-YYYY-NNNNNN separada), asunto y cuerpo del correo, y pantallas privadas («Cotización Nº 1761 · serie propia confirmada por el propietario, distinta de la referencia de solicitud»). Las versiones históricas sin número no se renumeran ni rellenan.
- **Prueba sustantiva de concurrencia** (`quotation-sequence-test.php`): **prueba semántica SQLite con procesos reales, NO verificación MySQL nativa** — 8 procesos PHP reales contra un motor SQLite real (WAL): 48 números únicos y contiguos desde 1761, exactamente un ganador de la fila de versión por orden compartido (los perdedores no escriben segunda versión), redeploy posterior continúa la serie. Más: fallos inyectados de allocator/bootstrap → null sin estado parcial y la serie solo avanza. El patrón SQL (INSERT de autoincremento + fila de versión con nombre único) es el mismísimo que corre el adaptador `$wpdb`/MySQL; la verificación sobre MySQL nativo queda como paso de integración con stack.

## Paso 8 (preparación offline): muestra, comandos reproducibles y checklist de entrega

### Muestra ficticia regenerada

- `sample-quotation.pdf` (2 páginas): «Cotización NÚM 1761 · MUESTRA-NO-EMITIDA», emisor/banco/dirección Picarquín vigentes, términos stock/contado-transferencia, cantidades válidas por mínimo (Cosechera 70/70, Universal 100/100 con opción Azul), vigencia 15 días, IVA 19% (46.322) y total 290.122 CLP, logo embebido. El número 1761 va CITADO en la versión ficticia: no hubo asignación (ninguna llamada a `fpw_quotation_allocate_number`), ni correo, ni runtime — la primera asignación real también será 1761.
- `.scratch/commercial-update/sample-multipage.pdf` (8 páginas, ignorado): 40 líneas ficticias de nombre largo, términos de retiro (sin despacho); paginación útil verificada (cabeceras repetidas, bloque final vigencia+banco junto).
- Regeneración reproducible: `PHP_BINARY=/home/mauricio-liu/.local/bin/php wordpress/scripts/generate-sample-quotation.php` (recrea ambos PDF; corre primero la suite de borrador offline como carga del arnés). Verificación: `pdfinfo`/`pdftotext` del contenido anterior + `pdfimages -list` (logo 365×227 con máscara).

### Hashes fijados de las migraciones y artefactos

| Artefacto | SHA256 | Custodio |
|---|---|---|
| `wordpress/data/products.json` (mínimos comerciales) | `1b3ef16a027b3f68b4704efb6c9d9f2d5e164a93c19f938fdf85cb0ecb38d088` | fijado en `update-catalog-minimums.php`; editar el JSON exige plan nuevo |
| `wordpress/scripts/catalog-pdf-release/products.json` (release técnico sellado) | `a88c84feddf1ad54f10587d23cd99a3e1d3c8d110b1d88768350d7a7e84e9f3a` | fijado en `update-catalog-pdf.php` y `commercial-source-test.py` |
| `pdf-assets/logo.png` | `a3e3db4a40679b15121e3a59e676c86fe51341d48147306beac30b75e23a3523` | `quotation-pdf.lock.json` + snapshot de emisor |
| Migración `/contacto/` | sin hash estático: receipt con token ligado a before/after (plan→apply) | `update-contacto-address.php` |
| Tabla `wp_fpw_quotation_sequence` | nace `AUTO_INCREMENT=1761` solo si no existe | `quotation-sequence.php`; jamás reseed |

### Secuencia de migración y rollback en staging (con aprobación del propietario)

1. Respaldo pareado DB+archivos verificado; `touch <ABSPATH>/.maintenance`; pausar escritores.
2. `FREEPLAST_CATALOG_BACKUP_VERIFIED=1 wp eval-file wordpress/scripts/update-catalog-minimums.php plan /private/minimos.json` → revisar → `apply … <token>`; verificar meta `_fp_quote_min_qty` en 15 padres.
3. `FREEPLAST_CONTACTO_BACKUP_VERIFIED=1 wp eval-file wordpress/scripts/update-contacto-address.php plan /private/contacto.json` → revisar → `apply … <token>`; verificar `/contacto/` (check 14.5).
4. Confirmar próximo número ANTES de la primera aprobación real: `wp db query "SELECT COUNT(*), MAX(id) FROM $(wp db prefix)fpw_quotation_sequence;"` — tabla vacía/ausente ⇒ primera asignación 1761; si el propietario corrige el último usado, `ALTER TABLE … AUTO_INCREMENT=<siguiente>` SOLO antes de la primera emisión real; después, jamás reseed.
5. Retirar `.maintenance` tras verificación independiente.
- Rollback (orden inverso): `/contacto/` tiene `rollback` simétrico con su receipt+token; los MÍNIMOS no tienen subcomando rollback — restaurar desde el respaldo pareado o revisar un plan inverso usando los valores `before` del receipt como referencia exacta. La tabla de secuencia NO se revierte: los números emitidos son hechos comerciales; un rollback de contenido no renumera.

### Validación PENDIENTE (no corrida aquí — sin stack/servidor/dispositivos)

- [ ] Staging runtime + HTTP real: fichas/canasta (bloque Cart) mostrando mínimos y «por confirmar», validaciones de agregar/modificar/enviar en vivo.
- [ ] MySQL NATIVO para la secuencia (la evidencia offline es semántica SQLite/procesos reales, no MySQL verificado): primera aprobación concurrente en staging.
- [ ] Visual/dispositivo: muestra en pantalla y móvil; el privado `http://mliu:8131/` es un destino de revisión provisto por el propietario — NO verificado aquí ni se garantiza que contenga la muestra; su apertura es paso del propietario.
- [ ] Correo real: ninguno enviado ni autorizado en esta fase.
- [ ] Paso 5 (doble envío): SIGUE BLOQUEADO — el propietario preguntará a Ignacio; no se afirma corregido. El paso 8 completo depende de 5 y de la aprobación explícita de publicación.

### Entrega

- Cambios sin confirmar en el árbol (el lead integra): plugin (mínimos/seams StoreApi/clásicos, correlativo, issuer), migraciones custodiadas (mínimos, /contacto/), suite de pruebas offline (incl. StoreApi fijado 11.1.0), documentación y muestras.
- Gate offline verde: `PHP_BINARY=/home/mauricio-liu/.local/bin/php FREEPLAST_SKIP_STACK=1 npm test` (1953 checks + suites); `git diff --check` PASS.

## Pendientes fuera de alcance

- Ejecución en staging de las migraciones (mínimos, /contacto/) y decisión sobre el origen de despachos: pendientes de aprobación del propietario.
- Paso 5 sigue bloqueado por reproducición exacta; cobertura preventiva de doble envío ya en el árbol. Paso 8 (validación y publicación) fuera de este alcance.
