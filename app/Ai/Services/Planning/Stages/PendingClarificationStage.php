<?php

namespace App\Ai\Services\Planning\Stages;

use App\Ai\Services\Planning\PlannerStage;
use App\Ai\Services\Planning\PlanningContext;
use App\Ai\Services\UsersCopilotRequestPlanner;

/**
 * Rama 5: si el snapshot tiene una clarificacion pendiente, se intenta
 * resolver con el nuevo prompt (elige opcion, refina target, etc.).
 */
final class PendingClarificationStage implements PlannerStage
{
    public function handle(PlanningContext $context, UsersCopilotRequestPlanner $planner): ?array
    {
        return $planner->resolvePendingClarification($context->normalized, $context->snapshot);
    }

    public function name(): string
    {
        return 'pending_clarification';
    }
}
