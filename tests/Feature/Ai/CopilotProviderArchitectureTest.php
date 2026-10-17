<?php

use App\Ai\Agents\System\UsersCopilotAgent;
use App\Ai\Agents\System\UsersGeminiCopilotAgent;
use App\Ai\Services\UsersCopilotCapabilityCatalog;
use App\Ai\Services\UsersCopilotRequestPlanner;
use App\Ai\Support\CopilotConversationSnapshot;
use App\Ai\Support\CopilotProviderProfile;
use App\Ai\Support\CopilotStructuredOutput;
use App\Models\User;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredAgentResponse;

it('feature gates gemini to the text json adapter path while preserving provider specific schema metadata', function () {
    $profile = CopilotProviderProfile::forProvider('gemini');

    expect($profile->usesTextJsonResponses())->toBeTrue()
        ->and($profile->usesStructuredResponses())->toBeFalse()
        ->and($profile->supportsStructuredOutput)->toBeFalse()
        ->and($profile->supportsToolsWithStructuredOutput)->toBeFalse()
        ->and($profile->schemaProfile)->toBe('gemini');
});

it('keeps structured output enabled for non gemini providers', function () {
    $profile = CopilotProviderProfile::forProvider('openai');

    expect($profile->usesStructuredResponses())->toBeTrue()
        ->and($profile->usesTextJsonResponses())->toBeFalse()
        ->and($profile->supportsStructuredOutput)->toBeTrue()
        ->and($profile->supportsToolsWithStructuredOutput)->toBeTrue()
        ->and($profile->supportsNativeCapabilityPlanning())->toBeTrue()
        ->and($profile->schemaProfile)->toBe('default');
});

it('prefers the deterministic metrics tool for planned aggregate capabilities', function () {
    $agent = new UsersCopilotAgent(
        User::factory()->make(),
        planningContext: [
            'request_normalization' => 'cuantos usuarios activos hay',
            'intent_family' => 'read_metrics',
            'capability_key' => 'users.metrics.active',
        ],
        conversationSnapshot: CopilotConversationSnapshot::empty(),
    );

    $toolNames = collect($agent->tools())
        ->map(fn (object $tool): string => class_basename($tool::class))
        ->values()
        ->all();

    expect($toolNames)->toContain('GetUsersMetricsTool')
        ->and($toolNames)->not->toContain('SearchUsersTool');
});

it('resolves the deterministic planning matrix to canonical capability keys', function () {
    $planner = new UsersCopilotRequestPlanner;

    foreach (config('ai-copilot.planning.matrices.deterministic') as $case) {
        $plan = $planner->plan($case['prompt'], CopilotConversationSnapshot::empty());

        expect($plan)->toMatchArray([
            'intent_family' => $case['intent_family'],
            'capability_key' => $case['capability_key'],
            'proposal_vs_execute' => 'execute',
        ]);
    }
});

it('keeps the gemini adapter on the text-json path without sdk tool schemas', function () {
    config()->set('ai-copilot.providers.default', 'gemini');

    $agent = new UsersGeminiCopilotAgent(User::factory()->make(), planningContext: [
        'request_normalization' => 'cuantos usuarios activos hay',
        'intent_family' => 'read_metrics',
        'capability_key' => 'users.metrics.active',
    ]);

    expect($agent)->not->toBeInstanceOf(HasTools::class)
        ->and((string) $agent->instructions())
        ->toContain('backend ejecuta capacidades locales seguras')
        ->toContain('No menciones tools')
        ->toContain('texto JSON sin tool-calling del SDK')
        ->toContain('capability_key=users.metrics.active');
});

it('extracts canonical search query filters from planner phrasing variants', function () {
    $planner = new UsersCopilotRequestPlanner;

    $namePlan = $planner->plan('Busca usuarios con nombre Daniel', CopilotConversationSnapshot::empty());
    $emailPlan = $planner->plan('Busca al usuario laura@example.com', CopilotConversationSnapshot::empty());

    expect($namePlan)
        ->toMatchArray([
            'intent_family' => 'read_search',
            'capability_key' => 'users.search',
        ])
        ->and(data_get($namePlan, 'filters.query'))->toBe('daniel')
        ->and($emailPlan)
        ->toMatchArray([
            'intent_family' => 'read_search',
            'capability_key' => 'users.search',
        ])
        ->and(data_get($emailPlan, 'filters.query'))->toBe('laura@example.com');
});

