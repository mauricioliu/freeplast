# Catálogo PDF 2026: entrega local para revisión

**Estado al entregar el worker: implementado localmente, cambios sin commit y sin despliegue. Staging NO entregado ni modificado por el worker.** La integración, commits y gates posteriores del lead se registran por separado; este documento no certifica su estado. Producción intacta. Woo sigue siendo autoridad diaria; JSON es referencia histórica enriquecida, no sincronizador.

## Entregado

- [15 fichas transcritas](transcription.md), páginas 6–20, con fuente por fila y ausencias explícitas.
- [Comparación y conflictos](comparison.md), conservando 17 identidades existentes (15 en PDF + 2 fuera), URLs, fotos, opciones, categorías y cantidades comerciales.
- [Tabla por producto](changes.md): antes → PDF, campos nuevos, pendientes.
- `wordpress/data/products.json`: medidas/pesos/usos corregidos, capacidades conocidas, interior, color, características y pallet; fuente por campo, descripciones técnicas y notas actualizadas.
- Ficha nativa: filas opcionales solo cuando conocidas dentro de «Ficha técnica completa»; resumen de cuatro campos, galería, formulario y variaciones nativas sin cambios.
- Herramienta manual de plan/aplicación sellada y [runbook staging](staging-runbook.md). Plan con diff y snapshot privado, token contra drift, identidad exacta, campos permitidos, guardas y transacción; no ejecutada sobre WordPress real.

## Decisiones humanas pendientes

1. G2 (resumen p.4) frente a Romano (ficha p.17): no se declara equivalencia ni se renombra.
2. PDF clasifica Tote, cuatro Universal y tres traversas en Agrícola; referencia mantiene Otros hasta aprobación.
3. Tote interior sin unidad impresa; se muestra explícitamente esa ausencia. Exterior mm conservado de referencia anterior.
4. Frutera conserva 44 l con procedencia del resumen p.3, ausentes de ficha p.14. Paltera conserva 30 l literales p.15, sin ajuste geométrico.
5. Capacidades faltantes no se deducen. Romano mantiene peso y tamaño de pallet desconocidos. En futura aplicación, ausencias PDF no borran valores Woo existentes.

Cambios numéricos destacados: Universal ancho 445 → 444 mm; Pollera alto 170 → 180 mm; G1 2500 → 2520 g; UPC 2020 → 2050 g. Detalle prima sobre resumen/web. Los pallets son embalaje, jamás mínimos de solicitud.

## Verificación realizada

`FREEPLAST_SKIP_STACK=1 npm test`: **verde**, ahora 1943 checks agregados (1939 previos + 4 de la nueva regresión offline de preflight del harness), suites existentes incluidas. Nuevas verificaciones: 15 fichas/datos/procedencias y hash de 17 identidades protegidas, 23 checks de frontera del patch, ficha PHP ampliada a 48 checks. [Salida completa](offline-tests.txt). `git diff --check` limpio. Dependencias locales reutilizadas: node_modules/php por symlink y copia privada de `.build`; sin instalación de red ni escrituras sobre checkout principal.

Pruebas de datos verifican pesos, capacidad, dimensiones exteriores/interiores, material, características, colores, pallet y procedencia. Hash protegido comprueba IDs fuente, URLs, slugs, títulos, fotos, categorías, opciones, destacados, relaciones y mínimos/pasos; dos productos fuera del PDF íntegros. Pruebas de patch: plan sin guardar, token inválido/obsoleto, identidad duplicada, producción rechazada, maintenance, atributos desconocidos/variaciones preservados, aplicación e idempotencia con dobles nativos. **No equivalen a persistencia MariaDB ni rollback real.**

## Bloqueado/no verificado

**Actualización del lead (2026-10-13, final): gate nativo completo VERDE** — `FREEPLAST_TEST_URL=http://127.0.0.1:18091 npm test` pasó **2691 checks (752 real-stack)** incluida la matriz ventas guarded. Log: `~/.herd/plan-mode/plan-mv6vrtm2-rsaa.worktree-full-gate.log`; lectura de instrumentación: `plan-mv6vrtm2-rsaa.instrumentation-result.json` (lead, con MU/opción limpiados). La evidencia roja histórica se conserva en [native-gate-blocker.md](native-gate-blocker.md); la causa del fallo original NO quedó probada concluyentemente (hipótesis de resto `fpw-stack-guard-off.php` de una corrida muerta por timeout sigue plausible-sin-confirmar; el preflight del harness ahora aborta explícitamente si reaparece, con regresión offline). Pasos 6–8 del plan siguen sin entrega staging real: la herramienta/runbook locales están listos; la aplicación externa queda para asignación acotada separada.

- Gate completo `npm test`: inicia stack HTTP desechable, prohibido por alcance worker; el worker no lo ejecutó. El lead informó un intento fallido previo al stack: faltaba cache y la descarga de SQLite fue rechazada correctamente por checksum. Preparación posterior local: seis artefactos de `.tools/cache` verificados contra los pins existentes; se copiaron solo los tres faltantes desde el cache principal, preservando todos los archivos previos (incluido `.part` rechazado). Sin descargas ni cambios de hashes. Cache listo para nuevo gate del lead; resultado pendiente de su registro separado.
- Native trial MariaDB, hook DATA/delta sellado de release, respaldo/restauración reales y aplicación staging pendientes. No se modifica `wp-release.json` para simular preparación completa del release.
- Sin servidor, browser, dispositivo, aceptación visual/móvil ni solicitud staging. Variaciones y checkout solo cubiertos por suites locales existentes; no flujo real nuevo.
- Sin producción, publicación, pagos, correos ni Maps/AGY. No se cambia cache/version de assets: no cambiaron CSS/JS; versionado de release corresponde al coordinador.

**Siguiente paso:** revisión de datos y del código por lead; después, autorización específica para trial/gate y ceremonia staging. Aprobación de producción sigue siendo separada. Riesgo principal: un diff nativo puede revelar ediciones Woo posteriores que deben revisarse antes de reemplazar descripciones/atributos; nunca aplicar la referencia ciegamente.
