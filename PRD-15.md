# PRD-15 — Adaptador WIX de consulta técnica por código exacto

## 1. Problema y contexto documental

### 1.1 Ficha documental

| Campo                | Valor                                                                                                                |
| -------------------- | -------------------------------------------------------------------------------------------------------------------- |
| Proyecto             | Filtroven; documento propio, no importado del boilerplate                                                            |
| Tipo                 | `PRD`                                                                                                                |
| Estado canónico      | `borrador`                                                                                                           |
| Edición              | **FINAL 1.1**, ajustada por autorización expresa conforme a auditoría crítica                                        |
| Fecha                | 2026-10-19, UTC                                                                                                      |
| Audiencia            | Producto, equipo backend, QA y mantenedores                                                                          |
| Fuentes verificables | [AGENTS.md](AGENTS.md), [política documental](docs/governance/documentation-policy.md), evidencia identificada en §9 |

**FINAL** identifica el cierre editorial de esta especificación: sus reglas V1 quedan definidas aquí.
El estado `borrador` expresa que no se ha ratificado como guía estable conforme a la política documental;
no significa que sus decisiones funcionales estén sin resolver. La autorización recibida es para redactar este PRD
y ajustar su especificación y enlace de descubrimiento, no para construir la aplicación ni habilitar el servicio en producción. El adaptador descrito no se declara implementado.

La edición documental `1.1` no es `contract_version`: se precisa el contrato inicial `1.0`
antes de su primera implementación, sin declarar clientes existentes ni una migración ya realizada.

PRD-01 a PRD-14 y los ADRs importados son contexto histórico del origen,
no requisitos ratificados de Filtroven.
Este documento es una especificación nueva e independiente;
no presupone que los módulos descritos en aquellos PRDs sean su fundamento de negocio.

### 1.2 Problema

Una búsqueda pública de WIX puede devolver variantes cercanas, resultados regionales distintos
y contenido repartido entre GraphQL, HTML y metadatos de recursos.
Convertir el primer resultado en «producto encontrado» introduciría falsos positivos.
Convertir un timeout, una página incompleta o un esquema desconocido en «no existe» introduciría falsos negativos.

Filtroven necesita una consulta técnica reutilizable que conserve la evidencia
y distinga identidad confirmada, ausencia acotada, ambigüedad e imposibilidad de concluir.
El consumidor debe poder usarla sin un dominio local de productos
ni una interfaz de usuario.

## 2. Objetivo

Especificar un adaptador interno PHP que,
mediante `fetch(string $wixCode): WixLookupResult`,
consulte exclusivamente WIX y entregue un resultado estable, verificable y honesto
sobre su identidad, procedencia y cobertura.

El éxito se mide por cumplimiento de invariantes y criterios de aceptación (§6 y §10),
no por devolver siempre datos
ni por alcanzar una latencia o disponibilidad del proveedor
que no se ha medido.

## 3. Alcance V1

### 3.1 Incluido

- Entrada literal del código WIX, resolución exacta y validación de identidad en dos etapas.
- Búsqueda pública GraphQL y lectura de ficha HTML mediante HTTP y DOM/XPath dentro del monolito PHP.
- Perfil primario `es-co` y fallback condicionado `en-ap`, con trazabilidad y presupuesto compartido.
- Identidad, dimensiones, especificaciones, aplicaciones, referencias OE, metadatos de imágenes y documentos, y observaciones técnicas publicadas que puedan capturarse.
- Contrato interno independiente del esquema upstream; cobertura por sección y errores operativos tipados.
- Pruebas futuras offline basadas en evidencia curada y casos sintéticos identificados.

### 3.2 Excluido

- Dominio `products`, tablas de catálogo propio, stocks, precios, ventas y lógica comercial.
- Cálculo o recomendación de equivalencias, catálogo multimarcas y consulta de otras marcas. Las referencias OE publicadas se conservan como evidencia, sin convertirlas en equivalencias validadas.
- IA, frontend, API HTTP pública y jobs propios del adaptador.
- Descarga de imágenes/documentos, almacenamiento de assets o integración con Storage en V1. Esta exclusión es una decisión de alcance, no una afirmación sobre un PRD anterior inexistente.
- Navegador automatizado en ejecución, crawlers generales, exploración de regiones no certificadas y dependencias nuevas.
- Caché de aplicación del adaptador, TTL, invalidación y resultados históricos servidos como lookup. Su eventual incorporación requiere alcance posterior explícito.

### 3.3 Límite arquitectónico

Se separan las responsabilidades de transporte, resolución de candidatos, parsing y DTO de salida.
La ubicación puede ser una integración WIX bajo la estructura existente de `app/`;
este PRD no obliga a crear un árbol concreto, siete archivos
ni clases adicionales con nombres prefijados.

El adaptador traduce fuentes externas a su contrato.
No conoce modelos comerciales ni exige base de datos, UI, colas
o almacenamiento de archivos para funcionar.
El consumidor decide qué hacer con un producto confirmado o con una resolución sin producto.

## 4. Requerimientos funcionales

### 4.1 Entrada e identidad

**RF-01 — Entrada literal.** La firma pública es `fetch(string $wixCode): WixLookupResult`.
Ejemplo válido: `51515`, sin anteponer `WIX`.
`requested_code` conserva exactamente el string recibido.
No convertir a entero, quitar ceros, prefijos o sufijos, cambiar casing ni normalizar silenciosamente.

Se rechazan cadena vacía, caracteres de control y espacios externos, con `invalid_input` y sin tráfico.
No se inventa una gramática universal de códigos WIX.
Un límite de longitud de entrada es una protección finita configurable y explícita (§7), no una regla comercial sobre todos los códigos válidos.
`51515R` debe conservar su letra; `wix51515` no se transforma en `51515`.

**RF-02 — Candidato exacto.** Un candidato exige evidencia de marca WIX
y `sales_designation` exactamente igual al código solicitado, con comparación sensible a mayúsculas.
En la evidencia actual, `product_brand.value = WIX` y `adminValue = wix` identifican la marca según el perfil;
no se usa esa diferencia de representación para normalizar el código.

La marca no se infiere del dominio,
del nombre comercial ni de un sufijo del SKU.
Una coincidencia aproximada, referencia cruzada o variante `MP`/`XP`
no satisface identidad exacta.

**RF-03 — Unicidad comprobada.** V1 agota la paginación válida de búsqueda del perfil
antes de declarar candidato único.
No termina en el primer exacto: no existe garantía observada
de que las páginas siguientes no contengan otra identidad exacta.

Dos o más identidades distintas, cada una demostrablemente válida y exacta, producen `ambiguous`.
Repeticiones de una misma referencia fuente pueden deduplicarse conservando sus ocurrencias y evidencia.
La identidad de referencia debe estar probada por los identificadores publicados; no se deduplican candidatos distintos porque sus títulos sean iguales.
Contradicciones de identificadores no se resuelven adivinando.

<!-- prettier-ignore -->
| Evidencia de identidad | Clasificación obligatoria |
| --- | --- |
| Al menos dos candidatos distintos válidos con marca y designación exactas | `failed/ambiguous`, `ambiguous_identity`; no exige terminar páginas restantes |
| Falta un identificador obligatorio del perfil para probar candidato o unicidad | `failed/incomplete`, `identity_unverified` |
| Identificadores fuente incompatibles impiden demostrar qué identidad representan | `failed/incomplete`, `identity_conflict`; no se inventan dos identidades válidas |
| Ficha contradice marca, designación o vínculo de identidad probado del candidato | `failed/incomplete`, `identity_mismatch`, producto nulo |
| Repeticiones de una misma referencia fuente demostrada, sin contradicciones | Una identidad lógica; conservar ocurrencias y evidencias |

Compartir un SKU no prueba por sí solo identidad si otros campos se contradicen.

**RF-04 — Verificación de ficha.** Tras resolver un único candidato,
la ficha debe cotejar marca y designación con el candidato y `requested_code`.
El SKU y los identificadores fuente participan como evidencia de coherencia cuando el perfil los publica;
no se fabrica un sufijo de SKU ni se supone una codificación universal.
Se comparan identificadores del mismo significado demostrado por el perfil: un GTIN y un SKU publicados
en superficies distintas no son una contradicción por diferir literalmente.

Una discrepancia de marca, designación o identificadores produce `identity_mismatch`, `failed/incomplete` y `product = null`.
Ausencia de evidencia suficiente tampoco confirma identidad.
Solo después de ambas etapas se permite `resolution = exact`.
Un error al abrir o reconocer la ficha antes de esa verificación nunca permite `partial/exact`.

### 4.2 Fuentes y navegación

**RF-05 — Perfiles previstos V1, pendientes de certificación.** La política predeterminada especifica estos perfiles, sin mezclarlos:

| Perfil  | Función  | Endpoint observado                                     | Store                  | Locale observado |
| ------- | -------- | ------------------------------------------------------ | ---------------------- | ---------------- |
| `es-co` | Primario | `https://www.wixfilters.com/api/graphql/catalog-stage` | `pcat_wix_co_store_es` | `es` / `es-CO`   |
| `en-ap` | Fallback | `https://www.wixfilters.com/api/graphql/catalog-prod`  | `pcat_wix_my_store_en` | `en`             |

La operación observada es GET anónimo `product_search_name`,
con variables `search`, `currentPage`, `pageSize` y `filterBy = ALL_FILTER`.
La query y los headers efectivos se fijan mediante fixtures del perfil, no por conjetura basada en el nombre `stage` o `prod`.
Son endpoints internos del sitio público, no una API oficial estable ni una autorización comercial.

