<?php

use App\Support\Scaffold\ScaffoldInputParser;
use App\Support\Scaffold\ScaffoldPermissionMap;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

$GLOBALS['scaffold_test_tokens'] ??= [];

afterEach(function () {
    cleanupScaffoldArtifacts();
});

it('requires module model and resource context', function () {
    $this->artisan('make:scaffold demo Product --field=name:string:required')
        ->expectsOutputToContain('module, model, and --resource values are required')
        ->assertFailed();
});

it('rejects malformed repeated field entries', function () {
    $this->artisan('make:scaffold demo Product --resource=products --field=name')
        ->expectsOutputToContain('Malformed --field')
        ->assertFailed();
});

it('rejects unsupported field types and flags', function () {
    $this->artisan('make:scaffold demo Product --resource=products --field=account:relation')
        ->expectsOutputToContain('Unsupported field type')
        ->assertFailed();

    $this->artisan('make:scaffold demo Product --resource=products --field=name:string:filter')
        ->expectsOutputToContain('Unsupported flag')
        ->assertFailed();
});

it('rejects alternate aggregate field input forms', function () {
    expect(fn () => $this->artisan('make:scaffold demo Product --resource=products --fields=name:string:required'))
        ->toThrow(RuntimeException::class, 'The "--fields" option does not exist');
});

dataset('supported scaffold fields', [
    'string with all index flags' => ['name:string:required:list:search:sort', 'string', [], true, false, true, true, true],
    'text nullable' => ['description:text:nullable', 'text', [], false, true, false, false, false],
    'integer sortable' => ['priority:integer:list:sort', 'integer', [], false, false, true, false, true],
    'decimal nullable sortable' => ['amount:decimal:nullable:list:sort', 'decimal', [], false, true, true, false, true],
    'boolean listable' => ['is_active:boolean:list', 'boolean', [], false, false, true, false, false],
    'date sortable' => ['starts_on:date:nullable:list:sort', 'date', [], false, true, true, false, true],
    'datetime searchable' => ['published_at:datetime:nullable:list:sort', 'datetime', [], false, true, true, false, true],
    'email searchable' => ['contact_email:email:required:list:search', 'email', [], true, false, true, true, false],
    'select options' => ['status:select[draft|published|archived]:required:list', 'select', ['draft', 'published', 'archived'], true, false, true, false, false],
]);

it('normalizes supported phase one fields', function (
    string $definition,
    string $type,
    array $options,
    bool $required,
    bool $nullable,
    bool $list,
    bool $searchable,
    bool $sortable,
) {
    $parser = app(ScaffoldInputParser::class);
    $permissionMap = app(ScaffoldPermissionMap::class);

    $context = $parser->parse([
        'module' => 'system',
        'model' => 'Widget',
        'resource' => 'widgets',
        'fields' => [$definition],
        'index_default' => 'created_at:desc',
        'per_page' => 15,
    ], $permissionMap);

    expect($context->fields)->toHaveCount(1);

    $field = $context->fields[0];

    expect($field->type)->toBe($type)
        ->and($field->options)->toBe($options)
        ->and($field->required)->toBe($required)
        ->and($field->nullable)->toBe($nullable)
        ->and($field->list)->toBe($list)
        ->and($field->searchable)->toBe($searchable)
        ->and($field->sortable)->toBe($sortable);
})->with('supported scaffold fields');

it('maps phase one permissions explicitly for writable and read only variants', function () {
    $permissionMap = app(ScaffoldPermissionMap::class);

    expect($permissionMap->for('system', 'widgets', false))->toBe([
        'system.widgets.view',
        'system.widgets.create',
        'system.widgets.update',
        'system.widgets.delete',
    ])->and($permissionMap->for('system', 'widgets', true))->toBe([
        'system.widgets.view',
    ]);
});

it('supports dry run without writing files and prints manual integration steps', function () {
    $module = uniqueScaffoldToken('preview');
    $model = Str::studly($module).'Item';
    $resource = Str::plural(Str::kebab($model));

    $this->artisan(sprintf(
        'make:scaffold %s %s --resource=%s --field=name:string:required:list:search:sort --field=is_active:boolean:list --dry-run',
        $module,
        $model,
        $resource,
    ))
        ->expectsOutputToContain('Dry run complete')
        ->expectsOutputToContain('Manual integration still required')
        ->expectsOutputToContain("require __DIR__.'/{$module}.php';")
        ->expectsOutputToContain('php artisan wayfinder:generate --with-form --no-interaction')
        ->expectsOutputToContain('bulk actions, export, and lifecycle behaviors stay outside the frozen Phase 1 generator contract')
        ->assertSuccessful();

    expect(file_exists(base_path("routes/{$module}.php")))->toBeFalse();
});

