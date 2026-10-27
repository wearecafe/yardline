<?php

namespace Tests\Unit;

use App\Help\HelpArticle;
use App\Help\HelpSearchIndex;
use PHPUnit\Framework\TestCase;

/**
 * Help search: stems, typos, prefixes as you type, yard synonyms, and
 * pointing at the section that matched.
 */
class HelpSearchIndexTest extends TestCase
{
    private function index(): HelpSearchIndex
    {
        return new HelpSearchIndex([
            $this->article('money', 'pallet-deposits', 'Pallet deposits', 'Charge a deposit per pallet.', <<<'HTML'
                <p>Deposits come back when the pallet does.</p>
                <h2 id="returns">Returns</h2><p>Credit the deposit when pallets are returned to the yard.</p>
                HTML),
            $this->article('money', 'taking-payments', 'Taking payments', 'Card, cash and cheque.', <<<'HTML'
                <h2 id="at-the-counter">At the counter</h2><p>Tap the card on the reader.</p>
                <h2 id="on-delivery">On delivery</h2><p>Drivers can collect payment on delivery.</p>
                HTML),
            $this->article('getting-started', 'yards', 'Adding yards', 'Each location.', <<<'HTML'
                <p>Give each yard a short code.</p>
                <table><tr><td>Owner</td><td>Runs</td></tr></table>
                HTML),
        ], ['money' => 'Money', 'getting-started' => 'Getting started']);
    }

    private function article(string $category, string $slug, string $title, string $summary, string $html): HelpArticle
    {
        return new HelpArticle($category, $slug, $title, $summary, 1, false, $html, strip_tags($html), [], []);
    }

    /**
     * @return list<string>
     */
    private function slugs(string $query): array
    {
        return array_map(fn (array $row) => $row['article']->slug, $this->index()->search($query));
    }

    public function test_plurals_and_endings_match(): void
    {
        $this->assertSame('pallet-deposits', $this->slugs('pallets deposit')[0]);
        $this->assertSame(HelpSearchIndex::stem('charged'), HelpSearchIndex::stem('charges'));
        $this->assertSame('pick', HelpSearchIndex::stem('picking'));
        $this->assertSame('delivery', HelpSearchIndex::stem('deliveries'));
    }

    public function test_typos_still_find_the_article(): void
    {
        $this->assertSame('pallet-deposits', $this->slugs('palet depsoit')[0]);
    }

    public function test_a_word_spelled_right_does_not_match_its_neighbours(): void
    {
        // "card" is a word the articles use, so it doesn't also match "yard".
        $this->assertSame(['taking-payments'], $this->slugs('card'));

        // "cod" is a known yard word, not the start of "code".
        $this->assertSame(['taking-payments'], $this->slugs('cod'));
    }

    public function test_the_last_word_matches_as_you_type(): void
    {
        $this->assertSame('pallet-deposits', $this->slugs('pall')[0]);
    }

    public function test_yard_synonyms_match(): void
    {
        $this->assertSame('pallet-deposits', $this->slugs('skid')[0]);
        $this->assertSame(['taking-payments'], $this->slugs('cash on delivery'));
    }

    public function test_results_point_at_the_section_and_mark_the_words(): void
    {
        $result = $this->index()->search('returned deposit')[0];

        $this->assertSame('pallet-deposits', $result['article']->slug);
        $this->assertSame(['id' => 'returns', 'title' => 'Returns'], $result['section']);
        $this->assertTrue($result['complete']);
        $this->assertContains(['text' => 'deposits', 'hit' => true], $result['titleParts']);
        $this->assertContains(['text' => 'returned', 'hit' => true], $result['snippetParts']);
        $this->assertStringContainsString('returned to the yard', $result['snippet']);
    }

    public function test_articles_with_every_word_come_before_close_matches(): void
    {
        $results = $this->index()->search('deposit reader');

        $this->assertSame([], array_values(array_filter($results, fn (array $r) => $r['complete'])));
        $this->assertCount(2, $results);
    }

    public function test_table_cells_are_separate_words(): void
    {
        $this->assertSame(['yards'], $this->slugs('runs'));
    }

    public function test_nothing_found(): void
    {
        $this->assertSame([], $this->slugs('zzzqqq'));
        $this->assertSame([], $this->slugs('   '));
    }
}