La navegación observada combina una regla pública en script `text/json`, `/{locale}/catalog/results/product.html/[URL_KEY].html`, con el `href` real del DOM.
Se verificó llegada HTTP 200 para `51515` y `51515R`.
El adaptador resuelve la URL desde evidencia del perfil y la valida; no construye una ficha a partir de un SKU supuesto.
`www2` y `en-us` quedan fuera de V1: hubo comportamiento variable/500 y no están certificados.

**RF-06 — Paginación concluyente.** Cada página debe validar estructura, tipos esperados,
número de página, tamaño, totales y coherencia con la secuencia.
El final se demuestra con los metadatos reconocidos del perfil y la colección recorrida, incluidos los vacíos válidos;
no se deduce solo de una página sin candidatos exactos.

Repetición de cursor/página, bucles, totales incompatibles, páginas omitidas, deriva observable del servidor,
límites agotados o esquema desconocido impiden declarar búsqueda exhaustiva.
Un candidato observado dentro de ese recorrido incompleto sigue siendo diagnóstico, no producto exacto confirmado.
Si ya se prueban dos identidades exactas distintas, puede finalizarse como `ambiguous` sin fingir exhaustividad.

Los errores GraphQL, incluso con HTTP 200 y datos parciales, invalidan la conclusión de esa respuesta.
Un intento posterior válido puede recuperarlos dentro del mismo presupuesto;
sin recuperación, la resolución es `incomplete`, no negativa.
`not_found_search_terms = null` no demuestra por sí solo ninguna ausencia.

**RF-07 — Fallback condicionado.** Solo un negativo primario válido y exhaustivo habilita `en-ap`.
Error, ambigüedad, búsqueda incompleta, discrepancia de identidad
y producto primario exacto —completo o parcial— detienen el flujo sin fallback.
Un positivo primario no se enriquece ni se combina con el otro perfil.

Con ambos perfiles requeridos válidos y exhaustivos, sin exacto, el resultado es `success/no_exact_match`.
Negativo primario seguido de timeout, error o presupuesto insuficiente en fallback
termina en `failed/incomplete`, sin producto.
La ausencia se afirma exclusivamente en los perfiles requeridos consultados, nunca en todo WIX.

La política de fallback es configurable y se identifica en el resultado. La política V1 prevé ambos perfiles; su habilitación efectiva exige certificarlos conforme a §11.
Si se deshabilita explícitamente, el alcance requerido pasa a ser solo `es-co` y debe quedar visible;
no se permite cambiar regiones o semánticas mediante un flag opaco.
Deshabilitar el adaptador completo devuelve `failed/not_attempted`, sin I/O externo.

### 4.3 Captura y fidelidad

**RF-08 — Captura técnica sin pérdida evitable.** El producto distingue identidad, dimensiones,
especificaciones, aplicaciones, OE, media, documentos y observaciones.
Los campos normalizados útiles no sustituyen las observaciones crudas: se conservan etiquetas repetidas,
texto de valor, unidades publicadas, sección, orden y referencia de procedencia.

Las observaciones son una colección, no un diccionario que sobrescriba una etiqueta repetida.
La normalización, si existe, es adicional y trazable; no corrige científicamente al proveedor ni elimina discrepancias.
Valores como altura `132` frente a `132.003`, rosca `3/4-16 mm` frente al resumen `3/4-16`,
o temperaturas min/max `-17.777` se mantienen con su contexto original.

**RF-09 — Aplicaciones.** Se extraen registros lógicos, no todas las filas `tr` ni todos los enlaces. Solo se deduplican representaciones responsive de la misma aplicación cuando coinciden su identidad y evidencia: fabricante, modelo, motor, fechas e identificadores del enlace, según el perfil.

La identidad del enlace incluye los parámetros que distinguen aplicaciones en el perfil certificado; no basta comparar el path ni eliminar toda la query. Una coincidencia visual no autoriza fusionar registros cuyos parámetros o datos de identidad difieren.

Se conservan motor vacío y fecha literal `9999-12-31`; no se inventa el significado del sentinel. No se aplica esa deduplicación indiscriminadamente a observaciones técnicas o referencias OE. Una sección poblada puede seguir incompleta si falta paginación o contenido declarado por el perfil.

**RF-10 — Imágenes y documentos como metadatos.** Se conservan referencias, roles, formatos declarados y metadatos publicados con procedencia. `mainImage` no implica unicidad ni selecciona automáticamente el «mejor» recurso. La colección admite varios recursos con ese rol.

No se afirma disponibilidad del archivo, MIME comprobado, hash de contenido ni descarga exitosa. Una galería HTML, una colección de metadatos y su paginación son superficies distintas; ninguna demuestra por sí sola que se capturó la otra. Los documentos vacíos observados no prueban descarga de documentos publicados. En V1 no se descargan binarios.

**RF-11 — Cobertura explícita.** Cada sección obligatoria tiene estado y completitud conforme a §5.3. Un vacío publicado válido puede estar completo; un contenido capturado no necesariamente lo está. Si una sección obligatoria falla después de confirmar identidad, se retorna producto parcial con la brecha, sin degradar a negativo.

Una plantilla principal desconocida impide confirmar identidad y termina en `failed/incomplete`. Una sección obligatoria reconocida pero no soportada, dentro de una ficha con identidad ya verificada, provoca `partial/exact`. El parser captura observaciones adicionales identificables; no promete detectar universalmente cualquier sección futura desconocida.

Después de confirmar identidad, un fallo local de extracción definido no interrumpe las secciones independientes:
se inspecciona el DOM disponible y se continúan las operaciones independientes permitidas con presupuesto restante.
Una dependencia fallida deja sus dependientes `not_checked`, con razón; no simula vacíos.
Al agotarse plazo o cupo no se inicia más tráfico y las secciones pendientes indican la causa.
El bloqueo de una acción insegura afecta esa acción y sus dependencias, no todo el DOM independiente,
salvo que la fuente completa resulte no confiable. Si aparece una contradicción posterior de identidad,
se invalida el producto previamente confirmado: `failed/incomplete`, `product = null`, `source = null`;
`coverage.sections.identity` pasa a `failed`, `complete = false`, con razón y evidencia de la contradicción.
Solo se conservan datos como evidencia diagnóstica, nunca como producto parcial.
Estas reglas no capturan indiscriminadamente excepciones de programación (RF-12).

### 4.4 Errores, trazabilidad y frescura

**RF-12 — Errores operativos.** Se devuelven diagnósticos tipados para entrada inválida, adaptador deshabilitado, configuración inválida, transporte, HTTP, GraphQL, esquema, paginación, presupuesto, identidad y parsing. El catálogo inicial de códigos se fija en §5.4. Los errores de programación no se ocultan mediante un `catch` universal ni se convierten en un negativo o parcial aparentemente válido.

**RF-13 — Procedencia.** Resultado, intentos y observaciones deben poder vincularse sin atribuir todos los datos a una sola URL. Se separa la fuente del producto seleccionado de la historia de búsqueda y de las fuentes GraphQL/HTML/metadatos de cada observación. Se conserva evidencia diagnóstica aun cuando no haya producto confirmado.

**RF-14 — Consumo desacoplado.** Un consumidor PHP debe poder tratar todos los estados válidos sin consultar `products`, la base comercial o una UI. Ningún campo exige crear una entidad local. `product = null` no equivale automáticamente a «no encontrado»: se interpreta junto a `resolution` y alcance.

**RF-15 — Consultas independientes sin caché de aplicación en V1.** Dos llamadas con el mismo código
son dos consultas separadas con sus propios intentos y evidencia; no se reutiliza un payload histórico.
Los instantes de solicitud y captura se distinguen de fechas publicadas por WIX.
HTTP o CDN upstream pueden servir contenido cacheado: no se garantiza frescura de origen.
Una futura caché queda fuera de V1; deberá preservar identidad literal, versiones, política regional,
fechas y cobertura, sin convertir fallos o incompletos en negativos ni parciales en completos.

**RF-16 — Evidencia durable antes de implementación.** La colección efímera existente debe curarse, sanearse y persistirse como fixtures versionadas antes de implementar el adaptador (§9.3). Este trabajo documental no crea esas fixtures ni afirma que ese gate ya se cumplió.

## 5. Contrato interno estable

### 5.1 Sobre y estados permitidos

`contract_version = "1.0"` identifica como string el contrato inicial, no una API REST ni la edición documental. El DTO contiene obligatoriamente:

| Campo              | Responsabilidad                                                                                       |
| ------------------ | ----------------------------------------------------------------------------------------------------- |
| `contract_version` | String de versión del contrato, inicialmente `"1.0"`                                                  |
| `requested_code`   | Entrada literal inmutable                                                                             |
| `status`           | `success`, `partial` o `failed`                                                                       |
| `resolution`       | `exact`, `no_exact_match`, `ambiguous`, `incomplete` o `not_attempted`                                |
| `product`          | Producto de identidad confirmada o `null`                                                             |
| `scope`            | Política, perfiles requeridos para concluir ausencia, perfiles consultados y alcance de la conclusión |
| `source`           | Perfil seleccionado, URL final de ficha y fecha UTC de captura; `null` sin producto                   |
| `attempts`         | Lista ordenada de intentos y evidencias de cada etapa                                                 |
| `evidence`         | Mapa de IDs únicos a evidencias sanitizadas resolubles dentro del mismo DTO                           |
| `coverage`         | Entradas obligatorias y `complete` global                                                             |
| `diagnostics`      | Errores/avisos tipados con severidad y recuperación                                                   |
| `meta`             | Versiones de perfil/parser, tiempos de esta consulta y consumo de límites; sin campos de caché V1     |

