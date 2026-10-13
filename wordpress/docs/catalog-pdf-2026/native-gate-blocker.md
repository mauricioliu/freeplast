# Gate nativo rojo: diagnóstico acotado, sin corrección de seguridad

## Resultado

No se encontró regresión causal atribuible al diff PDF. **Esto no demuestra que baseline pase o falle en ejecución nativa:** no se ejecutó una reproducción runtime del commit base. Gate completo continúa rojo; pasos 6–8 siguen parciales. Guardas, política de roles y verificadores intactos.

Para integrar: investigación de permisos/roles autorizada por separado, o aceptación explícita del gate reducido offline. No declarar gate completo verde ni ignorar las pruebas fallidas.

## Evidencia del lead

Comando: `FREEPLAST_TEST_URL=http://127.0.0.1:18091 npm test`.
Log: `/home/mauricio-liu/.herd/plan-mode/plan-mv6vrtm2-rsaa.worktree-full-gate.log`.

El proceso llegó a la matriz ventas guarded en `woo-stack-harness.mjs:1926` y falló:

- `editor_save_denied`
- `record_unchanged_editor_save`
- `quick_status_denied`
- `record_unchanged_quick_status`
- `bulk_status_denied`
- `record_unchanged_bulk_status`
- `record_unchanged_bulk_trash`
- `record_unchanged_trash_route`
- `note_delete_denied`
- `record_unchanged_note_delete`

Los checks de denegación `bulk_trash_denied` y `trash_route_denied` sí pasaron; sus checks de invariancia no. La comparación de invariancia usa el mismo snapshot anterior a todos los intentos (`woo-ventas-guard.py:283–288`): los fallos posteriores no prueban por sí solos una mutación independiente por cada ruta. El log oculta detalles de respuesta, por lo que no permite inferir códigos HTTP/cuerpo exactos ni el campo que cambió.

## Equivalencia fuente, no reproducción runtime

Baseline local: `f54ebec2d77e63fd9c9d211baa7206eff2c945bb`.
Comparación ejecutada: `git show HEAD:<path>` contra bytes actuales, con assert de igualdad y SHA256. Resultados:

| Archivo | SHA256 idéntico baseline/worktree |
|---|---|
| `wordpress/wp-content/plugins/freeplast-woo/freeplast-woo.php` | `3e5c2962147834f24e64ec42a73e9d1de9b5780cbebbccce4491544855b6b5e9` |
| `wordpress/wp-content/plugins/freeplast-woo/quotation-access.php` | `c09e366767d1172bfbda295bd56d718cc54d209d5ab447e56987a9207107c23e` |
| `wordpress/scripts/woo-stack-harness.mjs` | `2ddfa69a9d348967e878a4e19ba6abefc646c091b172fe2a930913f987b9a46d` |
| `wordpress/scripts/woo-ventas-guard.py` | `03e255af97fffa5937cffc7ee224a7cb14de30d0f3ff0e284e564e40b9de7aae` |
| `wordpress/scripts/woo-ventas-state.php` | `4fd7b101430406e1e7aa98b0157eda1d53f5eda8f9658ac4d20bbdd0546eca94` |
| `wordpress/scripts/test-woo-adapter.php` | `3ac6d7ee84c2e042f6419fbe1b6163bca68a1137aea93fc3680a2ac4f918612a` |

Adicionalmente, **239 archivos PHP** del adaptador instalado bajo `.build/wp/wp-content/plugins/freeplast-woo` coinciden byte a byte con baseline y worktree. Esto excluye cambios de fuente del adaptador en este patch; no certifica hooks efectivos, identidad/capacidades de sesión ni estado del runtime durante el fallo.

`bootstrap.mjs` solo cambia dos zonas: mapa de atributos técnicos del fixture y omisión del Color técnico para variables. No cambia creación de usuarios, capacidades, configuración de roles ni la matriz ventas. El resto del diff PDF añade referencia/plantilla/pruebas y herramienta manual no registrada ni ejecutada en WordPress. No se encontró conexión causal con los checks fallidos.

Lectura offline de SQLite existente mediante URI `mode=ro` (sin cargar WP) mostró `ventas_freeplast` con las cuatro capacidades de baseline: `read`, `manage_freeplast_quotes`, `edit_shop_orders`, `edit_others_shop_orders`. No prueba las capacidades efectivas del usuario durante el fallo. **Deriva de política de roles no confirmada.** No se consultaron datos personales ni se imprimieron credenciales.

## Checks offline ejecutados en este diagnóstico

- `wordpress/.tools/php/php wordpress/scripts/test-woo-adapter.php`: **516 assertions verdes** (incluye guardas con capacidades simuladas).
- `python3 wordpress/scripts/woo-ventas-guard-selftest.py`: **3 tests verdes** (fronteras simuladas).
- `wordpress/.tools/php/php wordpress/scripts/staging-role-migration-test.php`: **53 checks verdes**.
- Assert de equivalencia de las seis fuentes anteriores y los 239 PHP instalados: **verde**.

