<?php

use App\Ai\Support\CopilotConversationSnapshot;
use App\Ai\Support\CopilotStructuredOutput;
use Tests\TestCase;

uses(TestCase::class);

it('keeps clarification payloads authoritative over contradictory actions', function () {
    $payload = CopilotStructuredOutput::reconstruct(
        payload: [
            'answer' => 'Voy a desactivar al usuario ahora mismo.',
            'intent' => 'action_proposal',
            'cards' => [[
                'kind' => 'clarification',
                'title' => 'Necesito aclaracion',
                'summary' => 'Hay varios usuarios posibles.',
                'data' => [
                    'reason' => 'ambiguous_target',
                    'question' => 'Cual Mario quieres revisar?',
                    'options' => [['label' => 'Mario Vega', 'value' => 'user:10']],
                ],
            ]],
            'actions' => [[
                'kind' => 'action_proposal',
                'action_type' => 'deactivate',
                'target' => ['kind' => 'user', 'user_id' => 10],
                'summary' => 'Desactiva a Mario.',
                'payload' => ['reason' => 'copilot_confirmed_action'],
                'can_execute' => true,
                'deny_reason' => null,
                'required_permissions' => ['system.users.deactivate'],
            ]],
            'requires_confirmation' => true,
            'references' => [['label' => 'Usuarios', 'href' => '/system/users']],
            'meta' => [
                'module' => 'users',
                'channel' => 'web',
                'subject_user_id' => null,
                'fallback' => false,
                'diagnostics' => null,
            ],
        ],
        plan: [
            'intent_family' => 'ambiguous',
            'capability_key' => 'users.clarification',
        ],
        snapshot: CopilotConversationSnapshot::empty(),
    );

    expect($payload)
        ->toMatchArray([
            'intent' => 'ambiguous',
            'requires_confirmation' => false,
        ])
        ->and($payload['answer'])->toBe('Cual Mario quieres revisar?')
        ->and($payload['actions'])->toBe([])
        ->and(data_get($payload, 'meta.capability_key'))->toBe('users.clarification');
});

it('strips action confirmations from non action plans', function () {
    $payload = CopilotStructuredOutput::reconstruct(
        payload: [
            'answer' => 'Hay 5 usuarios activos.',
            'intent' => 'metrics',
            'cards' => [],
            'actions' => [[
                'kind' => 'action_proposal',
                'action_type' => 'deactivate',
                'target' => ['kind' => 'user', 'user_id' => 10],
                'summary' => 'No deberia salir.',
                'payload' => ['reason' => 'copilot_confirmed_action'],
                'can_execute' => true,
                'deny_reason' => null,
                'required_permissions' => ['system.users.deactivate'],
            ]],
            'requires_confirmation' => true,
            'references' => [],
            'meta' => [
                'module' => 'users',
                'channel' => 'web',
                'subject_user_id' => null,
                'fallback' => false,
                'diagnostics' => null,
            ],
        ],
        plan: [
            'intent_family' => 'read_metrics',
            'capability_key' => 'users.metrics.active',
        ],
        snapshot: CopilotConversationSnapshot::empty(),
    );

    expect($payload['actions'])->toBe([])
        ->and($payload['requires_confirmation'])->toBeFalse();
});

it('rebuilds snapshot subset count responses from conversation state', function () {
    $payload = CopilotStructuredOutput::reconstruct(
        payload: [
            'answer' => 'Respuesta incorrecta.',
            'intent' => 'help',
            'cards' => [],
            'actions' => [],
            'requires_confirmation' => false,
            'references' => [],
            'meta' => [
                'module' => 'users',
                'channel' => 'web',
                'subject_user_id' => null,
                'fallback' => false,
                'diagnostics' => ['reason' => 'provider_guess'],
            ],
        ],
        plan: [
            'intent_family' => 'read_metrics',
            'capability_key' => 'users.snapshot.result_count',
        ],
        snapshot: new CopilotConversationSnapshot([
            'last_result_count' => 4,
            'last_filters' => ['status' => 'inactive'],
        ]),
    );

    expect($payload)
        ->toMatchArray([
            'intent' => 'metrics',
            'requires_confirmation' => false,
        ])
        ->and($payload['answer'])->toBe('El subconjunto actual tiene 4 usuarios.')
        ->and(data_get($payload, 'cards.0.data.metric.value'))->toBe(4)
        ->and(data_get($payload, 'meta.diagnostics.source_of_truth'))->toBe('conversation_snapshot');
});

it('derives canonical resolution for missing context clarifications', function () {
    $payload = CopilotStructuredOutput::reconstruct(
        payload: [
            'answer' => 'Voy a continuar.',
            'intent' => 'ambiguous',
            'cards' => [[
                'kind' => 'clarification',
                'title' => 'Falta contexto',
                'summary' => 'Necesito contexto previo.',
                'data' => [
                    'reason' => 'missing_context',
                    'question' => 'Necesito contexto previo.',
                    'options' => [],
                ],
            ]],
            'actions' => [],
            'requires_confirmation' => false,
            'references' => [],
            'meta' => [
                'module' => 'users',
                'channel' => 'web',
                'subject_user_id' => null,
                'fallback' => false,
                'diagnostics' => null,
            ],
        ],
        plan: [
            'intent_family' => 'ambiguous',
            'capability_key' => 'users.clarification',
        ],
        snapshot: CopilotConversationSnapshot::empty(),
    );

    expect(data_get($payload, 'resolution.state'))->toBe('missing_context')
        ->and(data_get($payload, 'resolution.action_boundary'))->toBe('none')
        ->and(data_get($payload, 'resolution.missing.0.slot'))->toBe('context');
});

it('derives canonical resolution for denied payloads', function () {
    $payload = CopilotStructuredOutput::ensureResolution([
        'answer' => 'No puedo hacerlo.',
        'intent' => 'denied',
        'cards' => [[
            'kind' => 'denied',
            'title' => 'Solicitud denegada',
            'summary' => 'No puedo hacerlo.',
            'data' => [
                'category' => 'bypass_policy',
                'reason' => 'denied:bypass_policy',
                'message' => 'No puedo saltarme validaciones.',
                'alternatives' => [],
            ],
        ]],
        'actions' => [],
        'requires_confirmation' => false,
        'references' => [],
        'meta' => [
            'module' => 'users',
            'channel' => 'web',
            'subject_user_id' => null,
            'fallback' => false,
            'capability_key' => 'users.denied',
            'intent_family' => 'denied',
            'conversation_state_version' => 1,
            'response_source' => 'local_orchestrator',
            'diagnostics' => null,
        ],
    ]);

    expect(data_get($payload, 'resolution.state'))->toBe('denied')
        ->and(data_get($payload, 'resolution.action_boundary'))->toBe('blocked')
        ->and(data_get($payload, 'resolution.denials.0.reason_code'))->toBe('bypass_policy');
});
