# Limpieza de interfaz de release

## Estado actual: release LOGIC completado en dev; aceptación visual humana pendiente

El release `20261014T204713Z-a871c8` terminó mediante resume canónico autorizado el2026-10-14, fuente `7b3b8d5`, adapter1.12.11/tema1.0.25, solo `https://freeplast.mliu.site`. Gate nativo obligatorio, backup pareado, ensayo de restauración, trial, instalación/verificación y smoke: PASS; exit0. Inventario final395 archivos exactos a fuente, fingerprint protegido y uploads sin cambios, cron restaurado, shim intacto, sin mantenimiento ni recursos temporales activos. Registro: [`2026-10-14-logic-a871c8.md`](../../../wordpress/docs/releases/2026-10-14-logic-a871c8.md).

El primer intento falló antes de empaquetar por TMPDIR dentro del alcance ESM del repositorio; se preservó evidencia y se reanudó una sola vez con consentimiento fresco, temporal externo privado y mismas fuentes/tests. No se alteraron sellos ni baselines. **Las secciones siguientes describen etapas históricas**: sus NOT_RUN del stack y release pendiente quedan sustituidos por el gate/ceremonia posterior, no por aceptación visual, fiscal ni comercial. Producción excluida; revisión humana, píxeles, foco/teclado y dispositivo físico siguen pendientes.

Actualización `plan-mv85ckrr-iiu3`, worker `impl-1`: implementación verificada e integrada por lead en `de859e1` (base aislada inicial `6ff322c`). Seguimiento del paso 9: documentación de evidencia DOM ejecutada por lead, sin cambios de runtime. Los resultados anteriores quedan como evidencia histórica; la validación local actual figura al final.

Operación: reparación de microcopy en superficies de operación y consulta. Se conserva identidad Freeplast, idioma español, datos comerciales, formularios, autorizaciones, concurrencia, almacenamiento y seguridad de aprobación. Durante la implementación inicial no hubo publicación, instalaciones, operaciones en GitHub ni mutaciones de servidores; el release dev posterior figura arriba. Sin commits del worker ni cambios en el checkout original; el lead realizó commits acotados de snapshot e implementación en worktrees aislados.

Lecturas: catálogo de la biblioteca de diseño; `clarify.md` (principal: claridad de acciones y estados); `.agents/skills/impeccable/reference/craft-floor.md` (comprobaciones de calidad); `chrome-devtools-axi/SKILL.md` (protocolo de navegador aislado). No se rediseñaron tokens ni composición.

## Inventario de superficies

Rutas relativas a `wordpress/wp-content/`. Se revisaron implementaciones reales del tema/plugin y renderizadores ejecutados por pruebas offline, no maquetas alternativas. **No se certifica el render HTTP ni visual de todas las pantallas.**

