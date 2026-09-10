# 0005 — El trabajo guardado del borrador vive en una fila propia con control de revisión optimista

**Estado:** decisión técnica del corte 2 de la #49 (issue #51), registrada por el agente dentro del alcance autorizado de documentar las decisiones arquitectónicas consecuentes. No amplía el alcance de la #49 ni autoriza despliegue.
**Contexto:** [ADR-0004](0004-quote-draft-durable-relationship.md) · issue #51 · los cortes #52 (lista de precios), #55 (totales y vigencia) y #60 (distancia) la consumen.

## Contexto

El corte 1 dejó cada borrador como snapshot de recibo inmutable (ADR-0004: fila `fpw_draft_<order_id>`, INSERT puro, jamás reescrita). El corte 2 pide al dueño **completar precios netos CLP, cantidades, destino de trabajo y monto de despacho, guardar y retomarlos** — conservando aparte lo que el comprador pidió — sin sobrescribir en silencio trabajo más reciente y sin convertir un importe ausente en cero.

## Decisión

1. **Fila de trabajo propia:** los ajustes del dueño viven en `fpw_draft_work_<order_id>` (JSON, `autoload off`, SQL directo como el resto de las filas del borrador — sin caché de opciones). La fila de recibo (ADR-0004) queda intocada: «lo que pidió el comprador» y «lo que el dueño ajusta» son registros separados y legibles uno junto al otro.
   - *Alternativa descartada:* reescribir la fila del recibo — rompería la inmutabilidad del recibo y confundiría lo pedido con lo ajustado.
2. **Guardado con control de concurrencia optimista:** el formulario lleva la revisión que representa (`fpw_work_revision`); el guardado la compara con la revisión guardada y se aplica como **compare-and-set exacto** (`UPDATE … WHERE option_value = <valor previo>`) o, en la primera guardada, INSERT puro. Un envío desactualizado o concurrente recibe un estado de conflicto, se descarta completo y la pantalla re-representa los valores ya guardados: nada se sobrescribe en silencio (ni siquiera un doble clic).
3. **Frescura del monto de despacho:** el despacho guardado registra las condiciones (destino + cantidades) para las que fue **ingresado**; una guardada posterior que cambia destino o cantidades sin cambiar el monto deja esas condiciones de pie y la pantalla marca «Requiere revisión». Sin despacho sigue siendo distinto de despacho aún no valorizado: la solicitud sin despacho no ofrece destino ni flete.
4. **Validación en servidor, sin política de gratuidad:** importes ausentes quedan pendientes (nunca cero); un precio o monto en 0 se rechaza — dejar el campo vacío es la única vía de «aún pendiente». Cantidades enteras 1–1.000.000; montos enteros CLP 1–99.999.999; destino de trabajo ≤ 800 caracteres. El guardado es todo-o-nada.
5. **Autorización y CSRF al frente:** el guardado se atiende en `admin_init` — antes del render de wp-admin, para que un rechazo CSRF sea un 403 real y no un 200 con cabeceras ya enviadas. Primero la capacidad (`manage_woocommerce`; el nonce no otorga permisos), luego el nonce por solicitud (`fpw-draft-save-<order_id>`). Guardar no notifica, no aprueba ni emite nada, y el registro fuente nunca se toca.

## Consecuencias

- «¿Por qué no enriquecer la fila del recibo?» — porque «un recibo, un snapshot inmutable» es la invariante verificable de la ADR-0004; separar el trabajo permite mostrar pedido y ajuste lado a lado y mantener el registro histórico intacto.
- «¿Por qué compare-and-set en vez de solo comparar la revisión al leer?» — porque leer-y-luego-escribir deja una ventana real entre dos guardados con la misma revisión; el CAS exacto la cierra atómicamente en SQL.
- Los montos guardados son decisiones manuales del dueño, con origen explícito `manual`/`pending` por línea; el corte 3 (#52) prellena sugerencias desde la lista de precios sin pisar estos ajustes ni esta mecánica de revisión.
