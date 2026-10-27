<?php

namespace Tests\Feature\Yardline;

use App\Enums\PaymentTerms;
use App\Enums\StockMovementType;
use App\Enums\Unit;
use App\Inventory\InventoryLedger;
use App\Models\CreditNote;
use App\Models\Location;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Receivables\ReturnsSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The pallet usage fee: a non-refundable charge per pallet, set alongside
 * the pallet deposit, taxed like the product, never credited when the
 * empty pallet comes back and only credited when unused product comes back
 * on full pallets. Flat 13% tax.
 */
class PalletUsageFeeTest extends TestCase
{
    use RefreshDatabase;

    private Location $yard;

    private Product $pavers;

    private Product $mortar;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        config(['yardline.tax.driver' => 'flat', 'yardline.tax.flat_rate' => 0.13, 'yardline.payments.driver' => 'fake']);

        $this->signInToNewTenant();
        $this->yard = Location::query()->firstOrFail();
        $this->yard->update(['address_line1' => '100 Dundas St E', 'city' => 'Waterdown', 'region' => 'ON', 'postal_code' => 'L9H 7L6']);

        // 115 sq ft a pallet, carrying both a $25 deposit and the usage fee.
        $this->pavers = Product::factory()->create(['name' => 'Paver 60 mm', 'unit' => Unit::SquareFoot, 'price_cents' => 500, 'pallet_deposit' => true, 'pallet_usage_fee' => true, 'per_pallet' => 115]);
        $this->mortar = Product::factory()->create(['name' => 'Type S mortar', 'unit' => Unit::Bag, 'price_cents' => 1_000]);

        $ledger = app(InventoryLedger::class);
        $ledger->record($this->pavers, $this->yard, 1000, StockMovementType::Receipt);
        $ledger->record($this->mortar, $this->yard, 100, StockMovementType::Receipt);

        $this->org = Organization::factory()->withCredit(10_000_000, PaymentTerms::Net30)->create();

