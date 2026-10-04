# Correcciones de cotización — validación del lead

**DOS PENDIENTES RESUELTOS / GATE LOCAL INTEGRAL VERDE.** 2026-10-03. No autoriza despliegue ni equivale a aceptación en dispositivos reales.

Implementador en Herdr: `quotation-fix`, modelo **zai/glm-5.3**. Lead: revisión independiente del código, pruebas offline/nativas y Chrome. La ronda anterior cerró PARTIAL tras dos devoluciones; el usuario autorizó después continuar los dos pendientes. Se reutilizó el worker para el aviso; el lead corrigió la expectativa del harness. No commits, push ni despliegues. WIP previo preservado.

## Corregido y comprobado

| Hallazgo | Evidencia independiente |
|---|---|
| H1 cesta propia del rol cotizaciones | Prueba HTTP real: **18 checks PASS**, agregar/actualizar/quitar, permisos administrativos con nonce REST válido y rechazo de mutaciones sin nonce Store. No se amplió el acceso administrativo. |
| H2 cupón impropio | Ausente en DOM real de datos y despacho; no ocultamiento CSS. |
| H3 idioma y variantes | Chrome: Azul/Rojo distinguidos en controles de cesta; cantidades de catálogo incluyen producto. Dependencia de Woo declarada; warning del script nuevo ausente en retest. No prueba de lector físico. |
| H4 destinatario | Revisión previa muestra `herd-lead@example.invalid`; coincide con el correo capturado de la versión aprobada. |
| H5 error de email | Chrome móvil: texto español asociado al campo inválido; corrección elimina mensaje y aria-invalid. |
| H6 precio no guardado | Editar marca solo la leyenda de esa línea y bloquea revisión; reset restaura precio/leyenda y habilita revisión. |
| H0 continuidad aislada | Captura real local de solicitud, aviso al dueño, acuse y oferta con PDF. Igualdad SHA-256 con los bytes del documento aprobado. Aviso de prueba corregido y revalidado, abajo. |

**Gates independientes finales:** offline **1280 checks PASS**; suite nativa completa **710 checks PASS**, agregado **1990 checks PASS** (no sumar de nuevo); `git diff --check` PASS.

## Flujo nuevo recorrido en Chrome

Copia desechable `~/.local/state/freeplast-herd-validation/repo`, DB propia, `http://127.0.0.1:8098`, sin tocar servicios anteriores 8091/8100 ni producción/staging.

1. Cliente selecciona Caja Universal Cerrada Color Azul y Rojo.
2. Envía solicitud sintética **70 / FP-2026-000070**, TEST HERD NO ATENDER.
3. Captura aviso al dueño (enlace `request=70`) y acuse al comprador.
4. Dueño guarda precios 1500/1600 CLP, despacho 5000, vigencia7; revisión1.
5. Previsualiza destinatario y aprueba; versión1, total **8689 CLP** bajo política fiscal expresamente sintética del harness.
6. Una captura de oferta al comprador con PDF idéntico a la versión aprobada:
   `26dd283eeeef72bbd576757250b415e747c1db894bb9fbfdd34816bc945e37a1`.

Barreras verificadas antes de envío: captura fuera del webroot y privada; cero otros filtros de correo; HTTP saliente WordPress bloqueado; `sendmail_path=/usr/bin/false` en el servicio como barrera independiente. **No se entregó correo real** ni se validó un proveedor SMTP. El MU-plugin local no se instaló en sitios compartidos ni se empaqueta como parte de la aplicación.

## Pendientes cerrados tras autorización de continuidad

### 1. Suite nativa completa verde

Comando sobre copia aislada:

```sh
PHP_INI_SCAN_DIR=~/.local/state/freeplast-herd-validation \
FREEPLAST_TEST_URL=http://127.0.0.1:8098 npm test
```

Fallo reproducido dos veces antes de corregir:

```text
Error: stack harness: the save banner reports its explicit outcome
  at runStackHarness (.../wordpress/scripts/woo-stack-harness.mjs:1226:9)
```

**Causa confirmada: expectativa obsoleta**, no fallo de guardado. La respuesta HTTP real decía `Lista de precios guardada: 3 precios base y 0 referencias por producto u opción`, consistente con la ampliación aprobada del mantenedor (`manual-price-references-local-2026-10-02.md`). El harness esperaba el anterior «3 precios mantenidos».

Se actualizó la frase exacta y se reforzó la comprobación de persistencia: exactamente tres identidades/precios y cero referencias, coincidentes con el aviso. Permanecen los checks de reapertura, fingerprint de productos, ausencia de correo y no filtración pública. Sin cambios de lógica productiva. Suite completa verde: `lead-evidence/native-pending-after.log`. Reproducciones/probe: `native-pending-before.log`, `native-pending-probe.log`; instrumentación temporal retirada.

### 2. Aviso local sin desbordamiento ni superposición

**Causa:** `width:100%` más 24px de padding con `content-box`. Cambio mínimo: `box-sizing:border-box` solo en el aviso. Dos pruebas offline añadidas (43 checks del capturador); ningún CSS global ni ocultamiento.

Chrome independiente sobre solicitud sintética70: **412/412** móvil y **1440/1440** escritorio; aviso visible, identifica simulación, texto dentro de sus límites y **cero controles superpuestos**. Dock móvil intacto. Restaurar temporalmente `content-box` en el DOM reproduce **436/412** y el check falla; se restauró el estilo publicado localmente. Evidencia: `banner-mobile-reproduction.txt`, `banner-mobile-before.json`, `banner-mobile-after.json/png`, `banner-desktop-after.json/png`, runner `banner-check.js`. Ambas imágenes abiertas y revisadas por el lead. El CLI de screenshots devolvió un error de reconocimiento de ruta aunque escribió ambos PNG válidos.

Solo UI de herramienta local, nunca instalada en producción/staging. Antes del retest se confirmó captura privada activa, cero filtros de correo ajenos, HTTP saliente bloqueado y `sendmail_path=/usr/bin/false`. No se enviaron nuevos correos ni se guardó/aprobó la solicitud en este retest.

## Archivos y trazabilidad

- Implementación: `.scratch/herd-quotation-fix/implementation.md`.
- Diffs propios y baseline: `.scratch/herd-quotation-fix/my-changes/`, `baseline/`.
- Validación detallada: `.scratch/herd-quotation-fix/lead-validation.md`.
- Evidencias Chrome/HTTP/PDF: `.scratch/herd-quotation-fix/lead-evidence/`.
- Capturas privadas completas de correo: `~/.local/state/freeplast-herd-validation/mail-capture/` (no webroot ni repositorio).
- Solicitud70 y cuentas/fixtures sintéticas se conservan solo en DB desechable. No son solicitudes comerciales.

No [ASK] ni [STALL]. Pane del agente permanece abierta; no se cerraron panes ni worktrees. Se detiene el servicio temporal y el bridge aislado del lead al cerrar esta ronda. Copia desechable y evidencias se conservan. Chrome emuló escritorio/móvil; no touch real, lector real, evaluación global de diseño ni aprobación fiscal comercial.
