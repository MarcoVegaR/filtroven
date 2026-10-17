# PRD-07 — Consolidación del Boilerplate: Inventario de Patrones, Generador CRUD Base y Criterios de Evolución

## 1. Problema y Objetivos

### 1.1 Problema

La primera ola del boilerplate dejó resueltas las capacidades base:

- **PRD-00**: producto interno y governance.
- **PRD-01**: personalización corporativa.
- **PRD-02**: núcleo de identidad y autorización.
- **PRD-03**: operabilidad transversal.
- **PRD-04**: estándar de módulos CRUD administrativos.
- **PRD-05**: administración de acceso (roles y usuarios).
- **PRD-06**: visor administrativo de auditoría.

El problema ahora no es "qué falta construir desde cero", sino **cómo gobernar la transición entre la primera ola fundacional y la adopción real del boilerplate** en proyectos derivados.

Existen tres riesgos concretos:

1. **Abstracciones prematuras**: extraer patrones antes de que existan suficientes casos reales que los validen. Actualmente solo existen dos módulos implementados (acceso CRUD + visor read-only) — insuficiente para generalizaciones agresivas.
2. **Documentación desalineada**: ADRs y guías que reflejan intención original pero no el código que realmente se implementó.
3. **Ausencia de criterios de evolución**: sin reglas claras, la segunda ola podría agregar complejidad sin valor o, por el contrario, estancarse por falta de dirección.

### 1.2 Objetivo principal

Cerrar la primera ola del boilerplate con un ejercicio de consolidación pragmático que:

- verifique que la documentación refleja la implementación real;
- identifique patrones validados y los catalogue sin extraerlos prematuramente;
- apruebe un generador de scaffold CRUD base parametrizable para automatizar trabajo mecánico ya comprobado;
- defina criterios formales para futuras extracciones de patrones y generadores avanzados;
- deje el boilerplate listo para escalar de forma controlada.

### 1.3 Objetivos específicos

- Reconciliar ADRs y guías existentes con el código implementado.
- Inventariar patrones ya validados, distinguiendo entre los que ya están extraídos y los candidatos futuros.
- Diseñar e implementar un generador de scaffold CRUD administrativo base, parametrizable y limitado.
- Definir criterios formales de extracción de patrones y creación de generadores avanzados.
- Definir criterios formales de adopción de nuevas capacidades.
- Actualizar la guía de uso del boilerplate con evidencia de adopción real.
- Documentar el estado de madurez del boilerplate para decisiones informadas.

### 1.4 Lo que este PRD NO hace

- **No extrae patrones a abstracciones compartidas nuevas** (traits, helpers, contratos). El inventario identifica candidatos; la extracción se ejecuta cuando exista evidencia transversal suficiente (§4.5).
- **No crea generadores de dominio, generadores inteligentes ni generadores de stack completo.** Solo se aprueba un generador de scaffold CRUD base parametrizable (§4.6).
- **No define política de distribución a proyectos derivados.** PRD-00 §4.6 ya establece que paquetes versionados requieren repetición en al menos 2 proyectos. No existen proyectos derivados aún.

## 2. Alcance (Scope)

### 2.1 Entra en esta iteración

Este PRD cubre:

- inventario de patrones ya implementados y candidatos futuros, con evidencia concreta;
- reconciliación de ADRs existentes con implementación real (estado, trazabilidad);
- actualización de `docs/crud-module-guide.md` con hallazgos post-implementación;
- **diseño e implementación de un generador de scaffold CRUD administrativo base**, parametrizable y limitado;
- definición de criterios de madurez para futuras extracciones de patrones;
- definición de criterios de madurez para futuros generadores avanzados;
- definición de criterios de adopción de nuevas capacidades del boilerplate;
- registro del estado de madurez actual de cada capa del boilerplate.

### 2.2 Fuera de alcance en esta iteración

Queda fuera:

- nuevos módulos funcionales de negocio;
- extracción efectiva de patrones a abstracciones compartidas nuevas (traits, helpers, contratos);
- generadores de dominio complejo, generadores inteligentes o generadores de stack completo;
- política de distribución y versionado para proyectos derivados (no existen aún);
- rediseño de identidad/autorización;
- rediseño del estándar CRUD;
- migración de stack frontend;
- cambio de paquete RBAC o infraestructura.

### 2.3 Decisión de alcance congelada

Este PRD es **primariamente de consolidación y governance**, con una única excepción de implementación: el generador de scaffold CRUD base parametrizable. No autoriza refactors de código existente, nuevas dependencias externas ni cambios en la estructura de archivos de los módulos ya implementados.

## 3. User Stories

### 3.1 Como dueño del boilerplate

Quiero saber exactamente qué patrones ya están estabilizados y cuáles necesitan más evidencia antes de extraerse, para no llenar la base de abstracciones prematuras.

### 3.2 Como desarrollador que arrancará el primer proyecto derivado

Quiero una guía actualizada y precisa para crear nuevos módulos sin redescubrir decisiones ya resueltas.

### 3.3 Como mantenedor del boilerplate

Quiero criterios claros para decidir cuándo vale la pena crear un generador interno y cuándo es mejor seguir la guía manual.

### 3.4 Como arquitecto del sistema

