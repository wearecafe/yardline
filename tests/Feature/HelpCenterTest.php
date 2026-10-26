<?php

namespace Tests\Feature;

use App\Help\HelpCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/**
 * The public help center at /help.
 */
class HelpCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_help_center_is_public(): void
    {
        $this->get('/help')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('help/index')
                ->has('categories', count(HelpCenter::CATEGORIES))
                ->has('popular')
                ->has('nav'));
    }

    public function test_every_category_and_article_renders(): void
    {
        $help = new HelpCenter;

        foreach (array_keys(HelpCenter::CATEGORIES) as $category) {
            $this->assertNotEmpty($help->inCategory($category), "The {$category} category has no articles.");

            $this->get("/help/{$category}")
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('help/category')->where('category.slug', $category));
        }

        foreach ($help->all() as $article) {
            $this->get($article->url())
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('help/article')
                    ->where('article.title', $article->title)
                    ->has('related'));
        }
    }

    public function test_every_help_link_and_related_article_exists(): void
    {
        $help = new HelpCenter;
        $paths = array_keys($help->all());

        foreach ($help->all() as $article) {
            preg_match_all('#href="/help/([^"]*)"#', $article->html, $links);

            foreach ($links[1] as $link) {
                $target = rtrim($link, '/');
                $this->assertTrue(
                    in_array($target, $paths, true) || isset(HelpCenter::CATEGORIES[$target]),
                    "{$article->path()} links to /help/{$link}, which doesn't exist.",
                );
            }

            foreach ($article->related as $related) {
                $this->assertContains($related, $paths, "{$article->path()} lists {$related} as related, which doesn't exist.");
            }

            $this->assertNotSame('', $article->summary, "{$article->path()} has no summary.");
        }
    }

    public function test_unknown_pages_are_not_found(): void
    {
        $this->get('/help/nope')->assertNotFound();
        $this->get('/help/selling/nope')->assertNotFound();
        $this->get('/help/nope/counter-sale')->assertNotFound();
    }

    public function test_search_finds_articles_by_title_first(): void
    {
        $this->get('/help/search?q=pallet+deposits')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('help/search')
                ->where('query', 'pallet deposits')
                ->where('results.0.slug', 'pallet-deposits'));

        $this->get('/help/search?q=zzzqqq')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('results', 0));
    }

    public function test_articles_get_heading_anchors_and_callouts(): void
    {
        $dir = storage_path('framework/testing/help-'.uniqid());
        File::ensureDirectoryExists("{$dir}/selling");
        File::put("{$dir}/selling/sample.md", <<<'MD'
            ---
            title: Sample
            summary: A sample.
            ---

            ## First step

            > **Tip** Do it this way.

            ## First step

            <script>alert(1)</script>
            MD);

        try {
            $article = (new HelpCenter($dir))->find('selling', 'sample');

            $this->assertNotNull($article);
            $this->assertStringContainsString('<h2 id="first-step">', $article->html);
            $this->assertStringContainsString('<h2 id="first-step-2">', $article->html);
            $this->assertStringContainsString('class="callout callout-tip"', $article->html);
            $this->assertStringNotContainsString('<script>', $article->html);
            $this->assertCount(2, $article->toc);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_an_article_without_a_title_is_an_error(): void
    {
        $dir = storage_path('framework/testing/help-'.uniqid());
        File::ensureDirectoryExists("{$dir}/selling");
        File::put("{$dir}/selling/untitled.md", "---\nsummary: No title.\n---\n\nBody.\n");

        try {
            $this->expectException(RuntimeException::class);
            (new HelpCenter($dir))->all();
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_support_email_is_shared_when_set(): void
    {
        config(['yardline.support_email' => 'help@example.com']);

        $this->get('/help')->assertInertia(fn (Assert $page) => $page->where('supportEmail', 'help@example.com'));

        config(['yardline.support_email' => null]);

        $this->get('/help')->assertInertia(fn (Assert $page) => $page->where('supportEmail', null));
    }
}
