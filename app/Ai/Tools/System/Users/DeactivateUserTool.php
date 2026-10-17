<?php

namespace App\Ai\Tools\System\Users;

use App\Ai\Support\CopilotActionProposal;
use App\Ai\Support\CopilotActionType;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class DeactivateUserTool implements Tool
{
    public function __construct(protected User $actor) {}

    public function description(): Stringable|string
    {
        return 'Prepara una propuesta para desactivar un usuario sin ejecutar cambios.';
    }

    public function handle(Request $request): Stringable|string
    {
        $user = User::query()->find($request->integer('user_id'));

        if ($user === null) {
            return json_encode([
                'found' => false,
                'action' => null,
                'message' => __('No encontré el usuario solicitado para desactivar.'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        $requiredPermissions = ['system.users.deactivate', 'system.users-copilot.execute'];
        $hasFunctionalPermission = $this->actor->can('deactivate', $user);
        $hasCopilotExecutionPermission = $this->actor->hasPermissionTo('system.users-copilot.execute');
        $denyReason = null;

        if (! $hasFunctionalPermission) {
            $denyReason = $this->actor->id === $user->id
                ? __('No puedes desactivar tu propia cuenta.')
                : __('No tienes permiso para desactivar este usuario.');
        } elseif (User::isLastEffectiveAdmin($user)) {
            $denyReason = __('No es posible desactivar al último administrador efectivo del sistema.');
        } elseif (! $hasCopilotExecutionPermission) {
            $denyReason = __('Te falta permiso para ejecutar acciones del copiloto.');
        }

        $summary = $user->is_active
            ? __('Desactiva la cuenta de :name y cierra sus sesiones activas.', ['name' => $user->name])
            : __('El usuario :name ya está inactivo. Puedes confirmar la acción si deseas validar el estado actual.', ['name' => $user->name]);

        return json_encode([
            'found' => true,
            'action' => CopilotActionProposal::make(
                actionType: CopilotActionType::Deactivate,
                target: CopilotActionProposal::userTarget($user),
                summary: $summary,
                payload: [
                    'reason' => 'copilot_confirmed_action',
                ],
                canExecute: $denyReason === null,
                denyReason: $denyReason,
                requiredPermissions: $requiredPermissions,
            ),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'user_id' => $schema->integer()->required(),
        ];
    }
}
