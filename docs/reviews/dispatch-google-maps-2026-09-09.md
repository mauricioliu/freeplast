# Revisión de dirección de despacho con Google Maps

Fecha de consulta: 2026-09-09. Estado: investigación y recomendaciones para entrevista; **no es una decisión aprobada ni una especificación de implementación**.

## Veredicto

Google Places es una opción razonable para ayudar al cliente a identificar el destino. La propuesta anterior adelantó decisiones de persistencia, geocodificación, correo y cálculo de costo que todavía no estaban justificadas. Separar tres capacidades: capturar el destino, consultar una ruta y preparar el precio del despacho. No implementar antes de confirmar alcance con el dueño.

## Evidencia local

- `CONTEXT.md`: Delivery Address es el destino aportado por el cliente; Dispatch Distance es distancia por carretera usada por ventas; Quote Request no contiene precios para el cliente. Submitted Details y Current Contact Details son conceptos distintos.
- `docs/adr/0001-woocommerce-quote-only.md` dice expresamente: «Ventas consulta inicialmente la distancia de despacho fuera del sitio». Integrar el cálculo es una evolución de esa decisión, aunque no muestre precios al cliente. No afirmar que solo la opción con precios requiere revisar documentación; tampoco crear automáticamente un ADR sin evaluar reversibilidad y trade-off.
- `wordpress/wp-content/plugins/freeplast-woo/freeplast-woo.php:169,189-208`: dirección textarea, requerida en servidor para despacho, máximo 800 bytes, persistencia de original separada. Con «Sin despacho» el valor se vacía.
- `fields.js`: comportamiento condicional y reinicialización con `updated_checkout`. El widget nuevo no se instala simplemente sobre el textarea existente.
- `freeplast-woo.php:1292-1316` y `request-email.php`: detalles compartidos por administración y correos. El filtro de metadatos actual no discrimina `$sent`/destinatario. Añadir distancia a `fpw_details()` sin separar audiencias podría revelarla también al cliente.
- La entrada de solicitudes ya tiene identidad de intento y recuperación de reenvíos. El enriquecimiento externo debe respetar estas garantías, no repetir llamadas/correos en cada retry ni bloquear recepción si Google falla.

## Hallazgos externos y correcciones

### 1. Places identifica lugares; no certifica acceso ni precisión de una entrega

El widget permite seleccionar lugares, incluyendo ubicaciones que no necesariamente tienen número de calle o un acceso logístico preciso. «Seleccionado» describe procedencia, no exactitud. Un resultado para una comuna o calle no debe convertirse automáticamente en destino exacto. Separar procedencia del destino, revisión comercial y estado del cálculo de ruta. [S1, S2, S3]

Address Validation sí tiene cobertura en Chile; Google advierte que la calidad varía por país. Esto no verifica accesibilidad de camiones. No añadirla por defecto sin un problema postal concreto que resuelva. [S4, S5]

### 2. No es obligatorio obtener/persistir latitud y longitud

Routes admite `placeId`, coordenadas, direcciones y Plus Codes. Google recomienda Place IDs por eficiencia y por su información de accesos; coordenadas pueden ajustarse a la carretera más cercana sin que sea una entrada válida. La afirmación del handoff «sin coordenadas no hay ruta» es falsa. Tampoco convierte guardar coordenadas/comuna/región en decisión implícitamente aprobada. [S3]

Para un origen y un destino, recomiendo `computeRoutes` si se necesita cálculo integrado. `computeRouteMatrix` también sirve, pero no aporta una ventaja inicial para 1×1: ambos tienen precio Essentials de USD 5/1000 eventos; uno factura consultas y otro elementos. [S6, S7]

### 3. Geocoding API no es requisito automático

Routes puede geocodificar internamente texto de dirección. No hace falta habilitar Geocoding API solo por permitir texto libre. Pero ese mecanismo puede interpretar una dirección ambigua incorrectamente: devolver una ruta no equivale a confirmar el destino. [S3]

Recomendación inicial: conservar texto libre y marcar pendiente para ventas si no hay selección utilizable. Geocodificación adicional solo si se define cómo revisar resultados parciales/ambiguos y existe beneficio operativo. Nunca transformar silenciosamente una parcela incompleta en distancia confiable.

