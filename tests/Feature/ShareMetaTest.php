<?php

namespace Tests\Feature;

use App\Help\HelpCenter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Link previews (Open Graph / Twitter card) on public pages only.
 */
class ShareMetaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_home_page_has_a_link_preview(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<meta property="og:image" content="'.url('/og-image.png').'">', false)
            ->assertSee('<meta name="twitter:card" content="summary_large_image">', false)
            ->assertSee('<meta property="og:title" content="YardRunner: inventory, orders and rentals', false);

        $this->assertFileExists(public_path('og-image.png'));
    }

    public function test_help_articles_use_their_own_title_and_summary(): void
    {
        $article = (new HelpCenter)->inCategory(array_key_first(HelpCenter::CATEGORIES))[0];

        $this->get("/help/{$article->category}/{$article->slug}")
            ->assertOk()
            ->assertSee('<meta property="og:title" content="'.e($article->title).' · YardRunner Help">', false)
            ->assertSee('<meta property="og:image"', false);
    }

    public function test_the_sign_in_page_has_a_link_preview(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('<meta property="og:image"', false);
    }

    public function test_signed_in_pages_have_no_link_preview(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('og:image', false)
            ->assertDontSee('twitter:card', false);
    }
}
