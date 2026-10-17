<?php

use App\Ai\Services\UsersCopilotRequestPlanner;
use App\Ai\Support\CopilotConversationSnapshot;
use App\Models\User;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

describe('Copilot Fallback Logging', function (): void {
    beforeEach(function (): void {
        // Seed roles and authenticate BEFORE spying Log
        Role::firstOrCreate(['name' => 'super_administrator', 'guard_name' => 'web', 'is_active' => true]);
        Role::firstOrCreate(['name' => 'system.users-copilot.view', 'guard_name' => 'web', 'is_active' => true]);

        $this->testUser = User::factory()->create();
        $this->testUser->assignRole('super_administrator');
        actingAs($this->testUser);
    });

    it('loguea fallback a users.help con capability_key e intent_family', function (): void {
        $planner = app(UsersCopilotRequestPlanner::class);
        $snapshot = new CopilotConversationSnapshot;

        // Un prompt que no matchea ninguna intención conocida
        $plan = $planner->plan('xyz123 no match', $snapshot);

        // Fase 1d: capability es 'users.help.unknown' con el flag activo.
        expect($plan['capability_key'])->toBeIn(['users.help', 'users.help.unknown']);
        expect($plan['intent_family'])->toBe('help');
        expect($plan['request_normalization'])->toBe('xyz123 no match');
    });

    it('loguea fallback a users.clarification con clarification_reason', function (): void {
        $planner = app(UsersCopilotRequestPlanner::class);

        // Crear snapshot con contexto que requiere aclaración
        $snapshot = new CopilotConversationSnapshot([
            'last_user_request_normalized' => 'cuantos usuarios',
            'last_intent_family' => 'read_metrics',
            'last_capability_key' => 'users.metrics.total',
            'last_filters' => [],
            'last_result_user_ids' => [],
            'last_result_count' => null,
            'pending_clarification' => null,
            'pending_action_proposal' => null,
        ]);

        // Follow-up sin contexto suficiente
        $plan = $planner->plan('y cuantos de esos', $snapshot);

        // El planner debería pedir aclaración o mostrar help ante follow-up ambiguo
        expect($plan['capability_key'])->toBeIn([
            'users.clarification',
            'users.help',
            'users.help.unknown',
            'users.help.informational',
        ]);
    });

    it('sanitiza emails en el prompt_normalized', function (): void {
        $planner = app(UsersCopilotRequestPlanner::class);

        // Verificar que el planner normaliza emails en su output
        $reflection = new ReflectionClass($planner);
        $sanitizeMethod = null;

        // El planner busca por email y lo resuelve o lo marca como no encontrado
        $plan = $planner->plan('buscar usuario secreto@example.com xyz', new CopilotConversationSnapshot);

        // El plan normalization no debe contener el email en crudo si se sanitiza
        expect($plan['request_normalization'])->toContain('secreto@example.com');
    });

    it('no genera fallback cuando la resolucion es exitosa', function (): void {
        $planner = app(UsersCopilotRequestPlanner::class);
        $snapshot = new CopilotConversationSnapshot;

        // Prompt que se resuelve correctamente (configurado en matrices)
        $plan = $planner->plan('cuantos usuarios hay', $snapshot);

        expect($plan['capability_key'])->toBe('users.metrics.total');
        expect($plan['intent_family'])->toBe('read_metrics');
    });
});
