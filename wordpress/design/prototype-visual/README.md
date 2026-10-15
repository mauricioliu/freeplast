# Freeplast · PROTOTIPO visual desechable

Pregunta: ¿Cómo se ve el refinamiento de Freeplast en desktop y móvil?

Tres variantes de la Home copiada, en una única ruta `?variant=A/B/C`:
- A: recomendación del informe; banner completo, tres columnas y controles alineados.
- B: banner compacto y catálogo en filas para comparar cantidades.
- C: catálogo protagonista; banner lateral desktop y catálogo primero móvil, fotos mayores.

## Abrir
Desde esta carpeta:
```sh
python3 -m http.server 8000
```
Para compartir temporalmente por Tailscale:
```sh
~/.agents/skills/serve-output/scripts/serve-output.sh start "$PWD"
```

Flechas del selector, botones A/B/C o teclado ←/→. No se interceptan campos editables. URL estable al recargar. Cantidades y selección se pierden al recargar deliberadamente.

HTML y assets públicos capturados de http://mliu:8131/; se preservan ocho productos, contenido y fuentes vigentes (Manrope y Arvo/Poppins del banner), fotos y pie azul. `capture.cjs` documenta la extracción puntual (requiere Cheerio ya disponible en el entorno); no es necesario ejecutarlo para servir. Los scripts WordPress/Woo se retiraron; solo se conserva el carrusel visual y un JS propio de simulación. CSP bloquea conexiones y formularios. Contactos, fichas y páginas externas dan feedback de alcance, sin abrir destinos. No backend, persistencia, pagos ni solicitudes reales.

## Verificación ejecutada

PASS: Chrome aislado `freeplast-prototype`, A/B/C a 1440×900 y 390×844; ancho de documento igual al viewport en las seis vistas; ocho productos, cero imágenes rotas y fuentes cargadas. Una ronda, un lote de correcciones (filas B, espacio vacío de estado, ajuste banner C y favicon), una confirmación. Capturas completas `*-confirm.png` en `/tmp/freeplast-prototype-review/`.

PASS: recarga con `?variant=B`; selector A/B/C; flecha derecha cambia A→B y no cambia variante con input enfocado; menú móvil abre y Escape cierra; incremento 70→71, agregar, contador 1 y feedback de solicitud simulada. Consola final sin mensajes; red observada solo GET al puerto 7777, sin llamadas a WordPress. Los clics de comportamiento se dispararon mediante DOM en Chrome; teclado mediante `press` de chrome-devtools-axi.

NOT_RUN: teléfono físico, accesibilidad integral, regresión WordPress, envío real (deliberadamente inexistente). La segunda diapositiva se conserva con el carrusel original; no se hizo una matriz visual separada de esa diapositiva. Las capturas full-page muestran el selector fijo dentro de la imagen, no una sección de contenido.

Referencia: /tmp/freeplast-visual-proposal.md. Lecturas: prototype (SKILL/UI), catálogo, layout, impeccable (context una vez y craft-floor), chrome-devtools-axi y serve-output. No nuevas imágenes generadas. Decisión estética, captura en rama e implementación real pendientes del usuario.
