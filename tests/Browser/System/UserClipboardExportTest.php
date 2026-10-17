<?php

use App\Models\User;
use Database\Seeders\AccessModulePermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(AccessModulePermissionsSeeder::class);

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

it('copies filtered users as TSV for excel', function () {
    $this->app['env'] = 'local';

    $admin = User::factory()->withSuperAdmin()->create();

    User::factory()->create([
        'name' => 'Clipboard Proof User',
        'email' => 'clipboard-proof-user@example.test',
        'is_active' => true,
    ]);

    $this->actingAs($admin);

    $page = visit(route('system.users.index', [
        'search' => 'clipboard-proof-user@example.test',
    ], false));

    $page->assertSee('Copiar para Excel')
        ->assertNoJavaScriptErrors();

    $page->script(<<<'JS'
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: {
                writeText: async (text) => {
                    window.__copiedExcelTsv = text;
                },
            },
        });
    JS);

    $page->click('Copiar para Excel')
        ->assertSee('Copiado para Excel')
        ->assertScript(
            "window.__copiedExcelTsv.startsWith('Nombre\\tCorreo\\tEstado\\tRoles\\tCreado\\n')"
        )
        ->assertScript(
            "window.__copiedExcelTsv.includes('Clipboard Proof User\\tclipboard-proof-user@example.test\\tActivo\\tSin roles\\t')"
        );
});
