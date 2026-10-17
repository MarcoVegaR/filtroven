<?php

namespace App\Ai\Services;

use App\Ai\Support\CopilotConversationSnapshot;
use App\Ai\Support\CopilotDenialCatalog;
use App\Ai\Support\CopilotPlannerTelemetry;
use App\Ai\Support\CopilotStructuredOutput;
use App\Ai\Support\SearchQualityScorer;
use App\Ai\Support\UsersCopilotDomainLexicon;
use Illuminate\Support\Arr;

class UsersCopilotResponseBuilder
{
    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>|null  $executionResult
     * @param  array<string, mixed>|null  $providerPayload
     * @return array<string, mixed>
     */
    public function build(
        array $plan,
        CopilotConversationSnapshot $snapshot,
        ?array $executionResult = null,
        ?array $providerPayload = null,
        string $responseSource = 'native_tools',
        ?int $subjectUserId = null,
    ): array {
        // Fase 1a: rechazo explicito con intent 'denied' cuando el plan viene de
        // matchSensitiveDenial (reason con prefijo "denied:"). Antes se emitia
        // como 'ambiguous' mezclado con clarificaciones genuinas.
        if ($this->isDeniedPlan($plan) && $this->isDeniedIntentEnabled()) {
            return $this->withInterpretation(
                $this->deniedPayload($plan, $snapshot, $responseSource, $subjectUserId),
                $plan,
                'deterministic_denial',
            );
        }

        // Fase 1c: confirmacion de continuacion stale.
        if (($plan['intent_family'] ?? null) === 'continuation_confirm'
            && is_array($plan['clarification_state'] ?? null)
            && $this->isStalenessConfirmationEnabled()) {
            return $this->withInterpretation(
                $this->continuationConfirmPayload($plan, $snapshot, $responseSource, $subjectUserId),
                $plan,
                'snapshot_stale',
            );
        }

        if (($plan['intent_family'] ?? null) === 'ambiguous' && is_array($plan['clarification_state'] ?? null)) {
            return $this->withInterpretation(
                $this->clarificationPayload($plan, $snapshot, $responseSource, $subjectUserId),
                $plan,
                'deterministic',
            );
        }

        if (($executionResult['family'] ?? null) === 'mixed' && ($executionResult['outcome'] ?? null) === 'ok') {
            return $this->withInterpretation(
                $this->mixedMetricsSearchPayload($plan, $snapshot, $executionResult, $responseSource, $subjectUserId),
                $plan,
                'deterministic',
            );
        }

        if (($executionResult['family'] ?? null) === 'mixed_partial' && ($executionResult['outcome'] ?? null) === 'ok') {
            return $this->withInterpretation(
                $this->mixedPartialPayload($plan, $snapshot, $executionResult, $responseSource, $subjectUserId),
                $plan,
                'deterministic',
            );
        }

        if (($executionResult['family'] ?? null) !== 'action' && is_array($executionResult['cards'][0] ?? null) && (($executionResult['cards'][0]['kind'] ?? null) === 'notice')) {
            return $this->withInterpretation(
                $this->noticePayload($plan, $snapshot, $executionResult, $responseSource, $subjectUserId),
                $plan,
                'deterministic',
            );
        }

        if (($executionResult['family'] ?? null) === 'aggregate' && ($executionResult['outcome'] ?? null) === 'ok') {
            return $this->withInterpretation(
                $this->metricsPayload($plan, $snapshot, $executionResult, $responseSource, $subjectUserId),
                $plan,
                'deterministic',
            );
        }

        if (($executionResult['family'] ?? null) === 'list' && ($executionResult['outcome'] ?? null) === 'ok') {
            return $this->withInterpretation(
                $this->searchPayload($plan, $snapshot, $executionResult, $responseSource, $subjectUserId),
                $plan,
                'deterministic',
            );
        }

        if (($executionResult['family'] ?? null) === 'detail' && ($executionResult['outcome'] ?? null) === 'ok') {
            return $this->withInterpretation(
                $this->detailPayload($plan, $snapshot, $executionResult, $responseSource, $subjectUserId),
                $plan,
                'deterministic',
            );
        }

        if (($executionResult['family'] ?? null) === 'action' && ($executionResult['outcome'] ?? null) === 'ok') {
            return $this->withInterpretation(
                $this->actionProposalPayload($plan, $snapshot, $executionResult, $responseSource, $subjectUserId),
                $plan,
                'deterministic',
            );
        }

        $payload = is_array($providerPayload) ? $providerPayload : [
            'answer' => config('ai-copilot.fallback.message'),
            'intent' => 'help',
            'cards' => [],
            'actions' => [],
            'requires_confirmation' => false,
            'references' => [],
            'meta' => [
                'module' => 'users',
                'channel' => 'web',
                'subject_user_id' => $subjectUserId,
                'fallback' => false,
                'diagnostics' => ['reason' => 'missing_provider_payload'],
            ],
        ];

        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];

        $payload['meta'] = [
            ...$meta,
            'module' => 'users',
            'channel' => $meta['channel'] ?? 'web',
            'subject_user_id' => $subjectUserId,
            'fallback' => (bool) ($meta['fallback'] ?? false),
            'capability_key' => $plan['capability_key'] ?? ($meta['capability_key'] ?? null),
            'intent_family' => $plan['intent_family'] ?? ($meta['intent_family'] ?? null),
            'conversation_state_version' => $snapshot->version(),
            'response_source' => $meta['response_source'] ?? $responseSource,
            'diagnostics' => is_array($meta['diagnostics'] ?? null) ? $meta['diagnostics'] : null,
        ];