Quiero que ADRs y documentación reflejen lo que realmente fue validado en implementación, no solo la intención original.

### 3.5 Como futuro equipo de desarrollo

Quiero un marco de decisión para evaluar nuevas capacidades sin abrir un ciclo de improvisación.

### 3.6 Como desarrollador que creará el siguiente módulo CRUD

Quiero un generador que me entregue la columna vertebral del módulo (controller, requests, policy, rutas, páginas, tests base, permisos, seguridad) para enfocarme en la lógica de dominio y no en trabajo mecánico repetido.

## 4. Requerimientos Técnicos

### 4.1 Precondiciones

Este PRD asume que los siguientes PRDs están implementados y validados en código:

| PRD | Módulo | Estado |
| --- | --- | --- |
| PRD-04 | Estándar CRUD administrativo | ✅ Implementado |
| PRD-05 | Administración de acceso (roles + usuarios) | ✅ Implementado |
| PRD-06 | Visor administrativo de auditoría | ✅ Implementado |

**Estado real de módulos implementados**:

| Módulo | Tipo | Operaciones | Archivos test |
| --- | --- | --- | --- |
| Roles | CRUD completo + lifecycle | index, create, store, show, edit, update, destroy, activate, deactivate | 7 archivos |
| Users | CRUD completo + lifecycle + bulk | index, create, store, show, edit, update, destroy, activate, deactivate, bulk, export, send-reset, sync-roles | 7 archivos |
| Audit | Read-only + export | index, show, export | 1 archivo |
| Permissions | Read-only | index | 1 archivo |

**Implicación clave**: existen **dos CRUDs completos** (Roles, Users) dentro del mismo módulo funcional (acceso) y **un visor read-only** (Audit). Esto constituye evidencia suficiente de **repetición estructural** (mismo esqueleto de archivos, misma anatomía de controller/request/policy/pages/tests) para justificar un generador de scaffold base. Sin embargo, **no** constituye evidencia de repetición transversal suficiente para extraer abstracciones compartidas (traits, helpers, contratos), que requieren validación en módulos de contextos funcionales distintos.

### 4.2 Inventario de patrones: estado actual

#### A. Componentes UI — ya extraídos y operativos

Los siguientes componentes ya existen como artefactos compartidos. **No requieren extracción adicional**.

| Componente | Archivo | Usado en |
| --- | --- | --- |
| `StatusBadge` | `components/system/status-badge.tsx` | Roles, Users |
| `StatCard` | `components/system/stat-card.tsx` | Roles show, Users show |
| `PageHeader` | `components/system/page-header.tsx` | Roles, Users, Audit |
| `PasswordField` | `components/system/password-field.tsx` | Users create/edit |
| `PermissionPicker` | `components/system/permission-picker.tsx` | Roles create/edit |
| `RoleSelector` | `components/system/role-selector.tsx` | Users create/edit |
| `BulkActionBar` | `components/system/bulk-action-bar.tsx` | Users index |
| `AuditSourceBadge` | `components/system/audit-source-badge.tsx` | Audit index/show |
| `UserAvatar` | `components/system/user-avatar.tsx` | Users, Audit |
| `Table` + primitivas | `components/ui/table.tsx` | Roles, Users, Audit |
| `Pagination` | `components/ui/pagination.tsx` | Roles, Users, Audit |
| `EmptyState` | `components/ui/empty-state.tsx` | Roles, Users, Audit |
| `ConfirmationDialog` | `components/ui/confirmation-dialog.tsx` | Roles, Users |
| `Toolbar` | Composición en cada index | Roles, Users, Audit |
| Flash/Toasts | `components/flash-toaster.tsx` | Global |

| Utilidad compartida | Archivo | Función |
| --- | --- | --- |
| `resolveIcon()` | `lib/system.ts` | Mapeo icon name → Lucide component |
| `groupLabel()` | `lib/system.ts` | Etiquetas amigables para grupos de permisos |
| Event labels | `lib/system.ts` | Labels amigables para eventos de auditoría |
| `useCan()` hook | `hooks/use-can.ts` | Reflejo de permisos en UI |

**Conclusión**: la extracción de componentes UI compartidos **ya ocurrió** como parte natural de PRD-04/05/06. No hay deuda significativa de extracción UI pendiente.

#### B. Patrones backend — implementados pero no abstraídos

Los siguientes patrones se repiten entre módulos pero están implementados inline en cada controller/request. **Se documentan como candidatos para futura extracción, no como trabajo inmediato**.

| Patrón | Dónde se repite | Estado | Criterio para extraer |
| --- | --- | --- | --- |
| Filtro `search` con `ilike` | `RoleController::index`, `UserController::index` | 2 usos | Extraer cuando un 3er módulo CRUD lo necesite |
| Filtro `status` (active/inactive/all) | `RoleController::index`, `UserController::index` | 2 usos | Extraer cuando un 3er módulo CRUD lo necesite |
| Paginación con `withQueryString()` | Roles, Users, Audit | 3 usos | Patrón de una línea — no justifica abstracción |
| Activate/Deactivate controllers | `RoleActivateController`, `RoleDeactivateController`, `ActivateUserController`, `DeactivateUserController` | 4 controllers | Extraer trait o concern cuando un 3er modelo con lifecycle lo necesite |
| Protección "último admin" | `UserController`, `DeactivateUserController` | 2 usos | Mantener inline — lógica específica de dominio de acceso |
| Exportación CSV | `ExportUsersController`, `AuditExportController` | 2 usos | Extraer contrato base cuando un 3er módulo lo necesite |
| SecurityAuditService calls | Dispersos en controllers de System | Múltiples usos | Ya es un service extraído — no requiere cambio |
| Permission cache flush | Activate/deactivate de roles | 2 usos | Mantener inline — acoplado a Spatie |