| Superficie | Fuente / cambio | Evidencia y conservación |
|---|---|---|
| Catálogo, categorías, búsqueda, ficha | `themes/freeplast/inc/catalog.php`, plantillas WooCommerce; sin cambios | Pruebas offline de marco nativo, fichas, tarjetas, mínimos y herramientas. Se conservan fichas técnicas de productos: son información comercial, no depuración. |
| Carrito / selección | Plantillas del tema; sin cambios | `cart-page-test.php`, presentación y cantidades preservadas. |
| Checkout y dirección | Tema, `freeplast-woo.php`, `places.js`; formulario sin cambios | `checkout-form-test.php`, `checkout-places-test.php`. Identificación del lugar permanece en transporte y almacenamiento, no en hechos visibles del borrador. |
| Confirmación / recibo | Hooks y presentación de cotización; sin cambio de datos | `confirmation-test.php`, `quote-presentation-test.php`. |
| Solicitud administrativa Woo | `plugins/freeplast-woo/freeplast-woo.php` | Eliminado bloque «Datos originales recibidos» y JSON; se mantienen RUT, giro, despacho, dirección y referencias. Metadatos originales siguen almacenados. La comprobación HTTP de ventas ahora exige referencia, marcador ficticio y correo de la solicitud, no el encabezado eliminado. |
| Solicitudes del workspace | `workspace-requests.php` | Consulta y estados preservados; integración visual a confirmar con lead. |
| Borrador (incluido render administrativo alternativo) | `quote-draft.php` | Helper de datos originales retorna vacío. Helper de procedencia no muestra metadata; conserva «Revisa el número y la comuna de entrega.» para coincidencia amplia. Mensajes sin número de revisión; revisión oculta y control de conflictos intactos. Retirado botón de refresco técnico; handler y nonce conservados. |
| Distancia de despacho | `dispatch-distance.php` | Sin Place ID, procedencia ni precisión técnica visible. Conserva distancia, destino, advertencia amplia, límites de la estimación y monto manual. Errores de autorización/configuración dan una alternativa sin exponer credenciales. |
| Vista previa | `quote-draft.php`, `workspace-offer.php` | Sin revisión interna visible. Previa desactualizada explica regeneración; faltantes y bloqueo de aprobación se mantienen. Importes, IVA, total, vigencia y destino intactos. |
| Oferta aprobada / seguimiento | `quotation-approval.php`, `quotation-tracking.php` | Eliminada revisión del trabajo y explicación del servidor; se conserva versión comercial del documento, fecha, oferta fija y honestidad del envío (no prueba recepción). Conflictos y errores de seguimiento siguen explícitos. |
| Historial / importación de ventas | `sales-register.php`, `quote-draft.php` | Sin nombres de archivos ni tokens de lotes visibles. Se conservan fechas, responsables, resultados, conflictos y errores por fila, identidad de venta, RUT e importes. Tokens ocultos y recibos almacenados permanecen intactos. |
| Precios / referencias de volumen | `price-list.php`, `price-references.php` | Sin IDs técnicos de productos, fuente interna ni explicación de «centinela». Se conservan producto/opción, tramos, unidades, notas comerciales, precios y advertencia de borrado al dejar vacío. |
| Mantenedores | `data-hub.php` | Entradas de Precios y Ventas existentes, sin cambios. |

## Integración del workspace / exclusiones

- Integrados `owner-workspace.php`, `assets/owner-workspace.js` y `owner-workspace-js-test.mjs`. Retirados revisión visible, nombre de archivo del historial y disclosure «Opciones de precios / Refrescar precios». Nonce de refresco y handler siguen intactos. Esta decisión amplía la limpieza anterior de `owner-interface-simplification.md`, que aún conservaba el refresco.
- Pendiente detectado fuera del workspace: errores de `price-list.php` reflejaban claves técnicas. Ahora identifican producto/opción comercial; claves desconocidas producen recuperación genérica, sin reflejar entrada arbitraria. Validación por fila y rechazo atómico intactos.
- No se repitió el lote de nueve archivos ya incluido en la base: solicitud, borrador alternativo, despacho, previa, oferta, seguimiento, historial, precios y referencias. Catálogo/selección/checkout conservan fichas, cantidades y avisos comerciales.
- No se alteraron controles nativos de WordPress/WooCommerce, administración general, barra de administración, menús de plugins ajenos ni diálogos del navegador. El selector nativo de archivos puede mostrar el nombre seleccionado: no es un hecho técnico añadido por Freeplast.
- Se mantienen nombres de campos del formato de importación cuando permiten corregir una planilla (por ejemplo `id_venta`, CSV UTF-8), límites de carga y errores por fila. No se cambió el formato ni se ocultaron errores de negocio.
- Se mantienen referencias de solicitud, RUT, identificadores comerciales de venta y versión de cotización emitida. No equivalen a la revisión interna de edición.
- Atributos HTML, rutas de acciones, nonces, revisiones ocultas, esquemas internos, Place ID almacenado, nombres de descarga PDF y handlers no son texto visible de interfaz; no se eliminaron.
- No se tocaron vendor, backend de persistencia, permisos ni servicios de correo/rutas. Las pruebas usan fixtures ficticias locales.

## Pruebas y evidencia

Ejecutadas con `php wordpress/scripts/<nombre>-test.php`, salvo indicación. Resultados finales:

