# Diagnóstico de Ralph — ejecución nocturna 9–10 septiembre 2026

Diagnóstico solicitado por el dueño el 10 de septiembre. **Sin corrección, relanzamiento, integración, cambios en GitHub ni despliegue.** WIP previo de Freeplast y del repo Ralph preservado. Se creó únicamente este informe en el repo; el replay está en `/tmp/freeplast-ralph-429-repro.mjs`.

## Conclusión

La repetición está confirmada. No fueron siete implementaciones distintas del mismo ticket: hubo una implementación con commits por ticket y después 16 invocaciones vacías distribuidas entre #53, #56 y #61.

El detonante fue el cupo GLM agotado (HTTP 429, código 1308). Dos defectos encadenados lo convirtieron en un bucle: Ralph interpretó el error como finalización y el workflow no sabe reanudar una revisión/integración pendiente sin nuevos commits del implementador. Además, el planificador paralelizó cambios sobre los mismos módulos, contrario a sus instrucciones.

## Cronología (America/Santiago, UTC−03)

Fuente: `.ralph/logs/main-planner.log`, logs individuales, `main-merger.log` y git local.

| Inicio planificación | Tickets elegidos | Resultado observado |
|---|---|---|
| 9 sep 23:24 | #50 | Integrado |
| 10 sep 00:34 | #51, #54, #59 | Integrados, con conflictos resueltos por el merger |
| 02:14 | #52, #55, #60 | Integrados, con conflictos funcionales y ajustes de pruebas |
| 04:27 | #53, #56, #61 | Implementados en ramas; no integrados |
| 05:38:08 | Integración del último lote | GLM 429; cero actividad de integración; log afirma finalización |
| 05:38:39–05:43:46 | Seis nuevas planificaciones | Cinco eligen los tres; una elige solo #53 |
| ~05:44 | Fin de los logs | Se consumen las diez rondas configuradas |

| Ticket | Arranques implementador | Arranques posteriores sin commits | Commits actuales fuera de main |
|---|---:|---:|---:|
| #53 | 7 | 6 | 2 (`8d4dcfb`, `64559b4`) |
| #56 | 6 | 5 | 1 (`ef1ec98`) |
| #61 | 6 | 5 | 2 (`34dda02`, `8b9f6a3`) |