#### C. Patrones de testing — implementados y consistentes

| Patrón | Dónde se repite | Estado |
| --- | --- | --- |
| Factory states (`withSuperAdmin`, `withTwoFactor`) | `UserFactory` | ✅ Ya extraído |
| Helper `actingAsUserWithPermission()` | `tests/Pest.php` | ✅ Ya extraído |
| Patrón de test de autorización por capas | 4 archivos `*AuthorizationTest.php` | Consistente, no requiere extracción adicional |
| Patrón de test de lifecycle | `RoleLifecycleTest`, `UserLifecycleTest` | 2 archivos — consistente |

#### D. Documentación — ya existente

| Artefacto | Archivo | Estado |
| --- | --- | --- |
| Guía de módulo CRUD | `docs/crud-module-guide.md` | ✅ Existente, 4 checklists incluidos |
| Guía de autorización | `docs/authorization.md` | ✅ Existente |
| Guía de operabilidad | `docs/operability-guide.md` | ✅ Existente |
| ADR-005 Audit boundary | `docs/adr/ADR-005-audit-boundary.md` | ✅ Existente |
| ADR-007 Logging & correlation | `docs/adr/ADR-007-logging-and-correlation.md` | ✅ Existente |
| ADR-008 Queue & scheduler | `docs/adr/ADR-008-queue-and-scheduler-policy.md` | ✅ Existente |
| ADR-009 CRUD module standard | `docs/adr/ADR-009-crud-module-standard.md` | ✅ Existente |

### 4.3 Reconciliación de ADRs

#### 4.3.1 Clasificación de estado

Cada ADR existente debe reclasificarse con un estado explícito basado en la implementación real:

| ADR | Estado propuesto | PRD origen | Módulos que lo validan | Observaciones |
| --- | --- | --- | --- | --- |
| ADR-005 | **Vigente** | PRD-03 | Roles, Users, Audit | Frontera audits/security_audit_log validada en PRD-05 y PRD-06 |
| ADR-007 | **Vigente** | PRD-03 | Todos (middleware global) | Correlation ID y canales de log operativos |
| ADR-008 | **Vigente** | PRD-03 | Users export (queue >1000) | Política de colas validada parcialmente en exportación |
| ADR-009 | **Vigente** | PRD-04 | Roles, Users | Estándar CRUD validado en PRD-05 |

**Resultado**: no hay ADRs obsoletos, superpuestos ni fragmentados. Los 4 ADRs existentes son vigentes y trazables a implementación real.

#### 4.3.2 ADRs faltantes según PRD-00

PRD-00 §7 listaba ADRs planificados. Estado de cumplimiento:

| ADR planificado | Estado |
| --- | --- |
| ADR-001: Topología monolito modular | No creado — cubierto implícitamente por PRD-00 §4.2 |
| ADR-002: Estrategia template vs paquetes | No creado — cubierto por PRD-00 §4.6 |
| ADR-003: Stack base congelado | No creado — cubierto por PRD-00 §4.1 |
| ADR-004: Criterios golden path | No creado — cubierto por PRD-00 §4.6 governance |
| ADR-005: Modelo de auditoría base | ✅ Creado |
| ADR-006: Distribución a proyectos derivados | No creado — no hay proyectos derivados aún |

**Decisión**: los ADRs 001-004 no se crean como documentos separados. Su contenido ya está consolidado en PRD-00 como decisiones congeladas. Crear documentos independientes solo agregaría redundancia. Si una decisión congelada en PRD-00 cambia, se crea el ADR correspondiente para documentar el cambio.

ADR-006 se difiere hasta que exista al menos un proyecto derivado real.

#### 4.3.3 Actualización de ADRs existentes

Cada ADR existente debe actualizarse para incluir:

- **Estado explícito**: `vigente | reemplazado | experimental | obsoleto`
- **PRD origen**: referencia al PRD que lo motivó.
- **Validado en**: lista de módulos/PRDs donde se aplicó.
- **Código de referencia**: paths al código que implementa la decisión.

### 4.4 Actualización de la guía de módulo CRUD

`docs/crud-module-guide.md` debe actualizarse con hallazgos post-implementación de PRD-05 y PRD-06:

| Sección | Actualización requerida |
| --- | --- |
| Module File Checklist | Agregar lifecycle controllers invokables como patrón documentado |
| Controller Convention | Documentar patrón de bulk actions controller si se repite |
| Lifecycle Operations | Agregar ejemplo concreto de activate/deactivate basado en Roles/Users |
| Five-Layer Security | Verificar que los 5 layers coinciden con la implementación real en PRD-05 |
| Checklist A | Verificar que todos los items aplican al módulo Audit (read-only) |
| Nuevo: Read-Only Module Variant | Documentar la variante de módulo read-only (Audit) como sub-patrón |