### 4. Camiones: no prometer ruteo específico en Chile

La documentación actual incorpora `TRUCK`; por tanto sería incorrecto decir que Google no tiene ruteo de camiones en absoluto. Sin embargo, Large Vehicle Routing limita disponibilidad a los 48 estados contiguos de EE.UU. y Japón (experimental), con acceso aprovisionado para clientes limitados. **Chile no figura como disponible**. Además, incluso ese servicio advierte que no garantiza seguridad/legalidad y puede devolver rutas best-effort. [S5]

`DRIVE` significa automóvil de pasajeros. Para Freeplast en Chile sería una **distancia vial orientativa**, no ruta habilitada para camión ni ETA de entrega prometida. [S8]

### 5. Persistencia: la propuesta anterior era demasiado amplia

- Places permite conservar Place IDs indefinidamente. [S9]
- Existe una excepción específica para la dirección de calle seleccionada mediante Autocomplete por el usuario para su propia transacción, bajo las condiciones descritas por Google. No se extiende a todos los metadatos obtenidos de Places. [S9]
- Los términos específicos de Places permiten caché de lat/lng por hasta 30 días. No equivale a autorización para guardar indefinidamente todo Place Details. [S10 §14.3]
- Geocoding tiene una excepción adicional de persistencia bajo condiciones concretas de funcionalidad directa para el usuario e aislamiento por usuario; no asumir que habilita sin más un archivo comercial interno. [S10 §6.3]
- Routes tiene restricciones generales de almacenamiento; la excepción de §19.3 menciona lat/lng por hasta 30 días, **no** autorización general para archivar kilómetros/duración indefinidamente. [S10 §19.3, S11, S12 §3.2.3]

Consecuencia: retiro la recomendación incondicional de «guardar todo en la solicitud y poner kilómetros/tiempo en el email». Antes de persistir resultados o distribuirlos en correos durables hay que establecer una base contractual aplicable o confirmar con Google. Guardar con un TTL arbitrario de 30 días no resuelve por sí solo distancia/duración. Esto es una alerta técnica de cumplimiento, no asesoría legal.

Diseño conservador propuesto: conservar datos aportados/confirmados por el cliente y Place ID con procedencia; resultados externos separados de Submitted Details, obtenidos para consulta con atribución. No archivar respuestas brutas. Un resultado calculado nunca es «dato original recibido».

### 6. Alternativa omitida: enlace de ruta para ventas

Maps URLs abre Google Maps con origen, destino, modo de viaje y Place IDs opcionales. No requiere API key para el enlace. [S13]

Dos opciones reales:

1. **Asistencia a ventas:** autocomplete + texto libre + enlace «Ver ruta desde bodega» en administración/correo interno. Ventas consulta distancia en Maps y decide el precio. Places mantiene sus requisitos de facturación y políticas; solo el enlace no requiere API ni devolver datos al sitio. También se puede empezar con texto manual y enlace, sin Places, pero no satisface las sugerencias solicitadas.
2. **Cálculo integrado:** lo anterior más consulta de Routes en administración. Justificado si copiar/consultar kilómetros manualmente es el problema real. Resolver términos de datos, política de ruta, tiempos de consulta, fallos, seguridad y presupuesto antes de comprometer automatización.

La primera sigue la operación documentada por ADR-0001 y minimiza dependencias. No afirmar que cumple el objetivo si el dueño necesita que los kilómetros o el costo se calculen dentro del sitio: esa decisión sigue pendiente.

### 7. Widget oficial viable, integración no comprobada

`PlaceAutocompleteElement` crea su propio componente/input, tiene selección `gmp-select`, filtros geográficos y manejo de sesiones. La referencia actual documenta `name`, `value`, partes para input/predicciones/foco y propiedades CSS. Sí existe personalización oficial: no afirmar que su interior sea totalmente inestilizable. Debe fijarse una versión/canal compatible y comprobar el contrato efectivo. [S1, S2]

Para el widget se utiliza Maps JavaScript API y Places API (New). No es necesario renderizar un mapa. No confundir este widget con `BasicPlaceAutocompleteElement` de Places UI Kit, que tiene otro SKU. [S1, S2, S6]

