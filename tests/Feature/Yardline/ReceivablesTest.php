<?php

namespace Tests\Feature\Yardline;

use App\Enums\OrderStatus;
use App\Enums\PaymentTerms;
use App\Enums\StockMovementType;
use App\Enums\Unit;
use App\Inventory\InventoryLedger;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PalletMovement;
use App\Models\Product;
use App\Models\User;
use App\Notifications\StatementMessage;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Invoices and aging, statements, returns and credit notes, pallet
 * deposits, and the counter. Flat 13% tax.
 */
class ReceivablesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Location $yard;

    private Product $pavers;

    private Product $mortar;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        config(['yardline.tax.driver' => 'flat', 'yardline.tax.flat_rate' => 0.13, 'yardline.payments.driver' => 'fake']);

        $this->user = $this->signInToNewTenant();
        $this->yard = Location::query()->firstOrFail();
        $this->yard->update(['address_line1' => '100 Dundas St E', 'city' => 'Waterdown', 'region' => 'ON', 'postal_code' => 'L9H 7L6']);

        $this->pavers = Product::factory()->create(['name' => 'Paver 60 mm', 'unit' => Unit::SquareFoot, 'price_cents' => 500, 'pallet_deposit' => true, 'per_pallet' => 115]);
        $this->mortar = Product::factory()->create(['name' => 'Type S mortar', 'unit' => Unit::Bag, 'price_cents' => 1_000]);

        $ledger = app(InventoryLedger::class);
        $ledger->record($this->pavers, $this->yard, 1000, StockMovementType::Receipt);
        $ledger->record($this->mortar, $this->yard, 100, StockMovementType::Receipt);

        $this->org = Organization::factory()->withCredit(10_000_000, PaymentTerms::Net30)->create(['email' => 'ap@example.com']);
    }

    /**
     * @param  array<string, int>  $lines
     */
    private function order(array $lines, string $settlement = 'account', ?Organization $org = null): Order
    {
        $this->post(route('orders.store'), ['type' => 'sale', 'organization_id' => ($org ?? $this->org)->id, 'location_id' => $this->yard->id, 'fulfillment' => 'pickup']);
        $order = Order::query()->latest('id')->firstOrFail();

        foreach ($lines as $key => $quantity) {
            $this->post(route('orders.lines.store', $order), ['product_id' => $this->{$key}->id, 'quantity' => $quantity])->assertSessionHasNoErrors();
        }

        $this->post(route('orders.confirm', $order), ['settlement' => $settlement])->assertSessionHasNoErrors();

        return $order->refresh();
    }

    public function test_orders_are_invoiced_by_terms_and_age(): void
    {
        $order = $this->order(['mortar' => 10]);
        $invoice = $order->invoice;

        $this->assertInstanceOf(Invoice::class, $invoice);
        $this->assertStringStartsWith('INV-', $invoice->number);
        // Net 30 from the yard's today, not UTC's (they differ after 8 pm in Toronto).
        $this->assertSame(now(CurrentTenant::get()?->timezone ?? 'America/Toronto')->addDays(30)->toDateString(), $invoice->due_on->toDateString());
        $this->assertSame(11_300, $invoice->balanceCents());

        $this->travel(45)->days();
        $this->get(route('receivables.index'))->assertOk()->assertInertia(fn ($page) => $page
            ->where('rows.0.buckets.d30', 11_300)
            ->where('stats.overdue_cents', 11_300));

        $this->post(route('orders.payments.store', $order), ['tender' => 'cheque', 'amount' => '113.00'])->assertSessionHasNoErrors();
        $this->assertSame('paid', $invoice->refresh()->status);

        $other = $this->order(['mortar' => 1]);
        $this->post(route('orders.cancel', $other))->assertSessionHasNoErrors();
        $this->assertSame('void', $other->invoice?->refresh()->status);
    }

    public function test_statements_print_download_and_email(): void
    {
        Notification::fake();
        $this->order(['mortar' => 10]);

        $this->get(route('organizations.statement', $this->org))->assertOk();
        $this->get(route('organizations.statement.pdf', $this->org))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->post(route('organizations.statement.email', $this->org))->assertSessionHasNoErrors();

        Notification::assertSentOnDemand(StatementMessage::class);
    }

    public function test_returns_restock_less_a_fee_and_credit_the_account(): void
    {
        $order = $this->order(['mortar' => 20]);
        $this->post(route('orders.fulfill', $order))->assertSessionHasNoErrors();
        $line = $order->lines()->firstOrFail();

        $this->get(route('orders.returns.create', $order))->assertOk();
        $this->post(route('orders.returns.store', $order), ['lines' => [['order_line_id' => $line->id, 'quantity' => 21]]])->assertSessionHasErrors();

        $this->post(route('orders.returns.store', $order), [
            'lines' => [['order_line_id' => $line->id, 'quantity' => 10, 'restock' => true]],
            'reason' => 'Over-ordered',
            'restocking_percent' => 15,
        ])->assertRedirect();

        $credit = CreditNote::query()->firstOrFail();
        // $100 back, less a $15 fee, with 13% tax on what's credited.
        $this->assertSame(10_000, $credit->subtotal_cents);
        $this->assertSame(1_500, $credit->restocking_fee_cents);
        $this->assertSame(1_105, $credit->tax_cents);
        $this->assertSame(9_605, $credit->total_cents);
        $this->assertSame('90.000', app(InventoryLedger::class)->onHand($this->mortar, $this->yard));
        $this->get(route('credit-notes.show', $credit))->assertOk();

        // Spent against another invoice on the account.
        $next = $this->order(['mortar' => 5]);
        $this->post(route('credit-notes.apply', $credit), ['order_id' => $next->id])->assertSessionHasNoErrors();
        $this->assertSame(5_650, $credit->refresh()->applied_cents);
        $this->assertSame(0, $next->refresh()->balanceCents());

        // The rest refunded.
        $this->post(route('credit-notes.refund', $credit), ['tender' => 'cheque'])->assertSessionHasNoErrors();
        $this->assertSame('used', $credit->refresh()->status);
        $this->assertSame(3_955, $credit->refunded_cents);
    }

    public function test_pallet_deposits_go_out_and_come_back(): void
    {
        $order = $this->order(['pavers' => 230]);

        // 230 sq ft at 115 a pallet: two pallets at $25.
        $this->assertSame(2, $order->lines()->value('pallets'));
        $this->assertSame(5_000, $order->deposit_cents);

        $this->post(route('orders.fulfill', $order))->assertSessionHasNoErrors();
        $this->assertSame(2, (int) PalletMovement::query()->sum('quantity'));

        $this->post(route('pallet-returns.store'), ['organization_id' => $this->org->id, 'count' => 3, 'location_id' => $this->yard->id])->assertSessionHasErrors('count');
        $this->post(route('pallet-returns.store'), ['organization_id' => $this->org->id, 'count' => 2, 'location_id' => $this->yard->id])->assertRedirect();

        $credit = CreditNote::query()->where('kind', 'pallets')->firstOrFail();
        $this->assertSame(5_000, $credit->total_cents);
        $this->assertSame(0, (int) PalletMovement::query()->sum('quantity'));

        // A yard can turn deposits off.
        $this->yard->update(['pallet_deposits' => false]);
        $this->assertSame(0, $this->order(['pavers' => 230])->deposit_cents);
    }

    public function test_the_counter_rings_up_pays_and_hands_over(): void
    {
        $this->get(route('counter.show'))->assertOk();

        $this->post(route('counter.store'), [
            'location_id' => $this->yard->id,
            'lines' => [['product_id' => $this->mortar->id, 'quantity' => 3]],
            'settlement' => 'prepaid',
            'tender' => 'cash',
            'tendered' => '40.00',
        ])->assertRedirect();

        $order = Order::query()->latest('id')->firstOrFail();
        $this->assertSame('counter', $order->source);
        $this->assertSame(OrderStatus::Fulfilled, $order->status);
        $this->assertSame(0, $order->balanceCents());
        $this->assertSame('97.000', app(InventoryLedger::class)->onHand($this->mortar, $this->yard));
        $this->get(route('counter.receipt', ['order' => $order, 'change' => 610]))->assertOk();

        // On account for a contractor.
        $this->post(route('counter.store'), [
            'location_id' => $this->yard->id,
            'organization_id' => $this->org->id,
            'lines' => [['product_id' => $this->mortar->id, 'quantity' => 2]],
            'settlement' => 'account',
            'po_number' => 'PO-9',
        ])->assertRedirect();
        $this->assertSame(2_260, Order::query()->latest('id')->firstOrFail()->balanceCents());

        $this->getJson(route('counter.prices', ['organization_id' => $this->org->id, 'lines' => [['product_id' => $this->mortar->id, 'quantity' => 1]]]))->assertOk();
        $this->get(route('returns.index'))->assertOk();
        $this->get(route('invoices.show', Order::query()->latest('id')->firstOrFail()->invoice))->assertOk();
        $this->get(route('organizations.show', $this->org))->assertOk();
        $this->get(route('delivery-settings.edit'))->assertOk();
        $this->put(route('returns-settings.update'), ['restocking_percent' => 20, 'pallet_deposits' => true, 'pallet_deposit' => '30.00', 'return_window_days' => 30, 'yards' => [$this->yard->id => true]])->assertSessionHasNoErrors();
    }
}
