<?php

use App\Models\Permission;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

$GLOBALS['generated_scaffold_tokens'] ??= [];
$GLOBALS['generated_scaffold_backups'] ??= [];

afterEach(function () {
    cleanupGeneratedScaffoldArtifacts();

    $this->artisan('migrate:fresh --no-interaction')->assertSuccessful();
});

it('verifies a writable scaffold after the documented manual integration steps', function () {
    $module = 'verify-'.Str::lower(Str::random(6));
    $model = Str::studly($module).'Module';
    $resource = Str::plural(Str::kebab($model));
    $permissionsSeederClass = generatedPermissionsSeederClass($module, $resource);

    $GLOBALS['generated_scaffold_tokens'][] = $module;

    $this->artisan(sprintf(
        'make:scaffold %s %s --resource=%s --field=name:string:required:list:search:sort --field=notes:text:nullable --field=is_active:boolean:list --nav-label="%s" --nav-icon=boxes',
        $module,
        $model,
        $resource,
        Str::headline($resource),
    ))
        ->expectsOutputToContain('Manual integration still required')
        ->expectsOutputToContain("require __DIR__.'/{$module}.php';")
        ->expectsOutputToContain('$this->call('.Str::studly($module).Str::studly(Str::singular($resource)).'PermissionsSeeder::class);')
        ->assertSuccessful();

    integrateGeneratedModule($module, $resource, $model);
    $this->refreshApplication();

    $this->artisan('migrate:fresh --seed --no-interaction')->assertSuccessful();
    $this->artisan('db:seed', ['--class' => $permissionsSeederClass, '--no-interaction' => true])->assertSuccessful();
    $this->artisan('wayfinder:generate --with-form --no-interaction')->assertSuccessful();

    $controller = file_get_contents(base_path('app/Http/Controllers/'.Str::studly($module).'/'.$model.'Controller.php'));
    $routeFile = file_get_contents(base_path('routes/'.$module.'.php'));
    $typesFile = file_get_contents(base_path('resources/js/types/'.$module.'-'.$resource.'.ts'));
    $indexPage = file_get_contents(base_path('resources/js/pages/'.$module.'/'.$resource.'/index.tsx'));
    $createPage = file_get_contents(base_path('resources/js/pages/'.$module.'/'.$resource.'/create.tsx'));
    $actionFile = base_path('resources/js/actions/App/Http/Controllers/'.Str::studly($module).'/'.$model.'Controller.ts');
    $helpArticleIndexPath = base_path('resources/help/'.$module.'/manage-'.$resource.'.md');
    $helpArticleCreatePath = base_path('resources/help/'.$module.'/create-'.Str::singular($resource).'.md');
    $helpArticleIndex = file_get_contents($helpArticleIndexPath);
    $helpArticleCreate = file_get_contents($helpArticleCreatePath);

    expect($controller)->toContain("Gate::authorize('viewAny', {$model}::class)")
        ->and($controller)->toContain("return Inertia::render('{$module}/{$resource}/index'")
        ->and($routeFile)->toContain('ensure-two-factor')
        ->and($typesFile)->toContain('export type '.$model.'Data')
        ->and($indexPage)->toContain("import { create, destroy, edit, index, show } from '@/actions/App/Http/Controllers/".Str::studly($module)."/{$model}Controller';")
        ->and($indexPage)->toContain('<PageHeader')
        ->and($indexPage)->toContain('handleSort')
        ->and($indexPage)->toContain("import { HelpLink } from '@/components/help/help-link';")
        ->and($indexPage)->toContain("<HelpLink category=\"{$module}\" slug=\"manage-{$resource}\" />")
        ->and($createPage)->toContain("import { HelpLink } from '@/components/help/help-link';")
        ->and($createPage)->toContain("<HelpLink category=\"{$module}\" slug=\"create-".Str::singular($resource).'" />')
        ->and(file_exists($helpArticleIndexPath))->toBeTrue()
        ->and(file_exists($helpArticleCreatePath))->toBeTrue()
        ->and($helpArticleIndex)->toContain('title: Gestionar')
        ->and($helpArticleIndex)->toContain('category: '.Str::headline($module))
        ->and($helpArticleIndex)->toContain('## Checklist rápido')
        ->and($helpArticleCreate)->toContain('title: Crear')
        ->and($helpArticleCreate)->toContain('## Pasos sugeridos')
        ->and(file_exists($actionFile))->toBeTrue()
        ->and(Route::has($module.'.'.$resource.'.index'))->toBeTrue()
        ->and(Route::has($module.'.'.$resource.'.store'))->toBeTrue()
        ->and(Route::has($module.'.'.$resource.'.destroy'))->toBeTrue();

    expect(Permission::query()->where('name', $module.'.'.$resource.'.view')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', $module.'.'.$resource.'.create')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', $module.'.'.$resource.'.update')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', $module.'.'.$resource.'.delete')->exists())->toBeTrue();
});

