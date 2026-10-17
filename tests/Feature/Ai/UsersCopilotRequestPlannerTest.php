<?php

use App\Ai\Services\UsersCopilotRequestPlanner;
use App\Ai\Support\CopilotConversationSnapshot;
use App\Models\User;

it('reuses the previous search subset for deterministic follow-up counts', function () {
    $planner = new UsersCopilotRequestPlanner;

    $plan = $planner->plan('Y cuantos son', new CopilotConversationSnapshot([
        'last_capability_key' => 'users.search',
        'last_filters' => ['status' => 'inactive', 'query' => 'mario'],
        'last_result_count' => 2,
    ]));

    expect($plan)
        ->toMatchArray([
            'intent_family' => 'read_metrics',
            'capability_key' => 'users.snapshot.result_count',
            'proposal_vs_execute' => 'execute',
        ])
        ->and(data_get($plan, 'filters.status'))->toBe('inactive')
        ->and(data_get($plan, 'filters.query'))->toBe('mario');
});

it('refines the previous subset instead of discarding prior filters', function () {
    $planner = new UsersCopilotRequestPlanner;

    $plan = $planner->plan('Solo los inactivos por favor', new CopilotConversationSnapshot([
        'last_capability_key' => 'users.search',
        'last_filters' => ['query' => 'daniel', 'status' => 'active'],
        'last_result_count' => 3,
    ]));

    expect($plan)
        ->toMatchArray([
            'intent_family' => 'read_search',
            'capability_key' => 'users.search',
        ])
        ->and(data_get($plan, 'filters.query'))->toBe('daniel')
        ->and(data_get($plan, 'filters.status'))->toBe('inactive');
});

it('resolves entity references from the previous single result context', function () {
    $planner = new UsersCopilotRequestPlanner;
    $user = User::factory()->create(['name' => 'Mario Vega']);

    $plan = $planner->plan('Desactivalo', new CopilotConversationSnapshot([
        'last_capability_key' => 'users.search',
        'last_result_user_ids' => [$user->id],
        'last_result_count' => 1,
    ]));

    expect($plan)
        ->toMatchArray([
            'intent_family' => 'action_proposal',
            'capability_key' => 'users.actions.deactivate',
            'proposal_vs_execute' => 'proposal',
        ])
        ->and(data_get($plan, 'resolved_entity.id'))->toBe($user->id);
});

it('resolves first ordinal references from previous search results', function () {
    $planner = new UsersCopilotRequestPlanner;
    $firstUser = User::factory()->create(['name' => 'Carlos Uno']);
    $secondUser = User::factory()->create(['name' => 'Carlos Dos']);

    $plan = $planner->plan('muéstrame el primero', new CopilotConversationSnapshot([
        'last_capability_key' => 'users.search',
        'last_result_user_ids' => [$firstUser->id, $secondUser->id],
        'last_result_count' => 2,
    ]));

    expect($plan)
        ->toMatchArray([
            'intent_family' => 'read_detail',
            'capability_key' => 'users.detail',
            'proposal_vs_execute' => 'execute',
        ])
        ->and(data_get($plan, 'resolved_entity.id'))->toBe($firstUser->id)
        ->and($plan['clarification_state'])->toBeNull();
});

it('returns clarification when follow-up context is missing', function () {
    $planner = new UsersCopilotRequestPlanner;

    $plan = $planner->plan('Y cuantos son', CopilotConversationSnapshot::empty());

    expect($plan)
        ->toMatchArray([
            'intent_family' => 'ambiguous',
            'capability_key' => 'users.clarification',
        ])
        ->and(data_get($plan, 'clarification_state.reason'))->toBe('missing_context');
});

