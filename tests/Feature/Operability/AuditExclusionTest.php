<?php

use App\Models\User;
use OwenIt\Auditing\AuditableObserver;
use OwenIt\Auditing\Models\Audit;

beforeEach(function () {
    config(['audit.console' => true]);

    // Re-register observer since auditing is disabled at model boot time in console context
    User::observe(AuditableObserver::class);
});

test('updating password records an audit row but password is not in old or new values', function () {
    $user = User::factory()->create();

    $user->update(['password' => bcrypt('new-secret-password')]);

    // The audit row EXISTS (update is audited) — but the password key must be absent
    $audit = Audit::query()
        ->where('auditable_type', User::class)
        ->where('auditable_id', $user->id)
        ->where('event', 'updated')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->old_values)->not->toHaveKey('password')
        ->and($audit->new_values)->not->toHaveKey('password');
});

test('enabling 2FA (two_factor_secret set) does not appear in audit values', function () {
    $user = User::factory()->create();

    $user->forceFill([
        'two_factor_secret' => encrypt('TOTPSECRET'),
        'two_factor_recovery_codes' => encrypt(json_encode(['recovery1'])),
    ])->save();

    $audit = Audit::query()
        ->where('auditable_type', User::class)
        ->where('auditable_id', $user->id)
        ->where('event', 'updated')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->new_values)->not->toHaveKey('two_factor_secret')
        ->and($audit->new_values)->not->toHaveKey('two_factor_recovery_codes');
});

test('updating remember_token does not appear in audit values', function () {
    $user = User::factory()->create();

    $user->forceFill(['remember_token' => 'some-token-value'])->save();

    $audit = Audit::query()
        ->where('auditable_type', User::class)
        ->where('auditable_id', $user->id)
        ->where('event', 'updated')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->old_values)->not->toHaveKey('remember_token')
        ->and($audit->new_values)->not->toHaveKey('remember_token');
});

test('login does NOT create a row in the audits table', function () {
    $user = User::factory()->create();

    // Reset any audit rows from user creation
    Audit::query()->delete();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    // Login is a security event, not a model change — must NOT appear in audits table
    expect(Audit::query()->count())->toBe(0);
});