**Principio**: la guía se actualiza con evidencia de lo que se construyó, no con especulación de lo que se podría construir.

### 4.5 Criterios de extracción de patrones (para ejecución futura)

**Distinción clave**: este PRD distingue entre dos niveles de evidencia:

- **Repetición estructural** (mismo esqueleto de archivos, misma anatomía de controller/request/policy/pages/tests): suficiente para justificar un **scaffold generador** que produce código esqueleto editable. Los CRUDs de Roles y Users ya proporcionan esta evidencia.
- **Repetición transversal** (mismo patrón de comportamiento extraíble a una abstracción compartida como trait, helper o contrato): requiere evidencia más fuerte porque la abstracción vive en el código permanentemente y afecta a todos los consumidores.

Un patrón solo podrá extraerse a un componente, trait, helper o contrato compartido si cumple **todos** los criterios siguientes:

1. **Repetición comprobada**: aparece en al menos **3 módulos independientes** (no 2 del mismo módulo funcional). Justificación: Roles y Users son sub-módulos de acceso — su repetición interna es esperada, no evidencia de patrón transversal.
2. **Estabilidad**: su forma se mantuvo razonablemente estable entre los 3 usos.
3. **Reducción neta**: la extracción reduce duplicación **sin** aumentar el costo cognitivo ni la indirección.
4. **Límites claros**: los inputs, outputs y responsabilidades del patrón extraído son documentables.
5. **Testeable**: el patrón puede testearse de forma aislada.
6. **No universal**: la extracción no introduce una abstracción que intente cubrir casos que no existen.

**Cuándo NO extraer** — cualquiera de estas condiciones es suficiente para rechazar:

- Solo existe en módulos del mismo contexto funcional (e.g., Roles y Users son ambos "acceso").
- La implementación inline es ≤5 líneas y la abstracción sería más difícil de entender.
- El patrón depende de reglas de dominio específicas del módulo.
- La extracción forzaría una interfaz genérica que no se justifica todavía.

**Nota**: los criterios de extracción de patrones no aplican al generador de scaffold CRUD aprobado en §4.6, porque el generador produce código esqueleto que el desarrollador edita — no una abstracción compartida que vive en el boilerplate.

### 4.6 Generador de scaffold CRUD administrativo base

#### 4.6.1 Justificación

La repetición **estructural** entre los CRUDs de Roles y Users es suficiente evidencia para aprobar un generador de scaffold base. Si mañana se crea un CRUD de Bancos, Sucursales o Categorías, la columna vertebral será la misma:

- mismo index con toolbar, tabla, paginación y empty state;
- mismo create/edit con formulario compartido;
- mismo show con stat cards y acciones;
- mismas rutas por módulo;
- mismas policies con los mismos abilities;
- mismos FormRequests con authorize + rules;
- mismos tests base de CRUD y autorización;
- mismo enforcement de seguridad por capas;
- mismo patrón visual y layout administrativo.

Eso es trabajo mecánico repetido que sí justifica automatización. Lo que NO justifica es un generador que decida el dominio por ti.

**Principio rector**: el generador produce la **columna vertebral**; el desarrollador aporta el **dominio**.

#### 4.6.2 Arquitectura del generador

El generador se implementa como un comando Artisan personalizado que:

- **Solo crea archivos nuevos.** No modifica archivos existentes del proyecto.
- **Produce código legible y editable.** Los archivos generados son idénticos en estilo a los que un desarrollador escribiría manualmente siguiendo `docs/crud-module-guide.md`.
- **Funciona por parámetros, no por adivinación.** No infiere nada que no se le pase explícitamente.
- **Marca con `// TODO:` los puntos donde el desarrollador debe intervenir.** Especialmente en validaciones de Update que divergen de Store, relaciones, y lógica de dominio.
- **Soporta modo `--dry-run`** que muestra la lista completa de archivos que se generarían, sin escribir nada en disco.
- **Política de colisión segura**: si un archivo destino ya existe, el comando **falla con error explícito** indicando qué archivos colisionan. Se puede pasar `--force` para sobreescribir intencionalmente, pero **nunca sobreescribe silenciosamente**.

#### 4.6.3 Parámetros del generador

El generador se implementa en **dos fases** para evitar sobrediseño prematuro.

##### Fase 1 — Parámetros esenciales (se implementa en este PRD)

Estos parámetros son los mínimos necesarios para generar un scaffold funcional:

| Parámetro | Tipo | Requerido | Descripción |
| --- | --- | --- | --- |
| `module` | string | Sí | Nombre del módulo/namespace (e.g., `Catalog`, `Banking`) |
| `model` | string | Sí | Nombre del modelo (e.g., `Bank`, `Category`) |
| `fields` | array | Sí | Campos del modelo con tipo y atributos básicos |
| `fields.*.name` | string | Sí | Nombre del campo (e.g., `name`, `email`, `code`) |
| `fields.*.type` | enum | Sí | Tipo de campo: `string`, `text`, `integer`, `decimal`, `boolean`, `date`, `datetime`, `email`, `select` |
| `fields.*.required` | boolean | No (default: true) | Si el campo es requerido en formularios y validación |
| `fields.*.in_index` | boolean | No (default: false) | Si aparece como columna en el index |
| `fields.*.in_form` | boolean | No (default: true) | Si aparece en los formularios create/edit |
| `fields.*.in_show` | boolean | No (default: true) | Si aparece en la vista show |
| `fields.*.searchable` | boolean | No (default: false) | Si se incluye en la búsqueda del index |
| `fields.*.sortable` | boolean | No (default: false) | Si la columna es sorteable en el index |
| `fields.*.options` | array | Condicional | Opciones para campos tipo `select` |
| `route_prefix` | string | No | Prefijo de rutas (default: kebab-case del module) |
| `icon` | string | No | Icono Lucide para sidebar/header (default: `folder`) |
| `sidebar` | boolean | No (default: true) | Si se registra en la navegación |

**Advertencia sobre parámetros de UI**: los parámetros `in_index`, `searchable`, `sortable` y similares describen **visibilidad y estructura base** del scaffold; no sustituyen revisión humana de UX. Que un campo esté marcado como `in_index: true` y `sortable: true` no equivale a una decisión de UX validada de negocio — el desarrollador debe revisar y ajustar la experiencia del usuario final después de la generación.

##### Fase 2 — Parámetros avanzados (se implementa cuando exista validación real)

Estos parámetros se agregan **solo después** de que al menos un módulo generado con Fase 1 haya sido completado y deployado, validando que la Fase 1 es estable:

| Parámetro | Tipo | Descripción | Trigger |
| --- | --- | --- | --- |
| `lifecycle.deactivate` | boolean | Si incluye activate/deactivate controllers | Primer módulo generado con lifecycle |
| `lifecycle.soft_delete` | boolean | Si incluye soft delete + restore | Primer módulo generado con soft delete |
| `bulk_actions` | array | Acciones masivas habilitadas | Primer módulo generado con bulk |
| `export` | boolean | Si incluye exportación CSV | Primer módulo generado con export |
| `filters` | array | Filtros adicionales del index | Primer módulo generado con filtros custom |

#### 4.6.4 Archivos que genera

El generador produce los siguientes archivos, organizados por capa:

##### Backend

| Archivo generado | Contenido |
| --- | --- |
| `routes/{module}.php` | Route group con resource routes + middleware |
| `app/Http/Controllers/{Module}/{Model}Controller.php` | index, create, store, show, edit, update, destroy |
| `app/Http/Requests/{Module}/Store{Model}Request.php` | authorize() + rules derivadas de los campos |
| `app/Http/Requests/{Module}/Update{Model}Request.php` | authorize() + rules derivadas con `// TODO:` para divergencias |
| `app/Policies/{Model}Policy.php` | viewAny, view, create, update, delete con gate checks |
| `app/Models/{Model}.php` | Modelo Eloquent con $fillable, $casts y Auditable |
| `database/factories/{Model}Factory.php` | Factory con definiciones base por tipo de campo |
| `database/migrations/create_{models}_table.php` | Migración con columnas escalares simples (ver nota) |
| `database/seeders/{Module}PermissionsSeeder.php` | Permisos CRUD del módulo |

**Limitación de migraciones en Fase 1**: el generador solo crea columnas escalares simples derivadas de los tipos de campo soportados: `string`, `text`, `integer`, `decimal`, `boolean`, `date`, `datetime`. No genera relaciones, claves foráneas, índices compuestos, constraints `unique` avanzados, columnas JSON ni estructuras complejas de esquema. El diseño fino de esquema con dominio queda como responsabilidad del desarrollador — marcado con `// TODO:` en la migración generada.

##### Frontend

| Archivo generado | Contenido |
| --- | --- |
| `resources/js/pages/{module}/{models}/index.tsx` | Index con PageHeader, Toolbar, Table, Pagination, EmptyState |
| `resources/js/pages/{module}/{models}/create.tsx` | Create con formulario compartido |
| `resources/js/pages/{module}/{models}/edit.tsx` | Edit con formulario compartido |
| `resources/js/pages/{module}/{models}/show.tsx` | Show con bloques de definición y acciones |
| `resources/js/pages/{module}/{models}/components/{model}-form.tsx` | Formulario compartido con campos derivados |

##### Tests

| Archivo generado | Contenido |
| --- | --- |
| `tests/Feature/{Module}/{Model}IndexTest.php` | Index, búsqueda, paginación |
| `tests/Feature/{Module}/{Model}CreateTest.php` | Create form render, store, validación base |
| `tests/Feature/{Module}/{Model}UpdateTest.php` | Edit form render, update, validación base |
| `tests/Feature/{Module}/{Model}DeleteTest.php` | Destroy con autorización |
| `tests/Feature/{Module}/{Model}AuthorizationTest.php` | 5 capas de seguridad por acción |

#### 4.6.5 Validaciones generadas

El generador **sí** produce una primera capa útil de validación derivada de los parámetros:

**Reglas que SÍ se generan automáticamente:**

