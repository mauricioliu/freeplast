# wp-release endurecido — sin publicación de Freeplast

El responsable pidió **«realiza los fixes necesarios»** después de revisar los
fallos del skill. Esta tarea corrige el driver global; **no autoriza ni ejecuta
un release de staging/producción**. Los defectos descritos en el
[preflight anterior](../2026-09-09-preflight-blocked/README.md) quedan como
historia, no como estado de esta implementación.

## Cambios aplicados

Ubicación: `~/.agents/skills/wp-release/` (fuera del Git de Freeplast).

- Runner común para conservar todos los argumentos de WP-CLI. Recuperación
  con plugins/temas omitidos para poder restaurar un plugin que no carga.
- Rollback code-only: solo desde un release completado del mismo target y con
  los mismos componentes; manifiesto, versiones, estado anterior y fingerprint
  comprobados. Sin reset/import de SQL.
- Rollback paired real: autorización nueva, reconocimiento de pérdida de datos,
  fingerprint recién inspeccionado y confirmación RESTORE; checksum del respaldo,
  restauración completa de archivos, eliminación de archivos posteriores,
  reset/import SQL por stdin y comparación de archivos, registros y LIVE-CHECK.
  No hay placeholder de restauración ni éxito incondicional.
- Comandos WP sin entrada redirigidos desde `/dev/null`; el import usa un runner
  de streaming aparte. El ensayo real descubrió que `docker compose run -T`
  consumía el RESTORE destinado al operador; quedó cubierto por regresión.
- Mantenimiento persistente, propiedad de un release, mediante `.maintenance`
  no caducable y guard MU temporal. Cubre la eliminación del marcador por el
  instalador nativo. Se conserva durante install/verify y fallos; reabrir vuelve
  a verificar hashes, versiones, estado y fingerprint. Smoke fallido lo repone.
- Fingerprint obligatorio, exactamente 64 hex, persistido antes de instalar:
  resume no lo puede sobrescribir. Las citas guardadas no autorizan una nueva
  invocación. Configuración y bundle sellados; estados antiguos se rechazan.
- Payload ejecutable completo hasheado, incluidos hooks, runners, rollback y
  metadata. IDs con sufijo aleatorio; registros apuntan al rollback del host y
  declaran correctamente cuando no hay versión previa compatible.
- Rehearsal: checksums de archivos restaurados; copias locales con DB dirigida
  al servicio desechable antes del bootstrap. Redes internas, cron desactivado
  en la copia y rechazo de recursos Compose compartidos/fijos. Limpieza en fallo;
  nunca se borran proyectos ajenos para preparar el test.
- CLI rechaza flags desconocidos, valores faltantes y fases/IDs inválidos.
  Dry-run sigue limitado a gate/package, sin contacto con el target.
- Bloqueo automático antes de contactar el target: recibo de self-test completo
  para el hash exacto de scripts/assets/fixtures, más ejecución de regresiones
  rápidas. Sin flag de omisión. Un self-test nuevo invalida el recibo anterior
  hasta terminar con éxito.

El contrato sigue exigiendo gate, backup paired y rehearsal para todos los tiers;
logic/data además pagan trial. No se sustituyó la ceremonia por scripts ad hoc.

## Evidencia obtenida

Comando desde este equipo (PHP CLI local ya existente, sin instalar paquetes):

```bash
WP_RELEASE_PHP="$PWD/wordpress/.tools/php/php" \
  node ~/.agents/skills/wp-release/scripts/self-test.mjs
```

Resultado final guardado en `self-test.txt`:

- **7** regresiones de clasificación/rutas/globs.
- **11** casos de CLI, dry-run, autorización, recibo y configuración sellada.
- **20** regresiones ejecutables de recuperación/mantenimiento con transporte
  WP falso, PHP/archivos/tar reales, sin red ni Docker.
- **3** releases reales sobre fixture WordPress/MariaDB Docker: surface/local,
  logic/local y logic/on-host.
- HTTP **503** después de install y de una verificación fallida por corrupción;
  resume sin autorización rechazado; reparación + resume autorizado vuelven a 200.
- Code-only real funciona incluso con el plugin activo corrupto.
- Paired real elimina un post, una tabla SQL y un archivo creados después del
  backup, compara el estado restaurado y reabre solo al pasar.
- Tres registros generados; target y rehearsals propios desmontados, sin
  proyectos de rehearsal sobrantes.

También pasó el preflight automático del skill usando el config Freeplast,
**sin ejecutar ninguna fase ni contactar su target**.

Hash de fuentes cubierto por el recibo final:

`aa4388f53b296ddc971ac09d9d30327ce71ee5d192142791422672bba98e4ff6`

## Límites y continuación

- Sin SSH, deploy, formularios, correos ni smoke de `freeplast.mliu.site` o
  `freeplast.cl`. `status` local del driver sigue diciendo `no releases yet`.
- Ninguna aceptación visual/hardware ni cierre de #48. El tema Freeplast 1.0.16
  no se modificó ni se instaló por esta tarea.
- La integración real fue Docker, no un hosting compartido. El runner bare
  está cubierto por el fixture de transporte, no por una cuenta Hostinger real.
- El guard PHP no controla CDN/caché/estáticos ni cron externo. Smoke HTTP
  necesita una ventana abierta breve; si falla se vuelve a cerrar.
- El recibo certifica estas pruebas para estas fuentes, no la compatibilidad de
  cada target. El siguiente release real requiere autorización expresa y toda
  su propia ceremonia; configuraciones inseguras pueden ser rechazadas.
- Un rollback standalone no reescribe el registry local: el operador registra
  la recuperación antes de planificar otro release.

`skill.patch` conserva los cambios globales respecto del estado anterior a esta
tarea. No incluye el recibo local ni backups/datos de sitios. El skill actualizado
incluye `RECOVERY.md` con los comandos y límites operativos. Los registros del
preflight fallido se mantienen sin reescribir su resultado histórico.
