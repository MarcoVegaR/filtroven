<?php

namespace App\Ai\Middleware;

use App\Ai\Support\BaseCopilotAgent;
use Closure;
use Laravel\Ai\Prompts\AgentPrompt;

class LogCopilotUsage
{
    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        $agent = $prompt->agent;

        if ($agent instanceof BaseCopilotAgent) {
            $agent->markPromptStartedAt(microtime(true));
        }

        return $next($prompt);
    }
}
