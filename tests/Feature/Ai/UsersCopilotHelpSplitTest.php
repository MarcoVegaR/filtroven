<?php

use App\Ai\Services\UsersCopilotRequestPlanner;
use App\Ai\Support\CopilotConversationSnapshot;

/**
 * Fase 1d: Separar `help.informational` de `help.unknown`.
 *
 * Congela la distincion entre prompts informativos reconocidos dentro del
 * scope del copiloto (ej: "como puedo revisar un usuario") y prompts que
 * simplemente no son reconocidos (ej: frases aleatorias, chistes, ruido).
 *
 * Racional del producto:
 * - help.informational: el copiloto responde ayuda factual del modulo.
 * - help.unknown: el copiloto avisa que no reconocio la consulta y muestra
 *   ejemplos de lo que si puede hacer.
 */
beforeEach(function () {
    config()->set('ai-copilot.contracts.help_unknown_split', true);
    config()->set('ai-copilot.intent_classifier.enabled', false);
});

it('routes a recognized informational prompt to users.help.informational', function () {
    $plan = (new UsersCopilotRequestPlanner)
        ->plan('como puedo revisar un usuario', CopilotConversationSnapshot::empty());

    expect($plan['capability_key'])->toBe('users.help.informational');
    expect($plan['intent_family'])->toBe('help');
});

it('routes an unrecognized random prompt to users.help.unknown', function () {
    $plan = (new UsersCopilotRequestPlanner)
        ->plan('cuentame un chiste sobre bananas', CopilotConversationSnapshot::empty());

    expect($plan['capability_key'])->toBe('users.help.unknown');
    expect($plan['intent_family'])->toBe('help');
});

it('falls back to users.help when the split flag is disabled (legacy behavior)', function () {
    config()->set('ai-copilot.contracts.help_unknown_split', false);

    $informational = (new UsersCopilotRequestPlanner)
        ->plan('como puedo revisar un usuario', CopilotConversationSnapshot::empty());

    $unknown = (new UsersCopilotRequestPlanner)
        ->plan('cuentame un chiste sobre bananas', CopilotConversationSnapshot::empty());

    expect($informational['capability_key'])->toBe('users.help');
    expect($unknown['capability_key'])->toBe('users.help');
});
