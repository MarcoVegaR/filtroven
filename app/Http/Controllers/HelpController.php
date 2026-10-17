<?php

namespace App\Http\Controllers;

use App\Support\Help\HelpCatalog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HelpController extends Controller
{
    public function __construct(private readonly HelpCatalog $catalog) {}

    public function index(Request $request): Response
    {
        $category = $request->string('category')->trim()->toString();
        $selectedCategory = $category !== '' ? $category : null;

        return Inertia::render('help/index', [
            'categories' => $this->catalog->categories($selectedCategory),
            'filters' => [
                'category' => $selectedCategory,
            ],
            'breadcrumbs' => [
                ['title' => 'Ayuda', 'href' => route('help.index', absolute: false)],
            ],
        ]);
    }

    public function show(string $category, string $slug): Response
    {
        $article = $this->catalog->article($category, $slug);

        abort_if($article === null, 404);

        return Inertia::render('help/show', [
            'article' => $article,
            'categoryArticles' => $this->catalog->summariesForCategory($category),
            'breadcrumbs' => [
                ['title' => 'Ayuda', 'href' => route('help.index', absolute: false)],
                ['title' => $article['category_label'], 'href' => route('help.index', ['category' => $category], absolute: false)],
                ['title' => $article['title']],
            ],
        ]);
    }
}
