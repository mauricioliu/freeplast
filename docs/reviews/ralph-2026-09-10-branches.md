# Orden de ramas y publicación — 10 septiembre 2026

Pedido del dueño: «commit y push, realiza un orden de las ramas que puedan estar sueltas». Se publicaron los commits existentes de `main` y se respaldaron las ramas pendientes antes de eliminar referencias locales integradas. **No se integraron #53/#56/#61 ni se cerraron issues. No hubo deploy.**

## Ramas que se conservan

Todas tienen `origin/<rama>` configurado como upstream y se verificó igualdad de SHA local/remoto.

| Rama | SHA conservado | Uso / siguiente paso |
|---|---|---|
| `main` | `5bd471d` antes del commit documental/workflow de esta limpieza | Base integrada. Los 26 commits antes pendientes de publicación ya están en GitHub. |
| `ralph/issue-53` | `64559b472e83f580bff6ad7ed6875472ca19b906` | Implementación CSV propuesta; pendiente de muestra/formato y mapeo aprobados. No integrar. |
| `ralph/issue-56` | `ef1ec98a85857b934925f4291f0b9b381f0b7519` | Implementación con revisión rechazada; corregir los fallos reproducidos antes de nueva revisión. |
| `ralph/issue-61` | `8b9f6a37472573e9fc2e410cb5389febd6073485` | Regla de despacho propuesta; pendiente de fletes históricos y contrato/calibración aprobados. No integrar. |
| `prototype/quote-journey-20260908` | `785e65b502078b67e29bffbd2a7beab8ae408aac` | Prototipo exploratorio del recorrido público. Preservado, no fusionado con código productivo. |
| `prototype/owner-request-ui-2026-09-09` | `f7e8da00d8ddee2892ff6bc3354275dbc001a4e7` | Archivo de alternativas de UI del dueño y preferencia humana por A. No equivale a aprobar lógica comercial ni deploy. |

Se mantienen los worktrees de los dos prototipos. Los nombres `ralph/issue-N` pendientes se conservan porque el workflow los usa para recuperar commits, no para empezar de cero. Su existencia remota es respaldo, **no aprobación de merge**. Ver [revisión de recuperación](ralph-2026-09-10-recovery.md).

## Limpieza efectuada

- **41 ramas locales eliminadas**, exclusivamente después de comprobar `git merge-base --is-ancestor <rama> origin/main`.
- No se borraron ramas remotas: las 41 referencias obsoletas eran locales.
- Retirados los worktrees `ralph-issue-28` y `ralph-issue-33`. No tenían cambios de código; solo `.pyc` no versionados. Se respaldó cada árbol completo, incluidos archivos ignorados, antes de retirarlo.
- En el repo Ralph se retiraron el worktree temporal de publicación y su rama `fix/pi-error-and-review-recovery`, una vez que su commit estaba en `origin/main`.

### Puntas de las ramas locales retiradas

Los commits siguen alcanzables desde `main` publicado. Se puede reconstruir una referencia con `git branch ralph/issue-N <SHA>`.

| Ticket | SHA | Ticket | SHA |
|---|---|---|---|
| 1 | `6988d24` | 2 | `a8c0325` |
| 3 | `4fe0fd8` | 4 | `96f0127` |
| 5 | `f4e4b14` | 6 | `3217ebd` |
| 7 | `07eaabd` | 8 | `ad6117f` |
| 9 | `cc0534a` | 10 | `099e781` |
| 11 | `d5e7f65` | 12 | `3c1851e` |
| 13 | `afd7473` | 14 | `f0192cd` |
| 15 | `f62e5d8` | 16 | `cd2511c` |
| 17 | `c72551f` | 18 | `22eb61c` |
| 19 | `74c2ff3` | 20 | `ac0cf8a` |
| 21 | `c4be5d0` | 22 | `03a9e12` |
| 23 | `963fe1b` | 24 | `8139f60` |
| 25 | `3b7e15d` | 26 | `aafe26e` |
| 27 | `33bbd3c` | 28 | `8722c4c` |
| 29 | `0b1f786` | 30 | `81013ce` |
| 31 | `5ae2f46` | 32 | `ba6f5ca` |
| 33 | `dc2d813` | 34 | `cb55816` |
| 50 | `21f9928` | 51 | `13361f6` |
| 52 | `83417ed` | 54 | `6e66b00` |
| 55 | `68e6827` | 59 | `02742aa` |
| 60 | `3b9350b` | — | — |

## Respaldo local previo a la limpieza

Directorio privado, fuera del repo y **no subido**:

`~/.local/state/freeplast/git-cleanup/20260910T143023Z/`

- `branches-before.bundle`: ramas y tags antes de la limpieza; `git bundle verify` correcto.
- `refs-before.txt`: referencias originales con SHA completo.
- `removed-branches.tsv`: las 41 referencias retiradas con SHA completo.
- `retired-worktrees.tgz`: árboles completos de los dos worktrees retirados. Puede contener configuración local; mantener privado y no adjuntarlo a GitHub.

## Publicación de la corrección de Ralph

Repo `mauricioliu/ralph`, commit **`01e2f01bf39a0fe2ab4bfa2e8331a26ab0ee7834`** en `main`:

- Solo la corrección Pi/completion, workflow con revisión, regresiones, documentación y changesets propios.
- Construido y probado en worktree limpio: **1452 tests / 52 archivos**, typecheck y build correctos. Los 1464 de la sesión anterior incluían pruebas del WIP previo, deliberadamente excluido de la publicación.
- `lint-staged` ejecutado en el commit.
- Cambios ajenos de credenciales, defaults/modelos y demás WIP permanecen locales y sin publicar. Al avanzar el `main` de trabajo, 32 archivos se comprobaron byte a byte sin cambios.
- El push a `main` activa los workflows propios de GitHub; no se ordenó un release npm ni deploy del sitio.

En Freeplast se versionan únicamente el diagnóstico, revisión/reproductor, este inventario y los cuatro archivos fuente modificados del workflow. Los demás archivos de trabajo ajenos y `.ralph/.env`, logs, recibos de estado y configuración privada quedan fuera del commit.
