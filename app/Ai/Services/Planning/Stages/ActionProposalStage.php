<?php

namespace App\Ai\Services\Planning\Stages;

use App\Ai\Services\Planning\PlannerStage;
use App\Ai\Services\Planning\PlanningContext;
use App\Ai\Services\UsersCopilotRequestPlanner;
use App\Models\User;

/**
 * Rama 19: accion sin entity resuelta. Intenta usar subject_user o snapshot
 * single result como sujeto implicito.
 */
final class ActionProposalStage implements PlannerStage
{
    public function handle(PlanningContext $context, UsersCopilotRequestPlanner $planner): ?array
    {
        if (! $planner->looksLikeActionProposal($context->normalized)) {
            return null;
        }

        $actionSubject = $context->subjectUser;

        if (! $actionSubject instanceof User && $context->snapshot->singleResultUserId() !== null) {
            $actionSubject = User::query()->find($context->snapshot->singleResultUserId());
        }

        if (! $actionSubject instanceof User && ! $planner->isCreateUserProposal($context->normalized) && ! $context->snapshot->hasContext()) {
            return $planner->resolveContextBoundaryPrompt($context->normalized, $context->snapshot);
        }

        return $planner->actionPlan($context->normalized, $actionSubject);
    }

    public function name(): string
    {
        return 'action_proposal';
    }
}
