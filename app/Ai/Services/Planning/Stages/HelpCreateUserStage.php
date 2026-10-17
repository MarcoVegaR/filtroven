<?php

namespace App\Ai\Services\Planning\Stages;

use App\Ai\Services\Planning\PlannerStage;
use App\Ai\Services\Planning\PlanningContext;
use App\Ai\Services\UsersCopilotRequestPlanner;

/**
 * Fix Fase 5: separa semanticamente "ayuda sobre crear usuarios" de "crear
 * usuario ya mismo".
 *
 * Precedencia: ANTES de InformationalHelp y ActionExplanation, porque
 * "como doy de alta" contiene el verbo "alta" que dispara
 * `looksLikeActionProposal` en stages posteriores si no se resuelve aqui.
 */
final class HelpCreateUserStage implements PlannerStage
{
    public function handle(PlanningContext $context, UsersCopilotRequestPlanner $planner): ?array
    {
        if (! $planner->looksLikeCreateUserHelpPrompt($context->normalized)) {
            return null;
        }

        return $planner->helpCreateUserPlan($context->normalized);
    }

    public function name(): string
    {
        return 'help_create_user';
    }
}
