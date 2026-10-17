# PRD-06 — Visor Administrativo de Auditoría

## 1. Problema y Objetivos

### 1.1 Problema

El boilerplate ya define cómo se generan y trazan eventos relevantes del sistema (PRD-03), pero todavía no ofrece una interfaz administrativa reusable para consultarlos. Sin un visor administrativo de auditoría, cada sistema futuro tendría que reconstruir por su cuenta:

- listados de eventos auditables;
- filtros por usuario, fecha, módulo o acción;
- detalle legible de cambios;
- distinción entre auditoría de modelos y eventos de seguridad;
- exportación de resultados;
- controles de acceso sobre información sensible.

Eso degrada una parte clave del boilerplate: la observabilidad administrativa reutilizable.

### 1.2 Objetivo principal

Construir un módulo reusable de consulta administrativa de auditoría que permita inspeccionar de forma clara, segura y filtrable los eventos auditables del sistema.

### 1.3 Objetivos específicos

1. Proveer un listado unificado y usable de eventos de auditoría.
2. Permitir filtrar eventos por múltiples dimensiones relevantes.
3. Permitir ver detalle de cambios y contexto del evento.
4. Separar claramente auditoría de modelos y auditoría de seguridad.
5. Mantener el módulo estrictamente read-only.
6. Integrar el módulo con el estándar CRUD de PRD-04 sin convertirlo en un "SIEM" ni en una consola forense sobrediseñada.

---

## 2. Alcance (Scope)

### 2.1 Entra en esta iteración

Este PRD cubre un módulo administrativo de lectura y consulta de auditoría, incluyendo:

- listado unificado de eventos de auditoría;
- filtro por fuente de auditoría (modelos / seguridad);
- filtro por rango de fechas;
- filtro por actor/usuario;
- filtro por tipo de evento;
- filtro por entidad afectada (tipo y/o ID);
- vista de detalle del evento;
- visualización de cambios old/new cuando existan (auditoría de modelos);
- visualización de metadatos relevantes del evento (auditoría de seguridad);
- exportación de resultados filtrados (CSV obligatorio, JSON opcional);
- permisos de acceso específicos al visor;
- integración con la auditoría generada por PRD-03.

### 2.2 Fuera de alcance

Queda fuera:

- creación o edición manual de eventos de auditoría;
- eliminación desde UI de registros auditables;
- dashboards analíticos avanzados (gráficas, tendencias, KPIs);
- correlación con logs de infraestructura externos;
- SIEM / alerting en tiempo real;
- monitoreo operativo de colas, jobs o performance;
- edición masiva de retention policies desde UI;
- investigación forense avanzada multi-sistema;
- CRUD de reglas de auditoría;
- búsqueda full-text avanzada (Elasticsearch, Meilisearch);
- visor de logs operacionales (`storage/logs`).

### 2.3 Decisión de alcance congelada

- Este módulo es **read-only**. No modifica eventos de auditoría.
- No reemplaza logs, ni observabilidad técnica, ni cumplimiento normativo externo.
- La unidad mínima de consulta es el evento individual. No se implementan agregaciones ni rollups.

---

## 3. User Stories

### 3.1 Como super-admin

Quiero consultar qué cambios ocurrieron en el sistema para investigar incidentes o decisiones administrativas.

### 3.2 Como responsable de seguridad

Quiero revisar eventos sensibles como login, logout, cambios de acceso, activaciones, desactivaciones o restauraciones.

### 3.3 Como operador del backoffice

Quiero filtrar auditoría por usuario, fecha o entidad para entender rápidamente qué pasó.

### 3.4 Como desarrollador o soporte

Quiero ver diferencias old/new y metadatos relevantes sin entrar directamente a la base de datos.

### 3.5 Como dueño del boilerplate

Quiero un visor reusable para no reconstruir esta capacidad en cada nuevo sistema.

---

## 4. Requerimientos Técnicos

### 4.1 Estado actual del baseline

Antes de este PRD ya debe existir:

| Dependencia | Estado | Fuente |
| --- | --- | --- |
| `owen-it/laravel-auditing` instalado y operativo | ✅ Operativo | PRD-03 |
| Tabla `audits` con modelos `User`, `Role`, `Permission` auditables | ✅ Operativo | PRD-03 |
| Tabla `security_audit_log` con listeners de seguridad | ✅ Operativo | PRD-03 |
| `SecurityAuditService` + `SecurityEventType` enum (19 cases) | ✅ Operativo | PRD-03, PRD-05 |
| Estándar CRUD: layout, toolbar, tablas, paginación, filtros, `useCan`, flash/toasts | ✅ Operativo | PRD-04 |
| Enforcement por capas (navegación → ruta → controller → FormRequest → UI) | ✅ Operativo | PRD-04 |
| Administración de roles y usuarios (productores de audit trail) | ✅ Operativo | PRD-05 |
| Política de exclusión/enmascaramiento de datos sensibles en auditoría | ✅ Operativo | PRD-03 (`config/audit.php` `exclude`) |
| `routes/system.php` con rutas de acceso | ✅ Operativo | PRD-05 |

