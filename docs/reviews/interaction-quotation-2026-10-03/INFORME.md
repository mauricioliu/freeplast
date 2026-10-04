# Auditoría de interacción del recorrido de cotización

**Resultado: PARTIAL · 2026-10-03 · revisión sin correcciones**

## Resumen ejecutivo

El recorrido anónimo permite elegir color, agregar productos, cambiar cantidades y recuperar un fallo de conexión sin perder la selección confirmada. El formulario conserva los datos al regresar desde la cesta. En el panel, las referencias de precio requieren una decisión explícita, los cambios bloquean la revisión hasta guardar y el PDF preexistente concuerda con su oferta guardada.

**No quedó validado el recorrido completo de recepción y envío.** El local suprime `wp_mail` devolviendo éxito, pero no captura mensajes, destinatarios ni adjuntos. No se pulsó «Solicitar cotización» ni «Aprobar y enviar». No es lícito interpretar la fixture «Enviada» como evidencia de entrega de esta auditoría.

Problemas principales:

1. Falta una captura local auditable para probar la continuidad cliente → aviso → dueño → correo final.
2. La cuenta de cotizaciones puede agregar productos, pero no actualizar la cesta: la Store API devuelve 403.
3. El formulario de solicitud muestra cupones de compra y varias interacciones conservan etiquetas en inglés.
4. Dos variantes del mismo producto tienen controles de cantidad/eliminación con nombres accesibles idénticos.
5. La revisión final no muestra explícitamente el correo al que se enviará la oferta.

No se implementó, desplegó, instaló, publicó ni cambió configuración. No se consultó staging ni producción. Los hallazgos corresponden al **runtime local**, no a todos los entornos Freeplast.

## Entorno, autoridad y alcance

- Workspace: `/home/mauricio-liu/Projects/freeplast`.
- Target efectivo: `http://mliu:8096/`; cliente `/tienda/`, `/product/.../`, `/cotizacion/`, `/datos-y-envio/`; dueño `/cotizaciones/` y pantallas nativas privadas.
- Runtime: `~/.local/state/freeplast-owner-review/wordpress/.build/wp`, servido por `freeplast-owner-review.service`. Servicio ya existente, transitorio, con expiración indicada a las 09:17 del 03/10; no se reinició.
- WordPress **7.1**, WooCommerce **11.1.0**, adaptador **1.11.0**, tema **1.0.19**. HEAD del repo: `4baf52b1e9daebfa6c5e2e9206ff184aa4f2de6d`, con numerosos cambios previos sin commit.
- Comparados 345 archivos del adaptador con su copia activa: 344 idénticos; `quote-draft.php` difiere únicamente en un comentario de permisos, línea 25. Evidencia: `15-source-runtime.json`. El número de versión no basta para identificar este árbol sin commit.
- Local con política fiscal **sintética**: 19%, incluido despacho. No se acepta como decisión fiscal comercial.
- Cuenta existente autorizada desde `pass`, entrada `freeplast/local-owner-review`; acceso efectivo restringido a cotizaciones. Contraseña consumida en memoria por stdin, sin informes, argumentos de CLI ni capturas de contraseña.
- Fuentes leídas: metodología `emil-design-eng/SKILL.md`, `chrome-devtools-axi/SKILL.md`, `pass/SKILL.md`; `CONTEXT.md`, los cinco documentos recientes indicados en el brief, `wordpress/verification-safety.md` y ADR-0011. Los informes anteriores se usaron como contexto, nunca como PASS vigente.
- Chrome real mediante la instalación existente de chrome-devtools-axi, sesión aislada `quotation-audit-20261003`. Sin reiniciar Chrome compartido ni instalar dependencias.

### Barrera de seguridad de envíos

El MU-plugin **activo**, no la copia de staging, es:
`~/.local/state/freeplast-owner-review/wordpress/.build/wp/wp-content/mu-plugins/local-review-safety.php`.

