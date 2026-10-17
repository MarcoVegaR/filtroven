# PRD-04 — Estándar de Módulos CRUD Administrativos

## 1. Problema y Objetivos

### 1.1 Problema

El boilerplate ya define producto (PRD-00), identidad/autorización (PRD-02) y operabilidad transversal (PRD-03), pero todavía no ha congelado el estándar reusable de módulos CRUD administrativos. Sin ese estándar, el primer módulo específico — por ejemplo el de administración de acceso — terminaría imponiendo de facto decisiones sobre:

- layout y navegación;
- patrón de páginas (listado, formulario, detalle);
- tablas, filtros y paginación;
- formularios y validación;
- acciones y confirmaciones;
- reflejo de permisos en UI;
- componentes transversales;
- manejo de errores UX;
- convenciones de controladores, requests y policies;
- estructura de tests por módulo.

Eso generaría un anti-patrón: el módulo de acceso no solo resolvería su problema, sino que se convertiría accidentalmente en la "plantilla implícita" de todos los módulos futuros.

### 1.2 Objetivo principal

Definir e implementar el estándar del boilerplate para módulos CRUD administrativos sobre Laravel 13 + starter kit React (ya customizado según PRD-01: branding corporativo, idioma español, convenciones de routing via Wayfinder) + Inertia v2, de modo que los módulos posteriores reutilicen una convención consistente de páginas, tablas, formularios, permisos y experiencia administrativa.

### 1.3 Objetivos específicos

- Definir el contrato reusable de un módulo CRUD administrativo.
- Congelar el patrón base de páginas: listado, formulario, detalle y acciones.
- Estandarizar tablas, filtros, búsquedas, paginación y estados vacíos.
- Estandarizar formularios, validación y errores sobre Inertia v2 `<Form>` + Wayfinder actions.
- Congelar cómo se reflejan permisos en backend y UI, construyendo sobre el payload `auth.permissions` ya existente.
- Identificar qué componentes transversales deben existir antes de módulos específicos, distinguiendo los que ya existen de los que deben crearse.
- Preparar el terreno para que PRD-05 (Administración de acceso) se enfoque solo en su lógica de negocio, no en inventar el patrón del sistema.

## 2. Alcance (Scope)

### 2.1 Entra en esta iteración

Este PRD cubre el estándar reusable para módulos CRUD administrativos, incluyendo:

- contrato estructural de un módulo CRUD (estructura de directorios, archivos de rutas, capas de lógica);
- convención de archivos de rutas por módulo;
- decisión congelada sobre capas de lógica (controller → FormRequest → Eloquent, sin repositories por defecto);
- convención de factories y seeders por módulo;
- operaciones de lifecycle del recurso (delete, deactivate, soft delete, restore);
- estrategia de eliminación lógica (soft delete);
- convención de navegación administrativa dentro del sidebar server-driven;
- patrón estándar de páginas (Index, Create/Edit con form compartido, Show);
- patrón estándar de tablas con paginación offset;
- estrategia de búsqueda, paginación y datasets grandes;
- patrón estándar de formularios sobre `<Form>` + Wayfinder;
- patrón de selección relacional con creación contextual;
- patrón estándar de detalle/show;
- acciones primarias, secundarias y destructivas;
- validación de operaciones destructivas y de estado;
- exportación como capacidad opcional prevista;
- empty states, loading states y error states;
- hook `useCan` para reflejo de permisos en UI;
- componentes transversales mínimos (incluyendo los que deben crearse);
- estrategia de validación (server-side default, client hints, real-time opt-in);
- mecanismo de flash/toast para feedback post-redirect;
- estrategia visual explícita (shadcn/ui, Tailwind v4, Lucide, tokens de tema violet, dark mode);
- responsive y soporte mobile;
- convenciones de manejo de errores UX;
- modelo de enforcement de seguridad por capas (UI → navegación → ruta → controller → FormRequest);
- regla de permisos como unidad primaria de autorización (no roles);
- contratos de rutas/controladores/requests/policies para módulos CRUD (incluyendo lifecycle routes);
- convención de Eloquent models vs API Resources para props Inertia;
- estructura de tests por módulo;
- nuevas dependencias npm requeridas;
- documentación: ADR-009 + guía de construcción de módulos CRUD.

### 2.2 Fuera de alcance en esta iteración

Queda fuera:

- la lógica de negocio de roles/permisos/usuarios (PRD-05);
- el visor administrativo de auditoría (PRD-06);
- dashboards analíticos;
- workflows complejos no CRUD;
- importaciones/exportaciones masivas;
- realtime;
- motores avanzados de búsqueda;
- constructores visuales;
- generadores automáticos de código a gran escala;
- módulos de dominio específicos.

### 2.3 Decisión de alcance congelada

Este PRD no construye un módulo de negocio.
Este PRD construye la convención reusable que luego usarán los módulos concretos.

## 3. User Stories

### 3.1 Como dueño del boilerplate

Quiero que todos los módulos CRUD administrativos sigan el mismo patrón para no rediseñar la UX interna del sistema en cada capacidad nueva.

### 3.2 Como desarrollador

Quiero saber exactamente cómo debe verse y estructurarse un módulo CRUD nuevo para moverme más rápido y con menos ambigüedad.

### 3.3 Como responsable de producto interno

Quiero que el primer módulo específico no defina por accidente el estándar de todos los demás.

### 3.4 Como operador del backoffice

Quiero que listados, formularios, detalles y acciones se comporten de forma consistente entre módulos.

### 3.5 Como responsable de seguridad

Quiero que el patrón CRUD incorpore autorización backend real y reflejo UI consistente, sin duplicar decisiones por módulo. Laravel sigue recomendando gates y policies como mecanismos primarios de autorización.

### 3.6 Como agente SDD

Quiero una especificación cerrada del estándar CRUD para que los módulos específicos implementen sobre un contrato ya congelado.

## 4. Requerimientos Técnicos

### 4.1 Estado actual del baseline

#### 4.1.1 Primitivas UI existentes

Los siguientes componentes ya existen en `resources/js/components/ui/` y deben reutilizarse:

| Componente | Archivo | Base |
| --- | --- | --- |
| Button | `button.tsx` | Radix Slot + CVA |
| Input | `input.tsx` | HTML nativo |
| Label | `label.tsx` | Radix Label |
| Select | `select.tsx` | Radix Select |
| Checkbox | `checkbox.tsx` | Radix Checkbox |
| Dialog | `dialog.tsx` | Radix Dialog |
| Badge | `badge.tsx` | CVA |
| Card | `card.tsx` | Div semántico |
| Skeleton | `skeleton.tsx` | Div animado |
| Spinner | `spinner.tsx` | SVG animado |
| Separator | `separator.tsx` | Radix Separator |
| DropdownMenu | `dropdown-menu.tsx` | Radix DropdownMenu |
| Alert | `alert.tsx` | CVA |
| Tooltip | `tooltip.tsx` | Radix Tooltip |
| Sheet | `sheet.tsx` | Radix Dialog (slide) |

Se listan solo las primitivas relevantes para módulos CRUD. Existen componentes adicionales de propósito específico (`avatar`, `input-otp`, `toggle-group`, `collapsible`, `navigation-menu`, `icon`, `placeholder-pattern`) documentados en `resources/js/components/ui/`.

Componentes de aplicación existentes:

| Componente | Archivo | Función |
| --- | --- | --- |
| AppLayout | `layouts/app-layout.tsx` | Layout principal con sidebar |
| Breadcrumbs | `components/breadcrumbs.tsx` | Navegación contextual |
| Heading | `components/heading.tsx` | Título + descripción de sección |
| InputError | `components/input-error.tsx` | Mensaje de error por campo |
| AlertError | `components/alert-error.tsx` | Bloque de errores múltiples |
| NavMain | `components/nav-main.tsx` | Items del sidebar |
| AppSidebar | `components/app-sidebar.tsx` | Sidebar completo |

#### 4.1.2 Primitivas a crear en este PRD

Los siguientes componentes **no existen** y deben crearse como parte de este estándar:

