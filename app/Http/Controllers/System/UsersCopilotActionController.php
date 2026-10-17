<?php

namespace App\Http\Controllers\System;

use App\Ai\Services\CopilotActionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\System\Users\ExecuteUsersCopilotActionRequest;
use Illuminate\Http\JsonResponse;

class UsersCopilotActionController extends Controller
{
    public function __construct(protected CopilotActionService $copilotActionService) {}

    public function __invoke(ExecuteUsersCopilotActionRequest $request): JsonResponse
    {
        return response()->json($this->copilotActionService->execute(
            actor: $request->user(),
            actionType: $request->actionType(),
            validated: $request->validated(),
            ipAddress: $request->ip(),
        ));
    }
}
