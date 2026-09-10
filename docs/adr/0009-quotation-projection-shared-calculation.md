# 0009 — La proyección de la cotización es un cálculo único en servidor y la vista previa queda ligada a la revisión efectivamente vista

**Estado:** decisión técnica del corte 6 de la #49 (issue #55), registrada por el agente dentro del alcance autorizado de documentar las decisiones arquitectónicas consecuentes. No amplía el alcance de la #49, no confirma la política fiscal ni autoriza despliegue.
**Contexto:** [ADR-0005](0005-draft-working-state-optimistic-concurrency.md) · issue #55 · el corte 7 (#56, «Aprobar y enviar») la consume.

## Contexto

El corte 2 dejó el trabajo del dueño guardado con control de revisión. Antes de aprobar, el dueño debe revisar una proyección para el comprador — productos/opciones, cantidades, precios netos, subtotal, despacho, IVA, total y vigencia — que identifique faltantes y quede ligada a la revisión efectivamente vista, sin emitir documento. La tasa/base imponible del IVA, el tratamiento de productos y despacho, la precisión y el redondeo siguen **sin confirmación fiscal**: el 19% y la aritmética del prototipo no cuentan como aprobación.

## Decisión

1. **Una sola proyección, en servidor, determinista:** `fpw_quotation_projection()` calcula — con aritmética entera exacta en CLP (nunca flotantes) — las líneas, subtotal, despacho, IVA y total desde el trabajo guardado. La vista previa la renderiza y la emisión futura (corte 7) debe consumir LA MISMA función: no hay cálculos paralelos por pantalla. El kernel de IVA (`fpw_quotation_tax_amount`, base × ‰/1000 con redondeo al más cercano, media arriba, en dos pasos enteros a prueba de desborde) es único por la misma razón.
2. **Política fiscal ausente por defecto:** `fpw_quotation_tax_config()` solo entrega tasa (‰ entera 0–5000) y tratamiento del despacho a través de su filtro — el mismo patrón de costura de configuración que la clave de Places (ADR-0007). Sin ella no se inventa tasa alguna: el IVA y el total quedan **Pendientes** y la oferta queda incompleta. Una entrega malformada degrada a ausente en vez de volverse autoridad. Un 0‰ confirmado es representable y distinto de ausente.
3. **Vigencia:** siete días por defecto (filtro `fpw_quotation_default_validity_days`, acotado 1–365), editable por borrador dentro del trabajo guardado (fila de trabajo, esquema 2; vacío = defecto). La vista previa la expresa como «N días a contar de su aprobación» y las condiciones necesarias — sin cláusulas, plazos logísticos ni datos bancarios inventados.
4. **Faltantes explícitos, jamás ceros:** cada línea sin precio, el despacho solicitado sin monto o ingresado para otras condiciones, el destino de trabajo vacío con despacho ofrecido y la política fiscal ausente son faltantes nombrados que bloquean la oferta completa. La falta de historial o de asistencia de direcciones jamás bloquea: el bloqueo es de los datos comerciales que la oferta porta.
5. **Vista previa guardada y ligada:** «Generar vista previa» congela la proyección de la revisión actual en `fpw_draft_preview_<order_id>` (UPDATE incondicional con INSERT de primera escritura — ayuda de revisión reemplazable por la más nueva, no un recibo). La pantalla la muestra **tal como fue revisada** — jamás recalculada — y la marca **obsoleta** en cuanto una guardada comercial posterior supera su revisión: ninguna aprobación futura puede usarla para emitir valores distintos a los revisados. El corte 7 deberá exigir que lo emitido coincida con la proyección guardada.
6. **La vista previa no emite nada:** la acción va por la misma puerta frontal de `admin_init` (primero la capacidad `manage_woocommerce`, luego su propio nonce `fpw-draft-preview-<order_id>`, 403 explícito). Guardar o previsualizar no crea versión aprobada, ni PDF, ni correo al comprador; el registro fuente no se toca. El contenido para el comprador excluye por construcción historial, notas internas, costos de transportista y el desglose interno de la estimación de despacho.

## Consecuencias

- «¿Por qué no un 19% por defecto?» — porque la tasa es un insumo fiscal del dueño, no un dato técnico: entregarla sin confirmación sería inventar política comercial. La costura hace de la confirmación un cambio de configuración revisable, no un cambio de código.
- «¿Por qué no CAS para la vista previa?» — porque es un auxilio de revisión, no un documento: la última genera y queda ligada a SU revisión; la obsolescencia se decide en la lectura comparando revisiones, que es la invariante que el corte 7 verificará.
- El reducer y los supuestos del prototipo siguen fuera de producción: aquí no se traslada nada de su aritmética fiscal.
- Sin política fiscal confirmada, ninguna oferta proyecta total: la completitud es verificable, y el bloqueo es el comportamiento correcto mientras dure el bloqueo externo.
