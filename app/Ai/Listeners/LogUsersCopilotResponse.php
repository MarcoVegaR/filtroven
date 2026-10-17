<?php

namespace App\Ai\Listeners;

use App\Ai\Support\BaseCopilotAgent;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Events\AgentPrompted;

class LogUsersCopilotResponse
{
    public function handle(AgentPrompted $event): void
    {
        if (! config('ai-copilot.observability.enabled')) {
            return;
        }

        if (! $event->prompt->agent instanceof BaseCopilotAgent) {
            return;
        }

        $agent = $event->prompt->agent;
        $durationMs = null;

        if ($agent->promptStartedAt() !== null) {
            $durationMs = (int) round((microtime(true) - $agent->promptStartedAt()) * 1000);
        }

        Log::info('ai-copilot.users.usage', [
            'conversation_id' => property_exists($event->response, 'conversationId')
                ? $event->response->conversationId
                : null,
            'actor_id' => $agent->actor()->id,
            'module' => $agent->module(),
            'channel' => $agent->channel(),
            'provider' => $event->response->meta->provider,
            'model' => $event->response->meta->model,
            'tool_names' => $event->response->toolCalls
                ->map(fn ($toolCall): ?string => $toolCall->name ?? null)
                ->filter()
                ->values()
                ->all(),
            'timing_ms' => $durationMs,
            'usage' => $event->response->usage->toArray(),
            'correlation_id' => Context::get('correlation_id'),
            'diagnostics' => config('ai-copilot.observability.debug') ? [
                'invocation_id' => $event->invocationId,
                'structured' => method_exists($event->response, 'toArray') ? array_keys($event->response->toArray()) : null,
            ] : null,
        ]);
    }
}
