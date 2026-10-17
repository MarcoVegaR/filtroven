<?php

use App\Ai\Agents\System\UsersCopilotAgent;
use App\Ai\Agents\System\UsersGeminiCopilotAgent;
use App\Ai\Support\CopilotConversationSnapshot;
use App\Ai\Testing\BrowserCopilotFakeTransport;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessModulePermissionsSeeder;
use Database\Seeders\AiCopilotPermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\Support\CopilotBrowserFake;

beforeEach(function () {
    CopilotBrowserFake::clear();

    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(AccessModulePermissionsSeeder::class);
    $this->seed(AiCopilotPermissionsSeeder::class);

    config(['ai-copilot.enabled' => true]);
    config()->set('ai-copilot.providers.default', 'openai');
    config()->set('ai-copilot.model', 'gpt-5.4');
});

afterEach(function () {
    CopilotBrowserFake::clear();
});

function authorizedCopilotOperator(): User
{
    $role = Role::factory()->active()->create();
    $role->syncPermissions(['system.users.view', 'system.users-copilot.view']);

    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole($role);

    return $user;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function fakeCopilotResponse(array $overrides = []): array
{
    return array_replace_recursive([
        'answer' => 'Encontré usuarios inactivos para revisar.',
        'intent' => 'search_results',
        'cards' => [
            [
                'kind' => 'search_results',
                'title' => 'Usuarios inactivos',
                'summary' => 'Hay 1 usuario inactivo.',
                'data' => [
                    'count' => 1,
                    'users' => [],
                ],
            ],
        ],
        'actions' => [],
        'requires_confirmation' => false,
        'references' => [],
        'meta' => [
            'module' => 'users',
            'channel' => 'web',
            'subject_user_id' => null,
            'fallback' => false,
            'diagnostics' => null,
        ],
    ], $overrides);
}

it('registers the copilot message route with throttle middleware', function () {
    $route = app('router')->getRoutes()->getByName('system.users.copilot.messages');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('system/users/copilot/messages')
        ->and($route->methods())->toContain('POST')
        ->and($route->gatherMiddleware())->toContain('throttle:users-copilot-messages');
});

it('registers the copilot action route', function () {
    $route = app('router')->getRoutes()->getByName('system.users.copilot.actions');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('system/users/copilot/actions/{actionType}')
        ->and($route->methods())->toContain('POST');
});

it('returns 403 for a user without copilot view permission on the message endpoint', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Necesito ayuda con usuarios',
        ])
        ->assertForbidden();
});

it('returns validation errors for oversized prompts before controller execution', function () {
    config(['ai-copilot.limits.prompt_length' => 20]);

    $user = authorizedCopilotOperator();

    $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => str_repeat('a', 21),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['prompt']);
});

it('returns a real read-only message envelope and persists the conversation', function () {
    $user = authorizedCopilotOperator();

    UsersCopilotAgent::fake([
        fakeCopilotResponse(),
    ])->preventStrayPrompts();

    $response = $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Busca usuarios inactivos.',
        ]);

    $response->assertSuccessful()
        ->assertJsonPath('response.intent', 'search_results')
        ->assertJsonPath('response.requires_confirmation', false)
        ->assertJsonPath('response.meta.fallback', false);

    $conversationId = $response->json('conversation_id');

    expect($conversationId)->not->toBeEmpty()
        ->and(DB::table('agent_conversations')->where('id', $conversationId)->value('user_id'))->toBe($user->id)
        ->and(DB::table('agent_conversations')->where('id', $conversationId)->value('title'))->toBe('Busca usuarios inactivos.')
        ->and(DB::table('agent_conversations')->where('id', $conversationId)->value('snapshot_version'))->toBe(CopilotConversationSnapshot::VERSION)
        ->and(json_decode((string) DB::table('agent_conversations')->where('id', $conversationId)->value('snapshot'), true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray([
            'last_filters' => ['status' => 'inactive'],
            'last_result_user_ids' => [],
            'conversation_state_version' => CopilotConversationSnapshot::VERSION,
        ])
        ->and(DB::table('agent_conversation_messages')->where('conversation_id', $conversationId)->count())->toBe(2);

    UsersCopilotAgent::assertNeverPrompted();
});

it('binds native aggregate prompts to deterministic metrics planning instead of search semantics', function () {
    $user = authorizedCopilotOperator();

    User::factory()->count(2)->active()->create();
    User::factory()->inactive()->create();

    UsersCopilotAgent::fake([
        fakeCopilotResponse([
            'answer' => 'Encontré 1 usuario activo en la búsqueda visible.',
            'intent' => 'search_results',
            'cards' => [
                [
                    'kind' => 'search_results',
                    'title' => 'Usuarios activos',
                    'summary' => 'Resultado visible parcial.',
                    'data' => [
                        'count' => 1,
                        'users' => [],
                    ],
                ],
            ],
        ]),
    ])->preventStrayPrompts();

    $response = $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Cuantos usuarios activos hay',
        ]);

    $response->assertSuccessful()
        ->assertJsonPath('response.intent', 'metrics')
        ->assertJsonPath('response.answer', 'Hay 3 usuarios activos.')
        ->assertJsonPath('response.cards.0.kind', 'metrics')
        ->assertJsonPath('response.cards.0.data.capability_key', 'users.metrics.active')
        ->assertJsonPath('response.cards.0.data.metric.value', 3)
        ->assertJsonPath('response.meta.capability_key', 'users.metrics.active')
        ->assertJsonPath('response.meta.intent_family', 'read_metrics')
        ->assertJsonPath('response.meta.response_source', 'native_tools')
        ->assertJsonPath('response.meta.diagnostics.source_of_truth', 'deterministic_backend');

    $conversationId = $response->json('conversation_id');

    $snapshot = json_decode((string) DB::table('agent_conversations')->where('id', $conversationId)->value('snapshot'), true, flags: JSON_THROW_ON_ERROR);

    expect($snapshot)
        ->toMatchArray([
            'last_user_request_normalized' => 'cuantos usuarios activos hay',
            'last_intent_family' => 'read_metrics',
            'last_capability_key' => 'users.metrics.active',
            'last_result_count' => 3,
        ])
        ->and(data_get($snapshot, 'last_metrics_snapshot.capability_key'))->toBe('users.metrics.active');

    UsersCopilotAgent::assertNeverPrompted();
});

