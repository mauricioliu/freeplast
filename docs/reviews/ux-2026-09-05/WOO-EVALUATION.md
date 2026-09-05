# Evaluación de WooCommerce gratuito — Freeplast

Fecha: 2026-09-05. Registro de evaluación inicial y decisiones posteriores. **Q20 autorizó el reemplazo directo; staging ya fue migrado.** La evidencia de ejecución y sus límites están en [WOO-MIGRATION.md](../../../wordpress/WOO-MIGRATION.md). Las comparaciones estáticas siguientes conservan el estado de la investigación inicial.

La auditoría original permanece en [README.md](README.md). Este documento no cambia sus hallazgos ni los da por corregidos.

## Decisiones confirmadas en la conversación

- Revisar UX-01 a UX-17. Terminología: Catálogo, Productos a Cotizar, Solicitud de cotización y Cotización; seleccionar productos no es comprar ni reservar stock. El usuario sustituyó expresamente «Mi selección» por «Productos a Cotizar» después de presentar Q17–Q18.
- El responsable presente puede aprobar reglas comerciales. Indicó que Empresa/RUT/Giro son necesarios para la facturación casi siempre. No resolver por inferencia nuevas excepciones comerciales.
- Aceptar convenciones de una solución mantenida y adaptar interacción/administración para reducir código propio (Q11).
- Primera versión: recepción y seguimiento de solicitudes. Preparar/enviar documentos con precios no es requisito inicial; sin compra, pago ni registro obligatorio (Q12).
- Si se migra, WooCommerce será la fuente operativa editable del catálogo. Los archivos actuales quedan para migración/referencia, no como segunda fuente editable (Q13).
- Dirección completa cuando se solicita despacho. Ventas puede consultar la distancia fuera del sitio inicialmente; automatizarla no es requisito de entrada (Q14).
- **No se aceptan licencias de extensiones de pago** (Q15).
- Se permite un pequeño plugin de adaptación para campos, validaciones y textos; no reconstruir sesiones, carrito, cantidades, persistencia de solicitudes ni administración paralela (Q16).
- Aceptar la representación técnica y gestión de las solicitudes dentro de Pedidos Woo, identificadas como solicitudes de cotización. No cambia su significado comercial ni autoriza cobro, factura automática o reserva de stock; comprobar esos efectos antes de dar el flujo por apto (Q17).
- Aceptar dos pasos: Productos a Cotizar → Datos y envío, conservando cantidades y datos al volver atrás y sin textos de compra o pago (Q18).

Las preguntas Q4–Q10 no recibieron una aprobación global: el usuario interrumpió esa ronda para reconsiderar la plataforma. No convertir sus recomendaciones en decisiones tácitas. Q12–Q16 precisan parte de esas ramas.

Q20 sustituyó la arquitectura anterior mediante [ADR-0001](../../adr/0001-woocommerce-quote-only.md): reemplazar repositorio y `freeplast.mliu.site`, respaldar primero, conservar catálogo/imágenes/solicitudes y no intervenir `freeplast.cl`.

## Método y límites de la investigación inicial (antes de Q20)

Se consultaron documentación de fabricantes, fichas de WordPress.org y la API oficial de información de plugins. Se descargaron ZIP públicos a `/tmp/freeplast-woo-evaluation/`, se extrajeron y leyeron fuentes sin ejecutar PHP ni instalar plugins. No se inició servidor, no se operó navegador/dispositivo y no hubo envíos, correos ni cambios al staging o producción.

No es una auditoría exhaustiva de seguridad ni una comprobación de funcionamiento, accesibilidad o rendimiento. Las declaraciones de compatibilidad de un fabricante no son pruebas nuestras. Los resultados de búsqueda mostraban versiones anteriores; se priorizó la versión devuelta por la API y su ZIP.

Fuentes reproducibles:

- [API ELEX](https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=elex-request-a-quote): 2.4.1, actualizado 2026-09-04.
- [ZIP ELEX 2.4.1](https://downloads.wordpress.org/plugin/elex-request-a-quote.2.4.1.zip), SHA-256 `d1a41ef7bb2c4a0e409e041c8dd0743145238c0fa5843c02fe07b39fab6b2e18`.
- [API Quotes for WooCommerce](https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=quotes-for-woocommerce): 2.13, actualizado 2026-06-16.
- [ZIP Quotes for WooCommerce 2.13](https://downloads.wordpress.org/plugin/quotes-for-woocommerce.2.13.zip), SHA-256 `63e7144c022362b87d9fd43e5154bd14795e7bf0e729c268e23f8066f07c4034`.

Los paths de código siguientes son relativos a la raíz de cada plugin dentro de esos ZIP. Las dos búsquedas Exa de esta ronda costaron USD 0,014 según sus respuestas; no se compraron licencias.

## 1. ELEX WooCommerce Request a Quote Free 2.4.1

[Descripción de la edición gratuita](https://elextensions.com/plugin/elex-woocommerce-request-a-quote-plugin-free/) y [ficha WordPress.org](https://wordpress.org/plugins/elex-request-a-quote/).

### Capacidades presentes en código

- Campos configurables, incluido indicador `mandatory`: `src/FormSetting/FormSettingController.php:113–189`. El componente `assets/js/components/form/fields/form.js` aplica `required` a varios tipos y soporta radios.
- Acciones para visitantes sin cuenta: `src/Quotelist/QuoteListController.php:47–68`.
- Solicitud almacenada como `WC_Order`, con productos y estado `quote-requested`: `QuoteListController.php:212–278` y `src/Quotelist/Models/QuoteListModel.php:334–375`.
- Gestión integrada en pedidos Woo, con metabox de solicitud: `QuoteListController.php:187–209`.

### Objeciones para nuestro objetivo

1. **No reutiliza el carrito estándar para la selección.** Tiene cookie, tablas y operaciones de lista propias: `QuoteListController.php:21,47–68`, `QuoteListModel.php:244–378`. Instalar Woo no elimina los riesgos de esa implementación particular.
2. **Coordinación cantidad/envío no demostrada.** `assets/js/components/quote_list/quote_list_content.js:299–359` conserva cambios locales cuando hay botón Actualizar; en modo automático lanza peticiones por cambio. `assets/js/components/form/fields/form.js:274–353` envía por separado y no espera esas operaciones. El servidor toma los ítems de su lista almacenada (`QuoteListController.php:249–251`). Hay un riesgo estático de enviar una versión anterior; no se reprodujo en ejecución.
3. **Recuperación de fallo incompleta en el handler revisado.** El formulario deshabilita el botón al iniciar. Su AJAX atiende éxito positivo, pero no tiene rama de error de red o respuesta negativa que muestre el problema y vuelva a habilitarlo (`form.js:274–353`). El mismo patrón se comprobó en el bundle distribuido `assets/js/components/quote_list/quote_list.min.js`, cargado desde `src/RequestAQuote.php:262`.
4. **No basta con marcar campos obligatorios en el editor.** Existe un handler separado de validación (`FormSettingController.php:655–703`), pero el formulario revisado llama directamente a `elex_place_order()`, que crea el pedido sin invocar esa validación (`QuoteListController.php:212–278`). Sanitizar texto no equivale a comprobar campos requeridos. No se realizó petición inválida para probar consecuencias.
5. **Distinguir direcciones requiere adaptación.** El handler asigna el mismo `$address` a billing y shipping (`QuoteListController.php:241–242`), mientras el dominio Freeplast distingue destino de despacho de datos fiscales.
6. **Aprobar puede habilitar pago.** `src/create_order_status.php:67–76` considera `quote-approved` pagable y la plantilla aprobada contiene un enlace de pago (`src/TemplateSetting/Models/TemplateModel.php:88–97`). No asumir modo permanente sin pagos por defecto.

**Juicio de selección:** no recomendar ELEX como primer candidato de implementación. Corregir coordinación de cantidades y recuperación del formulario nos acerca al mantenimiento que acordamos evitar. No es una declaración de que el plugin falle siempre ni de que otro candidato ya esté aprobado.

## 2. Quotes for WooCommerce 2.13 — TechnoVama

[Ficha WordPress.org](https://wordpress.org/plugins/quotes-for-woocommerce/). Es un producto distinto de ELEX Request a Quote y de la extensión comercial de nombre similar consultada anteriormente.

### Encaje estático favorable

- Se integra con carrito, validación de agregado, checkout y pedidos Woo mediante hooks, en lugar de la lista propia de ELEX: `class-quotes-wc.php:52–124`.
- Usa un método técnico de cotización sin cobro. `includes/class-quotes-payment-gateway.php:64–91` lo describe explícitamente y `process_payment()` marca el pedido `_qwc_quote`, añade nota, vacía el carrito y devuelve la confirmación; no procesa dinero.
- Ofrece configuración gratuita de modo global, ocultación de precios y textos de botones/páginas: `includes/admin/class-quotes-wc-general-settings.php:99–243`.
- Declara compatibilidad con HPOS y Cart/Checkout Blocks, y contiene integración para bloques: `quotes-woocommerce.php:54–64`, `includes/blocks/`, `src/index.js`. **Declaración y presencia de código, no prueba de compatibilidad en Freeplast.**
- Se apoya en administración de pedidos y correos Woo: `class-quotes-wc.php:89–99,722–790`, `includes/emails/`.

### Condiciones y riesgos pendientes

- La solicitud es técnicamente un pedido Woo en estado pendiente con metadatos de cotización (`class-quotes-wc.php:722–734`, gateway). Esto no debe convertirla comercialmente en compra, factura o reserva. Requiere decidir si el operador acepta esa representación y comprobar efectos de stock, correo e integraciones.
- Tiene funciones posteriores de completar/enviar cotización; hay que impedir que habiliten pago o comuniquen una compra en la primera versión.
- **Ocultar dirección desde su configuración solo funciona con checkout clásico**, según la propia descripción del setting en `class-quotes-wc-general-settings.php:225–232`. Declarar compatibilidad con bloques no implica paridad de todas las opciones. Dirección condicional y campos Empresa/RUT/Giro en bloques requieren diseño y comprobación específicos.
- Falta inspeccionar y probar en conjunto: producto variable sin precio público, persistencia anónima, cantidad pendiente/error, obligatoriedad fiscal en servidor, dirección opcional/condicional, teléfonos chilenos, confirmación y correos sin importes/pago, referencias, seguimiento comercial y permisos.
- Ocultar precios visualmente no demuestra ausencia de importes en todas las superficies públicas. Revisar también correos, confirmaciones y datos estructurados; no introducir precios comerciales ficticios.
- No se seleccionó tema ni se comprobó integración con el actual. Cambiar la capa de datos no corrige automáticamente CSS, menú, foco ni contenido provisional.

**Juicio de selección:** mejor candidato para una prueba acotada que ELEX, porque reutiliza más infraestructura Woo. No aprobar migración hasta comprobar flujo y límites del adaptador.

## 3. YITH gratuito

La [ficha WordPress.org](https://wordpress.org/plugins/yith-woocommerce-request-a-quote/) separa explícitamente funciones gratuitas y premium. La gratuita incluye formulario básico, variaciones, ocultación de precios y correo a administrador; reserva editor avanzado de campos, sección Requests y varias funciones de gestión para Premium.

**Juicio de selección:** peor encaje inicial con nuestros campos fiscales y seguimiento sin licencia. No se descargó ni auditó su código; no se afirma imposibilidad absoluta de extenderlo.

## Autorización posterior y ejecución

Q17 y Q18 quedaron aprobadas expresamente. El usuario rechazó Q19 (prueba aislada) y Q20 confirmó el reemplazo de repositorio y staging, con respaldo previo y conservación de datos; producción queda excluida. Se instaló WooCommerce 11.1.0 + Quotes for WooCommerce 2.13, se conservaron 17 productos y dos solicitudes históricas y se comprobó un flujo anónimo real de prueba. Ver el registro operativo para comandos, resultados y limitaciones.

Los hallazgos UX no se consideran cerrados automáticamente por cambiar de plataforma. No se necesita inventar otro carrito para justificar esta evaluación, ni rebajar integridad de datos o accesibilidad para cumplir el presupuesto de licencias.
