<?php

namespace App\Ai\Services;

class UsersCopilotCapabilityCatalog
{
    /**
     * @return array<string, array{
     *   key: string,
     *   family: 'aggregate'|'list'|'detail'|'action'|'help'|'clarification'|'mixed'|'explain'|'denied'|'continuation',
     *   intent_family: 'read_metrics'|'read_search'|'read_detail'|'action_proposal'|'help'|'ambiguous'|'read_explain'|'denied'|'continuation_confirm',
     *   response_intent: 'metrics'|'search_results'|'user_context'|'action_proposal'|'help'|'ambiguous'|'notice'|'denied'|'continuation_confirm',
     *   requires_entity: bool,
     *   supports_follow_up: bool,
     *   required_filters: list<string>,
     *   follow_up_affordances: list<string>
     * }>
     */
    public static function all(): array
    {
        return [
            'users.metrics.total' => [
                'key' => 'users.metrics.total',
                'family' => 'aggregate',
                'intent_family' => 'read_metrics',
                'response_intent' => 'metrics',
                'requires_entity' => false,
                'supports_follow_up' => true,
                'required_filters' => [],
                'follow_up_affordances' => ['refine_subset', 'compare_status'],
            ],
            'users.metrics.active' => [
                'key' => 'users.metrics.active',
                'family' => 'aggregate',
                'intent_family' => 'read_metrics',
                'response_intent' => 'metrics',
                'requires_entity' => false,
                'supports_follow_up' => true,
                'required_filters' => ['status'],
                'follow_up_affordances' => ['refine_subset', 'compare_status'],
            ],
            'users.metrics.inactive' => [
                'key' => 'users.metrics.inactive',
                'family' => 'aggregate',
                'intent_family' => 'read_metrics',
                'response_intent' => 'metrics',
                'requires_entity' => false,
                'supports_follow_up' => true,
                'required_filters' => ['status'],
                'follow_up_affordances' => ['refine_subset', 'compare_status'],
            ],
            'users.metrics.with_roles' => [
                'key' => 'users.metrics.with_roles',
                'family' => 'aggregate',
                'intent_family' => 'read_metrics',
                'response_intent' => 'metrics',
                'requires_entity' => false,
                'supports_follow_up' => true,
                'required_filters' => ['has_roles'],
                'follow_up_affordances' => ['refine_subset'],
            ],
            'users.metrics.without_roles' => [
                'key' => 'users.metrics.without_roles',
                'family' => 'aggregate',
                'intent_family' => 'read_metrics',
                'response_intent' => 'metrics',
                'requires_entity' => false,
                'supports_follow_up' => true,
                'required_filters' => ['has_roles'],
                'follow_up_affordances' => ['refine_subset'],
            ],
            'users.metrics.verified' => [
                'key' => 'users.metrics.verified',
                'family' => 'aggregate',
                'intent_family' => 'read_metrics',
                'response_intent' => 'metrics',
                'requires_entity' => false,
                'supports_follow_up' => true,
                'required_filters' => ['email_verified'],
                'follow_up_affordances' => ['refine_subset'],
            ],
            'users.metrics.unverified' => [
                'key' => 'users.metrics.unverified',
                'family' => 'aggregate',
                'intent_family' => 'read_metrics',
                'response_intent' => 'metrics',
                'requires_entity' => false,
                'supports_follow_up' => true,
                'required_filters' => ['email_verified'],
                'follow_up_affordances' => ['refine_subset'],
            ],
            'users.metrics.role_distribution' => [
                'key' => 'users.metrics.role_distribution',
                'family' => 'aggregate',
                'intent_family' => 'read_metrics',
                'response_intent' => 'metrics',
                'requires_entity' => false,
                'supports_follow_up' => true,
                'required_filters' => ['role'],
                'follow_up_affordances' => ['refine_subset', 'compare_status'],
            ],
            'users.metrics.most_common_role' => [
                'key' => 'users.metrics.most_common_role',
                'family' => 'aggregate',
                'intent_family' => 'read_metrics',
                'response_intent' => 'metrics',
                'requires_entity' => false,
                'supports_follow_up' => true,
                'required_filters' => ['role'],
                'follow_up_affordances' => ['refine_subset', 'compare_status'],
            ],
            'users.metrics.admin_access' => [
                'key' => 'users.metrics.admin_access',
                'family' => 'aggregate',
                'intent_family' => 'read_metrics',
                'response_intent' => 'metrics',
                'requires_entity' => false,
                'supports_follow_up' => true,
                'required_filters' => ['access_profile'],
                'follow_up_affordances' => ['refine_subset', 'compare_status'],
            ],
            'users.search' => [
                'key' => 'users.search',
                'family' => 'list',
                'intent_family' => 'read_search',
                'response_intent' => 'search_results',
                'requires_entity' => false,
                'supports_follow_up' => true,
                'required_filters' => ['query', 'status', 'role', 'email_verified', 'has_roles'],
                'follow_up_affordances' => ['refine_subset', 'show_more', 'count_visible_results'],
            ],
            'users.mixed.metrics_search' => [
                'key' => 'users.mixed.metrics_search',
                'family' => 'mixed',
                'intent_family' => 'read_search',
                'response_intent' => 'search_results',
                'requires_entity' => false,
                'supports_follow_up' => true,
                'required_filters' => ['query', 'status', 'role', 'email_verified', 'has_roles'],
                'follow_up_affordances' => ['refine_subset', 'show_more', 'count_visible_results'],
            ],
            'users.detail' => [
                'key' => 'users.detail',
                'family' => 'detail',
                'intent_family' => 'read_detail',
                'response_intent' => 'user_context',
                'requires_entity' => true,
                'supports_follow_up' => true,
                'required_filters' => ['user_id'],
                'follow_up_affordances' => ['propose_action', 'expand_access'],
            ],
            'users.explain.permission' => [
                'key' => 'users.explain.permission',
                'family' => 'explain',
                'intent_family' => 'read_explain',
                'response_intent' => 'notice',
                'requires_entity' => true,
                'supports_follow_up' => true,
                'required_filters' => ['user_id', 'permission'],
                'follow_up_affordances' => ['expand_access'],
            ],
            'users.explain.action' => [
                'key' => 'users.explain.action',
                'family' => 'explain',
                'intent_family' => 'read_explain',
                'response_intent' => 'notice',
                'requires_entity' => true,
                'supports_follow_up' => true,
                'required_filters' => ['user_id', 'target_user_id', 'action_type'],
                'follow_up_affordances' => ['expand_access'],
            ],
            'users.explain.capabilities_summary' => [
                'key' => 'users.explain.capabilities_summary',
                'family' => 'explain',
                'intent_family' => 'read_explain',
                'response_intent' => 'notice',
                'requires_entity' => true,
                'supports_follow_up' => true,
                'required_filters' => ['user_id'],
                'follow_up_affordances' => ['expand_access'],
            ],
            'users.roles.catalog' => [
                'key' => 'users.roles.catalog',
                'family' => 'list',
                'intent_family' => 'read_search',
                'response_intent' => 'help',
                'requires_entity' => false,
                'supports_follow_up' => false,
                'required_filters' => [],
                'follow_up_affordances' => [],
            ],
            'users.actions.activate' => [
                'key' => 'users.actions.activate',
                'family' => 'action',
                'intent_family' => 'action_proposal',
                'response_intent' => 'action_proposal',
                'requires_entity' => true,
                'supports_follow_up' => false,
                'required_filters' => ['user_id'],
                'follow_up_affordances' => [],
            ],
            'users.actions.deactivate' => [
                'key' => 'users.actions.deactivate',
                'family' => 'action',
                'intent_family' => 'action_proposal',
                'response_intent' => 'action_proposal',
                'requires_entity' => true,
                'supports_follow_up' => false,
                'required_filters' => ['user_id'],
                'follow_up_affordances' => [],
            ],
            'users.actions.send_reset' => [
                'key' => 'users.actions.send_reset',
                'family' => 'action',
                'intent_family' => 'action_proposal',
                'response_intent' => 'action_proposal',
                'requires_entity' => true,
                'supports_follow_up' => false,
                'required_filters' => ['user_id'],
                'follow_up_affordances' => [],
            ],
            'users.actions.create_user' => [
                'key' => 'users.actions.create_user',
                'family' => 'action',
                'intent_family' => 'action_proposal',
                'response_intent' => 'action_proposal',
                'requires_entity' => false,
                'supports_follow_up' => false,
                'required_filters' => ['name', 'email', 'roles'],
                'follow_up_affordances' => [],
            ],
            'users.metrics.combined' => [
                'key' => 'users.metrics.combined',
                'family' => 'aggregate',
                'intent_family' => 'read_metrics',
                'response_intent' => 'metrics',
                'requires_entity' => false,
                'supports_follow_up' => true,
                'required_filters' => [],
                'follow_up_affordances' => ['refine_subset', 'compare_status'],
            ],
            'users.help' => [
                'key' => 'users.help',
                'family' => 'help',
                'intent_family' => 'help',
                'response_intent' => 'help',
                'requires_entity' => false,
                'supports_follow_up' => false,
                'required_filters' => [],
                'follow_up_affordances' => [],
            ],
            'users.help.informational' => [
                'key' => 'users.help.informational',
                'family' => 'help',
                'intent_family' => 'help',
                'response_intent' => 'help',
                'requires_entity' => false,
                'supports_follow_up' => false,
                'required_filters' => [],
                'follow_up_affordances' => [],
            ],
            'users.help.unknown' => [
                'key' => 'users.help.unknown',
                'family' => 'help',
                'intent_family' => 'help',
                'response_intent' => 'help',
                'requires_entity' => false,
                'supports_follow_up' => false,
                'required_filters' => [],
                'follow_up_affordances' => [],
            ],
            'users.clarification' => [
                'key' => 'users.clarification',
                'family' => 'clarification',
                'intent_family' => 'ambiguous',
                'response_intent' => 'ambiguous',
                'requires_entity' => false,
                'supports_follow_up' => true,
                'required_filters' => [],
                'follow_up_affordances' => ['clarify_target', 'clarify_scope'],
            ],
            'users.denied' => [
                'key' => 'users.denied',
                'family' => 'denied',
                'intent_family' => 'denied',
                'response_intent' => 'denied',
                'requires_entity' => false,
                'supports_follow_up' => false,
                'required_filters' => [],
                'follow_up_affordances' => [],
            ],
            'users.continuation.confirm' => [
                'key' => 'users.continuation.confirm',
                'family' => 'continuation',
                'intent_family' => 'continuation_confirm',
                'response_intent' => 'continuation_confirm',
                'requires_entity' => false,
                'supports_follow_up' => true,
                'required_filters' => [],
                'follow_up_affordances' => ['confirm_continuation', 'start_fresh'],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * @return array{
     *   key: string,
     *   family: 'aggregate'|'list'|'detail'|'action'|'help'|'clarification'|'mixed'|'explain',
     *   intent_family: 'read_metrics'|'read_search'|'read_detail'|'action_proposal'|'help'|'ambiguous'|'read_explain',
     *   response_intent: 'metrics'|'search_results'|'user_context'|'action_proposal'|'help'|'ambiguous'|'notice',
     *   requires_entity: bool,
     *   supports_follow_up: bool,
     *   required_filters: list<string>,
     *   follow_up_affordances: list<string>
     * }|null
     */
    public static function find(?string $key): ?array
    {
        if ($key === null || $key === '') {
            return null;
        }

        return self::all()[$key] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function aggregateKeys(): array
    {
        return array_values(array_keys(array_filter(
            self::all(),
            static fn (array $definition): bool => $definition['family'] === 'aggregate',
        )));
    }

    /**
     * Schema tipado de filters para validación de valores retornados por LLM.
     * Fuente de verdad única para tipos de filtros por capability.
     *
     * @return array<string, array{type: string, values?: list<string>}>
     */
    public static function filterSchema(): array
    {
        return [
            'query' => ['type' => 'string'],
            'status' => ['type' => 'enum', 'values' => ['active', 'inactive']],
            'role' => ['type' => 'string'],
            'email_verified' => ['type' => 'boolean'],
            'has_roles' => ['type' => 'boolean'],
            'user_id' => ['type' => 'integer'],
            'target_user_id' => ['type' => 'integer'],
            'permission' => ['type' => 'string'],
            'action_type' => ['type' => 'enum', 'values' => ['activate', 'deactivate', 'send_reset', 'create_user']],
            'access_profile' => ['type' => 'string'],
            'name' => ['type' => 'string'],
            'email' => ['type' => 'string'],
            'roles' => ['type' => 'array', 'items' => 'string'],
        ];
    }

    /**
     * Valida que un valor de filtro coincida con el tipo esperado.
     *
     * @param  string  $filterKey  Clave del filtro
     * @param  mixed  $value  Valor a validar
     * @return bool True si el valor es válido para el tipo esperado
     */
    public static function isValidFilterValue(string $filterKey, mixed $value): bool
    {
        $schema = self::filterSchema()[$filterKey] ?? null;

        if ($schema === null) {
            return false; // Filtro no reconocido
        }

        return match ($schema['type']) {
            'string' => is_string($value),
            'integer' => is_int($value) || (is_string($value) && ctype_digit($value)),
            'boolean' => is_bool($value) || in_array($value, [true, false, 1, 0, '1', '0'], true),
            'array' => is_array($value),
            'enum' => is_string($value) && in_array($value, $schema['values'] ?? [], true),
            default => false,
        };
    }
}
