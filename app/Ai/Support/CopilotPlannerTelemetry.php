<?php

namespace App\Ai\Support;

use Illuminate\Support\Facades\Log;

/**
 * Fase 2c: Observabilidad estructurada del planner en 4 ejes.
 *
 * Ejes:
 * 1. planner_resolution: que stage (rama de precedencia) resolvio el plan.
 * 2. staleness: decisiones del freshness gate (fresh/stale/expired).
 * 3. denial: categorias de denegacion y alternativas propuestas.
 * 4. interpretation: source/confidence del bloque interpretation.
 *
 * Disenado para:
 * - Ser invocable sin hacer IO costoso (solo Log::info).
 * - No bloquear la respuesta al usuario en caso de fallo.
 * - Sanitizar emails y datos sensibles antes de persistir.
 */
class CopilotPlannerTelemetry
{
    /**
     * Registra la resolucion ganadora del planner, con su stage y capability.
     *
     * @param  array<string, mixed>  $extra
     */
    public static function resolved(
        string $stage,
        string $capabilityKey,
        string $intentFamily,
        string $normalizedPrompt,
        array $extra = [],
    ): void {
        Log::info('copilot.planner.resolved', [
            'stage' => $stage,
            'capability_key' => $capabilityKey,
            'intent_family' => $intentFamily,
            'prompt_sanitized' => self::sanitizePrompt($normalizedPrompt),
            ...$extra,
        ]);
    }

    /**
     * Registra evaluacion del freshness gate.
     */
    public static function stalenessDecision(
        string $freshness,
        bool $hasContext,
        ?int $minutesElapsed,
        bool $requiresConfirmation,
    ): void {
        Log::info('copilot.planner.staleness', [
            'freshness' => $freshness,
            'has_context' => $hasContext,
            'minutes_elapsed' => $minutesElapsed,
            'requires_confirmation' => $requiresConfirmation,
        ]);
    }

    /**
     * Registra cada denegacion con categoria y normalized prompt sanitizado.
     *
     * @param  list<array{label: string, prompt: string}>  $alternatives
     */
    public static function denial(
        string $category,
        string $reason,
        string $normalizedPrompt,
        array $alternatives = [],
    ): void {
        Log::warning('copilot.planner.denial', [
            'category' => $category,
            'reason' => $reason,
            'prompt_sanitized' => self::sanitizePrompt($normalizedPrompt),
            'alternatives_count' => count($alternatives),
        ]);
    }

    /**
     * Registra la construccion del bloque interpretation.
     */
    public static function interpretation(
        string $source,
        string $confidence,
        ?string $capabilityKey,
        ?string $intentFamily,
    ): void {
        Log::info('copilot.planner.interpretation', [
            'source' => $source,
            'confidence' => $confidence,
            'capability_key' => $capabilityKey,
            'intent_family' => $intentFamily,
        ]);
    }

    /**
     * Sanitiza el prompt antes de persistir: remueve emails para no dejar PII
     * en logs agregados.
     */
    protected static function sanitizePrompt(string $prompt): string
    {
        return (string) preg_replace(
            '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/',
            '[email]',
            $prompt,
        );
    }
}
