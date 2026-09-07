# WP Super Cache recuperado — producción legacy

**Estado al 5 de septiembre de 2026, 19:26 UTC: WP Super Cache 3.1.3 ACTIVO, caché ON, modo Simple/PHP, con protección contra la caché compartida del hosting.** Sustituye la mitigación anterior de dejarlo inactivo. No se tocó `freeplast.mliu.site` ni el Woo nuevo.

Autorización: el usuario pidió intentar recuperarlo («ok, intenta») después de explicar una prueba aislada y una activación controlada con rollback. No autoriza cambios de PHP, handler, seguridad, plan de hosting ni cutover.

## Hallazgo que permitió avanzar

El cruce **se reprodujo sin WordPress ni WPSC**: un pequeño `index.php` temporal, en un directorio independiente del mismo hosting, respondía dos rutas reescritas (`alpha`, `beta`). Solo devolvía JSON sintético, sin clientes, sesiones, base de datos ni configuración WordPress.

- `Cache-Control: max-age=3, must-revalidate`: pedir `beta` 1,6 s después devolvió el JSON de `alpha`, incluido el mismo instante de generación, con `Age: 1`. **Dos cruces en dos pares.**
- `no-store, private`: 4/4 respuestas correctas, sin `Age`.
- `private, max-age=3, must-revalidate`: 4/4 correctas, sin `Age`.
- El servidor agrega además `Cache-Control: max-age=0, public`, aun fuera de WordPress.

[Registro sintético](synthetic.jsonl). Directorio retirado y ausencia verificada. Esto demuestra una reutilización incorrecta en la capa de hosting para estas rutas reescritas; **no identifica el módulo/configuración exactos**. No atribuirlo a un mutex de WPSC ni asegurar que conocemos la clave interna del servidor.

No hizo falta clonar toda la base legacy: el repro mínimo aisló el mecanismo en el mismo servidor sin copiar datos ni usar el staging nuevo.

## Reparación aplicada

### Una caché de archivos, no dos cachés de respuestas

WPSC guarda y entrega HTML por URL desde su caché en disco. Las respuestas PHP llevan:

```http
Cache-Control: private, no-store, no-cache, max-age=0, must-revalidate
```

La constante nativa `WPSC_CACHE_CONTROL_HEADER` impide que los hits de WPSC vuelvan a emitir su `max-age=3` predeterminado. Esas directivas HTTP **no deshabilitan el almacenamiento interno en disco de WPSC**.

Permanece una segunda cabecera añadida por el hosting (`max-age=0, public`). **No quedó normalizada.** La combinación con `private, no-store` evitó la reutilización incorrecta en los ensayos. El proveedor debe corregir su capa y sus cabeceras; no presentar esta mitigación como reparación de la configuración de Apache.

### Archivos y configuración

- `/home/freeplast/public_html/wp-content/advanced-cache.php`: **drop-in original del plugin**, sin parchear. Activación/desactivación y administración nativas lo gestionan.
- `/home/freeplast/public_html/wp-content/wp-cache-config.php`: ajustes nativos y el bloque exacto de [cache-guard.php](cache-guard.php) **embebido al final**, no instalado como plugin independiente.
- `/home/freeplast/wpsc-cache/`: caché fuera del docroot, directorio raíz creado con permisos 0700. No es una ruta web.
- `wp-config.php`: la activación nativa añadió `WPCACHEHOME` apuntando al plugin. No se cambiaron credenciales, PHP ni `DISABLE_WP_CRON`. `WP_CACHE` ya estaba en true.
- `.htaccess`: sin cambios en la activación final; sin reglas Expert/mod_rewrite de WPSC.
- Configuración anterior conservada **solo en el servidor**, bajo `/home/freeplast/.fp-wpsc-recovery-backup-20260905/wp-cache-config.php`, directorio 0700. No se copió a Git ni se imprimieron sus secretos.

[Configuración de partida, antes de habilitar](wp-cache-config.pre-enable.php). Ese archivo tiene ambos flags en false por diseño: no es un volcado del estado ON ni contiene los identificadores secretos que genera la administración del plugin.

Ajustes finales comprobados por lectura independiente:

- Caché y Super Cache: ON; Simple/PHP; TTL **1800 s**; intervalo GC **600 s**.
- Reconstrucción, precarga, precarga de taxonomías, compresión propia de WPSC, caché dinámica y «hacer anónimos a usuarios conocidos»: OFF.
- Vaciar al editar entradas/productos: ON.
- No cachear consultas GET ni usuarios conocidos; rechazar además cualquier nombre de cookie (`array('.')`).
- Guardia adicional: solo GET, host exacto `freeplast.cl`, sin cabecera Cookie (incluso malformada), sin cookies parseadas, query string ni Authorization.
- Únicas rutas elegibles: `/`, `/producto/<slug>/`, `/categoria-producto/<slug>/`. El plugin conserva sus controles de 404, error, páginas privadas y `DONOTCACHEPAGE`.
- Fuera: carrito/cotización, checkout, formularios enviados, administración, API, búsqueda y demás rutas. El bloque utiliza `WPSC_SERVE_DISABLED` + `DONOTCACHEPAGE` para no leer ni escribir caché en esos casos, **sin apagar los hooks administrativos de invalidación**.

**Precarga residual:** los flags OFF no cancelaban por sí solos eventos antiguos. La pantalla nativa todavía ofrecía «Cancelar la precarga». Se ejecutó ese botón; dejó de aparecer y se verificó `stop_preload.txt` en la caché privada. [Evidencia](final/preload-cancel.json). No se cambió el cron externo del hosting ni se lanzó precarga.

## Pruebas

### Canary sin caché pública

Se utilizó un drop-in temporal selectivo por capability aleatoria en cabecera, hash remoto, TTL 20 min, GET allowlist sin cookies/query. Para el resto del tráfico retornaba sin cargar WPSC. Configuración/caché separadas en un directorio privado; plugin global inactivo durante esta fase. Sin base clonada, MU plugin, envíos comerciales ni modificaciones de productos.

Tras corregir los problemas del instrumental descritos abajo:

- Portada, cuatro categorías y doce productos: primera generación → hit, canonical/título correctos; conteo y nombres de las cards contrastados con Store API.
- UPC↔G1 repetido: correcto, sin `Age`.
- Gzip e identity correctos.
- Cookies y query string: bypass.
- Llamada selectiva al handler nativo `wp_cache_post_edit(165)` contra **la caché privada del canary**, sin editar el producto: portada/categoría/ficha volvieron a MISS y después HIT.
- Una pausa de 150 s produjo hit de Tote de **0,434 s totales**, seguido de G1 de 0,078 s. No se instrumentó OPcache en esta prueba: no afirmar que esa petición necesariamente tuvo OPcache frío.

[Registro completo, incluidos intentos fallidos](canary.jsonl). Cada despliegue terminó con retirada y verificación de ausencia, conservando intactas la configuración activa de WPSC, wp-config y .htaccess.

### Activación final pública

[Registro](public-rollout.jsonl): **54/54 comprobaciones HTTP pasan**, incluidos:

- Dos vueltas a portada + cuatro categorías + doce fichas.
- UPC↔G1 seis veces.
- Bypass con cuatro cabeceras Cookie sintéticas distintas, query string y Store API.
- Gzip e identity.
- Vaciar mediante **el formulario administrativo nativo**: Tote MISS 1,543 s → HIT 0,101 s.
- Tras 150 s sin nuestras peticiones: Tote **0,108 s**, G1 **0,186 s**, hits correctos. El tráfico ajeno no está controlado.

Estos valores son **tiempos totales HTTP de requests**, no LCP ni TTFB puro. Mediana de los 27 hits de ese barrido: **0,079 s**.

Después se ejecutaron las sondas anteriores, independientes del código de despliegue:

```sh
python3 docs/reviews/wpsc-hotfix-2026-09-05/repro.py
# exit 0: UPC y G1 distintos y correctos.

# Copia sin alterar sweep.py en un directorio nuevo para no sobrescribir evidencia anterior.
python3 /tmp/fp-wpsc-recovery-final/sweep.py
# 47 GET, 44/44 comprobaciones funcionales PASS.

python3 docs/reviews/performance-2026-09-05/probe.py \
  / /producto/totem/ /categoria-producto/agricola/
# exit 0 / PASS en las tres rutas.
```

[Resultados independientes](final/): portada TTFB mediano **0,161 s**, máximo 0,231 s; Tote **0,105 s**, máximo 0,144 s; Agrícola **0,109 s**, máximo 0,138 s. Esto corresponde a **hits con HTML ya generado**, no al coste de una futura regeneración en frío.

La URL histórica `/producto/caja-pollera/` continúa en 404. La ficha vigente `/producto/base-para-pediluvio/` pasa; no se cambiaron slugs.

### Guardia local

19/19 casos CLI PHP 8.0: métodos, rutas, cookies parseadas/malformadas, query, Authorization, host, `DONOTCACHEPAGE` y configuración en conflicto.

```sh
docker run --rm --network none --read-only --tmpfs /tmp \
  -v "$PWD/docs/reviews/wpsc-recovery-2026-09-05:/work:ro" \
  php:8.0-cli php /work/test-guard.php
```

