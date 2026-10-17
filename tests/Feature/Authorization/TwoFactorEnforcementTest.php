<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

test('super-admin without 2FA confirmed is redirected to security settings in non-local environment', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // Set app environment to non-local (middleware checks app()->environment('local'))
    $this->app['env'] = 'staging';

    $user = User::factory()->withSuperAdmin()->create([
        'two_factor_confirmed_at' => null,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('security.edit'));
});

test('super-admin with 2FA confirmed passes through in non-local environment', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->app['env'] = 'production';

    $user = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();
});

test('super-admin without 2FA passes through in local environment', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->app['env'] = 'local';

    $user = User::factory()->withSuperAdmin()->create([
        'two_factor_confirmed_at' => null,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();
});

test('non-super-admin user without 2FA is not redirected by 2FA enforcement middleware', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->app['env'] = 'production';

    $user = User::factory()->create([
        'two_factor_confirmed_at' => null,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();
});
