<?php

namespace App\Ai\Services\Planning\Stages;

use App\Ai\Services\Planning\PlannerStage;
use App\Ai\Services\Planning\PlanningContext;
use App\Ai\Services\UsersCopilotRequestPlanner;

/**
 * Rama 17: cuando hay entityPlan cacheado y ninguna stage anterior lo uso,
 * es el fallback (se prefirio no devolverlo antes por falta de senales
 * explicitas de detail, pero aca es la ultima oportunidad).
 */
final class EntityPlanFallbackStage implements PlannerStage
{
    public function handle(PlanningContext $context, UsersCopilotRequestPlanner $planner): ?array
    {
        return $context->entityPlan;
    }

    public function name(): string
    {
        return 'entity_plan_fallback';
    }
}
