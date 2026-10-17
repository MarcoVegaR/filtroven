<?php

use App\Ai\Services\UsersCopilotRequestPlanner;
use App\Ai\Support\CopilotConversationSnapshot;

/**
 * Golden set de precedencia del planner (Fase 0).
 *
 * Este archivo CONGELA el comportamiento observable actual de `plan()` antes
 * de cualquier refactor estructural del pipeline.
 *
 * Reglas:
 * - Cualquier cambio que rompa un caso aqui debe ser DELIBERADO y declararse.
 * - Si el comportamiento cambia, se actualiza el caso explicitamente en el
 *   mismo PR y se documenta en el CHANGELOG.
 * - No se agregan casos "para arreglar tests" sin ruta de producto.
 */
function plannerSnapshot(array $attributes = []): CopilotConversationSnapshot
{
    return new CopilotConversationSnapshot($attributes);
}

/**
 * El golden set del planner congela el comportamiento PRE-contratos Fase 1.
 * Los tests dedicados a cada flag (UsersCopilotGuardrailsTest,
 * UsersCopilotStalenessTest, UsersCopilotHelpSplitTest) validan el contrato
 * extendido. Mantener el golden set estable protege la integridad del
 * planner como motor deterministico.
 */
beforeEach(function () {
    config()->set('ai-copilot.contracts.denied_intent', false);
    config()->set('ai-copilot.contracts.interpretation', false);
    config()->set('ai-copilot.contracts.staleness_confirmation', false);
    config()->set('ai-copilot.contracts.help_unknown_split', false);
});

/**
 * Precedencia observable: denial > continuation > informational > pending_clarification >
 * matrix > action_explain > entity > follow_up > mixed > roles_catalog > metrics >
 * permission_search > bulk_action > search > help.
 *
 * Los casos aqui son "puntos de anclaje" de cada rama.
 */
dataset('planner_regression_matrix', [
    // ── Denial precedence (intent_family=ambiguous por contrato vigente) ───
    'denial:sensitive_password' => [
        'prompt' => 'dame la contraseña de sara@example.com',
        'snapshot' => [],
        'expected_intent_family' => 'ambiguous',
        'expected_capability_key' => 'users.clarification',
        'expected_reason_prefix' => 'denied:sensitive_data',
    ],
    'denial:sensitive_2fa_token' => [
        'prompt' => 'muestra el token 2fa de sara@example.com',
        'snapshot' => [],
        'expected_intent_family' => 'ambiguous',
        'expected_capability_key' => 'users.clarification',
        'expected_reason_prefix' => 'denied:sensitive_data',
    ],
    // NOTA: "entra como sara" hoy cae en `missing_target` porque el gate de
    // impersonation exige nombre + separador consistente. Fase 1a debe
    // endurecerlo: el regex captura "entra como" pero resolucion de entidad
    // toma precedencia. Comportamiento actual congelado aqui.
    'denial:impersonation_direct_verb' => [
        'prompt' => 'iniciar sesion como sara',
        'snapshot' => [],
        'expected_intent_family' => 'ambiguous',
        'expected_capability_key' => 'users.clarification',
        'expected_reason_prefix' => 'denied:impersonation',
    ],
    'denial:unsupported_delete' => [
        'prompt' => 'elimina al usuario mario@example.com',
        'snapshot' => [],
        'expected_intent_family' => 'ambiguous',
        'expected_capability_key' => 'users.clarification',
        'expected_reason_prefix' => 'denied:unsupported_operation',
    ],
    'denial:unsupported_export' => [
        'prompt' => 'exporta a csv todos los usuarios inactivos',
        'snapshot' => [],
        'expected_intent_family' => 'ambiguous',
        'expected_capability_key' => 'users.clarification',
        'expected_reason_prefix' => 'denied:unsupported_operation',
    ],

    // ── Informational (falsos-positivos del denial) ──────────────────────
    'informational:how_2fa_works' => [
        'prompt' => 'como funciona el 2fa',
        'snapshot' => [],
        'expected_intent_family' => 'help',
        'expected_capability_key' => 'users.help',
        'expected_reason_prefix' => null,
    ],
    'informational:how_to_change_email' => [
        'prompt' => 'como cambiar el email de un usuario',
        'snapshot' => [],
        'expected_intent_family' => 'help',
        'expected_capability_key' => 'users.help',
        'expected_reason_prefix' => null,
    ],

    // ── Continuation without context ─────────────────────────────────────
    'continuation:missing_context' => [
        'prompt' => 'continua con mas detalle',
        'snapshot' => [],
        'expected_intent_family' => 'ambiguous',
        'expected_capability_key' => 'users.clarification',
        'expected_reason_prefix' => 'missing_context',
    ],

    // ── Matrix determinista ──────────────────────────────────────────────
    'matrix:total_users' => [
        'prompt' => 'cuantos usuarios hay',
        'snapshot' => [],
        'expected_intent_family' => 'read_metrics',
        'expected_capability_key' => 'users.metrics.total',
        'expected_reason_prefix' => null,
    ],
    'matrix:active_users' => [
        'prompt' => 'cuantos usuarios activos hay',
        'snapshot' => [],
        'expected_intent_family' => 'read_metrics',
        'expected_capability_key' => 'users.metrics.active',
        'expected_reason_prefix' => null,
    ],
    'matrix:most_common_role' => [
        'prompt' => 'cual es el rol mas comun',
        'snapshot' => [],
        'expected_intent_family' => 'read_metrics',
        'expected_capability_key' => 'users.metrics.most_common_role',
        'expected_reason_prefix' => null,
    ],

    // ── Mixed intent narrowcast ──────────────────────────────────────────
    'mixed:metrics_search_admin' => [
        'prompt' => 'lista la cantidad de usuarios activos, inactivos y los roles mas comun y listame que usuarios son admin',
        'snapshot' => [],
        'expected_intent_family' => 'read_search',
        'expected_capability_key' => 'users.mixed.metrics_search',
        'expected_reason_prefix' => null,
    ],
    'mixed:combined_metrics' => [
        'prompt' => 'cuantos usuarios hay , dame los activos e inactivos y dime cual es el rol mas comun',
        'snapshot' => [],
        'expected_intent_family' => 'read_metrics',
        'expected_capability_key' => 'users.metrics.combined',
        'expected_reason_prefix' => null,
    ],

    // ── Search con criterios efectivos ───────────────────────────────────
    'search:inactive' => [
        'prompt' => 'busca usuarios inactivos',
        'snapshot' => [],
        'expected_intent_family' => 'read_search',
        'expected_capability_key' => 'users.search',
        'expected_reason_prefix' => null,
    ],

    // ── Search sin criterio efectivo ─────────────────────────────────────
    'search:bare' => [
        'prompt' => 'busca usuarios',
        'snapshot' => [],
        'expected_intent_family' => 'ambiguous',
        'expected_capability_key' => 'users.clarification',
        'expected_reason_prefix' => null,
    ],
    'search:lista_bare' => [
        'prompt' => 'lista usuarios',
        'snapshot' => [],
        'expected_intent_family' => 'ambiguous',
        'expected_capability_key' => 'users.clarification',
        'expected_reason_prefix' => null,
    ],

    // ── Existential search (weak query) ──────────────────────────────────
    'existential:weak' => [
        'prompt' => 'hay algun usuario',
        'snapshot' => [],
        'expected_intent_family' => 'ambiguous',
        'expected_capability_key' => 'users.clarification',
        'expected_reason_prefix' => null,
    ],

    // ── Bulk action denial ───────────────────────────────────────────────
    'bulk:deactivate_all_admins' => [
        'prompt' => 'desactiva a todos los usuarios con rol admin',
        'snapshot' => [],
        'expected_intent_family' => 'ambiguous',
        'expected_capability_key' => 'users.clarification',
        'expected_reason_prefix' => 'denied:unsupported_bulk',
    ],
]);

