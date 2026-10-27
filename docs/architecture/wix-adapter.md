# Adaptador WIX: arquitectura y operación

!!! info "Ficha documental"
    - Estado: `vigente`
    - Tipo: `referencia`
    - Audiencia: desarrollo backend, QA y responsables de ejecución/despliegue
    - Fuente verificable: `app/Services/Wix/`, `app/Support/Wix/`, `config/wix.php`, `app/Providers/AppServiceProvider.php`, `tests/Unit/Wix/`, `tests/Support/Wix/` y `tests/fixtures/wix/`
    - Revisión técnica: 2026-10-27; contrato `1.0`, parser `capture-2026-10-25.1`

## Estado y alcance

El adaptador consulta el catálogo público de **WIX Filters** (no la plataforma de creación de sitios Wix), verifica una identidad literal y devuelve información técnica con procedencia y cobertura. Es una biblioteca PHP interna integrada mediante el contenedor Laravel; no un endpoint, crawler general ni catálogo comercial persistido.

**`config/wix.php` deja `enabled=true` por solicitud del usuario.** Construir o resolver el servicio no consulta WIX ni inicia procesos. Sólo `fetch()` intenta una búsqueda, y únicamente después de validar configuración y capacidades. Los límites auxiliares y parámetros de infraestructura todavía están en `null`: una llamada con la configuración distribuida devuelve `failed/not_attempted`, diagnóstico `invalid_configuration`, cero envíos. Habilitado no significa preparado para producción.

No hay caché de resultados, base de datos, migraciones, descarga de imágenes/PDF, jobs, nueva ruta web, autorización especial ni aprobación humana por consulta. El consumidor decide cuándo llamar y qué hacer con resultados completos, parciales o negativos. La preparación del entorno y las condiciones de uso del proveedor son decisiones previas de despliegue, no un flujo manual por código consultado.

La implementación se ha verificado en Linux **CLI** PHP 8.4 y 8.5 bajo restricciones concretas. No está acreditada para un padre web no CLI, varios hosts ni Laravel Cloud real. El archivo histórico de PRD-15 y su verificación anterior no certifican automáticamente esta nueva habilitación/configuración documental.

## Arquitectura y mapa del código

```text
Consumidor PHP / contenedor Laravel
  -> WixLookup::fetch(código literal)
     -> Preflight: entrada -> interruptor -> límites -> capacidades
     -> LookupContext: presupuesto + evidencia + intentos + diagnósticos
     -> WixHttpTransport.withinLookup: lease de admisión durante todo el lookup
        -> RegionalLookupPolicy
           -> SearchPaginator -> CandidateResolver (CO; AP sólo tras negativo válido)
           -> DetailVerifier -> IdentityLedger (GraphQL + ficha HTML)
        -> SurfacePlan -> extractores independientes + AssetMetadataPaginator
        -> ResultFactory -> WixLookupResult validado
     -> finally: liberar lease / cerrar IPC / terminar y recoger hijos

Cada envío:
  predebitar presupuesto -> DestinationGuard -> BoundedResolver (A + AAAA)
    -> SupervisedPhpTask -> document-worker.php -> NativeExchange / BoundedBodySink
       DNS fijado + TLS/CA/SNI + PREREQ antes de HTTP + recepción acotada
Cada parseo nativo:
  SupervisedDocumentReader -> SupervisedPhpTask -> worker estático JSON/DOM/lexemas
```

| Ubicación | Responsabilidad |
| --- | --- |
| `app/Support/Wix/Contracts/WixLookup.php` | API pública `fetch(string): WixLookupResult`. |
| `app/Services/Wix/WixLookupService.php` | Orquestación, preflight, factoría nativa y resultado por invocación. |
| `app/Providers/AppServiceProvider.php` | Bindings transitorios del contrato y servicio; configuración y telemetría Laravel. |
| `app/Support/Wix/Profiles/` | Perfiles cerrados, query, headers e inventario versionado. |
| `SearchPaginator`, `CandidateResolver`, `DetailVerifier`, `IdentityLedger`, `RegionalLookupPolicy` | Exhaustividad, unicidad, verificación y política regional. Todos bajo `app/Support/Wix/`. |
| `app/Support/Wix/Parsing/` | HTML estático, valores raw, tablas técnicas, aplicaciones, OE, recursos y cobertura. |
| `app/Support/Wix/Http/` | Admisión de destinos, DNS acotado, recepción TLS y paginación de metadatos. |
| `app/Support/Wix/Runtime/` | Capacidades, lease local, supervisor, protocolo IPC, lexemas y worker estático. |
| `LookupBudget`, `LookupContext`, `EvidenceBuilder`, `LookupTelemetry` | Estado efímero y límites compartidos; sin estado comercial global. |
| `app/Support/Wix/Data/`, `Enums/`, `ResultFactory.php` | DTOs readonly, invariantes y seis resultados permitidos. |
| `tests/Support/Wix/ProductionHarness.php`, `Prototype/NativeLab.php` | Harness offline y laboratorio DNS/TLS propio; los prototipos no sustituyen la ruta de producción. |
| `tests/fixtures/wix/manifest.json`, `bodies/`, `expected/` | Corpus saneado, procedencia y expectativas; no un caché operativo. |

## API de consumo

Dentro de Laravel, en un **contexto CLI previamente calificado**:

```php
use App\Support\Wix\Contracts\WixLookup;
use App\Support\Wix\Enums\LookupResolution;

$result = app(WixLookup::class)->fetch('51515');

if ($result->resolution === LookupResolution::Exact) {
    $technicalComplete = $result->coverage->groups->technical->complete;
    $product = $result->product;
}

$payload = $result->toArray();
```

El ejemplo no garantiza un producto: con la configuración distribuida retorna `invalid_configuration`. `Exact` puede ser `success` o `partial`; comprobar la cobertura que necesita el consumidor, no sólo que exista producto. `NoExactMatch` no es una excepción ni una caída del proveedor.

