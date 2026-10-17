<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class TestUsersSeeder extends Seeder
{
    /**
     * Named test users with realistic data for deterministic testing.
     * Covers active/inactive states, multiple roles, and edge cases.
     *
     * @var list<array{name: string, email: string, is_active: bool, roles: list<string>}>
     */
    private const NAMED_USERS = [
        ['name' => 'María García López', 'email' => 'maria.garcia@example.com', 'is_active' => true, 'roles' => ['administrador-acceso']],
        ['name' => 'Carlos Rodríguez Pérez', 'email' => 'carlos.rodriguez@example.com', 'is_active' => true, 'roles' => ['gestor-usuarios', 'auditor']],
        ['name' => 'Ana Martínez Ruiz', 'email' => 'ana.martinez@example.com', 'is_active' => true, 'roles' => ['editor-contenido']],
        ['name' => 'José Hernández Díaz', 'email' => 'jose.hernandez@example.com', 'is_active' => true, 'roles' => ['soporte-tecnico']],
        ['name' => 'Laura Sánchez Torres', 'email' => 'laura.sanchez@example.com', 'is_active' => true, 'roles' => ['coordinador-seguridad']],
        ['name' => 'Pedro Gómez Vargas', 'email' => 'pedro.gomez@example.com', 'is_active' => true, 'roles' => ['operador-reportes']],
        ['name' => 'Sofía Ramírez Castro', 'email' => 'sofia.ramirez@example.com', 'is_active' => true, 'roles' => ['gestor-roles', 'auditor']],
        ['name' => 'Miguel Ángel Flores', 'email' => 'miguel.flores@example.com', 'is_active' => false, 'roles' => ['lector-basico']],
        ['name' => 'Carmen Jiménez Moreno', 'email' => 'carmen.jimenez@example.com', 'is_active' => true, 'roles' => ['supervisor-operaciones']],
        ['name' => 'Roberto Díaz Medina', 'email' => 'roberto.diaz@example.com', 'is_active' => true, 'roles' => ['consultor-externo']],
        ['name' => 'Isabel Morales Ortega', 'email' => 'isabel.morales@example.com', 'is_active' => false, 'roles' => ['editor-contenido', 'lector-basico']],
        ['name' => 'Fernando Reyes Guzmán', 'email' => 'fernando.reyes@example.com', 'is_active' => true, 'roles' => ['gestor-usuarios', 'soporte-tecnico', 'operador-reportes']],
        ['name' => 'Lucía Herrera Campos', 'email' => 'lucia.herrera@example.com', 'is_active' => true, 'roles' => ['auditor']],
        ['name' => 'Andrés Castillo Vega', 'email' => 'andres.castillo@example.com', 'is_active' => true, 'roles' => ['coordinador-seguridad', 'gestor-roles']],
        ['name' => 'Gabriela Núñez Ríos', 'email' => 'gabriela.nunez@example.com', 'is_active' => false, 'roles' => ['soporte-tecnico']],
    ];

    /**
     * Run the database seeds.
     * This seeder is idempotent — safe to run multiple times.
     * Must run AFTER TestRolesSeeder so that named roles exist.
     */
    public function run(): void
    {
        $rolesByName = Role::pluck('id', 'name');
        $shouldGenerateFactoryFixtures = in_array((string) config('app.env'), ['local', 'testing'], true);

        // Create named users with specific role assignments
        foreach (self::NAMED_USERS as $data) {
            $user = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => 'Password1!',
                    'email_verified_at' => now(),
                    'is_active' => $data['is_active'],
                ],
            );

            $roleIds = collect($data['roles'])
                ->map(fn (string $name) => $rolesByName->get($name))
                ->filter()
                ->all();

            $user->syncRoles(Role::whereIn('id', $roleIds)->get());
        }

        // Fill to 50 total using factory (excluding admin and named users)
        $existingCount = User::count();
        $remaining = max(0, 50 - $existingCount);

        if ($shouldGenerateFactoryFixtures && $remaining > 0) {
            $assignableRoles = Role::active()->where('name', '!=', 'super-admin')->get();

            User::factory()
                ->count($remaining)
                ->sequence(fn ($sequence): array => [
                    'email' => sprintf('test-user-%02d@example.com', $sequence->index + 1),
                ])
                ->create()
                ->each(function (User $user) use ($assignableRoles): void {
                    $rolesToAssign = $assignableRoles->random(min(rand(1, 3), $assignableRoles->count()));
                    $user->syncRoles($rolesToAssign);
                });
        }
    }
}
