# WordPress local persistente

**Estado:** implementación y pruebas offline. Arranque real, recorrido HTTP, reinicio,
acceso PC/teléfono y revisión visual requieren los gates siguientes; no están certificados
por las pruebas offline. No usar datos reales.

## Alcance y aislamiento

- Proyecto `freeplast-local`; volúmenes propios `db_data`, `wp_data`, `capture`.
  No comparte staging (`freeplast-wordpress`) ni `wordpress/.build`.
- WordPress `7.1-php8.3-apache`, MariaDB `11.4`, CLI `cli-php8.3`;
  plugins exactos y SHA-256 desde `wordpress/woo-dependencies.json`.
- Tema y adaptador se montan **solo lectura desde este checkout**, no desde una ruta
  personal fija. Editar PHP/CSS/JS en el repositorio y recargar; sin despliegue.
  PHP OPcache desactivado, respuestas y estáticos `Cache-Control: no-store`.
- Solo `127.0.0.1:8131`; BD/CLI permanecen en una red Docker interna. WordPress añade
  un bridge de preview propio, no interno, con IP masquerading e IPv6 deshabilitados:
  Docker necesita esa red para materializar el puerto publicado. No cambia firewall
  global, Tailscale ni publica Internet. Conserva los proxies existentes 8091/8121/443.
  **Límite:** sin masquerade se evita el NAT saliente normal, no se obtiene un sandbox
  de red absoluto. Rutas/NAT preexistentes del host, servicios en el gateway y resolución
  DNS de Docker pueden seguir siendo accesibles. La contención efectiva de integraciones
  combina los bloqueos PHP/WordPress/CSP de abajo; no instalar código no confiable que
  use sockets/cURL directos para saltárselos.
- La configuración y el MU local deben existir antes de arrancar Apache. Host desconocido
  aborta WordPress. HTTP WordPress bloqueado; PHP `mail()` deshabilitado y PHPMailer
  rechaza cualquier intento de transporte real. Sin claves Google. CSP impide conexiones
  automáticas del navegador a terceros. Maps/Routes, actualizaciones, cron y otras
  integraciones externas no funcionan deliberadamente. Solo este runtime local custodiado
  permite dirección manual con despacho: texto no vacío y seguro, máximo 800 bytes, sin
  Place ID ni procedencia Google. Sin despacho descarta el destino como antes.
  La solicitud sintética con despacho permite revisar su borrador sin consultar Maps.
- La excepción requiere `FPW_LOCAL_MANUAL_ADDRESS === true`, emitido por `safety.php`
  tras sus guardias, entorno `local`, URL canónica local coincidente y captura privada
  operativa. No se activa por POST, cookie, query, Host ni una clave Google ausente.
  Fuera de esas condiciones, staging/producción incluidos, Google sigue obligatorio.
  El textarea y su ayuda reflejan esa misma política; no se carga Places en modo manual.
  Si falla la contención, falla cerrada la excepción, no se habilita un fallback global.
- **Activación tras revisión del lead:** PHP del plugin/tema, `config.php` y `safety.php`
  se leen desde binds en cada petición, con OPcache desactivado. Los binds de directorio
  ven el código integrado en la siguiente petición; el bind individual de `safety.php`
  puede conservar el inode anterior si Git reemplaza el archivo. Verificar sus hashes
  host/contenedor; si difieren, recrear solo WordPress con autorización separada para
  renovar el bind (un restart no garantiza renovarlo). Por eso se exige respaldo
  privado fresco/verificado de la BD DEV **antes del merge**, además de preservar la versión
  anterior del código. No ejecutar seed/reset. `apache.conf` sí se copia al arrancar, pero
  esta excepción no lo modifica. No recargar ni alterar el formulario abierto del usuario;
  verificación posterior solo con sesión/carrito sintéticos dedicados, sin envío.
- Reutiliza `scripts/local-review-mail-capture.php`: correo simulado solo si quedan JSON,
  índice y adjuntos completos; falla sin entregar si la captura no puede escribirse.
  Capturas privadas en `/var/freeplast/mail`, fuera de `/var/www/html`.
- Datos: catálogo/fotos del repositorio con checksums, páginas locales y dos solicitudes
  ficticias con borrador. No copia BD/usuarios/clientes de staging. Productos y páginas
  terminados no se sobreescriben al repetir seed; tampoco vuelve a fijar opciones editables.

## Uso diario

Requisitos: Docker local + Compose, Node 20+, archivos ZIP ya disponibles y verificados
(normalmente `wordpress/.tools/cache`). No descarga plugins ni instala dependencias.
Docker puede descargar sus imágenes al ejecutar `up` explícitamente.