Uso directo PHP, sin bootstrap Laravel (Composer cargado):

```php
require __DIR__.'/vendor/autoload.php';

use App\Services\Wix\WixLookupService;

$service = new WixLookupService();
$result = $service->fetch('51515');
```

El constructor sin argumentos lee `config/wix.php` al invocar `fetch`, no al construir. También falla cerrado mientras falte configuración. Para un contexto **ya medido y configurado**, la firma real permite:

```php
$service = new WixLookupService(
    configuration: static fn (): array => $validatedConfiguration,
    callerDeadline: static fn (): int => $callerMonotonicDeadlineNs,
);
```

`$validatedConfiguration` debe tener exactamente el esquema descrito abajo y valores reales del entorno; no existe un preset productivo universal. El deadline opcional es un entero monotónico de `hrtime(true)` en nanosegundos, no Unix time. Es un límite externo más estricto, nunca una ampliación del presupuesto. El binding Laravel actual no proporciona esa closure. `transport` y `telemetry` son los otros argumentos opcionales del constructor; sustituir el transporte es útil para tests, no una forma de acreditar seguridad de red.

`fetch` no es estático; no existe `WixAdapter::fetch`. Los fallos operativos se representan en el DTO en las rutas previstas. Errores de programación (`LogicException`, `TypeError`) siguen visibles; no envolver todo en `catch (Throwable)` y convertirlo en “no existe”.

## Flujo de resolución y captura

1. **Entrada literal.** Rechaza vacío, espacios externos y controles ASCII; no hace trim correctivo, uppercase, conversión numérica ni una gramática comercial inventada. Conserva ceros, sufijos y mayúsculas. El máximo de bytes se configura expresamente.
2. **Preflight sin red ni hijos.** Orden: sintaxis, interruptor, límites completos, longitud y capacidades reales. Un interruptor no booleano también es configuración inválida. Política `1.0` y `fallback` booleano son obligatorios.
3. **Un contexto por llamada.** Reloj monotónico, presupuesto común, evidencia append-only e historial nuevo, aunque se consulte dos veces el mismo código. La admisión nativa retiene una lease durante búsqueda, parseo, captura y finalización.
4. **Búsqueda CO exhaustiva.** GraphQL paginado valida tipos, página/tamaño, `totalCount`, `totalPages`, número esperado de items, estabilidad de totales y fingerprints de páginas. Encontrar un candidato en la primera página no demuestra unicidad. Página repetida, truncada, esquema cambiado, `errors` junto con `data` o cap agotado impiden concluir ausencia.
5. **Candidato literal.** Exige `product_brand.value=WIX`, `adminValue=wix`, `sales_designation.value` exactamente igual a la entrada y SKU/urlKey publicados. SKU no es designación, GTIN no es SKU. Repeticiones coherentes de SKU+urlKey agregan procedencia; referencias contradictorias no se fusionan. Dos candidatos distintos válidos producen ambigüedad.
6. **Fallback sólo por negativo probado.** AP se consulta únicamente si CO terminó exhaustivamente sin coincidencia exacta y `fallback=true`. Un timeout, 403/429/5xx, fallo DNS, identidad no verificada o parseo inválido en CO no autoriza AP como recuperación. CO y AP comparten todos los presupuestos; no se mezclan productos regionales.
7. **Ficha por urlKey publicado.** `DetailVerifier` verifica un único main/form/título y Product JSON-LD: designación literal, marca WIX y SKU consistente con búsqueda y formulario. La URL no se fabrica a partir del código. Sin esta segunda etapa no se expone producto parcial.
8. **Captura de superficies independientes.** GraphQL retenido y HTML verificado alimentan dimensiones, especificaciones, aplicaciones, OE, galería, documentos y observaciones. Después se pagina `/bin/assets.json` para metadatos de media. Totales/offset/limit/hasMore y cada hit se validan; el límite por página de assets es 25, separado de `page_size` de búsqueda.
9. **Continuidad con honestidad.** Fallos locales de una sección conservan hechos completos y permiten inspeccionar hermanos seguros con presupuesto remanente. Un recurso completamente interpretado se compromete una vez incluso si el siguiente agota evidencia. El callback interno actual sólo agrega datos: no debe hacer I/O ni lanzar. Agotamiento/cancelación global impide trabajo nuevo; no se rellena con vacíos ficticios.
10. **Invalidación tardía.** Una contradicción posterior, por ejemplo en REACH, invalida la identidad: se retiran producto/source y el resultado es `failed/incomplete`, no un parcial exacto engañoso. El historial y las pruebas permanecen.
11. **Cierre.** Se cierran referencias, se calculan cobertura y estado, se validan invariantes del DTO y se liberan lease, sockets y procesos propios en `finally`.

## Perfiles y superficies cerradas

| Perfil | Endpoint GraphQL en `www.wixfilters.com` | Store | Locale |
| --- | --- | --- | --- |
| `es-co` | `/api/graphql/catalog-stage` | `pcat_wix_co_store_es` | `es` |
| `en-ap` | `/api/graphql/catalog-prod` | `pcat_wix_my_store_en` | `en` |

Ver `Profiles/EsCoProfile.php`, `EnApProfile.php` y `ProfileDefinition.php`. Versión de perfiles `observed-2026-10-19`; inventario `technical-2026-10-20.2`. Las versiones identifican alcance observado, no garantizan que WIX mantenga su esquema.

La búsqueda es GET con query GraphQL fija y variables ordenadas `search`, `currentPage`, `pageSize`, `filterBy=ALL_FILTER`. El guard valida también el hash de la query. Headers de perfil: `User-Agent: FiltrovenPublicResearch/1.0 PHP-native low-volume`, `Accept: */*`, `Accept-Encoding: identity`; GraphQL añade `Store` y `Content-Type: application/json`; transporte añade `Connection: close`. El intercambio nativo admite recepción identity/gzip/deflate y hace descompresión explícita acotada. No hay credencial ni API key necesaria en estos headers.

