<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('does not seed fixture users in production by default', function () {
    config()->set('app.env', 'production');
    config()->set('app.allow_production_test_seed', false);

    $this->seed(DatabaseSeeder::class);

    expect(User::query()->where('email', 'test@mailinator.com')->exists())->toBeFalse();
});

it('seeds fixture users in production when explicitly enabled', function () {
    config()->set('app.env', 'production');
    config()->set('app.allow_production_test_seed', true);

    $this->seed(DatabaseSeeder::class);

    $user = User::query()->where('email', 'test@mailinator.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->is_active)->toBeTrue()
        ->and($user->hasRole('super-admin'))->toBeTrue();
});
