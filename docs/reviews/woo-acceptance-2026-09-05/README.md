# Revisión posterior a la migración Woo — 2026-09-05

**Resultado: NO listo para aceptación operativa.** El recorrido normal funciona, pero la concurrencia crea solicitudes duplicadas y el rol de ventas no tiene acceso al administrador nuevo. No se aplicaron correcciones ni se desplegó otra versión.

## Alcance y límites

Petición del propietario: ejecutar revisión visual/móvil, permisos de ventas y pruebas técnicas adicionales. Sólo `https://freeplast.mliu.site/`; producción `freeplast.cl` no se modificó. Versiones: adaptador 1.0.2, Woo 11.1.0, Quotes for WooCommerce 2.13, tema 1.0.0.

- Navegador Chrome existente mediante pi-browser-harness. Su sesión ya estaba autenticada como administrador: **las observaciones visuales no representan una sesión anónima**. No se alteraron cuentas ni roles. La regresión HTTP y la prueba concurrente usaron sesiones anónimas independientes.
- Inspección de renderizado a 412 px y checkout a 1440 px; diagnóstico geométrico a 320/375/412/1440 px. **Esto no valida móvil ni hardware**. No se usaron dispositivos, adb o servidores de desarrollo.
- Axe-core 4.10.3: WCAG 2 A/AA, 2.1 AA y 2.2 AA. Cinco páginas completas, excluyendo únicamente `#wpadminbar`; también formulario con errores dentro de `main`. Un resultado sin violaciones automáticas no equivale a conformidad ni sustituye lector de pantalla.
- Correo real sigue bloqueado por el MU plugin independiente. No se enviaron comunicaciones externas.

## Hallazgos prioritarios

### WA-01 · Alta · Dos envíos concurrentes crean dos solicitudes

**Reproducción ejecutada:** dos POST simultáneos de checkout con la misma cookie de sesión, nonce, datos y líneas; antes de abrir cualquier confirmación, un tercer POST secuencial del mismo formulario.

- Primer par: ambos devolvieron `success`, con pedidos **68 y 67**, respectivamente.
- Base de datos: dos pedidos distintos, mismo `cart_hash`, mismos datos originales y mismas líneas: producto 22 ×140 y variación 54 del producto 25 ×5.
- Reintento posterior: `failure`, «Lo sentimos, tu sesión ha caducado. Volver a la tienda». Carrito vacío. No devuelve la confirmación original.
- No se observó cobro ni reducción de stock. Ambos permanecen `pending` / `quote-pending`.

**Consecuencia:** la desactivación del botón en el navegador no garantiza idempotencia del servidor. No afirmar que esta migración resuelve duplicados o recuperación tras perder una confirmación.

Evidencia: `evidence/concurrency.txt`, `evidence/post-check.txt`. Se conserva el probe en `evidence/concurrency-probe.py`, con ayuda de CLI corregida para advertir hasta tres pedidos; es un **reportador de resultados, no un test CI con aserción de unicidad**. La carrera se ejecutó una sola vez y produjo el fallo; no se midió frecuencia ni se atribuye una causa interna definitiva.

Siguiente corrección: deduplicación/serialización acotada en el envío nativo Woo y recuperación de su resultado, manteniendo Woo como dueño del carrito, sesión y pedidos. Repetir el probe debe devolver un único pedido, sin reconstruir el sistema de solicitudes.

### WA-02 · Alta · Ventas quedó sin permisos Woo

Inspección de roles y usuarios en staging:

- `ventas_freeplast`: `read` + `manage_freeplast_quotes`; **sin** `edit_shop_orders`, `edit_others_shop_orders` o `read_private_shop_orders`.
- `shop_manager`: permite gestionar pedidos, pero también **borrarlos**, editar productos y `manage_woocommerce`.
- Sólo existe un usuario administrador; cero usuarios asignados a cualquiera de esos dos roles.

La migración conservó el rol antiguo pero no lo adaptó. Usar administrador o asignar `shop_manager` no demuestra mínimo privilegio. El plugin de cotizaciones además exige `manage_woocommerce` para completar/enviar cotizaciones (inspección de `class-quotes-wc.php`, handlers `qwc_update_status` / `qwc_send_quote`).

**Pendiente:** acordar capacidades de ventas, adaptar el rol y probar una sesión real restringida: listar/ver pedidos, nota privada y operaciones aprobadas; denegar configuración, catálogo, borrado y operaciones ajenas al alcance. No se creó una cuenta ni se ampliaron permisos en esta revisión.

### WA-03 · Media · Cambio de cantidad fallido sin explicación visible