Assets usa filtros fijos `metaData=true`, `brand=WIX Filters`, `productView=Front`, `productRelatedAssetType=Product Image`, `mainImage=true`, `productReferences=<SKU publicado>`, `limit` y `offset` cuando corresponde. No promete todos los recursos de Internet ni todas las variantes del producto: su exhaustividad es la del inventario y filtros versionados.

| Sección | Superficies obligatorias |
| --- | --- |
| `identity`, `dimensions`, `specifications`, `applications`, `observations` | `graphql_search` + `html` |
| `oe_references`, `documents` | `html` |
| `media` | `html` + `asset_metadata` |

### Fidelidad y límites del parser

- No normaliza unidades ni redondea: altura resumida `132.0` y dimensión `132.003` pueden coexistir con etiquetas, unidades y referencias distintas. Presiones métricas/imperiales, roscas y valores repetidos no se colapsan por conveniencia.
- Valores incluyen `value_raw: ?string`, `value_presence: present|missing`, `raw_type: string|number|boolean|null|object|array|missing`. Un null publicado es `present/null`; un campo ausente es `missing/missing`; `""` es string presente. Números y booleanos conservan lexema textual; arrays/objetos se conservan como JSON raw validado, no se aplanan. `raw_admin_value` y `source_key` son campos separados.
- Aplicaciones conservan celdas, etiquetas, navegación, parámetros **ordenados y repetidos**, identificadores de origen y ocurrencias desktop/tablet. Sólo se unen ocurrencias responsive demostrablemente equivalentes. Por ejemplo, fixtures 42831 tienen 11 aplicaciones lógicas/22 ocurrencias, y AP 51515 tiene 94/188: no contar cada vista como vehículo distinto ni usar la cifra como garantía para futuras respuestas.
- OE preserva fabricante nullable y referencia literal; no prueba equivalencia comercial, sustitución segura ni compatibilidad vehicular.
- Galería HTML y assets son procedencias separadas. Se conservan roles, posición publicada, formato y MIME declarados, incluso varios `mainImage=true`. Referencias duplicadas entre superficies no se convierten arbitrariamente en recursos únicos. Ningún binario se descarga; el MIME declarado no se verifica contra un archivo.
- Documentos tienen soporte **empty-only** en ambos perfiles. El marcador explícito “No hay información disponible”/“No Data Available” permite vacío publicado. Documentos poblados detectados son `unsupported`, incompletos y causan parcial; faltan positivos auténticos para soportarlos. La matriz de fixtures tiene 14 celdas positivas y dos empty-only, no 16 soportes positivos.
- JavaScript no se ejecuta; paneles dinámicos no reconocidos no se simulan como vacíos. HTML y JSON pueden cambiar: actualizar perfiles/parser requiere evidencia y regresiones, no relajar selectores para forzar éxito.

## Contrato de resultado 1.0

`Data/ContractData.php` proporciona `toArray()`/`fromArray()` y serialización; `WixLookupResult` comprueba campos, tipos, cronología, atribución y cierre de referencias. Nombres y enums de la tabla son valores serializados; los casos PHP son, por ejemplo, `LookupStatus::Success` y `LookupResolution::NoExactMatch`.

| Pareja única permitida | Significado |
| --- | --- |
| `success/exact` | Identidad verificada y todas las secciones completas. |
| `partial/exact` | Identidad verificada, al menos una sección obligatoria incompleta; hechos útiles retenidos. |
| `success/no_exact_match` | Ausencia exacta demostrada en todos los perfiles requeridos por la política. |
| `failed/ambiguous` | Más de una identidad candidata válida. |
| `failed/incomplete` | No se pudo concluir o la identidad se invalidó. |
| `failed/not_attempted` | Entrada, interruptor o configuración impidieron iniciar; cero intentos/evidencia/envíos. |

**`product` y `source` existen si y sólo si `resolution=exact`.** Un negativo exitoso tiene ambos null y `coverage.complete=false`: “búsqueda concluida” no significa “producto completo”. Un error no demuestra inexistencia.

### Campos de primer nivel

| Campo | Tipo y contenido |
| --- | --- |
| `contract_version` | String exacto `1.0`. |
| `requested_code` | Entrada literal, incluso si fue rechazada. No registrarla indiscriminadamente. |
| `status`, `resolution` | Enums limitados a las seis parejas anteriores. |
| `product` | `WixProduct|null`: `identity` y siete listas descritas abajo. |
| `scope` | `policy`, listas `required_profiles`, `consulted_profiles`, `exhaustive_profiles`, `conclusion`. Políticas `es-co-then-en-ap-v1` o `es-co-only-v1`; no ausencia global fuera de ese alcance. |
| `source` | `profile`, URL oficial de ficha `url`, UTC `captured_at`, `evidence_refs`; nullable. |
| `attempts` | Lista ordenada: `attempt_id`, `profile`, `stage`, `pages`, `requests`, `outcome`, `evidence_refs`, `started_at`, `finished_at`, `budget_used`. |
| `evidence` | Mapa por ID (`e-1`, etc.): `attempt_id`, `profile`, `surface`, `source_url`, `final_url` nullable, UTC `captured_at`, `locator`, `raw_excerpt` escalar/null. |
| `coverage` | `sections`, `groups`, `complete`; ver reglas siguientes. |
| `diagnostics` | Lista: `code`, `stage`, `attempt_id` nullable, `severity` (`info|warning|error`), `recovered`, mensaje seguro `message`, `section` nullable, `evidence_refs`. |
| `meta` | Mapa `profile_versions`, `parser_version`, UTC `requested_at`, `returned_at`, `budget_used`. |

`budget_used` contiene enteros `elapsed_ms`, `requests`, `redirects`, `retries`, `bytes`, `nodes`. La suma de requests de intentos coincide con la global. **Requests cuenta reservas conservadoras de envío antes de DNS, no HTTP realmente emitidos**: fallo DNS o renovación pre-wire puede consumir una reserva sin HTTP. Si se pierde el worker, los bytes pueden contabilizarse por cota superior conservadora, no fingirse observación exacta.

