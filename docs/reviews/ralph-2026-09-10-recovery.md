# Ralph — corrección y revisión de recuperación (10 septiembre 2026)

Autorización inicial del dueño: «sí, adelante» después de proponer corregir el falso éxito y recuperar las ramas existentes desde revisión/integración, no reimplementarlas. En esa fase no hubo push. Posteriormente pidió **«commit y push» y ordenar las ramas**; la publicación y limpieza se registran en [el inventario de ramas](ralph-2026-09-10-branches.md). Sin despliegue, correo comercial, datos reales, dispositivos ni servidores nuevos. No se relanzó una corrida con modelos ni se consumió cupo de otros agentes.

## Resultado

- Corregido y reconstruido el runtime local de Ralph que Freeplast realmente importa: `~/Projects/ralph/dist/index.js`.
- Corregido el workflow local `.ralph/main.mts` y sus prompts; aplicado también al scaffold `parallel-planner-with-review` en el repo Ralph.
- **Ninguna de las tres ramas supera la revisión de recuperación. No se integraron ni cerraron tickets.** `main` permanece en `5bd471d7ddafc39935d8d298a25b2a808f93601f`; las tres puntas permanecen intactas.
- El repo Ralph ya tenía WIP extenso, incluido en el build local; ese build no es un release limpio. Para publicar se aislaron solo los cambios propios sobre el HEAD anterior en otro worktree: commit **`01e2f01bf39a0fe2ab4bfa2e8331a26ab0ee7834`**, publicado en `mauricioliu/ralph:main`. Esa versión limpia pasó **1452 tests / 52 archivos**, typecheck y build. El WIP previo permanece fuera del commit; 32 archivos de trabajo se conservaron byte a byte al avanzar main. El cambio previo de modelo del planner también queda fuera del commit. Backup del diff previo: `/tmp/ralph-before-429-fix.patch`; dist anterior: `/tmp/ralph-dist-before-429-fix.iBccrw/dist/`.

## 1. Corrección de Ralph

### Parser e invocación

En `~/Projects/ralph/src/AgentProvider.ts` y `src/Orchestrator.ts`:

- Los mensajes Pi `message_end`/`agent_end` con `stopReason: error|aborted` son errores, incluso con salida del proceso 0. Se leen también los eventos de error y agotamiento de reintentos.
- El error queda pendiente hasta la salida o timeout de inactividad: una recuperación real del retry interno de Pi puede reemplazarlo; no se aborta el primer fallo transitorio antes de que Pi reintente.
- Un error o nuevo turno revoca el COMPLETE anterior. Un proceso colgado tras un error ya no termina por el temporizador de éxito.
- Resultado y señal de finalización se derivan solo de texto/resultados parseados del asistente. Eliminado el fallback al stdout crudo que podía contener el prompt del usuario o resultados de tools.
- Se conservan los diagnósticos en salidas fallidas; un 429 no inicia otra iteración de Ralph ni provoca un cambio automático de proveedor.

### Workflow

En `.ralph/main.mts`, `plan-prompt.md`, `review-prompt.md`, `merge-prompt.md` y el scaffold equivalente:

- `RALPH_AUTHORIZED_ISSUES` obligatorio por lanzamiento; etiquetas no sustituyen autorización ni insumos.
- Una sola tarea por ronda, sin pipelines simultáneos sobre módulos solapados.
- Recibos atómicos en `.ralph/workflow-state.json`: implementación, revisión pendiente, integración pendiente y merge comprobado.
- Ramas con commits pendientes van a revisión, no a una implementación nueva. Un recibo `merge-pending` solo permite saltar la revisión si coinciden SHA y HEAD de la base revisada.
- El revisor debe satisfacer aceptación y pruebas, no solo refactorizar; usa una señal propia `REVIEW_APPROVED`. Revisión truncada, falta de avance, error o límite de rondas detienen el workflow.
- El merger debe probar incluso sin conflictos. La señal COMPLETE debe acompañarse de la ascendencia git del SHA revisado comprobada en el host.
- Sin cierre automático de issues, push ni deploy. Una rama ya integrada con issue aún abierto detiene para conciliación; plan vacío no significa backlog completo.
- El workflow de Freeplast bloquea explícitamente **#53, #56 y #61** hasta resolver los hallazgos de esta revisión. No retirar bloqueos por pasar tests de fixtures.

