# Rendimiento de producción — freeplast.cl

> **Estado posterior, 2026-09-05 19:26 UTC:** [WP Super Cache recuperado con guardia HTTP](../wpsc-recovery-2026-09-05/README.md), hits medidos con medianas TTFB 0,105–0,161 s y catálogo 44/44 correcto. No se corrigió el arranque frío de OPcache; esta auditoría y la instrumentación enlazada conservan el estado previo.

> **Actualización posterior, 17:57 UTC:** el usuario autorizó instrumentación temporal. Se reprodujeron dos instancias nuevas/frías de OPcache tras pausas de 75 y 150 s, con TTFB de 9,51 / 7,51 s frente a 1,69 / 1,42 s inmediatamente después. Código y registros remotos retirados y ausencia verificada. [Resultado, límites y borrador para hosting](instrumentation/README.md). **No se aplicó reparación de configuración; el repro posterior sigue rojo.** El informe que sigue describe la fase previa de solo lectura.

Revisión del 2026-09-05, aproximadamente 17:11–17:30 UTC (13:11–13:30 Chile).
Sitio **legacy en producción**, no `freeplast.mliu.site` ni el nuevo WooCommerce.
Solicitud: revisar lentitud con acceso a cPanel y wp-admin. **Sin cambios de configuración, archivos, plugins, cron o contenido en producción.**

## Conclusión

La lentitud es real e intermitente y se concentra **antes de recibir el HTML**. Se reprodujo también en una ficha, no solo en la portada. Una carga medida en Chrome tuvo LCP de **7,082 s**, de los cuales **6,962 s (98,3 %) eran TTFB**. Los recursos estaban mayormente en la caché del navegador; esto aísla bien la espera del documento, pero no representa una primera visita móvil.

Hay evidencia independiente de restricciones de CPU del hosting: CloudLinux registra **48 CPU faults en el bucket del 5 de septiembre** y límite CPU LVE de **50 %** en su histórico. Sin embargo, los buckets por minuto correspondientes a las primeras mediciones de esta revisión registran cero faults. **No está demostrado que cada pausa de 8–14 s sea throttling ni qué plugin o llamada la origina.**

El patrón «primera petición lenta, siguientes bastante más rápidas» afecta a diferentes rutas y es compatible con calentamiento/reinicio del proceso PHP/OPcache o algún trabajo global del arranque. Falta perfil del servidor para distinguirlo de llamadas externas, bloqueos o consultas. No atribuirlo a Elementor, Wordfence o SQLite únicamente por estar instalados.

## Reproducción y mediciones HTTP

Sonda anónima de baja frecuencia, tres rondas secuenciales, una pausa de un segundo entre solicitudes. No es prueba de carga. Los umbrales del script son reglas operativas de esta auditoría, no una certificación CWV: FAIL si cualquier TTFB supera 5 s, la mediana supera 1,8 s, falta una respuesta o no es 200.

```sh
python3 docs/reviews/performance-2026-09-05/probe.py \
  /producto/totem/ / /categoria-producto/agricola/
```

Ejecutado, **exit 1**: [isolated.jsonl](isolated.jsonl). En este barrido no se lanzó simultáneamente una navegación de Chrome ni una lectura de wp-admin; el tráfico ajeno no está controlado.

| Ruta | Mediana TTFB | Máximo TTFB | Estado |
|---|---:|---:|---|
| `/producto/totem/` | 1,559 s | **8,051 s** | 3 × HTTP 200, FAIL por pico |
| `/` | 1,495 s | 1,676 s | 3 × HTTP 200 |
| `/categoria-producto/agricola/` | 1,326 s | 1,623 s | 3 × HTTP 200 |

Primera pasada correcta: [baseline-corrected.jsonl](baseline-corrected.jsonl), portada **13,330 s**, siguientes 1,468 / 1,552 s. En esa pasada también se intentó una traza de Chrome en paralelo; no usarla como medida aislada del servidor. Una primera visita anterior alcanzó **14,070 s**.

[baseline.jsonl](baseline.jsonl) contiene un **error del instrumento**: la ruta inventada `/producto/caja-cosechera-g1/` no existe. Sus 404 no son un fallo del catálogo y se excluyen. Se corrigió la sonda a la ruta real Tote antes de repetir y emitir conclusiones. Los tiempos de portada/categoría de aquel archivo sí son mediciones reales.

Controles:
- DNS/TCP/TLS normalmente completados en **25–41 ms**; un lookup inicial fue mayor, pero no explica 13 s.
- Imagen estática real del hero: HTTP 200, TTFB **44,9 ms**, total **70,1 ms**.
- HTML comprimido aproximadamente **21–22 KB**; transferencia tras primer byte normalmente submilisegundos/pocos milisegundos.
- Que el barrido caliente pase los umbrales no elimina la regresión intermitente. No se ha aplicado un fix ni obtenido un resultado post-fix.