### Producto y tipos anidados

| Campo | Estructura |
| --- | --- |
| `identity` | `brand`, `code`, lista `identifiers` (`name`, `value`, `evidence_refs`) y `evidence_refs`; identidad confirmada por búsqueda y HTML. |
| `dimensions`, `specifications`, `observations` | Listas `Observation`: campos raw, referencias, `label_raw`, `unit_raw`, `raw_admin_value`, `section`, `order`, `source_key` nullable. |
| `applications` | Lista `Application`: `manufacturer_raw`, `model_raw`, `series_raw`, `variant_raw`, `engine_raw`, `period_raw` (todos nullable), `cells`, `source_identifiers`, `navigation`, `occurrences`, `order`, `evidence_refs`. |
| `oe_references` | Lista `OeReference`: `manufacturer_raw` nullable, `reference_raw`, `order`, `evidence_refs`. |
| `media` | Lista `Media`: campos Resource más `position_published` nullable, `type_declared`, `format_declared`, `mime_declared` nullable y lista `roles`. |
| `documents` | Lista `Resource`: `reference_raw`, `url_resolved` nullable, `label_raw` nullable, lista `declared_metadata`, `order`, `evidence_refs`. Actualmente sólo vacío publicado soportado. |

`Cell` usa campos raw y referencias más `label_raw`, `unit_raw`, `order`. `RawField` añade `name` a campos raw/referencias; se usa en metadata declarada. `NameValue` es `{name,value}`: listas de ese tipo preservan repetición y orden en parámetros/identificadores. `navigation` contiene `path` nullable y `params`; `occurrences` contiene `surface`, `locator`, `evidence_refs`. Las listas no son mapas por etiqueta: una etiqueta puede repetirse.

### Cobertura y prueba

Hay exactamente ocho secciones: `identity`, `dimensions`, `specifications`, `applications`, `oe_references`, `media`, `documents`, `observations`. Cada una declara `state`, `complete`, lista `reasons` y `evidence_refs`.

- `captured`: hay datos; **no implica complete** si falta otra superficie/página.
- `published_empty`: vacío explícito reconocido, no una lista vacía por error.
- `not_published`: ausencia de publicación probada conforme al inventario, no “no lo miré”.
- `failed`: inspección fallida; `not_checked`: no inspeccionada; `unsupported`: estructura detectada sin soporte.

Grupos con un booleano `complete` cada uno:

```text
identity  = identity.complete
technical = dimensions & specifications & applications & oe_references & observations
resources = media & documents
coverage.complete = identity & technical & resources
```

Un grupo técnico completo puede ser útil con recursos incompletos. Cada brecha obligatoria de un parcial tiene diagnóstico terminal de sección; warnings recuperados no equivalen a error terminal. `evidence_refs` siempre resuelve a evidencia del intento/perfil correcto. La ficha seleccionada y sus datos pertenecen a un único perfil. Los fragmentos son prueba localizada, no hashes sustituyendo contenido ni el cuerpo HTML completo.

Ejemplo **de proyección de consumo**, no un DTO íntegro ni un fixture certificable. Resume una observación real de 51515 del 2026-10-26; evidencia/URLs de búsqueda se omiten deliberadamente de esta vista para no copiar un payload grande:

```json
{
  "requested_code": "51515",
  "status": "success",
  "resolution": "exact",
  "selected_profile": "es-co",
  "identity": { "brand": "WIX", "code": "51515", "sku": "51515_WIX", "urlKey": "51515_wix" },
  "coverage_complete": true,
  "counts": { "dimensions": 7, "specifications": 19, "applications": 0, "oe_references": 25, "media": 4, "documents": 0, "observations": 33 },
  "empty_sections": ["applications", "documents"]
}
```

Los nombres `selected_profile`, `counts` y `empty_sections` son de esta proyección, **no campos nuevos de 1.0**. Para obtener un DTO completo y validable offline, ver `ServiceTest.php`, `ConsumerTest.php`, `ContractTest.php` y `ContractWitness`; no construir referencias ficticias para parecer un resultado real.

## Configuración y requisitos efectivos

La aplicación conserva su baseline Laravel 13/PHP 8.4+, Composer y servicios locales según README. WIX añade restricciones **de esta implementación**, no requisitos universales de Laravel ni de cualquier adaptador. No necesita PostgreSQL/Redis/Mailpit para una consulta standalone.

### Configuración distribuida

`config/wix.php` usa literales, no variables `WIX_*`. Añadir una variable al entorno por sí sola no configura el servicio. No se añaden toggles ni valores operativos supuestos en esta entrega.

| Clave | Actual | Validación / responsabilidad |
| --- | --- | --- |
| `enabled` | `true` | Booleano; `false` impide nuevas admisiones y corta trabajo en checkpoints al releerse. |
| `policy_version`, `fallback` | `1.0`, `true` | Versión exacta y booleano; fallback no es recuperación ante errores. |
| `runtime.dns_server`, `dns_port` | `null`, `53` | IP literal de resolver accesible, puerto entero 1–65535. Puede ser DNS interno si cumple el protocolo; no se lee automáticamente `/etc/resolv.conf`. |
| `runtime.admission_path`, `device`, `inode` | `null` | Archivo real existente y su identidad `stat`; no valores de build ni archivo sustituido mientras se usa. |
| `runtime.topology` | `null` | Sólo `linux-cli-one-host-shared-inode`. |
| `runtime.ca_file` | `null` | Archivo CA real legible por el intercambio hijo; preservar verificación TLS. |
| `limits.input_max_bytes` | `null` | Entero positivo medido/acordado; no se presupone 128 bytes productivos. |
| `limits.max_search_pages`, `max_section_pages`, `page_size`, `max_nodes_total` | `null` | Enteros positivos finitos que definen alcance operativo. Valores del harness son de prueba, no defaults de producción aprobados. |

