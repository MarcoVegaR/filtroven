<?php

namespace App\Http\Requests\System\Users;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BulkActionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $action = $this->input('action');

        if ($action === 'delete') {
            return $this->user()?->hasPermissionTo('system.users.delete') ?? false;
        }

        return $this->user()?->hasPermissionTo('system.users.deactivate') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'in:deactivate,activate,delete'],
            'ids' => [
                'required',
                'array',
                'min:1',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($this->input('action') !== 'delete') {
                        return;
                    }

                    $authId = $this->user()?->id;

                    if ($authId === null || ! is_array($value)) {
                        return;
                    }

                    if (in_array($authId, $value, true)) {
                        $fail('No puedes aplicar esta acción a tu propia cuenta.');
                    }
                },
            ],
            'ids.*' => ['integer', 'exists:users,id'],
        ];
    }
}
