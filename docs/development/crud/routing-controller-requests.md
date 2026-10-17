# Rutas, controller y FormRequest

!!! info "Ficha documental"
    - Estado: `vigente`
    - Tipo: `how-to`
    - Audiencia: desarrolladores que implementan el backend HTTP de módulos CRUD
    - Fuente verificable: `routes/`, `app/Http/Controllers/`, `app/Http/Requests/`, `app/Policies/` y [ADR-009](../../adr/ADR-009-crud-module-standard.md)
    - Última revisión: `2026-05-28`
    - Owner: mantenimiento

El backend CRUD mantiene una ruta por módulo, controllers explícitos y FormRequests para toda mutación.

## Route file del módulo

```php
<?php

use App\Http\Controllers\{Module}\{Model}Controller;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'ensure-two-factor'])
    ->prefix('{module}')
    ->name('{module}.')
    ->group(function () {
        Route::resource('{resource}', {Model}Controller::class)
            ->only(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']);
    });
```

Registra el archivo al final de `routes/web.php`:

```php
require __DIR__.'/{module}.php';
```

## Nombres de ruta base

| Acción | Nombre de ruta |
| --- | --- |
| Index | `{module}.{resource}.index` |
| Create | `{module}.{resource}.create` |
| Store | `{module}.{resource}.store` |
| Show | `{module}.{resource}.show` |
| Edit | `{module}.{resource}.edit` |
| Update | `{module}.{resource}.update` |
| Destroy | `{module}.{resource}.destroy` |

!!! note "Operaciones lifecycle"
    `restore`, `force-delete` y `view-trashed` no pertenecen al contrato HTTP base. Se documentan en [Operaciones de ciclo de vida](lifecycle-operations.md).

## Controller

```php
<?php

namespace App\Http\Controllers\{Module};

use App\Http\Controllers\Controller;
use App\Http\Requests\{Module}\Store{Model}Request;
use App\Http\Requests\{Module}\Update{Model}Request;
use App\Models\{Model};
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class {Model}Controller extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', {Model}::class);

        $models = {Model}::query()
            ->when($request->search, fn ($query, $search) => $query->where('name', 'ilike', "%{$search}%"))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('{module}/{resource}/index', [
            'models' => $models,
            'filters' => $request->only(['search']),
            'breadcrumbs' => [
                ['title' => '{Recursos}'],
            ],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', {Model}::class);

        return Inertia::render('{module}/{resource}/create', [
            'breadcrumbs' => [
                ['title' => '{Recursos}', 'href' => route('{module}.{resource}.index')],
                ['title' => 'Crear'],
            ],
        ]);
    }

    public function store(Store{Model}Request $request): RedirectResponse
    {
        {Model}::create($request->validated());

        return to_route('{module}.{resource}.index')
            ->with('success', '{Recurso} creado exitosamente.');
    }

    public function show({Model} $model): Response
    {
        Gate::authorize('view', $model);

        return Inertia::render('{module}/{resource}/show', [
            'model' => $model,
            'breadcrumbs' => [
                ['title' => '{Recursos}', 'href' => route('{module}.{resource}.index')],
                ['title' => '{Recurso}'],
            ],
        ]);
    }

    public function edit({Model} $model): Response
    {
        Gate::authorize('update', $model);

        return Inertia::render('{module}/{resource}/edit', [
            'model' => $model,
            'breadcrumbs' => [
                ['title' => '{Recursos}', 'href' => route('{module}.{resource}.index')],
                ['title' => 'Editar'],
            ],
        ]);
    }

    public function update(Update{Model}Request $request, {Model} $model): RedirectResponse
    {
        $model->update($request->validated());

        return to_route('{module}.{resource}.index')
            ->with('success', '{Recurso} actualizado exitosamente.');
    }

    public function destroy({Model} $model): RedirectResponse
    {
        Gate::authorize('delete', $model);

        $model->delete();

        return to_route('{module}.{resource}.index')
            ->with('success', '{Recurso} eliminado exitosamente.');
    }
}
```

## Reglas de controller

- Las acciones de lectura (`index`, `create`, `show`, `edit`) autorizan explícitamente en el controller con `Gate::authorize()`.
- Las mutaciones `store` y `update` autorizan en su `FormRequest::authorize()`.
- Las eliminaciones (`destroy` y lifecycle cuando aplique) autorizan explícitamente en el controller con `Gate::authorize()`.
- Ninguna acción pública debe quedar sin autorización verificable.
- Conserva filtros en paginación con `->withQueryString()`.
- Flashea mensajes con `success`, `error`, `info` o `warning`; `HandleInertiaRequests` los comparte y `FlashToaster` los muestra.
- En creación contextual, autoriza el padre y también la creación del recurso hijo.

!!! note "PostgreSQL"
    Este boilerplate usa PostgreSQL como base de datos objetivo. Por eso las búsquedas case-insensitive usan `ilike`. Si el proyecto cambia de motor, esta convención debe revisarse.

## FormRequests

```php
<?php

namespace App\Http\Requests\{Module};

use App\Models\{Model};
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class Store{Model}Request extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', {Model}::class);
    }

    /** @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
```

```php
<?php

namespace App\Http\Requests\{Module};

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class Update{Model}Request extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('update', $this->route('user'));
    }

    /** @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
```

`authorize()` es la capa 3 del modelo de seguridad y se ejecuta antes de `rules()`. Para `store` se autoriza contra la clase; para `update` se autoriza contra la instancia route-bound.

En el ejemplo, `user` representa el parámetro route-bound singular del recurso. Sustitúyelo por el parámetro real generado por la ruta resource, por ejemplo `role`, `customer` o `invoice`.

## Errores comunes

- Registrar `routes/{module}.php` pero olvidar incluirlo en `routes/web.php`.
- Documentar o usar rutas lifecycle en esta guía en lugar de [Operaciones de ciclo de vida](lifecycle-operations.md).
- Autorizar `update` contra la clase en vez de contra la instancia route-bound.
- Usar un nombre de parámetro route-bound distinto al esperado por el FormRequest.
- Usar `like` en vez de `ilike` en búsquedas PostgreSQL case-insensitive.
- Crear mutaciones sin `Store{Model}Request` o `Update{Model}Request`.
- Ocultar acciones en frontend sin respaldarlas con autorización backend.
