<?php

namespace App\Actions\System\Users;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

class CreateUserAction
{
    /**
     * @param  array{name: string, email: string, password: string, is_active?: bool, roles: list<int>}  $attributes
     */
    public function handle(array $attributes): User
    {
        $user = User::query()->create([
            'name' => $attributes['name'],
            'email' => Str::lower($attributes['email']),
            'password' => $attributes['password'],
            'is_active' => $attributes['is_active'] ?? true,
        ]);

        $user->forceFill([
            'email_verified_at' => now(),
        ])->save();

        $user->syncRoles(
            Role::active()->whereIn('id', $attributes['roles'])->get()
        );

        return $user->load('roles');
    }
}
