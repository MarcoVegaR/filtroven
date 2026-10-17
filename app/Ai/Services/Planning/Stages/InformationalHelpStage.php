<?php

namespace App\Ai\Services\Planning\Stages;

use App\Ai\Services\Planning\PlannerStage;
use App\Ai\Services\Planning\PlanningContext;
use App\Ai\Services\UsersCopilotRequestPlanner;

/**
 * Rama 4: prompts informativos reconocidos ("como revisar un usuario").
 * Fase 1d: se resuelven como `users.help.informational`.
 */
final class InformationalHelpStage implements PlannerStage
{
    public function handle(PlanningContext $context, UsersCopilotRequestPlanner $planner): ?array
    {
        if (! $planner->looksLikeInformationalPrompt($context->normalized)) {
            return null;
        }

        return $planner->helpInformationalPlan($context->normalized);
    }

    public function name(): string
    {
        return 'informational_help';
    }
}