| Prueba | Resultado |
|---|---|
| quote-draft | PASS, 355 comprobaciones |
| price-list | PASS, 116 |
| sales-register | PASS, 141 |
| dispatch-distance | PASS, 58 |
| checkout-places | PASS, 92 |
| quotation-issuer | PASS, 377 acumuladas; sin correo real |
| quotation-access | PASS, 56 + 30 de denegación REST |
| workspace-offer | PASS, 82 propias, además del suite de borrador |
| workspace-chrome | PASS, 58 |
| confirmation | PASS, 28 |
| checkout-form | PASS, 247 |
| quote-presentation | PASS, 7 |
| catalog-minimums | PASS, 59 acumuladas; transacción nativa no ejecutada |
| catalog-native-frame | PASS, 50; sin HTTP |
| catalog-tools | PASS, 21; sin HTTP |
| card-theme | PASS, 25 |
| cart-page | PASS, 30 |
| product-sheet | PASS, 54 |
| accessible-names | PASS, 46 |
| `python wordpress/scripts/verify-ventas-role-selftest.py` | PASS, 7; incluye rechazo esperado de argumento no permitido |
| `python wordpress/scripts/woo-ventas-guard-selftest.py` | PASS, 3 |
| `node --check wordpress/scripts/woo-stack-harness.mjs` | PASS, sintaxis únicamente |
| `php -l` en los nueve archivos PHP del plugin modificados | PASS |
| `git diff --check` | PASS |

Los primeros pases detectaron expectativas antiguas de JSON, procedencia, revisiones, archivos y tokens visibles. Se actualizaron esas expectativas, no los controles de persistencia/seguridad. Las pruebas mantienen comprobaciones independientes de esquema almacenado, revisión guardada, conflictos, denegación de permisos y aprobación segura. Se añadieron comprobaciones negativas de metadata renderizada y conservación del aviso de dirección amplia.

Evidencia temporal de la sesión: `/tmp/release-<nombre>.log` para las pruebas PHP del lote general, incluidos los resultados finales de `checkout-places` y `price-list`. Los resultados Python, lint y sintaxis constan en la salida de las ejecuciones de la sesión.

## Pendientes / handoff

- Lead ejecutó comprobaciones DOM en navegador sobre fixtures sintéticos locales a 412/1440 px y CSS zoom 2; resultados al final. Capturas guardadas según handoff, sin inspección de píxeles acreditada. No equivale a dispositivo físico, zoom del navegador ni aceptación visual.
- NOT_RUN: `woo-stack-harness.mjs` completo y verificaciones HTTP de roles. Sus expectativas se actualizaron, pero ejecutarlos implica aprovisionamiento y mutaciones fuera de este encargo.
- Implementación integrada en `de859e1`; este seguimiento modifica solo documentación, sin cambios de runtime. Pendientes: recorrido nativo HTTP autenticado, revisión visual, dispositivo físico y aprobación humana; autorización separada para release en staging. Producción excluida.
- QA: una ronda de render real de catálogo → checkout → recibo y solicitudes → borrador → previa incompleta/desactualizada/completa → oferta aprobada, además de ventas y precios. Confirmar errores, cargas vacías, conflictos, advertencia amplia y ausencia de aprobación cuando faltan datos.
- Esta evidencia no constituye aprobación humana ni autorización de despliegue.

## Recálculo transitorio (integración actual)

- Edición de cantidades/precios recalcula neto por línea, productos, despacho, IVA y total, también en dock. «Cambios sin guardar» / «Importes estimados» distingue cálculo de persistencia. Sin peticiones de red, autosave ni acción de aprobación nueva.
- Política fiscal confirmada se transmite independientemente de la proyección incompleta, nunca IVA 19% implícito. `BigInt` evita pérdida de precisión incluso al sumar por encima de `Number.MAX_SAFE_INTEGER`; redondeo medio hacia arriba coincide con el kernel PHP.
- Cantidad 1–1.000.000; precios/flete 1–99.999.999, enteros estrictos con longitud igual al parser PHP. Blanco/0/inválido quedan pendientes, no cero. Vigencia vacía usa default; inválida exige 1–365. Sin despacho no se exige flete.
- Monto de despacho conservado conserva su base guardada (destino/cantidades); cambios incompatibles dejan despacho/total pendientes. Cambiar monto, o no tener base previa, permite nueva base estimada como el siguiente guardado. Volver al monto anterior vuelve a comprobar la base anterior.
- Límite deliberado: texto de destino cuya normalización WordPress sea incierta (etiquetas, entidades, porcentajes, espacios ambiguos) queda pendiente hasta guardar. También se verifica máximo de 800 bytes. No se presume destino confirmado ni se oculta aviso original de coincidencia amplia.
- Mínimo conocido se reevalúa al editar cantidad; desconocido mantiene revisión comercial. Pallets y referencias manuales permanecen; cruzar tramo nunca cambia precio elegido.
- Descartar/restaurar valores recupera importes y etiquetas realmente renderizados por servidor, no un cálculo sobre sugerencias. No habilita acciones inicialmente deshabilitadas. POST rechazado conserva bloqueo hasta guardar o navegar por enlace explícito de descarte. Flujo sin JS y ofertas aprobadas siguen server-first e inmutables.

