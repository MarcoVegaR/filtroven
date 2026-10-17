<?php

namespace App\Ai\Services\Planning\Stages;

use App\Ai\Services\Planning\PlannerStage;
use App\Ai\Services\Planning\PlanningContext;
use App\Ai\Services\UsersCopilotRequestPlanner;

/**
 * Rama 12: metricas puras (total, activos, inactivos, admin_access, etc.).
 */
final class MetricsStage implements PlannerStage
{
    public function handle(PlanningContext $context, UsersCopilotRequestPlanner $planner): ?array
    {
        return $planner->matchMetricsIntent($context->normalized);
    }

    public function name(): string
    {
        return 'metrics';
    }
}
