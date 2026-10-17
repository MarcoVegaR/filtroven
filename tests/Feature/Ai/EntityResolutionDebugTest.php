<?php

use App\Ai\Services\UsersCopilotRequestPlanner;
use App\Ai\Support\CopilotConversationSnapshot;

/**
 * Debug del flujo completo de entity resolution
 */
describe('Entity Resolution Debug', function (): void {
    it('debug completo del flujo de entity resolution', function (): void {
        $planner = new UsersCopilotRequestPlanner;
        $snapshot = new CopilotConversationSnapshot;

        // Prompt problemático
        $prompt = 'y quien es el usuario test@mailinator.com';

        // Acceder a métodos privados
        $planMethod = new ReflectionMethod($planner, 'plan');
        $entityPlanMethod = new ReflectionMethod($planner, 'resolveEntityDrivenPlan');
        $candidateMethod = new ReflectionMethod($planner, 'candidateUsersFor');

        echo "\n=== ENTITY RESOLUTION DEBUG ===\n";
        echo "Prompt: $prompt\n";

        try {
            // Intentar el plan completo
            $plan = $planMethod->invoke($planner, $prompt, $snapshot);

            echo "Plan capability_key: {$plan['capability_key']}\n";
            echo "Plan intent_family: {$plan['intent_family']}\n";
            echo 'Resolved entity: '.($plan['resolved_entity'] ? 'YES' : 'NO')."\n";

            if ($plan['resolved_entity']) {
                echo "Entity ID: {$plan['resolved_entity']['id']}\n";
                echo "Entity Label: {$plan['resolved_entity']['label']}\n";
            }

            // Si hay clarification state, mostrarlo
            if ($plan['clarification_state']) {
                echo "Clarification reason: {$plan['clarification_state']['reason']}\n";
                echo "Clarification question: {$plan['clarification_state']['question']}\n";
            }
        } catch (Exception $e) {
            echo 'ERROR: '.$e->getMessage()."\n";
        }

        // Test directo de candidateUsersFor
        echo "\n=== DIRECT CANDIDATE SEARCH ===\n";
        $candidates = $candidateMethod->invoke($planner, $prompt);
        echo 'Candidates found: '.$candidates->count()."\n";

        foreach ($candidates as $candidate) {
            echo "- ID: {$candidate->id}, Email: {$candidate->email}, Name: {$candidate->name}\n";
        }

        echo "===============================\n";

        // El test debe pasar siempre (solo es para debug)
        expect(true)->toBeTrue();
    });
});
