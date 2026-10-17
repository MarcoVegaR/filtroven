<?php

namespace App\Ai\Services\Planning\Stages;

use App\Ai\Services\Planning\PlannerStage;
use App\Ai\Services\Planning\PlanningContext;
use App\Ai\Services\UsersCopilotRequestPlanner;

/**
 * Rama 3: "continua", "sigue", "amplia" explicitos que reusan snapshot fresco.
 */
final class ConversationContinuationStage implements PlannerStage
{
    public function handle(PlanningContext $context, UsersCopilotRequestPlanner $planner): ?array
    {
        if (! $planner->looksLikeConversationContinuation($context->normalized)) {
            return null;
        }

        return $planner->resolveContinuation($context->normalized, $context->snapshot);
    }

    public function name(): string
    {
        return 'conversation_continuation';
    }
}
