<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessModulePermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(AccessModulePermissionsSeeder::class);
});

it('redirects unauthenticated users to login', function () {
    $this->get(route('system.roles.index'))
        ->assertRedirect(route('login'));
});

it('returns 403 for authenticated user without system.roles.view', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('system.roles.index'))
        ->assertForbidden();
});

it('allows super-admin to view role list with pagination', function () {
    $this->app['env'] = 'local';
    $user = User::factory()->withSuperAdmin()->create();

    Role::factory()->count(5)->create();

    $this->actingAs($user)
        ->get(route('system.roles.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('system/roles/index')
            ->has('roles.data')
            ->has('filters')
        );
});

it('returns matching roles when searching by name', function () {
    $this->app['env'] = 'local';
    $user = User::factory()->withSuperAdmin()->create();

    Role::factory()->create(['name' => 'editor-role', 'display_name' => 'Editor']);
    Role::factory()->create(['name' => 'viewer-role', 'display_name' => 'Viewer']);
    Role::factory()->create(['name' => 'admin-role', 'display_name' => 'Admin']);

    $this->actingAs($user)
        ->get(route('system.roles.index', ['search' => 'editor']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('system/roles/index')
            ->where('roles.data', fn ($data) => count($data) === 1
                && str_contains($data[0]['name'], 'editor')
            )
        );
});

it('returns matching roles when searching by display_name', function () {
    $this->app['env'] = 'local';
    $user = User::factory()->withSuperAdmin()->create();

    Role::factory()->create(['name' => 'role-xyz', 'display_name' => 'Coordinador']);
    Role::factory()->create(['name' => 'role-abc', 'display_name' => 'Analista']);

    $this->actingAs($user)
        ->get(route('system.roles.index', ['search' => 'Coordinador']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('system/roles/index')
            ->where('roles.data', fn ($data) => count($data) === 1
                && $data[0]['display_name'] === 'Coordinador'
            )
        );
});

it('filters roles by active status', function () {
    $this->app['env'] = 'local';
    $user = User::factory()->withSuperAdmin()->create();

    Role::factory()->count(3)->active()->create();
    Role::factory()->count(2)->inactive()->create();

    $this->actingAs($user)
        ->get(route('system.roles.index', ['status' => 'active']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('system/roles/index')
            ->where('roles.data', fn ($data) => collect($data)->every(fn ($r) => $r['is_active'] === true)
            )
        );
});

it('filters roles by inactive status', function () {
    $this->app['env'] = 'local';
    $user = User::factory()->withSuperAdmin()->create();

    Role::factory()->count(3)->active()->create();
    Role::factory()->count(2)->inactive()->create();

    $this->actingAs($user)
        ->get(route('system.roles.index', ['status' => 'inactive']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('system/roles/index')
            ->where('roles.data', fn ($data) => collect($data)->every(fn ($r) => $r['is_active'] === false)
            )
        );
});

it('supports server-side sorting contract for roles', function () {
    $this->app['env'] = 'local';
    $user = User::factory()->withSuperAdmin()->create();

    $alphaRole = Role::factory()->create([
        'name' => 'sort-contract-role-alpha',
        'display_name' => 'Sort Alpha',
        'created_at' => now()->subHours(3),
    ]);
    $betaRole = Role::factory()->create([
        'name' => 'sort-contract-role-beta',
        'display_name' => 'Sort Beta',
        'created_at' => now()->subHours(2),
    ]);
    $gammaRole = Role::factory()->create([
        'name' => 'sort-contract-role-gamma',
        'display_name' => 'Sort Gamma',
        'created_at' => now()->subHours(1),
    ]);

    $alphaRole->syncPermissions(['system.users.view']);
    $betaRole->syncPermissions([
        'system.users.view',
        'system.roles.view',
        'system.permissions.view',
    ]);

    User::factory()->count(2)->create()->each(
        fn (User $member) => $member->assignRole($alphaRole)
    );
    User::factory()->create()->assignRole($betaRole);

    $byName = $this->actingAs($user)
        ->get(route('system.roles.index', [
            'search' => 'sort-contract-role-',
            'sort' => 'name',
            'direction' => 'asc',
        ]))
        ->assertOk()
        ->inertiaProps('roles.data');

    expect(array_column($byName, 'name'))->toBe([
        'sort-contract-role-alpha',
        'sort-contract-role-beta',
        'sort-contract-role-gamma',
    ]);

    $byUsersCount = $this->actingAs($user)
        ->get(route('system.roles.index', [
            'search' => 'sort-contract-role-',
            'sort' => 'users_count',
            'direction' => 'desc',
        ]))
        ->assertOk()
        ->inertiaProps('roles.data');

    expect(array_column($byUsersCount, 'name'))->toBe([
        'sort-contract-role-alpha',
        'sort-contract-role-beta',
        'sort-contract-role-gamma',
    ]);

    $byPermissionsCount = $this->actingAs($user)
        ->get(route('system.roles.index', [
            'search' => 'sort-contract-role-',
            'sort' => 'permissions_count',
            'direction' => 'desc',
        ]))
        ->assertOk()
        ->inertiaProps('roles.data');

    expect(array_column($byPermissionsCount, 'name'))->toBe([
        'sort-contract-role-beta',
        'sort-contract-role-alpha',
        'sort-contract-role-gamma',
    ]);

    $byCreatedAt = $this->actingAs($user)
        ->get(route('system.roles.index', [
            'search' => 'sort-contract-role-',
            'sort' => 'created_at',
            'direction' => 'desc',
        ]))
        ->assertOk()
        ->inertiaProps('roles.data');

    expect(array_column($byCreatedAt, 'name'))->toBe([
        'sort-contract-role-gamma',
        'sort-contract-role-beta',
        'sort-contract-role-alpha',
    ]);
});