`LookupLimits::fromArray` exige **todas** las claves exactas de límites, sin extras, todas enteros (no strings numéricos, null o infinito). Valida positividad, conexión ≤ request ≤ total, reserva < total, bytes por respuesta ≤ total, redirects+retries < requests y concurrencia exactamente uno. Los parámetros runtime se verifican además al construir el transporte nativo y al adquirir admisión.

### Presupuestos actuales (no ampliados al habilitar)

| Límite | Valor |
| --- | --- |
| Total de invocación | 20 000 ms; incluye preflight desde inicio de `fetch` y espera de admisión. |
| Conexión por envío | 3 000 ms, incluyendo resolución DNS y TLS bajo el mismo deadline. |
| Request por envío | 8 000 ms, limitado también por deadline global. |
| Reservas de envío máximas | 13, compartidas por regiones, páginas y renovación pre-wire. |
| Redirects / retries HTTP | 0 / 0. No hay recuperación automática de 429/5xx. |
| Bytes decodificados | 2 097 152 por respuesta (2 MiB); 8 388 608 acumulados (8 MiB). |
| Concurrencia outbound | 1 en el dominio local de admisión calificado. |
| Reserva de finalización | 250 ms; no disponible para iniciar trabajo adicional. |

Headers acumulados ≤32 KiB; header relevante individual ≤8 KiB. Entrada encoded de recepción ≤máximo decodificado admitido+64 KiB. La descompresión usa pasos pequeños y rechaza encoding múltiple/no soportado, streams incompletos y exceso de tamaño. Evidencia: fragmento string ≤16 KiB, hasta 4096 items y 4 MiB acumulados incluyendo sobrecarga calculada; rechaza antes de agregar, no trunca pruebas existentes. IPC: frames ≤16 KiB, salida supervisada ≤16 MiB, lecturas de 4096 bytes, reconstrucción fragmentada de registros JSON grandes con un nodo por registro lógico. JSON depth 32, recorrido HTML depth 64; input al worker ≤2 MiB.

No confundir estos límites con un SLA/hard realtime. El caller debe dejar margen para su wrapper y cierre; timeout de request web, terminación de procesos, rollout y ventana externa deben medirse. No se recomiendan números productivos inventados para hacer pasar el guard.

### Runtime nativo obligatorio

- **Padre y ejecución Linux CLI**, PHP `>=8.4,<8.6`. `Preflight`, `Admission` y `SupervisedPhpTask` verifican el padre; no basta poder lanzar un hijo CLI desde una petición web. El hijo usa **`PHP_BINARY` del padre**, sin parámetro alternativo configurable.
- libcurl **exactamente `7.88.1` o `8.17.0`**, SSL, `CURLOPT_PREREQFUNCTION` y decodificación controlable. Otra versión moderna no está admitida automáticamente.
- Extensiones/primitivas curl, DOM/XPath/libxml, JSON, zlib, sockets y POSIX; `hrtime`, familia `proc_open/status/terminate/close`, select/streams, `flock`, `posix_setrlimit`, sockets UDP y funciones listadas en `RuntimeCapabilities.php`. `composer check-platform-reqs` no acredita por sí solo estos requisitos.
- Lectura de **rutas exactas cgroup v2** `/sys/fs/cgroup/memory.max` y `pids.max`: memoria finita entre **64 y 512 MiB** inclusive; pids entre **2 y 64** inclusive. Ausente, `max`, cgroup v1 o distinta ruta no se autodetecta: falla cerrado. Son gates actuales del producto, no meros flags del laboratorio.
- `/proc/<pid>/status` con `VmHWM` disponible para supervisión. Suma muestreada de high-water marks padre+hijo >192 MiB corta. **No es un cap exacto de RSS**; un padre de larga vida puede acumular HWM anterior.
- Worker con `memory_limit=64M` y límite duro `RLIMIT_AS=256 MiB` mediante POSIX, sin ejecutable `prlimit`. Espacio virtual AS no es RAM residente; extensiones/imagen/baseline afectan headroom. El límite del cgroup no garantiza ausencia universal de OOM.
- `proc_open` usa argv, no shell, entrypoint estático del repositorio y sockets no bloqueantes con buffers observados ≤8192 bytes. Entorno hijo reducido (`PATH`, `PHP_INI_SCAN_DIR`), no copia `.env` completa ni `PHPRC`; comprobar extensiones/INI efectivos del hijo, no sólo `php -m` del shell.
- Distribuir juntos `Runtime/document-worker.php`, protocolo/lexemas y clases HTTP requeridas por rutas relativas. No recibe código, rutas ejecutables ni autoload del usuario. Las operaciones de parseo no ejecutan curl; scripting/ejecución y funciones de escape se deshabilitan en el hijo.
- Supervisor reserva cierre y ejecuta TERM → gracia acotada → KILL si necesario → `proc_close`/reap y cierre de descriptores. Esto no acredita el comportamiento ante OOM del pod o muerte externa del padre: medirlo en despliegue.

### Admisión y red segura

Admisión exige archivo regular vacío, no symlink, `nlink=1`, abierto `r+`, device/inode exactamente configurados. `flock(LOCK_EX|LOCK_NB)` espera bajo presupuesto; es reentrante sólo para el mismo `LookupBudget` y libera por profundidad. Todos los callers deben compartir **el mismo inode en un host**. No crea el archivo, no lo repara, no usa Redis ni implementa lock distribuido. Reemplazarlo durante uso puede permitir dos leases independientes.

Destinos permitidos: HTTPS, `www.wixfilters.com`, puerto 443 y rutas/query cerradas de perfiles; sin userinfo, fragmentos, parámetros extra/duplicados ni escapes peligrosos. `BoundedResolver` consulta A+AAAA por UDP al resolver explícito, valida peer/puerto/ID/pregunta, tamaño ≤4096 y flags; rechaza truncado, inconsistencias, cadenas CNAME cíclicas/conflictivas/no relacionadas y **todas** las IP no públicas, no sólo la elegida. No fallback libc, TCP ni proxy.

