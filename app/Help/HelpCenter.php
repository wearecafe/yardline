<?php

namespace App\Help;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * The public help center at /help. Articles are Markdown files in
 * resources/help/{category}/{slug}.md with a small front matter block:
 *
 *     ---
 *     title: Ringing up a counter sale
 *     summary: One line shown in lists and search results.
 *     order: 2
 *     popular: true
 *     related: accounts/credit-limits, money/taking-payments
 *     ---
 *
 * Link between articles with root-relative URLs (/help/accounts/credit-limits).
 * A blockquote that starts with **Tip**, **Note** or **Heads up** becomes a
 * callout. The help center tests check that every link resolves.
 */
class HelpCenter
{
    /**
     * Categories in the order they're shown. The icon is a lucide name the
     * page maps to a component.
     */
    public const CATEGORIES = [
        'getting-started' => [
            'title' => 'Getting started',
            'summary' => 'Set up your business, yards, catalogue and accounts, and find your way around.',
            'icon' => 'rocket',
        ],
        'selling' => [
            'title' => 'Counter, orders and quotes',
            'summary' => 'Ring up walk-ins, build orders and quotes, and confirm them on account or prepaid.',
            'icon' => 'shopping-cart',
        ],
        'products-pricing' => [
            'title' => 'Products and pricing',
            'summary' => 'Selling units, price lists, quantity breaks and contract prices.',
            'icon' => 'tag',
        ],
        'accounts' => [
            'title' => 'Accounts and credit',
            'summary' => 'Organizations, contacts, job sites, credit limits and holds.',
            'icon' => 'factory',
        ],
        'yard-delivery' => [
            'title' => 'Picking, dispatch and delivery',
            'summary' => 'The pick queue, the yard kiosk, trucks, the dispatch board and proof of delivery.',
            'icon' => 'truck',
        ],
        'inventory' => [
            'title' => 'Stock and purchasing',
            'summary' => 'Stock by yard and colour run, purchase orders, receiving, counts and transfers.',
            'icon' => 'boxes',
        ],
        'money' => [
            'title' => 'Invoices, payments and returns',
            'summary' => 'Tax, payments, invoices, statements, returns, credit notes, pallet deposits and pallet usage fees.',
            'icon' => 'receipt',
        ],
        'rentals' => [
            'title' => 'Equipment rentals',
            'summary' => 'Your fleet, rates, bookings, agreements, inspections, deposits and maintenance.',
            'icon' => 'wrench',
        ],
        'time' => [
            'title' => 'Time and attendance',
            'summary' => 'Clocking in at the counter tablet or on a phone, timesheets, overtime and sending hours to payroll.',
            'icon' => 'clock',
        ],
        'integrations' => [
            'title' => 'Integrations',
            'summary' => 'QuickBooks Online, and how card payments, tax and text messages are set up.',
            'icon' => 'plug',
        ],
        'for-customers' => [
            'title' => 'For contractors and renters',
            'summary' => 'Help for your customers: the contractor portal, quotes and booking equipment online.',
            'icon' => 'users',
        ],
    ];

    /** @var array<string, HelpArticle>|null */
    private ?array $articles = null;

    private ?HelpSearchIndex $index = null;

    public function __construct(private readonly ?string $root = null) {}

    /**
     * @return array<string, array{title: string, summary: string, icon: string}>
     */
    public function categories(): array
    {
        return self::CATEGORIES;
    }

    /**
     * @return array{title: string, summary: string, icon: string}|null
     */
    public function category(string $slug): ?array
    {
        return self::CATEGORIES[$slug] ?? null;
    }

    /**
     * Every article, keyed by "category/slug", in category then article order.
     *
     * @return array<string, HelpArticle>
     */
    public function all(): array
    {
        if ($this->articles !== null) {
            return $this->articles;
        }

        $articles = [];

        foreach (array_keys(self::CATEGORIES) as $category) {
            $found = [];

            foreach (glob($this->directory()."/{$category}/*.md") ?: [] as $file) {
                $article = $this->parse($category, $file);
                $found[] = $article;
            }

            usort($found, fn (HelpArticle $a, HelpArticle $b) => [$a->order, $a->title] <=> [$b->order, $b->title]);

            foreach ($found as $article) {
                $articles[$article->path()] = $article;
            }
        }

        return $this->articles = $articles;
    }

    /**
     * @return list<HelpArticle>
     */
    public function inCategory(string $category): array
    {
        return array_values(array_filter($this->all(), fn (HelpArticle $a) => $a->category === $category));
    }

