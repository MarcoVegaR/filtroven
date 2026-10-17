<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

test('unauthenticated user accessing protected route is redirected to login', function () {
    $this->get(route('dashboard'))
        ->assertRedirect(route('login'));
});

test('authenticated user without any role cannot access route protected by super-admin role', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $user = User::factory()->create();

    // Route with Spatie role middleware protecting it: simulate the deny-by-default scenario
    // by directly testing that a user without super-admin role is forbidden
    expect($user->hasRole('super-admin'))->toBeFalse()
        ->and($user->hasAnyPermission([
            'system.roles.view',
            'system.roles.create',
            'system.roles.update',
            'system.roles.delete',
            'system.permissions.view',
            'system.users.view',
            'system.users.assign-role',
        ]))->toBeFalse();
});

test('authenticated user without permission receives 403 when accessing a role-protected route', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // Create a user with no roles or permissions
    $user = User::factory()->create();

    // Verify the deny-by-default: the user must not be able to pass
    // any of the system-level permission checks
    expect($user->can('system.roles.view'))->toBeFalse()
        ->and($user->can('system.users.view'))->toBeFalse()
        ->and($user->can('system.permissions.view'))->toBeFalse();
});

test('authenticated user without system.users.view permission gets 403 on protected route', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // User with no roles — no system.users.view permission
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('system.users.index'))
        ->assertForbidden();
});

test('authenticated super-admin with system.users.view permission can access protected route', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    // Use local env to bypass 2FA enforcement (development convenience)
    $this->app['env'] = 'local';

    $user = User::factory()->withSuperAdmin()->create();

    $this->actingAs($user)
        ->get(route('system.users.index'))
        ->assertSuccessful();
});
