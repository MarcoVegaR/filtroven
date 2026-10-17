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
    $this->get(route('system.users.index'))
        ->assertRedirect(route('login'));
});

it('returns 403 for user without system.users.view', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('system.users.index'))
        ->assertForbidden();
});

it('allows super-admin to view paginated user list', function () {
    $this->app['env'] = 'local';
    $user = User::factory()->withSuperAdmin()->create();

    User::factory()->count(5)->create();

    $this->actingAs($user)
        ->get(route('system.users.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('system/users/index')
            ->has('users.data')
            ->has('filters')
            ->has('roles')
        );
});

it('returns matching users when searching by name', function () {
    $this->app['env'] = 'local';
    $user = User::factory()->withSuperAdmin()->create();

    User::factory()->create(['name' => 'John Doe', 'email' => 'john@example.com']);
    User::factory()->create(['name' => 'Jane Smith', 'email' => 'jane@example.com']);

    $this->actingAs($user)
        ->get(route('system.users.index', ['search' => 'John']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('system/users/index')
            ->where('users.data', fn ($data) => count($data) === 1
                && str_contains($data[0]['name'], 'John')
            )
        );
});

it('returns matching users when searching by email', function () {
    $this->app['env'] = 'local';
    $user = User::factory()->withSuperAdmin()->create();

    User::factory()->create(['name' => 'Alice', 'email' => 'alice@unique.com']);
    User::factory()->create(['name' => 'Bob', 'email' => 'bob@different.com']);

    $this->actingAs($user)
        ->get(route('system.users.index', ['search' => 'alice@unique']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('system/users/index')
            ->where('users.data', fn ($data) => count($data) === 1
                && str_contains($data[0]['email'], 'alice@unique')
            )
        );
});

it('filters users by role', function () {
    $this->app['env'] = 'local';
    $superAdmin = User::factory()->withSuperAdmin()->create();

    $editorRole = Role::factory()->active()->create(['name' => 'editor-unique-filter']);
    $otherUser = User::factory()->create();
    $otherUser->assignRole($editorRole);

    User::factory()->count(3)->create(); // Users without the editor role

    $this->actingAs($superAdmin)
        ->get(route('system.users.index', ['role' => $editorRole->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('system/users/index')
            ->where('users.data', fn ($data) => count($data) === 1)
        );
});

it('filters users by active status', function () {
    $this->app['env'] = 'local';
    $superAdmin = User::factory()->withSuperAdmin()->create();

    User::factory()->count(3)->active()->create();
    User::factory()->count(2)->inactive()->create();

    $this->actingAs($superAdmin)
        ->get(route('system.users.index', ['status' => 'inactive']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('system/users/index')
            ->where('users.data', fn ($data) => collect($data)->every(fn ($u) => $u['is_active'] === false)
            )
        );
});

it('filters users by inactive status', function () {
    $this->app['env'] = 'local';
    $superAdmin = User::factory()->withSuperAdmin()->create();

    User::factory()->count(3)->active()->create();
    User::factory()->count(2)->inactive()->create();

    $this->actingAs($superAdmin)
        ->get(route('system.users.index', ['status' => 'active']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('system/users/index')
            ->where('users.data', fn ($data) => collect($data)->every(fn ($u) => $u['is_active'] === true)
            )
        );
});

it('supports server-side sorting contract for users', function () {
    $this->app['env'] = 'local';
    $superAdmin = User::factory()->withSuperAdmin()->create();

    User::factory()->create([
        'name' => 'Sort User Charlie',
        'email' => 'charlie@users-sort-contract.test',
        'created_at' => now()->subHours(3),
    ]);
    User::factory()->create([
        'name' => 'Sort User Alpha',
        'email' => 'alpha@users-sort-contract.test',
        'created_at' => now()->subHours(2),
    ]);
    User::factory()->create([
        'name' => 'Sort User Bravo',
        'email' => 'bravo@users-sort-contract.test',
        'created_at' => now()->subHours(1),
    ]);

    $byName = $this->actingAs($superAdmin)
        ->get(route('system.users.index', [
            'search' => 'users-sort-contract.test',
            'sort' => 'name',
            'direction' => 'asc',
        ]))
        ->assertOk()
        ->inertiaProps('users.data');

    expect(array_column($byName, 'name'))->toBe([
        'Sort User Alpha',
        'Sort User Bravo',
        'Sort User Charlie',
    ]);

    $byEmail = $this->actingAs($superAdmin)
        ->get(route('system.users.index', [
            'search' => 'users-sort-contract.test',
            'sort' => 'email',
            'direction' => 'desc',
        ]))
        ->assertOk()
        ->inertiaProps('users.data');

    expect(array_column($byEmail, 'email'))->toBe([
        'charlie@users-sort-contract.test',
        'bravo@users-sort-contract.test',
        'alpha@users-sort-contract.test',
    ]);

    $byCreatedAt = $this->actingAs($superAdmin)
        ->get(route('system.users.index', [
            'search' => 'users-sort-contract.test',
            'sort' => 'created_at',
            'direction' => 'desc',
        ]))
        ->assertOk()
        ->inertiaProps('users.data');

    expect(array_column($byCreatedAt, 'name'))->toBe([
        'Sort User Bravo',
        'Sort User Alpha',
        'Sort User Charlie',
    ]);
});
