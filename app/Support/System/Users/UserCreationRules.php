<?php

namespace App\Support\System\Users;

use App\Concerns\PasswordValidationRules;
use App\Models\Role;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class UserCreationRules
{
    use PasswordValidationRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public static function forRequest(): array
    {
        return (new self)->rules((new self)->passwordRules());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public static function forGeneratedPassword(): array
    {
        return (new self)->rules((new self)->generatedPasswordValidationRules());
    }

    /**
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    public static function generatedPasswordRules(): array
    {
        return (new self)->generatedPasswordValidationRules();
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'roles.required' => __('Debes seleccionar al menos un rol para el usuario.'),
            'roles.min' => __('Debes seleccionar al menos un rol para el usuario.'),
            'roles.*.in' => __('Uno o más roles seleccionados no son válidos o están inactivos.'),
        ];
    }

    /**
     * @param  array<int, ValidationRule|array<mixed>|string>  $passwordRules
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function rules(array $passwordRules): array
    {
        $activeRoleIds = Role::active()->pluck('id')->all();

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => $passwordRules,
            'is_active' => ['boolean'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['integer', Rule::in($activeRoleIds)],
        ];
    }

    /**
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function generatedPasswordValidationRules(): array
    {
        return array_values(array_filter(
            $this->passwordRules(),
            fn (mixed $rule): bool => $rule !== 'confirmed',
        ));
    }
}
