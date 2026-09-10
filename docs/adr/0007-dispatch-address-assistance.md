# 0007 — La asistencia de dirección de despacho es un widget oficial detrás de una configuración ausente por defecto, con procedencia guardada como reclamo revisable

**Estado:** decisión técnica del corte 10 de la #49 (issue #59), registrada por el agente dentro del alcance autorizado de la especificación (asesoría solo en el campo de dirección del formulario clásico; sin rutas, sin precios, sin despliegue). No autoriza credenciales reales ni modifica el #49.
**Contexto:** [ADR-0001](0001-woocommerce-quote-only.md) · [ADR-0004](0004-quote-draft-durable-relationship.md) · issue #59 (bloqueada operativamente por #46 hasta su corte).

## Contexto

El formulario clásico de #46 ya recibe la Dirección de despacho como textarea nativo condicional. El dueño aprobó asistir ese único campo con el autocompletado de direcciones de Google sin perder el ingreso manual: «Google ausente, una dirección rural o una coincidencia amplia no impiden recibir una solicitud válida». Falta el insumo externo (clave de navegador autorizada con restricciones, cuotas y términos) — la integración debe ser honesta sin él.

## Decisión

1. **Widget oficial vigente, nunca un mejora-campos sobre el textarea:** el elemento `PlaceAutocompleteElement` de la biblioteca Places de la Maps JS API se inserta como control propio EN la ranura de dirección, encima del textarea nativo, que conserva nombre, etiqueta, serialización y requisitos. El widget no lleva `name`: nunca serializa nada propio al POST nativo. La carga usa el bootstrap asíncrono documentado (`loading=async`, biblioteca `places`, clave y versión de la configuración) y viaja solo la consulta tecleada — jamás RUT, correo, historial ni productos. La atribución y la marca la porta el propio widget; sin Google cargado no se muestra marca alguna.
2. **Configuración ausente por defecto:** `fpw_places_config()` solo entrega configuración a través del filtro homónimo; sin ella el script `places.js` ni siquiera se encola — cero contacto con Google — y el ingreso manual sirve solo. La clave restringida por referente, las cuotas y los términos son un prerrequisito operativo aparte, no código.
3. **La procedencia es un reclamo, no evidencia:** una selección exacta o amplia deja en el registro únicamente la dirección confirmada, el Place ID bien formado (`[A-Za-z0-9_-]{8,255}`) y el alcance (`exacta`/`amplia`) — nunca coordenadas ni respuestas crudas. Cualquier pieza malformada, contradictoria o arbitraria (campos ocultos que no sean los dos portadores registrados `fpw_place_id`/`fpw_place_scope`, normalizados por Woo como `fpw_attempt`) degrada a «manual» en vez de rechazar una solicitud válida: el servidor jamás trata lo que dice el navegador como verificación.
4. **Invalidación explícita:** editar o borrar el texto invalida el Place ID anterior (evento `input`); «Sin despacho» descarta el destino Y toda asociación de lugar en el cliente y, en defensa en profundidad, el servidor no persiste nada de esto sin despacho. El fallo del proveedor, la cuota o la ausencia de selección exacta anuncian el estado en una región `role=status` y dejan el ingreso manual plenamente válido.
5. **Revisión privada honesta:** el snapshot del borrador (esquema 2, aditivo) lleva `source`/`place_id`/`scope` del destino, y la pantalla del dueño distingue «Confirmada con el asistente» (con alcance y Place ID, escapados), «Ingresada manualmente» y «Sin registro» para registros anteriores; una coincidencia amplia se muestra como tal («revisar número y comuna»), jamás como punto de entrega certificado.

## Consecuencias

- «¿Por qué no se geocodifica al recibir?» — este corte no consulta rutas ni calcula precios; la consulta de distancia (corte posterior) resolverá el destino por un camino confiable cuando el dueño la pida, sin convertir este reclamo en evidencia.
- Sin configuración autorizada no hay validación del widget real: los recorridos prueban el contrato del servidor y del navegador con transporte simulado; declarar validado el servicio real exige credenciales y evidencia separadas (bloqueo externo vigente).
- El filtro de región (`includedRegionCodes: ['CL']`) es una ayuda de sugerencias, no la política de cobertura comercial; una dirección fuera de las sugerencias sigue siendo un ingreso manual válido.
- El `schema` del borrador sube a 2 de forma aditiva: los registros anteriores leen con «Sin registro» en procedencia, sin datos inventados.
