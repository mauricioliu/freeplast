---
target: "pages of http://mliu:8096/wp-admin/admin.php?page=fpw-quotations"
total_score: 23
max_score: 40
na_heuristics: 
p0_count: 0
p1_count: 2
target_identity: "file:/home/mauricio-liu/Projects/freeplast/wordpress/wp-content/plugins/freeplast-woo/owner-workspace.php"
target_fingerprint: "sha256:fb024adeec2bee06603d5538b49528cf839d03ab4f2658a807d1b7cb387527e2"
target_path: /home/mauricio-liu/Projects/freeplast/wordpress/wp-content/plugins/freeplast-woo/owner-workspace.php
timestamp: 2026-09-12T00-56-32Z
slug: plugins-freeplast-woo-owner-workspace-php-97a5f6a1
---
⚠️ DEGRADED: single-context (sin herramienta de subagentes; evaluación visual y técnica realizadas secuencialmente).

# Crítica del panel de cotizaciones

La base A es buena, pero todavía se siente más como un formulario administrativo que como una herramienta ágil para el dueño. No cambiaría la identidad visual: corregiría jerarquía, densidad y, especialmente, lo que ocurre después de emitir una cotización.

Revisé bandeja, ficha editable, vista previa guardada y ficha emitida, a 1440 y 412 px. No guardé cambios ni emití nuevas cotizaciones. Modo: Operate. Fuente visual: A seleccionada, implementación actual de owner-workspace.php y sus CSS/JS. PRODUCT.md todavía describe emisión futura; prevalece el contrato de implementación del 11 de septiembre para esta superficie, sin reescribir documentación como efecto secundario.

## Especificidad del diseño

Sí se reconoce Freeplast: productos reales, fotos, cantidades por pallet, precios unitarios y cuatro hitos comerciales. Manrope y la paleta azul/verde mantienen coherencia.

Lo menos resuelto es la composición: tarjetas muy largas, explicaciones repetidas y una barra negra flotante que parece heredada del prototipo. El trabajo ahora es hacer A más operativa, no abrir otra dirección estética.

Detector: encontró una advertencia de fuente en owner-workspace.php:19, pero es un falso positivo: FPWManrope es el alias del mismo archivo Manrope. En navegador aparecieron 11 señales: descarté 10 por corresponder al aviso local o a contenido dentro de desplegables cerrados. Queda una observación menor sobre longitud de línea. Los problemas importantes son de experiencia y jerarquía; el detector no los descubre.

## Salud del diseño: 23/40 — aceptable, con mejoras importantes

| # | Heurística | Nota | Observación principal |
|---|---|---|---|
| 1 | Estado del sistema | 2/4 | La vista emitida contiene mensajes contradictorios. |
| 2 | Lenguaje del usuario | 2/4 | «Proyección», «revisión del trabajo» y «transporte» dominan demasiado. |
| 3 | Control y libertad | 2/4 | «Volver a ajustar» conduce a importes que ya no pueden editarse. |
| 4 | Consistencia | 2/4 | La presentación no cambia suficientemente al pasar de borrador a emitida. |
| 5 | Prevención de errores | 3/4 | Buenas separaciones entre guardar, previsualizar y aprobar. |
| 6 | Reconocer antes que recordar | 3/4 | Cantidad, precio y referencia están juntos. |
| 7 | Eficiencia | 2/4 | Exceso de desplazamiento para una cotización de solo dos productos. |
| 8 | Minimalismo | 2/4 | Explicaciones y navegación redundantes compiten con la tarea. |
| 9 | Recuperación de errores | 3/4 | El código conserva trabajo y ofrece recuperación; no repetí pruebas de fallos. |
| 10 | Ayuda contextual | 2/4 | Hay mucha explicación, pero poca orientación breve en el momento preciso. |
| | Total | 23/40 | Aceptable; las diez heurísticas aplican. |

## Lo que funciona

- Los cuatro hitos independientes responden a lo que pediste. Las fechas y etiquetas no dependen únicamente del color.
- Cantidad y precio ofrecido juntos, con el precio de referencia separado, facilitan una decisión comercial sin confundir valores.
- Contacto e historial desplegables reducen ruido. La separación entre guardar y enviar también merece conservarse.

## Cinco problemas prioritarios

### 1. [P1] Una cotización emitida todavía habla como si no estuviera emitida

En la vista guardada de la solicitud 143 aparecen simultáneamente «Nada fue aprobado ni enviado al comprador» y «Versión aprobada» / «Aceptado por el transporte».

También siguen visibles «Volver a ajustar» y «Generar vista previa». El documento figura como generado, pero no hay un enlace al PDF dentro de esa pantalla; el enlace compartido para revisión está fuera del panel.

Impacto: al terminar la tarea, el dueño vuelve a dudar de qué ocurrió y qué documento quedó aprobado.

Corrección: distinguir claramente dos vistas:
- Borrador: editar → revisar → aprobar.
- Emitida: versión, importe, fecha, resultado del envío y acceso autenticado al PDF guardado.

Conservar importes congelados y no convertir aceptación del correo en recepción del cliente. Renombrar el regreso a «Volver a la cotización», no prometer ajustes inexistentes. Un acceso autenticado al PDF es una recomendación de implementación posterior, no autorización para exponer documentos ni implementar nuevas versiones.

Comando sugerido: impeccable harden + impeccable clarify.

### 2. [P1] La ficha no es realmente “productos primero”