### 4.2 Fuentes de datos auditables

El visor consume dos fuentes de auditoría existentes. No crea tablas nuevas de datos.

#### A. Auditoría de modelos — tabla `audits`

Eventos provenientes de `owen-it/laravel-auditing` vía trait `Auditable` en modelos Eloquent.

**Esquema existente** (migración `create_audits_table`):

| Columna | Tipo | Notas |
| --- | --- | --- |
| `id` | bigint PK | — |
| `user_type` | string, nullable | Morph del actor |
| `user_id` | bigint, nullable | ID del actor |
| `event` | string | `created`, `updated`, `deleted`, `restored` |
| `auditable_type` | string | Morph de la entidad afectada |
| `auditable_id` | bigint | ID de la entidad afectada |
| `old_values` | text, nullable | JSON de valores anteriores |
| `new_values` | text, nullable | JSON de valores nuevos |
| `url` | text, nullable | URL de la request que generó el cambio |
| `ip_address` | inet, nullable | IP del actor |
| `user_agent` | string(1023), nullable | User agent del actor |
| `tags` | string, nullable | Tags opcionales |
| `created_at` | timestamp | Timestamp del evento |
| `updated_at` | timestamp | — |

**Modelos auditables actuales**: `User`, `Role`, `Permission`.

**Morph aliases**: la columna `auditable_type` almacena el FQCN del modelo (e.g., `App\Models\User`). Para presentar nombres legibles en la UI, el visor necesita un mapeo de morph type → label amigable:

| `auditable_type` | Label amigable |
| --- | --- |
| `App\Models\User` | Usuario |
| `App\Models\Role` | Rol |
| `App\Models\Permission` | Permiso |

Este mapeo se define en el backend (e.g., constante o config en el controller/service) y se replica en el frontend. Si un proyecto derivado agrega modelos auditables, debe extender este mapeo.

**Nota sobre `old_values` / `new_values`**: estas columnas son de tipo `text` en la migración, pero almacenan JSON. El modelo `Audit` de `laravel-auditing` ya aplica el cast correspondiente, por lo que el controller recibe arrays PHP directamente.

**Datos sensibles ya excluidos** por `config/audit.php`:
- `password`
- `two_factor_secret`
- `two_factor_recovery_codes`
- `remember_token`

#### B. Auditoría de seguridad/acceso — tabla `security_audit_log`

Eventos provenientes de `SecurityAuditService` + listeners de Laravel.

**Esquema existente** (migración `create_security_audit_log_table`):

| Columna | Tipo | Notas |
| --- | --- | --- |
| `id` | bigint PK | — |
| `event_type` | string(100), indexed | Valor del enum `SecurityEventType` |
| `user_id` | bigint, nullable, indexed | Sin FK — preserva datos históricos |
| `ip_address` | inet, nullable | IP del actor |
| `correlation_id` | uuid, nullable, indexed | ID de correlación del request |
| `metadata` | jsonb, default `{}` | Contexto adicional del evento |
| `occurred_at` | timestamp, indexed | Timestamp del evento |

**Eventos actuales** (enum `SecurityEventType`, 19 cases):

| Grupo | Eventos |
| --- | --- |
| Autenticación | `login_success`, `login_failed`, `logout` |
| 2FA | `2fa_enabled`, `2fa_disabled` |
| Roles | `role_assigned`, `role_revoked`, `role_created`, `role_updated`, `role_deactivated`, `role_activated`, `role_deleted`, `permissions_synced` |
| Usuarios | `user_created`, `user_updated`, `user_deactivated`, `user_activated`, `user_deleted`, `password_reset_sent` |

### 4.3 Modelo funcional del módulo

El módulo tendrá dos vistas principales: Index (listado unificado) y Show (detalle del evento).

#### 4.3.1 Index de auditoría

Listado unificado con las siguientes columnas:

| Columna | Fuente model_audit | Fuente security_audit | Notas |
| --- | --- | --- | --- |
| Fecha | `created_at` | `occurred_at` | Formato amigable (`diffForHumans` o `d/m/Y H:i`) |
| Fuente | literal `"Modelos"` | literal `"Seguridad"` | Badge visual diferenciado |
| Actor | `user` relación (morph) → `name` | `user` relación → `name` | Fallback: "Sistema" si `user_id` es null |
| Evento | `event` (`created`, `updated`, etc.) | `event_type` (valor del enum) | Label amigable (ver §4.5) |
| Entidad | `auditable_type` + `auditable_id` | Derivado de `metadata` cuando aplique | Tipo legible + ID |
| IP | `ip_address` | `ip_address` | Visible en listado, truncada si es larga |

#### 4.3.2 Show de auditoría

Vista de detalle del evento. El contenido varía según la fuente.

**Campos comunes**:

- Fecha exacta (timestamp completo + formato amigable).
- Actor (nombre + ID, link al usuario si existe y el usuario tiene permiso de ver usuarios).
- Tipo de evento.
- IP.
- Fuente (badge).

**Campos específicos de auditoría de modelos** (`audits`):

