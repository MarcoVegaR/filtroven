<?php

namespace App\Ai\Services;

use App\Ai\Services\Users\UsersMetricsAggregator;
use App\Ai\Support\CopilotActionType;
use App\Ai\Support\UsersCopilotDomainLexicon;
use App\Ai\Tools\System\Users\ActivateUserTool;
use App\Ai\Tools\System\Users\CreateUserTool;
use App\Ai\Tools\System\Users\DeactivateUserTool;
use App\Ai\Tools\System\Users\GetUserDetailTool;
use App\Ai\Tools\System\Users\SearchUsersTool;
use App\Ai\Tools\System\Users\SendUserPasswordResetTool;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Laravel\Ai\Tools\Request as ToolRequest;

class UsersCopilotCapabilityExecutor
{
    public function __construct(protected ?UsersMetricsAggregator $metricsAggregator = null)
    {
        $this->metricsAggregator ??= new UsersMetricsAggregator;
    }

    /**
     * @return array{
     *   capability_key: string,
     *   family: 'aggregate',
     *   outcome: 'ok'|'out_of_scope',
     *   answer_facts: array<string, mixed>,
     *   cards: list<array<string, mixed>>,
     *   actions: list<array<string, mixed>>,
     *   references: list<array{label: string, href: string|null}>,
     *   snapshot_updates: array<string, mixed>,
     *   diagnostics: array<string, mixed>
     * }
     */
    public function execute(string $capabilityKey): array
    {
        $definition = UsersCopilotCapabilityCatalog::find($capabilityKey);

        if (($definition['family'] ?? null) !== 'aggregate') {
            return [
                'capability_key' => $capabilityKey,
                'family' => 'aggregate',
                'outcome' => 'out_of_scope',
                'answer_facts' => [],
                'cards' => [],
                'actions' => [],
                'references' => $this->references(),
                'snapshot_updates' => [],
                'diagnostics' => [
                    'executor' => 'users_capability_executor',
                    'reason' => 'unsupported_capability',
                ],
            ];
        }

        $answerFacts = $this->resolveAggregateFacts($capabilityKey);
        $metric = is_array($answerFacts['metric'] ?? null) ? $answerFacts['metric'] : [];
        $metricValue = is_numeric($metric['value'] ?? null) ? (int) $metric['value'] : null;

        return [
            'capability_key' => $capabilityKey,
            'family' => 'aggregate',
            'outcome' => 'ok',
            'answer_facts' => $answerFacts,
            'cards' => [[
                'kind' => 'metrics',
                'title' => $metric['label'] ?? null,
                'summary' => $this->summaryFor($capabilityKey, $answerFacts),
                'data' => [
                    'capability_key' => $capabilityKey,
                    ...$answerFacts,
                ],
            ]],
            'actions' => [],
            'references' => $this->references(),
            'snapshot_updates' => [
                'last_intent_family' => 'read_metrics',
                'last_capability_key' => $capabilityKey,
                'last_result_count' => $metricValue,
                'last_metrics_snapshot' => [
                    'capability_key' => $capabilityKey,
                    ...$answerFacts,
                ],
            ],
            'diagnostics' => [
                'executor' => 'users_capability_executor',
                'source_of_truth' => 'deterministic_backend',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function executeCreateUserProposal(User $actor, array $payload): array
    {
        $result = json_decode((new CreateUserTool($actor))->handle(new ToolRequest($payload)), true, 512, JSON_THROW_ON_ERROR);
        $action = is_array($result['action'] ?? null) ? $result['action'] : null;
        $missingFields = array_values(array_filter(Arr::wrap($result['missing_fields'] ?? []), static fn (mixed $field): bool => is_string($field)));

        return [
            'capability_key' => 'users.actions.create_user',
            'family' => 'action',
            'outcome' => 'ok',
            'answer_facts' => $result,
            'cards' => $action === null ? [] : [[
                'kind' => 'notice',
                'title' => 'Alta guiada',
                'summary' => $missingFields === []
                    ? 'Revisa la propuesta antes de confirmar la creacion.'
                    : 'Completa los campos faltantes antes de confirmar.',
                'data' => [
                    'missing_fields' => $missingFields,
                ],
            ]],
            'actions' => $action === null ? [] : [$action],
            'references' => [[
                'label' => 'Usuarios',
                'href' => route('system.users.index', absolute: false),
            ]],
            'snapshot_updates' => [
                'pending_create_user' => $missingFields === [] ? null : [
                    'payload' => is_array($action['payload'] ?? null) ? $action['payload'] : $payload,
                    'missing_fields' => $missingFields,
                ],
            ],
            'diagnostics' => [
                'executor' => 'users_capability_executor',
                'source_of_truth' => 'deterministic_backend',
            ],
        ];
    }

    /**
     * @param  array{query: ?string, status: 'active'|'inactive'|'all'|null, role: ?string, access_profile: ?string, email_verified: ?bool, has_roles: ?bool}  $filters
     * @return array{
     *   capability_key: string,
     *   family: 'list',
     *   outcome: 'ok',
     *   answer_facts: array<string, mixed>,
     *   cards: list<array<string, mixed>>,
     *   actions: list<array<string, mixed>>,
     *   references: list<array{label: string, href: string|null}>,
     *   snapshot_updates: array<string, mixed>,
     *   diagnostics: array<string, mixed>
     * }
     */
    public function executeSearch(User $actor, array $filters): array
    {
        $searchResults = $this->runSearch($actor, $filters);
        $matchingCount = is_numeric($searchResults['matching_count'] ?? null) ? (int) $searchResults['matching_count'] : 0;

        return [
            'capability_key' => 'users.search',
            'family' => 'list',
            'outcome' => 'ok',
            'answer_facts' => [
                'search_results' => $searchResults,
                'applied_filters' => array_filter($filters, static fn (mixed $value): bool => $value !== null && $value !== ''),
            ],
            'cards' => [[
                'kind' => 'search_results',
                'title' => $this->searchTitle($filters),
                'summary' => $this->searchSummary($filters, $searchResults),
                'data' => $searchResults,
            ]],
            'actions' => [],
            'references' => $this->mixedReferences($searchResults),
            'snapshot_updates' => [
                'last_intent_family' => 'read_search',
                'last_capability_key' => 'users.search',
                'last_result_count' => $matchingCount,
            ],
            'diagnostics' => [
                'executor' => 'users_capability_executor',
                'source_of_truth' => 'deterministic_backend',
                'search_results_source' => 'deterministic_backend',
            ],
        ];
    }

    /**
     * @return array{
     *   capability_key: string,
     *   family: 'detail',
     *   outcome: 'ok',
     *   answer_facts: array<string, mixed>,
     *   cards: list<array<string, mixed>>,
     *   actions: list<array<string, mixed>>,
     *   references: list<array{label: string, href: string|null}>,
     *   snapshot_updates: array<string, mixed>,
     *   diagnostics: array<string, mixed>
     * }
     */
    public function executeDetail(User $actor, int $userId): array
    {
        $detail = json_decode((new GetUserDetailTool($actor))->handle(new ToolRequest([
            'user_id' => $userId,
            'include_access' => true,
        ])), true, 512, JSON_THROW_ON_ERROR);

        return [
            'capability_key' => 'users.detail',
            'family' => 'detail',
            'outcome' => 'ok',
            'answer_facts' => $detail,
            'cards' => [[
                'kind' => 'user_context',
                'title' => ($detail['found'] ?? false) ? 'Detalles del usuario' : 'Usuario no encontrado',
                'summary' => ($detail['found'] ?? false)
                    ? 'Informacion sobre el usuario '.($detail['user']['email'] ?? '')
                    : 'No encontre un usuario valido para mostrar en esta respuesta.',
                'data' => $detail,
            ]],
            'actions' => [],
            'references' => array_values(array_filter(Arr::wrap(data_get($detail, 'user.references', [])), static fn (mixed $reference): bool => is_array($reference))),
            'snapshot_updates' => [
                'last_intent_family' => 'read_detail',
                'last_capability_key' => 'users.detail',
                'last_resolved_entity_type' => ($detail['found'] ?? false) ? 'user' : null,
                'last_resolved_entity_id' => ($detail['found'] ?? false) ? ($detail['user']['id'] ?? null) : null,
            ],
            'diagnostics' => [
                'executor' => 'users_capability_executor',
                'source_of_truth' => 'deterministic_backend',
            ],
        ];
    }

    /**
     * @return array{capability_key: string,family: 'detail',outcome: 'ok',answer_facts: array<string,mixed>,cards: list<array<string,mixed>>,actions: list<array<string,mixed>>,references: list<array{label: string, href: string|null}>,snapshot_updates: array<string,mixed>,diagnostics: array<string,mixed>}
     */
    public function executePermissionExplanation(User $actor, int $userId, string $permission): array
    {
        $detail = json_decode((new GetUserDetailTool($actor))->handle(new ToolRequest([
            'user_id' => $userId,
            'include_access' => true,
        ])), true, 512, JSON_THROW_ON_ERROR);

        $groups = collect(Arr::wrap($detail['effective_permissions'] ?? []));
        $flatPermissions = $groups->flatten(1)->filter(static fn (mixed $permissionItem): bool => is_array($permissionItem));
        $permissionEntry = $flatPermissions->first(fn (array $permissionItem): bool => ($permissionItem['name'] ?? null) === $permission);

        return [
            'capability_key' => 'users.explain.permission',
            'family' => 'detail',
            'outcome' => 'ok',
            'answer_facts' => [
                'detail' => $detail,
                'permission' => $permission,
                'permission_label' => UsersCopilotDomainLexicon::permissionLabel($permission),
                'allowed' => $permissionEntry !== null,
                'permission_entry' => $permissionEntry,
            ],
            'cards' => [[
                'kind' => 'notice',
                'title' => 'Explicacion de permiso efectivo',
                'summary' => null,
                'data' => [
                    'type' => 'permission_explanation',
                    'permission' => $permission,
                    'permission_label' => UsersCopilotDomainLexicon::permissionLabel($permission),
                    'allowed' => $permissionEntry !== null,
                    'user' => $detail['user'] ?? null,
                    'roles' => $detail['roles'] ?? [],
                    'permission_entry' => $permissionEntry,
                ],
            ]],
            'actions' => [],
            'references' => array_values(array_filter(Arr::wrap(data_get($detail, 'user.references', [])), static fn (mixed $reference): bool => is_array($reference))),
            'snapshot_updates' => [
                'last_intent_family' => 'read_detail',
                'last_capability_key' => 'users.explain.permission',
                'last_resolved_entity_type' => ($detail['found'] ?? false) ? 'user' : null,
                'last_resolved_entity_id' => ($detail['found'] ?? false) ? ($detail['user']['id'] ?? null) : null,
            ],
            'diagnostics' => [
                'executor' => 'users_capability_executor',
                'source_of_truth' => 'deterministic_backend',
            ],
        ];
    }

    /**
     * @return array{capability_key: string,family: 'explain',outcome: 'ok',answer_facts: array<string,mixed>,cards: list<array<string,mixed>>,actions: list<array<string,mixed>>,references: list<array{label: string, href: string|null}>,snapshot_updates: array<string,mixed>,diagnostics: array<string,mixed>}
     */
    public function executeActionExplanation(User $actor, int $userId, int $targetUserId, CopilotActionType $actionType): array
    {
        $subject = User::query()->findOrFail($userId);
        $target = User::query()->findOrFail($targetUserId);

        Gate::forUser($actor)->authorize('view', $subject);
        Gate::forUser($actor)->authorize('view', $target);

        $allowed = match ($actionType) {
            CopilotActionType::Activate => Gate::forUser($subject)->check('activate', $target),
            CopilotActionType::Deactivate => Gate::forUser($subject)->check('deactivate', $target),
            CopilotActionType::SendReset => Gate::forUser($subject)->check('sendReset', $target),
            default => false,
        };

        $permissionLabel = match ($actionType) {
            CopilotActionType::Activate => 'activar usuarios',
            CopilotActionType::Deactivate => 'desactivar usuarios',
            CopilotActionType::SendReset => 'enviar restablecimientos de contraseña',
            default => 'realizar esta accion',
        };

        $reason = match ($actionType) {
            CopilotActionType::Deactivate => $subject->id === $target->id
                ? 'No puede desactivar su propia cuenta.'
                : (User::isLastEffectiveAdmin($target)
                    ? 'No se puede desactivar al ultimo administrador efectivo del sistema.'
                    : ($allowed ? 'Tiene permiso para desactivar este usuario y no se detecta una restriccion adicional.' : 'No tiene permiso efectivo para desactivar este usuario.')),
            CopilotActionType::Activate => $allowed
                ? 'Tiene permiso para activar este usuario.'
                : 'No tiene permiso efectivo para activar este usuario.',
            CopilotActionType::SendReset => $allowed
                ? 'Tiene permiso para enviar un restablecimiento a este usuario.'
                : 'No tiene permiso efectivo para enviar un restablecimiento a este usuario.',
            default => 'No tengo una explicacion deterministica para esta accion.',
        };

        return [
            'capability_key' => 'users.explain.action',
            'family' => 'explain',
            'outcome' => 'ok',
            'answer_facts' => [
                'subject' => ['id' => $subject->id, 'name' => $subject->name, 'email' => $subject->email],
                'target' => ['id' => $target->id, 'name' => $target->name, 'email' => $target->email],
                'action_type' => $actionType->value,
                'permission_label' => $permissionLabel,
                'allowed' => $allowed,
                'reason' => $reason,
            ],
            'cards' => [[
                'kind' => 'notice',
                'title' => 'Explicacion de accion',
                'summary' => null,
                'data' => [
                    'type' => 'action_explanation',
                    'subject' => ['id' => $subject->id, 'name' => $subject->name, 'email' => $subject->email],
                    'target' => ['id' => $target->id, 'name' => $target->name, 'email' => $target->email],
                    'action_type' => $actionType->value,
                    'permission_label' => $permissionLabel,
                    'allowed' => $allowed,
                    'reason' => $reason,
                ],
            ]],
            'actions' => [],
            'references' => [[
                'label' => 'Abrir perfil de usuario',
                'href' => route('system.users.show', ['user' => $target->id], absolute: false),
            ]],
            'snapshot_updates' => [
                'last_intent_family' => 'read_explain',
                'last_capability_key' => 'users.explain.action',
                'last_resolved_entity_type' => 'user',
                'last_resolved_entity_id' => $subject->id,
            ],
            'diagnostics' => [
                'executor' => 'users_capability_executor',
                'source_of_truth' => 'deterministic_backend',
            ],
        ];
    }

    /**
     * @return array{capability_key: string,family: 'list',outcome: 'ok',answer_facts: array<string,mixed>,cards: list<array<string,mixed>>,actions: list<array<string,mixed>>,references: list<array{label: string, href: string|null}>,snapshot_updates: array<string,mixed>,diagnostics: array<string,mixed>}
     */
    public function executeRolesCatalog(): array
    {
        $roles = Role::query()->active()->orderBy('display_name')->orderBy('name')->get(['id', 'name', 'display_name']);

        return [
            'capability_key' => 'users.roles.catalog',
            'family' => 'list',
            'outcome' => 'ok',
            'answer_facts' => [
                'roles' => $roles->map(fn (Role $role): array => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'display_name' => $role->display_name,
                ])->values()->all(),
            ],
            'cards' => [[
                'kind' => 'notice',
                'title' => 'Roles disponibles',
                'summary' => null,
                'data' => [
                    'type' => 'roles_catalog',
                    'roles' => $roles->map(fn (Role $role): array => [
                        'id' => $role->id,
                        'name' => $role->name,
                        'display_name' => $role->display_name,
                    ])->values()->all(),
                ],
            ]],
            'actions' => [],
            'references' => [[
                'label' => 'Roles',
                'href' => route('system.roles.index', absolute: false),
            ]],
            'snapshot_updates' => [
                'last_intent_family' => 'read_search',
                'last_capability_key' => 'users.roles.catalog',
            ],
            'diagnostics' => [
                'executor' => 'users_capability_executor',
                'source_of_truth' => 'deterministic_backend',
            ],
        ];
    }

    /**
     * @return array{capability_key: string,family: 'explain',outcome: 'ok',answer_facts: array<string,mixed>,cards: list<array<string,mixed>>,actions: list<array<string,mixed>>,references: list<array{label: string, href: string|null}>,snapshot_updates: array<string,mixed>,diagnostics: array<string,mixed>}
     */
    public function executeCapabilitiesSummary(User $actor, int $userId): array
    {
        $detail = json_decode((new GetUserDetailTool($actor))->handle(new ToolRequest([
            'user_id' => $userId,
            'include_access' => true,
        ])), true, 512, JSON_THROW_ON_ERROR);

        $groups = collect(Arr::wrap($detail['effective_permissions'] ?? []));
        $permissionNames = $groups->flatten(1)
            ->filter(static fn (mixed $permissionItem): bool => is_array($permissionItem))
            ->pluck('name')
            ->filter(static fn (mixed $name): bool => is_string($name))
            ->values()
            ->all();

        $highValueMap = [
            'system.users.view' => 'ver usuarios',
            'system.users.update' => 'editar usuarios',
            'system.users.deactivate' => 'desactivar usuarios',
            'system.users.send-reset' => 'enviar restablecimientos de contraseña',
            'system.users.assign-role' => 'asignar roles a usuarios',
            'system.roles.view' => 'ver roles',
            'system.roles.create' => 'crear roles',
            'system.roles.update' => 'editar roles',
        ];

        $allowed = [];
        $missing = [];

        foreach ($highValueMap as $permission => $label) {
            if (in_array($permission, $permissionNames, true)) {
                $allowed[] = $label;
            } else {
                $missing[] = $label;
            }
        }

        return [
            'capability_key' => 'users.explain.capabilities_summary',
            'family' => 'explain',
            'outcome' => 'ok',
            'answer_facts' => [
                'detail' => $detail,
                'allowed' => $allowed,
                'missing' => $missing,
            ],
            'cards' => [[
                'kind' => 'notice',
                'title' => 'Resumen de capacidades del usuario',
                'summary' => null,
                'data' => [
                    'type' => 'capabilities_summary',
                    'user' => $detail['user'] ?? null,
                    'roles' => $detail['roles'] ?? [],
                    'allowed' => $allowed,
                    'missing' => $missing,
                ],
            ]],
            'actions' => [],
            'references' => array_values(array_filter(Arr::wrap(data_get($detail, 'user.references', [])), static fn (mixed $reference): bool => is_array($reference))),
            'snapshot_updates' => [
                'last_intent_family' => 'read_explain',
                'last_capability_key' => 'users.explain.capabilities_summary',
                'last_resolved_entity_type' => ($detail['found'] ?? false) ? 'user' : null,
                'last_resolved_entity_id' => ($detail['found'] ?? false) ? ($detail['user']['id'] ?? null) : null,
            ],
            'diagnostics' => [
                'executor' => 'users_capability_executor',
                'source_of_truth' => 'deterministic_backend',
            ],
        ];
    }

    /**
     * @return array{
     *   capability_key: string,
     *   family: 'action',
     *   outcome: 'ok',
     *   answer_facts: array<string, mixed>,
     *   cards: list<array<string, mixed>>,
     *   actions: list<array<string, mixed>>,
     *   references: list<array{label: string, href: string|null}>,
     *   snapshot_updates: array<string, mixed>,
     *   diagnostics: array<string, mixed>
     * }
     */
    public function executeActionProposal(User $actor, string $capabilityKey, int $userId): array
    {
        $tool = match ($capabilityKey) {
            'users.actions.activate' => new ActivateUserTool($actor),
            'users.actions.deactivate' => new DeactivateUserTool($actor),
            'users.actions.send_reset' => new SendUserPasswordResetTool($actor),
            default => null,
        };

        if ($tool === null) {
            return [
                'capability_key' => $capabilityKey,
                'family' => 'action',
                'outcome' => 'ok',
                'answer_facts' => [],
                'cards' => [],
                'actions' => [],
                'references' => [],
                'snapshot_updates' => [],
                'diagnostics' => [
                    'executor' => 'users_capability_executor',
                    'reason' => 'unsupported_action_capability',
                ],
            ];
        }

        $payload = json_decode($tool->handle(new ToolRequest(['user_id' => $userId])), true, 512, JSON_THROW_ON_ERROR);
        $action = is_array($payload['action'] ?? null) ? $payload['action'] : null;

        return [
            'capability_key' => $capabilityKey,
            'family' => 'action',
            'outcome' => 'ok',
            'answer_facts' => $payload,
            'cards' => [],
            'actions' => $action === null ? [] : [$action],
            'references' => [[
                'label' => 'Usuarios',
                'href' => route('system.users.index', absolute: false),
            ]],
            'snapshot_updates' => [],
            'diagnostics' => [
                'executor' => 'users_capability_executor',
                'source_of_truth' => 'deterministic_backend',
            ],
        ];
    }

    /**
     * @param  array{query: ?string, status: 'active'|'inactive'|'all'|null, role: ?string, access_profile: ?string, email_verified: ?bool, has_roles: ?bool}  $filters
     * @return array{
     *   capability_key: string,
     *   family: 'mixed',
     *   outcome: 'ok',
     *   answer_facts: array<string, mixed>,
     *   cards: list<array<string, mixed>>,
     *   actions: list<array<string, mixed>>,
     *   references: list<array{label: string, href: string|null}>,
     *   snapshot_updates: array<string, mixed>,
     *   diagnostics: array<string, mixed>
     * }
     */
    public function executeMixedMetricsSearch(User $actor, array $filters): array
    {
        $metricsFacts = $this->resolveAggregateFacts('users.metrics.combined');
        $searchResults = $this->runSearch($actor, $filters);
        $matchingCount = is_numeric($searchResults['matching_count'] ?? null) ? (int) $searchResults['matching_count'] : 0;

        return [
            'capability_key' => 'users.mixed.metrics_search',
            'family' => 'mixed',
            'outcome' => 'ok',
            'answer_facts' => [
                'metric' => $metricsFacts['metric'] ?? [],
                'breakdown' => $metricsFacts['breakdown'] ?? [],
                'role_distribution' => $metricsFacts['role_distribution'] ?? [],
                'search_results' => $searchResults,
                'applied_filters' => array_filter($filters, static fn (mixed $value): bool => $value !== null && $value !== ''),
            ],
            'cards' => [
                [
                    'kind' => 'metrics',
                    'title' => $metricsFacts['metric']['label'] ?? null,
                    'summary' => $this->summaryFor('users.metrics.combined', $metricsFacts),
                    'data' => [
                        'capability_key' => 'users.metrics.combined',
                        ...$metricsFacts,
                    ],
                ],
                [
                    'kind' => 'search_results',
                    'title' => $this->mixedSearchTitle($filters),
                    'summary' => $this->mixedSearchSummary($filters, $searchResults),
                    'data' => $searchResults,
                ],
            ],
            'actions' => [],
            'references' => $this->mixedReferences($searchResults),
            'snapshot_updates' => [
                'last_intent_family' => 'read_search',
                'last_capability_key' => 'users.mixed.metrics_search',
                'last_result_count' => $matchingCount,
                'last_metrics_snapshot' => [
                    'capability_key' => 'users.metrics.combined',
                    ...$metricsFacts,
                ],
            ],
            'diagnostics' => [
                'executor' => 'users_capability_executor',
                'source_of_truth' => 'deterministic_backend',
                'search_results_source' => 'deterministic_backend',
                'composite' => 'metrics_plus_search',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function metricInputs(): array
    {
        return [
            'total',
            'active',
            'inactive',
            'admin_access',
            'with_roles',
            'without_roles',
            'verified',
            'unverified',
            'role_distribution',
            'most_common_role',
        ];
    }

    public static function capabilityKeyForMetric(string $metric): ?string
    {
        return match ($metric) {
            'total' => 'users.metrics.total',
            'active' => 'users.metrics.active',
            'inactive' => 'users.metrics.inactive',
            'admin_access' => 'users.metrics.admin_access',
            'with_roles' => 'users.metrics.with_roles',
            'without_roles' => 'users.metrics.without_roles',
            'verified' => 'users.metrics.verified',
            'unverified' => 'users.metrics.unverified',
            'role_distribution' => 'users.metrics.role_distribution',
            'most_common_role' => 'users.metrics.most_common_role',
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveAggregateFacts(string $capabilityKey): array
    {
        if ($capabilityKey === 'users.metrics.combined') {
            return $this->combinedMetricsFacts();
        }

        return match ($capabilityKey) {
            'users.metrics.total' => $this->metricsAggregator->total(),
            'users.metrics.active' => $this->metricsAggregator->active(),
            'users.metrics.inactive' => $this->metricsAggregator->inactive(),
            'users.metrics.admin_access' => $this->metricsAggregator->administrativeAccess(),
            'users.metrics.with_roles' => $this->metricsAggregator->withRoles(),
            'users.metrics.without_roles' => $this->metricsAggregator->withoutRoles(),
            'users.metrics.verified' => $this->metricsAggregator->verified(),
            'users.metrics.unverified' => $this->metricsAggregator->unverified(),
            'users.metrics.role_distribution' => $this->metricsAggregator->roleDistribution(),
            'users.metrics.most_common_role' => $this->metricsAggregator->mostCommonRole(),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $answerFacts
     */
    protected function summaryFor(string $capabilityKey, array $answerFacts): string
    {
        $metric = is_array($answerFacts['metric'] ?? null) ? $answerFacts['metric'] : [];
        $value = is_numeric($metric['value'] ?? null) ? (int) $metric['value'] : 0;
        $label = is_string($metric['label'] ?? null) ? $metric['label'] : 'Metrica';

        if ($capabilityKey === 'users.metrics.combined') {
            $breakdown = is_array($answerFacts['breakdown'] ?? null) ? $answerFacts['breakdown'] : [];
            $active = is_numeric($breakdown['active'] ?? null) ? (int) $breakdown['active'] : 0;
            $inactive = is_numeric($breakdown['inactive'] ?? null) ? (int) $breakdown['inactive'] : 0;
            $mostCommon = is_string($breakdown['most_common_role'] ?? null) ? $breakdown['most_common_role'] : 'Ninguno';
            $mostCommonCount = is_numeric($breakdown['most_common_role_count'] ?? null) ? (int) $breakdown['most_common_role_count'] : 0;

            return "Total: {$value}. Activos: {$active}. Inactivos: {$inactive}. Rol mas comun: {$mostCommon} ({$mostCommonCount} usuarios).";
        }

        return match ($capabilityKey) {
            'users.metrics.role_distribution' => $value === 0
                ? 'No hay roles asignados a usuarios en este momento.'
                : "Hay {$value} roles con usuarios asignados. La distribucion exacta esta en el desglose.",
            'users.metrics.most_common_role' => $value === 0
                ? 'Ningun rol tiene usuarios asignados actualmente.'
                : "{$label} es el rol mas comun con {$value} usuarios asignados.",
            'users.metrics.admin_access' => "Hay {$value} usuarios con acceso administrativo efectivo.",
            default => "{$label}: {$value}.",
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function combinedMetricsFacts(): array
    {
        $total = $this->metricsAggregator->total();
        $active = $this->metricsAggregator->active();
        $inactive = $this->metricsAggregator->inactive();
        $adminAccess = $this->metricsAggregator->administrativeAccess();
        $mostCommon = $this->metricsAggregator->mostCommonRole();
        $distribution = $this->metricsAggregator->roleDistribution();

        $totalValue = is_numeric($total['metric']['value'] ?? null) ? (int) $total['metric']['value'] : 0;
        $activeValue = is_numeric($active['metric']['value'] ?? null) ? (int) $active['metric']['value'] : 0;
        $inactiveValue = is_numeric($inactive['metric']['value'] ?? null) ? (int) $inactive['metric']['value'] : 0;
        $adminAccessValue = is_numeric($adminAccess['metric']['value'] ?? null) ? (int) $adminAccess['metric']['value'] : 0;
        $mostCommonLabel = is_string($mostCommon['metric']['label'] ?? null) ? $mostCommon['metric']['label'] : 'Ninguno';
        $mostCommonValue = is_numeric($mostCommon['metric']['value'] ?? null) ? (int) $mostCommon['metric']['value'] : 0;

        return [
            'metric' => [
                'label' => 'Resumen combinado de usuarios',
                'value' => $totalValue,
                'unit' => 'users',
            ],
            'breakdown' => [
                'total' => $totalValue,
                'active' => $activeValue,
                'inactive' => $inactiveValue,
                'admin_access' => $adminAccessValue,
                'most_common_role' => $mostCommonLabel,
                'most_common_role_count' => $mostCommonValue,
            ],
            'role_distribution' => $distribution['distribution'] ?? [],
        ];
    }

    /**
     * @param  array{query: ?string, status: 'active'|'inactive'|'all'|null, role: ?string, access_profile: ?string, email_verified: ?bool, has_roles: ?bool}  $filters
     * @return array<string, mixed>
     */
    protected function runSearch(User $actor, array $filters): array
    {
        $arguments = array_filter([
            'query' => $filters['query'] ?? null,
            'status' => $filters['status'] ?? 'all',
            'role' => $filters['role'] ?? null,
            'access_profile' => $filters['access_profile'] ?? null,
            'permission' => $filters['permission'] ?? null,
            'email_verified' => $filters['email_verified'] ?? null,
            'two_factor_enabled' => $filters['two_factor_enabled'] ?? null,
            'has_roles' => $filters['has_roles'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);

        return json_decode((new SearchUsersTool($actor))->handle(new ToolRequest($arguments)), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array{query: ?string, status: 'active'|'inactive'|'all'|null, role: ?string, access_profile: ?string, email_verified: ?bool, has_roles: ?bool}  $filters
     */
    protected function mixedSearchTitle(array $filters): string
    {
        return $this->searchTitle($filters);
    }

    /**
     * @param  array{query: ?string, status: 'active'|'inactive'|'all'|null, role: ?string, access_profile: ?string, email_verified: ?bool, has_roles: ?bool}  $filters
     */
    protected function searchTitle(array $filters): string
    {
        if (is_string($filters['permission'] ?? null) && $filters['permission'] !== '') {
            return 'Usuarios por permiso efectivo';
        }

        if (($filters['access_profile'] ?? null) === 'administrative_access') {
            return 'Usuarios con acceso administrativo';
        }

        if (($filters['access_profile'] ?? null) === 'super_admin_role') {
            return 'Usuarios con rol super-admin';
        }

        if (is_string($filters['role'] ?? null) && $filters['role'] !== '') {
            return 'Usuarios por rol';
        }

        if (($filters['status'] ?? null) === 'inactive') {
            return 'Usuarios inactivos';
        }

        if (($filters['status'] ?? null) === 'active') {
            return 'Usuarios activos';
        }

        return 'Usuarios filtrados';
    }

    /**
     * @param  array{query: ?string, status: 'active'|'inactive'|'all'|null, role: ?string, access_profile: ?string, email_verified: ?bool, has_roles: ?bool}  $filters
     * @param  array<string, mixed>  $searchResults
     */
    protected function mixedSearchSummary(array $filters, array $searchResults): string
    {
        return $this->searchSummary($filters, $searchResults);
    }

    /**
     * @param  array{query: ?string, status: 'active'|'inactive'|'all'|null, role: ?string, access_profile: ?string, email_verified: ?bool, has_roles: ?bool}  $filters
     * @param  array<string, mixed>  $searchResults
     */
    protected function searchSummary(array $filters, array $searchResults): string
    {
        $matchingCount = is_numeric($searchResults['matching_count'] ?? null) ? (int) $searchResults['matching_count'] : 0;

        if (is_string($filters['permission'] ?? null) && $filters['permission'] !== '') {
            $permissionLabel = UsersCopilotDomainLexicon::permissionLabel((string) $filters['permission']);

            return $matchingCount === 0
                ? "No hay usuarios con el permiso efectivo para {$permissionLabel}."
                : "Se encontraron {$matchingCount} usuarios que pueden {$permissionLabel}.";
        }

        if (($filters['access_profile'] ?? null) === 'administrative_access') {
            return $matchingCount === 0
                ? 'No hay usuarios con acceso administrativo efectivo.'
                : "Se encontraron {$matchingCount} usuarios con acceso administrativo efectivo.";
        }

        if (($filters['access_profile'] ?? null) === 'super_admin_role') {
            return $matchingCount === 0
                ? 'No hay usuarios con el rol super-admin.'
                : "Se encontraron {$matchingCount} usuarios con el rol super-admin.";
        }

        if (is_string($filters['role'] ?? null) && $filters['role'] !== '') {
            $role = (string) $filters['role'];

            return $matchingCount === 0
                ? "No hay usuarios con el rol {$role}."
                : "Se encontraron {$matchingCount} usuarios con el rol {$role}.";
        }

        return $matchingCount === 0
            ? 'No hubo coincidencias para el listado solicitado.'
            : "Se encontraron {$matchingCount} usuarios para el listado solicitado.";
    }

    /**
     * @param  array<string, mixed>  $searchResults
     * @return list<array{label: string, href: string|null}>
     */
    protected function mixedReferences(array $searchResults): array
    {
        $userReferences = collect(Arr::wrap($searchResults['users'] ?? []))
            ->filter(static fn (mixed $user): bool => is_array($user))
            ->take(3)
            ->map(static fn (array $user): array => [
                'label' => (string) ($user['name'] ?? 'Abrir usuario'),
                'href' => is_string($user['show_href'] ?? null) ? $user['show_href'] : null,
            ])
            ->values()
            ->all();

        return [
            ...$this->references(),
            ...$userReferences,
        ];
    }

    /**
     * @return list<array{label: string, href: string|null}>
     */
    protected function references(): array
    {
        return [[
            'label' => 'Usuarios',
            'href' => route('system.users.index', absolute: false),
        ]];
    }
}