it('creates clarification options for ambiguous direct user references', function () {
    $planner = new UsersCopilotRequestPlanner;

    User::factory()->create(['name' => 'Mario Vega', 'email' => 'mario1@example.com']);
    User::factory()->create(['name' => 'Mario Soto', 'email' => 'mario2@example.com']);

    $plan = $planner->plan('Revisa al usuario Mario', CopilotConversationSnapshot::empty());

    expect($plan)
        ->toMatchArray([
            'intent_family' => 'ambiguous',
            'capability_key' => 'users.clarification',
        ])
        ->and(data_get($plan, 'clarification_state.reason'))->toBe('ambiguous_target')
        ->and(data_get($plan, 'clarification_state.options'))->toHaveCount(2);
});

it('routes informational prompts about users to help instead of entity clarification', function () {
    $planner = new UsersCopilotRequestPlanner;

    $plan = $planner->plan(
        'Explica como revisar roles activos y permisos efectivos de un usuario',
        CopilotConversationSnapshot::empty(),
    );

    // Fase 1d: el split informational/unknown aplica cuando el flag esta activo.
    expect($plan)
        ->toMatchArray([
            'intent_family' => 'help',
            'proposal_vs_execute' => 'none',
        ])
        ->and($plan['capability_key'])->toBeIn(['users.help', 'users.help.informational'])
        ->and($plan['clarification_state'])->toBeNull()
        ->and($plan['resolved_entity'])->toBeNull();
});

it('routes combined metrics and search prompts to a mixed deterministic capability', function () {
    $planner = new UsersCopilotRequestPlanner;

    $plan = $planner->plan(
        'lista la cantidad de usuarios activos, inactivos y los roles mas comun y listame que usuarios son admin',
        CopilotConversationSnapshot::empty(),
    );

    expect($plan)
        ->toMatchArray([
            'intent_family' => 'read_search',
            'capability_key' => 'users.mixed.metrics_search',
            'proposal_vs_execute' => 'execute',
        ])
        ->and($plan['clarification_state'])->toBeNull()
        ->and(data_get($plan, 'filters.role'))->toBe('admin');
});

it('routes combined aggregate prompts to the combined deterministic metrics capability', function () {
    $planner = new UsersCopilotRequestPlanner;

    $plan = $planner->plan(
        'cuantos usuarios hay , dame los activos e inactivos y dime cual es el rol mas comun',
        CopilotConversationSnapshot::empty(),
    );

    expect($plan)
        ->toMatchArray([
            'intent_family' => 'read_metrics',
            'capability_key' => 'users.metrics.combined',
            'proposal_vs_execute' => 'execute',
        ])
        ->and($plan['clarification_state'])->toBeNull();
});

it('keeps plain inactive searches as search capability instead of mixed metrics', function () {
    $planner = new UsersCopilotRequestPlanner;

    $plan = $planner->plan('Busca usuarios inactivos', CopilotConversationSnapshot::empty());

    expect($plan)
        ->toMatchArray([
            'intent_family' => 'read_search',
            'capability_key' => 'users.search',
            'proposal_vs_execute' => 'execute',
        ])
        ->and(data_get($plan, 'filters.status'))->toBe('inactive');
});

it('keeps explicit search-by-email prompts as search instead of detail', function () {
    $planner = new UsersCopilotRequestPlanner;
    User::factory()->create([
        'name' => 'Sara Support',
        'email' => 'sara.support@example.com',
    ]);

    $plan = $planner->plan(
        'Busca al usuario sara.support@example.com',
        CopilotConversationSnapshot::empty(),
    );

    expect($plan)
        ->toMatchArray([
            'intent_family' => 'read_search',
            'capability_key' => 'users.search',
            'proposal_vs_execute' => 'execute',
        ])
        ->and(data_get($plan, 'filters.query'))->toBe('sara.support@example.com')
        ->and($plan['resolved_entity'])->toBeNull();
});