La cadena CNAME empaquetada se valida independientemente del orden. TTL mínimo de registros relacionados/A/AAAA se cuenta desde **recepción del datagrama**, con máximo 60 s y clamp al deadline original de conexión; no rejuvenece una respuesta anterior ni extiende el tiempo DNS. TTL cero/expirado se rechaza.

`CURLOPT_RESOLVE` fija la IP validada, conservando URL/Host/SNI y CA/hostname TLS verificados (TLS ≥1.2). PREREQ vuelve a comprobar destino/puerto/deadlines/TTL **después de TLS y antes del HTTP**. Handles nuevos sin reutilización, redirect, cookies, auth/netrc ni proxy de entorno. Nunca deshabilitar SSL para resolver un fallo.

Si el pin expira antes de cualquier HTTP demostrado (`status`, request bytes, headers y cuerpo cero), se permite **una sola renovación DNS/TLS pre-wire**, predebitando otra reserva. Revalida todas las reglas, conserva los deadlines 3/8/20 y cap 13; no es retry HTTP ni una segunda ventana de conexión. Un envío ya emitido, causa desconocida o segundo pin expirado no habilitan otra renovación. `errno=42` puede ser rechazo PREREQ/cancelación, no demuestra agotamiento global; `stage` conserva la causa segura. Detalles nativos son internos, no campos nuevos del DTO.

Parseo DOM usa `LIBXML_NONET`, rechaza declaraciones ENTITY/subset DTD y no evalúa JavaScript. Esto es independiente de que una URL de media publicada se conserve como dato: guardar una referencia **no autoriza descargarla**.

## Observabilidad y diagnóstico

`LookupTelemetry` emite `wix.lookup.begin|policy|stage|failure|end`; crea `lookup_id`, hereda un `correlation_id` UUID válido o genera otro y restaura el contexto al terminar, incluso ante bugs. Registra versiones, resultados, códigos y contadores acotados; no el código solicitado completo, cuerpo HTML/JSON, excepción privada, `.env`, tokens ni credenciales. El DTO sí contiene evidencia y entrada literal: no volcarlo íntegro a logs públicos.

| Síntoma | Interpretación / actuación |
| --- | --- |
| `disabled`, `not_attempted` | Interruptor falso releído; cero trabajo. Sintaxis inválida tiene precedencia. |
| `invalid_configuration`, `not_attempted` | Revisar límites null/tipos, SAPI/capacidades, CA/DNS/runtime e inode. `enabled=true` solo no basta. |
| `transport_error`, etapa `dns` | Resolver/respuesta insegura o no soportada; el mensaje público no siempre conserva motivo DNS específico. No adivinar NXDOMAIN/TTL/Cloud a partir de él. |
| `budget_exhausted`, `dns.connect_deadline` | Se consumió ventana de conexión; puede quedar mucho del total de 20 s. No aumentar ciegamente timeout global. |
| TLS / pin / `receive.pin_expired` | Comprobar CA, SNI, IP y vigencia; renovación sólo pre-wire y una vez. No proxy/SSL-off/retry oculto. |
| Tamaño/nodos/evidencia/IPC agotados | Corte real por capacidad; conservar hechos previos y cobertura incompleta. No truncar evidencia para fingir cierre. |
| `graphql_error`, `schema_error`, `pagination_error`, `parse_error` | Cambio/malformación de proveedor o parser; no evidencia de ausencia. Capturar muestra saneada autorizada y añadir regresión. |
| `identity_unverified`, `identity_conflict`, `identity_mismatch`, `ambiguous_identity` | Identidad no publicable o ambigua; no elegir primer candidato ni activar AP ante error. |
| `unsupported_section` en documents | Límite empty-only conocido, no fallo recuperable inventando documentos. |
| `success/no_exact_match` | Negativo exhaustivo dentro del scope; producto/source null y cobertura global falsa son correctos. |
| Error nativo sólo en host no calificado | Inspeccionar `supported()`/capabilities; no saltar tests ni retirar gates para pintar verde. |

Ante procesos largos/config cache, la closure relee el repositorio de configuración que realmente tiene el proceso: editar el archivo o una variable Cloud no garantiza que ese proceso vea un kill-switch instantáneo. Definir y probar reload/drain del caller real antes del despliegue.

## Pruebas y evidencia reproducible

Desde un runtime que cumpla los gates anteriores, comandos nativos del repositorio:

```bash
composer check-platform-reqs
php artisan test --compact tests/Unit/Wix/ServiceTest.php tests/Unit/Wix/PreflightTest.php tests/Unit/Wix/KillSwitchTest.php tests/Unit/Wix/AcceptanceTest.php
php -d pcre.jit=0 artisan test --compact --do-not-cache-result --fail-on-warning --fail-on-notice --fail-on-deprecation --fail-on-skipped tests/Unit/Wix
```

La suite debe ser **offline**: fixtures y laboratorio DNS/TLS local; habilitar la configuración no autoriza WIX LIVE. Usar aislamiento de red externo sin egress y no ejecutar contra base de datos productiva. `AcceptanceTest` prueba también rechazo de requests Laravel inesperadas; la ruta cURL nativa necesita aislamiento real, no sólo `Http::fake`. Las pruebas de servicio cubren resolución transitoria sin I/O, default habilitado con prerequisitos ausentes, deshabilitación explícita y cero envíos; contrato/consumer cubren las seis parejas.

Para modificar PHP, ejecutar Pint sólo sobre los archivos propios cambiados; para esta entrega: `vendor/bin/pint --dirty --format agent config/wix.php tests/Unit/Wix/ServiceTest.php tests/Unit/Wix/PreflightTest.php tests/Unit/Wix/AcceptanceTest.php`.

