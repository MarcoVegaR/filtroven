<?php

namespace App\Support\Help;

use RuntimeException;

/**
 * @phpstan-type HelpArticleSummary array{
 *     slug: string,
 *     category: string,
 *     category_label: string,
 *     title: string,
 *     summary: string,
 *     order: int,
 *     url: string,
 * }
 * @phpstan-type HelpCategory array{
 *     key: string,
 *     label: string,
 *     articles: list<HelpArticleSummary>,
 * }
 * @phpstan-type HelpAdjacentArticle array{title: string, url: string}
 * @phpstan-type HelpArticle array{
 *     slug: string,
 *     category: string,
 *     category_label: string,
 *     title: string,
 *     summary: string,
 *     order: int,
 *     url: string,
 *     content: string,
 *     prev: HelpAdjacentArticle|null,
 *     next: HelpAdjacentArticle|null,
 * }
 */
class HelpCatalog
{
    private const array REQUIRED_FIELDS = ['title', 'summary', 'category', 'order'];

    private const array ALLOWED_FIELDS = ['title', 'summary', 'category', 'order'];

    /**
     * @var list<array{slug: string, category: string, category_label: string, title: string, summary: string, order: int, content: string}>
     */
    private ?array $articles = null;

    public function __construct(private readonly ?string $basePath = null) {}

    /**
     * @return list<HelpCategory>
     */
    public function categories(?string $category = null): array
    {
        $categories = [];

        foreach ($this->articles() as $article) {
            if ($category !== null && $article['category'] !== $category) {
                continue;
            }

            $categories[$article['category']] ??= [
                'key' => $article['category'],
                'label' => $article['category_label'],
                'articles' => [],
            ];

            $categories[$article['category']]['articles'][] = $this->summary($article);
        }

        return array_values($categories);
    }

    /**
     * @return list<HelpArticleSummary>
     */
    public function summariesForCategory(string $category): array
    {
        return array_map(
            fn (array $article): array => $this->summary($article),
            $this->articlesForCategory($category),
        );
    }

    /**
     * @return HelpArticle|null
     */
    public function article(string $category, string $slug): ?array
    {
        $articles = $this->articlesForCategory($category);

        foreach ($articles as $index => $article) {
            if ($article['slug'] !== $slug) {
                continue;
            }

            return [
                ...$this->summary($article),
                'content' => $article['content'],
                'prev' => $this->adjacentArticle($articles[$index - 1] ?? null),
                'next' => $this->adjacentArticle($articles[$index + 1] ?? null),
            ];
        }

        return null;
    }

    /**
     * @return list<array{slug: string, category: string, category_label: string, title: string, summary: string, order: int, content: string}>
     */
    private function articles(): array
    {
        if ($this->articles !== null) {
            return $this->articles;
        }

        $articles = [];
        $categoryLabels = [];

        foreach ($this->markdownFiles() as $path) {
            $article = $this->parseArticle($path);

            if (isset($categoryLabels[$article['category']]) && $categoryLabels[$article['category']] !== $article['category_label']) {
                throw new RuntimeException(sprintf(
                    'Category label mismatch for [%s]. Expected [%s], got [%s].',
                    $article['category'],
                    $categoryLabels[$article['category']],
                    $article['category_label'],
                ));
            }

            $categoryLabels[$article['category']] = $article['category_label'];
            $articles[] = $article;
        }

        usort($articles, function (array $left, array $right): int {
            $categoryComparison = $this->categoryPriority($left['category']) <=> $this->categoryPriority($right['category']);

            if ($categoryComparison !== 0) {
                return $categoryComparison;
            }

            $orderComparison = $left['order'] <=> $right['order'];

            if ($orderComparison !== 0) {
                return $orderComparison;
            }

            return strcasecmp($left['title'], $right['title']);
        });

        return $this->articles = $articles;
    }

    private const array CATEGORY_ORDER = [
        'first-steps',
        'users',
        'roles-and-permissions',
        'security-access',
        'settings',
        'audit',
    ];

    private function categoryPriority(string $category): int
    {
        $index = array_search($category, self::CATEGORY_ORDER, true);

        return $index !== false ? $index : PHP_INT_MAX;
    }

    /**
     * @return list<string>
     */
    private function markdownFiles(): array
    {
        $files = glob($this->helpPath('/*/*.md')) ?: [];

        sort($files);

        return array_values($files);
    }

