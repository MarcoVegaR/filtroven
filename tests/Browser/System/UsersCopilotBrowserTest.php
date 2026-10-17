<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessModulePermissionsSeeder;
use Database\Seeders\AiCopilotPermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Vite;
use Tests\Support\CopilotBrowserFake;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class);

beforeEach(function () {
    CopilotBrowserFake::clear();

    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(AccessModulePermissionsSeeder::class);
    $this->seed(AiCopilotPermissionsSeeder::class);

    config(['ai-copilot.enabled' => true]);

    $publicHotPath = public_path('hot');
    if (file_exists($publicHotPath)) {
        unlink($publicHotPath);
    }

    $hotPath = storage_path('framework/testing/vite.hot');
    if (file_exists($hotPath)) {
        unlink($hotPath);
    }

    Vite::useHotFile($hotPath);
});

afterEach(function () {
    CopilotBrowserFake::clear();
});

function browserCopilotOperator(array $permissions): User
{
    $role = Role::factory()->active()->create();
    $role->syncPermissions($permissions);

    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole($role);

    return $user;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function browserCopilotResponse(array $overrides = []): array
{
    return array_replace_recursive([
        'answer' => 'Preparé una propuesta para revisar.',
        'intent' => 'action_proposal',
        'cards' => [],
        'actions' => [],
        'requires_confirmation' => false,
        'references' => [],
        'resolution' => [
            'state' => 'resolved',
            'confidence' => 'high',
            'action_boundary' => 'none',
            'understood' => [],
            'unresolved' => [],
            'missing' => [],
            'denials' => [],
        ],
        'meta' => [
            'module' => 'users',
            'channel' => 'web',
            'subject_user_id' => null,
            'fallback' => false,
            'diagnostics' => null,
        ],
    ], $overrides);
}

it('shows the copilot entrypoint, filters empty state prompts, and disables confirmation for proposal only actions', function () {
    $this->app['env'] = 'local';

    $operator = browserCopilotOperator([
        'system.users.view',
        'system.users.send-reset',
        'system.users-copilot.view',
        'system.users-copilot.execute',
    ]);
    $target = User::factory()->create([
        'name' => 'Reset Target',
        'email' => 'reset-target@example.test',
    ]);

    CopilotBrowserFake::write([
        browserCopilotResponse([
            'answer' => 'Puedo proponer el restablecimiento, pero esta propuesta no es ejecutable todavía.',
            'actions' => [
                [
                    'kind' => 'action_proposal',
                    'action_type' => 'send_reset',
                    'target' => [
                        'kind' => 'user',
                        'user_id' => $target->id,
                        'name' => $target->name,
                        'email' => $target->email,
                        'is_active' => true,
                    ],
                    'summary' => 'Envía un correo de restablecimiento de contraseña a Reset Target.',
                    'payload' => [
                        'reason' => 'copilot_confirmed_action',
                    ],
                    'can_execute' => false,
                    'deny_reason' => 'El backend marcó esta propuesta como solo lectura.',
                    'required_permissions' => ['system.users.send-reset', 'system.users-copilot.execute'],
                ],
            ],
            'meta' => [
                'capability_key' => 'users.actions.send_reset',
                'response_source' => 'native_tools',
            ],
        ]),
    ]);

    $this->actingAs($operator);

    $page = visit(route('system.users.index', [], false));

    $page->assertPresent('@copilot-open')
        ->assertVisible('@copilot-open')
        ->click('@copilot-open')
        ->assertSee('Copiloto de usuarios')
        ->assertSee('Buscar usuarios inactivos')
        ->assertSee('Proponer un restablecimiento')
        ->assertDontSee('Explicar accesos efectivos')
        ->assertPresent('@copilot-prompt')
        ->assertVisible('@copilot-prompt')
        ->type('@copilot-prompt', 'Propón un restablecimiento para Reset Target')
        ->assertButtonEnabled('@copilot-submit')
        ->press('Enviar')
        ->assertSee('Puedo proponer el restablecimiento, pero esta propuesta no es ejecutable todavía.')
        ->assertSee('Solo propuesta')
        ->assertButtonDisabled('@copilot-action-send_reset')
        ->assertDontSee('system.users.send-reset')
        ->assertDontSee('system.users-copilot.execute')
        ->assertDontSee('users.actions.send_reset')
        ->assertDontSee('native_tools')
        ->assertNoJavaScriptErrors();
});

it('confirms an executable copilot proposal from the browser flow', function () {
    $this->app['env'] = 'local';

    Notification::fake();

    $operator = browserCopilotOperator([
        'system.users.view',
        'system.users.send-reset',
        'system.users-copilot.view',
        'system.users-copilot.execute',
    ]);
    $target = User::factory()->create([
        'name' => 'Resettable User',
        'email' => 'resettable@example.test',
    ]);

    CopilotBrowserFake::write([
        browserCopilotResponse([
            'answer' => 'La propuesta está lista para confirmar.',
            'requires_confirmation' => true,
            'actions' => [
                [
                    'kind' => 'action_proposal',
                    'action_type' => 'send_reset',
                    'target' => [
                        'kind' => 'user',
                        'user_id' => $target->id,
                        'name' => $target->name,
                        'email' => $target->email,
                        'is_active' => true,
                    ],
                    'summary' => 'Envía un correo de restablecimiento de contraseña a Resettable User.',
                    'payload' => [
                        'reason' => 'copilot_confirmed_action',
                    ],
                    'can_execute' => true,
                    'deny_reason' => null,
                    'required_permissions' => ['system.users.send-reset', 'system.users-copilot.execute'],
                ],
            ],
        ]),
    ]);

    $this->actingAs($operator);

    $page = visit(route('system.users.index', [], false));

    $page->assertPresent('@copilot-open')
        ->assertVisible('@copilot-open')
        ->click('@copilot-open')
        ->assertPresent('@copilot-prompt')
        ->assertVisible('@copilot-prompt')
        ->type('@copilot-prompt', 'Necesito enviar un restablecimiento a Resettable User')
        ->assertButtonEnabled('@copilot-submit')
        ->press('Enviar')
        ->assertSee('La propuesta está lista para confirmar.')
        ->assertSee('Lista para confirmar')
        ->assertButtonEnabled('@copilot-action-send_reset')
        ->click('@copilot-action-send_reset')
        ->assertSee('Confirmar restablecimiento')
        ->assertButtonEnabled('@copilot-confirm-submit')
        ->click('@copilot-confirm-submit')
        ->assertSee('Correo de restablecimiento de contraseña enviado exitosamente.')
        ->assertSee('Resettable User · resettable@example.test')
        ->assertNoJavaScriptErrors();

    Notification::assertSentTo($target, ResetPassword::class);
});

it('renders resolution states for partial denied missing context and clarification responses', function () {
    $this->app['env'] = 'local';

    $operator = browserCopilotOperator([
        'system.users.view',
        'system.users-copilot.view',
    ]);

    CopilotBrowserFake::write([
        browserCopilotResponse([
            'answer' => 'Resolví la consulta disponible. La parte de acción no fue ejecutada.',
            'intent' => 'partial',
            'cards' => [[
                'kind' => 'partial_notice',
                'title' => 'Respuesta parcial',
                'summary' => 'Una parte quedó pendiente.',
                'data' => [
                    'segments' => [[
                        'text' => 'Crear usuario',
                        'status' => 'not_executed',
                        'reason' => 'faltan datos obligatorios',
                        'suggested_follow_up' => 'Indica email y rol',
                    ]],
                ],
            ]],
            'resolution' => [
                'state' => 'partial',
                'confidence' => 'high',
                'action_boundary' => 'blocked',
                'understood' => [],
                'unresolved' => [],
                'missing' => ['email', 'roles'],
                'denials' => [],
            ],
        ]),
        browserCopilotResponse([
            'answer' => 'No puedo mostrar credenciales.',
            'intent' => 'denied',
            'cards' => [[
                'kind' => 'denied',
                'title' => 'No puedo procesar esta solicitud',
                'summary' => 'Solicitud bloqueada.',
                'data' => [
                    'reason_code' => 'sensitive_data',
                    'category' => 'sensitive_data',
                    'message' => 'No puedo mostrar credenciales.',
                    'alternatives' => [],
                ],
            ]],
            'resolution' => [
                'state' => 'denied',
                'confidence' => 'high',
                'action_boundary' => 'blocked',
                'understood' => [],
                'unresolved' => [],
                'missing' => [],
                'denials' => [['reason_code' => 'sensitive_data']],
            ],
        ]),
        browserCopilotResponse([
            'answer' => 'Necesito que me indiques a qué usuario te refieres.',
            'intent' => 'ambiguous',
            'cards' => [[
                'kind' => 'clarification',
                'title' => 'Necesito una aclaracion',
                'summary' => 'Necesito contexto.',
                'data' => [
                    'reason' => 'missing_context',
                    'question' => 'Necesito que me indiques a qué usuario te refieres.',
                    'options' => [],
                ],
            ]],
            'resolution' => [
                'state' => 'missing_context',
                'confidence' => 'high',
                'action_boundary' => 'none',
                'understood' => [],
                'unresolved' => [],
                'missing' => [],
                'denials' => [],
            ],
        ]),
        browserCopilotResponse([
            'answer' => 'Necesito email y rol para continuar.',
            'intent' => 'ambiguous',
            'cards' => [[
                'kind' => 'clarification',
                'title' => 'Necesito una aclaracion',
                'summary' => 'Faltan datos.',
                'data' => [
                    'reason' => 'missing_slots',
                    'question' => 'Necesito email y rol para continuar.',
                    'options' => [],
                ],
            ]],
            'resolution' => [
                'state' => 'clarification_required',
                'confidence' => 'high',
                'action_boundary' => 'blocked',
                'understood' => [],
                'unresolved' => [],
                'missing' => ['email', 'roles'],
                'denials' => [],
            ],
        ]),
    ]);

    $this->actingAs($operator);

    $page = visit(route('system.users.index', [], false));

    $page->click('@copilot-open')
        ->type('@copilot-prompt', 'mixed')
        ->press('Enviar')
        ->assertSee('Respuesta parcial')
        ->assertSee('Accion bloqueada')
        ->assertSee('Faltan: email, roles')
        ->type('@copilot-prompt', 'secretos')
        ->press('Enviar')
        ->assertSee('Bloqueado por seguridad')
        ->type('@copilot-prompt', 'ese')
        ->press('Enviar')
        ->assertSee('Falta contexto')
        ->type('@copilot-prompt', 'crear')
        ->press('Enviar')
        ->assertSee('Necesita aclaracion')
        ->assertNoJavaScriptErrors();
});
