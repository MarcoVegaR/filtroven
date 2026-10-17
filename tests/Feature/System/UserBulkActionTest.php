<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessModulePermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(AccessModulePermissionsSeeder::class);
});

it('bulk deactivates valid user IDs', function () {
    // withTwoFactor() required because super-admin actor in testing env needs 2FA
    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    // Create extra super-admin so admin is not the last
    User::factory()->withSuperAdmin()->withTwoFactor()->create();

    $users = User::factory()->count(3)->active()->create();
    $ids = $users->pluck('id')->all();

    $this->actingAs($admin)
        ->post(route('system.users.bulk'), [
            'action' => 'deactivate',
            'ids' => $ids,
        ])
        ->assertRedirect(route('system.users.index'));

    foreach ($users as $user) {
        expect($user->fresh()->is_active)->toBeFalse();
    }
});

it('bulk deactivate skips self', function () {
    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    // Extra super-admin to avoid last admin protection on others
    User::factory()->withSuperAdmin()->withTwoFactor()->create();

    $otherUser = User::factory()->active()->create();

    $response = $this->actingAs($admin)
        ->post(route('system.users.bulk'), [
            'action' => 'deactivate',
            'ids' => [$admin->id, $otherUser->id],
        ])
        ->assertRedirect(route('system.users.index'));

    // Self must remain active (skipped with error)
    expect($admin->fresh()->is_active)->toBeTrue();
    // Other user was deactivated
    expect($otherUser->fresh()->is_active)->toBeFalse();
    // Session should contain warning about skipped self
    expect($response->getSession()->get('warning'))->toContain('propia cuenta');
});

it('bulk deactivate skips last effective admin and reports error', function () {
    // [CRITICAL] Last effective admin protection in bulk action
    // Create deactivator with bulk permission but NOT super-admin (no 2FA needed)
    $deactivatorRole = Role::factory()->active()->create();
    $deactivatorRole->syncPermissions([
        'system.users.deactivate',
        'system.users.view',
    ]);
    $deactivator = User::factory()->create();
    $deactivator->assignRole($deactivatorRole);

    $lastAdmin = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    $response = $this->actingAs($deactivator)
        ->post(route('system.users.bulk'), [
            'action' => 'deactivate',
            'ids' => [$lastAdmin->id],
        ])
        ->assertRedirect(route('system.users.index'));

    // Last admin must remain active
    expect($lastAdmin->fresh()->is_active)->toBeTrue();
    // Warning should mention last admin
    expect($response->getSession()->get('warning'))->toContain('último administrador');
});

it('partial success returns structured warning with self-skip info', function () {
    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    // Extra super-admin to avoid last admin protection
    User::factory()->withSuperAdmin()->withTwoFactor()->create();

    $successUser = User::factory()->active()->create();

    $response = $this->actingAs($admin)
        ->post(route('system.users.bulk'), [
            'action' => 'deactivate',
            'ids' => [$successUser->id, $admin->id], // admin is self — will be skipped
        ])
        ->assertRedirect(route('system.users.index'));

    // 1 success, 1 skipped self
    expect($successUser->fresh()->is_active)->toBeFalse();
    expect($admin->fresh()->is_active)->toBeTrue();

    expect($response->getSession()->get('warning'))->not->toBeNull();
    expect($response->getSession()->get('success'))->toContain('1');
});

it('returns 403 for user without bulk deactivate permission', function () {
    // Non-super-admin user with only view permission — no 2FA concern
    $viewRole = Role::factory()->active()->create();
    $viewRole->syncPermissions(['system.users.view']); // No deactivate permission

    $actor = User::factory()->create();
    $actor->assignRole($viewRole);

    $target = User::factory()->active()->create();

    $this->actingAs($actor)
        ->post(route('system.users.bulk'), [
            'action' => 'deactivate',
            'ids' => [$target->id],
        ])
        ->assertForbidden();
});

it('rejects bulk delete payload when it includes self user id', function () {
    $deleteRole = Role::factory()->active()->create();
    $deleteRole->syncPermissions(['system.users.delete']);

    $actor = User::factory()->create();
    $actor->assignRole($deleteRole);

    $target = User::factory()->active()->create();

    $this->actingAs($actor)
        ->from(route('system.users.index'))
        ->post(route('system.users.bulk'), [
            'action' => 'delete',
            'ids' => [$target->id, $actor->id],
        ])
        ->assertRedirect(route('system.users.index'))
        ->assertSessionHasErrors(['ids']);

    expect(User::query()->whereKey($actor->id)->exists())->toBeTrue()
        ->and(User::query()->whereKey($target->id)->exists())->toBeTrue();
});
