<?php

namespace App\Console\Commands;

use App\Support\Scaffold\ScaffoldCollisionDetector;
use App\Support\Scaffold\ScaffoldFileWriter;
use App\Support\Scaffold\ScaffoldInputParser;
use App\Support\Scaffold\ScaffoldPathMap;
use App\Support\Scaffold\ScaffoldPermissionMap;
use App\Support\Scaffold\ScaffoldStubRenderer;
use Illuminate\Console\Command;
use InvalidArgumentException;

class MakeScaffoldCommand extends Command
{
    protected $signature = 'make:scaffold
        {module : Context segment, e.g. system or billing}
        {model : Singular Studly model name, e.g. Customer}
        {--resource= : Plural route segment, e.g. customers}
        {--field=* : Repeatable field definition}
        {--read-only : Generate browse+detail scaffold only}
        {--soft-deletes : Reserved for later-phase lifecycle expansion}
        {--index-default=created_at:desc : Default sort}
        {--per-page=15 : Pagination size}
        {--nav-label= : Optional navigation label suggestion}
        {--nav-icon= : Optional navigation icon suggestion}
        {--force : Overwrite generated targets}
        {--dry-run : Preview without writing files}';

    protected $description = 'Generate a bounded phase-1 CRUD scaffold';

    public function handle(
        ScaffoldInputParser $parser,
        ScaffoldPermissionMap $permissionMap,
        ScaffoldPathMap $pathMap,
        ScaffoldCollisionDetector $collisionDetector,
        ScaffoldStubRenderer $renderer,
        ScaffoldFileWriter $writer,
    ): int {
        try {
            $context = $parser->parse([
                'module' => $this->argument('module'),
                'model' => $this->argument('model'),
                'resource' => $this->option('resource'),
                'fields' => $this->option('field'),
                'read_only' => $this->option('read-only'),
                'index_default' => $this->option('index-default'),
                'per_page' => $this->option('per-page'),
                'nav_label' => $this->option('nav-label'),
                'nav_icon' => $this->option('nav-icon'),
            ], $permissionMap);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $paths = $pathMap->for($context);
        $files = $renderer->render($context, $paths);
        $collisions = $collisionDetector->detect($files);

        if ($collisions !== [] && ! $this->option('force')) {
            $this->error('Generation stopped because the following files already exist:');

            foreach ($collisions as $collision) {
                $this->line(" - {$collision}");
            }

            return self::FAILURE;
        }

        $written = $writer->write($files, (bool) $this->option('dry-run'));

        $this->info($this->option('dry-run') ? 'Dry run complete.' : 'Scaffold generated successfully.');

        if ($this->option('soft-deletes')) {
            $this->warn('--soft-deletes is reserved for a later phase and is currently a no-op. No soft delete artifacts were generated.');
        }

        foreach ($written as $path) {
            $this->line(" - {$path}");
        }

        $this->newLine();
        $this->warn('Manual integration still required — generation does not register the module for you.');
        $this->line('The scaffold is verification-ready only after you complete the steps below:');
        $this->newLine();
        $this->line(' 1. Route registration (routes/web.php)');
        $this->line("    require __DIR__.'/{$context->modulePath}.php';");
        $this->newLine();
        $this->line(' 2. Permissions seeder wiring (database/seeders/DatabaseSeeder.php)');
        $this->line("    \$this->call({$context->seederClass()}::class);");
        $this->newLine();
        $this->line(' 3. Regenerate Wayfinder after routes are wired');
        $this->line('    php artisan wayfinder:generate --with-form --no-interaction');
        $this->newLine();

        $this->line(' 4. Sidebar navigation (app/Http/Middleware/HandleInertiaRequests.php)');
        $this->line("    Add the following entry inside the 'navigation.items' array, gated by permission:");
        $navLabel = $context->navLabel ?? $context->resourcesHeadline();
        $navIcon = $context->navIcon ?? 'box';
        $this->line("    ...(\$user?->can('{$context->permissionPrefix()}.view') ? [");
        $this->line("        ['title' => '{$navLabel}', 'href' => route('{$context->routeNamePrefix()}.index', absolute: false), 'icon' => '{$navIcon}'],");
        $this->line('    ] : []),');

        $this->newLine();
        $this->line(' 5. Complete domain-specific TODOs manually');
        $this->line('    Labels, relationships, richer validation, business rules, advanced filters, bulk actions, export, and lifecycle behaviors stay outside the frozen Phase 1 generator contract.');

        return self::SUCCESS;
    }
}
