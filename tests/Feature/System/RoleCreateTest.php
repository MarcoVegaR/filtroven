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

it('returns 403 on GET create for user without system.roles.create', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('system.roles.create'))
        ->assertForbidden();
});

it('returns 403 on POST store for user without system.roles.create', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('system.roles.store'), [
            'name' => 'new-role',
            'display_name' => 'New Role',
        ])
        ->assertForbidden();
});

it('stores a valid role with permissions', function () {
    // withTwoFactor() allows env=testing to pass ensure-two-factor middleware
    $user = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    $permissions = Permission::take(3)->pluck('id')->all();

    $this->actingAs($user)
        ->post(route('system.roles.store'), [
            'name' => 'test-editor',
            'display_name' => 'Test Editor',
            'description' => 'A test editor role',
            'permissions' => $permissions,
        ])
        ->assertRedirect();

    $role = Role::where('name', 'test-editor')->first();
    expect($role)->not->toBeNull()
        ->and($role->display_name)->toBe('Test Editor')
        ->and($role->permissions()->count())->toBe(3);
});

it('returns validation error for duplicate role name', function () {
    $user = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    Role::factory()->create(['name' => 'duplicate-role']);

    $permissions = Permission::take(1)->pluck('id')->all();

    $this->actingAs($user)
        ->post(route('system.roles.store'), [
            'name' => 'duplicate-role',
            'display_name' => 'Duplicate',
            'permissions' => $permissions,
        ])
        ->assertSessionHasErrors(['name']);
});

it('returns validation error for empty name', function () {
    $user = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    $permissions = Permission::take(1)->pluck('id')->all();

    $this->actingAs($user)
        ->post(route('system.roles.store'), [
            'name' => '',
            'display_name' => 'Test',
            'permissions' => $permissions,
        ])
        ->assertSessionHasErrors(['name']);
});

it('returns validation error when no permissions provided', function () {
    $user = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    $this->actingAs($user)
        ->post(route('system.roles.store'), [
            'name' => 'new-role-name',
            'display_name' => 'New Role',
            'permissions' => [],
        ])
        ->assertSessionHasErrors(['permissions']);
});

it('records role_created security audit event on successful create', function () {
    $user = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    $permissions = Permission::take(2)->pluck('id')->all();

    $this->actingAs($user)
        ->post(route('system.roles.store'), [
            'name' => 'role-with-audit',
            'display_name' => 'Role With Audit',
            'permissions' => $permissions,
        ])
        ->assertRedirect();

    $role = Role::where('name', 'role-with-audit')->firstOrFail();

    expect(SecurityAuditLog::query()
        ->where('event_type', SecurityEventType::RoleCreated->value)
        ->where('user_id', $user->id)
        ->where('metadata->role_id', $role->id)
        ->exists()
    )->toBeTrue();
});
