<?php

use App\Ai\Services\UsersCopilotRequestPlanner;

/**
 * Debug test para el problema de extracción de emails
 */
describe('Email Extraction Debug', function (): void {
    it('extrae email de prompt complejo', function (): void {
        $planner = new UsersCopilotRequestPlanner;

        // Test con reflection para acceder al método privado
        $extractMethod = new ReflectionMethod($planner, 'extractEmailFromText');
        $normalizeMethod = new ReflectionMethod($planner, 'normalizePrompt');
        $detailPromptMethod = new ReflectionMethod($planner, 'looksLikeExplicitDetailPrompt');

        // Prompt problemático
        $prompt = 'y quien es el usuario test@mailinator.com';
        $normalized = $normalizeMethod->invoke($planner, $prompt);
        $email = $extractMethod->invoke($planner, $normalized);
        $isDetail = $detailPromptMethod->invoke($planner, $normalized);

        echo "\n=== DEBUG ===\n";
        echo "Original: $prompt\n";
        echo "Normalized: $normalized\n";
        echo "Email extracted: $email\n";
        echo 'Is detail prompt: '.($isDetail ? 'YES' : 'NO')."\n";
        echo "=============\n";

        expect($email)->toBe('test@mailinator.com');
        expect($isDetail)->toBeTrue();
    });

    it('extrae email de prompt simple', function (): void {
        $planner = new UsersCopilotRequestPlanner;

        $extractMethod = new ReflectionMethod($planner, 'extractEmailFromText');
        $normalizeMethod = new ReflectionMethod($planner, 'normalizePrompt');

        $prompt = 'quien es test@mailinator.com';
        $normalized = $normalizeMethod->invoke($planner, $prompt);
        $email = $extractMethod->invoke($planner, $normalized);

        echo "\n=== DEBUG SIMPLE ===\n";
        echo "Original: $prompt\n";
        echo "Normalized: $normalized\n";
        echo "Email extracted: $email\n";
        echo "==================\n";

        expect($email)->toBe('test@mailinator.com');
    });
});
