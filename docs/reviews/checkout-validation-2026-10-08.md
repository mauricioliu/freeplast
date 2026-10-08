# Validación de Solicitar Cotización · 2026-10-08

## Verificación posterior del lead

Desplegado en staging mediante release `20261008T191746Z-1e969b`, fuente `e5a4a77`. Lead repitió suite offline y gate completo aislado. En navegador independiente verificó cada campo requerido vacío, despacho/dirección condicional, errores múltiples y Giro con foco/ARIA; capturas 412px/1440px revisadas sin overflow horizontal. POST no-AJAX devolvió resumen e inline servidor con Giro vacío. Fingerprint protegido posterior a las pruebas idéntico al backup. Detalle y límites: `wordpress/docs/releases/2026-10-08-logic-1e969b.md`. Producción intacta. El estado siguiente documenta la entrega original del worker, antes de esta verificación.

## Estado

Implementación local; **UI cambiada, no validada visualmente**. Sin commit, push, despliegue, servicios, navegador real, dispositivos, solicitudes reales, correo ni escrituras remotas. Lead conserva gates nativos aislados, revisión UI y despliegue.

Baseline limpio: `ff5cfc1667c5a84bf356f3fd137bcf72bd1e57bc`. Versiones preparadas: adapter **1.12.3**, theme **1.0.21**; la versión del theme invalida la URL cacheada de `checkout-form.js`. Cambios anteriores de cantidades de 10 intactos.

## Causa y alcance

La captura privada muestra Giro vacío y un aviso de resultado incierto. Se abrió sin copiarla al repositorio ni transcribir datos personales.

Reproducción local determinista con WooCommerce 11.1.0 fijado, jQuery real y transporte interceptado:

- El template nativo `wordpress/.build/wp/wp-content/plugins/woocommerce/templates/block-notices/error.php:31` coloca **un solo** error en un `div.wc-block-components-notice-banner[data-id]`; reserva `li` para varios errores.
- Baseline `wordpress/wp-content/themes/freeplast/assets/js/checkout-form.js:66` leía exclusivamente `li`. Para Giro solo obtenía cero mensajes; líneas 93–97 inferían incertidumbre de cero campos enlazables. Además ocultaba el grupo nativo al activar su presentación, dejando visible únicamente el aviso engañoso.
- Una prueba con el aviso individual y el evento AJAX real falló antes del parche: `single native block notice: empty Giro is validation, never uncertain receipt`.
- La corrección en `checkout-form.js:67–104` consume ambos formatos nativos. Conserva causas no asociadas a controles y texto de errores DIV de transporte. Usa los identificadores de validación del servidor, no búsquedas por frases, para distinguir rechazo conocido de resultado incierto. Un error de identidad conocido tampoco se convierte en incertidumbre al corregir Despacho (`:35–54`).

No se inspeccionó la respuesta HTTP instalada. La causa está confirmada en el código fijado y reproduce el síntoma exacto; lead debe verificar el mismo flujo instalado.

Otros puntos corregidos:

- `wordpress/wp-content/plugins/freeplast-woo/freeplast-woo.php:231–271`: mensajes requeridos mediante filtro nativo; se conserva el veredicto Email de Woo/WordPress; mensaje de longitud identifica campo; Despacho vacío no duplica dos avisos. Copia de errores exclusivamente en memoria de la petición para el fallback sin JS: Woo consume los notices antes de renderizar el formulario.
- `wordpress/wp-content/themes/freeplast/woocommerce/checkout/form-checkout.php:42–54,99–108`: resumen servidor singular/plural, enlaces, mensajes junto a campos y asociaciones ARIA. El marcado obligatorio de dirección sigue la selección de despacho también sin JS. Datos continúan pasando por `get_value()` nativo.
- Dirección sin despacho: su borrador local ya no bloquea por longitud mientras permanece oculto; no se guarda como destino, conforme al comportamiento existente. Con despacho conserva el límite previo.
- Intento, reclamación atómica, protección de duplicados, recuperación y guardado nativos no modificados. No se añade un segundo envío ni un validador cliente paralelo.