        $this->settings(fees: true, fee: '10.00');
    }

    private function settings(bool $fees, ?string $fee): void
    {
        $this->put(route('returns-settings.update'), [
            'restocking_percent' => 15,
            'pallet_deposits' => true,
            'pallet_deposit' => '25.00',
            'return_window_days' => 30,
            'pallet_usage_fees' => $fees,
            'pallet_usage_fee' => $fee,
        ])->assertSessionHasNoErrors();
    }

    /**
     * @param  array<string, int>  $lines
     */
    private function order(array $lines, ?Organization $org = null): Order
    {
        $this->post(route('orders.store'), ['type' => 'sale', 'organization_id' => ($org ?? $this->org)->id, 'location_id' => $this->yard->id, 'fulfillment' => 'pickup']);
        $order = Order::query()->latest('id')->firstOrFail();

        foreach ($lines as $key => $quantity) {
            $this->post(route('orders.lines.store', $order), ['product_id' => $this->{$key}->id, 'quantity' => $quantity])->assertSessionHasNoErrors();
        }

        $this->post(route('orders.confirm', $order), ['settlement' => 'account'])->assertSessionHasNoErrors();

        return $order->refresh();
    }

    public function test_the_fee_is_set_in_settings_beside_the_deposit(): void
    {
        $settings = ReturnsSettings::current();
        $this->assertTrue($settings->palletUsageFees);
        $this->assertSame(1_000, $settings->palletUsageFeeCents);
        $this->assertSame(2_500, $settings->palletDepositCents);

        // On, but with no amount.
        $this->put(route('returns-settings.update'), [
            'restocking_percent' => 15, 'pallet_deposits' => true, 'pallet_deposit' => '25.00', 'return_window_days' => 30,
            'pallet_usage_fees' => true, 'pallet_usage_fee' => '',
        ])->assertSessionHasErrors('pallet_usage_fee');
        $this->put(route('returns-settings.update'), [
            'restocking_percent' => 15, 'pallet_deposits' => true, 'pallet_deposit' => '25.00', 'return_window_days' => 30,
            'pallet_usage_fees' => true, 'pallet_usage_fee' => '0',
        ])->assertSessionHasErrors('pallet_usage_fee');

        $this->get(route('delivery-settings.edit'))->assertOk()->assertInertia(fn ($page) => $page
            ->where('returns.pallet_usage_fees', true)
            ->where('returns.pallet_usage_fee_cents', 1_000)
            ->where('yards.0.pallet_usage_fees', true));

        // A yard can turn it off for itself.
        $this->put(route('returns-settings.update'), [
            'restocking_percent' => 15, 'pallet_deposits' => true, 'pallet_deposit' => '25.00', 'return_window_days' => 30,
            'pallet_usage_fees' => true, 'pallet_usage_fee' => '10.00', 'fee_yards' => [$this->yard->id => false],
        ])->assertSessionHasNoErrors();
        $this->assertFalse($this->yard->refresh()->pallet_usage_fees);
    }

    public function test_orders_charge_and_tax_the_fee_per_pallet(): void
    {
        $order = $this->order(['pavers' => 230, 'mortar' => 2]);
        $line = $order->lines()->where('product_id', $this->pavers->id)->firstOrFail();

        // 230 sq ft at 115 a pallet: two pallets, each with a $25 deposit and a $10 fee.
        $this->assertSame(2, $line->pallets);
        $this->assertSame(2, $line->fee_pallets);
        $this->assertSame(2_000, $line->pallet_fee_cents);
        $this->assertSame(260, $line->pallet_fee_tax_cents);
        $this->assertSame(0, $order->lines()->where('product_id', $this->mortar->id)->value('fee_pallets'));

        $this->assertSame(2_000, $order->pallet_fee_cents);
        $this->assertSame(5_000, $order->deposit_cents);
        // $1,150 pavers + $20 mortar + $20 usage fees, all taxed; deposits on top, untaxed.
        $this->assertSame(119_000, $order->subtotal_cents);
        $this->assertSame(15_470, $order->tax_cents);
        $this->assertSame(134_470, $order->total_cents);
        $this->assertSame(139_470, $order->amountDueCents());

        $this->get(route('orders.show', $order))->assertOk()->assertInertia(fn ($page) => $page->where('order.pallet_fee_cents', 2_000));
        $this->get(route('invoices.show', $order->invoice))->assertOk()->assertInertia(fn ($page) => $page
            ->where('order.pallet_fee_cents', 2_000)
            ->where('order.lines.0.fee_pallets', 2));
    }

    public function test_the_fee_follows_the_product_the_yard_and_tax_exemption(): void
    {
        $this->pavers->update(['pallet_usage_fee' => false]);
        $this->assertSame(0, $this->order(['pavers' => 230])->pallet_fee_cents);

        $this->pavers->update(['pallet_usage_fee' => true]);
        $this->yard->update(['pallet_usage_fees' => false]);
        $this->assertSame(0, $this->order(['pavers' => 230])->pallet_fee_cents);

        $this->yard->update(['pallet_usage_fees' => true]);
        $this->settings(fees: false, fee: '10.00');
        $this->assertSame(0, $this->order(['pavers' => 230])->pallet_fee_cents);

        // A deposit isn't needed for the fee, and an exempt org pays no tax on it.
        $this->settings(fees: true, fee: '10.00');
        $this->pavers->update(['pallet_deposit' => false]);
        $exempt = Organization::factory()->withCredit(10_000_000, PaymentTerms::Net30)->create();
        $exempt->forceFill(['tax_exempt' => true])->save();
        $order = $this->order(['pavers' => 230], $exempt);
        $this->assertSame(2_000, $order->pallet_fee_cents);
        $this->assertSame(0, $order->deposit_cents);
        $this->assertSame(0, $order->tax_cents);
    }

    public function test_returning_empty_pallets_credits_the_deposit_but_not_the_fee(): void
    {
        $order = $this->order(['pavers' => 230]);
        $this->post(route('orders.fulfill', $order))->assertSessionHasNoErrors();

        $this->post(route('pallet-returns.store'), ['organization_id' => $this->org->id, 'count' => 2, 'location_id' => $this->yard->id])->assertRedirect();

        $credit = CreditNote::query()->where('kind', 'pallets')->firstOrFail();
        $this->assertSame(5_000, $credit->total_cents);
        $this->assertSame(0, $credit->pallet_fee_cents);
    }

    public function test_unused_product_back_on_full_pallets_credits_the_fee_with_its_tax(): void
    {
        $order = $this->order(['pavers' => 230]);
        $this->post(route('orders.fulfill', $order))->assertSessionHasNoErrors();
        $line = $order->lines()->firstOrFail();

        // One full pallet back.
        $this->post(route('orders.returns.store', $order), [
            'lines' => [['order_line_id' => $line->id, 'quantity' => 115, 'restock' => true]],
            'restocking_percent' => 0,
        ])->assertRedirect();

        $credit = CreditNote::query()->where('kind', 'return')->latest('id')->firstOrFail();
        $this->assertSame(57_500, $credit->subtotal_cents);
        $this->assertSame(1_000, $credit->pallet_fee_cents);
        // 13% on the goods and on the fee.
        $this->assertSame(7_475 + 130, $credit->tax_cents);
        $this->assertSame(57_500 + 1_000 + 7_605, $credit->total_cents);
        $this->get(route('credit-notes.show', $credit))->assertOk()->assertInertia(fn ($page) => $page->where('credit.pallet_fee_cents', 1_000));

        // A loose part-pallet back credits no fee...
        $this->post(route('orders.returns.store', $order), [
            'lines' => [['order_line_id' => $line->id, 'quantity' => 60, 'restock' => true]],
            'restocking_percent' => 0,
        ])->assertRedirect();
        $this->assertSame(0, CreditNote::query()->where('kind', 'return')->latest('id')->firstOrFail()->pallet_fee_cents);

        // ...until the rest of that pallet comes back too.
        $this->post(route('orders.returns.store', $order), [
            'lines' => [['order_line_id' => $line->id, 'quantity' => 55, 'restock' => true]],
            'restocking_percent' => 0,
        ])->assertRedirect();
        $this->assertSame(1_000, CreditNote::query()->where('kind', 'return')->latest('id')->firstOrFail()->pallet_fee_cents);
        $this->assertSame(2_000, (int) CreditNote::query()->sum('pallet_fee_cents'));
    }

    public function test_an_order_keeps_the_fee_it_was_charged(): void
    {
        $order = $this->order(['pavers' => 230]);
        $this->settings(fees: true, fee: '15.00');

        $this->assertSame(2_000, $order->refresh()->pallet_fee_cents);
        $this->assertSame(3_000, $this->order(['pavers' => 230])->pallet_fee_cents);
    }
}
