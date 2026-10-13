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

- `sample-quotation.pdf` (2 páginas): comprador ficticio «MUESTRA-NO-EMITIDA», despacho, vigencia 15 días, logo y banco embebidos. Generada por la suite offline, no por una emisión real.
- `.scratch/commercial-update/sample-multipage.pdf` (8 páginas, ignorado): retiro, vigencia 7 explícita del fixture; verifica paginación útil: cabeceras de tabla repetidas por página y bloque final resumen + términos + banco junto. PNG de revisión visual junto al PDF (este worker no pudo inspeccionarlos visualmente; solo verificación estructural de límites de página).

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

## Pendientes fuera de alcance

- Paso 3 (mostrar mínimos y validar en el flujo) y paso 7 (correlativo comercial) sin implementar: sus prerrequisitos aún no están integrados.
- **Ejecución** de la migración /contacto/ en staging y decisión sobre el origen de despachos: pendientes de aprobación del propietario (la vía y su rollback ya están listos, ver arriba).
- Paso 5 sigue bloqueado por reproducición exacta; cobertura preventiva de doble envío ya en el árbol.
