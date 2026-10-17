# Autorización y pruebas

!!! info "Ficha documental"
    - Estado: `vigente`
    - Tipo: `referencia`
    - Audiencia: desarrolladores, revisores de seguridad y mantenedores de tests
    - Fuente verificable: `app/Policies/`, `database/seeders/`, `tests/Feature/`, `resources/js/hooks/use-can.ts`, [Autorización](../../authorization.md) y [ADR-009](../../adr/ADR-009-crud-module-standard.md)

La seguridad CRUD combina middleware, gates, FormRequests, validación y visibilidad frontend. La UI nunca reemplaza controles backend.

## Modelo de cinco capas

| Capa | Donde | Mecanismo | Rechazo |
| --- | --- | --- | --- |
| 1 | `routes/{module}.php` | `auth`, `verified`, `ensure-two-factor` | 401 o redirect |
| 2 | Controller | `Gate::authorize('ability', model)` | 403 |
| 3 | FormRequest | `authorize()` con Gate o policy | 403 |
| 4 | FormRequest | `rules()` | 422 |
| 5 | Frontend | `useCan('{module}.{resource}.action')` | Solo oculta UI |

Las capas 1 a 4 son enforcement backend. La capa 5 es conveniencia de interfaz y no es frontera de seguridad.

## Policy

```php
<?php

namespace App\Policies;

use App\Models\{Model};
use App\Models\User;

class {Model}Policy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('{module}.{resource}.view');
    }

    public function view(User $user, {Model} $model): bool
    {
        return $user->hasPermissionTo('{module}.{resource}.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('{module}.{resource}.create');
    }

    public function update(User $user, {Model} $model): bool
    {
        return $user->hasPermissionTo('{module}.{resource}.edit');
    }

    public function delete(User $user, {Model} $model): bool
    {
        return $user->hasPermissionTo('{module}.{resource}.delete');
    }
}
```

Reglas:

- Las policies usan permisos, nunca nombres de rol.
- Todos los permisos referenciados por una policy deben estar en el seeder del módulo.
- La convención de permiso es `{module}.{resource}.{action}` en minúsculas.
- Los roles agregan permisos; el código CRUD no pregunta por roles como `admin` o `super-admin`.

## Cobertura Pest esperada

```text
tests/Feature/{Module}/
├── {Model}IndexTest.php
├── {Model}CreateTest.php
├── {Model}UpdateTest.php
├── {Model}DeleteTest.php
└── {Model}AuthorizationTest.php
```

Cobertura mínima:

- usuario autorizado puede ver index y detalle.
- usuario sin permiso recibe 403.
- filtros o búsqueda devuelven resultados esperados.
- store y update validan datos y persisten cambios válidos.
- destroy respeta delete o soft delete según el modelo.
- cada verbo HTTP relevante está cubierto por permiso explícito.
- factories crean los modelos necesarios.
- los tests usan `RefreshDatabase`, `actingAs()` y permisos asignados explicitamente.

## Rendering verification actual

El proyecto usa `tests/Feature/Authorization/ComponentContractTest.php` como proxy pragmático para shared primitives del fundamento CRUD.

Ese test verifica presencia de componentes/hooks, contrato de props compartidas (`auth.permissions`, `flash`) e integridad de build vía `public/build/manifest.json`. No es un harness DOM de React; si se necesita interacción a nivel componente, debe proponerse un cambio posterior para incorporar Vitest o React Testing Library.

## Checklist de seguridad

- Middleware correcto en el route group.
- `Gate::authorize()` presente en cada acción backend.
- FormRequests autorizan antes de validar.
- Policies no contienen nombres de rol.
- Permisos seeded y asignados en tests.
- Acciones UI escondidas con `useCan`, pero respaldadas por backend.
- No hay wildcard permissions como sustituto de permisos explícitos.
