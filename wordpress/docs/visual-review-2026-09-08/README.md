# Freeplast — observaciones visuales de staging

**Fecha:** 2026-09-08. **Sitio:** https://freeplast.mliu.site/.
**Código local al observar:** `0713770`; assets del tema servidos con `ver=1.0.14`.

## Estado y alcance

**Actualización:** el dueño confirmó estos defectos y pidió corregirlos. Las
correcciones locales, pruebas y límites están en [local-fix/README.md](local-fix/README.md).
Este informe conserva la observación original de staging; no implica que se haya desplegado el arreglo.

**Se encontraron defectos importantes de renderizado. No constituye aprobación visual, validación en hardware ni aceptación de #48.**

Revisión solicitada por el dueño en esta sesión. Chrome real mediante pi-browser-harness; ventanas de 412×915 y 1440×1000 CSS px, DPR 1. La matriz complementaria de catálogo usa alto 915 y anchos 320/375/599/600/601/768/769/999/1000/1024/1440. El navegador presenta una barra de desplazamiento de 15 px (412 de viewport → 397 de contenido). Son observaciones del renderizado en Chrome, **no pruebas de teléfono físico, Safari, teclado virtual o gestos**.

Recorrido: inicio, menú móvil, catálogo, ficha simple, relacionados, ficha con color, agregado, cotización poblada, incremento de cantidad, datos y envío, resumen desplegable, despacho, ayuda y eliminación hasta selección vacía. No se comparó con una sesión equivalente del prototipo A. Las capturas no acreditan paridad.

## Hallazgos prioritarios

| ID | Prioridad | Observación | Evidencia |
|---|---|---|---|
| V01 | Alta | **Tarjetas móviles demasiado estrechas.** En inicio a 412 px cada tarjeta mide 175.19 px y su columna de texto solo 31.19 px: «Caja Cosechera» se parte en fragmentos. El control de cantidad y el CTA salen del borde; el CTA queda recortado. En catálogo también ocurre. La matriz muestra tarjetas de 172.80 px dentro de una pista de 360 px a viewport 375; CTA de solo 26 px. Persiste en 600 y 768. | [Inicio móvil](captures/home-mobile-cards.jpeg), [Catálogo móvil](captures/catalog-mobile.jpeg), [Matriz](captures/catalog-contact-sheet.jpg), [Mediciones](catalog-matrix.json) |
| V02 | Alta | **Relacionados rotos también en PC.** En «Sigue completando tu selección», las tarjetas quedan como columnas muy angostas y separadas por grandes huecos. Los controles desbordan las tarjetas y «Agregar» se ve como una tira vertical recortada. | [Relacionados a 1440](captures/related-desktop.jpeg) |
| V03 | Alta | **Resumen de cotización comprimido en PC.** En 1440 px, la grilla del carrito mide 1200 px y declara columnas de 810/340 px, pero sus hijos miden 526.5/119 px. El resumen queda en 119 px; título y CTA se parten en varias líneas y las estadísticas desbordan. En móvil el resumen usa el ancho disponible, pero el título y el CTA quedan prácticamente sin padding lateral. | [Carrito PC](captures/cart-desktop.jpeg), [Carrito móvil](captures/cart-mobile.jpeg) |
| V04 | Media | **Contenedores y márgenes inconsistentes entre páginas.** El catálogo tiene breadcrumb, título y tarjetas pegados al borde izquierdo, sin el margen del header. En «Datos y envío» PC el contenido empieza aproximadamente en x=24 mientras el header empieza en x=112; queda cargado hacia la izquierda con gran vacío a la derecha. No es el mismo encuadre de inicio/producto/carrito. | [Catálogo PC](captures/catalog-desktop.jpeg), [Datos PC](captures/checkout-desktop-top.jpeg), [Inicio PC](captures/home-desktop.jpeg) |
| V05 | Media | **Aviso de privacidad duplicado en el formulario.** El mismo texto y enlace aparecen antes y dentro del bloque «Solicitud de cotización — sin pago», inmediatamente antes del botón final. Visible tanto en móvil como PC. | [Formulario móvil](captures/checkout-mobile-no-dispatch.jpeg), [Formulario PC](captures/checkout-desktop-bottom.jpeg) |
| V06 | Media | **CTA sin color elegido parece habilitado.** El botón «Agregar a Cotización» mantiene fondo azul, texto blanco y opacidad 1 aunque expone `aria-disabled=true` y clases `disabled wc-variation-selection-needed`. Después de seleccionar Azul su aspecto principal es prácticamente el mismo. Hay mensaje explicativo, pero el estado visual no ayuda a distinguir disponibilidad. No se probó forzar el agregado sin color. | [Sin color](captures/color-mobile-unselected.jpeg), [Con Azul](captures/color-mobile-selected.jpeg) |

