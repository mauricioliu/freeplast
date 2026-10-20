# Lighthouse DEV: primero validar la medición

**Fecha:** 2026-10-16. **Versión:** 1, propuesta para revisión.
**Repositorio:** `/home/mauricio-liu/Projects/freeplast`.
**Referencia inspeccionada:** HEAD `591c075fd52f931444485db496f6fcd365a4957f`, con trabajo local sin commit.
**Permisos actuales:** inspección de solo lectura y escritura de este plan. Implementación, instalaciones, tests, auditorías e intervenciones pendientes de aprobación explícita. Aprobar el diseño no autoriza por sí solo ejecutarlo.

## 1. Propuesta y decisiones del propietario

Construir una CLI local pequeña en `tools/lighthouse-dev/`, independiente de agentes y sin motor durable, modelos, servicios ni despliegues. Separar colección, comparación determinista y registro manual de una hipótesis. Artefactos privados fuera de Git. Primera versión supervisada, una iteración por ciclo; ningún cambio automático de código.

El primer piloto prueba **A/A sin cambio de código**: dos series de 18 auditorías (3 rutas × 2 perfiles × 3 repeticiones). Solo después de demostrar comparabilidad y ruido aceptable se propone una mejora concreta. Horizonte: aprender en una sesión local; no acreditar experiencia en producción.

Decisiones pendientes, con recomendación:

1. **Origen DEV:** confirmar `http://mliu:8131`, respaldado por el enlace recibido, documentación y HTTP real. El texto recibido dice `:8890`; tiene otro listener no identificado. **No se autoriza ni se consulta ese servicio por inferencia.** Si se elige 8890, verificar primero su identidad y aislamiento por metadatos locales autorizados, antes de HTTP.
2. **Ficha:** aprobar `/producto/caja-cosechera-3-4/`, que existe y responde como LOCAL. Conservar `/` y `/tienda/`. No seguir enlaces para descubrir otras rutas.
3. **Contrato:** aprobar provisionalmente umbrales, topes y retención de este plan; calibrarlos en A/A antes de congelarlos para candidatos.
4. **Primer paquete ejecutable:** autorizar por separado implementación/pruebas offline, instalación acotada de dependencias y piloto A/A. El permiso para optimizar el tema será posterior a la evidencia A/A.

Hasta resolver 1–2 no hay auditorías reales. Los valores propuestos no son acuerdos del propietario.

## 2. Evidencia y límites de conocimiento

Inspección realizada alrededor de las 20:40–20:42 de Chile, 2026-10-16. GET/HEAD anónimos, secuenciales, sin proxies ambientales y sin seguir redirecciones. No navegador ni Lighthouse.

| Afirmación | Tipo | Fuente | Consecuencia |
|---|---|---|---|
| Instrucciones de proyecto y dominio leídas | Hecho | `AGENTS.md`, `README.md`, `CONTEXT.md`, `docs/agents/domain.md`, ADR-0002 | Mantener vocabulario Catálogo y ceremonia de release fuera del runner |
| Hay dos archivos modificados y cuatro nuevos previos | Hecho | `git status --short`, abajo | Preservarlos; HEAD no identifica por sí solo lo servido |
| Las herramientas solicitadas no existen en Freeplast; están en Novadermis | Hecho | Rutas locales de §3 | Reutilizar ideas verificadas, no importar autoridad ni ejecutar sus CLIs |
| Local documentado usa WordPress/MariaDB, binds de tema/adaptador y URL 8131 | Hecho de configuración | `wordpress/local/{README.md,local.mjs,compose.yaml,config.php,safety.php}` | No prueba por sí solo runtime ni pureza de datos actuales |
| Tailscale Serve publica 8131 hacia loopback8131; 8890 tiene docker-proxy distinto | Hecho observado | `tailscale serve status`, `ss -ltnp`, procesos docker-proxy | No asumir equivalencia de puertos; 8890 no recibió HTTP |
| Las tres rutas recomendadas devuelven 200, marcador LOCAL y meta robots `noindex,nofollow` | Hecho observado | GET sin redirecciones a esas rutas en 8131 | Páginas candidatas válidas; no es baseline de rendimiento |
| HTML y CSS servido tienen `Cache-Control: no-store, max-age=0`; CSP restringe terceros | Hecho observado | HEAD portada y GET rutas/CSS | Mantener esta política; no relajar CSP para mejorar scores |
| CSS servido coincide byte a byte con este checkout | Hecho observado | SHA-256 `style.css`: `42374483cf4610d39087263b470de876d511c1a2d40524bb4f9c90d259f25e77` | Evidencia parcial de montaje; no certifica todos los PHP/plugins |
| OPcache off, cron off, bloqueos de correo/HTTP están configurados | Hecho de código | `local/php.ini`, `local/config.php`, `local/safety.php`, README local | Verificar identidad runtime antes del piloto; no leer secretos ni datos comerciales |
| No hay Lighthouse, Puppeteer ni Playwright en lock raíz; no `.github/` raíz | Hecho inspeccionado | `package*.json`, árbol, `node_modules/.bin` | Toolchain nuevo aislado; sin CI ni cron en v1 |
| Node 26.8.2, npm 11.19.1, Chromium 152.0.7977.82, Chrome 153.0.8010.47 y `/usr/bin/flock` disponibles | Hecho observado | Versiones y PATH; Chrome `/opt/google/chrome/chrome` | Elegir un solo binario explícito y fijar versión/huella; no autodetectar canal |
| No existe baseline Lighthouse DEV comprobada en esta inspección | Desconocido/no medido | Documentación revisada y ausencia de runner local | Obtener A/A; jamás trasladar valores de otro sitio |
| Mediciones antiguas Freeplast corresponden a producción legacy, otro protocolo | Hecho documental | `docs/reviews/performance-2026-09-05/README.md` | No reutilizar sondas dirigidas a producción ni sus cifras como baseline |
| Una mejora de LCP puede mejorar la primera lectura del catálogo | Hipótesis general | Se comprobará con informes DEV y revisión humana | No elegir todavía una intervención específica |