| Componente | Descripción | Dependencia nueva |
| --- | --- | --- |
| Table | Tabla base con Header/Body/Row/Cell | No (HTML + Tailwind) |
| Pagination | Controles de paginación offset | No (consume paginator de Laravel) |
| EmptyState | Estado vacío con icono y CTA | No |
| ConfirmationDialog | Modal de confirmación para acciones destructivas | No (compuesto sobre Dialog) |
| Toast / Sonner | Notificaciones post-acción | **Sí: `sonner`** |
| Toolbar | Barra de filtros + búsqueda + acciones | No (composición de primitivas) |
| Textarea | Campo de texto multilínea | No (HTML nativo + Tailwind) |

#### 4.1.3 Nuevas dependencias npm aprobadas

| Paquete | Versión | Justificación |
| --- | --- | --- |
| `sonner` | `^2.x` | Toasts accesibles y compatibles con shadcn/ui. Reemplaza el patrón inline `Transition` para feedback post-acción en contextos CRUD. El patrón `Transition` + `recentlySuccessful` se mantiene disponible para formularios de settings donde ya está en uso. |

**Decisión diferida**: `cmdk` para Combobox/searchable select. El `Select` de Radix ya instalado es suficiente para la primera iteración. Si PRD-05 o un módulo posterior necesita un select con búsqueda de muchos items, se evaluará agregar `cmdk` en ese momento.

#### 4.1.4 Otras precondiciones

Antes de este PRD ya debe existir:

- autenticación y autorización base del boilerplate (PRD-02);
- política transversal de errores, logging y auditoría (PRD-03);
- starter kit React customizado con Inertia v2 y Wayfinder (PRD-01);
- layout base del shell administrativo con sidebar y breadcrumbs;
- payload `auth.permissions` compartido al frontend;
- política de permisos backend/UI definida en PRD-02.

### 4.2 Contrato estructural de un módulo CRUD

#### 4.2.1 Estructura de directorios por módulo

Todo módulo CRUD sigue esta estructura de carpetas. Los artefactos marcados como **obligatorios** deben existir; los marcados como **condicional** se crean cuando la complejidad lo justifica.

```
app/
├── Http/
│   ├── Controllers/{Module}/
│   │   ├── {Model}Controller.php          # obligatorio
│   │   └── {Action}Controller.php         # condicional (acciones singulares)
│   └── Requests/{Module}/
│       ├── Store{Model}Request.php        # obligatorio
│       ├── Update{Model}Request.php       # obligatorio
│       ├── Delete{Model}Request.php       # condicional (§4.2.6)
│       ├── Deactivate{Model}Request.php   # condicional (§4.2.6)
│       └── Restore{Model}Request.php      # condicional (§4.2.6)
├── Models/
│   └── {Model}.php                        # obligatorio
├── Policies/
│   └── {Model}Policy.php                  # obligatorio
├── Services/
│   └── {Module}/                          # condicional (lógica compleja)
│       └── {Model}Service.php
database/
├── factories/
│   └── {Model}Factory.php                 # obligatorio
├── migrations/
│   └── xxxx_create_{table}_table.php      # obligatorio
└── seeders/
    └── {Module}Seeder.php                 # condicional (datos demo/dev)
resources/js/pages/{module}/
├── index.tsx                              # obligatorio
├── create.tsx                             # obligatorio (si aplica create)
├── edit.tsx                               # obligatorio (si aplica edit)
├── show.tsx                               # condicional
└── components/
    └── {model}-form.tsx                   # obligatorio (form compartido)
routes/
└── {module}.php                           # obligatorio
tests/Feature/{Module}/
├── IndexTest.php                          # obligatorio
├── CreateTest.php                         # obligatorio (si aplica)
├── UpdateTest.php                         # obligatorio (si aplica)
├── DeleteTest.php                         # condicional
└── AuthorizationTest.php                  # obligatorio
```

#### 4.2.2 Archivo de rutas por módulo

Cada módulo CRUD tiene su archivo de rutas propio. `web.php` solo compone e importa.

**Patrón base (CRUD estándar)**:

```php
// routes/{module}.php
Route::middleware(['auth', 'verified', 'ensure-two-factor'])
    ->prefix('{module}')
    ->name('{module}.')
    ->group(function () {
        Route::resource('{resource}', {Model}Controller::class)
            ->only(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']);
    });
```

**Patrón extendido (lifecycle operations — §4.2.6)**:

Cuando el módulo soporta operaciones de lifecycle más allá del CRUD estándar, las rutas se agregan explícitamente dentro del mismo grupo:

```php
// routes/{module}.php — con lifecycle
Route::middleware(['auth', 'verified', 'ensure-two-factor'])
    ->prefix('{module}')
    ->name('{module}.')
    ->group(function () {
        Route::resource('{resource}', {Model}Controller::class)
            ->only(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']);

        // Lifecycle operations — solo si el módulo las soporta
        Route::patch('{resource}/{model}/deactivate', [DeactivateController::class, '__invoke'])
            ->name('{resource}.deactivate');
        Route::patch('{resource}/{model}/restore', [RestoreController::class, '__invoke'])
            ->name('{resource}.restore');
        // force-delete solo con aprobación explícita en el PR del módulo
        Route::delete('{resource}/{model}/force', [ForceDeleteController::class, '__invoke'])
            ->name('{resource}.force-delete');
    });
```

| Ruta | Ability requerida | Policy method | Request dedicado |
| --- | --- | --- | --- |
| `GET index` | `module.resource.view` | `viewAny` | — |
| `GET create` | `module.resource.create` | `create` | — |
| `POST store` | `module.resource.create` | via FormRequest | `Store{Model}Request` |
| `GET show` | `module.resource.view` | `view` | — |
| `GET edit` | `module.resource.edit` | `update` | — |
| `PUT/PATCH update` | `module.resource.edit` | via FormRequest | `Update{Model}Request` |
| `DELETE destroy` | `module.resource.delete` | `delete` | Condicional (§4.2.6) |
| `PATCH deactivate` | `module.resource.deactivate` | `deactivate` | Condicional (§4.2.6) |
| `PATCH restore` | `module.resource.restore` | `restore` | Condicional (§4.2.6) |
| `DELETE force` | `module.resource.force-delete` | `forceDelete` | Condicional |

**Nota sobre `view` en index vs show**: por defecto, una sola ability `module.resource.view` cubre tanto index (`viewAny` en la Policy) como show (`view` en la Policy). Si un módulo necesita granularidad distinta (e.g., un usuario puede ver el listado pero no el detalle individual), puede definir abilities separadas (`module.resource.view-any` / `module.resource.view`). Esto es opt-in — la mayoría de módulos CRUD no lo necesitan.

```php
// routes/web.php — solo importa
require __DIR__.'/settings.php';
require __DIR__.'/{module}.php';
```

**Precedente existente**: `routes/settings.php` ya sigue esta convención.

**Convenciones de naming y prefix**:

- Prefix por módulo obligatorio (e.g., `system`, `admin`).
- Nombres de ruta: `{module}.{resource}.{action}` (e.g., `system.users.index`).
- **Prohibido**: closures con lógica de negocio en archivos de rutas. Los stubs existentes (e.g., `system.users.index` que retorna JSON) deben migrarse a controllers en su PRD correspondiente.

#### 4.2.3 Convenciones de controller

- **CRUD estándar**: resource controller con métodos explícitos + FormRequests.
- **Acciones singulares**: invokable controllers (`__invoke`) para operaciones fuera de CRUD (e.g., `AssignRole`, `ToggleStatus`, `Export`).
- **Prohibido**: closures con lógica de negocio en archivos de rutas.
- **Autorización en controller**: usar `Gate::authorize()` directamente. El base Controller del boilerplate no incluye el trait `AuthorizesRequests` (Laravel 13 default), por lo que `$this->authorize()` no está disponible.

```php
use Illuminate\Support\Facades\Gate;

public function index(Request $request): Response
{
    Gate::authorize('viewAny', Model::class);
    // ...
}
```

#### 4.2.4 Capas de lógica de negocio

El boilerplate adopta una postura pragmática basada en la evidencia del codebase existente:

| Capa | Cuándo es obligatoria | Cuándo es condicional |
| --- | --- | --- |
| **Controller** | Siempre | — |
| **FormRequest** | Siempre (store/update) | — |
| **Policy** | Siempre | — |
| **Service** | — | Cuando la lógica involucra >1 modelo, side-effects complejos, o necesita ser reutilizada fuera del controller |
| **Concerns (traits)** | — | Para reglas de validación compartidas entre requests |

**Decisión congelada**: No se adoptan Repository ni RepositoryInterface como patrón base.

