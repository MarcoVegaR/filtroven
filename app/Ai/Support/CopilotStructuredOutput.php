<?php

namespace App\Ai\Support;

use App\Ai\Tools\System\Users\SearchUsersTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Arr;
use JsonException;
use Laravel\Ai\Responses\AgentResponse;

class CopilotStructuredOutput
{
    public const PROFILE_DEFAULT = 'default';

    public const PROFILE_GEMINI = 'gemini';

    public const CARD_KINDS = [
        'notice',
        'search_results',
        'user_context',
        'metrics',
        'clarification',
        // Fase 1a: denied y continuation_confirm como cards de primera clase
        'denied',
        'continuation_confirm',
        // Fase 3: partial_notice para mixed-intent con segmentos no ejecutados
        'partial_notice',
    ];

    public const INTENTS = [
        'help',
        'metrics',
        'search_results',
        'user_context',
        'action_proposal',
        'ambiguous',
        'out_of_scope',
        'error',
        // Fase 1a: denied e continuation_confirm como intents diferenciados
        'denied',
        'continuation_confirm',
        // Fase 3: partial para respuestas incompletas honestas
        'partial',
    ];

    public const LEGACY_INTENTS = ['inform'];

    public const RESPONSE_SOURCES = ['native_tools', 'local_orchestrator', 'fallback'];

    /**
     * Categorias validas de denegacion por contenido.
     */
    public const DENIAL_CATEGORIES = [
        'sensitive_data',
        'impersonation',
        'unsupported_operation',
        'unsupported_bulk',
        'privilege_escalation',
        'bypass_policy',
    ];

