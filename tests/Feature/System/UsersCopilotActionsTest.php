<?php

use App\Ai\Support\CopilotActionProposal;
use App\Ai\Support\CopilotActionType;
use App\Ai\Support\CopilotConversationSnapshot;
use App\Models\Role;
use App\Models\SecurityAuditLog;
use App\Models\User;
use Database\Seeders\AccessModulePermissionsSeeder;
use Database\Seeders\AiCopilotPermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(AccessModulePermissionsSeeder::class);
    $this->seed(AiCopilotPermissionsSeeder::class);

    config(['ai-copilot.enabled' => true]);
});

function copilotActionOperator(array $permissions): User
{
    $role = Role::factory()->active()->create();
    $role->syncPermissions($permissions);

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function copilotActionPayload(string $actionType, User $target, ?string $conversationId = null, ?User $actor = null): array
{
    $targetPayload = CopilotActionProposal::userTarget($target);
    $payload = [
        'reason' => 'copilot_confirmed_action',
    ];
    $copilotActionType = CopilotActionType::tryFrom($actionType);

    if ($copilotActionType === null) {
        return [
            'conversation_id' => $conversationId ?? '018f0f66-5a1c-7d6e-b50c-4f3a2e0d5a11',
            'target' => $targetPayload,
            'payload' => $payload,
        ];
    }

    $proposal = copilotPersistActionProposal(
        actor: $actor,
        conversationId: $conversationId ?? '018f0f66-5a1c-7d6e-b50c-4f3a2e0d5a11',
        actionType: $copilotActionType,
        target: $targetPayload,
        payload: $payload,
        permissions: [],
    );

    return [
        'conversation_id' => $proposal['conversation_id'],
        'proposal_id' => $proposal['id'],
        'fingerprint' => $proposal['fingerprint'],
        'target' => $targetPayload,
        'payload' => $payload,
    ];
}

function copilotCreateUserPayload(Role $role, ?string $conversationId = null, ?User $actor = null): array
{
    $target = [
        'kind' => 'new_user',
        'name' => 'Laura Copilot',
        'email' => 'laura@example.com',
    ];
    $payload = [
        'name' => 'Laura Copilot',
        'email' => 'laura@example.com',
        'roles' => [$role->id],
    ];
    $proposal = copilotPersistActionProposal(
        actor: $actor,
        conversationId: $conversationId ?? '018f0f66-5a1c-7d6e-b50c-4f3a2e0d5a11',
        actionType: CopilotActionType::CreateUser,
        target: $target,
        payload: $payload,
        permissions: ['system.users.create', 'system.users.assign-role'],
    );

    return [
        'conversation_id' => $proposal['conversation_id'],
        'proposal_id' => $proposal['id'],
        'fingerprint' => $proposal['fingerprint'],
        'target' => $target,
        'payload' => $payload,
    ];
}

/**
 * @param  array<string, mixed>  $target
 * @param  array<string, mixed>  $payload
 * @param  list<string>  $permissions
 * @return array<string, mixed>
 */
function copilotPersistActionProposal(
    ?User $actor,
    string $conversationId,
    CopilotActionType $actionType,
    array $target,
    array $payload,
    array $permissions,
): array {
    $actor ??= auth()->user();
    $proposal = CopilotActionProposal::make(
        actionType: $actionType,
        target: $target,
        summary: 'Propuesta lista.',
        payload: $payload,
        canExecute: true,
        denyReason: null,
        requiredPermissions: $permissions,
    );

    if ($actor instanceof User) {
        DB::table('agent_conversations')->updateOrInsert(
            ['id' => $conversationId],
            [
                'user_id' => $actor->id,
                'title' => 'Propuesta de accion',
                ...CopilotConversationSnapshot::empty()->with([
                    'pending_action_proposal' => $proposal,
                ])->toDatabase(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    return [
        ...$proposal,
        'conversation_id' => $conversationId,
    ];
}

it('activates an inactive user through the copilot action endpoint', function () {
    $actor = copilotActionOperator(['system.users.deactivate', 'system.users-copilot.execute']);
    $target = User::factory()->inactive()->create();

    $this->actingAs($actor)
        ->postJson(route('system.users.copilot.actions', ['actionType' => 'activate']), copilotActionPayload('activate', $target))
        ->assertSuccessful()
        ->assertJsonPath('action_type', 'activate')
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('target.user_id', $target->id);

    expect($target->fresh()->is_active)->toBeTrue()
        ->and(SecurityAuditLog::query()->latest('occurred_at')->first()?->metadata)->toMatchArray([
            'target_user_id' => $target->id,
            'channel' => 'copilot',
            'module' => 'users',
            'conversation_id' => '018f0f66-5a1c-7d6e-b50c-4f3a2e0d5a11',
            'outcome' => 'success',
        ]);
});

it('returns a noop when activating an already active user', function () {
    $actor = copilotActionOperator(['system.users.deactivate', 'system.users-copilot.execute']);
    $target = User::factory()->active()->create();

    $this->actingAs($actor)
        ->postJson(route('system.users.copilot.actions', ['actionType' => 'activate']), copilotActionPayload('activate', $target))
        ->assertSuccessful()
        ->assertJsonPath('status', 'noop');
});

it('deactivates a user and clears their sessions', function () {
    $actor = copilotActionOperator(['system.users.deactivate', 'system.users-copilot.execute']);
    $coverageRole = Role::factory()->active()->create();
    $coverageRole->syncPermissions([
        'system.users.view',
        'system.users.assign-role',
        'system.roles.view',
    ]);

    $coverageUser = User::factory()->active()->create();
    $coverageUser->assignRole($coverageRole);

    $target = User::factory()->active()->create();

    DB::table('sessions')->insert([
        'id' => 'session-id',
        'user_id' => $target->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest',
        'payload' => base64_encode('session'),
        'last_activity' => now()->timestamp,
    ]);

    $this->actingAs($actor)
        ->postJson(route('system.users.copilot.actions', ['actionType' => 'deactivate']), copilotActionPayload('deactivate', $target))
        ->assertSuccessful()
        ->assertJsonPath('action_type', 'deactivate')
        ->assertJsonPath('status', 'success');

    expect($target->fresh()->is_active)->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(0);
});

it('prevents self deactivation through the copilot action endpoint', function () {
    $actor = copilotActionOperator(['system.users.deactivate', 'system.users-copilot.execute']);

    $this->actingAs($actor)
        ->postJson(route('system.users.copilot.actions', ['actionType' => 'deactivate']), copilotActionPayload('deactivate', $actor))
        ->assertForbidden();
});

it('prevents deactivating the last effective admin through the copilot action endpoint', function () {
    $targetRole = Role::factory()->active()->create();
    $targetRole->syncPermissions([
        'system.users.view',
        'system.users.assign-role',
        'system.roles.view',
    ]);

    $actorRole = Role::factory()->active()->create();
    $actorRole->syncPermissions([
        'system.users.deactivate',
        'system.users-copilot.execute',
    ]);

    $actor = User::factory()->create();
    $actor->assignRole($actorRole);

    $target = User::factory()->active()->create();
    $target->assignRole($targetRole);

    $this->actingAs($actor)
        ->postJson(route('system.users.copilot.actions', ['actionType' => 'deactivate']), copilotActionPayload('deactivate', $target))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['user']);
});

it('sends a password reset notification through the copilot action endpoint', function () {
    Notification::fake();

    $actor = copilotActionOperator(['system.users.send-reset', 'system.users-copilot.execute']);
    $target = User::factory()->create();

    $this->actingAs($actor)
        ->postJson(route('system.users.copilot.actions', ['actionType' => 'send_reset']), copilotActionPayload('send_reset', $target))
        ->assertSuccessful()
        ->assertJsonPath('action_type', 'send_reset')
        ->assertJsonPath('status', 'success');

    Notification::assertSentTo($target, ResetPassword::class);
});

it('forbids action execution without copilot execute permission', function () {
    $actor = copilotActionOperator(['system.users.deactivate']);
    $target = User::factory()->inactive()->create();

    $this->actingAs($actor)
        ->postJson(route('system.users.copilot.actions', ['actionType' => 'activate']), copilotActionPayload('activate', $target))
        ->assertForbidden();
});

it('forbids action execution without the functional permission', function () {
    $actor = copilotActionOperator(['system.users-copilot.execute']);
    $target = User::factory()->inactive()->create();

    $this->actingAs($actor)
        ->postJson(route('system.users.copilot.actions', ['actionType' => 'activate']), copilotActionPayload('activate', $target))
        ->assertForbidden();
});

it('rejects invalid action types and malformed payloads', function () {
    $actor = copilotActionOperator(['system.users.deactivate', 'system.users-copilot.execute']);
    $target = User::factory()->create();

    $this->actingAs($actor)
        ->postJson(route('system.users.copilot.actions', ['actionType' => 'invalid']), copilotActionPayload('invalid', $target))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['action_type']);

    $this->actingAs($actor)
        ->postJson(route('system.users.copilot.actions', ['actionType' => 'activate']), [
            'target' => ['kind' => 'user'],
            'payload' => [],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['target.user_id']);
});

it('creates a user through the confirmed copilot action without persisting the plaintext credential', function () {
    $actor = copilotActionOperator([
        'system.users.create',
        'system.users.assign-role',
        'system.users-copilot.execute',
    ]);
    $role = Role::factory()->active()->create([
        'name' => 'support',
        'display_name' => 'Soporte',
    ]);
    $conversationId = '018f0f66-5a1c-7d6e-b50c-4f3a2e0d5a99';

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => $actor->id,
        'title' => 'Alta guiada',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('agent_conversation_messages')->insert([
        'id' => '018f0f66-5a1c-7d6e-b50c-4f3a2e0d5b01',
        'conversation_id' => $conversationId,
        'user_id' => $actor->id,
        'agent' => 'users-copilot',
        'role' => 'assistant',
        'content' => 'Propuesta lista.',
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $messageCountBefore = DB::table('agent_conversation_messages')->count();

    $response = $this->actingAs($actor)
        ->postJson(
            route('system.users.copilot.actions', ['actionType' => 'create_user']),
            copilotCreateUserPayload($role, $conversationId),
        )
        ->assertSuccessful()
        ->assertJsonPath('action_type', 'create_user')
        ->assertJsonPath('status', 'success');

    /** @var array<string, mixed> $body */
    $body = $response->json();
    $password = data_get($body, 'credential.password');
    $createdUser = User::query()->where('email', 'laura@example.com')->first();

    expect($createdUser)->not->toBeNull()
        ->and($createdUser?->email_verified_at)->not->toBeNull()
        ->and($createdUser?->roles->pluck('id')->all())->toBe([$role->id])
        ->and(is_string($password))->toBeTrue()
        ->and(Hash::check($password, $createdUser?->password ?? ''))->toBeTrue()
        ->and($createdUser?->password)->not->toBe($password)
        ->and(DB::table('agent_conversation_messages')->count())->toBe($messageCountBefore)
        ->and(DB::table('agent_conversation_messages')
            ->where('content', 'like', "%{$password}%")
            ->orWhere('tool_results', 'like', "%{$password}%")
            ->orWhere('meta', 'like', "%{$password}%")
            ->count())->toBe(0)
        ->and(SecurityAuditLog::query()->latest('occurred_at')->first()?->metadata)->toMatchArray([
            'created_user_id' => $createdUser?->id,
            'email' => 'laura@example.com',
            'channel' => 'copilot',
            'module' => 'users',
            'conversation_id' => $conversationId,
            'outcome' => 'success',
        ])
        ->and(SecurityAuditLog::query()->latest('occurred_at')->first()?->metadata)->not->toHaveKey('password');
});

it('forbids confirmed create user execution without assign-role permission', function () {
    $actor = copilotActionOperator([
        'system.users.create',
        'system.users-copilot.execute',
    ]);
    $role = Role::factory()->active()->create();

    $this->actingAs($actor)
        ->postJson(
            route('system.users.copilot.actions', ['actionType' => 'create_user']),
            copilotCreateUserPayload($role),
        )
        ->assertForbidden();
});

it('forbids confirmed create user execution without copilot execute permission', function () {
    $actor = copilotActionOperator([
        'system.users.create',
        'system.users.assign-role',
    ]);
    $role = Role::factory()->active()->create();

    $this->actingAs($actor)
        ->postJson(
            route('system.users.copilot.actions', ['actionType' => 'create_user']),
            copilotCreateUserPayload($role),
        )
        ->assertForbidden();
});