Trabajo previo protegido:

- Modificados: `wordpress/local/README.md`, `wordpress/wp-content/themes/freeplast/functions.php`.
- Nuevos: `wordpress/scripts/discovery-test.php`, `wordpress/scripts/sitemap-test.php`, `wordpress/wp-content/themes/freeplast/inc/discovery.php`, `wordpress/wp-content/themes/freeplast/inc/sitemap.php`.

Las respuestas HTML no se guardaron como artefactos de auditoría. El listado de estado y observaciones anteriores es evidencia de inspección, no un reporte Lighthouse ni certificación de aislamiento. Los nonces/fechas pueden variar: no exigir igualdad byte-exacta de HTML dinámico sin distinguir campos esperados.

## 3. Reutilización y herramientas existentes

Referencias de solo lectura bajo `/home/mauricio-liu/Projects/novadermis/novadermis-store/`, HEAD observado `c1f4e5e1a816707012c058a121195a62be4f62b9`:

| Referencia inspeccionada | Reutilizar | Excluir |
|---|---|---|
| `tools/feedback-durable/src/measurement.mjs` | Mediana, rango, MAD, A/A, pins, informes inmutables y parada al primer fallo | Imports de configuración/policy, URLs, umbrales ajenos, permisos y subprocess runner |
| Su parser nuevo y parser antiguo | Validar números finitos/rangos y retener fallos | Parser antiguo convierte métricas ausentes en cero; no copiar. El nuevo tampoco cubre nuestro SI, cuatro guardrails y ambos perfiles |
| `tools/feedback-durable/{RUNBOOK.md,src/durable-store.mjs}` | Aprendizaje: separar intención/resultado, conservar incertidumbre y evidencia | Pi Durable, SQLite, planner, SSH, releases, rollback remoto, agentes, servicios, grants históricos |
| `scripts/webpay-feedback-loop/{core.mjs,test/durable-core.test.mjs}` y `docs/webpay-feedback-loop.md` | Diseño de pruebas multiproceso, crash y no repetición ciega | Motor Webpay, adquisición nativa, credenciales, instaladores, VM y systemd |

Huella de referencias: `measurement.mjs` SHA-256 `a6995ad5f1421bce34efffac87809abca1cd933c92190ca7c82301115e5546f5`; `core.mjs` SHA-256 `70b7e84d8a494d8343db7f73975d4f3a5d5b058d96a32d76f05492b9a7ea28f8`.

Freeplast ya tiene tests PHP/JS, jsdom, fixtures Woo fijados y fingerprint de familias de archivos (`wordpress/scripts/evidence-fingerprint-test.mjs`). Reutilizar patrones, no los comprobadores de pedidos. `npm test` puede arrancar un stack desechable; **no ejecutarlo sin `FREEPLAST_SKIP_STACK=1`** en este trabajo. El gate offline necesita fixtures locales y puede fallar si faltan: reportar bloqueo, no correr bootstrap ni descargar WordPress para compensarlo. `test:local` simula Docker; no prueba aislamiento runtime. No cambiar release pipelines ni `wordpress/infra/`.

## 4. Arquitectura propuesta y superficie CLI

Archivos futuros, aún inexistentes: paquete aislado `tools/lighthouse-dev/package.json` + lock exacto, `bin/freeplast-lh.mjs`, módulos de política DEV, medición, resumen/comparación, almacenamiento/ciclo y tests. Documento de uso `tools/lighthouse-dev/README.md`. Preferir módulos pequeños por responsabilidad, Node builtins para estado y tests. Sin dependencia runtime del checkout Novadermis.

Usar Lighthouse mediante API de Node para recibir JSON y HTML y controlar un navegador propio. Dependencias directas exactas y transitivas con integridad en lock; nunca `npx ...@latest`. Evaluar Lighthouse **13.5.0** como candidato de versión (observado en la referencia, no certificado aquí); fijar cliente CDP compatible y Node exactos tras comprobar sus contratos. Preferencia inicial: Chromium existente 152.0.7977.82 con ruta real y SHA; si no es compatible, detener y presentar cambio de toolchain, sin instalar/actualizar el navegador global. Una copia privada de navegador fijado requiere autorización de descarga/almacenamiento. No anunciar reproducibilidad portable antes de cerrar esos pins.

**Interfaz a construir, NO comandos disponibles hoy:**