- Entidad afectada (tipo legible + ID, link si aplica).
- Cambios old → new en formato tabla comparativa.
- URL de la request que generó el cambio.
- User agent.
- Tags si existen.

**Campos específicos de auditoría de seguridad** (`security_audit_log`):

- Tipo de evento (label amigable del enum).
- Metadata saneada en formato clave-valor legible.
- Correlation ID.
- Contexto derivado de metadata (e.g., rol afectado, email intentado).

### 4.4 Estrategia de visualización

#### 4.4.1 Listado unificado

**Decisión congelada**: el index será un listado unificado, no dos módulos separados.

**Mecanismo de unificación**: el controller consulta ambas tablas, normaliza los resultados a un shape común y los combina ordenados por fecha descendente. La paginación opera sobre la unión.

**Implementación recomendada**: dado que las dos tablas tienen esquemas diferentes, el controller construye una unión normalizada:

```php
// Pseudocódigo conceptual — la implementación puede usar union de queries,
// colección manual o un approach intermedio según performance.
// La decisión de implementación se toma durante desarrollo.
```

**Alternativa aceptable**: si la unión SQL resulta compleja o tiene problemas de performance, se acepta un enfoque con filtro de fuente obligatorio — donde el usuario selecciona "Modelos", "Seguridad" o "Todos" y el controller consulta una o ambas tablas según corresponda. Esta es una concesión pragmática para la primera iteración.

Cada fila debe indicar claramente su fuente mediante **badge de color diferenciado**:
- Modelos: badge neutro/outline.
- Seguridad: badge accent/warning.

#### 4.4.2 Detalle especializado por fuente

El show view varía según la fuente:

- **Auditoría de modelos**: prioriza visualización de cambios old/new en tabla comparativa con highlight de diferencias. Cada atributo modificado se muestra en una fila con columna "Anterior" y columna "Nuevo".
- **Auditoría de seguridad**: prioriza el contexto del evento y la metadata. Se muestra como lista de definición clave-valor con labels amigables para las claves de metadata conocidas.

### 4.5 Labels amigables para eventos

Para que el listado sea legible, los eventos deben mostrarse con labels humanos, no valores crudos.

#### Eventos de auditoría de modelos

| Valor crudo | Label amigable |
| --- | --- |
| `created` | Creación |
| `updated` | Actualización |
| `deleted` | Eliminación |
| `restored` | Restauración |

#### Eventos de seguridad

| Valor crudo (enum) | Label amigable |
| --- | --- |
| `login_success` | Inicio de sesión |
| `login_failed` | Intento de sesión fallido |
| `logout` | Cierre de sesión |
| `2fa_enabled` | 2FA habilitado |
| `2fa_disabled` | 2FA deshabilitado |
| `role_assigned` | Rol asignado |
| `role_revoked` | Rol revocado |
| `role_created` | Rol creado |
| `role_updated` | Rol actualizado |
| `role_deactivated` | Rol desactivado |
| `role_activated` | Rol activado |
| `role_deleted` | Rol eliminado |
| `permissions_synced` | Permisos sincronizados |
| `user_created` | Usuario creado |
| `user_updated` | Usuario actualizado |
| `user_deactivated` | Usuario desactivado |
| `user_activated` | Usuario activado |
| `user_deleted` | Usuario eliminado |
| `password_reset_sent` | Restablecimiento enviado |

**Implementación**: estos mapeos se definen como método `label()` en el enum `SecurityEventType` para el backend, y como diccionario en un helper TypeScript compartido para el frontend (e.g., extendiendo `resources/js/lib/system.ts`).

### 4.6 Filtros

#### 4.6.1 Filtros mínimos obligatorios

| Filtro | Tipo | Parámetro query | Notas |
| --- | --- | --- | --- |
| Fuente | Select | `?source=model_audit\|security_audit\|all` | Default: `all` |
| Rango de fechas | Date range picker (desde/hasta) | `?from=YYYY-MM-DD&to=YYYY-MM-DD` | Ambos opcionales. Sin rango: últimos 30 días como default razonable |
| Actor/usuario | Select o input | `?user_id=N` | Lista de usuarios que aparecen en los registros |
| Tipo de evento | Select multi o select simple | `?event=valor` | Opciones dinámicas según fuente seleccionada |
| Entidad afectada (tipo) | Select | `?auditable_type=User` | Solo aplica a auditoría de modelos; se ignora si fuente es `security_audit`. Cuando fuente es `all`, filtra solo los registros de model audit. Usa short aliases (`User`, `Role`, `Permission`), no FQCN |
| Entidad afectada (ID) | Input numérico | `?auditable_id=N` | Solo útil combinado con `auditable_type` |

#### 4.6.2 Filtros opcionales

Estos filtros pueden implementarse si aportan valor durante desarrollo, pero no son obligatorios para cerrar el PRD:

| Filtro | Tipo | Notas |
| --- | --- | --- |
| IP | Input texto | Búsqueda por IP exacta o parcial |
| Correlation ID | Input texto | Útil para soporte técnico |

#### 4.6.3 Comportamiento de filtros