Solo son válidas estas combinaciones:

| `status`  | `resolution`     | `product` | Condición                                                                |
| --------- | ---------------- | --------- | ------------------------------------------------------------------------ |
| `success` | `exact`          | No nulo   | Unicidad y ficha verificadas; todas las secciones obligatorias completas |
| `success` | `no_exact_match` | `null`    | Todos los perfiles requeridos negativos, válidos y exhaustivos           |
| `partial` | `exact`          | No nulo   | Identidad verificada; alguna sección obligatoria incompleta              |
| `failed`  | `ambiguous`      | `null`    | Al menos dos identidades exactas distintas probadas                      |
| `failed`  | `incomplete`     | `null`    | No se pudo completar la resolución/verificación de identidad             |
| `failed`  | `not_attempted`  | `null`    | Entrada inválida, deshabilitado o configuración que impide iniciar       |

No existen `partial/no_exact_match`, `failed/exact`, producto sin `exact` ni `success/incomplete`. En negativos completos, `coverage.complete = false`: no hay ficha evaluada; la exhaustividad de búsqueda se declara en `scope` y `attempts`, no se disfraza de cobertura del producto.

### 5.2 Producto y procedencia

El producto mínimo confirmado contiene marca WIX, designación exacta y evidencia de búsqueda/ficha.
Los tipos siguientes son definitivos en sentido conceptual: no imponen clases PHP internas ni un esquema de archivos.
Todo campo listado es obligatorio salvo indicación expresa de opcional; nullable no significa omitible.

#### Producto y tipos de datos

`product` es objeto o `null`. Si existe, contiene `identity` (objeto) y estas **siete listas siempre presentes, nunca nulas**:
`dimensions`, `specifications`, `applications`, `oe_references`, `media`, `documents`, `observations`.
`null` significa campo no publicado/no disponible; `""` conserva una cadena literalmente publicada vacía;
`[]` significa colección sin elementos, y no acredita por sí sola ausencia ni completitud.
Los códigos, identificadores fuente y fechas publicadas son strings literales: sin cast numérico, de años ni de fechas de negocio.
Los valores de `NameValue` y las proyecciones dentro de un registro heredan su `evidence_refs`;
si proceden de superficies diferentes, ese registro debe incluir todas las evidencias correspondientes.
`order` es entero no negativo del orden de aparición dentro de la sección; no es un dato técnico de WIX.
Todas las listas conservan orden y repetidos salvo la deduplicación de aplicaciones expresamente probada en RF-09.

<!-- prettier-ignore -->
| Objeto / colección | Campos y tipos mínimos |
| --- | --- |
| `identity` | `brand: string` (`WIX` comprobado), `code: string` (exactamente `requested_code`), `identifiers: list<Identifier>`, `evidence_refs: list<string>` no vacía con búsqueda y ficha |
| `Identifier` | `name: string`, `value: string`, `evidence_refs: list<string>` no vacía; nombre vinculado a su etiqueta/clave fuente, sin reinterpretar GTIN como SKU |
| `dimensions[]`, `specifications[]` | `Observation` según tabla siguiente, sin mapa por `height` u otra clave normalizada |
| `observations[]` | `Observation`; admite descripción, resumen, notas técnicas y declaración REACH reconocidas, sin imponer valor numérico |
| `applications[]` | `manufacturer_raw`, `model_raw`, `series_raw`, `variant_raw`, `engine_raw`, `period_raw`: todos string nullable; `cells: list<Cell>`; `source_identifiers: list<NameValue>`; `navigation: Navigation`; `occurrences: list<Occurrence>` no vacía; `order: int`; `evidence_refs: list<string>` no vacía |
| `oe_references[]` | `manufacturer_raw`: string nullable; `reference_raw: string` (también vacío literal publicado), `order: int`, `evidence_refs: list<string>` no vacía |
| `media[]` | `Resource` más `position_published`: int nullable; `type_declared`, `format_declared`, `mime_declared`: strings nullable; `roles: list<NameValue>` |
| `documents[]` | `Resource`; etiqueta y metadatos publicados no se sustituyen por validación de descarga |
| `Resource` | `reference_raw: string`; `url_resolved`, `label_raw`: strings nullable; `declared_metadata: list<RawField>`, `order: int`, `evidence_refs: list<string>` no vacía |
| `Cell` | `label_raw`, `value_raw`, `unit_raw`: strings nullable; `value_presence`: `present` o `missing`; `raw_type: RawType`, `order: int`, `evidence_refs: list<string>` no vacía |
| `NameValue` | `name: string`, `value: string`; conserva literalmente identificadores, parámetros y roles publicados |
| `Navigation` | `path`: string nullable; `params: list<NameValue>`; parámetros repetidos y su orden se conservan, vinculados a esa aplicación |
| `Occurrence` | `surface: string` (representación publicada, p. ej. `desktop`/`tablet`), `locator: string`, `evidence_refs: list<string>` no vacía |

`Observation` contiene estos campos obligatorios; `source_key: string|null` es el único campo opcional:

<!-- prettier-ignore -->
| Campo | Tipo / regla |
| --- | --- |
| `label_raw` | String nullable; nunca inventar una etiqueta vacía para un campo ausente |
| `value_raw` | String nullable; string cuando hay valor, incluido `""`; nulo solo si falta el valor o su tipo fuente es null |
| `value_presence` | `present` o `missing`; distingue propiedad ausente de propiedad presente con null |
| `raw_type` | `RawType`: `string`, `number`, `boolean`, `null`, `object`, `array`, `missing` |
| `unit_raw` | String nullable; solo unidad explícitamente publicada, nunca inferida por nombre o magnitud |
| `raw_admin_value` | String nullable; solo el `adminValue` observado de tipo string, sin convertir otros tipos |
| `section` | `string`; bloque técnico de procedencia, no etiqueta normalizada que borre el contexto |
| `order` | `int` no negativo |
| `evidence_refs` | `list<string>` no vacía y resoluble en `evidence` |

`RawField` tiene `name: string`, `value_raw: string|null`, `value_presence`, `raw_type` y `evidence_refs`
con las mismas reglas. Para valor presente no string se conserva su representación JSON en `value_raw`
y su tipo real en `raw_type`: booleano `true` → `"true"`/`boolean`, string `"true"` → `"true"`/`string`.
No se aplana un objeto ni se inventan conversiones a etiquetas/unidades; un valor ausente conserva la fila
con `value_presence = missing`, `raw_type = missing`, `value_raw = null` y evidencia de la ausencia.
Un null publicado usa `present`/`null`/null. Celdas siguen estas mismas reglas.
Campos futuros con tipos no representables se diagnostican como brecha; nunca se descartan silenciosamente.

Los campos de aplicaciones son proyecciones de texto explícito, no normalizaciones científicas:
las celdas preservan cilindrada/unidades, potencia kW y cualquier columna adicional o repetida.
Si la fuente separa fechas, se preservan en `cells`; `period_raw = null` si no publica un período textual conjunto.
`9999-12-31` no se convierte en fin abierto. Los identificadores de fuente conservan su etiqueta y literal.
Solo la evidencia permite unir representaciones responsive; no se deduplica por similitud textual.

La referencia original del recurso siempre se conserva aunque sea path o plantilla no resoluble.
`url_resolved` solo existe si puede resolverse y validarse conforme al perfil; de lo contrario es null,
sin fabricar una URL utilizable ni iniciar acceso. MIME/formato son **declarados**, no comprobados por extensión
o descarga. `roles` no asume booleans universales: el string observado `mainImage = "true"` sigue siendo string;
otros tipos de metadatos quedan tipados en `declared_metadata`. No se deduplica por hashes ni descargas.
Una posición publicada no entera se preserva en metadatos crudos; `position_published` queda null.

#### Registro de evidencia y sobre operativo

`evidence: map<string, Evidence>` usa IDs únicos dentro del resultado. Cada referencia de datos,
intentos, fuente seleccionada y conclusiones de cobertura debe resolver una entrada en ese mismo payload;
no requiere abrir logs, archivos externos ni conocer el manifiesto temporal. Un hash aislado no basta.

<!-- prettier-ignore -->
| Objeto | Campos obligatorios / tipos |
| --- | --- |
| `Evidence` | `attempt_id: string`, `profile: string`; `surface`: `graphql_search`, `html` o `asset_metadata`; `source_url: string`; `final_url`: string nullable; `captured_at: string` UTC, `locator: string`; `raw_excerpt`: string, number, boolean o null |
| `source` | Objeto o null; objeto: `profile: string`, `url: string`, `captured_at: string` UTC, `evidence_refs: list<string>` no vacía de la ficha seleccionada |
| `attempts[]` | `attempt_id: string` único, `profile: string`, `stage: string`, `pages: list<int>`, `requests: int`, `outcome: string`, `evidence_refs: list<string>`, `started_at`, `finished_at`: strings UTC, `budget_used: Budget` |
| `Budget` | `elapsed_ms: int`, `requests: int`, `redirects: int`, `retries: int`, `bytes: int`, `nodes: int`, todos no negativos; límites elegidos registrados operativamente (§11) |
| `scope` | `policy: string`, `required_profiles: list<string>`, `consulted_profiles: list<string>`, `exhaustive_profiles: list<string>`, `conclusion: string` que acota resolución/ausencia |
| `meta` | `profile_versions: map<string,string>`, `parser_version: string`, `requested_at: string` UTC, `returned_at: string` UTC, `budget_used: Budget` |
| `diagnostics[]` | Campos de §5.4 más `section`: string nullable; `evidence_refs: list<string>`; `attempt_id`: string obligatorio nullable |

