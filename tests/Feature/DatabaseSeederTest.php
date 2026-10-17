<?php

use App\Models\User;
use Database\Seeders\AccessModulePermissionsSeeder;
use Database\Seeders\AiCopilotPermissionsSeeder;
use Database\Seeders\AuditModulePermissionsSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

test('database seeder creates and updates a single local administrator', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    $admin = User::query()->where('email', 'test@mailinator.com')->sole();

    expect(User::query()->where('email', 'test@mailinator.com')->count())->toBe(1)
        ->and($admin->name)->toBe('Administrador')
        ->and($admin->email_verified_at)->not->toBeNull()
        ->and(Hash::check('12345678', $admin->password))->toBeTrue();
});

test('database seeder keeps deterministic administrator scoped to development environments', function () {
    config(['app.env' => 'production']);

    $this->seed(DatabaseSeeder::class);

    expect(User::query()->where('email', 'test@mailinator.com')->count())->toBe(0);
});

test('database seeder creates expected super-admin role and 18 permissions', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Role::where('name', 'super-admin')->exists())->toBeTrue()
        ->and(Permission::count())->toBe(18);
});

test('repeated database seeder run preserves exactly 1 role and 18 permissions without duplicates', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(Role::where('name', 'super-admin')->count())->toBe(1)
        ->and(Permission::count())->toBe(18);
});

test('database seeder keeps the core call order including the audit module seeder', function () {
    config(['app.env' => 'production']);

    $seeder = new class extends DatabaseSeeder
    {
        /**
         * @var array<int, string>
         */
        public array $calls = [];

        public function call($class, $silent = false, array $parameters = [])
        {
            $this->calls[] = (string) $class;

            return $this;
        }
    };

    $seeder->run();

    expect($seeder->calls)->toBe([
        RolesAndPermissionsSeeder::class,
        AccessModulePermissionsSeeder::class,
        AuditModulePermissionsSeeder::class,
        AiCopilotPermissionsSeeder::class,
    ]);
});