## Navegador

Chrome DevTools mediante `chrome-devtools-axi`; el gateway MCP no expuso `navigate_page`, pero el CLI sí proporcionó trazas. Sin limitación artificial de red o CPU. Navegación anónima, escritorio; sin pruebas en hardware del usuario.

- LCP: **7.082 ms**.
- TTFB: **6.962 ms**; 98,3 % del LCP.
- Demora de descubrimiento del recurso LCP: 48 ms.
- Descarga LCP desde caché: ~0,071 ms.
- Demora de render: 73 ms.
- CLS observado: **0,00** en esa traza.
- Otra navegación: TTFB **11.254 ms**, FCP **11.384 ms**, load **11.451 ms**.
- INP, TBT, Speed Index y datos de usuarios reales: **no medidos**. No hay nota Lighthouse de rendimiento ni aprobación móvil.

Evidencia: [browser-metrics.json](browser-metrics.json), [lcp-breakdown.txt](lcp-breakdown.txt), [document-latency.txt](document-latency.txt), [render-blocking.txt](render-blocking.txt). Los extractos `.txt` están truncados por el CLI; las métricas del JSON se transcriben del resumen devuelto por `perf-stop` y de las lecturas DOM, no de esos extractos incompletos. Traza bruta local de ~50 MB: `/tmp/freeplast-perf-20260905.json.gz` (temporal; no se agrega al repo). La traza empieza antes de la recarga; usar **NAVIGATION_1**, no `NO_NAVIGATION`.

Secundario, no explicación del bloqueo actual:
- 94 recursos registrados; suma de `encodedBodySize` legible de ~1,44 MB, **no bytes de red reales de esa visita ni total exacto cross-origin**.
- Dos fondos JPEG de hero: **348.295 y 299.807 bytes** (~648 KB combinados), descubiertos por CSS de Elementor.
- Tres fuentes de iconos grandes: eicons ~86 KB, Font Awesome solid ~80 KB, brands ~78 KB.
- Los estáticos sí tienen caché (hero `max-age=604800, public`). El HTML está comprimido. No recomendar «activar gzip» ni decir «no existe ninguna caché».
- Consola de la navegación inspeccionada: solo mensaje JQMIGRATE; no error JS reportado. No se probaron formularios ni todas las interacciones.

## cPanel: recursos, PHP y cron

Acceso mediante login cPanel normal, secretos leídos de 1Password en memoria. HTTP Basic fue rechazado; el login por formulario/API de sesión funcionó. Ninguna credencial o cookie guardada en este repo.

### Recursos

- Uso de disco: **2,35 GB de 3 GB**, 78 %; no lleno.
- RAM física máxima publicada: **1,5 GiB**.
- Entry processes: 13; procesos: 65; I/O: 5 MiB/s; IOPS: 1024.
- `ResourceUsage/get_usages` mostró CPU actual 0 y máximo 100. El histórico LVE muestra **límite 50 %**, diferencia que debe aclarar el proveedor; no prometer un número de cores basado solo en el widget.
- [host-day-buckets.json](host-day-buckets.json): bucket 2026-09-05 UTC, **48 CPU faults**, cero faults RAM/EP/I/O/IOPS/NPROC. El bucket del 4 de septiembre tiene 27 CPU y 1 I/O. La consulta `period=1d` devolvió ambos buckets de calendario: **no sumar 75 y presentarlo como “hoy” o una ventana exacta de 24 h**.
- [host-snapshots.jsonl](host-snapshots.jsonl): tres snapshots revisados a las 15:20:15, 15:25:05 y 16:19:23 UTC, todos con `cpu_fault: 1` y procesos `lsphp`. No hay consultas SQL ni peticiones HTTP en esos snapshots que identifiquen un culpable.
- [host-hour-buckets.json](host-hour-buckets.json): buckets por minuto de las 17:12 y 17:13 UTC registran CPU media 38,14 / 15,6 (límite 50) y **cero faults**. Por eso los faults históricos no son una correlación concluyente con los picos medidos.

### PHP realmente ejecutado

wp-admin → Salud del sitio → Información:
- PHP **7.4.33**, SAPI **litespeed**, servidor Apache; MariaDB 10.6.27.
- MultiPHP API de cPanel declara `ea-php81`, heredado, FPM desactivado. **La configuración nominal no coincide con el runtime de WordPress**; revisar PHP Selector/CloudLinux y el handler efectivo.
- OPcache **activado**, 91 MB de 128 MB; no lleno.
- Pool de cadenas internadas mostrado al **100 %** (6 MB utilizables, 8 B libres), hit ratio 83,74 % en esa muestra. Candidato a ajuste/observación, **no causa demostrada**.
- PHP `memory_limit=2048M`; eso es un máximo por proceso, **no uso real**, y no significa que el plan disponga de 2 GB.
- WP_CACHE sigue `true`, pero no se listó `advanced-cache.php`; WPSC inactivo. La caché de objetos SQLite sí está instalada y activa (`object-cache.php`). No borrar constantes/drop-ins por rutina.