- Línea 3: `pre_wp_mail` devuelve `true` sin captura.
- Línea 4: `pre_http_request` devuelve un error y bloquea HTTP saliente de WordPress.
- Líneas 7–8: política fiscal y aviso de revisión local.

Esto demuestra supresión por ese transporte, **no una bandeja local capturada**. No se encontró en ese mecanismo evidencia consultable de destinatarios, cuerpos o adjuntos. Además, el panel oculta avisos nativos mediante `assets/owner-workspace.css:7`; en las pantallas privadas observadas no aparece el aviso de correo bloqueado/IVA de prueba. Las tres solicitudes preexistentes 64–66 tienen correo sintético `@example.invalid`, comprobado en lectura SQLite. Eso no sustituye la captura exigida para crear una solicitud y comprobar también el aviso al dueño.

**Decisión:** detenerse antes de los envíos finales. No cambiar el entorno para superar la barrera.

## Mapa de etapas y pruebas vigentes

PASS significa únicamente lo descrito en la fila; no aceptación integral de la etapa.

| Etapa/caso | Estado | Evidencia y resultado |
|---|---|---|
| Descubrimiento y ficha | PASS | Home → catálogo → ficha; precios públicos no mostrados en las pantallas inspeccionadas. No es una auditoría de todas las APIs públicas. |
| Variante obligatoria | PASS | Antes de elegir color, agregar está deshabilitado con explicación. Elegir Azul habilita agregar. `01`, `27`. |
| Productos/cantidades anónimas | PASS | Azul 12 → 24; cantidad conservada al llegar a datos. Dos colores se mantienen como líneas separadas. `02`, `03`, `27`. |
| Repetición de agregado | PASS | Agregar nuevamente la misma variante suma unidades; Azul termina en 36 en la selección posterior. No equivale a probar doble clic simultáneo. `27`. |
| Cantidad con red interrumpida | PASS | 13 → 14 con Offline: vuelve a 13 y explica que no guardó. Tras volver a conexión y repetir: 14 y confirmación visible. `33`, `34`. |
| Cantidad cero en cesta | PASS | Como invitado, 0 se normaliza a 1 y se confirma 1. Es el comportamiento observado, no una política comercial aprobada. `35`. |
| Quitar último producto | PASS | Cesta vacía, aviso que incluye Azul y foco en el título del vacío. Sin deshacer visible. `36`. |
| Cesta con cuenta de cotizaciones | FAIL | Cambio válido rechazado por Store API 403; la selección anterior permanece. `42`, `43`. |
| Datos y despacho, antes de enviar | PASS | Datos TEST AUDITORIA conservados al regresar; dirección conservada al alternar sí/no/sí. Con «Sin despacho» se oculta y deja de ser requerida. `05`, `06`, `41`. La exclusión del registro final no se probó. |
| Email inválido y corrección, al salir del campo | PASS | Se marca `aria-invalid=true`; al corregir desaparece. Falta explicación textual del error, ver H5. `39`–`41`. |
| Solicitud: validación del servidor, envío, confirmación y reintento | NOT_RUN | No captura comprobada. No se envió formulario final, ni siquiera vacío. |
| Recepción durable, nacimiento del borrador y aviso al dueño | NOT_RUN | Sin solicitud nueva ni captura de correo. |
| Login dueño y acceso al mantenedor | PASS | Login real a bandeja. `/mantenedor/` rechaza a la cuenta de cotizaciones, conforme a su rol; no se intentó eludirlo. |
| Bandeja y búsqueda | PASS | Pendientes: 64 y 65; Enviadas: 66. Buscar referencia exacta devuelve 64; búsqueda sin coincidencias permite volver a Todas. `16`, `17`, `19`, `25`. No se ensayó paginación con más datos. |
| Referencias vs precio elegido | PASS | Con teclado, «5 o más pallets» copia 1900 al campo, marca cambios y deshabilita revisar. Aumentar cantidad a 350 conserva el precio existente y muestra 5 pallets. `09`, `10`, `24`. |
| Descartar ajustes no guardados | PASS | Reset devuelve cantidad 100/precio 1500 y habilita revisar. `12`. |
| Faltantes, cero y vigencia | PASS | Verificación limitada de controles: precio 0 y vigencia 0 son inválidos; despacho vacío es distinto de cero y deja revisión bloqueada por cambios. `22`. No se guardaron esos casos ni se probó rechazo de aprobación incompleta. |
| Guardar borrador y retomar una revisión nueva | NOT_RUN | No se guardaron cambios comerciales sobre las fixtures preexistentes ni se creó un borrador propio. Abrir de nuevo 64 sí mostró su revisión 8 previa. |
| Despacho manual / consulta de distancia | PASS | Campo manual disponible; disclosure explica falta de credencial y no inventa kilómetros ni precio. `20`. Consulta real/calibración: NOT_RUN. |
| Historial de compras | PASS | «Sin historial asociado» no implica «cliente nuevo». Solo lectura del caso sintético. `20`. |
| Generación de previsualización | PASS | Se regeneró la vista previa de la fixture 64, revisión 8, total 232.050 CLP. Véase incidencia de herramienta y delta abajo. `08`. No se probó obsolescencia/concurrencia. |
| Aprobación, PDF nuevo, envío y deduplicación final | NOT_RUN | No se pulsó «Aprobar y enviar». |
| Oferta y PDF preexistentes | PASS | 66/v1: HTTP 200, PDF privado de 13.009 bytes. Longitud y FNV del browser coinciden con bytes leídos en SQLite; extracción Poppler muestra 300.000 + 45.000 + 65.550 = 410.550 CLP, igual a la pantalla. `18`, `44`, `45`. No prueba emisión nueva ni recepción. |
| Confirmación real de envío al destinatario | NOT_RUN | El texto «Aceptado por el transporte» de una fixture simulada no demuestra entrega. |

