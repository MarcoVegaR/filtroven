<?php

use App\Ai\Services\UsersCopilotResponseBuilder;
use App\Ai\Support\CopilotConversationSnapshot;
use Tests\TestCase;

uses(TestCase::class);

it('limits combined metric answers to the requested signals', function () {
    $builder = new UsersCopilotResponseBuilder;

    $payload = $builder->build(
        plan: [
            'request_normalization' => 'cuantos usuarios activos e inactivos hay',
            'intent_family' => 'read_metrics',
            'capability_key' => 'users.metrics.combined',
        ],
        snapshot: CopilotConversationSnapshot::empty(),
        executionResult: [
            'family' => 'aggregate',
            'outcome' => 'ok',
            'capability_key' => 'users.metrics.combined',
            'answer_facts' => [
                'metric' => [
                    'label' => 'Resumen de usuarios',
                    'value' => 12,
                    'unit' => 'users',
                ],
                'breakdown' => [
                    'active' => 9,
                    'inactive' => 3,
                    'most_common_role' => 'Admin',
                    'most_common_role_count' => 4,
                ],
            ],
            'cards' => [],
            'references' => [],
        ],
    );

    expect($payload['answer'])
        ->toBe('9 activos y 3 inactivos.')
        ->not->toContain('Hay 12 usuarios en total')
        ->not->toContain('rol mas comun');
});

it('builds a mixed metrics and search payload with canonical cards', function () {
    $builder = new UsersCopilotResponseBuilder;

    $payload = $builder->build(
        plan: [
            'request_normalization' => 'lista la cantidad de usuarios activos, inactivos y el rol mas comun y listame que usuarios son admin',
            'intent_family' => 'read_search',
            'capability_key' => 'users.mixed.metrics_search',
            'filters' => ['role' => 'admin'],
        ],
        snapshot: CopilotConversationSnapshot::empty(),
        executionResult: [
            'family' => 'mixed',
            'outcome' => 'ok',
            'capability_key' => 'users.mixed.metrics_search',
            'answer_facts' => [
                'metric' => [
                    'label' => 'Resumen de usuarios',
                    'value' => 12,
                    'unit' => 'users',
                ],
                'breakdown' => [
                    'active' => 9,
                    'inactive' => 3,
                    'most_common_role' => 'Admin',
                    'most_common_role_count' => 4,
                ],
            ],
            'cards' => [
                [
                    'kind' => 'metrics',
                    'title' => 'Resumen de usuarios',
                    'summary' => 'Resumen combinado.',
                    'data' => [
                        'capability_key' => 'users.metrics.combined',
                        'metric' => [
                            'label' => 'Resumen de usuarios',
                            'value' => 12,
                            'unit' => 'users',
                        ],
                        'breakdown' => [
                            ['key' => 'active', 'label' => 'Activos', 'value' => 9],
                            ['key' => 'inactive', 'label' => 'Inactivos', 'value' => 3],
                            ['key' => 'most_common_role_count', 'label' => 'Rol mas comun', 'value' => 4],
                        ],
                    ],
                ],
                [
                    'kind' => 'search_results',
                    'title' => 'Usuarios por rol',
                    'summary' => 'Se encontraron 4 usuarios con el rol admin.',
                    'data' => [
                        'count' => 4,
                        'matching_count' => 4,
                        'users' => [
                            ['id' => 1, 'name' => 'Ada Admin', 'email' => 'ada@example.com'],
                        ],
                    ],
                ],
            ],
            'references' => [],
        ],
    );

    expect($payload['intent'])
        ->toBe('search_results')
        ->and(data_get($payload, 'meta.capability_key'))->toBe('users.mixed.metrics_search')
        ->and(data_get($payload, 'meta.intent_family'))->toBe('read_search')
        ->and($payload['answer'])->toContain('9 activos y 3 inactivos.')
        ->and($payload['answer'])->toContain('El rol mas comun es Admin con 4 usuarios asignados.')
        ->and($payload['answer'])->toContain('Ademas, encontre 4 usuarios con rol admin.')
        ->and(data_get($payload, 'cards.0.kind'))->toBe('metrics')
        ->and(data_get($payload, 'cards.1.kind'))->toBe('search_results');
});
