<?php

use App\Enums\SecurityEventType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SecurityAuditLog;
use App\Models\User;
use Database\Seeders\AccessModulePermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(AccessModulePermissionsSeeder::class);
});

it('returns 403 for user without system.roles.update', function () {
    $user = User::factory()->create();
    $role = Role::factory()->create();

    $this->actingAs($user)
        ->put(route('system.roles.update', $role), [
            'name' => $role->name,
            'display_name' => 'Updated',
            'permissions' => [],
        ])
        ->assertForbidden();
});

it('persists valid updates to a role', function () {
    $user = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    $role = Role::factory()->create(['name' => 'original-role', 'display_name' => 'Original']);

    $this->actingAs($user)
        ->put(route('system.roles.update', $role), [
            'name' => 'original-role',
            'display_name' => 'Updated Display Name',
            'description' => 'Updated description',
            'permissions' => [],
        ])
        ->assertRedirect();

    expect($role->fresh()->display_name)->toBe('Updated Display Name')
        ->and($role->fresh()->description)->toBe('Updated description');
});

it('cannot update super-admin role name', function () {
    $user = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    $superAdminRole = Role::where('name', 'super-admin')->first();

    $this->actingAs($user)
        ->put(route('system.roles.update', $superAdminRole), [
            'name' => 'new-super-admin-name',
            'display_name' => $superAdminRole->display_name,
            'permissions' => [],
        ])
        ->assertSessionHasErrors(['name']);
});

it('syncs permissions correctly on update', function () {
    $user = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    $allPermissions = Permission::all();
    $role = Role::factory()->withPermissions($allPermissions->take(3)->pluck('name')->all())->create();

    expect($role->permissions()->count())->toBe(3);

    $newPermissionIds = $allPermissions->take(5)->pluck('id')->all();

    $this->actingAs($user)
        ->put(route('system.roles.update', $role), [
            'name' => $role->name,
            'display_name' => $role->display_name,
            'permissions' => $newPermissionIds,
        ])
        ->assertRedirect();

    expect($role->fresh()->permissions()->count())->toBe(5);
});

it('removes all permissions when none are submitted', function () {
    $user = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    $allPermissions = Permission::all();
    $role = Role::factory()->withPermissions($allPermissions->take(3)->pluck('name')->all())->create();

    $this->actingAs($user)
        ->put(route('system.roles.update', $role), [
            'name' => $role->name,
            'display_name' => $role->display_name,
            'permissions' => [],
        ])
        ->assertRedirect();

    expect($role->fresh()->permissions()->count())->toBe(0);
});

it('records role_updated security audit event on successful update', function () {
    $user = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    $role = Role::factory()->create(['name' => 'audit-role-update']);
    $permissionIds = Permission::take(2)->pluck('id')->all();

    $this->actingAs($user)
        ->put(route('system.roles.update', $role), [
            'name' => 'audit-role-update',
            'display_name' => 'Audit Updated',
            'description' => 'Updated with audit event',
            'permissions' => $permissionIds,
        ])
        ->assertRedirect();

    expect(SecurityAuditLog::query()
        ->where('event_type', SecurityEventType::RoleUpdated->value)
        ->where('user_id', $user->id)
        ->where('metadata->role_id', $role->id)
        ->exists()
    )->toBeTrue();
});
