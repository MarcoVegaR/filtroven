<?php

use App\Models\Role;
use App\Models\User;
use App\Support\PermissionName;
use Database\Seeders\AccessModulePermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

test('baseline permissions satisfy the frozen naming convention', function () {
    foreach (RolesAndPermissionsSeeder::baselinePermissions() as $permission) {
        expect(PermissionName::isValid($permission))->toBeTrue();
    }
});

test('invalid permission names are rejected by the frozen naming convention', function (string $permission) {
    expect(fn () => PermissionName::assertValid($permission))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'uppercase segments' => 'System.Roles.View',
    'spaces' => 'system roles view',
    'ambiguous format' => 'manage-users',
]);

test('policy-backed role sync endpoint denies underprivileged authenticated users', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(AccessModulePermissionsSeeder::class);

    $actor = User::factory()->create();
    $target = User::factory()->create();

    $superAdminRole = Role::where('name', 'super-admin')->first();

    $this->actingAs($actor)
        ->put(route('system.users.roles.sync', $target), ['roles' => [$superAdminRole->id]])
        ->assertForbidden();

    expect($target->fresh()->hasRole('super-admin'))->toBeFalse();
});

test('form request authorize allows super-admin to sync roles through the policy path', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(AccessModulePermissionsSeeder::class);

    $actor = User::factory()->withSuperAdmin()->withTwoFactor()->create();
    $target = User::factory()->create();

    $superAdminRole = Role::where('name', 'super-admin')->first();

    $this->actingAs($actor)
        ->put(route('system.users.roles.sync', $target), ['roles' => [$superAdminRole->id]])
        ->assertRedirect();

    expect($target->fresh()->hasRole('super-admin'))->toBeTrue();
});

test('api-style unauthorized authorization failure returns json 403 instead of the inertia error page', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(AccessModulePermissionsSeeder::class);
    $this->app['env'] = 'production';

    $actor = User::factory()->create();
    $target = User::factory()->create();
    $token = 'test-csrf-token';

    $superAdminRole = Role::where('name', 'super-admin')->first();

    $this->actingAs($actor)
        ->withSession(['_token' => $token])
        ->putJson(route('system.users.roles.sync', $target), ['roles' => [$superAdminRole->id]], ['X-CSRF-TOKEN' => $token])
        ->assertForbidden()
        ->assertJsonStructure(['message']);

    expect($target->fresh()->hasRole('super-admin'))->toBeFalse();
});