it('resolves explicit email detail prompts as user detail instead of search', function () {
    $planner = new UsersCopilotRequestPlanner;
    $user = User::factory()->create([
        'name' => 'Test Admin',
        'email' => 'test@mailinator.com',
    ]);

    $plan = $planner->plan(
        'el usuario test@mailinator.com que permisos tiene y que rol',
        CopilotConversationSnapshot::empty(),
    );

    expect($plan)
        ->toMatchArray([
            'intent_family' => 'read_detail',
            'capability_key' => 'users.detail',
            'proposal_vs_execute' => 'execute',
        ])
        ->and(data_get($plan, 'resolved_entity.id'))->toBe($user->id);
});

it('resolves clarification follow-up hints against the pending user options', function () {
    $planner = new UsersCopilotRequestPlanner;
    $firstUser = User::factory()->create([
        'name' => 'Test Operator',
        'email' => 'operator@example.com',
    ]);
    $secondUser = User::factory()->create([
        'name' => 'Test Admin',
        'email' => 'test@mailinator.com',
    ]);

    $plan = $planner->plan(
        'el usuario es test admin',
        new CopilotConversationSnapshot([
            'pending_clarification' => [
                'reason' => 'ambiguous_target',
                'question' => 'Indica cual usuario quieres revisar.',
                'options' => [
                    [
                        'label' => sprintf('%s <%s>', $firstUser->name, $firstUser->email),
                        'value' => 'user:'.$firstUser->id,
                        'capability_key' => 'users.detail',
                        'intent_family' => 'read_detail',
                        'resolved_entity' => [
                            'type' => 'user',
                            'id' => $firstUser->id,
                            'label' => $firstUser->name,
                        ],
                    ],
                    [
                        'label' => sprintf('%s <%s>', $secondUser->name, $secondUser->email),
                        'value' => 'user:'.$secondUser->id,
                        'capability_key' => 'users.detail',
                        'intent_family' => 'read_detail',
                        'resolved_entity' => [
                            'type' => 'user',
                            'id' => $secondUser->id,
                            'label' => $secondUser->name,
                        ],
                    ],
                ],
            ],
        ]),
    );

    expect($plan)
        ->toMatchArray([
            'intent_family' => 'read_detail',
            'capability_key' => 'users.detail',
            'proposal_vs_execute' => 'execute',
        ])
        ->and(data_get($plan, 'resolved_entity.id'))->toBe($secondUser->id)
        ->and($plan['clarification_state'])->toBeNull();
});

it('does not loop on clarification when follow-up contains a new intent', function () {
    $planner = new UsersCopilotRequestPlanner;

    $plan = $planner->plan(
        'Lista los usuarios activos',
        new CopilotConversationSnapshot([
            'pending_clarification' => [
                'reason' => 'ambiguous_target',
                'question' => 'Necesito que aclares a que usuario te refieres.',
                'options' => [],
            ],
        ]),
    );

    expect($plan['intent_family'])->not->toBe('ambiguous')
        ->and($plan['capability_key'])->not->toBe('users.clarification');
});

it('does not trigger entity resolution for generic user references', function () {
    $planner = new UsersCopilotRequestPlanner;

    $plan = $planner->plan(
        'Explica el estado de un usuario en el sistema',
        CopilotConversationSnapshot::empty(),
    );

    // Fase 1d: el split informational/unknown aplica cuando el flag esta activo.
    expect($plan)
        ->toMatchArray(['intent_family' => 'help'])
        ->and($plan['capability_key'])->toBeIn(['users.help', 'users.help.informational', 'users.help.unknown'])
        ->and($plan['clarification_state'])->toBeNull();
});

it('still resolves a specific named user through entity resolution', function () {
    $planner = new UsersCopilotRequestPlanner;
    $user = User::factory()->create(['name' => 'Carlos Mendez', 'email' => 'carlos@example.com']);

    $plan = $planner->plan('Revisa al usuario Carlos Mendez', CopilotConversationSnapshot::empty());

    expect($plan)
        ->toMatchArray([
            'intent_family' => 'read_detail',
            'capability_key' => 'users.detail',
            'proposal_vs_execute' => 'execute',
        ])
        ->and(data_get($plan, 'resolved_entity.id'))->toBe($user->id);
});

