<?php

namespace App\Ai\Services\Planning\Stages;

use App\Ai\Services\Planning\PlannerStage;
use App\Ai\Services\Planning\PlanningContext;
use App\Ai\Services\UsersCopilotRequestPlanner;

/**
 * Rama 11: "cuales roles existen", "lista los roles" -> catalogo de roles.
 */
final class RolesCatalogStage implements PlannerStage
{
    public function handle(PlanningContext $context, UsersCopilotRequestPlanner $planner): ?array
    {
        return $planner->matchRolesCatalogIntent($context->normalized);
    }

    public function name(): string
    {
        return 'roles_catalog';
    }
}
