<?php

use App\Ai\Services\Planning\PlannerStage;
use App\Ai\Services\Planning\PlanningContext;
use App\Ai\Services\Planning\PlanningPipeline;
use App\Ai\Services\UsersCopilotRequestPlanner;
use App\Ai\Support\CopilotConversationSnapshot;

/**
 * Fase 2 estructural: verifica que el pipeline:
 * - Respeta el orden de precedencia (primera stage que devuelve gana).
 * - Permite inyectar stages arbitrarias sin tocar el planner.
 * - Comparte contexto entre stages (entityPlan cacheado).
 * - Expone los nombres de las stages para debugging.
 */
it('runs stages in order and stops at the first one that returns a plan', function () {
    $winner = new class implements PlannerStage
    {
        public function handle(PlanningContext $context, UsersCopilotRequestPlanner $planner): ?array
        {
            return [
                'request_normalization' => $context->normalized,
                'intent_family' => 'help',
                'capability_key' => 'test.winner',
                'filters' => [],
                'resolved_entity' => null,
                'missing_slots' => [],
                'clarification_state' => null,
                'proposal_vs_execute' => 'none',
            ];
        }

        public function name(): string
        {
            return 'test_winner';
        }
    };

    $never = new class implements PlannerStage
    {
        public function handle(PlanningContext $context, UsersCopilotRequestPlanner $planner): ?array
        {
            throw new RuntimeException('This stage should never run');
        }

        public function name(): string
        {
            return 'test_never';
        }
    };

    $planner = new UsersCopilotRequestPlanner(new PlanningPipeline([$winner, $never]));

    $plan = $planner->plan('anything', CopilotConversationSnapshot::empty());

    expect($plan['capability_key'])->toBe('test.winner');
});

it('falls back to the help unknown guard when every stage returns null', function () {
    $silent = new class implements PlannerStage
    {
        public function handle(PlanningContext $context, UsersCopilotRequestPlanner $planner): ?array
        {
            return null;
        }

        public function name(): string
        {
            return 'silent';
        }
    };

    $planner = new UsersCopilotRequestPlanner(new PlanningPipeline([$silent]));

    $plan = $planner->plan('untouchable', CopilotConversationSnapshot::empty());

    expect($plan['intent_family'])->toBe('help');
    expect($plan['capability_key'])->toBeIn(['users.help', 'users.help.unknown']);
});

it('shares the planning context across stages', function () {
    $writer = new class implements PlannerStage
    {
        public function handle(PlanningContext $context, UsersCopilotRequestPlanner $planner): ?array
        {
            $context->entityPlan = ['capability_key' => 'test.entity', 'intent_family' => 'read_detail'];

            return null;
        }

        public function name(): string
        {
            return 'writer';
        }
    };

    $reader = new class implements PlannerStage
    {
        public function handle(PlanningContext $context, UsersCopilotRequestPlanner $planner): ?array
        {
            return $context->entityPlan;
        }

        public function name(): string
        {
            return 'reader';
        }
    };

    $planner = new UsersCopilotRequestPlanner(new PlanningPipeline([$writer, $reader]));

    $plan = $planner->plan('anything', CopilotConversationSnapshot::empty());

    expect($plan['capability_key'])->toBe('test.entity');
});

it('exposes the canonical stage names in order', function () {
    $names = (new UsersCopilotRequestPlanner)->stageNames();

    expect($names)->toContain('denial');
    expect($names)->toContain('staleness_confirmation');
    expect($names)->toContain('entity_resolution');
    expect($names)->toContain('help_unknown');

    // La precedencia esencial: denial > staleness > todo lo demas > help_unknown.
    $denialIdx = array_search('denial', $names, true);
    $stalenessIdx = array_search('staleness_confirmation', $names, true);
    $helpUnknownIdx = array_search('help_unknown', $names, true);

    expect($denialIdx)->toBeLessThan($stalenessIdx);
    expect($stalenessIdx)->toBeLessThan($helpUnknownIdx);
});
