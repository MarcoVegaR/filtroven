# Operaciones de ciclo de vida

!!! info "Ficha documental"
    - Estado: `vigente`
    - Tipo: `how-to`
    - Audiencia: desarrolladores que agregan soft delete, restore, force delete o papelera a un módulo CRUD
    - Fuente verificable: `Illuminate\Database\Eloquent\SoftDeletes`, routes lifecycle, controllers invocables, policies, tests y [ADR-009](../../adr/ADR-009-crud-module-standard.md)

Las operaciones lifecycle amplían CRUD básico y deben ser explícitas. No todos los módulos necesitan restore o force delete; agrégalas solo cuando el dominio lo justifique.

## Operaciones

| Operación | Mecanismo | Ruta | Ability |
| --- | --- | --- | --- |
| Destroy soft | `SoftDeletes` + `$model->delete()` | `DELETE /{resource}/{id}` | `delete` |
| Destroy hard | sin `SoftDeletes` + `$model->delete()` | `DELETE /{resource}/{id}` | `delete` |
| Deactivate | `$model->update(['active' => false])` | `PATCH /{resource}/{id}/deactivate` | `deactivate` |
| Restore | `$model->restore()` | `PATCH /{resource}/{id}/restore` con `withTrashed()` | `restore` |
| Force delete | `$model->forceDelete()` | `DELETE /{resource}/{id}/force` con `withTrashed()` | `forceDelete` |

## Soft delete

Modelo:

```php
use Illuminate\Database\Eloquent\SoftDeletes;

class {Model} extends Model
{
    use SoftDeletes;
}
```

Migración:

```php
$table->softDeletes();
```

El `destroy()` estándar debe llamar `$model->delete()`. Si el modelo usa `SoftDeletes`, Laravel marca `deleted_at` en lugar de borrar físicamente.

## Restore

```php
<?php

namespace App\Http\Controllers\{Module};

use App\Http\Controllers\Controller;
use App\Models\{Model};
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class Restore{Model}Controller extends Controller
{
    public function __invoke({Model} $model): RedirectResponse
    {
        Gate::authorize('restore', $model);

        $model->restore();

        return to_route('{module}.{resource}.index')
            ->with('success', '{Recurso} restaurado exitosamente.');
    }
}
```

Ruta:

```php
Route::patch('{resource}/{model}/restore', Restore{Model}Controller::class)
    ->withTrashed()
    ->name('{resource}.restore');
```

## Force delete

```php
public function __invoke({Model} $model): RedirectResponse
{
    Gate::authorize('forceDelete', $model);

    $model->forceDelete();

    return to_route('{module}.{resource}.index')
        ->with('success', '{Recurso} eliminado permanentemente.');
}
```

La ruta también debe usar `->withTrashed()` para resolver modelos eliminados lógicamente.

## Ver registros eliminados

Usa `onlyTrashed()` o `withTrashed()` solo en rutas/pantallas protegidas por `view-trashed`.

```php
Gate::authorize('viewTrashed', {Model}::class);

$trashedModels = {Model}::onlyTrashed()
    ->latest()
    ->paginate(15);
```

## Policy lifecycle

```php
public function restore(User $user, {Model} $model): bool
{
    return $user->hasPermissionTo('{module}.{resource}.restore')
        && $user->hasPermissionTo('{module}.{resource}.view-trashed');
}

public function forceDelete(User $user, {Model} $model): bool
{
    return $user->hasPermissionTo('{module}.{resource}.force-delete');
}

public function viewTrashed(User $user): bool
{
    return $user->hasPermissionTo('{module}.{resource}.view-trashed');
}
```

## UI destructiva

Destroy, deactivate y force delete deben usar `ConfirmationDialog`. El botón confirma una acción irreversible o sensible y debe quedar deshabilitado mientras `processing` sea `true`.

## Checklist lifecycle

- Modelo con `SoftDeletes` cuando aplique.
- Migracion con `$table->softDeletes()`.
- `destroy()` llama `$model->delete()`.
- Rutas `restore` y `force-delete` usan `->withTrashed()`.
- Policy incluye `restore()`, `forceDelete()` y `viewTrashed()` si el módulo expone papelera.
- Permisos `restore`, `force-delete` y `view-trashed` están seeded.
- UI usa `ConfirmationDialog` y `useCan()` para visibilidad.
- Tests cubren soft delete, restore, force delete y acceso no autorizado.