    /**
     * @return array{slug: string, category: string, category_label: string, title: string, summary: string, order: int, content: string}
     */
    private function parseArticle(string $path): array
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read help article [%s].', $path));
        }

        if (! preg_match('/\A---\R(.*?)\R---\R?(.*)\z/s', $contents, $matches)) {
            throw new RuntimeException(sprintf('Help article [%s] must begin with frontmatter.', $path));
        }

        $metadata = $this->parseFrontmatter($matches[1], $path);
        $segments = explode(DIRECTORY_SEPARATOR, str_replace($this->helpPath().DIRECTORY_SEPARATOR, '', $path));
        $category = $segments[0] ?? null;
        $slug = pathinfo($path, PATHINFO_FILENAME);

        if ($category === null || $category === '') {
            throw new RuntimeException(sprintf('Help article [%s] must be stored in a category directory.', $path));
        }

        return [
            'slug' => $slug,
            'category' => $category,
            'category_label' => $metadata['category'],
            'title' => $metadata['title'],
            'summary' => $metadata['summary'],
            'order' => $metadata['order'],
            'content' => $this->stripHtmlComments(ltrim($matches[2], "\r\n")),
        ];
    }

    /**
     * @return array{title: string, summary: string, category: string, order: int}
     */
    private function parseFrontmatter(string $frontmatter, string $path): array
    {
        $metadata = [];

        foreach (preg_split('/\R/', trim($frontmatter)) ?: [] as $line) {
            [$key, $value] = array_pad(explode(':', $line, 2), 2, null);

            if ($key === null || $value === null) {
                throw new RuntimeException(sprintf('Invalid frontmatter line [%s] in [%s].', $line, $path));
            }

            $normalizedKey = trim($key);
            $normalizedValue = trim($value);

            if (! in_array($normalizedKey, self::ALLOWED_FIELDS, true)) {
                throw new RuntimeException(sprintf('Unsupported frontmatter key [%s] in [%s].', $normalizedKey, $path));
            }

            if ($normalizedValue === '') {
                throw new RuntimeException(sprintf('Frontmatter field [%s] in [%s] cannot be empty.', $normalizedKey, $path));
            }

            $metadata[$normalizedKey] = $normalizedValue;
        }

        foreach (self::REQUIRED_FIELDS as $field) {
            if (! array_key_exists($field, $metadata)) {
                throw new RuntimeException(sprintf('Missing required frontmatter field [%s] in [%s].', $field, $path));
            }
        }

        $order = filter_var($metadata['order'], FILTER_VALIDATE_INT);

        if ($order === false) {
            throw new RuntimeException(sprintf('Frontmatter field [order] in [%s] must be an integer.', $path));
        }

        return [
            'title' => $metadata['title'],
            'summary' => $metadata['summary'],
            'category' => $metadata['category'],
            'order' => $order,
        ];
    }

    /**
     * @param  array{slug: string, category: string, category_label: string, title: string, summary: string, order: int, content: string}|null  $article
     * @return HelpAdjacentArticle|null
     */
    private function adjacentArticle(?array $article): ?array
    {
        if ($article === null) {
            return null;
        }

        return [
            'title' => $article['title'],
            'url' => route('help.show', [
                'category' => $article['category'],
                'slug' => $article['slug'],
            ], absolute: false),
        ];
    }

    /**
     * @param  array{slug: string, category: string, category_label: string, title: string, summary: string, order: int, content: string}  $article
     * @return HelpArticleSummary
     */
    private function summary(array $article): array
    {
        return [
            'slug' => $article['slug'],
            'category' => $article['category'],
            'category_label' => $article['category_label'],
            'title' => $article['title'],
            'summary' => $article['summary'],
            'order' => $article['order'],
            'url' => route('help.show', [
                'category' => $article['category'],
                'slug' => $article['slug'],
            ], absolute: false),
        ];
    }

    /**
     * @return list<array{slug: string, category: string, category_label: string, title: string, summary: string, order: int, content: string}>
     */
    private function articlesForCategory(string $category): array
    {
        return array_values(array_filter(
            $this->articles(),
            fn (array $article): bool => $article['category'] === $category,
        ));
    }

    private function stripHtmlComments(string $content): string
    {
        return trim(preg_replace('/<!--.*?-->/s', '', $content) ?? $content);
    }

    private function helpPath(string $path = ''): string
    {
        return ($this->basePath ?? resource_path('help')).$path;
    }
}