**Justificación**: El codebase existente no tiene repositorios ni interfaces de negocio. El patrón actual (Controller → FormRequest → Eloquent directo) es el establecido por el starter kit y los PRDs anteriores. `SecurityAuditService` es el único service y existe por ser una capacidad transversal con side-effects (log + DB), no un wrapper de Eloquent.

**Criterio de escalada**: Si un módulo necesita desacoplar la persistencia de la lógica de negocio — por ejemplo, para testing con fuentes de datos alternativas o para lógica que cruza múltiples modelos — se crea un Service en `App\Services\{Module}\`. Ese Service no necesita interface salvo que haya múltiples implementaciones reales (no especulativas). Esta decisión se documenta en el PR del módulo.

**Anti-patrón prohibido**: crear un Service o Repository "ceremonial" que solo proxea a Eloquent sin agregar valor. Un `UserRepository::findById($id)` que solo llama a `User::find($id)` no aporta nada.

#### 4.2.5 Factories, seeders y datos de prueba

| Artefacto | Obligatorio | Notas |
| --- | --- | --- |
| **Factory** por modelo CRUD | ✅ Sí | Con `definition()` mínimo y states relevantes al dominio |
| **Seeder** por módulo | Recomendado | Para datos demo/dev local. No es obligatorio. |
| **Tests usan factories** | ✅ Sí | Los tests nunca dependen de seeders. Cada test configura su propio estado via factories. |

**Precedente existente**: `UserFactory` ya sigue esta convención con states (`unverified()`, `withTwoFactor()`, `withSuperAdmin()`).

**Convención de factory**:

```php
// database/factories/{Model}Factory.php
class {Model}Factory extends Factory
{
    public function definition(): array
    {
        return [
            // campos mínimos con fake data
        ];
    }

    // States para variantes relevantes al dominio
    public function withSpecificState(): static
    {
        return $this->state(fn (array $attributes) => [...]);
    }
}
```

#### 4.2.6 Operaciones de lifecycle del recurso

Todo módulo CRUD define qué operaciones de lifecycle soporta. El estándar reconoce las siguientes:

| Operación | Cuándo aplica | Notas |
| --- | --- | --- |
| **Destroy (delete físico)** | Solo cuando el modelo no requiere conservación histórica ni tiene relaciones operativas activas | Catálogos efímeros, borradores, datos sin valor de auditoría. |
| **Deactivate / Archive** | Cuando el recurso sigue existiendo pero deja de estar operativo | Usuarios desactivados, roles archivados. El registro se mantiene íntegro. |
| **Soft delete** | Cuando el recurso debe desaparecer del flujo normal pero mantenerse recuperable o auditable | Preferible cuando el dato tiene valor histórico, trazabilidad o relaciones. |
| **Restore** | Opcional, solo cuando el modelo usa soft deletes y el negocio justifica la recuperación | Requiere permiso explícito (e.g., `module.resource.restore`). |
| **Force delete** | Fuera del estándar base | Solo en módulos que lo justifiquen explícitamente y con aprobación en el PR. |

**Decisión por módulo**: El PRD del módulo específico decide cuál(es) operación(es) aplican. PRD-04 define el patrón para cada una.

**FormRequests para operaciones destructivas y de estado**:

| Request | Obligatorio | Cuándo |
| --- | --- | --- |
| `Store{Model}Request` | ✅ Siempre | — |
| `Update{Model}Request` | ✅ Siempre | — |
| `Delete{Model}Request` | Condicional | Cuando el delete tiene reglas de negocio (relaciones activas, último admin, motivo requerido, flag de confirmación). |
| `Deactivate{Model}Request` | Condicional | Cuando la desactivación tiene restricciones o side-effects. |
| `Restore{Model}Request` | Condicional | Cuando la restauración tiene reglas de elegibilidad. |

**Regla**: si la operación destructiva solo necesita autorización (sin reglas adicionales), el `Gate::authorize()` en el controller es suficiente — no se crea un FormRequest ceremonial.

#### 4.2.7 Estrategia de eliminación lógica (soft delete)

Cuando un módulo usa soft deletes, el estándar define:

| Aspecto | Convención |
| --- | --- |
| **Trait** | El modelo usa `SoftDeletes` de Eloquent. |
| **Index por defecto** | Muestra solo registros activos (`withoutTrashed`, que es el default de Eloquent). |
| **Filtro de estado** | Opcional. Si se implementa, el Index soporta filtros: "Activos" (default), "Eliminados", "Todos". Se envía como query param `?trashed=only` o `?trashed=with`. |
| **Indicador visual** | Los registros soft-deleted, cuando se muestran, llevan un `<Badge>` de estado (e.g., "Eliminado") y acciones restringidas. |
| **Restore** | Acción opcional en la fila del registro eliminado, condicionada por permiso. |
| **Auditoría** | La eliminación lógica se audita igual que cualquier operación destructiva (consumiendo la plataforma de PRD-03). |
| **Cascada** | El módulo debe documentar qué pasa con las relaciones cuando un registro se soft-deleta (e.g., ¿se desactivan las relaciones? ¿se mantienen?). |

**Control de acceso sobre registros soft-deleted**:

| Ability | Acción que habilita | Notas |
| --- | --- | --- |
| `module.resource.view` | Ver registros activos (Index default, Show activo) | Ability base de lectura. |
| `module.resource.view-trashed` | Ver registros eliminados (filtro "Eliminados"/"Todos" en Index, Show de eliminado) | Ability separada. Sin ella, el usuario no ve registros eliminados aunque existan. |
| `module.resource.restore` | Restaurar un registro eliminado | Requiere también `view-trashed` para poder ver el registro. |
| `module.resource.force-delete` | Eliminación permanente | Solo módulos aprobados (§4.2.6). |

**Route model binding con `withTrashed()`**: las rutas que operan sobre registros soft-deleted (`restore`, `force-delete`, `show` administrativo de eliminados) deben usar `withTrashed()` en el binding. El controller verifica que el usuario tenga la ability correspondiente antes de operar:

```php
// En la ruta
Route::patch('{resource}/{model}/restore', [RestoreController::class, '__invoke'])
    ->withTrashed()
    ->name('{resource}.restore');

// En el controller
public function __invoke(Restore{Model}Request $request, {Model} $model): RedirectResponse
{
    // FormRequest::authorize() ya verificó module.resource.restore
    $model->restore();
    return to_route('module.resource.index')->with('success', 'Recurso restaurado.');
}
```

**Acceso por URL a registro eliminado**: si un usuario sin `view-trashed` intenta acceder por URL directa a un registro soft-deleted, el comportamiento es:
- Sin `withTrashed()` en la ruta → Laravel retorna 404 (el modelo no se resuelve).
- Con `withTrashed()` en la ruta → la Policy/Gate retorna 403 por falta de ability.

En ningún caso un usuario sin el permiso adecuado ve ni opera sobre un registro eliminado.

**Cuándo NO usar soft delete**: catálogos efímeros, borradores, datos temporales, o cuando el dominio permita eliminación física sin riesgo. En esos casos, `destroy` directo.

#### 4.2.8 Convención de routing con Wayfinder

Toda referencia a rutas desde componentes React **debe usar Wayfinder**:

- **Formularios**: `<Form {...Controller.action.form()}>` importando desde `@/actions/App/Http/Controllers/{Module}/{Controller}`
- **Links y navegación**: importar desde `@/routes/{resource}` (e.g., `import { index } from '@/routes/system/users'`)
- **Prohibido**: `route('name')` con strings hardcoded, `href="/path"` literal, o cualquier referencia a rutas que no pase por Wayfinder.

#### 4.2.9 Convención de Eloquent models vs API Resources para props Inertia

- **Páginas Inertia (no-API)**: pasar modelos y colecciones directamente como props. Los atributos `$hidden` del modelo ya protegen campos sensibles. Esto es más simple y alineado con el starter kit.
- **Endpoints JSON puros (API)**: usar Eloquent API Resources.
- **Excepción**: si un modelo necesita transformación compleja para la UI (e.g., campos computados, anidamiento selectivo), crear un Resource incluso para páginas Inertia.

### 4.3 Patrón de páginas

Toda página CRUD del boilerplate sigue este contrato:

```tsx
// Patrón obligatorio de toda página CRUD
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Módulo', href: moduleIndex() },
    { title: 'Acción actual', href: currentRoute() },
];

