<?php

namespace App\Ai\Tools\System\Users;

use App\Ai\Services\UsersCopilotCapabilityExecutor;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetUsersMetricsTool implements Tool
{
    public function __construct(
        protected User $actor,
        protected ?UsersCopilotCapabilityExecutor $executor = null,
    ) {
        $this->executor ??= new UsersCopilotCapabilityExecutor;
    }

    public function description(): Stringable|string
    {
        return 'Devuelve metricas exactas de usuarios desde el backend sin reutilizar resultados de busqueda o listas truncadas.';
    }

    public function handle(Request $request): Stringable|string
    {
        if (! $this->actor->can('viewAny', User::class)) {
            throw new AuthorizationException(__('No tienes permiso para consultar metricas de usuarios.'));
        }

        $metric = $request->string('metric')->trim()->value();
        $capabilityKey = UsersCopilotCapabilityExecutor::capabilityKeyForMetric($metric);

        if ($capabilityKey === null) {
            return json_encode([
                'available' => false,
                'metric' => $metric,
                'message' => __('La metrica solicitada no esta soportada de forma deterministica.'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        return json_encode(
            $this->executor->execute($capabilityKey),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'metric' => $schema->string()->enum(UsersCopilotCapabilityExecutor::metricInputs())->required(),
        ];
    }
}
