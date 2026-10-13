# Paso 5: doble envío, diagnóstico offline inconcluso

Plan `plan-mv76b7sk-tgrr`, worker impl-1. Base `13aa89727e0e3a8859f4481a177494bd284407da`. **No se reprodujo el síntoma informado; no hay fix runtime ni causa confirmada. Paso 5 pendiente.**

## Falta identificar el síntoma

El trabajo recibido no identifica si «doble envío» significa necesitar dos clics para enviar o producir dos solicitudes/correos, ni cuál botón: público **Solicitar cotización** o privado **Aprobar y enviar**. Se pidió al lead esa precisión. No equiparar un POST repetido con un efecto comercial duplicado.

Necesario para continuar: botón/pantalla/URL, secuencia exacta desde carga, mensaje tras primer clic, qué sucede tras segundo clic, y evidencia redactada (captura o registro de solicitudes/respuestas con hora). Si son duplicados, distinguir dos referencias de solicitud, dos versiones o dos mensajes del mismo correo; no aportar tokens, cookies ni datos personales. También precisar si despacho estaba activado y si se acababa de editar un campo/precio. No repetir envíos reales para investigar una respuesta incierta.

## Alcance leído y comprobado

### Público

- `wordpress/wp-content/themes/freeplast/assets/js/checkout-form.js`: acompaña ciclo AJAX de Woo, estado ocupado y errores; no constituye una implementación de envío alternativa.
- `wordpress/wp-content/plugins/freeplast-woo/freeplast-woo.php`: `fpw_checkout_claim()` participa en `woocommerce_create_order`. Identidad de intento/sesión, claim persistente y recuperación de intento ya recibido evitan crear otra solicitud por el mismo intento. El fold quita el hook de notificaciones de la extensión en esa petición mediante `fpw_dedupe_request_notifications()`. Autorizaciones y límites temporales permanecen; no se alteraron.
- Test existente usaba evento submit directo. Se amplió `wordpress/scripts/checkout-native-test.mjs` con `.click()` sobre el botón real del formulario PHP de fixture, ejecutando Woo/jQuery fijados y JS del proyecto en JSDOM; transporte interceptado, nunca HTTP.
- Resultado nuevo: **un clic → exactamente un POST**; dos clics adicionales mientras está pendiente → sigue uno; timeout seguido de un clic explícito → exactamente un retry y mismo `fpw_attempt`. Esto no demuestra entrega, persistencia SQL ni que un usuario haya visto la misma secuencia.
- Los tests existentes cubren reemplazo del botón durante actualización del checkout, retención del intento, mensajes inciertos, corrección y reintento. `test-woo-adapter.php` cubre claim, recuperación, sesión, vida útil y protección de selección con almacenamiento simulado.

### Privado

- `quotation-approval.php`: `fpw_draft_approve_html()` produce formulario POST con acción oculta, nonce y token de vista previa; `fpw_handle_draft_approve_request()` verifica nonce/versión previa/vista revisada antes de `fpw_quotation_approve_and_send()`.
- `fpw_quotation_approve_and_send()` usa INSERT de fila única de versión. Solo ganador genera documento/envía; perdedor devuelve versión existente. Guarda estado de correo desconocido antes del transporte y no reenvía automáticamente tras incertidumbre.
- `assets/owner-workspace.js` conserva controles exitosos del formulario, marca ocupado y bloquea acciones distintas cuando hay edición sin guardar. No deduplica por sí solo todo POST privado: la autoridad es el servidor. Que una segunda petición alcance servidor no prueba doble emisión.
- `wordpress/scripts/quote-draft-test.php` ya cubría aprobación, segundo POST, intercalado determinista al INSERT y retry tras resultado de correo no guardado. Se añadió assert explícito **un POST de aprobación → exactamente una llamada al transporte simulado**, sin segunda presentación.
- Resultado: 340 checks pasan; repetición no modifica versión ni reenvía. El transporte está sustituido por stub y los datos son ficticios. Esto no es una prueba de clic privado en navegador ni concurrencia MariaDB real.

## Cambios mínimos

Solo dos tests (16 líneas): bloque de 5 asserts públicos y 1 assert privado. Sin cambio runtime, plantilla, textos de interfaz, fixtures persistidos, configuración ni exports. Cobertura preventiva, no una regresión de un bug reproducido. Los tests nuevos pasan también antes de cualquier fix porque no hubo fix.

## Comandos y resultados

```sh
PHP_BINARY=/home/mauricio-liu/.local/bin/php node --input-type=module -e "import {runNativeCheckoutTests} from './wordpress/scripts/checkout-native-test.mjs'; await runNativeCheckoutTests();"
/home/mauricio-liu/.local/bin/php wordpress/scripts/quote-draft-test.php
/home/mauricio-liu/.local/bin/php wordpress/scripts/test-woo-adapter.php
PHP_BINARY=/home/mauricio-liu/.local/bin/php FREEPLAST_SKIP_STACK=1 npm test
git diff --check
```

- Baseline antes de editar: público 630 checks, privado 339, intake 516; todos PASS.
- Tras ampliar cobertura: público **635**, privado **340**; PASS.
- Gate completo offline **PASS exit 0**, contador final **1951** checks de sintaxis/dependencias/deployment más suites reportadas. Stack explícitamente omitido.
- `git diff --check`: PASS.
- Logs preservados en `.scratch/duplicate-submit-diagnosis/`: `public-baseline.log`, `private-baseline.log`, `intake-baseline.log`, `public-clicks.log`, `private-count.log`, `offline-suite.log`.

## Límite y siguiente checkpoint

No hay comando que reproduzca el síntoma exacto del usuario porque aún no está definido ni se cuenta con captura. Por disciplina de diagnóstico no se avanzó a hipótesis causal, instrumentación ni fix especulativo. La cobertura offline no descarta diferencia de versión desplegada, sesión o secuencia de interacción, ni prueba que alguna de ellas sea la causa. Solicitar reproducción concreta; mantener paso 5 abierto.

Sin navegador, servidor, red de aplicación, correo real, datos productivos, commits ni despliegue. No se ejecutó ningún paso posterior del plan.