it('normalizes json encoded flexible fields for structured providers', function () {
    $normalized = CopilotStructuredOutput::normalize([
        'answer' => 'Listado preparado.',
        'intent' => 'search_results',
        'cards' => [
            [
                'kind' => 'search_results',
                'title' => 'Usuarios',
                'summary' => 'Resultado resumido.',
                'data_json' => json_encode(['count' => 1, 'users' => [['id' => 10]]], JSON_THROW_ON_ERROR),
            ],
        ],
        'actions' => [
            [
                'kind' => 'action_proposal',
                'action_type' => 'send_reset',
                'target_json' => json_encode(['kind' => 'user', 'user_id' => 10], JSON_THROW_ON_ERROR),
                'summary' => 'Enviar reset.',
                'payload_json' => json_encode(['reason' => 'copilot_confirmed_action'], JSON_THROW_ON_ERROR),
                'can_execute' => true,
                'deny_reason' => null,
                'required_permissions' => ['system.users.send-reset'],
            ],
        ],
        'requires_confirmation' => true,
        'references' => [
            ['label' => 'Usuarios'],
        ],
        'meta' => [
            'module' => 'users',
            'channel' => 'web',
            'subject_user_id' => null,
            'fallback' => false,
            'diagnostics_json' => json_encode(['profile' => 'openai'], JSON_THROW_ON_ERROR),
        ],
    ]);

    expect($normalized)
        ->not->toBeNull()
        ->and($normalized['cards'][0]['data'])
        ->toMatchArray([
            'count' => 1,
            'visible_count' => 1,
            'matching_count' => 1,
            'truncated' => false,
            'limit' => 8,
            'count_represents' => 'visible_results',
            'list_semantics' => 'search_results_only',
            'aggregate_safe' => false,
            'users' => [['id' => 10]],
        ])
        ->and($normalized['actions'][0]['target'])
        ->toBe(['kind' => 'user', 'user_id' => 10])
        ->and($normalized['actions'][0]['payload'])
        ->toBe(['reason' => 'copilot_confirmed_action'])
        ->and($normalized['meta']['diagnostics'])
        ->toBe(['profile' => 'openai']);
});

it('normalizes malformed search result cards to an empty users array', function () {
    $normalized = CopilotStructuredOutput::normalize([
        'answer' => 'Listado preparado.',
        'intent' => 'search_results',
        'cards' => [
            [
                'kind' => 'search_results',
                'title' => 'Usuarios',
                'summary' => 'Resultado parcial.',
                'data_json' => json_encode(['count' => '3'], JSON_THROW_ON_ERROR),
            ],
        ],
        'actions' => [],
        'requires_confirmation' => false,
        'references' => [],
        'meta' => [
            'module' => 'users',
            'channel' => 'web',
            'subject_user_id' => null,
            'fallback' => false,
            'diagnostics_json' => null,
        ],
    ]);

    expect($normalized)
        ->not->toBeNull()
        ->and($normalized['cards'][0]['data'])
        ->toMatchArray([
            'count' => 0,
            'visible_count' => 0,
            'matching_count' => 0,
            'truncated' => false,
            'limit' => 8,
            'count_represents' => 'visible_results',
            'list_semantics' => 'search_results_only',
            'aggregate_safe' => false,
            'users' => [],
        ]);
});