UTC se representa en ISO 8601 con `Z`; no es una fecha publicada por WIX.
`required_profiles` expresa los perfiles necesarios para concluir ausencia, no una obligación de fallback tras un positivo.
Cada `Evidence.attempt_id` y cada diagnóstico con intento no nulo apuntan a un intento válido;
el perfil coincide. Un intento previo a obtener respuesta puede tener `evidence_refs = []`;
un error de transporte sin captura no inventa evidencia del proveedor.
`source_url` es la URL real de origen validada, sin query secreta; `final_url` es obligatoria y no nula
si cambió tras redirects, y null si no cambió. La sanitización no altera el literal técnico de identidad;
si no permite conservar evidencia suficiente, se declara la brecha en lugar de afirmar exactitud.
`locator` identifica campo JSON lógico (p. ej. `/data/catalogSearch/items/0/product/sku`)
o bloque/fila DOM. `raw_excerpt` contiene el valor y contexto sanitizado suficiente para comprobar la afirmación;
un escalar aislado solo basta cuando locator y contexto identifican inequívocamente el campo.
No exige el cuerpo completo. Un fragmento puede acreditar varias afirmaciones si realmente las contiene.

Las fechas de captura corresponden a obtener cada evidencia en esta consulta, no al retorno del DTO.
Los candidatos no confirmados y las fichas conflictivas solo aparecen como evidencia diagnóstica.
Sin producto, `source = null`; intentos y registro de evidencia conservan procedencia.
No todos los atributos que dicen SKU son equivalentes ni estables entre superficies: §9.4 registra contraejemplos.

### 5.3 Cobertura por sección

Las claves obligatorias son `identity`, `dimensions`, `specifications`, `applications`, `oe_references`, `media`, `documents` y `observations`. Cada entrada declara `state`, `complete` booleano, `reasons` y referencias de evidencia. Su evaluación está acotada al perfil y a su versión reconocida, no a toda la información que WIX pudiera publicar.

`coverage` contiene `sections` (mapa de esas ocho claves), `groups` y `complete: bool`.
Cada entrada de `sections` tiene `state` según tabla, `complete: bool`, `reasons: list<string>`
y `evidence_refs: list<string>`; una conclusión positiva o de ausencia exige evidencia no vacía.
Para `not_checked` sin captura, se permite `evidence_refs = []` y se exige razón causal.

Ninguna de esas ocho claves es opcional para calcular el estado global. Una superficie exigida por el perfil dentro de una clave tampoco puede reclasificarse como opcional para ocultar una brecha. Los datos accesorios fuera de esas superficies pueden enriquecer el resultado, pero no son requisito de `success/exact`; su indisponibilidad solo genera avisos y nunca acredita ausencia o completitud de una sección obligatoria.

| `state`           | Significado                                                    | `complete` permitido                                                       |
| ----------------- | -------------------------------------------------------------- | -------------------------------------------------------------------------- |
| `captured`        | Se capturó contenido de la sección                             | `true` si agotó sus superficies/páginas obligatorias; de otro modo `false` |
| `published_empty` | Contenedor reconocido inspeccionado, explícitamente vacío      | `true` solo con inspección y paginación concluyentes                       |
| `not_published`   | Sección ausente en una plantilla reconocida e inspeccionada    | `true` solo con evidencia de esa ausencia acotada                          |
| `failed`          | Falló la lectura de una sección identificada                   | `false`                                                                    |
| `not_checked`     | No se evaluó                                                   | `false`                                                                    |
| `unsupported`     | Superficie/sección detectada que el perfil no sabe interpretar | `false`                                                                    |

`not_published` no significa «este dato no existe en WIX». No puede derivarse de un selector sin coincidencias en HTML desconocido, ni de un panel no inspeccionado. `observations.complete` significa captura de las observaciones reconocibles por esa versión, no detección universal de futuras secciones.

`coverage.complete` es verdadero únicamente si hay identidad confirmada y todas las entradas obligatorias son completas. `identity` solo puede completarse con las dos etapas verificadas. Si no existe producto, las demás secciones quedan `not_checked`, salvo evidencia de inspección que justifique otro estado, siempre con completitud global falsa.

`groups` es un mapa con tres objetos de un único campo `complete: bool`, derivados sin umbral configurable:
`identity` = sección identity; `technical` = AND de dimensions, specifications, applications,
oe_references y observations; `resources` = AND de media y documents.
No sustituyen las ocho claves ni permiten ocultarlas al consumidor. Un `partial/exact` puede tener
`technical.complete = true`, `resources.complete = false`: el consumidor decide qué grupos necesita,
sin tratar parcial global como producto necesariamente inutilizable ni como completo.
Las superficies requeridas y el criterio observable de cierre por perfil están en §9.4.

### 5.4 Diagnósticos y recuperación

Cada diagnóstico incluye `code`, `stage`, `attempt_id` cuando existe, `severity` (`info`, `warning`, `error`), `recovered` y mensaje seguro. Un error recuperado queda como `warning`, `recovered = true`; no contradice un resultado exitoso. Un fallo terminal queda como `error`, `recovered = false`, y debe explicar la resolución fallida o la sección parcial correspondiente.

Todo `failed` exige al menos un diagnóstico terminal, incluidos `ambiguous` y `not_attempted`. Todo `partial/exact` exige razones de cobertura y diagnósticos terminales vinculados a cada brecha obligatoria, aunque se trate de contenido pendiente y no de un fallo de transporte. Un error no recuperado en resolución, identidad o cobertura obligatoria impide `success`; un problema exclusivamente accesorio se registra como `warning`, sin marcarlo recuperado si no lo fue.

Códigos iniciales del contrato: `invalid_input`, `disabled`, `invalid_configuration`, `transport_error`, `http_error`, `graphql_error`, `schema_error`, `pagination_error`, `budget_exhausted`, `identity_mismatch`, `identity_unverified`, `identity_conflict`, `ambiguous_identity`, `parse_error` y `unsupported_section`. Los detalles operativos adicionales se expresan como atributos diagnósticos seguros, no como nuevos códigos improvisados. `ambiguous_identity` explica `failed/ambiguous` sin confundirlo con caída del proveedor.

No se exponen secretos, cookies, credenciales, trazas internas ni cuerpos HTTP completos como mensajes al consumidor. La evidencia sanitizada y los metadatos de status HTTP pueden referenciarse separadamente para diagnóstico.

### 5.5 Compatibilidad y evolución

- La semántica de campos, tipos, listas, nulabilidad, estados e invariantes es parte del contrato. Una serialización, si se incorpora, debe ser canónica y preservar esas reglas; no crea por sí misma un endpoint público.
- Cambiar significados, eliminar campos, exigir nuevos campos al consumidor o introducir miembros de enum que rompan consumidores exhaustivos exige una versión mayor y migración explícita.
- Nuevas observaciones o atributos técnicos dentro de colecciones extensibles pueden ser aditivos si conservan el contrato existente. No se añaden silenciosamente estados o códigos enumerados bajo esa excusa.
- Versiones de parser y perfil son independientes de `contract_version`: adaptar selectores upstream puede mantener `1.0` si conserva la semántica. La cobertura registra con qué versión se evaluó.
- No se promete que todos los consumidores permanezcan inalterados a través de versiones mayores. La estabilidad se prueba contra ejemplos de consumidor y combinaciones válidas, no contra el JSON bruto del proveedor.

La precisión editorial 1.1 cierra tipos del contrato inicial antes de implementarlo;
las reglas de compatibilidad anteriores rigen su evolución posterior. El ejemplo completo del anexo A
es sintético, no una nueva captura ni evidencia de certificación.

## 6. Invariantes

| ID     | Regla verificable                                                                                                              |
| ------ | ------------------------------------------------------------------------------------------------------------------------------ |
| INV-01 | `requested_code` es idéntico a la entrada; rechazo de entrada o deshabilitación no generan I/O externo.                        |
| INV-02 | `product != null` si y solo si `resolution = exact`; exactitud exige candidato único tras búsqueda agotada y ficha verificada. |
| INV-03 | Sin identidad confirmada no hay `partial`; un candidato visto no equivale a producto.                                          |
| INV-04 | `no_exact_match` solo resulta de negativos válidos y exhaustivos de todos los perfiles requeridos.                             |
| INV-05 | Fallback solo sigue a negativo primario concluyente; no mezcla productos ni reinicia presupuesto.                              |
| INV-06 | Ningún fallo técnico, esquema desconocido o conflicto de identidad se convierte en ausencia.                                   |
| INV-07 | `success/exact` implica cobertura completa; `partial/exact` implica brecha explícita obligatoria.                              |
| INV-08 | Todo valor técnico conserva contexto y procedencia; etiquetas repetidas no se sobrescriben.                                    |
| INV-09 | Vacío, no publicado, no inspeccionado y no soportado son estados distintos con evidencia.                                      |
| INV-10 | Requests, retries, redirects, regiones y superficies comparten límites finitos y contabilizados.                               |
| INV-11 | Metadatos de recursos no certifican descarga ni disponibilidad; varios `mainImage` son válidos.                                |
| INV-12 | Los únicos pares status/resolution emitidos son los de §5.1; V1 no sirve payloads desde caché de aplicación.                   |

## 7. Requerimientos no funcionales

**RNF-01 — Ejecución proporcionada.** PHP del monolito, cliente HTTP y DOM/XPath, sin runtime de navegador ni dependencias nuevas. La comprobación previa con PHP nativo 8.5, sin cookies ni autenticación, demostró viabilidad E2E para los casos observados; no garantiza permanencia del proveedor.

