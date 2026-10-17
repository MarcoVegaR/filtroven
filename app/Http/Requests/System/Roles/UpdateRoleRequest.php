<?php

namespace App\Http\Requests\System\Roles;

use App\Models\Role;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('update', $this->route('role'));
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if (! is_array($this->input('permissions'))) {
            $this->merge(['permissions' => []]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Role $role */
        $role = $this->route('role');

        $nameRules = ['required', 'string', 'max:255', Rule::unique('roles', 'name')->ignore($role->id)];

        // Protect the super-admin role name from being changed
        if ($role->name === 'super-admin' && $this->input('name') !== 'super-admin') {
            $nameRules[] = Rule::in(['super-admin']);
        }

        return [
            'name' => $nameRules,
            'display_name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'permissions' => ['array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.in' => __('El nombre del rol super-admin no puede ser modificado.'),
        ];
    }
}
