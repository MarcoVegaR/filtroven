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

class ActivateUserTool implements Tool
{
    public function __construct(protected User $actor) {}

    public function description(): Stringable|string
    {
        return 'Prepara una propuesta para activar un usuario existente sin ejecutar cambios.';
    }

    public function handle(Request $request): Stringable|string
    {
        $user = User::query()->find($request->integer('user_id'));

        if ($user === null) {
            return json_encode([
                'found' => false,
                'action' => null,
                'message' => __('No encontré el usuario solicitado para activar.'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        $requiredPermissions = ['system.users.deactivate', 'system.users-copilot.execute'];
        $hasFunctionalPermission = $this->actor->can('activate', $user);
        $hasCopilotExecutionPermission = $this->actor->hasPermissionTo('system.users-copilot.execute');
        $canExecute = $hasFunctionalPermission && $hasCopilotExecutionPermission;
        $denyReason = null;

        if (! $hasFunctionalPermission) {
            $denyReason = __('No tienes permiso para activar este usuario.');
        } elseif (! $hasCopilotExecutionPermission) {
            $denyReason = __('Te falta permiso para ejecutar acciones del copiloto.');
        }

        $summary = $user->is_active
            ? __('El usuario :name ya está activo. Puedes confirmar la acción si deseas revalidar el estado actual.', ['name' => $user->name])
            : __('Activa la cuenta de :name y restablece su acceso al sistema.', ['name' => $user->name]);

        return json_encode([
            'found' => true,
            'action' => CopilotActionProposal::make(
                actionType: CopilotActionType::Activate,
                target: CopilotActionProposal::userTarget($user),
                summary: $summary,
                payload: [
                    'reason' => 'copilot_confirmed_action',
                ],
                canExecute: $canExecute,
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