**RNF-02 — Presupuesto finito obligatorio.** Antes de habilitar consultas se configuran y miden límites positivos finitos: longitud de entrada, duración total, conexión/request, número total de requests, redirects, retries, páginas por búsqueda/sección, tamaño de página, bytes descomprimidos por respuesta y acumulados, y volumen de nodos/elementos procesados. La concurrencia saliente también debe tener un límite operativo explícito.

El tiempo se mide con reloj monotónico. Todo request de búsqueda, ficha o metadatos, incluido un retry o redirect, consume el mismo presupuesto global. Los límites por región/superficie son adicionales; nunca amplían el global. Se comprueba el remanente antes de cada operación y durante la recepción/procesamiento; no basta comprobar `Content-Length`. El plazo global incluye parsing y construcción del DOM: estas operaciones deben quedar acotadas por tiempo y volumen procesado, no ejecutarse sin límite para comprobar el plazo solo al terminar. Esperas y backoff consumen tiempo total.

Solo se reintentan operaciones idempotentes con fallos transitorios clasificados, dentro de límites; `Retry-After`, cuando aplica, no autoriza excederlos. Configuración ausente, ilimitada o inválida impide iniciar (`failed/not_attempted`). Agotamiento antes de verificar identidad es `failed/incomplete`; después, si deja una sección obligatoria incompleta, es `partial/exact`. No se inventan valores de SLA, cuotas WIX o métricas de rendimiento: el gate §11 exige registrar valores elegidos y mediciones antes de habilitación.

**RNF-03 — Transporte y contenido no confiable.** TLS verificado; orígenes, esquemas y puertos permitidos del perfil explícitos. Se validan URLs embebidas y cada redirect antes de seguirlo, incluidas resolución de host y exclusión de destinos privados/locales no autorizados, para evitar SSRF. Una URL publicada no obtiene permiso automático de acceso. No se ejecutan scripts del HTML ni entidades externas del parser; el contenido se trata como texto y debe escaparse si un consumidor lo presenta.

**RNF-04 — Respuestas reconocidas.** Se validan HTTP, JSON, `errors` GraphQL, esquema y plantilla antes de interpretar negocio. No se sigue un esquema desconocido por coincidencia parcial de nombres. Los errores de programación conservan visibilidad para depuración, sin filtrarse como detalles HTTP al consumidor.

**RNF-05 — Observabilidad mínima.** Cada lookup registra correlación, código/política según política local de logs, versiones, etapas, perfiles, resultado, latencias, requests, páginas, bytes y límites agotados. Permite distinguir error recuperado, error terminal y dato parcial, sin registrar secretos ni volcar respuestas completas indiscriminadamente.

**RNF-06 — Determinismo de pruebas.** Las pruebas normales se ejecutan offline con HTTP simulado y prohibición de solicitudes no previstas (`Http::fake` y `preventStrayRequests` en la implementación Laravel). Los casos sintéticos se identifican como tales. Una prueba live requiere activación expresa, límites y entorno controlado; no es requisito determinista de CI.

**RNF-07 — Uso comercial condicionado.** El acceso anónimo observado no demuestra permiso contractual de automatización o reutilización comercial. La habilitación productiva requiere verificar y registrar las condiciones aplicables de WIX, el uso permitido y los límites operativos acordados. Este PRD no declara esa verificación realizada ni introduce autenticación supuesta.

## 8. Flujo de resolución

```text
fetch(código literal)
  ├─ inválido / deshabilitado / configuración inválida → failed/not_attempted
  └─ búsqueda es-co, páginas válidas hasta agotamiento
       ├─ fallo / recorrido incompleto → failed/incomplete
       ├─ varias identidades exactas probadas → failed/ambiguous
       ├─ único exacto → ficha es-co → verificar identidad
       │    ├─ falla verificación → failed/incomplete
       │    └─ identidad confirmada → capturar y evaluar cobertura
       │         ├─ completa → success/exact
       │         └─ brecha obligatoria → partial/exact
       └─ negativo exhaustivo
            ├─ política solo es-co → success/no_exact_match (solo ese alcance)
            └─ búsqueda en-ap, con presupuesto restante
                 ├─ fallo / incompleta → failed/incomplete
                 ├─ ambiguo → failed/ambiguous
                 ├─ único exacto → ficha y cobertura, mismas reglas
                 └─ negativo exhaustivo → success/no_exact_match
```

«Único exacto» en el diagrama es candidato único tras agotamiento, todavía no `resolution = exact`. Cada retorno conserva intentos, alcance y diagnósticos; las salidas por errores de programación siguen el mecanismo normal de excepciones, no este flujo de fallos operativos.

## 9. Evidencia y sus límites

### 9.1 Observaciones live ya realizadas

Ventana de comprobación: **2026-10-18, 17:41–18:13 UTC**. No se requieren nuevas consultas WIX para redactar este documento.

| Caso observado          | Evidencia útil                                                                                                                                                         | Lo que no demuestra                                                                   |
| ----------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------- |
| `51515`                 | Exacto distinguible de `51515MP` y `51515XP`; marca y designación publicadas                                                                                           | Que el primer exacto garantice unicidad                                               |
| `51515`, `pageSize = 1` | Páginas 1–3: `51515`, `51515MP`, `51515XP`; total 3, totalPages 3                                                                                                      | Un exacto en página tardía: ese caso será sintético                                   |
| `NOEXISTE51515ZZ`       | Vacío válido sin errores GraphQL                                                                                                                                       | Ausencia mundial ni utilidad aislada de `not_found_search_terms`                      |
| `51515R`                | `es-co` vacío; `en-ap` exacto; navegación de ficha comprobada                                                                                                          | Que quitar `R` sea aceptable o que el fallback cure errores                           |
| HTML `51515`            | 3 dimensiones, 8 especificaciones, 25 OE, aplicaciones y downloads vacíos, galería 2                                                                                   | Exhaustividad de todas las superficies de recursos                                    |
| HTML `42831`            | Captura reportada de 177558 bytes; 11 aplicaciones lógicas, 5 tablas, 93 filas `tr`; desktop/tablet duplican 11 aplicaciones cada uno, 22 enlaces/11 parámetros únicos | Que 93 filas o 22 enlaces sean aplicaciones distintas                                 |
| Inspección `42831`      | Sin XHR nuevo observado tras 8 acordeones, scroll y viewport móvil 390×844 en las ventanas inspeccionadas                                                              | Ausencia universal de lazy loading en todos los productos                             |
| Recursos `51515R`       | 4 metadatos con `mainImage = "true"` (string): 2 JPEG y 2 TIFF; total 4, limit 25, `hasMore = false`                                                                   | Un único principal, disponibilidad binaria o cobertura de toda la galería             |
| Galería y documentos    | Error real CSP/WebAssembly en galería; documentos observados vacíos                                                                                                    | Necesidad de navegador en backend, galería completa o descarga de documentos poblados |

Los bytes de respuesta y de archivo almacenado pueden diferir: para `42831`, la auditoría de la captura almacenada registra 177547 bytes y hashes distintos de cuerpo recibido/almacenado. No se intercambian estas mediciones ni sus hashes; las futuras fixtures conservan ambas representaciones cuando difieran.

### 9.2 Trazabilidad local actual

- Manifiesto efímero: `/tmp/opencode/wix-live-manifest.json`.
- SHA-256 del manifiesto: `5877d5920b780a3435e7168a042af355fb11ce90849dbc54fcb8a9dc3377e93d`.
- Auditoría: `/tmp/opencode/wix-live-fixture-audit.json`; 14 capturas, 10 con nuevos metadatos de captura.
- Verificación local offline repetida el **2026-10-19T12:32:43Z**: `python3 -B /tmp/opencode/wix-live-evidence.py verify-manifest`, **59/59**, `mismatches = []` (también verificada a las 12:25:33Z).

Estas rutas identifican evidencia de la investigación, no fixtures durables del repositorio ni fuentes garantizadas para otro entorno. El hash acredita integridad del manifiesto disponible, no continuidad del servicio ni autorización de uso. No se presentan las 59 entradas como 59 casos obligatorios de prueba.

### 9.3 Gate de fixtures antes de implementar

**Pendiente y obligatorio antes de implementar el parser:** seleccionar capturas representativas, sanearlas y persistirlas versionadas; ubicación recomendada `tests/fixtures/wix`, respetando el directorio `tests/fixtures` existente, sin crearla en esta fase. No existe actualmente un corpus WIX versionado. Cada fixture debe enlazar request, query/variables, headers regionales pertinentes, perfil, URL inicial/final, status, fecha UTC, bytes recibidos/almacenados, hashes y expectativas verificables.

Se excluyen secretos y datos sensibles, y se documenta cualquier transformación con su nuevo hash. Las capturas live se distinguen de variantes derivadas/sintéticas. Deben cubrir búsqueda, ficha, aplicaciones pobladas, vacíos, recursos múltiples y paginación; no se exige copiar toda la carpeta temporal. Fallos, exacto tardío y otros escenarios no observados se generan como sintéticos explícitos, sin afirmar inyección de fallos live.

Las 14 capturas con metadatos no son 59 casos de prueba. Las observaciones del navegador en JSON
son transcripciones de la inspección, no un HAR ni un volcado DOM; no sustituyen cuerpos reproducibles.
El manifiesto mantiene diferenciados hashes/bytes recibidos y almacenados y transformaciones de saneamiento.

### 9.4 Matriz operativa de superficies por perfil