```sh
node tools/lighthouse-dev/bin/freeplast-lh.mjs --help
node tools/lighthouse-dev/bin/freeplast-lh.mjs doctor
node tools/lighthouse-dev/bin/freeplast-lh.mjs measure --label aa-1
node tools/lighthouse-dev/bin/freeplast-lh.mjs measure --label aa-2
node tools/lighthouse-dev/bin/freeplast-lh.mjs calibrate --a <id-a1> --b <id-a2>
node tools/lighthouse-dev/bin/freeplast-lh.mjs list
node tools/lighthouse-dev/bin/freeplast-lh.mjs show --run <id>
node tools/lighthouse-dev/bin/freeplast-lh.mjs baseline set --run <id> --confirm
node tools/lighthouse-dev/bin/freeplast-lh.mjs compare --baseline <id> --candidate <id>
node tools/lighthouse-dev/bin/freeplast-lh.mjs cycle begin --baseline <id> --hypothesis <archivo.json>
node tools/lighthouse-dev/bin/freeplast-lh.mjs cycle evaluate --cycle <id> --candidate <id> --checks <archivo.json>
node tools/lighthouse-dev/bin/freeplast-lh.mjs cycle decide --cycle <id> --action keep
node tools/lighthouse-dev/bin/freeplast-lh.mjs cycle decide --cycle <id> --action discard
```

`measure` no solicita propuestas ni modifica fuentes. `cycle` registra una intervención humana, verifica pre/post-imágenes y ejecuta solo las etapas de medición/evaluación; no acepta shell hooks arbitrarios, no llama modelos, no aplica parches propuestos. `discard` es la única recuperación de fuentes y exige el manifiesto de parche de esa iteración y autorización explícita por comando. Sin evidencia completa de tests/aceptación visual, `keep` queda bloqueado aunque el comparador numérico marque mejora. La baseline solo cambia mediante `baseline set` explícito, nunca al comparar o tras regresión.

Sin argumentos: estado local e instrucciones pertinentes, sin red/escritura. `--help` por subcomando, versiones `--version/-v/-V`, flags desconocidos rechazados antes de efectos, sin prompts. Stdout estructurado mínimo (TOON con campos simples; `--json` para integración); progreso a stderr, logs privados saneados. Códigos: 0 colección/consulta correcta o comparación con mejora; 1 fallo técnico, seguridad o medición inválida; 2 uso; 3 regresión; 4 inconcluso; 5 incompatible; 6 ocupado. Documentar que `measure` exitoso no implica mejora.

## 5. Frontera DEV y aislamiento

1. Una configuración revisada fija **un origen exacto** y las tres rutas; no admitir `--url` arbitrario ni variables de entorno capaces de sustituirlo. Rechazar otros esquemas, puertos, userinfo, fragmentos, queries no aprobadas, paths codificados ambiguos y destinos privados alternativos por parecido de nombre. No tratar localhost y mliu como alias intercambiables.
2. Resolver y registrar IP de `mliu`, comprobar pertenencia al host local autorizado y fallar ante cambio durante la serie. Desactivar proxies ambientales; jamás tomar settings/cookies de un navegador personal.
3. Preflight HTTP manual de redirecciones: validar destino **antes** de solicitar cada hop; máximo 3, únicamente mismo origen y rutas autorizadas. Verificar 200, identidad LOCAL, robots y cabeceras. 429/5xx, timeout o identidad dudosa detienen toda la serie. No arrancar DEV ni corregir infraestructura.
4. El preflight NO basta: instalar guardia de navegación antes de que Lighthouse navegue, en cada documento/target de su navegador, con auto-attach pausado para nuevos targets. Interceptar solicitudes antes de enviarlas, incluidos saltos HTTP, meta-refresh, JavaScript, popups y frames. Bloquear documentos fuera del origen/rutas aprobados; bloquear todos los popups. Verificar URL final e historial de documentos, estados HTTP y guardia activa al terminar. Una validación posterior sola es insuficiente.
5. Permitir recursos externos propios de la página, no documentos externos; no seguir links. Denegar también recursos a staging/producción Freeplast conocidos y aliases descubiertos en configuración local. Las solicitudes del runner (fuera del navegador) solo alcanzan DEV. La CSP actual ya restringe terceros: conservarla. Registrar bloqueos sin atribuirles ahorros reales. Recursos inesperados de integraciones comerciales causan pausa, no ampliar automáticamente permisos.
6. Métodos solo GET/HEAD; rechazar POST/PUT/PATCH/DELETE, WebSocket y endpoints de mutación incluso por GET (`add-to-cart`, `wc-ajax`, formularios/acciones, carrito/checkout, administración, cron). Permitir assets y auxiliares de lectura requeridos por Lighthouse, como robots, solo con política explícita; no APIs comerciales ni login. Determinar antes del piloto si la página hace peticiones automáticas incompatibles: parar y documentar, no ejecutar un flujo de compra.
7. Lanzar navegador headless con sandbox, ejecutable explícito, perfil temporal propio 0700, extensiones deshabilitadas, depuración en loopback y puerto efímero. No conectarse a 9222 existente, perfiles personales ni browser harness compartido. Limpiar solo perfil y procesos hijos acreditados por PID + identidad de instancia. Nunca `pkill`, Docker prune, restart o stop de servicios.
8. Bloqueo kernel `flock` no bloqueante en archivo fijo privado por usuario/host, compartido por todos los runners de esta herramienta, no por carpeta de resultados ni checkout. Mantenerlo durante cada serie y mutación del estado; no borrar el inode del lock ni recuperar por PID solamente. Un ciclo activo reserva el derecho de editar su parche entre comandos; medir puede ser secuencial pero editar concurrentemente invalida la iteración.

