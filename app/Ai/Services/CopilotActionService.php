<?php

namespace App\Ai\Services;

use App\Actions\System\Users\CreateUserAction;
use App\Ai\Support\CopilotActionProposal;
use App\Ai\Support\CopilotActionType;
use App\Ai\Support\CopilotConversationSnapshot;
use App\Enums\SecurityEventType;
use App\Models\User;
use App\Services\SecurityAuditService;
use App\Support\System\Users\UserCreationRules;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CopilotActionService
{
    public function __construct(
        protected SecurityAuditService $securityAuditService,
        protected CreateUserAction $createUserAction,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function execute(
        User $actor,
        CopilotActionType $actionType,
        array $validated,
        ?string $ipAddress,
    ): array {
        if (! $actor->hasPermissionTo('system.users-copilot.execute')) {
            throw new AuthorizationException(__('No tienes permiso para ejecutar acciones del copiloto.'));
        }

        $this->assertPendingProposalMatches($actor, $actionType, $validated);

        $result = match ($actionType) {
            CopilotActionType::Activate => $this->activateUser(
                actor: $actor,
                target: User::query()->findOrFail((int) data_get($validated, 'target.user_id')),
                conversationId: data_get($validated, 'conversation_id'),
                ipAddress: $ipAddress,
            ),
            CopilotActionType::Deactivate => $this->deactivateUser(
                actor: $actor,
                target: User::query()->findOrFail((int) data_get($validated, 'target.user_id')),
                conversationId: data_get($validated, 'conversation_id'),
                ipAddress: $ipAddress,
            ),
            CopilotActionType::SendReset => $this->sendPasswordReset(
                actor: $actor,
                target: User::query()->findOrFail((int) data_get($validated, 'target.user_id')),
                conversationId: data_get($validated, 'conversation_id'),
                ipAddress: $ipAddress,
            ),
            CopilotActionType::CreateUser => $this->createUser(
                actor: $actor,
                validated: $validated,
                conversationId: data_get($validated, 'conversation_id'),
                ipAddress: $ipAddress,
            ),
        };

        $this->clearPendingProposal((string) data_get($validated, 'conversation_id'));

        return $result;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function createUser(User $actor, array $validated, ?string $conversationId, ?string $ipAddress): array
    {
        Gate::forUser($actor)->authorize('create', User::class);

        if (! $actor->hasPermissionTo('system.users.assign-role')) {
            throw new AuthorizationException(__('No tienes permiso para asignar roles desde el copiloto.'));
        }

        $password = $this->generatePassword();

        /** @var array{name: string, email: string, password: string, is_active: bool, roles: list<int>} $creationData */
        $creationData = Validator::make([
            'name' => data_get($validated, 'payload.name'),
            'email' => data_get($validated, 'payload.email'),
            'password' => $password,
            'is_active' => true,
            'roles' => data_get($validated, 'payload.roles', []),
        ], UserCreationRules::forGeneratedPassword(), UserCreationRules::messages())->validate();

        $user = $this->createUserAction->handle($creationData);

        $this->securityAuditService->record(
            eventType: SecurityEventType::UserCreated,
            userId: $actor->id,
            ipAddress: $ipAddress,
            metadata: [
                'created_user_id' => $user->id,
                'email' => $user->email,
                'roles' => $user->roles->pluck('id')->all(),
                'channel' => 'copilot',
                'module' => 'users',
                'conversation_id' => $conversationId,
                'outcome' => 'success',
            ],
        );

        return $this->resultEnvelope(
            actionType: CopilotActionType::CreateUser,
            target: CopilotActionProposal::userTarget($user),
            summary: __('Usuario creado exitosamente. Comparte la contraseña temporal por un canal seguro.'),
            status: 'success',
            conversationId: $conversationId,
            credential: [
                'kind' => 'one_time_password',
                'name' => $user->name,
                'email' => $user->email,
                'password' => $password,
                'notice' => __('Esta contraseña temporal solo se muestra una vez.'),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function assertPendingProposalMatches(User $actor, CopilotActionType $actionType, array $validated): void
    {
        $conversationId = data_get($validated, 'conversation_id');

        if (! is_string($conversationId) || $conversationId === '') {
            throw ValidationException::withMessages([
                'conversation_id' => __('Debes confirmar una propuesta vigente del copiloto.'),
            ]);
        }

        $conversation = DB::table('agent_conversations')
            ->select(['user_id', 'snapshot', 'snapshot_version', 'last_turn_at'])
            ->where('id', $conversationId)
            ->first();

        if ($conversation === null || (int) $conversation->user_id !== $actor->id) {
            throw ValidationException::withMessages([
                'conversation_id' => __('La propuesta no pertenece a una conversacion vigente.'),
            ]);
        }

        $proposal = CopilotConversationSnapshot::fromDatabase(
            $conversation->snapshot,
            $conversation->snapshot_version,
            $conversation->last_turn_at,
        )->pendingActionProposal();

        if (! is_array($proposal) || ($proposal['can_execute'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'proposal' => __('No hay una propuesta ejecutable pendiente para confirmar.'),
            ]);
        }

        $expiresAt = is_string($proposal['expires_at'] ?? null)
            ? CarbonImmutable::parse($proposal['expires_at'])
            : null;

        if ($expiresAt === null || $expiresAt->isPast()) {
            throw ValidationException::withMessages([
                'proposal' => __('La propuesta del copiloto vencio. Solicita una nueva propuesta antes de ejecutar.'),
            ]);
        }

        $proposalId = $proposal['id'] ?? null;
        $requestProposalId = data_get($validated, 'proposal_id');

        if (is_string($requestProposalId) && $proposalId !== $requestProposalId) {
            throw ValidationException::withMessages([
                'proposal_id' => __('La propuesta confirmada no coincide con la propuesta pendiente.'),
            ]);
        }

        if (($proposal['action_type'] ?? null) !== $actionType->value) {
            throw ValidationException::withMessages([
                'action_type' => __('La accion confirmada no coincide con la propuesta pendiente.'),
            ]);
        }

        $expectedFingerprint = CopilotActionProposal::fingerprint([
            'action_type' => $actionType->value,
            'target' => $proposal['target'] ?? null,
            'payload' => $proposal['payload'] ?? [],
            'required_permissions' => $proposal['required_permissions'] ?? [],
        ]);
        $proposalFingerprint = is_string($proposal['fingerprint'] ?? null) ? $proposal['fingerprint'] : '';
        $requestFingerprint = data_get($validated, 'fingerprint');

        if (! hash_equals($proposalFingerprint, $expectedFingerprint)) {
            throw ValidationException::withMessages([
                'fingerprint' => __('La propuesta pendiente fue modificada o ya no es confiable.'),
            ]);
        }

        if (is_string($requestFingerprint) && ! hash_equals($proposalFingerprint, $requestFingerprint)) {
            throw ValidationException::withMessages([
                'fingerprint' => __('La huella de la propuesta confirmada no coincide.'),
            ]);
        }

        if (($proposal['target'] ?? null) != data_get($validated, 'target')) {
            throw ValidationException::withMessages([
                'target' => __('El objetivo confirmado no coincide con la propuesta pendiente.'),
            ]);
        }

        if (($proposal['payload'] ?? []) != data_get($validated, 'payload', [])) {
            throw ValidationException::withMessages([
                'payload' => __('La carga confirmada no coincide con la propuesta pendiente.'),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function activateUser(User $actor, User $target, ?string $conversationId, ?string $ipAddress): array
    {
        Gate::forUser($actor)->authorize('activate', $target);

        if ($target->is_active) {
            return $this->resultEnvelope(
                actionType: CopilotActionType::Activate,
                target: CopilotActionProposal::userTarget($target),
                summary: __('El usuario :name ya estaba activo.', ['name' => $target->name]),
                status: 'noop',
                conversationId: $conversationId,
            );
        }

        $target->update(['is_active' => true]);

        $this->recordAudit(
            eventType: SecurityEventType::UserActivated,
            actor: $actor,
            target: $target,
            ipAddress: $ipAddress,
            conversationId: $conversationId,
            outcome: 'success',
        );

        return $this->resultEnvelope(
            actionType: CopilotActionType::Activate,
            target: CopilotActionProposal::userTarget($target),
            summary: __('Usuario activado exitosamente.'),
            status: 'success',
            conversationId: $conversationId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function deactivateUser(User $actor, User $target, ?string $conversationId, ?string $ipAddress): array
    {
        Gate::forUser($actor)->authorize('deactivate', $target);

        if ($target->id === $actor->id) {
            throw ValidationException::withMessages([
                'user' => [__('No puedes desactivar tu propia cuenta.')],
            ]);
        }

        if (User::isLastEffectiveAdmin($target)) {
            throw ValidationException::withMessages([
                'user' => [__('No es posible desactivar al último administrador efectivo del sistema.')],
            ]);
        }

        if (! $target->is_active) {
            return $this->resultEnvelope(
                actionType: CopilotActionType::Deactivate,
                target: CopilotActionProposal::userTarget($target),
                summary: __('El usuario :name ya estaba inactivo.', ['name' => $target->name]),
                status: 'noop',
                conversationId: $conversationId,
            );
        }

        $target->update(['is_active' => false]);
        DB::table('sessions')->where('user_id', $target->id)->delete();

        $this->recordAudit(
            eventType: SecurityEventType::UserDeactivated,
            actor: $actor,
            target: $target,
            ipAddress: $ipAddress,
            conversationId: $conversationId,
            outcome: 'success',
        );

        return $this->resultEnvelope(
            actionType: CopilotActionType::Deactivate,
            target: CopilotActionProposal::userTarget($target),
            summary: __('Usuario desactivado exitosamente.'),
            status: 'success',
            conversationId: $conversationId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function sendPasswordReset(User $actor, User $target, ?string $conversationId, ?string $ipAddress): array
    {
        Gate::forUser($actor)->authorize('sendReset', $target);

        Password::broker()->sendResetLink(['email' => $target->email]);

        $this->recordAudit(
            eventType: SecurityEventType::PasswordResetSent,
            actor: $actor,
            target: $target,
            ipAddress: $ipAddress,
            conversationId: $conversationId,
            outcome: 'success',
        );

        return $this->resultEnvelope(
            actionType: CopilotActionType::SendReset,
            target: CopilotActionProposal::userTarget($target),
            summary: __('Correo de restablecimiento de contraseña enviado exitosamente.'),
            status: 'success',
            conversationId: $conversationId,
        );
    }

    protected function recordAudit(
        SecurityEventType $eventType,
        User $actor,
        User $target,
        ?string $ipAddress,
        ?string $conversationId,
        string $outcome,
    ): void {
        $this->securityAuditService->record(
            eventType: $eventType,
            userId: $actor->id,
            ipAddress: $ipAddress,
            metadata: [
                'target_user_id' => $target->id,
                'email' => $target->email,
                'channel' => 'copilot',
                'module' => 'users',
                'conversation_id' => $conversationId,
                'outcome' => $outcome,
            ],
        );
    }

    protected function clearPendingProposal(string $conversationId): void
    {
        $conversation = DB::table('agent_conversations')
            ->select(['snapshot', 'snapshot_version', 'last_turn_at'])
            ->where('id', $conversationId)
            ->first();

        if ($conversation === null) {
            return;
        }

        $snapshot = CopilotConversationSnapshot::fromDatabase(
            $conversation->snapshot,
            $conversation->snapshot_version,
            $conversation->last_turn_at,
        )->with(['pending_action_proposal' => null]);

        DB::table('agent_conversations')
            ->where('id', $conversationId)
            ->update([
                ...$snapshot->toDatabase(),
                'updated_at' => now(),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function generatePassword(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $password = Str::password(length: 20, letters: true, numbers: true, symbols: true, spaces: false);

            if (Validator::make([
                'password' => $password,
            ], [
                'password' => UserCreationRules::generatedPasswordRules(),
            ])->passes()) {
                return $password;
            }
        }

        throw ValidationException::withMessages([
            'payload' => __('No pude generar una contraseña temporal válida para completar la alta guiada.'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>|null  $credential
     * @return array<string, mixed>
     */
    protected function resultEnvelope(
        CopilotActionType $actionType,
        array $target,
        string $summary,
        string $status,
        ?string $conversationId,
        ?array $credential = null,
    ): array {
        $result = [
            'ok' => true,
            'status' => $status,
            'action_type' => $actionType->value,
            'summary' => $summary,
            'target' => $target,
            'meta' => [
                'module' => 'users',
                'channel' => 'web',
                'conversation_id' => $conversationId,
            ],
        ];

        if ($credential !== null) {
            $result['credential'] = $credential;
        }

        return $result;
    }
}
