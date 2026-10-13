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
  integraciones externas no funcionan deliberadamente; probar solicitudes **sin despacho**.
  La solicitud sintética con despacho permite revisar su borrador sin consultar Maps.
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
