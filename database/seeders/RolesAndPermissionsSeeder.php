<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\PermissionName;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Baseline system permissions (dot-notation, frozen in PRD-02).
     *
     * Format: {context}.{resource}.{action}
     * Future modules append their own permissions in dedicated module seeders.
     *
     * @var list<string>
     */
    private const BASELINE_PERMISSIONS = [
        'system.roles.view',
        'system.roles.create',
        'system.roles.update',
        'system.roles.delete',
        'system.permissions.view',
        'system.users.view',
        'system.users.assign-role',
    ];

    /**
     * Run the database seeds.
     * This seeder is idempotent — safe to run multiple times without creating duplicates.
     */
    public function run(): void
    {
        // Flush cache to prevent stale data during seeding
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Upsert baseline permissions
        foreach (self::BASELINE_PERMISSIONS as $permission) {
            PermissionName::assertValid($permission);

            Permission::firstOrCreate(
                ['name' => $permission, 'guard_name' => 'web'],
                ['is_active' => true],
            );
        }

        // Upsert super-admin role
        $superAdmin = Role::firstOrCreate(
            ['name' => 'super-admin', 'guard_name' => 'web'],
            ['is_active' => true, 'display_name' => 'Super Administrador'],
        );

        // Sync ALL defined permissions to super-admin explicitly (no Gate::before bypass)
        $superAdmin->syncPermissions(Permission::all());

        // Re-flush cache to ensure fresh state after changes
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /**
     * Get the frozen baseline permissions for PRD-02.
     *
     * @return list<string>
     */
    public static function baselinePermissions(): array
    {
        return self::BASELINE_PERMISSIONS;
    }
}
