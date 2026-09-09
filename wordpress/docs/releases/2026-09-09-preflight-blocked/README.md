# Preflight bloqueado — NO es un release completado

## Autorización y alcance

El responsable pidió literalmente **«commit, push y deploy»** e indicó usar
`wp-release`; después confirmó **«confirmo»** al tier **surface** propuesto para
**https://freeplast.mliu.site**. No autoriza tocar `freeplast.cl` ni omitir la
ceremonia de ADR-0002.

La implementación del tema 1.0.16 está lista para Git. **No se ejecutó `run`
contra la configuración real**: ningún transfer, backup, install, smoke ni
rollback de staging. `status` informa `no releases yet` en este driver. El último
release desplegado conocido sigue siendo 1.0.15 (`20260908T231752Z`); no se hizo
una consulta remota nueva para afirmar su estado actual.

## Gate completo: verde, sin saltar el stack

`npm test` pasó con **181 checks de stack real** y **639 checks agregados**, más
las suites PHP/JS detalladas en `gate.txt`. Es un stack local desechable,
no el sitio público. Se detuvo su listener al finalizar el harness.

Se corrigieron aserciones obsoletas del harness, no la búsqueda del runtime:

- El regex esperaba que `product` fuese la primera clase. En el DOM capturado
  real había 17 tarjetas y el regex contaba cero por empezar con `product-card`.
  Ahora un parser cuenta tokens de clase en `ul.products > li.product`.
- El estado vacío tiene el markup y texto del override actual; se comprueba
  cero tarjetas y el encabezado que incluye la búsqueda sin resultados.
- Los conteos de categorías se contrastan con `get_term_by(...)->count` del
  WordPress de prueba; ya no se espera arbitrariamente que empiecen en «1».
- `$` dentro de JS/CSS no es un precio público. Se inspecciona texto del DOM
  excluyendo script/style/template/hidden; una cantidad visible sigue fallando.
- Se actualiza la expectativa del script de campos a su versión ya existente
  **1.0.4**; el adaptador no se modificó.

`catalog-markup-test.mjs` añade siete regresiones: ambas posiciones de clase,
atributos/quoting, nombres parciales, bucle realmente vacío, identificador `$`
en script y moneda realmente presente. No se rebajó el umbral de búsqueda.

## Configuración y clasificador

`wp-release.json` tenía rutas sin el prefijo `wordpress/` para tiers y el
archivo a comparar en smoke; corregidas según la raíz real del repo.

El clasificador global también recortaba tres caracteres de rutas que no
contenían columnas de estado y reescribía los `*` de sus propias expresiones
regulares. Se corrigió usando rutas NUL de git, incluyendo archivos sin seguimiento,
y un parser de glob que no reprocesa su salida. Los patrones superpuestos toman
el tier más alto. `plan-test.mjs` del skill pasa siete casos con CLI/repositorios
temporales; el plan de Freeplast propone **SURFACE**, con ocho archivos del tema,
cero de lógica y cero de datos.

El self-test existente del skill pasó sus tres releases sobre fixture Docker
(surface/local, logic/local y logic/on-host); limpió sus propios recursos.
Su limpieza inicial fue endurecida: ahora rechaza proyectos existentes en vez
de eliminar volúmenes de otras ejecuciones. Estos cambios del skill son locales
al directorio global, **no están versionados por el repo Freeplast**.

## Bloqueos de seguridad reproducidos

El self-test anterior no prueba rollback ni la rama de fallo de instalación.
La sonda local `safety-probe.mjs` ejecuta código real del generador/chain contra
un runner WP falso en un directorio temporal; no usa Docker, SSH ni red.
Resultado guardado en `safety-probe.json` (**tres fallos**, exit 1):

1. **Rollback code-only:** el generador declara `WP=(...)` pero invoca `$WP`
   en vez del array completo. Con un runner compuesto se pierde todo salvo el
   primer token. La prueba falla antes de llegar al install falso:
   `bash: theme: No such file or directory`.
2. **Rollback paired incompleto:** imprime que la restauración de archivos es
   específica del host, no la ejecuta, y aun así termina con «paired rollback
   complete». No constituye recuperación válida.
3. **Fallo de install reabre el sitio:** el trap dice «maintenance retained»
   pero ejecuta `maintenance-mode deactivate`. La sonda induce un fallo 42
   y comprueba que el indicador de mantenimiento efectivamente desaparece.

Además, la instalación normal desactiva mantenimiento antes de la verificación
posterior de archivos/estado/fingerprint. Esto necesita revisión al endurecer
el manejo de fallos; no se ensayó sobre el target real.

### Reproducir la sonda

```bash
node wordpress/docs/releases/2026-09-09-preflight-blocked/safety-probe.mjs
```

Puede apuntarse a otra copia con `WP_RELEASE_SCRIPT_DIR`. El test paired es una
inspección del script generado buscando el placeholder; las otras dos pruebas
sí ejecutan sus ramas contra el runner falso. No son ensayos de restore reales.

## Continuación requerida

**No publicar con esta versión del driver.** Primero corregir y probar el
rollback completo y mantenimiento/fail-closed, conservando el contrato. Añadir
regresiones de esas ramas al self-test, no conformarse con sus tres caminos
exitosos. Después ejecutar la ceremonia íntegra sobre staging con el tier
confirmado y la autorización de esta sesión (o pedir una nueva si es otra sesión).

Los defectos del driver **no se arreglaron mediante scripts ad hoc**, ni se
omitió rollback/rehearsal para terminar el deploy. No hay respaldo nuevo ni
release id real que se pueda citar como prueba de publicación.
