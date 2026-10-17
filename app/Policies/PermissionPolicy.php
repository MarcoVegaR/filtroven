<?php

namespace App\Policies;

use App\Models\User;
use Spatie\Permission\Models\Permission;

class PermissionPolicy
{
    /**
     * Determine whether the user can view any permissions.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('system.permissions.view');
    }

    /**
     * Determine whether the user can view a specific permission.
     */
    public function view(User $user, Permission $permission): bool
    {
        return $user->hasPermissionTo('system.permissions.view');
    }
}
