# DEV: precios desde staging y aviso de Action Scheduler (2026-10-15)

Solicitado y autorizado por el dueño en esta sesión. No se desplegó código a staging ni se modificó producción.

## Aviso técnico

Reproducción contra WordPress DEV real: `ActionScheduler_AdminView::instance()->maybe_check_pastdue_actions()` imprimía el aviso de tareas vencidas con `page=fpw-price-list` y el administrador local. La causa es el aviso global de Action Scheduler, no un mensaje del mantenedor. DEV desactiva cron deliberadamente por contención local.

`workspace-chrome.php` filtra `action_scheduler_check_pastdue_actions` únicamente en superficies autorizadas del área del dueño. No cambia tareas, ejecutores, permisos, avisos de guardado ni el banner de correo LOCAL. El diagnóstico sigue visible en la administración técnica. No se vació ni se ejecutó la cola para ocultar el mensaje. La corrección está activa en DEV mediante el bind del checkout; pendiente del release habitual para staging.

Pruebas: workspace chrome 79; price list 118; quote draft 358; workspace offer 111; owner workspace JS 142. WordPress real verificó ausencia en precios/cotizaciones, presencia en Action Scheduler y renderizado de los precios importados.

## Importación realizada

- Fuente de solo lectura: `https://freeplast.mliu.site`, SSH `hetzner-vps`, `/var/www/freeplast`, WP-CLI.
- Destino validado: `http://mliu:8131`, entorno `local`, captura privada de correo activa.
- Lista DEV ausente antes del cambio. Importación transaccional, bloqueo de fila y rechazo si aparece una lista durante la operación; lectura posterior exacta.
- 1 precio base y 21 referencias por volumen, incluidas unidades/pallet, tramos, fuente y notas. 27 identidades cotejadas por slug de producto + atributos de variación, sin copiar IDs ciegamente.
- Actor de importación: `staging-import:freeplast.mliu.site:freeplast_admin`.
- Valores copiados fielmente, sin inferencias ni correcciones comerciales. En particular, Caja Merlucera conserva el valor `small=1` que ya tiene staging; no se interpretó como un precio aprobado diferente.
- Staging leído nuevamente después: lista idéntica.

Correspondencias distintas: `p:50→p:48`, `p:38→p:37`, `p:40→p:39`, `p:52→p:50`, `p:42→p:41`, `p:46→p:45`, `p:48→p:47`, `p:44→p:43`. Resto conserva identidad nativa.

## Respaldo y comprobación

Artefactos privados fuera del repositorio/webroot:
`~/.local/state/freeplast-local/price-import-20261015/` (directorio 0700; SQL/JSON 0600).

Incluye `dev-before.sql` (dump completo, 920604 bytes), `dev-after.sql`, `staging.json`, `staging-after.json`, `option-before.json`, script de operación y `SHA256SUMS`. El dump previo contiene DDL/datos y marcador de finalización; no se hizo restauración de ensayo.

Comparación SQL antes/después: productos, metadatos, solicitudes, borradores, documentos emitidos y demás tablas comerciales intactos. La única opción comercial añadida es `fpw_price_list`; también cambian naturalmente sesiones WooCommerce y el lock `action_scheduler_lock_async-request-runner` durante la verificación. No se restauraron sesiones ni locks.

Recibo de la fila anterior (ausente) y mapa también en `/var/freeplast/price-import-20261015-before.json` del contenedor. Para revertir solo esta importación se debe comprobar que la fila sigue siendo la importada y eliminar exclusivamente `fpw_price_list`; si el dueño ya editó precios, detenerse y replanificar. No restaurar toda la BD encima de trabajo nuevo. No reejecutar ciegamente el script: rechaza listas existentes.
