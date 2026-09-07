# Instrumentación autorizada — resultado y retirada

> **Actualización posterior, 2026-09-05 19:26 UTC:** se recuperó la caché HTML de WPSC con protección frente al cruce de respuestas del hosting. [Estado actual y pruebas](../../wpsc-recovery-2026-09-05/README.md). No se corrigió la pérdida de OPcache ni se cambiaron PHP/LSAPI. Las mediciones y el estado sin reparación descritos abajo corresponden a las 17:48–17:57 UTC.

2026-09-05, 17:48–17:57 UTC. Autorización del usuario en esta conversación: «autorizado» a instrumentar temporalmente producción. **No incluía cambiar configuración de hosting/PHP, desactivar plugins ni reactivar caché HTML.**

## Hallazgo principal

**Los picos capturados coinciden con una instancia nueva y fría de OPcache después de pausas sin nuestras peticiones.** No era simplemente que el primer visitante descargase imágenes sin caché. WordPress tiene que volver a compilar/cargar miles de archivos PHP; las peticiones inmediatamente posteriores reutilizan el código y son mucho más rápidas.

Se reprodujo en **dos rutas y dos pausas**, en siete solicitudes instrumentadas, sin reinicios, purgas ni llamadas a `opcache_reset()` provocadas por la sonda.

| Solicitud | TTFB curl | Inicio OPcache (epoch) | OPcache al entrar al MU | Misses al final | Tiempo PHP hasta captura | CPU del proceso hasta captura |
|---|---:|---:|---|---:|---:|---:|
| warm-1 `/` | 1,483 s | 1788630502 | 2.561 scripts; 7.753 hits | 2.563 | 1,404 s | 0,690 s |
| warm-2 Tote | 1,414 s | 1788630502 | 2.561 scripts; 10.315 hits | 2.563 | 1,354 s | 0,707 s |
| warm-3 `/` | 1,403 s | 1788630502 | 2.561 scripts; 12.879 hits | 2.563 | 1,330 s | 0,690 s |
| **75 s pausa → Tote** | **9,508 s** | **1788630597** | **444 scripts; 0 hits; 444 misses** | **2.594** | **9,183 s** | **4,018 s** |
| Repetición Tote | 1,691 s | 1788630597 | 2.594 scripts; 2.576 hits | 2.594 | 1,613 s | 0,826 s |
| **150 s pausa → `/`** | **7,510 s** | **1788630760** | **444 scripts; 0 hits; 444 misses** | **2.534** | **7,117 s** | **3,572 s** |
| Repetición `/` | 1,422 s | 1788630760 | 2.534 scripts; 472 hits | 2.534 | 1,359 s | 0,713 s |

Fuente: [capture-174820/events.jsonl](capture-174820/events.jsonl) y [capture-174820/server.jsonl](capture-174820/server.jsonl). Los PID de los hijos cambian incluso en solicitudes calientes; **un PID distinto por sí solo no demuestra pérdida de OPcache**. La evidencia es la nueva `start_time`, cero hits y miles de misses/compilaciones nuevos. `last_restart_time`, `oom_restarts`, `hash_restarts` y `manual_restarts` permanecen en cero: encaja con **nuevos procesos/segmentos de memoria**, no un reset registrado dentro de la misma instancia.

En frío, la sonda MU recién comienza a ~1,44–1,49 s desde `REQUEST_TIME_FLOAT`; en caliente, a ~22–39 ms. Luego el coste extra aparece distribuido por carga de plugins, init, tema y render, no en una única espera de un plugin.

### Qué queda confirmado y qué falta