## Matriz de campos y mensajes

Antes, los required nativos recibían el aviso genérico de campo obligatorio. Con un único aviso en formato block, la presentación podía reemplazarlo por incertidumbre. Las reglas siguientes son las existentes, salvo dejar de validar la longitud del destino no utilizado cuando no hay despacho.

| Campo | Regla real | Antes | Después |
|---|---|---|---|
| Nombre | Obligatorio; máximo 240 bytes | Required genérico | `Nombre: escribe tu nombre.` |
| Teléfono | Obligatorio; máximo 240 bytes; sin validador adicional de formato en la definición Freeplast | Required genérico | `Teléfono: escribe un número de contacto.` |
| Email vacío | Obligatorio; máximo 240 bytes | Required genérico | `Email: escribe tu correo de contacto.` |
| Email incorrecto | Validación nativa `is_email`; sin nueva regex de aceptación | Aviso nativo de formato; blur sin nombre de campo | `Email: escribe un correo completo, como nombre@empresa.cl.` en respuesta servidor y ayuda de blur |
| Nombre de empresa | Obligatorio; máximo 240 bytes | Required genérico | `Nombre de empresa: escribe el nombre o razón social.` |
| RUT empresa | Obligatorio; máximo 240 bytes; sin verificación adicional de formato/dígito | Required genérico | `RUT empresa: escribe el RUT de la empresa.` |
| Giro | Obligatorio; máximo 240 bytes | Required genérico, perdido en aviso individual | `Giro: escribe la actividad de la empresa, por ejemplo, producción agrícola.` |
| Despacho | Valor `si` o `no`; elección obligatoria | `Selecciona si necesitas despacho.`; podía duplicarse con required nativo | `Despacho: selecciona Con despacho o Sin despacho.`; un aviso |
| Dirección | Texto no vacío tras trim si despacho=`si`; máximo 800 bytes | `Indica la dirección completa de despacho.` | `Dirección de despacho: escribe calle, número, comuna y región.` |
| Comuna | Parte del mismo textarea Dirección, no campo independiente ni parser existente | Solo placeholder de dirección | Nombrada en corrección y explicación de obligatoriedad condicional |
| Región | Parte del mismo textarea Dirección, no campo independiente ni parser existente | Solo placeholder de dirección | Nombrada en corrección y explicación de obligatoriedad condicional |
| Longitud de los siete campos de texto anteriores | Límites previos en **bytes**, no caracteres; destino solo con despacho | `El campo es demasiado largo.` | `<Nombre del campo>: el texto es demasiado largo. Acórtalo y vuelve a intentarlo.` |
| Mensaje | Opcional, sin nuevo límite/obligatoriedad | Opcional | Sigue opcional; vacío aceptado |

No se inventa comprobación separada de calle, número, comuna o región dentro del texto libre. Tampoco reglas nuevas de RUT, teléfono, consentimiento, registro o geocodificación. `shipping` está vacío; no hay otros campos de dirección obligatorios independientes.

Controles técnicos, no campos comerciales editables:

- `payment_method`: debe seguir siendo `quotes-gateway`; rechazo conserva explicación sin pagos, agrega recuperación y `data-id` para no presentarlo como incertidumbre.
- `fpw_attempt`: obligatorio por la frontera de identidad, no por campos nativos; mensajes y validación existentes intactos, sin enlace a un input oculto.
- Nonce/sesión: manejo nativo intacto. Un error sin metadatos suficientes no se declara éxito ni rechazo definitivo; se conserva su causa y la recuperación prudente.
- `fpw_place_id`/`fpw_place_scope`: opcionales; manual válido; normalización/procedencia intactas.

## Estados y accesibilidad

