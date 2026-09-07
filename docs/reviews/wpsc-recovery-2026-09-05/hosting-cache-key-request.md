# Borrador para soporte — NO ENVIADO

**Asunto:** freeplast.cl: respuestas cruzadas entre URLs reescritas, reproducible sin WordPress

Hola:

Además de la pérdida de OPcache comunicada en el otro borrador, aislamos el incidente de fichas cruzadas en la capa del hosting.

El 5 de septiembre de 2026, 18:37 UTC, creamos temporalmente un directorio independiente de WordPress. Su único `index.php` devolvía JSON sintético identificando la ruta y el instante de generación; una regla local reescribía `/short/alpha/` y `/short/beta/` a ese archivo. No cargaba WordPress ni WP Super Cache, ni accedía a una base de datos.

1. Con `Cache-Control: max-age=3, must-revalidate`, pedir `beta` 1,6 segundos después de `alpha` devolvió el JSON de **alpha**, incluido el mismo instante de generación. La segunda respuesta tenía `Age: 1`. Se reprodujo en dos pares.
2. Con `private, max-age=3, must-revalidate`, 4/4 respuestas fueron correctas, sin `Age`.
3. Con `no-store, private`, 4/4 correctas, sin `Age`.
4. El servidor añadía además `Cache-Control: max-age=0, public` en todos los casos. Esa cabecera no la emitía el PHP sintético.

El directorio de prueba se retiró. No alteramos la configuración de Apache/LSAPI para obtener estos resultados.

Solicitamos:

- Identificar la capa que almacena/reutiliza esas respuestas y revisar su clave con URL original frente a destino interno de rewrite. No sabemos aún el módulo exacto.
- Comprobar que dos URLs distintas no comparten objeto cacheado y que no se reutilicen respuestas privadas, con cookies, de administración o formularios.
- Revisar el origen de la cabecera `max-age=0, public` añadida a respuestas PHP que ya declaran `private, no-store`.
- Proponer un cambio limitado a esta cuenta y una prueba antes/después, sin cambiar handlers/PHP ni políticas globales sin aprobación.

Como mitigación, recuperamos WP Super Cache en modo Simple/PHP con archivos privados por URL y respuesta HTTP `private, no-store, no-cache, max-age=0, must-revalidate`. Solo cacheamos GET anónimos de portada/catálogo, sin cookies, query ni Authorization. El barrido posterior de 44 comprobaciones de catálogo pasó, sin cruces. **No retirar esa protección ni cambiar a modo Expert para probar ajustes del servidor.**

También observamos durante un fallo PHP de nuestra instrumentación que algunas respuestas HTTP 200 contenían el cuerpo de una página anterior. El fallo de la sonda ya se corrigió y la instrumentación se retiró; agradeceríamos revisar si existe entrega de contenido obsoleto ante errores y con qué clave se selecciona. No atribuimos ese comportamiento a un módulo concreto sin sus logs.

Esto es independiente de la petición de retención del grupo LSPHP/OPcache. No solicitamos actualizar WordPress, WooCommerce, Elementor o PHP, desactivar seguridad ni tocar el staging nuevo.

Gracias.
