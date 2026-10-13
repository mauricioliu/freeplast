# Comparación e identidad

Comparación local contra `wordpress/data/products.json` previo al cambio, no lectura del staging actual. WooCommerce sigue siendo autoridad. Los 15 `source_id` se emparejan por nombre con p.6–20; se mantienen todos los slugs, rutas históricas, títulos, opciones/variaciones y fotos. Los otros dos productos (pediluvios y ladrillo) permanecen íntegros. IDs numéricos Woo actuales: no consultados, deben verificarse antes de aplicar.

## Contradicciones / decisiones

- Universal: resumen p.2 y web dicen ancho 445 mm; cuatro fichas p.8–11 dicen 444 mm. Prevalece 444.
- Pollera: resumen p.5 parece reutilizar los datos del Tote (800 g, 500 x 280 x 190 mm); web/referencia dice 600 x 400 x 170. Prevalece p.19: 600 x 400 x 180 mm, 1350 g aprox.
- G2 p.4 frente a Romano p.17: no afirmar equivalencia, renombrar ni crear otro producto. Mantener identidad Romano y alias únicamente como pendiente de validación interna.
- Categorías: p.2–4 agrupan Tote, cuatro Universal y tres traversas en Agrícola; referencia los tiene en Otros. Conservar clasificación actual hasta aprobación explícita (no cambiar URLs de categoría silenciosamente).
- Frutera: 44 l en resumen p.3/web, ausente de ficha p.14. Conservar 44 l con procedencia separada p.3; no atribuirlo a p.14.
- Cosechera: material y exterior ausentes p.7, presentes p.2 y referencia (PEAD reciclado; 600 x 400 x 180 mm). Conservar y citar p.2.
- Tote: p.6 omite unidades en ambas dimensiones. Exterior mm ya presente en referencia; interior se transcribe sin unidad, pendiente confirmar mm; no inventarla.
- Tomatera: p.12 dice PP, no especifica virgen/reciclado; se usa PP de ficha. No repetir resistencia de 18 kg del resumen en la nueva descripción técnica.
- Paltera: conservar 30 l literales p.15, no resolver aparente incompatibilidad geométrica por cálculo.
- Sin capacidad nueva para Cosechera, Frutillera, Pollera ni traversas; sin peso ni medidas pallet para Romano.

## Alcance antes de escribir código

Datos y notas en JSON; plantilla única de ficha; fixture sintético `bootstrap.mjs`; prueba PHP de ficha y test local de integridad de catálogo; runbook de staging con lista permitida. Importador histórico `migrate-to-woo.php` se inspecciona pero NO se reutiliza ni cambia: recrearía alcance comercial/configuración. No exports ni dependencias nuevas. Ningún precio, mínimo/paso, solicitud, opción, medio o URL se modifica. La interfaz mostrará más filas en el disclosure, mismo resumen de cuatro campos. Riesgo de sobrescribir ediciones Woo posteriores: solo aplicar tras diff del estado nativo y respaldo, nunca sincronizar JSON.