Aspectos a probar posteriormente: serialización de Woo/jQuery, `updated_checkout`, texto sin seleccionar, borrar/editar tras selección (invalidar asociación anterior), ida y vuelta del formulario, Google bloqueado o sin red, cambio a «Sin despacho», teclado y móvil. Mantener instrucciones de acceso/parcela sin perder capacidad del textarea actual. No se ha hecho prueba de integración ni validación visual/hardware.

### 8. Costos y seguridad

Tarifa global consultada, USD por 1000 eventos en el primer tramo pagado, sin estimación de impuestos/cambio local: [S6]

| SKU | Eventos gratuitos/mes | Primer tramo pagado |
| --- | ---: | ---: |
| Autocomplete Requests | 10.000 | USD 2,83/1000 |
| Place Details Essentials | 10.000 | USD 5/1000 |
| Compute Routes Essentials | 10.000 | USD 5/1000 |
| Compute Route Matrix Essentials | 10.000 | USD 5/1000 |
| Geocoding | 10.000 | USD 5/1000 |
| Address Validation Pro | 5.000 | USD 17/1000 |

No es necesario contratar una suscripción mensual llamada «Essentials»: aquí Essentials es categoría/SKU bajo pago por uso. No equivale a 10.000 solicitudes comerciales gratis: autocomplete puede emitir múltiples peticiones mientras se escribe, también en sesiones abandonadas. En sesiones terminadas con Place Details Essentials se cobran hasta las primeras 12 peticiones de autocomplete, además de Details, sujetos a franquicias. Campos extra y opciones de tráfico pueden subir de SKU. [S6, S7, S14]

Presupuesto no validado: falta volumen real y configuración. Una franquicia podría cubrir el uso, pero no garantizar costo cero. Configurar alertas **y** cuotas; las alertas por sí solas no cortan el gasto. [S15]

Clave del navegador visible por diseño, restringida por sitios y APIs; clave de servidor independiente restringida por APIs e IP pública de salida (o autenticación soportada apropiada). No aplicar restricción de referrer a todas las llamadas. Verificar egress del alojamiento antes de configurar; no se ha probado. No leer ni configurar credenciales durante esta investigación. [S16]

Places/Routes también requieren términos/política de privacidad y atribución pertinente. Solo enviar a Google los datos necesarios del destino; no datos fiscales, email ni productos. [S9, S11]

## Aclaraciones del dueño tras la revisión

El dueño confirmó en esta sesión:

- Acepta no requerir coordenadas persistentes para calcular rutas.
- El transportista usa vehículos pequeños de flete; no se busca ruteo específico de camiones grandes.
- No necesita guardar kilómetros ni otros resultados derivados: basta conservar el destino para recalcular.
- Considera bajo el volumen de solicitudes y poco probable superar las franquicias. Esto expresa su expectativa, no una medición ni un límite de gasto configurado.
- El transportista cobra por distancia, pero demora en entregar el costo. Freeplast quiere responder antes con un precio aproximado basado en kilómetros, aceptando absorber diferencias respecto del cargo del transportista.

Se distinguieron en CONTEXT.md Dispatch Distance, Estimated Dispatch Price, Carrier Charge y Carrier. La aclaración no decide todavía quién hace la conversión de km a dinero (persona/software), dónde se consulta la distancia, criterio de recorrido, cobertura ni tratamiento de destinos ambiguos. No autoriza implementación ni despliegue.

## Ampliación del flujo y respuestas posteriores del dueño

El objetivo ahora incluye preparación asistida de cotizaciones, no solo dirección/ruta: solicitud → aviso al dueño → borrador con productos, cantidades, precios e historial → decisión humana → cotización final. Reabre el alcance de preparación/emisión de documentos con precios que la primera entrega dejó afuera. No se ha elegido arquitectura ni autorizado implementación.

Respuestas explícitas:

