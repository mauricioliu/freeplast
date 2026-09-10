# #56 / #64 — biblioteca PDF aprobada y pruebas nativas

## Decisión y alcance

El dueño aprobó expresamente Dompdf 3.1.6, marca actual del sitio y Manrope, sin fotografías, condiciones legales ni datos bancarios nuevos. Decisión [registrada y releída en #56](https://github.com/mauricioliu/freeplast/issues/56#issuecomment-5623666677) antes de implementar. Esto cumple el criterio original; no lo flexibiliza para justificar el writer previo. ADR-0011 sustituye esa justificación.

Trabajo aislado en `ralph/issue-56`, worktree `/home/mauricio-liu/Projects/freeplast-issue56-fix`, base de esta fase `4b16aea8a11d23dac2e1a025090786398bba0ac9`. Sin staging, producción, Ralph, dispositivos, datos comerciales, correo real, push, merge ni cierre de issues. El `main` y su WIP permanecen intactos.

## Entregado

- Writer propio retirado. `quotation-document.php` renderiza exclusivamente la proyección de la versión congelada, con Dompdf **3.1.6**. No hay otro cálculo comercial ni fallback al writer.
- Distribución upstream completa y sin modificaciones: **319 archivos**, comparados byte a byte contra el ZIP con SHA-256 `05df8ee4907325ed2e09a139de9784325f761b43a049297155499270163ac94d`, coincidente con el digest del release oficial. El lock fija biblioteca, archivo y cada miembro.
- Marca del tema copiada sin alteración y Manrope existente convertida a TTF estática 400/700; procedencia, herramienta fijada, receta y OFL en `pdf-assets/README.md`. No se descargan activos al emitir.
- El checker verificó **323 hashes** (319 biblioteca + 4 activos); el empaquetador ahora llama a la misma verificación antes de escribir paquetes. No se ejecutó un release ni se instalaron paquetes en staging. Conservadas las fuentes y licencias upstream: LGPL-2.1, LGPL-2.1-or-later, LGPL-3.0-or-later del componente SVG y MIT. No se afirma que todo el bundle sea únicamente LGPL-2.1.
- PHP/JavaScript y recursos remotos deshabilitados en Dompdf; URI de recursos limitada a archivos locales y chroot de activos. Strings de comprador/productos escapados. Conflictos de clases de otra distribución fallan cerrados, no mezclan proveedores.
- Activos requeridos ausentes/alterados o biblioteca ausente ⇒ documento pendiente, sin correo ni sustitución silenciosa. Cache de fuentes y adjuntos en directorios aleatorios 0700, fuera de uploads; limpieza al terminar. El adjunto conserva exactamente los bytes almacenados.
- Fecha numérica `d/m/Y` mediante `wp_date`, para el timestamp Unix congelado y la zona horaria configurada de WordPress, sin depender del idioma inglés del stack de pruebas.

## Pruebas primero y hallazgos del desarrollo

Se mantuvieron las interfaces acordadas: aprobación del dueño, emisión/envío ante fallos de almacenamiento y renderer. El PHP offline carga los módulos reales; WP/DB/mail son sus fronteras simuladas. El stack nativo usa sesiones HTTP, WordPress/Woo y SQLite físicos.

Rojos observados y corregidos:

1. El lector independiente `pdfinfo` no reconocía Dompdf 3.1.6 en el documento del writer previo; después lo reconoce, con valores revisados y Manrope embebida.
2. El adjunto antiguo estaba directamente en el directorio temporal compartido; el transporte ahora lo observa dentro de un directorio 0700 aun con umask permisiva.
3. La revisión detectó que las aprobaciones posteriores **en el mismo proceso** perdían Manrope: Dompdf conserva una cache estática familia→ruta, pero la operación anterior ya había borrado su directorio privado. Se añadió una prueba que falló por sustitución con Helvetica. Ahora cada render usa un alias CSS propio para la misma Manrope; los nombres/fuentes TTF no cambian y vendor no se parchea. La prueba pasa.

Además se comprobó una oferta de cuarenta nombres largos: todos los productos, 40.000 CLP netos, 7.600 de IVA de prueba, 47.600 de total y vigencia sobreviven a la paginación. Un primer fallo de ese test provenía del lector: un identificador se partía después del guion; se corrigió la aserción para aceptar el salto de línea, no se presentó como pérdida de productos arreglada en el renderer.

La primera prueba nativa nueva de concurrencia falló porque el servidor PHP preaceptó ambos sockets en un solo worker. Se cambió la coordinación de prueba: iniciar B cuando A ya llegó a la barrera **antes del INSERT**, no cuando A termina. Dos PID distintos quedan registrados; así se observa solapamiento verdadero, no un reintento serial disfrazado.

## Evidencia nativa y final

Última suite completa sobre el código final:

```sh
PHPRC="$RUN_DIR/php.ini" FREEPLAST_TEST_URL=http://mliu:8096 npm test
```

- **339 comprobaciones** del borrador offline.
- **4 tests** del contrato de empaquetado: distribución válida, alteración, archivos inesperados/ausentes y manifiesto incompleto. Solo alteran copias temporales propias.
- **659 comprobaciones nativas**, incluyendo:
  - dos primeras aprobaciones en procesos PHP distintos, una versión, un correo interceptado y reintento sin cambios;
  - errores reales de SQLite mediante triggers `RAISE(ABORT)` en INSERT, UPDATE del PDF y UPDATE del resultado después del transporte; sin mocks de `wpdb` ni retorno simulado de SQL;
  - ningún correo antes del PDF duradero; estado `unknown` duradero después de fallo post-correo; reintentos sin duplicados; recuperación explícita tras falla inicial sin fila;
  - biblioteca, marca o fuente ausentes en la instalación **desechable**, restauradas por `finally` sin alterar fuentes del repo;
  - renderer que devuelve vacío o arroja excepción, además del documento inválido del recorrido previo;
  - permisos y CSRF con sesiones/nonce válidos del harness existente;
  - PDF parseado por Poppler, valores de la vista previa y SHA-256 del adjunto idéntico al de los bytes congelados.
- **1867 checks finales** del checker: mezcla sintaxis, dependencias y recorridos; incluye los 659 nativos. No sumar los contadores como si fueran categorías disjuntas ni llamarlos 1867 unit tests.
- PHP lint, Node syntax y `git diff --cached --check` del código propio correctos. El chequeo de whitespace de TODO el agregado avisa sobre espacios finales upstream dentro de vendor: se conservaron intactos para no crear un fork. La suite completa se repitió después del hallazgo de cache de fuentes; no se conservó el verde anterior como evidencia suficiente.

Entorno: WordPress 7.1, Woo 11.1.0, Quotes for WooCommerce 2.13, PHP 8.3.32 y SQLite real. **No MariaDB/MySQL ni prueba de equivalencia con producción.** Correo interceptado en `pre_wp_mail` y, por separado, `sendmail_path=/bin/false` desde antes de provisionar. Google simulado en el transporte. Logs y credenciales desechables permanecen privados bajo `.build` / el directorio indicado por `/tmp/freeplast-issue56-native-current`; último log `full-dompdf-commit.log`. No copiarlos a GitHub.

Al cerrar: puerto 8096 sin listener, cero triggers `fpw_test_*`, sin mu-plugin de barrera. Se conservan únicamente registros sintéticos, evidencia de PID y logs en el build ignorado. La [muestra PDF](issue56-pdf-samples/quotation-synthetic.pdf) versionada es deliberadamente sintética, no un documento comercial ni una URL pública del flujo real.

## Standards — revisión local

Base fija `4b16aea`; revisión del delta propio, no auditoría nueva de 319 archivos upstream. No hubo subagentes disponibles: las dos pasadas son locales, no independientes. Fuentes: instrucciones del repo, `CONTEXT.md`, ADR-0005/0009/0011 y el baseline de smells del skill de review.

**0 infracciones nuevas pendientes identificadas.** El renderer se separa de la aprobación/transporte; el cuerpo PDF solo formatea la proyección común. La prueba nativa nueva queda en su propio módulo. Las comprobaciones de distribución se comparten entre checker y empaquetador. Se retiró el código muerto del writer en vez de conservar una segunda ruta de emisión. Sin forks de proveedores. El defecto de cache observado durante la revisión está corregido y cubierto, no ocultado por las pruebas del primer PDF.

## Spec — revisión local

Contrato de biblioteca/activos de #64 implementado con decisión explícita; fallos y concurrencia inicial de #56 observados en el stack nativo del proyecto. Las anteriores carencias de biblioteca y de corrida nativa ya no describen esta fase.

**1 gate de aceptación pendiente:** revisión humana del aspecto del PDF y de las superficies privadas/accesibilidad pertinentes. Se observaron imágenes rasterizadas y datos de un lector PDF, no teléfono físico, teclado/foco ni aprobación humana. El detector mecánico de los módulos PDF no encontró advertencias; eso tampoco es aceptación visual. La tasa 19% y los importes del fixture no aprueban política fiscal/comercial.

No cerrar #56/#64 ni desbloquear/ejecutar merges automáticamente. Publicación, integración, validación de dispositivos y despliegue requieren sus autorizaciones correspondientes.