**Capacidad observada, no certificación concedida.** Estos IDs locales identifican el soporte ya inspeccionado offline:

<!-- prettier-ignore -->
| ID | Archivo efímero en `/tmp/opencode/` |
| --- | --- |
| CO-H | `wix-live-php-es-co-51515-detail.response.html` |
| CO-G | `wix-live-php-es-co-51515-search.response.json` |
| AP-H | `wix-live-php-en-ap-51515R-detail.response.html` |
| AP-G | `wix-live-php-en-ap-51515R-search.response.json` |
| APP-H | `wix-live-42831-es.response.html` |
| APP-G | `wix-live-42831-search-es.response.json` |
| ASSET-R | `wix-live-assets-51515R.response.json` |

Estos IDs son referencias de investigación, no sustituyen los IDs autocontenidos de `evidence` del DTO.
El criterio común **C** exige plantilla/esquema reconocidos, inventario de superficies requeridas inspeccionado,
páginas/bucles agotados según el perfil, ningún error obligatorio sin recuperar y evidencia para la conclusión.
La completitud se acota a perfil/versión y superficies declaradas; una muestra no certifica todas las familias.
Cada fila define superficies requeridas, no alternativas donde la primera coincidencia permita ignorar las restantes.

<!-- prettier-ignore -->
| Sección | Perfil | Capacidad / soporte observado | Criterio de cierre además de C |
| --- | --- | --- | --- |
| identity | es-co | CO-G: `data.catalogSearch.items[].product`, `sku`, `urlKey`, `product_brand`, `sales_designation`; CO-H: marca JSON-LD, `data-product-sku` de formulario y código/designación visibles | Búsqueda exhaustiva única y vínculo con ficha probado; JSON-LD `name` compuesto no basta |
| identity | en-ap | AP-G/AP-H: mismas superficies; formulario SKU `51515R_WIX`, código GTIN visible `765809129054` bajo `role=sku` | No asumir equivalencia de todos los atributos llamados SKU; verificar marca/designación e identificadores con semántica de fuente |
| dimensions | es-co | CO-H: `#dimensions`, 3 filas; CO-G: `aa_dimension_string` y resúmenes publicados | Capturar filas y resúmenes/atributos dimensionales publicados, incluso discrepancias, no «una fuente gana» |
| dimensions | en-ap | AP-H: `#dimensions`, 3 filas; AP-G: atributos/resúmenes dimensionales | Igual inventario; unidades solo explícitas y valores originales conservados |
| specifications | es-co | CO-H: `#technicalData`, 8 filas; CO-G: atributos técnicos publicados | Capturar tabla y atributos técnicos reconocidos, sin exigir traducciones no publicadas |
| specifications | en-ap | AP-H: `#technicalData`, 6 filas; AP-G: atributos técnicos, incluidas dos claves de presión distintas | Preservar ambas claves y valores aunque solo uno aparezca en tabla; no corregir supuesto typo |
| applications | es-co | CO-H: `#applications`, «No hay información disponible» explícito; APP-H/APP-G: caso `42831` poblado, 11 lógicas en 5 tablas, 11 desktop + 11 tablet, 22 links/11 parámetros únicos | Vacío solo con marcador reconocido; pobladas: inventario completo, navegación ligada y deduplicación probada sin contar `tr` como registros |
| applications | en-ap | AP-H: `#applications`, «No data available» explícito; sin evidencia AP poblada | Vacío reconocido puede cerrar ese caso; poblada no reconocida exige `unsupported` y parcial hasta soporte verificado, nunca vacío supuesto |
| oe_references | es-co | CO-H: `#oeNumbers`, Toyota, 25 `li` en listas/acordeones, no tabla | Recorrer todos los grupos/listas y conservar fabricante/referencia/orden; `references[]` GraphQL no es OE equivalente |
| oe_references | en-ap | AP-H: `#oeNumbers` explícitamente vacío | Evidencia del vacío del bloque HTML; `references[]` GraphQL no permite deducirlo |
| media | es-co | CO-H: JSON embebido `ProductGallery`, 2 entradas; sin muestra de colección de assets CO | Inventario completo de galería y de metadatos filtrados si el perfil los declara requeridos; no exigir formatos no publicados |
| media | en-ap | AP-H: `ProductGallery`, 8 entradas; ASSET-R: 4 hits de filtro front/main, 2 JPEG + 2 TIFF declarados, `total=4`, `hasMore=false` | Preservar las 8 referencias de galería y agotar colección del filtro declarado; 8+4 no demuestra 12 recursos únicos ni inventario universal de assets |
| documents | es-co | CO-H: `#downloads`, vacío explícito; sin documento positivo observado | Vacío reconocido puede cerrar ese caso; poblado no soportado es `unsupported`/parcial, no positivo certificado por extrapolación |
| documents | en-ap | AP-H: `#downloads`, vacío explícito; sin documento positivo observado | Mismo criterio; con soporte futuro, conservar referencias/etiquetas/metadatos y paginación sin descargar |
| observations | es-co | CO-H/CO-G: descripción/resumen/extra técnico reconocido y `#reachInformation` con fecha SVHC y declaración del producto | Capturar todos los bloques técnicos reconocidos; `main_application_aa` textual no se transforma en fitment estructurado |
| observations | en-ap | AP-H/AP-G: descripción/resumen/extra técnico reconocido y `#reachInformation` con fecha SVHC y declaración del producto | Igual inventario reconocido, conservando texto/fecha literal; no promete detectar contenido futuro arbitrario |

En media, el perfil versionado debe declarar antes de habilitarse si exige además la colección de metadatos,
su filtro, alcance y paginación; si está configurada como requerida, su fallo hace parcial aunque la galería esté completa.
No puede omitirse dinámicamente del inventario por haber fallado. Galería inventariada y metadatos filtrados agotados
pueden cerrar **ese alcance declarado** aunque solo cuatro metadatos correspondan a ocho referencias de galería;
no afirman universalidad ni descarga, y no fusionan registros sin vínculo publicado demostrable.
Ausencia de un formato no publicado no es brecha. Nuevas superficies técnicas detectadas y reconocidas pero
no interpretables producen `unsupported` en su sección; cero reconocimiento de plantilla principal es fallo estructural.
No se capturan diccionarios globales, placeholders ocultos, analytics, cabeceras o precios como observaciones técnicas.

Contraejemplos que el contrato debe preservar: en AP-G, `bypass_valve_opening_pressurem = 0.758`
y `bypass_valve_opening_pressure = 0.551` son claves distintas; solo la segunda aparece en tabla.
No se corrigen, fusionan ni se les infieren unidades. Una nota textual `main_application_aa` puede coexistir
con aplicaciones estructuradas vacías en CO. El SKU de formulario AP no convierte el GTIN visible en SKU equivalente.
Los metadatos TIFF/MIME son declaraciones de la fuente, no archivos verificados.

## 10. Criterios de aceptación y matriz de trazabilidad

La siguiente matriz define pruebas de la futura implementación, no pruebas ya ejecutadas ni archivos creados por este PRD. Cada caso valida también los pares permitidos de §5.1 y un consumidor que distingue fallo, ausencia y producto parcial.

