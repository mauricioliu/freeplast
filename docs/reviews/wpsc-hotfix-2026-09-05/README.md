# Verificación independiente del hotfix WP Super Cache

> **Estado posterior, 2026-09-05 19:26 UTC:** WPSC recuperado en producción, Simple/PHP con guardia contra la caché compartida del hosting. [Cambio, pruebas y límites](../wpsc-recovery-2026-09-05/README.md). El estado inactivo descrito abajo es histórico, no el actual.

> **Actualización 2026-09-05, 12:18 Chile:** con GO explícito del usuario se apagó la caché, se purgó y se desactivó WP Super Cache. Barrido posterior **44/44 pasa** y repro curl pasa. El plugin queda inactivo; no reactivarlo por rutina. [Cambio aplicado, evidencia y límites](post-fix/README.md). Lo que sigue conserva la revisión **fallida anterior al nuevo fix**.

Fecha: 2026-09-05, 15:41–15:47 UTC (11:41–11:47 Chile).
Entrada: `/tmp/handoff-freeplast-wpscache-2026-09-05.md`.
Alcance: **producción `https://freeplast.cl`**, sitio legacy. No staging/cutover.

## Resultado

**El cruce de páginas sigue ocurriendo.** La opción `cache_rebuild_files` permanece desactivada, pero eso no acredita que el incidente esté resuelto. También siguen apareciendo dos campos Cache-Control.

Prueba mínima independiente con curl, ejecutada y fallando con exit 1:

```sh
python3 docs/reviews/wpsc-hotfix-2026-09-05/repro.py
```

1. GET `/producto/traversas-para-bines/` → 200, h1 UPC, canonical UPC.
2. Esperar 1,6 s.
3. GET `/producto/traversas-para-bins-tipo-w/` → 200, **h1 UPC y canonical UPC**, aunque corresponde G1.
4. **Los dos cuerpos son idénticos byte por byte** (mismo SHA-256). La segunda respuesta incluye `Age: 1`.

Evidencia: [`minimal-repro.txt`](minimal-repro.txt). El mismo patrón ocurrió antes con otra secuencia curl independiente. Es intermitente: una ejecución verde aislada no prueba reparación.

## Barrido anónimo

[`sweep.log`](sweep.log), [`results.json`](results.json), [`summary.json`](summary.json).

- 47 GET secuenciales sin cookies, separados por al menos 1,6 s; sin proxy configurado para urllib.
- Dos rondas sobre las cuatro categorías, siguiendo los enlaces realmente presentes; comparación card→h1→canonical→nombre de Store API (12 productos).
- **44 comprobaciones: 24 pasan, 20 fallan**, incluyendo 6 comprobaciones de categorías y 14 de fichas.
- Ejemplos: Tote→Agrícola; Universal→Cosechera; G1→UPC; Frutillera→Tomatera. Incluso categorías devolvieron fichas de producto y sus productos relacionados.
- Los 20 fallos registran `Age: 1`. Esto es una pista de reutilización de respuesta, no una identificación definitiva del componente culpable.
- 43/47 respuestas del barrido traen dos campos: `Cache-Control: max-age=3, must-revalidate` y `Cache-Control: max-age=0, public`. No es cierto que el duplicado haya desaparecido de forma estable. Varios campos Cache-Control no son por sí solos un error; aquí además se repite `max-age` con valores distintos.
- Controles con `?fp_verify=20260905` en Agrícola, Tote y G1 devolvieron los títulos esperados y **un solo** Cache-Control (`max-age=0, public`). El contraste depende de la query, pero no demuestra por sí solo qué capa de caché se elude.
- `/producto/caja-pollera/` sigue devolviendo 404, como advierte el handoff; no se modificaron slugs.

**Límite de cobertura:** al devolver HTML de otro recurso, algunas categorías contienen productos relacionados en lugar del catálogo esperado. El barrido registra esto como fallo de categoría; no afirmar que se recorrieron correctamente los 14 enlaces previstos. Los conteos esperados provienen del handoff. Los cuerpos públicos completos permanecen en `/tmp/freeplast-wpsc-validation-20260905/`; los resultados compactos y hashes quedan aquí.

## Configuración administrativa observada (solo lectura)

Sesión existente de producción, sin obtener credenciales nuevas ni exportar cookies. Avanzado se leyó al inicio y se volvió a abrir al final; última lectura 15:47:27 UTC.

- `wp_cache_enabled`: marcado.
- **`cache_rebuild_files`: desmarcado**, en ambas lecturas.
- `wp_cache_clear_on_post_edit`: desmarcado.
- `wp_cache_mutex_disabled`: desmarcado (valor del input `0`); no se cambió.
- Precarga: `wp_cache_preload_on` y `wp_cache_preload_taxonomies` marcados; intervalo 600 minutos; última precarga mostrada 07:50:09.
- Sigue el aviso **«Sistema CRON desactivado»**. No se verificó si el hosting tiene un cron externo.
- Contenido muestra 0 KB / 0 páginas, **pero dice que las estadísticas se generaron hace 17 minutos y no se actualizan automáticamente**. No se regeneraron: ese dato no prueba que la caché esté vacía ahora ni acredita retrospectivamente las dos purgas.

## Navegador

Un recorrido Agrícola→clic Tote en Chrome, sin barra admin, terminó correctamente en Tote (h1/canonical correctos). Evidencia: [`browser-tote.txt`](browser-tote.txt). Ese recorrido exitoso no invalida las respuestas anónimas erróneas reproducidas mediante urllib y curl. No se hizo validación visual, móvil físico ni envío de formularios.

## Corrección de las conclusiones del handoff

- Confirmado: el ajuste rebuild OFF persiste.
- Refutado como estado actual: «el cliente ya puede ver el catálogo correctamente» y «un único Cache-Control».
- **No confirmado:** que precarga+cron+mutex sea la causa raíz exacta. La evidencia actual prueba mezcla de respuestas entre URLs; no atribuye la falla exclusivamente a WPSC ni demuestra una carrera de mutex. Un HTTP 200 con HTML de otro producto tampoco debe descartarse como mero throttling.
- `DISABLE_WP_CRON` no descarta que exista cron externo. No afirmar que garbage collection jamás corre sin revisar eso.

## Siguiente paso y límites

Mantener el incidente abierto. Con autorización de cambios, aislar la capa que reutiliza respuestas entre URLs y volver a ejecutar el repro tras cada cambio. No asumir que aplicar los pendientes del handoff resolverá la mezcla.

**No se guardaron ajustes, no se purgó, no se cambió cron, no se desplegó y no se enviaron cotizaciones.** Los GET de verificación pueden poblar caché y logs normalmente. Los cambios pendientes siguen requiriendo GO humano. No se tocaron los cambios preexistentes del árbol de trabajo; solo se añadió este directorio de evidencia.
