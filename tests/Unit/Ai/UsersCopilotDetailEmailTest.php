<?php

use App\Ai\Services\UsersCopilotRequestPlanner;

/**
 * Tests unitarios de reconocimiento de patrones de detail por email.
 * No requieren base de datos - solo verifican la lógica de regex.
 */
describe('UsersCopilot Detail by Email Pattern Recognition', function (): void {
    it('reconoce quien es [email] como detail prompt via reflection', function (): void {
        $planner = new UsersCopilotRequestPlanner;
        $method = new ReflectionMethod($planner, 'looksLikeExplicitDetailPrompt');

        expect($method->invoke($planner, 'quien es test@example.com'))->toBeTrue();
    });

    it('que permisos tiene [email] sigue funcionando via reflection', function (): void {
        $planner = new UsersCopilotRequestPlanner;
        $method = new ReflectionMethod($planner, 'looksLikeExplicitDetailPrompt');

        expect($method->invoke($planner, 'que permisos tiene test@example.com'))->toBeTrue();
    });

    it('quien es sin email no es detail prompt', function (): void {
        $planner = new UsersCopilotRequestPlanner;
        $method = new ReflectionMethod($planner, 'looksLikeExplicitDetailPrompt');

        // Sin email, "quien es el administrador" no es detail prompt
        expect($method->invoke($planner, 'quien es el administrador'))->toBeFalse();
    });

    it('variantes de quien son reconocidas', function (): void {
        $planner = new UsersCopilotRequestPlanner;
        $method = new ReflectionMethod($planner, 'looksLikeExplicitDetailPrompt');

        // Varias formas de "quien es" con email
        expect($method->invoke($planner, 'quien es admin@example.com'))->toBeTrue();
        expect($method->invoke($planner, 'quien es user@domain.org'))->toBeTrue();
    });
});
