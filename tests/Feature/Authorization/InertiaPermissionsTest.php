<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

test('authenticated super-admin has non-empty permissions array in inertia shared props', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // Use local env to bypass 2FA enforcement middleware so we can reach the dashboard
    $this->app['env'] = 'local';

    $user = User::factory()->withSuperAdmin()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('auth.permissions')
            ->where('auth.permissions', fn ($permissions) => count($permissions) === 7)
        );
});

test('authenticated super-admin inertia props contain all baseline permission names', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->app['env'] = 'local';

    $user = User::factory()->withSuperAdmin()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.permissions', fn ($permissions) => collect($permissions)->contains('system.roles.view')
                && collect($permissions)->contains('system.users.view')
                && collect($permissions)->contains('system.permissions.view')
            )
        );
});

test('authenticated user without any role has empty permissions array in inertia shared props', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.permissions', [])
        );
});

test('unauthenticated request to login page has empty permissions array in inertia shared props', function () {
    // No user is authenticated — the login page is an Inertia page shared by HandleInertiaRequests.
    // auth.permissions must be empty/absent so unauthenticated users receive no permission data.
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.permissions', [])
        );
});

// ── PRD-04 additions: explicit auth.permissions array-shape contract ─────────
// These tests assert the shape consumed by resources/js/hooks/use-can.ts:
// - auth.permissions is always present (never missing/null)
// - auth.permissions is always a JSON array (not a plain value)
// - each element in the array is a string following {module}.{resource}.{action}

test('auth.permissions is always present as an array in inertia shared props for authenticated users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            // has() asserts the key exists and is not missing — not null, not absent
            ->has('auth.permissions')
            // collect() works on both PHP arrays and Illuminate Collections from AssertableInertia
            ->where('auth.permissions', fn ($permissions) => collect($permissions)->count() === 0)
        );
});

test('auth.permissions is always an array — never null or missing — regardless of role assignment', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // User with a role → non-empty array
    $withRole = User::factory()->withSuperAdmin()->create();
    $this->app['env'] = 'local';

    $this->actingAs($withRole)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('auth.permissions')
            ->where('auth.permissions', fn ($v) => collect($v)->count() > 0)
        );

    // User without any role → empty collection (not null, not missing)
    $withoutRole = User::factory()->create();

    $this->actingAs($withoutRole)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('auth.permissions')
            ->where('auth.permissions', fn ($v) => collect($v)->count() === 0)
        );
});

test('auth.permissions array elements are strings — compatible with useCan hook consumption', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->app['env'] = 'local';

    $user = User::factory()->withSuperAdmin()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('auth.permissions')
            ->where('auth.permissions', function ($permissions) {
                // Every element must be a non-empty string (permission name)
                // collect() handles both array and Collection from AssertableInertia
                $coll = collect($permissions);

                return $coll->count() > 0
                    && $coll->every(fn ($p) => is_string($p) && strlen($p) > 0);
            })
        );
});
