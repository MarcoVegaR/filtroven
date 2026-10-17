<?php

namespace App\Ai\Services\Planning\Stages;

use App\Ai\Services\Planning\PlannerStage;
use App\Ai\Services\Planning\PlanningContext;
use App\Ai\Services\UsersCopilotRequestPlanner;
use App\Ai\Support\UsersCopilotDomainLexicon;
use App\Models\User;

/**
 * Rama 18: si la ruta tiene un subject_user explicito (detail page del
 * controller), el prompt se resuelve como capabilities summary, permission
 * explain, o detail del usuario, excepto cuando es una accion.
 */
final class SubjectUserStage implements PlannerStage
{
    public function handle(PlanningContext $context, UsersCopilotRequestPlanner $planner): ?array
    {
        if (! $context->subjectUser instanceof User) {
            return null;
        }

        if ($planner->looksLikeActionProposal($context->normalized)) {
            return null;
        }

        if ($planner->looksLikeCapabilitiesSummaryPrompt($context->normalized)) {
            return $planner->capabilitiesSummaryPlan($context->normalized, $context->subjectUser);
        }

        if ($permission = UsersCopilotDomainLexicon::permissionNameForIntent($context->normalized)) {
            return $planner->permissionExplainPlan($context->normalized, $context->subjectUser, $permission);
        }

        return $planner->detailPlan($context->normalized, $context->subjectUser);
    }

    public function name(): string
    {
        return 'subject_user';
    }
}