Los tamaños anteriores son lecturas de `getBoundingClientRect`/estilos calculados, no estimaciones desde píxeles. Las relaciones porcentuales sugieren conflictos entre reglas Woo y la grilla propia, pero **no se realizó diagnóstico de cascada completo ni se aplicaron correcciones**.

### Otras observaciones

- En `/tienda/` se muestran 17 productos y ordenamiento, pero no se encontró buscador (`input[type=search]`: 0) ni navegación de categorías en la superficie revisada. Contrastar con el alcance esperado de catálogo antes de considerar esta ausencia aceptable.
- La ficha «Caja Universal Cerrada Color» usa una foto referencial negra; el aviso lo explicita. No se consideró una imagen fallida ni se aceptó como equivalente a otra foto/prototipo.
- No se interpretaron imágenes lazy aún no cargadas como errores de red.

## Interacciones efectivamente observadas

No equivalen a certificación funcional integral:

- Inicio y cabecera legibles en las capturas principales; menú móvil abre y permite ir a Catálogo.
- Ayuda PC abre, se ve centrada, cierra con Escape y devuelve el foco a «Cómo cotizar».
- La ficha simple presenta imagen, datos y CTA legibles en 412 y 1440, salvo sus relacionados.
- Agregado de Caja Cosechera 3/4 ×2 → cabecera 1 producto y dock 2 unidades.
- Incremento en carrito de 2 a 3 → cantidad visible 3, resumen 3 y mensaje «Cantidad guardada». El enlace Continuar volvió a `aria-disabled=false` después de la actualización.
- Elección Azul → estado seleccionado visible y posterior agregado de una unidad. Carrito mostró dos líneas: Cosechera ×3 y Universal Color/Azul ×1; resumen 2 productos / 4 unidades.
- Formulario: resumen cerrado y abierto en móvil; cambio a «Sin despacho» oculta dirección. **No se rellenaron datos personales ni se accionó Solicitar cotización.**
- Eliminación de ambas líneas de prueba → cabecera 0 y estado «Aún no agregas productos». La selección comenzó y terminó vacía. No se eliminaron productos preexistentes.
- Manrope aparece como fuente cargada en `document.fonts` (no solo como familia declarada).

## Evidencia y límites

34 imágenes en [`captures/`](captures/). Las capturas principales fueron abiertas e inspeccionadas individualmente. La matriz complementaria de 11 anchos guarda capturas y geometría; su hoja de contacto inspeccionada incluye 320/375/600/768/1024/1440. No se afirma revisión visual individual exhaustiva de cada captura intermedia.

No se ejecutaron: envío/confirmación, correo, validaciones de formulario por submit, fallos de red/carreras, screen reader, auditoría WCAG/contraste completa, foco de todo el recorrido, zoom 200%, reduced motion, otros navegadores ni hardware. Tampoco se revisaron las páginas completas de Contacto/Nosotros/Privacidad. No se abrió servidor de desarrollo ni se usó adb. Sin cambios de código/configuración, deploy, commit, push o escritura en GitHub.

**Siguiente paso propuesto:** corregir V01–V03 antes de pedir aprobación al dueño; después corregir espaciado/duplicaciones/estado visual y repetir estas superficies en el navegador y en PC/teléfono físicos con revisión humana.
