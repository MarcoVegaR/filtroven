<?php

namespace App\Ai\Services;

use App\Ai\Agents\System\UsersCopilotAgent;
use App\Ai\Agents\System\UsersGeminiCopilotAgent;
use App\Ai\Support\BaseCopilotAgent;
use App\Ai\Support\CopilotActionType;
use App\Ai\Support\CopilotConversationSnapshot;
use App\Ai\Support\CopilotProviderProfile;
use App\Ai\Support\CopilotStructuredOutput;
use App\Ai\Testing\BrowserCopilotFakeTransport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Responses\AgentResponse;
use Throwable;

class CopilotConversationService
{
    public function __construct(
        protected DatabaseManager $database,
        protected BrowserCopilotFakeTransport $browserFakeTransport,
        protected UsersGeminiCapabilityOrchestrator $usersGeminiCapabilityOrchestrator,
        protected UsersCopilotRequestPlanner $usersCopilotRequestPlanner,
        protected UsersCopilotCapabilityExecutor $usersCopilotCapabilityExecutor,
        protected UsersCopilotResponseBuilder $usersCopilotResponseBuilder,
    ) {}

    /**
     * @return array{conversation_id: string, response: array<string, mixed>}
     */
    public function respond(
        User $actor,
        string $prompt,
        ?string $conversationId = null,
        ?int $subjectUserId = null,
    ): array {
        $subjectUser = $subjectUserId !== null ? User::query()->findOrFail($subjectUserId) : null;

        if ($subjectUser !== null && ! $actor->can('view', $subjectUser)) {
            throw new AuthorizationException(__('No puedes consultar este usuario desde el copiloto.'));
        }

        $agent = $this->makeAgent($actor, $subjectUser);
        $isNewConversation = $conversationId === null;
        $conversationId ??= $this->resolveConversationId($actor, $prompt, $agent);

        $this->assertConversationOwnership($conversationId, $actor);

        $snapshot = $this->loadSnapshot($conversationId);
        $plan = $this->usersCopilotRequestPlanner->plan($prompt, $snapshot, $subjectUser);
        $agent = $this->makeAgent($actor, $subjectUser, $plan, $snapshot);

        $executionResult = $this->plannedExecutionResult($actor, $plan);

        try {
            if ($payload = $this->resolveBrowserFakeResponse($subjectUser?->id)) {
                $this->persistSnapshot(
                    $conversationId,
                    $this->usersCopilotResponseBuilder->nextSnapshot($snapshot, $plan, $payload, $executionResult),
                );

                $this->storeUserMessage($conversationId, $actor, $prompt, $agent);
                $this->storeAssistantPayloadMessage($conversationId, $actor, $payload, $agent, 'browser_file');

                return [
                    'conversation_id' => $conversationId,
                    'response' => $payload,
                ];
            }

            if ($agent instanceof UsersGeminiCopilotAgent) {
                return $this->respondWithGeminiOrchestrator(
                    $agent,
                    $actor,
                    $prompt,
                    $conversationId,
                    $subjectUser?->id,
                    $plan,
                    $snapshot,
                    $executionResult,
                );
            }

            if ($this->shouldBypassProvider($executionResult)) {
                $payload = $this->usersCopilotResponseBuilder->build(
                    plan: $plan,
                    snapshot: $snapshot,
                    executionResult: $executionResult,
                    providerPayload: null,
                    responseSource: 'native_tools',
                    subjectUserId: $subjectUser?->id,
                );

                $this->persistSnapshot(
                    $conversationId,
                    $this->usersCopilotResponseBuilder->nextSnapshot($snapshot, $plan, $payload, $executionResult),
                );

                $this->storeUserMessage($conversationId, $actor, $prompt, $agent);
                $this->storeAssistantPayloadMessage($conversationId, $actor, $payload, $agent, 'native_tools');

                return [
                    'conversation_id' => $conversationId,
                    'response' => $payload,
                ];
            }

            $response = $agent
                ->continue($conversationId, as: $actor)
                ->prompt($prompt);

            $payload = $this->usersCopilotResponseBuilder->build(
                plan: $plan,
                snapshot: $snapshot,
                executionResult: $executionResult,
                providerPayload: $this->normalizeResponse($response, $subjectUser?->id),
                responseSource: 'native_tools',
                subjectUserId: $subjectUser?->id,
            );

            $this->persistSnapshot(
                $conversationId,
                $this->usersCopilotResponseBuilder->nextSnapshot($snapshot, $plan, $payload, $executionResult),
            );

            $this->storeUserMessage($conversationId, $actor, $prompt, $agent);
            $this->storeAssistantMessage($conversationId, $actor, $response, $agent);

            return [
                'conversation_id' => $conversationId,
                'response' => $payload,
            ];
        } catch (AuthorizationException|ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if ($isNewConversation) {
                $this->touchConversation($conversationId);
            }

            return [
                'conversation_id' => $conversationId,
                'response' => CopilotStructuredOutput::fallback(
                    diagnostics: [
                        'reason' => 'agent_unavailable',
                        'exception' => class_basename($exception),
                    ],
                    subjectUserId: $subjectUser?->id,
                ),
            ];
        }
    }

