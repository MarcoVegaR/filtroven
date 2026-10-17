<?php

namespace App\Ai\Tools\System\Users;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetUserDetailTool implements Tool
{
    public function __construct(protected User $actor) {}

    public function description(): Stringable|string
    {
        return 'Obtiene el detalle resumido de un usuario, incluidos roles y permisos efectivos cuando se solicitan.';
    }

    public function handle(Request $request): Stringable|string
    {
        $user = User::query()->with(['roles.permissions'])->find($request->integer('user_id'));

        if ($user === null) {
            return json_encode([
                'found' => false,
                'user_id' => $request->integer('user_id'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        if (! $this->actor->can('view', $user)) {
            throw new AuthorizationException(__('No tienes permiso para ver este usuario.'));
        }

        $includeAccess = $request->has('include_access') ? $request->boolean('include_access') : true;

        $payload = [
            'found' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'email_verified' => $user->email_verified_at !== null,
                'email_verified_at' => optional($user->email_verified_at)?->toIso8601String(),
                'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
                'two_factor_confirmed_at' => optional($user->two_factor_confirmed_at)?->toIso8601String(),
                'created_at' => optional($user->created_at)?->toIso8601String(),
                'updated_at' => optional($user->updated_at)?->toIso8601String(),
                'references' => [
                    [
                        'label' => __('Abrir perfil de usuario'),
                        'href' => route('system.users.show', ['user' => $user->id], absolute: false),
                    ],
                ],
            ],
        ];

        if ($includeAccess) {
            $payload['roles'] = $user->roles
                ->map(fn ($role): array => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'display_name' => $role->display_name,
                    'is_active' => $role->is_active,
                ])
                ->values()
                ->all();

            $payload['effective_permissions'] = $user->getAllPermissions()
                ->map(fn (Permission $permission): array => [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'display_name' => $permission->display_name,
                    'group_key' => $permission->groupKey(),
                    'roles' => $user->roles
                        ->filter(fn ($role) => $role->is_active && $role->hasPermissionTo($permission))
                        ->map(fn ($role): array => [
                            'name' => $role->name,
                            'display_name' => $role->display_name,
                        ])
                        ->values()
                        ->all(),
                ])
                ->groupBy('group_key')
                ->map(fn ($permissions) => $permissions->values()->all())
                ->all();
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'user_id' => $schema->integer()->required(),
            'include_access' => $schema->boolean()->default(true),
        ];
    }
}