    public function find(string $category, string $slug): ?HelpArticle
    {
        return $this->all()["{$category}/{$slug}"] ?? null;
    }

    /**
     * @return list<HelpArticle>
     */
    public function popular(int $limit = 10): array
    {
        $popular = array_filter($this->all(), fn (HelpArticle $a) => $a->popular);

        return array_slice(array_values($popular), 0, $limit);
    }

    /**
     * Articles matching the query, best first. See HelpSearchIndex for how
     * words are matched and ranked.
     *
     * @return list<array{
     *     article: HelpArticle,
     *     snippet: string,
     *     snippetParts: list<array{text: string, hit: bool}>,
     *     titleParts: list<array{text: string, hit: bool}>,
     *     section: array{id: string, title: string}|null,
     *     complete: bool,
     * }>
     */
    public function search(string $query, int $limit = 20): array
    {
        $this->index ??= new HelpSearchIndex(
            $this->all(),
            array_map(fn (array $category) => $category['title'], self::CATEGORIES),
        );

        return $this->index->search($query, $limit);
    }

    /**
     * Related articles: those named in front matter, then those linked from
     * the body, then the rest of the category.
     *
     * @return list<HelpArticle>
     */
    public function related(HelpArticle $article, int $limit = 4): array
    {
        $paths = $article->related;

        preg_match_all('#href="/help/([a-z0-9-]+/[a-z0-9-]+)"#', $article->html, $links);
        $paths = [...$paths, ...$links[1]];

        foreach ($this->inCategory($article->category) as $sibling) {
            $paths[] = $sibling->path();
        }

        $related = [];

        foreach (array_unique($paths) as $path) {
            $found = $this->all()[$path] ?? null;

            if ($found !== null && $found->path() !== $article->path()) {
                $related[] = $found;
            }
        }

        return array_slice($related, 0, $limit);
    }

    public function directory(): string
    {
        return $this->root ?? resource_path('help');
    }

    private function parse(string $category, string $file): HelpArticle
    {
        $source = (string) file_get_contents($file);
        $slug = basename($file, '.md');

        if (! preg_match('/\A---\R(.*?)\R---\R(.*)\z/s', $source, $parts)) {
            throw new RuntimeException("Help article {$category}/{$slug} has no front matter.");
        }

        $meta = [];
        foreach (preg_split('/\R/', $parts[1]) ?: [] as $line) {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $meta[trim($key)] = trim($value);
            }
        }

        if (($meta['title'] ?? '') === '') {
            throw new RuntimeException("Help article {$category}/{$slug} has no title.");
        }

        $html = Str::markdown($parts[2], [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        [$html, $toc] = $this->anchorHeadings($html);

        return new HelpArticle(
            category: $category,
            slug: $slug,
            title: $meta['title'],
            summary: $meta['summary'] ?? '',
            order: (int) ($meta['order'] ?? 100),
            popular: in_array(Str::lower($meta['popular'] ?? ''), ['true', 'yes', '1'], true),
            html: $this->callouts($html),
            text: trim((string) preg_replace('/\s+/u', ' ', html_entity_decode((string) preg_replace('/<[^>]+>/', ' ', $html)))),
            toc: $toc,
            related: array_values(array_filter(array_map('trim', explode(',', $meta['related'] ?? '')))),
        );
    }

    /**
     * Gives each h2 an id so the page can link to it, and lists them.
     *
     * @return array{string, list<array{id: string, title: string}>}
     */
    private function anchorHeadings(string $html): array
    {
        $toc = [];

        $html = (string) preg_replace_callback('#<h2>(.*?)</h2>#s', function (array $match) use (&$toc) {
            $title = trim(html_entity_decode(strip_tags($match[1])));
            $id = Str::slug($title) ?: 'section';

            $base = $id;
            $n = 2;
            while (in_array($id, array_column($toc, 'id'), true)) {
                $id = $base.'-'.$n++;
            }

            $toc[] = ['id' => $id, 'title' => $title];

            return "<h2 id=\"{$id}\">{$match[1]}</h2>";
        }, $html);

        return [$html, $toc];
    }

    private function callouts(string $html): string
    {
        return (string) preg_replace_callback(
            '#<blockquote>\s*<p><strong>(Tip|Note|Heads up)</strong>#',
            fn (array $m) => '<blockquote class="callout callout-'.Str::slug($m[1]).'"><p><strong>'.$m[1].'</strong>',
            $html,
        );
    }
}
