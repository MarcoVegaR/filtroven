<?php

use App\Enums\SecurityEventType;
use App\Models\Role;
use App\Models\SecurityAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Log;

test('successful login records login_success in security_audit_log', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    expect(SecurityAuditLog::query()
        ->where('event_type', SecurityEventType::LoginSuccess->value)
        ->where('user_id', $user->id)
        ->exists()
    )->toBeTrue();
});

test('failed login records login_failed with null user_id and email in metadata', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $row = SecurityAuditLog::query()
        ->where('event_type', SecurityEventType::LoginFailed->value)
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->user_id)->toBeNull()
        ->and($row->metadata)->toHaveKey('email_attempted')
        ->and($row->metadata['email_attempted'])->toBe($user->email);
});

test('failed login metadata does not contain password', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $row = SecurityAuditLog::query()
        ->where('event_type', SecurityEventType::LoginFailed->value)
        ->first();

    expect($row->metadata)->not->toHaveKey('password');
});

test('logout records logout event with correct user_id', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('logout'));

    expect(SecurityAuditLog::query()
        ->where('event_type', SecurityEventType::Logout->value)
        ->where('user_id', $user->id)
        ->exists()
    )->toBeTrue();
});

test('enabling 2FA records 2fa_enabled event', function () {
    $user = User::factory()->create();

    $user->forceFill(['two_factor_confirmed_at' => null])->save();
    SecurityAuditLog::query()->delete(); // reset

    $user->forceFill(['two_factor_confirmed_at' => now()])->save();

    expect(SecurityAuditLog::query()
        ->where('event_type', SecurityEventType::TwoFactorEnabled->value)
        ->where('user_id', $user->id)
        ->exists()
    )->toBeTrue();
});

test('disabling 2FA records 2fa_disabled event', function () {
    $user = User::factory()->create();

    $user->forceFill(['two_factor_confirmed_at' => now()])->save();
    SecurityAuditLog::query()->delete(); // reset

    $user->forceFill(['two_factor_confirmed_at' => null])->save();

    expect(SecurityAuditLog::query()
        ->where('event_type', SecurityEventType::TwoFactorDisabled->value)
        ->where('user_id', $user->id)
        ->exists()
    )->toBeTrue();
});

test('assigning a role records role_assigned with role name and assigned_by actor', function () {
    $actor = User::factory()->create();
    $user = User::factory()->create();

    /** @var Role $role */
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);

    $this->actingAs($actor);
    $user->assignRole($role);

    $row = SecurityAuditLog::query()
        ->where('event_type', SecurityEventType::RoleAssigned->value)
        ->where('user_id', $user->id)
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->metadata['role'])->toBe('editor')
        ->and($row->metadata['assigned_by'])->toBe($actor->id);
});

test('revoking a role records role_revoked with role name and revoked_by actor', function () {
    $actor = User::factory()->create();
    $user = User::factory()->create();

    /** @var Role $role */
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
    $user->assignRole($role);
    SecurityAuditLog::query()->delete(); // reset

    $this->actingAs($actor);
    $user->removeRole($role);

    $row = SecurityAuditLog::query()
        ->where('event_type', SecurityEventType::RoleRevoked->value)
        ->where('user_id', $user->id)
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->metadata['role'])->toBe('editor')
        ->and($row->metadata['revoked_by'])->toBe($actor->id);
});

test('successful login writes to the security log channel', function () {
    $spy = Log::spy();

    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $spy->shouldHaveReceived('channel')
        ->with('security')
        ->atLeast()
        ->once();
});
