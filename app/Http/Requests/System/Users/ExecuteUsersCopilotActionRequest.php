<?php

namespace App\Http\Requests\System\Users;

use App\Ai\Support\CopilotActionType;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ExecuteUsersCopilotActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('system.users-copilot.execute') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'action_type' => $this->route('actionType'),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'action_type' => ['required', 'string', 'in:'.implode(',', array_column(CopilotActionType::cases(), 'value'))],
            'target' => ['required', 'array'],
            'target.kind' => ['required', 'string', Rule::in(['user', 'new_user'])],
            'target.user_id' => ['required_unless:action_type,'.CopilotActionType::CreateUser->value, 'integer', 'exists:users,id'],
            'target.name' => ['nullable', 'string', 'max:255'],
            'target.email' => ['nullable', 'email', 'max:255'],
            'target.is_active' => ['nullable', 'boolean'],
            'payload' => ['nullable', 'array'],
            'conversation_id' => ['nullable', 'uuid'],
            'proposal_id' => ['nullable', 'uuid'],
            'fingerprint' => ['nullable', 'string', 'size:64'],
            'payload.name' => ['required_if:action_type,'.CopilotActionType::CreateUser->value, 'string', 'max:255'],
            'payload.email' => ['required_if:action_type,'.CopilotActionType::CreateUser->value, 'email', 'max:255'],
            'payload.roles' => ['required_if:action_type,'.CopilotActionType::CreateUser->value, 'array', 'min:1'],
            'payload.roles.*' => ['integer', 'exists:roles,id'],
            'payload.role_labels' => ['nullable', 'array'],
            'payload.role_labels.*' => ['string', 'max:255'],
            'payload.reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $actionType = $this->input('action_type');
            $payload = $this->input('payload', []);
            $target = $this->input('target', []);

            if ($actionType !== CopilotActionType::CreateUser->value && is_array($payload)) {
                $createUserKeys = ['name', 'email', 'roles'];

                if (count(array_intersect(array_keys($payload), $createUserKeys)) > 0) {
                    $validator->errors()->add('payload', __('La carga enviada no coincide con la accion solicitada.'));
                }
            }

            if ($actionType === CopilotActionType::CreateUser->value && data_get($target, 'kind') !== 'new_user') {
                $validator->errors()->add('target.kind', __('La propuesta de alta debe incluir un objetivo de tipo nuevo usuario.'));
            }

            if ($actionType !== CopilotActionType::CreateUser->value && data_get($target, 'kind') !== 'user') {
                $validator->errors()->add('target.kind', __('La acción solicitada debe apuntar a un usuario existente.'));
            }
        });
    }

    public function actionType(): CopilotActionType
    {
        return CopilotActionType::from($this->string('action_type')->value());
    }

    public function targetUser(): User
    {
        return User::query()->findOrFail((int) data_get($this->validated(), 'target.user_id'));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'action_type.in' => __('La accion solicitada no es valida para el copiloto.'),
            'target.required' => __('Debes indicar el objetivo de la accion.'),
            'target.user_id.required_unless' => __('Debes indicar el usuario objetivo de la accion.'),
            'payload.email.required_if' => __('Debes indicar un correo valido para crear el usuario.'),
            'payload.roles.required_if' => __('Debes seleccionar al menos un rol para crear el usuario.'),
        ];
    }
}
