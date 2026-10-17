<?php

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\AiCopilotPermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('is idempotent and preserves copilot permission attributes on repeated runs', function () {
    $permissionNames = ['system.users-copilot.execute', 'system.users-copilot.view'];

    $this->seed(AiCopilotPermissionsSeeder::class);
    $this->seed(AiCopilotPermissionsSeeder::class);

    $permissions = Permission::query()
        ->whereIn('name', $permissionNames)
        ->where('guard_name', 'web')
        ->orderBy('name')
        ->get();

    $superAdmin = Role::query()
        ->where('name', 'super-admin')
        ->where('guard_name', 'web')
        ->firstOrFail();

    expect($permissions)->toHaveCount(2)
        ->and($permissions->pluck('name')->all())->toBe(['system.users-copilot.execute', 'system.users-copilot.view'])
        ->and($permissions->pluck('display_name', 'name')->all())->toBe([
            'system.users-copilot.execute' => 'Ejecutar acciones del copiloto de usuarios',
            'system.users-copilot.view' => 'Ver copiloto de usuarios',
        ])
        ->and($permissions->every(fn (Permission $permission): bool => (bool) $permission->is_active))->toBeTrue()
        ->and($superAdmin->hasPermissionTo('system.users-copilot.view'))->toBeTrue()
        ->and($superAdmin->hasPermissionTo('system.users-copilot.execute'))->toBeTrue();
});
