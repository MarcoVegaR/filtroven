<?php

use App\Ai\Services\UsersCopilotRequestPlanner;
use App\Ai\Support\CopilotConversationSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Fase 1c: Staleness semantics (last_turn_at + freshness tri-valuada).
 *
 * Congela:
 * - fresh  -> continuacion automatica (comportamiento legacy).
 * - stale  -> plan continuation.confirm (requiere confirmacion explicita).
 * - expired -> snapshot descartado (planner se comporta como sin contexto).
 */
beforeEach(function () {
    config()->set('ai-copilot.contracts.staleness_confirmation', true);
    config()->set('ai-copilot.snapshot.ttl_soft_minutes', 30);
    config()->set('ai-copilot.snapshot.ttl_hard_hours', 24);
});

it('classifies a snapshot without last_turn_at as fresh (backward compatibility)', function () {
    $snapshot = new CopilotConversationSnapshot([
        'last_capability_key' => 'users.search',
        'last_result_count' => 3,
    ]);

    expect($snapshot->freshness())->toBe(CopilotConversationSnapshot::FRESHNESS_FRESH);
});

it('classifies freshness based on soft ttl boundary', function () {
    $recent = new CopilotConversationSnapshot([
        'last_capability_key' => 'users.search',
        'last_result_count' => 3,
        'last_turn_at' => CarbonImmutable::now()->subMinutes(10),
    ]);

    expect($recent->freshness())->toBe(CopilotConversationSnapshot::FRESHNESS_FRESH);

    $stale = new CopilotConversationSnapshot([
        'last_capability_key' => 'users.search',
        'last_result_count' => 3,
        'last_turn_at' => CarbonImmutable::now()->subHours(2),
    ]);

    expect($stale->freshness())->toBe(CopilotConversationSnapshot::FRESHNESS_STALE);
});

it('classifies snapshots past hard ttl as expired', function () {
    $expired = new CopilotConversationSnapshot([
        'last_capability_key' => 'users.search',
        'last_result_count' => 3,
        'last_turn_at' => CarbonImmutable::now()->subDays(2),
    ]);

    expect($expired->freshness())->toBe(CopilotConversationSnapshot::FRESHNESS_EXPIRED);
});

it('requires continuation confirmation for a deictic count follow-up on a stale snapshot', function () {
    $planner = new UsersCopilotRequestPlanner;

    $plan = $planner->plan('Y cuantos son', new CopilotConversationSnapshot([
        'last_capability_key' => 'users.search',
        'last_filters' => ['status' => 'inactive'],
        'last_result_count' => 3,
        'last_turn_at' => CarbonImmutable::now()->subHours(2),
    ]));

    expect($plan['intent_family'])->toBe('continuation_confirm');
    expect($plan['capability_key'])->toBe('users.continuation.confirm');
    expect(data_get($plan, 'clarification_state.reason'))->toBe('snapshot_stale');
    expect(data_get($plan, 'clarification_state.options'))->toHaveCount(2);
});

it('ignores the snapshot entirely when it has expired beyond the hard ttl', function () {
    $planner = new UsersCopilotRequestPlanner;

    $plan = $planner->plan('Y cuantos son', new CopilotConversationSnapshot([
        'last_capability_key' => 'users.search',
        'last_filters' => ['status' => 'inactive'],
        'last_result_count' => 3,
        'last_turn_at' => CarbonImmutable::now()->subDays(2),
    ]));

    // Sin contexto fresco ni stale: debe caer a missing_context.
    expect($plan['intent_family'])->toBe('ambiguous');
    expect($plan['capability_key'])->toBe('users.clarification');
    expect(data_get($plan, 'clarification_state.reason'))->toBe('missing_context');
});

it('allows automatic continuation on a fresh snapshot (no confirmation)', function () {
    $planner = new UsersCopilotRequestPlanner;

    $plan = $planner->plan('Y cuantos son', new CopilotConversationSnapshot([
        'last_capability_key' => 'users.search',
        'last_filters' => ['status' => 'inactive'],
        'last_result_count' => 3,
        'last_turn_at' => CarbonImmutable::now()->subMinutes(5),
    ]));

    expect($plan['intent_family'])->toBe('read_metrics');
    expect($plan['capability_key'])->toBe('users.snapshot.result_count');
});

it('does not ask for confirmation when the staleness flag is disabled', function () {
    config()->set('ai-copilot.contracts.staleness_confirmation', false);

    $planner = new UsersCopilotRequestPlanner;

    $plan = $planner->plan('Y cuantos son', new CopilotConversationSnapshot([
        'last_capability_key' => 'users.search',
        'last_filters' => ['status' => 'inactive'],
        'last_result_count' => 3,
        'last_turn_at' => CarbonImmutable::now()->subHours(2),
    ]));

    expect($plan['intent_family'])->toBe('read_metrics');
    expect($plan['capability_key'])->toBe('users.snapshot.result_count');
});

it('does not trigger confirmation when the prompt is not a deictic continuation', function () {
    $planner = new UsersCopilotRequestPlanner;

    $plan = $planner->plan('busca usuarios inactivos', new CopilotConversationSnapshot([
        'last_capability_key' => 'users.search',
        'last_filters' => ['status' => 'active'],
        'last_result_count' => 3,
        'last_turn_at' => CarbonImmutable::now()->subHours(2),
    ]));

    // Aunque el snapshot este stale, "busca usuarios inactivos" es una
    // consulta fresh que no depende del contexto previo.
    expect($plan['intent_family'])->toBe('read_search');
    expect($plan['capability_key'])->toBe('users.search');
});

it('produces an entity label in the confirm payload when snapshot resolved a user', function () {
    $user = User::factory()->create(['name' => 'Mario Vega']);
    $planner = new UsersCopilotRequestPlanner;

    $plan = $planner->plan('Desactivalo', new CopilotConversationSnapshot([
        'last_capability_key' => 'users.search',
        'last_result_user_ids' => [$user->id],
        'last_result_count' => 1,
        'last_resolved_entity_type' => 'user',
        'last_resolved_entity_id' => $user->id,
        'last_turn_at' => CarbonImmutable::now()->subHours(2),
    ]));

    expect($plan['intent_family'])->toBe('action_proposal');
    expect(data_get($plan, 'resolved_entity.label'))->toBe('Mario Vega');
});