| Tipo de campo | Reglas en Store | Reglas en Update |
| --- | --- | --- |
| `string` (required) | `['required', 'string', 'max:255']` | `['required', 'string', 'max:255']` |
| `string` (nullable) | `['nullable', 'string', 'max:255']` | `['nullable', 'string', 'max:255']` |
| `text` | `['required', 'string']` | `['required', 'string']` |
| `integer` | `['required', 'integer']` | `['required', 'integer']` |
| `decimal` | `['required', 'numeric']` | `['required', 'numeric']` |
| `boolean` | `['boolean']` | `['boolean']` |
| `date` | `['required', 'date']` | `['required', 'date']` |
| `datetime` | `['required', 'date']` | `['required', 'date']` |
| `email` | `['required', 'string', 'email', 'max:255']` | `['required', 'string', 'email', 'max:255']` |
| `select` | `['required', Rule::in([...])]` | `['required', Rule::in([...])]` |

**Reglas que NO se generan — se marcan con `// TODO:`:**

- Unicidad condicionada (`unique` con `ignore` en Update).
- Reglas cruzadas entre campos.
- Restricciones por relaciones.
- Validaciones de negocio específicas.
- Reglas de formato custom (regex, etc.).

El `UpdateRequest` generado incluye un comentario explícito:

```php
// TODO: Revise estas reglas. Update frecuentemente diverge de Store
// (e.g., campos opcionales que en Store son required, unique con ignore, etc.)
```

#### 4.6.6 Seguridad generada

El generador produce seguridad base alineada con el modelo de 5 capas de PRD-04:

| Capa | Qué genera |
| --- | --- |
| 1. Route middleware | `['auth', 'verified', 'ensure-two-factor']` en el route group |
| 2. Policy | `{Model}Policy` con abilities: `viewAny`, `view`, `create`, `update`, `delete` |
| 3. FormRequest authorize | `$this->user()->can('{ability}', {Model}::class)` en cada Request |
| 4. Permission seeder | Permisos: `{module}.{models}.view`, `.create`, `.edit`, `.delete` |
| 5. Frontend reflejo | `useCan()` para proteger botones y acciones en páginas |

**Lo que NO genera en seguridad:**

- Protección "último admin" u otras reglas de dominio.
- Gates custom.
- Middleware adicional.
- Lógica de scope/tenant.

#### 4.6.7 Integración con sidebar/header

El generador **no modifica** archivos existentes del proyecto (sidebar config, header nav, etc.). En su lugar:

- Produce un bloque de configuración de navegación listo para copiar.
- Incluye instrucciones en la salida del comando indicando qué agregar y dónde.
- El registro de permisos en `lib/system.ts` (groupLabels, iconMap) se documenta como paso manual.

**Justificación**: modificar archivos existentes desde un generador es frágil y propenso a conflictos. El paso manual de integración es de <2 minutos y el desarrollador debe verificarlo.

#### 4.6.8 Reglas del output generado

Todo archivo generado debe:

- Respetar el estándar de PRD-04 y `docs/crud-module-guide.md`.
- Usar rutas por módulo con route file independiente.
- Respetar Wayfinder.
- Respetar policies y FormRequests.
- Respetar naming conventions del boilerplate.
- Respetar layout, tema y UX administrativa del boilerplate.
- Ser legible y editable — no "código mágico".
- Incluir `// TODO:` en cada punto donde el desarrollador debe tomar una decisión de dominio.
- Compilar, tipar y pasar tests base sin modificación manual.

#### 4.6.9 Testing del generador

El generador debe incluir sus propios tests:

- Test de que el comando se ejecuta sin errores con parámetros válidos.
- Test de que los archivos generados existen en las rutas esperadas.
- Test de que el código PHP generado pasa `vendor/bin/pint` sin errores.
- Test de que el código generado compila (`php artisan route:list` no falla).
- Test de que `npm run build` completa sin errores con las páginas generadas.
- Test de que los tests generados pasan en un entorno limpio.

### 4.7 Criterios para generadores avanzados (para ejecución futura)

Los generadores avanzados (lifecycle controllers, bulk actions, exportación, etc.) se rigen por criterios más estrictos que el scaffold base:

1. **Existe repetición real**: al menos **3 módulos independientes** implementados con la misma variante.
2. **El patrón es estable**: no cambió significativamente entre las 3 implementaciones.
3. **Ahorro mecánico real**: el generador ahorra >30 minutos de trabajo manual por módulo.
4. **Output legible**: los archivos generados son editables por humanos sin "deshacer magia".
5. **Alineado con estándar vigente**: el output respeta PRD-04 y `docs/crud-module-guide.md`.
6. **Sin opciones especulativas**: el generador no tiene flags para variantes que no se han implementado.

**Generadores prohibidos** — no se crean independientemente de la evidencia:

- Generador de stack completo (backend + frontend + tests + lifecycle + export en un solo comando).
- Generador con opciones de arquitectura (--with-service, --with-repository).
- Generador de IAM o seguridad avanzada.
- Generador que produzca código no alineado al estándar vigente.
- Generador que intente resolver lógica de dominio.

### 4.8 Criterios de adopción de nuevas capacidades

Cada nueva capacidad propuesta para el boilerplate debe evaluarse con estas preguntas antes de aprobarse:

| Pregunta | Criterio de aprobación |
| --- | --- |
| ¿Es fundacional o de segunda ola? | Las fundacionales (PRD-00 a PRD-06) ya están cerradas. Las de segunda ola requieren mayor justificación. |
| ¿Ya existe repetición real? | Al menos 2 proyectos o 3 módulos que la necesiten. |
| ¿Es transversal o de dominio? | Solo las transversales entran al boilerplate. Las de dominio van al proyecto específico. |
| ¿Aporta más de lo que complica? | El beneficio debe ser claramente mayor que el costo de mantenimiento perpetuo. |
| ¿Puede mantenerse sin crear deuda excesiva? | Si nadie va a mantenerla activamente, no entra. |
| ¿Existe evidencia en código, no solo en diseño? | Las capacidades especulativas no se aprueban. |

Este marco reemplaza la evaluación ad hoc y se alinea con PRD-00 §4.6 (governance del golden path).

### 4.9 Estado de madurez del boilerplate

El boilerplate debe registrar explícitamente su estado de madurez por capa:

| Capa | Madurez | Evidencia | Próximo hito |
| --- | --- | --- | --- |
| Autenticación | **Estable** | PRD-01, PRD-02, 8 tests auth | — |
| Autorización (RBAC) | **Estable** | PRD-02, Spatie, 8 tests authz | — |
| Operabilidad (errores, logs, audit) | **Estable** | PRD-03, 6 tests operability | — |
| Estándar CRUD | **Validado en 1 módulo** | PRD-04, PRD-05 (Roles + Users) | Validar con primer CRUD de negocio |
| Componentes UI compartidos | **Estable** | 9 componentes system/, guía, PRD-04/05/06 | — |
| Módulo de acceso | **Estable** | PRD-05, 14 tests system | — |
| Visor de auditoría | **Estable** | PRD-06, 1 test | — |
| Generador scaffold CRUD (Fase 1) | **Aprobado** | PRD-07, repetición estructural Roles/Users | Implementar y validar con primer CRUD de negocio |
| Generador scaffold CRUD (Fase 2) | **Diferido** | — | Primer módulo generado con Fase 1 completado |
| Generadores avanzados | **Diferido** | — | 3 módulos independientes con la misma variante |
| Política de distribución | **No iniciado** | — | Primer proyecto derivado |
| Versionado interno | **No iniciado** | — | Primer proyecto derivado |

**Interpretación de madurez**:
- **Estable**: implementado, testeado, documentado. Puede usarse en proyectos derivados.
- **Validado en N módulos**: implementado y testeado, pero requiere más evidencia para considerar extracciones.
- **No iniciado**: diferido intencionalmente por falta de evidencia o de necesidad real.

## 5. Criterios de Aceptación

### 5.1 Inventario de patrones

- Existe un inventario de patrones ya extraídos y candidatos futuros, con evidencia concreta de uso.
- El inventario distingue entre lo que ya está resuelto y lo que requiere más evidencia.
- Ningún patrón se extrae a abstracción compartida en esta iteración sin cumplir los criterios de §4.5.

### 5.2 Generador de scaffold CRUD base

- Existe un comando Artisan funcional que genera el scaffold CRUD completo (backend + frontend + tests) a partir de parámetros.
- El código generado compila, tipa y pasa tests base sin modificación manual.
- El código generado pasa `vendor/bin/pint` sin errores.
- El código generado pasa `npm run build` sin errores.
- Los archivos generados son legibles, editables y siguen el estándar de PRD-04 y `docs/crud-module-guide.md`.
- El generador no modifica archivos existentes del proyecto.
- El generador soporta `--dry-run` y falla con error explícito ante colisión de archivos.
- El generador incluye `// TODO:` en cada punto donde el desarrollador debe tomar decisiones de dominio.
- El generador incluye seguridad base de 5 capas (§4.6.6).
- El generador tiene sus propios tests (§4.6.9).
- Existe documentación de uso del generador que incluye al menos **2 ejemplos completos** (uno mínimo y uno intermedio) y una sección explícita de **qué no resuelve el generador**.

### 5.3 Criterios de extracción y generadores avanzados

- Existe un criterio formal y documentado para aprobar extracción de patrones (§4.5).
- Existe un criterio formal y documentado para aprobar generadores avanzados (§4.7).
- Ambos criterios exigen evidencia de repetición transversal real, no especulación.

### 5.4 ADRs

- Los 4 ADRs existentes quedan actualizados con estado explícito, PRD origen y código de referencia.
- Los ADRs planificados en PRD-00 que no se crearon quedan explícitamente diferidos con justificación.
- No se crean ADRs redundantes con contenido ya presente en PRDs.

### 5.5 Guía y documentación

- `docs/crud-module-guide.md` queda actualizada con hallazgos de PRD-05 y PRD-06.
- Se documenta la variante de módulo read-only basada en el visor de auditoría.
- Los checklists existentes se verifican contra la implementación real.

### 5.6 Marco de adopción futura

- Existe un conjunto de preguntas para evaluar nuevas capacidades (§4.8).
- Existe una tabla de madurez del boilerplate (§4.9) que registra el estado real de cada capa.
- El marco se alinea con PRD-00 §4.6 sin duplicarlo.

### 5.7 Calidad

- Los tests existentes (42 archivos) siguen pasando sin modificación.
- Los tests del generador pasan.
- `npm run build` sigue completando sin errores.
- `vendor/bin/pint --dirty --format agent` sigue limpio.

