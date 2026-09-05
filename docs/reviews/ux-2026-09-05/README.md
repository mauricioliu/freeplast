# Auditoría de usabilidad — Freeplast

**Sitio:** https://freeplast.mliu.site/  
**Fecha:** 5 de septiembre de 2026  
**Resultado:** hallazgos y recomendaciones; no aprobación visual, móvil ni de lanzamiento.  
**Cambios:** únicamente este informe y sus evidencias. Sin cambios de código, despliegues ni solicitudes enviadas. La selección temporal de prueba quedó nuevamente vacía.

## Uso por agentes

Este documento es la fuente de verdad de los hallazgos **UX-01 a UX-17** de esta auditoría. Describe el estado observado en la fecha indicada, no necesariamente el estado actual del sitio.

Cuando recibas un encargo sobre un hallazgo:

1. Lee los límites de la sección 2, su entrada completa y la evidencia enlazada en la sección 9. Distingue el hecho observado de su riesgo y de la solución propuesta.
2. Contrasta el hallazgo con la versión actual. Registra el resultado por ID: reproducido, no reproducido o pendiente; «no reproducido» no equivale por sí solo a corregido.
3. Para el trabajo autorizado, usa su criterio de aceptación y las condiciones que deben conservarse en la sección 6. Registra cambios, comprobaciones y pendientes por ID; separa la comprobación técnica de la evaluación humana.

**Cierre documental:** cada ID incluido en el encargo tiene un resultado respaldado por evidencia o un pendiente explícito. Las decisiones comerciales requieren confirmación del responsable; el plan de la sección 8 es una entrega para evaluación humana, no una autorización para operar dispositivos, desplegar o enviar solicitudes.

## 1. Diagnóstico ejecutivo

El problema principal no es añadir más decoración: es **facilitar que alguien encuentre el producto correcto, confíe en sus especificaciones y envíe exactamente las cantidades que quiso pedir**.

Hay una base útil: catálogo acotado, fichas con estructura técnica, selección de varios productos, cantidades editables y contacto directo. Pero el recorrido presenta problemas reproducibles en búsqueda, confirmación de acciones, despacho y edición de cantidades. Además, buena parte del contenido todavía comunica un proyecto en preparación, no un catálogo listo para decidir.

**Recomendación:** corregir primero los fallos del recorrido y completar información comercial; después ajustar presentación y densidad. No recomiendo convertirlo en un comercio electrónico con pagos: el objetivo sigue siendo recibir solicitudes de cotización mayorista.

## 2. Alcance y límites

### Revisado

- **34 URLs/estados de contenido público**, mediante GET sin cookies: inicio, tienda, ambas categorías, cotización vacía, nosotros, contacto, privacidad, una ruta inexistente, ocho búsquedas y las **17 fichas**.
- Navegación real en el navegador conectado: inicio → cotización vacía → tienda → búsqueda → ficha; agregar, actualizar y quitar productos; seleccionar color; revisar selección y formulario; alternar despacho.
- Validación nativa de cantidad cero y selección obligatoria de color, sin enviar una solicitud.
- Cuatro comprobaciones con **axe-core 4.10.3**, sobre `.wp-site-blocks`: inicio, catálogo Agrícola, ficha de color seleccionada y formulario con despacho seleccionado/Actualizar en hover. Se incluyeron reglas WCAG A/AA y buenas prácticas; no constituyen una certificación.
- Lectura de CSS/JS y partes relevantes del código local. Los tres archivos públicos `style.css`, `nav.js` y `basket.js` coinciden byte a byte con los locales: ver [checksums](evidence/asset-checksums.json). Referencia local: commit `90d83f9`; no se afirma que todo el servidor corresponda a ese commit.

### Limitaciones importantes

