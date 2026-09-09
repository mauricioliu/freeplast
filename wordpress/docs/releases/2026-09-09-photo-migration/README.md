# Integración DATA de fotos y navegación — 2026-09-09

## Autorización y alcance

Después de implementar localmente la tanda, se pidió: «¿Confirmas continuar con
una publicación de nivel DATA —modifica imágenes del catálogo— en
freeplast.mliu.site, sin tocar producción?». El responsable respondió literalmente
**«confirmo»**. Autoriza DATA solo en **https://freeplast.mliu.site**; no autoriza
contacto ni publicación en `freeplast.cl`.

Integración, gate completo y certificación final del driver **verdes**.
`self-test.txt` registra cinco releases y recuperaciones; `certification.txt`
identifica las fuentes exactas certificadas. El registro generado por la
ceremonia se enlazará aquí después de finalizar. **Esta preparación por sí sola
no es prueba de publicación.**

## Implementación

- Tema **1.0.17**: Nosotros presente en PC y móvil; mismos destinos, orden y
  etiqueta Cómo cotizar; separación adaptable de enlaces y caption nativo.
- Publica también los pulidos 1.0.16 aún no desplegados: resumen compacto,
  aclaraciones sin repetición y avisos contextuales coherentes.
- 17 fotos nativas: 15 recortes del PDF y dos fuentes anteriores. Cuatro
  Universal distintas; Paltera/Romano dejan de ser placeholders. Encuadres
  centrados cuadrados, sin ampliar ni inventar detalles; marcas conservadas.
- `package-woo.py` prepara una clausura separada de medios y scripts; el driver
  la sella junto a los ZIPs. No hay migración en init ni un mapa de imágenes
  paralelo al catálogo de WooCommerce.
- `migrate-catalog-photos-release.php`: capture read-only antes del backup;
  apply/verify con el mismo baseline por stdin, identidad de staging y guard
  propietario. Las fotos se importan en trial y en instalación con idéntico
  código; asignación y thumbnails son nativos.
- `record-state.php` conserva el digest original de sus seis tablas mediante
  [el delta esperado de ADR-0003](../../../../docs/adr/0003-photo-migration-expected-delta.md).
  Solo se proyectan los adjuntos nuevos exactos y sus punteros autorizados.
  Datos previos, imágenes originales, solicitudes y cambios no autorizados en
  esas tablas siguen protegidos. `pre == post` significa este invariante,
  **no ausencia de escrituras de medios**. No se reemplaza el baseline.
- El importador individual sigue siendo local-only. El punto de entrada del
  release exige el baseline y guard de la ceremonia; no sirve de bypass.

## Driver global

`~/.agents/skills/wp-release/` incorpora migraciones selladas, baseline inmutable
incluido en hashes del backup, transporte stdin en ensayo/instalación/fingerprint,
verificación de fingerprint del restore y del trial en ambos backends,
reintento con snapshot idéntico y recuperación paired-only para migraciones.
El cierre de archivos rechaza symlinks/rutas fuera del repo y copia/hash de
subdirectorios también en targets locales.

Se detectó en un preflight **de solo lectura** que la CLI de staging está bajo
un perfil Compose. La inspección de aislamiento ahora expande todos los perfiles
sin arrancarlos; el fixture incluye el mismo perfil `tools` para ejercitarlo.
No se modificó el compose ni el entorno del servidor para esquivar ese control.

`skill.patch` conserva el delta global respecto de la versión endurecida previa,
excluyendo el recibo machine-local. El hardening anterior y su evidencia se
conservaron; no se mezclan con esta integración.

## Evidencia previa a publicación

- `gate-preflight.txt`: gate completo verde, sin saltar el stack. **795 checks
  agregados**, incluidos **181 HTTP** sobre fixture desechable; más las suites
  PHP/JS detalladas. El listener de prueba terminó con el harness.
- `native-final.txt`: **99** comprobaciones nativas de medios/delta + **7** del
  hook capture/apply/verify/no-op/guard/identidad + **5** de la CLI.
- El delta también rechaza cambios de precios, metadatos ajenos, solicitudes
  nuevas y alteraciones de adjuntos originales/nuevos. Se corrigió una pérdida
  de escapes Unicode de la procedencia JSON al pasar por la API de metadatos
  de WP; prueba de los 17 adjuntos agregada.
- `staging-readonly-preflight.json`: home correcto, **17/17** fuentes actuales
  coinciden con los hashes permitidos, sin conflictos de fotografías editadas
  por el comerciante. HPOS desactivado y sin overrides de templates/parts.
- Preflight HTTP de lectura: Inicio, Tienda, Cotización y CSS devolvieron 200.
- Certificación del driver: cinco releases (surface/local, logic/local,
  logic/on-host, data/local, data/on-host); trial fallido sin tocar target,
  fallo de migración live con 503 y resume autorizado, recuperación pareada
  real de datos/archivos y code-only de releases sin migración. La salida final
  y su hash de certificación están registrados antes de ejecutar el release real.
  Regresiones rápidas: 7 de clasificación, 16 de CLI y 26 de seguridad.
  Hash certificado: `afe4a3e247bc32ef7eaac041205da8d36c61d8c4ec1f05c4a373d21ec8b2573e`.

Los contenedores, usuarios/solicitudes y fallos inducidos pertenecen exclusivamente
a fixtures. No se envían formularios ni correos reales para validar la publicación.

## Límites y material pendiente

No aceptación visual ni hardware: no adb, teléfono ni revisión humana de PC.
Los HTTP/DOM y hashes solo son comprobaciones mecánicas. Las fotos del PDF siguen
siendo referenciales y tienen marca de agua; las dos Universal Color muestran
rojo, no fotografías de blanco/amarillo/azul/verde. Faltan esos originales y
fuentes de mayor resolución de Pediluvio/Ladrillo.

La clausura de migración permanece explícita en el config. Retirarla requiere
mantener la dependencia del módulo de fingerprint en futuros bundles y probar
la transición; no borrar la clausura conservando un hook que la requiere.