**Gate técnico obligatorio:** demostrar con fixtures de navegador que la interceptación persiste a través de los passes reales de Lighthouse y previene la primera petición externa, no solo que detecta el destino final. Si el cliente CDP elegido no lo garantiza, bloquear piloto y revisar arquitectura; no reducir este control a `finalUrl`. No hay garantía ya implementada.

Lecturas HTTP pueden crear logs, cachés internas o sesiones anónimas técnicas; no se promete cero escritura del servidor. No escribir datos de negocio ni consultar BD para demostrarlo. Contención por runtime LOCAL + requests permitidos; si se exige prueba de inmutabilidad de tablas comerciales, se necesitará un evaluador autorizado distinto, fuera de este paquete.

## 6. Protocolo de colección y comparabilidad

### Configuración explícita propuesta

Categorías: performance, accessibility, best-practices, seo. Locale `es`, zona `America/Santiago`, idioma/UA y modo headless fijados en config efectiva.

| Perfil | Viewport CSS / DPR | CPU | Red simulada |
|---|---|---|---|
| móvil | 412 × 823 / 1.75; mobile true | multiplicador 4 | RTT 150 ms, throughput 1638.4 Kbps |
| escritorio | 1440 × 900 / 1; mobile false | multiplicador 1 | RTT 40 ms, throughput 10240 Kbps |

Método `simulate`, sin depender de defaults cambiantes; fijar además todos los campos de throttling requeridos por la versión elegida y guardar settings resueltos, UA efectivo, versión de esquema y hashes. Estas cifras son políticas propuestas de laboratorio, no representación certificada de un teléfono o conexión del usuario.

**Caché:** perfil nuevo por auditoría, cookies/storage/cache/SW vacíos; mantener reset de Lighthouse y bloquear interferencias de service workers. Fría de navegador exclusivamente. Servidor ya iniciado, con cachés internas no vaciadas ni reiniciadas; no afirmar servidor frío. Sin query cache-buster. Guardar cabeceras y evidencia de cache/SW. Si se observa contenido servido desde caché incompatible con el protocolo, marcar inválido. Warm-cache sería otro experimento con baseline independiente.

### Secuencia y evidencia

- `doctor`: disponibilidad, pins, estado de seguridad/config sin secretos, checkout/montajes acreditados por metadatos no sensibles, respuesta LOCAL, espacio libre y ausencia de otro runner. Si no puede probar procedencia runtime, pedir al operador un recibo de identidad sin credenciales; no leer archivos de password ni ejecutar `local status`, que valida secretos.
- Registrar antes/después Git HEAD, rama, estado index/worktree/untracked por nombre y SHA-256; hashes de contenido de tema/adaptador, assets locales, configuración LOCAL, lockfiles, manifiesto de dependencias y herramienta/evaluador. No volcar diff global ni `.env`, logs de correo o fuentes comerciales. Para parche propio guardar diff limitado y pre/post-imágenes saneadas. La huella incluye bytes, modos, altas/bajas, no solo `git diff --stat`.
- Mantener misma identidad de runtime/WordPress/plugins y contenido público. Capturar estructura pública, inventario de recursos y hashes de assets relevantes; distinguir nonces/fechas de cambios de contenido mediante normalizador explícito versionado que no oculte títulos, textos, imágenes ni layout. Drift de fuentes no intervenidas, datos públicos o configuración invalida comparación. No bloquear trabajo ajeno por fuerza: detectar y parar.
- Tres rondas fijas: por ronda móvil (portada, catálogo, ficha), luego escritorio (mismas rutas). 18 auditorías totalmente secuenciales, 5 s entre ellas; misma secuencia A1/A2/B. Solo fixtures offline pueden paralelizarse fuera de la ventana real.
- Preflight por auditoría, captura y timeout total por auditoría de 120 s, incluido arranque/guardias; deadline por serie 45 min. Primera falla detiene: conservar parciales y logs; sin reintento automático ni completar con ceros. Máximo un nuevo intento explícitamente autorizado de la serie completa después de corregir causa, con nuevo ID. Ningún descarte por ser lenta o puntuar mal.
- Guardar ID inmutable, ISO fecha/inicio/fin, URL solicitada y final/hops, ruta/perfil/ronda, configuración resuelta, versiones exactas/binarios/hash, hardware/OS, carga/memoria antes/después, política de caché y manifest de archivos. Host compartido: anotar actividad que no controla el runner; no cerrar aplicaciones ajenas para estabilizarlo.
- A1 y A2 miden mismo árbol y runtime sin intervención. Si ruido es excesivo, detener y revisar medición/ventana. No elegir un cambio de rendimiento con instrumento todavía inestable.

**Incompatible:** navegador/Lighthouse/Node/cliente CDP, configuración, caché, hardware, evaluator o versión de casos cambiados; origen/rutas distintos; baseline incompleta; deriva inexplicada. No comparar de forma permisiva: nueva baseline explícita + A/A cuando cambie el instrumento. Cambios del candidato solo se admiten si coinciden exactamente con la intervención declarada. Tests y configuraciones del evaluador quedan congelados durante A/B.