A 412 px, el primer precio editable comienza aproximadamente en y=1000 y Guardar borrador en y=2440. La ficha completa ocupa unos 3000 px, con solo dos productos. Antes del precio aparecen cabecera, identidad, contacto y todo el seguimiento comercial. Mediciones sobre la copia local, que incluye un aviso de entorno; no son mediciones de producción.

Impacto: quien entra a preparar precios tiene que atravesar información que todavía no necesita.

Corrección: adaptar la jerarquía al estado:
- Borrador: productos y precios primero; cuatro hitos visibles, pero compactos.
- Emitida: seguimiento primero; oferta resumida y de solo lectura.
- Reducir altura por producto y acercar Guardar, sin reducir objetivos táctiles ni inventar cálculos en el navegador.

Comando sugerido: impeccable layout.

### 3. [P2] La barra negra flotante añade más ruido que utilidad

En la bandeja contiene únicamente «Bandeja», un enlace a la página donde ya estás, dentro de una barra mayormente vacía. En escritorio duplica la navegación y el resumen lateral. En las capturas se superpone a contenido al desplazarse.

Impacto: ocupa una zona valiosa de la pantalla sin ayudar a resolver la tarea principal.

Corrección: eliminarla de la bandeja y del escritorio. En móvil, reservar una barra compacta para estado de guardado y acción principal, con acceso al resumen. Preservar formularios nativos y funcionamiento sin JS.

Comando sugerido: impeccable distill + impeccable adapt.

### 4. [P2] La bandeja tarda demasiado en mostrar trabajo útil

En móvil, la primera solicitud empieza aproximadamente en y=661. Buscar, ordenar y el botón consumen tres filas. Los seis filtros ocupan 631 px en un espacio de 365 px: los últimos quedan fuera de la vista inicial.

Además, los filtros representan el primer hito pendiente, algo que etiquetas como «Pago pendiente» no explican por sí solas.

Impacto: escanear pendientes exige desplazarse y entender una regla implícita.

Corrección: búsqueda compacta, ordenación secundaria y filtros completos más descubribles. Explicitar «Próximo paso» si se mantiene esa clasificación. En escritorio, usar encabezados compartidos para evitar repetir cuatro etiquetas en cada fila. No imponer prerrequisitos a los hitos independientes, ni inventar SLA/antigüedad o contadores no calculados por la consulta real.

Comando sugerido: impeccable layout + impeccable clarify.

### 5. [P2] La vista previa da más protagonismo a las explicaciones que al importe

El total de la tabla móvil se presenta a 13 px, peso normal, mientras abundan párrafos sobre proyecciones, revisiones y lo que la vista no hace. También aparece “September 11, 2026” dentro de una interfaz española.

Impacto: el momento de revisar una oferta exige leer demasiado y no destaca suficientemente lo que se está aprobando.

Corrección: destacar total, vigencia y condiciones; dejar explicaciones técnicas en un desplegable. Mantener una advertencia breve sobre el envío. Revisar idioma y formato de fecha; parte de esa mezcla puede provenir de la configuración local.

Comando sugerido: impeccable typeset + impeccable clarify.

## Carga cognitiva y recorrido

Carga moderada: 3 de 8 criterios débiles — foco, jerarquía y cantidad de opciones visibles. Agrupar los cuatro hitos y separar contacto/historial ayuda. Agrupación, chunking, secuencia, memoria de trabajo y disclosure proporcionan apoyos; seis filtros son un punto de decisión con más de cuatro opciones, no prueba empírica de abandono.

El recorrido empieza razonablemente claro, se vuelve lento al preparar precios y termina con incertidumbre al consultar una versión emitida. Ese cierre debería ser el momento de mayor confianza.

## Alertas por tipo de usuario

- Dueño frecuente — Alex: demasiada altura por solicitud y producto; la navegación repetida no acelera el trabajo.
- Primera vez — Jordan: «Volver a ajustar» y «nada fue aprobado» contradicen la versión emitida.
- Uso móvil interrumpido — Casey: precio y guardado están muy separados; la barra inferior no ofrece Guardar directamente.

## Detalles menores

- «Descartar cambios sin guardar» aparece incluso cuando no hay cambios.
- «1 pallets completos» necesita concordancia.
- La ficha emitida conserva instrucciones sobre guardar y referencias de lista que ya no intervienen en esa oferta.
- Los tramos y el historial ampliado pendientes no se deben rellenar con datos inventados para mejorar la apariencia.

## Dirección recomendada y preguntas de decisión

Conservar A y abordar primero la claridad de la cotización emitida, la ficha móvil y la barra flotante. Son mejoras de funcionamiento y jerarquía, no un rediseño de marca.

1. Prioridad de uso: preparar precios desde el teléfono / revisar seguimiento diario / ambas por igual.
2. Alcance: tres primeros problemas / cinco problemas.

## Evidencia y límites

Capturas y evaluaciones: `.impeccable/review/owner-workspace-20260911-critique/` (ocho capturas; assessment-A.md registrado antes del detector; assessment-B.json).

Lectura y navegación de datos ficticios existentes; no nuevas emisiones, guardados, imports ni modificaciones del código de aplicación. Evaluación visual, no validación humana ni hardware. No lector de pantalla, no reproducción nueva de errores/concurrencia ni suite completa. El PDF como artefacto impreso no fue objeto de esta crítica de páginas; se evaluó su descubribilidad en la interfaz.
