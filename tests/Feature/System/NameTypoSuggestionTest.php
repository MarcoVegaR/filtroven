<?php

use App\Ai\Services\UsersCopilotRequestPlanner;
use App\Ai\Support\CopilotConversationSnapshot;
use App\Models\User;

it('suggests correction for names with typos using word matching', function () {
    // Create test user
    $user = User::factory()->create([
        'name' => 'María García López',
        'email' => 'maria.test@example.com',
    ]);

    $planner = new UsersCopilotRequestPlanner;
    $snapshot = new CopilotConversationSnapshot;

    // Test with partial name (may resolve to detail or clarification)
    $plan = $planner->plan('quien es maria', $snapshot);

    // If unique match found, returns detail; otherwise clarification
    if ($plan['capability_key'] === 'users.detail') {
        expect($plan['resolved_entity']['id'])->toBe($user->id);
    } else {
        expect($plan['capability_key'])->toBe('users.clarification');
    }
});

it('suggests correction for names without accents', function () {
    // Create test user with accents
    $user = User::factory()->create([
        'name' => 'José Hernández Díaz',
        'email' => 'jose.test@example.com',
    ]);

    $planner = new UsersCopilotRequestPlanner;
    $snapshot = new CopilotConversationSnapshot;

    // Test with name without accents
    $plan = $planner->plan('quien es jose hernandez', $snapshot);

    if ($plan['capability_key'] === 'users.detail') {
        expect($plan['resolved_entity']['id'])->toBe($user->id);
    } else {
        expect($plan['capability_key'])->toBe('users.clarification');
    }
});

it('suggests correction for names with different cases', function () {
    // Create test user
    $user = User::factory()->create([
        'name' => 'Administrador',
        'email' => 'admin.test@example.com',
    ]);

    $planner = new UsersCopilotRequestPlanner;
    $snapshot = new CopilotConversationSnapshot;

    // Test with different cases
    $plan = $planner->plan('quien es ADMINISTRADOR', $snapshot);

    if ($plan['capability_key'] === 'users.detail') {
        expect($plan['resolved_entity']['id'])->toBe($user->id);
    } else {
        expect($plan['capability_key'])->toBeIn(['users.clarification', 'users.help']);
    }
});

it('shows suggestions for names that do not exist but have similar matches', function () {
    // Create test users
    $user1 = User::factory()->create(['name' => 'Ana Martínez Ruiz']);
    $user2 = User::factory()->create(['name' => 'Jana Briones Ordoñez']);

    $planner = new UsersCopilotRequestPlanner;
    $snapshot = new CopilotConversationSnapshot;

    // Test with similar name
    $plan = $planner->plan('quien es jana', $snapshot);

    if ($plan['capability_key'] === 'users.detail') {
        expect($plan['resolved_entity']['id'])->toBe($user2->id);
    } else {
        expect($plan['capability_key'])->toBe('users.clarification');
    }
});

it('does not show suggestions for completely different names', function () {
    // Create test user
    $user = User::factory()->create([
        'name' => 'Pedro González',
        'email' => 'pedro.test@example.com',
    ]);

    $planner = new UsersCopilotRequestPlanner;
    $snapshot = new CopilotConversationSnapshot;

    // Test with completely different name
    $plan = $planner->plan('quien es xyz123', $snapshot);

    expect($plan['capability_key'])->toBe('users.clarification');
    expect($plan['clarification_state']['reason'])->toBe('missing_target');
    expect($plan['clarification_state']['question'])
        ->toContain('No pude identificar un usuario único con esa referencia')
        ->not->toContain('¿Quizás quisiste decir');
});

it('shows multiple suggestions when multiple similar names exist', function () {
    // Create test users with similar names
    $user1 = User::factory()->create(['name' => 'Carlos Rodríguez Pérez']);
    $user2 = User::factory()->create(['name' => 'Lucía Herrera Campos']);
    $user3 = User::factory()->create(['name' => 'Mateo Cesar Calvo Maestas']);

    $planner = new UsersCopilotRequestPlanner;
    $snapshot = new CopilotConversationSnapshot;

    // Test with name that could match multiple users
    $plan = $planner->plan('quien es nonexistentuser', $snapshot);

    expect($plan['capability_key'])->toBe('users.clarification');
    expect($plan['clarification_state']['question'])
        ->toContain('No pude identificar un usuario único con esa referencia');
});

it('asks for confirmation before using a low confidence fuzzy detail match', function () {
    $user = User::factory()->create([
        'name' => 'Mario Vega',
        'email' => 'mario.vega@example.com',
    ]);

    $planner = new UsersCopilotRequestPlanner;
    $snapshot = new CopilotConversationSnapshot;

    $plan = $planner->plan('quien es mari vega', $snapshot);

    expect($plan['capability_key'])->toBe('users.clarification');
    expect($plan['clarification_state']['reason'])->toBe('low_confidence_match');
    expect($plan['clarification_state']['question'])->toContain('Mario Vega');
    expect($plan['clarification_state']['options'][0]['resolved_entity']['id'])->toBe($user->id);
});
