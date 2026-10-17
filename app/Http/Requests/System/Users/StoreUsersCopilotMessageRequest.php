<?php

namespace App\Http\Requests\System\Users;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreUsersCopilotMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('system.users-copilot.view') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:'.(int) config('ai-copilot.limits.prompt_length', 4000)],
            'conversation_id' => ['nullable', 'uuid'],
            'subject_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'prompt.required' => __('Debes ingresar una solicitud para el copiloto.'),
            'prompt.max' => __('La solicitud del copiloto supera el limite permitido.'),
            'conversation_id.uuid' => __('La conversacion indicada no es valida.'),
            'subject_user_id.exists' => __('El usuario de contexto seleccionado no existe.'),
        ];
    }
}
