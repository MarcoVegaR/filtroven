<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class TestRolesSeeder extends Seeder
{
    /**
     * Named test roles with realistic display names, descriptions, and permission subsets.
     * Provides deterministic, meaningful data for testing tables, search, relations, and show views.
     *
     * @var list<array{name: string, display_name: string, description: string, permissions: list<string>, is_active: bool}>
     */
    private const NAMED_ROLES = [
        [
            'name' => 'editor-contenido',
            'display_name' => 'Editor de Contenido',
            'description' => 'Puede ver y editar contenido general del sistema.',
            'permissions' => ['system.roles.view', 'system.users.view'],
            'is_active' => true,
        ],
        [
            'name' => 'gestor-usuarios',
            'display_name' => 'Gestor de Usuarios',
            'description' => 'Administra cuentas de usuario: creación, edición y desactivación.',
            'permissions' => ['system.users.view', 'system.users.create', 'system.users.update', 'system.users.deactivate'],
            'is_active' => true,
        ],
        [
            'name' => 'auditor',
            'display_name' => 'Auditor',
            'description' => 'Acceso de solo lectura a roles, usuarios y permisos para fines de auditoría.',
            'permissions' => ['system.roles.view', 'system.users.view', 'system.permissions.view'],
            'is_active' => true,
        ],
        [
            'name' => 'soporte-tecnico',
            'display_name' => 'Soporte Técnico',
            'description' => 'Resuelve problemas de acceso y restablece contraseñas.',
            'permissions' => ['system.users.view', 'system.users.send-reset'],
            'is_active' => true,
        ],
        [
            'name' => 'coordinador-seguridad',
            'display_name' => 'Coordinador de Seguridad',
            'description' => 'Supervisa la seguridad del sistema, gestiona roles y permisos.',
            'permissions' => ['system.roles.view', 'system.roles.create', 'system.roles.update', 'system.roles.delete', 'system.roles.deactivate', 'system.permissions.view'],
            'is_active' => true,
        ],
        [
            'name' => 'operador-reportes',
            'display_name' => 'Operador de Reportes',
            'description' => 'Exporta datos de usuarios para reportes periódicos.',
            'permissions' => ['system.users.view', 'system.users.export'],
            'is_active' => true,
        ],
        [
            'name' => 'administrador-acceso',
            'display_name' => 'Administrador de Acceso',
            'description' => 'Control total sobre roles y asignación de usuarios.',
            'permissions' => ['system.roles.view', 'system.roles.create', 'system.roles.update', 'system.roles.delete', 'system.roles.deactivate', 'system.permissions.view', 'system.users.view', 'system.users.create', 'system.users.update', 'system.users.delete', 'system.users.deactivate', 'system.users.assign-role', 'system.users.send-reset', 'system.users.export'],
            'is_active' => true,
        ],
        [
            'name' => 'lector-basico',
            'display_name' => 'Lector Básico',
            'description' => 'Acceso mínimo de solo lectura al sistema.',
            'permissions' => ['system.roles.view'],
            'is_active' => true,
        ],
        [
            'name' => 'gestor-roles',
            'display_name' => 'Gestor de Roles',
            'description' => 'Crea y modifica roles del sistema.',
            'permissions' => ['system.roles.view', 'system.roles.create', 'system.roles.update', 'system.permissions.view'],
            'is_active' => true,
        ],
        [
            'name' => 'supervisor-operaciones',
            'display_name' => 'Supervisor de Operaciones',
            'description' => 'Supervisa operaciones generales del sistema.',
            'permissions' => ['system.roles.view', 'system.users.view', 'system.permissions.view', 'system.users.export'],
            'is_active' => true,
        ],
        [
            'name' => 'rol-temporal-inactivo',
            'display_name' => 'Rol Temporal (Inactivo)',
            'description' => 'Rol desactivado para pruebas de estado inactivo.',
            'permissions' => ['system.users.view'],
            'is_active' => false,
        ],
        [
            'name' => 'consultor-externo',
            'display_name' => 'Consultor Externo',
            'description' => 'Acceso limitado para consultores externos.',
            'permissions' => ['system.users.view', 'system.roles.view'],
            'is_active' => true,
        ],
    ];

    /**
     * Run the database seeds.
     * This seeder is idempotent — safe to run multiple times.
     */
    public function run(): void
    {
        $allPermissions = Permission::pluck('id', 'name');
        $shouldGenerateFactoryFixtures = in_array((string) config('app.env'), ['local', 'testing'], true);

        // Create named roles with specific permission subsets
        foreach (self::NAMED_ROLES as $data) {
            $role = Role::firstOrCreate(
                ['name' => $data['name'], 'guard_name' => 'web'],
                [
                    'display_name' => $data['display_name'],
                    'description' => $data['description'],
                    'is_active' => $data['is_active'],
                ],
            );

            $permissionIds = collect($data['permissions'])
                ->map(fn (string $name) => $allPermissions->get($name))
                ->filter()
                ->all();

            $role->syncPermissions(Permission::whereIn('id', $permissionIds)->get());
        }

        // Fill to 50 total using factory (excluding super-admin and named roles)
        $existingCount = Role::count();
        $remaining = max(0, 50 - $existingCount);

        if ($shouldGenerateFactoryFixtures && $remaining > 0) {
            $permissionPool = Permission::all();

            Role::factory()
                ->count($remaining)
                ->create()
                ->each(function (Role $role) use ($permissionPool): void {
                    $role->syncPermissions(
                        $permissionPool->random(min(rand(1, 6), $permissionPool->count()))
                    );
                });
        }
    }
}