it('warns that soft deletes are reserved and remain a no op', function () {
    $module = uniqueScaffoldToken('soft-delete');
    $model = Str::studly($module).'Record';
    $resource = Str::plural(Str::kebab($model));

    $this->artisan(sprintf(
        'make:scaffold %s %s --resource=%s --field=name:string:required:list --soft-deletes',
        $module,
        $model,
        $resource,
    ))
        ->expectsOutputToContain('--soft-deletes is reserved for a later phase and is currently a no-op')
        ->assertSuccessful();
});

it('generates a writable scaffold with gate backed requests', function () {
    $module = uniqueScaffoldToken('writer');
    $model = Str::studly($module).'Record';
    $resource = Str::plural(Str::kebab($model));

    $this->artisan(sprintf(
        'make:scaffold %s %s --resource=%s --field=name:string:required:list:search:sort --field=status:select[draft|published]:required:list --field=price:decimal:nullable:list:sort',
        $module,
        $model,
        $resource,
    ))->assertSuccessful();

    $requestBase = base_path('app/Http/Requests/'.Str::studly($module).'/'.Str::studly(Str::singular($resource)));
    $storeRequest = file_get_contents($requestBase.'/Store'.$model.'Request.php');
    $updateRequest = file_get_contents($requestBase.'/Update'.$model.'Request.php');
    $routeFile = file_get_contents(base_path("routes/{$module}.php"));
    $formComponent = file_get_contents(base_path("resources/js/pages/{$module}/{$resource}/components/".Str::kebab($model).'-form.tsx'));
    $indexPage = file_get_contents(base_path("resources/js/pages/{$module}/{$resource}/index.tsx"));
    $showPage = file_get_contents(base_path("resources/js/pages/{$module}/{$resource}/show.tsx"));
    $createPage = file_get_contents(base_path("resources/js/pages/{$module}/{$resource}/create.tsx"));
    $editPage = file_get_contents(base_path("resources/js/pages/{$module}/{$resource}/edit.tsx"));
    $modelFile = file_get_contents(base_path("app/Models/{$model}.php"));
    $controller = file_get_contents(base_path('app/Http/Controllers/'.Str::studly($module).'/'.$model.'Controller.php'));
    $migrationPath = collect(glob(base_path('database/migrations/*'.str_replace('-', '_', $resource).'*')) ?: [])->first();
    $migration = $migrationPath ? file_get_contents($migrationPath) : false;

    expect($storeRequest)->toContain("Gate::allows('create', {$model}::class)")
        ->and($storeRequest)->not->toContain('return true;')
        ->and($storeRequest)->toContain('use Illuminate\\Validation\\Rule;')
        ->and($updateRequest)->toContain("Gate::allows('update', \$this->route('".Str::camel($model)."'))")
        ->and($updateRequest)->toContain('Required fields stay required on update in the frozen PRD-07 baseline')
        ->and($updateRequest)->toContain("'name' => ['required', 'string', 'max:255']")
        ->and($updateRequest)->toContain("'status' => ['required', 'string', 'max:255', Rule::in(['draft', 'published'])]")
        ->and($controller)->toContain('function ($nested) use ($search): void')
        ->and($routeFile)->toContain("Route::resource('{$resource}', {$model}Controller::class)->only(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']);")
        ->and($formComponent)->toContain('<Form {...action} className="space-y-6">')
        ->and($formComponent)->toContain("import type { Wayfinder } from '@laravel/wayfinder';")
        ->and($indexPage)->toContain('<PageHeader')
        ->and($indexPage)->toContain("handleSort('name')")
        ->and($indexPage)->toContain('DropdownMenuItem asChild')
        ->and($indexPage)->toContain('Editar')
        ->and($createPage)->toContain('mx-auto max-w-3xl space-y-6')
        ->and($editPage)->toContain('const recordLabel = String(')
        ->and($editPage)->toContain('title={`Editar ')
        ->and($showPage)->toContain('text-2xl font-semibold tracking-tight')
        ->and($showPage)->toContain('const recordLabel = String(')
        ->and($modelFile)->toContain('implements Auditable')
        ->and($modelFile)->toContain('use HasFactory, \\OwenIt\\Auditing\\Auditable;')
        ->and($modelFile)->toContain("protected function casts(): array\n    {\n        return [")
        ->and($migration !== false)->toBeTrue()
        ->and($migration)->toContain("Schema::create('".str_replace('-', '_', $resource)."',");

    expect(strpos($formComponent, "import type { Wayfinder } from '@laravel/wayfinder';"))->toBeLessThan(
        strpos($formComponent, "import InputError from '@/components/input-error';"),
    )
        ->and($formComponent)->toContain('CardDescription')
        ->and($formComponent)->toContain('Campo obligatorio en el baseline generado.')
        ->and($controller)->toContain("['title' => 'Crear']")
        ->and($controller)->toContain("['title' => (string) (")
        ->and($controller)->not->toContain("['title' => 'Editar']");
});

