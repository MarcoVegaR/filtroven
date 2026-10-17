<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use OwenIt\Auditing\AuditableObserver;
use OwenIt\Auditing\Models\Audit;

// laravel-auditing disables itself in console (artisan test) context when audit.console=false.
// We re-enable it and register the observer manually before each test.
beforeEach(function () {
    config(['audit.console' => true]);

    // Force the AuditableObserver to be re-registered since bootAuditable() skipped it at boot time
    User::observe(AuditableObserver::class);
    Role::observe(AuditableObserver::class);
    Permission::observe(AuditableObserver::class);
});

test('creating a User records an audits row with event=created', function () {
    $user = User::factory()->create(['name' => 'Alice Audit']);

    $audit = Audit::query()
        ->where('auditable_type', User::class)
        ->where('auditable_id', $user->id)
        ->where('event', 'created')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->new_values)->toHaveKey('name')
        ->and($audit->new_values['name'])->toBe('Alice Audit');
});

test('updating User name records an audits row with old and new values', function () {
    $user = User::factory()->create(['name' => 'Before']);

    $user->update(['name' => 'After']);

    $audit = Audit::query()
        ->where('auditable_type', User::class)
        ->where('auditable_id', $user->id)
        ->where('event', 'updated')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->old_values['name'])->toBe('Before')
        ->and($audit->new_values['name'])->toBe('After');
});

test('creating a Role records an audits row with correct auditable_type', function () {
    $role = Role::create(['name' => 'manager', 'guard_name' => 'web']);

    $audit = Audit::query()
        ->where('auditable_type', Role::class)
        ->where('auditable_id', $role->id)
        ->where('event', 'created')
        ->first();

    expect($audit)->not->toBeNull();
});

test('updating a Permission name records an audits row with old and new values', function () {
    $permission = Permission::create(['name' => 'view-reports', 'guard_name' => 'web']);
    Audit::query()->delete(); // reset audits

    $permission->update(['name' => 'view-all-reports']);

    $audit = Audit::query()
        ->where('auditable_type', Permission::class)
        ->where('auditable_id', $permission->id)
        ->where('event', 'updated')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->old_values['name'])->toBe('view-reports')
        ->and($audit->new_values['name'])->toBe('view-all-reports');
});
