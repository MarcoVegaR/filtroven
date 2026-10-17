<?php

use App\Support\Help\HelpCatalog;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    helpCatalogTestState()['files'] = [];
    helpCatalogTestState()['directories'] = [];
    helpCatalogTestState()['base_path'] = storage_path('framework/testing/help-catalog-'.Str::uuid());
});

afterEach(function () {
    foreach (helpCatalogTestState()['files'] as $file) {
        File::delete($file);
    }

    $directories = helpCatalogTestState()['directories'];

    rsort($directories);

    foreach ($directories as $directory) {
        if (File::isDirectory($directory) && count(File::files($directory)) === 0 && count(File::directories($directory)) === 0) {
            File::deleteDirectory($directory);
        }
    }

    if (is_string(helpCatalogTestState()['base_path']) && File::isDirectory(helpCatalogTestState()['base_path'])) {
        File::deleteDirectory(helpCatalogTestState()['base_path']);
    }
});

it('parses valid frontmatter into grouped summaries and article detail', function () {
    seedUnitHelpArticles($this, [
        'users/invite-user.md' => helpArticleFixture('Invitar usuario', 'Pasos para invitar usuarios.', 'Usuarios', 20, "# Invitar\n\nContenido base."),
        'users/manage-access.md' => helpArticleFixture('Gestionar accesos', 'Permisos y alcance.', 'Usuarios', 10, "# Accesos\n\nContenido adicional."),
    ]);

    $catalog = new HelpCatalog(helpCatalogBasePath());

    expect($catalog->categories())->toHaveCount(1)
        ->and($catalog->categories()[0]['articles'][0])->toMatchArray([
            'slug' => 'manage-access',
            'category' => 'users',
            'category_label' => 'Usuarios',
            'title' => 'Gestionar accesos',
            'summary' => 'Permisos y alcance.',
            'order' => 10,
            'url' => route('help.show', ['category' => 'users', 'slug' => 'manage-access'], absolute: false),
        ])
        ->and($catalog->article('users', 'invite-user'))->toMatchArray([
            'slug' => 'invite-user',
            'category' => 'users',
            'category_label' => 'Usuarios',
            'title' => 'Invitar usuario',
            'summary' => 'Pasos para invitar usuarios.',
            'order' => 20,
            'content' => "# Invitar\n\nContenido base.",
            'prev' => [
                'title' => 'Gestionar accesos',
                'url' => route('help.show', ['category' => 'users', 'slug' => 'manage-access'], absolute: false),
            ],
            'next' => null,
        ]);
});

it('fails when required frontmatter fields are missing', function () {
    seedUnitHelpArticles($this, [
        'users/invite-user.md' => "---\ntitle: Invitar usuario\ncategory: Usuarios\norder: 20\n---\n# Contenido",
    ]);

    $catalog = new HelpCatalog(helpCatalogBasePath());

    expect(fn () => $catalog->categories())->toThrow(RuntimeException::class, 'Missing required frontmatter field [summary]');
});

it('fails when frontmatter includes unsupported keys', function () {
    seedUnitHelpArticles($this, [
        'users/invite-user.md' => "---\ntitle: Invitar usuario\nsummary: Pasos\ncategory: Usuarios\norder: 20\naudience: admins\n---\n# Contenido",
    ]);

    $catalog = new HelpCatalog(helpCatalogBasePath());

    expect(fn () => $catalog->categories())->toThrow(RuntimeException::class, 'Unsupported frontmatter key [audience]');
});

it('fails when order is not an integer', function () {
    seedUnitHelpArticles($this, [
        'users/invite-user.md' => "---\ntitle: Invitar usuario\nsummary: Pasos\ncategory: Usuarios\norder: first\n---\n# Contenido",
    ]);

    $catalog = new HelpCatalog(helpCatalogBasePath());

    expect(fn () => $catalog->categories())->toThrow(RuntimeException::class, 'Frontmatter field [order]');
});

it('fails when frontmatter is missing entirely', function () {
    seedUnitHelpArticles($this, [
        'users/invite-user.md' => "# Invitar usuario\n\nContenido sin metadata.",
    ]);

    $catalog = new HelpCatalog(helpCatalogBasePath());

    expect(fn () => $catalog->categories())->toThrow(RuntimeException::class, 'must begin with frontmatter');
});

it('orders articles inside a category by order and then title', function () {
    seedUnitHelpArticles($this, [
        'users/third.md' => helpArticleFixture('Zeta', 'Tercero.', 'Usuarios', 30, 'Contenido'),
        'users/first.md' => helpArticleFixture('Beta', 'Primero.', 'Usuarios', 10, 'Contenido'),
        'users/second-a.md' => helpArticleFixture('Alfa', 'Segundo A.', 'Usuarios', 20, 'Contenido'),
        'users/second-b.md' => helpArticleFixture('Omega', 'Segundo B.', 'Usuarios', 20, 'Contenido'),
    ]);

    $slugs = collect((new HelpCatalog(helpCatalogBasePath()))->summariesForCategory('users'))->pluck('slug');

    expect($slugs->all())->toBe(['first', 'second-a', 'second-b', 'third']);
});

it('fails when category labels are inconsistent inside a folder', function () {
    seedUnitHelpArticles($this, [
        'users/invite-user.md' => helpArticleFixture('Invitar usuario', 'Pasos.', 'Usuarios', 10, 'Contenido'),
        'users/manage-access.md' => helpArticleFixture('Gestionar accesos', 'Permisos.', 'Seguridad', 20, 'Contenido'),
    ]);

    $catalog = new HelpCatalog(helpCatalogBasePath());

    expect(fn () => $catalog->categories())->toThrow(RuntimeException::class, 'Category label mismatch');
});

function seedUnitHelpArticles(object $testCase, array $files): void
{
    foreach ($files as $relativePath => $contents) {
        $path = helpCatalogBasePath().DIRECTORY_SEPARATOR.$relativePath;
        $directory = dirname($path);

        File::ensureDirectoryExists($directory);

        if (! in_array($directory, helpCatalogTestState()['directories'], true)) {
            helpCatalogTestState()['directories'][] = $directory;
        }

        File::put($path, $contents);
        helpCatalogTestState()['files'][] = $path;
    }
}

function helpArticleFixture(string $title, string $summary, string $category, int $order, string $content): string
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

function &helpCatalogTestState(): array
{
    static $state = [
        'base_path' => null,
        'files' => [],
        'directories' => [],
    ];

    return $state;
}

function helpCatalogBasePath(): string
{
    return helpCatalogTestState()['base_path'];
}
