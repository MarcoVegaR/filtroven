<?php

namespace App\Ai\Services\Planning;

use App\Ai\Services\UsersCopilotRequestPlanner;

/**
 * Fase 2 estructural: ejecuta un conjunto ordenado de stages hasta que una
 * devuelve un plan. El pipeline no aplica fallback implicito: si ninguna
 * stage responde, retorna null y el planner decide.
 *
 * Diseno explicito:
 * - Determinista: mismo input, misma salida, mismo stage ganador.
 * - Inspectable: el stage ganador se adjunta al plan como
 *   `_pipeline_stage` para telemetria y debugging.
 * - Composable: los stages se inyectan; cualquier test puede reemplazarlos.
 */
final class PlanningPipeline
{
    /**
     * @param  list<PlannerStage>  $stages
     */
    public function __construct(
        private readonly array $stages,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function handle(PlanningContext $context, UsersCopilotRequestPlanner $planner): ?array
    {
        foreach ($this->stages as $stage) {
            $plan = $stage->handle($context, $planner);

            if ($plan !== null) {
                $plan['_pipeline_stage'] = $stage->name();

                return $plan;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function stageNames(): array
    {
        return array_map(static fn (PlannerStage $stage): string => $stage->name(), $this->stages);
    }
}
