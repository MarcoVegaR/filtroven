<?php

namespace App\Ai\Services\Planning\Stages;

use App\Ai\Services\Planning\PlannerStage;
use App\Ai\Services\Planning\PlanningContext;
use App\Ai\Services\UsersCopilotRequestPlanner;

final class PendingCreateUserStage implements PlannerStage
{
    public function handle(PlanningContext $context, UsersCopilotRequestPlanner $planner): ?array
    {
        return $planner->matchPendingCreateUserContinuation($context->normalized, $context->snapshot);
    }

    public function name(): string
    {
        return 'pending_create_user';
    }
}