    /**
     * Get the structured schema shared by copilot agents.
     *
     * @return array<string, Type>
     */
    public static function schema(JsonSchema $schema, string $profile = self::PROFILE_DEFAULT): array
    {
        if ($profile === self::PROFILE_GEMINI) {
            return self::geminiSchema($schema);
        }

        return [
            'answer' => $schema->string()->required(),
            'intent' => $schema->string()->enum([
                ...self::INTENTS,
                ...self::LEGACY_INTENTS,
            ])->required(),
            'cards' => $schema->array()->items(
                $schema->object([
                    'kind' => $schema->string()->enum(self::CARD_KINDS)->required(),
                    'title' => $schema->string()->nullable()->required(),
                    'summary' => $schema->string()->nullable()->required(),
                    'data_json' => $schema->string()->required(),
                ])->withoutAdditionalProperties()
            )->default([])->required(),
            'actions' => $schema->array()->items(
                $schema->object([
                    'kind' => $schema->string()->enum(['action_proposal'])->required(),
                    'action_type' => $schema->string()->enum(array_column(CopilotActionType::cases(), 'value'))->required(),
                    'target_json' => $schema->string()->required(),
                    'summary' => $schema->string()->required(),
                    'payload_json' => $schema->string()->required(),
                    'can_execute' => $schema->boolean()->required(),
                    'deny_reason' => $schema->string()->nullable()->required(),
                    'required_permissions' => $schema->array()->items(
                        $schema->string()
                    )->default([])->required(),
                ])->withoutAdditionalProperties()
            )->default([])->required(),
            'requires_confirmation' => $schema->boolean()->required(),
            'references' => $schema->array()->items(
                $schema->object([
                    'label' => $schema->string()->required(),
                    'href' => $schema->string()->nullable()->required(),
                ])->withoutAdditionalProperties()
            )->default([])->required(),
            'meta' => $schema->object([
                'module' => $schema->string()->required(),
                'channel' => $schema->string()->required(),
                'subject_user_id' => $schema->integer()->nullable()->required(),
                'fallback' => $schema->boolean()->required(),
                'capability_key' => $schema->string()->nullable()->required(),
                'intent_family' => $schema->string()->nullable()->required(),
                'conversation_state_version' => $schema->integer()->nullable()->required(),
                'response_source' => $schema->string()->enum(self::RESPONSE_SOURCES)->nullable()->required(),
                'diagnostics_json' => $schema->string()->nullable()->required(),
            ])->withoutAdditionalProperties()->required(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public static function normalize(array $payload, string $profile = self::PROFILE_DEFAULT): ?array
    {
        $requiredKeys = ['answer', 'intent', 'cards', 'actions', 'requires_confirmation', 'references', 'meta'];

        foreach ($requiredKeys as $requiredKey) {
            if (! array_key_exists($requiredKey, $payload)) {
                return null;
            }
        }

        $cards = [];

        foreach (Arr::wrap($payload['cards']) as $card) {
            if (! is_array($card)) {
                return null;
            }

            $data = $card['data'] ?? self::decodeJsonObject($card['data_json'] ?? null);

            if (! is_array($data)) {
                return null;
            }

            $kind = $card['kind'] ?? null;

            $cards[] = [
                'kind' => $kind,
                'title' => $card['title'] ?? null,
                'summary' => $card['summary'] ?? null,
                'data' => self::normalizeCardData($kind, $data),
            ];
        }

        $actions = [];

        foreach (Arr::wrap($payload['actions']) as $action) {
            if (! is_array($action)) {
                return null;
            }

            $target = $action['target'] ?? self::decodeJsonObject($action['target_json'] ?? null);
            $actionPayload = $action['payload'] ?? self::decodeJsonObject($action['payload_json'] ?? null);

            if (! is_array($target) || ! is_array($actionPayload)) {
                return null;
            }

            $actions[] = [
                'kind' => $action['kind'] ?? null,
                'action_type' => $action['action_type'] ?? null,
                'target' => $target,
                'summary' => $action['summary'] ?? null,
                'payload' => $actionPayload,
                'can_execute' => (bool) ($action['can_execute'] ?? false),
                'deny_reason' => $action['deny_reason'] ?? null,
                'required_permissions' => array_values(array_filter(
                    Arr::wrap($action['required_permissions'] ?? []),
                    static fn (mixed $permission): bool => is_string($permission) && $permission !== '',
                )),
            ];
        }

        $meta = is_array($payload['meta']) ? $payload['meta'] : null;

        if ($meta === null) {
            return null;
        }

        $diagnostics = $meta['diagnostics'] ?? self::decodeJsonObject($meta['diagnostics_json'] ?? null);

        if ($diagnostics !== null && ! is_array($diagnostics)) {
            return null;
        }

        $intent = self::normalizeIntent($payload['intent'] ?? null, $cards);
        $fallback = (bool) ($meta['fallback'] ?? false);

        return [
            'answer' => $payload['answer'],
            'intent' => $intent,
            'cards' => $cards,
            'actions' => $actions,
            'requires_confirmation' => (bool) $payload['requires_confirmation'],
            'references' => array_values(array_filter(
                Arr::wrap($payload['references']),
                static fn (mixed $reference): bool => is_array($reference),
            )),
            'meta' => [
                'module' => $meta['module'] ?? 'users',
                'channel' => $meta['channel'] ?? 'web',
                'subject_user_id' => $meta['subject_user_id'] ?? null,
                'fallback' => $fallback,
                'capability_key' => is_string($meta['capability_key'] ?? null) ? $meta['capability_key'] : null,
                'intent_family' => is_string($meta['intent_family'] ?? null)
                    ? $meta['intent_family']
                    : self::defaultIntentFamily($intent),
                'conversation_state_version' => is_numeric($meta['conversation_state_version'] ?? null)
                    ? (int) $meta['conversation_state_version']
                    : null,
                'response_source' => self::normalizeResponseSource(
                    $meta['response_source'] ?? null,
                    $fallback,
                    $diagnostics,
                ),
                'diagnostics' => $diagnostics,
            ],
            'resolution' => self::normalizeResolution($payload['resolution'] ?? null, $intent, $cards, $actions, (bool) $payload['requires_confirmation'], $meta, $diagnostics),
        ];
    }

    public static function profileForProvider(string|array|null $provider): string
    {
        return CopilotProviderProfile::forProvider($provider)->schemaProfile;
    }

    /**
     * @return array<string, mixed>
     */
    public static function normalizeResponse(AgentResponse $response, CopilotProviderProfile $profile, ?int $subjectUserId): array
    {
        $payload = self::extractPayloadFromResponse($response, $profile);

        if (! is_array($payload)) {
            return self::fallback(
                diagnostics: ['reason' => 'missing_structured_output'],
                subjectUserId: $subjectUserId,
            );
        }

        $normalized = self::normalize($payload, $profile->schemaProfile);

        if ($normalized === null) {
            return self::fallback(
                diagnostics: ['reason' => 'invalid_structured_output'],
                subjectUserId: $subjectUserId,
            );
        }

        return self::repairSearchResultsPayload($normalized, $response);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>|null  $executionResult
     * @return array<string, mixed>
     */
    public static function reconstruct(
        array $payload,
        array $plan,
        CopilotConversationSnapshot $snapshot,
        ?array $executionResult = null,
        string $responseSource = 'native_tools',
        ?int $subjectUserId = null,
    ): array {
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $cards = array_values(array_filter(Arr::wrap($payload['cards'] ?? []), static fn (mixed $card): bool => is_array($card)));
        $actions = array_values(array_filter(Arr::wrap($payload['actions'] ?? []), static fn (mixed $action): bool => is_array($action)));
        $intentFamily = $plan['intent_family'] ?? ($meta['intent_family'] ?? null);
        $capabilityKey = $plan['capability_key'] ?? ($meta['capability_key'] ?? null);

        if ($intentFamily !== 'action_proposal') {
            $actions = [];
        }

        if ($intentFamily === 'ambiguous' || self::hasClarificationCard($cards)) {
            $clarification = self::firstClarificationCard($cards);
            $question = Arr::get($clarification, 'data.question')
                ?? Arr::get($clarification, 'summary')
                ?? $payload['answer']
                ?? 'Necesito una aclaracion para continuar.';

            $cards = $clarification === null ? [] : [$clarification];
            $actions = [];

            return self::withResolution([
                'answer' => is_string($question) ? $question : 'Necesito una aclaracion para continuar.',
                'intent' => 'ambiguous',
                'cards' => $cards,
                'actions' => [],
                'requires_confirmation' => false,
                'references' => [],
                'meta' => [
                    ...$meta,
                    'module' => 'users',
                    'channel' => $meta['channel'] ?? 'web',
                    'subject_user_id' => $subjectUserId,
                    'fallback' => (bool) ($meta['fallback'] ?? false),
                    'capability_key' => $capabilityKey ?? 'users.clarification',
                    'intent_family' => 'ambiguous',
                    'conversation_state_version' => $snapshot->version(),
                    'response_source' => $responseSource,
                    'diagnostics' => array_filter([
                        ...(is_array($meta['diagnostics'] ?? null) ? $meta['diagnostics'] : []),
                        'repair' => 'clarification_authoritative',
                    ]),
                ],
            ]);
        }

        if ($capabilityKey === 'users.snapshot.result_count') {
            $count = $snapshot->lastResultCount();
            $filters = $snapshot->lastFilters();

            return self::withResolution([
                'answer' => $count === 1
                    ? 'El subconjunto actual tiene 1 usuario.'
                    : 'El subconjunto actual tiene '.(int) $count.' usuarios.',
                'intent' => 'metrics',
                'cards' => [[
                    'kind' => 'metrics',
                    'title' => 'Conteo del subconjunto actual',
                    'summary' => 'Este conteo reutiliza el ultimo resultado confirmado de la conversacion.',
                    'data' => [
                        'capability_key' => 'users.snapshot.result_count',
                        'metric' => [
                            'label' => 'Usuarios del subconjunto actual',
                            'value' => $count,
                            'unit' => 'users',
                        ],
                        'breakdown' => [],
                        'applied_filters' => $filters === [] ? null : $filters,
                    ],
                ]],
                'actions' => [],
                'requires_confirmation' => false,
                'references' => Arr::wrap($payload['references'] ?? []),
                'meta' => [
                    ...$meta,
                    'module' => 'users',
                    'channel' => $meta['channel'] ?? 'web',
                    'subject_user_id' => $subjectUserId,
                    'fallback' => false,
                    'capability_key' => 'users.snapshot.result_count',
                    'intent_family' => 'read_metrics',
                    'conversation_state_version' => $snapshot->version(),
                    'response_source' => $responseSource,
                    'diagnostics' => array_filter([
                        ...(is_array($meta['diagnostics'] ?? null) ? $meta['diagnostics'] : []),
                        'source_of_truth' => 'conversation_snapshot',
                    ]),
                ],
            ]);
        }

        $canConfirm = count($actions) > 0
            && collect($actions)->contains(static fn (array $action): bool => (bool) ($action['can_execute'] ?? false));

        return self::withResolution([
            ...$payload,
            'actions' => $actions,
            'requires_confirmation' => $intentFamily === 'action_proposal' ? $canConfirm : false,
            'meta' => [
                ...$meta,
                'module' => 'users',
                'channel' => $meta['channel'] ?? 'web',
                'subject_user_id' => $subjectUserId,
                'fallback' => (bool) ($meta['fallback'] ?? false),
                'capability_key' => $capabilityKey,
                'intent_family' => is_string($intentFamily) ? $intentFamily : self::defaultIntentFamily((string) ($payload['intent'] ?? 'help')),
                'conversation_state_version' => $snapshot->version(),
                'response_source' => $responseSource,
                'diagnostics' => is_array($meta['diagnostics'] ?? null) ? $meta['diagnostics'] : null,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function extractPayloadFromResponse(AgentResponse $response, CopilotProviderProfile $profile): ?array
    {
        if ($response instanceof Arrayable) {
            $payload = $response->toArray();

            return is_array($payload) ? $payload : null;
        }

        if ($profile->usesTextJsonResponses()) {
            return self::decodeTextPayload($response->text);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function decodeTextPayload(?string $text): ?array
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $text = trim($text);

        if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $text, $matches) === 1) {
            $text = trim($matches[1]);
        }

        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Build a safe fallback response for malformed or unavailable output paths.
     *
     * @param  array<string, mixed>  $diagnostics
     * @return array<string, mixed>
     */
    public static function fallback(
        ?string $answer = null,
        array $diagnostics = [],
        ?int $subjectUserId = null,
    ): array {
        return self::withResolution([
            'answer' => $answer ?? config('ai-copilot.fallback.message'),
            'intent' => 'error',
            'cards' => [],
            'actions' => [],
            'requires_confirmation' => false,
            'references' => [],
            'meta' => [
                'module' => 'users',
                'channel' => 'web',
                'subject_user_id' => $subjectUserId,
                'fallback' => true,
                'capability_key' => null,
                'intent_family' => null,
                'conversation_state_version' => null,
                'response_source' => 'fallback',
                'diagnostics' => empty($diagnostics) ? null : $diagnostics,
            ],
        ]);
    }

    /**
     * @return array<string, Type>
     */
    protected static function geminiSchema(JsonSchema $schema): array
    {
        return [
            'answer' => $schema->string()->required(),
            'intent' => $schema->string()->enum([
                ...self::INTENTS,
                ...self::LEGACY_INTENTS,
            ])->required(),
            'cards' => $schema->array()->items(
                $schema->object([
                    'kind' => $schema->string()->enum(self::CARD_KINDS)->required(),
                    'title' => $schema->string()->nullable(),
                    'summary' => $schema->string()->nullable(),
                    'data_json' => $schema->string()->required(),
                ])->withoutAdditionalProperties()
            )->default([]),
            'actions' => $schema->array()->items(
                $schema->object([
                    'kind' => $schema->string()->enum(['action_proposal'])->required(),
                    'action_type' => $schema->string()->enum(array_column(CopilotActionType::cases(), 'value'))->required(),
                    'target_json' => $schema->string()->required(),
                    'summary' => $schema->string()->required(),
                    'payload_json' => $schema->string()->required(),
                    'can_execute' => $schema->boolean()->required(),
                    'deny_reason' => $schema->string()->nullable(),
                    'required_permissions' => $schema->array()->items(
                        $schema->string()
                    )->default([]),
                ])->withoutAdditionalProperties()
            )->default([]),
            'requires_confirmation' => $schema->boolean()->required(),
            'references' => $schema->array()->items(
                $schema->object([
                    'label' => $schema->string()->required(),
                    'href' => $schema->string()->nullable(),
                ])->withoutAdditionalProperties()
            )->default([]),
            'meta' => $schema->object([
                'module' => $schema->string()->required(),
                'channel' => $schema->string()->required(),
                'subject_user_id' => $schema->integer()->nullable(),
                'fallback' => $schema->boolean()->required(),
                'capability_key' => $schema->string()->nullable(),
                'intent_family' => $schema->string()->nullable(),
                'conversation_state_version' => $schema->integer()->nullable(),
                'response_source' => $schema->string()->enum(self::RESPONSE_SOURCES)->nullable(),
                'diagnostics_json' => $schema->string()->nullable(),
            ])->withoutAdditionalProperties()->required(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected static function decodeJsonObject(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected static function normalizeCardData(mixed $kind, array $data): array
    {
        if ($kind === 'metrics') {
            $metric = is_array($data['metric'] ?? null) ? $data['metric'] : [];

            return [
                'capability_key' => is_string($data['capability_key'] ?? null) ? $data['capability_key'] : null,
                'metric' => [
                    'label' => is_string($metric['label'] ?? null) ? $metric['label'] : null,
                    'value' => is_numeric($metric['value'] ?? null) ? (int) $metric['value'] : null,
                    'unit' => in_array($metric['unit'] ?? null, ['users', 'roles'], true) ? $metric['unit'] : 'users',
                ],
                'breakdown' => array_values(array_map(
                    static fn (array $item): array => [
                        'key' => is_string($item['key'] ?? null) ? $item['key'] : '',
                        'label' => is_string($item['label'] ?? null) ? $item['label'] : '',
                        'value' => is_numeric($item['value'] ?? null) ? (int) $item['value'] : 0,
                    ],
                    array_filter(
                        Arr::wrap($data['breakdown'] ?? []),
                        static fn (mixed $item): bool => is_array($item),
                    ),
                )),
                'applied_filters' => is_array($data['applied_filters'] ?? null)
                    ? $data['applied_filters']
                    : null,
            ];
        }

        if ($kind === 'clarification') {
            return [
                'reason' => is_string($data['reason'] ?? null) ? $data['reason'] : 'missing_context',
                'question' => is_string($data['question'] ?? null) ? $data['question'] : '',
                'options' => array_values(array_map(
                    static fn (array $option): array => [
                        'label' => is_string($option['label'] ?? null) ? $option['label'] : '',
                        'value' => is_string($option['value'] ?? null) ? $option['value'] : '',
                    ],
                    array_filter(
                        Arr::wrap($data['options'] ?? []),
                        static fn (mixed $option): bool => is_array($option),
                    ),
                )),
            ];
        }

        if ($kind === 'denied') {
            $category = is_string($data['category'] ?? null) ? $data['category'] : 'unsupported_operation';
            if (! in_array($category, self::DENIAL_CATEGORIES, true)) {
                $category = 'unsupported_operation';
            }

            return [
                'category' => $category,
                'reason' => is_string($data['reason'] ?? null) ? $data['reason'] : $category,
                'message' => is_string($data['message'] ?? null) ? $data['message'] : '',
                'alternatives' => array_values(array_map(
                    static fn (array $alt): array => [
                        'label' => is_string($alt['label'] ?? null) ? $alt['label'] : '',
                        'prompt' => is_string($alt['prompt'] ?? null) ? $alt['prompt'] : '',
                    ],
                    array_filter(
                        Arr::wrap($data['alternatives'] ?? []),
                        static fn (mixed $alt): bool => is_array($alt),
                    ),
                )),
            ];
        }

        if ($kind === 'continuation_confirm') {
            return [
                'freshness' => in_array($data['freshness'] ?? null, ['stale', 'expired'], true)
                    ? $data['freshness']
                    : 'stale',
                'question' => is_string($data['question'] ?? null) ? $data['question'] : '',
                'entity_label' => is_string($data['entity_label'] ?? null) ? $data['entity_label'] : null,
                'minutes_elapsed' => is_numeric($data['minutes_elapsed'] ?? null) ? (int) $data['minutes_elapsed'] : null,
                'options' => array_values(array_map(
                    static fn (array $option): array => [
                        'label' => is_string($option['label'] ?? null) ? $option['label'] : '',
                        'value' => is_string($option['value'] ?? null) ? $option['value'] : '',
                    ],
                    array_filter(
                        Arr::wrap($data['options'] ?? []),
                        static fn (mixed $option): bool => is_array($option),
                    ),
                )),
            ];
        }

        if ($kind === 'partial_notice') {
            return [
                'segments' => array_values(array_map(
                    static fn (array $segment): array => [
                        'text' => is_string($segment['text'] ?? null) ? $segment['text'] : '',
                        'status' => in_array($segment['status'] ?? null, ['not_executed', 'failed', 'skipped'], true)
                            ? $segment['status']
                            : 'not_executed',
                        'reason' => is_string($segment['reason'] ?? null) ? $segment['reason'] : '',
                        'suggested_follow_up' => is_string($segment['suggested_follow_up'] ?? null)
                            ? $segment['suggested_follow_up']
                            : null,
                    ],
                    array_filter(
                        Arr::wrap($data['segments'] ?? []),
                        static fn (mixed $segment): bool => is_array($segment),
                    ),
                )),
            ];
        }

        if ($kind !== 'search_results') {
            return $data;
        }

        $users = array_values(array_filter(
            Arr::wrap($data['users'] ?? []),
            static fn (mixed $user): bool => is_array($user),
        ));

        $visibleCount = $data['visible_count'] ?? $data['count'] ?? count($users);
        $visibleCount = is_numeric($visibleCount) ? (int) $visibleCount : count($users);

        $matchingCount = $data['matching_count'] ?? $data['count'] ?? $visibleCount;
        $matchingCount = is_numeric($matchingCount) ? (int) $matchingCount : $visibleCount;

        if ($users === []) {
            $visibleCount = 0;
            $matchingCount = 0;
        }

        return [
            ...$data,
            'count' => max($visibleCount, 0),
            'visible_count' => max($visibleCount, 0),
            'matching_count' => max($matchingCount, 0),
            'truncated' => is_bool($data['truncated'] ?? null) ? $data['truncated'] : $matchingCount > $visibleCount,
            'limit' => is_numeric($data['limit'] ?? null) ? (int) $data['limit'] : 8,
            'count_represents' => is_string($data['count_represents'] ?? null) ? $data['count_represents'] : 'visible_results',
            'list_semantics' => is_string($data['list_semantics'] ?? null) ? $data['list_semantics'] : 'search_results_only',
            'aggregate_safe' => is_bool($data['aggregate_safe'] ?? null) ? $data['aggregate_safe'] : false,
            'users' => $users,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected static function repairSearchResultsPayload(array $payload, AgentResponse $response): array
    {
        $searchResults = self::latestSearchUsersToolResult($response);

        if ($searchResults === null) {
            return $payload;
        }

        $cards = [];
        $hasSearchResultsCard = false;

        foreach (Arr::wrap($payload['cards'] ?? []) as $card) {
            if (! is_array($card)) {
                continue;
            }

            if (($card['kind'] ?? null) !== 'search_results') {
                $cards[] = $card;

                continue;
            }

            $hasSearchResultsCard = true;

            $cards[] = [
                ...$card,
                'data' => self::normalizeCardData('search_results', $searchResults),
                'summary' => $card['summary'] ?? self::defaultSearchResultsSummary($searchResults),
            ];
        }

        if (($payload['intent'] ?? null) === 'search_results' && ! $hasSearchResultsCard) {
            $cards[] = [
                'kind' => 'search_results',
                'title' => 'Resultados de usuarios',
                'summary' => self::defaultSearchResultsSummary($searchResults),
                'data' => self::normalizeCardData('search_results', $searchResults),
            ];
        }

        return [
            ...$payload,
            'cards' => $cards,
            'meta' => [
                ...$payload['meta'],
                'diagnostics' => array_filter([
                    ...(is_array($payload['meta']['diagnostics'] ?? null) ? $payload['meta']['diagnostics'] : []),
                    'search_results_source' => 'tool_results',
                ]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected static function latestSearchUsersToolResult(AgentResponse $response): ?array
    {
        $result = null;

        foreach ($response->toolResults as $toolResult) {
            $name = is_object($toolResult) ? ($toolResult->name ?? null) : ($toolResult['name'] ?? null);

            if ($name !== class_basename(SearchUsersTool::class)) {
                continue;
            }

            $decoded = self::decodeSearchUsersToolResult(
                is_object($toolResult) ? ($toolResult->result ?? null) : ($toolResult['result'] ?? null),
            );

            if ($decoded !== null) {
                $result = $decoded;
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected static function decodeSearchUsersToolResult(mixed $result): ?array
    {
        if (is_array($result)) {
            return self::normalizeCardData('search_results', $result);
        }

        $decoded = self::decodeJsonObject($result);

        return is_array($decoded)
            ? self::normalizeCardData('search_results', $decoded)
            : null;
    }

    /**
     * @param  array<string, mixed>  $searchResults
     */
    protected static function defaultSearchResultsSummary(array $searchResults): string
    {
        $count = (int) ($searchResults['count'] ?? 0);

        return $count === 1
            ? 'Se encontro 1 usuario.'
            : "Se encontraron {$count} usuarios.";
    }

    public static function ensureResolution(array $payload): array
    {
        return self::withResolution($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected static function withResolution(array $payload): array
    {
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $diagnostics = is_array($meta['diagnostics'] ?? null) ? $meta['diagnostics'] : null;
        $cards = array_values(array_filter(Arr::wrap($payload['cards'] ?? []), static fn (mixed $card): bool => is_array($card)));
        $actions = array_values(array_filter(Arr::wrap($payload['actions'] ?? []), static fn (mixed $action): bool => is_array($action)));
        $intent = is_string($payload['intent'] ?? null) ? $payload['intent'] : 'error';

        return [
            ...$payload,
            'resolution' => self::normalizeResolution(
                $payload['resolution'] ?? null,
                $intent,
                $cards,
                $actions,
                (bool) ($payload['requires_confirmation'] ?? false),
                $meta,
                $diagnostics,
            ),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $cards
     * @param  list<array<string, mixed>>  $actions
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>|null  $diagnostics
     * @return array<string, mixed>
     */
    protected static function normalizeResolution(
        mixed $resolution,
        string $intent,
        array $cards,
        array $actions,
        bool $requiresConfirmation,
        array $meta,
        ?array $diagnostics,
    ): array {
        $provided = is_array($resolution) ? $resolution : [];
        $state = self::resolutionState($provided['state'] ?? null, $intent, $cards, $meta);
        $actionBoundary = self::resolutionActionBoundary($provided['action_boundary'] ?? null, $state, $actions, $requiresConfirmation);
        $confidence = in_array($provided['confidence'] ?? null, ['high', 'medium', 'low'], true)
            ? $provided['confidence']
            : ((($meta['response_source'] ?? null) === 'fallback' || (($diagnostics['reason'] ?? null) === 'missing_structured_output')) ? 'low' : 'high');

        return [
            'state' => $state,
            'confidence' => $confidence,
            'action_boundary' => $actionBoundary,
            'understood' => self::resolutionSegments($provided['understood'] ?? null),
            'unresolved' => self::resolutionSegments($provided['unresolved'] ?? null),
            'missing' => self::resolutionMissing($provided['missing'] ?? null, $cards, $meta),
            'denials' => self::resolutionDenials($provided['denials'] ?? null, $cards),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $cards
     * @param  array<string, mixed>  $meta
     */
    protected static function resolutionState(mixed $state, string $intent, array $cards, array $meta): string
    {
        if (in_array($state, ['resolved', 'partial', 'missing_context', 'clarification_required', 'denied', 'not_understood'], true)) {
            return $state;
        }

        if ($intent === 'denied' || self::firstCardOfKind($cards, 'denied') !== null || ($meta['intent_family'] ?? null) === 'denied') {
            return 'denied';
        }

        if ($intent === 'partial' || self::firstCardOfKind($cards, 'partial_notice') !== null) {
            return 'partial';
        }

        if ($intent === 'ambiguous') {
            $clarification = self::firstCardOfKind($cards, 'clarification');

            return (data_get($clarification, 'data.reason') === 'missing_context') ? 'missing_context' : 'clarification_required';
        }

        if ($intent === 'error' || (($meta['fallback'] ?? false) === true)) {
            return 'not_understood';
        }

        return 'resolved';
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     */
    protected static function resolutionActionBoundary(mixed $actionBoundary, string $state, array $actions, bool $requiresConfirmation): string
    {
        if (in_array($actionBoundary, ['none', 'proposed', 'executable', 'executed', 'blocked'], true)) {
            return $actionBoundary;
        }

        if ($state === 'denied') {
            return 'blocked';
        }

        if ($requiresConfirmation || $actions !== []) {
            return collect($actions)->contains(static fn (array $action): bool => (bool) ($action['can_execute'] ?? false))
                ? 'proposed'
                : 'blocked';
        }

        return 'none';
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected static function resolutionSegments(mixed $segments): array
    {
        return array_values(array_filter(Arr::wrap($segments), static fn (mixed $segment): bool => is_array($segment)));
    }

    /**
     * @param  list<array<string, mixed>>  $cards
     * @param  array<string, mixed>  $meta
     * @return list<array<string, mixed>>
     */
    protected static function resolutionMissing(mixed $missing, array $cards, array $meta): array
    {
        $items = self::resolutionSegments($missing);
        $clarification = self::firstCardOfKind($cards, 'clarification');
        $missingSlots = Arr::wrap(data_get($meta, 'diagnostics.missing_slots', []));

        foreach ($missingSlots as $slot) {
            if (is_string($slot) && $slot !== '') {
                $items[] = ['slot' => $slot, 'label' => $slot, 'reason' => 'required'];
            }
        }

        if (data_get($clarification, 'data.reason') === 'missing_context') {
            $items[] = ['slot' => 'context', 'label' => 'Contexto previo', 'reason' => 'missing_context'];
        }

        return $items;
    }

    /**
     * @param  list<array<string, mixed>>  $cards
     * @return list<array<string, mixed>>
     */
    protected static function resolutionDenials(mixed $denials, array $cards): array
    {
        $items = self::resolutionSegments($denials);
        $denied = self::firstCardOfKind($cards, 'denied');

        if ($denied !== null) {
            $items[] = [
                'reason_code' => (string) data_get($denied, 'data.category', 'unsupported_operation'),
                'message' => (string) data_get($denied, 'data.message', data_get($denied, 'summary', 'Solicitud denegada.')),
            ];
        }

        return $items;
    }

    /**
     * @param  list<array<string, mixed>>  $cards
     * @return array<string, mixed>|null
     */
    protected static function firstCardOfKind(array $cards, string $kind): ?array
    {
        foreach ($cards as $card) {
            if (($card['kind'] ?? null) === $kind) {
                return $card;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $cards
     */
    protected static function normalizeIntent(mixed $intent, array $cards): string
    {
        if (! is_string($intent) || $intent === '') {
            return 'error';
        }

        if ($intent !== 'inform') {
            return in_array($intent, self::INTENTS, true) ? $intent : 'error';
        }

        foreach ($cards as $card) {
            $kind = $card['kind'] ?? null;

            if ($kind === 'user_context') {
                return 'user_context';
            }

            if ($kind === 'search_results') {
                return 'search_results';
            }

            if ($kind === 'metrics') {
                return 'metrics';
            }

            if ($kind === 'clarification') {
                return 'ambiguous';
            }

            if ($kind === 'denied') {
                return 'denied';
            }

            if ($kind === 'continuation_confirm') {
                return 'continuation_confirm';
            }

            if ($kind === 'partial_notice') {
                return 'partial';
            }
        }

        return 'help';
    }

    protected static function defaultIntentFamily(string $intent): ?string
    {
        return match ($intent) {
            'metrics' => 'read_metrics',
            'search_results' => 'read_search',
            'user_context' => 'read_detail',
            'action_proposal' => 'action_proposal',
            'ambiguous' => 'ambiguous',
            'help' => 'help',
            'out_of_scope' => 'out_of_scope',
            'denied' => 'denied',
            'continuation_confirm' => 'continuation_confirm',
            'partial' => 'read_search',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>|null  $diagnostics
     */
    protected static function normalizeResponseSource(mixed $source, bool $fallback, ?array $diagnostics): string
    {
        if (is_string($source) && in_array($source, self::RESPONSE_SOURCES, true)) {
            return $source;
        }

        if ($fallback) {
            return 'fallback';
        }

        if (($diagnostics['execution'] ?? null) === 'local_capability_orchestrator') {
            return 'local_orchestrator';
        }

        return 'native_tools';
    }

    /**
     * @param  list<array<string, mixed>>  $cards
     */
    protected static function hasClarificationCard(array $cards): bool
    {
        return self::firstClarificationCard($cards) !== null;
    }

    /**
     * @param  list<array<string, mixed>>  $cards
     * @return array<string, mixed>|null
     */
    protected static function firstClarificationCard(array $cards): ?array
    {
        foreach ($cards as $card) {
            if (($card['kind'] ?? null) === 'clarification') {
                return $card;
            }
        }

        return null;
    }
}
