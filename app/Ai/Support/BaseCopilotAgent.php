<?php

namespace App\Ai\Support;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Promptable;

abstract class BaseCopilotAgent
{
    use Promptable;

    protected ?string $conversationId = null;

    protected ?User $conversationUser = null;

    protected ?float $promptStartedAt = null;

    public function __construct(
        protected User $actor,
        protected ?User $subjectUser = null,
        protected string $channel = 'web',
    ) {
        $this->conversationUser = $actor;
    }

    public function actor(): User
    {
        return $this->actor;
    }

    public function subjectUser(): ?User
    {
        return $this->subjectUser;
    }

    public function module(): string
    {
        return 'users';
    }

    public function channel(): string
    {
        return $this->channel;
    }

    public function provider(): string|array
    {
        return config('ai-copilot.providers.default', config('ai.default'));
    }

    public function model(): ?string
    {
        return config('ai-copilot.model');
    }

    public function timeout(): int
    {
        return (int) config('ai-copilot.limits.timeout', 30);
    }

    /**
     * Start a new remembered conversation for the given actor.
     */
    public function forUser(User $user): static
    {
        $this->conversationId = null;
        $this->conversationUser = $user;

        return $this;
    }

    /**
     * Continue a remembered conversation for the given actor.
     */
    public function continue(string $conversationId, User $as): static
    {
        $this->conversationId = $conversationId;
        $this->conversationUser = $as;

        return $this;
    }

    public function messages(): iterable
    {
        if ($this->conversationId === null) {
            return [];
        }

        return resolve(ConversationStore::class)
            ->getLatestConversationMessages($this->conversationId, $this->maxConversationMessages())
            ->all();
    }

    public function currentConversation(): ?string
    {
        return $this->conversationId;
    }

    public function hasConversationParticipant(): bool
    {
        return $this->conversationUser !== null;
    }

    public function conversationParticipant(): ?User
    {
        return $this->conversationUser;
    }

    public function conversationTitleFor(string $prompt): string
    {
        return Str::of($prompt)
            ->squish()
            ->limit(100, preserveWords: true)
            ->value();
    }

    public function markPromptStartedAt(float $startedAt): void
    {
        $this->promptStartedAt = $startedAt;
    }

    public function promptStartedAt(): ?float
    {
        return $this->promptStartedAt;
    }

    public function subjectContextInstructions(): string
    {
        $subjectUser = $this->subjectUser();

        if (! $subjectUser instanceof User) {
            return '';
        }

        return sprintf(
            "\n\nContexto del usuario seleccionado:\n- id: %d\n- name: %s\n- email: %s\n- is_active: %s\n",
            $subjectUser->id,
            json_encode($subjectUser->name, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            json_encode($subjectUser->email, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $subjectUser->is_active ? 'true' : 'false',
        );
    }

    protected function maxConversationMessages(): int
    {
        return (int) config('ai-copilot.limits.context_window', 20);
    }
}
