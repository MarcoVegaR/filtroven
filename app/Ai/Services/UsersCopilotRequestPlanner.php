<?php

namespace App\Ai\Services;

use App\Ai\Agents\System\CopilotIntentClassifierAgent;
use App\Ai\Services\Planning\PlanningContext;
use App\Ai\Services\Planning\PlanningPipeline;
use App\Ai\Services\Planning\Stages\ActionExplanationStage;
use App\Ai\Services\Planning\Stages\ActionProposalStage;
use App\Ai\Services\Planning\Stages\BulkActionDenialStage;
use App\Ai\Services\Planning\Stages\ConfiguredMatrixStage;
use App\Ai\Services\Planning\Stages\ConversationContinuationStage;
use App\Ai\Services\Planning\Stages\CreateUserIntentStage;
use App\Ai\Services\Planning\Stages\DenialStage;
use App\Ai\Services\Planning\Stages\EntityActionFallbackStage;
use App\Ai\Services\Planning\Stages\EntityPlanFallbackStage;
use App\Ai\Services\Planning\Stages\EntityResolutionStage;
use App\Ai\Services\Planning\Stages\FollowUpStage;
use App\Ai\Services\Planning\Stages\HelpCreateUserStage;
use App\Ai\Services\Planning\Stages\HelpUnknownStage;
use App\Ai\Services\Planning\Stages\InformationalHelpStage;
use App\Ai\Services\Planning\Stages\MetricsStage;
use App\Ai\Services\Planning\Stages\MixedCreateSearchStage;
use App\Ai\Services\Planning\Stages\MixedMetricsSearchStage;
use App\Ai\Services\Planning\Stages\PendingActionCancellationStage;
use App\Ai\Services\Planning\Stages\PendingClarificationStage;
use App\Ai\Services\Planning\Stages\PendingCreateUserStage;
use App\Ai\Services\Planning\Stages\PermissionSearchStage;
use App\Ai\Services\Planning\Stages\RolesCatalogStage;
use App\Ai\Services\Planning\Stages\SearchStage;
use App\Ai\Services\Planning\Stages\StalenessConfirmationStage;
use App\Ai\Services\Planning\Stages\SubjectUserStage;
use App\Ai\Support\CopilotConversationSnapshot;
use App\Ai\Support\CopilotPlannerTelemetry;
use App\Ai\Support\UsersCopilotDomainLexicon;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class UsersCopilotRequestPlanner
{
    /**
     * Fase 2 estructural: pipeline de stages inyectable. Si es null, se
     * construye el pipeline canonico en `defaultPipeline()`. Esto permite
     * a tests y evaluadores externos sustituir el orden o cualquier stage
     * individual sin tocar el planner.
     */
    protected ?PlanningPipeline $pipeline = null;

    public function __construct(?PlanningPipeline $pipeline = null)
    {
        $this->pipeline = $pipeline;
    }

    /**
     * @return array{
     *   request_normalization: string,
     *   intent_family: 'read_metrics'|'read_search'|'read_detail'|'read_explain'|'action_proposal'|'help'|'out_of_scope'|'ambiguous',
     *   capability_key: ?string,
     *   filters: array{query: ?string, status: 'active'|'inactive'|'all'|null, role: ?string, access_profile: ?string, permission: ?string, email_verified: ?bool, has_roles: ?bool},
     *   resolved_entity: array{type: 'user', id: int, label: string}|null,
     *   missing_slots: list<string>,
     *   clarification_state: array{reason: string, question: string, options?: list<array{label: string, value: string}>}|null,
     *   proposal_vs_execute: 'proposal'|'execute'|'none'
     * }
     */
    public function plan(string $prompt, CopilotConversationSnapshot $snapshot, ?User $subjectUser = null): array
    {
        $startedAt = hrtime(true);
        $normalized = $this->normalizePrompt($prompt);
        $originalFreshness = $snapshot->freshness();

        // Fase 1c: staleness gate. Si el prompt requiere contexto previo pero
        // el snapshot esta stale, pedir confirmacion antes de continuar. Si
        // esta expired, se trata como fresh (snapshot descartado).
        $snapshot = $this->applyFreshnessGate($snapshot);

        $context = new PlanningContext(
            originalPrompt: $prompt,
            normalized: $normalized,
            snapshot: $snapshot,
            subjectUser: $subjectUser,
            originalFreshness: $originalFreshness,
        );

        $plan = $this->pipeline()->handle($context, $this);

        // El HelpUnknownStage es terminal, asi que esto nunca deberia pasar,
        // pero defensivamente devolvemos un unknown fallback.
        if ($plan === null) {
            $plan = $this->helpUnknownPlan($normalized);
            $plan['_pipeline_stage'] = 'fallback_guard';
        }

        $pipelineStage = is_string($plan['_pipeline_stage'] ?? null) ? $plan['_pipeline_stage'] : null;
        unset($plan['_pipeline_stage']);

        // Fase 2c: telemetria estructurada de la resolucion ganadora.
        $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);
        CopilotPlannerTelemetry::resolved(
            stage: $pipelineStage ?? $this->inferStageFromPlan($plan),
            capabilityKey: (string) ($plan['capability_key'] ?? 'users.help.unknown'),
            intentFamily: (string) ($plan['intent_family'] ?? 'help'),
            normalizedPrompt: $normalized,
            extra: [
                'snapshot_freshness' => $originalFreshness,
                'has_snapshot_context' => $snapshot->hasContext(),
                'duration_ms' => $durationMs,
                'clarification_reason' => data_get($plan, 'clarification_state.reason'),
                'llm_rescued' => isset($plan['classification_source']) && $plan['classification_source'] === 'llm_fallback',
                'pipeline_stage' => $pipelineStage,
            ],
        );

        return $plan;
    }

    /**
     * Construye el pipeline canonico. Precedencia identica a la version
     * anterior monolitica, ahora expresada declarativamente. Cada stage
     * encapsula UNA rama de decision.
     */
    protected function pipeline(): PlanningPipeline
    {
        if ($this->pipeline !== null) {
            return $this->pipeline;
        }

        return $this->pipeline = new PlanningPipeline([
            new DenialStage,
            new StalenessConfirmationStage,
            new PendingActionCancellationStage,
            new FollowUpStage,
            new ConversationContinuationStage,
            new PendingCreateUserStage,
            new HelpCreateUserStage, // ← Fix Fase 5: help sobre crear usuario
            new InformationalHelpStage,
            new PendingClarificationStage,
            new ConfiguredMatrixStage,
            new ActionExplanationStage,
            new MixedCreateSearchStage,
            new CreateUserIntentStage, // ← Fix Fase 5: salta EntityResolution para create
            new EntityResolutionStage, // ← escribe $context->entityPlan
            new MixedMetricsSearchStage,
            new RolesCatalogStage,
            new MetricsStage,
            new PermissionSearchStage,
            new BulkActionDenialStage,
            new SearchStage,
            new EntityActionFallbackStage, // ← lee $context->entityPlan
            new EntityPlanFallbackStage,   // ← lee $context->entityPlan
            new SubjectUserStage,
            new ActionProposalStage,
            new HelpUnknownStage,          // ← terminal, siempre produce plan
        ]);
    }

    /**
     * Permite reemplazar el pipeline en runtime (tests, eval harness).
     */
    public function setPipeline(PlanningPipeline $pipeline): void
    {
        $this->pipeline = $pipeline;
    }

    /**
     * @return list<string>
     */
    public function stageNames(): array
    {
        return $this->pipeline()->stageNames();
    }

    /**
     * Intenta rescatar un fallback usando el clasificador LLM.
     * Solo se invoca si el clasificador está habilitado y la categoría es rescatable.
     */
    public function attemptLLMRescue(string $normalized, array $fallbackPlan, CopilotConversationSnapshot $snapshot, ?User $subjectUser): array
    {
        // Verificar si el clasificador está habilitado
        if (! config('ai-copilot.intent_classifier.enabled', false)) {
            return $fallbackPlan;
        }

        // Solo rescatar categorías no_match y ambiguous_target
        $fallbackCategory = $this->categorizeFallback(
            $fallbackPlan['capability_key'],
            $fallbackPlan['intent_family'],
            $fallbackPlan['clarification_state']['reason'] ?? null,
            $normalized
        );

        if (! in_array($fallbackCategory, ['no_match', 'ambiguous_target'], true)) {
            return $fallbackPlan;
        }

        try {
            $classification = $this->classifyWithLLM($normalized);

            if ($this->validateLLMClassification($classification, $normalized, $subjectUser)) {
                // Log de éxito del clasificador
                $this->logLLMClassification($normalized, $classification, true, null);

                // Construir plan desde la clasificación LLM
                return $this->buildPlanFromLLMClassification($normalized, $classification, $snapshot, $subjectUser);
            }

            // Log de rechazo del clasificador
            $this->logLLMClassification($normalized, $classification, false, 'validation_failed');
        } catch (\Throwable $e) {
            // Log de error del clasificador
            Log::warning('copilot.planner.llm_classification_error', [
                'error' => $e->getMessage(),
                'prompt_normalized' => preg_replace('/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/', '[email]', $normalized) ?? $normalized,
                'correlation_id' => Context::get('correlation_id'),
            ]);
        }

        return $fallbackPlan;
    }

    /**
     * Clasifica el prompt usando el agente LLM.
     *
     * @return array{intent_family: string, capability_key: string, filters: array<string, mixed>, confidence: float}
     */
    protected function classifyWithLLM(string $normalized): array
    {
        $agent = new CopilotIntentClassifierAgent;

        $timeout = config('ai-copilot.intent_classifier.timeout', 5);

        // Usar el SDK de Laravel AI con timeout
        $response = $agent->timeout($timeout)->prompt($normalized);

        $data = json_decode($response->text, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Respuesta malformada del clasificador LLM');
        }

        return [
            'intent_family' => $data['intent_family'] ?? 'help',
            'capability_key' => $data['capability_key'] ?? 'users.help',
            'filters' => $data['filters'] ?? [],
            'confidence' => $data['confidence'] ?? 0.0,
        ];
    }

    /**
     * Valida la clasificación del LLM en cuatro pasos.
     *
     * Pasos:
     * 1. Existencia: capability_key existe en catálogo
     * 2. Consistencia: intent_family coincide con el del catálogo
     * 3. Filters: claves son subset de required_filters, valores son del tipo correcto
     * 4. Confianza: confidence >= threshold
     */
    protected function validateLLMClassification(array $classification, string $normalized, ?User $subjectUser = null): bool
    {
        $capabilityKey = $classification['capability_key'] ?? null;
        $intentFamily = $classification['intent_family'] ?? null;
        $filters = $classification['filters'] ?? null;
        $confidence = $classification['confidence'] ?? null;

        if (! is_string($capabilityKey) || trim($capabilityKey) === '') {
            return false;
        }

        if (! is_string($intentFamily) || trim($intentFamily) === '') {
            return false;
        }

        if (! is_array($filters) || ! is_numeric($confidence)) {
            return false;
        }

        $confidence = (float) $confidence;

        // Paso 1: Existencia
        $definition = UsersCopilotCapabilityCatalog::find($capabilityKey);
        if ($definition === null) {
            return false;
        }

        // Paso 2: Consistencia
        if ($definition['intent_family'] !== $intentFamily) {
            return false;
        }

        // Paso 3: Validación de filters
        $requiredFilters = $definition['required_filters'] ?? [];

        // Verificar que todas las claves de filters estén en required_filters
        foreach (array_keys($filters) as $filterKey) {
            if (! is_string($filterKey)) {
                return false;
            }

            if (! in_array($filterKey, $requiredFilters, true)) {
                return false; // Filtro no reconocido
            }

            if (is_string($filters[$filterKey]) && trim($filters[$filterKey]) === '') {
                return false;
            }

            // Validar el tipo del valor
            if (! UsersCopilotCapabilityCatalog::isValidFilterValue($filterKey, $filters[$filterKey])) {
                return false;
            }
        }

        // Paso 4: Confianza
        $threshold = config('ai-copilot.intent_classifier.confidence_threshold', 0.7);
        if ($confidence < $threshold) {
            return false;
        }

        if (! $this->llmClassificationHasRequiredCriteria($definition, $filters, $subjectUser)) {
            return false;
        }

        return true;
    }

    /**
     * Construye un plan desde una clasificación LLM validada.
     */
    protected function buildPlanFromLLMClassification(string $normalized, array $classification, CopilotConversationSnapshot $snapshot, ?User $subjectUser): array
    {
        $capabilityKey = $classification['capability_key'];
        $intentFamily = $classification['intent_family'];
        $filters = $classification['filters'];
        $resolvedEntity = $this->resolveLLMClassificationSubject($subjectUser, $filters);

        // Marcar como procedente de LLM
        $plan = [
            'request_normalization' => $normalized,
            'intent_family' => $intentFamily,
            'capability_key' => $capabilityKey,
            'filters' => array_merge($this->emptyFilters(), $filters),
            'resolved_entity' => $resolvedEntity,
            'missing_slots' => [],
            'clarification_state' => null,
            'proposal_vs_execute' => 'none',
            'classification_source' => 'llm_fallback',
        ];

        // Determinar proposal_vs_execute según la familia
        $definition = UsersCopilotCapabilityCatalog::find($capabilityKey);
        if ($definition !== null) {
            $plan['proposal_vs_execute'] = match ($definition['family']) {
                'aggregate', 'list', 'detail', 'explain' => 'execute',
                'action' => 'proposal',
                default => 'none',
            };
        }

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $filters
     */
    protected function llmClassificationHasRequiredCriteria(array $definition, array $filters, ?User $subjectUser): bool
    {
        $capabilityKey = (string) ($definition['key'] ?? '');

        if (in_array($capabilityKey, ['users.search', 'users.mixed.metrics_search'], true)) {
            return $this->hasEffectiveSearchCriteria([
                ...$this->emptyFilters(),
                ...$filters,
            ]);
        }

        if (($definition['requires_entity'] ?? false) === true
            && ! $subjectUser instanceof User
            && ! $this->llmFiltersContainUserId($filters)) {
            return false;
        }

        return match ($capabilityKey) {
            'users.explain.permission' => is_string($filters['permission'] ?? null)
                && trim($filters['permission']) !== '',
            'users.explain.action' => $this->llmFiltersContainUserId($filters)
                && $this->llmFiltersContainTargetUserId($filters)
                && is_string($filters['action_type'] ?? null)
                && trim($filters['action_type']) !== '',
            default => true,
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>|null
     */
    protected function resolveLLMClassificationSubject(?User $subjectUser, array $filters): ?array
    {
        if ($subjectUser instanceof User) {
            return [
                'type' => 'user',
                'id' => $subjectUser->id,
                'label' => $subjectUser->name,
            ];
        }

        if (! $this->llmFiltersContainUserId($filters)) {
            return null;
        }

        $user = User::query()->find((int) $filters['user_id']);

        if (! $user instanceof User) {
            return null;
        }

        return [
            'type' => 'user',
            'id' => $user->id,
            'label' => $user->name,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function llmFiltersContainUserId(array $filters): bool
    {
        $userId = $filters['user_id'] ?? null;

        return is_int($userId) || (is_string($userId) && ctype_digit($userId));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function llmFiltersContainTargetUserId(array $filters): bool
    {
        $targetUserId = $filters['target_user_id'] ?? null;

        return is_int($targetUserId) || (is_string($targetUserId) && ctype_digit($targetUserId));
    }

    /**
     * Registra log de clasificación LLM.
     */
    protected function logLLMClassification(string $normalized, array $classification, bool $accepted, ?string $rejectReason): void
    {
        $sanitized = preg_replace('/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/', '[email]', $normalized) ?? $normalized;

        Log::info('copilot.planner.llm_classification', [
            'prompt_normalized' => $sanitized,
            'llm_intent_family' => $classification['intent_family'],
            'llm_capability_key' => $classification['capability_key'],
            'llm_confidence' => $classification['confidence'],
            'accepted' => $accepted,
            'reject_reason' => $rejectReason,
            'correlation_id' => Context::get('correlation_id'),
        ]);
    }

    protected function normalizePrompt(string $prompt): string
    {
        $normalized = UsersCopilotDomainLexicon::normalize($prompt);

        return UsersCopilotDomainLexicon::correctTypos($normalized);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function matchConfiguredMatrix(string $normalized, CopilotConversationSnapshot $snapshot): ?array
    {
        foreach (['deterministic', 'extended'] as $matrix) {
            foreach (Arr::wrap(config("ai-copilot.planning.matrices.{$matrix}")) as $case) {
                if (($case['prompt'] ?? null) !== $normalized) {
                    continue;
                }

                if (($case['requires_snapshot'] ?? false) === true && ! $this->hasSnapshotContext($snapshot)) {
                    return $this->clarificationPlan(
                        normalized: $normalized,
                        reason: 'missing_context',
                        question: 'Necesito el contexto previo o un criterio mas especifico para continuar.',
                    );
                }

                if (! is_string($case['capability_key'] ?? null) && ($case['intent_family'] ?? null) === 'ambiguous') {
                    return $this->clarificationPlan(
                        normalized: $normalized,
                        reason: 'ambiguous_target',
                        question: 'Necesito que aclares a que usuario o subconjunto te refieres.',
                    );
                }

                if (! is_string($case['capability_key'] ?? null)) {
                    if ($this->looksLikeCountFollowUp($normalized)) {
                        return $this->countFollowUpPlan($normalized, $snapshot);
                    }

                    return $this->clarificationPlan(
                        normalized: $normalized,
                        reason: 'missing_context',
                        question: 'Necesito el resultado anterior o un criterio explicito para responder con seguridad.',
                    );
                }

                return $this->planFromCapability($normalized, $case['capability_key']);
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolveFollowUp(string $normalized, CopilotConversationSnapshot $snapshot): ?array
    {
        if (! $snapshot->hasContext()) {
            return null;
        }

        if ($this->looksLikeCountFollowUp($normalized)) {
            return $this->countFollowUpPlan($normalized, $snapshot);
        }

        if ($this->looksLikeSubsetRefinement($normalized)) {
            return $this->subsetFollowUpPlan($normalized, $snapshot);
        }

        if ($this->looksLikeEntityReference($normalized)) {
            return $this->entityReferencePlan($normalized, $snapshot);
        }

        return null;
    }

    public function resolveContextBoundaryPrompt(string $normalized, CopilotConversationSnapshot $snapshot): ?array
    {
        if ($snapshot->hasContext()) {
            return null;
        }

        if ($this->looksLikeActionProposal($normalized) && $this->looksLikeBulkAction($normalized)) {
            return null;
        }

        if (preg_match('/\b(?:haz|hacer|hace|hac[eé]?)\s+lo\s+mismo\b|\blo\s+mismo\b/u', $normalized) === 1) {
            return $this->clarificationPlan(
                normalized: $normalized,
                reason: 'missing_context',
                question: 'Necesito un antecedente estructurado para saber que accion repetir.',
            );
        }

        if (! $this->isCreateUserProposal($normalized) && ! $this->looksLikeConditionalSearch($normalized) && ($this->looksLikeConfirmation($normalized) || $this->looksLikeStandaloneActionConfirmation($normalized))) {
            return $this->clarificationPlan(
                normalized: $normalized,
                reason: 'missing_context',
                question: 'No tengo una propuesta pendiente que confirmar. Indica la accion y el usuario especifico antes de continuar.',
            );
        }

        if ($this->looksLikeEntityReference($normalized)) {
            return $this->clarificationPlan(
                normalized: $normalized,
                reason: 'missing_context',
                question: 'Necesito que me indiques a que usuario te refieres antes de continuar.',
            );
        }

        if ($this->looksLikeCountFollowUp($normalized) || $this->looksLikeSubsetRefinement($normalized)) {
            return $this->clarificationPlan(
                normalized: $normalized,
                reason: 'missing_context',
                question: 'Necesito una busqueda o resultado previo para responder a ese seguimiento.',
            );
        }

        if ($this->matchPermissionSearchIntent($normalized) !== null) {
            return null;
        }

        if ($this->looksLikeActionProposal($normalized)
            && ! $this->isCreateUserProposal($normalized)
            && $this->extractEntitySearchQuery($normalized) === null) {
            return $this->clarificationPlan(
                normalized: $normalized,
                reason: 'missing_context',
                question: 'Necesito que indiques el usuario especifico antes de proponer esa accion.',
            );
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolvePendingClarification(string $normalized, CopilotConversationSnapshot $snapshot): ?array
    {
        $clarification = $snapshot->pendingClarification();

        if (! is_array($clarification)) {
            return null;
        }

        if ($this->looksLikeNewIntent($normalized)) {
            return null;
        }

        $options = array_values(array_filter(
            Arr::wrap($clarification['options'] ?? []),
            static fn (mixed $option): bool => is_array($option),
        ));

        // Si el usuario está RECHAZANDO, devolver null para permitir nueva búsqueda
        if ($this->looksLikeRejection($normalized)) {
            return null; // El usuario rechazó la sugerencia, permitir nuevo intento
        }

        // Si hay options y el usuario está confirmando, seleccionar la primera sugerencia
        if (count($options) === 1 && $this->looksLikeConfirmation($normalized)) {
            return $this->planFromClarificationOption($normalized, $options[0]);
        }

        // Si hay múltiples options y el usuario confirma sin especificar, pedir clarificación
        if (count($options) > 1 && $this->looksLikeConfirmation($normalized)) {
            return $this->clarificationPlanWithOptions(
                normalized: $normalized,
                reason: 'ambiguous_target',
                question: 'Hay varias opciones posibles. Por favor, indica específicamente cuál deseas seleccionar usando el número o el nombre completo.',
                options: $options, // PRESERVAR opciones para siguiente interacción
            );
        }

        if ($options === []) {
            return $this->clarificationPlan(
                normalized: $normalized,
                reason: (string) ($clarification['reason'] ?? 'missing_context'),
                question: (string) ($clarification['question'] ?? 'Necesito una aclaracion para continuar.'),
            );
        }

        $hint = $this->clarificationHint($normalized);

        $matches = array_values(array_filter($options, fn (array $option): bool => $this->clarificationOptionMatchesPrompt($option, $normalized)
            || ($hint !== null && $this->clarificationOptionMatchesPrompt($option, $hint))));

        if (count($matches) !== 1 && $hint !== null) {
            $matchedOption = $this->resolveClarificationOptionFromUserHint($hint, $options);

            if ($matchedOption !== null) {
                return $this->planFromClarificationOption($normalized, $matchedOption);
            }
        }

        if (count($matches) !== 1) {
            return $this->clarificationPlan(
                normalized: $normalized,
                reason: (string) ($clarification['reason'] ?? 'ambiguous_target'),
                question: (string) ($clarification['question'] ?? 'Necesito una aclaracion para continuar.'),
            );
        }

        return $this->planFromClarificationOption($normalized, $matches[0]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function matchPendingActionCancellation(string $normalized, CopilotConversationSnapshot $snapshot): ?array
    {
        if (! is_array($snapshot->pendingActionProposal()) && ! is_array($snapshot->pendingCreateUser())) {
            return null;
        }

        if (! $this->looksLikeCancellation($normalized)) {
            return null;
        }

        return [
            'request_normalization' => $normalized,
            'intent_family' => 'help',
            'capability_key' => 'users.actions.cancel_pending',
            'filters' => $this->emptyFilters(),
            'resolved_entity' => null,
            'missing_slots' => [],
            'clarification_state' => null,
            'proposal_vs_execute' => 'none',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function matchActionConfirmationWithoutPendingProposal(string $normalized, CopilotConversationSnapshot $snapshot): ?array
    {
        if (is_array($snapshot->pendingActionProposal()) || is_array($snapshot->pendingCreateUser())) {
            return null;
        }

        if (! $this->looksLikeStandaloneActionConfirmation($normalized)) {
            return null;
        }

        return $this->clarificationPlan(
            normalized: $normalized,
            reason: 'missing_context',
            question: 'No tengo una propuesta pendiente que confirmar. Indica la accion y el usuario especifico antes de continuar.',
        );
    }

    protected function looksLikeNewIntent(string $normalized): bool
    {
        return preg_match('/\b(busca|buscar|lista|listar|muestra|mostrar|explica|como\s+puedo|cuantos|dame|encuentra|crear|crea)\b/u', $normalized) === 1;
    }

    /**
     * Detecta si el usuario está RECHAZANDO una sugerencia anterior.
     * Frases como: "no", "no ese", "incorrecto", "ese no", "no es", etc.
     * Importante: verificar ANTES que looksLikeConfirmation para evitar falsos positivos.
     */
    protected function looksLikeRejection(string $normalized): bool
    {
        // Patrones de negación fuerte al inicio
        if (preg_match('/^\s*no\b/u', $normalized) === 1) {
            return true;
        }

        // Patrones de rechazo explícito
        $rejectionPatterns = [
            '/\bno\s+(ese|esa|esos|esas|el|la|los|las)\b/u',           // "no ese", "no el"
            '/\b(ese|esa|esos|esas)\s+no\b/u',                         // "ese no", "esa no"
            '/\bno\s+es\b/u',                                          // "no es"
            '/\bincorrecto\b/u',                                      // "incorrecto"
            '/\bno\s+(quiero|ese|correcto|valido)\b/u',               // "no quiero", "no correcto"
            '/\botro\b/u',                                              // "otro"
            '/\bdiferente\b/u',                                        // "diferente"
        ];

        foreach ($rejectionPatterns as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    protected function looksLikeCancellation(string $normalized): bool
    {
        return preg_match('/\b(cancela|cancelar|cancelalo|canc[eé]lalo|descarta|descartar|olvida|dejalo|d[eé]jalo|no\s+lo\s+hagas|mejor\s+no)\b/u', $normalized) === 1;
    }

    /**
     * Detecta si el usuario está confirmando una sugerencia anterior.
     * Frases como: "sí", "ese", "el que dices", "correcto", "así es", etc.
     */
    protected function looksLikeConfirmation(string $normalized): bool
    {
        $confirmationPatterns = [
            '/^\s*(si|sí|yes|correcto|exacto|as[ií]\s+es|claro|por\s+supuesto)\b/u',
            '/\b(ese|esa|el\s+que\s+dices?|la\s+que\s+dices?|el\s+que\s+indicas?|el\s+primero|la\s+primera)\b/u',
            '/\b(el\s+que\s+me\s+indicas?|la\s+que\s+me\s+indicas?|el\s+sugerido|la\s+sugerida)\b/u',
            '/\b(me\s+refiero\s+a|quise\s+decir|quer[ií]a\s+decir|me\s+refer[ií]a)\b/u',
            '/\b(el\s+que|la\s+que|a\s+ese|a\s+esa)\s+(dijiste|mencionaste|sugeriste)\b/u',
        ];

        foreach ($confirmationPatterns as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    protected function looksLikeConditionalSearch(string $normalized): bool
    {
        return preg_match('/\bsi\s+(ya\s+)?existe\b/u', $normalized) === 1
            || preg_match('/\bsi\s+puedes?\b/u', $normalized) === 1;
    }

    protected function looksLikeStandaloneActionConfirmation(string $normalized): bool
    {
        return preg_match('/\b(confirma|confirmalo|confirmar|ejecuta|ejecutalo|hazlo|hacelo|procede|adelante|dale|ok|listo)\b/u', $normalized) === 1;
    }

    /**
     * @param  array<string, mixed>  $option
     * @return array<string, mixed>
     */
    protected function planFromClarificationOption(string $normalized, array $option): array
    {
        $capabilityKey = is_string($option['capability_key'] ?? null)
            ? $option['capability_key']
            : 'users.detail';

        $intentFamily = is_string($option['intent_family'] ?? null)
            ? $option['intent_family']
            : (($capabilityKey === 'users.detail') ? 'read_detail' : 'action_proposal');

        return [
            'request_normalization' => $normalized,
            'intent_family' => $intentFamily,
            'capability_key' => $capabilityKey,
            'filters' => $this->emptyFilters(),
            'resolved_entity' => is_array($option['resolved_entity'] ?? null) ? $option['resolved_entity'] : null,
            'missing_slots' => [],
            'clarification_state' => null,
            'proposal_vs_execute' => $intentFamily === 'action_proposal' ? 'proposal' : 'execute',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function countFollowUpPlan(string $normalized, CopilotConversationSnapshot $snapshot): ?array
    {
        $metricSnapshot = $snapshot->lastMetricsSnapshot();

        if (is_array($metricSnapshot) && is_string($metricSnapshot['capability_key'] ?? null)) {
            return $this->planFromCapability($normalized, $metricSnapshot['capability_key']);
        }

        if ($snapshot->lastResultCount() === null) {
            return $this->clarificationPlan(
                normalized: $normalized,
                reason: 'missing_context',
                question: 'Necesito el resultado anterior o un criterio explicito para decirte cuantos son.',
            );
        }

        return [
            'request_normalization' => $normalized,
            'intent_family' => 'read_metrics',
            'capability_key' => 'users.snapshot.result_count',
            'filters' => $snapshot->lastFilters(),
            'resolved_entity' => null,
            'missing_slots' => [],
            'clarification_state' => null,
            'proposal_vs_execute' => 'execute',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function subsetFollowUpPlan(string $normalized, CopilotConversationSnapshot $snapshot): ?array
    {
        if ($snapshot->lastCapabilityKey() !== 'users.search' && $snapshot->lastFilters() === []) {
            return $this->clarificationPlan(
                normalized: $normalized,
                reason: 'missing_context',
                question: 'Necesito una busqueda previa para poder refinar ese subconjunto.',
            );
        }

        $filters = [
            ...$this->emptyFilters(),
            ...$snapshot->lastFilters(),
        ];

        if (str_contains($normalized, 'inactivo')) {
            $filters['status'] = 'inactive';
        }

        if (str_contains($normalized, 'activo') && ! str_contains($normalized, 'inactivo')) {
            $filters['status'] = 'active';
        }

        if (str_contains($normalized, 'no verificado')) {
            $filters['email_verified'] = false;
        }

        if (str_contains($normalized, 'verificado') && ! str_contains($normalized, 'no verificado')) {
            $filters['email_verified'] = true;
        }

        if (preg_match('/\b(con\s+dos\s+factores|con\s+2fa|tiene\s+2fa|tienen\s+2fa|2fa)\b/u', $normalized) === 1) {
            $filters['two_factor_enabled'] = true;
        }

        if (preg_match('/\b(sin\s+dos\s+factores|sin\s+2fa)\b/u', $normalized) === 1) {
            $filters['two_factor_enabled'] = false;
        }

        if ($this->extractHasRolesFilter($normalized) === false) {
            $filters['has_roles'] = false;
        }

        if ($this->extractHasRolesFilter($normalized) === true) {
            $filters['has_roles'] = true;
        }

        $roleFilter = $this->extractRoleFilter($normalized);

        if ($roleFilter !== null) {
            $filters['role'] = $roleFilter;
        }

        return [
            'request_normalization' => $normalized,
            'intent_family' => 'read_search',
            'capability_key' => 'users.search',
            'filters' => $filters,
            'resolved_entity' => null,
            'missing_slots' => [],
            'clarification_state' => null,
            'proposal_vs_execute' => 'execute',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function entityReferencePlan(string $normalized, CopilotConversationSnapshot $snapshot): ?array
    {
        $resolvedEntity = $snapshot->resolvedEntity();
        $resolvedUserId = is_array($resolvedEntity) && (($resolvedEntity['type'] ?? null) === 'user')
            ? ($resolvedEntity['id'] ?? null)
            : null;
        $resolvedUserId ??= $this->firstOrdinalReferenceUserId($normalized, $snapshot);
        $resolvedUserId ??= $snapshot->singleResultUserId();

        if (! is_int($resolvedUserId)) {
            if (($snapshot->lastResultCount() ?? 0) > 1) {
                return $this->clarificationPlan(
                    normalized: $normalized,
                    reason: 'ambiguous_target',
                    question: 'Hay varios usuarios posibles en el contexto previo. Indica cual de ellos quieres revisar.',
                );
            }

            return $this->clarificationPlan(
                normalized: $normalized,
                reason: 'missing_context',
                question: 'Necesito que me indiques que usuario quieres revisar o una busqueda previa con un unico resultado.',
            );
        }

        $resolvedUser = User::query()->find($resolvedUserId);

        if (! $resolvedUser instanceof User) {
            return $this->clarificationPlan(
                normalized: $normalized,
                reason: 'missing_context',
                question: 'Perdi el contexto del usuario anterior. Indica de nuevo el usuario que quieres revisar.',
            );
        }

        return $this->looksLikeActionProposal($normalized)
            ? $this->actionPlan($normalized, $resolvedUser)
            : $this->detailPlan($normalized, $resolvedUser);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolveEntityDrivenPlan(string $normalized, CopilotConversationSnapshot $snapshot): ?array
    {
        if (! $this->looksLikeUserTargetingPrompt($normalized)) {
            return null;
        }

        if ($this->looksLikeEntityReference($normalized)) {
            return $this->entityReferencePlan($normalized, $snapshot);
        }

        $query = $this->extractEntitySearchQuery($normalized);

        if ($query === null) {
            return $this->clarificationPlan(
                normalized: $normalized,
                reason: 'missing_target',
                question: 'Necesito que me indiques el usuario concreto que quieres revisar.',
                missingSlots: ['user_id'],
            );
        }

        $matches = $this->candidateUsersFor($query);

        // Si se proporcionó email pero no se encontró, dar mensaje específico con sugerencias
        $email = $this->extractEmailFromText($normalized);
        if ($email !== null && $matches->isEmpty()) {
            $similarEmails = $this->findSimilarEmails($email);
            $suggestion = '';
            $options = [];

            if (! empty($similarEmails)) {
                $suggestedEmail = $similarEmails[0]['email'];
                $suggestion = " ¿Quizás quisiste decir '{$suggestedEmail}'?";
                // Crear opción estructurada para el usuario sugerido
                foreach ($similarEmails as $similar) {
                    $user = User::query()->find($similar['user_id']);
                    if ($user instanceof User) {
                        $options[] = [
                            'label' => sprintf('%s <%s>', $user->name, $user->email),
                            'value' => 'user:'.$user->id,
                            'capability_key' => 'users.detail',
                            'intent_family' => 'read_detail',
                            'resolved_entity' => [
                                'type' => 'user',
                                'id' => $user->id,
                                'label' => $user->name,
                            ],
                        ];
                    }
                }
            }

            return $this->clarificationPlanWithOptions(
                normalized: $normalized,
                reason: 'missing_entity',
                question: "No encontré ningún usuario con el correo '{$email}'.{$suggestion} ¿Está correcto el email o quieres buscar por nombre?",
                options: $options,
                missingSlots: ['user_id'],
            );
        }

        // Si no se encontró por nombre (y no era email), dar sugerencias de nombres similares
        if ($email === null && $matches->isEmpty()) {
            $similarNames = $this->findSimilarNames($query);
            $suggestion = '';
            $options = [];

            if (! empty($similarNames)) {
                $suggestions = [];
                foreach ($similarNames as $similar) {
                    $suggestions[] = $similar['name'];
                    // Crear opción estructurada para cada nombre similar
                    $user = User::query()->find($similar['user_id']);
                    if ($user instanceof User) {
                        $options[] = [
                            'label' => sprintf('%s <%s>', $user->name, $user->email),
                            'value' => 'user:'.$user->id,
                            'capability_key' => 'users.detail',
                            'intent_family' => 'read_detail',
                            'resolved_entity' => [
                                'type' => 'user',
                                'id' => $user->id,
                                'label' => $user->name,
                            ],
                        ];
                    }
                }
                $suggestionText = implode(', ', $suggestions);
                $suggestion = " ¿Quizás quisiste decir: {$suggestionText}?";
            }

            return $this->clarificationPlanWithOptions(
                normalized: $normalized,
                reason: 'missing_target',
                question: "No pude identificar un usuario único con esa referencia.{$suggestion} Indica el nombre completo o el correo.",
                options: $options,
                missingSlots: ['user_id'],
            );
        }

        if ($matches->count() > 1) {
            return $this->ambiguousUserClarificationPlan(
                $normalized,
                $matches,
                $this->looksLikeActionProposal($normalized) ? $this->actionCapabilityKey($normalized) : 'users.detail',
            );
        }

        if ($matches->count() === 1) {
            $match = $matches->first();

            if ($this->looksLikeActionProposal($normalized)) {
                return $this->actionPlan($normalized, $match);
            }

            if ($this->shouldConfirmLowConfidenceDetailMatch($query, $match)) {
                return $this->clarificationPlanWithOptions(
                    normalized: $normalized,
                    reason: 'low_confidence_match',
                    question: sprintf(
                        "Encontre una coincidencia probable con '%s'. Confirma si te refieres a %s <%s>.",
                        $query,
                        $match->name,
                        $match->email,
                    ),
                    options: [[
                        'label' => sprintf('%s <%s>', $match->name, $match->email),
                        'value' => 'user:'.$match->id,
                        'capability_key' => 'users.detail',
                        'intent_family' => 'read_detail',
                        'resolved_entity' => [
                            'type' => 'user',
                            'id' => $match->id,
                            'label' => $match->name,
                        ],
                    ]],
                    missingSlots: ['user_id'],
                );
            }

            if ($this->looksLikeCapabilitiesSummaryPrompt($normalized)) {
                return $this->capabilitiesSummaryPlan($normalized, $match);
            }

            if ($permission = UsersCopilotDomainLexicon::permissionNameForIntent($normalized)) {
                return $this->permissionExplainPlan($normalized, $match, $permission);
            }

            return $this->detailPlan($normalized, $match);
        }

        return $this->clarificationPlan(
            normalized: $normalized,
            reason: 'missing_target',
            question: 'No pude identificar un usuario unico con esa referencia. Indica el nombre completo o el correo.',
            missingSlots: ['user_id'],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function matchMetricsIntent(string $normalized): ?array
    {
        if ($this->extractAccessProfileFilter($normalized) === 'administrative_access'
            && preg_match('/\b(cantidad|cuantos|cuantas|hay)\b/u', $normalized) === 1
            && preg_match('/\b(quienes\s+son|quien(es)?\s+son)\b/u', $normalized) === 1) {
            $searchPlan = $this->searchPlan($normalized);

            return [
                ...$searchPlan,
                'request_normalization' => $normalized,
                'capability_key' => 'users.mixed.metrics_search',
            ];
        }

        if ($this->looksLikeCombinedMetricsQuery($normalized)) {
            return $this->combinedMetricsPlan($normalized);
        }

        $capabilityKey = match (true) {
            str_contains($normalized, 'rol mas comun') => 'users.metrics.most_common_role',
            $this->extractAccessProfileFilter($normalized) === 'administrative_access' && preg_match('/\b(cantidad|cuantos|cuantas|total|tenemos|hay)\b/u', $normalized) === 1 => 'users.metrics.admin_access',
            str_contains($normalized, 'distribucion de roles') => 'users.metrics.role_distribution',
            $this->extractHasRolesFilter($normalized) === false => 'users.metrics.without_roles',
            $this->extractHasRolesFilter($normalized) === true => 'users.metrics.with_roles',
            str_contains($normalized, 'no estan verificados'), str_contains($normalized, 'no verificados') => 'users.metrics.unverified',
            str_contains($normalized, 'estan verificados'), str_contains($normalized, 'verificados') => 'users.metrics.verified',
            preg_match('/\bcuantos\s+activos\s+hay\b/u', $normalized) === 1 => 'users.metrics.active',
            preg_match('/\bcuantos\s+inactivos\s+hay\b/u', $normalized) === 1 => 'users.metrics.inactive',
            str_contains($normalized, 'usuarios inactivos') && str_contains($normalized, 'cuantos') => 'users.metrics.inactive',
            str_contains($normalized, 'usuarios activos') && str_contains($normalized, 'cuantos') => 'users.metrics.active',
            str_contains($normalized, 'cuantos usuarios hay'), str_contains($normalized, 'total actual de usuarios'), str_contains($normalized, 'cantidad de usuarios') => 'users.metrics.total',
            default => null,
        };

        if ($capabilityKey !== null) {
            return $this->planFromCapability($normalized, $capabilityKey);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function matchPermissionSearchIntent(string $normalized): ?array
    {
        $permission = UsersCopilotDomainLexicon::permissionNameForIntent($normalized);

        if ($permission === null) {
            return null;
        }

        if (preg_match('/\b(quien|quienes|usuarios?|list(a|ame)|muestrame|muestra|dame)\b/u', $normalized) === 1
            && preg_match('/\b(puede(n)?|pueda(n)?|tiene(n)?\s+permisos?|con\s+permisos?)\b/u', $normalized) === 1) {
            $plan = $this->searchPlan($normalized);
            $plan['filters']['permission'] = $permission;

            return $plan;
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function matchActionExplanationIntent(string $normalized): ?array
    {
        if (! str_contains($normalized, 'por que')) {
            return null;
        }

        $actionType = $this->extractActionTypeForExplanation($normalized);

        if ($actionType === null) {
            return null;
        }

        preg_match_all('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/', $normalized, $emailMatches);
        $emails = array_values(array_unique($emailMatches[0] ?? []));

        if (count($emails) < 2) {
            return null;
        }

        $subject = User::query()->whereRaw('lower(email) = ?', [Str::lower($emails[0])])->first();
        $target = User::query()->whereRaw('lower(email) = ?', [Str::lower($emails[1])])->first();

        if (! $subject instanceof User || ! $target instanceof User) {
            return null;
        }

        return [
            'request_normalization' => $normalized,
            'intent_family' => 'read_explain',
            'capability_key' => 'users.explain.action',
            'filters' => [
                ...$this->emptyFilters(),
                'action_type' => $actionType,
                'target_user_id' => $target->id,
            ],
            'resolved_entity' => [
                'type' => 'user',
                'id' => $subject->id,
                'label' => $subject->name,
            ],
            'missing_slots' => [],
            'clarification_state' => null,
            'proposal_vs_execute' => 'execute',
        ];
    }

    public function looksLikeActionExplanationPrompt(string $normalized): bool
    {
        return str_contains($normalized, 'por que')
            && $this->extractActionTypeForExplanation($normalized) !== null;
    }

    public function looksLikeCapabilitiesSummaryPrompt(string $normalized): bool
    {
        return preg_match('/\b(que\s+puede\s+hacer|que\s+no\s+puede\s+hacer)\b/u', $normalized) === 1;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function matchRolesCatalogIntent(string $normalized): ?array
    {
        if (preg_match('/\b(cuales|que)\s+roles\s+(existen|hay)\b/u', $normalized) === 1
            || preg_match('/\b(lista|listar|listame|muestra|mostrar|dame)\s+los\s+roles\b/u', $normalized) === 1) {
            return $this->planFromCapability($normalized, 'users.roles.catalog');
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function matchMixedMetricsSearchIntent(string $normalized): ?array
    {
        if (! $this->looksLikeExplicitCollectionSearch($normalized)) {
            return null;
        }

        if (count($this->metricTopicsFor($normalized)) < 2) {
            return null;
        }

        $searchPlan = $this->searchPlan($this->extractMixedSearchFragment($normalized) ?? $normalized);

        return [
            ...$searchPlan,
            'request_normalization' => $normalized,
            'capability_key' => 'users.mixed.metrics_search',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function matchMixedCreateSearchIntent(string $normalized): ?array
    {
        if (! $this->isCreateUserProposal($normalized)
            || preg_match('/\b(busca|buscar|checa|activos|existe|parecido|similar|permisos|datos\s+te\s+faltan|crear\s+usuarios\s+en\s+general|hay\s+otros|ya\s+existe|si\s+ya\s+existe|si\s+no\s+hay)\b/u', $normalized) !== 1) {
            return null;
        }

        $searchPlan = $this->searchPlan($normalized);

        return [
            ...$searchPlan,
            'request_normalization' => $normalized,
            'capability_key' => 'users.mixed.create_search',
            'mixed_segments' => [
                [
                    'text' => 'Preparar alta guiada de usuario',
                    'status' => 'not_executed',
                    'reason' => 'las altas requieren una propuesta completa y confirmacion explicita antes de ejecutar',
                    'suggested_follow_up' => 'Completa la propuesta de alta guiada en un turno separado',
                ],
            ],
            'create_user_payload' => $this->extractCreateUserPayload($normalized),
        ];
    }

    protected function extractMixedSearchFragment(string $normalized): ?string
    {
        $segments = preg_split('/\s+y\s+/u', $normalized) ?: [];

        for ($index = count($segments) - 1; $index >= 0; $index--) {
            $segment = trim((string) ($segments[$index] ?? ''));

            if ($segment === '') {
                continue;
            }

            if ($this->looksLikeExplicitCollectionSearch($segment)
                || preg_match('/\b(listame|que\s+usuarios|quienes\s+son)\b/u', $segment) === 1) {
                return $segment;
            }
        }

        return null;
    }

    protected function looksLikeCombinedMetricsQuery(string $normalized): bool
    {
        if ($this->looksLikeExplicitCollectionSearch($normalized) || $this->looksLikeExplicitDetailPrompt($normalized)) {
            return false;
        }

        return (count($this->metricTopicsFor($normalized)) >= 2
            || ($this->extractAccessProfileFilter($normalized) === 'administrative_access'
                && preg_match('/\b(cantidad|cuantos|cuantas|hay)\b/u', $normalized) === 1
                && preg_match('/\b(quienes\s+son|listame|lista|muestrame|muestra|dime)\b/u', $normalized) === 1))
            && preg_match('/\b(cantidad|cuantos|cuantas|lista|listar|listame|dame|muestra|mostrar)\b/u', $normalized) === 1;
    }

    /**
     * @return list<string>
     */
    protected function metricTopicsFor(string $normalized): array
    {
        $topics = [];

        if (str_contains($normalized, 'cuantos usuarios hay') || str_contains($normalized, 'total actual de usuarios') || str_contains($normalized, 'cantidad de usuarios') || preg_match('/\btotal\b/u', $normalized) === 1) {
            $topics[] = 'total';
        }

        if (str_contains($normalized, 'usuarios activos') || (str_contains($normalized, 'activos') && ! str_contains($normalized, 'inactivos'))) {
            $topics[] = 'active';
        }

        if (str_contains($normalized, 'usuarios inactivos') || str_contains($normalized, 'inactivos')) {
            $topics[] = 'inactive';
        }

        if (str_contains($normalized, 'rol mas comun')) {
            $topics[] = 'most_common_role';
        } elseif (str_contains($normalized, 'distribucion de roles')) {
            $topics[] = 'role_distribution';
        }

        if ($this->extractAccessProfileFilter($normalized) === 'administrative_access'
            && preg_match('/\b(cantidad|cuantos|cuantas|total|tenemos|hay)\b/u', $normalized) === 1) {
            $topics[] = 'admin_access';
        }

        if (str_contains($normalized, 'no estan verificados') || str_contains($normalized, 'no verificados')) {
            $topics[] = 'unverified';
        } elseif (str_contains($normalized, 'estan verificados') || str_contains($normalized, 'verificados')) {
            $topics[] = 'verified';
        }

        if ($this->extractHasRolesFilter($normalized) === false) {
            $topics[] = 'without_roles';
        } elseif ($this->extractHasRolesFilter($normalized) === true) {
            $topics[] = 'with_roles';
        }

        return array_values(array_unique($topics));
    }

    /**
     * @return array<string, mixed>
     */
    protected function combinedMetricsPlan(string $normalized): array
    {
        $roleFilter = $this->extractRoleFilter($normalized);
        $accessProfile = $this->extractAccessProfileFilter($normalized);

        $filters = $this->emptyFilters();

        if ($roleFilter !== null) {
            $filters['role'] = $roleFilter;
        }

        if ($accessProfile !== null) {
            $filters['access_profile'] = $accessProfile;
        }

        return [
            'request_normalization' => $normalized,
            'intent_family' => 'read_metrics',
            'capability_key' => 'users.metrics.combined',
            'filters' => $filters,
            'resolved_entity' => null,
            'missing_slots' => [],
            'clarification_state' => null,
            'proposal_vs_execute' => 'execute',
        ];
    }

    public function looksLikeSearch(string $normalized): bool
    {
        if ($this->looksLikeExplicitDetailPrompt($normalized) && ! $this->looksLikeExplicitCollectionSearch($normalized)) {
            return false;
        }

        // Fix Fase 5: precedencia. "Dame de alta" / "dame el alta" contiene
        // "dame" (verbo de busqueda) pero es alta, no search. Prioriza create.
        if ($this->isCreateUserProposal($normalized)) {
            return false;
        }

        return preg_match('/\b(busca|buscar|muestra|mostrar|lista|listar|listame|encuentra|encontrar|dame)\b/u', $normalized) === 1
            || preg_match('/\b(quienes?|que\s+usuarios)\b.*\b(rol|perfil|permisos|acceso)\b/u', $normalized) === 1
            || preg_match('/\busuarios\s+admin\b/u', $normalized) === 1
            || preg_match('/\badmin\s+del\s+sistema\b/u', $normalized) === 1
            || preg_match('/\busuarios\s+con\s+(rol|perfil)\b/u', $normalized) === 1
            || preg_match('/\badministradores\s+del\s+sistema\b/u', $normalized) === 1
            || preg_match('/\busuarios\s+(con|sin)\s+dos\s+factores\b/u', $normalized) === 1
            // Preguntas existenciales: "hay algún juan", "existe un usuario", "tienen alguno"
            || preg_match('/\b(hay|existe|tienen?)\s+(algun|alguna|alguno|algunos|algunas|un|una|el|la|los|las)\b/u', $normalized) === 1
            || str_contains($normalized, 'usuarios no verificados')
            || str_contains($normalized, 'usuarios sin roles')
            || str_contains($normalized, 'usuarios inactivos');
    }

    /**
     * @return array<string, mixed>
     */
    public function searchPlan(string $normalized): array
    {
        $filters = $this->emptyFilters();

        if (preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/', $normalized, $matches) === 1) {
            $filters['query'] = $matches[0];
        }

        if (str_contains($normalized, 'inactivo')) {
            $filters['status'] = 'inactive';
        }

        if (str_contains($normalized, 'activo') && ! str_contains($normalized, 'inactivo')) {
            $filters['status'] = 'active';
        }

        if (str_contains($normalized, 'no verificado')) {
            $filters['email_verified'] = false;
        }

        if (str_contains($normalized, 'verificado') && ! str_contains($normalized, 'no verificado')) {
            $filters['email_verified'] = true;
        }

        if (preg_match('/\b(sin\s+dos\s+factores|sin\s+2fa)\b/u', $normalized) === 1) {
            $filters['two_factor_enabled'] = false;
        }

        if (preg_match('/\b(con\s+dos\s+factores|usuarios\s+con\s+dos\s+factores|con\s+2fa)\b/u', $normalized) === 1) {
            $filters['two_factor_enabled'] = true;
        }

        if ($this->extractHasRolesFilter($normalized) === false) {
            $filters['has_roles'] = false;
        }

        if ($this->extractHasRolesFilter($normalized) === true) {
            $filters['has_roles'] = true;
        }

        $roleFilter = $this->extractRoleFilter($normalized);
        $accessProfile = $this->extractAccessProfileFilter($normalized);

        if ($roleFilter !== null) {
            $filters['role'] = $roleFilter;
        }

        if ($accessProfile !== null) {
            $filters['access_profile'] = $accessProfile;
        }

        if ($filters['access_profile'] === null && preg_match('/\busuarios\s+admin\b/u', $normalized) === 1) {
            $filters['access_profile'] = 'administrative_access';
        }

        $permission = UsersCopilotDomainLexicon::permissionNameForIntent($normalized);

        if ($permission !== null) {
            $filters['permission'] = $permission;
        }

        $filters['query'] ??= $this->extractSearchQuery($normalized);

        if ($searchCriteriaClarification = $this->searchCriteriaClarification($normalized, $filters)) {
            return $searchCriteriaClarification;
        }

        return [
            'request_normalization' => $normalized,
            'intent_family' => 'read_search',
            'capability_key' => 'users.search',
            'filters' => $filters,
            'resolved_entity' => null,
            'missing_slots' => [],
            'clarification_state' => null,
            'proposal_vs_execute' => 'execute',
        ];
    }

    protected function extractActionTypeForExplanation(string $normalized): ?string
    {
        return match (true) {
            str_contains($normalized, 'desactiv') => 'deactivate',
            str_contains($normalized, 'activ') || str_contains($normalized, 'reactiv') => 'activate',
            str_contains($normalized, 'reset') || str_contains($normalized, 'restablec') => 'send_reset',
            default => null,
        };
    }

    protected function extractSearchQuery(string $normalized): ?string
    {
        $patterns = [
            '/\b(?:llamad[oa]s?|nombre)\s+(.+?)(?:\s*$)/u',
            '/\b(?:busca|buscar|encuentra|encontrar|muestra|mostrar|lista|listar|listame|dame)\s+(?:al\s+)?usuario\s+(.+?)(?:\s*$)/u',
            '/\b(?:busca|buscar|encuentra|encontrar|checa)\s+a\s+(.+?)(?:\s*$)/u',
            '/\bsi\s+no\s+hay\s+(?:una|un)?\s*(.+?)(?:,|\s+y\s+|$)/u',
            '/\b(?:con\s+nombre)\s+(.+?)(?:\s*$)/u',
            '/\b(?:hay|existe|tienen?)\s+(?:algun|alguna|alguno|algunos|algunas|un|una)\s+(.+?)(?:\s*$)/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized, $matches) === 1) {
                $query = trim($matches[1]);

                return $query !== '' ? $query : null;
            }
        }

        return null;
    }

    /**
     * @param  array{query: ?string, status: 'active'|'inactive'|'all'|null, role: ?string, access_profile: ?string, permission: ?string, email_verified: ?bool, two_factor_enabled: ?bool, has_roles: ?bool}  $filters
     * @return array<string, mixed>|null
     */
    protected function searchCriteriaClarification(string $normalized, array $filters): ?array
    {
        if (! $this->hasEffectiveSearchCriteria($filters)) {
            return $this->clarificationPlan(
                normalized: $normalized,
                reason: 'insufficient_search_criteria',
                question: 'Necesito al menos un criterio para buscar: nombre, correo, estado, rol u otro filtro especifico.',
            );
        }

        if ($this->hasStructuredSearchCriteria($filters)) {
            return null;
        }

        if (! $this->isTypedSearchQuery($filters['query'])) {
            return $this->clarificationPlan(
                normalized: $normalized,
                reason: 'insufficient_search_criteria',
                question: 'Necesito un criterio de busqueda mas concreto. Indica un nombre, correo o filtro especifico para continuar.',
            );
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function detailPlan(string $normalized, User $subjectUser): array
    {
        return [
            'request_normalization' => $normalized,
            'intent_family' => 'read_detail',
            'capability_key' => 'users.detail',
            'filters' => [
                ...$this->emptyFilters(),
                'query' => null,
            ],
            'resolved_entity' => [
                'type' => 'user',
                'id' => $subjectUser->id,
                'label' => $subjectUser->name,
            ],
            'missing_slots' => [],
            'clarification_state' => null,
            'proposal_vs_execute' => 'execute',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function permissionExplainPlan(string $normalized, User $subjectUser, string $permission): array
    {
        return [
            'request_normalization' => $normalized,
            'intent_family' => 'read_explain',
            'capability_key' => 'users.explain.permission',
            'filters' => [
                ...$this->emptyFilters(),
                'permission' => $permission,
            ],
            'resolved_entity' => [
                'type' => 'user',
                'id' => $subjectUser->id,
                'label' => $subjectUser->name,
            ],
            'missing_slots' => [],
            'clarification_state' => null,
            'proposal_vs_execute' => 'execute',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function capabilitiesSummaryPlan(string $normalized, User $subjectUser): array
    {
        return [
            'request_normalization' => $normalized,
            'intent_family' => 'read_explain',
            'capability_key' => 'users.explain.capabilities_summary',
            'filters' => $this->emptyFilters(),
            'resolved_entity' => [
                'type' => 'user',
                'id' => $subjectUser->id,
                'label' => $subjectUser->name,
            ],
            'missing_slots' => [],
            'clarification_state' => null,
            'proposal_vs_execute' => 'execute',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function matchPendingCreateUserContinuation(string $normalized, CopilotConversationSnapshot $snapshot): ?array
    {
        $pending = $snapshot->pendingCreateUser();

        if (! is_array($pending)) {
            return null;
        }

        $currentPayload = is_array($pending['payload'] ?? null) ? $pending['payload'] : [];
        $mergedPayload = [
            ...$currentPayload,
            ...array_filter($this->extractCreateUserPayload($normalized), static fn (mixed $value): bool => $value !== null && $value !== []),
        ];

        if ($mergedPayload === $currentPayload && ! $this->looksLikeStandaloneActionConfirmation($normalized)) {
            return null;
        }

        return [
            'request_normalization' => $normalized,
            'intent_family' => 'action_proposal',
            'capability_key' => 'users.actions.create_user',
            'filters' => $this->emptyFilters(),
            'resolved_entity' => null,
            'missing_slots' => [],
            'clarification_state' => null,
            'proposal_vs_execute' => 'proposal',
            'create_user_payload' => $mergedPayload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function actionPlan(string $normalized, ?User $subjectUser): array
    {
        $capabilityKey = match (true) {
            str_contains($normalized, 'desactiv') => 'users.actions.deactivate',
            str_contains($normalized, 'activ') || str_contains($normalized, 'reactiv') => 'users.actions.activate',
            str_contains($normalized, 'reset'), str_contains($normalized, 'restablec') => 'users.actions.send_reset',
            $this->isCreateUserProposal($normalized) => 'users.actions.create_user',
            default => null,
        };

        if ($capabilityKey !== null && $capabilityKey !== 'users.actions.create_user' && $this->looksLikeBulkAction($normalized)) {
            return $this->clarificationPlan(
                normalized: $normalized,
                reason: 'denied:unsupported_bulk',
                question: 'No puedo ejecutar acciones masivas desde el copiloto. Indica un usuario especifico para continuar.',
            );
        }

        if ($capabilityKey === 'users.actions.create_user') {
            return [
                'request_normalization' => $normalized,
                'intent_family' => 'action_proposal',
                'capability_key' => $capabilityKey,
                'filters' => $this->emptyFilters(),
                'resolved_entity' => null,
                'missing_slots' => [],
                'clarification_state' => null,
                'proposal_vs_execute' => 'proposal',
                'create_user_payload' => $this->extractCreateUserPayload($normalized),
            ];
        }

        if (! $subjectUser instanceof User) {
            return [
                'request_normalization' => $normalized,
                'intent_family' => 'action_proposal',
                'capability_key' => null,
                'filters' => $this->emptyFilters(),
                'resolved_entity' => null,
                'missing_slots' => ['user_id'],
                'clarification_state' => null,
                'proposal_vs_execute' => 'proposal',
            ];
        }

        if ($capabilityKey === null) {
            // Fase 1d: capability desconocida tras normalizacion -> unknown.
            return $this->helpUnknownPlan($normalized);
        }

        return [
            'request_normalization' => $normalized,
            'intent_family' => 'action_proposal',
            'capability_key' => $capabilityKey,
            'filters' => $this->emptyFilters(),
            'resolved_entity' => [
                'type' => 'user',
                'id' => $subjectUser->id,
                'label' => $subjectUser->name,
            ],
            'missing_slots' => [],
            'clarification_state' => null,
            'proposal_vs_execute' => 'proposal',
        ];
    }

    /**
     * @param  list<string>  $missingSlots
     * @return array<string, mixed>
     */
    public function clarificationPlan(string $normalized, string $reason, string $question, array $missingSlots = []): array
    {
        $this->logFallback($normalized, 'users.clarification', 'ambiguous', $reason);

        return [
            'request_normalization' => $normalized,
            'intent_family' => 'ambiguous',
            'capability_key' => 'users.clarification',
            'filters' => $this->emptyFilters(),
            'resolved_entity' => null,
            'missing_slots' => $missingSlots,
            'clarification_state' => [
                'reason' => $reason,
                'question' => $question,
                'options' => [],
            ],
            'proposal_vs_execute' => 'none',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $options
     * @param  list<string>  $missingSlots
     * @return array<string, mixed>
     */
    protected function clarificationPlanWithOptions(string $normalized, string $reason, string $question, array $options, array $missingSlots = []): array
    {
        $this->logFallback($normalized, 'users.clarification', 'ambiguous', $reason);

        return [
            'request_normalization' => $normalized,
            'intent_family' => 'ambiguous',
            'capability_key' => 'users.clarification',
            'filters' => $this->emptyFilters(),
            'resolved_entity' => null,
            'missing_slots' => $missingSlots,
            'clarification_state' => [
                'reason' => $reason,
                'question' => $question,
                'options' => $options,
            ],
            'proposal_vs_execute' => 'none',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function helpPlan(string $normalized): array
    {
        // Conservado como alias legacy: redirige al unknown plan para mantener
        // compatibilidad con llamadas historicas que no han migrado aun.
        return $this->helpUnknownPlan($normalized);
    }

    /**
     * Fase 1d: plan canonico para prompts informativos reconocidos (ej:
     * "como funciona 2fa", "que puedo hacer"). Emite `users.help.informational`
     * cuando el flag `help_unknown_split` esta activo.
     *
     * @return array<string, mixed>
     */
    public function helpInformationalPlan(string $normalized): array
    {
        $capabilityKey = $this->isHelpUnknownSplitEnabled()
            ? 'users.help.informational'
            : 'users.help';

        $this->logFallback($normalized, $capabilityKey, 'help', null);

        return [
            'request_normalization' => $normalized,
            'intent_family' => 'help',
            'capability_key' => $capabilityKey,
            'filters' => $this->emptyFilters(),
            'resolved_entity' => null,
            'missing_slots' => [],
            'clarification_state' => null,
            'proposal_vs_execute' => 'none',
        ];
    }

    /**
     * Fix Fase 5: detector de prompts de AYUDA para crear usuarios. Cubre las
     * formulaciones naturales en las que el usuario pregunta COMO proceder,
     * que son semanticamente diferentes a dar instrucciones directas.
     *
     * Precedencia: si matchea aqui, NO debe pasar por action_proposal.
     * La presencia de un email o nombre propio indica intent ejecutivo y
     * descarta este detector.
     */
    public function looksLikeCreateUserHelpPrompt(string $normalized): bool
    {
        // Si hay email o datos concretos, no es ayuda: el usuario ya quiere ejecutar.
        if ($this->extractEmailFromText($normalized) !== null) {
            return false;
        }

        // Patrones informativos explicitos.
        // - "como doy de alta / como se da de alta / como agrego / como registro"
        // - "quiero ayuda para crear / ayuda con dar de alta"
        // - "que debo hacer para crear / como creo un usuario"
        // - "explicame como crear / explica como doy de alta"
        return preg_match('/\b(como\s+(?:doy\s+de\s+alta|se\s+da\s+de\s+alta|agrego|registro|creo|incorporo|sumo|cargo)|como\s+(?:hago|se\s+hace)\s+(?:para|un)\s+(?:crear|registrar|dar\s+de\s+alta|agregar|incorporar))\b/u', $normalized) === 1
            || preg_match('/\b(quiero|necesito)\s+ayuda\s+(?:para|con|en)\s+(?:crear|registrar|dar\s+de\s+alta|agregar|incorporar|sumar|cargar)\b/u', $normalized) === 1
            || preg_match('/\b(que\s+debo\s+hacer|que\s+hago|como\s+puedo)\s+(?:para\s+)?(?:crear|registrar|dar\s+de\s+alta|agregar|incorporar)\b/u', $normalized) === 1
            || preg_match('/\bexplica(?:me)?\s+como\s+(?:crear|registrar|dar\s+de\s+alta|agregar|incorporar|cargo?|sumo)\b/u', $normalized) === 1;
    }

    /**
     * Fix Fase 5: plan canonico para ayuda sobre creacion de usuarios. Separa
     * explicitamente la AYUDA de la accion (users.actions.create_user), evitando
     * que prompts informativos se interpreten como propuestas ejecutables.
     *
     * @return array<string, mixed>
     */
    public function helpCreateUserPlan(string $normalized): array
    {
        $this->logFallback($normalized, 'users.help.create_user', 'help', null);

        return [
            'request_normalization' => $normalized,
            'intent_family' => 'help',
            'capability_key' => 'users.help.create_user',
            'filters' => $this->emptyFilters(),
            'resolved_entity' => null,
            'missing_slots' => ['name', 'email'],
            'clarification_state' => null,
            'proposal_vs_execute' => 'none',
        ];
    }

    /**
     * Fase 1d: plan canonico para prompts fuera del catalogo. Emite
     * `users.help.unknown` cuando el flag `help_unknown_split` esta activo.
     *
     * @return array<string, mixed>
     */
    public function helpUnknownPlan(string $normalized): array
    {
        $capabilityKey = $this->isHelpUnknownSplitEnabled()
            ? 'users.help.unknown'
            : 'users.help';

        $this->logFallback($normalized, $capabilityKey, 'help', null);

        return [
            'request_normalization' => $normalized,
            'intent_family' => 'help',
            'capability_key' => $capabilityKey,
            'filters' => $this->emptyFilters(),
            'resolved_entity' => null,
            'missing_slots' => [],
            'clarification_state' => null,
            'proposal_vs_execute' => 'none',
        ];
    }

    protected function isHelpUnknownSplitEnabled(): bool
    {
        return (bool) config('ai-copilot.contracts.help_unknown_split', false);
    }

    /**
     * Registra un log de fallback para observabilidad.
     */
    protected function logFallback(string $normalized, string $capabilityKey, string $intentFamily, ?string $clarificationReason): void
    {
        // Sanitizar: reemplazar emails por [email]
        $sanitized = preg_replace('/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/', '[email]', $normalized) ?? $normalized;

        $fallbackCategory = $this->categorizeFallback($capabilityKey, $intentFamily, $clarificationReason, $normalized);

        Log::info('copilot.planner.fallback', [
            'capability_key' => $capabilityKey,
            'intent_family' => $intentFamily,
            'prompt_normalized' => $sanitized,
            'fallback_category' => $fallbackCategory,
            'clarification_reason' => $clarificationReason,
            'correlation_id' => Context::get('correlation_id'),
        ]);
    }

    /**
     * Categoriza el fallback según la razón del no-match.
     *
     * Categorías:
     * - denied: solicitud denegada explícitamente (sensible, impersonación, operación no soportada, bulk)
     * - no_match: ningún resolver reconoció el prompt
     * - ambiguous_target: se identificó intención pero no el usuario objetivo
     * - missing_entity: se requiere usuario específico pero no se proporcionó
     * - missing_context: se perdió contexto conversacional
     * - insufficient_criteria: búsqueda sin criterio efectivo
     * - informational: prompt informativo/educativo (resultado correcto, no es fallo)
     */
    protected function categorizeFallback(string $capabilityKey, string $intentFamily, ?string $clarificationReason, string $normalized): string
    {
        // denied: solicitudes denegadas explícitamente por el denial gate
        if (is_string($clarificationReason) && str_starts_with($clarificationReason, 'denied:')) {
            return 'denied';
        }

        // insufficient_criteria: búsqueda sin filtros efectivos
        if ($clarificationReason === 'insufficient_search_criteria') {
            return 'insufficient_criteria';
        }

        // informational: help plan que es resultado correcto
        if ($capabilityKey === 'users.help' && $this->isInformationalPrompt($normalized)) {
            return 'informational';
        }

        // Categorías basadas en clarification reason
        if ($clarificationReason !== null) {
            return match ($clarificationReason) {
                'ambiguous_target' => 'ambiguous_target',
                'missing_target' => 'missing_entity',
                'missing_context' => 'missing_context',
                default => 'no_match',
            };
        }

        // Default para help sin categoría específica
        return 'no_match';
    }

    /**
     * Determina si un prompt es informativo/educativo (no requiere acción).
     */
    protected function isInformationalPrompt(string $normalized): bool
    {
        $informationalPatterns = [
            '/\b(como|que|cuales|ayuda|info|informacion|explica|explicame)\b/u',
            '/\b(funciona|sirve|uso|usar|hago|puedo|puedes)\b/u',
            '/\b(capacidad|capacidades|habilidad|ayuda)\b/u',
        ];

        foreach ($informationalPatterns as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function planFromCapability(string $normalized, string $capabilityKey): array
    {
        $definition = UsersCopilotCapabilityCatalog::find($capabilityKey);

        return [
            'request_normalization' => $normalized,
            'intent_family' => $definition['intent_family'] ?? 'help',
            'capability_key' => $capabilityKey,
            'filters' => $this->emptyFilters(),
            'resolved_entity' => null,
            'missing_slots' => [],
            'clarification_state' => null,
            'proposal_vs_execute' => in_array($definition['family'] ?? null, ['aggregate', 'list', 'detail'], true)
                ? 'execute'
                : 'none',
        ];
    }

    /**
     * @return array{query: ?string, status: 'active'|'inactive'|'all'|null, role: ?string, access_profile: ?string, permission: ?string, email_verified: ?bool, two_factor_enabled: ?bool, has_roles: ?bool}
     */
    protected function emptyFilters(): array
    {
        return [
            'query' => null,
            'status' => null,
            'role' => null,
            'access_profile' => null,
            'permission' => null,
            'email_verified' => null,
            'two_factor_enabled' => null,
            'has_roles' => null,
        ];
    }

    protected function hasSnapshotContext(CopilotConversationSnapshot $snapshot): bool
    {
        return $snapshot->hasContext();
    }

    /**
     * Fase 1c: aplica la semantica de freshness tri-valuada sobre el snapshot
     * antes de planear. En `expired` se descarta el snapshot (se trata como
     * fresh sin contexto); en `stale` se preserva para que el planner decida
     * si pedir confirmacion; en `fresh` se usa directamente.
     */
    protected function applyFreshnessGate(CopilotConversationSnapshot $snapshot): CopilotConversationSnapshot
    {
        if (! (bool) config('ai-copilot.contracts.staleness_confirmation', false)) {
            return $snapshot;
        }

        $freshness = $snapshot->freshness();

        // Fase 2c: telemetria del gate.
        CopilotPlannerTelemetry::stalenessDecision(
            freshness: $freshness,
            hasContext: $snapshot->hasContext(),
            minutesElapsed: $snapshot->minutesSinceLastTurn(),
            requiresConfirmation: $freshness === CopilotConversationSnapshot::FRESHNESS_STALE,
        );

        if ($freshness === CopilotConversationSnapshot::FRESHNESS_EXPIRED) {
            return CopilotConversationSnapshot::empty();
        }

        return $snapshot;
    }

    /**
     * Fase 1c: decide si el prompt actual depende de contexto previo y el
     * snapshot esta stale. En ese caso se emite un plan `continuation.confirm`
     * para pedir confirmacion antes de reusar el contexto.
     */
    public function shouldRequireContinuationConfirmation(string $normalized, CopilotConversationSnapshot $snapshot): bool
    {
        if (! (bool) config('ai-copilot.contracts.staleness_confirmation', false)) {
            return false;
        }

        if (! $snapshot->hasContext()) {
            return false;
        }

        if ($snapshot->freshness() !== CopilotConversationSnapshot::FRESHNESS_STALE) {
            return false;
        }

        return $this->looksLikeConversationContinuation($normalized)
            || $this->looksLikeCountFollowUp($normalized)
            || $this->looksLikeSubsetRefinement($normalized)
            || $this->looksLikeEntityReference($normalized);
    }

    /**
     * Fase 1c: plan canonico que produce una respuesta `continuation_confirm`.
     *
     * @return array<string, mixed>
     */
    public function continuationConfirmPlan(string $normalized, CopilotConversationSnapshot $snapshot): array
    {
        $entity = $snapshot->resolvedEntity();
        $entityLabel = null;

        if ($entity !== null) {
            $user = User::query()->find($entity['id']);
            $entityLabel = $user?->name;
        }

        $minutesElapsed = $snapshot->minutesSinceLastTurn();
        $question = $entityLabel !== null
            ? sprintf('Tu ultima interaccion sobre %s fue hace unos minutos. Deseas continuar con ese contexto o empezar de nuevo?', $entityLabel)
            : 'Tu ultima interaccion tiene tiempo. Deseas continuar con el contexto previo o empezar de nuevo?';

        return [
            'request_normalization' => $normalized,
            'intent_family' => 'continuation_confirm',
            'capability_key' => 'users.continuation.confirm',
            'filters' => $this->emptyFilters(),
            'resolved_entity' => $entity,
            'missing_slots' => [],
            'clarification_state' => [
                'reason' => 'snapshot_stale',
                'freshness' => $snapshot->freshness(),
                'question' => $question,
                'entity_label' => $entityLabel,
                'minutes_elapsed' => $minutesElapsed,
                'options' => [
                    ['label' => 'Continuar con el contexto previo', 'value' => 'confirm_continuation'],
                    ['label' => 'Empezar de nuevo', 'value' => 'start_fresh'],
                ],
            ],
            'proposal_vs_execute' => 'none',
        ];
    }

    /**
     * Denial gate: detects and blocks requests for sensitive data, impersonation, or unsupported operations.
     * Evaluated with explicit precedence: impersonation > sensitive_data > unsupported_operation.
     *
     * @return array<string, mixed>|null
     */
    public function matchSensitiveDenial(string $normalized): ?array
    {
        // Exclude informational/educational contexts — "como funciona el 2fa", "como cambiar el email"
        if (preg_match('/\b(como\s+funciona|que\s+es|que\s+significa|como\s+se\s+configura|como\s+se\s+activa|como\s+habilitar|como\s+cambiar)\b/u', $normalized) === 1) {
            return null;
        }

        if (($this->isCreateUserProposal($normalized) || preg_match('/\bcrea(?:r)?\s+(?:uno|un|una)\b/u', $normalized) === 1)
            && preg_match('/\b(elimina|eliminar|borra|borrar|exporta|exportar|csv|excel|pdf|todos\s+los\s+usuarios|usuarios\s+inactivos)\b/u', $normalized) === 1) {
            return [
                'request_normalization' => $normalized,
                'intent_family' => 'partial',
                'capability_key' => 'users.mixed.create_unsupported',
                'filters' => $this->emptyFilters(),
                'resolved_entity' => null,
                'missing_slots' => [],
                'clarification_state' => null,
                'proposal_vs_execute' => 'none',
                'mixed_segments' => [[
                    'text' => 'Rama de accion no soportada',
                    'status' => 'not_executed',
                    'reason' => 'la solicitud incluye exportacion o accion destructiva no soportada',
                    'suggested_follow_up' => 'Separa la creacion guiada en un pedido con nombre, email y rol',
                ]],
            ];
        }

        if (preg_match('/\balgo\s+de\s+admin\b/u', $normalized) === 1
            && preg_match('/\bno\s+total\b/u', $normalized) === 1) {
            return $this->clarificationPlan(
                normalized: $normalized,
                reason: 'ambiguous_role',
                question: 'Necesito que indiques un rol concreto y permitido. No puedo inferir “algo de admin pero no total”.',
                missingSlots: ['roles'],
            );
        }

        // 1. Impersonation (highest precedence)
        // Fix Fase 5: ampliamos para cubrir "necesito entrar como", "quiero
        // entrar a la cuenta de", "ingresar como X para", etc.
        if (preg_match('/\b(entra|entrar|logueate|loguear|iniciar?\s+sesion|accede|acceder|ingresa|ingresar)\s+(como|de|por|a)\b/u', $normalized) === 1
            || preg_match('/\b(entrar|acceder|ingresar)\s+a\s+(la\s+)?cuenta\s+de\b/u', $normalized) === 1
            || preg_match('/\bnecesito\s+entrar\s+como\b/u', $normalized) === 1) {
            return $this->clarificationPlan(
                normalized: $normalized,
                reason: 'denied:impersonation',
                question: 'No puedo iniciar sesion como otro usuario ni acceder a cuentas ajenas.',
            );
        }

        if (preg_match('/\b(sin\s+(validar|verificar|revisar)\s+(permisos?|politicas?|validaciones?)|salt(?:a|ar|ate)\s+(los\s+)?(permisos?|validaciones?|politicas?)|ignora\s+(los\s+)?(permisos?|validaciones?|politicas?))\b/u', $normalized) === 1) {
            return $this->clarificationPlan(
                normalized: $normalized,
                reason: 'denied:bypass_policy',
                question: 'No puedo saltarme validaciones, permisos ni politicas de seguridad.',
            );
        }

        if (preg_match('/\b(acceso\s+total|todos\s+los\s+permisos|super\s*admin|superadmin|administrador\s+total|privilegios?\s+(maximos|totales)|control\s+total)\b/u', $normalized) === 1
            && preg_match('/\b(haz|hacer|dale|darle|pon|ponle|asigna|asignar|convierte|convertir|otorga|concede|conceder)\b/u', $normalized) === 1) {
            return $this->clarificationPlan(
                normalized: $normalized,
                reason: 'denied:privilege_escalation',
                question: 'No puedo conceder acceso total ni elevar privilegios desde el copiloto sin una politica y permisos validos.',
            );
        }

        if (preg_match('/\b(borra|borrar|elimina|eliminar|destruye|destruir)\b/u', $normalized) === 1
            && preg_match('/\b(todos|todas|administradores|admins?|con\s+rol|usuarios\s+administradores)\b/u', $normalized) === 1) {
            return $this->clarificationPlan(
                normalized: $normalized,
                reason: 'denied:unsupported_bulk',
                question: 'No puedo ejecutar borrados ni acciones destructivas masivas desde el copiloto.',
            );
        }

        // 2. Sensitive data — requires exposure indicator + sensitive term co-occurrence
        // Fix Fase 5: incorporamos paraphrases coloquiales:
        // - "hash de contrasena", "hash del password"
        // - "secreto del autenticador", "secreto de google authenticator"
        // - "codigo de doble factor", "codigo 2fa", "token del authenticator"
        $sensitiveTerms = '/\b(contrasena|password|clave\s+de\s+acceso|token|2fa|otp|secret|secreto|recovery\s+code|codigo\s+de\s+recuperacion|codigo\s+(de\s+)?(doble\s+factor|2fa|segundo\s+factor)|doble\s+factor|api\s+key|bearer|credencial|credenciales|autenticador|authenticator|hash\s+(de\s+)?(contrasena|password|clave)|emails?\s+privados?|correos?\s+privados?)\b/u';
        $exposureIndicators = '/\b(dame|dime|muestra|muestrame|mostrar|mostrame|revela|revelar|cual\s+es|obtener|ver\s+la|ver\s+el|enviame|pasame|compartime|decime|necesito|quiero|borrale\s+la|quitale\s+la|cambia\s+la|con\s+que)\b/u';

        if (preg_match($sensitiveTerms, $normalized) === 1 && preg_match($exposureIndicators, $normalized) === 1) {
            return $this->clarificationPlan(
                normalized: $normalized,
                reason: 'denied:sensitive_data',
                question: 'No puedo mostrar contrasenas, tokens ni datos de autenticacion. Si necesitas restablecer el acceso de un usuario, puedo proponer un restablecimiento de contrasena.',
            );
        }

        // 3. Unsupported operations
        // Fix Fase 5: cubrimos variantes coloquiales de delete aplicadas a
        // email directamente ("borrame a mario@...", "eliminar de una vez",
        // "sacalo de la base") y mantenemos el export existente.
        if (preg_match('/\b(eliminar?|borrar?|destruir?|sacar?)\s+(definitivamente?\s+)?(al?\s+)?(usuario|cuenta|perfil)\b/u', $normalized) === 1
            || preg_match('/\b(borra(?:me|lo|la|les)?|elimina(?:me|lo|la|les)?|quitame|sacame)\s+a?\s*[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/u', $normalized) === 1
            || preg_match('/\b(borra(?:me|lo|la)?|elimina(?:me|lo|la)?)\s+a\s+\w+\s+de\s+(una\s+vez|todo|una|la\s+base)/u', $normalized) === 1
            || preg_match('/\bexportar?\s+(a\s+)?(csv|excel|pdf|archivo|todos)\b/u', $normalized) === 1
            || preg_match('/\bexporta(?:me|lo|la)?\s+(la\s+)?lista\s+(completa\s+)?(de\s+usuarios|con\s+sus\s+correos|de\s+todos)\b/u', $normalized) === 1) {
            return $this->clarificationPlan(
                normalized: $normalized,
                reason: 'denied:unsupported_operation',
                question: 'Esa operacion no esta disponible desde el copiloto. Puedo ayudarte a consultar, buscar usuarios o proponer acciones como activar, desactivar o restablecer contrasena.',
            );
        }

        return null;
    }

    /**
     * Resolves conversation continuation prompts using snapshot context instead of falling to helpPlan.
     *
     * @return array<string, mixed>
     */
    public function resolveContinuation(string $normalized, CopilotConversationSnapshot $snapshot): array
    {
        if (! $snapshot->hasContext()) {
            return $this->clarificationPlan(
                normalized: $normalized,
                reason: 'missing_context',
                question: 'No tengo un resultado previo sobre el cual continuar. Indica que necesitas.',
            );
        }

        $followUp = $this->resolveFollowUp($normalized, $snapshot);

        if ($followUp !== null) {
            return $followUp;
        }

        $entity = $snapshot->resolvedEntity();

        if ($entity !== null && ($entity['type'] ?? null) === 'user') {
            $user = User::query()->find($entity['id']);

            if ($user instanceof User) {
                return $this->detailPlan($normalized, $user);
            }
        }

        $singleUserId = $snapshot->singleResultUserId();

        if ($singleUserId !== null) {
            $user = User::query()->find($singleUserId);

            if ($user instanceof User) {
                return $this->detailPlan($normalized, $user);
            }
        }

        return $this->clarificationPlan(
            normalized: $normalized,
            reason: 'missing_context',
            question: 'No logre determinar como continuar. Puedes ser mas especifico?',
        );
    }

    public function looksLikeBulkAction(string $normalized): bool
    {
        return preg_match('/\b(a\s+todos|todos\s+los|masivamente|en\s+lote|de\s+una\s+vez|a\s+cada\s+uno)\b/u', $normalized) === 1;
    }

    protected function hasEffectiveSearchCriteria(array $filters): bool
    {
        return $filters['query'] !== null
            || $filters['status'] !== null
            || $filters['role'] !== null
            || $filters['access_profile'] !== null
            || $filters['permission'] !== null
            || $filters['email_verified'] !== null
            || $filters['two_factor_enabled'] !== null
            || $filters['has_roles'] !== null;
    }

    /**
     * @param  array{query: ?string, status: 'active'|'inactive'|'all'|null, role: ?string, access_profile: ?string, permission: ?string, email_verified: ?bool, two_factor_enabled: ?bool, has_roles: ?bool}  $filters
     */
    protected function hasStructuredSearchCriteria(array $filters): bool
    {
        return $filters['status'] !== null
            || $filters['role'] !== null
            || $filters['access_profile'] !== null
            || $filters['permission'] !== null
            || $filters['email_verified'] !== null
            || $filters['two_factor_enabled'] !== null
            || $filters['has_roles'] !== null;
    }

    protected function isTypedSearchQuery(?string $query): bool
    {
        if (! is_string($query)) {
            return false;
        }

        $normalized = $this->normalizePrompt($query);

        if ($normalized === '') {
            return false;
        }

        if ($this->extractEmailFromText($normalized) !== null) {
            return true;
        }

        $weakStandaloneTerms = [
            'alguno',
            'alguna',
            'alguien',
            'otro',
            'otra',
            'otros',
            'otras',
            'mismo',
            'misma',
            'mismos',
            'mismas',
            'usuario',
            'usuarios',
            'persona',
            'personas',
            'gente',
            'soporte',
            'finanzas',
        ];

        if (in_array($normalized, $weakStandaloneTerms, true)) {
            return false;
        }

        return $this->searchTokens($normalized) !== [];
    }

    public function looksLikeActionProposal(string $normalized): bool
    {
        if (preg_match('/\b(propon|prepara|desactiva|desactivar|desactivalo|activa|activar|reactiva|reactivar|reactivalo|reset|restablece|restablecer|restablecele|crea|crear|alta|armalo|hacelo)\b/u', $normalized) === 1) {
            return true;
        }

        if (str_contains($normalized, 'restablecimiento') && preg_match('/\b(necesito|quiero|puedo|ayudame|enviar|envia)\b/u', $normalized) === 1) {
            return true;
        }

        // Fix Fase 5: verbos naturales de alta. Delegamos a isCreateUserProposal
        // que ya aplica los guards (email o marca de novedad) para evitar que
        // "agregar un filtro" o "sumar criterios" se confundan con create.
        return $this->isCreateUserProposal($normalized);
    }

    public function looksLikeConversationContinuation(string $normalized): bool
    {
        return preg_match('/\b(continua|continua con|sigue|seguir|amplia|amplia esto|mas detalle|mas detalles)\b/u', $normalized) === 1;
    }

    public function looksLikeInformationalPrompt(string $normalized): bool
    {
        return preg_match('/\b(como\s+(?:revisar|funciona|puedo|hago|se\s+hace)|explica\s+como|que\s+puedo\s+hacer|que\s+significa)\b/u', $normalized) === 1
            && preg_match('/\b(?:de\s+un\s+usuario|de\s+los\s+usuarios|del\s+usuario|un\s+usuario)\b/u', $normalized) === 1;
    }

    protected function looksLikeCountFollowUp(string $normalized): bool
    {
        return preg_match('/\b(y\s+)?cuantos\s+son\b/u', $normalized) === 1
            || str_contains($normalized, 'de esos cuantos siguen activos')
            || str_contains($normalized, 'cuantos son');
    }

    protected function looksLikeSubsetRefinement(string $normalized): bool
    {
        return str_contains($normalized, 'solo los ')
            || str_contains($normalized, 'ahora solo los ')
            || str_contains($normalized, 'muestrame los ')
            || str_contains($normalized, 'solo las ')
            || preg_match('/\bde\s+es[oa]s\b/u', $normalized) === 1;
    }

    protected function looksLikeEntityReference(string $normalized): bool
    {
        return $this->looksLikeFirstOrdinalReference($normalized)
            || preg_match('/\b(ese usuario|esa usuaria|el de arriba|la de arriba|desactivalo|reactivalo|restablecele|propon desactivarlo|propon activarlo)\b/u', $normalized) === 1;
    }

    protected function firstOrdinalReferenceUserId(string $normalized, CopilotConversationSnapshot $snapshot): ?int
    {
        if (! $this->looksLikeFirstOrdinalReference($normalized)) {
            return null;
        }

        return $snapshot->lastResultUserIds()[0] ?? null;
    }

    protected function looksLikeFirstOrdinalReference(string $normalized): bool
    {
        $padded = " {$normalized} ";

        foreach ([' el primero ', ' la primera ', ' primero ', ' primera ', ' first '] as $phrase) {
            if (str_contains($padded, $phrase)) {
                return true;
            }
        }

        return false;
    }

    protected function looksLikeUserTargetingPrompt(string $normalized): bool
    {
        if ($this->looksLikeActionProposal($normalized)) {
            return true;
        }

        if ($this->mentionsUserAbstractly($normalized)) {
            return false;
        }

        return preg_match('/\b(revisa|resume|explica|detalle|estado|acceso|usuario|permisos|roles|puede|quien)\b/u', $normalized) === 1;
    }

    public function looksLikeExplicitDetailPrompt(string $normalized): bool
    {
        if ($this->extractEmailFromText($normalized) !== null
            && preg_match('/\b(que\s+permisos\s+tiene|que\s+roles?\s+tiene|que\s+puede\s+hacer|que\s+rol(?:es)?\s+tiene)\b/u', $normalized) === 1) {
            return true;
        }

        if (preg_match('/\b(que\s+permisos\s+tiene|que\s+rol(?:es)?\s+tiene|que\s+rol\s+tiene|roles\s+y\s+permisos|permisos\s+y\s+rol|acceso\s+efectivo|estado\s+actual|estado\s+operativo)\b/u', $normalized) === 1) {
            return true;
        }

        if ($this->extractEmailFromText($normalized) !== null
            && preg_match('/\b(usuario|usuaria|revisa|resume|explica|detalle|estado|acceso|permisos|rol|roles|quien\s+es)\b/u', $normalized) === 1) {
            return true;
        }

        return preg_match('/\b(revisa|resume|explica|detalle|estado|acceso|permisos|roles|puede|por\s+que)\b/u', $normalized) === 1
            && (preg_match('/\busuario\b/u', $normalized) === 1 || $this->extractEmailFromText($normalized) !== null);
    }

    public function looksLikeExplicitCollectionSearch(string $normalized): bool
    {
        return preg_match('/\b(busca|buscar|lista|listar|listame|muestra|mostrar|encuentra|encontrar|dame)\b.*\busuarios?\b/u', $normalized) === 1
            || preg_match('/\b(que\s+usuarios|quienes\s+son\s+los\s+usuarios|quienes\s+son)\b/u', $normalized) === 1
            || preg_match('/\b(quienes?|que\s+usuarios)\b.*\b(rol|perfil|permisos|acceso)\b/u', $normalized) === 1
            // Preguntas existenciales: "hay algun juan", "existe un usuario", "tienen algun"
            || preg_match('/\b(hay|existe|tienen?)\b.*\b(algun|alguno|un|una)\b.*\busuarios?\b/u', $normalized) === 1
            || preg_match('/\b(hay|existe|tienen?)\b.*\b(algun|alguno|un|una)\b.*\b\w{3,}\b/u', $normalized) === 1;
    }

    protected function mentionsUserAbstractly(string $normalized): bool
    {
        if ($this->extractEmailFromText($normalized) !== null) {
            return false;
        }

        if (preg_match('/\b(?:un\s+usuario|de\s+un\s+usuario|los\s+usuarios|de\s+los\s+usuarios|del\s+sistema)\b/u', $normalized) !== 1) {
            return false;
        }

        return preg_match('/\b(?:al\s+usuario\s+\w{2,}|usuario\s+[a-z]{2,}\s+[a-z])\b/u', $normalized) !== 1;
    }

    protected function extractEntitySearchQuery(string $normalized): ?string
    {
        $email = $this->extractEmailFromText($normalized);

        if ($email !== null) {
            return $email;
        }

        $patterns = [
            '/\b(?:explica|explicame|revisa|resume)\s+(?:el|la)?\s*(?:acceso\s+efectivo|estado\s+actual|estado|operativo|detalle(?:s)?|resumen)\s+de\s+(.+)$/u',
            '/\b(?:usuario|usuaria|a)\s+(.+)$/u',
            '/\b(?:revisa|resume|explica|detalle|estado|acceso)\s+(?:de\s+)?(.+)$/u',
            '/\b(?:desactiva|activa|reactiva|restablece|propon\s+desactivar\s+a|propon\s+activar\s+a|enviar\s+un\s+restablecimiento\s+a|envia\s+un\s+restablecimiento\s+a)\s+(.+)$/u',
            '/\b(?:quien\s+es)\s+(.+)$/u',
            '/\b(?:que\s+permisos\s+tiene|que\s+roles?\s+tiene|que\s+puede\s+hacer|que\s+rol(?:es)?\s+tiene)\s+(.+)$/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized, $matches) === 1) {
                return $this->sanitizeEntityCandidate($matches[1]);
            }
        }

        return null;
    }

    protected function sanitizeEntityCandidate(string $candidate): ?string
    {
        $candidate = trim($candidate, " .,!?:;\"'");
        $candidate = preg_replace('/\s+/u', ' ', $candidate) ?? $candidate;
        $candidate = preg_replace('/\b(?:por\s+favor|si\s+puedes|si\s+es\s+posible|con\s+correo|correo|email)\b.*$/u', '', $candidate) ?? $candidate;
        $candidate = trim($candidate, " .,!?:;\"'");

        return $candidate !== '' ? $candidate : null;
    }

    /**
     * Find emails similar to the given email using fuzzy matching
     *
     * @param  string  $email  The email to find similar matches for
     * @return array Array of similar emails with their distance score
     */
    protected function findSimilarEmails(string $email): array
    {
        $similar = [];
        $maxDistance = 2; // Maximum Levenshtein distance for similarity
        $limit = 10; // Maximum suggestions to return

        // Get users with emails - LIMIT to prevent memory issues on large tables
        // TODO: Implement pg_trgm for scalable similarity search (PRD-12)
        $users = User::whereNotNull('email')->limit(1000)->get();

        foreach ($users as $user) {
            $distance = levenshtein(strtolower($email), strtolower($user->email));

            // Only consider emails that are similar but not identical
            if ($distance > 0 && $distance <= $maxDistance) {
                $similar[] = [
                    'email' => $user->email,
                    'distance' => $distance,
                    'user_id' => $user->id,
                    'name' => $user->name,
                ];
            }
        }

        // Sort by distance (closest first)
        usort($similar, function ($a, $b) {
            return $a['distance'] - $b['distance'];
        });

        // Return only top matches to prevent UI overload
        return array_slice($similar, 0, $limit);
    }

    /**
     * Find names similar to the given name using fuzzy matching with accent and case insensitivity
     *
     * @param  string  $name  The name to find similar matches for
     * @return array Array of similar names with their distance score
     */
    protected function findSimilarNames(string $name): array
    {
        $similar = [];
        $maxDistance = 3; // Maximum Levenshtein distance for name similarity
        $limit = 10; // Maximum suggestions to return

        // Normalize the input name (remove accents, lowercase)
        $normalizedName = strtolower(trim($name));
        $unaccentedName = $this->removeAccents($normalizedName);

        // Get users with names - LIMIT to prevent memory issues on large tables
        // TODO: Implement pg_trgm for scalable similarity search (PRD-12)
        $users = User::whereNotNull('name')->limit(1000)->get();

        foreach ($users as $user) {
            if (empty($user->name)) {
                continue;
            }

            // Normalize the user's name
            $normalizedUserName = strtolower(trim($user->name));
            $unaccentedUserName = $this->removeAccents($normalizedUserName);

            // Split names into words for partial matching
            $inputWords = explode(' ', $unaccentedName);
            $userWords = explode(' ', $unaccentedUserName);

            $foundSimilar = false;
            $bestDistance = PHP_INT_MAX;
            $bestSimilarity = 0;

            // Check each input word against each user word
            foreach ($inputWords as $inputWord) {
                if (strlen($inputWord) < 2) {
                    continue;
                } // Skip very short words

                foreach ($userWords as $userWord) {
                    if (strlen($userWord) < 2) {
                        continue;
                    } // Skip very short words

                    // Calculate distance between words
                    $distance = levenshtein($inputWord, $userWord);

                    // Check if words are similar but not identical
                    if ($distance > 0 && $distance <= $maxDistance) {
                        $similarity = similar_text($inputWord, $userWord, $percent);

                        if ($percent >= 60) { // Higher threshold for word matching
                            $foundSimilar = true;
                            $bestDistance = min($bestDistance, $distance);
                            $bestSimilarity = max($bestSimilarity, $percent);
                        }
                    }

                    // Also check if input word is contained in user word or vice versa
                    // Require minimum length ratio to avoid false positives (e.g., "ana" in "jana")
                    if (strpos($userWord, $inputWord) !== false || strpos($inputWord, $userWord) !== false) {
                        $minLength = min(strlen($inputWord), strlen($userWord));
                        $maxLength = max(strlen($inputWord), strlen($userWord));
                        $lengthRatio = $minLength / $maxLength;

                        // Only match if: meaningful length AND covers at least 70% of the longer word
                        if ($minLength >= 3 && $lengthRatio >= 0.7) {
                            $foundSimilar = true;
                            $bestDistance = 0;
                            $bestSimilarity = 80;
                        }
                    }
                }
            }

            // If we found similarity, add to results
            if ($foundSimilar) {
                $similar[] = [
                    'name' => $user->name,
                    'distance' => $bestDistance,
                    'similarity' => $bestSimilarity,
                    'user_id' => $user->id,
                    'email' => $user->email,
                ];
            }
        }

        // Sort by distance (closest first) and then by similarity (highest first)
        usort($similar, function ($a, $b) {
            if ($a['distance'] === $b['distance']) {
                return $b['similarity'] <=> $a['similarity'];
            }

            return $a['distance'] - $b['distance'];
        });

        // Return only top matches to prevent UI overload
        return array_slice($similar, 0, $limit);
    }

    /**
     * Remove accents from a string
     */
    protected function removeAccents(string $string): string
    {
        // Using trans-literation to remove accents
        $string = transliterator_transliterate('Any-Latin; Latin-ASCII;', $string);

        return $string;
    }

    /**
     * @return Collection<int, User>
     */
    protected function candidateUsersFor(string $query): Collection
    {
        $normalized = $this->normalizePrompt($query);
        $email = $this->extractEmailFromText($normalized);

        if ($email !== null) {
            return User::query()
                ->whereRaw('lower(email) = ?', [$email])
                ->orderBy('name')
                ->limit(5)
                ->get();
        }

        $baseQuery = User::query()->select(['id', 'name', 'email']);

        // Intentar con unaccent primero (PostgreSQL), fallback a ILIKE simple
        try {
            // Buscar coincidencias exactas primero (case-insensitive con unaccent)
            $exactMatches = (clone $baseQuery)
                ->whereRaw('lower(unaccent(name)) = lower(unaccent(?))', [$query])
                ->orderBy('name')
                ->limit(5)
                ->get();

            if ($exactMatches->isNotEmpty()) {
                return $exactMatches;
            }

            // Si no hay exactas, buscar con ILIKE
            $tokens = $this->searchTokens($normalized);

            if (count($tokens) >= 2) {
                foreach ($tokens as $token) {
                    $baseQuery->whereRaw('lower(unaccent(name)) LIKE lower(unaccent(?))', ['%'.$token.'%']);
                }
            } else {
                $baseQuery->whereRaw('lower(unaccent(name)) LIKE lower(unaccent(?))', ['%'.$query.'%']);
            }

            return $baseQuery
                ->orderByRaw('CASE WHEN lower(unaccent(name)) LIKE lower(unaccent(?)) THEN 0 ELSE 1 END', [$query.'%'])
                ->orderByRaw('abs(length(name) - length(?))', [$query])
                ->orderBy('name')
                ->limit(5)
                ->get();
        } catch (\Throwable $e) {
            // Fallback: usar ILIKE simple sin unaccent (para SQLite o cuando unaccent no está disponible)
            Log::notice('copilot.entity_resolution.unaccent_fallback', [
                'error' => $e->getMessage(),
                'query' => $query,
                'correlation_id' => Context::get('correlation_id'),
            ]);

            $baseQuery = User::query()->select(['id', 'name', 'email']);

            // Buscar coincidencias exactas case-insensitive
            $exactMatches = (clone $baseQuery)
                ->whereRaw('lower(name) = lower(?)', [$query])
                ->orderBy('name')
                ->limit(5)
                ->get();

            if ($exactMatches->isNotEmpty()) {
                return $exactMatches;
            }

            // Buscar con LIKE simple
            $tokens = $this->searchTokens($normalized);

            if (count($tokens) >= 2) {
                foreach ($tokens as $token) {
                    $baseQuery->whereRaw('lower(name) LIKE lower(?)', ['%'.$token.'%']);
                }
            } else {
                $baseQuery->whereRaw('lower(name) LIKE lower(?)', ['%'.$query.'%']);
            }

            return $baseQuery
                ->orderByRaw('CASE WHEN lower(name) LIKE lower(?) THEN 0 ELSE 1 END', [$query.'%'])
                ->orderByRaw('abs(length(name) - length(?))', [$query])
                ->orderBy('name')
                ->limit(5)
                ->get();
        }
    }

    protected function shouldConfirmLowConfidenceDetailMatch(string $query, User $match): bool
    {
        if ($this->extractEmailFromText($query) !== null) {
            return false;
        }

        $queryTokens = $this->searchTokens($this->removeAccents($this->normalizePrompt($query)));
        $nameTokens = $this->searchTokens($this->removeAccents($this->normalizePrompt($match->name)));

        if ($queryTokens === [] || $nameTokens === []) {
            return false;
        }

        $usedPrefixFallback = false;

        foreach ($queryTokens as $queryToken) {
            $matchedExactly = in_array($queryToken, $nameTokens, true);

            if ($matchedExactly) {
                continue;
            }

            $matchedByPrefix = collect($nameTokens)->contains(
                static fn (string $nameToken): bool => str_starts_with($nameToken, $queryToken)
            );

            if (! $matchedByPrefix) {
                return true;
            }

            $usedPrefixFallback = true;
        }

        return $usedPrefixFallback;
    }

    /**
     * @param  Collection<int, User>  $matches
     * @return array<string, mixed>
     */
    protected function ambiguousUserClarificationPlan(string $normalized, Collection $matches, string $capabilityKey): array
    {
        $forAction = $capabilityKey !== 'users.detail';
        $question = $forAction
            ? 'Encontre varios usuarios posibles. Indica cual quieres usar para la propuesta.'
            : 'Encontre varios usuarios posibles. Indica cual quieres revisar.';

        return [
            'request_normalization' => $normalized,
            'intent_family' => 'ambiguous',
            'capability_key' => 'users.clarification',
            'filters' => $this->emptyFilters(),
            'resolved_entity' => null,
            'missing_slots' => ['user_id'],
            'clarification_state' => [
                'reason' => 'ambiguous_target',
                'question' => $question,
                'options' => $matches->map(fn (User $user): array => [
                    'label' => sprintf('%s <%s>', $user->name, $user->email),
                    'value' => 'user:'.$user->id,
                    'capability_key' => $capabilityKey,
                    'intent_family' => $forAction ? 'action_proposal' : 'read_detail',
                    'resolved_entity' => [
                        'type' => 'user',
                        'id' => $user->id,
                        'label' => $user->name,
                    ],
                ])->values()->all(),
            ],
            'proposal_vs_execute' => 'none',
        ];
    }

    protected function actionCapabilityKey(string $normalized): string
    {
        return match (true) {
            str_contains($normalized, 'desactiv') => 'users.actions.deactivate',
            str_contains($normalized, 'activ') || str_contains($normalized, 'reactiv') => 'users.actions.activate',
            str_contains($normalized, 'reset'), str_contains($normalized, 'restablec') => 'users.actions.send_reset',
            $this->isCreateUserProposal($normalized) => 'users.actions.create_user',
            default => 'users.actions.deactivate',
        };
    }

    /**
     * Fix Fase 5: detecta senales FUERTES de intencion de crear usuario.
     * Usadas por CreateUserIntentStage para saltarse la resolucion de entidad
     * (que buscaria el email y fallaria, emitiendo un clarification falso).
     *
     * Criterio: verbo de create + (email o marca explicita de novedad).
     */
    public function looksLikeStrongCreateUserIntent(string $normalized): bool
    {
        if (! $this->isCreateUserProposal($normalized)) {
            return false;
        }

        $hasEmail = preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/', $normalized) === 1;
        $hasNoveltyMarker = preg_match('/\b(usuario|usuaria)\s+(nuevo|nueva)\b/u', $normalized) === 1
            || preg_match('/\bno\s+existe\s+(todavia|aun|todavia\s+no|aun\s+no)\b/u', $normalized) === 1
            || preg_match('/\bcreame\s+(el\s+|un\s+)?usuario\b/u', $normalized) === 1
            || preg_match('/\balta\b/u', $normalized) === 1;

        return $hasEmail || $hasNoveltyMarker;
    }

    public function isCreateUserProposal(string $normalized): bool
    {
        // Verbos literales ya existentes.
        if (str_contains($normalized, 'crear usuario')
            || str_contains($normalized, 'crear un usuario')
            || str_contains($normalized, 'crea un usuario')
            || str_contains($normalized, 'creame el usuario')
            || str_contains($normalized, 'creame un usuario')
            || str_contains($normalized, 'prepara la creacion')
            || str_contains($normalized, 'preparar la creacion')
            || preg_match('/\bcrea\s+a\s+[a-z]/u', $normalized) === 1
            || preg_match('/\bsi\s+no\s+hay\s+(?:una|un)?\s*[a-z].*\b(agregala|agregalo|agrega|crear|crea)\b/u', $normalized) === 1
            || str_contains($normalized, 'alta')) {
            return true;
        }

        $hasEmail = preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/', $normalized) === 1;
        $hasNovelty = preg_match('/\b(usuario|usuaria)\s+(nuevo|nueva)\b/u', $normalized) === 1
            || preg_match('/\b(este|esta)\s+(usuario|usuaria)\b/u', $normalized) === 1
            || preg_match('/\bno\s+existe\s+todavia\b/u', $normalized) === 1;

        // Fix Fase 5: verbos naturales de alta (registrar/sumar/incorporar/cargar/agregar).
        // Se exige email o novedad para reducir falsos positivos.
        if ($hasEmail || $hasNovelty) {
            if (preg_match('/\b(crea(?:r|me|lo|la)?|prepara(?:r|me|lo|la)?|registra(?:r|me|lo|la)?|sumar?|sumame|incorpora(?:r|lo|la)?|carga(?:r|lo|la)?|agrega(?:r|lo|la|me)?|registrarme)\b/u', $normalized) === 1) {
                return true;
            }
        }

        // Fix Fase 5: fórmulas sin verbo de create explícito pero con senal
        // contextual fuerte. Cubre:
        // - "hacelo vos: usuario nuevo con email X"
        // - "armalo: Diego, diego@..., rol Soporte"
        // - "con estos datos armalo / hacelo / preparalo"
        if ($hasEmail && $hasNovelty
            && (preg_match('/\b(hacelo|armalo|preparalo|dale|dalo\s+de\s+alta)\b/u', $normalized) === 1
                || preg_match('/\bcon\s+(estos\s+)?datos\b/u', $normalized) === 1)) {
            return true;
        }

        // Fix Fase 5: email + "con estos datos" + verbo imperativo = create
        // aunque no haya novelty marker explicito. El patron "ahora con estos
        // datos armalo: X, X@..., rol Y" es un handoff de datos a ejecutar.
        if ($hasEmail
            && preg_match('/\bcon\s+estos\s+datos\b/u', $normalized) === 1
            && preg_match('/\b(armalo|hacelo|preparalo|crealo|creamelo)\b/u', $normalized) === 1) {
            return true;
        }

        return false;
    }

    protected function extractEmailFromText(string $text): ?string
    {
        if (preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/', $text, $matches) !== 1) {
            return null;
        }

        return $matches[0];
    }

    /**
     * @return array{name: ?string, email: ?string, roles: list<string>}
     */
    protected function extractCreateUserPayload(string $normalized): array
    {
        return [
            'name' => $this->extractCreateUserName($normalized),
            'email' => $this->extractEmailFromText($normalized),
            'roles' => $this->extractCreateUserRoleHints($normalized),
        ];
    }

    protected function extractCreateUserName(string $normalized): ?string
    {
        $withoutEmail = trim((string) preg_replace('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/u', '', $normalized));

        if (preg_match('/\b(?:nombre|llamado|llamada|se\s+llama|para)\s+([a-z][a-z\s]{2,80}?)(?:\s*,?\s*(?:con|correo|email|rol|perfil)\b|$)/u', $withoutEmail, $matches) === 1) {
            return Str::of($matches[1])->squish()->title()->value();
        }

        if (preg_match('/\balta\s+(?:uno|una|un)?\s*nuevo:?\s+([a-z][a-z\s]{2,80}?)(?:,|\s+(?:con|correo|email|rol|perfil)\b|$)/u', $withoutEmail, $matches) === 1) {
            return Str::of($matches[1])->squish()->title()->value();
        }

        if (preg_match('/\b(?:quise\s+decir|queria\s+decir|era)\s+([a-z][a-z\s]{2,80}?)(?:\s+no\s+|$)/u', $withoutEmail, $matches) === 1) {
            return Str::of($matches[1])->squish()->title()->value();
        }

        if (preg_match('/\bcreacion\s+de\s+([a-z][a-z\s]{2,80}?)(?:\s+(?:con|correo|email|rol|perfil)\b|$)/u', $withoutEmail, $matches) === 1) {
            return Str::of($matches[1])->squish()->title()->value();
        }

        if (preg_match('/\b(?:alta|crear|crea|creame|agrega|agregar)\s+(?:a|para|usuario|usuaria|un\s+usuario|una\s+usuaria)?\s*([a-z][a-z\s]{2,80}?)(?:\s+(?:con|correo|email|rol|perfil)\b|$)/u', $withoutEmail, $matches) === 1) {
            return Str::of($matches[1])->squish()->title()->value();
        }

        return null;
    }

    /**
     * @return list<string>
     */
    protected function extractCreateUserRoleHints(string $normalized): array
    {
        $roleFilter = $this->extractRoleFilter($normalized);

        if ($roleFilter !== null) {
            return [$roleFilter];
        }

        return Role::query()
            ->active()
            ->get()
            ->filter(function (Role $role) use ($normalized): bool {
                $name = mb_strtolower($role->name);
                $displayName = $role->display_name !== null ? mb_strtolower($role->display_name) : null;

                return (preg_match('/\brol(?:es)?\b/u', $normalized) === 1 || preg_match('/\bcomo\s+(?:admin|administrador|administradora|editor|soporte)\b/u', $normalized) === 1)
                    && (str_contains($normalized, $name)
                        || ($displayName !== null && str_contains($normalized, $displayName))
                        || (preg_match('/\b(?:admin|administrador|administradora)\b/u', $normalized) === 1 && (str_contains($name, 'admin') || ($displayName !== null && str_contains($displayName, 'admin')))));
            })
            ->map(fn (Role $role): string => $role->display_name ?? $role->name)
            ->values()
            ->all();
    }

    protected function extractExplicitSearchQuery(string $normalized): ?string
    {
        $sanitized = $this->normalizePrompt($normalized);
        $sanitized = preg_replace('/^(?:el|la)?\s*usuario\s+(?:es|se\s+llama)?\s*/u', '', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/^(?:al|a\s+la|a\s+el|del|de\s+la)\s+/u', '', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/^(?:efectivo|actual|operativo)\s+de\s+/u', '', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/^(?:el|la|los|las)\s+(?:acceso\s+efectivo|estado\s+actual|estado\s+operativo|detalle(?:s)?|resumen)\s+de\s+/u', '', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/^(?:el|la|los|las)\s+(?:acceso\s+efectivo|estado\s+actual|estado\s+operativo|detalle(?:s)?|resumen)\s+/u', '', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/\b(?:que\s+permisos\s+tiene.*|que\s+rol(?:es)?\s+tiene.*|y\s+que\s+rol.*|y\s+que\s+permisos.*|con\s+que\s+rol.*|cuales?\s+son\s+sus\s+roles.*|cuales?\s+son\s+sus\s+permisos.*)$/u', '', $sanitized) ?? $sanitized;
        $sanitized = trim($sanitized, " .,!?:;\"'");

        return $sanitized !== '' ? $sanitized : null;
    }

    protected function extractRoleFilter(string $normalized): ?string
    {
        $patterns = [
            '/\bque\s+usuarios\s+(?:son|sean|tienen\s+(?:el\s+)?(?:rol|perfil)\s+(?:de\s+)?)\s*(.+)$/u',
            '/\busuarios\s+(?:son|sean|con\s+rol|con\s+perfil)\s+(.+)$/u',
            '/\b(?:rol|perfil)\s+(.+)$/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized, $matches) === 1) {
                $role = trim((string) ($matches[1] ?? ''));
                $role = trim((preg_replace('/\s+y\s+.*$/u', '', $role) ?? $role));
                $role = trim((preg_replace('/\b(?:activos?|inactivos?|usuarios?|usuario|permisos?|acceso|efectivo)\b/u', ' ', $role) ?? $role));
                $role = UsersCopilotDomainLexicon::canonicalRole($role);

                if ($role !== '') {
                    return $role;
                }
            }
        }

        return null;
    }

    protected function extractAccessProfileFilter(string $normalized): ?string
    {
        if (preg_match('/\b(rol|perfil)\s+super-admin\b/u', $normalized) === 1) {
            return 'super_admin_role';
        }

        if (preg_match('/\bsuper-admin\b/u', $normalized) === 1 && preg_match('/\b(permisos|acceso|usuarios?|lista|lista(me)?|quienes?)\b/u', $normalized) === 1) {
            return 'super_admin_role';
        }

        return UsersCopilotDomainLexicon::accessProfile($normalized);
    }

    protected function clarificationHint(string $normalized): ?string
    {
        return $this->sanitizeEntityCandidate($this->extractEntitySearchQuery($normalized) ?? $normalized);
    }

    /**
     * @param  array<string, mixed>  $option
     */
    protected function clarificationOptionMatchesPrompt(array $option, string $candidate): bool
    {
        $candidate = $this->normalizePrompt($candidate);

        if ($candidate === '') {
            return false;
        }

        $label = $this->normalizePrompt((string) ($option['label'] ?? ''));
        $value = $this->normalizePrompt((string) ($option['value'] ?? ''));
        $resolvedLabel = $this->normalizePrompt((string) data_get($option, 'resolved_entity.label', ''));
        $optionEmail = $this->extractEmailFromText($label);
        $candidateEmail = $this->extractEmailFromText($candidate);

        if ($candidateEmail !== null && $optionEmail !== null) {
            return $candidateEmail === $optionEmail;
        }

        return $candidate === $label
            || $candidate === $value
            || $candidate === $resolvedLabel
            || str_contains($label, $candidate)
            || ($resolvedLabel !== '' && str_contains($resolvedLabel, $candidate));
    }

    /**
     * @param  list<array<string, mixed>>  $options
     * @return array<string, mixed>|null
     */
    protected function resolveClarificationOptionFromUserHint(string $hint, array $options): ?array
    {
        $matches = $this->candidateUsersFor($hint);

        if ($matches->count() !== 1) {
            return null;
        }

        $userId = $matches->first()?->id;

        if (! is_int($userId)) {
            return null;
        }

        return collect($options)->first(fn (array $option): bool => (int) data_get($option, 'resolved_entity.id', 0) === $userId);
    }

    /**
     * @return list<string>
     */
    protected function searchTokens(string $normalized): array
    {
        return array_values(array_filter(
            preg_split('/\s+/', $normalized) ?: [],
            static fn (string $token): bool => mb_strlen($token) >= 3 && ! in_array($token, ['el', 'la', 'los', 'las', 'del', 'de', 'usuario', 'usuaria'], true),
        ));
    }

    protected function extractHasRolesFilter(string $normalized): ?bool
    {
        if (str_contains($normalized, 'sin roles')) {
            return false;
        }

        if (str_contains($normalized, 'con roles') || str_contains($normalized, 'tienen roles')) {
            return true;
        }

        return null;
    }

    /**
     * Fase 2c: infiere el stage ganador a partir del plan final. Util para
     * telemetria sin tener que reescribir cada rama del planner.
     *
     * @param  array<string, mixed>  $plan
     */
    protected function inferStageFromPlan(array $plan): string
    {
        $capabilityKey = (string) ($plan['capability_key'] ?? '');
        $reason = (string) data_get($plan, 'clarification_state.reason', '');
        $classificationSource = $plan['classification_source'] ?? null;

        // Denial se detecta por reason prefix 'denied:'
        if (str_starts_with($reason, 'denied:')) {
            return 'denial';
        }

        // Fase 1c: continuation confirm
        if ($capabilityKey === 'users.continuation.confirm') {
            return 'staleness_confirmation';
        }

        if ($reason === 'snapshot_stale') {
            return 'staleness_confirmation';
        }

        // Clarificacion explicita con razones concretas
        if ($capabilityKey === 'users.clarification') {
            return match ($reason) {
                'missing_target' => 'clarification_missing_target',
                'missing_context' => 'clarification_missing_context',
                'missing_entity' => 'clarification_missing_entity',
                'ambiguous_target' => 'clarification_ambiguous_target',
                'weak_search' => 'clarification_weak_search',
                default => 'clarification_other',
            };
        }

        // Rescate LLM
        if ($classificationSource === 'llm_fallback') {
            return 'llm_rescue';
        }

        // Capability-based stage inference (reutiliza convenciones del catalogo)
        if ($capabilityKey === 'users.help.informational') {
            return 'help_informational';
        }

        if ($capabilityKey === 'users.help.unknown' || $capabilityKey === 'users.help') {
            return 'help_unknown';
        }

        if ($capabilityKey === 'users.mixed.metrics_search') {
            return 'mixed_metrics_search';
        }

        if ($capabilityKey === 'users.roles.catalog') {
            return 'roles_catalog';
        }

        if ($capabilityKey === 'users.snapshot.result_count') {
            return 'follow_up_count';
        }

        if (str_starts_with($capabilityKey, 'users.metrics.')) {
            return 'metrics';
        }

        if (str_starts_with($capabilityKey, 'users.explain.')) {
            return 'explain';
        }

        if (str_starts_with($capabilityKey, 'users.actions.')) {
            return 'action_proposal';
        }

        if ($capabilityKey === 'users.search') {
            return 'search';
        }

        if ($capabilityKey === 'users.detail') {
            return 'detail';
        }

        return 'unknown';
    }
}