<!-- prettier-ignore -->
| ID    | Escenario y resultado exigido                                                                                                                                                                                                                                                                                               | Requisitos / invariantes                     |
| ----- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------- |
| CA-01 | Vacío, controles, espacios externos o límite de entrada excedido → `failed/not_attempted`, `invalid_input`, cero I/O; adaptador deshabilitado → mismo par con `disabled`.                                                                                                                                                   | RF-01, RF-07; INV-01, INV-12                 |
| CA-02 | `51515` confirma marca/designación en búsqueda y ficha; `MP`/`XP` no sustituyen al exacto. Ceros, casing, prefijos y sufijos se preservan sin corrección silenciosa.                                                                                                                                                        | RF-01–04; INV-01–03                          |
| CA-03 | `51515R`: negativo primario exhaustivo y exacto AP; la letra permanece en entrada/producto y la fuente es AP, sin merge.                                                                                                                                                                                                    | RF-02, RF-04–07; INV-02, INV-05              |
| CA-04 | Código inexistente con ambos negativos válidos → `success/no_exact_match`, producto/fuente nulos, alcance limitado a esos perfiles; política solo CO hace explícito su alcance distinto.                                                                                                                                    | RF-05–07, RF-14; INV-04, INV-12              |
| CA-05 | Recorrer las tres páginas live de `51515` con tamaño 1; variante sintética con exacto tardío no se pierde. Encontrar exacto en primera página no permite cortar recorrido.                                                                                                                                                  | RF-03, RF-06; INV-02, INV-04                 |
| CA-06 | Sintéticos: mismo candidato repetido se deduplica con evidencia; dos identidades exactas distintas → `failed/ambiguous`; conflicto de identificadores no se oculta.                                                                                                                                                         | RF-02–04; INV-02, INV-06                     |
| CA-07 | Página omitida/repetida, cursor circular, totales incompatibles o deriva → `failed/incomplete`, incluso habiendo visto un candidato exacto único hasta entonces.                                                                                                                                                            | RF-03, RF-06; INV-02–04                      |
| CA-08 | HTTP 200 con `errors` GraphQL, incluido `data` parcial, no es negativo ni exacto; JSON/esquema inválido tampoco. Retry válido puede recuperar con diagnóstico y consumo trazables.                                                                                                                                          | RF-06, RF-12–13; RNF-04; INV-06, INV-10      |
| CA-09 | Timeout, 403, 429 o 500 sin recuperación → `failed/incomplete`, sin fallback desde error primario; no se convierten en vacío.                                                                                                                                                                                               | RF-06–07, RF-12; INV-04–06                   |
| CA-10 | Negativo CO y timeout/error/presupuesto agotado en AP → `failed/incomplete`, no `no_exact_match`; ambos intentos quedan registrados.                                                                                                                                                                                        | RF-07, RF-13; INV-04–06, INV-10              |
| CA-11 | Marca/designación o identificadores comparables conflictivos en ficha, identidad sin verificar o plantilla principal desconocida → `failed/incomplete`, sin producto ni parcial ni fallback. SKU y GTIN de significados distintos no se declaran conflictivos solo por diferir. | RF-04, RF-07, RF-11; INV-02–03, INV-06 |
| CA-12 | Tras identidad verificada, fallo de sección obligatoria o sección obligatoria reconocida no soportada → `partial/exact`; valores capturados permanecen y cada brecha tiene razón y diagnóstico terminal. Un problema solo accesorio no degrada cobertura obligatoria completa ni permite ocultar brechas reclasificándolas. | RF-08, RF-11–13; INV-07–09                   |
| CA-13 | Aplicaciones `42831` producen 11 registros lógicos, no 22 ni 93; conservar motor vacío y fecha sentinel; datos de aplicaciones diferentes no colapsan. Un sintético con igual path y parámetros de identidad distintos conserva ambas aplicaciones.                                                                         | RF-09; INV-08                                |
| CA-14 | Etiquetas repetidas, unidades y valores conflictivos sobreviven con sección/orden/procedencia, sin corrección científica ni sobrescritura por mapa normalizado.                                                                                                                                                             | RF-08, RF-13; INV-08                         |
| CA-15 | Diferenciar contenedor vacío, sección ausente en plantilla reconocida, panel no inspeccionado y sección no soportada. Solo los dos primeros pueden estar completos con evidencia concluyente.                                                                                                                               | RF-10–11; INV-07, INV-09                     |
| CA-16 | Varios `mainImage` se conservan; galería y metadatos tienen evidencia separada. Paginación de recursos pendiente mantiene `captured/complete=false`; no hay descarga de binarios.                                                                                                                                           | RF-10–11; INV-09, INV-11                     |
| CA-17 | Límites globales se respetan entre páginas, retries, redirects y regiones; bytes descomprimidos y acumulados se cortan aun sin `Content-Length`. Parsing y construcción del DOM respetan plazo global y volumen de nodos/elementos, sin esperar una operación no acotada para detectar agotamiento. Antes/después de identidad produce fallo/parcial coherente. | RNF-02; INV-03, INV-05, INV-10 |
| CA-18 | URL embebida o redirect fuera del perfil/destino permitido se bloquea antes de acceso; TLS y parsing seguro permanecen activos; no se ejecuta HTML.                                                                                                                                                                         | RF-05; RNF-03–04; INV-10                     |
| CA-19 | Configuración no finita/ausente → `failed/not_attempted`, sin red. Errores de programación siguen visibles; diagnósticos operativos no exponen secretos ni trazas y distinguen recuperación. Todo fallo incluye diagnóstico terminal; ningún error obligatorio no recuperado permite `success`.                             | RF-12–13; RNF-02, RNF-04–05; INV-01, INV-12  |
| CA-20 | Dos llamadas V1 al mismo código ejecutan intentos independientes con HTTP simulado y evidencia de cada consulta; no hay lectura/escritura de caché de aplicación ni payload histórico. Timestamps de solicitud/captura no son fechas publicadas ni garantía de frescura del origen/CDN. | RF-15; INV-01, INV-10, INV-12 |
| CA-21 | Consumidor PHP sin productos/DB/UI acepta todas las combinaciones válidas y rechaza contradicciones; opera con HTTP y DOM/XPath sin runtime de navegador ni dependencias nuevas. Serialización eventual preserva contrato; cambios de enum requieren evaluación de compatibilidad.                                          | RF-14; RNF-01; §5; INV-02–04, INV-07, INV-12 |
| CA-22 | Cada dato remite a su superficie real; producto nulo conserva intentos; observaciones nuevas capturables caben en colección sin nuevo enum ni pérdida de etiquetas.                                                                                                                                                         | RF-08, RF-13; §5.2–5.5; INV-08               |
| CA-23 | Fixtures live curadas y casos sintéticos rotulados; suite normal con HTTP fake y bloqueo de solicitudes inesperadas, sin WIX en CI. Live solo opt-in controlado.                                                                                                                                                            | RF-16; RNF-06; §9.3                          |
| CA-24 | Registrar mediciones y límites finitos elegidos, perfiles/versiones y condiciones de uso verificadas antes de habilitación productiva; no atribuir a WIX cuotas/SLA no publicados.                                                                                                                                          | RF-05; RNF-02, RNF-07; §11                   |
| CA-25 | Producto exacto contiene identity y las siete listas no nulas; tipos/nullability de §5.2, `""` literal, missing y null publicado se distinguen. Filas sin valor no se descartan; boolean/string crudos no se confunden; campos/celdas repetidos permanecen. | RF-08–10, RF-14; §5.2; INV-08–09 |
| CA-26 | Cada `evidence_ref` de datos, fuente, intentos y cobertura resuelve en el DTO y cada evidencia apunta a intento válido. Locator/fragmento/URL/perfil/UTC permiten comprobar valor o conclusión sin logs; hash solo, referencia huérfana o atribución a otra superficie se rechazan. | RF-13; §5.2–5.3; RNF-05; INV-08–09 |
| CA-27 | Dos exactos distintos válidos → ambiguous; identificador obligatorio ausente o conflicto que impide probar identidad → incomplete, con código propio; contradicción posterior invalida producto/fuente aunque ya hubiera datos parciales. | RF-02–04, RF-11–12; INV-02–03, INV-06 |
| CA-28 | Fallo local definido tras identidad no impide extraer secciones independientes del DOM o red permitida con presupuesto; dependientes quedan not_checked con causa. Agotamiento impide tráfico nuevo; acción insegura no cancela DOM independiente salvo fuente globalmente no confiable. Excepciones de programación no se absorben. | RF-11–12; RNF-02–04; INV-07, INV-10 |
| CA-29 | Grupos derivados corresponden exactamente a sus secciones; technical completo y resources incompleto → partial/exact utilizable según consumidor, nunca success. Mantener ocho claves y superficies requeridas visibles; no ocultar cobertura ni rebajar requisitos tras fallar. | RF-11, RF-14; §5.3, §9.4; INV-07, INV-09 |
| CA-30 | Matriz de 8 secciones × 2 perfiles se verifica contra fixtures por versión; vacíos no certifican casos poblados. Galería y metadatos filtrados conservan inventario/alcance separados, sin deducir 12 únicos ni MIME verificado; AP presiones, CO nota textual y GTIN/SKU no se normalizan. | RF-04–05, RF-08–11, RF-16; §9.3–9.4; INV-08–11 |

La trazabilidad de CA-01–30 cubre RF-01–16, RNF-01–07 e INV-01–12;
los nuevos casos precisan los anteriores, sin sustituirlos ni introducir una segunda nomenclatura de tests.

## 11. Condiciones de entrega y habilitación

1. **Esta entrega documental:** PRD FINAL 1.1 en raíz y enlace mínimo en README. Una sola fuente; los PRDs de raíz no se publican mediante el MkDocs actual de `docs/`. No se requiere copia ni cambio de navegación.
2. **Antes de implementar la aplicación:** autorización explícita de ese alcance, SDD posterior para spec/diseño/tareas y gate de fixtures durables de §9.3 antes del parser. No reabre las reglas funcionales cerradas aquí ni convierte la redacción en permiso de implementación. Esta edición no crea artefactos SDD.
3. **Para aceptar una implementación futura:** todos los criterios de §10 satisfechos, contrato/versiones documentados y comportamiento desacoplado comprobado. CA-20 verifica consultas sin caché de aplicación; una demo live exitosa no sustituye los criterios.
4. **Antes de habilitar consultas:** valores finitos configurados, mediciones representativas y perfiles/parser certificados contra fixtures. No se fijan cifras arbitrarias como si ya hubieran sido medidas.
5. **Antes de producción/comercialización:** condiciones de uso verificadas y registro operativo de límites, política regional y versiones, con la trazabilidad habitual del despliegue. El estado documental y el de implementación/habilitación se registran separadamente.

### Validación y mantenimiento del adaptador

El adaptador WIX será un componente interno del backend de Filtroven, reutilizable por cualquier servicio autorizado de la aplicación.
Su ejecución será automática cuando un servicio consumidor invoque su contrato público. No requiere intervención humana, aprobaciones individuales ni un operador dedicado.
Su implementación deberá cumplir los criterios de aceptación, las pruebas automatizadas, los controles de seguridad y las condiciones operativas establecidos en este PRD.
El mantenimiento y la evolución del adaptador seguirán el proceso habitual de desarrollo del repositorio Filtroven, sin establecer roles o procedimientos administrativos adicionales.
La habilitación en producción estará condicionada a las verificaciones técnicas y condiciones de uso del proveedor aplicables.

Antes de tráfico habilitado deben comprobarse monitoreo y deshabilitación/kill-switch,
límites de requests, tiempo, bytes, concurrencia y retries, y alcance certificado por perfil/versión,
incluidos los casos poblados aún no soportados. Las condiciones de uso conservan fuente, fecha y conclusión verificables.

Certificación significa evidencia reproducible de los criterios aplicables al alcance/versiones declarados,
no certificación oficial WIX ni soporte universal. Valores numéricos medidos, verificación de condiciones de uso
y habilitación de producción siguen pendientes; este PRD no fabrica cuotas, SLA ni autorizaciones.

