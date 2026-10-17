<?php

namespace App\Ai\Middleware;

use App\Ai\Support\BaseCopilotAgent;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Laravel\Ai\Prompts\AgentPrompt;

class AuthorizeCopilotAccess
{
    /**
     * @throws AuthorizationException
     */
    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        $agent = $prompt->agent;

        if (! $agent instanceof BaseCopilotAgent) {
            return $next($prompt);
        }

        $actor = $agent->actor();
        $module = $agent->module();
        $channel = $agent->channel();

        if (! config('ai-copilot.enabled')
            || ! config("ai-copilot.modules.{$module}.enabled")
            || ! config("ai-copilot.channels.{$channel}.enabled")
            || ! $actor->hasPermissionTo('system.users-copilot.view')) {
            throw new AuthorizationException(__('No tienes acceso al copiloto de usuarios.'));
        }

        return $next($prompt);
    }
}