```bash
npm run local                       # estado, no inicia nada
npm run local -- plan                # valida Compose sin crear recursos
npm run local -- init                # plan; verifica ZIP locales
npm run local -- init --execute      # crea credenciales privadas y copias verificadas
# Si el cache está en otro checkout: init --cache /ruta/wordpress/.tools/cache --execute
npm run local -- doctor              # modos/propiedad, hashes, endpoint local, Compose
npm run local -- up --execute
npm run local -- seed --execute      # primera vez o reintento de seed incompleto
npm run local -- status
npm run local -- logs
npm run local -- capture --execute   # exporta JSON/PDF a una carpeta privada del host
npm run local -- stop --execute      # conserva BD, archivos, capturas
npm run local -- up --execute        # no vuelve a sembrar ni modifica datos
```

### Recuperación automática y parada manual

WordPress y MariaDB usan `restart: unless-stopped`: si estaban activos, Docker los recupera
tras una caída del proceso o un reinicio del equipo/daemon. Requiere que Docker arranque
con el equipo; esta configuración no modifica servicios del sistema. La CLI auxiliar no
se reinicia automáticamente.

`npm run local -- stop --execute` conserva datos y deja ambos servicios detenidos, incluso
tras reiniciar el equipo. Para reanudarlos explícitamente: `npm run local -- up --execute`.
No hace falta repetir `seed`, `init` ni `reset`. El proxy Tailscale queda configurado y
puede responder 502 mientras el sitio esté detenido o recuperándose.

Para aplicar esta política a contenedores existentes, el operador ejecuta `up --execute`
desde el checkout/estado propietario después de integrar el cambio; editar Compose por sí
solo no actualiza los contenedores. Puede recrearlos brevemente, conservando los volúmenes.
Tras un reboot, esperar a que ambos estén saludables: Docker no reaplica la espera
`depends_on` de Compose al reiniciar contenedores. La política reacciona a salidas del
proceso, no a un contenedor que permanezca vivo pero `unhealthy`.

Admin: `local-admin`, contraseña en la ruta `password_file` mostrada por `init`/`seed`.
Acceso: `http://mliu:8131/wp-admin/` tras habilitar el endpoint privado descrito abajo.
La CLI nunca imprime la contraseña. Estado por defecto:
`~/.local/state/freeplast-local/` (0700, propietario actual). Los archivos de secreto
son **0444 dentro de ese directorio privado**, para que los bind mounts de Compose sean
legibles por uid33/MariaDB: Compose no garantiza remapear uid/gid/mode de secretos-file.
No colocar ese directorio en un servidor estático, backup público o repositorio.

`capture` exporta instantáneas nuevas, no borra capturas anteriores. Los PDFs se inspeccionan
como archivos privados, nunca mediante una URL pública. Logs y errores redactan los tres
secretos conocidos y campos de credenciales; errores incluyen hasta 2400 caracteres de
evidencia stdout/stderr saneada. No introducir datos personales reales.

Reinicio destructivo, solo cuando se desea volver a cero:

```bash
npm run local -- reset --confirm freeplast-local             # plan
npm run local -- reset --confirm freeplast-local --execute   # borra 3 volúmenes locales
npm run local -- up --execute
npm run local -- seed --execute
```

Conserva credenciales/ZIP y exportaciones privadas del host. `stop` es la opción habitual.
La CLI rechaza volúmenes con otra propiedad y contexto Docker remoto. No usar `docker prune`.
No cambiar checkout, URL, puerto o proyecto sobre un estado ya inicializado. Para otro
checkout, crear identidad/estado distintos; no reutilizar ni borrar recursos ajenos.

Variables opcionales, también necesarias para gates temporales:
`FREEPLAST_LOCAL_PROJECT=freeplast-local-<sufijo>`, `FREEPLAST_LOCAL_STATE=/ruta/privada`,
`FREEPLAST_LOCAL_PORT=18131`, `FREEPLAST_LOCAL_URL=http://localhost:18131`.
Solo hosts `mliu`, `localhost` y `127.0.0.1`; mismo puerto en URL y publicación. El estado
no puede estar dentro del checkout ni `/var/www`, ni atravesar symlinks.

## PC y teléfono: autorización separada

```bash
npm run local -- access
# Primero inspeccionar; no sobrescribir endpoints existentes:
tailscale serve status
# Solo tras autorización del operador, si :8131 sigue libre en Serve:
tailscale serve --bg --http=8131 http://127.0.0.1:8131
```