No servidor, HTTP, remoto, cambio de DB/receipt, prueba nativa nueva, debilitamiento de guardas o modificación de tests. No se reprodujo baseline nativamente ni se resolvió la causa de seguridad. Se preservó también `.build-offline-preserved-7c92a1/`: contiene el build anterior, es artefacto local no versionable; excluirlo expresamente de cualquier commit amplio.

## Anotación 2026-10-13 (relevo): instrumentación HTTP acotada lista para el lead

El bloqueo queda anotado, no resuelto: el sondeo CLI directo del lead es verde
(actor restringido, guardas 403, registro inmutable) y los escaneos de fuente
no discriminan las hipótesis vivas en runtime HTTP (predicado/caps falsos solo
en web, ganchos ausentes solo en web, o divergencia de transporte/status).
Nueva sonda lista, separada de la política de seguridad, sin corrección
adivinada y sin cambios de guardas/tests:

- `wordpress/scripts/ventas-http-diag.py` (lead-run): arranca SU servidor
  desechable en 127.0.0.1:18091 sobre el `.build` existente (router.php,
  workers=4); crea actor/registro sintéticos propiedad de la corrida (email
  `ventas-<run>@example.invalid`, email/first_name fijados con setters
  explícitos para pasar la verificación de identidad de
  `woo-ventas-state.php`); autentica por HTTP real como ese actor y reproduce
  las 4 rutas guardadas fallidas de la matriz (editor save, quick status,
  bulk status, note delete) con nonces válidos acuñados; reporta status HTTP
  real y delta del digest por ruta. Limpieza solo de lo propio (orden,
  usuario, opción, mu-plugin EXCLUSIVO O_EXCL, grupo del servidor con
  verificación de puerto liberado); toda falla de limpieza sale como exit 4
  explícito, nunca tragada. Nunca trunca el JSONL: agrega solo entradas
  etiquetadas con su run.
- `wordpress/scripts/ventas-http-diag-fixture.php`: shims independientes
  `pre_wp_mail`/`pre_http_request` antes de crear nada; path del fixture
  corregido a `dirname(__DIR__) . '/.build/wp'`.
- Mu-plugin desechable `fpw-ventas-http-diag.php`: registra SOLO booleanos de
  caps (raw/efectivos), `limited`, prioridades de los 4 ganchos del adaptador,
  claves de transporte de acción y eventos `wp_die` (tag + código). Los
  handlers wp_die registran y llaman al handler propio del core. Sin PII,
  contraseñas, cookies o nonces en evidencia.
- Autochequeo offline verde: `python3 wordpress/scripts/ventas-http-diag-selftest.py`.

Comandos exactos del lead (desde la raíz del worktree):

    python3 wordpress/scripts/ventas-http-diag-selftest.py
    python3 wordpress/scripts/ventas-http-diag.py

Requisitos: `.build` existente y aprovisionado para `http://127.0.0.1:18091`
(si no, el runner falla con pista: re-aprovisionar con `cd wordpress &&
FREEPLAST_TEST_URL=http://127.0.0.1:18091 node scripts/bootstrap.mjs`), puerto
18091 libre (rehúsa si algo responde; nunca 8091).

Salida: resumen JSON en stdout (`probes[].status` + `digest_unchanged`,
`http_diag_state.actor/hooks` = actor y ganchos EN HTTP, `cli_report` = lo
mismo vía WP-CLI), entradas propias del run en stderr, evidencia completa en
`.build/ventas-http-diag.jsonl` (se conserva). Cruces decisivos:

- `limited=false` solo en HTTP (vs `cli_report.limited=true`) → divergencia de
  predicado/caps en runtime web.
- `hooks.*=false` solo en HTTP → guardas no registradas en runtime web.
- `wp_die tag=sales-read-only code=403` con `probes[].status != 403` →
  divergencia de transporte/status, no de guardas.
- `digest_unchanged=false` en `editor_save` → mutación real confirmada.

Exit codes: 0 diagnóstico+limpieza OK; 2 falla de setup/infra; 4 falla de
limpieza (reporte `cleanup:` en stdout).

## Anotación 2026-10-13 (2): sonda aislada VERDE; verificador guardado y observación del gate completo listos

Sonda aislada del lead: las 4 rutas guardadas respondieron 403 con
`digest_unchanged=true`; actor restringido y ganchos idénticos CLI/HTTP; limpieza 5/5. Log:
`~/.herd/plan-mode/plan-mv6vrtm2-rsaa.ventas-http-probe.log`. Las guardas y su
predicado funcionan en un contexto HTTP limpio. La divergencia vive en lo que el
harness completo añade. Análisis de las diferencias:

1. `auto_prepend_file=.build/fpw-trace-prepend.php` (ausente en la sonda): solo
   registra un shutdown-logger de fatales; sin interacción con hooks/caps. No
   causal por sí solo, pero el verificador guardado ahora lo incluye byte a
   byte, con `max_execution_time=10` y `PHP_CLI_SERVER_WORKERS=8` exactos del
   harness.
