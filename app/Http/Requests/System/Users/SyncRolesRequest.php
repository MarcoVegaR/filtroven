<?php

namespace App\Http\Requests\System\Users;

use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SyncRolesRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        /** @var User $targetUser */
        $targetUser = $this->route('user');

        return Gate::allows('syncRoles', $targetUser);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $activeRoleIds = Role::active()->pluck('id')->all();

        return [
            'roles' => ['required', 'array'],
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
