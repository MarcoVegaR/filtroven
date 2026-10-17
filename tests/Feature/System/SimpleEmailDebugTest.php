<?php

use App\Ai\Services\UsersCopilotRequestPlanner;
use App\Ai\Support\CopilotConversationSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Test simple para debug del flujo de email sin dependencias complejas
 */
uses(RefreshDatabase::class);

describe('Simple Email Debug', function (): void {
    it('debug directo del planner con email existente', function (): void {
        // Crear usuario de prueba
        $testUser = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@mailinator.com',
            'is_active' => true,
        ]);

        echo "\n=== DEBUG DIRECTO ===\n";
        echo "Usuario creado: ID {$testUser->id}, Email: {$testUser->email}\n";

        // Crear planner y simular
        $planner = new UsersCopilotRequestPlanner;
        $snapshot = new CopilotConversationSnapshot;

        // Test directo del planner
        $prompt = 'quien es el usuario test@mailinator.com';
        $plan = $planner->plan($prompt, $snapshot);

        echo "Plan capability: {$plan['capability_key']}\n";
        echo "Plan intent: {$plan['intent_family']}\n";
        echo 'Resolved entity: '.($plan['resolved_entity'] ? 'YES' : 'NO')."\n";

        if ($plan['resolved_entity']) {
            echo "Entity ID: {$plan['resolved_entity']['id']}\n";
            echo "Entity Label: {$plan['resolved_entity']['label']}\n";
        }

        if ($plan['clarification_state']) {
            echo "Clarification reason: {$plan['clarification_state']['reason']}\n";
            echo "Clarification question: {$plan['clarification_state']['question']}\n";
        }

        // Verificaciones
        expect($plan['capability_key'])->toBe('users.detail');
        expect($plan['resolved_entity']['id'])->toBe($testUser->id);

        echo "\n=== ÉXITO: Planner funciona correctamente ===\n";
    });

    it('debug directo con email que no existe', function (): void {
        $planner = new UsersCopilotRequestPlanner;
        $snapshot = new CopilotConversationSnapshot;

        $prompt = 'quien es el usuario nonexistent@example.com';
        $plan = $planner->plan($prompt, $snapshot);

        echo "\n=== DEBUG EMAIL NO EXISTE ===\n";
        echo "Plan capability: {$plan['capability_key']}\n";

        if ($plan['clarification_state']) {
            echo "Clarification reason: {$plan['clarification_state']['reason']}\n";
            echo "Clarification question: {$plan['clarification_state']['question']}\n";
        }

        // Debería ser clarification con el nuevo mensaje mejorado
        expect($plan['capability_key'])->toBe('users.clarification');
        expect($plan['clarification_state']['reason'])->toBe('missing_entity');
        expect($plan['clarification_state']['question'])->toContain('nonexistent@example.com');

        echo "\n=== ÉXITO: Manejo correcto de email no existente ===\n";
    });
});