### Modalidades y estados

| Dimensión | Inicial / activación / cancelación / error / repetición / interrupción |
|---|---|
| Pointer | Selección vacía, color pendiente, agregar, editar y quitar ejercitados. Error de conexión y recuperación observados. No se ensayó doble clic de aprobación. |
| Teclado/foco | Tab hasta referencia y Enter aplican precio; el control enfocado queda visible en `09`. Abrir menú lleva foco a Cerrar; Escape cierra y devuelve foco a Abrir (`37`, `38`). Quitar último producto enfoca el vacío. No revisión completa de orden de tabulación ni lector de pantalla. |
| Viewport móvil | Capturas y controles a **412×915**, sin desbordamiento horizontal en cesta y formulario medidos (`32`, `41`). No dispositivo táctil real ni teclado virtual. |
| Escritorio | Cliente a **1440×1000** (`03`), borrador a 1440×1000 (`23`). Las capturas `16` y `18` son **1905×2053**, no 1440: se conserva su dimensión real. |
| Reduced motion | **NOT_RUN en emulación**. Inspección estática: `themes/freeplast/assets/css/woo.css:158` elimina transiciones del diálogo bajo reduce. No se deduce de ello cumplimiento global ni timing probado. |
| Salida con cambios del dueño | **NOT_RUN concluyente**: al navegar con cambios, la herramienta agotó tiempo y perdió las pestañas. El código tiene `beforeunload` (`owner-workspace.js:90–95`), pero eso no prueba el diálogo real ni pérdida de datos. No se atribuye el fallo del harness al producto. |

## Hallazgos priorizados

### H0 · P1 · Bloqueo del entorno de auditoría: no hay captura verificable de correo

**Tipo:** hecho confirmado del entorno, no bug de entrega en producción.