it('normalizes malformed openai search result cards before returning them to the frontend', function () {
    $user = authorizedCopilotOperator();

    UsersCopilotAgent::fake([
        [
            'answer' => 'Encontré resultados parciales para revisar.',
            'intent' => 'search_results',
            'cards' => [
                [
                    'kind' => 'search_results',
                    'title' => 'Usuarios inactivos',
                    'summary' => 'Hay resultados parciales.',
                    'data_json' => json_encode(['count' => 2], JSON_THROW_ON_ERROR),
                ],
            ],
            'actions' => [],
            'requires_confirmation' => false,
            'references' => [],
            'meta' => [
                'module' => 'users',
                'channel' => 'web',
                'subject_user_id' => null,
                'fallback' => false,
                'diagnostics_json' => null,
            ],
        ],
    ])->preventStrayPrompts();

    $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Busca usuarios inactivos.',
        ])
        ->assertSuccessful()
        ->assertJsonPath('response.intent', 'search_results')
        ->assertJsonPath('response.meta.fallback', false)
        ->assertJsonPath('response.cards.0.kind', 'search_results')
        ->assertJsonPath('response.cards.0.data.count', 0)
        ->assertJsonPath('response.cards.0.data.users', []);
});

it('rebuilds malformed openai search result cards from tool results before returning them', function () {
    $user = authorizedCopilotOperator();

    User::factory()->inactive()->unverified()->create([
        'name' => 'Irene Inactiva',
        'email' => 'irene@example.com',
    ]);

    $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Busca usuarios inactivos.',
        ])
        ->assertSuccessful()
        ->assertJsonPath('response.intent', 'search_results')
        ->assertJsonPath('response.meta.fallback', false)
        ->assertJsonPath('response.cards.0.kind', 'search_results')
        ->assertJsonPath('response.cards.0.data.count', 1)
        ->assertJsonPath('response.cards.0.data.users.0.email', 'irene@example.com')
        ->assertJsonPath('response.meta.diagnostics.search_results_source', 'deterministic_backend');
});

it('uses the browser fake transport for HTTP copilot requests regardless of provider', function () {
    config()->set('ai-copilot.providers.default', 'gemini');
    config()->set('ai-copilot.model', 'gemini-2.5-flash-lite');

    $user = authorizedCopilotOperator();

    CopilotBrowserFake::write([
        fakeCopilotResponse([
            'answer' => 'Respuesta determinística desde el transporte fake del navegador.',
            'actions' => [
                [
                    'kind' => 'action_proposal',
                    'action_type' => 'send_reset',
                    'target' => [
                        'kind' => 'user',
                        'user_id' => 77,
                        'name' => 'Provider Agnostic',
                        'email' => 'provider-agnostic@example.test',
                        'is_active' => true,
                    ],
                    'summary' => 'Envía un restablecimiento de contraseña.',
                    'payload' => [
                        'reason' => 'copilot_confirmed_action',
                    ],
                    'can_execute' => true,
                    'deny_reason' => null,
                    'required_permissions' => ['system.users.send-reset', 'system.users-copilot.execute'],
                ],
            ],
            'requires_confirmation' => true,
        ]),
    ]);

    $response = $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Necesito una propuesta determinística.',
        ]);

    $response->assertSuccessful()
        ->assertJsonPath('response.answer', 'Respuesta determinística desde el transporte fake del navegador.')
        ->assertJsonPath('response.actions.0.target.email', 'provider-agnostic@example.test')
        ->assertJsonPath('response.meta.fallback', false);

    $conversationId = $response->json('conversation_id');
    $assistantMessage = DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)
        ->where('role', 'assistant')
        ->first();

    expect($assistantMessage)->not->toBeNull()
        ->and(json_decode($assistantMessage->meta, true, 512, JSON_THROW_ON_ERROR))->toMatchArray([
            'payload_source' => 'browser_file',
        ])
        ->and(File::exists(BrowserCopilotFakeTransport::path()))->toBeFalse();
});

it('uses local gemini orchestration for inactive-user queries even when formatter output is invalid', function () {
    config()->set('ai-copilot.providers.default', 'gemini');
    config()->set('ai-copilot.model', 'gemini-2.5-flash-lite');

    $user = authorizedCopilotOperator();

    User::factory()->inactive()->unverified()->create([
        'name' => 'Irene Inactiva',
        'email' => 'irene@example.com',
    ]);

    UsersGeminiCopilotAgent::fake(['Respuesta plana']);

    $response = $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Busca usuarios inactivos y resume su estado actual',
        ])
        ->assertSuccessful()
        ->assertJsonPath('response.intent', 'search_results')
        ->assertJsonPath('response.meta.fallback', false)
        ->assertJsonPath('response.meta.module', 'users')
        ->assertJsonPath('response.meta.capability_key', 'users.search')
        ->assertJsonPath('response.meta.intent_family', 'read_search')
        ->assertJsonPath('response.cards.0.kind', 'search_results')
        ->assertJsonPath('response.cards.0.data.count', 1)
        ->assertJsonPath('response.cards.0.data.users.0.email', 'irene@example.com');

    expect($response->json('response.meta.response_source'))->toBe('gemini_local_orchestrator')
        ->and($response->json('response.meta.diagnostics.source_of_truth'))->toBe('deterministic_backend');

    $conversationId = $response->json('conversation_id');
    $snapshot = json_decode((string) DB::table('agent_conversations')->where('id', $conversationId)->value('snapshot'), true, flags: JSON_THROW_ON_ERROR);

    expect($snapshot)
        ->toMatchArray([
            'last_user_request_normalized' => 'busca usuarios inactivos y resume su estado actual',
            'last_intent_family' => 'read_search',
            'last_capability_key' => 'users.search',
            'last_result_count' => 1,
        ]);
});

