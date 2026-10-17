<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessModulePermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(AccessModulePermissionsSeeder::class);
});

it('returns 403 for user without system.users.update', function () {
    $actor = User::factory()->create();
    $target = User::factory()->create();

    $this->actingAs($actor)
        ->put(route('system.users.update', $target), [
            'name' => 'Updated Name',
            'email' => $target->email,
        ])
        ->assertForbidden();
});

it('persists name and email updates', function () {
    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();
    $target = User::factory()->create(['name' => 'Old Name', 'email' => 'old@example.com']);

    $this->actingAs($admin)
        ->put(route('system.users.update', $target), [
            'name' => 'New Name',
            'email' => 'new@example.com',
        ])
        ->assertRedirect();

    expect($target->fresh()->name)->toBe('New Name')
        ->and($target->fresh()->email)->toBe('new@example.com');
});

it('updates role assignments when roles provided', function () {
    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    $roleA = Role::factory()->active()->create();
    $roleB = Role::factory()->active()->create();

    $target = User::factory()->create();
    $target->assignRole($roleA);

    expect($target->roles()->count())->toBe(1);

    $this->actingAs($admin)
        ->put(route('system.users.update', $target), [
            'name' => $target->name,
            'email' => $target->email,
            'roles' => [$roleB->id],
        ])
        ->assertRedirect();

    $target->refresh();
    expect($target->roles()->count())->toBe(1)
        ->and($target->hasRole($roleB))->toBeTrue()
        ->and($target->hasRole($roleA))->toBeFalse();
});

it('can update own user via admin controller — self-update is allowed', function () {
    // The UserPolicy update does NOT block self, so self-update works
    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    $this->actingAs($admin)
        ->put(route('system.users.update', $admin), [
            'name' => 'Self Updated',
            'email' => $admin->email,
        ])
        ->assertRedirect();

    expect($admin->fresh()->name)->toBe('Self Updated');
});