it('verifies a read only scaffold after the documented manual integration steps', function () {
    $module = 'readonly-'.Str::lower(Str::random(6));
    $model = Str::studly($module).'Entry';
    $resource = Str::plural(Str::kebab($model));

    $GLOBALS['generated_scaffold_tokens'][] = $module;

    $this->artisan(sprintf(
        'make:scaffold %s %s --resource=%s --field=name:string:required:list:search:sort --field=is_active:boolean:list --read-only',
        $module,
        $model,
        $resource,
    ))->assertSuccessful();

    integrateGeneratedModule($module, $resource, $model);
    $this->refreshApplication();

    $this->artisan('migrate:fresh --seed --no-interaction')->assertSuccessful();
    $this->artisan('wayfinder:generate --with-form --no-interaction')->assertSuccessful();

    $actionFile = base_path('resources/js/actions/App/Http/Controllers/'.Str::studly($module).'/'.$model.'Controller.ts');
    $seederFile = file_get_contents(base_path('database/seeders/'.Str::studly($module).Str::studly(Str::singular($resource)).'PermissionsSeeder.php'));
    $indexPage = file_get_contents(base_path('resources/js/pages/'.$module.'/'.$resource.'/index.tsx'));
    $helpArticleIndexPath = base_path('resources/help/'.$module.'/manage-'.$resource.'.md');
    $helpArticleCreatePath = base_path('resources/help/'.$module.'/create-'.Str::singular($resource).'.md');

    expect(file_exists(base_path('app/Http/Requests/'.Str::studly($module).'/'.Str::studly(Str::singular($resource)).'/Store'.$model.'Request.php')))->toBeFalse()
        ->and(file_exists(base_path('resources/js/pages/'.$module.'/'.$resource.'/create.tsx')))->toBeFalse()
        ->and(file_exists($actionFile))->toBeTrue()
        ->and(file_exists($helpArticleIndexPath))->toBeTrue()
        ->and(file_exists($helpArticleCreatePath))->toBeFalse()
        ->and($indexPage)->toContain("import { HelpLink } from '@/components/help/help-link';")
        ->and($indexPage)->toContain("<HelpLink category=\"{$module}\" slug=\"manage-{$resource}\" />")
        ->and(Route::has($module.'.'.$resource.'.index'))->toBeTrue()
        ->and(Route::has($module.'.'.$resource.'.show'))->toBeTrue()
        ->and(Route::has($module.'.'.$resource.'.store'))->toBeFalse()
        ->and(Route::has($module.'.'.$resource.'.destroy'))->toBeFalse()
        ->and($seederFile)->toContain($module.'.'.$resource.'.view')
        ->and($seederFile)->not->toContain($module.'.'.$resource.'.create')
        ->and($seederFile)->not->toContain($module.'.'.$resource.'.update')
        ->and($seederFile)->not->toContain($module.'.'.$resource.'.delete');
});

