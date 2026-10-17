<?php

use App\Models\User;
use App\Support\Help\HelpCatalog;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    helpTestState()['base_path'] = storage_path('framework/testing/help-feature-'.Str::uuid());
    helpTestState()['files'] = [];
    helpTestState()['directories'] = [];

    $this->app->singleton(HelpCatalog::class, fn (): HelpCatalog => new HelpCatalog(helpFeatureBasePath()));
});

afterEach(function () {
    foreach (helpTestState()['files'] as $file) {
        File::delete($file);
    }

    $directories = helpTestState()['directories'];

    rsort($directories);

    foreach ($directories as $directory) {
        if (File::isDirectory($directory) && count(File::files($directory)) === 0 && count(File::directories($directory)) === 0) {
            File::deleteDirectory($directory);
        }
    }

    if (is_string(helpTestState()['base_path']) && File::isDirectory(helpTestState()['base_path'])) {
        File::deleteDirectory(helpTestState()['base_path']);
    }
});

it('registers help routes inside the protected shell middleware group', function () {
    $indexRoute = app('router')->getRoutes()->getByName('help.index');
    $showRoute = app('router')->getRoutes()->getByName('help.show');

    expect($indexRoute)->not->toBeNull()
        ->and($showRoute)->not->toBeNull()
        ->and($indexRoute->uri())->toBe('help')
        ->and($showRoute->uri())->toBe('help/{category}/{slug}')
        ->and($indexRoute->methods())->toContain('GET')
        ->and($showRoute->methods())->toContain('GET')
        ->and($indexRoute->gatherMiddleware())->toContain('auth', 'verified', 'ensure-two-factor')
        ->and($showRoute->gatherMiddleware())->toContain('auth', 'verified', 'ensure-two-factor');
});

it('redirects guests from the help index', function () {
    $this->get(route('help.index'))
        ->assertRedirect(route('login'));
});