- **Dónde:** runtime local, panel privado y fixture enviada 66.
- **Reproducción:** inspeccionar MU-plugin activo; abrir 66 desde Enviadas. Se muestra «Aceptado por el transporte» aunque el transporte del local devuelve éxito sin enviar ni capturar.
- **Esperado:** prueba aislada con evidencia de destinatarios, aviso al dueño, referencia, enlace privado y adjunto final; distinguir claramente simulación de entrega.
- **Observado:** supresión sin captura; la advertencia local no aparece en el workspace. No puede verificarse la continuidad entre ambos actores.
- **Evidencia:** `47-environment-final-state.json`, `18-issued-desktop.png`; MU-plugin citado arriba; `wordpress/wp-content/plugins/freeplast-woo/assets/owner-workspace.css:7`.
- **Sugerencia:** preparar, con autorización separada, un entorno de prueba con captura local comprobada y bloqueo independiente de salida. Mostrar el modo simulado en el workspace; no convertir un «true» de prueba en evidencia comercial.
- **Aceptación:** una nueva solicitud TEST produce una captura de aviso al dueño; una aprobación produce una captura al comprador y su PDF exacto; ninguno alcanza un destinatario real. El panel identifica que es una simulación. Después se ejecutan repetición, rechazo e incertidumbre sin reenvíos ciegos.

### H1 · P2 · Bug confirmado: la cuenta de cotizaciones no puede actualizar su cesta

- **URL/estado:** `/cotizacion/`, autenticado con la cuenta restringida de cotizaciones, varias líneas existentes.
- **Pasos:** iniciar sesión por `/cotizaciones/`; ir al catálogo y agregar un producto; entrar a cesta; cambiar una cantidad positiva y pulsar Tab.
- **Esperado:** actualizar la cesta pública propia, o explicar antes de operar que debe usarse una sesión de cliente. No ofrecer una interacción que fallará por un permiso administrativo ajeno al carrito.
- **Observado:** POST a `/wp-json/wc/store/v1/cart/update-item?_locale=site` → **403**; aviso «Esta cuenta no tiene acceso a la API de administración». Agregar por ficha sí funciona. Como invitado, la misma operación de cantidad funciona.
- **Impacto:** impide que el operador recorra el flujo del cliente en el mismo navegador; puede dejarlo corrigiendo una cantidad que nunca se guarda.
- **Evidencia:** `30-owner-storeapi-failure.txt`, `42-role-cart-network.txt`, `43-role-cart-repro.txt`; control anónimo `32-guest-quantity.txt`.
- **Código:** `wordpress/wp-content/plugins/freeplast-woo/quotation-access.php:141–144` rechaza globalmente REST para roles restringidos sin distinguir Store API de administración.
- **Sugerencia:** decidir explícitamente el comportamiento del catálogo para estas cuentas. Si se permite cotizar como cliente, delimitar una excepción estricta a la cesta propia y conservar todos los rechazos administrativos. Si no se permite, dar un acceso claro a una sesión de cliente y evitar controles rotos. **No ampliar permisos generales.**
- **Aceptación:** agregar, actualizar y quitar tienen un resultado coherente con ese contrato; nunca 403 inesperado en controles habilitados. Configuración, pedidos ajenos, mantenedor y API administrativa siguen denegados.

### H2 · P2 · Bug confirmado de contrato: cupón de compra en solicitud sin precios

- **URL:** `/datos-y-envio/`, invitado con un producto.
- **Pasos:** agregar Azul 12; ir a cesta y continuar con datos.
- **Esperado:** exclusivamente datos/contacto/despacho de una solicitud sin pago ni precio final.
- **Observado:** franja destacada «Have a coupon? Click here to enter your code» antes de los pasos. Compite con la instrucción «Sin registro ni pago en línea» y sugiere un descuento sobre una compra.
- **Evidencia:** `03-checkout-desktop.png`, `03-checkout-initial.txt`; opción local `woocommerce_enable_coupons=yes` leída, no alterada.
- **Código:** `wordpress/wp-content/themes/freeplast/woocommerce/checkout/form-checkout.php:23` ejecuta el hook nativo donde Woo incorpora el cupón.
- **Sugerencia:** excluir el mecanismo de cupones del recorrido quote-only en su punto de integración, sin esconder simplemente un control todavía operable ni modificar el checkout comercial de otro sitio.
- **Aceptación:** no hay invitación, campo ni acción de cupón en escritorio, móvil o árbol accesible del flujo de solicitud; selección y envío nativo siguen funcionando.

