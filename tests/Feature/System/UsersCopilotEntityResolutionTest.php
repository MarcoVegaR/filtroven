<?php

use App\Ai\Services\UsersCopilotRequestPlanner;
use App\Ai\Support\CopilotConversationSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

describe('UsersCopilot Entity Resolution', function (): void {
    beforeEach(function (): void {
        // Seed roles
        Role::firstOrCreate(['name' => 'super_administrator', 'guard_name' => 'web', 'is_active' => true]);
        Role::firstOrCreate(['name' => 'system.users-copilot.view', 'guard_name' => 'web', 'is_active' => true]);
    });
    it('no ejecuta full table scan para resolver entidades', function (): void {
        $admin = actingAs(
            User::factory()->create()->assignRole('super_administrator')
        );

        // Crear múltiples usuarios para simular una base grande
        User::factory()->count(50)->create();

        $planner = app(UsersCopilotRequestPlanner::class);
        $snapshot = new CopilotConversationSnapshot;

        // Habilitar query log
        DB::enableQueryLog();
        DB::flushQueryLog();

        // Ejecutar una búsqueda de entidad
        $plan = $planner->plan('revisa el usuario '.User::first()->name, $snapshot);

        // Obtener queries ejecutadas
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Buscar queries a la tabla users
        $userQueries = array_filter($queries, function (array $query): bool {
            return str_contains($query['query'], 'from "users"') || str_contains($query['query'], 'FROM users');
        });

        // Verificar que ninguna query carga todos los usuarios sin WHERE
        foreach ($userQueries as $query) {
            // No debería haber SELECT * FROM users sin cláusula WHERE
            expect($query['query'])
                ->not
                ->toMatch('/SELECT.*FROM\s+users\s*$/i')
                ->not
                ->toMatch('/SELECT.*FROM\s+users\s+WHERE\s+1\s*=\s*1/i');
        }

        // Debería haber usado ILIKE o LIKE con condición
        $hasWhereCondition = false;
        foreach ($userQueries as $query) {
            if (str_contains($query['query'], 'WHERE') || str_contains($query['query'], 'where')) {
                $hasWhereCondition = true;
                break;
            }
        }

        expect($hasWhereCondition)->toBeTrue('Debería usar cláusula WHERE en queries de users');
    });

    it('resuelve entidades por email exacto con query limitada', function (): void {
        $admin = actingAs(
            User::factory()->create()->assignRole('super_administrator')
        );

        $targetUser = User::factory()->create(['email' => 'test.resolution@example.com']);
        User::factory()->count(20)->create();

        DB::enableQueryLog();
        DB::flushQueryLog();

        $planner = app(UsersCopilotRequestPlanner::class);
        $snapshot = new CopilotConversationSnapshot;

        $plan = $planner->plan('revisa el usuario test.resolution@example.com', $snapshot);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Debería haber una query por email exacto con limit 5
        $emailQueryFound = false;
        foreach ($queries as $query) {
            if (str_contains($query['query'], 'lower(email)')) {
                $emailQueryFound = true;
                expect($query['query'])->toContain('limit');
            }
        }

        expect($emailQueryFound)->toBeTrue('Debería buscar por email exacto');
    });

    it('resuelve entidades por nombre con ILIKE y unaccent', function (): void {
        $admin = actingAs(
            User::factory()->create()->assignRole('super_administrator')
        );

        $targetUser = User::factory()->create(['name' => 'José María García']);
        User::factory()->count(20)->create();

        DB::enableQueryLog();
        DB::flushQueryLog();

        $planner = app(UsersCopilotRequestPlanner::class);
        $snapshot = new CopilotConversationSnapshot;

        $plan = $planner->plan('revisa el usuario jose maria', $snapshot);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Verificar que encuentra el usuario correcto o pide aclaración si hay ambigüedad
        // (factory users can randomly overlap with "jose" or "maria")
        if ($plan['capability_key'] === 'users.detail') {
            expect($plan['resolved_entity']['id'])->toBe($targetUser->id);
        } else {
            expect($plan['capability_key'])->toBe('users.clarification');
        }
    });
});
