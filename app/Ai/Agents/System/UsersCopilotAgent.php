<?php

namespace App\Ai\Agents\System;

use App\Ai\Middleware\AttachCopilotContext;
use App\Ai\Middleware\AuthorizeCopilotAccess;
use App\Ai\Middleware\LogCopilotUsage;
use App\Ai\Middleware\SanitizeCopilotPrompt;
use App\Ai\Support\BaseCopilotAgent;
use App\Ai\Support\CopilotConversationSnapshot;
use App\Ai\Support\CopilotProviderProfile;
use App\Ai\Support\CopilotStructuredOutput;
use App\Ai\Support\UsersCopilotPrompt;
use App\Ai\Tools\System\Users\ActivateUserTool;
use App\Ai\Tools\System\Users\CreateUserTool;
use App\Ai\Tools\System\Users\DeactivateUserTool;
use App\Ai\Tools\System\Users\GetUserDetailTool;
use App\Ai\Tools\System\Users\GetUsersMetricsTool;
use App\Ai\Tools\System\Users\SearchUsersTool;
use App\Ai\Tools\System\Users\SendUserPasswordResetTool;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Stringable;

#[MaxSteps(4)]
#[MaxTokens(1200)]
#[Temperature(0.2)]
class UsersCopilotAgent extends BaseCopilotAgent implements Agent, Conversational, HasMiddleware, HasProviderOptions, HasStructuredOutput, HasTools
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

    public function schema(JsonSchema $schema): array
    {
        return CopilotStructuredOutput::schema($schema, $this->structuredOutputProfile());
    }

    public function providerOptions(Lab|string $provider): array
    {
        return [];
    }

    public function tools(): iterable
    {
        $profile = CopilotProviderProfile::forProvider($this->provider());

        if ($profile->shouldPreferDeterministicMetricsTool($this->planningContext)) {
            return [
                new GetUsersMetricsTool($this->actor()),
                new GetUserDetailTool($this->actor()),
                new ActivateUserTool($this->actor()),
                new DeactivateUserTool($this->actor()),
                new SendUserPasswordResetTool($this->actor()),
                new CreateUserTool($this->actor()),
            ];
        }

        return [
            new GetUsersMetricsTool($this->actor()),
            new SearchUsersTool($this->actor()),
            new GetUserDetailTool($this->actor()),
            new ActivateUserTool($this->actor()),
            new DeactivateUserTool($this->actor()),
            new SendUserPasswordResetTool($this->actor()),
            new CreateUserTool($this->actor()),
        ];
    }

    protected function structuredOutputProfile(): string
    {
        return CopilotProviderProfile::forProvider($this->provider())->schemaProfile;
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
