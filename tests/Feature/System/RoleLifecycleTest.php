<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessModulePermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(AccessModulePermissionsSeeder::class);
});

it('deactivates a role setting is_active to false', function () {
    $user = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    $role = Role::factory()->active()->create();

    $this->actingAs($user)
        ->patch(route('system.roles.deactivate', $role))
        ->assertRedirect();

    expect($role->fresh()->is_active)->toBeFalse();
});

it('activates a role setting is_active to true', function () {
    $user = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    $role = Role::factory()->inactive()->create();

    $this->actingAs($user)
        ->patch(route('system.roles.activate', $role))
        ->assertRedirect();

    expect($role->fresh()->is_active)->toBeTrue();
});

it('forbids role lifecycle actions without system.roles.deactivate permission', function () {
    $role = Role::factory()->active()->create();

    $operatorRole = Role::factory()->active()->create();
    $operatorRole->syncPermissions(['system.roles.update']);

    $operator = User::factory()->create();
    $operator->assignRole($operatorRole);

    $this->actingAs($operator)
        ->patch(route('system.roles.deactivate', $role))
        ->assertForbidden();

    $this->actingAs($operator)
        ->patch(route('system.roles.activate', $role))
        ->assertForbidden();
});

it('allows role lifecycle actions with system.roles.deactivate permission', function () {
    $role = Role::factory()->active()->create();

    $operatorRole = Role::factory()->active()->create();
    $operatorRole->syncPermissions(['system.roles.deactivate']);

    $operator = User::factory()->create();
    $operator->assignRole($operatorRole);

    $this->actingAs($operator)
        ->patch(route('system.roles.deactivate', $role))
        ->assertRedirect();

    expect($role->fresh()->is_active)->toBeFalse();

    $this->actingAs($operator)
        ->patch(route('system.roles.activate', $role))
        ->assertRedirect();

    expect($role->fresh()->is_active)->toBeTrue();
});

it('[CRITICAL] cannot deactivate the last role with administrative coverage', function () {
    $operatorRole = Role::factory()->active()->create();
    $operatorRole->syncPermissions(['system.roles.deactivate']);

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
        ->patch(route('system.roles.deactivate', $coverageRole));

    $response->assertRedirect()
        ->assertSessionHas('error');

    expect($coverageRole->fresh()->is_active)->toBeTrue();
});

it('[CRITICAL] deactivating a role immediately removes its permissions from user authorization checks', function () {
    // [CRITICAL] Dual Spatie override regression test:
    // After a role is deactivated, getPermissionsViaRoles() and hasPermissionTo() must
    // no longer grant permissions from that role.

    $actor = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    // Create a test role with a specific permission
    $viewPerm = Permission::where('name', 'system.users.view')->first();
    $testRole = Role::factory()->active()->withPermissions([$viewPerm->name])->create();

    // Create a regular user with only the test role
    $targetUser = User::factory()->create();
    $targetUser->assignRole($testRole);

    // Flush cache so override reads fresh state
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $targetUser->unsetRelation('roles');

    expect($targetUser->hasPermissionTo('system.users.view'))->toBeTrue();

    // Deactivate the role via the endpoint
    $this->actingAs($actor)
        ->patch(route('system.roles.deactivate', $testRole))
        ->assertRedirect();

    // Reload the user's roles relationship after cache flush
    $targetUser->unsetRelation('roles');

    // [CRITICAL] The override must exclude inactive role permissions
    expect($targetUser->hasPermissionTo('system.users.view'))->toBeFalse();
});

it('[CRITICAL] super-admin with active role retains ALL permissions after inactive role test', function () {
    // [CRITICAL] Verify that the dual Spatie override does NOT break super-admin
    // when their super-admin role is active — they must retain all permissions.

    $superAdmin = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    // Deactivate an unrelated role (not super-admin)
    $unrelatedRole = Role::factory()->active()->create();
    $unrelatedRole->update(['is_active' => false]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $superAdmin->unsetRelation('roles');

    expect($superAdmin->hasPermissionTo('system.roles.view'))->toBeTrue()
        ->and($superAdmin->hasPermissionTo('system.users.view'))->toBeTrue()
        ->and($superAdmin->hasPermissionTo('system.users.create'))->toBeTrue();
});

it('[CRITICAL] user with ONLY inactive roles has NO effective permissions', function () {
    // [CRITICAL] A user whose every assigned role is inactive must have zero effective permissions.

    $viewPerm = Permission::where('name', 'system.users.view')->first();
    $createPerm = Permission::where('name', 'system.roles.create')->first();

    $roleA = Role::factory()->inactive()->withPermissions([$viewPerm->name])->create();
    $roleB = Role::factory()->inactive()->withPermissions([$createPerm->name])->create();

    $user = User::factory()->create();
    $user->syncRoles([$roleA, $roleB]);

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $user->unsetRelation('roles');

    $effectivePermissions = $user->getPermissionsViaRoles();

    expect($effectivePermissions)->toBeEmpty()
        ->and($user->hasPermissionTo('system.users.view'))->toBeFalse()
        ->and($user->hasPermissionTo('system.roles.create'))->toBeFalse();
});