it('normalizes metrics and clarification cards into first class phase one contracts', function () {
    $normalized = CopilotStructuredOutput::normalize([
        'answer' => 'Hay 12 usuarios activos y necesito aclarar un nombre ambiguo.',
        'intent' => 'inform',
        'cards' => [
            [
                'kind' => 'metrics',
                'title' => 'Usuarios activos',
                'summary' => 'Conteo exacto.',
                'data_json' => json_encode([
                    'capability_key' => 'users.metrics.active',
                    'metric' => [
                        'label' => 'Usuarios activos',
                        'value' => 12,
                        'unit' => 'users',
                    ],
                    'breakdown' => [
                        ['key' => 'active', 'label' => 'Activos', 'value' => 12],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
            [
                'kind' => 'clarification',
                'title' => 'Necesito aclaracion',
                'summary' => 'Hay varios usuarios posibles.',
                'data_json' => json_encode([
                    'reason' => 'ambiguous_target',
                    'question' => 'Cual Mario quieres revisar?',
                    'options' => [
                        ['label' => 'Mario Vega', 'value' => 'user:10'],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        ],
        'actions' => [],
        'requires_confirmation' => false,
        'references' => [],
        'meta' => [
            'module' => 'users',
            'channel' => 'web',
            'subject_user_id' => null,
            'fallback' => false,
            'capability_key' => 'users.metrics.active',
            'intent_family' => 'read_metrics',
            'conversation_state_version' => 1,
            'response_source' => 'local_orchestrator',
            'diagnostics_json' => json_encode(['execution' => 'local_capability_orchestrator'], JSON_THROW_ON_ERROR),
        ],
    ]);

    expect($normalized)
        ->not->toBeNull()
        ->and($normalized['intent'])->toBe('metrics')
        ->and($normalized['cards'][0]['data'])
        ->toMatchArray([
            'capability_key' => 'users.metrics.active',
            'metric' => [
                'label' => 'Usuarios activos',
                'value' => 12,
                'unit' => 'users',
            ],
        ])
        ->and($normalized['cards'][1]['data'])
        ->toBe([
            'reason' => 'ambiguous_target',
            'question' => 'Cual Mario quieres revisar?',
            'options' => [
                ['label' => 'Mario Vega', 'value' => 'user:10'],
            ],
        ])
        ->and($normalized['meta'])
        ->toMatchArray([
            'capability_key' => 'users.metrics.active',
            'intent_family' => 'read_metrics',
            'conversation_state_version' => 1,
            'response_source' => 'local_orchestrator',
        ]);
});

it('defines the phase one capability catalog and snapshot boundary', function () {
    $planning = config('ai-copilot.planning');
    $snapshot = CopilotConversationSnapshot::empty();

    expect(UsersCopilotCapabilityCatalog::aggregateKeys())
        ->toBe([
            'users.metrics.total',
            'users.metrics.active',
            'users.metrics.inactive',
            'users.metrics.with_roles',
            'users.metrics.without_roles',
            'users.metrics.verified',
            'users.metrics.unverified',
            'users.metrics.role_distribution',
            'users.metrics.most_common_role',
            'users.metrics.admin_access',
            'users.metrics.combined',
        ])
        ->and(UsersCopilotCapabilityCatalog::find('users.search'))
        ->toMatchArray([
            'family' => 'list',
            'response_intent' => 'search_results',
        ])
        ->and(UsersCopilotCapabilityCatalog::find('users.detail'))
        ->toMatchArray([
            'family' => 'detail',
            'requires_entity' => true,
        ])
        ->and($planning['thresholds'])
        ->toBe([
            'deterministic' => 100,
            'extended' => 90,
        ])
        ->and($snapshot->toDatabase())
        ->toBe([
            'snapshot' => json_encode($snapshot->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'snapshot_version' => CopilotConversationSnapshot::VERSION,
            'last_turn_at' => null,
        ]);
});

it('rebuilds openai search result cards from search tool results', function () {
    $response = (new StructuredAgentResponse(
        'invocation-1',
        [
            'answer' => 'Listado preparado.',
            'intent' => 'search_results',
            'cards' => [
                [
                    'kind' => 'search_results',
                    'title' => 'Usuarios',
                    'summary' => 'Resultado parcial.',
                    'data_json' => json_encode(['count' => 1], JSON_THROW_ON_ERROR),
                ],
            ],
            'actions' => [],
            'requires_confirmation' => false,
            'references' => [],
            'meta' => [
                'module' => 'users',
                'channel' => 'web',
                'subject_user_id' => null,
                'fallback' => false,
                'diagnostics_json' => null,
            ],
        ],
        json_encode(['answer' => 'Listado preparado.'], JSON_THROW_ON_ERROR),
        new Usage,
        new Meta('openai', 'gpt-4o-mini'),
    ))
        ->withToolCallsAndResults(new Collection, new Collection([
            new ToolResult(
                id: 'tool-1',
                name: 'SearchUsersTool',
                arguments: ['status' => 'inactive'],
                result: json_encode([
                    'count' => 1,
                    'users' => [
                        ['id' => 15, 'email' => 'irene@example.com'],
                    ],
                ], JSON_THROW_ON_ERROR),
            ),
        ]));

    $normalized = CopilotStructuredOutput::normalizeResponse(
        $response,
        CopilotProviderProfile::forProvider('openai'),
        null,
    );

    expect($normalized['meta']['fallback'])->toBeFalse()
        ->and($normalized['cards'][0]['data'])
        ->toMatchArray([
            'count' => 1,
            'visible_count' => 1,
            'matching_count' => 1,
            'truncated' => false,
            'limit' => 8,
            'count_represents' => 'visible_results',
            'list_semantics' => 'search_results_only',
            'aggregate_safe' => false,
            'users' => [
                ['id' => 15, 'email' => 'irene@example.com'],
            ],
        ])
        ->and($normalized['meta']['diagnostics'])
        ->toMatchArray([
            'search_results_source' => 'tool_results',
        ]);
});
