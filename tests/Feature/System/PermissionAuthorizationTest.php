<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessModulePermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(AccessModulePermissionsSeeder::class);
});

it('forbids permissions catalog access without system.permissions.view permission', function () {
    $role = Role::factory()->active()->create();
    $role->syncPermissions(['system.roles.view']);

    $user = User::factory()->create();
    $user->assignRole($role);

    $permission = Permission::query()->firstOrFail();

    $this->actingAs($user)
        ->get(route('system.permissions.index'))
        ->assertForbidden();

    $this->actingAs($user)
        ->get(route('system.permissions.show', $permission))
        ->assertForbidden();
});

it('allows permissions catalog access with system.permissions.view permission', function () {
    $role = Role::factory()->active()->create();
    $role->syncPermissions(['system.permissions.view']);

    $user = User::factory()->create();
    $user->assignRole($role);

    $permission = Permission::query()->firstOrFail();

    $this->actingAs($user)
        ->get(route('system.permissions.index'))
        ->assertOk();

    $this->actingAs($user)
        ->get(route('system.permissions.show', $permission))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('system/permissions/show')
            ->has('permission')
        );
});
