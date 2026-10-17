<?php

namespace App\Ai\Services\Planning;

use App\Ai\Services\UsersCopilotRequestPlanner;

/**
 * Fase 2 estructural: contrato de una etapa del pipeline.
 *
 * Cada stage recibe el contexto y el planner como dependencia (los stages son
 * stateless y solo delegan a los helpers del planner). Devuelve:
 * - Un array plan cuando la stage gana la precedencia.
 * - null cuando la stage no aplica y se debe pasar a la siguiente.
 */
interface PlannerStage
{
    /**
     * @return array<string, mixed>|null
     */
    public function handle(PlanningContext $context, UsersCopilotRequestPlanner $planner): ?array;

    /**
     * Identificador estable del stage para telemetria y debugging.
     */
    public function name(): string;
}