`.ralph/` está ignorado por git en Freeplast. Para la publicación autorizada se agregan explícitamente solo los cuatro archivos fuente modificados (`main.mts`, `plan-prompt.md`, `review-prompt.md`, `merge-prompt.md`); `.env`, estado, logs, worktrees y demás configuración local siguen ignorados. El entorno/scaffold local existente sigue siendo prerrequisito; este commit no publica credenciales ni reconstruye toda la instalación. La implementación reutilizable está en el repo Ralph. No se modificó el workflow de otras familias de templates; el alcance es el scaffold con revisión y esta instalación.

### Evidencia

- Replay del build original: `/tmp/freeplast-ralph-429-repro.mjs` devolvía `resolved` en ambos casos y `falseCompletion=true` cuando el prompt contenía el marcador.
- Tras rebuild, el mismo replay por el invocador real devuelve **`rejected` en ambos casos**, verificando que la excepción contiene el 429/código 1308 capturado (no acepta una excepción ajena como verde).
- Regresiones de invocación: error+exit0, marker del usuario/tool/stdout malformado, aborto/error después de COMPLETE, retry interno recuperado, error colgado y turno posterior que invalida el marcador.
- Replay del workflow anterior contra las nuevas pruebas: **11 rojas / 1 verde**. Workflow corregido: **12 verdes**, sin subprocessos, modelos, Docker ni GitHub; se ejecuta el template real con I/O sustituido.
- Suite Ralph completa: **53 archivos / 1464 tests verdes**. `npm run typecheck`, `npm run build` y `git diff --check` correctos. Logs: `/tmp/ralph-full-test.log`, `/tmp/ralph-429-build.log`.
- Guardia local: ejecutar sin `RALPH_AUTHORIZED_ISSUES` sale 1 **antes de cualquier modelo**. Log `/tmp/freeplast-ralph-launch-guard.log`.
- Freeplast `main`: `FREEPLAST_SKIP_STACK=1 npm test` pasó; salida final reporta 653 checks de sintaxis/dependencias/despliegue y declara stack omitido. Log `/tmp/freeplast-main-offline-recovery.log`.

## 2. Revisión de ramas recuperadas

| Ticket / SHA | Veredicto | Motivo |
|---|---|---|
| #53 / `64559b472e83f580bff6ad7ed6875472ca19b906` | Bloqueado por contrato/insumos | La rama implementa un CSV propuesto con `id_producto`, `id_variacion`, `precio` y rechaza XLSX. El criterio pide el contrato acordado desde una muestra, o acordar expresamente un formato si no existe. La ADR de la rama admite que ese acuerdo no existe. |
| #56 / `ef1ec98a85857b934925f4291f0b9b381f0b7519` | Revisión rechazada | Fallos reproducidos de revisión vista, persistencia y manejo de error del renderer; además el PDF propio no satisface el requisito de biblioteca fijada/activos autorizados. |
| #61 / `8b9f6a37472573e9fc2e410cb5389febd6073485` | Bloqueado por contrato/insumos | La rama fija `cargo fijo + CLP/km × kilómetros iniciados`, mínimo y valores estrictamente positivos antes de aprobar forma, interpretación y calibración con fletes reales. Ausente por defecto no equivale a aprobación de esa forma. |

Se leyó el estado/cuerpo vigente de los tres issues y sus ADRs de rama. Sus pruebas existentes pasan con fixtures, pero eso no resuelve los contratos ni constituye una revisión de aceptación completa.

### #56 — hallazgos reproducidos

Archivo de rama: `wordpress/wp-content/plugins/freeplast-woo/quotation-approval.php`.