- **Confirmado:** OPcache pierde su instancia caliente entre estos grupos de peticiones; la recompilación en frío acompaña el gasto de CPU y el salto de latencia.
- **Fuertemente indicado:** reciclado del proceso padre/grupo LSPHP por inactividad. La arquitectura documentada de mod_lsapi termina ese padre según `lsapi_backend_pgrp_max_idle`.
- **No leído del servidor:** el valor efectivo de ese timeout, la causa exacta de terminación del padre, ni logs del administrador. Las variables LSAPI seleccionadas no están expuestas al PHP de esta cuenta. No afirmar «este servidor está configurado exactamente a 30 segundos»: ese es el valor por defecto de la documentación, no una lectura local.
- El coste CPU/tiempo de pared es compatible con el histórico LVE limitado al 50 %, pero no se ha hecho una prueba cambiando dicho límite ni establecido cuánto de cada espera es throttling frente a otro scheduling. No prometer que cambiar de plan resuelva por sí solo la pérdida de caché.
- Las pausas son **sin nuestras solicitudes**; no hay control sobre el tráfico real ni sobre cron. El primer ciclo cruza las 17:50 UTC, cuando hay cron externo configurado. El segundo no cruza un minuto de cron programado y también reproduce el patrón.

## Hipótesis contrastadas

1. **Arranque de PHP/OPcache:** corroborado por las dos instancias frías y la diferencia inmediato/tras pausa.
2. **Un único plugin lento:** no aislado. Wordfence carga en ~0,72–0,78 s en frío frente a ~0,11–0,16 s en caliente, WooCommerce ~0,48–0,50 s en frío; también se encarecen otras fases. Los intervalos son inclusivos entre marcas; no son perfiles de self-time de cada callback.
3. **Consultas lentas a MySQL:** las consultas observadas mediante `$wpdb` suman **92 ms / 68 ms** en las dos solicitudes lentas. Sus máximos individuales son **14 ms / 41 ms**. No explican la mayor parte de 7–9 s.
4. **Llamadas HTTP salientes:** no se registraron llamadas finalizadas por la API HTTP de WordPress en estas diez solicitudes seleccionadas.

**Límites del instrumental:** inicia como primer MU plugin, después de parte del bootstrap y del object-cache drop-in; no es Xdebug/Tideways ni un profiler de todos los syscalls. `$wpdb->queries` no mide directamente las operaciones internas del SQLite object cache. Los hooks HTTP no observan cURL directo, sockets, llamadas antes de cargar MU o peticiones interceptadas antes del transporte. Por tanto, no se declara que “toda base de datos o toda red externa está descartada” en cualquier ruta.

## Runtime efectivo (segunda sonda breve)

Tres capturas más, [capture-175448/server.jsonl](capture-175448/server.jsonl), solo para parámetros de ejecución:

- PHP **7.4.33**, SAPI `litespeed`.
- INI cargado: **`/opt/alt/php74/etc/php.ini`**.
- INI adicional: `/opt/alt/php74/link/conf/alt_php.ini`.
- Esto aclara la discrepancia: WordPress está utilizando **CloudLinux alt-PHP 7.4**, no el `ea-php81` declarado por MultiPHP.
- `opcache.enable=1`, memoria `128`, strings `8`, `max_accelerated_files=10000`.
- `opcache.validate_timestamps=1`, `revalidate_freq=2`, `file_update_protection=2`.
- `opcache.file_cache` vacío; `file_cache_only=0`: **sin segunda caché de bytecode en disco** que sobreviva al cierre del grupo.
- Ningún restart por memoria llena; al finalizar quedan ~39–41 MiB libres en OPcache. El pool de strings se llena, pero no es motivo demostrado de la nueva instancia.

## Intervención recomendada: servidor, no WordPress

Primero pedir al proveedor confirmar y ajustar la **retención del proceso padre/grupo LSPHP** para esta cuenta. Revisar `lsapi_backend_pgrp_max_idle`, reciclado y reinicios, y el límite de CPU. El timeout está documentado para `httpd.conf`: **no insertar esa directiva a ciegas en `.htaccess`**, ni cambiar el handler PHP de toda la web para intentar corregirlo.

Un valor finito mayor (por ejemplo evaluar 300–600 s con el proveedor) puede evitar el frío tras pausas cortas, pero debe considerar la RAM del servidor y el perfil de tráfico. No recomendar procesos eternos globales ni asegurar que 300 s sea suficiente para toda primera visita. Si el proveedor no permite una retención adecuada, evaluar una caché de bytecode en disco privada/per-user compatible con este alt-PHP, o infraestructura que retenga OPcache. Eso requiere otra intervención y medir su resultado.