## Gate offline actual

Ejecutado en worktree del worker, sin servidor, navegador, dispositivo, DB ni correo real:

```sh
FREEPLAST_SKIP_STACK=1 \
PHP_BINARY=/home/mauricio-liu/Projects/freeplast/wordpress/.tools/php/php \
npm test
```

**PASS: 2039 comprobaciones agregadas de sintaxis/dependencias/despliegue**, además de suites PHP/Python listadas por el runner. Focales: workspace-offer **103** (+355 de quote-draft), owner-workspace JS **140**, price-list **118**, sales-register **142**. Preservados tests de nonces, concurrencia, token de aprobación, permisos e inmutabilidad. Tests nuevos comenzaron rojos para recálculo y eliminación del refresco.

Dependencias ya instaladas, sin descargas: enlaces ignorados locales `node_modules`, `wordpress/.tools/php/php`, `wordpress/.build/wp/wp-includes` y `wordpress/.build/wp/wp-content/plugins/woocommerce` apuntan a dependencias originales, leídas por tests. Primeros intentos del gate fallaron por ausencia local de fuentes Woo, binario PHP fijo de un test y hooks WordPress; resueltos con esos enlaces, sin cambiar originales. No ejecutar bootstrap ni stack a través de esos enlaces.

Log final: `.scratch/release-ui-cleanup/offline.log` en worktree worker. Logs de pases previos: `/tmp/iiu3-offline.log`, `/tmp/iiu3-workspace-offer.log`, `/tmp/iiu3-price-list.log`, `/tmp/iiu3-sales-register.log`; red inicial `/tmp/iiu3-red.log`. `git diff --check`: PASS. No son evidencia HTTP ni visual.

### Fixture reproducible para el gate del lead

Reutilizar, **copiándolo al checkout aislado de integración**, `/home/mauricio-liu/Projects/freeplast/.scratch/interface-simplification/render-fixture.php` en `.scratch/interface-simplification/render-fixture.php`. Su `dirname(__DIR__, 2)` resolverá la integración, no el original. Ejecutar con PHP y argumento `quote`, `quote-dispatch`, `quote-issued`, `prices`, `prices-empty`, `sales` o `sales-preview`, guardando HTML en carpeta aislada. Renderiza helpers PHP reales sobre fixtures sintéticos de las suites; almacenamiento en arrays, sin DB. Incluye bloqueo capture-phase de todos los submits. Assets requeridos por lead: `/plugin/` al plugin integrado, `/admin/` a estilos WordPress y `/manrope.woff2` a fuente local; no instalar nada.

Preparación ya ejecutada en worker: copia local del script anterior y siete HTML en `.scratch/release-ui-cleanup/{quote,quote-dispatch,quote-issued,prices,prices-empty,sales,sales-preview}.html`. Autocomprobación Python: los siete empiezan con doctype, bloquean submits y no contienen avisos/errores PHP. No son capturas ni validación visual.

La evidencia DOM del lead descrita abajo cubre parte de esta preparación. Sigue pendiente la revisión visual y nativa completa de escritorio/móvil, teclado/foco, zoom del navegador, total largo en dock, campos inválidos, cantidades máximas, despacho obsoleto, descarte, precio sugerido sin guardar, POST rechazado, previa incompleta/obsoleta y oferta aprobada. Para variante sin política, retirar filtro `fpw_quotation_tax_config` del fixture antes del render, no cambiar backend. Validar también formularios reales con permisos/nonces vía HTTP solamente bajo autorización propia.

