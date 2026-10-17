<?php

namespace App\Ai\Services\Planning\Stages;

use App\Ai\Services\Planning\PlannerStage;
use App\Ai\Services\Planning\PlanningContext;
use App\Ai\Services\UsersCopilotRequestPlanner;

/**
 * Rama 9: follow-up deictico cuando el snapshot es fresco (count follow-up,
 * subset refinement, entity reference). Gateado por staleness en la stage 2.
 */
final class FollowUpStage implements PlannerStage
{
    public function handle(PlanningContext $context, UsersCopilotRequestPlanner $planner): ?array
    {
        $boundaryPlan = $planner->resolveContextBoundaryPrompt($context->normalized, $context->snapshot);

        if ($boundaryPlan !== null) {
            return $boundaryPlan;
        }

        return $planner->resolveFollowUp($context->normalized, $context->snapshot);
    }

    public function name(): string
    {
        return 'follow_up';
    }
}
