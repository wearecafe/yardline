<?php

namespace Tests\Feature;

use App\BackOffice\Attention;
use App\Email\BrandSettings;
use App\Enums\UserRole;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\UserInviteMessage;
use App\Onboarding\Setup;
use App\Tenancy\CurrentTenant;
use App\Time\TimeSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Setup: a new business saves its details and yards before anything else,
 * then the optional steps, then the checklist on Today.
 */
class SetupTest extends TestCase
{
    use RefreshDatabase;

    private const YARD = [
        'name' => 'Burlington yard',
        'code' => 'bur',
        'address_line1' => '4475 North Service Rd',
        'address_line2' => '',
        'city' => 'Burlington',
        'region' => 'ON',
        'postal_code' => 'l7l4x7',
        'country' => 'CA',
    ];

    /**
     * Sign up the way a yard does, with the email already verified.
     */
    private function signUp(): User
    {
        $this->post(route('register.store'), [
            'business_name' => 'Halton Stone Supply',
            'name' => 'Pat Owner',
            'email' => 'pat@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $owner = User::query()->where('email', 'pat@example.com')->firstOrFail();
        $owner->markEmailAsVerified();
        $this->actingAs($owner);
        CurrentTenant::set($owner->tenant_id);

        return $owner;
    }

    private function tenant(): Tenant
    {
        return Tenant::query()->findOrFail(CurrentTenant::id());
    }

    /** Fill in the two required steps. */
    private function saveBasics(): void
    {
        $this->put(route('setup.business'), [
            'name' => 'Halton Stone Supply',
            'timezone' => 'America/Toronto',
            'reply_to_email' => 'sales@haltonstone.ca',
            'tax_numbers' => 'HST 123456789 RT0001',
        ])->assertRedirect(route('setup.step', 'yards'));

        $main = Location::query()->firstOrFail();
        $this->put(route('setup.yards'), ['yards' => [['id' => $main->id, ...self::YARD]]])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('setup.step', 'work'));
    }

    public function test_a_new_owner_is_kept_in_setup_until_the_business_and_yards_are_saved(): void
    {
        $this->signUp();

        $this->get(route('dashboard'))->assertRedirect(route('setup.step', 'business'));
        $this->get(route('orders.index'))->assertRedirect(route('setup.step', 'business'));
        $this->post(route('products.store'), [])->assertRedirect(route('setup.step', 'business'));

        // Their own account, help and billing still open.
        $this->get(route('profile.edit'))->assertOk();
        $this->get(route('billing.edit'))->assertOk();
        $this->get(route('help.index'))->assertOk();

        // Steps can't be jumped.
        $this->get(route('setup.step', 'yards'))->assertRedirect(route('setup.step', 'business'));
        $this->get(route('setup.step', 'team'))->assertRedirect(route('setup.step', 'business'));
        $this->post(route('setup.next', 'business'))->assertNotFound();

        $this->get(route('setup.index'))->assertRedirect(route('setup.step', 'business'));
        $this->get(route('setup.step', 'business'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('setup/business')
            ->where('setup.step', 'business')
            ->where('setup.required', true)
            ->where('setup.basics_done', false)
            ->has('setup.steps', 7)
            ->where('setup.steps.0.open', true)
            ->where('setup.steps.1.open', false)
            ->where('business.name', 'Halton Stone Supply')
            ->where('business.reply_to_email', 'pat@example.com')
            ->where('guessTimezone', true));

        $this->saveBasics();

        $tenant = $this->tenant();
        $this->assertNotNull($tenant->onboarded_at);
        $this->assertSame('sales@haltonstone.ca', $tenant->replyToEmail());
        $this->assertSame('HST 123456789 RT0001', BrandSettings::for($tenant)->taxNumbers);

        $yard = Location::query()->sole();
        $this->assertSame('BUR', $yard->code);
        $this->assertSame('L7L 4X7', $yard->postal_code);
        $this->assertSame('Burlington', $yard->city);
        $this->assertTrue($yard->is_default);

        $this->get(route('dashboard'))->assertOk();
        $this->get(route('orders.index'))->assertOk();
    }

    public function test_yards_need_a_full_address_and_their_own_codes(): void
    {
        $this->signUp();
        $this->put(route('setup.business'), ['name' => 'Halton Stone Supply', 'timezone' => 'America/Toronto', 'reply_to_email' => 'sales@haltonstone.ca']);
        $main = Location::query()->firstOrFail();

        $this->put(route('setup.yards'), ['yards' => [
            ['id' => $main->id, ...self::YARD, 'address_line1' => '', 'postal_code' => '12345'],
            [...self::YARD, 'name' => 'Milton yard', 'code' => 'BUR'],
        ]])->assertSessionHasErrors(['yards.0.address_line1', 'yards.0.postal_code', 'yards.1.code']);

        $this->assertNull($this->tenant()->onboarded_at);

        // A second yard comes in with the first.
        $this->put(route('setup.yards'), ['yards' => [
            ['id' => $main->id, ...self::YARD],
            [...self::YARD, 'name' => 'Milton yard', 'code' => 'mil', 'city' => 'Milton', 'postal_code' => 'L9T 2X5'],
        ]])->assertSessionHasNoErrors();

        $this->assertSame(['BUR', 'MIL'], Location::query()->orderBy('id')->pluck('code')->all());
        $this->assertSame(1, Location::query()->where('is_default', true)->count());
        $this->assertNotNull($this->tenant()->onboarded_at);
    }

    public function test_people_who_dont_manage_the_business_are_not_held_in_setup(): void
    {
        $owner = $this->signUp();
        $sales = User::factory()->create(['tenant_id' => $owner->tenant_id, 'role' => UserRole::Sales]);
        $sales->setYards(Location::query()->pluck('id')->all());

        $this->actingAs($sales)->get(route('orders.index'))->assertOk();
        $this->get(route('setup.index'))->assertForbidden();
    }

    public function test_owners_who_havent_verified_their_email_verify_first(): void
    {
        $this->post(route('register.store'), [
            'business_name' => 'Halton Stone Supply',
            'name' => 'Pat Owner',
            'email' => 'pat@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->get(route('dashboard'))->assertRedirect(route('verification.notice'));
    }

    public function test_existing_businesses_never_see_setup(): void
    {
        $this->signInToNewTenant();

        $this->get(route('dashboard'))->assertOk();
        $this->assertNotNull(CurrentTenant::get()?->onboarded_at);
    }

    public function test_how_you_work_switches_add_ons_and_shapes_the_checklist(): void
    {
        $this->signUp();
        $this->saveBasics();

        $this->get(route('setup.step', 'work'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('setup/work')
            ->where('answers.rentals', false)
            ->where('answers.time', false)
            ->where('prices.rentals_cents', 19_900));

        $this->put(route('setup.work'), ['delivers' => true, 'contractors' => true, 'rentals' => true, 'time' => true])
            ->assertRedirect(route('setup.step', 'products'));

        $tenant = $this->tenant();
        $this->assertTrue($tenant->rentals_enabled);
        $this->assertTrue(TimeSettings::for($tenant)->enabled);

        $keys = array_column(Setup::for($tenant)->checklistItems(), 'key');
        $this->assertSame(['products', 'team', 'logo', 'payments', 'domain', 'trucks', 'accounts', 'fleet', 'billing'], $keys);

        // Doing something outside Setup ticks it off.
        Organization::factory()->create();
        $accounts = collect(Setup::for($tenant)->checklistItems())->firstWhere('key', 'accounts');
        $this->assertTrue($accounts['done']);

        $this->put(route('setup.work'), ['delivers' => false, 'contractors' => false, 'rentals' => false, 'time' => false]);
        $tenant = $this->tenant();
        $this->assertFalse($tenant->rentals_enabled);
        $this->assertSame(['products', 'team', 'logo', 'payments', 'domain', 'billing'], array_column(Setup::for($tenant)->checklistItems(), 'key'));
    }

    public function test_the_team_step_invites_people(): void
    {
        Notification::fake();
        $this->signUp();
        $this->saveBasics();

        $this->post(route('setup.team'), ['name' => 'Sam Counter', 'email' => 'sam@example.com', 'role' => 'sales'])
            ->assertSessionHasNoErrors();

        $sam = User::query()->where('email', 'sam@example.com')->firstOrFail();
        $this->assertSame([UserRole::Sales], $sam->roleList());
        $this->assertSame(CurrentTenant::id(), $sam->tenant_id);
        Notification::assertSentTo($sam, UserInviteMessage::class);

        // Drivers need trucks first, and nobody else can be made an owner.
        $this->post(route('setup.team'), ['name' => 'Dee', 'email' => 'dee@example.com', 'role' => 'driver'])->assertSessionHasErrors('role');
        $this->post(route('setup.team'), ['name' => 'Ola', 'email' => 'ola@example.com', 'role' => 'owner'])->assertSessionHasErrors('role');

        $this->get(route('setup.step', 'team'))->assertInertia(fn (Assert $page) => $page
            ->component('setup/team')
            ->has('people', 1)
            ->where('people.0.name', 'Sam Counter')
            ->where('setup.steps.4.status', 'done'));
    }

    public function test_the_brand_step_saves_the_colour(): void
    {
        $this->signUp();
        $this->saveBasics();

        $this->put(route('setup.brand'), ['primary' => 'nope'])->assertSessionHasErrors('primary');
        $this->put(route('setup.brand'), ['primary' => '1d4ed8'])->assertRedirect(route('setup.step', 'payments'));

        $this->assertSame('#1D4ED8', strtoupper(BrandSettings::for($this->tenant())->primary));
    }

    public function test_optional_steps_can_be_skipped_and_setup_resumes_where_it_was_left(): void
    {
        $this->signUp();
        $this->saveBasics();

        $this->post(route('setup.next', 'work'))->assertRedirect(route('setup.step', 'products'));
        $this->post(route('setup.next', 'products'))->assertRedirect(route('setup.step', 'team'));
        $this->get(route('setup.step', 'team'))->assertOk();

        $this->get(route('setup.index'))->assertRedirect(route('setup.step', 'team'));
        $this->get(route('setup.step', 'done'))->assertInertia(fn (Assert $page) => $page
            ->component('setup/done')
            ->where('setup.steps.2.status', 'skipped')
            ->where('setup.steps.3.status', 'skipped')
            ->where('setup.steps.4.status', 'todo')
            ->has('checklist'));

        $this->post(route('setup.finish'))->assertRedirect(route('dashboard'));
        $this->assertTrue(Setup::for($this->tenant())->finished());
    }

    public function test_today_shows_the_checklist_until_it_is_hidden(): void
    {
        $this->signUp();
        $this->saveBasics();

        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('setup.done', 0)
            ->where('setup.total', 6)
            ->where('setup.items.0.key', 'products'));

        $this->post(route('setup.checklist.hide'))->assertRedirect();

        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('setup', null));
    }

    public function test_staff_see_businesses_that_stalled_in_setup(): void
    {
        $tenant = Tenant::factory()->notSetUp()->create(['name' => 'Stalled Supply', 'created_at' => now()->subDays(3)]);
        Tenant::factory()->notSetUp()->create(['name' => 'Brand New Supply']);

        $items = collect(app(Attention::class)->items())->where('title', 'Hasn’t finished setup');

        $this->assertSame(['Stalled Supply'], $items->pluck('tenant.name')->values()->all());
        $this->assertSame('Not set up yet: on your business', Setup::for($tenant)->summary());
    }
}
