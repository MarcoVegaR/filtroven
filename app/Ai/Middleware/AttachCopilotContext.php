<?php

namespace App\Ai\Middleware;

use App\Ai\Support\BaseCopilotAgent;
use Closure;
use Laravel\Ai\Prompts\AgentPrompt;

class AttachCopilotContext
{
    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        $agent = $prompt->agent;

        if (! $agent instanceof BaseCopilotAgent) {
            return $next($prompt);
        }

        $actor = $agent->actor();
        $subjectUser = $agent->subjectUser();

        $context = [
            'CONTEXTO_OPERATIVO:',
            '- actor.id: '.$actor->id,
            '- actor.name: '.$actor->name,
            '- actor.permissions: '.json_encode([
                'users_view' => $actor->hasPermissionTo('system.users.view'),
                'users_create' => $actor->hasPermissionTo('system.users.create'),
                'users_update' => $actor->hasPermissionTo('system.users.update'),
                'users_deactivate' => $actor->hasPermissionTo('system.users.deactivate'),
                'users_assign_role' => $actor->hasPermissionTo('system.users.assign-role'),
                'users_send_reset' => $actor->hasPermissionTo('system.users.send-reset'),
                'copilot_execute' => $actor->hasPermissionTo('system.users-copilot.execute'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            '- module: '.$agent->module(),
            '- channel: '.$agent->channel(),
        ];

        if ($subjectUser !== null) {
            $context[] = '- subject_user: '.json_encode([
                'id' => $subjectUser->id,
                'name' => $subjectUser->name,
                'email' => $subjectUser->email,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $next($prompt->prepend(implode(PHP_EOL, $context)));
    }
}
