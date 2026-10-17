<?php

use App\Ai\Tools\System\Users\CreateUserTool;
use App\Ai\Tools\System\Users\DeactivateUserTool;
use App\Ai\Tools\System\Users\GetUserDetailTool;
use App\Ai\Tools\System\Users\GetUsersMetricsTool;
use App\Ai\Tools\System\Users\SearchUsersTool;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessModulePermissionsSeeder;
use Database\Seeders\AiCopilotPermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Laravel\Ai\Tools\Request as ToolRequest;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(AccessModulePermissionsSeeder::class);
    $this->seed(AiCopilotPermissionsSeeder::class);
});

function actorWithUsersViewPermission(): User
{
    $role = Role::factory()->active()->create();
    $role->syncPermissions(['system.users.view']);

    $actor = User::factory()->create();
    $actor->assignRole($role);

    return $actor;
}

function actorWithCopilotExecutionPermissions(): User
{
    $role = Role::factory()->active()->create();
    $role->syncPermissions([
        'system.users.view',
        'system.users.deactivate',
        'system.users.create',
        'system.users.assign-role',
        'system.users-copilot.execute',
    ]);

    $actor = User::factory()->create();
    $actor->assignRole($role);

    return $actor;
}

it('filters search users results and returns minimized fields only', function () {
    $actor = actorWithUsersViewPermission();
    $role = Role::factory()->active()->create(['display_name' => 'Operaciones']);

    User::factory()->active()->create(['name' => 'Ana Activa']);

    $inactive = User::factory()->inactive()->unverified()->withTwoFactor()->create([
        'name' => 'Irene Inactiva',
        'email' => 'irene@example.com',
    ]);
    $inactive->assignRole($role);

    $result = json_decode((new SearchUsersTool($actor))->handle(new ToolRequest([
        'status' => 'inactive',
        'has_roles' => true,
        'email_verified' => false,
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect($result['count'])->toBe(1)
        ->and($result['users'][0]['name'])->toBe('Irene Inactiva')
        ->and($result['users'][0])->not->toHaveKey('password')
        ->and($result['users'][0])->not->toHaveKey('remember_token')
        ->and($result['users'][0])->not->toHaveKey('two_factor_secret');
});

it('returns an empty collection when the search filters match no users', function () {
    $actor = actorWithUsersViewPermission();

    User::factory()->active()->create(['name' => 'Ana Activa']);

    $result = json_decode((new SearchUsersTool($actor))->handle(new ToolRequest([
        'query' => 'no-existe@example.com',
        'status' => 'inactive',
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect($result['count'])->toBe(0)
        ->and($result['users'])->toBe([]);
});

it('rejects user search without users view permission', function () {
    $actor = User::factory()->create();

    expect(fn () => (new SearchUsersTool($actor))->handle(new ToolRequest([
        'status' => 'active',
    ])))->toThrow(AuthorizationException::class);
});

it('returns a normalized user detail payload with effective access data', function () {
    $actor = actorWithUsersViewPermission();
    $role = Role::factory()->active()->create(['display_name' => 'Soporte']);
    $role->syncPermissions(['system.users.view']);

    $target = User::factory()->withTwoFactor()->create();
    $target->assignRole($role);

    $result = json_decode((new GetUserDetailTool($actor))->handle(new ToolRequest([
        'user_id' => $target->id,
        'include_access' => true,
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect($result['found'])->toBeTrue()
        ->and($result['user']['id'])->toBe($target->id)
        ->and($result['user'])->not->toHaveKey('password')
        ->and($result['roles'])->toHaveCount(1)
        ->and($result['effective_permissions'])->not->toBeEmpty();
});

it('omits access collections when include access is false', function () {
    $actor = actorWithUsersViewPermission();
    $target = User::factory()->withTwoFactor()->create();

    $result = json_decode((new GetUserDetailTool($actor))->handle(new ToolRequest([
        'user_id' => $target->id,
        'include_access' => false,
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect($result['found'])->toBeTrue()
        ->and($result['user']['id'])->toBe($target->id)
        ->and($result)->not->toHaveKey('roles')
        ->and($result)->not->toHaveKey('effective_permissions');
});

it('returns a not found payload for unknown user detail lookups', function () {
    $actor = actorWithUsersViewPermission();

    $result = json_decode((new GetUserDetailTool($actor))->handle(new ToolRequest([
        'user_id' => 999999,
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect($result)->toBe([
        'found' => false,
        'user_id' => 999999,
    ]);
});

it('rejects user detail lookups without users view permission', function () {
    $actor = User::factory()->create();
    $target = User::factory()->create();

    expect(fn () => (new GetUserDetailTool($actor))->handle(new ToolRequest([
        'user_id' => $target->id,
    ])))->toThrow(AuthorizationException::class);
});

it('builds a deactivate proposal without mutating state', function () {
    $actor = actorWithCopilotExecutionPermissions();
    $coverageRole = Role::factory()->active()->create();
    $coverageRole->syncPermissions([
        'system.users.view',
        'system.users.assign-role',
        'system.roles.view',
    ]);

    $coverageUser = User::factory()->active()->create();
    $coverageUser->assignRole($coverageRole);

    $target = User::factory()->active()->create([
        'name' => 'Mario Operador',
        'email' => 'mario@example.com',
    ]);

    $result = json_decode((new DeactivateUserTool($actor))->handle(new ToolRequest([
        'user_id' => $target->id,
    ])), true, flags: JSON_THROW_ON_ERROR);

    $target->refresh();

    expect($result['action']['action_type'])->toBe('deactivate')
        ->and($result['action']['can_execute'])->toBeTrue()
        ->and($result['action']['target']['user_id'])->toBe($target->id)
        ->and($target->is_active)->toBeTrue();
});

it('filters users by role name using the role parameter', function () {
    $actor = actorWithUsersViewPermission();
    $role = Role::factory()->active()->create(['display_name' => 'Auditor']);

    $matchingUser = User::factory()->active()->create(['name' => 'Ana Auditora']);
    $matchingUser->assignRole($role);

    User::factory()->active()->create(['name' => 'Carlos Sin Rol']);

    $result = json_decode((new SearchUsersTool($actor))->handle(new ToolRequest([
        'role' => 'auditor',
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect($result['count'])->toBe(1)
        ->and($result['users'][0]['name'])->toBe('Ana Auditora');
});

it('returns empty results when role filter matches no users', function () {
    $actor = actorWithUsersViewPermission();

    User::factory()->active()->create(['name' => 'Ana Activa']);

    $result = json_decode((new SearchUsersTool($actor))->handle(new ToolRequest([
        'role' => 'rol-inexistente',
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect($result['count'])->toBe(0)
        ->and($result['users'])->toBe([]);
});

it('searches users by name using the query parameter', function () {
    $actor = actorWithUsersViewPermission();

    User::factory()->active()->create(['name' => 'Daniel Query Unique', 'email' => 'daniel@example.com']);
    User::factory()->active()->create(['name' => 'Ana Activa', 'email' => 'ana@example.com']);

    $result = json_decode((new SearchUsersTool($actor))->handle(new ToolRequest([
        'query' => 'Daniel Query Unique',
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect($result['count'])->toBe(1)
        ->and($result['users'][0]['name'])->toBe('Daniel Query Unique');
});

it('returns executable create user proposals without mutating state', function () {
    $actor = actorWithCopilotExecutionPermissions();
    $role = Role::factory()->active()->create([
        'name' => 'support',
        'display_name' => 'Soporte',
    ]);

    $result = json_decode((new CreateUserTool($actor))->handle(new ToolRequest([
        'name' => 'Laura Copilot',
        'email' => 'laura@example.com',
        'roles' => ['Soporte'],
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect($result['action']['action_type'])->toBe('create_user')
        ->and($result['action']['can_execute'])->toBeTrue()
        ->and($result['action']['payload']['roles'])->toBe([$role->id])
        ->and(User::query()->where('email', 'laura@example.com')->exists())->toBeFalse();
});

it('returns deterministic aggregate metrics through the native metrics tool', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('system.users.view');

    $ops = Role::factory()->active()->create(['name' => 'ops', 'display_name' => 'Operaciones']);
    $support = Role::factory()->active()->create(['name' => 'support', 'display_name' => 'Soporte']);

    User::factory()->active()->create()->assignRole($ops);
    User::factory()->inactive()->create()->assignRole($ops);
    User::factory()->active()->unverified()->create()->assignRole($support);
    User::factory()->inactive()->unverified()->create();

    $result = json_decode((new GetUsersMetricsTool($actor))->handle(new ToolRequest([
        'metric' => 'role_distribution',
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect($result)
        ->toMatchArray([
            'capability_key' => 'users.metrics.role_distribution',
            'family' => 'aggregate',
            'outcome' => 'ok',
        ])
        ->and($result['answer_facts']['metric'])
        ->toBe([
            'label' => 'Roles con usuarios asignados',
            'value' => 2,
            'unit' => 'roles',
        ])
        ->and($result['answer_facts']['breakdown'])
        ->toBe([
            ['key' => 'ops', 'label' => 'Operaciones', 'value' => 2],
            ['key' => 'support', 'label' => 'Soporte', 'value' => 1],
        ])
        ->and($result['diagnostics'])
        ->toMatchArray([
            'executor' => 'users_capability_executor',
            'source_of_truth' => 'deterministic_backend',
        ]);
});

it('keeps search tool metadata explicitly list scoped and truncated', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('system.users.view');

    User::factory()->count(9)->create();

    $result = json_decode((new SearchUsersTool($actor))->handle(new ToolRequest([])), true, flags: JSON_THROW_ON_ERROR);

    expect($result)
        ->toMatchArray([
            'count' => 8,
            'visible_count' => 8,
            'matching_count' => 10,
            'truncated' => true,
            'limit' => 8,
            'count_represents' => 'visible_results',
            'list_semantics' => 'search_results_only',
            'aggregate_safe' => false,
        ])
        ->and($result['users'])->toHaveCount(8);
});
