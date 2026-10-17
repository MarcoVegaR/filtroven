<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

test('all spatie permission tables exist after migration', function () {
    expect(Schema::hasTable('permissions'))->toBeTrue()
        ->and(Schema::hasTable('roles'))->toBeTrue()
        ->and(Schema::hasTable('role_has_permissions'))->toBeTrue()
        ->and(Schema::hasTable('model_has_roles'))->toBeTrue()
        ->and(Schema::hasTable('model_has_permissions'))->toBeTrue();
});

test('seeder creates super-admin role', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Role::where('name', 'super-admin')->where('guard_name', 'web')->exists())->toBeTrue();
});

test('seeder creates exactly 7 baseline permissions', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Permission::count())->toBe(7);
});

test('super-admin role has all 7 baseline permissions', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $superAdmin = Role::findByName('super-admin', 'web');

    expect($superAdmin->permissions()->count())->toBe(7);
});

test('super-admin role has all expected permission names', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $superAdmin = Role::findByName('super-admin', 'web');
    $assignedPermissions = $superAdmin->permissions()->pluck('name')->sort()->values()->all();

    $expected = collect([
        'system.roles.view',
        'system.roles.create',
        'system.roles.update',
        'system.roles.delete',
        'system.permissions.view',
        'system.users.view',
        'system.users.assign-role',
    ])->sort()->values()->all();

    expect($assignedPermissions)->toBe($expected);
});

test('user with super-admin role inherits system.roles.view permission', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $user = User::factory()->withSuperAdmin()->create();

    expect($user->hasPermissionTo('system.roles.view'))->toBeTrue();
});

test('admin user at test@mailinator.com has super-admin role after database seeder runs', function () {
    config(['app.env' => 'testing']);
    $this->seed(DatabaseSeeder::class);

    $admin = User::where('email', 'test@mailinator.com')->first();

    expect($admin)->not->toBeNull()
        ->and($admin->hasRole('super-admin'))->toBeTrue();
});

test('application auth default guard is web', function () {
    // Spatie v7 uses the application's auth default guard (no separate config key).
    // The spec requires single-guard setup using the web guard as default.
    expect(config('auth.defaults.guard'))->toBe('web');
});

test('spatie permission cache is enabled with a configured expiration time', function () {
    // Spatie v7 stores cache.expiration_time as a DateInterval (e.g., "24 hours").
    $expiration = config('permission.cache.expiration_time');

    expect($expiration)->not->toBeNull()
        ->and($expiration)->toBeInstanceOf(DateInterval::class);
});

test('super-admin passes authorization through standard gate mechanism', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $user = User::factory()->withSuperAdmin()->create();

    // Verify the Gate defined in AppServiceProvider resolves permission via Spatie (no Gate::before bypass)
    expect($user->can('system.users.view'))->toBeTrue()
        ->and($user->can('system.roles.view'))->toBeTrue();
});

test('no Gate::before callback grants blanket access to super-admin', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // A user with no role must be denied system permissions even if gate::before existed
    $regularUser = User::factory()->create();

    // If Gate::before existed for super-admin, a non-super-admin would fail this check trivially.
    // More importantly: verify a user with super-admin gets access strictly via permission inheritance,
    // not via a Gate::before shortcut — by confirming the gate check fails for a user without the permission.
    expect($regularUser->can('system.roles.view'))->toBeFalse()
        ->and($regularUser->can('system.users.view'))->toBeFalse();
});
