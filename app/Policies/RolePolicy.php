<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

class RolePolicy
{
    /**
     * Determine whether the user can view any roles.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('system.roles.view');
    }

    /**
     * Determine whether the user can view a specific role.
     */
    public function view(User $user, Role $role): bool
    {
        return $user->hasPermissionTo('system.roles.view');
    }

    /**
     * Determine whether the user can create roles.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('system.roles.create');
    }

    /**
     * Determine whether the user can update the role.
     * System roles (is_system = true) cannot be modified if the column exists.
     */
    public function update(User $user, Role $role): bool
    {
        return $user->hasPermissionTo('system.roles.update');
    }

    /**
     * Determine whether the user can delete the role.
     * The super-admin role is protected from deletion.
     */
    public function delete(User $user, Role $role): bool
    {
        if ($role->name === 'super-admin') {
            return false;
        }

        return $user->hasPermissionTo('system.roles.delete');
    }

    /**
     * Determine whether the user can deactivate the role.
     */
    public function deactivate(User $user, Role $role): bool
    {
        if ($role->name === 'super-admin') {
            return false;
        }

        return $user->hasPermissionTo('system.roles.deactivate');
    }

    /**
     * Determine whether the user can activate the role.
     */
    public function activate(User $user, Role $role): bool
    {
        return $user->hasPermissionTo('system.roles.deactivate');
    }
}