export default function PageName(/* props tipadas */) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Título de la página" />
            {/* contenido */}
        </AppLayout>
    );
}
```

#### Index (listado)

Estructura mínima:

- `<AppLayout>` con breadcrumbs
- `<Head>` con título
- `<Heading>` con título y descripción de la sección
- `<Toolbar>`: búsqueda + filtros + acción primaria ("Crear nuevo", condicionada por `useCan`)
- `<Table>` con columnas configuradas
- `<Pagination>` conectada al paginator de Laravel
- `<EmptyState>` cuando no hay registros (incluyendo cuando hay filtros activos sin resultados)
- Acciones por fila: ver, editar, eliminar (condicionadas por permisos via `useCan`)

#### Create y Edit (formulario)

**Convención congelada**: páginas separadas + form compartido.

- `create.tsx` y `edit.tsx` son páginas independientes, cada una con su propio breadcrumb, título y `<Head>`.
- Ambas importan un componente de formulario compartido `components/{model}-form.tsx` que recibe `defaultValues` opcionales y el action de Wayfinder.
- Esto evita duplicación del formulario y mantiene páginas con responsabilidad clara.

**Estructura de `create.tsx`**:

- `<AppLayout>` con breadcrumbs → `[Módulo, Crear]`
- `<Head title="Crear {recurso}">` 
- `<Heading>` con título y descripción
- `<{Model}Form action={Controller.store} />`

**Estructura de `edit.tsx`**:

- `<AppLayout>` con breadcrumbs → `[Módulo, Editar {nombre}]`
- `<Head title="Editar {recurso}">`
- `<Heading>` con título y descripción
- `<{Model}Form action={Controller.update} defaultValues={model} />`

**Estructura del form compartido `{model}-form.tsx`**:

```tsx
// pages/{module}/components/{model}-form.tsx
type Props = {
    action: { form: () => FormProps };
    defaultValues?: Partial<ModelData>;
};

export function {Model}Form({ action, defaultValues }: Props) {
    return (
        <Form {...action.form()} options={{ preserveScroll: true }} className="space-y-6">
            {({ processing, errors }) => (
                <>
                    {/* campos con Label + Input + InputError */}
                    <Button disabled={processing}>Guardar</Button>
                </>
            )}
        </Form>
    );
}
```

#### Show (detalle)

Estructura mínima:

- `<AppLayout>` con breadcrumbs → `[Módulo, {nombre del recurso}]`
- `<Head>` con título
- `<Heading>` con título
- Datos en layout de definición (label + valor) usando `<Card>` sections
- Acciones contextuales condicionadas por permisos (editar, eliminar)

### 4.4 Patrón de tablas y listados

Las tablas CRUD del sistema siguen un contrato común:

| Aspecto | Decisión |
| --- | --- |
| Componente base | `<Table>` con `<TableHeader>`, `<TableBody>`, `<TableRow>`, `<TableCell>` |
| Columnas | Configurables por módulo. No hay config object genérico — cada tabla define su JSX. |
| Búsqueda | Input de texto en Toolbar. Se envía como query param `?search=` via Inertia `router.get()` con `preserveState`. |
| Filtros | Selectores en Toolbar. Query params (e.g., `?status=active`). |
| Paginación | **Offset** (`Model::query()->paginate(15)`). Componente `<Pagination>` consume `links` del paginator de Laravel serializado por Inertia. |
| Page size | 15 por defecto. Configurable por módulo si se justifica. |
| Acciones por fila | `<DropdownMenu>` con items condicionados por `useCan()`. |
| Estado vacío | `<EmptyState>` con icono, mensaje y CTA opcional. |
| Estado loading | `<Skeleton>` rows durante navegación (Inertia transitions). |
| Acciones masivas | Solo si el módulo las necesita. No es parte del estándar base. |
| Sorting | Diferido. No es parte de la primera iteración. Se agrega por módulo si se justifica. |
| Exportación | Capacidad opcional prevista. Ver §4.4.3. |

#### 4.4.1 Contrato del controller para listados

```php
use Illuminate\Support\Facades\Gate;

public function index(Request $request): Response
{
    Gate::authorize('viewAny', Model::class);

    $models = Model::query()
        ->when($request->search, fn ($q, $search) => $q->where('name', 'ilike', "%{$search}%"))
        ->latest()
        ->paginate(15)
        ->withQueryString();

    return Inertia::render('{module}/index', [
        'models' => $models,
        'filters' => $request->only(['search']),
    ]);
}
```

> **Nota PostgreSQL**: `ilike` es el operador case-insensitive de PostgreSQL, que es la base de datos del boilerplate. Para MySQL, `like` ya es case-insensitive por defecto con collation `utf8mb4_unicode_ci`.

#### 4.4.2 Estrategia de búsqueda, paginación y datasets grandes

El estándar define un árbol de decisión según el tamaño del dataset:

| Escala del dataset | Estrategia |
| --- | --- |
| **Pequeño** (<500 registros) | Offset pagination + búsqueda server-side. Comportamiento default sin optimización adicional. |
| **Mediano** (500–10K registros) | Offset pagination + búsqueda server-side + índices de base de datos en columnas buscadas/filtradas. Filtros que reduzcan el dataset antes de paginar. |
| **Grande** (>10K registros) | No se promete búsqueda "real-time" agresiva por defecto. Se evalúa por módulo: índices parciales, búsqueda dedicada (e.g., full-text search de PostgreSQL), o experiencia optimizada específica. Se documenta la decisión en el PR. |

**Comportamiento de búsqueda**:

- **Default**: submit explícito (el usuario presiona Enter o un botón). Es el patrón más predecible y seguro para cualquier tamaño de dataset.
- **Opcional**: debounce de 300ms en el input de búsqueda. Solo si el módulo lo justifica y el dataset es pequeño/mediano. Se implementa con `router.get()` + `preserveState` + debounce en el frontend.
- Cada búsqueda genera una nueva request server-side. No hay búsqueda client-side.

#### 4.4.3 Exportación

La exportación es una **capacidad opcional prevista** por el estándar, no obligatoria para todo CRUD.

| Aspecto | Decisión |
| --- | --- |
| Ubicación | Botón opcional en `<Toolbar>`, condicionado por permiso. |
| Exportación pequeña (<1000 registros) | Puede ser síncrona (respuesta directa con download). |
| Exportación grande (>1000 registros) | **Debe usar job/cola** (ADR-008). El usuario recibe feedback de que la exportación está en proceso. |
| Formato | A decidir por módulo. CSV como mínimo razonable. |
| Permiso | La acción de exportar requiere permiso explícito (e.g., `module.resource.export`). |

Esta capacidad no se implementa en este PRD — se define como extensión prevista para que los módulos futuros sepan cómo integrarla.

### 4.5 Patrón de formularios

El estándar de formularios es consistente con Inertia v2 y la convención ya establecida en el boilerplate:

#### Patrón primario: `<Form>` declarativo con Wayfinder

```tsx
import Controller from '@/actions/App/Http/Controllers/{Module}/{Model}Controller';

<Form
    {...Controller.store.form()}
    options={{ preserveScroll: true }}
    className="space-y-6"
>
    {({ processing, recentlySuccessful, errors }) => (
        <>
            <div className="grid gap-2">
                <Label htmlFor="name">Nombre</Label>
                <Input id="name" name="name" required />
                <InputError message={errors.name} />
            </div>

            <Button disabled={processing}>Guardar</Button>
        </>
    )}
</Form>
```

#### Patrón secundario: `useForm` imperativo

Solo para formularios que requieran control imperativo — por ejemplo, uploads con progreso, confirmaciones multi-paso, o manipulación de datos antes del submit. Debe justificarse en el PR.

#### Estrategia de validación

| Nivel | Cuándo aplica | Notas |
| --- | --- | --- |
| **Server-side al submit** | **Siempre (default obligatorio)** | FormRequests son la fuente de verdad. Inertia maneja automáticamente los errores 422 y los expone en `errors` del render prop. |
| **Hints client-side** | Complementario, nunca sustitutivo | Atributos HTML como `required`, `type="email"`, `maxLength` mejoran la UX pero no reemplazan la validación server-side. |
| **Validación en tiempo real** | Opt-in por módulo, justificado | Solo si un campo requiere feedback inmediato (e.g., verificar unicidad de email). Se implementa con un endpoint dedicado. No es parte del estándar base. |

- **FormRequests** obligatorios. No se valida inline en controllers.
- **`authorize()` en FormRequest** obligatorio. Delega a la Policy del modelo.

#### Convención de errores en formularios

- **Errores por campo**: `<InputError message={errors.field} />` debajo de cada input.
- **Errores generales** (e.g., excepción de negocio): `<AlertError>` arriba del formulario si hay error flash.
- **Feedback exitoso post-redirect**: toast via `sonner`. Ver §4.5.1 para el mecanismo.
- **Feedback exitoso inline** (sin redirect): el patrón `Transition` + `recentlySuccessful` se mantiene para formularios inline de settings donde ya está implementado.

#### 4.5.1 Mecanismo de flash para toasts post-redirect

Cuando un `store()` o `update()` exitoso redirige a otra página (e.g., `return to_route('module.index')`), el toast debe sobrevivir el redirect. El mecanismo es:

**1. Controller usa flash de sesión**:

```php
return to_route('module.resource.index')
    ->with('success', 'Recurso creado exitosamente.');
