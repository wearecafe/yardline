<?php

namespace Tests\Feature\YardRunner;

use App\Models\Tenant;
use Database\Seeders\DemoYardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The demo is linked whenever it's turned on, and builds itself the first
 * time someone opens it after a fresh deploy.
 */
class DemoSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_home_and_login_pages_link_the_demo_when_it_is_on(): void
    {
        config(['yardline.demo' => true]);

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->where('demo', true));
        $this->get(route('login'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('demo', true));

        config(['yardline.demo' => false]);
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('demo', false));
    }

    public function test_the_demo_yard_is_built_on_first_visit(): void
    {
        config(['yardline.demo' => true]);
        $this->assertFalse(Tenant::query()->where('slug', DemoYardSeeder::SLUG)->exists());

        $this->get('/demo')->assertOk()->assertInertia(fn (Assert $page) => $page->has('accounts', count(DemoYardSeeder::ACCOUNTS)));
        $this->assertTrue(Tenant::query()->where('slug', DemoYardSeeder::SLUG)->exists());

        $this->post('/demo', ['email' => 'gord.hessels@example.com'])->assertRedirect(route('dashboard'));
    }
}