Es una propuesta, **la CLI no ejecuta Serve**. No usar Funnel, `serve reset`, 0.0.0.0 ni
configuración global nueva. Ambos dispositivos deben pertenecer al tailnet; usar la misma
URL canónica `http://mliu:8131`. El transporte entre dispositivos queda protegido por
Tailscale, aunque la aplicación usa HTTP en este entorno exclusivamente local.

Gate humano pendiente: inicio/admin en PC y teléfono, sesión, navegación sin saltos a
staging, selección de color/cantidades, solicitud sin despacho, panel privado, edición del
borrador, PDF y correo simulado. La CSP o el proxy podrían requerir ajustes después de
esas comprobaciones; no marcar experiencia visual validada con pruebas de código.

## Actualización local custodiada (mínimos + /contacto/) — PREPARADA, no ejecutada

`local/upgrade.php` (montado solo lectura en `/opt/freeplast/upgrade.php`) aplica en el entorno LOCAL, con plan/apply/rollback custodiados, los mínimos por solicitud (`_fp_quote_min_qty`/`_fp_quote_step` desde el catálogo fijado del repositorio) y el reemplazo exacto del placeholder local de `/contacto/` por el bloque aprobado con la dirección Picarquín y la nota LOCAL. Contención exigida en cada modo: WP-CLI, entorno `local`, captura de correo activa (`fpw_local_capture_active`), URL canónica local coincidente con `FREEPLAST_LOCAL_URL` (mliu/localhost/127.0.0.1:puerto), y procedencia bajo el bind `/opt/freeplast`. Los guardias de staging de las migraciones del repositorio NO se relajan ni se reusan: este archivo solo corre bajo la contención local. Reglas duras: receipt privado 0600 en `/var/freeplast/receipts` (volumen de captura, fuera del webroot) con token ligado a modo+URL+hash fuente; hash del catálogo fijado (`1b3ef16a…`); metadatos de cantidad editados por el comercio NUNCA se sobreescriben (drift → replanificar); apply/rollback por compare-and-swap por fila dentro de una transacción verificada (FOR UPDATE sobre las filas planificadas), COMMIT incierto reportado y jamás reclamado ni reintentado a ciegas; la página se escribe con UN solo UPDATE byte-exacto (`CAST(? AS BINARY)`); el seed no se re-corre y los marcadores `_fp_local_seed_complete`, variantes, solicitudes sintéticas y números quedan intactos. Los desconocidos (pediluvios, ladrillo) quedan como cadena vacía explícita, igual que la migración de staging.

```bash
# 0) estado (lectura) y respaldo privado ANTES de cualquier apply:
npm run local -- status
docker compose --env-file /dev/null -p freeplast-local -f wordpress/local/compose.yaml \
  exec -T db sh -c 'mariadb-dump -u root -p"$(cat /run/secrets/db_root_password)" freeplast_local' \
  > ~/freeplast-local-$(date +%F-%s).sql && chmod 600 ~/freeplast-local-*.sql
cli() { docker compose --env-file /dev/null -p freeplast-local -f wordpress/local/compose.yaml run --rm -T --no-deps cli wp "$@"; }
# 1) revisar el delta (solo escribe el receipt privado):
cli wp eval-file /opt/freeplast/upgrade.php status
cli wp eval-file /opt/freeplast/upgrade.php minima plan minima.json
cli wp eval-file /opt/freeplast/upgrade.php contacto plan contacto.json
# 2) aplicar (requiere respaldo verificado) y verificar por HTTP:
FREEPLAST_LOCAL_BACKUP_VERIFIED=1 # exportar en el entorno que llama a cli(), o -e ante compose run
cli wp eval-file /opt/freeplast/upgrade.php minima apply minima.json <token>
cli wp eval-file /opt/freeplast/upgrade.php contacto apply contacto.json <token>
curl --noproxy '*' -s http://mliu:8131/producto/caja-cosechera-3-4/ | grep -F 'Cantidad mínima: 70 unidades'
curl --noproxy '*' -s http://mliu:8131/contacto/ | grep -F 'P Picarquín sitio 52'
# 3) rollback puntual (nunca reset amplio):
cli wp eval-file /opt/freeplast/upgrade.php contacto rollback contacto.json <token>
cli wp eval-file /opt/freeplast/upgrade.php minima rollback minima.json <token>
```

Regresiones offline: `wordpress/scripts/local-upgrade-test.php` (36 checks: guardias de contención, hash fijado, plan/apply/rollback con CAS, drift de comercio rechazado, COMMIT incierto sin rollback reclamado, placeholder/contacto byte-exacto, edición concurrente sobrevive, seed intacto; fuente alterada rechazada en proceso aislado). **No ejecutado contra el stack local**: la aplicación real espera la integración del helper por el lead y la orden `dev-local-despliegue`.