```

**2. `HandleInertiaRequests` comparte el flash**:

```php
// En share()
'flash' => [
    'success' => $request->session()->get('success'),
    'error' => $request->session()->get('error'),
],
```

**3. Componente `<FlashToaster>` en el layout raíz**:

```tsx
// components/flash-toaster.tsx
import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';

export function FlashToaster() {
    const { flash } = usePage().props as { flash?: { success?: string; error?: string } };

    useEffect(() => {
        if (flash?.success) toast.success(flash.success);
        if (flash?.error) toast.error(flash.error);
    }, [flash]);

    return null;
}
```

Este componente se monta una vez en el layout raíz (`app-layout.tsx`) junto con el `<Toaster />` de Sonner. Cada vez que Inertia carga una página con flash data, el toast se dispara automáticamente.

#### 4.5.2 Patrón de selección relacional con creación contextual

Cuando un formulario CRUD requiere seleccionar una entidad relacionada que podría no existir aún (e.g., seleccionar un visitante al crear una visita), el estándar define:

| Aspecto | Convención |
| --- | --- |
| **Default** | Las relaciones se seleccionan desde `<Select>` o input del formulario padre, con datos precargados desde el controller. |
| **Si el relacionado no existe** | El sistema puede ofrecer creación contextual según la complejidad de la entidad. |
| **Entidad simple** (pocos campos) | Modal o `<Sheet>` de creación rápida que, al guardar exitosamente, retorna el registro recién creado al formulario padre sin perder el estado del form. |
| **Entidad compleja** (muchos campos, validaciones complejas) | Link de navegación al `create` del módulo destino. El formulario padre puede usar `preserveState` o guardar borrador para no perder el progreso. |
| **Retorno al formulario padre** | La creación contextual (modal/sheet) devuelve el ID y nombre del registro creado para auto-seleccionarlo en el select/input padre. |

**Reglas de implementación**:

- La creación contextual usa el mismo FormRequest y Policy del módulo destino — no se duplican reglas de validación.
- El permiso para crear la entidad relacionada se verifica con `useCan` antes de mostrar el botón "Crear nuevo" en el modal/sheet.
- Si la creación contextual falla (validación, error), los errores se muestran dentro del modal/sheet sin afectar el formulario padre.
- Este patrón es **opt-in por módulo** — no todo select relacional necesita creación contextual.

**Reglas de seguridad de la creación contextual**:

- **Regla de doble permiso**: la creación contextual requiere **dos permisos distintos** — el permiso para la operación del recurso padre (e.g., `visits.create`) **y** el permiso para crear la entidad relacionada (e.g., `visitors.create`). No basta con poder crear la visita para poder crear el visitante desde ahí.
- **Regla de no-bypass**: la creación contextual **no abre una vía de bypass del módulo relacionado**. El modal/sheet reutiliza exactamente las mismas reglas de validación (FormRequest), la misma Policy, y el mismo audit trail del módulo destino. No existe un "create lite" que se salte validación o auditoría.
- **Enforcement backend**: el endpoint que recibe la creación contextual es el mismo `store` del módulo destino (o un endpoint dedicado que use el mismo FormRequest). El backend verifica autorización de forma independiente — el hecho de que el usuario llegó desde un modal no cambia las reglas.

**Ejemplo de flujo**:

```
Formulario "Crear Visita"
  └─ Select "Visitante"
       ├─ [opciones existentes]
       └─ [+ Crear visitante] → Sheet con form mínimo
            └─ onSuccess → auto-selecciona el nuevo visitante en el select padre
```

#### 4.5.3 Validación de operaciones destructivas y de estado

Las operaciones de lifecycle (§4.2.6) pueden requerir validación y mensajes de negocio más explícitos que un CRUD simple:

| Operación | Validación | Feedback |
| --- | --- | --- |
| **Delete** (con reglas de negocio) | `Delete{Model}Request` con `authorize()` + reglas custom. Ejemplo: verificar que no hay relaciones activas. | Toast de error si hay restricción. `<ConfirmationDialog>` obligatorio antes de intentar. |
| **Deactivate** | `Deactivate{Model}Request` si hay restricciones (e.g., último admin activo). | Toast de confirmación o error. Mensaje explícito de por qué no se puede desactivar. |
| **Restore** | `Restore{Model}Request` si hay reglas de elegibilidad. | Toast de confirmación. |
| **Delete trivial** (sin reglas extra) | `Gate::authorize()` en controller es suficiente. | `<ConfirmationDialog>` + toast. |

**Mensajes de negocio para restricciones**: cuando una operación destructiva falla por regla de negocio (no por validación de campos), el controller lanza una excepción de negocio (`BoilerplateException` de PRD-03) que se presenta al usuario como toast de error o `<AlertError>`, nunca como error de campo.

### 4.6 Seguridad y autorización del CRUD

#### 4.6.1 Modelo de enforcement por capas

La seguridad del CRUD es **redundante por diseño**. Ninguna capa individual es suficiente — todas operan en conjunto:

| Capa | Mecanismo | Qué hace | Qué NO hace |
| --- | --- | --- | --- |
| **Capa 1 — UI** | `useCan()` hook | Oculta/muestra botones, links, acciones y items de menú según permisos del usuario. | No impone seguridad real. Un usuario puede saltarla con URL directa o request HTTP. |
| **Capa 2 — Navegación** | `HandleInertiaRequests::share()` | Filtra items del sidebar por permiso en PHP. El frontend solo recibe los items que el usuario puede ver. | No protege las rutas subyacentes. |
| **Capa 3 — Ruta** | Middleware `auth`, `verified`, `ensure-two-factor` | Garantiza que toda ruta CRUD requiere sesión autenticada, email verificado y 2FA confirmado. | No verifica permisos granulares por acción. |
| **Capa 4 — Controller** | `Gate::authorize()` o Policy | Protege acciones de lectura (`index`, `show`) y acciones singulares. Verifica que el usuario tiene la ability concreta para la operación. | No valida datos de entrada. |
| **Capa 5 — FormRequest** | `authorize()` + `rules()` | Protege acciones mutantes (`store`, `update`, `delete`, `deactivate`, `restore`). Verifica autorización **y** valida datos antes de llegar al controller. | — |

**Regla de diseño**: toda acción mutante debe estar protegida al menos por las capas 3 + 5 (ruta autenticada + FormRequest con `authorize()`). Toda acción de lectura debe estar protegida al menos por las capas 3 + 4 (ruta autenticada + Gate/Policy en controller).

**Prohibido**: depender solo de la capa 1 (UI) para evitar que un usuario ejecute una acción. Un botón oculto no es seguridad.

#### 4.6.2 Permisos como unidad primaria de autorización

**Decisión congelada**: el acceso a rutas, páginas y acciones CRUD se controla por **abilities/permisos**, no por nombres de rol. Los roles no son la unidad primaria de autorización del módulo.

| Correcto | Incorrecto |
| --- | --- |
| `Gate::authorize('system.users.delete', $user)` | `if ($user->hasRole('Admin'))` |
| `$request->user()->can('system.roles.create')` | `if ($user->hasRole('SuperAdmin'))` |
| `useCan('system.users.edit')` | `if (user.roles.includes('Admin'))` |
| Middleware `can:system.users.view` | Middleware `role:admin` |

**Justificación**: Spatie Laravel Permission recomienda explícitamente que la app chequee permisos, no roles. Los roles sirven para **agrupar** permisos y asignarlos a usuarios. Los permisos son lo que la app verifica para decidir acceso.

**Excepción documentada**: `hasRole()` es aceptable en **infraestructura de seguridad transversal** donde la decisión es intrínsecamente sobre el rol, no sobre una acción CRUD. Ejemplos existentes en el boilerplate:
- `EnsureTwoFactorEnabled` middleware — verifica `hasRole('super-admin')` para forzar 2FA a super-admins (PRD-02).
- `User::isLastSuperAdmin()` — guard de último admin que opera sobre el rol directamente.
- Assertions en tests — `$user->hasRole(...)` para verificar estado del modelo, no para decidir acceso.

Estos usos no violan la regla porque no controlan acceso a acciones CRUD. La regla aplica a controllers, rutas, UI y lógica de módulo.

**Convención de naming de permisos**: `{module}.{resource}.{action}` (e.g., `system.users.view`, `system.users.create`, `system.roles.delete`, `system.users.deactivate`, `system.users.view-trashed`, `system.users.restore`).

#### 4.6.3 Backend (fuente de verdad)

Todo módulo CRUD aplica autorización real en backend:

- **Policy** registrada para el modelo. Métodos base: `viewAny`, `view`, `create`, `update`, `delete`. Métodos adicionales según lifecycle del módulo: `deactivate`, `restore`, `forceDelete`, `viewTrashed` (§4.2.6, §4.2.7).
- **FormRequest** con `authorize()` que delega a la policy o gate. Obligatorio en toda acción mutante.
- **Controller** usa `Gate::authorize()` en métodos que no pasan por FormRequest (e.g., `index`, `show`). No `$this->authorize()` — el base Controller no incluye `AuthorizesRequests`.
- **Middleware de ruta** puede usar `can:permission.name` como prefiltro cuando tenga sentido (e.g., entry points de módulo). Esto es un complemento, no un reemplazo del Gate/Policy en el controller.
- La visibilidad UI **nunca sustituye** la seguridad backend.

#### 4.6.4 Frontend (reflejo de permisos)

El payload `auth.permissions` ya está compartido desde `HandleInertiaRequests`:

```php
'auth' => [
    'user' => $request->user(),
    'permissions' => $request->user()?->getAllPermissions()->pluck('name')->all() ?? [],
],
```

Se creará un hook `useCan` para consumir permisos de forma declarativa:

```tsx
// hooks/use-can.ts
import { usePage } from '@inertiajs/react';
import type { Auth } from '@/types';