function integrateGeneratedModule(string $module, string $resource, string $model): void
{
    $filesystem = app(Filesystem::class);
    $webPath = base_path('routes/web.php');
    $seederPath = base_path('database/seeders/DatabaseSeeder.php');
    $seederClass = Str::studly($module).Str::studly(Str::singular($resource)).'PermissionsSeeder';

    backupFileOnce($webPath);
    backupFileOnce($seederPath);

    $webContents = file_get_contents($webPath) ?: '';
    $requireLine = "require __DIR__.'/{$module}.php';";

    if (! str_contains($webContents, $requireLine)) {
        $webContents = rtrim($webContents).PHP_EOL.$requireLine.PHP_EOL;
        $filesystem->put($webPath, $webContents);
    }

    $seederContents = file_get_contents($seederPath) ?: '';
    $callLine = "        \$this->call({$seederClass}::class);";

    if (! str_contains($seederContents, $callLine)) {
        $updatedSeederContents = insertGeneratedSeederCall($seederContents, $callLine);

        if (is_string($updatedSeederContents)) {
            $seederContents = $updatedSeederContents;
        }

        if (! str_contains($seederContents, $callLine)) {
            throw new RuntimeException('Unable to register generated permissions seeder in DatabaseSeeder.');
        }

        $filesystem->put($seederPath, $seederContents);
    }
}

function insertGeneratedSeederCall(string $seederContents, string $callLine): ?string
{
    return preg_replace(
        '/(^\s*\$this->call\([^\n]+\);\R(?:\s*\$this->call\([^\n]+\);\R)*)/m',
        "$1{$callLine}".PHP_EOL,
        $seederContents,
        1,
    );
}

function generatedPermissionsSeederClass(string $module, string $resource): string
{
    return 'Database\\Seeders\\'.Str::studly($module).Str::studly(Str::singular($resource)).'PermissionsSeeder';
}

function backupFileOnce(string $path): void
{
    if (! array_key_exists($path, $GLOBALS['generated_scaffold_backups'])) {
        $GLOBALS['generated_scaffold_backups'][$path] = file_get_contents($path);
    }
}

function cleanupGeneratedScaffoldArtifacts(): void
{
    $filesystem = app(Filesystem::class);

    foreach ($GLOBALS['generated_scaffold_tokens'] as $token) {
        $migrationNeedles = array_unique([
            str_replace('-', '_', $token),
            Str::snake(Str::studly($token)),
            str_replace('-', '', $token),
        ]);

        foreach ([
            base_path('app/Http/Controllers/'.Str::studly($token)),
            base_path('app/Http/Requests/'.Str::studly($token)),
            base_path('resources/help/'.$token),
            base_path('resources/js/pages/'.$token),
            base_path('resources/js/actions/App/Http/Controllers/'.Str::studly($token)),
            base_path('tests/Feature/'.Str::studly($token)),
        ] as $directory) {
            if ($filesystem->isDirectory($directory)) {
                $filesystem->deleteDirectory($directory);
            }
        }

        foreach ([
            base_path('routes/'.$token.'.php'),
        ] as $file) {
            if ($filesystem->exists($file)) {
                $filesystem->delete($file);
            }
        }

        foreach (glob(base_path('app/Models/'.Str::studly($token).'*')) ?: [] as $path) {
            $filesystem->delete($path);
        }

        foreach (glob(base_path('app/Policies/'.Str::studly($token).'*')) ?: [] as $path) {
            $filesystem->delete($path);
        }

        foreach (glob(base_path('database/factories/'.Str::studly($token).'*')) ?: [] as $path) {
            $filesystem->delete($path);
        }

        foreach (glob(base_path('database/seeders/'.Str::studly($token).'*')) ?: [] as $path) {
            $filesystem->delete($path);
        }

        foreach (glob(base_path('resources/js/types/'.$token.'-*')) ?: [] as $path) {
            $filesystem->delete($path);
        }

        foreach (glob(base_path('resources/js/routes/'.$token.'*')) ?: [] as $path) {
            if ($filesystem->isDirectory($path)) {
                $filesystem->deleteDirectory($path);

                continue;
            }

            $filesystem->delete($path);
        }

        foreach ($migrationNeedles as $needle) {
            foreach (glob(base_path('database/migrations/*'.$needle.'*')) ?: [] as $path) {
                $filesystem->delete($path);
            }
        }
    }

    foreach ($GLOBALS['generated_scaffold_backups'] as $path => $contents) {
        $filesystem->put($path, $contents);
    }

    $GLOBALS['generated_scaffold_tokens'] = [];
    $GLOBALS['generated_scaffold_backups'] = [];
}