it('uses canonical deterministic metrics payloads on the gemini path', function () {
    config()->set('ai-copilot.providers.default', 'gemini');

    $user = authorizedCopilotOperator();

    User::factory()->count(2)->active()->create();
    User::factory()->inactive()->create();

    UsersGeminiCopilotAgent::fake(['Respuesta plana'])->preventStrayPrompts();

    $response = $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Cuantos usuarios activos hay',
        ])
        ->assertSuccessful()
        ->assertJsonPath('response.intent', 'metrics')
        ->assertJsonPath('response.answer', 'Hay 3 usuarios activos.')
        ->assertJsonPath('response.cards.0.kind', 'metrics')
        ->assertJsonPath('response.cards.0.data.capability_key', 'users.metrics.active')
        ->assertJsonPath('response.cards.0.data.metric.value', 3)
        ->assertJsonPath('response.meta.capability_key', 'users.metrics.active')
        ->assertJsonPath('response.meta.intent_family', 'read_metrics')
        ->assertJsonPath('response.meta.response_source', 'gemini_local_orchestrator')
        ->assertJsonPath('response.meta.diagnostics.source_of_truth', 'deterministic_backend')
        ->assertJsonPath('response.meta.diagnostics.formatter_reason', 'missing_structured_output');

    $conversationId = $response->json('conversation_id');
    $snapshot = json_decode((string) DB::table('agent_conversations')->where('id', $conversationId)->value('snapshot'), true, flags: JSON_THROW_ON_ERROR);

    expect($snapshot)
        ->toMatchArray([
            'last_user_request_normalized' => 'cuantos usuarios activos hay',
            'last_intent_family' => 'read_metrics',
            'last_capability_key' => 'users.metrics.active',
            'last_result_count' => 3,
        ])
        ->and(data_get($snapshot, 'last_metrics_snapshot.capability_key'))->toBe('users.metrics.active');

});

it('returns deterministic mixed metrics and filtered search cards in one response', function () {
    $user = authorizedCopilotOperator();
    $admin = Role::factory()->active()->create(['name' => 'admin', 'display_name' => 'Admin']);
    $admin->syncPermissions(['system.users.view', 'system.users.assign-role', 'system.roles.view']);
    $support = Role::factory()->active()->create(['name' => 'support', 'display_name' => 'Soporte']);

    $firstAdmin = User::factory()->active()->create([
        'name' => 'Ada Admin',
        'email' => 'ada-admin@example.com',
    ]);
    $firstAdmin->assignRole($admin);

    $secondAdmin = User::factory()->inactive()->create([
        'name' => 'Ian Admin',
        'email' => 'ian-admin@example.com',
    ]);
    $secondAdmin->assignRole($admin);

    $supportUser = User::factory()->active()->create([
        'name' => 'Sara Support',
        'email' => 'sara-support@example.com',
    ]);
    $supportUser->assignRole($support);

    UsersCopilotAgent::fake([
        fakeCopilotResponse([
            'answer' => 'Respuesta parcial del proveedor.',
            'cards' => [],
        ]),
    ])->preventStrayPrompts();

    $response = $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'lista la cantidad de usuarios activos, inactivos y el rol mas comun y listame que usuarios son admin',
        ]);

    $response->assertSuccessful()
        ->assertJsonPath('response.intent', 'search_results')
        ->assertJsonPath('response.meta.capability_key', 'users.mixed.metrics_search')
        ->assertJsonPath('response.meta.intent_family', 'read_search')
        ->assertJsonPath('response.meta.response_source', 'native_tools')
        ->assertJsonPath('response.meta.diagnostics.source_of_truth', 'deterministic_backend')
        ->assertJsonPath('response.cards.0.kind', 'metrics')
        ->assertJsonPath('response.cards.0.data.capability_key', 'users.metrics.combined')
        ->assertJsonPath('response.cards.1.kind', 'search_results')
        ->assertJsonPath('response.cards.1.data.matching_count', 1);

    expect($response->json('response.answer'))
        ->toContain('3 activos y 1 inactivos.')
        ->toContain('El rol mas comun es Admin con 2 usuarios asignados.')
        ->toContain('Ademas, encontre 1 usuario con acceso administrativo efectivo.')
        ->and(collect($response->json('response.cards.1.data.users'))->pluck('name')->all())
        ->toEqualCanonicalizing(['Ada Admin']);

    $conversationId = $response->json('conversation_id');
    $snapshot = json_decode((string) DB::table('agent_conversations')->where('id', $conversationId)->value('snapshot'), true, flags: JSON_THROW_ON_ERROR);

    expect($snapshot)
        ->toMatchArray([
            'last_user_request_normalized' => 'lista la cantidad de usuarios activos, inactivos y el rol mas comun y listame que usuarios son admin',
            'last_intent_family' => 'read_search',
            'last_capability_key' => 'users.mixed.metrics_search',
            'last_result_count' => 1,
        ])
        ->and(data_get($snapshot, 'last_metrics_snapshot.capability_key'))->toBe('users.metrics.combined')
        ->and(data_get($snapshot, 'last_filters.access_profile'))->toBe('administrative_access');
});

it('returns partial response for mixed create and search requests', function () {
    $user = authorizedCopilotOperator();
    User::factory()->active()->create([
        'name' => 'Miguel Rojas',
        'email' => 'miguel.rojas@example.com',
    ]);

    $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Crea a Miguel Rojas miguel@example.com y además dime si ya existe alguien parecido',
        ])
        ->assertSuccessful()
        ->assertJsonPath('response.intent', 'partial')
        ->assertJsonPath('response.resolution.state', 'partial')
        ->assertJsonPath('response.actions', [])
        ->assertJsonPath('response.cards.0.kind', 'search_results')
        ->assertJsonPath('response.cards.1.kind', 'partial_notice')
        ->assertJsonPath('response.cards.1.data.segments.0.status', 'not_executed');

    expect(User::query()->where('email', 'miguel@example.com')->exists())->toBeFalse();
});