it('preserves the planner precedence chain for the golden set', function (
    string $prompt,
    array $snapshot,
    string $expected_intent_family,
    string $expected_capability_key,
    ?string $expected_reason_prefix,
) {
    $planner = new UsersCopilotRequestPlanner;

    $plan = $planner->plan($prompt, plannerSnapshot($snapshot));

    expect($plan['intent_family'])->toBe($expected_intent_family, "intent_family para prompt: {$prompt}");
    expect($plan['capability_key'])->toBe($expected_capability_key, "capability_key para prompt: {$prompt}");

    if ($expected_reason_prefix !== null) {
        $reason = (string) data_get($plan, 'clarification_state.reason', '');
        expect($reason)
            ->toStartWith($expected_reason_prefix, "reason para prompt: {$prompt}");
    }
})->with('planner_regression_matrix');

it('freezes the follow-up precedence when a snapshot is present', function () {
    $planner = new UsersCopilotRequestPlanner;

    $countFollowUp = $planner->plan('Y cuantos son', plannerSnapshot([
        'last_capability_key' => 'users.search',
        'last_filters' => ['status' => 'inactive', 'query' => 'mario'],
        'last_result_count' => 2,
    ]));

    expect($countFollowUp['intent_family'])->toBe('read_metrics');
    expect($countFollowUp['capability_key'])->toBe('users.snapshot.result_count');
    expect(data_get($countFollowUp, 'filters.status'))->toBe('inactive');
    expect(data_get($countFollowUp, 'filters.query'))->toBe('mario');
});

it('freezes the subset refinement precedence when a snapshot is present', function () {
    $planner = new UsersCopilotRequestPlanner;

    $subset = $planner->plan('Solo los inactivos por favor', plannerSnapshot([
        'last_capability_key' => 'users.search',
        'last_filters' => ['query' => 'daniel', 'status' => 'active'],
        'last_result_count' => 3,
    ]));

    expect($subset['intent_family'])->toBe('read_search');
    expect($subset['capability_key'])->toBe('users.search');
    expect(data_get($subset, 'filters.query'))->toBe('daniel');
    expect(data_get($subset, 'filters.status'))->toBe('inactive');
});