it('renders the help index for protected-shell users', function () {
    seedHelpArticles($this, [
        'users/invite-user.md' => helpArticle('Invitar usuario', 'Pasos para invitar usuarios.', 'Usuarios', 20, "# Invitar\n\nContenido"),
        'users/manage-access.md' => helpArticle('Gestionar accesos', 'Permisos y alcance.', 'Usuarios', 10, "# Accesos\n\nContenido"),
        'audit/review-events.md' => helpArticle('Revisar eventos', 'Consultar actividad operativa.', 'Auditoría', 5, "# Auditoría\n\nContenido"),
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('help.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('help/index')
            ->has('categories', 2)
            ->where('categories.0.key', 'users')
            ->where('categories.0.label', 'Usuarios')
            ->where('categories.0.articles.0.slug', 'manage-access')
            ->where('categories.0.articles.1.slug', 'invite-user')
            ->where('categories.1.key', 'audit')
            ->where('categories.1.label', 'Auditoría')
            ->where('filters.category', null)
            ->where('breadcrumbs.0.href', route('help.index', absolute: false))
        );
});

it('applies category filtering state on the help index', function () {
    seedHelpArticles($this, [
        'users/invite-user.md' => helpArticle('Invitar usuario', 'Pasos para invitar usuarios.', 'Usuarios', 20, "# Invitar\n\nContenido"),
        'audit/review-events.md' => helpArticle('Revisar eventos', 'Consultar actividad operativa.', 'Auditoría', 5, "# Auditoría\n\nContenido"),
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('help.index', ['category' => 'users']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('help/index')
            ->has('categories', 1)
            ->where('categories.0.key', 'users')
            ->where('filters.category', 'users')
        );
});

it('renders a help article with breadcrumbs and same-category navigation', function () {
    seedHelpArticles($this, [
        'users/getting-started.md' => helpArticle('Primeros pasos', 'Configura el módulo de usuarios.', 'Usuarios', 10, "# Primeros pasos\n\nGuía base."),
        'users/invite-user.md' => helpArticle('Invitar usuario', 'Pasos para invitar usuarios.', 'Usuarios', 20, "# Invitar\n\n1. Abre el formulario."),
        'users/manage-access.md' => helpArticle('Gestionar accesos', 'Permisos y alcance.', 'Usuarios', 30, "# Accesos\n\nRevisa roles."),
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('help.show', ['category' => 'users', 'slug' => 'invite-user']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('help/show')
            ->where('article.slug', 'invite-user')
            ->where('article.category', 'users')
            ->where('article.category_label', 'Usuarios')
            ->where('article.content', "# Invitar\n\n1. Abre el formulario.")
            ->where('article.prev.title', 'Primeros pasos')
            ->where('article.prev.url', route('help.show', ['category' => 'users', 'slug' => 'getting-started'], absolute: false))
            ->where('article.next.title', 'Gestionar accesos')
            ->where('article.next.url', route('help.show', ['category' => 'users', 'slug' => 'manage-access'], absolute: false))
            ->has('categoryArticles', 3)
            ->where('breadcrumbs.0.href', route('help.index', absolute: false))
            ->where('breadcrumbs.1.href', route('help.index', ['category' => 'users'], absolute: false))
            ->where('breadcrumbs.2.title', 'Invitar usuario')
        );
});

it('returns not found for invalid help articles', function () {
    seedHelpArticles($this, [
        'users/invite-user.md' => helpArticle('Invitar usuario', 'Pasos para invitar usuarios.', 'Usuarios', 20, "# Invitar\n\nContenido"),
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('help.show', ['category' => 'users', 'slug' => 'missing-article']))
        ->assertNotFound();
});

it('resolves every article slug referenced by contextual HelpLinks in module screens', function () {
    $this->app->singleton(HelpCatalog::class, fn (): HelpCatalog => new HelpCatalog);
    $catalog = $this->app->make(HelpCatalog::class);

    $referenced = [
        ['category' => 'users', 'slug' => 'manage-users'],
        ['category' => 'users', 'slug' => 'create-user'],
        ['category' => 'users', 'slug' => 'assign-roles'],
        ['category' => 'users', 'slug' => 'user-lifecycle'],
        ['category' => 'roles-and-permissions', 'slug' => 'manage-roles'],
        ['category' => 'roles-and-permissions', 'slug' => 'create-role'],
        ['category' => 'roles-and-permissions', 'slug' => 'assign-permissions'],
        ['category' => 'audit', 'slug' => 'review-audit-events'],
        ['category' => 'security-access', 'slug' => 'review-my-access'],
    ];

    foreach ($referenced as $ref) {
        $article = $catalog->article($ref['category'], $ref['slug']);

        expect($article)
            ->not->toBeNull("Article [{$ref['category']}/{$ref['slug']}] referenced by a HelpLink does not exist in resources/help/");
    }
});

it('serves every HelpLink article slug as a 200 response for an authenticated user', function () {
    $this->app->singleton(HelpCatalog::class, fn (): HelpCatalog => new HelpCatalog);

    $user = User::factory()->create();

    $referenced = [
        ['category' => 'users', 'slug' => 'manage-users'],
        ['category' => 'users', 'slug' => 'create-user'],
        ['category' => 'users', 'slug' => 'assign-roles'],
        ['category' => 'users', 'slug' => 'user-lifecycle'],
        ['category' => 'roles-and-permissions', 'slug' => 'manage-roles'],
        ['category' => 'roles-and-permissions', 'slug' => 'create-role'],
        ['category' => 'roles-and-permissions', 'slug' => 'assign-permissions'],
        ['category' => 'audit', 'slug' => 'review-audit-events'],
        ['category' => 'security-access', 'slug' => 'review-my-access'],
    ];

    foreach ($referenced as $ref) {
        $this->actingAs($user)
            ->get(route('help.show', $ref))
            ->assertOk();
    }
});

function seedHelpArticles(object $testCase, array $files): void
{
    foreach ($files as $relativePath => $contents) {
        $path = helpFeatureBasePath().DIRECTORY_SEPARATOR.$relativePath;
        $directory = dirname($path);

        File::ensureDirectoryExists($directory);

        if (! in_array($directory, helpTestState()['directories'], true)) {
            helpTestState()['directories'][] = $directory;
        }

        File::put($path, $contents);
        helpTestState()['files'][] = $path;
    }
}

function helpArticle(string $title, string $summary, string $category, int $order, string $content): string
{
    return implode("\n", [
        '---',
        'title: '.$title,
        'summary: '.$summary,
        'category: '.$category,
        'order: '.$order,
        '---',
        $content,
    ]);
}

function &helpTestState(): array
{
    static $state = [
        'base_path' => null,
        'files' => [],
        'directories' => [],
    ];

    return $state;
}

function helpFeatureBasePath(): string
{
    return helpTestState()['base_path'];
}