it('merges gemini formatter text into the deterministic action proposal payload', function () {
    config()->set('ai-copilot.providers.default', 'gemini');
    config()->set('ai-copilot.model', 'gemini-2.5-flash-lite');

    $user = authorizedCopilotOperator();
    $target = User::factory()->active()->create([
        'name' => 'Gemini Target',
        'email' => 'gemini@example.com',
    ]);

    UsersGeminiCopilotAgent::fake([
        [
            'answer' => 'Preparé una propuesta compatible con Gemini.',
            'intent' => 'action_proposal',
            'cards' => [
                [
                    'kind' => 'notice',
                    'title' => 'Compatibilidad',
                    'summary' => 'Salida segura para Gemini.',
                    'data_json' => json_encode(['provider' => 'gemini'], JSON_THROW_ON_ERROR),
                ],
            ],
            'actions' => [
                [
                    'kind' => 'action_proposal',
                    'action_type' => 'send_reset',
                    'target_json' => json_encode([
                        'kind' => 'user',
                        'user_id' => 44,
                        'name' => 'Gemini Target',
                        'email' => 'gemini@example.com',
                        'is_active' => true,
                    ], JSON_THROW_ON_ERROR),
                    'summary' => 'Envía un restablecimiento al usuario.',
                    'payload_json' => json_encode([
                        'reason' => 'copilot_confirmed_action',
                    ], JSON_THROW_ON_ERROR),
                    'can_execute' => true,
                    'deny_reason' => null,
                    'required_permissions' => ['system.users.send-reset', 'system.users-copilot.execute'],
                ],
            ],
            'requires_confirmation' => true,
            'references' => [],
            'meta' => [
                'module' => 'users',
                'channel' => 'web',
                'subject_user_id' => $target->id,
                'fallback' => false,
                'diagnostics_json' => json_encode(['profile' => 'gemini'], JSON_THROW_ON_ERROR),
            ],
        ],
    ])->preventStrayPrompts();

    $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Propón enviar un restablecimiento a este usuario.',
            'subject_user_id' => $target->id,
        ])
        ->assertSuccessful()
        ->assertJsonPath('response.actions.0.target.email', 'gemini@example.com')
        ->assertJsonPath('response.actions.0.payload.reason', 'copilot_confirmed_action')
        ->assertJsonPath('response.actions.0.summary', 'Envía un restablecimiento al usuario.')
        ->assertJsonPath('response.answer', 'Preparé una propuesta compatible con Gemini.')
        ->assertJsonPath('response.meta.diagnostics.formatter_result', 'gemini_text_json')
        ->assertJsonPath('response.meta.fallback', false);
});

it('prioritizes an explicitly mentioned user over the inactive keyword heuristic', function () {
    config()->set('ai-copilot.providers.default', 'gemini');
    config()->set('ai-copilot.model', 'gemini-2.5-flash-lite');

    $user = authorizedCopilotOperator();
    $target = User::factory()->active()->create([
        'name' => 'Bernardo Copilot Check',
        'email' => 'bernardo-copilot-check@example.com',
    ]);

    UsersGeminiCopilotAgent::fake(['Respuesta plana'])->preventStrayPrompts();

    $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Explícame el acceso efectivo de Bernardo Copilot Check',
        ])
        ->assertSuccessful()
        ->assertJsonPath('response.intent', 'user_context')
        ->assertJsonPath('response.cards.0.kind', 'user_context')
        ->assertJsonPath('response.cards.0.data.user.email', $target->email)
        ->assertJsonPath('response.meta.fallback', false);
});

it('resolves explicit email detail prompts through the gemini orchestrator', function () {
    config()->set('ai-copilot.providers.default', 'gemini');
    config()->set('ai-copilot.model', 'gemini-2.5-flash-lite');

    $user = authorizedCopilotOperator();
    $target = User::factory()->active()->create([
        'name' => 'Test Admin',
        'email' => 'test@mailinator.com',
    ]);

    UsersGeminiCopilotAgent::fake(['Respuesta plana'])->preventStrayPrompts();

    $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'el usuario test@mailinator.com que permisos tiene y que rol',
        ])
        ->assertSuccessful()
        ->assertJsonPath('response.intent', 'user_context')
        ->assertJsonPath('response.cards.0.kind', 'user_context')
        ->assertJsonPath('response.cards.0.data.user.email', $target->email)
        ->assertJsonPath('response.meta.capability_key', 'users.detail');
});

it('reuses clarification hints to resolve the intended user on the next message', function () {
    config()->set('ai-copilot.providers.default', 'gemini');
    config()->set('ai-copilot.model', 'gemini-2.5-flash-lite');

    $user = authorizedCopilotOperator();

    User::factory()->create([
        'name' => 'Test Operator',
        'email' => 'operator@example.com',
    ]);
    $target = User::factory()->create([
        'name' => 'Test Admin',
        'email' => 'test@mailinator.com',
    ]);

    UsersGeminiCopilotAgent::fake(['Respuesta plana'])->preventStrayPrompts();

    $conversationId = $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Revisa al usuario Test',
        ])
        ->assertSuccessful()
        ->assertJsonPath('response.intent', 'ambiguous')
        ->json('conversation_id');

    $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'el usuario es test admin',
            'conversation_id' => $conversationId,
        ])
        ->assertSuccessful()
        ->assertJsonPath('response.intent', 'user_context')
        ->assertJsonPath('response.cards.0.kind', 'user_context')
        ->assertJsonPath('response.cards.0.data.user.email', $target->email)
        ->assertJsonPath('response.meta.capability_key', 'users.detail');
});

it('can prepare a reset proposal for a user mentioned by name without explicit subject context', function () {
    config()->set('ai-copilot.providers.default', 'gemini');
    config()->set('ai-copilot.model', 'gemini-2.5-flash-lite');

    $user = authorizedCopilotOperator();
    $target = User::factory()->active()->create([
        'name' => 'Resettable User',
        'email' => 'resettable@example.test',
    ]);

    UsersGeminiCopilotAgent::fake(['Respuesta plana'])->preventStrayPrompts();

    $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Necesito enviar un restablecimiento a Resettable User',
        ])
        ->assertSuccessful()
        ->assertJsonPath('response.intent', 'action_proposal')
        ->assertJsonPath('response.actions.0.action_type', 'send_reset')
        ->assertJsonPath('response.actions.0.target.user_id', $target->id)
        ->assertJsonPath('response.actions.0.target.email', $target->email)
        ->assertJsonPath('response.meta.fallback', false);
});

it('keeps the deterministic action proposal when gemini returns invalid json fragments', function () {
    config()->set('ai-copilot.providers.default', 'gemini');

    $user = authorizedCopilotOperator();
    $target = User::factory()->active()->create([
        'name' => 'Invalid Gemini Target',
        'email' => 'invalid-gemini@example.com',
    ]);

    UsersGeminiCopilotAgent::fake([
        [
            'answer' => 'Respuesta inválida.',
            'intent' => 'action_proposal',
            'cards' => [],
            'actions' => [
                [
                    'kind' => 'action_proposal',
                    'action_type' => 'send_reset',
                    'target_json' => '{invalid-json',
                    'summary' => 'Envía un restablecimiento.',
                    'payload_json' => json_encode(['reason' => 'copilot_confirmed_action'], JSON_THROW_ON_ERROR),
                    'can_execute' => true,
                    'deny_reason' => null,
                    'required_permissions' => ['system.users.send-reset', 'system.users-copilot.execute'],
                ],
            ],
            'requires_confirmation' => true,
            'references' => [],
            'meta' => [
                'module' => 'users',
                'channel' => 'web',
                'subject_user_id' => $target->id,
                'fallback' => false,
                'diagnostics_json' => null,
            ],
        ],
    ])->preventStrayPrompts();

    $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Propón enviar un restablecimiento a este usuario.',
            'subject_user_id' => $target->id,
        ])
        ->assertSuccessful()
        ->assertJsonPath('response.intent', 'action_proposal')
        ->assertJsonPath('response.actions.0.target.email', 'invalid-gemini@example.com')
        ->assertJsonPath('response.meta.fallback', false)
        ->assertJsonPath('response.meta.diagnostics.formatter_reason', 'invalid_structured_output');
});

