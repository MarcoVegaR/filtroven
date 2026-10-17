<?php

namespace App\Ai\Middleware;

use Closure;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Prompts\AgentPrompt;

class SanitizeCopilotPrompt
{
    /**
     * @throws ValidationException
     */
    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        $normalizedPrompt = Str::of($prompt->prompt)->squish()->value();

        if (mb_strlen($normalizedPrompt) > (int) config('ai-copilot.limits.prompt_length', 4000)) {
            throw ValidationException::withMessages([
                'prompt' => [__('La solicitud del copiloto es demasiado extensa.')],
            ]);
        }

        if (preg_match('/ignore\s+(all\s+)?previous\s+instructions|system\s+prompt|developer\s+message|<tool_call|function\s+call/iu', $normalizedPrompt) === 1) {
            throw ValidationException::withMessages([
                'prompt' => [__('La solicitud del copiloto contiene instrucciones no permitidas.')],
            ]);
        }

        return $next($prompt->revise($normalizedPrompt));
    }
}
