<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\PermissionName;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class AccessModulePermissionsSeeder extends Seeder
{
    /**
     * New access module permissions not in the baseline seeder.
     * These extend the frozen PRD-02 baseline with PRD-05 CRUD capabilities.
     *
     * @var array<string, string>
     */
    private const NEW_PERMISSIONS = [
        'system.roles.deactivate' => 'Desactivar roles',
        'system.users.create' => 'Crear usuarios',
        'system.users.update' => 'Actualizar usuarios',
        'system.users.delete' => 'Eliminar usuarios',
        'system.users.deactivate' => 'Desactivar usuarios',
        'system.users.send-reset' => 'Enviar restablecimiento de contraseña',
        'system.users.export' => 'Exportar usuarios',
    ];

    /**
     * Display names to backfill on the existing baseline permissions.
     *
     * @var array<string, string>
     */
    private const BASELINE_DISPLAY_NAMES = [
        'system.roles.view' => 'Ver roles',
        'system.roles.create' => 'Crear roles',
        'system.roles.update' => 'Actualizar roles',
        'system.roles.delete' => 'Eliminar roles',
        'system.permissions.view' => 'Ver permisos',
        'system.users.view' => 'Ver usuarios',
        'system.users.assign-role' => 'Asignar roles a usuarios',
    ];

    /**
     * Run the database seeds.
     * This seeder is idempotent — safe to run multiple times without creating duplicates.
     * Must run AFTER RolesAndPermissionsSeeder.
     */
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Backfill display names on existing baseline permissions
        foreach (self::BASELINE_DISPLAY_NAMES as $name => $displayName) {
            PermissionName::assertValid($name);

            Permission::where('name', $name)->where('guard_name', 'web')
                ->update(['display_name' => $displayName, 'is_active' => true]);
        }

        // Create new access module permissions
        foreach (self::NEW_PERMISSIONS as $name => $displayName) {
            PermissionName::assertValid($name);

            Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['display_name' => $displayName, 'is_active' => true],
            )->update(['display_name' => $displayName, 'is_active' => true]);
        }

        // Sync ALL permissions to super-admin
        $superAdmin = Role::where('name', 'super-admin')->where('guard_name', 'web')->first();

        if ($superAdmin !== null) {
            $superAdmin->syncPermissions(Permission::all());
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