En el Cart block con 5 unidades rojas:

1. Se desactivó la red sólo para la pestaña mediante CDP.
2. Clic nativo en `+`: muestra 6 y desactiva temporalmente «Datos y envío».
3. La solicitud `cart/update-item` falla con `ERR_INTERNET_DISCONNECTED`; vuelve a 5 y habilita continuar.
4. No se encontró aviso de error en el árbol accesible/DOM al inspeccionar tras el fallo.
5. Restaurada la red, nuevo clic guarda 6; checkout muestra ×6.

El dato no quedó falsamente guardado y el reintento funciona, pero falta explicar que el cambio no se guardó. No fue una prueba de timeout ni de respuesta de envío cortada en tránsito. La red quedó restaurada.

### WA-04 · Media · Ocho enlaces sin nombre accesible en Home

Axe: `link-name`, impacto `serious`, ocho enlaces vacíos/tabulables en las tarjetas destacadas, selector `.post-<id> > p > .woocommerce-LoopProduct-link.woocommerce-loop-product__link` (IDs 22, 23, 24, 27, 28, 29, 35, 31). El árbol accesible también mostró enlaces sin etiqueta.

Corregir el marcado que genera esos enlaces, no ocultar el hallazgo del auditor. Evidencia: `evidence/axe-home-full-412.json`.

### WA-05 · Media · Estado de variación sólo visual y contraste insuficiente

En Caja Universal Cerrada Color, antes de elegir color:

```html
<button type="submit" class="single_add_to_cart_button button alt wp-element-button disabled wc-variation-selection-needed">Agregar a Productos a Cotizar</button>
```

No tiene `disabled` ni `aria-disabled`. Axe mide contraste **3.51:1** para texto blanco de 16 px sobre `#8880c8`; por eso lo evalúa como botón disponible. Ajustar semántica del estado e indicación para elegir color, y el contraste que corresponda. No basta con considerar la clase `disabled` una semántica accesible.

Evidencia: `evidence/axe-variable-full-412.json`. Hay además resultados `incomplete` que requieren revisión manual; no se clasificaron como violaciones confirmadas.

## Resultados favorables, con su alcance

- `npm test`: **16 aserciones de campos + 13 comprobaciones sintácticas/dependencias/despliegue**, offline.
- Verificación read-only del servidor: **62 checks antes**, **68 después**. Los seis adicionales corresponden a las dos comprobaciones por cada nuevo pedido técnico; no son seis funcionalidades nuevas.
- Regresión HTTP anónima normal: selección de color obligatoria, cantidad 70→140, cinco unidades rojas, rechazo de RUT vacío y despacho sin dirección, envío y confirmación correctos, carrito vacío. Pedido **66**.
- Los tres pedidos nuevos están impagos, no requieren pago y no redujeron stock. Sin despacho, la dirección quedó vacía. Catálogo y solicitudes históricas siguieron pasando las comprobaciones.
- Acceso anónimo a las confirmaciones 67/68 **sin clave**: HTTP 200, pero no muestra el nombre sintético ni el producto comprobado. Prueba acotada; no es auditoría integral de autorización.
- Plantillas de email de solicitud interna y de cliente: renderizan producto y RUT sin símbolo `$`. No se invocó su envío para esta inspección. Hash del MU plugin coincide con el repo; contador final de supresiones: **9**. Esto no acredita entregabilidad ni exhaustividad de todos los formatos de correo.
- Administrador: pedido 66 muestra datos fiscales/despacho; una **nota privada** agregada mediante botón nativo se guardó y se verificó en servidor. No se ejecutaron cambios de estado ni «Cotización lista»/envío/reenvío.
- Checkout: despacho muestra dirección con `required` y `aria-required`; errores de campos generan resumen y errores inline. Tab desde resumen enfoca el enlace del teléfono, con contorno visible; Enter enfoca `billing_phone` sin quedar oculto en la observación.
- Borrador: nombre, giro, mensaje y despacho sobrevivieron checkout→cart→checkout. No hubo envío válido desde la sesión administrativa del navegador.
- Menú estrecho: abre y Escape cierra, devolviendo foco al botón de apertura.
- Axe completo sin violaciones automáticas en catálogo, Cart y Checkout; el formulario con errores tampoco arrojó violaciones en `main`. Home y ficha variable **sí** tienen los hallazgos anteriores.

## Observaciones de renderizado (no aceptación móvil)