- Los filtros se envían como query params via `router.get()` con `preserveState` (patrón PRD-04).
- Los filtros se aplican server-side. No hay filtrado client-side.
- Si no hay rango de fechas, el backend aplica un default de últimos 30 días para evitar consultas masivas sin restricción temporal. Este default se documenta claramente en la UI ("Mostrando últimos 30 días").
- El botón "Limpiar filtros" restablece todos los filtros al estado default.
- Los filtros activos se reflejan visualmente (badges o indicadores en toolbar).

### 4.7 Paginación

**Decisión congelada**: paginación offset, consistente con PRD-04.

| Aspecto | Decisión |
| --- | --- |
| Page size default | 50 registros por página |
| Componente | `<Pagination>` de PRD-04 |
| Mecanismo | `->paginate(50)->withQueryString()` en cada fuente |

**Justificación del page size**: 50 registros por página. El visor de auditoría es una herramienta de investigación donde el usuario escanea eventos rápidamente buscando patrones. Un page size mayor reduce la fricción de navegación entre páginas. Las filas del listado son compactas (fecha, badge, actor, evento, entidad, IP) — no formularios densos. Si un proyecto derivado necesita ajustar, el cambio es trivial.

**Nota sobre paginación unificada**: si la implementación usa unión de dos queries, la paginación se calcula sobre el resultado unificado. Si usa consulta filtrada por fuente, la paginación es estándar sobre una sola tabla.

### 4.8 Exportación

| Aspecto | Decisión |
| --- | --- |
| CSV | **Obligatorio** |
| JSON | **Opcional** (recomendado) |
| Alcance | Exporta el resultado filtrado, no toda la base de datos |
| Headers CSV | Amigables en español (`Fecha`, `Fuente`, `Actor`, `Evento`, `Entidad`, `IP`) |
| Volúmenes grandes (>1000 registros) | Job/cola siguiendo la política de PRD-03. Toast de "exportación en proceso" + descarga cuando esté lista |
| Volúmenes pequeños (≤1000 registros) | Respuesta síncrona con download directo |
| Permiso | `system.audit.export` |
| Formato JSON (si se implementa) | Mismo endpoint con `?format=json`. Estructura: array de objetos normalizados |

**No se incluye** Excel nativo ni PDF como baseline obligatorio.

### 4.9 Permisos del módulo

#### 4.9.1 Permisos nuevos

| Permiso | Descripción | Notas |
| --- | --- | --- |
| `system.audit.view` | Ver listado y detalle de auditoría | Cubre ambas fuentes (modelos + seguridad) |
| `system.audit.export` | Exportar resultados filtrados | Requiere también `system.audit.view` |

**Decisión sobre granularidad por fuente**: no se crean permisos separados `system.audit.security.view` / `system.audit.model.view` en el baseline. Si un proyecto derivado necesita esta granularidad, puede agregar los permisos como opt-in. En el baseline, un usuario con `system.audit.view` ve ambas fuentes.

**Justificación**: el visor de auditoría es una herramienta administrativa de supervisión. Separar el acceso por fuente añade complejidad de configuración sin beneficio claro en un backoffice interno donde el auditor necesita visión completa. Si solo pudiera ver una fuente, tendría una vista parcial e incompleta del sistema.

#### 4.9.2 Seeder de permisos

Se creará `AuditModulePermissionsSeeder` que:

1. Agrega los 2 permisos nuevos con `display_name`.
2. Sincroniza al rol `super-admin`.
3. Se ejecuta después de `AccessModulePermissionsSeeder`.

```php
$permissions = [
    ['name' => 'system.audit.view', 'display_name' => 'Ver auditoría'],
    ['name' => 'system.audit.export', 'display_name' => 'Exportar auditoría'],
];
```

`DatabaseSeeder` actualizado: `RolesAndPermissionsSeeder` → `AccessModulePermissionsSeeder` → `AuditModulePermissionsSeeder`.

**Total de permisos del sistema** tras este PRD: 16 (14 de PRD-05 + 2 nuevos).

### 4.10 Seguridad del módulo

#### 4.10.1 Read-only estricto

El visor **no permite**:

- editar eventos;
- borrar eventos;
- reintentar acciones;
- alterar metadata;
- crear eventos manuales.

No existen endpoints POST/PUT/PATCH/DELETE para registros de auditoría.

#### 4.10.2 Enforcement por capas

El módulo sigue el enforcement de PRD-04 (§4.6.1):

| Capa | Mecanismo | Aplicación en este módulo |
| --- | --- | --- |
| **1 — UI** | `useCan('system.audit.view')` | Sidebar condicionado, botón de exportar condicionado |
| **2 — Navegación** | `HandleInertiaRequests::share()` | Item "Auditoría" solo visible si tiene permiso |
| **3 — Ruta** | `auth`, `verified`, `ensure-two-factor` | Middleware estándar del grupo `system` |
| **4 — Controller** | `Gate::authorize('system.audit.view')` | En `index()` y `show()` |
| **5 — Export** | `Gate::authorize('system.audit.export')` | En `export()` |