- El navegador tenía una sesión WordPress autenticada. Se excluyó la barra administrativa de los hallazgos y de axe; el contenido se contrastó por separado con HTML público sin cookies. **No se realizó un recorrido interactivo anónimo independiente**.
- El viewport observado por el DOM fue **1440 × 1000 CSS px**. Las herramientas informaron cambios de tamaño que no se reflejaron en `innerWidth` ni en las media queries. Las capturas fallaron; el fallback produjo un recorte de un layout de otro ancho, descartado. Esto es una limitación de la herramienta, no un defecto móvil atribuido al sitio.
- No hubo revisión en teléfono real, lector de pantalla ni sesiones con compradores. Tampoco pruebas fiables de todos los recorridos de teclado. La pérdida de foco documentada sí se observó en `document.activeElement` tras acciones.
- No se pulsó «Enviar solicitud»: quedan fuera la confirmación final, los correos, la recuperación de errores del servidor y la recepción comercial. La validación de dirección en backend se revisó en código, no mediante un envío.
- No se midieron Core Web Vitals, rendimiento con red lenta, conversiones ni abandono. No hay fundamento para asignar una nota UX o prometer un porcentaje de mejora.

**Lectura del informe:** “observado” significa comportamiento/DOM o contenido público reproducible; “riesgo” es una consecuencia inferida; “propuesta” requiere aprobación y evaluación posterior. Ninguna observación de escritorio equivale a validación en hardware móvil.

## 3. Prioridades

P1 = resolver antes de presentar el flujo como listo para captar solicitudes. P2 = mejora importante después o junto con P1. P3 = pulido. No se identificó ni se declara un bloqueo absoluto del envío, porque ese paso no se ejecutó.

| ID | Prioridad | Hallazgo | Acción principal |
|---|---|---|---|
| UX-01 | P1 | «cajas» no encuentra productos | Búsqueda tolerante a plurales y vocabulario de uso |
| UX-02 | P1 | CTA principal inicia en selección vacía | Enviar al catálogo cuando no hay productos |
| UX-03 | P1 | Confirmación de agregado fuera del viewport | Respuesta visible junto a la acción |
| UX-04 | P1 | Dirección visible con No; obligatoriedad desincronizada con Sí | Corregir estados y validación de despacho |
| UX-05 | P1 | Cantidad escrita distinta de la guardada | Evitar envío con cambios pendientes |
| UX-06 | P1 | Información y fotografías provisionales | Cerrar la revisión del catálogo |
| UX-07 | P1 | Contraste insuficiente en estados interactivos | Corregir colores de selección y hover |
| UX-08 | P2 | Se pierde el foco al mutar la selección | Conservar/restaurar foco con un destino predecible |
| UX-09 | P2 | Formulario exige información fiscal desde el inicio | Revisar necesidad comercial y explicar campos |
| UX-10 | P2 | No se concreta qué ocurre después | Explicar proceso y expectativas reales |
| UX-11 | P2 | Orientación del catálogo mejorable | Contadores, accesos por necesidad y estado de navegación |
| UX-12 | P2 | Comparación técnica costosa | Datos normalizados y diferencias entre modelos |
| UX-13 | P2 | Afirmaciones de confianza poco verificables | Evidencia real y ubicación/cobertura claras |
| UX-14 | P2 | Landmarks duplicados y jerarquía del footer | Corregir semántica del shell |
| UX-15 | P2 | Quitar es inmediato y no ofrece deshacer | Recuperación breve y accesible |
| UX-16 | P2, pendiente | Menú estrecho requiere examen específico | Cierre visible, foco y navegación detrás del panel |
| UX-17 | P3 | Copy repetitivo y poco orientado a tareas | Unificar términos y reducir instrucciones de posición |

## 4. Hallazgos principales

### UX-01 — La búsqueda falla con palabras que un comprador sí usaría

**Observado.** Estas consultas públicas devolvieron:

| Consulta | Productos encontrados |
|---|---:|
| `caja` | 11 |
| `cajas` | 0 |
| `palta` | 0 |
| `paltera` | 1 |
| `frutilla` | 1 |
| `frutlla` | 0 |
| `cosechera` | 1 |
| `rojo` | 2 |