No hay aislamiento de bases de datos por crear una rama. Este es un piloto observacional sobre DEV compartido sin restauración de datos; conserva confusores de carga/red y no permite causalidad fuerte ni extrapolar a hosting real. Tres repeticiones son una decisión de ingeniería, no potencia estadística garantizada. La ficha alternativa variable y categorías quedan como validación reservada futura: un cambio transversal exige ampliar casos y obtener nueva referencia antes de aceptarlo.

## 7. Contrato de evaluación

Unidad: cada ruta × perfil, nunca un promedio que esconda una regresión. Comparar medianas de 3 válidas; mostrar valores individuales, n, min/max y MAD. Usar números completos para decidir; redondear solo presentación. Para cada señal temporal `m`, proponer umbral `T = max(absoluto, relativo × mediana_A, ruido_AA)`, con `ruido_AA = |mediana_A1−mediana_A2| + 2×max(MAD_A1,MAD_A2)` por caso/señal. Baseline cero: relativo no aplica, mostrar delta absoluto sin división por cero. Congelar calibración antes de B; no recalcularla con B para tolerar una regresión.

| Señal | Tipo / definición | Casos / ventana / evaluador | Decisión propuesta | Estado |
|---|---|---|---|---|
| LCP, principal | Proxy de primera lectura; ms, menor mejor | 6 casos; serie completa / parser independiente | Mejora focal si reducción > T; regresión en cualquier caso si aumento > T; pisos 200 ms y 10% | Propuesta a calibrar |
| FCP / Speed Index | Proxy visual; ms, menor mejor | Mismos casos / parser | Regresión si aumento > T; mínimos 150 ms y 10% | Propuesta |
| TBT | Proxy bloqueo de hilo; ms, menor mejor | Mismos casos / parser | Regresión si aumento > T; mínimos 50 ms y 15% | Propuesta; **no INP** |
| CLS | Proxy estabilidad; adimensional, menor mejor | Mismos casos / parser | Regresión si delta > max(0.01, ruido_AA); línea roja nueva CLS > 0.1 | Propuesta; deuda previa no se oculta |
| TTFB | Diagnóstico de documento; ms y audit ID/origen | Cuando versión lo exponga / parser | Advertir delta > max(100 ms, 15%, ruido_AA); si mecanismo esperado era TTFB, fijarlo antes como condición | Propuesta; opcional con causa |
| Cuatro scores | Proxy Lighthouse, 0–100 | 6 casos / parser | Performance informativo; caída A11y/BP/SEO no tolerada para aceptación | Propuesta |
| Auditorías A11y/BP/SEO | IDs, scoreDisplayMode, fallos nuevos y detalles relevantes | Cada auditoría válida / comparador de IDs y estado | Cualquier nuevo fallo o empeoramiento bloquea KEEP; si intermitente, inconcluso y revisión; no compensar con LCP | Política propuesta |
| Contenido y diseño | Resultado visible humano, no score | Rutas afectadas; antes/después / propietario | Malo: imagen/texto ocultado, layout alterado, navegación rota. Bueno: mismo contenido y diseño aprobado, foco y lectura conservados | Aprobación humana obligatoria |
| Funcionalidad | Resultado observable, sin flujos comerciales | Tests offline pertinentes y lectura DEV; por iteración / comprobadores deterministas | Todo exigible pasa; omitido/fallido nunca verde | Obligatorio |
| Seguridad | Origen, robots y no mutaciones comerciales | Todo el ciclo / policy + propietario | Frontera rota, noindex eliminado o request peligroso: detener sin aceptación | Obligatorio |

LCP debe incluir, cuando exista en el LHR fijado, selector/nodo y recurso/URL, tamaño y breakdown disponibles. Registrar auditorías fallidas, recursos render-blocking y diagnósticos con ID de versión. Las cinco métricas obligatorias (LCP, CLS, FCP, TBT y SI) y los cuatro scores ausentes/null/NaN/tipo erróneo, runtimeError, categoría incompleta o audit error exigible => **fallo de colección**, jamás mejora. TTFB/detalle LCP opcionales quedan `unavailable` con motivo, no cero; si son la evidencia principal de una hipótesis, esa hipótesis no puede confirmarse sin ellos.

A/A ruidosa: si `max−min > max(2×absoluto, 25%×mediana)` en LCP/FCP/SI, o `|mediana_A1−mediana_A2| > max(absoluto, relativo×mediana_A1)` en esas señales, bloquear aceptación y revisar confusores. `calibrate` persiste pisos por caso sin modificar la baseline y rechaza una A/A inestable; `compare` sin calibración válida solo puede devolver inconcluso/incompatible, nunca mejora. Mantener todos los valores; no hay sustitución selectiva de outliers. MAD con n=3 puede ser cero sin significar ausencia de ruido.

Objetivo del primer cambio: reducir LCP del caso focal seleccionado **antes** del parche, sin regresiones en otros casos/guardrails. Si el diagnóstico justifica otra principal (por ejemplo CLS), revisar contrato antes de cambiar código y no después de ver B. No forzar una mejora cuando DEV ya funciona bien.

**SEO DEV:** preservar `noindex,nofollow`; un fallo `is-crawlable` por esa directiva es deuda intencional anotada, no algo que arreglar. HTTP LOCAL puede limitar Best Practices/secure context: documentar evidencia real, no enmascarar ni cambiar a otro entorno para ganar puntos. Toda excepción debe quedar ligada a casos/audit IDs, dueño propietario y revisión en el siguiente cambio de herramienta/entorno; no exime nuevas regresiones.

