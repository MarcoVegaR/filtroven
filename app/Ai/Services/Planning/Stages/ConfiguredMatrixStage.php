<?php

namespace App\Ai\Services\Planning\Stages;

use App\Ai\Services\Planning\PlannerStage;
use App\Ai\Services\Planning\PlanningContext;
use App\Ai\Services\UsersCopilotRequestPlanner;

/**
 * Rama 6: matrix determinista configurada en `ai-copilot.planning.matrices`.
 */
final class ConfiguredMatrixStage implements PlannerStage
{
    public function handle(PlanningContext $context, UsersCopilotRequestPlanner $planner): ?array
    {
        return $planner->matchConfiguredMatrix($context->normalized, $context->snapshot);
    }

    public function name(): string
    {
        return 'configured_matrix';
    }
}
