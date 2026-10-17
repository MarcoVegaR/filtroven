<?php

namespace App\Ai\Services\Planning\Stages;

use App\Ai\Services\Planning\PlannerStage;
use App\Ai\Services\Planning\PlanningContext;
use App\Ai\Services\UsersCopilotRequestPlanner;

/**
 * Rama 10: mixed intent que combina metricas y busqueda (ej: "cuantos admin
 * hay y muestrame sus nombres").
 */
final class MixedMetricsSearchStage implements PlannerStage
{
    public function handle(PlanningContext $context, UsersCopilotRequestPlanner $planner): ?array
    {
        return $planner->matchMixedMetricsSearchIntent($context->normalized);
    }

    public function name(): string
    {
        return 'mixed_metrics_search';
    }
}