### H3 · P2 · Defecto de accesibilidad/localización confirmado en el árbol; impacto con lector pendiente

- **URLs:** catálogo, ficha, cesta; también login y mensajes nativos de validación.
- **Pasos:** agregar Caja Universal Cerrada Color Azul y Rojo; examinar/navegar los controles de ambas líneas.
- **Esperado:** nombres españoles inequívocos: «Cantidad de Caja Universal Cerrada Color, Azul», y equivalentes para aumentar, reducir y quitar.
- **Observado:** ambas variantes tienen exactamente «Quantity of Caja Universal Cerrada Color in your cart.» y «Remove Caja Universal Cerrada Color from cart». En catálogo los steppers dicen «Product quantity»; aparecen «Products in cart», «Clear» y «(optional)».
- **Impacto:** un usuario que navega por controles no distingue Azul de Rojo por el nombre accesible; mezcla de idiomas en acciones críticas. La lectura de la fila podría aportar contexto: **no se afirma una prueba con lector de pantalla**.
- **Evidencia:** `27-multi-variant-settled.txt`, `01-product-added.txt`, `03-checkout-initial.txt`, `22-zero-pending-native.txt`.
- **Código útil:** `wordpress/wp-content/themes/freeplast/assets/js/loop-add-to-cart-quantity.js:49–55` hereda el nombre nativo; `plugins/freeplast-woo/freeplast-woo.php:1463` usa el input nativo. Los controles de cesta provienen del bloque Woo, no de esos dos métodos.
- **Sugerencia:** revisar las traducciones efectivamente cargadas y añadir el contexto de variante por interfaces soportadas, sin fork del vendor.
- **Aceptación:** cantidades y eliminar se identifican en español con producto y variante; auditoría del árbol sin nombres repetidos ambiguos y posterior comprobación con lector real.

### H4 · P2 · Fricción UX: aprobar sin ver explícitamente el destinatario

- **URL/estado:** revisión privada de 64, revisión 8, antes de «Aprobar y enviar».
- **Pasos:** abrir el borrador guardado → revisar oferta.
- **Esperado:** poder comprobar en el último paso empresa, destinatario y valores que se comprometen.
- **Observado:** empresa en el encabezado y frase «al correo del comprador», pero ningún email explícito junto a la aprobación. La revisión muestra importes y destino; para revisar contacto hay que volver a ajustar/consultar la solicitud. No se observó un envío erróneo.
- **Evidencia:** `08-reference-unsaved.txt` (el nombre del archivo refleja el intento original; su contenido es la vista previa real).
- **Código:** `wordpress/wp-content/plugins/freeplast-woo/workspace-offer.php:80–99`; el destino real de correo se toma de la identidad congelada en `quotation-approval.php:174`.
- **Sugerencia:** mostrar «Se enviará a: …» desde la misma identidad autorizada que se congelará, sin abrir edición accidental en la pantalla de aprobación. Ofrecer una ruta explícita para corregir datos si el contrato lo permite.
- **Aceptación:** el dueño puede verificar el destinatario final antes de aprobar; el correo capturado coincide exactamente con ese destinatario y los datos internos nunca se filtran al documento.

### H5 · P2 · Fricción UX/accesibilidad: email inválido explicado solo con color