**Reproducibilidad del laboratorio:** la evidencia histórica utilizó una imagen local existente `filtroven-prd15-php84-21:20261021` (CLI oficial 8.4.26 con extensiones requeridas) y binario/INI Herd 8.5.0 montados readonly. Ejecución secuencial por runtime, CPU 1, cgroup 512 MiB/pids 64, usuario sin privilegios, filesystem readonly, capabilities eliminadas, no-new-privileges, red `none`, vendor/fuentes readonly y scratch/cache en tmpfs. El servidor propio del laboratorio usa loopback dentro del contenedor. Estas medidas del harness no son instrucciones de despliegue Cloud ni todas son gates del producto.

**No hay Dockerfile ni runner portable versionado que reconstruya esa imagen.** Los runners y manifiestos de aquella ejecución viven en `/tmp/opencode/prd15-live-diagnosis/`; son artefactos locales de auditoría, no un requisito que otro miembro deba encontrar en su máquina. Para repetir en otro equipo se necesita preparar/acreditar un entorno equivalente con PHP 8.4/8.5 y las extensiones/primitivas de esta guía; no se promete que instalar PHP o ejecutar la suite en cualquier host funcione. No instalar/pullar una imagen supuesta ni ampliar gates para ocultar esta limitación. Los tests/corpus sí están en el repositorio.

Incidentes que explican regresiones importantes: CNAME recursivo válido empaquetado fue rechazado por owner incorrectamente estricto; un registro JSON/HTML grande legítimo excedía el frame IPC y ahora se fragmenta sin subir el cap; TTL antes se descontaba desde inicio DNS, no recepción. `ProductionBoundaryTest`, `ProductionBudgetTest` y `ContinuityTest` cubren estos límites; `MediaTest`/`ContinuityTest` preservan el recurso completo anterior a una interrupción a mitad de página. No tratar cuerpo saneado pequeño como prueba de todo tamaño real.

Evidencia LIVE **previa**, no reejecutada para esta documentación: 51515 `success/exact` el 2026-10-26T21:41:30Z (7.940 s) y negativo reservado exhaustivo CO+AP (2.256 s). El 2026-10-27 entre 11:59:26–11:59:56 UTC hubo tres 42831 `success/exact` (3.495/2.638/5.452 s), después de un parcial anterior por DNS de assets no reproducido. Son observaciones puntuales fuera de Cloud, no SLA ni fiabilidad universal. La renovación de pin no fue necesaria en esos éxitos: está acreditada por laboratorio hermético, no por esos LIVE.

## Compatibilidad con Laravel Cloud

Investigación documental oficial del **2026-10-27**, contrastada con el código actual. **Cloud es un destino plausible para un padre PHP CLI, pero la compatibilidad completa no está demostrada.** No se ha desplegado, creado recursos, medido Cloud ni consultado WIX desde Cloud. No hay evidencia para afirmar que Cloud prohíba `proc_open` en general, ni para decir “PHP compatible, sólo desplegar”.

### Matriz de compatibilidad

| Condición | Estado sustentado | Fuente / brecha |
| --- | --- | --- |
| PHP 8.4/8.5 y familia Laravel 13 | Confirmado a nivel de versiones soportadas | [Runtimes][cloud-runtime], [Deployments][cloud-deploy]. Build/binario exacto por medir. |
| curl/dom/libxml/posix/sockets/json/zlib | Listadas oficialmente; funciones concretas condicionales | [Extensiones][cloud-extensions]. No acredita syscalls/INI ni comportamiento del hijo. |
| Ejecutar PHP CLI | Commands y custom background processes documentados | [Commands][cloud-commands], [Workers][cloud-workers]. No equivale al SAPI del caller web. |
| Padre web con `PHP_SAPI != cli` | **Incompatible con política actual del adaptador** | Gates padre y `PHP_BINARY`; no atribuir una prohibición al proveedor. SAPI web default no acreditado por las fuentes consultadas. |
| libcurl 7.88.1/8.17.0 | No documentado para la imagen Cloud real | Medir; otra versión falla aunque sea reciente. |
| proc_open, IPC/select/buffers, POSIX RLIMIT_AS, procfs | No acreditado | Extensiones no prueban permisos efectivos; requiere probe. |
| cgroup v2 paths y memoria 64–512 MiB/pids 2–64 | No acreditado; condición crítica actual | Un tamaño anunciado de 512 MB no demuestra contenido/rutas/pids. Una instancia mayor puede quedar fuera del gate. |
| Memoria PHP, AS y headroom/HWM | Condicional | [Memoria PHP][cloud-memory] no es cgroup ni AS; [502/OOM][cloud-oom] advierte sobre falta de memoria. |
| DNS UDP al resolver explícito, HTTPS directo/pin/CA sin proxy | Condicional, no medido | [Network][cloud-network] documenta red/IPs de salida, no garantiza esta semántica precisa. Resolver interno puede servir si cumple. WAF/cache ingress no prueban política egress. |
| Un dominio CLI con una réplica | Plausible, no calificado | [Autoscaling][cloud-compute] permite `None`; [Workers][cloud-workers] admite proceso custom. No existe un nuevo caller/worker WIX implementado aquí. |
| Admisión global entre réplicas/clusters | **No soportada por el lock local actual** | [Filesystem][cloud-filesystem]: disco propio efímero por réplica; flock no se distribuye. |
| Continuidad de inode entre deploys | No garantizada | Filesystem se reinicia; [Deployments][cloud-deploy] y [shutdown_timeout][cloud-api] requieren medir solapamiento/drain old/new. |
| Timeout de entorno | Ajustable según API; compatibilidad condicional | [API][cloud-api]: `timeout` 5–60 s; 30 es ejemplo, no default demostrado. Sumar wrapper/cierre al presupuesto real. |
| Imagen Docker/cgroup/pids personalizados | Soporte general no documentado | [Private Cloud][cloud-private] ofrece add-ons controlados, no prueba imagen arbitraria ni privilegios. No recomendar contratarlo por especulación. |