No es una emulación del hosting PHP 7.4. La ejecución real en producción comprobó esa compatibilidad.

## Incidentes del instrumental y de despliegue — no ocultarlos

- El config sample no define `wp_cache_slash_check`; en las primeras pruebas solo portada entregaba HIT. Se ajustó a 1, como en la configuración original del sitio. No era mezcla de claves de WPSC.
- Activar debug de WPSC sin precrear el log intentó usar `wp_rand()` demasiado temprano y provocó un fatal en solicitudes **selectivas**. El hosting devolvió entonces cuerpos de otra página con HTTP 200; una variante comprimida dio error de decodificación. No se usaron esos datos como evidencia del arreglo. El diagnóstico selectivo identificó el fatal; se precreó el log privado y no hubo errores en la ronda final. Public controls y repro tras retirar siguieron correctos.
- Una versión de la sonda comprobaba mal las categorías (no exigía canonical); otra sobreescapó el regex de cards. Se corrigieron y se repitió todo. No interpretar esos falsos «passed» tempranos como aprobaciones.
- Dos intentos públicos abortaron porque **guardar Avanzado recrea el drop-in nativo**. Se apagó/desactivó y restauró la configuración anterior automáticamente en ambos casos. El loader personalizado de [discarded/](discarded/) NO está desplegado. La solución final deja el drop-in original y coloca la guardia en la configuración, que sobrevive al guardado nativo; se comprobó después de habilitar y purgar.
- El botón Sencillo ON fuerza `cache_rebuild_files=1`. Por eso se usó **Avanzado**, conservando los demás controles. No reactivar mediante «configuración recomendada» sin revisar el resultado.

## Límites y operación

- **No se reparó la pérdida de OPcache del hosting.** Una URL aún no cacheada, tras caducar 30 min o tras editar/purgar, debe regenerarse; puede volver a pagar el coste PHP frío. Sesiones/cookies y rutas excluidas también lo pagan.
- Toda cookie causa bypass, incluso analítica. Es una decisión conservadora; no retirar esa barrera solo para subir el hit ratio.
- Se probó invalidación con el handler nativo en la caché aislada y con el botón de purga en producción. No se editó un producto real para probar el editor.
- No se enviaron formularios, correos ni solicitudes comerciales. No se certifica el envío end-to-end ni la experiencia visual/hardware. Los formularios que estén embebidos en portada/fichas pueden compartir HTML anónimo por hasta 30 min; revisar su flujo humano antes de ampliar TTL o alcance.
- No se modificó seguridad, backups, PHP, LSAPI, object cache SQLite ni LiteSpeed Cache. Staging nuevo intacto.
- No se instaló keep-alive ni precarga periódica. El intervalo GC es una configuración de WPSC; no se demostró la ejecución real del cron del hosting.
- **Al actualizar WPSC, resetear ajustes o regenerar configuración**, confirmar que el bloque guard permanece, Simple sigue activo y repetir repro/sweep/exclusiones. Nunca cambiar a Expert mientras el hosting siga sin corregir.
- [Solicitud adicional para soporte](hosting-cache-key-request.md), no enviada. La petición sobre OPcache sigue siendo pertinente.

## Reversión

1. WP Super Cache → desactivar almacenamiento; purgar; **desactivar el plugin**. El lifecycle nativo elimina el drop-in.
2. Si el admin no funciona, retirar primero `wp-content/advanced-cache.php` mediante cPanel: corta entrega y generación tempranas. Luego desactivar WPSC normalmente.
3. Verificar UPC↔G1 y el barrido. Una respuesta 200 sola no basta.
4. Si se necesita restaurar ajustes previos, el respaldo privado indicado arriba es el original OFF; no restaurar configuraciones ON antiguas. No sobreescribir wp-config ni .htaccess con backups históricos.
5. Conservar WPSC fuera durante el cutover al Woo nuevo salvo decisión explícita.

Estado final independiente: [final/state.json](final/state.json). No quedan canaries, directorios sintéticos, archivos `.stage` ni MU plugins. Los artefactos temporales propios fueron movidos a la papelera privada de cPanel (fuera del docroot), no quedaron ejecutándose. El directorio `wpsc-cache` y el respaldo OFF son deliberadamente permanentes.

`tools/` conserva los ejecutores utilizados para auditoría, **no un comando de despliegue para repetir automáticamente**: dependen del estado inicial OFF, rutas temporales de fuentes leídas del servidor y acceso administrativo. La revalidación de rutina utiliza las sondas de lectura anteriores, no esos ejecutores.
