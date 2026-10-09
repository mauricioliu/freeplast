# Cotizaciones y mantenedores: navegación compartida

## Implementación

- Cotizaciones es el menú raíz para cuentas con permiso de cotizaciones. Mantenedores, Precios y Ventas dependen de él.
- La cuenta exclusivamente de datos mantiene su entrada propia (`fpw-data`) sin acceso a solicitudes ni borradores.
- `workspace-chrome.php` centraliza la selección de superficies autorizadas, banner y navegación. Corrección solicitada: se elimina el bloque lateral duplicado; arriba quedan Cotizaciones y un único desplegable Mantenedores con Precios y Ventas Históricas.
- Los callbacks de datos, precios y ventas componen `fpw_workspace_shell`; conservan URLs, campos y procesamiento POST existente.
- El mismo CSS se carga únicamente en superficies autorizadas. Oculta el chrome de WordPress y conserva los mensajes de los formularios dentro de la app.
- Navegación nativa con `<details>/<summary>` operable por clic y teclado incluso sin JavaScript; mejora para hover exclusivo de mouse, clic que fija el menú abierto, Escape y clic exterior para cerrar. Sección activa con `aria-current`, trigger del grupo activo y accesos de 44 px. Dropdown alineado para caber en móvil.
- No cambios en `quotation-access.php`, capacidades, roles, allowlists, precios, borradores ni importaciones. No despliegue ni commit.

## Pruebas

- `FREEPLAST_SKIP_STACK=1 npm test`: PASS. 54 nuevos checks de chrome; regresiones de precios, ventas, permisos y borradores verdes.
- `git diff --check`: PASS.
- `npm test`: checks offline verdes; gate HTTP bloqueado porque `http://mliu:8091` ya responde HTTP 200 y el harness detecta servidor ajeno. No se detuvo ni modificó ese servicio.

## Cierre del paso 7

- Suite completa ejecutada en copia aislada bajo `/tmp/freeplast-navigation-*`, con base SQLite propia y correo contenido. `FREEPLAST_TEST_URL=http://127.0.0.1:8097 npm test`: PASS, 742 comprobaciones HTTP nativas; 2639 checks reportados por el runner.
- La primera ejecución nativa detectó un defecto de registro: los submenús se registraban antes del padre y WordPress derivaba hooks incompatibles. Corregido con menú de cotizaciones a prioridad 10, hub a 20 y precios/ventas a 30. Nuevas regresiones HTTP prueban callbacks, formularios/nonces, banner, navegación y permisos por rol.
- Revisión Chrome de cotizaciones, hub, precios y ventas a 1440×1000 y 390×844: un banner por pantalla, destino activo correcto, controles de navegación de 44 px, sin desbordamiento horizontal de página, sidebar/barra/pie WordPress invisibles.
- Se detectó el aviso de telemetría de Quotes for WooCommerce insertado dentro de `.wrap` por JavaScript. Se oculta `.qwc-message` solamente dentro del workspace; las notificaciones propias de guardado e importación siguen visibles.
- Navegación real pulsando enlaces: precios → ventas históricas → cotizaciones, manteniendo banner y formularios.
- Capturas iniciales/finales y métricas: `.scratch/workspace-navigation-validation/`. Gate final: `native-suite-final.log` en ese directorio.
- Se apagó el servidor de revisión 8097; el harness confirma apagado de sus propios workers. Servidor ajeno 8091 intacto. Ningún despliegue o cambio remoto.

## Corrección de duplicidad (pedido posterior)

- Eliminados links Precios/Ventas como opciones de primer nivel y bloque lateral de mantenedores.
- Unit tests: dropdown colapsado inicial, mouse/touch, hover salida, clic fija apertura, Escape/foco y clic exterior. Regresiones HTTP: una sola navegación, exactamente dos opciones colapsadas y permisos intactos.
- Suite final en 8097 PASS: 744 checks HTTP nativos, 2649 checks reportados por runner. Una ejecución anterior falló en `core_note_edit_allowed_when_guard_removed`, control positivo ajeno al menú; repetición completa desde base limpia verde, sin parchear ese test.
- Chrome 1440×1000 y 390×844: hover real abre opciones, click fija, Escape cierra y enfoca summary; móvil click abre, ancho de página 390 sin overflow. Capturas corregidas en `.scratch/workspace-navigation-preview/dropdown-*.png` y preview actualizado sin imágenes anteriores.
- Logs: `.scratch/workspace-navigation-validation/dropdown-native-final.log`.

Validación móvil emulada, no hardware físico. Sin despliegue. Paso 7 completado para el alcance local.

Archivos XLSX no rastreados del usuario intactos.
