<?php

use App\Ai\Services\Users\UsersMetricsAggregator;
use App\Ai\Services\UsersCopilotCapabilityExecutor;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessModulePermissionsSeeder;
use Database\Seeders\AiCopilotPermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(AccessModulePermissionsSeeder::class);
    $this->seed(AiCopilotPermissionsSeeder::class);
});

it('computes exact aggregate metrics from backend truth', function () {
    $ops = Role::factory()->active()->create(['name' => 'ops', 'display_name' => 'Operaciones']);
    $support = Role::factory()->active()->create(['name' => 'support', 'display_name' => 'Soporte']);

    $activeVerified = User::factory()->active()->create();
    $activeVerified->assignRole($ops);

    $inactiveVerified = User::factory()->inactive()->create();
    $inactiveVerified->assignRole($ops);

    $activeUnverified = User::factory()->active()->unverified()->create();
    $activeUnverified->assignRole($support);

    User::factory()->inactive()->unverified()->create();

    $aggregator = new UsersMetricsAggregator;

    expect($aggregator->total()['metric']['value'])->toBe(4)
        ->and($aggregator->active()['metric']['value'])->toBe(2)
        ->and($aggregator->inactive()['metric']['value'])->toBe(2)
        ->and($aggregator->withRoles()['metric']['value'])->toBe(3)
        ->and($aggregator->withoutRoles()['metric']['value'])->toBe(1)
        ->and($aggregator->verified()['metric']['value'])->toBe(2)
        ->and($aggregator->unverified()['metric']['value'])->toBe(2)
        ->and($aggregator->roleDistribution()['breakdown'])
        ->toBe([
            ['key' => 'ops', 'label' => 'Operaciones', 'value' => 2],
            ['key' => 'support', 'label' => 'Soporte', 'value' => 1],
        ])
        ->and($aggregator->mostCommonRole()['metric'])
        ->toBe([
            'label' => 'Operaciones',
            'value' => 2,
            'unit' => 'users',
        ]);
});

it('returns deterministic execution envelopes for aggregate capabilities only', function () {
    $executor = new UsersCopilotCapabilityExecutor(new UsersMetricsAggregator);

    User::factory()->count(3)->create();

    $result = $executor->execute('users.metrics.total');
    $unsupported = $executor->execute('users.search');

    expect($result)
        ->toMatchArray([
            'capability_key' => 'users.metrics.total',
            'family' => 'aggregate',
            'outcome' => 'ok',
        ])
        ->and($result['answer_facts']['metric']['value'])->toBe(3)
        ->and($result['cards'][0]['kind'])->toBe('metrics')
        ->and($result['snapshot_updates'])
        ->toMatchArray([
            'last_capability_key' => 'users.metrics.total',
            'last_intent_family' => 'read_metrics',
            'last_result_count' => 3,
        ])
        ->and($unsupported['outcome'])->toBe('out_of_scope')
        ->and($unsupported['answer_facts'])->toBe([]);
});
