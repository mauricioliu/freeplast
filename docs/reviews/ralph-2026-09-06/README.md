# Revisión de la última tanda de Ralph — 2026-09-06

**Resultado: hay correcciones pendientes antes de considerar terminados los tickets.** Revisión del código y pruebas offline; sin despliegue, servidor de desarrollo, navegador, dispositivo ni mutaciones en staging/producción.

## Alcance y punto fijo

- Última tanda: 2026-09-05, tickets **#24–#30 y #1**. El planner terminó con `issues: []` a las 20:55:56 UTC (`.ralph/logs/main-planner.log`).
- Punto fijo anterior a la tanda: `90d83f99ee2ee0c6c67aef51f8fde7325f86f6a6`. HEAD revisado: `eb69ec7`.
- Comparación: `git diff 90d83f9...eb69ec7`; 28 commits incluyendo merges, documentación y la migración a Woo intercalada. Para distinguir lo que Ralph agregó al stack ya migrado: `git diff 3e4edb2...eb69ec7`.
- #24 y #27 primero aterrizaron en `wordpress/legacy/`; #1 después agregó los ports Woo. Los hallazgos funcionales de este informe corresponden al código **activo**, no al legado.
- Se consultaron los cuerpos completos de #1 y #24–#30 en GitHub, ADR-0001, CONTEXT.md, BUILD-DECISIONS.md (entradas de esta tanda), WOO-MIGRATION.md y los tests/fuentes Woo 11.1.0 + Quotes 2.13 disponibles localmente.
- `git ls-remote origin refs/heads/main` todavía devuelve **90d83f9**. Los merges están en main local, no en GitHub. Todos esos tickets figuran cerrados. WOO-MIGRATION.md registra despliegue pendiente de adapter 1.3.0 / theme 1.0.5; **no se comprobó el estado remoto de staging en esta revisión**.

## Standards — calidad de las pruebas

### ST-01 · P2 · Las pruebas de denegación pueden pasar por nonce inválido, no por permisos

**Ubicación:** `wordpress/scripts/verify-ventas-role.py:130–137`.

La prueba de borrado pide la ruta `action=trash` sin nonce; la de cotización con precios manda literalmente `security_nonce: 'x'`. Las dos fallan también para un usuario autorizado por protección CSRF. Por tanto, seguirían verdes si una regresión concediera los permisos prohibidos. La protección actual de Quotes sí comprueba `manage_woocommerce`; el hallazgo es la falsa garantía de su test, no un bypass demostrado de esa acción.

**Regla:** #1, Testing Decisions: «Tests should assert externally observable behavior rather than internal class structure or private helper calls» y cobertura de autorización/nonce. #25 exige comprobar las denegaciones en las acciones directas y la ausencia de elevación de privilegios. Una prueba de nonce inválido no demuestra la denegación por rol.

**Corrección sugerida:** separar CSRF de autorización; ejecutar solicitudes válidas para el actor restringido y probar que retirar el control de capacidades hace fallar la regresión. No ejecutar pruebas potencialmente destructivas sobre historial existente.

`.ralph/CODING_STANDARDS.md` sólo contiene ejemplos comentados. No se informan infracciones ficticias de esas reglas ni preferencias de estilo como bloqueantes.

## Spec — comportamiento frente a los tickets

### SP-01 · P1 · Una nueva solicitud idéntica se fusiona con la anterior

**Ubicación:** `wordpress/wp-content/plugins/freeplast-woo/freeplast-woo.php:352–360,448–451`.

**Criterio #24:** «Una nueva solicitud legítima después de completar la anterior sigue siendo posible, incluso con productos y datos iguales; no se deduplica indefinidamente por contenido o identidad del cliente».

El identificador sólo combina customer ID de sesión, hash del carrito y campos; no contiene una identidad de intento que rote al completar. El vínculo `fpw_attempt_<hash>` es permanente. Si se reconstruye el mismo carrito y se envían los mismos datos mientras se conserva la sesión, se devuelve el pedido anterior y se suprime la nueva notificación. El gateway además vacía el carrito de ese nuevo envío. Esto pierde una solicitud legítima bajo apariencia de éxito.

**Reproducción offline:** usando las funciones reales del adapter y los dobles en memoria del proyecto, finalizar el pedido sintético 12345, vaciar/reconstruir el mismo carrito y calcular el siguiente intento produjo:

```json
{"same_hash":true,"result":{"state":"recovered","order_id":12345}}
```

Woo `WC_Cart::get_cart_hash()` es derivado del contenido, no de un intento, y vaciar el carrito no rota por sí mismo el customer ID. Prueba de lógica, no recorrido HTTP nuevo.

**Corrección sugerida:** separar identidad de intento de contenido, con vigencia y rotación al completar; cubrir mismo navegador, productos/opciones/cantidades/datos iguales y referencia nueva.

### SP-02 · P1 · Una respuesta perdida sigue dejando al cliente sin recuperar la confirmación

**Ubicación:** `wordpress/wp-content/plugins/freeplast-woo/freeplast-woo.php:518–534`; test que consolida el comportamiento incorrecto en `wordpress/scripts/woo-checkout-race.py:177–179`.

