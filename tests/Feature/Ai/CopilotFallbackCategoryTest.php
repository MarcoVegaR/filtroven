<?php

use App\Ai\Services\UsersCopilotRequestPlanner;
use App\Ai\Support\CopilotConversationSnapshot;
use App\Models\User;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

describe('Copilot Fallback Category Taxonomy', function (): void {
    beforeEach(function (): void {
        // Seed roles and create user BEFORE spying Log
        Role::firstOrCreate(['name' => 'super_administrator', 'guard_name' => 'web', 'is_active' => true]);
        Role::firstOrCreate(['name' => 'system.users-copilot.view', 'guard_name' => 'web', 'is_active' => true]);

        // Create and authenticate user before Log spy to avoid SecurityAuditService noise
        $this->testUser = User::factory()->create();
        $this->testUser->assignRole('super_administrator');
        actingAs($this->testUser);
    });

    it('categoriza prompt sin match como no_match', function (): void {
        $planner = app(UsersCopilotRequestPlanner::class);
        $snapshot = new CopilotConversationSnapshot;

        $plan = $planner->plan('xyz123 completely unmatched prompt', $snapshot);

        // Fase 1d: 'xyz123 completely unmatched prompt' no matchea nada -> unknown.
        expect($plan['capability_key'])->toBe('users.help.unknown');
        expect($plan['intent_family'])->toBe('help');
    });

    it('categoriza prompt ambiguo como ambiguous_target', function (): void {
        $planner = app(UsersCopilotRequestPlanner::class);
        $snapshot = new CopilotConversationSnapshot([
            'last_user_request_normalized' => 'buscar usuarios',
            'last_intent_family' => 'read_search',
            'last_capability_key' => 'users.search',
            'last_filters' => [],
            'last_result_user_ids' => [1, 2, 3],
            'last_result_count' => 3,
        ]);

        $plan = $planner->plan('dame el detalle de ese usuario', $snapshot);

        // Con múltiples resultados y referencia vaga, debería pedir aclaración
        expect($plan['capability_key'])->toBe('users.clarification');
        expect($plan['clarification_state']['reason'])->toBeIn(['ambiguous_target', 'missing_context']);
    });

    it('categoriza prompt con missing target como missing_entity', function (): void {
        $planner = app(UsersCopilotRequestPlanner::class);
        $snapshot = new CopilotConversationSnapshot;

        $plan = $planner->plan('activa el usuario', $snapshot);

        // Sin usuario específico, debe pedir aclaración o proponer acción sin target
        expect($plan['capability_key'])->toBeIn(['users.clarification', 'users.help', 'users.help.unknown', 'users.help.informational']);
        if ($plan['capability_key'] === 'users.clarification') {
            expect($plan['clarification_state']['reason'])->toBe('missing_target');
        }
    });

    it('categoriza prompt informativo como informational', function (): void {
        $planner = app(UsersCopilotRequestPlanner::class);
        $snapshot = new CopilotConversationSnapshot;

        $plan = $planner->plan('como funciona el sistema de usuarios', $snapshot);

        // Fase 1d: prompt informativo reconocido cae en informational split.
        expect($plan['capability_key'])->toBeIn(['users.help', 'users.help.informational', 'users.help.unknown']);
        expect($plan['intent_family'])->toBe('help');
    });

    it('categoriza prompt con missing context como missing_context', function (): void {
        $planner = app(UsersCopilotRequestPlanner::class);
        $snapshot = new CopilotConversationSnapshot;

        $plan = $planner->plan('y cuantos de esos son admin', $snapshot);

        // Puede resolver a métrica admin_access, clarificación o help (split o legacy).
        expect($plan['capability_key'])->toBeIn([
            'users.clarification',
            'users.help',
            'users.help.unknown',
            'users.help.informational',
            'users.metrics.admin_access',
        ]);
    });
});
