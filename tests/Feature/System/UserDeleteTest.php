<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessModulePermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(AccessModulePermissionsSeeder::class);
});

it('returns 403 for user without system.users.delete', function () {
    $actor = User::factory()->create();
    $target = User::factory()->create();

    $this->actingAs($actor)
        ->delete(route('system.users.destroy', $target))
        ->assertForbidden();
});

it('[CRITICAL] cannot delete self', function () {
    // [CRITICAL] Self-deletion must be blocked — UserPolicy::delete() returns false for self.
    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    // Super-admin can delete others, but not themselves
    // The UserPolicy::delete() already blocks self: if ($user->id === $model->id) return false
    $this->actingAs($admin)
        ->delete(route('system.users.destroy', $admin))
        ->assertForbidden(); // Policy blocks self-delete

    expect(User::where('id', $admin->id)->exists())->toBeTrue();
});

it('[CRITICAL] cannot delete last effective admin', function () {
    // [CRITICAL] Deleting the last effective admin must be blocked.
    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    // Create a second user to act as the actor (non-super-admin, has delete permission)
    $actor = User::factory()->create();
    $actorRole = Role::factory()->active()->create();
    $actorRole->syncPermissions(['system.users.delete', 'system.users.view']);
    $actor->assignRole($actorRole);

    // admin is the last effective admin (super-admin, only one)
    $this->actingAs($actor)
        ->delete(route('system.users.destroy', $admin))
        ->assertRedirect();

    // Admin must still exist (last effective admin protection)
    expect(User::where('id', $admin->id)->exists())->toBeTrue();
});

it('deletes a non-admin user successfully', function () {
    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    // Create a second super-admin so the first one is NOT the last effective admin
    User::factory()->withSuperAdmin()->withTwoFactor()->create();

    $target = User::factory()->create(); // No admin role

    $this->actingAs($admin)
        ->delete(route('system.users.destroy', $target))
        ->assertRedirect(route('system.users.index'));

    expect(User::where('id', $target->id)->exists())->toBeFalse();
});