Reproducción directa: [caja](https://freeplast.mliu.site/?s=caja), [cajas](https://freeplast.mliu.site/?s=cajas), [palta](https://freeplast.mliu.site/?s=palta).

**Riesgo:** el usuario puede concluir que Freeplast no vende lo que necesita. «Cajas» no es un caso exótico ni un error de tipeo. Las especificaciones renderizadas también contienen usos que conviene incluir en el índice, no solo texto de publicaciones.

**Propuesta:** normalización lingüística controlada, alias revisados —p. ej. palta/paltera— y búsqueda en nombre, descripción y atributos relevantes. Mantener los enlaces de recuperación actuales; añadir sugerencias específicas. Para 17 productos no hace falta introducir un buscador complejo antes de arreglar estos casos.

**Aceptación:** `caja` y `cajas` encuentran las mismas cajas activas; `palta` encuentra productos pertinentes aprobados; los typos tienen una sugerencia cuando existe una coincidencia suficientemente clara. No sugerir compatibilidades no verificadas.

### UX-02 — «Cotiza Online» lleva primero a una selección vacía

**Observado.** Desde inicio, con contador cero, el CTA principal abre `/cotizacion/`: «Tu cotización está vacía». Recién allí aparece «Explorar la tienda». El mismo destino se ofrece desde Contacto.

**Riesgo:** el primer clic no inicia el trabajo prometido: pide retroceder y encontrar otro punto de partida.

**Propuesta:** CTA «Ver productos y cotizar» → catálogo cuando no hay selección. Con productos, «Revisar mi selección» → cotización. Conservar `/cotizacion/` vacía como estado recuperable, no como recorrido principal del visitante nuevo.

**Copy propuesto:** «Elige productos, indica cantidades y solicita tu cotización. Sin registro ni pago en línea»; confirmar las condiciones antes de publicar el texto.

**Aceptación:** un visitante nuevo llega a productos desde el primer CTA; un visitante con selección no pierde su avance.

### UX-03 — La acción funciona, pero su confirmación queda fuera de la pantalla

**Observado.** En Caja Universal Cerrada Color, al agregar cinco unidades rojas con `scrollY=596`, el botón estaba en `y=531`, dentro del viewport. El estado «se agregó a tu cotización» apareció en `y=-509`, fuera de la pantalla. El botón conservó el mismo texto; el contador cambió a 2, también arriba. Se repitió el problema mediante agregado rápido del catálogo.

El mensaje tiene `role=status`, lo cual es positivo, pero **anuncio accesible y confirmación visible son dos necesidades distintas**. Aunque el CSS declara sticky en un wrapper del encabezado, el encabezado salió del viewport en esta sesión.

**Riesgo:** repetir clics, agregar unidades de más o creer que el botón no respondió. Esto es riesgo, no una duplicación accidental observada en clientes.

**Propuesta:** confirmación persistente junto al botón: «Agregaste 5 unidades · Rojo», con «Seguir eligiendo» y «Revisar selección». Añadir estado de operación en curso y evitar doble activación mientras se guarda. Si se conserva un encabezado sticky, corregir su contexto contenedor y comprobar que no tape el foco ni contenido.

**Aceptación:** se sabe qué producto, opción y cantidad se agregó sin volver al inicio de la página, tanto desde ficha como desde tarjeta.

### UX-04 — El despacho tiene dos inconsistencias

**Observado.** En `/cotizacion/`:

1. Sin elección y al marcar **No**, la dirección continúa en el layout y árbol accesible. El contenedor tiene `.fpcq-hidden`, pero su `display` computado es `flex`.
2. Al cambiar a **Sí** desde el formulario inicial, la dirección permanece con `required=false` y `data-fpcq-manual-required="0"`.

**Causa contrastada:** en el CSS publicado, `.fpcq-field { display:flex }` aparece después de `.fpcq-hidden { display:none }` y gana por orden. El JS decide obligatoriedad con un dato generado a partir del estado inicial; ese dato mezcla «actualmente solicita despacho» con «necesita dirección manual».

**Matiz:** el backend contiene una validación para dirección ausente. No se afirma que acepte una solicitud inválida: no se envió ninguna. El problema es la incoherencia previa al envío y el riesgo de descubrir el requisito tarde.

**Propuesta:** ocultación que no pueda ser anulada por el estilo del campo; requerir dirección únicamente con despacho y sin destino confirmado; ejemplo de dirección completa. Etiquetar «¿Necesitas despacho?» y, si corresponde comercialmente, «Retiro en bodega» en lugar de un No ambiguo.

**Aceptación:** con «No», el campo queda fuera de la vista y de la tabulación; con «Sí», aparece y se marca obligatorio cuando no existe un destino confirmado. Cambiar de opción conserva lo escrito y evita validar campos ocultos.

### UX-05 — Escribir una cantidad no significa que vaya a utilizarse

**Observado.** Con 70 unidades guardadas, escribir 140 sin pulsar «Actualizar» dejó:

- input visible: **140**;
- selección obtenida del servidor: **70**;
- «Enviar solicitud»: habilitado;
- avisos de cambios pendientes en el contenido principal: **ninguno**.

**Riesgo:** enviar una cantidad distinta a la que se ve en el input. No se ejecutó ese envío; la discrepancia sí se comprobó. Los productos de la solicitud se resuelven desde la selección guardada, no desde el input de otro formulario.

**Propuesta:** guardar explícitamente antes de continuar o autoguardar con estado «Guardando/Guardado/Error». Si se mantiene «Actualizar», impedir continuar con cambios pendientes e indicar exactamente qué falta. No asumir que el usuario recuerda guardar cada línea.

**Aceptación:** la cantidad revisada coincide con la utilizada al enviar; una operación de guardado pendiente/fallida no se oculta. Mantener los datos de contacto al actualizar, comportamiento que ya se observó funcionando.

### UX-06 — El catálogo todavía transmite información interna de preparación

**Observado en las 17 fichas:**

- Las **17** incluyen «Imagen provisional — fotografía original pendiente».
- **7 de 17** muestran «Consultar» en las seis filas técnicas principales: Universal Ventilada Negra, ambas Universal Color, Paltera, Traversa Romano, Pediluvios y Ladrillo.
- Las **17** dejan «Cantidad mínima: Consultar».
- Los **cuatro modelos Universal** reutilizan `fp-universal.webp` y el alt «Caja Universal de plástico negro reciclado». Seleccionar Rojo no cambia la imagen.
- Hay textos como «pendientes de revisión con el cliente», «Nombre provisional; alias de revisión…» y «sin descripción publicada actualmente».
- Privacidad termina con «Texto definitivo en revisión».

**Riesgo:** confundir modelos, no poder comparar y dudar de si el catálogo está listo para recibir solicitudes. En particular, cerrada/ventilada y color/negra no pueden diferenciarse fiablemente por la foto actual.

**Propuesta:** aprobar datos y fotos con el negocio antes de publicación. No basta con esconder la palabra provisional. Para datos realmente desconocidos, explicitar qué confirmará ventas y ofrecer consulta contextual; evitar diez repeticiones de «Consultar» entre resumen y tabla. No fabricar medidas, stock, certificaciones, mínimos ni colores. Publicar privacidad revisada por el responsable correspondiente.

**Aceptación:** cada producto disponible para solicitar tiene identidad visual correcta y datos suficientes para reconocer su uso; ninguna nota editorial interna aparece al comprador. Los datos faltantes se tratan de forma honesta y útil.

### UX-07 — El contraste falla justamente al seleccionar o interactuar

**Medido con axe y respaldado por los colores publicados:**

- Texto de **Rojo seleccionado** y **Sí seleccionado**: `#3a3a3a` sobre `#558948`, **2,73:1**.
- **Actualizar en hover**: blanco sobre `#558948`, **4,15:1**.
- El texto es de 14 px; el umbral aplicado es **4,5:1**, no el de texto grande.

**Propuesta:** usar una superficie seleccionada clara con texto oscuro, o una superficie suficientemente oscura con texto claro; conservar borde/radio/indicador para no depender solo del color. Separar tokens de fondo decorativo y fondo de control interactivo. Revisar también el hover del resto de botones que reutilicen el verde suave.

**Aceptación:** estados normal, hover, foco, seleccionado y error cumplen contraste aplicable. La comprobación automatizada se complementa con lectura humana; no basta con el estado inicial.

Evidencia: [cotización](evidence/axe-cotizacion-selected-hover.json), [producto de color](evidence/axe-producto-color-selected.json). Referencia del verificador: [regla de contraste de axe](https://dequeuniversity.com/rules/axe/4.10/color-contrast?application=axeAPI).

## 5. Mejoras importantes adicionales

### UX-08 — Conservar el foco tras actualizar, agregar o quitar

**Observado:** `document.activeElement` terminó en `BODY` después de Actualizar, del agregado rápido que cierra el disclosure y de Quitar. El JS reemplaza el bloque de selección completo, o esconde el control que tenía foco.

**Propuesta:** restaurarlo al control equivalente, al resumen de la tarjeta o a la siguiente línea; al quitar el último producto, al título del estado vacío. Mantener el anuncio `role=status`. **Aceptación:** se puede continuar la tarea sin reiniciar la navegación desde arriba. La prueba completa con teclado y lector queda pendiente.

### UX-09 — Reducir o justificar la carga de datos empresariales

**Observado:** Nombre, Teléfono, Email, Nombre Empresa, RUT Empresa y Giro son obligatorios, además de elegir despacho. Mensaje sí está marcado opcional. No hay ejemplos visibles para RUT ni Giro.

**Propuesta para decidir con ventas:** comprobar si RUT/Giro son necesarios al pedir precio o solo al preparar el documento comercial. Si son necesarios, mantenerlos y explicar el motivo, agruparlos como «Datos de la empresa» y dar ejemplos de formato. Si no lo son, posponerlos. No modificar por cuenta propia una condición acordada con el cliente.

**Aceptación:** todos los campos obligatorios tienen una razón comercial; se entiende qué escribir y qué es opcional. Conservar `autocomplete`, tipos email/tel y textos asociados a los campos.

### UX-10 — Explicar qué pasa después, sin inventar un plazo

**Observado:** se pide enviar datos para que Freeplast prepare una cotización, pero no se concreta en el formulario el canal esperado ni un plazo de respuesta. La distinción solicitud/cotización no siempre es evidente.

**Propuesta:** tres pasos breves: «Elige productos → Indica cantidades y entrega → Recibe respuesta de ventas». Cerca del envío, explicar que no es una compra ni un cobro. Añadir canal y plazo solo cuando ventas pueda cumplirlos. En la confirmación futura, mostrar referencia, resumen y siguiente paso; esa pantalla no se evaluó aquí.

**Aceptación:** el usuario puede explicar qué envía y qué recibirá sin llamar para preguntar. No prometer «24 horas» o disponibilidad sin autorización.

### UX-11 — Mejorar orientación sin sobredimensionar los filtros

**Observado:** 17 productos, 5 en Agrícola y 12 en Otros. El catálogo no muestra un contador de productos como sí lo hace la búsqueda. Cambiar categoría conserva el título genérico «Tienda». El filtro activo expone `aria-current`, pero los enlaces de navegación principal no.

**Propuesta:** «17 productos», «Agrícola · 5 productos», navegación activa y acceso al buscador más visible. Mantener la clasificación aprobada Agrícola/Otros; añadir, si los datos lo permiten, accesos por uso o familia sin sustituirla automáticamente. No hace falta filtrar por decenas de atributos para un catálogo tan pequeño.

**Aceptación:** se entiende dónde se está, cuántos resultados hay y cómo recuperar el catálogo completo.

### UX-12 — Facilitar comparación y lectura técnica

**Observado:** las tarjetas mezclan dimensiones, capacidad, material y usos en textos de formato desigual. Algunas fichas repiten datos en párrafo, cuatro atributos y tabla. En el viewport de escritorio observado, el artículo de producto mide 680 px; sus dos columnas dejan unos 300 px para el título, y el CTA del producto de color quedaba por debajo de `y=1127`.

**Propuesta:** priorizar uso, medidas exteriores/interiores, capacidad/carga cuando estén confirmadas, material y color. Normalizar unidades: mm, kg, g, L y orden largo × ancho × alto. Hacer explícita la diferencia entre peso propio, capacidad y resistencia. Un resumen comparable entre modelos es más útil que más párrafos. Revisar ancho y densidad con el diseño aprobado; no se emite un juicio visual definitivo sin capturas válidas.

**Importante:** las 70 unidades por pallet de Cosechera son embalaje, **no un mínimo de compra**. Mantener esa distinción y acercar la aclaración al selector; no imponer múltiplos de pallet sin aprobación.

**Aceptación:** los atributos confirmados usan el mismo nombre, unidad y orden en tarjetas y fichas; se distinguen peso propio, capacidad, resistencia y embalaje. Las diferencias aprobadas entre modelos Universal quedan explícitas. Los datos pendientes continúan identificados como tales. El ajuste de ancho y densidad queda pendiente de evaluación visual humana.

### UX-13 — Sustituir confianza declarada por evidencia verificable

**Observado:** Nosotros ofrece misión, visión y «Somos los mejores en el mercado del plástico», sin fotos ni enlaces de continuación dentro de su contenido principal. Inicio dice «Estamos en la VI Región y en Santiago»; Contacto solo concreta la dirección de Mostazal.

**Propuesta:** explicar si Santiago significa sucursal, atención o cobertura de despacho. Añadir fotos reales, experiencia documentada, casos o clientes autorizados y pruebas concretas de calidad. Publicar certificaciones únicamente si existen y aplican al producto. Añadir «Ver catálogo» al final de Nosotros.

**Aceptación:** ubicación, cobertura y promesas se pueden comprobar; no se inducen visitas a una sucursal no identificada.

### UX-14 — Corregir la estructura accesible del shell

**Observado en las cuatro comprobaciones:** dos `banner` anidados y dos `contentinfo` anidados. axe también marca salto de encabezados en el footer (`h4` después de secciones de nivel 2). Varias reglas reportan el mismo problema estructural: no deben contarse como muchos defectos independientes.

**Propuesta:** un único encabezado global y un único pie global, eliminando el landmark interno redundante o cambiándolo por un contenedor neutro; ajustar la jerarquía de títulos. No resolver la duplicación únicamente añadiendo nombres diferentes a dos elementos que representan lo mismo.

**Aceptación:** navegación por landmarks clara y sin duplicados; jerarquía coherente. El enlace «Saltar al contenido» ya existe y debe preservarse.

### UX-15 — Permitir deshacer una eliminación

**Observado:** Quitar elimina inmediatamente la línea, sin confirmación ni acción Deshacer. No hay error técnico en que la eliminación sea inmediata; falta recuperación para un toque equivocado.

**Propuesta:** mensaje «Producto quitado · Deshacer» durante un tiempo razonable, anunciado de forma accesible. Para esta acción reversible es preferible a bloquear siempre con un diálogo.

**Aceptación:** recuperar producto, opción y cantidad sin volver a buscarlo; foco en un destino válido después de quitar.

### UX-16 — Menú estrecho: riesgo identificado en código, no validación móvil

**Revisión estática:** `.fp-sheet` ocupa toda la pantalla y tiene z-index 60, por encima del wrapper del burger (50). No hay un botón de cierre dentro del panel; el JS contempla Escape, clic en fondo y navegación por enlaces, pero no gestión explícita de foco o del contenido de fondo.

**Propuesta:** comprobar y asegurar un Cerrar visible dentro del panel, sin depender de Escape o de descubrir dónde tocar. Elegir un patrón no modal accesible o un diálogo con foco y fondo correctamente gestionados. Evitar navegación a controles tapados.

**Aceptación propuesta:** el panel abierto ofrece un control Cerrar visible y operable; cerrar devuelve el foco al disparador; el recorrido de teclado no alcanza controles tapados y la navegación a un enlace deja el panel cerrado. Registrar la evaluación humana de abrir/cerrar, Atrás, scroll y lectura en teléfono real.

**Estado:** pendiente de reproducción y evaluación móvil. La ausencia de un cierre interno se identificó en código; **no se declara que el menú móvil haya sido probado ni que una simulación lo valide.**

### UX-17 — Copy de tareas, no instrucciones sobre la página

**Observado:** conviven «Tienda», «Catálogo», «Cotización», «Tu cotización», «Ver tu cotización», «Ver mi cotización» y «Cotiza Online». Aparecen instrucciones como «el formulario ... está más abajo» y explicaciones largas sobre el contador del encabezado. Privacidad repite el título y hay «Contactanos» sin tilde.

**Propuesta:** menú «Catálogo»; panel «Productos a cotizar»; acción de producto «Agregar a mi selección» o mantener una denominación única acordada; envío «Solicitar cotización». Sustituir referencias a posiciones por enlaces o pasos claros. Preferir «Te confirmaremos disponibilidad y condiciones» a notas editoriales.

**Aceptación:** cada etiqueta anticipa su acción; el comprador distingue seleccionar productos, enviar una solicitud y recibir una cotización con precio.

## 6. Qué conservar

- Selección de varios productos y opciones, persistente al navegar. La prueba mantuvo cantidades y color Rojo correctamente.
- Agregado desde ficha y desde catálogo: son caminos útiles para compradores nuevos y recurrentes, una vez resuelto el feedback.
- Actualizar no borró el nombre de prueba escrito en el formulario.
- Cantidad cero inválida y color obligatorio mediante controles nativos.
- Labels asociados, `fieldset`/`legend` para opciones, inputs de contacto de 16 px en el DOM observado y autocompletado para nombre, teléfono, email y empresa.
- Tablas técnicas con caption y cabeceras; migas de pan y productos relacionados.
- Estado sin resultados con enlaces de recuperación; ruta inexistente respondió 404 con búsqueda y navegación útil.
- Teléfono, WhatsApp, correo, mapa y horario disponibles. Se comprobaron destinos de enlaces, no se hicieron llamadas ni se enviaron mensajes.
- Privacidad enlazada junto al envío; interfaz coherente con solicitar precios, no con pagar online.
- Código con reduced-motion y mejoras progresivas; sus modos alternativos no se probaron de extremo a extremo en esta revisión.

## 7. Orden recomendado de trabajo

### A. Integridad y respuesta de la interfaz

UX-03, UX-04, UX-05, UX-07 y UX-08: feedback visible, despacho coherente, cantidades guardadas, contraste y foco. Añadir pruebas de regresión de cada estado antes de darlo por resuelto.

### B. Entrada y elección de producto

UX-01 y UX-02: búsqueda plural/uso y CTA contextual. Cerrar UX-06 con el responsable del catálogo. No considerar el contenido provisional un detalle cosmético.

### C. Menos incertidumbre comercial

UX-09 a UX-13, UX-15 y UX-17: campos requeridos, expectativas de respuesta, orientación, comparación, confianza y recuperación. Mantener las reglas mayoristas aprobadas.

### D. Evaluación humana y preparación de lanzamiento

UX-14 y UX-16, junto con revisión real de todos los cambios. El trabajo semántico puede realizarse antes; la evaluación final no se sustituye por axe ni por compilación.

No se estiman días sin acordar alcance ni se crean issues, cambios de datos o despliegues automáticamente.

## 8. Plan de evaluación con personas

Primera ronda formativa con aproximadamente cinco compradores o personas que realicen compras similares; es una muestra exploratoria, no evidencia estadística de conversión.

### Tareas, sin decirles qué botones pulsar

1. «Necesitas cajas para cosecha: encuentra una opción y dime por qué sirve».
2. «Busca cajas para paltas usando las palabras que usarías normalmente».
3. «Elige una Universal cerrada de color rojo y explica cómo se diferencia de la ventilada».
4. «Prepara una solicitud con dos productos; cambia una cantidad y elimina/recupera uno».
5. «Pide despacho y luego decide retirar; explica qué datos siguen siendo necesarios».
6. «Antes de enviar, dime qué ocurrirá después, por qué canal y si esto implica una compra».

### Registrar

- Finalización sin ayuda; primer clic; búsquedas sin resultado; retrocesos y dudas.
- Errores de modelo/color/cantidad y clics repetidos después de agregar.
- Comprensión de mínimos, embalaje y diferencias de especificación.
- Campos que obligan a buscar información fuera del sitio.
- Visibilidad de confirmación y comprensión del siguiente paso.

**Criterios de salida propuestos:** ninguna pérdida o discrepancia de datos entre lo revisado y lo enviado; sin ayuda para recuperar acciones; contenido comercial revisado; sin fallos de contraste conocidos en controles. Las metas de tiempo y conversión deben fijarse con una línea base, no inventarse ahora.

### Revisión humana en teléfono real, aproximadamente 412 px de ancho

- Primer acceso, lectura y primer CTA; menú con cierre visible.
- Teclado virtual, autocompletado, zoom de texto y cantidad legible. El campo de cantidad hereda 12 px en el escritorio observado: revisar una presentación de 16 px sin afirmar que se probó un problema en iOS.
- Despacho Sí/No, dirección larga y mensajes de error, sin elementos ocultos accesibles por accidente.
- Confirmación de agregado visible y acceso a la selección sin recorrer toda la página.
- Foco no tapado por encabezados o barras; orientación horizontal y preferencia de movimiento reducido.
- Tolerancia a red lenta y doble toque; recuperación al volver desde WhatsApp o mapa.
- Lector de pantalla y teclado por una persona que pueda comprobar la interacción real.

También queda pendiente un envío controlado y autorizado para comprobar referencia, resumen, correos y atención comercial. Hacerlo con contención de correo apropiada al staging. Medir rendimiento por separado con un contexto anónimo y condiciones reproducibles; **HTTP 200 no significa carga rápida**.

## 9. Evidencias y puntos de implementación

### Archivos

- [Contenido público de 34 rutas](evidence/public-pages.json): sin cookies, nonces, datos de cliente ni HTML de formularios ocultos.
- [Observaciones de interacción y limpieza](evidence/interactions.json).
- [axe — Inicio](evidence/axe-inicio.json).
- [axe — Agrícola](evidence/axe-catalogo-agricola.json).
- [axe — Producto de color seleccionado](evidence/axe-producto-color-selected.json).
- [axe — Cotización seleccionada/hover](evidence/axe-cotizacion-selected-hover.json).
- [Comparación de CSS/JS publicados con el repositorio](evidence/asset-checksums.json).

### Mapa técnico orientativo, no cambios implementados

- Búsqueda: `wordpress/wp-content/plugins/freeplast-catalog-quotes/includes/class-discovery.php`, `render_search()` y generación de tarjetas.
- CTA de entrada: `wordpress/wp-content/themes/freeplast/templates/front-page.html` y contenido de Contacto.
- Feedback, foco y cambios pendientes: `wordpress/wp-content/plugins/freeplast-catalog-quotes/assets/js/basket.js`, `apply()` y listener de submit.
- Ocultación de despacho: `wordpress/wp-content/themes/freeplast/style.css:1293` frente a `:1356`.
- Requisito de dirección: `wordpress/wp-content/plugins/freeplast-catalog-quotes/includes/class-address.php:475–500` y listener `change` de `basket.js`.
- Estados de color/hover: `.fpcq-choice:has(input:checked)`, `.fpcq-add-option:has(input:checked)` y `.fpcq-edit-submit:hover` en el CSS del tema.
- Landmarks y menú: `parts/header.html`, `parts/footer.html`, sus template-parts y `assets/js/nav.js`.
- Catálogo: `wordpress/data/products.json` y medios asociados, mediante revisión y sincronización aprobadas; no inventar datos en las plantillas.

**Decisión recomendada:** antes de un rediseño estético, asegurar «encuentro lo correcto → sé que se agregó → reviso lo que realmente enviaré → entiendo qué ocurrirá después».
