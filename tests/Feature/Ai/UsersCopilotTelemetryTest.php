<?php

use App\Ai\Services\UsersCopilotRequestPlanner;
use App\Ai\Support\CopilotConversationSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Fase 2c: Verifica que el planner emite telemetria estructurada en 4 ejes:
 * 1. planner_resolution con stage inferido y duracion.
 * 2. staleness con freshness actual.
 * 3. denial con categoria (cuando aplica).
 * 4. interpretation con source y confidence (cuando el flag esta activo).
 */
beforeEach(function () {
    config()->set('ai-copilot.contracts.denied_intent', true);
    config()->set('ai-copilot.contracts.staleness_confirmation', true);
    config()->set('ai-copilot.contracts.interpretation', true);
});

it('logs planner resolution with the winning pipeline stage', function () {
    Log::spy();

    (new UsersCopilotRequestPlanner)
        ->plan('cuantos usuarios hay', CopilotConversationSnapshot::empty());

    Log::shouldHaveReceived('info')
        ->withArgs(function (string $channel, array $payload): bool {
            // "cuantos usuarios hay" esta en la matrix configurada, asi que
            // la stage ganadora es `configured_matrix`.
            return $channel === 'copilot.planner.resolved'
                && $payload['stage'] === 'configured_matrix'
                && $payload['capability_key'] === 'users.metrics.total'
                && array_key_exists('duration_ms', $payload)
                && is_int($payload['duration_ms']);
        });
});

it('logs denial stage when the prompt matches a denial gate', function () {
    Log::spy();

    (new UsersCopilotRequestPlanner)
        ->plan('dame la contraseña de sara@example.com', CopilotConversationSnapshot::empty());

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $channel, array $payload): bool => $channel === 'copilot.planner.resolved' && $payload['stage'] === 'denial');
});

it('logs staleness decision when the flag is active', function () {
    Log::spy();

    $snapshot = new CopilotConversationSnapshot([
        'last_capability_key' => 'users.search',
        'last_result_count' => 2,
        'last_turn_at' => CarbonImmutable::now()->subHours(2),
    ]);

    (new UsersCopilotRequestPlanner)->plan('y cuantos son', $snapshot);

    Log::shouldHaveReceived('info')
        ->withArgs(function (string $channel, array $payload): bool {
            return $channel === 'copilot.planner.staleness'
                && $payload['freshness'] === 'stale'
                && $payload['requires_confirmation'] === true;
        });
});

it('logs help_unknown stage for unrecognized prompts', function () {
    Log::spy();

    (new UsersCopilotRequestPlanner)
        ->plan('cuentame un chiste sobre bananas', CopilotConversationSnapshot::empty());

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $channel, array $payload): bool => $channel === 'copilot.planner.resolved' && $payload['stage'] === 'help_unknown');
});

it('sanitizes emails in the logged prompt', function () {
    Log::spy();

    (new UsersCopilotRequestPlanner)
        ->plan('busca al usuario mario@secret.example.com', CopilotConversationSnapshot::empty());

    Log::shouldHaveReceived('info')
        ->withArgs(function (string $channel, array $payload): bool {
            return $channel === 'copilot.planner.resolved'
                && isset($payload['prompt_sanitized'])
                && ! str_contains($payload['prompt_sanitized'], 'mario@secret.example.com')
                && str_contains($payload['prompt_sanitized'], '[email]');
        });
});
