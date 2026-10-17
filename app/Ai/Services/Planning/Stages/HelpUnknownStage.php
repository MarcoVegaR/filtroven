<?php

namespace App\Ai\Services\Planning\Stages;

use App\Ai\Services\Planning\PlannerStage;
use App\Ai\Services\Planning\PlanningContext;
use App\Ai\Services\UsersCopilotRequestPlanner;

/**
 * Rama 20 (terminal): fallback a `users.help.unknown`. Si el clasificador
 * LLM esta habilitado, intenta un rescate antes de devolver el unknown plan.
 * Garantizado: esta stage siempre produce un plan.
 */
final class HelpUnknownStage implements PlannerStage
{
    public function handle(PlanningContext $context, UsersCopilotRequestPlanner $planner): ?array
    {
        $fallback = $planner->helpUnknownPlan($context->normalized);

        return $planner->attemptLLMRescue(
            $context->normalized,
            $fallback,
            $context->snapshot,
            $context->subjectUser,
        );
    }

    public function name(): string
    {
        return 'help_unknown';
    }
}
