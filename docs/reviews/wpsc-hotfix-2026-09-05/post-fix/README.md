# Hotfix aplicado: WP Super Cache fuera de servicio

> **Mitigación sustituida a las 19:26 UTC:** [WPSC recuperado con guardia HTTP y pruebas](../../wpsc-recovery-2026-09-05/README.md). Este documento conserva el cambio de las 16:06–16:18 UTC; no describe el estado final actual.

2026-09-05, 16:06–16:18 UTC (12:06–12:18 Chile).
Autorización explícita del usuario después del informe fallido: «has los fixes tú».
Producción: `https://freeplast.cl`, sitio legacy. Staging intacto.

## Cambio aplicado

1. Antes de tocar ajustes, se volvió a ejecutar `../repro.py`: UPC→G1 volvió a devolver el HTML de UPC, mismo hash, `Age: 2`.
2. WP Super Cache → Sencillo → **Almacenamiento en caché desactivado** → Actualizar estado. Confirmado guardado: radio OFF marcado, ON desmarcado.
3. Repro inmediatamente después: **exit 0**, UPC y G1 correctos, un solo Cache-Control. Evidencia: [cache-off-repro.txt](cache-off-repro.txt). Esto ocurrió **antes** de la nueva purga.
4. Pestaña Precarga: muestra **«Precarga de caché inactiva»** al estar la caché OFF; ya no ofrece el formulario. No se reactivó la caché para editar su intervalo. El flag histórico de modo precarga puede seguir almacenado.
5. Contenido → **Vaciar la caché**, mediante el formulario real del plugin. Resultado recién generado: WP-Cache y WP-Super-Cache 0 KB / 0 páginas (no las estadísticas antiguas de la revisión inicial).
6. Plugins → WP Super Cache **3.1.3** → **Desactivar**. Confirmación «Plugin desactivado» a las 16:13:34 UTC. Tras nueva carga a las 16:16:05, fila `inactive`, acción **Activar** (no Desactivar). No se desinstaló ni borró el plugin.

**Estado final deliberado: WP Super Cache inactivo.** Su precarga no tiene callbacks activos mientras el plugin esté inactivo. No se afirmó que se hayan eliminado todos los eventos cron almacenados.

## Comprobaciones posteriores

- [summary.json](summary.json), [results.json](results.json), [sweep.log](sweep.log): 47 GET anónimos secuenciales, 16:14:09–16:16:38 UTC, **44/44 comprobaciones funcionales pasan**.
- Dos vueltas completas: Agrícola 8/8, Carnes 2/2, Otros 2/2, Productos del mar 2/2 en cada vuelta. Las 28 visitas desde cards coinciden en título, canonical y nombre de Store API. Ahora sí se alcanzaron los 12 productos únicos.
- Además de los asserts del barrido, comprobación offline explícita de los ocho h1/canonical de categorías: correctos.
- Repeticiones Tote↔G1: todas correctas.
- Repro curl final después del barrido: **exit 0**, [plugin-off-repro.txt](plugin-off-repro.txt).
- Todas las respuestas 200 de fichas/categorías registradas tienen exactamente **`Cache-Control: max-age=0, public`**, sin `Age`. No reaparece la pareja de `max-age=3` y `max-age=0`.
- Chrome: Agrícola→clic Tote termina en URL, h1 y canonical Tote, sin barra admin: [browser-tote.txt](browser-tote.txt). No se enviaron formularios.

### Excepción conocida: URL histórica 404

El resumen marca **1 respuesta con varios Cache-Control**: `/producto/caja-pollera/`, HTTP 404, con `no-transform, no-cache, no-store, must-revalidate` y `public`. No es la duplicación de max-age del catálogo. No afirmar que todos los headers de todo el dominio quedaron normalizados.

El producto real Caja Pollera mantiene `/producto/base-para-pediluvio/`, y esa ficha pasó en ambas vueltas. No se cambiaron productos, slugs ni redirecciones.

## Interpretación y límites

La intervención mínima que hizo pasar el repro fue **apagar la caché de WPSC**; el plugin se desactivó después para mantenerlo fuera del recorrido de respuesta. Es una corrección operativa por retirada de esa caché, **no un parche al código del plugin ni una demostración de una carrera específica precarga/mutex**. Puede involucrar interacción con la caché del hosting/Apache. No se reactivó el componente problemático para experimentar con tráfico real.

Trade-off: las páginas dejan de beneficiarse de esa caché HTML y pueden requerir más trabajo PHP. No se hizo prueba de carga ni auditoría de rendimiento. Mantener el plugin inactivo hasta aislar la incompatibilidad y pasar el mismo repro en un entorno seguro.

No se modificó `wp-config.php`, `.htaccess`, cron del hosting, otros plugins, productos ni el sitio nuevo. No se aplicaron los cambios de mutex/clear-on-edit del handoff: con el plugin inactivo no solucionan nada adicional. El aviso previo de WP-CRON desactivado permanece como deuda separada; no se reactivó sin revisar previamente cron externo y trabajos pendientes.

No hubo validación visual ni hardware. Las comprobaciones anteriores son de HTTP, identidad de contenido y un flujo de navegación DOM.

## Reversibilidad

El plugin sigue instalado. No reactivarlo por rutina ni activar «caché recomendada»: puede restablecer los ajustes por defecto y reintroducir el incidente. Una futura reactivación debe ser un cambio explícito, controlado y acompañado por el repro y el barrido. Conservar esta mitigación durante cualquier futuro cutover a Woo nuevo.
