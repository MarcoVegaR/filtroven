<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\PermissionName;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class AiCopilotPermissionsSeeder extends Seeder
{
    /**
     * @var array<string, string>
     */
    private const PERMISSIONS = [
        'system.users-copilot.view' => 'Ver copiloto de usuarios',
        'system.users-copilot.execute' => 'Ejecutar acciones del copiloto de usuarios',
    ];

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name => $displayName) {
            PermissionName::assertValid($name);

            Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['display_name' => $displayName, 'is_active' => true],
            )->update(['display_name' => $displayName, 'is_active' => true]);
        }

        $superAdmin = Role::query()
            ->where('name', 'super-admin')
            ->where('guard_name', 'web')
            ->first();

        if ($superAdmin !== null) {
            $superAdmin->syncPermissions(Permission::all());
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