it('uses local gemini user-detail orchestration for selected-user follow ups', function () {
    config()->set('ai-copilot.providers.default', 'gemini');
    config()->set('ai-copilot.model', 'gemini-2.5-flash-lite');

    $user = authorizedCopilotOperator();
    $targetRole = Role::factory()->active()->create(['display_name' => 'Soporte']);
    $targetRole->syncPermissions(['system.users.view']);
    $target = User::factory()->withTwoFactor()->create([
        'name' => 'Laura Soporte',
        'email' => 'laura@example.com',
    ]);
    $target->assignRole($targetRole);

    UsersGeminiCopilotAgent::fake([
        "```json\n".json_encode([
            'answer' => 'Laura Soporte mantiene un contexto operativo claro.',
            'intent' => 'user_context',
            'cards' => [
                [
                    'kind' => 'user_context',
                    'title' => 'Resumen operativo',
                    'summary' => 'Detalle revisado con formato natural.',
                    'data' => ['ignored' => true],
                ],
            ],
            'actions' => [],
            'requires_confirmation' => false,
            'references' => [],
            'meta' => [
                'module' => 'users',
                'channel' => 'web',
                'subject_user_id' => $target->id,
                'fallback' => false,
                'diagnostics' => ['formatter' => 'text'],
            ],
        ], JSON_THROW_ON_ERROR)."\n```",
    ])->preventStrayPrompts();

    $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Explica el acceso efectivo y el estado actual de este usuario.',
            'subject_user_id' => $target->id,
        ])
        ->assertSuccessful()
        ->assertJsonPath('response.intent', 'user_context')
        ->assertJsonPath('response.answer', 'Laura Soporte mantiene un contexto operativo claro.')
        ->assertJsonPath('response.cards.0.kind', 'user_context')
        ->assertJsonPath('response.cards.0.summary', 'Detalle revisado con formato natural.')
        ->assertJsonPath('response.cards.0.data.user.id', $target->id)
        ->assertJsonPath('response.meta.subject_user_id', $target->id)
        ->assertJsonPath('response.meta.fallback', false);

    UsersGeminiCopilotAgent::assertPrompted(function (AgentPrompt $prompt): bool {
        return $prompt->contains('Explica el acceso efectivo y el estado actual de este usuario.')
            && $prompt->contains('JSON base');
    });

    UsersCopilotAgent::assertNeverPrompted();
});

it('returns deterministic help when the gemini formatter returns invalid json', function () {
    config()->set('ai-copilot.providers.default', 'gemini');

    $user = authorizedCopilotOperator();

    UsersGeminiCopilotAgent::fake([
        '{invalid-json',
    ])->preventStrayPrompts();

    $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Necesito ayuda.',
        ])
        ->assertSuccessful()
        ->assertJsonPath('response.intent', 'help')
        ->assertJsonPath('response.meta.fallback', false)
        ->assertJsonPath('response.meta.diagnostics.capability', 'help')
        ->assertJsonPath('response.meta.diagnostics.formatter_reason', 'missing_structured_output');
});

it('requires usable context before continuing an owned conversation', function () {
    $user = authorizedCopilotOperator();

    UsersCopilotAgent::fake([
        fakeCopilotResponse([
            'answer' => 'Resumen inicial.',
            'intent' => 'inform',
            'cards' => [],
        ]),
        fakeCopilotResponse([
            'answer' => 'Continué la conversación.',
            'intent' => 'inform',
            'cards' => [],
        ]),
    ])->preventStrayPrompts();

    $conversationId = $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Resume este usuario.',
        ])
        ->assertSuccessful()
        ->json('conversation_id');

    $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Continúa con más detalle.',
            'conversation_id' => $conversationId,
        ])
        ->assertSuccessful()
        ->assertJsonPath('conversation_id', $conversationId)
        ->assertJsonPath('response.intent', 'ambiguous')
        ->assertJsonPath('response.cards.0.kind', 'clarification')
        ->assertJsonPath('response.meta.capability_key', 'users.clarification')
        ->assertJsonPath('response.answer', 'No logre determinar como continuar. Puedes ser mas especifico?');

    expect(DB::table('agent_conversation_messages')->where('conversation_id', $conversationId)->count())->toBe(4);
});

it('reconstructs count follow-ups from the previous native search snapshot', function () {
    $user = authorizedCopilotOperator();

    $marioOne = User::factory()->inactive()->create([
        'name' => 'Mario Uno',
        'email' => 'mario1@example.com',
    ]);
    $marioTwo = User::factory()->inactive()->create([
        'name' => 'Mario Dos',
        'email' => 'mario2@example.com',
    ]);

    UsersCopilotAgent::fake([
        fakeCopilotResponse([
            'answer' => 'Encontré usuarios inactivos.',
            'intent' => 'search_results',
            'cards' => [[
                'kind' => 'search_results',
                'title' => 'Usuarios inactivos',
                'summary' => 'Hay 2 usuarios inactivos.',
                'data' => [
                    'count' => 2,
                    'matching_count' => 2,
                    'users' => [
                        [
                            'id' => $marioOne->id,
                            'name' => $marioOne->name,
                            'email' => $marioOne->email,
                            'is_active' => false,
                            'email_verified' => false,
                            'two_factor_enabled' => false,
                            'roles_count' => 0,
                            'roles' => [],
                            'created_at' => now()->toIso8601String(),
                            'show_href' => '/system/users/'.$marioOne->id,
                        ],
                        [
                            'id' => $marioTwo->id,
                            'name' => $marioTwo->name,
                            'email' => $marioTwo->email,
                            'is_active' => false,
                            'email_verified' => false,
                            'two_factor_enabled' => false,
                            'roles_count' => 0,
                            'roles' => [],
                            'created_at' => now()->toIso8601String(),
                            'show_href' => '/system/users/'.$marioTwo->id,
                        ],
                    ],
                ],
            ]],
        ]),
        fakeCopilotResponse([
            'answer' => 'No deberia usarse este texto del proveedor.',
            'intent' => 'help',
            'cards' => [],
        ]),
    ])->preventStrayPrompts();

    $conversationId = $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Busca usuarios inactivos.',
        ])
        ->assertSuccessful()
        ->json('conversation_id');

    $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Y cuantos son',
            'conversation_id' => $conversationId,
        ])
        ->assertSuccessful()
        ->assertJsonPath('response.intent', 'metrics')
        ->assertJsonPath('response.answer', 'El subconjunto actual tiene 2 usuarios.')
        ->assertJsonPath('response.cards.0.kind', 'metrics')
        ->assertJsonPath('response.cards.0.data.capability_key', 'users.snapshot.result_count')
        ->assertJsonPath('response.cards.0.data.metric.value', 2)
        ->assertJsonPath('response.meta.diagnostics.source_of_truth', 'conversation_snapshot');
});

