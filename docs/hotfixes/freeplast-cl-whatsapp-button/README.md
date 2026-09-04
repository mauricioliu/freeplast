# Hotfix — freeplast.cl botón "Cotiza por Whatsapp" roto (2026-09-04)

## Síntoma

En toda ficha de producto (`/producto/…`), el botón verde **"Cotiza por
Whatsapp"** no abría WhatsApp: abría **la misma página de producto en una
pestaña nueva**.

## Causa raíz

El campo **Enlace (Link)** del widget botón estaba **vacío** en la plantilla de
Elementor "Ficha de Productos" (elementor_library post **61**, type `product`,
widget id `309ae16`, CSS id `boton-wsp`). Con link vacío, Elementor renderiza
`href="#"`; combinado con el `target="_blank"` del widget, el clic abría la
misma URL + `#` en pestaña nueva. Datos en DB:

```json
"link": {"url": "#", "is_external": "on", "nofollow": "on", "custom_attributes": ""}
```

Afectaba a **todas** las fichas (el botón vive en la plantilla compartida, no
en cada producto). El resto del sitio ya usaba la URL correcta
(header/footer/contacto): `https://api.whatsapp.com/send?phone=56968444265`.

## Fix aplicado (2026-09-04)

Se editó la plantilla **"Ficha de Productos" (ID 61)** en el editor de
Elementor vía wp-admin (sesión Ignacio Maturana) y se fijó el enlace del botón:

```
https://api.whatsapp.com/send?phone=56968444265
```

(design + `is_external` + `nofollow` se preservaron tal cual estaban).
Luego **Actualizar** en Elementor y **Vaciar la caché** global en
WP Super Cache (`Opciones Generales → WP Super Cache`).

Nota: el export oficial de la plantilla regenera los ids de elemento (por eso
el backup tiene el botón como `183a1539` y no `309ae16`); es equivalente para
restaurar por importación.

## Verificación

- Datos: la config del editor (`ElementorConfig.initial_document`) mostró el
  modelo del widget con la URL nueva antes de guardar.
- Render: 6 fichas muestreadas por curl (`traversas-para-bines`,
  `traversas-para-bins-tipo-w`, `caja-frutera`, `caja-cosechera-3-4`,
  `totem`, `pediluvio`, `ladrillo-plastico`) renderizan
  `<a href="https://api.whatsapp.com/send?phone=56968444265" … id="boton-wsp">`.
- Clic real en Chrome: abre pestaña nueva con `api.whatsapp.com/send?…`
  ("Share on WhatsApp"). Ya no re-abre el producto.
- Sin regresiones: menú, carrusel y el hotfix anterior del contrato
  Elementor (`__fpElementorContractFix`, 2026-09-03) siguen presentes y
  operativos.

## Backup

`ficha-de-productos-template-61-backup-2026-09-04.json` — export oficial de la
plantilla ANTES del cambio, restaurable vía
Plantillas → Importar de Elementor.

## Pendiente con cliente (no bloqueante)

- Mejora opcional: mensaje prellenado por producto, p. ej.
  `?phone=56968444265&text=Hola, quiero cotizar: <producto>` — requiere
  dynamic tag o JS en la plantilla; se propone para el nuevo sitio WP.
- Este fix vive en la plantilla Elementor del sitio legacy; el nuevo WordPress
  (tema de bloques, sin Elementor, en construcción en este repo) lo reemplaza.
