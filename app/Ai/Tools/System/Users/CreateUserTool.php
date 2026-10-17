<?php

namespace App\Ai\Tools\System\Users;

use App\Ai\Support\CopilotActionProposal;
use App\Ai\Support\CopilotActionType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateUserTool implements Tool
{
    public function __construct(protected User $actor) {}

    public function description(): Stringable|string
    {
        return 'Normaliza una propuesta de creación de usuario sin generar contraseña ni ejecutar cambios.';
    }

    public function handle(Request $request): Stringable|string
    {
        $requiredPermissions = [
            'system.users.create',
            'system.users.assign-role',
            'system.users-copilot.execute',
        ];

        if (! $this->actor->hasPermissionTo('system.users.create') || ! $this->actor->hasPermissionTo('system.users.assign-role')) {
            return json_encode([
                'available' => false,
                'action' => null,
                'message' => __('La creación guiada no está disponible porque te faltan permisos para crear usuarios o asignar roles.'),
                'required_permissions' => $requiredPermissions,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        $matchedRoles = Role::query()
            ->active()
            ->get()
            ->filter(function (Role $role) use ($request): bool {
                $requestedRoleIds = collect($request['roles'] ?? [])
                    ->filter(fn (mixed $value): bool => is_int($value) || (is_string($value) && is_numeric($value)))
                    ->map(fn (int|string $value): int => (int) $value);
                $requestedRoles = collect($request['roles'] ?? [])
                    ->filter(fn ($value) => is_string($value) && $value !== '')
                    ->map(fn (string $value) => mb_strtolower(trim($value)));

                if ($requestedRoles->isEmpty() && $requestedRoleIds->isEmpty()) {
                    return false;
                }

                return $requestedRoleIds->contains((int) $role->id)
                    || $requestedRoles->contains(mb_strtolower($role->name))
                    || ($role->display_name !== null && $requestedRoles->contains(mb_strtolower($role->display_name)));
            })
            ->values();

        $payload = [
            'name' => $request->string('name')->trim()->value() ?: null,
            'email' => $request->string('email')->trim()->lower()->value() ?: null,
            'roles' => $matchedRoles->pluck('id')->all(),
            'role_labels' => $matchedRoles->map(fn (Role $role): string => $role->display_name ?? $role->name)->all(),
        ];

        $missingFields = $this->missingFields($payload);
        $canExecute = $this->actor->hasPermissionTo('system.users-copilot.execute') && $missingFields === [];

        return json_encode([
            'available' => true,
            'action' => CopilotActionProposal::make(
                actionType: CopilotActionType::CreateUser,
                target: CopilotActionProposal::newUserTarget($payload['name'], $payload['email']),
                summary: __('Preparé una propuesta de alta guiada. Revísala y confirma para crear el usuario.'),
                payload: $payload,
                canExecute: $canExecute,
                denyReason: $canExecute ? null : ($missingFields === [] ? __('También necesitas permiso para ejecutar acciones confirmadas del copiloto.') : __('Completa los campos faltantes antes de confirmar el alta.')),
                requiredPermissions: $requiredPermissions,
            ),
            'missing_fields' => $missingFields,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->nullable(),
            'email' => $schema->string()->nullable(),
            'roles' => $schema->array()->items($schema->string())->default([]),
        ];
    }

    /**
     * @param  array{name: ?string, email: ?string, roles: list<int>, role_labels: list<string>}  $payload
     * @return list<string>
     */
    protected function missingFields(array $payload): array
    {
        return Collection::make([
            'name' => $payload['name'],
            'email' => $payload['email'],
            'roles' => $payload['roles'],
        ])->filter(function (mixed $value): bool {
            if (is_array($value)) {
                return $value === [];
            }

            return $value === null || $value === '';
        })->keys()->values()->all();
    }
}