- **URL:** `/datos-y-envio/`, viewport 412×915.
- **Pasos:** escribir `correo-invalido` en Email y pulsar Tab.
- **Esperado:** texto próximo que explique cómo corregir el dato, asociado al campo.
- **Observado:** borde/etiqueta rojos y `aria-invalid=true`, sin mensaje textual ni `aria-describedby` de error. Corregir a un email sintético válido recupera el campo. **No se probó la validación tras enviar.**
- **Evidencia:** `39-email-blur-invalid.txt`, `40-invalid-email-mobile.png`, `41-email-recovered-no-dispatch.txt`.
- **Código útil:** `wordpress/wp-content/themes/freeplast/woocommerce/checkout/form-checkout.php:86–96` delega los campos a Woo; el marcado observado confirma que no se agrega una explicación en este estado.
- **Sugerencia:** incorporar un mensaje breve en español sobre el error nativo, asociado al input, sin reemplazar la validación del servidor ni borrar datos.
- **Aceptación:** al salir del campo inválido aparece una indicación comprensible sin depender del rojo; al corregir se retira el error; el resto del formulario permanece intacto.

### H6 · P3 · Fricción UX: origen del precio no refleja el cambio todavía no guardado

- **Estado:** borrador 64, aplicar referencia o teclear otro precio.
- **Observado:** el campo cambia y el resumen anuncia correctamente «Cambios sin guardar», pero junto al precio sigue «Precio guardado para esta oferta». El total antiguo sí se identifica como guardado; no se confirmó un error de cálculo.
- **Evidencia:** `10-price-applied.txt`, `13-leave-dirty.txt` y captura inicial `07`.
- **Código:** `owner-workspace.php:159–163`; `assets/owner-workspace.js:43–50,57–65` actualiza el estado global, no esa leyenda.
- **Sugerencia/aceptación:** mostrar junto al campo «Cambio sin guardar» y, si es útil, el valor guardado previo; reset debe restaurar ambos. No recalcular dinero en otra fuente paralela.

## Antes / Después propuesto / Por qué

Ninguna propuesta fue implementada.

| Antes | Después propuesto | Por qué / ubicación |
|---|---|---|
| Mail local retorna éxito sin captura | Captura local verificable + distintivo de simulación | Permite probar el recorrido sin destinatarios reales; MU-plugin activo:3 y `owner-workspace.css:7`. |
| Cesta habilitada pero Store API denegada al operador | Contrato coherente de cesta propia o sesión de cliente claramente separada | Evitar acciones imposibles sin ampliar privilegios; `quotation-access.php:141–144`. |
| Cupón de compra en solicitud | Flujo quote-only sin acción de cupón | No inducir expectativas de descuento/checkout; `form-checkout.php:23`. |
| Controles ingleses y variantes con igual nombre | Español + producto + color en nombres accesibles | Operación inequívoca; `loop-add-to-cart-quantity.js:49–55` y bloque nativo. |
| «Se intenta enviar al correo del comprador» | Destinatario concreto revisable antes de aprobar | Disminuir errores de envío; `workspace-offer.php:96`. |
| Email inválido solo rojo | Explicación textual asociada, conservando datos | Recuperación sin depender del color; `form-checkout.php:86–96`. |
| Leyenda local «Precio guardado» tras editar | Diferenciar valor escrito y último guardado | Alinear feedback junto al control; `owner-workspace.php:159–163`. |

## Top 5 mejoras

1. **Desbloquear la prueba integral segura**, con captura de correo y simulación visible. No se recomienda publicar basándose en las fixtures actuales.
2. **Resolver la incompatibilidad entre cuenta de cotizaciones y cesta pública**, manteniendo la frontera de permisos.
3. **Eliminar el cupón del flujo quote-only** y comprobar su ausencia funcional, no solo visual.
4. **Completar idioma y nombres de variantes**, junto con mensajes de validación claros.
5. **Reforzar la última revisión**, mostrando destinatario concreto y diferenciando con claridad valores escritos/guardados. Como mejora adicional, mostrar fecha exacta de término una vez aprobada la vigencia, sin inventar una convención de vencimiento.

