# Revisión de adjuntos: cotización y catálogo

Paso 1 del plan `plan-mv76b7sk-tgrr`. Solo evidencia; sin cambios de comportamiento, datos Woo, mínimos, configuración, fixtures, exports ni documentos históricos. Revisión local; red autorizada después solo para instalar dependencias npm en este worktree. Sin navegador, servidor, correo ni despliegue.

## Fuentes exactas y método

- `/home/mauricio-liu/Downloads/Cotización Atama Factory.pdf`: 1 página A4; SHA256 `258aa3da415e49ee9a8b69048839d126055a8db97b35c378ea10f3d34711042b`. Extracción `pdftotext -layout` y lectura visual del render `pdftoppm -scale-to 1500 -png`.
- `/home/mauricio-liu/Downloads/Catálogo Freeplast 2026.pdf`: 20 páginas; SHA256 `fa76b5475fe71b9725e40fb49e7a2f43a7a804a139ed46837410e9cff55bea31`. Idéntico byte por byte a `docs/Catálogo Freeplast 2026.pdf`. Sin capa de texto útil: render de las 20 páginas y Tesseract `eng` local. [OCR completo por página](catalogue.txt), orientativo, no transcripción infalible. Lectura visual adicional de páginas 2, 4 y 5 para resolver errores OCR y confirmar contradicciones.
- Contraste con `wordpress/data/products.json` y la [transcripción técnica existente](../../../wordpress/docs/catalog-pdf-2026/transcription.md), previamente obtenida por lectura visual de las páginas 6–20. El OCR nuevo coincide con los 15 recuentos de pallet y los datos técnicos de esa transcripción. No se repitió inspección visual individual de las 15 fichas.
- Extracción íntegra de Atama y renders quedaron en `.scratch/quotation-source-review/` (ignorado). No agregar al repositorio los datos personales del comprador ni reutilizarlos como fixtures.

## Cotización Atama, p.1: datos verificados

| Campo | Texto/dato de fuente |
|---|---|
| Titular / razón social en bloque bancario | Freeplast SPA |
| RUT empresa y titular | 76.766.847-3 |
| Dirección impresa | Camino El Arrayán 52, San Francisco de Mostazal, VI Región |
| Teléfono impreso | 968444265 |
| Correo comercial y transferencia | ventas@freeplast.cl |
| Vendedor de esta cotización | Ignacio Maturana |
| Banco | Banco de Chile |
| Tipo de cuenta | Cta Cte (cuenta corriente) |
| Número de cuenta | 390400602 |
| Número comercial observado | 1760 |
| Fecha impresa | 10/13/2026 (13 octubre 2026) |

El catálogo p.1–5 respalda teléfono y WhatsApp **+56 9 68444265**, correo **ventas@freeplast.cl**. El número nacional coincide con el de Atama. Son datos leídos del adjunto, no una validación externa de titularidad o vigencia bancaria.

Formato observado: logo azul/verde arriba a la izquierda; título «COTIZACIÓN Nº 1760»; fecha; bloque emisor; bloque comprador/contacto; tabla cantidad, artículos, valor unitario, valor neto y total IVA incluido; totales; condiciones; transferencia al pie. Tabla y comprador usan cabeceras verdes. Documento de una página: no evidencia de paginación ni de comportamiento multipágina.

Ejemplo comercial: 5.000 Cajas Cosecheras 3/4 × 1.980 = 9.900.000 neto; 11.781.000 IVA incluido, consistente con 19%. La moneda no está impresa explícitamente. No convertir ese precio en tarifa general, ni asignar vendedor fijo a todos los documentos.

Condiciones literales: validez 15 días; sujeta a stock disponible; pago contado vía transferencia electrónica; retiro en bodega de Freeplast.

**Decisión posterior confirmada por el usuario:** defaults de 15 días, sujeta a stock y pago contado vía transferencia. Retiro en bodega aplica solo cuando no hay despacho; no suprime la opción de despacho. Continuar correlativo comercial existente. En confirmación posterior, el usuario fijó **último usado 1760; siguiente cotización nueva 1761**, entero, sin reinicio anual inventado. Esta decisión proviene del usuario, no de inferir el estado de la numeración a partir del PDF. Estas decisiones se registran únicamente; sin cambios runtime en esta fase.

Logo: `assets/logo.webp` coincide visualmente con el logotipo azul/verde del adjunto; candidato reutilizable, no identidad binaria probada. El render actual `wordpress/wp-content/plugins/freeplast-woo/quotation-document.php` usa `pdf-assets/mark.svg` más texto «Freeplast», papel letter y referencia de solicitud. El PDF de referencia usa A4 y logotipo completo. Integración, formato final y snapshot de emisor/banco pertenecen al paso 6; no implementados aquí.