- **Historial:** existe una planilla donde Freeplast registra todas sus ventas. El sistema la cargará periódicamente; mecanismo y frecuencia quedan por decidir. No confundir solicitudes Woo con compras. Se acepta la recomendación de no afirmar «cliente nuevo» solo por falta de historial.
- **Precios de productos:** el dueño pidió aclarar qué significa mantenedor. No están aprobados tramos por cantidad ni descuentos automáticos por historial. Fuente editable, estructura y reglas siguen pendientes.
- **Notificación:** acepta correo con enlace a pantalla privada utilizable desde teléfono; no un enlace público que exponga historial/precios.
- **Emisión:** acepta vista previa y acción explícita «Aprobar y enviar», conservando la versión enviada. No emitir automáticamente antes de aprobación.
- **Despacho:** debe mostrar monto total y detalle de cómo se calculó; queda por decidir si el detalle también se envía al comprador. Regla, valores y fuente de la tarifa siguen pendientes. La lectura contextual es monto de despacho, sin dar por resuelto el desglose fiscal de toda la cotización.

Se actualizaron conceptos en CONTEXT.md; las mecánicas de importación/notificación permanecen en esta nota, no en el glosario. La frontera enumerada abajo corresponde a la revisión inicial: las respuestas de esta sección prevalecen y debe recomputarse la entrevista.

## Decisiones sobre precios y despacho — siguiente respuesta

- **Mantenedor:** pantalla privada de WordPress que permite importar una planilla Excel para actualizar precios. La lista mantenida en WordPress será la fuente de precios vigentes; Excel es una vía de actualización, no otra autoridad sincronizada implícitamente. Contrato del archivo, claves de asociación, validación y aprobación de importaciones pendientes.
- **Descuentos por volumen:** decisión manual de Freeplast inicialmente, sin automatización. Se entiende «cliente» en esta respuesta como Freeplast/el dueño, coherente con la decisión final humana; no descuentos editables por el comprador.
- **Despacho:** acepta regla explícita y editable, contrastada con cobros reales, mostrando componentes y permitiendo modificar el monto antes de enviar. No aprobó aún fórmula, coeficientes, tratamiento de retorno/peajes/impuestos ni mecanismo de extracción de datos históricos.

Se precisó Price List y se incorporó Price Adjustment al glosario. Las preguntas siguientes deben cerrar reglas fiscales, vigencia de precios en borradores, importaciones, identificación de clientes, cálculo de flete y emisión; no inventar las respuestas ni implementar todavía.

## Aprobación explícita de cinco recomendaciones — «sí a todo»

El dueño aceptó las cinco preguntas de la ronda siguiente:

1. **CLP y precios netos** en mantenedor; subtotal, despacho, IVA y total separados en la cotización.
2. **RUT de empresa normalizado** como asociación principal del historial. Sin uniones automáticas por similitud de nombres; ausencia de coincidencia = sin historial asociado, no prueba de cliente nuevo.
3. **Precios de borrador preservados** ante cambios del mantenedor. Actualización explícita, sin pérdida silenciosa de ajustes manuales. Versión enviada conservada.
4. **Desglose de flete interno**: comprador recibe monto de despacho separado, no kilómetros/tarifa/componentes de estimación.
5. **Monto de despacho aprobado comprometido** para cantidades y destino cotizados; Freeplast absorbe diferencias frente al transportista. Cambios de cantidades/destino requieren revisión y otra versión.

Esta conservación de la versión enviada corresponde a importes y condiciones comerciales; no revoca la decisión de no archivar kilómetros ni respuestas Google. Se actualizaron los conceptos del glosario. No se ha aprobado fórmula numérica de flete, contrato de importación, periodicidad concreta, formato de entrega/validez de cotización ni autorización de implementación/despliegue. Faltan muestras de datos para investigar mapeos reales.

## Aprobación operativa — segundo «sí a todo»

El dueño aceptó las cuatro recomendaciones de la ronda operativa:

