<?php

namespace App\Http\Controllers\System;

use App\Ai\Services\CopilotConversationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\System\Users\StoreUsersCopilotMessageRequest;
use Illuminate\Http\JsonResponse;

class UsersCopilotMessageController extends Controller
{
    public function __invoke(StoreUsersCopilotMessageRequest $request, CopilotConversationService $conversationService): JsonResponse
    {
        return response()->json($conversationService->respond(
            actor: $request->user(),
            prompt: $request->validated('prompt'),
            conversationId: $request->validated('conversation_id'),
            subjectUserId: $request->integer('subject_user_id') ?: null,
        ));
    }
}