- Rechazo conocido: `Revisa 1 campo para continuar.` / `Revisa N campos para continuar.`; datos conservados, lista de causas, enlaces con foco en controles, `aria-invalid` y `aria-describedby`. Radio Despacho enlaza su primer control real y asocia el aviso a ambos.
- Rechazo conocido sin control visible: `Revisa los avisos para continuar.`; no se inventa incertidumbre.
- Red/timeout/respuesta ambigua: se conserva `Puede que ya se haya guardado…`, el mismo intento y el reintento nativo. No se asegura que la solicitud haya fallado definitivamente.
- Corregir Giro y reenviar conserva datos e identidad; un timeout posterior vuelve correctamente al estado incierto. Cambiar a Sin despacho elimina errores de destino ya irrelevantes, no otros errores.
- Sin JS: resumen y errores inline renderizados por PHP. Notices nativos previos permanecen disponibles; no se eliminan avisos desconocidos. Puede haber repetición de la causa en el notice nativo y el resumen: no se ocultó información para resolverla.

## Pruebas ejecutadas

Evidencia privada en `.scratch/herd-validation-fix/`:

| Prueba | Resultado |
|---|---|
| Regresión roja previa (`red.log`) | Falló con Giro individual, como esperado |
| `wordpress/.tools/php/php wordpress/scripts/checkout-form-test.php` | **243** checks |
| `wordpress/.tools/php/php wordpress/scripts/test-woo-adapter.php` | **513** checks, incluyendo identidad/reintento/recuperación existentes |
| `runCheckoutFormTests()` | **33** checks |
| `runNativeCheckoutTests()` | **630** checks |
| `FREEPLAST_SKIP_STACK=1 npm test` | **PASS**; **1882** checks en resumen principal; stack omitido explícitamente |
| `git diff --check` | PASS |

Comando JS focalizado:

```sh
node --input-type=module -e "const a=await import('./wordpress/scripts/checkout-form-js-test.mjs'); await a.runCheckoutFormTests(); const b=await import('./wordpress/scripts/checkout-native-test.mjs'); await b.runNativeCheckoutTests();"
```

`checkout-form-test.php:160+` ejecuta el método real de validación de campos extraído de Woo fijado, `WP_Error` real, `is_email`/`sanitize_email` nativos, funciones Freeplast y ambos templates reales de notices. El render usa helpers/stubs acotados, no un servidor WordPress. Produce fixtures sintéticas para JS, en lugar de asumir que el servidor siempre devuelve listas.

Cobertura individual: todos los required; Despacho inválido; dirección vacía/espacios con despacho; destino completo manual; dirección no requerida sin despacho; Email inválido; cada límite aceptado y excedido; múltiples campos; opcional vacío; corrección/reintento; mensajes, foco y ARIA; conservación de valores e identidad. JS pasa cada rechazo generado por los **dos** formatos nativos a checkout/jQuery reales con transporte interceptado. Prueba red incierta, timeout, doble activación, actualización del resumen, navegación de borrador y recuperación existente. El test PHP verifica fallback sin JS por cada escenario, no navegación HTTP.

Logs: `focused-tests.log`, `npm-test.log`, `red.log`. No contienen datos de la captura.

## Reversión acotada

- Estado y HEAD previos: `status-before.txt` (vacío), `head-before.txt`.
- Copias exactas: `.scratch/herd-validation-fix/baseline/`.
- Inventario existente: `existing-files.txt`; hashes: `baseline.sha256`.
- Archivo nuevo del repositorio: `docs/reviews/checkout-validation-2026-10-08.md`, inventariado en `new-files.txt`.
- Para revertir, copiar **solo** cada ruta de `existing-files.txt` desde `baseline/` a su ruta original; retirar **solo** el documento inventariado. Comparar hashes y diff de esas rutas. Conservar backups/evidencia privada. No reset, stash ni descarte general.

## Pendiente del lead / límites

**UI no validada**: revisar desktop/mobile, zoom, teclado, lector de pantalla, no-JS en navegador y coherencia visual de notices/resumen. Ejecutar gate completo en entorno aislado y control de correo antes de desplegar; confirmar versión de assets/cache en destino. No se corrió `npm test` con stack habilitado, ni pruebas HTTP/BD reales. No hay aprobación visual ni de dispositivos implícita en los tests offline.

Límites intencionales: dirección continúa texto libre; límites heredados cuentan bytes; errores nativos sin identificador suficiente permanecen conservadores. No se ampliaron reglas comerciales para compensar esos límites.
