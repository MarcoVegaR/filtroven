# Estándar CRUD

!!! info "Ficha documental"
    - Estado: `vigente`
    - Tipo: `referencia`
    - Audiencia: desarrolladores que implementan módulos administrativos y revisores de PR
    - Fuente verificable: [ADR-009](../../adr/ADR-009-crud-module-standard.md), `routes/`, `app/Http/Controllers/`, `app/Http/Requests/`, `app/Policies/`, `resources/js/pages/` y `tests/Feature/`

Un módulo CRUD completo mantiene una estructura reconocible en backend, frontend y pruebas. La consistencia permite auditar permisos, navegar rutas, regenerar Wayfinder y verificar pantallas sin aprender una convención nueva por módulo.

## Reglas generales

- Usa un route file por módulo: `routes/{module}.php`.
- Protege el grupo con `auth`, `verified` y `ensure-two-factor`.
- Usa nombres de ruta `{module}.{resource}.{action}`.
- Autoriza en controller con `Gate::authorize()`; no uses `$this->authorize()` porque el controller base del proyecto no incluye `AuthorizesRequests`.
- Usa `Store{Model}Request` y `Update{Model}Request` para autorización y validación de mutaciones.
- Las policies referencian permisos, no roles.
- Los permisos viven en un seeder dedicado del módulo.
- Las páginas Inertia reciben props tipadas y `breadcrumbs` desde el controller.
- Los formularios y enlaces usan Wayfinder; no hardcodees rutas como `href="/system/users"`.
- Las acciones destructivas visibles en UI usan `ConfirmationDialog`.

## Checklist de archivos

| Capa | Archivo esperado | Propósito |
| --- | --- | --- |
| Rutas | `routes/{module}.php` | Grupo de rutas del módulo |
| Controller | `app/Http/Controllers/{Module}/{Model}Controller.php` | `index`, `create`, `store`, `show`, `edit`, `update`, `destroy` |
| Requests | `app/Http/Requests/{Module}/Store{Model}Request.php` | Autorizar y validar creación |
| Requests | `app/Http/Requests/{Module}/Update{Model}Request.php` | Autorizar y validar actualización |
| Policy | `app/Policies/{Model}Policy.php` | Abilities CRUD y lifecycle cuando aplique |
| Modelo | `app/Models/{Model}.php` | Modelo Eloquent |
| Factory | `database/factories/{Model}Factory.php` | Datos de prueba |
| Migración | `database/migrations/*_create_{resource}_table.php` | Tabla del recurso |
| Seeder | `database/seeders/{Module}PermissionsSeeder.php` | Permisos del módulo |
| Páginas | `resources/js/pages/{module}/{resource}/index.tsx` | Listado con filtros, tabla, paginación y empty state |
| Páginas | `resources/js/pages/{module}/{resource}/create.tsx` | Formulario de creación |
| Páginas | `resources/js/pages/{module}/{resource}/edit.tsx` | Formulario de edición |
| Páginas | `resources/js/pages/{module}/{resource}/show.tsx` | Vista de detalle |
| Componente | `resources/js/pages/{module}/{resource}/components/{model}-form.tsx` | Campos compartidos de create/edit |
| Tipos | `resources/js/types/{module}.d.ts` | Tipos TypeScript del módulo |
| Tests | `tests/Feature/{Module}/*Test.php` | Cobertura de lectura, escritura, borrado y autorización |

## Regla de tres

El scaffold automatiza estructura repetitiva, pero no justifica crear runtimes CRUD transversales, traits, helpers o DSLs avanzados. Extrae una abstracción compartida solo cuando tres módulos reales prueben la misma necesidad.

## Inventario de madurez

| Capa | Estado | Nota |
| --- | --- | --- |
| Patrón de seguridad por capas | Estable | Respaldado por módulos del sistema y tests |
| Baseline Inertia + Wayfinder | Estable | Usado en roles, usuarios, permisos y auditoría |
| `make:scaffold` fase 1 | Provisional | Existe y su alcance está acotado |
| Lifecycle/export/bulk generator | Diferido | Fuera de la garantia del generador |
| Abstracciones CRUD transversales | Diferido | Bloqueadas por la regla de tres |

## Checklist de revisión

- Route file creado y registrado en `routes/web.php`.
- Grupo de rutas con `auth`, `verified`, `ensure-two-factor`.
- Controller con `Gate::authorize()` en cada acción.
- Requests con `authorize()` y `rules()` en mutaciones.
- Policy auto-descubierta o registrada y basada en permisos.
- Seeder de permisos agregado al flujo de seed.
- Factory disponible para tests.
- Mensajes flash con `->with('success', ...)` o `->with('error', ...)`.
- Paginas Inertia con `breadcrumbs`.
- Enlaces y formularios con Wayfinder.
- Tests Pest relevantes pasando.