### Cron: corrección importante respecto del handoff previo

`wp-config.php`: `DISABLE_WP_CRON=true`.

**Hay dos tareas externas configuradas**, obtenidas mediante cPanel API2 Cron/listcron:

```cron
54 * * * * cd /home/freeplast/public_html; /bin/nice -n15 /usr/bin/php -q wp-cron.php >/dev/null 2>&1
*/10 * * * * /usr/bin/curl -s -o /dev/null "https://freeplast.cl/wp-cron.php?doing_wp_cron" > /dev/null 2>&1
```

Por tanto, el flag no prueba que cron esté detenido. No habilitar el cron por visita a ciegas. Hay que verificar ejecución y versión PHP del comando CLI, luego evaluar consolidar en un solo mecanismo con observabilidad. Esta auditoría **no ejecutó cron manualmente**, no comprobó entrega de correos ni finalización de backups y no cambió estas tareas.

## WordPress / plugins: inventario observado

WP **7.1**, tema Astra; producción conserva extensiones antiguas junto a otras recientes.

- Elementor 3.1.4; Elementor Pro 2.10.3 **activo** aunque su directorio se llama `elementor-pro.disabled-20260831`.
- WooCommerce 4.2.5; Essential Addons Lite 4.5.5.
- Wordfence 7.4.8 y Kadence Security Basic 10.0.3 **ambos activos**.
- Duplicator Pro 3.8.9.1 y UpdraftPlus 1.26.7 **ambos activos**.
- SQLite Object Cache 1.6.5, Rank Math 1.0.277.2, Site Kit 1.186.0, Templately 3.7.5 y WPCode Lite 2.2.0 activos, entre otros.
- **WP Super Cache 3.1.3 y LiteSpeed Cache 7.9 inactivos**. Se mantuvo intacta la mitigación del incidente de fichas.

La superposición de seguridad/backups es una hipótesis de trabajo para una prueba controlada, no prueba de plugins innecesarios. No se desactivó ningún plugin ni se dispararon actualizaciones. No actualizar Woo/Elementor/PHP juntos en producción: hay saltos grandes y avisos de compatibilidad.

El `error_log` leído contiene errores históricos de compatibilidad, incluido `filter_var()` con argumento de tipo incorrecto. No se obtuvo un error contemporáneo que explique estas peticiones lentas; no reutilizar esos errores viejos como explicación actual.

## Próximos pasos recomendados (requieren autorización para intervenir)

1. **Perfilar una petición lenta real**, con instrumentación temporal limitada y sin datos de clientes: bootstrap PHP, consultas, llamadas HTTP salientes, tiempos de hooks y reinicios/uptime de OPcache. Confirmar si la latencia aparece tras inactividad. El siguiente paso no es un nuevo plugin de caché indiscriminado.
2. Pedir al hosting aclaración del límite **50 vs 100 %**, logs de LSAPI/reciclado de procesos y evidencia alrededor de 17:12–17:13 y 17:25 UTC. Mejorar capacidad solo si se demuestra beneficio; no vender un cambio de plan como solución confirmada.
3. En una **copia aislada del legacy**, no sobre el staging nuevo ya aprobado, medir cada cambio por separado: ejecución de cron, solapamiento de seguridad/backups, ajustes OPcache y compatibilidad PHP/plugins. Evitar clonar dentro de la cuota de disco restante sin estimar tamaño.
4. Si se incorpora caché de páginas, elegir una solución compatible con el hosting y probar claves por URL, exclusiones y formulario. Ejecutar siempre el repro funcional y el barrido de [la revisión WPSC](../wpsc-hotfix-2026-09-05/post-fix/README.md). **No reactivar WP Super Cache por rutina.**
5. Después, optimizar los ~648 KB del slider y recursos visuales, con revisión humana de apariencia. No es la primera intervención para una espera del HTML de 8–14 s.

## Alcance y estado final

Se hicieron GET públicos, navegación anónima, login y lecturas administrativas/API. Las lecturas pueden generar sesiones, logs o tareas internas normales de WordPress; no hubo escrituras de configuración ni cambios operativos intencionados. Sin carga masiva, formularios comerciales, actualizaciones, purgas, instalación de profiler, edición remota, cambio de plan ni publicación.

**Diagnóstico acotado, no reparación aplicada.** El cuello de botella de servidor está demostrado; el componente exacto de los picos todavía no. Sin validación visual/hardware.
