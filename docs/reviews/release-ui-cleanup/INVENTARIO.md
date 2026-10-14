# Limpieza de interfaz de release

## Estado: PARTIAL (implementación y pruebas offline completas; navegador pendiente)

Operación: reparación de microcopy en superficies de operación y consulta. Se conserva identidad Freeplast, idioma español, datos comerciales, formularios, autorizaciones, concurrencia, almacenamiento y seguridad de aprobación. Sin publicación, instalaciones, GitHub, commits ni mutaciones de servidores.

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

## Archivos reservados / exclusiones

- No se editaron `owner-workspace.php`, `assets/owner-workspace.js` ni `owner-workspace-js-test.mjs`: son del lead. La limpieza de sus mensajes, controles y render final requiere integración del lead.
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

- NOT_RUN: navegador aislado, capturas, móvil/escritorio, zoom, foco y estados interactivos. No se suministró endpoint aislado; no se inició ni reutilizó navegador personal, ni se instalaron herramientas.
- NOT_RUN: `woo-stack-harness.mjs` completo y verificaciones HTTP de roles. Sus expectativas se actualizaron, pero ejecutarlos implica aprovisionamiento y mutaciones fuera de este encargo.
- Lead: integrar sus tres archivos reservados y confirmar que el workspace no vuelve a introducir metadata, refrescos técnicos o mensajes de revisión.
- QA: una ronda de render real de catálogo → checkout → recibo y solicitudes → borrador → previa incompleta/desactualizada/completa → oferta aprobada, además de ventas y precios. Confirmar errores, cargas vacías, conflictos, advertencia amplia y ausencia de aprobación cuando faltan datos.
- Esta evidencia no constituye aprobación humana ni autorización de despliegue.
