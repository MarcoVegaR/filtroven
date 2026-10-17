<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessModulePermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(AccessModulePermissionsSeeder::class);
});

it('blocks unauthenticated user from accessing any system.roles.* routes', function (string $method, string $route, array $params) {
    $role = Role::factory()->create();

    $url = route($route, $params ? array_merge($params, ['role' => $role->id]) : ['role' => $role->id]);

    $this->{$method}($url)
        ->assertRedirect(route('login'));
})->with([
    'index GET' => ['get', 'system.roles.index', []],
    'create GET' => ['get', 'system.roles.create', []],
    'show GET' => ['get', 'system.roles.show', []],
    'edit GET' => ['get', 'system.roles.edit', []],
]);

it('blocks unauthenticated user from mutating system.roles.* routes', function () {
    $this->post(route('system.roles.store'), [])->assertRedirect(route('login'));
});

it('blocks inactive user from accessing system.roles.* routes via Fortify login block', function () {
    $user = User::factory()->inactive()->withSuperAdmin()->create();

    // An inactive user cannot pass actingAs because Fortify blocks them
    // — verify they get redirected from protected routes
    $this->actingAs($user)
        ->get(route('system.roles.index'))
        ->assertRedirect();
});

it('grants system.roles.view access and denies create to user with only view permission', function () {
    // Non-super-admin user with specific role — no 2FA check needed
    $viewRole = Role::factory()->active()->create();
    $viewRole->syncPermissions(['system.roles.view']);

    $user = User::factory()->create();
    $user->assignRole($viewRole);

    $this->actingAs($user)
        ->get(route('system.roles.index'))
        ->assertOk();

    $this->actingAs($user)
        ->get(route('system.roles.create'))
        ->assertForbidden();
});

it('policy check prevents super-admin role deletion even for user with delete permission', function () {
    // User has system.roles.view + system.roles.delete but tries to delete super-admin
    $customRole = Role::factory()->active()->create();
    $customRole->syncPermissions(['system.roles.view', 'system.roles.delete']);

    $user = User::factory()->create();
    $user->assignRole($customRole);

    $superAdminRole = Role::where('name', 'super-admin')->first();

    // The policy's delete() method blocks super-admin deletion regardless of permission
    $this->actingAs($user)
        ->delete(route('system.roles.destroy', $superAdminRole))
        ->assertForbidden();
});
