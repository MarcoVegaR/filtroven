<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessModulePermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(AccessModulePermissionsSeeder::class);
});

it('deactivates a user setting is_active to false', function () {
    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    // Create a second admin so first admin is not last effective admin
    User::factory()->withSuperAdmin()->withTwoFactor()->create();

    $target = User::factory()->active()->create();

    $this->actingAs($admin)
        ->patch(route('system.users.deactivate', $target))
        ->assertRedirect();

    expect($target->fresh()->is_active)->toBeFalse();
});

it('activates a user setting is_active to true', function () {
    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();
    $target = User::factory()->inactive()->create();

    $this->actingAs($admin)
        ->patch(route('system.users.activate', $target))
        ->assertRedirect();

    expect($target->fresh()->is_active)->toBeTrue();
});

it('[CRITICAL] deactivated user cannot login via Fortify block', function () {
    // [CRITICAL] Fortify must throw ValidationException for inactive user with correct credentials.
    $user = User::factory()->inactive()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertGuest();
    $response->assertSessionHasErrors([config('fortify.username')]);
});

it('[CRITICAL] wrong credentials for inactive user return null, not enumeration', function () {
    // [CRITICAL] Wrong credentials for inactive user must NOT reveal account status.
    // The authenticateUsing callback must return null (not throw ValidationException).
    $user = User::factory()->inactive()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();

    // The response should not contain the "desactivada" message anywhere —
    // that message is only thrown for CORRECT credentials + inactive user.
    // Wrong credentials should fall through to Fortify's generic auth failure.
    // We just verify the user is NOT logged in and the specific message isn't shown.
    $responseContent = $response->getContent().json_encode($response->getSession()?->all());
    expect($responseContent)->not->toContain('desactivada');
});

it('[CRITICAL] cannot deactivate self', function () {
    // [CRITICAL] Self-deactivation must be blocked — UserPolicy::deactivate() returns false for self.
    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    $this->actingAs($admin)
        ->patch(route('system.users.deactivate', $admin))
        ->assertForbidden(); // Policy blocks self-deactivate

    expect($admin->fresh()->is_active)->toBeTrue();
});

it('[CRITICAL] cannot deactivate last effective admin', function () {
    // [CRITICAL] Deactivating the last effective admin must be blocked.
    // ValidationException from a web POST request redirects back with session errors (302).

    // Create deactivator with ONLY deactivate + view permission (NOT assign-role, NOT roles.view)
    // This means the deactivator is NOT an effective admin, so lastAdmin remains the only one.
    $deactivatorRole = Role::factory()->active()->create();
    $deactivatorRole->syncPermissions([
        'system.users.deactivate',
        'system.users.view',
        // Deliberately NOT including assign-role or roles.view so deactivator is not an effective admin
    ]);
    $deactivator = User::factory()->create();
    $deactivator->assignRole($deactivatorRole);

    // Last effective admin (super-admin has all 3 required permissions)
    $lastAdmin = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    $response = $this->actingAs($deactivator)
        ->patch(route('system.users.deactivate', $lastAdmin));

    // ValidationException on a web request → 302 redirect with session errors
    $response->assertRedirect();

    // Last admin must remain active — this is the critical check
    expect($lastAdmin->fresh()->is_active)->toBeTrue();

    // Also verify the session has errors from the ValidationException
    $response->assertSessionHasErrors(['user']);
});

it('deactivating a user deletes their sessions', function () {
    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    // Create a second admin so first admin is not last effective admin
    User::factory()->withSuperAdmin()->withTwoFactor()->create();

    $target = User::factory()->active()->create();

    // Simulate sessions for the target user
    DB::table('sessions')->insert([
        'id' => 'session-abc-1',
        'user_id' => $target->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Mozilla',
        'payload' => 'test',
        'last_activity' => now()->timestamp,
    ]);
    DB::table('sessions')->insert([
        'id' => 'session-abc-2',
        'user_id' => $target->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Mozilla',
        'payload' => 'test',
        'last_activity' => now()->timestamp,
    ]);

    expect(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(2);

    $this->actingAs($admin)
        ->patch(route('system.users.deactivate', $target))
        ->assertRedirect();

    expect(DB::table('sessions')->where('user_id', $target->id)->count())->toBe(0);
});
