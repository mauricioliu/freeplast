# Hotfix — freeplast.cl menú móvil + carrusel roto (2026-09-03)

## Síntomas

- El menú hamburguesa (widget *Nav Menu* de Elementor Pro en el header) no abre en móvil.
- El carrusel hero (widget *Slides* de Elementor Pro) no cambia de imagen con las flechas.

## Causa raíz

El sitio corre **Elementor (free) 3.1.4 + Elementor Pro 2.10.3**. Elementor 3.1
cambió el contrato de `HandlerBase` (véase
https://developers.elementor.com/a-new-method-for-attaching-a-js-handler-to-an-element/):
`isActive(settings)` ahora se invoca **antes** de asignar `this.$element`.

Los handlers de Pro 2.10.3 (`sticky`, entre otros) fueron escritos para el
contrato viejo y dereferencian `this.$element` (todavía `null`) durante
`__construct`:

```
TypeError: Cannot read properties of null (reading 'data')
    at child.isActive (elementor-pro/.../frontend.min.js)
    at child.__construct (elementor/.../frontend-modules.min.js:3090)
```

Ese throw ocurre dentro del dispatch por-elemento de Elementor
(`runReadyTrigger` → `doAction('frontend/element_ready/global', …)`), aborta el
loop y **ningún widget posterior recibe su handler JS**: el toggle del menú
nunca se bindea y Swiper nunca se inicializa. Un bug = todo muerto.

Agravante: el 2026-08-31 alguien renombró `wp-content/plugins/elementor-pro` →
`elementor-pro.disabled-20260831` (y `essential-addons-elementor` → `…disabled…`)
intentando desactivar Pro, pero el plugin **sigue activo** cargando desde la
ruta renombrada (`active_plugins` apunta al nuevo path). Estado half-disabled.

## Hotfix aplicado

Snippet WPCode (HTML, *Site Wide Header*) que restaura el contrato pre-3.1:
atrapa la asignación de `window.elementorModules`, envuelve
`frontend.handlers.Base.prototype.__construct` y pre-asigna `$element` antes de
que corra `isActive()`. Con `$element` asignado, los handlers viejos evalúan
limpio (`data('sticky')` → `undefined` → el handler simplemente no se activa) y
el dispatch ya no aborta: menú y carrusel vuelven a funcionar.

Verificado en Chrome real a 412 px contra https://freeplast.cl/ antes de
aplicar: toggle `aria-expanded` pasa a `true`, dropdown visible, Swiper inicializa
(6 slides con clones de loop) y las flechas cambian de slide.

El snippet es aditivo y reversible: borrarlo en WPCode revierte todo. Es
inocuo si algún día se actualiza Elementor/Pro a versiones modernas.

## Arreglo de fondo (pendiente, decidir con cliente)

- La pareja instalada es de 2021: free 3.1.4 + Pro 2.10.3 + WooCommerce 4.2.5.
- O rollback de free a 3.0.16 (contrato viejo, pareja original), o update
  coordinado free+Pro a versiones modernas pareadas (requiere licencia Pro
  válida) — con backup UpdraftPlus previo.
- O simplemente migrar al nuevo sitio WordPress (tema de bloques, sin
  Elementor) que ya está en construcción en este repo.

## Snippet

Véase `freeplast-cl-elementor-contract-hotfix.html` en esta carpeta.