#### 4.10.3 Protección de datos sensibles

El visor **respeta** las reglas de redacción de PRD-03:

- Los campos excluidos por `config/audit.php` (`password`, `two_factor_secret`, `two_factor_recovery_codes`, `remember_token`) **nunca llegan a la tabla `audits`** — la exclusión ocurre aguas arriba en `laravel-auditing`. El visor no necesita redactar porque los datos ya no existen.
- La `metadata` de `security_audit_log` no contiene contraseñas ni tokens por diseño de los listeners (PRD-03).
- El visor **no rehidrata** datos que fueron excluidos. Si un campo no está en `old_values`/`new_values`, simplemente no se muestra.
- Los valores de `metadata` se muestran tal cual fueron persistidos. No se hacen queries adicionales para "enriquecer" datos sensibles.

**Regla de seguridad**: el visor es una ventana de solo lectura sobre datos ya persistidos y ya saneados. No abre nuevas vías de acceso a datos sensibles.

### 4.11 Contrato técnico de datos

#### 4.11.1 Shape normalizado para el index

Para que el frontend renderice un listado unificado, el backend normaliza ambas fuentes al siguiente shape:

```php
[
    'id' => string,              // "{source}_{id}" para unicidad (e.g., "model_42", "security_108")
    'source' => string,          // 'model_audit' | 'security_audit'
    'timestamp' => string,       // ISO 8601
    'actor_name' => ?string,     // Nombre del actor o null
    'actor_id' => ?int,          // ID del actor o null
    'event' => string,           // Valor crudo del evento
    'event_label' => string,     // Label amigable
    'subject_type' => ?string,   // Tipo de entidad afectada (legible) o null
    'subject_id' => ?int,        // ID de la entidad o null
    'subject_label' => ?string,  // "{tipo legible} #{id}" o null
    'ip_address' => ?string,     // IP del actor
]
```

**Nota sobre `id`**: el prefijo de fuente evita colisiones de IDs entre las dos tablas.

#### 4.11.2 Shape extendido para el show

**Model audit** (desde tabla `audits`):

```php
[
    // ...shape base...
    'old_values' => ?array,      // Atributos anteriores
    'new_values' => ?array,      // Atributos nuevos
    'url' => ?string,            // URL de la request
    'user_agent' => ?string,     // User agent
    'tags' => ?string,           // Tags si existen
]
```

**Security audit** (desde tabla `security_audit_log`):

```php
[
    // ...shape base...
    'metadata' => array,         // Metadata saneada
    'correlation_id' => ?string, // UUID de correlación
]
```

### 4.12 Rutas

Las rutas se agregan al archivo `routes/system.php` existente, dentro del mismo grupo middleware.

| Método | Ruta | Controller | Ability | Permiso |
| --- | --- | --- | --- | --- |
| GET | `/system/audit` | `AuditController@index` | `viewAny` | `system.audit.view` |
| GET | `/system/audit/{source}/{id}` | `AuditController@show` | `view` | `system.audit.view` |
| GET | `/system/audit/export` | `AuditExportController` | `export` | `system.audit.export` |

**Nota sobre la ruta de show**: el parámetro `{source}` (`model` o `security`) determina en qué tabla buscar el registro. El controller valida que `{source}` sea uno de los valores permitidos.

**Nota sobre orden de rutas**: dado que este módulo no usa `Route::resource` (solo rutas explícitas), no hay riesgo de conflicto de binding como en PRD-05. Sin embargo, la ruta de export debe registrarse antes de la ruta de show para evitar que `export` se interprete como valor de `{source}`.

### 4.13 Controllers

#### 4.13.1 `AuditController`

Controller con solo `index` y `show`.

**Decisión: Gate directo sin Policy.** Este módulo no tiene un modelo Eloquent propio. Opera sobre dos tablas ajenas (`audits` de `laravel-auditing` y `security_audit_log`). Crear una Policy requiere un modelo vinculado, lo cual no aplica aquí. El patrón correcto es `Gate::authorize()` con el ability como string, consistente con PRD-04 §4.6.3 para acciones de lectura.

**Nota sobre actor morph en `audits`**: la tabla `audits` usa morph columns (`user_type` + `user_id`) para el actor, no una simple FK. El eager load debe usar la relación morph definida en el modelo `Audit` de `laravel-auditing` (`$audit->user`), no un `belongsTo` directo.

```php
class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('system.audit.view');
        // Consulta unificada con filtros
        // Normalización de shape
        // Paginación
    }

    public function show(Request $request, string $source, int $id): Response
    {
        Gate::authorize('system.audit.view');
        // Validar $source ∈ ['model', 'security']
        // Buscar registro en tabla correspondiente
        // Retornar shape extendido según fuente
    }
}
```

#### 4.13.2 `AuditExportController`

Invokable controller para exportación:

```php
class AuditExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse|RedirectResponse
    {
        Gate::authorize('system.audit.export');
        // Aplicar mismos filtros que index
        // Si >1000 registros: despachar job, retornar toast
        // Si ≤1000: StreamedResponse con CSV
    }
}
```