## Pallets: evidencia disponible para el próximo paso

Todos los siguientes valores ya figuran en `wordpress/data/products.json`; no hace falta volver a inventar ni extraer fichas nuevas. Es referencia local, no prueba del estado Woo actual.

| Producto | Página física | Unidades por pallet | Mínimo derivado de instrucción aprobada, todavía NO configurado |
|---|---:|---:|---:|
| Tote | 6 | 120 | 120 |
| Caja Cosechera 3/4 | 7 | 70 | 70 |
| Universal Cerrada Negra | 8 | 100 | 100 |
| Universal Cerrada Color | 9 | 100 | 100 |
| Universal Ventilada Negra | 10 | 100 | 100 |
| Universal Ventilada Color | 11 | 100 | 100 |
| Caja Tomatera | 12 | 128 | 128 |
| Caja Frutillera | 13 | 200 | 200 |
| Caja Frutera | 14 | 65 | 65 |
| Caja Paltera | 15 | 540 | 540 |
| Traversa Tipo G1 | 16 | 132 | 50 |
| Traversa para Bins Tipo Romano | 17 | 200 | 50 |
| Traversa Tipo UPC | 18 | 132 | 50 |
| Caja Pollera | 19 | 90 | 90 |
| Caja Merlucera | 20 | 75 | 75 |

**Datos faltantes, política ya confirmada:** los productos activos `fp-bases-plasticas-para-pediluvios` y `fp-ladrillo-plastico` no tienen ficha ni unidades/pallet en este catálogo; JSON mantiene null. Usuario confirmó mantener ambos solicitables con texto explícito **«mínimo por confirmar»** y revisión del propietario sobre esas líneas. No inventar unidades/pallet, bloquear ni archivar. Implementación pendiente de siguientes pasos; esta fase solo documenta.

**Regla comercial:** «un pallet salvo traversas 50; solo mínimos, no múltiplos» viene de la instrucción aprobada, no del PDF. Los 15 recuentos están respaldados; `minimum_quantity` y `quantity_step` siguen null en las 17 referencias locales. La ficha actual dice «no una cantidad mínima de solicitud», y documentación previa dice «embalaje nunca mínimo»: contradicción con la nueva instrucción que deben resolver pasos 2–3, sin cambiar el dato físico de embalaje. 5.000 cosecheras de la cotización tampoco son múltiplo de 70; es consistente con mínimos sin múltiplos, no una regla autónoma.

## Contradicciones y ausencias que no se deben resolver inventando

1. **Dirección:** adjunto dice Camino El Arrayán 52; instrucción aprobada exige **P Picarquín sitio 52, San Fco de Mostazal, VI Región**. Para nuevas superficies manda la instrucción; conservar documentos anteriores. `dispatch-distance.php` define origen Arrayán con posibilidad de override: no probar equivalencia geográfica ni cambiar origen de rutas solo por cambio de texto. Revisión separada, paso 4.
2. **Universales:** resumen p.2 indica ancho **445 mm**; fichas p.8–11 **444 mm**. JSON ya usa 444 según detalle. Conservar discrepancia explícita, no mezclar dimensiones.
3. **Pollera:** resumen p.5 indica **800 g y 500 × 280 × 190 mm**; ficha p.19 **1350 g aprox. y 600 × 400 × 180 mm**. JSON sigue p.19. OCR p.5 contiene un error (96); imagen confirma 190.
4. **G2/Romano:** p.4 nombra «Traversa Tipo G2»; p.17 «Traversa para Bins Tipo Romano». Equivalencia no demostrada; no renombrar/fusionar productos ni asignar los 200 de Romano a G2 por semejanza.
5. **Paltera:** p.15 declara 30 l y exterior 500 × 300 × 150 mm (envolvente geométrica 22,5 l). Inconsistencia física pendiente de fabricante; no corregir capacidad con cálculo. Uso exportación aparece en resumen p.4; ficha p.15 no lo indica, JSON todavía dice Consultar.
6. **Tomatera:** resumen p.3 habla de PP virgen y reciclado y resistencia hasta 18 kg; ficha p.12 solo PP y capacidad 38 l. No convertir kg en litros ni inferir variante de material. JSON conserva PP; resistencia del resumen no está incorporada.
7. **Tote:** ficha p.6 omite unidad exterior/interior; p.2 sí da exterior en mm. JSON exterior mm está respaldado por resumen; interior sigue «unidad no indicada en PDF». No completar unidad interior por analogía.
8. **Frutera:** 44 l se apoya en resumen p.3, no en ficha p.14. No atribuir capacidad al detalle ni tratar ausencia como cero.
9. **Ausencias técnicas:** capacidad Cosechera, Frutillera y Pollera; ancho de las tres traversas; peso y medidas de pallet Romano; interiores de Universales, Frutera, Paltera y Merlucera; varias características no impresas. Véase transcripción completa enlazada. Silencio no significa «no apilable». Categorías del resumen agrícola difieren de varias categorías web previas: fuera del cambio aprobado, no reclasificar automáticamente.
10. **Datos empresariales adicionales:** no giro, representante legal ni otro identificador fiscal del emisor. No completar con datos del comprador; su dirección/giro están vacíos en el ejemplo.
11. **Correlativo:** solo consta una cotización **1760**. No confirma siguiente número 1761, último emitido, inicio, prefijo, reinicio anual ni política de cancelados/reemisiones. Usuario confirmó posteriormente continuar la numeración existente y suministró último **1760**, siguiente **1761**. El desconocimiento del último queda resuelto por esa confirmación, no por el adjunto. No inventar reglas adicionales; mantener separado de `FP-YYYY-NNNNNN`.