it('resolves first ordinal follow-ups from the previous native search snapshot', function () {
    config()->set('ai.providers.openai.api_key', '');
    config()->set('ai.providers.gemini.api_key', '');
    config()->set('ai.providers.anthropic.api_key', '');

    $user = authorizedCopilotOperator();

    User::factory()->active()->create([
        'name' => 'Carlos Alpha',
        'email' => 'carlos.alpha@example.com',
    ]);
    User::factory()->active()->create([
        'name' => 'Carlos Beta',
        'email' => 'carlos.beta@example.com',
    ]);

    UsersCopilotAgent::fake([
        fakeCopilotResponse(),
        fakeCopilotResponse(),
    ])->preventStrayPrompts();

    $searchResponse = $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Busca usuarios llamados Carlos',
        ])
        ->assertSuccessful()
        ->assertJsonPath('response.intent', 'search_results')
        ->assertJsonPath('response.meta.fallback', false);

    $firstResultId = $searchResponse->json('response.cards.0.data.users.0.id');

    expect($firstResultId)->toBeInt();

    $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'muéstrame el primero',
            'conversation_id' => $searchResponse->json('conversation_id'),
        ])
        ->assertSuccessful()
        ->assertJsonPath('response.intent', 'user_context')
        ->assertJsonPath('response.cards.0.kind', 'user_context')
        ->assertJsonPath('response.cards.0.data.user.id', $firstResultId)
        ->assertJsonPath('response.meta.capability_key', 'users.detail')
        ->assertJsonPath('response.meta.fallback', false);

    UsersCopilotAgent::assertNeverPrompted();
});

it('reconstructs count follow-ups from the previous gemini search snapshot', function () {
    config()->set('ai-copilot.providers.default', 'gemini');

    $user = authorizedCopilotOperator();

    User::factory()->inactive()->create(['name' => 'Irene Uno', 'email' => 'irene1@example.com']);
    User::factory()->inactive()->create(['name' => 'Irene Dos', 'email' => 'irene2@example.com']);

    UsersGeminiCopilotAgent::fake([
        'Respuesta plana',
        'Respuesta plana',
    ])->preventStrayPrompts();

    $conversationId = $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Busca usuarios inactivos.',
        ])
        ->assertSuccessful()
        ->json('conversation_id');

    $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Y cuantos son',
            'conversation_id' => $conversationId,
        ])
        ->assertSuccessful()
        ->assertJsonPath('response.intent', 'metrics')
        ->assertJsonPath('response.answer', 'El subconjunto actual tiene 2 usuarios.')
        ->assertJsonPath('response.cards.0.data.capability_key', 'users.snapshot.result_count')
        ->assertJsonPath('response.meta.response_source', 'gemini_local_orchestrator')
        ->assertJsonPath('response.meta.diagnostics.source_of_truth', 'conversation_snapshot');
});

it('returns an explicit clarification for ambiguous direct user references', function () {
    $user = authorizedCopilotOperator();

    User::factory()->create(['name' => 'Mario Vega', 'email' => 'mario1@example.com']);
    User::factory()->create(['name' => 'Mario Soto', 'email' => 'mario2@example.com']);

    UsersCopilotAgent::fake([
        fakeCopilotResponse([
            'answer' => 'Respuesta irrelevante del proveedor.',
            'intent' => 'help',
            'cards' => [],
        ]),
    ])->preventStrayPrompts();

    $response = $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Revisa al usuario Mario',
        ]);

    $response->assertSuccessful()
        ->assertJsonPath('response.intent', 'ambiguous')
        ->assertJsonPath('response.cards.0.kind', 'clarification')
        ->assertJsonPath('response.meta.capability_key', 'users.clarification')
        ->assertJsonPath('response.actions', []);

    $snapshot = json_decode((string) DB::table('agent_conversations')->where('id', $response->json('conversation_id'))->value('snapshot'), true, flags: JSON_THROW_ON_ERROR);

    expect(data_get($snapshot, 'pending_clarification.reason'))->toBe('ambiguous_target')
        ->and(count(data_get($snapshot, 'pending_clarification.options', [])))->toBeGreaterThan(1);
});

it('fails safely when a follow-up count request has no prior context', function () {
    $user = authorizedCopilotOperator();

    UsersCopilotAgent::fake([
        fakeCopilotResponse([
            'answer' => 'Respuesta irrelevante del proveedor.',
            'intent' => 'help',
            'cards' => [],
        ]),
    ])->preventStrayPrompts();

    $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Y cuantos son',
        ])
        ->assertSuccessful()
        ->assertJsonPath('response.intent', 'ambiguous')
        ->assertJsonPath('response.cards.0.kind', 'clarification')
        ->assertJsonPath('response.meta.capability_key', 'users.clarification')
        ->assertJsonPath('response.meta.intent_family', 'ambiguous')
        ->assertJsonPath('response.meta.response_source', 'native_tools');
});

