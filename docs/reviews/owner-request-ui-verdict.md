# Prototipo de solicitud recibida por el dueño — veredicto visual

Fecha: 2026-09-09. Especificación #49; implementación comienza por #50 y continúa por los cortes #51–#62.

## Pregunta

¿Cómo debería organizarse lo que ve el dueño de Freeplast al recibir una Solicitud de cotización: el correo de aviso y el borrador privado para preparar la respuesta?

## Respuesta del dueño

Ante tres alternativas, el dueño respondió literalmente: **«A me gusta»**.

Se selecciona **A — Ficha completa** como base visual. En escritorio reúne solicitud original, productos/precios y despacho en el área principal; historial y resumen de la propuesta acompañan en una columna lateral. En móvil se apilan. El correo de aviso es común a las tres alternativas y abre el borrador privado.

No se proporcionó una razón adicional ni se indicó dispositivo de revisión. No inferir aprobación de todos los estados, accesibilidad o comportamiento por esta preferencia visual.

## Fuente primaria

El archivo `owner-request-ui.prototype.html` de esta misma carpeta conserva íntegramente A, B y C. Se abre directamente, sin instalar dependencias; imágenes y Manrope están embebidos. Parámetros: `?variant=A&view=email` para el correo; `?variant=A&view=draft` para el borrador. B corresponde a revisión por pasos y C a documento primero.

Todo vive en memoria y se pierde al recargar. Guardar, Maps, aprobación, PDF y envío son simulaciones. El código completo y la barra comparadora permanecen exclusivamente en esta rama desechable: **no fusionar la rama ni copiar el prototipo como implementación**.

## Alcance de la decisión

- Elegida la organización A, no autorizados implementación ni despliegue.
- Cliente, historial, precios, flete e IVA son ficticios; no constituyen políticas comerciales/fiscales aprobadas.
- No se aprueba por extensión el prototipo anterior de lógica.
- No se probaron permisos, sesión, base de datos, correo, PDF o Google reales.
- Observaciones del agente: sintaxis JS aceptada, navegación correo → borrador → vista previa y cambio A/B/C en Chrome; capturas a 412 y 1280 px. No equivale a validación humana de hardware/accesibilidad.
- WordPress, código productivo y WIP previo se mantuvieron intactos. La implementación posterior debe rehacer el diseño elegido con los contratos y pruebas de #49–#62.

## Activos

Logo Freeplast, fotos de Caja Cosechera y Caja Universal provenientes de los activos existentes del proyecto; tipografía Manrope proveniente del tema existente. Los datos comerciales de demostración no proceden de clientes reales.
