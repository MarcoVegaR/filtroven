<?php

namespace App\Ai\Tools\System\Users;

use App\Ai\Support\UsersCopilotDomainLexicon;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchUsersTool implements Tool
{
    protected const RESULT_LIMIT = 8;

    public function __construct(protected User $actor) {}

    public function description(): Stringable|string
    {
        return 'Busca usuarios del sistema con filtros acotados y devuelve un resumen normalizado.';
    }

    public function handle(Request $request): Stringable|string
    {
        if (! $this->actor->can('viewAny', User::class)) {
            throw new AuthorizationException(__('No tienes permiso para consultar usuarios.'));
        }

        $query = User::query()
            ->with('roles')
            ->withCount('roles')
            ->when($request->string('query')->trim()->value(), function (Builder $builder, string $search): void {
                $term = '%'.mb_strtolower($search).'%';
                $useUnaccent = DB::connection()->getDriverName() === 'pgsql';
                $wrap = fn (string $column): string => $useUnaccent
                    ? "unaccent(LOWER({$column})) LIKE unaccent(?)"
                    : "LOWER({$column}) LIKE ?";

                $builder->where(fn (Builder $nested) => $nested
                    ->whereRaw($wrap('name'), [$term])
                    ->orWhereRaw($wrap('email'), [$term])
                );
            })
            ->when($request->string('status')->value(), fn (Builder $builder, string $status) => match ($status) {
                'active' => $builder->where('is_active', true),
                'inactive' => $builder->where('is_active', false),
                default => $builder,
            })
            ->when($request->has('email_verified'), fn (Builder $builder) => $request->boolean('email_verified')
                ? $builder->whereNotNull('email_verified_at')
                : $builder->whereNull('email_verified_at'))
            ->when($request->has('two_factor_enabled'), fn (Builder $builder) => $request->boolean('two_factor_enabled')
                ? $builder->whereNotNull('two_factor_confirmed_at')
                : $builder->whereNull('two_factor_confirmed_at'))
            ->when($request->has('has_roles'), fn (Builder $builder) => $request->boolean('has_roles')
                ? $builder->whereHas('roles')
                : $builder->whereDoesntHave('roles'))
            ->when($request->string('role')->trim()->value(), function (Builder $builder, string $role): void {
                $terms = UsersCopilotDomainLexicon::roleSearchTerms($role);
                $useUnaccent = DB::connection()->getDriverName() === 'pgsql';
                $wrap = fn (string $column): string => $useUnaccent
                    ? "unaccent(LOWER({$column})) LIKE unaccent(?)"
                    : "LOWER({$column}) LIKE ?";

                $builder->whereHas('roles', function (Builder $nested) use ($terms, $wrap): void {
                    $nested->where(function (Builder $roleQuery) use ($terms, $wrap): void {
                        foreach ($terms as $term) {
                            if (in_array($term, ['admin', 'super-admin'], true)) {
                                $roleQuery->orWhereRaw('LOWER(display_name) = ?', [mb_strtolower($term)])
                                    ->orWhereRaw('LOWER(name) = ?', [mb_strtolower($term)]);

                                continue;
                            }

                            $like = '%'.mb_strtolower($term).'%';

                            $roleQuery->orWhereRaw($wrap('display_name'), [$like])
                                ->orWhereRaw($wrap('name'), [$like]);
                        }
                    });
                });
            })
            ->when($request->string('access_profile')->trim()->value(), function (Builder $builder, string $profile): void {
                match ($profile) {
                    'administrative_access' => $builder->administrativeAccess(),
                    'super_admin_role' => $builder->withSuperAdminRole(),
                    default => $builder,
                };
            })
            ->when($request->string('permission')->trim()->value(), function (Builder $builder, string $permission): void {
                $builder->whereHas('roles', fn (Builder $roleQuery) => $roleQuery
                    ->where('is_active', true)
                    ->whereHas('permissions', fn (Builder $permissionQuery) => $permissionQuery->where('name', $permission))
                );
            })
            ->orderByDesc('created_at');

        $matchingCount = (clone $query)->count();
        $results = $query
            ->limit(self::RESULT_LIMIT)
            ->get();

        $visibleCount = $results->count();

        return json_encode([
            'count' => $visibleCount,
            'visible_count' => $visibleCount,
            'matching_count' => $matchingCount,
            'truncated' => $matchingCount > $visibleCount,
            'limit' => self::RESULT_LIMIT,
            'count_represents' => 'visible_results',
            'list_semantics' => 'search_results_only',
            'aggregate_safe' => false,
            'access_profile' => $request->string('access_profile')->trim()->value() ?: null,
            'permission' => $request->string('permission')->trim()->value() ?: null,
            'two_factor_enabled' => $request->has('two_factor_enabled') ? $request->boolean('two_factor_enabled') : null,
            'users' => $results->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'email_verified' => $user->email_verified_at !== null,
                'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
                'roles_count' => $user->roles_count,
                'roles' => $user->roles
                    ->map(fn ($role): array => [
                        'id' => $role->id,
                        'name' => $role->name,
                        'display_name' => $role->display_name,
                        'is_active' => $role->is_active,
                    ])
                    ->values()
                    ->all(),
                'created_at' => optional($user->created_at)?->toIso8601String(),
                'show_href' => route('system.users.show', ['user' => $user->id], absolute: false),
            ])->values()->all(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->nullable(),
            'status' => $schema->string()->enum(['active', 'inactive', 'all'])->default('all'),
            'email_verified' => $schema->boolean()->nullable(),
            'two_factor_enabled' => $schema->boolean()->nullable(),
            'has_roles' => $schema->boolean()->nullable(),
            'role' => $schema->string()->nullable(),
            'access_profile' => $schema->string()->enum(['administrative_access', 'super_admin_role'])->nullable(),
            'permission' => $schema->string()->nullable(),
        ];
    }
}