it('keeps action proposals read only during message handling', function () {
    Notification::fake();

    $user = authorizedCopilotOperator();
    $user->roles->first()->syncPermissions([
        'system.users.view',
        'system.users.create',
        'system.users.assign-role',
        'system.users-copilot.view',
        'system.users-copilot.execute',
    ]);
    $target = User::factory()->active()->create([
        'name' => 'Mario Operador',
        'email' => 'mario@example.com',
    ]);

    UsersCopilotAgent::fake([
        fakeCopilotResponse([
            'answer' => 'Preparé una propuesta de alta guiada, pero no crearé al usuario hasta confirmarlo.',
            'intent' => 'action_proposal',
            'cards' => [],
            'actions' => [
                [
                    'kind' => 'action_proposal',
                    'action_type' => 'create_user',
                    'target' => [
                        'kind' => 'new_user',
                        'name' => 'Laura Copilot',
                        'email' => 'laura@example.com',
                    ],
                    'summary' => 'Preparé una propuesta de alta guiada. Revísala y confirma para crear el usuario.',
                    'payload' => [
                        'name' => 'Laura Copilot',
                        'email' => 'laura@example.com',
                        'roles' => [1],
                        'role_labels' => ['Soporte'],
                    ],
                    'can_execute' => false,
                    'deny_reason' => 'Aún falta confirmación.',
                    'required_permissions' => [
                        'system.users.create',
                        'system.users.assign-role',
                        'system.users-copilot.execute',
                    ],
                ],
            ],
        ]),
    ])->preventStrayPrompts();

    $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Propón desactivar a Mario Operador.',
        ])
        ->assertSuccessful()
        ->assertJsonPath('response.actions.0.action_type', 'deactivate');

    $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Propón enviar un restablecimiento a Mario Operador.',
        ])
        ->assertSuccessful()
        ->assertJsonPath('response.actions.0.action_type', 'send_reset');

    $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Propón dar de alta a Laura Copilot.',
        ])
        ->assertSuccessful()
        ->assertJsonPath('response.actions.0.action_type', 'create_user');

    expect($target->fresh()->is_active)->toBeTrue()
        ->and(User::query()->where('email', 'laura@example.com')->exists())->toBeFalse();

    Notification::assertNothingSent();
});

it('keeps incomplete create user proposals pending until required slots are provided', function () {
    $operatorRole = Role::factory()->active()->create();
    $operatorRole->syncPermissions([
        'system.users.view',
        'system.users.create',
        'system.users.assign-role',
        'system.users-copilot.view',
        'system.users-copilot.execute',
    ]);
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole($operatorRole);
    Role::factory()->active()->create([
        'name' => 'soporte',
        'display_name' => 'Soporte',
    ])->syncPermissions(['system.users.view']);

    $conversationId = $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Propón dar de alta a Laura Copilot con correo laura@example.com',
        ])
        ->assertSuccessful()
        ->assertJsonPath('response.intent', 'action_proposal')
        ->assertJsonPath('response.actions.0.action_type', 'create_user')
        ->assertJsonPath('response.actions.0.can_execute', false)
        ->assertJsonPath('response.actions.0.payload.name', 'Laura Copilot')
        ->assertJsonPath('response.actions.0.payload.email', 'laura@example.com')
        ->assertJsonPath('response.cards.0.data.missing_fields.0', 'roles')
        ->json('conversation_id');

    $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'asígnale rol Soporte',
            'conversation_id' => $conversationId,
        ])
        ->assertSuccessful()
        ->assertJsonPath('response.intent', 'action_proposal')
        ->assertJsonPath('response.actions.0.action_type', 'create_user')
        ->assertJsonPath('response.actions.0.can_execute', true)
        ->assertJsonPath('response.actions.0.payload.name', 'Laura Copilot')
        ->assertJsonPath('response.actions.0.payload.email', 'laura@example.com')
        ->assertJsonPath('response.actions.0.payload.role_labels.0', 'Soporte')
        ->assertJsonPath('response.requires_confirmation', true);
});

it('cancels a pending create user proposal before confirmation', function () {
    config()->set('ai.providers.openai.key', null);
    UsersCopilotAgent::fake([])->preventStrayPrompts();

    $operatorRole = Role::factory()->active()->create();
    $operatorRole->syncPermissions([
        'system.users.view',
        'system.users.create',
        'system.users.assign-role',
        'system.users-copilot.view',
        'system.users-copilot.execute',
    ]);
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole($operatorRole);
    Role::factory()->active()->create([
        'name' => 'admin',
        'display_name' => 'Admin',
    ])->syncPermissions(['system.users.view']);

    $conversationId = $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Prepara la creación de Quentin Execute quentin.execute@example.com con rol admin',
        ])
        ->assertSuccessful()
        ->assertJsonPath('response.intent', 'action_proposal')
        ->assertJsonPath('response.actions.0.can_execute', true)
        ->json('conversation_id');

    $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'cancela eso',
            'conversation_id' => $conversationId,
        ])
        ->assertSuccessful()
        ->assertJsonPath('response.intent', 'help')
        ->assertJsonPath('response.meta.capability_key', 'users.actions.cancel_pending')
        ->assertJsonPath('response.cards.0.kind', 'notice');

    $snapshot = json_decode((string) DB::table('agent_conversations')->where('id', $conversationId)->value('snapshot'), true, flags: JSON_THROW_ON_ERROR);

    expect(data_get($snapshot, 'pending_action_proposal'))->toBeNull()
        ->and(data_get($snapshot, 'pending_create_user'))->toBeNull();

    $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'confirma',
            'conversation_id' => $conversationId,
        ])
        ->assertSuccessful()
        ->assertJsonPath('response.resolution.state', 'missing_context');

    expect(User::query()->where('email', 'quentin.execute@example.com')->exists())->toBeFalse();
});

it('denies continuing another users conversation', function () {
    $owner = authorizedCopilotOperator();
    $intruder = authorizedCopilotOperator();

    UsersCopilotAgent::fake([
        [
            'answer' => 'Resumen inicial.',
            'intent' => 'inform',
            'cards' => [],
            'actions' => [],
            'requires_confirmation' => false,
            'references' => [],
            'meta' => [
                'module' => 'users',
                'channel' => 'web',
                'subject_user_id' => null,
                'fallback' => false,
                'diagnostics' => null,
            ],
        ],
    ]);

    $conversationId = $this->actingAs($owner)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Resume este usuario.',
        ])
        ->json('conversation_id');

    $this->actingAs($intruder)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Quiero continuar esta conversación.',
            'conversation_id' => $conversationId,
        ])
        ->assertForbidden();
});