export function useCan(permission: string): boolean {
    const { auth } = usePage().props as { auth: Auth };
    return auth.permissions.includes(permission);
}
```

Uso en componentes:

```tsx
const canCreate = useCan('module.resource.create');
const canDelete = useCan('module.resource.delete');
const canViewTrashed = useCan('module.resource.view-trashed');

// Botón de crear condicionado
{canCreate && <Button onClick={...}>Crear nuevo</Button>}

// Acción de eliminar en dropdown condicionada
{canDelete && <DropdownMenuItem>Eliminar</DropdownMenuItem>}

// Filtro de eliminados solo si tiene permiso
{canViewTrashed && <Select>...</Select>}
```

El hook lee del payload ya serializado — no hace requests adicionales. **Es conveniencia visual, no seguridad.** El backend siempre verifica de forma independiente.

### 4.7 Navegación administrativa

#### Decisión congelada: server-driven via `HandleInertiaRequests`

La navegación del sidebar se gestiona desde el backend en `HandleInertiaRequests::share()`. Cada módulo agrega sus items directamente al array `ui.navigation.items`:

```php
'navigation' => [
    'label' => 'Navegación',
    'items' => array_values(array_filter([
        // --- Core ---
        ['title' => 'Panel', 'href' => route('dashboard', absolute: false)],

        // --- Acceso (PRD-05) ---
        $request->user()?->can('system.users.view')
            ? ['title' => 'Usuarios', 'href' => route('system.users.index', absolute: false)]
            : null,
        $request->user()?->can('system.roles.view')
            ? ['title' => 'Roles', 'href' => route('system.roles.index', absolute: false)]
            : null,
    ])),
    'starterPromoLinksRemoved' => true,
],
```

**Convenciones**:

- Los items se agrupan con comentarios por módulo (`// --- NombreMódulo ---`).
- Los items de un módulo se condicionan por permiso en PHP usando el patrón ternario + `array_filter`. El frontend solo recibe los items que el usuario puede ver.
- No se implementa un `NavigationRegistry` ni `MenuBuilder` en esta iteración. Si el número de módulos crece significativamente (>8 items principales), se evaluará un sistema dinámico en un ADR posterior.
- El type `NavItem` ya soporta iconos opcionales via `icon?: LucideIcon | null`.

### 4.8 Componentes transversales mínimos

El boilerplate deberá tener, como mínimo, los siguientes componentes transversales para soportar módulos CRUD:

| Componente | Estado | Notas |
| --- | --- | --- |
| Button (con variantes) | ✅ Existe | CVA variants: default, destructive, outline, secondary, ghost, link |
| Input | ✅ Existe | |
| Label | ✅ Existe | |
| Select | ✅ Existe | Radix Select. Suficiente para la primera iteración. |
| Checkbox | ✅ Existe | |
| Dialog | ✅ Existe | Base para ConfirmationDialog |
| Badge | ✅ Existe | Para estados, tags |
| Card | ✅ Existe | Para secciones de detalle |
| Skeleton | ✅ Existe | Para loading states |
| Spinner | ✅ Existe | Para botones processing |
| DropdownMenu | ✅ Existe | Para acciones por fila |
| Alert | ✅ Existe | Para mensajes importantes |
| Tooltip | ✅ Existe | Para ayuda contextual |
| Heading | ✅ Existe | Título + descripción de sección |
| InputError | ✅ Existe | Error por campo |
| AlertError | ✅ Existe | Bloque de errores múltiples |
| Breadcrumbs | ✅ Existe | Navegación contextual |
| **Table** | 🔨 Crear | Header/Body/Row/Cell/Head |
| **Pagination** | 🔨 Crear | Consume `links` del paginator Laravel |
| **EmptyState** | 🔨 Crear | Icono + mensaje + CTA opcional |
| **ConfirmationDialog** | 🔨 Crear | Compuesto sobre Dialog. Título + descripción + confirmar/cancelar |
| **Toast (Sonner)** | 🔨 Crear | Provider `<Toaster />` global + invocación con `toast()` |
| **FlashToaster** | 🔨 Crear | Lee flash de sesión compartido por Inertia y dispara toasts automáticamente |
| **Toolbar** | 🔨 Crear | Búsqueda + filtros + acciones primarias |
| **Textarea** | 🔨 Crear | Campo de texto multilínea (HTML nativo + Tailwind) |
| **`useCan` hook** | 🔨 Crear | Lee `auth.permissions` del payload Inertia |

### 4.9 UX administrativa estándar

Todos los módulos CRUD heredan:

- **Consistencia visual**: mismos componentes, mismo espaciado, mismo idioma (español).
- **Textos y acciones previsibles**: "Crear", "Editar", "Eliminar", "Guardar", "Cancelar" como vocabulario estándar.
- **Confirmación para acciones destructivas**: `<ConfirmationDialog>` obligatorio antes de eliminar.
- **Breadcrumbs**: siempre presentes, reflejando la ubicación actual.
- **Feedback post-acción**: toast para create/update/delete exitosos. Mensaje de error para fallos.
- **Manejo coherente de errores**: heredado de PRD-03.

#### Manejo de errores UX por tipo

| Tipo de error | Presentación | Fuente |
| --- | --- | --- |
| Validación (422) | `<InputError>` por campo, manejado automáticamente por Inertia | FormRequest |
| Autorización (403) | Página de error 403. Botones/acciones ocultos preventivamente via `useCan`. | Policy / Gate |
| Negocio (BoilerplateException) | Toast de error o `<AlertError>` según contexto | Excepción custom |
| No encontrado (404) | Página de error 404 | Route model binding |
| Inesperado (500) | Página de error 500. Flujo estándar de reporte Laravel. | Throwable |

### 4.10 Estrategia visual del CRUD administrativo

#### Design system base

El sistema visual de los módulos CRUD se construye sobre el stack del starter kit React oficial ya customizado por el boilerplate:

| Capa | Tecnología | Notas |
| --- | --- | --- |
| Framework CSS | **Tailwind CSS v4** | Utility-first. No CSS custom salvo tokens de tema. |
| Componentes UI | **shadcn/ui** (patrón, no paquete) | Componentes copiados en `resources/js/components/ui/`, construidos con Radix UI + CVA + Tailwind. Se extienden, no se reescriben. |
| Iconografía | **Lucide React** | Set consistente de iconos. No mezclar con otros icon sets. |
| Tema | Palette **violet** con soporte light/dark/system | Configurado en PRD-01. Los tokens CSS de shadcn/ui son la fuente de verdad para colores. |

#### Tema y tokens de color

El boilerplate usa la palette **violet** de shadcn/ui, definida como variables CSS en `:root` (light) y `.dark` (dark). El estándar CRUD congela:

| Token | Uso en CRUD | Ejemplo |
| --- | --- | --- |
| `--primary` | Botones primarios, links activos, focus rings | Botón "Crear nuevo", link activo en sidebar |
| `--destructive` | Acciones destructivas, errores | Botón "Eliminar", badge de error |
| `--muted` / `--muted-foreground` | Texto secundario, placeholders, empty states | Descripción en `<Heading>`, icono de empty state |
| `--card` / `--card-foreground` | Fondo y texto de cards en Show | Secciones de detalle |
| `--border` | Bordes de tabla, inputs, cards, separadores | Líneas de tabla, bordes de input |
| `--accent` | Hover en items de menú, filas de tabla | Hover en `<DropdownMenuItem>`, hover en fila |
| `--ring` | Focus visible en inputs, botones, links | Accesibilidad de teclado |

**Reglas de tema**:

- **Prohibido**: colores hardcoded (e.g., `text-purple-600`, `bg-red-500`). Todo usa tokens semánticos del tema (`text-primary`, `text-destructive`, `bg-muted`).
- **Prohibido**: paleta custom por módulo. Todos los módulos comparten la misma palette.
- **Obligatorio**: todo componente nuevo debe verse correcto en light y dark mode. Esto incluye badges, toasts, dialogs, tables, empty states, y formularios.
- **Validación**: antes de cerrar un componente, verificar visualmente en ambos modos. Los contrastes deben cumplir WCAG AA mínimo.

#### Convenciones de composición visual

| Aspecto | Convención |
| --- | --- |
| **Spacing** | `gap-4` entre secciones principales. `gap-2` dentro de form groups. `space-y-6` para stacks verticales de secciones. Padding de página: `p-4`. |
| **Cards** | Usar `<Card>` para agrupar secciones relacionadas en Show. No envolver todo en cards — los Index usan tabla directa. |
| **Headings** | `<Heading variant="default">` para títulos de página CRUD. `<Heading variant="small">` para sub-secciones dentro de una página. |
| **Formularios** | Campos en stack vertical (`space-y-6`). Grids (`grid grid-cols-2 gap-4`) solo si hay campos cortos que se beneficien de lado a lado. En mobile, los grids colapsan a una columna. |
| **Toolbar** | Ubicada entre `<Heading>` y `<Table>`. Flex row con búsqueda a la izquierda y acciones a la derecha. En mobile, se apila verticalmente. |
| **Acciones destructivas** | Botón `variant="destructive"`. Siempre requiere `<ConfirmationDialog>`. Texto explícito ("Eliminar", no icono solo). |
| **Acciones primarias** | Botón `variant="default"` (primary). Ubicado en toolbar (Index) o al final del formulario (Create/Edit). |
| **Empty states** | Centrados vertical y horizontalmente en el espacio de la tabla. Icono muted + mensaje + CTA opcional. |
| **Badges** | Para estados y categorías en celdas de tabla. Colores semánticos via variantes de CVA. Deben ser legibles en light y dark. |
| **Estados hover/focus** | Toda acción interactiva debe tener `:hover` y `:focus-visible` claros. Los focus rings usan `--ring`. |

#### Responsive y soporte mobile

Todo módulo CRUD debe ser funcional y usable en dispositivos móviles. El estándar congela:

| Aspecto | Convención |
| --- | --- |
| **Breakpoints** | Se usan los de Tailwind (`sm`, `md`, `lg`, `xl`). No se definen breakpoints custom. |
| **Sidebar** | Ya soporta colapso mobile via `SidebarProvider` del starter kit. No hay trabajo adicional por módulo. |
| **Tablas** | `overflow-x-auto` en el contenedor de tabla. En pantallas pequeñas, el usuario hace scroll horizontal. No se hace layout alternativo (e.g., cards) por defecto — si un módulo lo necesita, lo justifica en su PR. |
| **Formularios** | Grids de 2 columnas (`grid-cols-2`) colapsan a 1 columna en mobile via `sm:grid-cols-2` (mobile-first). |
| **Toolbar** | Flex wrap o stack vertical en mobile. Búsqueda full-width, acciones debajo. |
| **Modales y Sheets** | Los `<Dialog>` se muestran como modal centrado en desktop. Los `<Sheet>` se usan para paneles laterales que en mobile ocupan el ancho completo. |
| **Acciones por fila** | `<DropdownMenu>` funciona correctamente en mobile (Radix ya lo soporta). |
| **Touch targets** | Botones e interacciones respetan tamaño mínimo de 44×44px para touch (natural con los tamaños default de shadcn/ui). |
| **Paginación** | Los controles de paginación son touch-friendly. En pantallas muy pequeñas, se muestran solo prev/next sin números intermedios. |

**Regla general**: mobile-first no significa "mobile-optimized" — el backoffice es primariamente desktop, pero debe ser **funcional** en mobile sin layout roto ni acciones inaccesibles.

#### Decisiones visuales que NO toma este PRD

- No se define un grid system rígido para dashboards.
- No se definen animaciones complejas ni transiciones de página.
- No se define un layout alternativo de tabla para mobile (e.g., card view) — si un módulo lo necesita, lo define en su propio PRD.

### 4.11 Testing mínimo del estándar CRUD

Todo módulo CRUD debe tener los siguientes archivos de test como mínimo:

| Archivo | Cubre |
| --- | --- |
| `tests/Feature/{Module}/IndexTest.php` | Acceso autorizado al listado, paginación, filtros, búsqueda. |
| `tests/Feature/{Module}/CreateTest.php` | Rendering del formulario, store con datos válidos, validación con datos inválidos. |
| `tests/Feature/{Module}/UpdateTest.php` | Rendering del formulario edit, update con datos válidos, validación con datos inválidos. |
| `tests/Feature/{Module}/DeleteTest.php` | Eliminación exitosa, restricciones si aplican. Solo si el módulo soporta delete. |
| `tests/Feature/{Module}/AuthorizationTest.php` | Enforcement por capas: acceso por URL directa, acciones HTTP directas, lifecycle, soft-delete, creación contextual. |

**Convenciones de test**:

- Usar Pest con `RefreshDatabase`.
- Crear usuarios con factory y asignar permisos explícitamente por test.
- No depender del seeder para tests — cada test configura su propio estado.
- Usar `$this->actingAs($user)` para autenticación.
- Verificar tanto el happy path como la validación.

#### Tests de seguridad obligatorios (AuthorizationTest)

El `AuthorizationTest.php` de cada módulo debe cubrir como mínimo los siguientes escenarios. Estos tests verifican que el modelo de enforcement por capas (§4.6.1) funciona de forma independiente de la UI:

**Acceso por URL directa sin permiso**:

| Escenario | Resultado esperado |
| --- | --- |
| `GET /module/resource` (index) sin permiso de view | 403 |
| `GET /module/resource/create` sin permiso de create | 403 |
| `GET /module/resource/{id}/edit` sin permiso de edit | 403 |
| `GET /module/resource/{id}` (show) sin permiso de view | 403 |

**Acciones HTTP directas sin permiso**:

| Escenario | Resultado esperado |
| --- | --- |
| `POST /module/resource` (store) sin permiso de create | 403 |
| `PUT /module/resource/{id}` (update) sin permiso de edit | 403 |
| `DELETE /module/resource/{id}` (destroy) sin permiso de delete | 403 |

**Lifecycle operations sin permiso** (si el módulo las soporta):

| Escenario | Resultado esperado |
| --- | --- |
| `PATCH /module/resource/{id}/deactivate` sin permiso de deactivate | 403 |
| `PATCH /module/resource/{id}/restore` sin permiso de restore | 403 |
| `DELETE /module/resource/{id}/force` sin permiso de force-delete | 403 |