### 4.14 UX del módulo

El módulo sigue el estándar visual de PRD-04:

| Componente | Aplicación |
| --- | --- |
| `AppLayout` | Layout principal con sidebar |
| Breadcrumbs | `[Panel, Auditoría]` y `[Panel, Auditoría, Detalle]` |
| `Head` | Títulos `"Auditoría"` y `"Detalle de evento"` |
| `Heading` | Título + descripción de la sección |
| `Toolbar` | Filtros + botón exportar (condicionado por `useCan`) |
| `Table` | Listado unificado con columnas definidas en §4.3.1 |
| `Pagination` | Componente estándar de PRD-04 |
| `EmptyState` | Cuando no hay registros que coincidan con los filtros |
| `Badge` | Fuente del evento (modelo/seguridad), tipo de evento |
| `Card` | Secciones del detalle (datos generales, cambios, metadata) |
| Flash/toasts | Feedback de exportación |
| Light/dark mode | Tema violeta, obligatorio |

#### 4.14.1 Detalles de presentación

- **Fechas en listado**: formato amigable relativo (e.g., "hace 2 horas") con tooltip que muestra timestamp exacto.
- **Fechas en detalle**: formato completo (`dd/mm/yyyy HH:mm:ss`) + formato relativo.
- **Valores largos en tabla**: truncados con `truncate` de Tailwind.
- **Old/new en detalle**: tabla comparativa con dos columnas. Valores null mostrados como `—`. Atributos que cambiaron destacados visualmente (e.g., fondo accent suave).
- **Metadata en detalle**: lista de definición con labels amigables para claves conocidas (`email_attempted` → "Email intentado", `role` → "Rol", `assigned_by` → "Asignado por", etc.).
- **Links contextuales en detalle**: si la entidad afectada es un modelo existente (User, Role) y el usuario tiene permiso de verlo, el nombre/ID de la entidad se muestra como link navegable.

### 4.15 Componentes nuevos del módulo

Este módulo necesita un componente UI que no existe en el inventario de PRD-04:

| Componente | Descripción | Implementación |
| --- | --- | --- |
| Date input (desde/hasta) | Dos inputs `type="date"` nativos para rango de fechas | HTML nativo `<Input type="date" />` reutilizando el componente `Input` existente. No requiere dependencia nueva |

**Decisión congelada**: no se agrega un date picker complejo (calendar, popover) para la primera iteración. Los inputs nativos `type="date"` son suficientes para el caso de uso de rango de fechas y funcionan correctamente en todos los navegadores modernos, incluyendo mobile. Si un proyecto derivado necesita un picker más elaborado, puede instalar un componente de calendario como opt-in.

### 4.16 Responsive

El módulo es funcional desde mobile, aunque su uso principal sea desktop.

| Aspecto | Convención |
| --- | --- |
| Tabla | `overflow-x-auto` en contenedor |
| Toolbar | Apilable verticalmente en mobile |
| Filtros | Utilizables en mobile (selects full-width) |
| Detalle | Legible sin romper layout. Cards en stack vertical |
| Paginación | Touch-friendly. Prev/next en pantallas pequeñas |

### 4.17 Navegación

Se agrega item "Auditoría" al sidebar en `HandleInertiaRequests`:

```php
// --- Auditoría (PRD-06) ---
$request->user()?->can('system.audit.view')
    ? ['title' => 'Auditoría', 'href' => route('system.audit.index', absolute: false), 'icon' => 'scroll-text']
    : null,
```

**Icono**: `scroll-text` de Lucide (icono de documento/log). Se mapea vía `resolveIcon()` en `resources/js/lib/system.ts`.

### 4.18 Frontend — Estructura de archivos

```
resources/js/pages/system/audit/
├── index.tsx                              # Listado unificado
├── show.tsx                               # Detalle del evento
└── components/
    ├── audit-filters.tsx                  # Componente de filtros
    ├── changes-table.tsx                  # Tabla old/new para model audits
    └── metadata-display.tsx               # Display clave-valor para security audits
```

### 4.19 Validaciones lógicas

Aunque el módulo es read-only, debe respetar estas reglas:

- No exponer eventos fuera del scope autorizado por permisos.
- No mezclar ni inventar datos si una fuente no provee cierto campo — se muestra `—` o null explícito.
- Si un registro no existe o fue eliminado por retention policy, el acceso directo a detalle retorna 404 consistente.
- El módulo no depende de que ambas fuentes tengan exactamente el mismo schema — la normalización ocurre en el backend.
- Los filtros deben validarse server-side: `source` solo acepta valores válidos, `user_id` debe existir si se provee, fechas deben ser parseable.

### 4.20 Índices de base de datos

Para garantizar performance aceptable en los filtros definidos, se recomienda verificar/agregar los siguientes índices:

**Tabla `audits`** (verificar existentes):
- `(auditable_type, auditable_id)` — ya existe (morphs).
- `(user_id, user_type)` — ya existe.
- `created_at` — **agregar si no existe** para filtro por rango de fechas.
- `event` — **evaluar durante desarrollo** si el filtro por tipo es lento.

