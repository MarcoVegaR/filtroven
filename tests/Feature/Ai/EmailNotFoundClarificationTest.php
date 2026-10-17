<?php

use App\Ai\Services\UsersCopilotRequestPlanner;
use App\Ai\Support\CopilotConversationSnapshot;

/**
 * Test para verificar el mejor mensaje de clarificación cuando email no existe
 */
describe('Email Not Found Clarification', function (): void {
    it('da mensaje específico cuando email no existe', function (): void {
        $planner = new UsersCopilotRequestPlanner;
        $snapshot = new CopilotConversationSnapshot;

        // Simular prompt con email que probablemente no existe
        $prompt = 'quien es nonexistent@example.com';

        // Usar reflection para acceder a métodos privados
        $planMethod = new ReflectionMethod($planner, 'plan');
        $extractMethod = new ReflectionMethod($planner, 'extractEmailFromText');

        // Verificar que el email se extrae correctamente
        $email = $extractMethod->invoke($planner, $prompt);
        expect($email)->toBe('nonexistent@example.com');

        // El plan debería ser clarification con mensaje específico
        $plan = $planMethod->invoke($planner, $prompt, $snapshot);

        expect($plan['capability_key'])->toBe('users.clarification');
        expect($plan['clarification_state']['reason'])->toBe('missing_entity');
        expect($plan['clarification_state']['question'])->toContain('nonexistent@example.com');
        expect($plan['clarification_state']['question'])->toContain('No encontré ningún usuario');
    });

    it('maneja email con contexto extra', function (): void {
        $planner = new UsersCopilotRequestPlanner;
        $snapshot = new CopilotConversationSnapshot;

        $prompt = 'y quien es el usuario test@nonexistent.com';

        $planMethod = new ReflectionMethod($planner, 'plan');
        $plan = $planMethod->invoke($planner, $prompt, $snapshot);

        expect($plan['capability_key'])->toBe('users.clarification');
        expect($plan['clarification_state']['reason'])->toBe('missing_entity');
        expect($plan['clarification_state']['question'])->toContain('test@nonexistent.com');
    });
});
