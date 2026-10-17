<?php

namespace App\Ai\Services\Planning\Stages;

use App\Ai\Services\Planning\PlannerStage;
use App\Ai\Services\Planning\PlanningContext;
use App\Ai\Services\UsersCopilotRequestPlanner;

/**
 * Rama 7: "por que esta inactivo", "por que no puede X" -> explain action.
 */
final class ActionExplanationStage implements PlannerStage
{
    public function handle(PlanningContext $context, UsersCopilotRequestPlanner $planner): ?array
    {
        return $planner->matchActionExplanationIntent($context->normalized);
    }

    public function name(): string
    {
        return 'action_explanation';
    }
}
