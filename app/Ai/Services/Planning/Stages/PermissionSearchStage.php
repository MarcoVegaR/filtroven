<?php

namespace App\Ai\Services\Planning\Stages;

use App\Ai\Services\Planning\PlannerStage;
use App\Ai\Services\Planning\PlanningContext;
use App\Ai\Services\UsersCopilotRequestPlanner;

/**
 * Rama 13: "quienes tienen permiso X", "que usuarios pueden Y" -> search
 * filtrado por permiso.
 */
final class PermissionSearchStage implements PlannerStage
{
    public function handle(PlanningContext $context, UsersCopilotRequestPlanner $planner): ?array
    {
        return $planner->matchPermissionSearchIntent($context->normalized);
    }

    public function name(): string
    {
        return 'permission_search';
    }
}
