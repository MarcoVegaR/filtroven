<?php

namespace App\Ai\Agents\System;

use App\Ai\Middleware\AttachCopilotContext;
use App\Ai\Middleware\AuthorizeCopilotAccess;
use App\Ai\Middleware\LogCopilotUsage;
use App\Ai\Middleware\SanitizeCopilotPrompt;
use App\Ai\Support\BaseCopilotAgent;
use App\Ai\Support\CopilotConversationSnapshot;
use App\Ai\Support\CopilotProviderProfile;
use App\Ai\Support\UsersCopilotPrompt;
use App\Models\User;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Stringable;

class UsersGeminiCopilotAgent extends BaseCopilotAgent implements Agent, Conversational, HasMiddleware, HasProviderOptions
{
    /**
     * @param  array<string, mixed>|null  $planningContext
     */
    public function __construct(
        User $actor,
        ?User $subjectUser = null,
        string $channel = 'web',
        protected ?array $planningContext = null,
        protected ?CopilotConversationSnapshot $conversationSnapshot = null,
    ) {
        parent::__construct($actor, $subjectUser, $channel);
    }

    public function instructions(): Stringable|string
    {
        return UsersCopilotPrompt::instructions(
            $this,
            CopilotProviderProfile::forProvider($this->provider()),
        );
    }

    public function middleware(): array
    {
        return [
            new AuthorizeCopilotAccess,
            new SanitizeCopilotPrompt,
            new AttachCopilotContext,
            new LogCopilotUsage,
        ];
    }

    public function providerOptions(Lab|string $provider): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function planningContext(): ?array
    {
        return $this->planningContext;
    }

    public function conversationSnapshot(): ?CopilotConversationSnapshot
    {
        return $this->conversationSnapshot;
    }
}
