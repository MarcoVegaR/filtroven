<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Determine whether the user can view any users.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('system.users.view');
    }

    /**
     * Determine whether the user can view a specific user.
     */
    public function view(User $user, User $model): bool
    {
        return $user->hasPermissionTo('system.users.view');
    }

    /**
     * Determine whether the user can create users.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('system.users.create');
    }

    /**
     * Determine whether the user can update a user.
     */
    public function update(User $user, User $model): bool
    {
        return $user->hasPermissionTo('system.users.update');
    }

    /**
     * Determine whether the user can delete a user.
     * Guard: cannot act on self.
     */
    public function delete(User $user, User $model): bool
    {
        if ($user->id === $model->id) {
            return false;
        }

        return $user->hasPermissionTo('system.users.delete');
    }

    /**
     * Determine whether the user can deactivate a user.
     * Guard: cannot deactivate self.
     */
    public function deactivate(User $user, User $model): bool
    {
        if ($user->id === $model->id) {
            return false;
        }

        return $user->hasPermissionTo('system.users.deactivate');
    }

    /**
     * Determine whether the user can activate a user.
     */
    public function activate(User $user, User $model): bool
    {
        return $user->hasPermissionTo('system.users.deactivate');
    }

    /**
     * Determine whether the user can sync roles for a user.
     * Guard: cannot sync own roles.
     */
    public function syncRoles(User $user, User $model): bool
    {
        if ($user->id === $model->id) {
            return false;
        }

        return $user->hasPermissionTo('system.users.assign-role');
    }

    /**
     * Determine whether the user can assign a role to another user.
     */
    public function assignRole(User $user, User $model): bool
    {
        return $user->hasPermissionTo('system.users.assign-role');
    }

    /**
     * Determine whether the user can send a password reset for a user.
     */
    public function sendReset(User $user, User $model): bool
    {
        return $user->hasPermissionTo('system.users.send-reset');
    }

    /**
     * Determine whether the user can export users.
     */
    public function export(User $user): bool
    {
        return $user->hasPermissionTo('system.users.export');
    }

    /**
     * Determine whether the user can bulk deactivate users.
     */
    public function bulkDeactivate(User $user): bool
    {
        return $user->hasPermissionTo('system.users.deactivate');
    }
}
