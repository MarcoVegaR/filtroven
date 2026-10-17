<?php

namespace App\Ai\Services\Planning\Stages;

use App\Ai\Services\Planning\PlannerStage;
use App\Ai\Services\Planning\PlanningContext;
use App\Ai\Services\UsersCopilotRequestPlanner;

/**
 * Rama 14: action + bulk simultaneos ("desactiva a todos los inactivos") ->
 * denegacion por unsupported_bulk.
 */
final class BulkActionDenialStage implements PlannerStage
{
    public function handle(PlanningContext $context, UsersCopilotRequestPlanner $planner): ?array
    {
        if (! $planner->looksLikeActionProposal($context->normalized)) {
            return null;
        }

        if (! $planner->looksLikeBulkAction($context->normalized)) {
            return null;
        }

        return $planner->clarificationPlan(
            normalized: $context->normalized,
            reason: 'denied:unsupported_bulk',
            question: 'No puedo ejecutar acciones masivas desde el copiloto. Indica un usuario especifico para continuar.',
        );
    }

    public function name(): string
    {
        return 'bulk_action_denial';
    }
}
