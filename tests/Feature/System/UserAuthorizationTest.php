<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessModulePermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(AccessModulePermissionsSeeder::class);
});

it('blocks unauthenticated user from accessing system.users.index', function () {
    $this->get(route('system.users.index'))
        ->assertRedirect(route('login'));
});

it('blocks unauthenticated user from accessing system.users.create', function () {
    $this->get(route('system.users.create'))
        ->assertRedirect(route('login'));
});

it('blocks unauthenticated user from POST system.users.store', function () {
    $this->post(route('system.users.store'), [])
        ->assertRedirect(route('login'));
});

it('blocks inactive user at Fortify layer — cannot login', function () {
    // Inactive users are blocked at the login level by Fortify authenticateUsing callback.
    $role = Role::factory()->active()->create();
    $role->syncPermissions(['system.users.view']);

    $inactiveUser = User::factory()->inactive()->create();
    $inactiveUser->assignRole($role);

    // Post to login — Fortify should block and return session errors
    $response = $this->post(route('login.store'), [
        'email' => $inactiveUser->email,
        'password' => 'password',
    ]);

    $this->assertGuest();
    $response->assertSessionHasErrors([config('fortify.username')]);
});

it('user with system.users.view but not system.users.create gets 403 on create', function () {
    $viewRole = Role::factory()->active()->create();
    $viewRole->syncPermissions(['system.users.view']);

    $user = User::factory()->create();
    $user->assignRole($viewRole);

    $this->actingAs($user)
        ->get(route('system.users.create'))
        ->assertForbidden();
});

it('user with system.users.view but not system.users.update gets 403 on edit', function () {
    $viewRole = Role::factory()->active()->create();
    $viewRole->syncPermissions(['system.users.view']);

    $user = User::factory()->create();
    $user->assignRole($viewRole);

    $target = User::factory()->create();

    $this->actingAs($user)
        ->get(route('system.users.edit', $target))
        ->assertForbidden();
});

it('user with system.users.view but not system.users.delete gets 403 on destroy', function () {
    // Non-super-admin user with only view permission — no 2FA middleware concern
    $viewRole = Role::factory()->active()->create();
    $viewRole->syncPermissions(['system.users.view']);

    $user = User::factory()->create();
    $user->assignRole($viewRole);

    $target = User::factory()->create();

    $this->actingAs($user)
        ->delete(route('system.users.destroy', $target))
        ->assertForbidden();
});

it('ensure-two-factor middleware protects system routes in non-local env for super-admin without 2FA', function () {
    // In non-local environments, super-admin users without 2FA are redirected by ensure-two-factor.
    $this->app['env'] = 'production';

    // A super-admin WITHOUT 2FA confirmed — factory creates with two_factor_confirmed_at = null
    $user = User::factory()->withSuperAdmin()->create();
    expect($user->two_factor_confirmed_at)->toBeNull();

    $this->actingAs($user)
        ->get(route('system.users.index'))
        ->assertRedirect(); // Redirected to 2FA setup
});
