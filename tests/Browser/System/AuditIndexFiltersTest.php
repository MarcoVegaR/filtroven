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

it('shows date-only clear actions and searchable actor and event filters', function () {
    $this->app['env'] = 'local';

    $admin = User::factory()->withSuperAdmin()->withTwoFactor()->create();

    User::withoutAuditing(fn () => User::factory()->create([
        'name' => 'Actor filtro especial',
        'email' => 'actor-especial@example.test',
    ]));

    User::withoutAuditing(fn () => User::factory()->create([
        'name' => 'Actor secundario',
        'email' => 'actor-secundario@example.test',
    ]));

    $this->actingAs($admin);

    $page = visit(route('system.audit.index', [
        'to' => now()->subDay()->toDateString(),
    ], false));

    $page->assertSee('Limpiar filtros activos')
        ->click('[aria-label="Filtrar por actor"]')
        ->type('[aria-label="Buscar actor"]', 'especial')
        ->assertSeeIn('[data-slot="dropdown-menu-content"]', 'Actor filtro especial')
        ->assertDontSeeIn('[data-slot="dropdown-menu-content"]', 'Actor secundario')
        ->click('[aria-label="Filtrar por evento"]')
        ->type('[aria-label="Buscar evento"]', 'crea')
        ->assertSeeIn('[data-slot="dropdown-menu-content"]', 'Creación')
        ->assertDontSeeIn('[data-slot="dropdown-menu-content"]', 'Actualización')
        ->assertNoJavaScriptErrors();
});
