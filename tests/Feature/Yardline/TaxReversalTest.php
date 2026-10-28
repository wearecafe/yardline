<?php

namespace Tests\Feature\YardRunner;

use App\Enums\PaymentTerms;
use App\Enums\StockMovementType;
use App\Enums\Unit;
use App\Inventory\InventoryLedger;
use App\Models\CreditNote;
use App\Models\Location;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Tax\FlatRateTaxCalculator;
use App\Tax\ReverseSalesTax;
use App\Tax\TaxCalculator;
use App\Tax\TaxRequest;
use App\Tax\TaxResult;
use App\Tax\TaxReversalLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stripe Tax reversals: the whole sale when an order is cancelled, the
 * credited part of each line when a return is credited. Flat-rate tax has
 * nothing to reverse.
 */
class TaxReversalTest extends TestCase
{
    use RefreshDatabase;

    private Location $yard;

    private Product $mortar;

    private Product $sand;

    private Organization $org;

    /** @var list<array{transaction: string, reference: string, lines: list<TaxReversalLine>|null}> */
    public array $reversals = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['yardline.tax.driver' => 'flat', 'yardline.tax.flat_rate' => 0.13, 'yardline.payments.driver' => 'fake']);

        $this->signInToNewTenant();
        $this->yard = Location::query()->firstOrFail();
        $this->mortar = Product::factory()->create(['name' => 'Type S mortar', 'unit' => Unit::Bag, 'price_cents' => 1_000]);
        $this->sand = Product::factory()->create(['name' => 'Polymeric sand', 'unit' => Unit::Bag, 'price_cents' => 3_000]);
        $ledger = app(InventoryLedger::class);
        $ledger->record($this->mortar, $this->yard, 100, StockMovementType::Receipt);
        $ledger->record($this->sand, $this->yard, 100, StockMovementType::Receipt);
        $this->org = Organization::factory()->withCredit(10_000_000, PaymentTerms::Net30)->create();
    }

    /** Stripe Tax as far as YardRunner can tell: flat 13%, with references. */
    private function useStripeTax(): void
    {
        $this->app->instance(TaxCalculator::class, new class($this) implements TaxCalculator
        {
            private FlatRateTaxCalculator $flat;

            public function __construct(private TaxReversalTest $test)
            {
                $this->flat = new FlatRateTaxCalculator(0.13);
            }

            public function calculate(TaxRequest $request): TaxResult
            {
                $result = $this->flat->calculate($request);

                return new TaxResult($result->lineTaxCents, $result->totalCents, 'taxcalc_'.count($request->lines));
            }

            public function commit(string $calculationReference, string $saleReference): ?string
            {
                return "tax_txn_{$saleReference}";
            }

            public function reverse(string $transactionReference, string $reversalReference, ?array $lines = null): ?string
            {
                $this->test->reversals[] = ['transaction' => $transactionReference, 'reference' => $reversalReference, 'lines' => $lines];

                return 'tax_rev_'.count($this->test->reversals);
            }
        });
    }

    /**
     * @param  array<string, int>  $lines
     */
    private function accountOrder(array $lines): Order
    {
        $this->post(route('orders.store'), ['type' => 'sale', 'organization_id' => $this->org->id, 'location_id' => $this->yard->id, 'fulfillment' => 'pickup']);
        $order = Order::query()->latest('id')->firstOrFail();

        foreach ($lines as $key => $quantity) {
            $this->post(route('orders.lines.store', $order), ['product_id' => $this->{$key}->id, 'quantity' => $quantity])->assertSessionHasNoErrors();
        }

        $this->post(route('orders.confirm', $order), ['settlement' => 'account'])->assertSessionHasNoErrors();

        return $order->refresh();
    }

    public function test_cancelling_an_order_reverses_all_its_tax_once(): void
    {
        $this->useStripeTax();
        $order = $this->accountOrder(['mortar' => 10]);
        $this->assertSame("tax_txn_{$order->number}", $order->tax_transaction_ref);

        $this->post(route('orders.cancel', $order))->assertSessionHasNoErrors();

        $this->assertSame([['transaction' => "tax_txn_{$order->number}", 'reference' => "{$order->number}-cancelled", 'lines' => null]], $this->reversals);
        $this->assertSame('tax_rev_1', $order->refresh()->tax_reversal_ref);

        // Run again (a retried job), nothing more is reversed.
        app(ReverseSalesTax::class)->order($order);
        $this->assertCount(1, $this->reversals);
    }

    public function test_a_return_reverses_the_credited_part_of_each_line(): void
    {
        $this->useStripeTax();
        $order = $this->accountOrder(['mortar' => 20, 'sand' => 4]);
        $this->post(route('orders.fulfill', $order))->assertSessionHasNoErrors();
        $mortar = $order->lines()->where('product_id', $this->mortar->id)->firstOrFail();
        $sand = $order->lines()->where('product_id', $this->sand->id)->firstOrFail();

        // 10 bags of mortar back on the shelf less 15%; 1 bag of sand damaged.
        $this->post(route('orders.returns.store', $order), [
            'lines' => [
                ['order_line_id' => $mortar->id, 'quantity' => 10, 'restock' => true],
                ['order_line_id' => $sand->id, 'quantity' => 1, 'restock' => false],
            ],
            'restocking_percent' => 15,
        ])->assertRedirect();
        $credit = CreditNote::query()->sole();

        $this->assertCount(1, $this->reversals);
        $reversal = $this->reversals[0];
        $this->assertSame("tax_txn_{$order->number}", $reversal['transaction']);
        $this->assertSame($credit->number, $reversal['reference']);

        $lines = collect($reversal['lines'] ?? [])->keyBy('lineReference');
        // Mortar: $100 less the $15 fee, with $13 tax less the fee's $1.95 share.
        $this->assertSame([8_500, 1_105], [$lines["line_{$mortar->id}"]->amountCents, $lines["line_{$mortar->id}"]->taxCents]);
        // Sand: damaged, so no restocking fee.
        $this->assertSame([3_000, 390], [$lines["line_{$sand->id}"]->amountCents, $lines["line_{$sand->id}"]->taxCents]);
        $this->assertSame($credit->tax_cents, $lines->sum('taxCents'));
        $this->assertSame('tax_rev_1', $credit->refresh()->tax_reversal_ref);
    }

    public function test_a_credit_with_no_tax_reverses_nothing(): void
    {
        $this->useStripeTax();
        $this->org->update(['tax_exempt' => true]);
        $order = $this->accountOrder(['mortar' => 5]);
        $this->post(route('orders.fulfill', $order))->assertSessionHasNoErrors();
        $line = $order->lines()->firstOrFail();

        $this->post(route('orders.returns.store', $order), ['lines' => [['order_line_id' => $line->id, 'quantity' => 1]]])->assertRedirect();

        $this->assertSame([], $this->reversals);
    }

    public function test_flat_rate_tax_has_nothing_to_reverse(): void
    {
        $order = $this->accountOrder(['mortar' => 10]);
        $this->assertNull($order->tax_transaction_ref);

        $this->post(route('orders.cancel', $order))->assertSessionHasNoErrors();

        $this->assertNull($order->refresh()->tax_reversal_ref);
        $this->assertNull((new FlatRateTaxCalculator(0.13))->reverse('anything', 'ref'));
    }
}