    /**
     * @return array{conversation_id: string, response: array<string, mixed>}
     */
    protected function respondWithGeminiOrchestrator(
        UsersGeminiCopilotAgent $agent,
        User $actor,
        string $prompt,
        string $conversationId,
        ?int $subjectUserId,
        array $plan,
        CopilotConversationSnapshot $snapshot,
        ?array $executionResult,
    ): array {
        $orchestration = $this->usersGeminiCapabilityOrchestrator->prepare(
            $agent,
            $prompt,
            $plan,
            $snapshot,
            $executionResult,
        );
        $diagnostics = [];

        if (mb_strlen($orchestration['prompt']) > (int) config('ai-copilot.limits.prompt_length', 4000)) {
            $payload = $this->usersGeminiCapabilityOrchestrator->finalizePayload(
                $orchestration['payload'],
                null,
                ['formatter_result' => 'skipped_prompt_length'],
            );

            $this->persistSnapshot(
                $conversationId,
                $this->usersCopilotResponseBuilder->nextSnapshot($snapshot, $plan, $payload, $executionResult),
            );

            $this->storeUserMessage($conversationId, $actor, $prompt, $agent);
            $this->storeAssistantPayloadMessage($conversationId, $actor, $payload, $agent, 'gemini_local_orchestrator');

            return [
                'conversation_id' => $conversationId,
                'response' => $payload,
            ];
        }

        try {
            $response = $agent
                ->continue($conversationId, as: $actor)
                ->prompt($orchestration['prompt']);

            $normalized = $this->normalizeResponse($response, $subjectUserId);

            if (($normalized['meta']['fallback'] ?? false) === true) {
                $diagnostics['formatter_reason'] = Arr::get($normalized, 'meta.diagnostics.reason', 'missing_structured_output');
                $normalized = null;
            } else {
                $diagnostics['formatter_result'] = 'gemini_text_json';
            }
        } catch (AuthorizationException|ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $normalized = null;
            $diagnostics['formatter_exception'] = class_basename($exception);
        }

        $payload = $this->usersGeminiCapabilityOrchestrator->finalizePayload(
            $orchestration['payload'],
            $normalized,
            $diagnostics,
        );

        $this->persistSnapshot(
            $conversationId,
            $this->usersCopilotResponseBuilder->nextSnapshot($snapshot, $plan, $payload, $executionResult),
        );

        $this->storeUserMessage($conversationId, $actor, $prompt, $agent);
        $this->storeAssistantPayloadMessage($conversationId, $actor, $payload, $agent, 'gemini_local_orchestrator');

        return [
            'conversation_id' => $conversationId,
            'response' => $payload,
        ];
    }