- En las cinco páginas, `document.scrollWidth` no superó el viewport en 320/375/412/1440 px. El diagnóstico encontró la imagen interna de zoom de ficha fuera del rectángulo; no produjo desbordamiento documental y no se clasificó como defecto visual.
- En las capturas observadas: Home/catalogo usan dos columnas de productos a 412 px; botones del catálogo parten el texto en tres líneas y sus alturas/posiciones varían con el título. Checkout apila campos y mensaje a 412 px y usa dos columnas a 1440 px. Cart muestra imagen, variante y cantidad con CTA inferior. Su botón conserva apariencia gris de Woo, diferente del azul de catálogo: consistencia visual pendiente de criterio humano.
- Selector nativo de orden del catálogo: altura medida **19 px**. Es una observación de facilidad de toque, no un fallo WCAG automático (controles nativos tienen excepciones).
- Persisten términos auxiliares «carrito», «Facturación» y «Medios de pago» en el árbol accesible/resúmenes; el recorrido principal sí dice **Productos a Cotizar** / **Datos y envío**.
- Capturas de esta sesión, sólo suplementarias: `/tmp/freeplast-review-20260905/`. No se publicaron capturas ni sesiones del navegador.

## No ejecutado / no aprobado

- Teléfono físico, gestos, teclado móvil, zoom del dispositivo y lector de pantalla real. Requiere revisión humana; emulación no lo sustituye.
- Login y flujo con una cuenta de ventas restringida: bloqueado por WA-02; sólo capacidades y flujo administrador inspeccionados.
- Correo real: deliberadamente no habilitado. La contención permanece activa.
- Core Web Vitals / Lighthouse: el MCP `performance_start_trace` no está disponible. No se inventaron LCP/INP/CLS ni se lanzó otro Chrome para suplirlo. Hace falta habilitar el tooling de rendimiento; tampoco hay datos de campo.
- HPOS: staging tiene `woocommerce_custom_orders_table_enabled=no`, sincronización desactivada. Sólo modo de pedidos tradicional probado; no se cambió el modo en vivo.
- Flujos comerciales, estados finales de ventas y acciones de email/precios del plugin siguen requiriendo acuerdo. El administrador ofrece estados «Procesando/Completado/Reembolsado» y controles de envío: que estén visibles no autoriza usarlos ni prueba su adecuación.
- No se cierran en bloque UX-01–UX-17 ni las decisiones comerciales pendientes.

## Registro operativo y reproducción segura

Antes de crear nuevos pedidos de prueba se tomó respaldo emparejado de la versión **1.0.2**:

`/root/freeplast-wordpress-backups/20260905T124710Z`

Restauración aislada ensayada: **17 productos / 4 pedidos / 2 solicitudes originales** en ambas pilas. La pila temporal fue desmontada. Es un respaldo **anterior** a los pedidos 66–68, no del estado posterior a esta auditoría.

Pedidos técnicos conservados: anteriores **63/64**, nuevos **66/67/68**. Los nuevos usan `example.invalid`, identificadores de prueba y textos «NO ATENDER»/no comerciales. No procesar ni eliminar automáticamente. Se agregó una nota privada técnica únicamente al 66.

La pestaña administrativa abierta para esta revisión se cerró. Se retiraron por el control nativo las seis unidades de prueba del navegador; estado vacío confirmado y pestaña devuelta a Home. No se cerró sesión ni se eliminaron cookies globales.

Comandos ya ejecutados:

```bash
npm test
python3 wordpress/scripts/verify-woo-http.py --execute-staging
python3 /tmp/freeplast-review-20260905/concurrency.py --execute-staging
# Servidor, desde /opt/freeplast-wordpress:
docker compose run --rm -T cli wp eval-file /bundle/verify-woo-state.php
```

Para reproducir concurrencia desde el repo, el probe conservado es `python3 docs/reviews/woo-acceptance-2026-09-05/evidence/concurrency-probe.py --execute-staging`. **No ejecutarlo automáticamente**: genera hasta tres pedidos marcados por ejecución, requiere respaldo y confirmar primero contención de correo vigente. Reutiliza el preámbulo de `wordpress/scripts/verify-woo-http.py`; cambiar ese archivo puede exigir adaptar el probe. El envío concurrente debe evaluarse por el número de pedidos retornados y el estado del servidor, no por el exit code de este reportador.

Evidencias versionables en `evidence/`: salidas acotadas, sin cookies, nonces, claves de pedido ni datos personales de clientes. Diagnósticos completos/axe de terceros permanecen en `/tmp`; no publicar esa carpeta en bloque. Los probes PHP copiados al servidor bajo `bundle/review-*.php` son de inspección manual por WP-CLI, no hooks activos.
