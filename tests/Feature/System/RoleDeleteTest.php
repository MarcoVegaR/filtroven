<?php

use App\Enums\SecurityEventType;
use App\Models\Role;
use App\Models\SecurityAuditLog;
use App\Models\User;
use Database\Seeders\AccessModulePermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(AccessModulePermissionsSeeder::class);
});

it('returns 403 for user without system.roles.delete', function () {
    $user = User::factory()->create();
    $role = Role::factory()->create();

    $this->actingAs($user)
        ->delete(route('system.roles.destroy', $role))
        ->assertForbidden();
});

it('deletes a role with no assigned users', function () {
    $user = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    $role = Role::factory()->create();

    expect(Role::where('id', $role->id)->exists())->toBeTrue();

    $this->actingAs($user)
        ->delete(route('system.roles.destroy', $role))
        ->assertRedirect(route('system.roles.index'));

    expect(Role::where('id', $role->id)->exists())->toBeFalse();
});

it('cannot delete a role that has assigned users', function () {
    $user = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    $role = Role::factory()->create();

    // Assign some users to the role
    User::factory()->count(2)->create()->each(fn (User $u) => $u->assignRole($role));

    $this->actingAs($user)
        ->delete(route('system.roles.destroy', $role))
        ->assertRedirect();

    // Role must still exist
    expect(Role::where('id', $role->id)->exists())->toBeTrue();
});

it('cannot delete the super-admin role', function () {
    $user = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    $superAdminRole = Role::where('name', 'super-admin')->first();

    $this->actingAs($user)
        ->delete(route('system.roles.destroy', $superAdminRole))
        ->assertForbidden();

    // Role must still exist
    expect(Role::where('name', 'super-admin')->exists())->toBeTrue();
});

it('[CRITICAL] cannot delete the last role with administrative coverage', function () {
    $operatorRole = Role::factory()->active()->create();
    $operatorRole->syncPermissions(['system.roles.delete']);

    $operator = User::factory()->create();
    $operator->assignRole($operatorRole);

    $superAdminRole = Role::where('name', 'super-admin')->firstOrFail();
    $superAdminRole->syncPermissions([]);

    $coverageRole = Role::factory()->active()->create();
    $coverageRole->syncPermissions([
        'system.users.view',
        'system.users.assign-role',
        'system.roles.view',
    ]);

    $response = $this->actingAs($operator)
        ->delete(route('system.roles.destroy', $coverageRole));

    $response->assertRedirect()
        ->assertSessionHas('error');

    expect(Role::whereKey($coverageRole->id)->exists())->toBeTrue();
});

it('records role_deleted security audit event on successful delete', function () {
    $user = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    $role = Role::factory()->create();

    $this->actingAs($user)
        ->delete(route('system.roles.destroy', $role))
        ->assertRedirect(route('system.roles.index'));

    expect(SecurityAuditLog::query()
        ->where('event_type', SecurityEventType::RoleDeleted->value)
        ->where('user_id', $user->id)
        ->where('metadata->role_id', $role->id)
        ->exists()
    )->toBeTrue();
});