## Gates offline

```bash
FREEPLAST_SKIP_STACK=1 PHP_BINARY=/ruta/php npm test
PHP_BINARY=/ruta/php FREEPLAST_LOCAL_TEST_CACHE=/ruta/cache npm run test:local
npm run local -- plan
```

`test:local` usa un Docker simulado, nunca inicia contenedores. Con `FREEPLAST_LOCAL_TEST_CACHE`
prueba además init real en directorio temporal, hashes y permisos de secretos. Sin esa
variable declara explícitamente omitidas esas pruebas. El gate general existente requiere
fixtures locales de WordPress/Woo bajo `.build` aunque omita el servidor; no ejecutar
bootstrap ni el test completo contra 8091 ocupado para resolver esa falta.

## Gate runtime temporal (operador, NO ejecutado por trabajador)

Ejecutar desde un checkout de prueba dedicado con dependencias disponibles, no staging.
No habilita Serve, no abre teléfono ni navegador. Requiere autorización para contenedores
temporales; verificar primero puerto libre y ausencia del proyecto generado.

```bash
set -eu
export FREEPLAST_LOCAL_PROJECT="freeplast-local-gate-$(date +%s)"
parent=$(mktemp -d)
export FREEPLAST_LOCAL_STATE="$parent/state"
export FREEPLAST_LOCAL_PORT=18131
export FREEPLAST_LOCAL_URL=http://localhost:18131
export FREEPLAST_LOCAL_CHECKOUT="$PWD"
test -z "$(ss -ltnH '( sport = :18131 )')" # aborta si aparece algún listener
node wordpress/local/local.mjs init --cache "$PWD/wordpress/.tools/cache" --execute
# Solo elimina recursos del proyecto temporal; conserva evidencia del host para revisar.
cleanup() { node wordpress/local/local.mjs reset --confirm "$FREEPLAST_LOCAL_PROJECT" --execute; }
trap cleanup EXIT
node wordpress/local/local.mjs plan
node wordpress/local/local.mjs doctor
node wordpress/local/local.mjs up --execute
node wordpress/local/local.mjs seed --execute
compose() { docker compose --env-file /dev/null -p "$FREEPLAST_LOCAL_PROJECT" -f wordpress/local/compose.yaml "$@"; }
compose run --rm -T --no-deps cli wp eval-file /opt/freeplast/runtime-check.php > "$parent/before.json"
# Repetir seed no duplica registros ni borra borradores.
node wordpress/local/local.mjs seed --execute
node wordpress/local/local.mjs stop --execute
node wordpress/local/local.mjs up --execute
compose run --rm -T --no-deps cli wp eval-file /opt/freeplast/runtime-check.php > "$parent/after.json"
cmp "$parent/before.json" "$parent/after.json"
curl --noproxy '*' --fail --silent --show-error "$FREEPLAST_LOCAL_URL/tienda/" > "$parent/catalog.html"
grep -q 'LOCAL' "$parent/catalog.html"
curl --noproxy '*' --fail --silent --show-error -I "$FREEPLAST_LOCAL_URL/wp-content/themes/freeplast/style.css" | grep -i 'cache-control:.*no-store'
node wordpress/local/local.mjs capture --execute
# Rutas privadas del host quedan en $parent para revisión; no publicarlas.
```

`runtime-check.php`: permisos efectivos uid33, catálogo/fotos y variaciones, dos solicitudes
con borradores, HTTP bloqueado, renderer PDF real y captura de bytes idénticos sin entrega.
No verifica el checkout HTTP completo ni aprobación comercial/end-to-end. Para demostrar
bind/reload en el checkout temporal: añadir un comentario único a `style.css`, obtenerlo
por curl sin reiniciar y quitar **solo ese comentario**; comprobar que los IDs anteriores
siguen iguales. Revisar también `Mounts` y `NetworkSettings` de los contenedores: binds
read-only del checkout correcto, secretos fuera de webroot, publicación efectiva
`NetworkSettings.Ports["80/tcp"]` en loopback (no basta `HostConfig.PortBindings`),
BD/CLI solo en red interna y WordPress en preview sin masquerading.

Persistencia y flujo HTTP completo son gates separados. Ejecutar la suite desechable
existente solo con su puerto libre configurado mediante `FREEPLAST_TEST_URL`, nunca con
8091 (Metabase). Requiere aprobación propia: `npm test` sin `FREEPLAST_SKIP_STACK=1`
arranca otro servicio desechable y no certifica automáticamente este stack MariaDB.