it('normalizes admin access phrasing into administrative access search semantics', function () {
    $planner = new UsersCopilotRequestPlanner;

    $plan = $planner->plan('Quienes tienen acceso de administrador', CopilotConversationSnapshot::empty());

    expect($plan)
        ->toMatchArray([
            'intent_family' => 'read_search',
            'capability_key' => 'users.search',
            'proposal_vs_execute' => 'execute',
        ])
        ->and(data_get($plan, 'filters.access_profile'))->toBe('administrative_access')
        ->and(data_get($plan, 'filters.role'))->toBeNull();
});

it('repairs minor admin typos when extracting administrative access filters', function () {
    $planner = new UsersCopilotRequestPlanner;

    $plan = $planner->plan('Lista usuarios con acceso adminsitrador activos', CopilotConversationSnapshot::empty());

    expect($plan)
        ->toMatchArray([
            'intent_family' => 'read_search',
            'capability_key' => 'users.search',
            'proposal_vs_execute' => 'execute',
        ])
        ->and(data_get($plan, 'filters.access_profile'))->toBe('administrative_access')
        ->and(data_get($plan, 'filters.role'))->toBeNull()
        ->and(data_get($plan, 'filters.status'))->toBe('active');
});

it('maps explicit super-admin collection prompts to the super-admin role/access filter', function () {
    $planner = new UsersCopilotRequestPlanner;

    $plan = $planner->plan('lista los usuarios con permisos de super admin', CopilotConversationSnapshot::empty());

    expect($plan)
        ->toMatchArray([
            'intent_family' => 'read_search',
            'capability_key' => 'users.search',
            'proposal_vs_execute' => 'execute',
        ])
        ->and(data_get($plan, 'filters.access_profile'))->toBe('super_admin_role');
});

it('maps plain admin count prompts to the deterministic administrative access metric', function () {
    $planner = new UsersCopilotRequestPlanner;

    $plan = $planner->plan('cuantos admin tenemos', CopilotConversationSnapshot::empty());

    expect($plan)
        ->toMatchArray([
            'intent_family' => 'read_metrics',
            'capability_key' => 'users.metrics.admin_access',
            'proposal_vs_execute' => 'execute',
        ]);
});

it('routes capabilities summary prompts to the explain capability', function () {
    $planner = new UsersCopilotRequestPlanner;
    $user = User::factory()->create(['name' => 'Sara Support', 'email' => 'sara@example.com']);

    $plan = $planner->plan('que puede hacer el usuario sara@example.com', CopilotConversationSnapshot::empty());

    expect($plan)
        ->toMatchArray([
            'intent_family' => 'read_explain',
            'capability_key' => 'users.explain.capabilities_summary',
            'proposal_vs_execute' => 'execute',
        ])
        ->and(data_get($plan, 'resolved_entity.id'))->toBe($user->id);
});

it('routes capabilities summary via subject user context without email in prompt', function () {
    $planner = new UsersCopilotRequestPlanner;
    $user = User::factory()->create(['name' => 'Sara Support']);

    $plan = $planner->plan('que puede hacer', CopilotConversationSnapshot::empty(), subjectUser: $user);

    expect($plan)
        ->toMatchArray([
            'intent_family' => 'read_explain',
            'capability_key' => 'users.explain.capabilities_summary',
            'proposal_vs_execute' => 'execute',
        ])
        ->and(data_get($plan, 'resolved_entity.id'))->toBe($user->id);
});

it('does not false-positive capabilities summary on unrelated que-puede phrases', function () {
    $planner = new UsersCopilotRequestPlanner;

    $plan = $planner->plan(
        'Explica que puede pasar si desactivo un usuario',
        CopilotConversationSnapshot::empty(),
    );

    expect($plan['capability_key'])->not->toBe('users.explain.capabilities_summary');
});
