<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessModulePermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Validation\Rules\Password;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(AccessModulePermissionsSeeder::class);

    // Set a password policy for tests
    Password::defaults(fn () => Password::min(8)
        ->letters()
        ->mixedCase()
        ->numbers()
        ->symbols()
    );
});

it('returns 403 for user without system.users.create', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('system.users.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ])
        ->assertForbidden();
});

it('stores a valid user with assigned roles', function () {
    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    $role1 = Role::factory()->active()->create();
    $role2 = Role::factory()->active()->create();

    $this->actingAs($admin)
        ->post(route('system.users.store'), [
            'name' => 'New Test User',
            'email' => 'newuser@example.com',
            'password' => 'Password1!@',
            'password_confirmation' => 'Password1!@',
            'is_active' => true,
            'roles' => [$role1->id, $role2->id],
        ])
        ->assertRedirect();

    $newUser = User::where('email', 'newuser@example.com')->first();
    expect($newUser)->not->toBeNull()
        ->and($newUser->name)->toBe('New Test User')
        ->and($newUser->roles()->count())->toBe(2);
});

it('returns validation error for duplicate email', function () {
    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    User::factory()->create(['email' => 'existing@example.com']);

    $this->actingAs($admin)
        ->post(route('system.users.store'), [
            'name' => 'Another User',
            'email' => 'existing@example.com',
            'password' => 'Password1!@',
            'password_confirmation' => 'Password1!@',
        ])
        ->assertSessionHasErrors(['email']);
});

it('enforces password policy via PasswordValidationRules', function () {
    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    $this->actingAs($admin)
        ->post(route('system.users.store'), [
            'name' => 'Test User',
            'email' => 'testpw@example.com',
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ])
        ->assertSessionHasErrors(['password']);
});

it('returns validation error when assigning an inactive role', function () {
    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    $inactiveRole = Role::factory()->inactive()->create();

    $this->actingAs($admin)
        ->post(route('system.users.store'), [
            'name' => 'Test User',
            'email' => 'testinactive@example.com',
            'password' => 'Password1!@',
            'password_confirmation' => 'Password1!@',
            'roles' => [$inactiveRole->id],
        ])
        ->assertSessionHasErrors(['roles.0']);
});