**Tabla `security_audit_log`** (verificar existentes):
- `event_type` — ya existe (índice en migración).
- `user_id` — ya existe.
- `occurred_at` — ya existe.
- `correlation_id` — ya existe.

**Migración**: si se necesitan índices nuevos, se crea una migración `add_indexes_for_audit_viewer`.

### 4.21 Testing mínimo obligatorio

Siguiendo el estándar de PRD-04 (§4.11):

#### Archivos de test

| Archivo | Cubre |
| --- | --- |
| `tests/Feature/System/AuditIndexTest.php` | Listado unificado, filtros, paginación, datos de ambas fuentes |
| `tests/Feature/System/AuditShowTest.php` | Detalle de model audit, detalle de security audit, 404 para inexistente |
| `tests/Feature/System/AuditAuthorizationTest.php` | Enforcement por capas: 403 sin permiso en index, show y export |
| `tests/Feature/System/AuditExportTest.php` | Export CSV con filtros, protección por permiso |

#### Escenarios de test críticos

| Escenario | Resultado esperado |
| --- | --- |
| Acceso a `/system/audit` sin `system.audit.view` | 403 |
| Acceso a `/system/audit/{source}/{id}` sin permiso | 403 |
| Acceso a `/system/audit/export` sin `system.audit.export` | 403 |
| Index muestra eventos de ambas fuentes | Registros de `audits` y `security_audit_log` presentes |
| Filtro por fuente `model_audit` | Solo registros de tabla `audits` |
| Filtro por fuente `security_audit` | Solo registros de `security_audit_log` |
| Filtro por rango de fechas | Solo registros dentro del rango |
| Filtro por usuario/actor | Solo registros del actor seleccionado |
| Filtro por tipo de evento | Solo registros con ese tipo |
| Show de model audit muestra old/new | Cambios visibles |
| Show de security audit muestra metadata | Metadata visible |
| Show con source inválido | 404 o 422 |
| Datos sensibles no expuestos en show | Campos excluidos por `config/audit.php` ausentes |
| Export CSV respeta filtros activos | CSV contiene solo registros filtrados |
| Export sin permiso de export pero con permiso de view | 403 en export |
| Index sin rango de fechas aplica default 30 días | Solo registros de últimos 30 días |

---

## 5. Criterios de Aceptación

### 5.1 Funcionales

- Existe listado unificado de auditoría con eventos de ambas fuentes.
- Existe detalle de evento con visualización especializada por fuente.
- Existen filtros mínimos obligatorios (fuente, fechas, actor, tipo de evento, entidad).
- El listado distingue claramente la fuente del evento con badge visual.
- El detail view muestra old/new cuando aplica (auditoría de modelos).
- El detail view muestra metadata saneada cuando aplica (auditoría de seguridad).
- El módulo exporta CSV con headers amigables.
- JSON opcional disponible si se implementa.
- Labels amigables para todos los tipos de evento.
- Default de 30 días cuando no hay rango de fechas especificado.

### 5.2 Seguridad

- El módulo es estrictamente read-only.
- Un usuario sin `system.audit.view` no puede acceder por URL directa.
- La UI no es la única barrera — el backend verifica en cada endpoint.
- El visor no revela secretos ni datos sensibles redaccionados por PRD-03.
- Exportación requiere permiso específico `system.audit.export`.
- No existen endpoints de escritura sobre registros de auditoría.

### 5.3 UX y consistencia

- Fechas amigables en listado con tooltip exacto.
- Timestamps exactos disponibles en detalle.
- Light/dark y tema violeta respetados.
- Mobile funcional.
- El módulo reutiliza completamente el estándar CRUD de PRD-04 (Table, Pagination, Toolbar, EmptyState, Badge, Card, AppLayout, Heading, Breadcrumbs, FlashToaster, useCan).
- Links contextuales a entidades relacionadas cuando el usuario tiene permiso.

### 5.4 Calidad

- El módulo es reutilizable en futuros sistemas sin modificación.
- Está alineado con PRD-03 (fuentes de datos) y PRD-04 (estándar visual/CRUD).
- Tiene tests de feature suficientes (4 archivos mínimos).
- No degrada el boilerplate hacia un SIEM sobrediseñado.
- `npm run build` completa sin errores.
- `npm run types:check` completa sin errores.
- `php artisan test --compact` pasa.
- `vendor/bin/pint --dirty --format agent` pasa.
- Wayfinder regenerado y funcional.

---

## 6. Dependencias y Riesgos

### 6.1 Dependencias

| Dependencia | Fuente |
| --- | --- |
| PRD-03 | Operabilidad transversal, tablas `audits` y `security_audit_log`, `SecurityAuditService`, `SecurityEventType` enum, política de exclusión |
| PRD-04 | Estándar CRUD, componentes UI, enforcement por capas, `useCan`, Toolbar, Table, Pagination |
| PRD-05 | Administración de acceso (productor de audit trail), permisos, navegación filtrada |
| `owen-it/laravel-auditing` | Modelo `Audit`, tabla `audits`, trait `Auditable` |
| `spatie/laravel-permission` | Modelo `Role`, `Permission` (entidades auditables) |

