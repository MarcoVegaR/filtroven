<?php

use App\Ai\Services\UsersCopilotRequestPlanner;
use App\Ai\Support\CopilotConversationSnapshot;
use App\Models\User;

it('suggests correction for emails with typos', function () {
    // Create test user
    $user = User::factory()->create([
        'email' => 'test@example.com',
        'name' => 'Test User',
    ]);

    $planner = new UsersCopilotRequestPlanner;
    $snapshot = new CopilotConversationSnapshot;

    // Test with typo (extra character)
    $plan = $planner->plan('quien es test@examplee.com', $snapshot);

    expect($plan['capability_key'])->toBe('users.clarification');
    expect($plan['clarification_state']['reason'])->toBe('missing_entity');
    expect($plan['clarification_state']['question'])
        ->toContain('No encontré ningún usuario con el correo \'test@examplee.com\'')
        ->toContain('¿Quizás quisiste decir \'test@example.com\'?');
});

it('suggests correction for emails with missing characters', function () {
    // Create test user
    $user = User::factory()->create([
        'email' => 'admin@test.com',
        'name' => 'Admin User',
    ]);

    $planner = new UsersCopilotRequestPlanner;
    $snapshot = new CopilotConversationSnapshot;

    // Test with missing character
    $plan = $planner->plan('que permisos tiene admn@test.com', $snapshot);

    expect($plan['capability_key'])->toBe('users.clarification');
    expect($plan['clarification_state']['question'])
        ->toContain('¿Quizás quisiste decir \'admin@test.com\'?');
});

it('does not suggest correction for completely different emails', function () {
    // Create test user
    $user = User::factory()->create([
        'email' => 'john@company.com',
        'name' => 'John Doe',
    ]);

    $planner = new UsersCopilotRequestPlanner;
    $snapshot = new CopilotConversationSnapshot;

    // Test with completely different email
    $plan = $planner->plan('quien es mary@different.org', $snapshot);

    expect($plan['capability_key'])->toBe('users.clarification');
    expect($plan['clarification_state']['question'])
        ->toContain('No encontré ningún usuario con el correo \'mary@different.org\'')
        ->not->toContain('¿Quizás quisiste decir');
});

it('does not suggest correction for exact email matches', function () {
    // Create test user
    $user = User::factory()->create([
        'email' => 'exact@match.com',
        'name' => 'Exact Match',
    ]);

    $planner = new UsersCopilotRequestPlanner;
    $snapshot = new CopilotConversationSnapshot;

    // Test with exact match (should not suggest correction)
    $plan = $planner->plan('quien es exact@match.com', $snapshot);

    expect($plan['capability_key'])->toBe('users.detail');
    expect($plan['resolved_entity']['id'])->toBe($user->id);
    expect($plan['clarification_state'])->toBeNull();
});
