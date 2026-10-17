<?php

namespace App\Ai\Services\Planning\Stages;

use App\Ai\Services\Planning\PlannerStage;
use App\Ai\Services\Planning\PlanningContext;
use App\Ai\Services\UsersCopilotRequestPlanner;

/**
 * Fix Fase 5: detecta intent FUERTE de crear usuario y salta EntityResolution.
 *
 * Sin este stage, prompts como "creame el usuario con laura@example.com" o
 * "usuario nuevo llamado Ana con email..." pasan por EntityResolution, que
 * busca el email, no lo encuentra, y emite un `missing_entity` falso.
 *
 * Precedencia: inmediatamente antes de EntityResolution.
 */
final class CreateUserIntentStage implements PlannerStage
{
    public function handle(PlanningContext $context, UsersCopilotRequestPlanner $planner): ?array
    {
        if (! $planner->looksLikeStrongCreateUserIntent($context->normalized)) {
            return null;
        }

        // Si la ruta tiene un subject_user explicito, el usuario ya esta en
        // detail context: no crear, dejar que SubjectUserStage decida.
        if ($context->subjectUser !== null) {
            return null;
        }

        return $planner->actionPlan($context->normalized, null);
    }

    public function name(): string
    {
        return 'create_user_intent';
    }
}
