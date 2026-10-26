<?php

namespace Tests\Feature\Yardline;

use App\Enums\StockMovementType;
use App\Enums\Unit;
use App\Inventory\InventoryLedger;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Stop;
use App\Models\Tenant;
use App\Notifications\PortalLinkMessage;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The contractor portal and the audit log.
 */
class PortalAndAuditTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Location $yard;

    private Organization $org;

    private Customer $mike;

    private Product $mortar;

    protected function setUp(): void
    {
        parent::setUp();

        config(['yardline.tax.driver' => 'flat', 'yardline.tax.flat_rate' => 0.13, 'yardline.payments.driver' => 'fake']);

        $this->signInToNewTenant();
        $this->tenant = CurrentTenant::get() ?? Tenant::factory()->create();
        $this->yard = Location::query()->firstOrFail();
        $this->yard->update(['address_line1' => '100 Dundas St E', 'city' => 'Waterdown', 'region' => 'ON', 'postal_code' => 'L9H 7L6']);

        $this->org = Organization::factory()->withCredit(1_000_000)->create(['name' => 'Brantwood Landscaping']);
        $this->mike = Customer::factory()->create(['organization_id' => $this->org->id, 'first_name' => 'Mike', 'email' => 'mike@brantwood.example']);
        $this->mortar = Product::factory()->create(['name' => 'Type S mortar', 'unit' => Unit::Bag, 'price_cents' => 1_000]);
        app(InventoryLedger::class)->record($this->mortar, $this->yard, 100, StockMovementType::Receipt);
    }

    private function confirmedOrder(): Order
    {
        $this->post(route('orders.store'), ['type' => 'sale', 'organization_id' => $this->org->id, 'customer_id' => $this->mike->id, 'location_id' => $this->yard->id, 'fulfillment' => 'pickup']);
        $order = Order::query()->latest('id')->firstOrFail();
        $this->post(route('orders.lines.store', $order), ['product_id' => $this->mortar->id, 'quantity' => 10]);
        $this->post(route('orders.confirm', $order), ['settlement' => 'account'])->assertSessionHasNoErrors();

        return $order->refresh();
    }

    private function enterPortal(): void
    {
        $this->get(URL::temporarySignedRoute('portal.enter', now()->addMinutes(30), ['tenant' => $this->tenant->slug, 'customer' => $this->mike->id]))
            ->assertRedirect(route('portal.home'));
    }

    public function test_contractors_sign_in_with_an_emailed_link(): void
    {
        Notification::fake();
        auth()->logout();

        $this->get(route('portal.login', $this->tenant->slug))->assertOk();
        $this->post(route('portal.send', $this->tenant->slug), ['email' => 'MIKE@brantwood.example'])->assertSessionHas('portal_sent');
        $this->post(route('portal.send', $this->tenant->slug), ['email' => 'nobody@example.com'])->assertSessionHas('portal_sent');
        Notification::assertSentTo($this->mike, PortalLinkMessage::class);
        Notification::assertCount(1);

        $this->get(route('portal.enter', ['tenant' => $this->tenant->slug, 'customer' => $this->mike->id]))->assertForbidden();
        $this->get(route('portal.home'))->assertRedirect();

        $this->enterPortal();
        $this->get(route('portal.home'))->assertOk();
        $this->post(route('portal.logout'))->assertRedirect(route('portal.login', $this->tenant->slug));
        $this->get(route('portal.home'))->assertRedirect();
    }

    public function test_contractors_reorder_track_and_request_pickups(): void
    {
        $order = $this->confirmedOrder();
        $other = Organization::factory()->create();
        $theirs = Order::query()->create(['type' => 'sale', 'number' => 'SO-9999', 'organization_id' => $other->id, 'location_id' => $this->yard->id]);
        auth()->logout();
        $this->enterPortal();

        $this->get(route('portal.home'))->assertOk()->assertInertia(fn ($page) => $page->where('orders.0.number', $order->number));
        $this->get(route('portal.order', $order))->assertOk();
        $this->get(route('portal.order', $theirs))->assertNotFound();

        $this->post(route('portal.reorder', $order))->assertRedirect();
        $draft = Order::query()->latest('id')->firstOrFail();
        $this->assertSame('portal', $draft->source);
        $this->assertSame('10.000', $draft->lines()->value('quantity'));
        $this->assertSame($this->mike->id, $draft->customer_id);

        $this->post(route('portal.calculate'), ['area' => 400, 'lines' => [['product_id' => $this->mortar->id, 'quantity' => 6]]])->assertRedirect();
        $this->assertSame(4, Order::query()->count());

        $this->get(route('portal.statement'))->assertOk();
        $this->get(route('portal.statement.pdf'))->assertOk();

        $this->post(route('portal.pallet-pickup'), ['count' => 8])->assertSessionHasNoErrors();
        $this->assertSame('pallet_pickup', Stop::query()->latest('id')->value('kind'));

        // What a contractor does is theirs in the audit log.
        $this->assertTrue(AuditEvent::query()->where('customer_id', $this->mike->id)->exists());
    }

    public function test_changes_to_orders_prices_and_credit_are_logged(): void
    {
        $this->put(route('organizations.update', $this->org), [
            ...$this->org->only('name', 'account_number', 'email', 'phone', 'country'),
            'credit_limit' => '25000.00',
            'payment_terms' => 'net_30',
        ]);
        $this->product();
        $order = $this->confirmedOrder();

        $credit = AuditEvent::query()->where('subject_type', 'organization')->where('event', 'updated')->latest('id')->first();
        $this->assertNotNull($credit);
        $this->assertArrayHasKey('credit_limit_cents', $credit->changes ?? []);

        $this->assertTrue(AuditEvent::query()->where('subject_type', 'order')->where('subject_id', $order->id)->where('event', 'updated')->get()
            ->contains(fn (AuditEvent $e) => ($e->changes['status'][1] ?? null) === 'confirmed'));

        $this->assertTrue(AuditEvent::query()->where('subject_type', 'product')->where('event', 'updated')->exists());

        $this->get(route('audit.index'))->assertOk();
        $this->get(route('audit.index', ['kind' => 'order', 'search' => $order->number]))->assertOk()->assertInertia(fn ($page) => $page->where('events.data.0.label', $order->number));
        $this->get(route('orders.show', $order))->assertOk()->assertInertia(fn ($page) => $page->has('history.0'));
    }

    private function product(): void
    {
        $this->put(route('products.update', $this->mortar), [
            ...$this->mortar->only('sku', 'name', 'kind', 'track_inventory', 'is_active'),
            'unit' => 'bag',
            'price' => '11.50',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1_150, $this->mortar->refresh()->price_cents);
    }
}