it('does not emit provider observability logs for deterministic native executions', function () {
    $user = authorizedCopilotOperator();

    Context::add('correlation_id', 'copilot-test-correlation');

    // Spy Log AFTER user creation to avoid SecurityAuditService channel() noise
    $logSpy = Log::spy();
    $logSpy->shouldReceive('channel')->andReturnSelf();

    UsersCopilotAgent::fake([
        fakeCopilotResponse([
            'answer' => 'Resumen operativo seguro.',
        ]),
    ])->preventStrayPrompts();

    $response = $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Necesito revisar al usuario secreto@example.com',
        ]);

    $response->assertSuccessful();

    $conversationId = $response->json('conversation_id');

    Log::shouldNotHaveReceived('info', function (string $message): bool {
        return $message === 'ai-copilot.users.usage';
    });

    expect($conversationId)->not->toBeEmpty();

    Context::flush();
});

it('creates prompt derived titles for new conversations without a hidden extra provider call', function () {
    $user = authorizedCopilotOperator();

    expect(in_array(RemembersConversations::class, class_uses_recursive(UsersCopilotAgent::class), true))->toBeFalse();

    UsersCopilotAgent::fake([
        fakeCopilotResponse([
            'answer' => 'Consulta inicial resuelta.',
            'intent' => 'inform',
            'cards' => [],
        ]),
    ])->preventStrayPrompts();

    $response = $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Consulta inicial para revisar permisos de usuarios',
        ]);

    $response->assertSuccessful();

    expect(DB::table('agent_conversations')->where('id', $response->json('conversation_id'))->value('title'))
        ->toBe('Consulta inicial para revisar permisos de usuarios')
        ->and((new UsersCopilotAgent(User::factory()->make()))->conversationTitleFor('Consulta inicial para revisar permisos de usuarios'))
        ->toBe('Consulta inicial para revisar permisos de usuarios');
});

it('searches users by name through the gemini orchestrator', function () {
    config()->set('ai-copilot.providers.default', 'gemini');
    config()->set('ai-copilot.model', 'gemini-2.5-flash-lite');

    $user = authorizedCopilotOperator();

    $uniqueName = 'Zxqtest Copilotuser';

    User::factory()->active()->create([
        'name' => $uniqueName,
        'email' => 'zxqtest-copilotuser@example.com',
    ]);

    UsersGeminiCopilotAgent::fake(['Respuesta plana'])->preventStrayPrompts();

    $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Busca usuarios con nombre Zxqtest',
        ])
        ->assertSuccessful()
        ->assertJsonPath('response.intent', 'search_results')
        ->assertJsonPath('response.meta.capability_key', 'users.search')
        ->assertJsonPath('response.cards.0.kind', 'search_results')
        ->assertJsonPath('response.cards.0.data.users.0.name', $uniqueName)
        ->assertJsonPath('response.meta.fallback', false);
});

it('returns empty search results instead of help for non-existent users', function () {
    config()->set('ai-copilot.providers.default', 'gemini');
    config()->set('ai-copilot.model', 'gemini-2.5-flash-lite');

    $user = authorizedCopilotOperator();

    UsersGeminiCopilotAgent::fake(['Respuesta plana'])->preventStrayPrompts();

    $response = $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Busca usuario llamado SuperManInexistente',
        ])
        ->assertSuccessful();

    expect($response->json('response.intent'))->toBe('search_results')
        ->and($response->json('response.meta.capability_key'))->toBe('users.search')
        ->and($response->json('response.cards.0.data.count'))->toBe(0)
        ->and($response->json('response.answer'))->toContain('No se encontraron');
});

it('searches users by role through the gemini orchestrator', function () {
    config()->set('ai-copilot.providers.default', 'gemini');
    config()->set('ai-copilot.model', 'gemini-2.5-flash-lite');

    $user = authorizedCopilotOperator();
    $role = Role::factory()->active()->create(['display_name' => 'Auditor']);
    $target = User::factory()->active()->create([
        'name' => 'Ana Auditora',
        'email' => 'ana-audit@example.com',
    ]);
    $target->assignRole($role);

    UsersGeminiCopilotAgent::fake(['Respuesta plana'])->preventStrayPrompts();

    $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Busca usuarios con rol auditor',
        ])
        ->assertSuccessful()
        ->assertJsonPath('response.intent', 'search_results')
        ->assertJsonPath('response.meta.capability_key', 'users.search')
        ->assertJsonPath('response.cards.0.data.users.0.name', 'Ana Auditora')
        ->assertJsonPath('response.meta.fallback', false);
});

it('triggers deactivation proposal with direct imperative verb without magic words', function () {
    config()->set('ai-copilot.providers.default', 'gemini');
    config()->set('ai-copilot.model', 'gemini-2.5-flash-lite');

    $user = authorizedCopilotOperator();
    $target = User::factory()->active()->create([
        'name' => 'Mario Operador',
        'email' => 'mario-direct@example.com',
    ]);

    UsersGeminiCopilotAgent::fake(['Respuesta plana'])->preventStrayPrompts();

    $response = $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Desactiva al usuario Mario Operador',
        ])
        ->assertSuccessful()
        ->assertJsonPath('response.intent', 'action_proposal')
        ->assertJsonPath('response.actions.0.action_type', 'deactivate')
        ->assertJsonPath('response.actions.0.target.user_id', $target->id)
        ->assertJsonPath('response.meta.fallback', false);

    expect($target->fresh()->is_active)->toBeTrue();
});

it('triggers activation proposal with direct imperative verb', function () {
    config()->set('ai-copilot.providers.default', 'gemini');
    config()->set('ai-copilot.model', 'gemini-2.5-flash-lite');

    $user = authorizedCopilotOperator();
    $target = User::factory()->inactive()->create([
        'name' => 'Inactivo Directo',
        'email' => 'inactivo-directo@example.com',
    ]);

    UsersGeminiCopilotAgent::fake(['Respuesta plana'])->preventStrayPrompts();

    $this->actingAs($user)
        ->postJson(route('system.users.copilot.messages'), [
            'prompt' => 'Activa al usuario Inactivo Directo',
        ])
        ->assertSuccessful()
        ->assertJsonPath('response.intent', 'action_proposal')
        ->assertJsonPath('response.actions.0.action_type', 'activate')
        ->assertJsonPath('response.actions.0.target.user_id', $target->id)
        ->assertJsonPath('response.meta.fallback', false);

    expect($target->fresh()->is_active)->toBeFalse();
});

it('resolves the copilot provider and model from configuration', function () {
    config()->set('ai-copilot.providers.default', 'gemini');
    config()->set('ai-copilot.model', 'gemini-2.5-flash-lite');

    $agent = new UsersCopilotAgent(User::factory()->make());

    expect($agent->provider())
        ->toBe('gemini')
        ->and($agent->model())
        ->toBe('gemini-2.5-flash-lite');
});