Cloud documenta Octane opcional con FrankenPHP, **no demuestra `PHP_SAPI=cli` del caller real**. Un worker separado puede proporcionar padre CLI y aislar competencia, pero agregarlo no conecta automáticamente HTTP con el servicio ni corrige libcurl/cgroups. Cada proceso configurado corre en cada réplica; no duplicar dominios WIX inadvertidamente. La API prohíbe `start_command` para apps PHP: no presentar un servidor custom de arranque como ajuste disponible sin más.

El lock es especialmente importante: autoscaling `None` no basta si otro cluster, Commands, preview o release anterior también llama WIX con otro inode. El archivo no debe crearse en build y asumir que su device/inode será estable en runtime. Deploy commands no conservan cambios de filesystem y las réplicas tienen discos distintos. No borrar/recrear el lock durante una lease. No se implementó Redis lock, cola, servicio REST ni coordinación entre releases.

### Calificación mínima propuesta, NO ejecutada

1. Acordar alcance de un entorno de prueba y coste si requiere recursos. Inspeccionar **sin WIX** caller real, instancia/cluster/release, SAPI, PHP_BINARY, arquitectura, libcurl, INI/extensiones padre/hijo, funciones y rutas cgroup/procfs. Commands puede ejecutarse en cualquier instancia: una muestra CLI no certifica caller web ni todas las réplicas.
2. Probar worker estático/IPC/rlimit/cleanup herméticamente con pruebas existentes adecuadas; medir baseline VMS/HWM y headroom, deadline/cancelación, FDs e hijos sin huérfanos. Sin instalar Docker/prlimit en Cloud ni cambiar los guards.
3. Acreditar **un único dominio de admisión** con inode real compartido por todos sus callers; competición, restart, config reload, sleep y rollover/drain entre releases. Evitar procesos interrumpibles por scale-to-zero durante trabajo. No afirmar exclusión global a partir de un flock local verde.
4. Sólo con alcance de red autorizado, comprobar resolver UDP A/AAAA y HTTPS directo CA/SNI/pin/expiry mediante laboratorio controlado antes de WIX. No fallback nativo/proxy/TLS inseguro para pasar.
5. Una vez apto y autorizado, lookup público mínimo secuencial positivo y negativo con caps intactos; observar DTO, contadores reservas vs HTTP, DNS/TLS, memoria, referencias y cleanup. No convertir dos éxitos en SLA, permiso comercial ni certificación universal.

Si falla sólo configuración real, completar esa configuración con evidencia y regresiones. Si falla SAPI padre, libcurl, cgroup/procfs, primitivas, proxy obligatorio o topología multirréplica requerida, presentar la discrepancia antes de decidir un cambio de diseño. **No quitar gates ni inventar valores para declarar compatibilidad.** No se requiere aprobación humana por consulta ordinaria: lo pendiente es calificar el entorno y alcance de despliegue.

### Fuentes oficiales Cloud

Referencias recuperadas para la investigación del 2026-10-27; describen capacidades documentadas, no resultados de un probe de esta aplicación:

- [Runtimes PHP][cloud-runtime]; [extensiones][cloud-extensions]; [Commands][cloud-commands]; [filesystem][cloud-filesystem]; [memoria PHP][cloud-memory].
- [Compute/autoscaling y Octane][cloud-compute]; [worker clusters y procesos custom][cloud-workers]; [network e IPs][cloud-network].
- [Deployments][cloud-deploy]; [API UpdateEnvironment: timeout, shutdown_timeout, start_command][cloud-api]; [Private Cloud add-ons][cloud-private]; [diagnóstico 502/memoria][cloud-oom].
- [Guía de build Laravel][cloud-build]: comandos de build/env, no receta ejecutada aquí. Config cache/env requieren revisar fase y reload; las claves WIX actuales son literales null, no env knobs existentes.

[cloud-runtime]: https://laravel.com/cloud/docs/runtimes.md#php
[cloud-extensions]: https://laravel.com/cloud/docs/environments.md#php-extensions
[cloud-commands]: https://laravel.com/cloud/docs/environments.md#commands
[cloud-filesystem]: https://laravel.com/cloud/docs/environments.md#filesystem
[cloud-memory]: https://laravel.com/cloud/docs/environments.md#php-memory-limit
[cloud-compute]: https://laravel.com/cloud/docs/compute.md#autoscaling
[cloud-workers]: https://laravel.com/cloud/docs/workers.md#worker-clusters
[cloud-network]: https://laravel.com/cloud/docs/network.md#cloud-compute-network
[cloud-deploy]: https://laravel.com/cloud/docs/deployments.md#introduction
[cloud-api]: https://laravel.com/cloud/docs/api/environments/update-environment.md#openapi
[cloud-private]: https://laravel.com/cloud/docs/private-cloud/build-configuration.md#managing-add-ons
[cloud-oom]: https://laravel.com/cloud/docs/knowledge-base/502-error.md#your-environment-may-be-under-provisioned
[cloud-build]: https://laravel.com/cloud/docs/deploy-guides/laravel.md#build-your-application

## Mantenimiento y preparación de despliegue

Antes de integrar un caller productivo: completar límites/runtime con valores medidos, acreditar condiciones de uso WIX con fuente y fecha, calificar SAPI/topología/capacidades/red/memoria/admisión y probar monitorización, deshabilitación y drain. El switch ya está ON; no equivale a permiso del proveedor ni a infraestructura lista. Los términos/cuotas/uso permitido siguen sin acreditación: no inventar certificación legal ni roles/firma administrativa nuevos.

Para cambios del proveedor: conservar muestra auténtica saneada y linaje, distinguir fixtures genuinas de derivadas/sintéticas, actualizar sólo el perfil/inventario/parser pertinente, añadir contracasos y ejecutar suite offline ambos runtimes secuencialmente. Mantener contrato 1.0 compatible o versionar explícitamente si cambia su significado. No reescribir PRD/archivo histórico para ocultar una brecha.

**Pendiente para Cloud:** autorización de una calificación acotada y medición real. No se han creado recursos, instalado dependencias, desplegado ni ejecutado nuevas consultas WIX como parte de esta guía.
