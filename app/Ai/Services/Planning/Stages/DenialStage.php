<?php

namespace App\Ai\Services\Planning\Stages;

use App\Ai\Services\Planning\PlannerStage;
use App\Ai\Services\Planning\PlanningContext;
use App\Ai\Services\UsersCopilotRequestPlanner;

/**
 * Rama 1 (maxima precedencia): denial gate para solicitudes sensibles,
 * impersonation y operaciones no soportadas.
 */
final class DenialStage implements PlannerStage
{
    public function handle(PlanningContext $context, UsersCopilotRequestPlanner $planner): ?array
    {
        return $planner->matchSensitiveDenial($context->normalized);
    }

    public function name(): string
    {
        return 'denial';
    }
}
