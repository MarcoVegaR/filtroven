<?php

namespace App\Ai\Services\Planning\Stages;

use App\Ai\Services\Planning\PlannerStage;
use App\Ai\Services\Planning\PlanningContext;
use App\Ai\Services\UsersCopilotRequestPlanner;

/**
 * Rama 2: gate global de staleness. Si el snapshot esta `stale` y el prompt
 * es deictico, pide confirmacion antes de reusar contexto.
 */
final class StalenessConfirmationStage implements PlannerStage
{
    public function handle(PlanningContext $context, UsersCopilotRequestPlanner $planner): ?array
    {
        if (! $planner->shouldRequireContinuationConfirmation($context->normalized, $context->snapshot)) {
            return null;
        }

        return $planner->continuationConfirmPlan($context->normalized, $context->snapshot);
    }

    public function name(): string
    {
        return 'staleness_confirmation';
    }
}