## Delta, registros y límites

### Registros sintéticos

- **Solicitudes nuevas creadas: ninguna. IDs nuevos de solicitud/borrador/versión: ninguno.**
- Se consultaron únicamente fixtures documentadas **64, 65 y 66**; 65 apareció en bandeja. 66/v1 se leyó y su PDF se descargó en memoria, sin reemitir.
- **Delta de preparación:** se regeneró `fpw_draft_preview_64`, ligada a la revisión **8** ya guardada. Un clic automatizado dirigido a la referencia inferior, cuando quedaba bajo el dock móvil, activó «Revisar cotización». Se detuvo ante la pantalla resultante y no se aprobó. Esto es una incidencia de posicionamiento del harness, **no prueba de que el botón de referencia invoque revisión al pulsarlo directamente**. La posterior prueba por Tab/Enter sí aplicó la referencia correctamente.
- No se guardaron ajustes de precio/cantidad/destino/vigencia del borrador. Estado final leído: trabajos 64/r8, 65/r2, 66/r1; solo versión emitida preexistente 66. `47` documenta las huellas finales, **no una comparación completa pre/post**, que no se tomó antes de toda interacción.
- Se crearon/modificaron **cestas y sesiones de prueba**, sin referencia FP: Cosechera, Universal Cerrada Color Azul y Rojo. Una selección se incorporó a la cesta persistente del usuario local al iniciar sesión. No se borró esa cesta mediante interfaces privadas ni se modificaron cuentas para limpiarla. No es una solicitud recibida ni debe atenderse.
- Formulario no enviado: identidad/empresa/mensaje **TEST AUDITORIA**, email `test-auditoria@example.invalid`, teléfono y dirección ficticios. Esos datos no originaron solicitud ni oferta.

### Incidencias de herramienta y fuerza de la evidencia

- `11` contiene un error de selector de la herramienta (`main` no era el elemento HTML del workspace); la comprobación válida de cantidad/precio es `24`.
- `31` contiene un flag no admitido; la evidencia de red válida es `42`.
- `14` quedó sin resultado concluyente por el timeout de navegación con cambios. No es una prueba de error del producto.
- En varias capturas, el CLI informó que no reconoció la ruta guardada; los PNG `23`, `29` y `40` sí existen. `40` fue abierto y revisado. Las dimensiones de cada PNG son evidencia, no la intención del comando.
- Cambiar la emulación de red restableció el viewport de la herramienta; por eso se registran separadamente las pruebas móviles medidas y las pruebas de red. No se atribuye ese cambio al sitio.
- Huella FNV del PDF se usa solo como cotejo auxiliar, no como garantía criptográfica; `45` incluye SHA-256 de los bytes persistidos y extracción textual independiente. No se afirma revisión visual completa del PDF.

### Pendientes explícitos

Recepción nueva, aviso al dueño, guardado durable nuevo/reanudación entre dispositivos, concurrencia/preview obsoleta, doble envío, rechazo/resultado desconocido, aprobación nueva, adjunto de correo nuevo y recepción real: **NOT_RUN**. Sin captura no deben darse por aprobados.

No se probaron Google real, fórmula calibrada de flete, datos de compras reales, touch físico, lector de pantalla, teclado virtual, rendimiento GPU ni timings de animación. No hubo prueba visual independiente ni prueba de usuarios. **Regresión visual pendiente del responsable padre / visual-regression-agent**, sin delegación iniciada aquí.

### Archivos

Solo se añadieron informe, script de reproducción de la sesión y evidencias bajo `docs/reviews/interaction-quotation-2026-10-03/`. `46-final-git-status.txt` conserva el estado de archivos observado al cierre de pruebas; el árbol preexistente no se corrigió ni se preparó para commit. El directorio de evidencias contiene capturas actuales, snapshots redactados y observaciones de lectura; los artefactos con errores están identificados arriba y no cuentan como PASS.