it('omits Rule imports when generated requests do not need them', function () {
    $module = uniqueScaffoldToken('plain');
    $model = Str::studly($module).'Record';
    $resource = Str::plural(Str::kebab($model));

    $this->artisan(sprintf(
        'make:scaffold %s %s --resource=%s --field=name:string:required:list:search:sort --field=notes:text:nullable',
        $module,
        $model,
        $resource,
    ))->assertSuccessful();

    $requestBase = base_path('app/Http/Requests/'.Str::studly($module).'/'.Str::studly(Str::singular($resource)));
    $storeRequest = file_get_contents($requestBase.'/Store'.$model.'Request.php');
    $updateRequest = file_get_contents($requestBase.'/Update'.$model.'Request.php');

    expect($storeRequest)->not->toContain('use Illuminate\\Validation\\Rule;')
        ->and($updateRequest)->not->toContain('use Illuminate\\Validation\\Rule;');
});

it('generates a read only scaffold without mutation artifacts', function () {
    $module = uniqueScaffoldToken('catalog');
    $model = Str::studly($module).'Entry';
    $resource = Str::plural(Str::kebab($model));

    $this->artisan(sprintf(
        'make:scaffold %s %s --resource=%s --field=name:string:required:list:search:sort --read-only',
        $module,
        $model,
        $resource,
    ))->assertSuccessful();

    expect(file_exists(base_path('app/Http/Requests/'.Str::studly($module).'/'.Str::studly(Str::singular($resource)).'/Store'.$model.'Request.php')))->toBeFalse()
        ->and(file_exists(base_path("resources/js/pages/{$module}/{$resource}/create.tsx")))->toBeFalse()
        ->and(file_get_contents(base_path("routes/{$module}.php")))->toContain("only(['index', 'show'])");
});

it('stops on collisions unless force is passed', function () {
    $module = uniqueScaffoldToken('collision');
    $model = Str::studly($module).'Unit';
    $resource = Str::plural(Str::kebab($model));
    $filesystem = app(Filesystem::class);

    $filesystem->put(base_path("routes/{$module}.php"), '<?php');

    $this->artisan(sprintf(
        'make:scaffold %s %s --resource=%s --field=name:string:required:list --field=is_active:boolean:list',
        $module,
        $model,
        $resource,
    ))
        ->expectsOutputToContain('Generation stopped because the following files already exist')
        ->assertFailed();

    $this->artisan(sprintf(
        'make:scaffold %s %s --resource=%s --field=name:string:required:list --field=is_active:boolean:list --force',
        $module,
        $model,
        $resource,
    ))->assertSuccessful();
});

function uniqueScaffoldToken(string $prefix): string
{
    $token = $prefix.'-'.Str::lower(Str::random(8));
    $GLOBALS['scaffold_test_tokens'][] = $token;

    return $token;
}

function cleanupScaffoldArtifacts(): void
{
    $filesystem = app(Filesystem::class);
    foreach ($GLOBALS['scaffold_test_tokens'] as $token) {
        $migrationNeedles = array_unique([
            str_replace('-', '_', $token),
            Str::snake(Str::studly($token)),
            str_replace('-', '', $token),
        ]);

        foreach ([
            base_path('app/Http/Controllers/'.Str::studly($token)),
            base_path('app/Http/Requests/'.Str::studly($token)),
            base_path('resources/js/pages/'.$token),
            base_path('resources/js/actions/App/Http/Controllers/'.Str::studly($token)),
            base_path('tests/Feature/'.Str::studly($token)),
            resource_path('help/'.$token),
        ] as $directory) {
            if ($filesystem->isDirectory($directory)) {
                $filesystem->deleteDirectory($directory);
            }
        }

        foreach ([
            base_path("routes/{$token}.php"),
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

    $GLOBALS['scaffold_test_tokens'] = [];
}
