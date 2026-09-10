# 0004 — El borrador de cotización es una fila durable única, creada al recibo, leída solo por el dueño

**Estado:** decisión técnica del corte 1 de la #49 (issue #50), registrada por el agente dentro del alcance autorizado de documentar «la relación durable escogida y cualquier decisión arquitectónica consecuente». No amplía el alcance de la #49 ni autoriza despliegue.
**Contexto:** [ADR-0001](0001-woocommerce-quote-only.md) · issue #50 · corte 2 (#51) y corte 5 (#54) la consumen.

## Contexto

Una Solicitud de cotización recibida duraderamente debe quedar relacionada con **un único borrador inicial** (issue #50): ni el reprocesamiento, ni la doble activación, ni la concurrencia del mismo intento, ni la recuperación tras una respuesta perdida pueden crear otro borrador; una solicitud nueva legítima con contenido idéntico sí es independiente. Woo ya garantiza «un intento, una solicitud» (filas `fpw_attempt_*`/`fpw_recovery_*` del adaptador); falta decidir dónde vive el borrador y quién lo lee.

## Decisión

1. **Relación durable:** el borrador vive en una **fila dedicada de la tabla `options`, `fpw_draft_<order_id>`, escrita como INSERT puro** — el mismo patrón de vínculo durable de los intentos (nombre único: el segundo INSERT pierde; la fila jamás se reescrita). La creación engancha `woocommerce_checkout_order_created`, que Woo dispara exactamente una vez por pedido persistido y jamás para un intento fusionado, con el fallo de snapshot aislado (el recibo nunca se rompe; la pantalla dice «Sin borrador» en vez de inventar).
   - *Alternativas descartadas:* meta del pedido (la escritura idempotente exigiría leer-antes-de-escribir, no atómica; y Woo borra metas en borrados en cascada que no se pidieron) y un CPT propio (un segundo almacén de registros comerciales que ADR-0001 no autoriza).
2. **Contenido = snapshot del recibo** del registro nativo: ítems, opciones y cantidades, identidad, destino, Submitted Details e identidad de intento. Lo que el registro aún no tiene — precios, historial, estimación de despacho — queda como **`pending` explícito**: nunca precio cero ni veredicto de cliente («sin historial» no es palabra de esta pantalla). Los cortes posteriores completan el enriquecimiento sobre esta misma estructura.
3. **Lectura privada:** pantalla wp-admin no listada (`admin.php?page=fpw-quote-draft&request=<id>`), cuya única llave es la capacidad `manage_woocommerce` (el dueño; el rol Ventas, con sus cuatro capacidades aprobadas, no la tiene). Conocer el enlace, el id o un nonce no otorga permisos. El corte 1 es solo lectura GET: no hay cambio de estado, luego no hay superficie CSRF; cada acción futura deberá traer su nonce.
4. **El aviso al dueño sigue siendo uno:** el correo admin existente de la extensión de cotizaciones (plantilla `request-email.php` del adaptador, rama `$sent_to_admin`) incorpora el enlace al borrador. No se registra ninguna superficie nueva de notificación; los intentos fusionados ya no disparan la notificación por la deduplicación existente.

## Consecuencias

- «¿Por qué no se genera el borrador al leerlo?» — porque «un recibo, un borrador» es una invariante verificable solo si nace en el recibo; generar bajo demanda difuminaría el snapshot del recibo con datos posteriores.
- El borrador no es documento comercial: no emite nada, no crea compras, no reserva stock ni toca precios públicos; el acuse del comprador sigue sin precios y distinto de una cotización emitida.
- Los registros históricos importados y los creados por administración no pasan por el recibo de checkout: no obtienen borrador, y la pantalla lo declara honestamente.
