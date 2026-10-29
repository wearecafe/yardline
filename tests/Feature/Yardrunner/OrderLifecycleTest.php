<?php

namespace Tests\Feature\YardRunner;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\StockMovementType;
use App\Enums\UserRole;
use App\Inventory\InventoryLedger;
use App\Models\Location;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Flat 13% tax is used in tests (phpunit.xml / config default).
 */
class OrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Location $yard;

    protected function setUp(): void
    {
        parent::setUp();

        config(['yardrunner.tax.driver' => 'flat', 'yardrunner.tax.flat_rate' => 0.13, 'yardrunner.payments.driver' => 'fake']);

        $this->user = $this->signInToNewTenant();
        $this->yard = Location::query()->firstOrFail();
    }

    private function draftFor(?Organization $org, array $attributes = []): Order
    {
        $this->post(route('orders.store'), [
            'type' => 'sale',
            'organization_id' => $org?->id,
            'location_id' => $this->yard->id,
            'fulfillment' => 'pickup',
            ...$attributes,
        ])->assertRedirect();

        return Order::query()->latest('id')->firstOrFail();
    }

    private function addLine(Order $order, Product $product, string|int $qty): void
    {
        $this->post(route('orders.lines.store', $order), [
            'product_id' => $product->id,
            'quantity' => $qty,
        ])->assertSessionHasNoErrors();
    }

    public function test_order_numbers_are_sequential_per_tenant(): void
    {
        $first = $this->draftFor(null);
        $second = $this->draftFor(null);

        $this->assertSame('SO-1001', $first->number);
        $this->assertSame('SO-1002', $second->number);
    }

    public function test_account_order_within_limit_is_confirmed_with_tax(): void
    {
        $org = Organization::factory()->withCredit(100_000)->create();
        $product = Product::factory()->create(['price_cents' => 10_000]);

        $order = $this->draftFor($org);
        $this->addLine($order, $product, 2);

        $this->post(route('orders.confirm', $order), ['settlement' => 'account'])->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertSame(20_000, $order->subtotal_cents);
        $this->assertSame(2_600, $order->tax_cents);
        $this->assertSame(22_600, $order->total_cents);
    }

    public function test_account_order_over_limit_needs_payment_or_override(): void
    {
        $org = Organization::factory()->withCredit(10_000)->create();
        $product = Product::factory()->create(['price_cents' => 10_000]);

        $order = $this->draftFor($org);
        $this->addLine($order, $product, 1); // 113.00 with tax > 100.00 limit

        $this->post(route('orders.confirm', $order), ['settlement' => 'account'])
            ->assertSessionHasErrors('override_reason');
        $this->assertTrue($order->refresh()->isDraft());

        // Prepaid is always allowed.
        $this->post(route('orders.confirm', $order), ['settlement' => 'prepaid'])->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::Confirmed, $order->refresh()->status);
    }

    public function test_owner_can_override_the_credit_limit_with_a_reason(): void
    {
        $org = Organization::factory()->withCredit(10_000)->create();
        $product = Product::factory()->create(['price_cents' => 10_000]);

        $order = $this->draftFor($org);
        $this->addLine($order, $product, 1);

        $this->post(route('orders.confirm', $order), [
            'settlement' => 'account',
            'override_reason' => 'Cheque on the way, per Mike',
        ])->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertSame($this->user->id, $order->credit_override_by);
    }

    public function test_sales_cannot_override_the_credit_limit(): void
    {
        $staff = User::factory()->create(['tenant_id' => $this->user->tenant_id, 'role' => UserRole::Sales]);
        $staff->setYards([$this->yard->id]);
        $this->actingAs($staff);

        $org = Organization::factory()->withCredit(10_000)->create();
        $product = Product::factory()->create(['price_cents' => 10_000]);
        $order = $this->draftFor($org);
        $this->addLine($order, $product, 1);

        $this->post(route('orders.confirm', $order), [
            'settlement' => 'account',
            'override_reason' => 'Please',
        ])->assertSessionHasErrors('override_reason');

        $this->assertTrue($order->refresh()->isDraft());
    }

    public function test_existing_unpaid_account_orders_use_up_credit(): void
    {
        $org = Organization::factory()->withCredit(20_000)->create();
        $product = Product::factory()->create(['price_cents' => 10_000]);

        $first = $this->draftFor($org);
        $this->addLine($first, $product, 1);
        $this->post(route('orders.confirm', $first), ['settlement' => 'account'])->assertSessionHasNoErrors();

        $second = $this->draftFor($org);
        $this->addLine($second, $product, 1);
        $this->post(route('orders.confirm', $second), ['settlement' => 'account'])->assertSessionHasErrors();
    }

    public function test_walk_ins_cannot_go_on_account(): void
    {
        $product = Product::factory()->create(['price_cents' => 1_000]);
        $order = $this->draftFor(null);
        $this->addLine($order, $product, 1);

        $this->post(route('orders.confirm', $order), [
            'settlement' => 'account',
            'override_reason' => 'Trying anyway',
        ])->assertSessionHasErrors('order');
    }

    public function test_prepaid_orders_must_be_paid_before_release_and_release_moves_stock(): void
    {
        $product = Product::factory()->create(['price_cents' => 1_000]);
        app(InventoryLedger::class)->record($product, $this->yard, 50, StockMovementType::Receipt);

        $order = $this->draftFor(null);
        $this->addLine($order, $product, 5);
        $this->post(route('orders.confirm', $order), ['settlement' => 'prepaid'])->assertSessionHasNoErrors();

        $this->post(route('orders.fulfill', $order))->assertSessionHasErrors('order');

        $this->post(route('orders.payments.store', $order), [
            'tender' => 'cash',
            'amount' => '56.50',
        ])->assertSessionHasNoErrors();

        $this->assertSame(PaymentStatus::Paid, $order->refresh()->payment_status);

        $this->post(route('orders.fulfill', $order))->assertSessionHasNoErrors();

        $this->assertSame(OrderStatus::Fulfilled, $order->refresh()->status);
        $this->assertSame('45.000', app(InventoryLedger::class)->onHand($product, $this->yard));
    }

    public function test_payment_link_completes_the_payment(): void
    {
        $product = Product::factory()->create(['price_cents' => 1_000]);
        $order = $this->draftFor(null);
        $this->addLine($order, $product, 1);
        $this->post(route('orders.confirm', $order), ['settlement' => 'prepaid']);

        $this->post(route('orders.payment-link', $order))->assertSessionHasNoErrors();
        $payment = $order->payments()->firstOrFail();

        $this->get(route('dev.fake-checkout', ['session' => $payment->stripe_checkout_session_id]))
            ->assertRedirect(route('orders.show', $order));

        $this->assertSame(PaymentStatus::Paid, $order->refresh()->payment_status);
        $this->assertSame(1_130, $order->amount_paid_cents);
    }

    public function test_rentals_are_booked_in_the_rental_module_not_as_orders(): void
    {
        $this->post(route('orders.store'), [
            'type' => 'rental',
            'location_id' => $this->yard->id,
            'fulfillment' => 'pickup',
        ])->assertSessionHasErrors('type');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_sale_and_rental_items_do_not_mix(): void
    {
        $saw = Product::factory()->rental()->create();
        $order = $this->draftFor(null);

        $this->post(route('orders.lines.store', $order), ['product_id' => $saw->id, 'quantity' => 1])
            ->assertSessionHasErrors('product_id');
    }

    public function test_order_pages_render(): void
    {
        $order = $this->draftFor(null);

        $this->get(route('dashboard'))->assertOk();
        $this->get(route('orders.index'))->assertOk();
        $this->get(route('orders.create'))->assertOk();
        $this->get(route('orders.show', $order))->assertOk();
        $this->get(route('products.index'))->assertOk();
        $this->get(route('organizations.index'))->assertOk();
        $this->get(route('customers.index'))->assertOk();
        $this->get(route('inventory.index'))->assertOk();
        $this->get(route('price-lists.index'))->assertOk();
        $this->get(route('locations.index'))->assertOk();
        $this->get(route('business.edit'))->assertOk();
    }
}
