<?php

use App\Models\User;
use Database\Seeders\AccessModulePermissionsSeeder;
use Database\Seeders\AuditModulePermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(AccessModulePermissionsSeeder::class);
    $this->seed(AuditModulePermissionsSeeder::class);

    $publicHotPath = public_path('hot');
    if (file_exists($publicHotPath)) {
        unlink($publicHotPath);
    }

    $hotPath = storage_path('framework/testing/vite.hot');
    if (file_exists($hotPath)) {
        unlink($hotPath);
    }

    Vite::useHotFile($hotPath);
});

it('shows global and contextual help entry points across the MVP screens', function () {
    $this->app['env'] = 'local';

    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    $this->actingAs($admin);

    $page = visit(route('system.users.index', [], false));

    $page->assertMissing('[data-test="shell-help-link"]')
        ->click('[data-test="sidebar-menu-button"]')
        ->assertPresent('[data-test="user-menu-help-link"]')
        ->assertSee('Ayuda')
        ->click('[data-test="user-menu-help-link"]')
        ->assertPathIs('/help')
        ->assertSee('Centro de ayuda')
        ->assertNoJavaScriptErrors();

    foreach ([
        [route('system.users.index', [], false), '[data-test="help-link-users-manage-users"]'],
        [route('system.users.create', [], false), '[data-test="help-link-users-create-user"]'],
        [route('system.roles.index', [], false), '[data-test="help-link-roles-and-permissions-manage-roles"]'],
        [route('system.roles.create', [], false), '[data-test="help-link-roles-and-permissions-create-role"]'],
        [route('system.audit.index', [], false), '[data-test="help-link-audit-review-audit-events"]'],
        [route('settings.access', [], false), '[data-test="help-link-security-access-review-my-access"]'],
    ] as [$path, $selector]) {
        visit($path)
            ->assertPresent($selector)
            ->assertSee('Ayuda')
            ->assertNoJavaScriptErrors();
    }
});

it('renders markdown help articles with headings tables and code blocks', function () {
    $this->app['env'] = 'local';

    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    $this->actingAs($admin);

    visit(route('help.show', ['category' => 'security-access', 'slug' => 'review-my-access'], false))
        ->assertSee('Revisar mi acceso actual')
        ->assertSee('¿Dónde veo mis permisos?')
        ->assertSee('Permisos efectivos')
        ->assertSee('¿Cuándo pedir ayuda a un administrador?')
        ->assertNoJavaScriptErrors();
});