**No se hicieron estos ajustes.** Tampoco se añadió un cron de “keep-alive”: enmascararía el comportamiento gastando CPU de forma permanente. No se reactivó WP Super Cache ni LiteSpeed Cache, ni se desactivaron seguridad/backups.

[Borrador para soporte del hosting](hosting-request.md), **no enviado**.

### Fuentes primarias consultadas

[CloudLinux — mod_lsapi, funcionamiento](https://docs.cloudlinux.com/cloudlinuxos/cloudlinux_os_components/#apache-mod-lsapi-pro):

> “If there are no requests for lsapi_backend_pgrp_max_idle seconds, lsphp parent process will be terminated”

[CloudLinux — lsapi_backend_pgrp_max_idle](https://docs.cloudlinux.com/cloudlinuxos/cloudlinux_os_components/#lsapi-backend-pgrp-max-idle):

> “Default : lsapi_backend_pgrp_max_idle 30”
>
> “Context : httpd.conf”
>
> “Controls how long a control process will wait for a new request before it exits.”

Consultadas 2026-09-05. Estas fuentes explican el mecanismo candidato; **no sustituyen los valores efectivos de este hosting**.

## Seguridad, pruebas y rollback

- Dos despliegues temporales y secuenciales, ~4 min 21 s y ~14 s desde verificación hasta solicitud de retirada.
- Código MU con capability aleatoria en cabecera, hash en el código, caducidad máxima 20 min; token solo en memoria del proceso local. Rutas GET permitidas, sin cookies ni query string.
- Cada despliegue tuvo un control **sin capability**, sin generación de registros; después se verificó el número exacto de capturas (7 y 3).
- No se registraron IPs, cookies, cuerpos de formularios, SQL literal, parámetros de URL, credenciales ni datos de clientes. Tiempos y conteos de SQL se serializan; el texto conservado por `SAVEQUERIES` permanece únicamente en memoria durante nuestras peticiones.
- Registro limitado a 256 KiB, fuera del docroot, con `chmod(0600)` en la escritura. Plantilla local conservada para auditoría; no instalada permanentemente.
- Subida primero con sufijo `.stage`, verificación exacta de contenido, cambio de nombre a `.php`. Nunca se sobreescribió código existente.
- Ambos despliegues se retiraron mediante autolimpieza autenticada; cPanel confirmó **archivo MU ausente, registro privado ausente y directorio `mu-plugins` creado para la prueba también ausente**. No fue necesario el fallback a papelera. Eventos `rollback_verified` en los dos archivos de eventos.
- Dos rondas de 8 tests de guardas/privacidad/autolimpieza pasaron en contenedor PHP 8.0 CLI aislado (sin red, raíz read-only, tmpfs). No se inició servidor ni se tocó un dispositivo. La ejecución en producción confirmó compatibilidad con su PHP 7.4; la prueba local no era un emulador de su rendimiento.
- Instrumentación versionada como plantilla: [probe.php.template](probe.php.template), ejecutor [run.py](run.py), tests [test.php](test.php). La primera captura usa la versión sin el bloque adicional `runtime`; la segunda añade solo esa lectura de metadatos. Hash de cada fuente desplegada en `events.jsonl`.

## Comprobación posterior: NO hay reparación aplicada

Después de retirar completamente ambas sondas y de otra pausa:

```sh
python3 docs/reviews/performance-2026-09-05/probe.py /
```

Ejecutado a las 17:56 UTC: **7,990 s → 1,756 s → 1,501 s**, tres HTTP 200, **exit 1 / FAIL** por pico >5 s. Evidencia: [post-removal.jsonl](post-removal.jsonl).

Esto prueba que el síntoma persiste **sin instrumentación**. No presentar la petición caliente de ~1,4 s inmediatamente tras la retirada como una mejora aplicada.

La regresión para el futuro debe incluir **pausa → primera carga → repetición**, comparando instancia OPcache y TTFB, además del repro funcional de identidad de productos si se toca caché HTML. Las pruebas que solo recargan rápidamente ocultan este problema.
