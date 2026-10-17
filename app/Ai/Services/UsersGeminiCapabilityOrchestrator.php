<?php

namespace App\Ai\Services;

use App\Ai\Support\BaseCopilotAgent;
use App\Ai\Support\CopilotConversationSnapshot;
use App\Ai\Tools\System\Users\ActivateUserTool;
use App\Ai\Tools\System\Users\CreateUserTool;
use App\Ai\Tools\System\Users\DeactivateUserTool;
use App\Ai\Tools\System\Users\GetUserDetailTool;
use App\Ai\Tools\System\Users\SearchUsersTool;
use App\Ai\Tools\System\Users\SendUserPasswordResetTool;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request as ToolRequest;

class UsersGeminiCapabilityOrchestrator
{
    public function __construct(
        protected UsersCopilotCapabilityExecutor $capabilityExecutor,
        protected UsersCopilotResponseBuilder $responseBuilder,
    ) {}

    /**
     * @return array{prompt: string, payload: array<string, mixed>}
     */
    public function prepare(
        BaseCopilotAgent $agent,
        string $prompt,
        array $plan,
        CopilotConversationSnapshot $snapshot,
        ?array $executionResult = null,
    ): array {
        $payload = $this->resolvePayload($agent, $prompt, $plan, $snapshot, $executionResult);

        return [
            'prompt' => $this->buildFormatterPrompt($prompt, $payload),
            'payload' => $payload,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $formattedPayload
     * @param  array<string, mixed>  $diagnostics
     * @return array<string, mixed>
     */
    public function finalizePayload(array $basePayload, ?array $formattedPayload, array $diagnostics = []): array
    {
        $payload = $this->mergeFormattedPayload($basePayload, $formattedPayload);
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $existingDiagnostics = is_array($meta['diagnostics'] ?? null) ? $meta['diagnostics'] : [];

        $meta['diagnostics'] = array_filter([
            ...$existingDiagnostics,
            ...$diagnostics,
        ], static fn (mixed $value): bool => $value !== null);

        $payload['meta'] = $meta;

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolvePayload(
        BaseCopilotAgent $agent,
        string $prompt,
        array $plan,
        CopilotConversationSnapshot $snapshot,
        ?array $executionResult,
    ): array {
        $normalizedPrompt = is_string($plan['request_normalization'] ?? null)
            ? $plan['request_normalization']
            : Str::of(Str::lower(Str::ascii($prompt)))->squish()->value();
        $mentionedUser = $this->findMentionedUser($prompt);
        $contextUser = $agent->subjectUser() ?? $mentionedUser;
        $capabilityKey = $plan['capability_key'] ?? null;

        if ($sharedPayload = $this->sharedPayload($agent, $plan, $snapshot, $executionResult)) {
            return $sharedPayload;
        }

        if ($capabilityKey === 'users.actions.deactivate' && $contextUser instanceof User) {
            return $this->wrapPlannedPayload(
                $agent,
                $plan,
                $snapshot,
                $this->actionPayload($agent, new DeactivateUserTool($agent->actor()), $contextUser->id, 'users.actions.deactivate'),
            );
        }

        if ($capabilityKey === 'users.actions.activate' && $contextUser instanceof User) {
            return $this->wrapPlannedPayload(
                $agent,
                $plan,
                $snapshot,
                $this->actionPayload($agent, new ActivateUserTool($agent->actor()), $contextUser->id, 'users.actions.activate'),
            );
        }

        if ($capabilityKey === 'users.actions.send_reset' && $contextUser instanceof User) {
            return $this->wrapPlannedPayload(
                $agent,
                $plan,
                $snapshot,
                $this->actionPayload($agent, new SendUserPasswordResetTool($agent->actor()), $contextUser->id, 'users.actions.send_reset'),
            );
        }

        if ($capabilityKey === 'users.actions.create_user') {
            return $this->wrapPlannedPayload($agent, $plan, $snapshot, $this->createUserPayload($agent, $prompt));
        }

        if ($capabilityKey === 'users.detail' && $contextUser instanceof User) {
            return $this->wrapPlannedPayload($agent, $plan, $snapshot, $this->userDetailPayload($agent, $contextUser));
        }

        if ($capabilityKey === 'users.search') {
            return $this->wrapPlannedPayload($agent, $plan, $snapshot, $this->searchPayload($agent, $prompt, $normalizedPrompt, $plan));
        }

        if ($contextUser instanceof User && $this->looksLikeActionProposal($normalizedPrompt, ['desactiv', 'inhabilit', 'bloque'])) {
            return $this->wrapPlannedPayload(
                $agent,
                $plan,
                $snapshot,
                $this->actionPayload($agent, new DeactivateUserTool($agent->actor()), $contextUser->id, 'users.actions.deactivate'),
            );
        }

        if ($contextUser instanceof User && $this->looksLikeActionProposal($normalizedPrompt, ['reactiv', 'activ'])) {
            return $this->wrapPlannedPayload(
                $agent,
                $plan,
                $snapshot,
                $this->actionPayload($agent, new ActivateUserTool($agent->actor()), $contextUser->id, 'users.actions.activate'),
            );
        }

        if ($contextUser instanceof User && $this->looksLikePasswordResetProposal($normalizedPrompt)) {
            return $this->wrapPlannedPayload(
                $agent,
                $plan,
                $snapshot,
                $this->actionPayload($agent, new SendUserPasswordResetTool($agent->actor()), $contextUser->id, 'users.actions.send_reset'),
            );
        }

        if ($contextUser instanceof User && ! $this->looksLikeExplicitCollectionSearch($normalizedPrompt)) {
            return $this->wrapPlannedPayload($agent, $plan, $snapshot, $this->userDetailPayload($agent, $contextUser));
        }

        if ($this->looksLikeInactiveSearch($normalizedPrompt)) {
            return $this->wrapPlannedPayload($agent, $plan, $snapshot, $this->inactiveUsersPayload($agent));
        }

        if ($this->looksLikeOperationalSearch($normalizedPrompt)) {
            return $this->wrapPlannedPayload($agent, $plan, $snapshot, $this->operationalSearchPayload($agent, $normalizedPrompt));
        }

        if ($this->looksLikeRoleSearch($normalizedPrompt)) {
            return $this->wrapPlannedPayload($agent, $plan, $snapshot, $this->roleSearchPayload($agent, $normalizedPrompt));
        }

        if ($this->looksLikeNameSearch($normalizedPrompt)) {
            return $this->wrapPlannedPayload($agent, $plan, $snapshot, $this->nameSearchPayload($agent, $prompt, $normalizedPrompt));
        }

        if ($this->looksLikeCreateUserProposal($normalizedPrompt)) {
            return $this->wrapPlannedPayload($agent, $plan, $snapshot, $this->createUserPayload($agent, $prompt));
        }

        return $this->wrapPlannedPayload($agent, $plan, $snapshot, $this->helpPayload($agent));
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>|null  $executionResult
     * @return array<string, mixed>|null
     */
    protected function sharedPayload(
        BaseCopilotAgent $agent,
        array $plan,
        CopilotConversationSnapshot $snapshot,
        ?array $executionResult,
    ): ?array {
        $capabilityKey = $plan['capability_key'] ?? null;

        if (($plan['intent_family'] ?? null) === 'ambiguous' || $capabilityKey === 'users.clarification') {
            return $this->responseBuilder->build(
                plan: $plan,
                snapshot: $snapshot,
                executionResult: null,
                providerPayload: null,
                responseSource: 'gemini_local_orchestrator',
                subjectUserId: $agent->subjectUser()?->id,
            );
        }

        if (in_array($executionResult['family'] ?? null, ['mixed', 'aggregate', 'list', 'detail', 'action', 'explain'], true)
            && ($executionResult['outcome'] ?? null) === 'ok') {
            return $this->responseBuilder->build(
                plan: $plan,
                snapshot: $snapshot,
                executionResult: $executionResult,
                providerPayload: null,
                responseSource: 'gemini_local_orchestrator',
                subjectUserId: $agent->subjectUser()?->id,
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function wrapPlannedPayload(
        BaseCopilotAgent $agent,
        array $plan,
        CopilotConversationSnapshot $snapshot,
        array $payload,
    ): array {
        return $this->responseBuilder->build(
            plan: $plan,
            snapshot: $snapshot,
            executionResult: null,
            providerPayload: $payload,
            responseSource: 'gemini_local_orchestrator',
            subjectUserId: $agent->subjectUser()?->id,
        );
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    protected function searchPayload(BaseCopilotAgent $agent, string $originalPrompt, string $normalizedPrompt, array $plan): array
    {
        $filters = is_array($plan['filters'] ?? null) ? $plan['filters'] : [];

        if (($filters['has_roles'] ?? null) !== null
            || ($filters['email_verified'] ?? null) !== null
            || ($filters['status'] ?? null) !== null
            || ($filters['access_profile'] ?? null) !== null
            || (($filters['role'] ?? null) !== null && ($filters['query'] ?? null) !== null)) {
            return $this->operationalSearchPayloadFromFilters($agent, [
                'query' => $filters['query'] ?? null,
                'status' => $filters['status'] ?? null,
                'role' => $filters['role'] ?? null,
                'access_profile' => $filters['access_profile'] ?? null,
                'email_verified' => $filters['email_verified'] ?? null,
                'has_roles' => $filters['has_roles'] ?? null,
            ]);
        }

        if (($filters['role'] ?? null) !== null) {
            return $this->roleSearchPayload($agent, (string) $filters['role']);
        }

        if (($filters['status'] ?? null) === 'inactive' && ($filters['query'] ?? null) === null) {
            return $this->inactiveUsersPayload($agent);
        }

        if (is_string($filters['query'] ?? null) && trim((string) $filters['query']) !== '') {
            return $this->nameSearchPayload($agent, $originalPrompt, (string) $filters['query']);
        }

        if ($this->looksLikeRoleSearch($normalizedPrompt)) {
            return $this->roleSearchPayload($agent, $normalizedPrompt);
        }

        if ($this->looksLikeOperationalSearch($normalizedPrompt)) {
            return $this->operationalSearchPayload($agent, $normalizedPrompt);
        }

        if ($this->looksLikeInactiveSearch($normalizedPrompt)) {
            return $this->inactiveUsersPayload($agent);
        }

        return $this->nameSearchPayload($agent, $originalPrompt, $normalizedPrompt);
    }

    protected function looksLikeInactiveSearch(string $prompt): bool
    {
        return preg_match('/\b(busca|buscar|muestra|mostrar|lista|listar|resume|resumir|revisa|revisa los|dame)\b.*\busuarios? inactiv[oa]s?\b/u', $prompt) === 1
            || preg_match('/\busuarios? inactiv[oa]s?\b.*\b(busca|mostrar|listar|resume|revisa|dame)\b/u', $prompt) === 1;
    }

    protected function looksLikeOperationalSearch(string $prompt): bool
    {
        return str_contains($prompt, 'sin roles')
            || str_contains($prompt, 'no verificados')
            || str_contains($prompt, 'no verificado')
            || str_contains($prompt, 'no tienen roles');
    }

    /**
     * @param  list<string>  $keywords
     */
    protected function looksLikeActionProposal(string $prompt, array $keywords): bool
    {
        $hasIntentPrefix = str_contains($prompt, 'propon')
            || str_contains($prompt, 'prepara')
            || str_contains($prompt, 'puedo')
            || str_contains($prompt, 'necesito')
            || str_contains($prompt, 'quiero')
            || str_contains($prompt, 'ayudame')
            || str_contains($prompt, 'ayúdame');

        $hasDirectVerb = (bool) preg_match('/\b(desactiva|inhabilita|bloquea|reactiva|activa|restablece|resetea|envia\s+reset|envia\s+restablecimiento|crea|da\s+de\s+alta)\b/u', $prompt);

        if (! $hasIntentPrefix && ! $hasDirectVerb) {
            return false;
        }

        foreach ($keywords as $keyword) {
            if (str_contains($prompt, $keyword)) {
                return true;
            }
        }

        return false;
    }

    protected function looksLikePasswordResetProposal(string $prompt): bool
    {
        return $this->looksLikeActionProposal($prompt, ['restablec', 'reset', 'contras']);
    }

    protected function looksLikeCreateUserProposal(string $prompt): bool
    {
        return $this->looksLikeActionProposal($prompt, ['crear usuario', 'alta', 'nuevo usuario']);
    }

    protected function looksLikeExplicitCollectionSearch(string $prompt): bool
    {
        return $this->looksLikeInactiveSearch($prompt)
            || $this->looksLikeOperationalSearch($prompt)
            || $this->looksLikeRoleSearch($prompt)
            || preg_match('/\b(busca|buscar|muestra|mostrar|lista|listar|resume|resumir|dame)\b.*\busuarios?\b/u', $prompt) === 1;
    }

    protected function looksLikeNameSearch(string $prompt): bool
    {
        return preg_match('/\b(busca|buscar|muestra|mostrar|lista|listar|dame|encuentra|encontrar)\b.*\b(usuario|usuarios|nombre|llamad[oa])\b/u', $prompt) === 1;
    }

    protected function looksLikeRoleSearch(string $prompt): bool
    {
        return preg_match('/\b(busca|buscar|muestra|mostrar|lista|listar|dame|encuentra|encontrar)\b.*\b(rol|role|perfil)\b/u', $prompt) === 1
            || preg_match('/\busuarios?\b.*\b(con\s+rol|con\s+perfil|del\s+rol|que\s+sean)\b/u', $prompt) === 1;
    }

    protected function extractSearchTerm(string $originalPrompt, string $normalizedPrompt): string
    {
        $patterns = [
            '/\b(?:llamad[oa]|nombre)\s+(.+?)(?:\s*$)/u',
            '/\b(?:busca|buscar|encuentra|encontrar)\s+(?:al\s+)?usuario\s+(.+?)(?:\s*$)/u',
            '/\b(?:con\s+nombre)\s+(.+?)(?:\s*$)/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalizedPrompt, $matches) === 1) {
                return trim($matches[1]);
            }
        }

        $stopWords = ['busca', 'buscar', 'muestra', 'mostrar', 'lista', 'listar', 'dame', 'encuentra', 'encontrar', 'usuario', 'usuarios', 'con', 'nombre', 'llamado', 'llamada', 'al', 'el', 'la', 'los', 'las', 'de', 'del', 'que', 'se', 'un', 'una'];
        $words = preg_split('/\s+/', $normalizedPrompt) ?: [];
        $meaningful = array_filter($words, fn (string $word): bool => ! in_array($word, $stopWords, true) && mb_strlen($word) >= 3);

        return implode(' ', $meaningful);
    }

    protected function extractRoleSearchTerm(string $normalizedPrompt): string
    {
        $patterns = [
            '/\b(?:con\s+rol|con\s+perfil|del\s+rol)\s+(?:de\s+)?(.+?)(?:\s*$)/u',
            '/\b(?:que\s+sean)\s+(.+?)(?:\s*$)/u',
            '/\b(?:rol|perfil)\s+(.+?)(?:\s*$)/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalizedPrompt, $matches) === 1) {
                return trim($matches[1]);
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    protected function nameSearchPayload(BaseCopilotAgent $agent, string $originalPrompt, string $normalizedPrompt): array
    {
        $searchTerm = $this->extractSearchTerm($originalPrompt, $normalizedPrompt);

        $results = $this->runJsonTool(new SearchUsersTool($agent->actor()), [
            'query' => $searchTerm,
        ]);

        $count = (int) ($results['count'] ?? 0);
        $users = Arr::wrap($results['users'] ?? []);

        $answer = $count === 0
            ? 'No encontré usuarios que coincidan con "'.Str::limit($searchTerm, 50).'".'
            : 'Encontré '.$count.' usuario'.($count === 1 ? '' : 's').' que coincide'.($count === 1 ? '' : 'n').' con la búsqueda.';

        return $this->makePayload(
            agent: $agent,
            answer: rtrim($answer),
            intent: 'search_results',
            cards: [[
                'kind' => 'search_results',
                'title' => $count === 0
                    ? 'Sin resultados para "'.Str::limit($searchTerm, 30).'"'
                    : 'Resultados de búsqueda',
                'summary' => $count === 0
                    ? 'No hay usuarios que coincidan. Intenta con otro nombre o correo.'
                    : 'Se '.($count === 1 ? 'encontró' : 'encontraron').' '.$count.' usuario'.($count === 1 ? '' : 's').'.',
                'data' => $results,
            ]],
            references: $this->referencesFromUsers($users),
            diagnostics: [
                'execution' => 'local_capability_orchestrator',
                'capability' => 'users.search',
                'formatter' => 'gemini_text_json',
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function roleSearchPayload(BaseCopilotAgent $agent, string $normalizedPrompt): array
    {
        $roleTerm = $this->extractRoleSearchTerm($normalizedPrompt);

        if ($roleTerm === '') {
            $roleTerm = trim($normalizedPrompt);
        }

        $results = $this->runJsonTool(new SearchUsersTool($agent->actor()), [
            'role' => $roleTerm,
        ]);

        $count = (int) ($results['count'] ?? 0);
        $users = Arr::wrap($results['users'] ?? []);

        $answer = $count === 0
            ? 'No encontré usuarios con un rol que coincida con "'.Str::limit($roleTerm, 50).'".'
            : 'Encontré '.$count.' usuario'.($count === 1 ? '' : 's').' con el rol indicado.';

        return $this->makePayload(
            agent: $agent,
            answer: rtrim($answer),
            intent: 'search_results',
            cards: [[
                'kind' => 'search_results',
                'title' => $count === 0
                    ? 'Sin resultados para rol "'.Str::limit($roleTerm, 30).'"'
                    : 'Usuarios por rol',
                'summary' => $count === 0
                    ? 'No hay usuarios con ese rol. Verifica el nombre del rol.'
                    : 'Se '.($count === 1 ? 'encontró' : 'encontraron').' '.$count.' usuario'.($count === 1 ? '' : 's').' con ese rol.',
                'data' => $results,
            ]],
            references: $this->referencesFromUsers($users),
            diagnostics: [
                'execution' => 'local_capability_orchestrator',
                'capability' => 'users.search',
                'formatter' => 'gemini_text_json',
            ],
        );
    }

    protected function findMentionedUser(string $prompt): ?User
    {
        if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $prompt, $matches) === 1) {
            $user = User::query()->where('email', Str::lower($matches[0]))->first();

            if ($user instanceof User) {
                return $user;
            }
        }

        $normalizedPrompt = Str::of(Str::lower(Str::ascii($prompt)))->squish()->value();

        return User::query()
            ->select(['id', 'name', 'email', 'is_active'])
            ->get()
            ->sortByDesc(static fn (User $user): int => mb_strlen($user->name))
            ->first(function (User $user) use ($normalizedPrompt): bool {
                $normalizedName = Str::of(Str::lower(Str::ascii($user->name)))->squish()->value();

                return $normalizedName !== ''
                    && mb_strlen($normalizedName) >= 4
                    && str_contains($normalizedPrompt, $normalizedName);
            });
    }

    /**
     * @return array<string, mixed>
     */
    protected function inactiveUsersPayload(BaseCopilotAgent $agent): array
    {
        $results = $this->runJsonTool(new SearchUsersTool($agent->actor()), [
            'status' => 'inactive',
        ]);

        $count = (int) ($results['count'] ?? 0);
        $users = Arr::wrap($results['users'] ?? []);
        $namedUsers = collect($users)
            ->pluck('name')
            ->filter(fn (mixed $name): bool => is_string($name) && $name !== '')
            ->take(3)
            ->values()
            ->all();

        $answer = $count === 0
            ? 'No encontré usuarios inactivos con los datos actuales.'
            : 'Encontré '.$count.' usuario'.($count === 1 ? '' : 's').' inactivo'.($count === 1 ? '' : 's').'. '
                .$this->joinNaturalList($namedUsers)
                .$this->inactiveUsersStatusSuffix($users);

        return $this->makePayload(
            agent: $agent,
            answer: rtrim($answer),
            intent: 'search_results',
            cards: [[
                'kind' => 'search_results',
                'title' => 'Usuarios inactivos',
                'summary' => $count === 0
                    ? 'No hay usuarios inactivos para revisar.'
                    : 'Hay '.$count.' usuario'.($count === 1 ? '' : 's').' inactivo'.($count === 1 ? '' : 's').' en el resultado actual.',
                'data' => $results,
            ]],
            references: $this->referencesFromUsers($users),
            diagnostics: [
                'execution' => 'local_capability_orchestrator',
                'capability' => 'users.search',
                'formatter' => 'gemini_text_json',
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function operationalSearchPayload(BaseCopilotAgent $agent, string $prompt): array
    {
        $filters = ['status' => 'all'];
        $title = 'Busqueda operativa de usuarios';

        if (str_contains($prompt, 'sin roles') || str_contains($prompt, 'no tienen roles')) {
            $filters['has_roles'] = false;
            $title = 'Usuarios sin roles';
        }

        if (str_contains($prompt, 'no verificados') || str_contains($prompt, 'no verificado')) {
            $filters['email_verified'] = false;
            $title = 'Usuarios no verificados';
        }

        $results = $this->runJsonTool(new SearchUsersTool($agent->actor()), $filters);
        $count = (int) ($results['count'] ?? 0);

        return $this->makePayload(
            agent: $agent,
            answer: $count === 0
                ? 'No encontré usuarios para ese criterio operativo.'
                : 'Encontré '.$count.' usuario'.($count === 1 ? '' : 's').' para ese criterio operativo.',
            intent: 'search_results',
            cards: [[
                'kind' => 'search_results',
                'title' => $title,
                'summary' => $count === 0
                    ? 'No hubo coincidencias.'
                    : 'Resultado operativo listo para revisar.',
                'data' => $results,
            ]],
            references: $this->referencesFromUsers(Arr::wrap($results['users'] ?? [])),
            diagnostics: [
                'execution' => 'local_capability_orchestrator',
                'capability' => 'users.search',
                'formatter' => 'gemini_text_json',
            ],
        );
    }

    /**
     * @param  array{query: ?string, status: 'active'|'inactive'|'all'|null, role: ?string, access_profile: ?string, email_verified: ?bool, has_roles: ?bool}  $filters
     * @return array<string, mixed>
     */
    protected function operationalSearchPayloadFromFilters(BaseCopilotAgent $agent, array $filters): array
    {
        $toolFilters = ['status' => $filters['status'] ?? 'all'];
        $title = 'Busqueda operativa de usuarios';

        if (is_string($filters['query'] ?? null) && trim((string) $filters['query']) !== '') {
            $toolFilters['query'] = $filters['query'];
        }

        if (is_string($filters['role'] ?? null) && trim((string) $filters['role']) !== '') {
            $toolFilters['role'] = $filters['role'];
            $title = 'Usuarios por rol';
        }

        if (is_string($filters['access_profile'] ?? null) && trim((string) $filters['access_profile']) !== '') {
            $toolFilters['access_profile'] = $filters['access_profile'];
            $title = match ($filters['access_profile']) {
                'administrative_access' => 'Usuarios con acceso administrativo',
                'super_admin_role' => 'Usuarios con rol super-admin',
                default => $title,
            };
        }

        if (($filters['has_roles'] ?? null) === false) {
            $toolFilters['has_roles'] = false;
            $title = 'Usuarios sin roles';
        }

        if (($filters['email_verified'] ?? null) === false) {
            $toolFilters['email_verified'] = false;
            $title = 'Usuarios no verificados';
        }

        $results = $this->runJsonTool(new SearchUsersTool($agent->actor()), $toolFilters);
        $count = (int) ($results['count'] ?? 0);

        return $this->makePayload(
            agent: $agent,
            answer: $count === 0
                ? 'No encontré usuarios para ese criterio operativo.'
                : 'Encontré '.$count.' usuario'.($count === 1 ? '' : 's').' para ese criterio operativo.',
            intent: 'search_results',
            cards: [[
                'kind' => 'search_results',
                'title' => $title,
                'summary' => $count === 0
                    ? 'No hubo coincidencias.'
                    : 'Resultado operativo listo para revisar.',
                'data' => $results,
            ]],
            references: $this->referencesFromUsers(Arr::wrap($results['users'] ?? [])),
            diagnostics: [
                'execution' => 'local_capability_orchestrator',
                'capability' => 'users.search',
                'formatter' => 'gemini_text_json',
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function userDetailPayload(BaseCopilotAgent $agent, User $user): array
    {
        $detail = $this->runJsonTool(new GetUserDetailTool($agent->actor()), [
            'user_id' => $user->id,
            'include_access' => true,
        ]);

        if (! ($detail['found'] ?? false)) {
            return $this->makePayload(
                agent: $agent,
                answer: 'No encontré el usuario solicitado para revisar su estado.',
                intent: 'inform',
                cards: [],
                diagnostics: [
                    'execution' => 'local_capability_orchestrator',
                    'capability' => 'users.detail',
                    'formatter' => 'gemini_text_json',
                    'result' => 'not_found',
                ],
            );
        }

        $userData = Arr::get($detail, 'user', []);
        $roles = Arr::wrap($detail['roles'] ?? []);
        $permissionGroups = array_keys(Arr::wrap($detail['effective_permissions'] ?? []));

        $status = ($userData['is_active'] ?? false) ? 'activo' : 'inactivo';
        $verification = ($userData['email_verified'] ?? false) ? 'correo verificado' : 'correo no verificado';
        $twoFactor = ($userData['two_factor_enabled'] ?? false) ? '2FA activo' : '2FA no configurado';

        return $this->makePayload(
            agent: $agent,
            answer: sprintf(
                '%s está %s, tiene %s y %s. Roles asignados: %d. Grupos de permisos efectivos: %d.',
                $userData['name'] ?? 'El usuario',
                $status,
                $verification,
                $twoFactor,
                count($roles),
                count($permissionGroups),
            ),
            intent: 'user_context',
            cards: [[
                'kind' => 'user_context',
                'title' => 'Contexto del usuario',
                'summary' => 'Estado operativo, roles y acceso efectivo consolidados.',
                'data' => $detail,
            ]],
            references: Arr::wrap($userData['references'] ?? []),
            diagnostics: [
                'execution' => 'local_capability_orchestrator',
                'capability' => 'users.detail',
                'formatter' => 'gemini_text_json',
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function actionPayload(BaseCopilotAgent $agent, object $tool, int $userId, string $capability): array
    {
        $result = $this->runJsonTool($tool, ['user_id' => $userId]);
        $action = is_array($result['action'] ?? null) ? $result['action'] : null;

        if ($action === null) {
            return $this->makePayload(
                agent: $agent,
                answer: (string) ($result['message'] ?? 'No pude preparar la propuesta solicitada.'),
                intent: 'inform',
                cards: [],
                diagnostics: [
                    'execution' => 'local_capability_orchestrator',
                    'capability' => $capability,
                    'formatter' => 'gemini_text_json',
                    'result' => 'missing_action',
                ],
            );
        }

        $canExecute = (bool) ($action['can_execute'] ?? false);
        $answer = $canExecute
            ? 'Preparé una propuesta lista para confirmación. Revísala antes de ejecutarla.'
            : 'Preparé una propuesta no ejecutable por ahora. Revisa el motivo indicado antes de continuar.';

        return $this->makePayload(
            agent: $agent,
            answer: $answer,
            intent: 'action_proposal',
            cards: [],
            actions: [$action],
            requiresConfirmation: $canExecute,
            references: $this->referencesFromAction($action),
            diagnostics: [
                'execution' => 'local_capability_orchestrator',
                'capability' => $capability,
                'formatter' => 'gemini_text_json',
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function createUserPayload(BaseCopilotAgent $agent, string $prompt): array
    {
        preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $prompt, $emailMatch);
        $name = Str::of($prompt)
            ->after(' para ')
            ->before(',')
            ->trim()
            ->value();

        $result = $this->runJsonTool(new CreateUserTool($agent->actor()), [
            'name' => $name !== '' ? $name : null,
            'email' => $emailMatch[0] ?? null,
            'roles' => $this->extractRoleHints($prompt),
        ]);

        $action = is_array($result['action'] ?? null) ? $result['action'] : null;

        return $this->makePayload(
            agent: $agent,
            answer: $action === null
                ? (string) ($result['message'] ?? 'No pude preparar el alta guiada solicitada.')
                : 'Preparé una propuesta de alta guiada. Completa los campos faltantes antes de confirmar.',
            intent: $action === null ? 'inform' : 'action_proposal',
            cards: $action === null ? [] : [[
                'kind' => 'notice',
                'title' => 'Alta guiada',
                'summary' => 'Revisa los campos detectados y los faltantes antes de confirmar.',
                'data' => [
                    'missing_fields' => Arr::wrap($result['missing_fields'] ?? []),
                ],
            ]],
            actions: $action === null ? [] : [$action],
            requiresConfirmation: (bool) Arr::get($action, 'can_execute', false),
            diagnostics: [
                'execution' => 'local_capability_orchestrator',
                'capability' => 'users.actions.create_user',
                'formatter' => 'gemini_text_json',
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function helpPayload(BaseCopilotAgent $agent): array
    {
        return $this->makePayload(
            agent: $agent,
            answer: 'Puedo buscar usuarios inactivos, revisar el estado y acceso efectivo de un usuario con contexto seleccionado, y preparar propuestas seguras como activar, desactivar, enviar restablecimiento o alta guiada cuando haya datos suficientes.',
            intent: 'help',
            cards: [[
                'kind' => 'notice',
                'title' => 'Capacidades disponibles',
                'summary' => 'Consultas operativas del modulo de usuarios disponibles sin ejecutar cambios.',
                'data' => [
                    'supported_queries' => [
                        'Busca usuarios inactivos y resume su estado actual',
                        'Explica el acceso efectivo del usuario seleccionado',
                        'Propón desactivar al usuario seleccionado',
                        'Propón enviar un restablecimiento al usuario seleccionado',
                    ],
                ],
            ]],
            diagnostics: [
                'execution' => 'local_capability_orchestrator',
                'capability' => 'help',
                'formatter' => 'gemini_text_json',
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function buildFormatterPrompt(string $originalPrompt, array $payload): string
    {
        $capabilityKey = Arr::get($payload, 'meta.capability_key', 'users.help');
        $intentFamily = Arr::get($payload, 'meta.intent_family', 'help');
        $instructions = "Consulta: {$originalPrompt}\n"
            ."Capacidad={$capabilityKey}; intent_family={$intentFamily}.\n"
            ."Reescribe solo answer, cards[].title, cards[].summary y actions[].summary.\n"
            ."No cambies ids, acciones, referencias, intent, meta ni data.\n"
            ."Devuelve solo JSON valido y sin Markdown.\n"
            ."Si count es 0, dilo claramente.\n"
            .'JSON base:';

        return $instructions."\n".json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>|null  $formattedPayload
     * @return array<string, mixed>
     */
    protected function mergeFormattedPayload(array $basePayload, ?array $formattedPayload): array
    {
        if (! is_array($formattedPayload)) {
            return $basePayload;
        }

        if (is_string($formattedPayload['answer'] ?? null) && $formattedPayload['answer'] !== '') {
            $basePayload['answer'] = $formattedPayload['answer'];
        }

        foreach (Arr::wrap($basePayload['cards'] ?? []) as $index => $card) {
            $formattedCard = Arr::get($formattedPayload, "cards.{$index}");

            if (! is_array($formattedCard) || ($formattedCard['kind'] ?? null) !== ($card['kind'] ?? null)) {
                continue;
            }

            if (is_string($formattedCard['title'] ?? null)) {
                $basePayload['cards'][$index]['title'] = $formattedCard['title'];
            }

            if (is_string($formattedCard['summary'] ?? null)) {
                $basePayload['cards'][$index]['summary'] = $formattedCard['summary'];
            }
        }

        foreach (Arr::wrap($basePayload['actions'] ?? []) as $index => $action) {
            $formattedAction = Arr::get($formattedPayload, "actions.{$index}");

            if (! is_array($formattedAction) || ($formattedAction['action_type'] ?? null) !== ($action['action_type'] ?? null)) {
                continue;
            }

            if (is_string($formattedAction['summary'] ?? null)) {
                $basePayload['actions'][$index]['summary'] = $formattedAction['summary'];
            }
        }

        return $basePayload;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function runJsonTool(object $tool, array $arguments): array
    {
        return json_decode($tool->handle(new ToolRequest($arguments)), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<array<string, mixed>>  $users
     */
    protected function inactiveUsersStatusSuffix(array $users): string
    {
        if ($users === []) {
            return '';
        }

        $unverified = collect($users)->where('email_verified', false)->count();
        $withTwoFactor = collect($users)->where('two_factor_enabled', true)->count();

        return ' '.$unverified.' sin correo verificado y '.$withTwoFactor.' con 2FA activo en esta muestra.';
    }

    /**
     * @param  list<string>  $values
     */
    protected function joinNaturalList(array $values): string
    {
        if ($values === []) {
            return '';
        }

        if (count($values) === 1) {
            return $values[0].'.';
        }

        $last = array_pop($values);

        return implode(', ', $values).' y '.$last.'.';
    }

    /**
     * @param  list<array<string, mixed>>  $users
     * @return list<array{label: string, href: string|null}>
     */
    protected function referencesFromUsers(array $users): array
    {
        return collect($users)
            ->filter(fn (mixed $user): bool => is_array($user))
            ->take(3)
            ->map(fn (array $user): array => [
                'label' => (string) ($user['name'] ?? 'Abrir usuario'),
                'href' => is_string($user['show_href'] ?? null) ? $user['show_href'] : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $action
     * @return list<array{label: string, href: string|null}>
     */
    protected function referencesFromAction(array $action): array
    {
        $target = Arr::wrap($action['target'] ?? []);
        $userId = Arr::get($target, 'user_id');

        if (! is_int($userId)) {
            return [];
        }

        return [[
            'label' => 'Abrir perfil del usuario',
            'href' => route('system.users.show', ['user' => $userId], absolute: false),
        ]];
    }

    /**
     * @return list<string>
     */
    protected function extractRoleHints(string $prompt): array
    {
        return collect(preg_split('/[,;]+/', $prompt) ?: [])
            ->map(fn (string $part): string => trim($part))
            ->filter(fn (string $part): bool => str_contains(Str::lower($part), 'rol '))
            ->map(fn (string $part): string => trim(Str::after(Str::lower($part), 'rol ')))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $cards
     * @param  list<array<string, mixed>>  $actions
     * @param  list<array{label: string, href: string|null}>  $references
     * @param  array<string, mixed>  $diagnostics
     * @return array<string, mixed>
     */
    protected function makePayload(
        BaseCopilotAgent $agent,
        string $answer,
        string $intent,
        array $cards,
        array $actions = [],
        bool $requiresConfirmation = false,
        array $references = [],
        array $diagnostics = [],
    ): array {
        return [
            'answer' => $answer,
            'intent' => $intent,
            'cards' => $cards,
            'actions' => $actions,
            'requires_confirmation' => $requiresConfirmation,
            'references' => $references,
            'meta' => [
                'module' => $agent->module(),
                'channel' => $agent->channel(),
                'subject_user_id' => $agent->subjectUser()?->id,
                'fallback' => false,
                'diagnostics' => $diagnostics === [] ? null : $diagnostics,
            ],
        ];
    }
}