        return $this->withInterpretation(
            CopilotStructuredOutput::reconstruct(
                payload: $payload,
                plan: $plan,
                snapshot: $snapshot,
                executionResult: $executionResult,
                responseSource: $responseSource,
                subjectUserId: $subjectUserId,
            ),
            $plan,
            'provider',
        );
    }

    // ── Fase 1a/1b/1c helpers ────────────────────────────────────────────

    protected function isDeniedIntentEnabled(): bool
    {
        return (bool) config('ai-copilot.contracts.denied_intent', false);
    }

    protected function isInterpretationEnabled(): bool
    {
        return (bool) config('ai-copilot.contracts.interpretation', false);
    }

    protected function isStalenessConfirmationEnabled(): bool
    {
        return (bool) config('ai-copilot.contracts.staleness_confirmation', false);
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    protected function isDeniedPlan(array $plan): bool
    {
        $reason = (string) data_get($plan, 'clarification_state.reason', '');

        return str_starts_with($reason, 'denied:');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    protected function withInterpretation(array $payload, array $plan, string $source): array
    {
        $payload = CopilotStructuredOutput::ensureResolution($payload);

        if (! $this->isInterpretationEnabled()) {
            return $payload;
        }

        $payload['interpretation'] = $this->buildInterpretation($plan, $source);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    protected function buildInterpretation(array $plan, string $source): array
    {
        $filters = array_filter(
            is_array($plan['filters'] ?? null) ? $plan['filters'] : [],
            static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== [],
        );

        $entity = is_array($plan['resolved_entity'] ?? null)
            ? [
                'type' => (string) ($plan['resolved_entity']['type'] ?? 'user'),
                'id' => is_numeric($plan['resolved_entity']['id'] ?? null) ? (int) $plan['resolved_entity']['id'] : null,
                'label' => is_string($plan['resolved_entity']['label'] ?? null) ? $plan['resolved_entity']['label'] : null,
            ]
            : null;

        $confidence = match (true) {
            ($plan['classification_source'] ?? null) === 'llm_fallback' => 'low',
            $source === 'provider' => 'medium',
            default => 'high',
        };

        // Fase 2c: telemetria de la interpretacion emitida al usuario.
        CopilotPlannerTelemetry::interpretation(
            source: $source,
            confidence: $confidence,
            capabilityKey: is_string($plan['capability_key'] ?? null) ? $plan['capability_key'] : null,
            intentFamily: is_string($plan['intent_family'] ?? null) ? $plan['intent_family'] : null,
        );

        return [
            'understood_intent' => $this->understoodIntentText($plan),
            'applied_filters' => $filters,
            'entity' => $entity,
            'source' => $source,
            'confidence' => $confidence,
            'capability_key' => is_string($plan['capability_key'] ?? null) ? $plan['capability_key'] : null,
            'intent_family' => is_string($plan['intent_family'] ?? null) ? $plan['intent_family'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    protected function understoodIntentText(array $plan): string
    {
        $key = (string) ($plan['capability_key'] ?? '');

        return match (true) {
            str_starts_with($key, 'users.metrics.') => 'Consultar metricas agregadas de usuarios',
            $key === 'users.search' => 'Buscar usuarios con los filtros indicados',
            $key === 'users.mixed.metrics_search' => 'Combinar metricas y busqueda de usuarios',
            $key === 'users.detail' => 'Mostrar detalle de un usuario',
            str_starts_with($key, 'users.actions.') => 'Preparar una propuesta de accion sobre un usuario',
            str_starts_with($key, 'users.explain.') => 'Explicar permisos o capacidades de un usuario',
            $key === 'users.roles.catalog' => 'Listar el catalogo de roles',
            $key === 'users.denied' => 'Solicitud rechazada por politica del copiloto',
            $key === 'users.continuation.confirm' => 'Confirmar si continuar con el contexto previo',
            $key === 'users.clarification' => 'Pedir una aclaracion para continuar',
            $key === 'users.help.informational' => 'Responder una pregunta informativa del modulo',
            $key === 'users.help.unknown' => 'Consulta no reconocida dentro del scope de usuarios',
            $key === 'users.help' => 'Proporcionar ayuda general del copiloto de usuarios',
            default => 'Solicitud interpretada por el copiloto',
        };
    }

    /**
     * Fase 1a: payload canonico para rechazos explicitos.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    protected function deniedPayload(
        array $plan,
        CopilotConversationSnapshot $snapshot,
        string $responseSource,
        ?int $subjectUserId,
    ): array {
        $clarification = is_array($plan['clarification_state'] ?? null) ? $plan['clarification_state'] : [];
        $reason = is_string($clarification['reason'] ?? null) ? $clarification['reason'] : 'denied:unsupported_operation';
        $category = CopilotDenialCatalog::categoryFromReason($reason);
        $catalog = CopilotDenialCatalog::forCategory($category);
        $question = is_string($clarification['question'] ?? null) && $clarification['question'] !== ''
            ? $clarification['question']
            : $catalog['message'];

        // Fase 2c: telemetria de denial con categoria y alternativas.
        CopilotPlannerTelemetry::denial(
            category: $category,
            reason: $reason,
            normalizedPrompt: (string) ($plan['request_normalization'] ?? ''),
            alternatives: $catalog['alternatives'],
        );

        return [
            'answer' => $question,
            'intent' => 'denied',
            'cards' => [[
                'kind' => 'denied',
                'title' => 'No puedo procesar esta solicitud',
                'summary' => $question,
                'data' => [
                    'category' => $category,
                    'reason' => $reason,
                    'message' => $question,
                    'alternatives' => $catalog['alternatives'],
                ],
            ]],
            'actions' => [],
            'requires_confirmation' => false,
            'references' => [],
            'meta' => [
                'module' => 'users',
                'channel' => 'web',
                'subject_user_id' => $subjectUserId,
                'fallback' => false,
                'capability_key' => 'users.denied',
                'intent_family' => 'denied',
                'conversation_state_version' => $snapshot->version(),
                'response_source' => $responseSource,
                'diagnostics' => [
                    'planner' => 'users_request_planner',
                    'denial_category' => $category,
                    'denial_reason' => $reason,
                ],
            ],
        ];
    }

    /**
     * Fase 1c: payload canonico para confirmar continuacion con snapshot stale.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    protected function continuationConfirmPayload(
        array $plan,
        CopilotConversationSnapshot $snapshot,
        string $responseSource,
        ?int $subjectUserId,
    ): array {
        $clarification = is_array($plan['clarification_state'] ?? null) ? $plan['clarification_state'] : [];
        $question = is_string($clarification['question'] ?? null)
            ? $clarification['question']
            : 'Tu ultima interaccion tiene tiempo. Deseas continuar con el contexto previo o empezar de nuevo?';
        $freshness = is_string($clarification['freshness'] ?? null) ? $clarification['freshness'] : 'stale';
        $entityLabel = is_string($clarification['entity_label'] ?? null) ? $clarification['entity_label'] : null;
        $minutesElapsed = is_numeric($clarification['minutes_elapsed'] ?? null) ? (int) $clarification['minutes_elapsed'] : null;
        $options = array_values(array_filter(
            Arr::wrap($clarification['options'] ?? []),
            static fn (mixed $option): bool => is_array($option),
        ));

        if ($options === []) {
            $options = [
                ['label' => 'Continuar con el contexto previo', 'value' => 'confirm_continuation'],
                ['label' => 'Empezar de nuevo', 'value' => 'start_fresh'],
            ];
        }

        return [
            'answer' => $question,
            'intent' => 'continuation_confirm',
            'cards' => [[
                'kind' => 'continuation_confirm',
                'title' => 'Confirmacion de continuacion',
                'summary' => $question,
                'data' => [
                    'freshness' => $freshness,
                    'question' => $question,
                    'entity_label' => $entityLabel,
                    'minutes_elapsed' => $minutesElapsed,
                    'options' => $options,
                ],
            ]],
            'actions' => [],
            'requires_confirmation' => false,
            'references' => [],
            'meta' => [
                'module' => 'users',
                'channel' => 'web',
                'subject_user_id' => $subjectUserId,
                'fallback' => false,
                'capability_key' => 'users.continuation.confirm',
                'intent_family' => 'continuation_confirm',
                'conversation_state_version' => $snapshot->version(),
                'response_source' => $responseSource,
                'diagnostics' => [
                    'planner' => 'users_request_planner',
                    'freshness' => $freshness,
                    'minutes_elapsed' => $minutesElapsed,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $executionResult
     */
    public function nextSnapshot(
        CopilotConversationSnapshot $snapshot,
        array $plan,
        array $payload,
        ?array $executionResult = null,
    ): CopilotConversationSnapshot {
        $attributes = $snapshot->toArray();
        $filters = array_filter(
            is_array($plan['filters'] ?? null) ? $plan['filters'] : [],
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );

        $attributes['last_user_request_normalized'] = $plan['request_normalization'] ?? $attributes['last_user_request_normalized'];
        $attributes['last_intent_family'] = $plan['intent_family'] ?? $attributes['last_intent_family'];
        $attributes['last_capability_key'] = $plan['capability_key'] ?? $attributes['last_capability_key'];
        $attributes['last_filters'] = $filters;
        $attributes['pending_clarification'] = is_array($plan['clarification_state'] ?? null)
            ? $plan['clarification_state']
            : null;
        $attributes['pending_action_proposal'] = null;

        if (is_array($executionResult['snapshot_updates'] ?? null)) {
            $attributes = [...$attributes, ...$executionResult['snapshot_updates']];
        }

        if (($payload['intent'] ?? null) === 'metrics' && ($plan['capability_key'] ?? null) === 'users.snapshot.result_count') {
            $attributes['last_result_count'] = $snapshot->lastResultCount();
            $attributes['last_metrics_snapshot'] = [
                'capability_key' => 'users.snapshot.result_count',
                'metric' => [
                    'label' => 'Usuarios del subconjunto actual',
                    'value' => $snapshot->lastResultCount(),
                    'unit' => 'users',
                ],
                'applied_filters' => $snapshot->lastFilters(),
            ];
        }

        if (($payload['intent'] ?? null) === 'search_results') {
            $cardData = $this->firstCardData($payload, 'search_results');
            $users = array_values(array_filter(
                Arr::wrap($cardData['users'] ?? []),
                static fn (mixed $user): bool => is_array($user) && is_numeric($user['id'] ?? null),
            ));

            $attributes['last_result_user_ids'] = array_values(array_map(
                static fn (array $user): int => (int) $user['id'],
                array_slice($users, 0, 8),
            ));
            $attributes['last_result_count'] = is_numeric($cardData['matching_count'] ?? null)
                ? (int) $cardData['matching_count']
                : (is_numeric($cardData['count'] ?? null) ? (int) $cardData['count'] : null);
            $attributes['last_metrics_snapshot'] = ($executionResult['family'] ?? null) === 'mixed'
                ? ($executionResult['snapshot_updates']['last_metrics_snapshot'] ?? $attributes['last_metrics_snapshot'])
                : null;
        }

        if (($payload['intent'] ?? null) === 'user_context' && is_array($plan['resolved_entity'] ?? null)) {
            $attributes['last_resolved_entity_type'] = $plan['resolved_entity']['type'] ?? null;
            $attributes['last_resolved_entity_id'] = $plan['resolved_entity']['id'] ?? null;
        }

        if (($payload['intent'] ?? null) === 'action_proposal') {
            $firstAction = Arr::first(Arr::wrap($payload['actions'] ?? []), static fn (mixed $action): bool => is_array($action));

            if (is_array($firstAction)) {
                $attributes['pending_action_proposal'] = [
                    'id' => $firstAction['id'] ?? null,
                    'action_type' => $firstAction['action_type'] ?? null,
                    'target' => is_array($firstAction['target'] ?? null) ? $firstAction['target'] : null,
                    'payload' => is_array($firstAction['payload'] ?? null) ? $firstAction['payload'] : [],
                    'summary' => $firstAction['summary'] ?? null,
                    'required_permissions' => array_values(array_filter(Arr::wrap($firstAction['required_permissions'] ?? []), static fn (mixed $permission): bool => is_string($permission))),
                    'created_at' => $firstAction['created_at'] ?? null,
                    'expires_at' => $firstAction['expires_at'] ?? null,
                    'fingerprint' => $firstAction['fingerprint'] ?? null,
                    'can_execute' => (bool) ($firstAction['can_execute'] ?? false),
                ];

                if (($firstAction['target']['kind'] ?? null) === 'user' && is_numeric($firstAction['target']['user_id'] ?? null)) {
                    $attributes['last_resolved_entity_type'] = 'user';
                    $attributes['last_resolved_entity_id'] = (int) $firstAction['target']['user_id'];
                }
            }
        }

        return $snapshot->with($attributes);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $executionResult
     * @return array<string, mixed>
     */
    protected function metricsPayload(
        array $plan,
        CopilotConversationSnapshot $snapshot,
        array $executionResult,
        string $responseSource,
        ?int $subjectUserId,
    ): array {
        $answerFacts = is_array($executionResult['answer_facts'] ?? null) ? $executionResult['answer_facts'] : [];
        $metric = is_array($answerFacts['metric'] ?? null) ? $answerFacts['metric'] : [];
        $capabilityKey = (string) ($executionResult['capability_key'] ?? $plan['capability_key'] ?? 'users.metrics.total');
        $value = is_numeric($metric['value'] ?? null) ? (int) $metric['value'] : 0;
        $label = is_string($metric['label'] ?? null) ? $metric['label'] : 'Metrica';

        return [
            'answer' => $this->metricsAnswer(
                capabilityKey: $capabilityKey,
                label: $label,
                value: $value,
                answerFacts: $answerFacts,
                normalizedRequest: (string) ($plan['request_normalization'] ?? ''),
            ),
            'intent' => 'metrics',
            'cards' => array_values(array_filter(Arr::wrap($executionResult['cards'] ?? []), static fn (mixed $card): bool => is_array($card))),
            'actions' => [],
            'requires_confirmation' => false,
            'references' => array_values(array_filter(Arr::wrap($executionResult['references'] ?? []), static fn (mixed $reference): bool => is_array($reference))),
            'meta' => [
                'module' => 'users',
                'channel' => 'web',
                'subject_user_id' => $subjectUserId,
                'fallback' => false,
                'capability_key' => $capabilityKey,
                'intent_family' => 'read_metrics',
                'conversation_state_version' => $snapshot->version(),
                'response_source' => $responseSource,
                'diagnostics' => array_filter([
                    ...(is_array($executionResult['diagnostics'] ?? null) ? $executionResult['diagnostics'] : []),
                    'planner' => 'users_request_planner',
                ]),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    protected function clarificationPayload(
        array $plan,
        CopilotConversationSnapshot $snapshot,
        string $responseSource,
        ?int $subjectUserId,
    ): array {
        $clarification = is_array($plan['clarification_state'] ?? null) ? $plan['clarification_state'] : [];
        $question = (string) ($clarification['question'] ?? 'Necesito una aclaracion para continuar.');

        return [
            'answer' => $question,
            'intent' => 'ambiguous',
            'cards' => [[
                'kind' => 'clarification',
                'title' => 'Necesito una aclaracion',
                'summary' => $question,
                'data' => [
                    'reason' => $clarification['reason'] ?? 'missing_context',
                    'question' => $question,
                    'options' => array_values(array_filter(
                        Arr::wrap($clarification['options'] ?? []),
                        static fn (mixed $option): bool => is_array($option),
                    )),
                ],
            ]],
            'actions' => [],
            'requires_confirmation' => false,
            'references' => [],
            'meta' => [
                'module' => 'users',
                'channel' => 'web',
                'subject_user_id' => $subjectUserId,
                'fallback' => false,
                'capability_key' => $plan['capability_key'] ?? 'users.clarification',
                'intent_family' => 'ambiguous',
                'conversation_state_version' => $snapshot->version(),
                'response_source' => $responseSource,
                'diagnostics' => [
                    'planner' => 'users_request_planner',
                    'reason' => $clarification['reason'] ?? 'missing_context',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $executionResult
     * @return array<string, mixed>
     */
    protected function searchPayload(
        array $plan,
        CopilotConversationSnapshot $snapshot,
        array $executionResult,
        string $responseSource,
        ?int $subjectUserId,
    ): array {
        $cards = array_values(array_filter(Arr::wrap($executionResult['cards'] ?? []), static fn (mixed $card): bool => is_array($card)));
        $searchCard = Arr::first($cards, static fn (mixed $card): bool => is_array($card) && ($card['kind'] ?? null) === 'search_results');
        $searchData = is_array($searchCard['data'] ?? null) ? $searchCard['data'] : [];
        $matchingCount = is_numeric($searchData['matching_count'] ?? null) ? (int) $searchData['matching_count'] : 0;

        // Fase 3: score de calidad para decidir notas de respuesta parcial y
        // enriquecer diagnostics.
        $quality = SearchQualityScorer::score(
            requestedFilters: is_array($plan['filters'] ?? null) ? $plan['filters'] : [],
            appliedFilters: is_array($searchData['applied_filters'] ?? null)
                ? $searchData['applied_filters']
                : (is_array($plan['filters'] ?? null) ? $plan['filters'] : []),
            resultCount: $matchingCount,
        );

        return [
            'answer' => $this->searchAnswer($matchingCount, $plan),
            'intent' => 'search_results',
            'cards' => $cards,
            'actions' => [],
            'requires_confirmation' => false,
            'references' => array_values(array_filter(Arr::wrap($executionResult['references'] ?? []), static fn (mixed $reference): bool => is_array($reference))),
            'meta' => [
                'module' => 'users',
                'channel' => 'web',
                'subject_user_id' => $subjectUserId,
                'fallback' => false,
                'capability_key' => 'users.search',
                'intent_family' => 'read_search',
                'conversation_state_version' => $snapshot->version(),
                'response_source' => $responseSource,
                'diagnostics' => array_filter([
                    ...(is_array($executionResult['diagnostics'] ?? null) ? $executionResult['diagnostics'] : []),
                    'planner' => 'users_request_planner',
                    'search_quality' => $quality,
                ]),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $executionResult
     * @return array<string, mixed>
     */
    protected function noticePayload(
        array $plan,
        CopilotConversationSnapshot $snapshot,
        array $executionResult,
        string $responseSource,
        ?int $subjectUserId,
    ): array {
        $cards = array_values(array_filter(Arr::wrap($executionResult['cards'] ?? []), static fn (mixed $card): bool => is_array($card)));

        return [
            'answer' => $this->noticeAnswer($executionResult, $plan),
            'intent' => 'help',
            'cards' => $cards,
            'actions' => [],
            'requires_confirmation' => false,
            'references' => array_values(array_filter(Arr::wrap($executionResult['references'] ?? []), static fn (mixed $reference): bool => is_array($reference))),
            'meta' => [
                'module' => 'users',
                'channel' => 'web',
                'subject_user_id' => $subjectUserId,
                'fallback' => false,
                'capability_key' => $executionResult['capability_key'] ?? 'users.help',
                'intent_family' => $plan['intent_family'] ?? 'help',
                'conversation_state_version' => $snapshot->version(),
                'response_source' => $responseSource,
                'diagnostics' => array_filter([
                    ...(is_array($executionResult['diagnostics'] ?? null) ? $executionResult['diagnostics'] : []),
                    'planner' => 'users_request_planner',
                ]),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $executionResult
     * @return array<string, mixed>
     */
    protected function detailPayload(
        array $plan,
        CopilotConversationSnapshot $snapshot,
        array $executionResult,
        string $responseSource,
        ?int $subjectUserId,
    ): array {
        $cards = array_values(array_filter(Arr::wrap($executionResult['cards'] ?? []), static fn (mixed $card): bool => is_array($card)));
        $detail = is_array($executionResult['answer_facts'] ?? null) ? $executionResult['answer_facts'] : [];

        return [
            'answer' => $this->detailAnswer($detail),
            'intent' => 'user_context',
            'cards' => $cards,
            'actions' => [],
            'requires_confirmation' => false,
            'references' => array_values(array_filter(Arr::wrap($executionResult['references'] ?? []), static fn (mixed $reference): bool => is_array($reference))),
            'meta' => [
                'module' => 'users',
                'channel' => 'web',
                'subject_user_id' => $subjectUserId,
                'fallback' => false,
                'capability_key' => 'users.detail',
                'intent_family' => 'read_detail',
                'conversation_state_version' => $snapshot->version(),
                'response_source' => $responseSource,
                'diagnostics' => array_filter([
                    ...(is_array($executionResult['diagnostics'] ?? null) ? $executionResult['diagnostics'] : []),
                    'planner' => 'users_request_planner',
                ]),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $executionResult
     * @return array<string, mixed>
     */
    protected function actionProposalPayload(
        array $plan,
        CopilotConversationSnapshot $snapshot,
        array $executionResult,
        string $responseSource,
        ?int $subjectUserId,
    ): array {
        $actions = array_values(array_filter(Arr::wrap($executionResult['actions'] ?? []), static fn (mixed $action): bool => is_array($action)));
        $firstAction = $actions[0] ?? null;
        $cards = array_values(array_filter(Arr::wrap($executionResult['cards'] ?? []), static fn (mixed $card): bool => is_array($card)));
        $missingFields = array_values(array_filter(Arr::wrap($cards[0]['data']['missing_fields'] ?? []), static fn (mixed $field): bool => is_string($field) && $field !== ''));
        $canExecute = count($actions) > 0 && collect($actions)->contains(static fn (array $action): bool => (bool) ($action['can_execute'] ?? false));

        return [
            'answer' => $this->actionAnswer($firstAction),
            'intent' => 'action_proposal',
            'cards' => $cards,
            'actions' => $actions,
            'requires_confirmation' => $canExecute,
            'references' => array_values(array_filter(Arr::wrap($executionResult['references'] ?? []), static fn (mixed $reference): bool => is_array($reference))),
            'meta' => [
                'module' => 'users',
                'channel' => 'web',
                'subject_user_id' => $subjectUserId,
                'fallback' => false,
                'capability_key' => $plan['capability_key'] ?? 'users.actions.deactivate',
                'intent_family' => 'action_proposal',
                'conversation_state_version' => $snapshot->version(),
                'response_source' => $responseSource,
                'diagnostics' => array_filter([
                    ...(is_array($executionResult['diagnostics'] ?? null) ? $executionResult['diagnostics'] : []),
                    'planner' => 'users_request_planner',
                ]),
            ],
            'resolution' => [
                'state' => ($canExecute || $missingFields === []) ? 'resolved' : 'clarification_required',
                'confidence' => 'high',
                'action_boundary' => $canExecute ? 'proposed' : 'blocked',
                'understood' => [],
                'unresolved' => $canExecute ? [] : [[
                    'text' => 'Propuesta de accion no ejecutable todavia',
                    'status' => 'pending',
                    'reason' => 'faltan datos requeridos para confirmar',
                ]],
                'missing' => $missingFields,
                'denials' => [],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $executionResult
     * @return array<string, mixed>
     */
    protected function mixedMetricsSearchPayload(
        array $plan,
        CopilotConversationSnapshot $snapshot,
        array $executionResult,
        string $responseSource,
        ?int $subjectUserId,
    ): array {
        $answerFacts = is_array($executionResult['answer_facts'] ?? null) ? $executionResult['answer_facts'] : [];
        $metric = is_array($answerFacts['metric'] ?? null) ? $answerFacts['metric'] : [];
        $cards = array_values(array_filter(Arr::wrap($executionResult['cards'] ?? []), static fn (mixed $card): bool => is_array($card)));
        $searchCard = Arr::first($cards, static fn (mixed $card): bool => is_array($card) && ($card['kind'] ?? null) === 'search_results');
        $searchData = is_array($searchCard['data'] ?? null) ? $searchCard['data'] : [];
        $matchingCount = is_numeric($searchData['matching_count'] ?? null) ? (int) $searchData['matching_count'] : 0;
        $roleFilter = data_get($plan, 'filters.role');
        $accessProfile = data_get($plan, 'filters.access_profile');

        $answer = trim(implode(' ', array_filter([
            $this->metricsAnswer(
                capabilityKey: 'users.metrics.combined',
                label: is_string($metric['label'] ?? null) ? $metric['label'] : 'Resumen combinado de usuarios',
                value: is_numeric($metric['value'] ?? null) ? (int) $metric['value'] : 0,
                answerFacts: $answerFacts,
                normalizedRequest: (string) ($plan['request_normalization'] ?? ''),
            ),
            $this->mixedSearchAnswer(
                $matchingCount,
                is_string($roleFilter) ? $roleFilter : null,
                is_string($accessProfile) ? $accessProfile : null,
            ),
        ])));

        // Fase 3: score de calidad del segmento de busqueda dentro del mixed.
        $quality = SearchQualityScorer::score(
            requestedFilters: is_array($plan['filters'] ?? null) ? $plan['filters'] : [],
            appliedFilters: is_array($searchData['applied_filters'] ?? null)
                ? $searchData['applied_filters']
                : (is_array($plan['filters'] ?? null) ? $plan['filters'] : []),
            resultCount: $matchingCount,
        );

        // Si el segmento de busqueda quedo vacio pero las metricas si se
        // resolvieron, emitir partial_notice honesto junto al resultado.
        if ($quality['quality'] === 'empty') {
            $cards[] = [
                'kind' => 'partial_notice',
                'title' => 'Respuesta parcial',
                'summary' => 'Obtuve las metricas solicitadas pero la busqueda no devolvio coincidencias.',
                'data' => [
                    'segments' => [[
                        'text' => 'Listado de usuarios que cumplen los filtros',
                        'status' => 'not_executed',
                        'reason' => 'sin coincidencias con los filtros indicados',
                        'suggested_follow_up' => 'Relaja algun criterio o muestrame las opciones disponibles',
                    ]],
                ],
            ];
        }

        return [
            'answer' => $answer,
            'intent' => 'search_results',
            'cards' => $cards,
            'actions' => [],
            'requires_confirmation' => false,
            'references' => array_values(array_filter(Arr::wrap($executionResult['references'] ?? []), static fn (mixed $reference): bool => is_array($reference))),
            'meta' => [
                'module' => 'users',
                'channel' => 'web',
                'subject_user_id' => $subjectUserId,
                'fallback' => false,
                'capability_key' => 'users.mixed.metrics_search',
                'intent_family' => 'read_search',
                'conversation_state_version' => $snapshot->version(),
                'response_source' => $responseSource,
                'diagnostics' => array_filter([
                    ...(is_array($executionResult['diagnostics'] ?? null) ? $executionResult['diagnostics'] : []),
                    'planner' => 'users_request_planner',
                    'search_quality' => $quality,
                ]),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $executionResult
     * @return array<string, mixed>
     */
    protected function mixedPartialPayload(
        array $plan,
        CopilotConversationSnapshot $snapshot,
        array $executionResult,
        string $responseSource,
        ?int $subjectUserId,
    ): array {
        $cards = array_values(array_filter(Arr::wrap($executionResult['cards'] ?? []), static fn (mixed $card): bool => is_array($card)));
        $segments = array_values(array_filter(Arr::wrap($plan['mixed_segments'] ?? []), static fn (mixed $segment): bool => is_array($segment)));

        $cards[] = [
            'kind' => 'partial_notice',
            'title' => 'Respuesta parcial',
            'summary' => 'Resolvi la parte de consulta y deje sin ejecutar la parte de accion.',
            'data' => [
                'segments' => $segments,
            ],
        ];

        return [
            'answer' => 'Resolvi la consulta disponible. La parte de accion no fue ejecutada.',
            'intent' => 'partial',
            'cards' => $cards,
            'actions' => [],
            'requires_confirmation' => false,
            'references' => array_values(array_filter(Arr::wrap($executionResult['references'] ?? []), static fn (mixed $reference): bool => is_array($reference))),
            'meta' => [
                'module' => 'users',
                'channel' => 'web',
                'subject_user_id' => $subjectUserId,
                'fallback' => false,
                'capability_key' => 'users.mixed.create_search',
                'intent_family' => 'partial',
                'conversation_state_version' => $snapshot->version(),
                'response_source' => $responseSource,
                'diagnostics' => array_filter([
                    ...(is_array($executionResult['diagnostics'] ?? null) ? $executionResult['diagnostics'] : []),
                    'planner' => 'users_request_planner',
                ]),
            ],
        ];
    }

    protected function metricsAnswer(
        string $capabilityKey,
        string $label,
        int $value,
        array $answerFacts = [],
        string $normalizedRequest = '',
    ): string {
        if ($capabilityKey === 'users.metrics.combined') {
            $breakdown = is_array($answerFacts['breakdown'] ?? null) ? $answerFacts['breakdown'] : [];
            $active = is_numeric($breakdown['active'] ?? null) ? (int) $breakdown['active'] : 0;
            $inactive = is_numeric($breakdown['inactive'] ?? null) ? (int) $breakdown['inactive'] : 0;
            $adminAccess = is_numeric($breakdown['admin_access'] ?? null) ? (int) $breakdown['admin_access'] : 0;
            $mostCommon = is_string($breakdown['most_common_role'] ?? null) ? $breakdown['most_common_role'] : 'Ninguno';
            $mostCommonCount = is_numeric($breakdown['most_common_role_count'] ?? null) ? (int) $breakdown['most_common_role_count'] : 0;
            $segments = [];

            if ($normalizedRequest === '' || str_contains($normalizedRequest, 'total') || str_contains($normalizedRequest, 'cuantos usuarios hay') || str_contains($normalizedRequest, 'cantidad de usuarios')) {
                $segments[] = "Hay {$value} usuarios en total.";
            }

            if ($normalizedRequest === '' || str_contains($normalizedRequest, 'activo') || str_contains($normalizedRequest, 'inactivo')) {
                $segments[] = "{$active} activos y {$inactive} inactivos.";
            }

            if ($normalizedRequest === '' || str_contains($normalizedRequest, 'cuantos admin') || str_contains($normalizedRequest, 'admin tenemos') || str_contains($normalizedRequest, 'acceso administrativo')) {
                $segments[] = "Hay {$adminAccess} usuarios con acceso administrativo efectivo.";
            }

            if ($normalizedRequest === '' || str_contains($normalizedRequest, 'rol mas comun')) {
                $segments[] = $mostCommonCount === 0
                    ? 'Ningun rol tiene usuarios asignados actualmente.'
                    : "El rol mas comun es {$mostCommon} con {$mostCommonCount} usuarios asignados.";
            }

            return implode(' ', array_values(array_filter($segments)));
        }

        return match ($capabilityKey) {
            'users.metrics.total' => "Hay {$value} usuarios en total.",
            'users.metrics.active' => "Hay {$value} usuarios activos.",
            'users.metrics.inactive' => "Hay {$value} usuarios inactivos.",
            'users.metrics.admin_access' => "Hay {$value} usuarios con acceso administrativo efectivo.",
            'users.metrics.with_roles' => "Hay {$value} usuarios con roles asignados.",
            'users.metrics.without_roles' => "Hay {$value} usuarios sin roles asignados.",
            'users.metrics.verified' => "Hay {$value} usuarios con correo verificado.",
            'users.metrics.unverified' => "Hay {$value} usuarios sin correo verificado.",
            'users.metrics.role_distribution' => $value === 0
                ? 'No hay roles asignados a usuarios en este momento.'
                : "Hay {$value} roles con usuarios asignados. El desglose exacto esta en la tarjeta.",
            'users.metrics.most_common_role' => $value === 0
                ? 'Ningun rol tiene usuarios asignados actualmente.'
                : "{$label} es el rol mas comun con {$value} usuarios asignados.",
            default => "{$label}: {$value}.",
        };
    }

    protected function mixedSearchAnswer(int $matchingCount, ?string $roleFilter, ?string $accessProfile): string
    {
        if ($accessProfile === 'administrative_access') {
            return $matchingCount === 0
                ? 'No encontre usuarios con acceso administrativo efectivo.'
                : "Ademas, encontre {$matchingCount} usuario".($matchingCount === 1 ? '' : 's').' con acceso administrativo efectivo.';
        }

        if ($accessProfile === 'super_admin_role') {
            return $matchingCount === 0
                ? 'No encontre usuarios con el rol super-admin.'
                : "Ademas, encontre {$matchingCount} usuario".($matchingCount === 1 ? '' : 's').' con el rol super-admin.';
        }

        if ($roleFilter !== null && $roleFilter !== '') {
            return $matchingCount === 0
                ? "No encontre usuarios con rol {$roleFilter}."
                : "Ademas, encontre {$matchingCount} usuario".($matchingCount === 1 ? '' : 's')." con rol {$roleFilter}.";
        }

        return $matchingCount === 0
            ? 'No encontre usuarios para el listado solicitado.'
            : "Ademas, encontre {$matchingCount} usuario".($matchingCount === 1 ? '' : 's').' para el listado solicitado.';
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    protected function searchAnswer(int $matchingCount, array $plan): string
    {
        $roleFilter = data_get($plan, 'filters.role');
        $accessProfile = data_get($plan, 'filters.access_profile');
        $permission = data_get($plan, 'filters.permission');
        $query = data_get($plan, 'filters.query');
        $status = data_get($plan, 'filters.status');
        $twoFactor = data_get($plan, 'filters.two_factor_enabled');

        if (is_string($permission) && $permission !== '') {
            $permissionLabel = UsersCopilotDomainLexicon::permissionLabel($permission);

            return $matchingCount === 0
                ? "No se encontraron usuarios que puedan {$permissionLabel}."
                : "Se encontraron {$matchingCount} usuarios que pueden {$permissionLabel}.";
        }

        if ($accessProfile === 'administrative_access') {
            return $matchingCount === 0
                ? 'No se encontraron usuarios con acceso administrativo efectivo en el sistema.'
                : "Se encontraron {$matchingCount} usuarios con acceso administrativo efectivo.";
        }

        if ($accessProfile === 'super_admin_role') {
            return $matchingCount === 0
                ? 'No se encontraron usuarios con el rol super-admin en el sistema.'
                : "Se encontraron {$matchingCount} usuarios con el rol super-admin.";
        }

        if (is_string($roleFilter) && $roleFilter !== '') {
            return $matchingCount === 0
                ? "No se encontraron usuarios con el rol {$roleFilter} en el sistema."
                : "Se encontraron {$matchingCount} usuarios con el rol {$roleFilter}.";
        }

        if (is_string($query) && $query !== '') {
            return $matchingCount === 0
                ? 'No se encontraron coincidencias para la búsqueda solicitada.'
                : "Se encontraron {$matchingCount} usuarios que coinciden con la búsqueda.";
        }

        if ($twoFactor === true) {
            return $matchingCount === 0
                ? 'No se encontraron usuarios con autenticación de dos factores habilitada.'
                : "Se encontraron {$matchingCount} usuarios con autenticación de dos factores habilitada.";
        }

        if ($twoFactor === false) {
            return $matchingCount === 0
                ? 'No se encontraron usuarios sin autenticación de dos factores habilitada.'
                : "Se encontraron {$matchingCount} usuarios sin autenticación de dos factores habilitada.";
        }

        if ($status === 'inactive') {
            return $matchingCount === 0
                ? 'No se encontraron usuarios inactivos en el sistema.'
                : "Se encontraron {$matchingCount} usuarios inactivos.";
        }

        if ($status === 'active') {
            return $matchingCount === 0
                ? 'No se encontraron usuarios activos en el sistema.'
                : "Se encontraron {$matchingCount} usuarios activos.";
        }

        return $matchingCount === 0
            ? 'No se encontraron usuarios para el criterio solicitado.'
            : "Se encontraron {$matchingCount} usuarios para el criterio solicitado.";
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    protected function detailAnswer(array $detail): string
    {
        if (($detail['found'] ?? false) !== true) {
            return 'No encontre un usuario valido para mostrar en esta respuesta.';
        }

        $user = is_array($detail['user'] ?? null) ? $detail['user'] : [];
        $roles = array_values(array_filter(Arr::wrap($detail['roles'] ?? []), static fn (mixed $role): bool => is_array($role)));
        $permissions = collect(Arr::wrap($detail['effective_permissions'] ?? []))
            ->filter(static fn (mixed $group): bool => is_array($group))
            ->flatten(1)
            ->filter(static fn (mixed $permission): bool => is_array($permission))
            ->count();

        $roleLabels = collect($roles)
            ->map(fn (array $role): string => (string) ($role['display_name'] ?? $role['name'] ?? 'Rol'))
            ->filter()
            ->implode(', ');

        return sprintf(
            'El usuario %s (%s) tiene %d rol%s y %d permiso%s efectivo%s.%s',
            (string) ($user['name'] ?? 'sin nombre'),
            (string) ($user['email'] ?? 'sin correo'),
            count($roles),
            count($roles) === 1 ? '' : 'es',
            $permissions,
            $permissions === 1 ? '' : 's',
            $permissions === 1 ? '' : 's',
            $roleLabels !== '' ? ' Roles: '.$roleLabels.'.' : ''
        );
    }

    /**
     * @param  array<string, mixed>|null  $action
     */
    protected function actionAnswer(?array $action): string
    {
        if ($action === null) {
            return 'No pude preparar una propuesta accionable con la informacion actual.';
        }

        return (string) ($action['summary'] ?? 'He preparado una propuesta para esta accion.')
            .((bool) ($action['can_execute'] ?? false)
                ? ' Puedes confirmarla si deseas proceder.'
                : ' Por ahora queda como propuesta y no puede confirmarse.');
    }

    /**
     * @param  array<string, mixed>  $executionResult
     * @param  array<string, mixed>  $plan
     */
    protected function noticeAnswer(array $executionResult, array $plan): string
    {
        return match ($executionResult['capability_key'] ?? null) {
            'users.actions.cancel_pending' => 'Cancelé la propuesta pendiente. No se ejecutó ninguna acción.',
            'users.roles.catalog' => 'Estos son los roles activos disponibles actualmente en el sistema.',
            'users.explain.permission' => $this->permissionExplainAnswer(is_array($executionResult['answer_facts'] ?? null) ? $executionResult['answer_facts'] : []),
            'users.explain.action' => $this->actionExplainAnswer(is_array($executionResult['answer_facts'] ?? null) ? $executionResult['answer_facts'] : []),
            'users.explain.capabilities_summary' => $this->capabilitiesSummaryAnswer(
                is_array($executionResult['answer_facts'] ?? null) ? $executionResult['answer_facts'] : [],
                (string) ($plan['request_normalization'] ?? ''),
            ),
            default => 'Aqui tienes la informacion solicitada.',
        };
    }

    /**
     * @param  array<string, mixed>  $answerFacts
     */
    protected function permissionExplainAnswer(array $answerFacts): string
    {
        $detail = is_array($answerFacts['detail'] ?? null) ? $answerFacts['detail'] : [];
        $user = is_array($detail['user'] ?? null) ? $detail['user'] : [];
        $permissionLabel = is_string($answerFacts['permission_label'] ?? null) ? $answerFacts['permission_label'] : 'esa accion';
        $allowed = (bool) ($answerFacts['allowed'] ?? false);

        return sprintf(
            '%s %s dentro del sistema.%s',
            (string) ($user['name'] ?? 'El usuario'),
            $allowed ? 'puede '.$permissionLabel : 'no puede '.$permissionLabel,
            $allowed ? '' : ' No encontré un permiso efectivo que habilite esa accion.'
        );
    }

    /**
     * @param  array<string, mixed>  $answerFacts
     */
    protected function actionExplainAnswer(array $answerFacts): string
    {
        $subject = is_array($answerFacts['subject'] ?? null) ? $answerFacts['subject'] : [];
        $target = is_array($answerFacts['target'] ?? null) ? $answerFacts['target'] : [];
        $allowed = (bool) ($answerFacts['allowed'] ?? false);
        $reason = is_string($answerFacts['reason'] ?? null) ? $answerFacts['reason'] : 'No tengo una explicacion deterministica para esta accion.';

        return sprintf(
            '%s %s actuar sobre %s. %s',
            (string) ($subject['name'] ?? 'El usuario'),
            $allowed ? 'si puede' : 'no puede',
            (string) ($target['name'] ?? 'ese usuario'),
            $reason
        );
    }

    /**
     * @param  array<string, mixed>  $answerFacts
     */
    protected function capabilitiesSummaryAnswer(array $answerFacts, string $normalizedRequest): string
    {
        $detail = is_array($answerFacts['detail'] ?? null) ? $answerFacts['detail'] : [];
        $user = is_array($detail['user'] ?? null) ? $detail['user'] : [];
        $allowed = array_values(array_filter(Arr::wrap($answerFacts['allowed'] ?? []), static fn (mixed $item): bool => is_string($item)));
        $missing = array_values(array_filter(Arr::wrap($answerFacts['missing'] ?? []), static fn (mixed $item): bool => is_string($item)));

        if (str_contains($normalizedRequest, 'no puede')) {
            $missingText = $missing === []
                ? 'No detecté restricciones relevantes dentro del resumen actual.'
                : 'No puede: '.implode(', ', array_slice($missing, 0, 5)).'.';

            return sprintf('%s — %s', (string) ($user['name'] ?? 'El usuario'), $missingText);
        }

        $allowedText = $allowed === []
            ? 'No tiene acciones relevantes habilitadas en esta version del resumen.'
            : 'Puede: '.implode(', ', array_slice($allowed, 0, 5)).'.';

        $missingText = $missing === []
            ? ''
            : ' No puede: '.implode(', ', array_slice($missing, 0, 4)).'.';

        return sprintf('%s — %s%s', (string) ($user['name'] ?? 'El usuario'), $allowedText, $missingText);
    }

    /**
     * @return array<string, mixed>
     */
    protected function firstCardData(array $payload, string $kind): array
    {
        foreach (Arr::wrap($payload['cards'] ?? []) as $card) {
            if (($card['kind'] ?? null) === $kind && is_array($card['data'] ?? null)) {
                return $card['data'];
            }
        }

        return [];
    }
}
