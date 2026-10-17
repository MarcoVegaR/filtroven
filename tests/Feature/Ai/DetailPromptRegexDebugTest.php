<?php

use App\Ai\Services\UsersCopilotRequestPlanner;

/**
 * Test específico para debuggear el problema de detección de detail prompts
 */
describe('Detail Prompt Regex Debug', function (): void {
    it('debug exacto de looksLikeExplicitDetailPrompt', function (): void {
        $planner = new UsersCopilotRequestPlanner;

        // Acceder a métodos privados via reflection
        $reflection = new ReflectionClass($planner);
        $method = $reflection->getMethod('looksLikeExplicitDetailPrompt');
        $extractMethod = $reflection->getMethod('extractEmailFromText');

        $prompts = [
            'quien es test@mailinator.com',
            'que permisos tiene test@mailinator.com',
            'quien es test admin',
        ];

        foreach ($prompts as $prompt) {
            $normalized = strtolower(trim($prompt)); // Simplified normalization
            $email = $extractMethod->invoke($planner, $normalized);
            $isDetail = $method->invoke($planner, $normalized);

            echo "\n=== Prompt: $prompt ===\n";
            echo "Normalized: $normalized\n";
            echo 'Email: '.($email ?: 'NULL')."\n";
            echo 'Is Detail: '.($isDetail ? 'YES' : 'NO')."\n";

            // Debug individual regex patterns
            $regex1 = preg_match('/\b(que\s+permisos\s+tiene|que\s+roles?\s+tiene|que\s+puede\s+hacer|que\s+rol(?:es)?\s+tiene)\b/u', $normalized);
            $regex2 = preg_match('/\b(que\s+permisos\s+tiene|que\s+rol(?:es)?\s+tiene|que\s+rol\s+tiene|roles\s+y\s+permisos|permisos\s+y\s+rol|acceso\s+efectivo|estado\s+actual|estado\s+operativo)\b/u', $normalized);
            $regex3 = ($email !== null && preg_match('/\b(usuario|usuaria|revisa|resume|explica|detalle|estado|acceso|permisos|rol|roles|quien\s+es)\b/u', $normalized) === 1);
            $regex4 = (preg_match('/\b(revisa|resume|explica|detalle|estado|acceso|permisos|roles|puede|por\s+que)\b/u', $normalized) === 1 && (preg_match('/\busuario\b/u', $normalized) === 1 || $email !== null));

            echo 'Regex 1 (permisos): '.($regex1 ? 'MATCH' : 'NO')."\n";
            echo 'Regex 2 (general): '.($regex2 ? 'MATCH' : 'NO')."\n";
            echo 'Regex 3 (email+palabra): '.($regex3 ? 'MATCH' : 'NO')."\n";
            echo 'Regex 4 (fallback): '.($regex4 ? 'MATCH' : 'NO')."\n";
        }

        // Verificaciones específicas
        $method = $reflection->getMethod('looksLikeExplicitDetailPrompt');

        expect($method->invoke($planner, 'quien es test@mailinator.com'))->toBeTrue();
        expect($method->invoke($planner, 'que permisos tiene test@mailinator.com'))->toBeTrue();
        // "quien es el usuario X" sin email o keyword de detalle no matchea este regex
        // El flujo de resolución por nombre se hace por otra vía (resolveEntityDrivenPlan)
        expect($method->invoke($planner, 'revisa el usuario Test Admin'))->toBeTrue();
    });
});