2. Restos del falso guard-off: el harness escribe
   `mu-plugins/fpw-stack-guard-off.php` (REMOVE de las 4 guardas) para sus
   controles positivos y lo borra en `finally`. Un harness muerto a mitad de
   matriz dejaría las guardas REMOVIDAS para toda petición: exactamente el
   fallo observado (mutación sin 403, denegación core de trash intacta). El
   verificador ABORTA y lo reporta como hallazgo si lo encuentra.
3. Estado secuencial previo (suites owner/race/quotes dejan filas, opciones y
   5 mu-plugins propios): nada toca caps; bootstrap re-sincroniza solo
   theme+adapter (mu-plugins sobreviven). Hipótesis restante: flip transitorio
   del predicado bajo carga/SQLite-lock con 8 workers. La observación del gate
   completo la confirma o descarta con evidencia por petición.

Comandos del lead (raíz del worktree), en orden:

    # 1. repro acotado del contexto harness con el verificador REAL:
    python3 wordpress/scripts/ventas-http-diag.py --mode guarded
    #    (env opcional FREEPLAST_VENTAS_USER/PASS para reutilizar actor; nunca se registran)

    # 2. si (1) verde: observar el gate nativo completo con la misma sonda:
    TOKEN=$(python3 wordpress/scripts/ventas-http-diag.py --install-instrumentation | python3 -c 'import json,sys; print(json.load(sys.stdin)["run"])')
    FREEPLAST_TEST_URL=http://127.0.0.1:18091 npm test
    python3 wordpress/scripts/ventas-http-diag.py --read-instrumentation --run "$TOKEN"

Lectura: `tally.limited_false > 0` o `tally.hooks_missing > 0` en (2) → flip de
predicado/ganchos en runtime cargado (causa raíz concreta, corregir ahí);
`(1)` rojo → la diferencia está en el prep del harness (guard-off remante
reportado, mu-plugins u opciones); ambos verdes → la divergencia requiere la
secuencia completa de escenarios (2 la captura). Sin corrección de política de
seguridad hasta causa probada.

## Anotación 2026-10-13 (3): verificador guardado REAL verde; causa plausible SIN confirmar; preflight endurecido

El verificador guardado del lead (`--mode guarded`, flags PHP exactos del
harness + `woo-ventas-guard.py` real) pasó TODOS los checks con `failures=[]`;
limpieza propia completa. Log:
`~/.herd/plan-mode/plan-mv6vrtm2-rsaa.ventas-guarded-probe.log`.

Causa plausible (NO confirmada, no asertar): la primera corrida completa pudo
morir por timeout con `fpw-stack-guard-off.php` instalado (guardas REMOVIDAS
para toda petición); la corrida siguiente lo habría borrado en su `finally`, y
la ausencia posterior en el listado no excluye su presencia previa. Con las
guardas removidas el fallo observado encaja exactamente (mutación sin 403,
denegación core de trash intacta). Confirmación pendiente de la observación
instrumentada del gate completo en estado limpio actual.

Endurecimiento (sin cambios de guardas ni de política):

- `woo-stack-harness.mjs` preflight -1: si existe
  `wp-content/mu-plugins/fpw-stack-guard-off.php` ANTES de bootstrap/servidor,
  el harness falla explícitamente y ordena inspección/eliminación deliberada;
  nunca lo elimina ni prueba sobre estado debilitado. `node --check` verde.
- `ventas-http-diag.py` `read_instrumentation`: `hooks_missing` corregido —
  `has_action`/`has_filter` devuelven prioridad y 0 ES presencia válida; solo
  null/False cuentan como ausente (extracción a `hooks_missing_entry`).
- Regresión offline nueva en `ventas-http-diag-selftest.py`:
  `priority0_hook_counts_as_present` (+ ausente/clave faltante) verde.

Flujo vigente del lead: gate completo instrumentado sobre el estado limpio
actual (`--install-instrumentation` → `FREEPLAST_TEST_URL=http://127.0.0.1:18091
npm test` → `--read-instrumentation --run <token>`).

## Anotación 2026-10-13 (4): CIERRE — gate nativo completo VERDE

`FREEPLAST_TEST_URL=http://127.0.0.1:18091 npm test` del lead: **2691 checks
pasados, 752 real-stack**, matriz ventas guarded incluida. Log:
`~/.herd/plan-mode/plan-mv6vrtm2-rsaa.worktree-full-gate.log`. Lectura de
instrumentación guardada en `plan-mv6vrtm2-rsaa.instrumentation-result.json`
(lead; mu-plugin y opción limpiados tras la lectura).

La evidencia roja histórica de arriba se conserva íntegra. La causa del fallo
original NO quedó probada concluyentemente: la hipótesis del resto
`fpw-stack-guard-off.php` dejado por una corrida muerta (timeout) — y borrado
después por el `finally` de la corrida siguiente — sigue plausible pero sin
confirmación directa. Mitigación permanente en el harness: preflight que
aborta explícitamente antes de bootstrap/servidor si el resto reaparece,
nunca lo elimina ni prueba en estado debilitado, con regresión offline
`woo-stack-preflight-test.mjs` (4 checks, wired en check-woo.mjs;
`FREEPLAST_SKIP_STACK=1 npm test` ahora 1943). Sin cambios de guardas,
tests de la matriz ni política de seguridad.
