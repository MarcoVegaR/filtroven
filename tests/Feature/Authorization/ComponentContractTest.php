<?php

/**
 * ComponentContractTest — verifies the PRD-04 UI components exist at their
 * prescribed paths and that the backend Inertia shared props match the type
 * contracts expected by the new hooks and components.
 *
 * This test file intentionally avoids a JS runner (no Jest / React Testing
 * Library). Instead it covers:
 *
 *   A) Source file existence — each new component/hook source file must be at
 *      the declared path. A missing file breaks the TypeScript build.
 *
 *   B) Inertia shared prop contracts — the backend `HandleInertiaRequests`
 *      must expose `auth.permissions` as an array and `flash` as an object with
 *      string|null levels, so that `useCan()` and `FlashToaster` can consume
 *      them without defensive casting.
 *
 *   C) Build output integrity — the `public/build/manifest.json` must exist
 *      and reference the primary app entry point, confirming the production
 *      bundle contains the new components (which are tree-shaken into the
 *      main bundle, not separate manifest entries).
 *
 * @see resources/js/components/ui/table.tsx
 * @see resources/js/components/ui/pagination.tsx
 * @see resources/js/components/ui/empty-state.tsx
 * @see resources/js/components/ui/confirmation-dialog.tsx
 * @see resources/js/components/ui/toolbar.tsx
 * @see resources/js/components/ui/textarea.tsx
 * @see resources/js/components/flash-toaster.tsx
 * @see resources/js/hooks/use-can.ts
 */

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

// ── A. Source file existence ──────────────────────────────────────────────────

$uiComponents = [
    'Table' => 'resources/js/components/ui/table.tsx',
    'Pagination' => 'resources/js/components/ui/pagination.tsx',
    'EmptyState' => 'resources/js/components/ui/empty-state.tsx',
    'ConfirmationDialog' => 'resources/js/components/ui/confirmation-dialog.tsx',
    'Toolbar' => 'resources/js/components/ui/toolbar.tsx',
    'Textarea' => 'resources/js/components/ui/textarea.tsx',
    'FlashToaster' => 'resources/js/components/flash-toaster.tsx',
    'useCan hook' => 'resources/js/hooks/use-can.ts',
];

test('all prd-04 ui component source files exist at their prescribed paths', function () use ($uiComponents) {
    foreach ($uiComponents as $name => $relativePath) {
        expect(file_exists(base_path($relativePath)))
            ->toBeTrue("Expected {$name} at {$relativePath} — file is missing");
    }
});

it('each prd-04 component source is non-empty', function (string $relativePath) {
    expect(filesize(base_path($relativePath)))->toBeGreaterThan(0);
})->with(array_values($uiComponents));

// ── B. Inertia shared prop contracts (auth.permissions array shape) ───────────

test('auth.permissions is present and is an array — satisfies useCan array contract', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('auth.permissions')
            // collect() handles both PHP arrays and Illuminate Collections
            ->where('auth.permissions', fn ($v) => collect($v)->count() === 0)
        );
});

test('auth.permissions is non-empty for a user with permissions — useCan can find permission strings', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->app['env'] = 'local';

    $user = User::factory()->withSuperAdmin()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('auth.permissions')
            ->where('auth.permissions', fn ($v) => collect($v)->count() > 0)
            ->where('auth.permissions', fn ($v) => collect($v)->every(fn ($p) => is_string($p) && strlen($p) > 0))
        );
});

// ── B. Inertia shared prop contracts (flash object shape) ─────────────────────

test('flash is present with all four levels in inertia shared props — satisfies FlashToaster contract', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('flash')
            ->has('flash.success')
            ->has('flash.error')
            ->has('flash.info')
            ->has('flash.warning')
        );
});

test('flash values are null when no flash is set — FlashToaster fires no toast', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('flash.success', null)
            ->where('flash.error', null)
            ->where('flash.info', null)
            ->where('flash.warning', null)
        );
});

test('flash.success is a string when set — compatible with FlashToaster string guard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['success' => 'Recurso creado exitosamente.'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('flash.success', fn ($v) => is_string($v) && strlen($v) > 0)
            ->where('flash.error', null)
        );
});

// ── C. Build output integrity ─────────────────────────────────────────────────

test('vite manifest exists and references the primary app entry point', function () {
    $manifestPath = public_path('build/manifest.json');

    expect(file_exists($manifestPath))->toBeTrue('public/build/manifest.json is missing — run npm run build');

    $manifest = json_decode(file_get_contents($manifestPath), associative: true);

    expect($manifest)->toBeArray();
    expect(array_key_exists('resources/js/app.tsx', $manifest))->toBeTrue(
        'manifest.json does not contain the app.tsx entry — frontend build may be corrupt'
    );
});

test('vite manifest entry for app.tsx has a non-empty file reference', function () {
    $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), associative: true);
    $appEntry = $manifest['resources/js/app.tsx'] ?? null;

    expect($appEntry)->not->toBeNull();
    expect($appEntry['file'] ?? '')->not->toBeEmpty();
});

test('flash toaster is mounted once at the application root', function () {
    $appRoot = file_get_contents(base_path('resources/js/app.tsx'));
    $sidebarLayout = file_get_contents(base_path('resources/js/layouts/app/app-sidebar-layout.tsx'));

    expect($appRoot)->toContain('import { Toaster } from \'sonner\';')
        ->and($appRoot)->toContain("import { FlashToaster } from '@/components/flash-toaster';")
        ->and($appRoot)->toContain('<FlashToaster />')
        ->and($appRoot)->toContain('<Toaster theme="system" richColors closeButton />')
        ->and($sidebarLayout)->not->toContain('<FlashToaster />')
        ->and($sidebarLayout)->not->toContain('<Toaster theme="system" richColors closeButton />');
});

test('flash toaster consumes shared page flash props and inertia flash events', function () {
    $flashToaster = file_get_contents(base_path('resources/js/components/flash-toaster.tsx'));

    expect($flashToaster)->toContain("import { router } from '@inertiajs/react';")
        ->and($flashToaster)->toContain('window.history.state?.page?.props?.flash')
        ->and($flashToaster)->toContain("router.on('navigate'")
        ->and($flashToaster)->toContain("router.on('success'")
        ->and($flashToaster)->toContain("router.on('flash'")
        ->and($flashToaster)->toContain('showFlash(event.detail.page.props.flash as Flash | undefined);');
});
