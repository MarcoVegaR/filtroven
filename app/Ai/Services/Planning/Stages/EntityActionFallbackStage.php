<?php

namespace App\Ai\Services\Planning\Stages;

use App\Ai\Services\Planning\PlannerStage;
use App\Ai\Services\Planning\PlanningContext;
use App\Ai\Services\UsersCopilotRequestPlanner;
use App\Models\User;

/**
 * Rama 16: si hay entityPlan pero el prompt es una accion, procesar como
 * accion usando la entidad resuelta o el snapshot single result.
 */
final class EntityActionFallbackStage implements PlannerStage
{
    public function handle(PlanningContext $context, UsersCopilotRequestPlanner $planner): ?array
    {
        if ($context->entityPlan === null) {
            return null;
        }

        if (! $planner->looksLikeActionProposal($context->normalized)) {
            return null;
        }

        $resolvedUserId = $context->entityPlan['resolved_entity']['id'] ?? null;

        if ($resolvedUserId !== null) {
            $resolvedUser = User::query()->find($resolvedUserId);

            if ($resolvedUser instanceof User) {
                return $planner->actionPlan($context->normalized, $resolvedUser);
            }
        }

        // Fallback a snapshot single result
        if ($resolvedUserId === null && $context->snapshot->singleResultUserId() !== null) {
            $snapshotUser = User::query()->find($context->snapshot->singleResultUserId());

            if ($snapshotUser instanceof User) {
                return $planner->actionPlan($context->normalized, $snapshotUser);
            }
        }

        return null;
    }

    public function name(): string
    {
        return 'entity_action_fallback';
    }
}