Los otros siete tickets tienen un arranque del implementador cada uno. `main` termina en `5bd471d` (integración #52/#55/#60). GitHub mantiene abiertos #53/#56/#61; #57/#58/#62 no aparecen ejecutados en esta corrida. No se observó un proceso activo de este workflow al inspeccionar. No hay evidencia en estos registros de dos corridas superpuestas: el patrón encaja con las diez rondas del único bucle configurado.

## 1. Error del proveedor convertido en falso COMPLETE — confirmado con replay

Sesión capturada del merger:

`~/.pi/agent/sessions/--home-mauricio-liu-Projects-freeplast--/2026-09-10T08-38-09-793Z_01a08a77-9c81-7fd1-bc11-b485d2d7b04a.jsonl`

Contiene cuatro respuestas del asistente, entre 08:38:11Z y 08:38:28Z, con:

- `provider: zai-coding-cn`, modelo `glm-5.3`.
- `stopReason: error`, `content: []`, cero tokens.
- HTTP `429`, código `1308`: se alcanzó el límite de uso de cinco horas.

No se interpreta la zona horaria del reset contenido en el mensaje del proveedor ni se afirma el cupo actual.

Camino del fallo en `~/Projects/ralph/`:

1. `src/AgentProvider.ts:545`: el parser Pi extrae bloques de texto de `agent_end`, pero ignora `stopReason` y `errorMessage` de los mensajes. Un asistente vacío con error produce cero eventos útiles.
2. `src/Orchestrator.ts:182`: el error solo se eleva por código de salida del proceso distinto de cero. En el modo JSON de Pi, un error del modelo puede salir con código 0; su contenido debe interpretarse.
3. `src/Orchestrator.ts:200`: sin resultado parseado, usa **todo el stdout crudo** (`resultText || execResult.stdout`).
4. `src/Orchestrator.ts:560`: busca el marcador con `agentOutput.includes(...)`. El stream contiene el mensaje del usuario, incluido el prompt que ordena emitir `<promise>COMPLETE</promise>`. El marcador del **prompt** se acepta como si fuese una respuesta satisfactoria.

El código desplegado resuelve a `~/Projects/ralph/dist/index.js`, no al symlink roto de `freeplast/node_modules/.bin/ralph`. Se comprobó el mismo comportamiento en ese build, no solo en el source WIP.

### Reproducción ejecutada dos veces

```sh
node /tmp/freeplast-ralph-429-repro.mjs
```

El script expone el invocador del build desplegado en una copia temporal, sustituye exclusivamente la ejecución externa por un stream sintético construido con el error real capturado y usa el parser/invocador reales. Sin llamadas a modelos, Docker, GitHub ni git. No es un replay byte a byte del stdout perdido: la envoltura de eventos reproduce el contrato de Pi comprobado en su código.

Resultado estable, salida 1 esperada mientras existe el bug:

```text
promptContainsMarker=true  actual=resolved falseCompletion=true  parsedErrorEvents=[]
promptContainsMarker=false actual=resolved falseCompletion=false parsedErrorEvents=[]
AssertionError: BUG REPRODUCED: 429 resolves successfully; user prompt marker produces false completion
```

Cambiar únicamente el marcador del prompt elimina la finalización falsa, pero **no** la aceptación errónea del 429. Son dos fallos distintos. El texto `Run complete: ... without completion signal` de los planners es normal para su configuración de una iteración con salida estructurada; no es el error investigado.

## 2. No existe recuperación de fase pendiente — confirmado en workflow y git

En `.ralph/main.mts`:

- Línea 147: solo revisa si `implement.commits.length > 0` en **esta invocación**.
- Línea 189: solo propone integrar resultados cumplidos con commits en **esta invocación**.
- Líneas 202–205: si no hay commits nuevos, hace `continue`, sin reconciliar ramas pendientes ni detenerse por falta de progreso.
- Líneas 213–232: no verifica resultado de merge, ascendencia de cada rama en main ni cierre real de tickets; imprime `Branches merged.` al resolverse la llamada.
- Línea 235: imprime `All done.` incluso al agotar el máximo de diez rondas con pendientes.

El planner vuelve a consultar issues abiertos con etiqueta Ralph. Como el merge no ocurrió, los tickets permanecen abiertos. No recibe un estado durable como `implemented`, `review-pending`, `merge-pending` o `provider-blocked`. Al repetir el ciclo vuelve al implementador en vez de a la fase interrumpida. Los cinco commits pendientes **sí existen**; el filtro los ignora al mirar solo el delta de la nueva invocación.

En esta corrida el 429 explica directamente el merger fallido. Los arranques posteriores muestran agentes vacíos y finalización falsa compatibles con la misma condición; no se conservó en los logs individuales su detalle de error para atribuir cada uno con igual certeza.

La revisión de #56 termina tras «All tests pass. Now let me examine…», sin conclusión ni marcador; no debe darse por terminada. No se atribuye con certeza ese corte al 429 porque falta su sesión detallada. El workflow, de todos modos, no exige un veredicto de aprobación de revisión antes de integrar.

La lógica problemática también existe en `~/Projects/ralph/src/templates/parallel-planner-with-review/main.mts`: corregir solo el proyecto dejaría futuros scaffolds expuestos.

## 3. Secuencia/paralelismo y bloqueos débiles — hallazgo independiente

`.ralph/plan-prompt.md:20` ordena considerar bloqueados entre sí los tickets que modifican archivos/módulos superpuestos. Sin embargo:

- #52 y #55 se ejecutaron juntos y ambos cambiaron `quote-draft.php`, `freeplast-woo.php`, `woo-stack-harness.mjs` y `CONTEXT.md`.
- El merger registra explícitamente «Real functional conflict between two front-door implementations».
- Los pares del lote #51/#54/#59 comparten 5–6 archivos; los de #52/#55/#60, 4–5; los de #53/#56/#61, 3–4 (diff de cada rama contra la base de su lote).

Esto añade conflictos y trabajo de integración; no es la causa inmediata de las 16 invocaciones vacías.

Hay otra instrucción contradictoria en el prompt: «Include only unblocked issues» seguida de «If every issue is blocked, include the single highest-priority candidate». Debe poder devolver cero por bloqueo, no saltarlo. La etiqueta Ralph se trata como lista ya preparada, pero los tickets #53/#61 declaran bloqueos externos de muestras/calibración que no resuelve una etiqueta. Esta revisión no determina si hubo autorización humana posterior para lanzar el proceso ni audita la conformidad comercial del código; esos bloqueos deben comprobarse antes de recuperar las ramas.

## Orden de corrección y recuperación propuesto (no ejecutado)

1. Corregir el adaptador/orquestador: errores y abortos estructurados no son éxito; detectar COMPLETE solo en salida válida del asistente, nunca en stdout bruto/prompts/tool results. Conservar el error visible y el estado de la ejecución. Considerar los reintentos internos de Pi antes de convertir un error transitorio en terminal.
2. Detener/pausar el workflow ante cupo agotado o rondas sin progreso; no relanzar implementadores sobre un proveedor bloqueado. Aplicar backoff/reanudación explícita sin cambiar de proveedor ni gastar cupo adicional automáticamente.
3. Persistir y reconciliar estados por ticket/branch/SHA. Separar implementación, aprobación de revisión e integración. Decidir trabajo pendiente mediante diferencias/ascendencia frente a main y revisión vinculada al SHA, no solo nuevos commits por invocación.
4. Comprobar integración real antes de avanzar/cerrar: rama incluida en main, pruebas requeridas, veredicto de revisión y estado del issue. Reportar `blocked`/`partial`/`iteration-limit`, no `All done` universal.
5. Imponer bloqueos externos y técnicos, y serializar cambios superpuestos. Quitar el fallback que fuerza un ticket bloqueado.
6. **Después** de revisar insumos/contratos y autorización, recuperar el lote existente #53/#56/#61 desde sus ramas. Completar la revisión de #56 y comprobar las otras revisiones antes de integrar secuencialmente. No pedir tres implementaciones nuevas. Solo tras integración verificada reconsiderar #57/#58/#62.

No se corrigió ni se reconstruyó Ralph: su repo contiene WIP ajeno. No se movieron ramas ni se publicaron cambios. Este informe no certifica la corrección del código comercial producido durante la corrida.