Ejemplo de indicador engañoso: ocultar hero o productos puede subir Performance y empeorar experiencia. Lo rechazan revisión visual, huella de contenido y auditorías funcionales aunque las medianas mejoren. Lighthouse es laboratorio: no mide usuarios reales, INP de campo, conversiones ni accesibilidad completa. Validación de teléfono físico queda abierta y separada.

## 8. Ciclo manual, decisión y recuperación

| Paso | Entrada y acción futura | Responsable | Evidencia / criterio de salida |
|---|---|---|---|
| Preparar | Verificar autorización, seguridad, pins, presupuesto e historial | Ejecutor designado por propietario | Doctor aprobado; bloqueos resueltos; no credenciales |
| Baseline | Ejecutar A/A; revisar calibración y fijar A1 explícitamente si estable | Runner + propietario | 36 reportes completos, mismos inputs, ruido/calibración congelados |
| Diagnosticar | Leer LHR, elemento LCP, recursos y pruebas; elegir caso focal | Proponente | Evidencia enlazada, una hipótesis y alternativa plausible |
| Delimitar | Anotar mecanismo, refutación, archivos/hunks, tests, guardrails y recuperación | Ejecutor con aprobación del propietario | Manifiesto pre-imagen y autorización de un parche reversible |
| Intervenir | Aplicar un cambio mínimo manualmente, sin tocar datos ni infraestructura | Ejecutor autorizado | Diff exacto, post-imágenes y tests offline pertinentes; sin cambios ajenos |
| Evaluar | Nueva serie B 18 auditorías; comparar con contrato congelado | Runner determinista | JSON/HTML, manifest comparable, verdict con causas por caso |
| Decidir | Revisar tests/visual/resultado; KEEP o descartar | Propietario | Decisión atribuida; sin promoción automática de baseline |
| Aprender | Registrar confirmado/refutado, confusores y siguiente elección | Ejecutor | Lección enlazada; parar o pedir otro ciclo |

Ejemplo de hipótesis futura, **no hallazgo**: si un LHR demuestra imagen LCP tardía por descubrimiento CSS, adelantar solo ese recurso debería reducir su demora y LCP sin alterar contenido/CLS. Alternativa: domina TTFB; refutación: el recurso llega antes pero LCP no supera el piso de ruido. No implementarla sin ese diagnóstico.

| Resultado | Significado | Acción |
|---|---|---|
| Mejora | Datos válidos, LCP focal supera T y todos los guardrails/cobertura exigibles pasan | Recomendar conservar; propietario autoriza KEEP; fuentes quedan locales, sin commit ni deploy. Hardware pendiente se informa, no se declara aprobado |
| Regresión | Empeora un caso/guardrail según contrato | Rechazar; descartar solo parche propio con precondiciones verificadas; comprobar recuperación por hashes y tests. Si se mide recuperación, consume presupuesto y autorización adicional cuando se agota |
| Inconcluso | Datos válidos sin beneficio relevante, ruido no resuelto o revisión pendiente | No KEEP automático. Parar, registrar causa; descartar parche por defecto tras orden explícita. Nueva serie/hipótesis requiere presupuesto y aprobación |
| Inválido | Colección fallida, permiso/frontera rota o no comparabilidad | Guardar parciales/causa; no emitir «mejoró». Contener; si había parche, recuperación custodiada; corregir causa antes de nuevo intento explícito |

Rollback v1 por **archivo exacto de la iteración**, no `git checkout/reset/stash`. Antes de modificar: guardar bytes/mode/existencia del archivo actual (incluido trabajo previo) y Git index fingerprint; después: acreditar bytes exactos resultantes. Para descartar, tomar lock y comprobar que cada archivo sigue idéntico a su post-imagen, que las rutas no se volvieron symlinks y que no hay cambios ajenos/posteriores ni drift del índice. Restaurar la pre-imagen de esa iteración, no HEAD; borrar archivos nuevos solo si aún coinciden exactamente con lo creado. Preparar todas las comprobaciones antes de escribir; receipt por archivo y restauración atómica por archivo. Si falla a mitad, estado de recuperación incierto, conservar receipt y parar sin reintento ciego. Conflictos o concurrencia: ninguna restauración automática, pedir intervención. El lock de runners no impide editores humanos: reservar ventana exclusiva y reconocer que comparación de huellas no es aislamiento transaccional frente a un escritor no cooperante. Si no se puede reservar esa ventana, recuperación manual.

## 9. Estado privado y memoria

Raíz propuesta `~/.local/state/freeplast-lighthouse-dev/`, fuera de checkout/webroots, propietaria 0700, archivos 0600, sin ancestros symlink. No reutilizar `freeplast-local/` (contiene credenciales). IDs nuevos e inmutables, escrituras exclusivas/rename para manifests; no sobrescribir reportes.

Por serie: manifest, LHR JSON/HTML por auditoría, logs saneados, resumen Markdown/JSON, distribución de métricas y checksums. Por ciclo: baseline/candidato/calibración IDs, hipótesis, parche/pre/post-imágenes limitadas, versiones del evaluador, tests con alcance y resultado real, validez, decisión/aprobador, aprendizaje y próximo paso. Una interrupción deja `incomplete/uncertain`, nunca success ni autorreanudación.