No acreditado: aceptación visual/dispositivos, stack HTTP nativo, integración nativa de permisos que pide `FREEPLAST_LOCAL_TEST_CACHE`. Sidecar `.impeccable/design.json` reportado desactualizado; no reparado (fuera de alcance; `document` puede regenerarlo con autorización).

## Paso 9: evidencia local del lead tras integración

Fuentes en `/home/mauricio-liu/.herd/plan-mode/`, prefijo `plan-mv85ckrr-iiu3`:

| Archivo | Evidencia registrada |
|---|---|
| `.browser-mobile.jsonl` | 9 casos a 412 px |
| `.browser-desktop.jsonl` | Los mismos 9 casos a 1440 px |
| `.final-mobile.jsonl` | Total máximo del caso: `118.999.998.810.000 CLP`, ancho 412 px, `overflow:false`, dock de 69 px de alto, guardar visible y revisión bloqueada |
| `.final-zoom.jsonl` | 4 pantallas a 1440 px con `cssZoom:"2"`, `bodyWidth:1440`, sin desborde registrado |
| `.lead-integrated-gate.log` | Rerun offline del lead: PASS, **2039** comprobaciones agregadas; workspace-offer 103, JS 140, price-list 118, sales-register 142; stack nativo omitido |

Los **18 casos** corresponden a precios, precios vacíos, ventas, previa de ventas, borrador con despacho, sin despacho, oferta emitida, borrador vacío y revisión de cotización, en ambos anchos. Todos registran `overflow:false` y `technicalCopy:false` (resultado del detector utilizado, no prueba exhaustiva de ausencia de cualquier texto técnico). Formularios registrados por vista: 1, 0, 1, 3, 1, 1, 0, 1 y 1, respectivamente.

En los seis casos editables ejercitados (tres vistas por ancho), el neto de línea pasa a **525.000 CLP**. Sin despacho y borrador vacío: resumen y dock **624.750 CLP**; con despacho: ambos **Por definir**. Todos registran etiqueta «Total estimado · Cambios sin guardar», revisión bloqueada, ausencia de desborde tras editar, entrada inválida como «Por definir», `reset:true` y `escape:true`. Estos dos últimos son resultados DOM del harness; no certifican navegación completa de teclado, foco visible ni experiencia con lector de pantalla.

El caso máximo de una línea equivale a `1.000.000 × 99.999.999 × 1,19 = 118.999.998.810.000 CLP`; el registro a 412 px confirma ausencia de desborde de página, guardar visible y revisión bloqueada. No acredita por sí solo legibilidad, ausencia de recorte interno ni todos los máximos posibles de una oferta multilínea.

CSS zoom 2 se probó en borrador sin despacho, con despacho, precios vacíos y previa de ventas. **CSS zoom no es zoom del navegador. Las aserciones DOM no son inspección de píxeles.** Capturas guardadas por lead según handoff; este seguimiento no las abrió ni afirma revisión visual.

Verificación documental offline del worker: JSONL parseable, conjuntos y conteos exactos (18 + 1 + 4), anchos, flags, importes, estado de guardado/revisión, reset/Escape y resultados del log cotejados con aserciones Python; `git diff --check` PASS. No se volvió a ejecutar aplicación, navegador, servidor, suite runtime ni dispositivos en este seguimiento.

### Prerrequisitos registrados antes de la autorización posterior

1. Recorrido **nativo HTTP autenticado** real: catálogo → solicitud → borrador → previa → oferta, mantenedores y estados de error/vacío; comprobar permisos, nonces, concurrencia y guardado real bajo autorización propia. Los fixtures locales no usan DB ni prueban este recorrido HTTP.
2. Inspección visual/píxeles, teclado/foco, zoom del navegador, dispositivo físico y aceptación humana. Ninguna queda sustituida por estas aserciones DOM ni por capturas sin revisar.
3. Autorización separada para publicar en **staging**. **Producción excluida**. Completar documentación/validación local del paso 9 no autoriza despliegue ni significa aprobación humana.