## 6. Dependencias y Riesgos

### 6.1 Dependencias

Este PRD depende de que los PRDs anteriores estén implementados:

| PRD | Estado |
| --- | --- |
| PRD-04 | ✅ Implementado |
| PRD-05 | ✅ Implementado |
| PRD-06 | ✅ Implementado |

### 6.2 Riesgos principales

| Riesgo | Severidad | Mitigación |
| --- | --- | --- |
| Tratar el inventario como autorización para extraer todo | Alta | Los criterios de §4.5 son gate obligatorio; el inventario es diagnóstico, no mandato |
| Generador scaffold se convierte en generador de dominio | Alta | Fase 1 limitada a parámetros esenciales; Fase 2 requiere validación real; generadores prohibidos explícitos (§4.7) |
| Generador produce código que no compila o no pasa tests | Alta | Testing del generador obligatorio (§4.6.9); CI debe validar output |
| Generador cristaliza convenciones aún inmaduras | Media | El output es código editable, no una abstracción permanente; `// TODO:` marca puntos de decisión; el primer uso real valida o desafía |
| Actualizar documentación sin verificar contra código real | Media | Cada actualización debe referenciar archivos concretos |
| Sobre-documentar y crear burocracia sin valor | Media | Principio: si ya está en un PRD, no se duplica en ADR; si ya está en código, no se duplica en guía |
| Confundir repetición estructural con repetición transversal | Media | La distinción de §4.5 es explícita: scaffold ≠ abstracción compartida |

### 6.3 Riesgo metodológico

Este PRD aprueba **un** generador de scaffold base — no autoriza extracciones de patrones a abstracciones compartidas ni generadores avanzados. La extracción de patrones transversales sigue requiriendo evidencia de 3 módulos independientes. El generador produce código esqueleto editable que el desarrollador adapta; no impone una abstracción permanente en el boilerplate.

## 7. Entregables esperados

Al cerrar este PRD, el boilerplate debe tener:

| Entregable | Tipo | Descripción |
| --- | --- | --- |
| Inventario de patrones | Sección en este PRD (§4.2) | Ya extraídos vs candidatos futuros |
| **Generador scaffold CRUD base (Fase 1)** | Comando Artisan + tests | Scaffold parametrizable: backend + frontend + tests (§4.6) |
| Criterios de extracción | Sección en este PRD (§4.5) | Gate formal para futuras extracciones transversales |
| Criterios de generadores avanzados | Sección en este PRD (§4.7) | Gate formal para generadores de lifecycle, bulk, export |
| Criterios de adopción | Sección en este PRD (§4.8) | Marco para nuevas capacidades |
| ADRs actualizados | 4 archivos actualizados | Estado, PRD origen, código de referencia |
| Guía CRUD actualizada | `docs/crud-module-guide.md` | Hallazgos post-implementación + variante read-only |
| Tabla de madurez | Sección en este PRD (§4.9) | Estado real de cada capa del boilerplate |

## 8. Qué sigue después de este PRD

Con la primera ola cerrada, el generador implementado y los criterios de evolución definidos, los próximos pasos naturales son:

1. **Primer módulo CRUD de negocio usando el generador**: el verdadero test del estándar PRD-04 y del generador Fase 1 fuera del contexto de acceso/sistema. Este módulo validará (o desafiará) tanto los patrones inventariados como la calidad del output del generador.
2. **Evaluación post-módulo**: después de implementar el primer CRUD de negocio, se revisa:
   - el inventario de §4.2 para decidir qué extracciones transversales se justifican;
   - la calidad del scaffold generado para decidir si se activa Fase 2 del generador;
   - si el patrón del generador se mantuvo estable o necesita ajustes.
3. **Primer proyecto derivado**: cuando exista, se activa la definición de política de distribución (ADR-006) y versionado interno.

La secuencia no se fuerza — se activa por evidencia real.

## 9. Decisiones congeladas de esta versión

1. **Se aprueba un único generador**: scaffold CRUD administrativo base, parametrizable (§4.6). No se aprueban generadores de dominio, inteligentes ni de stack completo.
2. **El generador se implementa en dos fases**: Fase 1 (parámetros esenciales) se implementa ahora; Fase 2 (parámetros avanzados) se activa cuando el primer módulo generado haya sido completado.
3. **No se extraen patrones a abstracciones compartidas** en esta iteración. Se inventarían. La extracción transversal requiere evidencia de 3 módulos independientes.
4. **Repetición estructural ≠ repetición transversal.** La primera justifica un scaffold generador; la segunda justifica abstracciones compartidas. Roles + Users sí cuentan como evidencia estructural suficiente para el generador, pero no como evidencia transversal para extracciones.
5. **No se crea política de distribución** sin proyectos derivados reales.
6. **Los ADRs 001-004 de PRD-00 no se crean** como documentos separados — su contenido ya vive en PRD-00.
7. **La guía se actualiza con evidencia**, no con especulación.
8. **El generador no modifica archivos existentes** — la integración con sidebar/header es un paso manual documentado.
9. **El generador produce código esqueleto con `// TODO:`** — no decide dominio, no genera lógica de negocio, no resuelve relaciones.
10. **Este PRD cierra la primera ola** del boilerplate y define las reglas de la segunda.