No almacenar cookies, Authorization, passwords, valores de formularios, DB, pedidos, cotizaciones, correos capturados, hojas comerciales ni diff global. LHR puede incluir URL/DOM/screenshot: solo páginas públicas LOCAL y sin query sensible; sanear antes de persistir tanto JSON como HTML/logs. Si se detecta contenido sensible, detener colección y no persistirlo; registrar solo causa fija. No subir informes, servirlos por HTTP ni enviar a terceros. HTML/JSON corresponden al mismo LHR saneado; registrar versión de saneamiento y checksums. No guardar trazas extra por defecto.

Propuesta de retención: 30 días para runs sin referencia, conservar baseline vigente y ciclos referenciados hasta revisión del propietario. Tope inicial 2 GiB: preflight de espacio y abortar antes de excederlo; nunca borrar automáticamente para continuar. Listar tamaño/edad y limpieza manual explícita por IDs propios, futura y separada. No hace falta implementar GC en v1.

Antes de otra hipótesis, leer historial del mismo caso/mecanismo: una refutada solo vuelve con evidencia nueva o cambio de dominio. Registrar feedback humano atribuido separado de interpretación del agente. Lecciones útiles producen fixtures/reglas tras revisión separada; cambiar evaluador durante A/B invalida esa comparación y exige rebaseline. Sin entrenamiento de modelos: casos reservados sirven para comprobar generalización, no hay pipeline de aprendizaje automático.

## 10. Prerrequisitos, pruebas y presupuesto

Propietario: Mauricio (aprobación). Ejecutor/proponente: agente posterior que él designe, **no nombrado aún**. Evaluador numérico: comparador versionado + tests deterministas; aceptación funcional/visual: propietario. El autor del parche puede interpretar resultados pero no cambiar el evaluador durante comparación ni autoaprobar visuales.

| Preparación | Responsable | Dependencias | Entregable / salida | Estimación | Permiso pendiente |
|---|---|---|---|---|---|
| Resolver origen y ficha | Propietario | §1 y observaciones | Un origen y tres rutas aprobados | 5 min | Confirmación |
| Implementar runner/políticas | Ejecutor por designar | Diseño y referencias locales | CLI y tests offline revisables, sin deploy | 4–8 h | Escrituras acotadas |
| Cerrar toolchain exacto | Ejecutor + propietario si cambia propuesta | API/engines e integridad | Lock, versiones/binarios/config y compatibilidad verificada | 30–90 min | npm/install; navegador privado si se necesitara |
| Probar frontera y fallos | Ejecutor | Toolchain, fixtures sintéticos aislados | Bloqueos antes de red externa probados con Lighthouse real sobre fixtures | 1–2 h | Tests y servidores fixture efímeros propios |
| Acreditar identidad DEV | Ejecutor/operador | Origen confirmado; metadatos no sensibles | Runtime/procedencia y rutas autorizadas; sin secretos/BD | 15–30 min | Inspección local runtime acotada si hace falta |
| Piloto A/A | Runner; propietario revisa | Todos los anteriores | 36 auditorías completas o parada motivada | 20–90 min | Auditorías DEV y artefactos privados |
| Un parche posterior | Ejecutor y propietario | A/A estable, diagnóstico | Hipótesis aprobada, pruebas y B comparable | No estimable sin diagnóstico; fijar antes | Autorización posterior |

Los tiempos técnicos son estimaciones, no compromiso de disponibilidad. No hay coste de modelos ni APIs de medición; npm/navegador puede requerir red y disco autorizados. Ningún gasto monetario, infraestructura nueva permanente o tarea periódica está autorizado.

Pruebas futuras con `node --test`, independientes del gate comercial:

- Parseo CLI: help/version, flags desconocidos, códigos y cero efectos antes de validar.
- URL: staging, producción, alias, puerto, userinfo, IP/host inesperados, queries mutantes y encoding rechazados sin contactar destino. Redirect relativo permitido según política, externo/cross-port/loops rechazados antes del hop.
- Navegador fixture: 302/307, meta-refresh, JS, popup, frame y redirección tardía bajo los passes de Lighthouse; contadores en servidores **propios loopback sintéticos** prueban cero peticiones al destino denegado. Nunca probar rechazo contactando staging real.
- Métodos y endpoints: negar mutaciones/compra aun con GET; permitir recursos de lectura expresamente admitidos. LHR final correcto no basta si hubo intento externo.
- Exclusión mutua entre procesos y distintas carpetas de output; crash sin lock huérfano inseguro; solo cerrar hijos propios. Deadline mata solo árbol propio y conserva fallos.
- Fallos Lighthouse, salida 0 sin informe, runtimeError, JSON malformado, HTML faltante, métricas null/ausentes/NaN/negativas, scores fuera de rango y errores de audits exigibles.
- Medianas impares/pares, rango/MAD, baseline cero, igualdad de umbral, absolutos/relativos/ruido; nuevo fallo A11y aunque score redondeado no cambie; no promediar regresiones entre casos.
- Incompatibilidad de versión/config/caché/origen/estado; intervención declarada vs drift ajeno; n insuficiente y descartes retenidos.
- Preservación de árbol dirty/index/untracked; rollback a pre-imagen dirty, modificación posterior del mismo/otro archivo, symlink, alta/baja, fallo parcial de recuperación y rechazo ante conflicto.
- Tiempo/iteración/espacio/reintentos; interrupción y reanudación bloqueada; baseline no cambia tras regresión; consultas no mutan estado.
- Sanitización de secretos sintéticos en JSON/HTML/logs; permisos y symlinks del almacenamiento.
- Integración real contra **DEV aprobado**: A1/A2 completas y comparación A/A. Ejemplo negativo con fixtures acredita detección de regresión, no regresión real. Un B real necesita cambio mínimo aprobado; si no hay hipótesis justificable, no fabricar B ni beneficio.

