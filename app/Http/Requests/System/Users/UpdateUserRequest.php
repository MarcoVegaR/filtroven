<?php

namespace App\Http\Requests\System\Users;

use App\Concerns\PasswordValidationRules;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('update', $this->route('user'));
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('roles') && ! is_array($this->input('roles'))) {
            $this->merge(['roles' => []]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->route('user');
        $activeRoleIds = Role::active()->pluck('id')->all();

        $passwordRules = $this->filled('password')
            ? $this->passwordRules()
            : ['nullable', 'string'];

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'password' => $passwordRules,
            'is_active' => ['boolean'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['integer', Rule::in($activeRoleIds)],
        ];
    }

    /**
     * Get custom error messages for validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'roles.*.in' => __('Uno o más roles seleccionados no son válidos o están inactivos.'),
        ];
    }
}