    protected function resolveConversationId(User $actor, string $prompt, BaseCopilotAgent $agent): string
    {
        $conversationId = (string) Str::uuid7();

        $this->database->table('agent_conversations')->insert([
            'id' => $conversationId,
            'user_id' => $actor->id,
            'title' => $agent->conversationTitleFor($prompt),
            ...CopilotConversationSnapshot::empty()->toDatabase(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $conversationId;
    }

    protected function assertConversationOwnership(string $conversationId, User $actor): void
    {
        $conversation = $this->database->table('agent_conversations')->where('id', $conversationId)->first();

        if ($conversation === null) {
            throw ValidationException::withMessages([
                'conversation_id' => __('La conversacion indicada no existe.'),
            ]);
        }

        if ((int) $conversation->user_id !== $actor->id) {
            throw new AuthorizationException(__('No puedes continuar una conversacion que no te pertenece.'));
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeResponse(AgentResponse $response, ?int $subjectUserId): array
    {
        return CopilotStructuredOutput::normalizeResponse(
            $response,
            CopilotProviderProfile::forProvider($response->meta->provider),
            $subjectUserId,
        );
    }

    protected function storeUserMessage(string $conversationId, User $actor, string $prompt, BaseCopilotAgent $agent): void
    {
        $this->database->table('agent_conversation_messages')->insert([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversationId,
            'user_id' => $actor->id,
            'agent' => $agent::class,
            'role' => 'user',
            'content' => $prompt,
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->touchConversation($conversationId);
    }

    protected function storeAssistantMessage(string $conversationId, User $actor, AgentResponse $response, BaseCopilotAgent $agent): void
    {
        $this->database->table('agent_conversation_messages')->insert([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversationId,
            'user_id' => $actor->id,
            'agent' => $agent::class,
            'role' => 'assistant',
            'content' => $response->text,
            'attachments' => '[]',
            'tool_calls' => json_encode($response->toolCalls),
            'tool_results' => json_encode($response->toolResults),
            'usage' => json_encode($response->usage),
            'meta' => json_encode($response->meta),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->touchConversation($conversationId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function storeAssistantPayloadMessage(
        string $conversationId,
        User $actor,
        array $payload,
        BaseCopilotAgent $agent,
        ?string $payloadSource = null,
    ): void {
        $this->database->table('agent_conversation_messages')->insert([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversationId,
            'user_id' => $actor->id,
            'agent' => $agent::class,
            'role' => 'assistant',
            'content' => (string) Arr::get($payload, 'answer', config('ai-copilot.fallback.message')),
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => json_encode([
                'payload_source' => $payloadSource,
                'payload_meta' => Arr::get($payload, 'meta'),
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->touchConversation($conversationId);
    }

    protected function touchConversation(string $conversationId): void
    {
        $this->database->table('agent_conversations')
            ->where('id', $conversationId)
            ->update(['updated_at' => now()]);
    }

    /**
     * @param  array<string, mixed>|null  $planningContext
     */
    protected function makeAgent(
        User $actor,
        ?User $subjectUser,
        ?array $planningContext = null,
        ?CopilotConversationSnapshot $snapshot = null,
    ): BaseCopilotAgent {
        return CopilotProviderProfile::forProvider(config('ai-copilot.providers.default', config('ai.default')))->usesStructuredResponses()
            ? new UsersCopilotAgent($actor, $subjectUser, planningContext: $planningContext, conversationSnapshot: $snapshot)
            : new UsersGeminiCopilotAgent($actor, $subjectUser, planningContext: $planningContext, conversationSnapshot: $snapshot);
    }

    protected function loadSnapshot(string $conversationId): CopilotConversationSnapshot
    {
        $conversation = $this->database->table('agent_conversations')
            ->select(['snapshot', 'snapshot_version', 'last_turn_at'])
            ->where('id', $conversationId)
            ->first();

        return CopilotConversationSnapshot::fromDatabase(
            $conversation?->snapshot,
            $conversation?->snapshot_version,
            $conversation?->last_turn_at,
        );
    }

    /**
     * Fase 1c: cada persist actualiza `last_turn_at` para el snapshot. Eso
     * alimenta la semantica de freshness tri-valuada.
     */
    protected function persistSnapshot(string $conversationId, CopilotConversationSnapshot $snapshot): void
    {
        $stamped = $snapshot->with(['last_turn_at' => CarbonImmutable::now()]);

        $this->database->table('agent_conversations')
            ->where('id', $conversationId)
            ->update([
                ...$stamped->toDatabase(),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>|null
     */
    protected function plannedExecutionResult(User $actor, array $plan): ?array
    {
        $capabilityKey = $plan['capability_key'] ?? null;

        if ($capabilityKey === 'users.clarification') {
            return [
                'family' => 'clarification',
                'data' => $plan['clarification_state'] ?? [],
            ];
        }

        // Fase 1c: continuation_confirm bypasea provider y va al response builder
        // directamente (staleness semantics).
        if ($capabilityKey === 'users.continuation.confirm') {
            return [
                'family' => 'continuation_confirm',
                'data' => $plan['clarification_state'] ?? [],
            ];
        }

        // Fase 1a: users.denied bypasea provider y va al response builder.
        if ($capabilityKey === 'users.denied') {
            return [
                'family' => 'denied',
                'data' => $plan['clarification_state'] ?? [],
            ];
        }

        if ($capabilityKey === 'users.actions.cancel_pending') {
            return [
                'family' => 'notice',
                'capability_key' => 'users.actions.cancel_pending',
                'outcome' => 'ok',
                'cards' => [[
                    'kind' => 'notice',
                    'title' => 'Propuesta cancelada',
                    'summary' => 'Cancelé la propuesta pendiente. No se ejecutó ninguna acción.',
                    'data' => [
                        'cancelled' => true,
                    ],
                ]],
                'snapshot_updates' => [
                    'pending_action_proposal' => null,
                    'pending_create_user' => null,
                ],
            ];
        }

        if ($capabilityKey === 'users.mixed.metrics_search') {
            return $this->usersCopilotCapabilityExecutor->executeMixedMetricsSearch(
                $actor,
                is_array($plan['filters'] ?? null) ? $plan['filters'] : [],
            );
        }

        if ($capabilityKey === 'users.mixed.create_search') {
            return [
                ...$this->usersCopilotCapabilityExecutor->executeSearch(
                    $actor,
                    is_array($plan['filters'] ?? null) ? $plan['filters'] : [],
                ),
                'capability_key' => 'users.mixed.create_search',
                'family' => 'mixed_partial',
                'diagnostics' => [
                    'executor' => 'users_capability_executor',
                    'source_of_truth' => 'deterministic_backend',
                    'composite' => 'create_plus_search',
                ],
            ];
        }

        if ($capabilityKey === 'users.mixed.create_unsupported') {
            return [
                'capability_key' => 'users.mixed.create_unsupported',
                'family' => 'mixed_partial',
                'outcome' => 'ok',
                'cards' => [],
                'actions' => [],
                'references' => [],
                'snapshot_updates' => [],
                'diagnostics' => [
                    'executor' => 'users_capability_executor',
                    'source_of_truth' => 'deterministic_backend',
                    'composite' => 'create_plus_unsupported',
                ],
            ];
        }

        if ($capabilityKey === 'users.search') {
            return $this->usersCopilotCapabilityExecutor->executeSearch(
                $actor,
                is_array($plan['filters'] ?? null) ? $plan['filters'] : [],
            );
        }

        if ($capabilityKey === 'users.roles.catalog') {
            return $this->usersCopilotCapabilityExecutor->executeRolesCatalog();
        }

        if ($capabilityKey === 'users.detail' && is_numeric(data_get($plan, 'resolved_entity.id'))) {
            return $this->usersCopilotCapabilityExecutor->executeDetail(
                $actor,
                (int) data_get($plan, 'resolved_entity.id'),
            );
        }

        if ($capabilityKey === 'users.explain.permission'
            && is_numeric(data_get($plan, 'resolved_entity.id'))
            && is_string(data_get($plan, 'filters.permission'))) {
            return $this->usersCopilotCapabilityExecutor->executePermissionExplanation(
                $actor,
                (int) data_get($plan, 'resolved_entity.id'),
                (string) data_get($plan, 'filters.permission'),
            );
        }

        if ($capabilityKey === 'users.explain.action'
            && is_numeric(data_get($plan, 'resolved_entity.id'))
            && is_numeric(data_get($plan, 'filters.target_user_id'))
            && is_string(data_get($plan, 'filters.action_type'))) {
            return $this->usersCopilotCapabilityExecutor->executeActionExplanation(
                $actor,
                (int) data_get($plan, 'resolved_entity.id'),
                (int) data_get($plan, 'filters.target_user_id'),
                CopilotActionType::from((string) data_get($plan, 'filters.action_type')),
            );
        }

        if ($capabilityKey === 'users.explain.capabilities_summary'
            && is_numeric(data_get($plan, 'resolved_entity.id'))) {
            return $this->usersCopilotCapabilityExecutor->executeCapabilitiesSummary(
                $actor,
                (int) data_get($plan, 'resolved_entity.id'),
            );
        }

        if (is_string($capabilityKey)
            && str_starts_with($capabilityKey, 'users.actions.')
            && $capabilityKey !== 'users.actions.create_user'
            && is_numeric(data_get($plan, 'resolved_entity.id'))) {
            return $this->usersCopilotCapabilityExecutor->executeActionProposal(
                $actor,
                $capabilityKey,
                (int) data_get($plan, 'resolved_entity.id'),
            );
        }

        if ($capabilityKey === 'users.actions.create_user') {
            return $this->usersCopilotCapabilityExecutor->executeCreateUserProposal(
                $actor,
                is_array($plan['create_user_payload'] ?? null) ? $plan['create_user_payload'] : [],
            );
        }

        if (! is_string($capabilityKey) || ! in_array($capabilityKey, UsersCopilotCapabilityCatalog::aggregateKeys(), true)) {
            return null;
        }

        return $this->usersCopilotCapabilityExecutor->execute($capabilityKey);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveBrowserFakeResponse(?int $subjectUserId): ?array
    {
        $payload = $this->browserFakeTransport->dequeue();

        if ($payload === null) {
            return null;
        }

        $normalized = CopilotStructuredOutput::normalize($payload);

        return $normalized ?? CopilotStructuredOutput::fallback(
            diagnostics: ['reason' => 'invalid_browser_fake_transport'],
            subjectUserId: $subjectUserId,
        );
    }

    /**
     * @param  array<string, mixed>|null  $executionResult
     */
    protected function shouldBypassProvider(?array $executionResult): bool
    {
        $family = $executionResult['family'] ?? null;

        return in_array($family, [
            'aggregate',
            'list',
            'detail',
            'action',
            'mixed',
            'mixed_partial',
            'explain',
            'clarification',
            'notice',
            // Fase 1a/1c: denied y continuation_confirm no necesitan provider.
            'denied',
            'continuation_confirm',
        ], true);
    }
}
