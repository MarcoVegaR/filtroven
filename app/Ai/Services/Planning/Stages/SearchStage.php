<?php

namespace App\Ai\Services\Planning\Stages;

use App\Ai\Services\Planning\PlannerStage;
use App\Ai\Services\Planning\PlanningContext;
use App\Ai\Services\UsersCopilotRequestPlanner;

/**
 * Rama 15: busqueda generica con verbos "busca/lista/muestra".
 */
final class SearchStage implements PlannerStage
{
    public function handle(PlanningContext $context, UsersCopilotRequestPlanner $planner): ?array
    {
        if (! $planner->looksLikeSearch($context->normalized)) {
            return null;
        }

        return $planner->searchPlan($context->normalized);
    }

    public function name(): string
    {
        return 'search';
    }
}