1. **Alta: una pestaña antigua puede aprobar la revisión nueva que no mostró.** El formulario (líneas 406–413) solo envía acción y nonce ligado al order ID; el handler (490–516) lee el trabajo y preview actuales sin comparar con la revisión mostrada al dueño. Escenario: pestaña A muestra revisión 1; B guarda/previsualiza 2; A envía su formulario válido. Resultado reproducido: `approval-sent`, versión congelada con revisión **2**, un correo interceptado. El control de obsolescencia entre los dos registros actuales no vincula la acción a la revisión vista por A.
2. **Alta: se envía sin PDF durable y se afirma éxito ante fallo de UPDATE** (144–148). Los dos `fpw_update_options_row` ignoran su booleano. Fallo inyectado: retorno `sent`, un correo interceptado, fila persistida aún con `document=pending` y sin bytes PDF. La recuperación no tendría el documento enviado y el estado durable contradice lo anunciado.
3. **Alta: INSERT fallido se confunde con versión ya aprobada** (133–135). Si no existe fila después del fallo, retorna `already` con `version=null`; la UI puede anunciar una aprobación inexistente. Debe distinguir contención/versión encontrada de fallo o resultado incierto.
4. **Media: excepción de renderer configurable escapa** (395, antes del `try`). `apply_filters('fpw_quotation_document_bytes', ...)` que lanza no produce el estado `document-pending`: rompe la solicitud después de guardar la versión aprobada.
5. **Conformidad no satisfecha:** la ADR-0011 de la rama sustituye unilateralmente la biblioteca PDF fijada requerida por un writer propio, sin activos reales. Esa justificación no cambia el criterio del issue. Se requiere cumplirlo o una modificación explícita del contrato, no afirmar aceptación.

Reproductor preservado: `docs/reviews/ralph-2026-09-10-issue56-repro.php`. Usa el **módulo real de la rama**, sustituye exclusivamente fronteras WP/DB/mail y datos sintéticos. No inicia WordPress ni transporte real. Para reproducir:

```sh
git show ralph/issue-56:wordpress/wp-content/plugins/freeplast-woo/quotation-approval.php > /tmp/freeplast-quotation-approval-review.php
wordpress/.tools/php/php docs/reviews/ralph-2026-09-10-issue56-repro.php /tmp/freeplast-quotation-approval-review.php
```

Resultado esperado mientras la rama siga así: **5 aserciones rojas**, salida 1 (el fallo de UPDATE verifica por separado envío y anuncio). Se conservó rojo deliberadamente: no es un fix del código comercial ni se incluyó como test verde en la suite.

### Pruebas de las ramas y límites

Se exportaron sin modificar refs a `/tmp/freeplast-ralph-recovery.k7TgKD/issue-{53,56,61}` y se ejecutaron sus tests PHP existentes:

- #53 `price-import-test.php`: **81** checks verdes.
- #56 `quote-draft-test.php`: **259** checks verdes (incluye #50/#51/#55/#56); no cubrían los fallos inyectados arriba.
- #61 `dispatch-rule-test.php`: **62** checks verdes.

Intentos de suite offline completa en los exports se bloquearon por caches ausentes y luego por el fingerprint que requiere contexto git/frozen A. Se copiaron dependencias locales de solo prueba, sin descargarlas; no se falseó ese gate ni se atribuyó el error de entorno a la lógica de la rama. No se declara suite completa verde de las ramas. Los logs `issue-*-offline.log` y `issue-*-targeted.log` están en el directorio temporal indicado.

No hubo prueba HTTP WP/Woo ni concurrencia DB real en esta sesión, ni navegación/aceptación visual/hardware. El estado sigue **7 de 13 integrados localmente**, 3 ramas bloqueadas y #57/#58/#62 sin ejecutar en la corrida.

## Siguiente trabajo

1. Corregir #56 con regresiones de revisión vista ligada a la acción, escrituras durables comprobadas, estados inciertos y fallos del renderer; resolver el contrato PDF. Mantener el ticket abierto.
2. Pedir muestra/mapeo o formato expresamente aprobado para #53; fletes históricos y forma/criterio de calibración aprobados para #61. Mantener la vía manual.
3. Revisión nueva ligada a los SHAs corregidos y al `main` vigente, incluyendo el recorrido requerido en stack desechable cuando exista autorización de ese servidor. Solo entonces considerar merge secuencial; ni `COMPLETE` ni un comentario de implementación sustituyen el gate.