## Verificación y límites

- `pdfinfo`, `pdftotext`, `pdftoppm`, `tesseract`, `sha256sum`: fuentes leídas localmente; 1 + 20 páginas procesadas.
- Gate de evidencia ejecutado con Python/assert: SHA256 exacto e igualdad de copia local; extracción por página de `Unidades por Pallet: N unidades` contra `review.spec_sources.units_per_pallet` y `specs.units_per_pallet`; **15 coincidencias y exactamente dos productos sin dato, PASS**.
- `FREEPLAST_SKIP_STACK=1 npm test`: falla inicialmente por ruta PHP predeterminada ausente. Repetido con `PHP_BINARY="$(command -v php)" FREEPLAST_SKIP_STACK=1 npm test`: checks PHP/catálogo ejecutados pasan, pero suite completa falla por dependencia `jsdom` ausente. `npm ci --offline --ignore-scripts` no puede completar (tarball whatwg-url 14.2.0 no cacheado); no se usó red. Logs locales en `.scratch/quotation-source-review/`.
- Follow-up autorizado: `npm ci --ignore-scripts` vía registry configurado **PASS**, 41 paquetes instalados en worktree, 0 vulnerabilidades reportadas; sin upgrades ni instalaciones globales. Gate `PHP_BINARY=/home/mauricio-liu/.local/bin/php FREEPLAST_SKIP_STACK=1 npm test` **FAIL (exit 1)**: superada falta de jsdom; ahora falta `wordpress/.build/wp/wp-content/plugins/woocommerce/assets/js/frontend/checkout.js` requerido por `checkout-native-test.mjs`. No se descargó/preparó stack ni cambió el gate. Evidencia inicial preservada; logs nuevos `.scratch/quotation-source-review/npm-ci-authorized.log` y `offline-tests-authorized.log`.
- Segundo follow-up autorizado: copiados **25 archivos estáticos allowlisted** desde `/home/mauricio-liu/Projects/freeplast/wordpress/` a `.build/wp` y `.tools/php/php` ignorados del worktree (código WordPress/Woo requerido por tests y PHP). Lista SHA256 en `.scratch/quotation-source-review/static-test-source-copy.txt`; sin DB, wp-config, secretos, correo, logs originales ni datos de producción; original solo leído. Primera repetición detectó `add-to-cart.js` faltante en fingerprint; incorporado como archivo 25.
- Gate final **PASS exit 0**: `PHP_BINARY=/home/mauricio-liu/.local/bin/php FREEPLAST_SKIP_STACK=1 npm test`. Resultado: 1946 checks de sintaxis/dependencias/deployment más suites offline reportadas, incluidos 630 checks del ciclo Woo/jQuery con transporte interceptado. Stack explícitamente omitido. Log íntegro `.scratch/quotation-source-review/offline-tests-static-sources-2.log`; fallos anteriores preservados.
- No cambios de código: tests/fixtures/config/export no requieren actualización en este paso. Gate offline verde; no equivale a validación funcional real ni aprobación visual. Sin comprobar Woo remoto, render nuevo, multipágina, concurrencia, envío, correo ni navegador. No commits ni despliegues.
