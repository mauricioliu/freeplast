# Borrador para soporte — NO ENVIADO

> Actualizado tras la recuperación de WPSC, 2026-09-05 19:26 UTC. [Mitigación y estado actual](../../wpsc-recovery-2026-09-05/README.md). La causa de pérdida de OPcache sigue pendiente; [otro borrador](../../wpsc-recovery-2026-09-05/hosting-cache-key-request.md) trata el cruce de respuestas aislado en el hosting.

**Asunto:** freeplast.cl: LSPHP pierde OPcache tras inactividad; primera respuesta 7–9 s

Hola:

En la cuenta `freeplast`, dominio `freeplast.cl`, reprodujimos una pérdida de la instancia caliente de OPcache después de pausas cortas. Solicitamos revisar la configuración efectiva y proponer un ajuste acotado a esta cuenta, antes de aplicarlo.

Runtime observado desde una solicitud pública: Apache, SAPI `litespeed`, PHP 7.4.33; INI `/opt/alt/php74/etc/php.ini` y `/opt/alt/php74/link/conf/alt_php.ini`. MultiPHP muestra `ea-php81`, pero no es el runtime efectivo de WordPress.

Mediciones 5 de septiembre de 2026, UTC:

- 17:48: solicitudes calientes de portada/Tote, TTFB 1,40–1,48 s; OPcache `start_time=1788630502`, 2.561 scripts almacenados.
- Tras 75 s sin nuestras solicitudes, inicio a las 17:49:57: Tote TTFB **9,508 s**; nueva instancia `start_time=1788630597`, 0 hits y 444 misses ya al entrar en el primer MU plugin; 2.594 misses al terminar. Repetición inmediata: **1,691 s**, misma instancia, sin nuevos misses.
- Tras otra pausa de 150 s, inicio a las 17:52:40: portada TTFB **7,510 s**; nueva instancia `start_time=1788630760`, 0 hits, 444 misses al inicio, 2.534 al final. Repetición: **1,422 s**, misma instancia.
- `last_restart_time=0`, `oom_restarts=0`, `hash_restarts=0`, `manual_restarts=0`. Caché no llena (~40 MiB libres al final). `opcache.file_cache` vacío.
- Consultas `$wpdb` capturadas durante esas solicitudes: 92 ms y 68 ms totales, no explican la demora. CPU del proceso hasta captura: ~4,02 s y ~3,57 s, respectivamente.
- El histórico CloudLinux publica límite LVE CPU de 50 %, mientras el widget `ResourceUsage/get_usages` muestra máximo 100; agradeceríamos aclarar el límite real de la cuenta.

La instrumentación temporal ya fue retirada por completo. Después de retirarla, la portada repitió **7,990 s → 1,756 s → 1,501 s**. No se purgó OPcache ni se provocaron reinicios para obtener estas medidas.

Por favor:

1. Confirmar el valor efectivo de `lsapi_backend_pgrp_max_idle` y el ciclo de vida del proceso padre/grupo LSPHP de esta cuenta. Revisar terminaciones por inactividad, reciclado o reinicios en esas horas.
2. Proponer una retención suficiente del grupo y su memoria compartida OPcache. Evaluar un timeout finito mayor (por ejemplo 300–600 s) considerando RAM y tráfico; evitar cambios globales sin revisar su impacto.
3. Si la política del hosting obliga a reciclar frecuentemente, indicar una alternativa de persistencia de bytecode compatible con este alt-PHP y aislada por usuario.
4. Aclarar el límite CPU efectivo y la discrepancia PHP Selector/MultiPHP.

No queremos actualizar PHP/WooCommerce/Elementor, cambiar el handler ni desactivar seguridad como parte de esta revisión sin una aprobación separada. **WP Super Cache se recuperó posteriormente en modo Simple/PHP con una guardia `private, no-store` contra el cruce de respuestas del hosting. No retirar esa guardia, cambiar a Expert ni alterar sus exclusiones.** Esa mitigación acelera hits de HTML, pero no corrige el arranque frío de las solicitudes que necesitan ejecutar WordPress.

Referencia oficial de CloudLinux: https://docs.cloudlinux.com/cloudlinuxos/cloudlinux_os_components/#lsapi-backend-pgrp-max-idle

Gracias.
