<?php

namespace App\Ai\Services\Planning;

use App\Ai\Support\CopilotConversationSnapshot;
use App\Models\User;

/**
 * Fase 2 estructural: DTO compartido que viaja por el pipeline.
 *
 * El contexto es mutable solo para dos campos:
 * - entityPlan: cache del resultado de resolveEntityDrivenPlan() para evitar
 *   doble consulta a DB entre stages.
 * - snapshot: puede ser reemplazado por el freshness gate (expired => empty).
 *
 * Todos los demas campos son inmutables por disciplina.
 */
final class PlanningContext
{
    /**
     * @var array<string, mixed>|null
     */
    public ?array $entityPlan = null;

    public function __construct(
        public readonly string $originalPrompt,
        public readonly string $normalized,
        public CopilotConversationSnapshot $snapshot,
        public readonly ?User $subjectUser,
        public readonly string $originalFreshness,
    ) {}
}