**Criterio #24:** «Un reintento autorizado del mismo intento, antes de visitar la confirmación [...] recupera la solicitud original [...] Se comprueba la recuperación cuando la primera respuesta no llega al cliente».

El hook de recuperación es `woocommerce_create_order`. En el Woo fijado, `WC_Checkout::process_checkout()` rechaza antes el carrito vacío (fuente local, líneas 1372–1373). Quotes vacía el carrito al procesar el primer envío. Por ello un reintento posterior con la respuesta original perdida no alcanza este hook: recibe el error de sesión caducada. El test **exige** `replay_rejected == failure`, lo opuesto al criterio.

**Evidencia:** orden de ejecución de las fuentes Woo/Quotes y aserción del test; no se volvió a enviar un checkout HTTP en esta revisión.

**Corrección sugerida:** recuperación autorizada antes de ese rechazo, vinculada al intento original y conservando nonce/sesión. La regresión debe perder deliberadamente la primera respuesta y exigir la misma referencia/confirmación al reintentar, sin visitar previamente la confirmación.

### SP-03 · P1 · Ventas puede editar solicitudes y cambiar estados comerciales

**Ubicación:** `wordpress/wp-content/plugins/freeplast-woo/freeplast-woo.php:187–193` y guardias del rol en el mismo archivo.

**Criterio #25:** «No se incluyen edición de datos del cliente o transiciones comerciales en este alcance».

Se conceden `edit_shop_orders` y `edit_others_shop_orders` para abrir el editor nativo, pero no se bloquea el guardado general. Las guardias añadidas cubren notas privadas y reenvíos de correo, no edición de datos/estado. Woo acepta el guardado con `edit_post`, y su metabox guarda campos de contacto y ejecuta `set_status()` desde el POST. WOO-MIGRATION.md reconoce explícitamente que esos cambios quedan sin restringir; documentarlo no sustituye una aprobación del cambio de alcance.

**Evidencia:** código de capacidades, guardias y fuentes nativas `class-wc-admin-meta-boxes.php:215–262` / `class-wc-meta-box-order-data.php:858,941`. No se operó con cuentas reales ni se modificaron pedidos.

**Corrección sugerida:** limitar en servidor las mutaciones del rol a notas privadas, conservando consulta nativa, o pedir aprobación explícita para ampliar su alcance antes de cerrar el ticket. Probar guardados válidos de contacto y estado con el rol restringido y exigir ausencia de cambios.

### SP-04 · P2 · Se permite avanzar durante una actualización de cantidad todavía en vuelo

**Ubicación:** `wordpress/wp-content/themes/freeplast/assets/js/cart-quantity-feedback.js:147–157`.

**Criterio #26:** «El estado pendiente no permite avanzar con una cantidad todavía sin confirmar».

El código ya reconoce que al abortar una actualización Woo puede limpiar la bandera pending mientras la sustituta sigue ejecutándose. Cuenta `inflight` para posponer el aviso, pero el CTA y su click guard sólo consultan `hasPendingItemsOperations()`. En esa ventana, «Datos y envío» se habilita antes de que termine la sustituta.

**Reproducción:** bundle real `wc-blocks-data` 11.1.0 y script real, con infraestructura/DOM simulados del harness existente. Se encolaron dos actualizaciones, se abortó la primera y se mantuvo la segunda pendiente. Se hizo pasar el transporte falso por `window.fetch` para ejercitar el observador que el harness original no ejecuta (no define fetch).

```json
{"inflight":1,"pending":[],"ariaDisabled":"false","navigationPrevented":false}
```

Tras resolver la segunda, quedó cantidad 7 y `inflight: 0`. No es aceptación de navegador, teclado físico o accesibilidad.

**Corrección sugerida:** usar un único criterio de actividad para aviso y CTA, incluyendo transporte pendiente; agregar el escenario abortar + reemplazo lento a la regresión.

## Ejecuciones y límites

- `FREEPLAST_SKIP_STACK=1 npm test`: **169 aserciones locales + 68 chequeos**, todos pasan. Incluye 32 aserciones del store de Cart dentro de esos 68, no adicionales.
- Se omitió expresamente `woo-stack-harness.mjs`: arranca un servidor PHP y hace envíos sintéticos, fuera del alcance de esta revisión sin servidor.
- Dos probes adicionales reproducen SP-01 y SP-04. Archivos en `evidence/`; no escriben bases de datos ni usan red.
- #27, #28, #29 y #30 muestran implementaciones dirigidas a los defectos descritos (bloque dinámico sobre loop Woo, estado semántico, tabla sin importes y contador nativo). No se detectó otro bloqueo concreto en sus cambios durante esta revisión; eso no sustituye los recorridos de navegador/staging pendientes.
- No se modificaron fuentes de aplicación, tickets, ramas ni archivos preexistentes sin seguimiento. Este directorio es el informe nuevo.
- No se declara UI validada ni aceptación operativa.

**Resumen por eje:** Standards: **1 hallazgo P2** (denegaciones con falsos positivos); Spec: **4 hallazgos**, peor severidad **P1** (identidad/recuperación del intento y permisos de Ventas).