**Soft delete access control** (si el módulo usa soft deletes):

| Escenario | Resultado esperado |
| --- | --- |
| `GET /module/resource?trashed=only` sin permiso de view-trashed | Los registros eliminados no se muestran (filtro ignorado o 403). |
| `GET /module/resource/{id-eliminado}` (show) sin permiso de view-trashed | 404 (modelo no se resuelve) o 403. |
| `PATCH /module/resource/{id-eliminado}/restore` sin permiso de restore | 403 |

**Creación contextual** (si el módulo la soporta):

| Escenario | Resultado esperado |
| --- | --- |
| Crear entidad relacionada desde modal sin permiso sobre la entidad relacionada | 403 |
| Crear entidad relacionada con permiso de padre pero sin permiso de la entidad relacionada | 403 |

**Happy paths** (cada acción con el permiso correcto):

- Cada una de las acciones anteriores debe pasar con el permiso adecuado asignado al usuario.
- El test verifica tanto el código de respuesta (200/302) como el efecto en base de datos.

### 4.12 ADRs y documentación

Este PRD produce:

| Entregable | Contenido |
| --- | --- |
| **ADR-009: Estándar CRUD administrativo** | Documenta las decisiones congeladas de este PRD: patrón de páginas, tablas, formularios, show, permisos UI, controllers, y convención de Wayfinder. Incluye justificación de decisiones (offset vs cursor, `<Form>` vs `useForm`, modelo directo vs Resource). |
| **`docs/crud-module-guide.md`** | Guía paso a paso para construir un nuevo módulo CRUD. Incluye checklist de consistencia como sección final. |

No se crean ADRs separados para tablas y formularios — son sub-decisiones del estándar CRUD y se documentan dentro de ADR-009.

## 5. Criterios de Aceptación

### 5.1 Criterios funcionales

- Deben existir los componentes listados en §4.1.2 (`Table`, `Pagination`, `EmptyState`, `ConfirmationDialog`, Toast via Sonner, `Toolbar`, `Textarea`).
- Debe existir el componente `<FlashToaster>` y estar montado en el layout raíz.
- Debe existir el hook `useCan` en `resources/js/hooks/use-can.ts`.
- Debe existir el provider `<Toaster />` de Sonner integrado en el layout raíz.
- `HandleInertiaRequests` debe compartir `flash.success` y `flash.error` de sesión.
- Debe existir ADR-009 documentando las decisiones del estándar.
- Debe existir `docs/crud-module-guide.md` con guía + checklist.
- La guía de módulo debe incluir el checklist de lifecycle operations (§4.2.6) y la estrategia de soft delete (§4.2.7).

### 5.2 Criterios de consistencia

- Un módulo nuevo no debe tener que inventar su propio patrón de listado.
- Un módulo nuevo no debe tener que inventar su propio patrón de formulario.
- Un módulo nuevo no debe tener que inventar su propio patrón de permisos UI/backend.
- El estándar debe ser reusable en al menos: (a) el futuro módulo de acceso (PRD-05) y (b) un futuro visor administrativo de auditoría (PRD-06).

### 5.3 Criterios de seguridad y calidad

- El estándar exige autorización backend real con enforcement por capas (§4.6.1): middleware de ruta + Gate/Policy en controller + FormRequest `authorize()` en mutaciones.
- Ninguna acción CRUD es accesible solo porque un botón está visible. Toda acción se verifica en backend de forma independiente de la UI.
- Los permisos (abilities) son la unidad primaria de autorización. No se bloquea por nombre de rol.
- Las operaciones de lifecycle (deactivate, restore, force-delete) requieren ability explícita y Policy method dedicado.
- La creación contextual exige doble permiso (recurso padre + entidad relacionada).
- El estándar se apoya en la política de errores de PRD-03.
- Los componentes nuevos son testeables y aptos para CI.
- El estándar no obliga a over-engineering ni a una mega-DataTable universal.

### 5.4 Criterios de verificación

- `npm run build` completa sin errores.
- `npm run types:check` completa sin errores.
- `php artisan test --compact` pasa.
- Los componentes nuevos tienen al menos tests de rendering básico.
- Todos los componentes nuevos se verifican visualmente en light y dark mode.
- Las páginas CRUD (Index, Create, Edit, Show) son funcionales en viewport mobile (≥320px) sin layout roto ni acciones inaccesibles.

## 6. Dependencias y Riesgos

### 6.1 Dependencias

- **PRD-02**: identidad/autorización (payload `auth.permissions`, policies, gates).
- **PRD-03**: operabilidad transversal (manejo de errores, BoilerplateException, logging).
- **Laravel 13 + Inertia v2 + React 19**: stack base.
- **Wayfinder**: convención de routing type-safe.
- **Convención de policies/gates/form requests**: ya definida en PRD-02.
- **`sonner`**: nueva dependencia npm para toasts.

### 6.2 Riesgos principales

| Riesgo | Severidad | Mitigación |
| --- | --- | --- |
| Sobrediseñar un framework interno de CRUD | Alta | Definir un patrón reusable, no una meta-plataforma configurable infinita. Table es JSX directo, no config objects. |
| Hacer el estándar demasiado acoplado al módulo de acceso | Media | Redactarlo como contrato general y validar que también sirva para un visor de auditoría (read-only CRUD). |
| Duplicar lógica entre estándar CRUD y módulo específico | Media | Mover al estándar todo lo transversal y dejar al módulo solo su lógica propia. |
| Confundir patrón UI con control de acceso real | Alta | Backend es fuente de verdad. `useCan` es conveniencia visual, no seguridad. |
| Componentes nuevos introducen bugs o inconsistencias | Media | Tests de rendering + uso inmediato en PRD-05 como validación. |
| `sonner` introduce conflictos con el stack | Baja | Es la recomendación de shadcn/ui, compatible con Radix y Tailwind. |

## 7. Entregables esperados

Al cerrar este PRD, el boilerplate debe tener:

- Componentes UI nuevos: Table, Pagination, EmptyState, ConfirmationDialog, Toast (Sonner), Toolbar, Textarea.
- Componente de aplicación: `<FlashToaster>` montado en layout raíz.
- Hook: `useCan`.
- Dependencia: `sonner` instalada.
- `HandleInertiaRequests` compartiendo flash de sesión (`success`, `error`).
- ADR-009: Estándar CRUD administrativo (incluyendo lifecycle operations, soft delete strategy, tema/tokens, responsive).
- `docs/crud-module-guide.md`: guía de construcción + checklist de consistencia + checklist de lifecycle + checklist visual (light/dark/mobile).
- Tests de rendering básicos para componentes nuevos.
- Todos los componentes verificados en light y dark mode.
- Páginas CRUD funcionales en viewport mobile.

## 8. Qué cambia en PRDs anteriores

### 8.1 Ajuste de secuencia

La secuencia anterior (PRD-00, PRD-02, PRD-03) referenciaba "PRD-04" como el módulo de administración de acceso. Con la inserción de este PRD de estándar CRUD, la secuencia actualizada es:

- **PRD-00**: Boilerplate como producto interno _(completado)_
- **PRD-01**: Personalización base corporativa _(completado, alias PRD.md)_
- **PRD-02**: Núcleo de identidad y autorización _(completado)_
- **PRD-03**: Operabilidad transversal _(completado)_
- **PRD-04**: Estándar de módulos CRUD administrativos _(este documento)_
- **PRD-05**: Administración de acceso — roles, permisos y asignación de usuarios
- **PRD-06**: Visor administrativo de auditoría _(si se decide como módulo separado)_

### 8.2 Ajustes aplicados en documentos existentes

Las secciones de secuencia futura en PRD-00 (§7), PRD-02 (§8) y PRD-03 (§8) ya fueron actualizadas para reflejar la nueva numeración.

### 8.3 Qué sale del futuro PRD-05

El PRD de administración de acceso (ex PRD-04, ahora PRD-05) debe ajustarse:

- Sale cualquier pretensión de definir el patrón CRUD general del sistema.
- Sale el visor de audit log como parte del módulo de acceso.
- Queda solo la lógica específica de administración de acceso: CRUD de roles, CRUD de permisos, asignación de roles a usuarios, restricciones operativas.
- El stub `system.users.index` (actualmente una closure que retorna JSON) se migra a un controller + página Inertia completa siguiendo el estándar de este PRD-04.