1. **Entrega final:** correo al comprador con PDF adjunto, generado únicamente al aprobar. Cada versión preserva importes y condiciones, independiente de futuras modificaciones del mantenedor.
2. **Importaciones iniciales:** carga manual de Excel desde WordPress, con procesos separados para ventas y precios; vista previa, errores identificados y confirmación antes de aplicar. La periodicidad automática queda para después de conocer la fuente disponible. No se promete ni se configura un calendario automático en esta primera etapa.
3. **Datos incompletos/fallos externos:** se crea el borrador y se avisa al dueño igualmente. Faltantes visibles y completables manualmente; aprobación/envío bloqueados mientras falten datos obligatorios. Un monto ausente no se interpreta como cero.
4. **Vigencia:** configurable, inicialmente siete días, editable por el dueño antes de aprobar. El compromiso de precio aplica durante esa vigencia para las condiciones cotizadas.

Se incorporó Quotation Validity al glosario y se acotó Quoted Dispatch Price a esa vigencia. El siguiente paso de investigación necesita muestras de ventas, precios (si existe lista) y fletes históricos. Formatos, asociaciones de productos, calidad del historial y coeficientes del flete no pueden inferirse sin esos datos. Usar muestras anonimizadas o archivos fuera del repositorio público, sin copiar datos comerciales/personales reales a documentación versionada. Aprobación de recomendaciones de diseño no equivale todavía a cierre de entrevista ni autorización de implementación o despliegue.

## Frontera pendiente para retomar entrevista

1. ¿Se necesita que ventas abra una ruta preparada, que el sitio muestre km, o que calcule dinero? No son equivalentes.
2. ¿Cómo decide hoy Freeplast el costo de despacho? Distancia de ida o viaje completo, tipo/capacidad del vehículo, carga/volumen, peajes, mínimos, tarifas de transportista, destinos fuera de cobertura. No inventar una fórmula por kilómetro.
3. ¿Se acepta distancia de automóvil como referencia para cotizar, con revisión de acceso por ventas?
4. Cobertura comercial (Chile no significa necesariamente todo Chile), instrucciones rurales y alternativas de destino cuando no hay coincidencia. Un filtro de sugerencias no constituye por sí solo una regla de despacho del servidor.
5. Según lo anterior: momento del cálculo, audiencias, persistencia legal, costos máximos y credenciales. No decidir esas ramas prematuramente.
6. Confirmar acceso real de salida de la bodega, no solo texto postal. Enmiendas de ventas deben invalidar cualquier asociación/ruta anterior y conservar Submitted Details.

No se modificó CONTEXT.md: las decisiones siguen abiertas. No se inició servidor, se tocó dispositivo, se llamó a APIs comerciales con credenciales ni se desplegó nada. Único archivo creado: esta revisión; WIP ajeno preservado.

## Fuentes primarias

- **S1** https://developers.google.com/maps/documentation/javascript/place-autocomplete-new
- **S2** https://developers.google.com/maps/documentation/javascript/reference/places-widget
- **S3** https://developers.google.com/maps/documentation/routes/specify_location
- **S4** https://developers.google.com/maps/documentation/address-validation/coverage
- **S5** https://developers.google.com/maps/documentation/routes/lvr (actualizada 2026-09-08)
- **S6** https://developers.google.com/maps/billing-and-pricing/pricing
- **S7** https://developers.google.com/maps/documentation/routes/usage-and-billing
- **S8** https://developers.google.com/maps/documentation/routes/reference/rest/v2/RouteTravelMode
- **S9** https://developers.google.com/maps/documentation/places/web-service/policies (incluye excepción «Autocomplete for end user addresses»)
- **S10** https://cloud.google.com/maps-platform/terms/maps-service-terms (la extracción legible omitió §19; verificado además en HTML original)
- **S11** https://developers.google.com/maps/documentation/routes/policies
- **S12** https://cloud.google.com/maps-platform/terms
- **S13** https://developers.google.com/maps/documentation/urls/get-started
- **S14** https://developers.google.com/maps/documentation/javascript/session-pricing
- **S15** https://developers.google.com/maps/billing-and-pricing/manage-costs
- **S16** https://developers.google.com/maps/api-security-best-practices

Método: contraste directo de documentación y términos oficiales tras búsqueda de descubrimiento; no tomar síntesis del buscador como evidencia final. Dos ejemplos de corrección al leer la fuente: existencia de TRUCK no implica cobertura Chile; el widget sí documenta partes CSS. Las herramientas no reportaron costo monetario de investigación, por lo que no se cuantifica.
