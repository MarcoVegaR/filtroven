<?php

namespace App\Ai\Services\Planning\Stages;

use App\Ai\Services\Planning\PlannerStage;
use App\Ai\Services\Planning\PlanningContext;
use App\Ai\Services\UsersCopilotRequestPlanner;

/**
 * Rama 8a: intenta resolver el prompt como detalle de una entidad concreta
 * (por email o nombre). Cachea el resultado en `$context->entityPlan` para
 * que stages posteriores (SubjectUser/EntityFallback) lo reusen sin doble
 * consulta a DB.
 *
 * Solo gana si hay match entity + prompt "explicito" de detalle (ej:
 * "revisa al usuario X", "que permisos tiene X").
 */
final class EntityResolutionStage implements PlannerStage
{
    public function handle(PlanningContext $context, UsersCopilotRequestPlanner $planner): ?array
    {
        // No ejecutar resolucion cuando hay subject_user explicito o el prompt
        // es crear usuario (la resolucion es costosa y ambigua en esos casos).
        if ($context->subjectUser === null && ! $planner->isCreateUserProposal($context->normalized)) {
            $context->entityPlan = $planner->resolveEntityDrivenPlan($context->normalized, $context->snapshot);
        }

        if ($context->entityPlan === null) {
            return null;
        }

        if ($planner->looksLikeExplicitCollectionSearch($context->normalized)) {
            return null;
        }

        if ($planner->looksLikeActionExplanationPrompt($context->normalized)) {
            return null;
        }

        if (! $planner->looksLikeExplicitDetailPrompt($context->normalized)) {
            return null;
        }

        return $context->entityPlan;
    }

    public function name(): string
    {
        return 'entity_resolution';
    }
}
