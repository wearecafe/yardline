<?php

namespace Tests\Feature\YardRunner;

use App\Enums\RentalStatus;
use App\Enums\StockMovementType;
use App\Enums\Unit;
use App\Enums\UserRole;
use App\Fulfillment\DriverRun;
use App\Http\Controllers\Settings\AlertsController;
use App\Inventory\InventoryLedger;
use App\Models\Location;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\PushSubscription;
use App\Models\Rental;
use App\Models\Shipment;
use App\Models\Tenant;
use App\Models\Truck;
use App\Models\User;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\Channels\WebPushMessage;
use App\Notifications\StaffAlert;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The installable app and its alerts (web push): where it opens, turning
 * alerts on and off for a device, and who hears about what.
 */
class PushAlertsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Tenant $tenant;

    private Location $yard;

    private Location $otherYard;

    private Product $sand;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'yardrunner.tax.driver' => 'flat',
            'yardrunner.tax.flat_rate' => 0.13,
            'yardrunner.payments.driver' => 'fake',
            'yardrunner.push.public_key' => 'BPublicKeyForTests',
            'yardrunner.push.private_key' => 'private-key-for-tests',
        ]);
        Storage::fake(config('yardrunner.files_disk'));

        $this->owner = $this->signInToNewTenant();
        $this->tenant = CurrentTenant::get() ?? Tenant::factory()->create();
        // Keep "today" the same day for the app and for now() in the test.
        $this->tenant->update(['timezone' => 'UTC']);
        CurrentTenant::set($this->tenant->refresh());
        $this->yard = Location::query()->firstOrFail();
        $this->otherYard = Location::factory()->create(['name' => 'North yard']);

        $this->sand = Product::factory()->create(['name' => 'Polymeric sand', 'sku' => 'SND-POLY', 'unit' => Unit::Bag, 'price_cents' => 4_000]);
        app(InventoryLedger::class)->record($this->sand, $this->yard, 100, StockMovementType::Receipt);

        $this->org = Organization::factory()->withCredit(10_000_000)->create(['name' => 'Brantwood Landscaping']);
    }

    /**
     * @param  list<Location>|null  $yards
     */
    private function user(UserRole $role, string $name, ?array $yards = null, bool $alerts = true): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => $role, 'name' => $name]);

        if ($user->isYardScoped()) {
            $user->setYards(array_map(fn (Location $l) => $l->id, $yards ?? [$this->yard]));
        }

        if ($alerts) {
            $this->device($user);
        }

        return $user;
    }

    private function device(User $user, string $endpoint = ''): PushSubscription
    {
        $endpoint = $endpoint !== '' ? $endpoint : 'https://fcm.googleapis.com/fcm/send/'.$user->id.'-'.uniqid();

        return PushSubscription::query()->create([
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::hash($endpoint),
            'public_key' => 'BKey',
            'auth_token' => 'auth',
        ]);
    }

    private function confirmedOrder(): Order
    {
        $this->actingAs($this->owner);

        $this->post(route('orders.store'), [
            'type' => 'sale',
            'organization_id' => $this->org->id,
            'location_id' => $this->yard->id,
            'fulfillment' => 'pickup',
        ])->assertSessionHasNoErrors();
        $order = Order::query()->latest('id')->firstOrFail();

        $this->post(route('orders.lines.store', $order), ['product_id' => $this->sand->id, 'quantity' => 20])->assertSessionHasNoErrors();
        $this->post(route('orders.confirm', $order), ['settlement' => 'account'])->assertSessionHasNoErrors();

        return $order->refresh();
    }

    /**
     * @param  array<string, mixed>  $picking
     */
    private function picking(array $picking): void
    {
        $this->tenant->update(['settings' => [...($this->tenant->settings ?? []), 'picking' => $picking]]);
        CurrentTenant::set($this->tenant->refresh());
    }

    public function test_the_installed_app_opens_on_each_persons_home_screen(): void
    {
        $this->get(route('pwa.start'))->assertRedirect(route('dashboard'));

        $this->actingAs($this->user(UserRole::YardCrew, 'Luis', alerts: false));
        $this->get(route('pwa.start'))->assertRedirect(route('orders.queue'));

        $this->actingAs($this->user(UserRole::Driver, 'Sam', alerts: false));
        $this->get(route('pwa.start'))->assertRedirect(route('driver.today'));

        auth()->logout();
        $this->get(route('pwa.start'))->assertRedirect(route('login'));
    }

    public function test_the_manifest_and_offline_page_are_in_place(): void
    {
        $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('YardRunner', $manifest['name']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertStringStartsWith('/start', $manifest['start_url']);

        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
        }

        $this->assertContains('maskable', array_column($manifest['icons'], 'purpose'));
        $this->assertFileExists(public_path('sw.js'));
        $this->assertFileExists(public_path('offline.html'));

        // Staff pages link it; the page carries the theme for the status bar.
        $this->get(route('dashboard'))->assertOk()->assertSee('rel="manifest"', false);
    }

    public function test_a_device_turns_alerts_on_and_off(): void
    {
        $crew = $this->user(UserRole::YardCrew, 'Luis', alerts: false);
        $this->actingAs($crew);

        $this->get(route('alerts.edit'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('settings/alerts')
            ->where('publicKey', 'BPublicKeyForTests')
            ->where('alerts', fn ($alerts) => collect($alerts)->contains('New orders in the pick queue at your yards'))
            ->has('devices', 0));

        $endpoint = 'https://fcm.googleapis.com/fcm/send/abc123';
        $payload = ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'BNcRdreALRFX', 'auth' => 'tBHItJI5svbpez7KI4CCXg'], 'content_encoding' => 'aes128gcm'];

        $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/130.0 Mobile Safari/537.36')
            ->postJson(route('alerts.devices.store'), $payload)
            ->assertOk();

        $device = PushSubscription::query()->sole();
        $this->assertSame($crew->id, $device->user_id);
        $this->assertSame('Chrome on Android', $device->device);

        // Checking in again doesn't duplicate it.
        $this->postJson(route('alerts.devices.store'), $payload)->assertOk();
        $this->assertSame(1, PushSubscription::query()->count());

        // A shared tablet: whoever signs in next takes its alerts.
        $mia = $this->user(UserRole::YardCrew, 'Mia', alerts: false);
        $this->actingAs($mia)->postJson(route('alerts.devices.store'), $payload)->assertOk();
        $this->assertSame($mia->id, PushSubscription::query()->sole()->user_id);

        // Only real push services, over HTTPS.
        $this->postJson(route('alerts.devices.store'), [...$payload, 'endpoint' => 'http://example.com/push'])->assertJsonValidationErrors('endpoint');

        $this->deleteJson(route('alerts.devices.destroy'), ['endpoint' => $endpoint])->assertOk();
        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_someone_can_only_remove_their_own_devices(): void
    {
        $luis = $this->user(UserRole::YardCrew, 'Luis');
        $mia = $this->user(UserRole::YardCrew, 'Mia', alerts: false);

        $this->actingAs($mia)->delete(route('alerts.devices.destroy'), ['id' => $luis->pushSubscriptions()->value('id')]);

        $this->assertSame(1, $luis->pushSubscriptions()->count());
    }

    public function test_a_new_order_alerts_yard_crew_at_its_yard(): void
    {
        Notification::fake();

        $luis = $this->user(UserRole::YardCrew, 'Luis');
        $north = $this->user(UserRole::YardCrew, 'Nora', [$this->otherYard]);
        $quiet = $this->user(UserRole::YardCrew, 'Quinn', alerts: false);
        $sales = $this->user(UserRole::Sales, 'Sally');

        $order = $this->confirmedOrder();

        Notification::assertSentTo($luis, StaffAlert::class, function (StaffAlert $alert) use ($order) {
            return $alert->kind === 'order_to_pick'
                && str_contains($alert->message->body, $order->number)
                && str_contains($alert->message->body, 'Brantwood Landscaping')
                && ! str_contains($alert->message->body, '$')
                && $alert->message->url === route('orders.queue', absolute: false);
        });
        Notification::assertNotSentTo([$north, $quiet, $sales, $this->owner], StaffAlert::class);
    }

    public function test_a_counter_sale_handed_over_on_the_spot_alerts_nobody(): void
    {
        Notification::fake();

        $luis = $this->user(UserRole::YardCrew, 'Luis');
        $this->actingAs($this->owner);

        $this->post(route('counter.store'), [
            'location_id' => $this->yard->id,
            'lines' => [['product_id' => $this->sand->id, 'quantity' => 3]],
            'settlement' => 'prepaid',
            'tender' => 'cash',
            'tendered' => '200.00',
        ])->assertRedirect();

        Notification::assertNotSentTo($luis, StaffAlert::class);
    }

    public function test_a_load_waiting_for_a_check_alerts_who_can_sign_off_and_a_rejection_reaches_the_picker(): void
    {
        Notification::fake();
        $this->picking(['checks' => ['picked' => ['required' => true, 'roles' => ['yard_crew'], 'different_user' => true]]]);

        $luis = $this->user(UserRole::YardCrew, 'Luis');
        $mia = $this->user(UserRole::YardCrew, 'Mia');
        $north = $this->user(UserRole::YardCrew, 'Nora', [$this->otherYard]);

        $order = $this->confirmedOrder();

        // Only what happens from here on.
        Notification::fake();

        $this->actingAs($luis)->post(route('orders.pick', $order))->assertSessionHasNoErrors();
        $shipment = Shipment::query()->latest('id')->firstOrFail();

        foreach ($shipment->lines as $line) {
            $this->post(route('shipments.lines.record', [$shipment, $line]), ['picked_quantity' => $line->quantity])->assertSessionHasNoErrors();
        }

        $this->post(route('shipments.picked', $shipment))->assertSessionHasNoErrors();

        Notification::assertSentTo($mia, StaffAlert::class, fn (StaffAlert $alert) => $alert->kind === 'check_needed'
            && $alert->message->title === 'Check needed at Picked'
            && str_contains($alert->message->body, 'marked by Luis'));
        // Not the picker (someone else has to check), not another yard.
        Notification::assertNotSentTo([$luis, $north], StaffAlert::class);

        $this->actingAs($mia)->post(route('shipments.reject', $shipment), ['reason' => 'Two bags short'])->assertSessionHasNoErrors();

        Notification::assertSentTo($luis, StaffAlert::class, fn (StaffAlert $alert) => $alert->kind === 'check_rejected'
            && str_contains($alert->message->body, 'Two bags short'));
    }

    public function test_drivers_hear_when_theyre_put_on_a_truck_and_when_a_stop_lands_on_todays_run(): void
    {
        Notification::fake();

        $sam = $this->user(UserRole::Driver, 'Sam');
        $truck = Truck::query()->create(['name' => 'Truck 2', 'position' => 1]);
        $truck->drivers()->attach($sam->id, ['tenant_id' => $this->tenant->id]);

        $this->actingAs($this->owner)
            ->put(route('dispatch.driver', $truck), ['date' => now()->addDay()->toDateString(), 'user_id' => $sam->id])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($sam, StaffAlert::class, fn (StaffAlert $alert) => $alert->kind === 'run_assigned'
            && $alert->message->title === 'You’re on Truck 2 tomorrow');

        // Putting him on the same truck again isn't news.
        Notification::fake();
        $this->put(route('dispatch.driver', $truck), ['date' => now()->addDay()->toDateString(), 'user_id' => $sam->id])->assertSessionHasNoErrors();
        Notification::assertNothingSent();

        // A delivery scheduled onto his truck today.
        app(DriverRun::class)->assign($truck, now()->toDateString(), $sam);
        Notification::fake();

        $this->post(route('orders.store'), [
            'type' => 'sale',
            'organization_id' => $this->org->id,
            'location_id' => $this->yard->id,
            'fulfillment' => 'delivery',
            'delivery_address' => ['line1' => '251 Hamilton St N', 'city' => 'Waterdown', 'postal_code' => 'L8B 1A1'],
        ])->assertSessionHasNoErrors();
        $order = Order::query()->latest('id')->firstOrFail();
        $this->post(route('orders.lines.store', $order), ['product_id' => $this->sand->id, 'quantity' => 10])->assertSessionHasNoErrors();
        $this->post(route('orders.confirm', $order), ['settlement' => 'account'])->assertSessionHasNoErrors();
        $stop = $order->stops()->firstOrFail();

        $this->patch(route('stops.schedule', $stop), ['truck_id' => $truck->id, 'date' => now()->toDateString(), 'starts_at' => '13:30'])->assertSessionHasNoErrors();

        Notification::assertSentTo($sam, StaffAlert::class, fn (StaffAlert $alert) => $alert->kind === 'stop_added'
            && $alert->message->body === '13:30 · Delivery · 251 Hamilton St N, Waterdown');
    }

    public function test_the_rental_desk_hears_about_an_overdue_rental_once_a_day(): void
    {
        Notification::fake();

        $this->tenant->forceFill(['rentals_enabled' => true])->save();
        CurrentTenant::set($this->tenant->refresh());

        $desk = $this->user(UserRole::RentalDesk, 'Rita');
        $northDesk = $this->user(UserRole::RentalDesk, 'Nate', [$this->otherYard]);

        $rental = Rental::factory()->create([
            'location_id' => $this->yard->id,
            'status' => RentalStatus::Out,
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subHours(3),
        ]);

        CurrentTenant::set(null);
        $this->artisan('yardrunner:rentals:remind')->assertSuccessful();
        $this->artisan('yardrunner:rentals:remind')->assertSuccessful();

        Notification::assertSentToTimes($desk, StaffAlert::class, 1);
        Notification::assertSentTo($desk, StaffAlert::class, fn (StaffAlert $alert) => $alert->kind === 'rental_overdue'
            && str_contains($alert->message->body, $rental->number));
        Notification::assertNotSentTo($northDesk, StaffAlert::class);
    }

    public function test_without_keys_an_alert_is_logged_not_sent(): void
    {
        config(['yardrunner.push.public_key' => null, 'yardrunner.push.private_key' => null]);
        $this->assertFalse(WebPushChannel::configured());

        Log::spy();
        $luis = $this->user(UserRole::YardCrew, 'Luis');

        $luis->notifyNow(new StaffAlert('test', new WebPushMessage('Alerts are on', 'Test', '/settings/alerts')));

        Log::shouldHaveReceived('info')->withArgs(fn (string $message) => str_contains($message, 'no VAPID keys'))->once();
        $this->assertNull($luis->pushSubscriptions()->value('last_used_at'));
    }

    public function test_the_device_list_names_browsers_plainly(): void
    {
        $this->assertSame('Safari on iPhone', AlertsController::describe('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1'));
        $this->assertSame('Edge on Windows', AlertsController::describe('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36 Edg/130.0'));
        $this->assertSame('Browser', AlertsController::describe(''));
    }
}