Durante una intervención, usar tests offline pertinentes (PHP/JS de catálogo, accesibilidad, fotos/markup según archivos); gate amplio solo con `FREEPLAST_SKIP_STACK=1` y recursos ya presentes, sin bootstrap. No conducir checkout, crear solicitudes ni explorar panel privado. Cambios visuales siguen `wordpress/design/DECISIONS.md` y `wordpress/docs/journey-a-evidence/visual-matrix-procedure.md`: comparación de estados coincidentes, diferencias explícitas y aceptación humana; los flujos comerciales de esa matriz están fuera de este piloto. Teléfono físico/lector de pantalla: validación humana separada, nunca inferida de Lighthouse.

**Topes propuestos:** calibración 36 auditorías/90 min, cada serie 18/45 min, pausa 5 s, timeout 120 s/auditoría; una iteración y 90 min de reloj desde `cycle begin` (incluye espera/manual y B), con máximo 18 auditorías B. Si se agota, parar sin extender reloj al reiniciar. Estado fuera de plazo solo admite inspección y recuperación custodiada, no medición nueva. Un reintento técnico requiere autorización nueva y nuevo presupuesto; defecto 0 reintentos automáticos. No iniciar auditoría si su timeout excede tiempo restante.

Parar ante daño funcional/visual, seguridad dudosa, ambiente/árbol cambiante, ruido sin resolver, primer fallo de medición, conflicto de recuperación, presupuesto, falta de mejora o éxito del caso focal. El éxito lleva a mantenimiento manual, no a un daemon. No lograr mejora puede significar abandonar hipótesis; A/A inestable significa revisar instrumento, no culpar al sitio. Nunca «seguir hasta 100».

## 11. Handoff y criterio de cierre

**Diseño:** listo para revisión, no aprobado ni listo para ejecutar mediciones. Bloqueos exactos: origen/ficha sin confirmar, toolchain/guardia sin validar, ejecutor y presupuesto sin autorización; baseline no medida.

Primer paquete que se puede autorizar: construir únicamente `tools/lighthouse-dev/` y documentación, instalar dependencias fijadas allí, ejecutar fixtures/offline sin servicios compartidos y, tras gates de seguridad/identidad, A/A de las tres rutas acordadas. Entradas: decisiones §1, pins verificados, DEV ya disponible. Salidas: CLI/pruebas/documentación exacta, 36 JSON/HTML reales privados o evidencia de fallo, comparativa A/A, límites no validados y listado exacto de archivos modificados. No incluye optimizar tema, cambiar infraestructura, commit, push, staging, producción, deploy, cron ni publicación de artefactos. Cambiar `package.json` raíz solo si se aprueba añadir aliases; preferencia v1 sin tocarlo.

Posterior paquete separado: diagnóstico de A/A estable → una hipótesis/parche mínimo → tests → B → decisión con recuperación. No está autorizado por esta entrega. No se garantiza puntuación ni mejora.

**Inspeccionado:** instrucciones, configuración LOCAL, herramientas/paquetes/tests, existencia de pipelines, referencias Novadermis indicadas, docs visuales/release, listeners/Serve, versiones y cuatro recursos HTTP DEV. **No realizado:** auditorías, benchmarks/tests, instalaciones, navegador, arranques, consultas de BD/credenciales, cambios de aplicación, staging/producción o agentes auxiliares. Único archivo creado en esta etapa: este plan.

### Fuentes técnicas consultadas

Documentación primaria consultada el 2026-10-16; al fijar toolchain verificar las mismas APIs en su tag exacto, no depender de `main`:

- [Variabilidad Lighthouse](https://github.com/GoogleChrome/lighthouse/blob/main/docs/variability.md): múltiples corridas, contención y evitar concurrencia; no adopta automáticamente nuestro n=3 ni umbrales.
- [API programática Lighthouse](https://github.com/GoogleChrome/lighthouse/blob/main/docs/readme.md): navegador externo propio, reportes y flags CLI que no lanzan Chrome desde Node.
- [Throttling](https://github.com/GoogleChrome/lighthouse/blob/main/docs/throttling.md): referencia para cerrar configuración efectiva del tag elegido.
- [Intercepción Puppeteer](https://pptr.dev/api/puppeteer.page.setrequestinterception): solicitudes detenidas antes de continuar; no prueba por sí sola cobertura multi-target o compatibilidad con Lighthouse.
- [TOON](https://toonformat.dev/reference/spec.html): formato de salida AXI; internos/artefactos conservan JSON.

Contrato de planificación revisado: identidad/permisos, evidencia y prerrequisitos, evaluación, comparabilidad, ciclo, decisiones/recuperación, gobierno/topes, memoria y handoff cubiertos. No aplica inferencia estadística formal (n=3 sin garantía), entrenamiento de modelos, despliegue, ni rollback de BD (excluidos). Baseline y resultados reales siguen pendientes, no simulados.