### 6.2 Riesgos principales

| Riesgo | Severidad | Mitigación |
| --- | --- | --- |
| Unificar dos fuentes con shapes distintos resulta complejo o lento | Alta | Aceptar filtro de fuente como primer filtro; normalizar solo los campos mínimos del listado; no intentar un schema universal perfecto |
| Problemas de performance con datasets grandes sin indexar | Alta | Verificar índices existentes antes de implementar; agregar los faltantes; aplicar default de 30 días sin rango de fechas |
| Exponer demasiado detalle sensible en UI | Media | El saneamiento ya ocurre aguas arriba (PRD-03). El visor no rehidrata. Verificar con test de no-exposición |
| Convertir el módulo en consola forense o SIEM | Media | Ceñirse al scope congelado. No agregar alerting, correlación, ni dashboards |
| Mezclar auditoría con logging operativo | Media | El visor consulta solo `audits` y `security_audit_log`. No lee archivos de log |
| Diseñar filtros demasiado ambiciosos para la primera iteración | Media | Los filtros opcionales (IP, correlation_id) son opt-in. Solo los obligatorios bloquean el cierre |
| Paginación unificada sobre dos tablas es costosa | Media | Si union SQL es lenta, degradar a filtro de fuente obligatorio. Esto es aceptable para v1 |

---

## 7. Entregables esperados

Al cerrar este PRD, el boilerplate debe tener:

### Backend

- `AuditModulePermissionsSeeder` con 2 permisos nuevos y `display_name`.
- `AuditController` (index + show).
- `AuditExportController` (invokable).
- Rutas en `routes/system.php`: index, show, export.
- `SecurityEventType::label()` — método para labels amigables del enum.
- Migración de índices si se necesitan (verificar durante desarrollo).
- `HandleInertiaRequests` actualizado con item de navegación "Auditoría".
- `DatabaseSeeder` actualizado con `AuditModulePermissionsSeeder`.

### Frontend

- `resources/js/pages/system/audit/index.tsx` — listado unificado.
- `resources/js/pages/system/audit/show.tsx` — detalle del evento.
- `resources/js/pages/system/audit/components/audit-filters.tsx` — filtros.
- `resources/js/pages/system/audit/components/changes-table.tsx` — tabla old/new.
- `resources/js/pages/system/audit/components/metadata-display.tsx` — display clave-valor.
- Helper de labels amigables en `resources/js/lib/system.ts` (extendido: icono `scroll-text` en `iconMap`, grupo `system.audit` en `groupLabels`, diccionarios de event labels y auditable type labels).
- Tipos TypeScript para shapes normalizados de auditoría.

### Tests

- 4 archivos de test mínimos (`AuditIndexTest`, `AuditShowTest`, `AuditAuthorizationTest`, `AuditExportTest`).
- Escenarios críticos de autorización, filtrado y no-exposición de datos sensibles.

### Documentación

- ADR-011: Visor de auditoría — decisiones sobre unificación, normalización, filtros y límites del módulo.

---

## 8. Qué cambia en PRDs anteriores

### 8.1 Secuencia actualizada

- **PRD-00**: Boilerplate como producto interno _(completado)_
- **PRD-01**: Personalización base corporativa _(completado)_
- **PRD-02**: Núcleo de identidad y autorización _(completado)_
- **PRD-03**: Operabilidad transversal _(completado)_
- **PRD-04**: Estándar de módulos CRUD administrativos _(completado)_
- **PRD-05**: Administración de acceso — roles y usuarios _(completado)_
- **PRD-06**: Visor administrativo de auditoría _(este documento)_

Con la finalización de PRD-06, **la primera ola del boilerplate queda esencialmente completa**.

### 8.2 Cambios en código existente de PRDs anteriores

| Archivo | PRD origen | Cambio |
| --- | --- | --- |
| `routes/system.php` | PRD-05 | Se agregan rutas de auditoría al grupo `system` |
| `app/Enums/SecurityEventType.php` | PRD-03 | Se agrega método `label(): string` para labels amigables |
| `app/Http/Middleware/HandleInertiaRequests.php` | PRD-04/05 | Se agrega item de navegación "Auditoría" condicionado por permiso |
| `database/seeders/DatabaseSeeder.php` | PRD-05 | Se agrega llamada a `AuditModulePermissionsSeeder` |
| `resources/js/lib/system.ts` | PRD-05 | Se extiende con: icono `scroll-text` en `iconMap`, grupo `'system.audit': 'Auditoría'` en `groupLabels`, diccionarios de event labels y auditable type labels |

### 8.3 Qué no cambia

- Las tablas `audits` y `security_audit_log` no se modifican (solo índices si faltan).
- `SecurityAuditService` no cambia — el visor solo lee.
- La política de exclusión de `config/audit.php` no cambia.
- Los componentes UI de PRD-04 no cambian — el módulo los consume.
- Los modelos `User`, `Role`, `Permission` no cambian.
- El estándar CRUD de PRD-04 no cambia.
