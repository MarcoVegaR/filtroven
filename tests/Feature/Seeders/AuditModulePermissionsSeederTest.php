<?php

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\AuditModulePermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('is idempotent and preserves audit permission attributes on repeated runs', function () {
    $permissionNames = ['system.audit.export', 'system.audit.view'];

    $this->seed(AuditModulePermissionsSeeder::class);
    $this->seed(AuditModulePermissionsSeeder::class);

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
        ->and($permissions->pluck('name')->all())->toBe(['system.audit.export', 'system.audit.view'])
        ->and($permissions->pluck('display_name', 'name')->all())->toBe([
            'system.audit.export' => 'Exportar auditoría',
            'system.audit.view' => 'Ver auditoría',
        ])
        ->and($permissions->every(fn (Permission $permission): bool => (bool) $permission->is_active))->toBeTrue()
        ->and($superAdmin->hasPermissionTo('system.audit.view'))->toBeTrue()
        ->and($superAdmin->hasPermissionTo('system.audit.export'))->toBeTrue();
});
