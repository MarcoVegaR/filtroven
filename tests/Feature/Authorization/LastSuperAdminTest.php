<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Validation\ValidationException;

test('isLastSuperAdmin returns false when user does not have super-admin role', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $user = User::factory()->create();

    expect(User::isLastSuperAdmin($user))->toBeFalse();
});

test('isLastSuperAdmin returns true when user is the only super-admin', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $user = User::factory()->withSuperAdmin()->create();

    expect(User::isLastSuperAdmin($user))->toBeTrue();
});

test('isLastSuperAdmin returns false when multiple users have super-admin role', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $user1 = User::factory()->withSuperAdmin()->create();
    $user2 = User::factory()->withSuperAdmin()->create();

    expect(User::isLastSuperAdmin($user1))->toBeFalse()
        ->and(User::isLastSuperAdmin($user2))->toBeFalse();
});

test('removeSuperAdminRole throws ValidationException when removing the last super-admin', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $user = User::factory()->withSuperAdmin()->create();

    expect(fn () => $user->removeSuperAdminRole())
        ->toThrow(ValidationException::class);
});

test('removeSuperAdminRole succeeds when another super-admin exists', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $user1 = User::factory()->withSuperAdmin()->create();
    $user2 = User::factory()->withSuperAdmin()->create();

    // user1 is not the last super-admin, so removal should succeed
    $user1->removeSuperAdminRole();

    expect($user1->hasRole('super-admin'))->toBeFalse()
        ->and($user2->hasRole('super-admin'))->toBeTrue();
});

test('deleting the last super-admin user throws ValidationException', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $user = User::factory()->withSuperAdmin()->create();

    expect(fn () => $user->delete())
        ->toThrow(ValidationException::class);
});

test('deleting a super-admin user when another super-admin exists succeeds', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $user1 = User::factory()->withSuperAdmin()->create();
    $user2 = User::factory()->withSuperAdmin()->create();

    // user1 is not the last super-admin, so deletion should succeed
    $user1->delete();

    expect(User::find($user1->id))->toBeNull()
        ->and($user2->fresh()->hasRole('super-admin'))->toBeTrue();
});
