<?php

namespace App\Http\Controllers;

use App\Help\HelpArticle;
use App\Help\HelpCenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public help center: guides for yard staff and their customers.
 */
class HelpController extends Controller
{
    public function __construct(private readonly HelpCenter $help) {}

    public function index(): Response
    {
        $categories = [];

        foreach ($this->help->categories() as $slug => $category) {
            $articles = $this->help->inCategory($slug);

            $categories[] = [
                'slug' => $slug,
                ...$category,
                'count' => count($articles),
                'top' => array_map(fn (HelpArticle $a) => $a->summaryArray(), array_slice($articles, 0, 3)),
            ];
        }

        return Inertia::render('help/index', [
            ...$this->shared(),
            'categories' => $categories,
            'popular' => array_map(fn (HelpArticle $a) => $a->summaryArray(), $this->help->popular(10)),
        ]);
    }

    public function category(string $category): Response
    {
        $found = $this->help->category($category);
        abort_if($found === null, 404);

        return Inertia::render('help/category', [
            ...$this->shared(),
            'category' => ['slug' => $category, ...$found],
            'articles' => array_map(fn (HelpArticle $a) => $a->summaryArray(), $this->help->inCategory($category)),
        ]);
    }

    public function article(string $category, string $article): Response
    {
        $found = $this->help->find($category, $article);
        abort_if($found === null, 404);

        $siblings = $this->help->inCategory($category);
        $index = array_search($found->path(), array_map(fn (HelpArticle $a) => $a->path(), $siblings), true);
        $index = $index === false ? 0 : $index;

        return Inertia::render('help/article', [
            ...$this->shared(),
            'category' => ['slug' => $category, ...(array) $this->help->category($category)],
            'article' => [
                ...$found->summaryArray(),
                'html' => $found->html,
                'toc' => $found->toc,
            ],
            'siblings' => array_map(fn (HelpArticle $a) => ['slug' => $a->slug, 'title' => $a->title], $siblings),
            'previous' => isset($siblings[$index - 1]) ? $siblings[$index - 1]->summaryArray() : null,
            'next' => isset($siblings[$index + 1]) ? $siblings[$index + 1]->summaryArray() : null,
            'related' => array_map(fn (HelpArticle $a) => $a->summaryArray(), $this->help->related($found)),
        ]);
    }

    public function search(Request $request): Response
    {
        $query = $this->query($request);

        return Inertia::render('help/search', [
            ...$this->shared(),
            'query' => $query,
            'results' => $this->results($query, 20),
        ]);
    }

    /**
     * Results as you type in the search box: the top few, as JSON.
     */
    public function suggest(Request $request): JsonResponse
    {
        $query = $this->query($request);

        return response()->json([
            'query' => $query,
            'results' => $this->results($query, 6),
        ]);
    }

    private function query(Request $request): string
    {
        return Str::limit(trim((string) $request->string('q')), 100, '');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function results(string $query, int $limit): array
    {
        return array_map(fn (array $row) => [
            ...$row['article']->summaryArray(),
            'categoryTitle' => $this->help->category($row['article']->category)['title'] ?? '',
            'snippet' => $row['snippet'],
            'snippetParts' => $row['snippetParts'],
            'titleParts' => $row['titleParts'],
            'section' => $row['section'],
            'complete' => $row['complete'],
        ], $this->help->search($query, $limit));
    }

    /**
     * @return array{nav: list<array{slug: string, title: string}>, supportEmail: string|null}
     */
    private function shared(): array
    {
        $nav = [];
        foreach ($this->help->categories() as $slug => $category) {
            $nav[] = ['slug' => $slug, 'title' => $category['title']];
        }

        $email = config('yardrunner.support_email');

        return [
            'nav' => $nav,
            'supportEmail' => is_string($email) && $email !== '' ? $email : null,
        ];
    }
}