## 12. Revisión crítica y decisiones cerradas

| Objeción de abogado del diablo                             | Decisión V1                                                                                                                      |
| ---------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| «El primer exacto basta; recorrer todo cuesta más».        | No demuestra unicidad. Se agota búsqueda válida dentro del presupuesto; si no alcanza, se declara incompleta.                    |
| «El fallback rescata cualquier problema regional».         | Ocultaría fallos y cambiaría identidad/procedencia. Solo rescata un negativo exhaustivo, con el remanente del mismo presupuesto. |
| «Con datos parciales ya podemos publicar producto».        | Solo después de identidad verificada en búsqueda y ficha. Una ficha equivocada no es un producto parcial útil.                   |
| «Un array vacío es suficiente para expresar ausencia».     | No distingue vacío publicado, fallo o no inspeccionado. Se exige cobertura con evidencia y completitud separada.                 |
| «Normalizar todos los atributos simplifica al consumidor». | Puede destruir unidades, repetidos y contradicciones. Se ofrecen campos útiles conservando observaciones originales.             |
| «Una sección nueva siempre se detectará».                  | No es demostrable para HTML arbitrario. Se declara el alcance del perfil y se marca lo detectado no soportado.                   |
| «Cuatro principales son un error que debemos corregir».    | Son metadatos observados; no se inventa unicidad ni se descarga para desempatar en V1.                                           |
| «59 archivos verificados ya son fixtures listas».          | Son evidencia efímera, no un corpus durable. Curación y persistencia son un gate pendiente previo a implementar.                 |
| «Acceso anónimo y PHP E2E permiten activar producción».    | Prueban viabilidad puntual. No sustituyen condiciones de uso, mediciones y límites verificados.                                  |

Quedan cerrados el comportamiento, los tipos conceptuales, las invariantes y los criterios de aceptación.
La distribución final de archivos y los valores operativos medidos se resolverán en SDD/implementación posterior
con autorización; no cambian estas garantías ni se presentan como trabajo realizado. Caché permanece fuera de V1.

## Anexo A. Respuesta contractual completa SINTÉTICA

Este JSON es autocontenido y **SINTÉTICO**, no una captura de `51515` ni una certificación de perfil.
Simula una plantilla reconocida: una dimensión, otras secciones técnicas explícitamente vacías,
documentos vacíos y fallo local de galería. Las cadenas de URL/locator/fragmento y versiones son datos
de prueba inventados con forma de fuente; no acreditan publicación real ni autorizan navegar a ellos.
El perfil sintético requiere la galería como única superficie de media; no configura metadatos adicionales requeridos.
Las cifras de consumo ilustran contabilidad del ejemplo, no límites operativos aprobados.
La evidencia `h-sections` contiene los vacíos reconocidos del escenario sintético;
`h-media` permite diagnosticar el fallo sin fingir metadatos capturados.

<!-- prettier-ignore -->
```json
{
  "contract_version": "1.0",
  "requested_code": "51515",
  "status": "partial",
  "resolution": "exact",
  "product": {
    "identity": {
      "brand": "WIX", "code": "51515",
      "identifiers": [{"name": "sku", "value": "SYNTHETIC-51515", "evidence_refs": ["g-identity", "h-identity"]}],
      "evidence_refs": ["g-identity", "h-identity"]
    },
    "dimensions": [{"label_raw": "Altura", "value_raw": "132", "value_presence": "present", "raw_type": "string", "unit_raw": "mm", "raw_admin_value": null, "section": "dimensions", "order": 0, "evidence_refs": ["h-sections"]}],
    "specifications": [],
    "applications": [],
    "oe_references": [],
    "media": [],
    "documents": [],
    "observations": []
  },
  "scope": {
    "policy": "v1-primary-with-negative-fallback",
    "required_profiles": ["es-co", "en-ap"],
    "consulted_profiles": ["es-co"],
    "exhaustive_profiles": ["es-co"],
    "conclusion": "Identidad exacta es-co; sin fallback por positivo primario; cobertura de recursos incompleta."
  },
  "source": {
    "profile": "es-co",
    "url": "https://www.wixfilters.com/es-co/catalog/results/product.html/SYNTHETIC-51515.html",
    "captured_at": "2026-10-19T00:00:01Z",
    "evidence_refs": ["h-identity"]
  },
  "attempts": [
    {"attempt_id": "a-search", "profile": "es-co", "stage": "search", "pages": [1], "requests": 1, "outcome": "exhaustive_unique_candidate", "evidence_refs": ["g-identity"], "started_at": "2026-10-19T00:00:00Z", "finished_at": "2026-10-19T00:00:01Z", "budget_used": {"elapsed_ms": 1000, "requests": 1, "redirects": 0, "retries": 0, "bytes": 500, "nodes": 10}},
    {"attempt_id": "a-detail", "profile": "es-co", "stage": "detail", "pages": [], "requests": 1, "outcome": "identity_verified_section_failure", "evidence_refs": ["h-identity", "h-sections", "h-media"], "started_at": "2026-10-19T00:00:01Z", "finished_at": "2026-10-19T00:00:02Z", "budget_used": {"elapsed_ms": 1000, "requests": 1, "redirects": 0, "retries": 0, "bytes": 1500, "nodes": 30}}
  ],
  "evidence": {
    "g-identity": {
      "attempt_id": "a-search", "profile": "es-co", "surface": "graphql_search",
      "source_url": "https://www.wixfilters.com/api/graphql/catalog-stage", "final_url": null,
      "captured_at": "2026-10-19T00:00:01Z", "locator": "/data/catalogSearch",
      "raw_excerpt": "SYNTHETIC: recognized search page 1/1, total=1, errors absent; items[0].product: product_brand.value=WIX, sales_designation=51515, sku=SYNTHETIC-51515, urlKey=SYNTHETIC-51515; dimensional summaries and technical attributes absent in recognized schema; recognized description/summary/extra technical fields explicitly empty."
    },
    "h-identity": {
      "attempt_id": "a-detail", "profile": "es-co", "surface": "html",
      "source_url": "https://www.wixfilters.com/es-co/catalog/results/product.html/SYNTHETIC-51515.html", "final_url": null,
      "captured_at": "2026-10-19T00:00:01Z", "locator": "recognized-main:jsonld-brand/form/visible-designation",
      "raw_excerpt": "SYNTHETIC: recognized template; JSON-LD brand.name=WIX; form data-product-sku=SYNTHETIC-51515; visible sales designation=51515."
    },
    "h-sections": {
      "attempt_id": "a-detail", "profile": "es-co", "surface": "html",
      "source_url": "https://www.wixfilters.com/es-co/catalog/results/product.html/SYNTHETIC-51515.html", "final_url": null,
      "captured_at": "2026-10-19T00:00:01Z", "locator": "recognized-main:#dimensions/#technicalData/#applications/#oeNumbers/#downloads/technical-notes",
      "raw_excerpt": "SYNTHETIC: all required HTML technical surfaces inspected, no pending pages; #dimensions one row Altura | 132 | mm; #technicalData explicitly empty; #applications explicit empty; #oeNumbers explicit empty; #downloads explicit empty; recognized description/summary/extra/REACH blocks explicitly empty."
    },
    "h-media": {
      "attempt_id": "a-detail", "profile": "es-co", "surface": "html",
      "source_url": "https://www.wixfilters.com/es-co/catalog/results/product.html/SYNTHETIC-51515.html", "final_url": null,
      "captured_at": "2026-10-19T00:00:01Z", "locator": "recognized-main:ProductGallery",
      "raw_excerpt": "SYNTHETIC: ProductGallery embedded payload malformed: {invalid."
    }
  },
  "coverage": {
    "sections": {
      "identity": {"state": "captured", "complete": true, "reasons": [], "evidence_refs": ["g-identity", "h-identity"]},
      "dimensions": {"state": "captured", "complete": true, "reasons": [], "evidence_refs": ["g-identity", "h-sections"]},
      "specifications": {"state": "published_empty", "complete": true, "reasons": [], "evidence_refs": ["g-identity", "h-sections"]},
      "applications": {"state": "published_empty", "complete": true, "reasons": [], "evidence_refs": ["h-sections"]},
      "oe_references": {"state": "published_empty", "complete": true, "reasons": [], "evidence_refs": ["h-sections"]},
      "media": {"state": "failed", "complete": false, "reasons": ["Required gallery payload could not be parsed; independent sections continued."], "evidence_refs": ["h-media"]},
      "documents": {"state": "published_empty", "complete": true, "reasons": [], "evidence_refs": ["h-sections"]},
      "observations": {"state": "published_empty", "complete": true, "reasons": [], "evidence_refs": ["g-identity", "h-sections"]}
    },
    "groups": {"identity": {"complete": true}, "technical": {"complete": true}, "resources": {"complete": false}},
    "complete": false
  },
  "diagnostics": [{"code": "parse_error", "stage": "detail", "attempt_id": "a-detail", "severity": "error", "recovered": false, "message": "SYNTHETIC: local gallery extraction failed; independent technical sections inspected.", "section": "media", "evidence_refs": ["h-media"]}],
  "meta": {
    "profile_versions": {"es-co": "synthetic-example-1", "en-ap": "synthetic-example-1"},
    "parser_version": "synthetic-example-1",
    "requested_at": "2026-10-19T00:00:00Z", "returned_at": "2026-10-19T00:00:02Z",
    "budget_used": {"elapsed_ms": 2000, "requests": 2, "redirects": 0, "retries": 0, "bytes": 2000, "nodes": 40}
  }
}
```
