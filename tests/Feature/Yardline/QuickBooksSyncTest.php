<?php

namespace Tests\Feature\Yardline;

use App\Accounting\QuickBooks\PullPayments;
use App\Accounting\QuickBooks\QuickBooks;
use App\Enums\IntegrationProvider;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTerms;
use App\Enums\StockMovementType;
use App\Enums\Unit;
use App\Inventory\InventoryLedger;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Integration;
use App\Models\Location;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\QuickBooksSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeQuickBooks;
use Tests\TestCase;

/**
 * What Yardline sends to QuickBooks, record by record, per the sync map.
 * Flat 13% tax, an Ontario yard.
 */
class QuickBooksSyncTest extends TestCase
{
    use RefreshDatabase;

    private FakeQuickBooks $qbo;

    private Integration $integration;

    private Location $yard;

    private Organization $org;

    private Product $pavers;

    private Product $mortar;

    protected function setUp(): void
    {
        parent::setUp();

        config(['yardline.tax.driver' => 'flat', 'yardline.tax.flat_rate' => 0.13, 'yardline.payments.driver' => 'fake']);
        $this->qbo = (new FakeQuickBooks)->install();

        $this->signInToNewTenant();
        $this->yard = Location::query()->firstOrFail();
        $this->yard->update(['name' => 'Waterdown', 'address_line1' => '100 Dundas St E', 'city' => 'Waterdown', 'region' => 'ON', 'postal_code' => 'L9H 7L6']);

        $this->integration = Integration::query()->create([
            'provider' => IntegrationProvider::QuickBooks,
            'external_account' => '9130',
            'credentials' => ['access_token' => 'access', 'refresh_token' => 'refresh', 'access_expires_at' => now()->addHour()->toIso8601String()],
            'settings' => [
                'start_date' => now()->subDay()->toDateString(),
                'accounts' => ['sales' => '1', 'delivery' => '2', 'rental' => '3', 'damage_waiver' => '4', 'restocking' => '5', 'adjustments' => '6', 'pallet_deposits' => '7', 'stripe_clearing' => '8', 'undeposited' => '9'],
                'tax_codes' => ['ON' => '20', 'QC' => '21', 'other' => '22', 'exempt' => '23'],
            ],
        ]);
        QuickBooks::forget();

        $this->pavers = Product::factory()->create(['name' => 'Paver 60 mm', 'sku' => 'PAV-60', 'unit' => Unit::SquareFoot, 'price_cents' => 500, 'pallet_deposit' => true, 'per_pallet' => 115]);
        $this->mortar = Product::factory()->create(['name' => 'Type S mortar', 'sku' => 'MOR-S', 'unit' => Unit::Bag, 'price_cents' => 1_000]);
        $ledger = app(InventoryLedger::class);
        $ledger->record($this->pavers, $this->yard, 1000, StockMovementType::Receipt);
        $ledger->record($this->mortar, $this->yard, 100, StockMovementType::Receipt);

        $this->org = Organization::factory()->withCredit(10_000_000, PaymentTerms::Net30)->create(['name' => 'Brantwood Landscaping', 'account_number' => 'C-10021', 'email' => 'ap@brantwood.example']);
    }

    /**
     * @param  array<string, int>  $lines
     */
    private function order(array $lines, string $settlement = 'account', ?Customer $walkIn = null): Order
    {
        $this->post(route('orders.store'), [
            'type' => 'sale',
            ...($walkIn ? ['customer_id' => $walkIn->id] : ['organization_id' => $this->org->id]),
            'location_id' => $this->yard->id,
            'fulfillment' => 'pickup',
        ]);
        $order = Order::query()->latest('id')->firstOrFail();

        foreach ($lines as $key => $quantity) {
            $this->post(route('orders.lines.store', $order), ['product_id' => $this->{$key}->id, 'quantity' => $quantity])->assertSessionHasNoErrors();
        }

        $this->post(route('orders.confirm', $order), ['settlement' => $settlement])->assertSessionHasNoErrors();

        return $order->refresh();
    }

    public function test_a_confirmed_account_order_becomes_an_invoice(): void
    {
        // 230 sq ft of pavers ships on two pallets at the default deposit.
        $order = $this->order(['pavers' => 230, 'mortar' => 10]);
        $invoice = $this->qbo->last('Invoice');

        $this->assertSame($order->invoice?->number, $invoice['DocNumber']);
        $this->assertSame($order->invoice?->due_on->toDateString(), $invoice['DueDate']);
        $this->assertSame('TaxExcluded', $invoice['GlobalTaxCalculation']);
        $this->assertSame('ap@brantwood.example', $invoice['BillEmail']['Address']);
        $this->assertStringContainsString($order->number, $invoice['PrivateNote']);

        // Customer: the organization, with its account number in the notes.
        $customer = $this->qbo->records['Customer'][$invoice['CustomerRef']['value']];
        $this->assertSame('Brantwood Landscaping', $customer['DisplayName']);
        $this->assertStringContainsString('C-10021', $customer['Notes']);

        // Items: non-inventory, on the sales account.
        $items = collect($this->qbo->records['Item']);
        $paver = $items->firstWhere('Name', 'Paver 60 mm');
        $this->assertSame('NonInventory', $paver['Type']);
        $this->assertSame('1', $paver['IncomeAccountRef']['value']);

        // Lines: two products at HST ON, the deposit exempt and on the liability account.
        $lines = collect($invoice['Line']);
        $this->assertSame([1150.0, 100.0], $lines->take(2)->pluck('Amount')->all());
        $this->assertSame('20', $lines[0]['SalesItemLineDetail']['TaxCodeRef']['value']);
        $deposit = $lines->first(fn ($l) => str_contains((string) $l['Description'], 'pallets'));
        $this->assertNotNull($deposit);
        $this->assertSame('23', $deposit['SalesItemLineDetail']['TaxCodeRef']['value']);
        $this->assertSame('7', $this->qbo->records['Item'][$deposit['SalesItemLineDetail']['ItemRef']['value']]['IncomeAccountRef']['value']);

        // Tax as Yardline charged it, and the total matches to the cent.
        $this->assertSame(round($order->tax_cents / 100, 2), (float) $invoice['TxnTaxDetail']['TotalTax']);
        $this->assertSame('30', $invoice['TxnTaxDetail']['TaxLine'][0]['TaxLineDetail']['TaxRateRef']['value']);
        $this->assertSame($order->amountDueCents(), (int) round($invoice['TotalAmt'] * 100));
        $this->assertSame('synced', QuickBooksSync::for($order->invoice)?->status);
    }

    public function test_payments_go_to_the_holding_account_for_their_method(): void
    {
        $order = $this->order(['mortar' => 10]);
        $this->post(route('orders.payments.store', $order), ['tender' => 'cheque', 'amount' => '50.00', 'reference' => '4471'])->assertSessionHasNoErrors();
        $this->post(route('orders.payments.store', $order), ['tender' => 'card_terminal', 'amount' => '63.00'])->assertSessionHasNoErrors();

        [$cheque, $card] = $this->qbo->created('Payment');
        $invoiceId = (string) QuickBooksSync::for($order->invoice)?->qbo_id;

        $this->assertSame('9', $cheque['DepositToAccountRef']['value']);
        $this->assertSame('4471', $cheque['PaymentRefNum']);
        $this->assertSame($invoiceId, $cheque['Line'][0]['LinkedTxn'][0]['TxnId']);
        $this->assertSame('8', $card['DepositToAccountRef']['value']);
        $this->assertSame(63.0, (float) $card['TotalAmt']);
    }

    public function test_walk_ins_go_to_cash_sales_with_their_name_in_the_memo(): void
    {
        $dana = Customer::factory()->create(['first_name' => 'Dana', 'last_name' => 'Kowalski']);
        $order = $this->order(['mortar' => 2], 'prepaid', $dana);
        $this->post(route('orders.payments.store', $order), ['tender' => 'cash', 'amount' => '22.60'])->assertSessionHasNoErrors();

        $invoice = $this->qbo->last('Invoice');
        $this->assertSame('Cash sales', $this->qbo->records['Customer'][$invoice['CustomerRef']['value']]['DisplayName']);
        $this->assertStringContainsString('Dana Kowalski', $invoice['PrivateNote']);
        $this->assertCount(1, $this->qbo->records['Customer']);
        $this->assertCount(1, $this->qbo->created('Payment'));

        // A second walk-in reuses the same customer.
        $this->order(['mortar' => 1], 'prepaid', Customer::factory()->create());
        $this->assertCount(1, $this->qbo->records['Customer']);
    }

    public function test_a_cancelled_order_voids_its_invoice(): void
    {
        $order = $this->order(['mortar' => 1]);
        $this->post(route('orders.cancel', $order))->assertSessionHasNoErrors();

        $this->assertNull(QuickBooksSync::for($order)?->message);
        $this->assertTrue($this->qbo->last('Invoice')['voided'] ?? false);
        $this->assertTrue(QuickBooksSync::for($order->invoice)?->meta['voided'] ?? false);
    }

    public function test_nothing_dated_before_the_start_date_is_sent(): void
    {
        $this->integration->forceFill(['settings' => [...$this->integration->settings ?? [], 'start_date' => now()->addDay()->toDateString()]])->save();
        QuickBooks::forget();

        $order = $this->order(['mortar' => 1]);
        $this->post(route('orders.payments.store', $order), ['tender' => 'cash', 'amount' => '11.30']);

        $this->assertSame([], $this->qbo->created('Invoice'));
        $this->assertSame([], $this->qbo->created('Payment'));
        $this->assertSame('skipped', QuickBooksSync::for($order->invoice)?->status);
    }

    public function test_records_wait_until_accounts_and_tax_codes_are_chosen(): void
    {
        $this->integration->forceFill(['settings' => [...$this->integration->settings ?? [], 'tax_codes' => []]])->save();
        QuickBooks::forget();

        $order = $this->order(['mortar' => 1]);
        $this->assertSame([], $this->qbo->created('Invoice'));
        $this->assertSame('waiting', QuickBooksSync::for($order->invoice)?->status);

        $this->put(route('quickbooks.update'), [
            'start_date' => now()->subDay()->toDateString(),
            'accounts' => $this->integration->settings['accounts'],
            'tax_codes' => ['ON' => '20', 'QC' => '21', 'other' => '22', 'exempt' => '23'],
        ])->assertSessionHasNoErrors();

        $this->assertCount(1, $this->qbo->created('Invoice'));
        $this->assertSame('synced', QuickBooksSync::for($order->invoice)?->status);
    }

    public function test_returns_credits_and_refunds(): void
    {
        $order = $this->order(['mortar' => 20]);
        $this->post(route('orders.fulfill', $order))->assertSessionHasNoErrors();
        $line = $order->lines()->firstOrFail();
        $this->post(route('orders.returns.store', $order), [
            'lines' => [['order_line_id' => $line->id, 'quantity' => 10, 'restock' => true]],
            'restocking_percent' => 15,
        ])->assertRedirect();
        $credit = CreditNote::query()->firstOrFail();

        // A credit memo: $100 of mortar back, less a $15 restocking fee, with its tax.
        $memo = $this->qbo->last('CreditMemo');
        $this->assertSame($credit->number, $memo['DocNumber']);
        $this->assertSame([100.0, -15.0], array_column($memo['Line'], 'Amount'));
        $this->assertSame('5', $this->qbo->records['Item'][$memo['Line'][1]['SalesItemLineDetail']['ItemRef']['value']]['IncomeAccountRef']['value']);
        $this->assertSame(96.05, (float) $memo['TotalAmt']);

        // Spent on the next invoice: a $0 payment linking both.
        $next = $this->order(['mortar' => 5]);
        $this->post(route('credit-notes.apply', $credit), ['order_id' => $next->id])->assertSessionHasNoErrors();
        $link = $this->qbo->last('Payment');
        $this->assertSame(0.0, (float) $link['TotalAmt']);
        $this->assertSame(['Invoice', 'CreditMemo'], [$link['Line'][0]['LinkedTxn'][0]['TxnType'], $link['Line'][1]['LinkedTxn'][0]['TxnType']]);

        // The rest refunded by cheque: a journal entry, linked to the memo.
        $this->post(route('credit-notes.refund', $credit), ['tender' => 'cheque'])->assertSessionHasNoErrors();
        $entry = $this->qbo->last('JournalEntry');
        $this->assertSame(39.55, (float) $entry['Line'][0]['Amount']);
        $this->assertSame('10', $entry['Line'][0]['JournalEntryLineDetail']['AccountRef']['value']);
        $this->assertSame('9', $entry['Line'][1]['JournalEntryLineDetail']['AccountRef']['value']);
        $this->assertSame('JournalEntry', $this->qbo->last('Payment')['Line'][0]['LinkedTxn'][0]['TxnType']);

        // Saving the credit again sends nothing twice.
        $before = count($this->qbo->writes);
        $credit->refresh()->save();
        $this->assertCount($before, $this->qbo->writes);
    }

    public function test_pallets_returned_credit_the_deposit_account(): void
    {
        $order = $this->order(['pavers' => 230]);
        $this->post(route('orders.fulfill', $order))->assertSessionHasNoErrors();
        $this->post(route('pallet-returns.store'), ['organization_id' => $this->org->id, 'count' => 2, 'location_id' => $this->yard->id])->assertSessionHasNoErrors();

        $this->assertSame([], QuickBooksSync::query()->where('syncable_type', 'credit_note')->whereNotNull('message')->pluck('message')->all());
        $memo = $this->qbo->last('CreditMemo');
        $this->assertCount(1, $memo['Line']);
        $this->assertSame('23', $memo['Line'][0]['SalesItemLineDetail']['TaxCodeRef']['value']);
        $this->assertSame('7', $this->qbo->records['Item'][$memo['Line'][0]['SalesItemLineDetail']['ItemRef']['value']]['IncomeAccountRef']['value']);
    }

    public function test_payments_entered_in_quickbooks_come_back_once(): void
    {
        $order = $this->order(['mortar' => 10]);
        $invoiceId = (string) QuickBooksSync::for($order->invoice)?->qbo_id;

        $this->qbo->cdcPayments = [[
            'Id' => '9001',
            'TxnDate' => now()->toDateString(),
            'PaymentRefNum' => '5521',
            'PaymentMethodRef' => ['value' => '2', 'name' => 'Cheque'],
            'TotalAmt' => 113.00,
            'Line' => [['Amount' => 113.00, 'LinkedTxn' => [['TxnId' => $invoiceId, 'TxnType' => 'Invoice']]]],
        ]];

        $pull = app(PullPayments::class);
        $this->assertSame(1, $pull->handle($this->integration));
        $this->assertSame(0, $pull->handle($this->integration->refresh()));

        $order->refresh();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame('QuickBooks 5521', $order->payments()->value('reference'));
        // Not echoed back to QuickBooks.
        $this->assertSame([], $this->qbo->created('Payment'));
    }

    public function test_our_own_payments_are_not_pulled_back(): void
    {
        $order = $this->order(['mortar' => 10]);
        $this->post(route('orders.payments.store', $order), ['tender' => 'cash', 'amount' => '113.00']);
        $this->qbo->cdcPayments = array_values($this->qbo->records['Payment']);

        $this->assertSame(0, app(PullPayments::class)->handle($this->integration));
        $this->assertSame(1, $order->payments()->count());
    }

    public function test_changes_to_a_sent_customer_or_item_follow(): void
    {
        $unsent = Organization::factory()->create();
        $unsent->update(['name' => 'Renamed before any sale']);
        $this->assertSame([], $this->qbo->created('Customer'));

        $this->order(['mortar' => 1]);
        $this->org->update(['email' => 'billing@brantwood.example']);
        $this->mortar->update(['price_cents' => 1_100]);

        $customer = collect($this->qbo->records['Customer'])->firstWhere('DisplayName', 'Brantwood Landscaping');
        $this->assertSame('billing@brantwood.example', $customer['PrimaryEmailAddr']['Address']);
        $this->assertSame(11.0, (float) collect($this->qbo->records['Item'])->firstWhere('Name', 'Type S mortar')['UnitPrice']);
    }

    public function test_an_existing_quickbooks_customer_with_the_same_name_is_reused(): void
    {
        $this->qbo->records['Customer']['50'] = ['Id' => '50', 'DisplayName' => 'Placeholder'];
        $this->org->update(['name' => 'Placeholder']);

        $this->order(['mortar' => 1]);

        $this->assertSame('Placeholder', $this->qbo->records['Customer']['50']['DisplayName']);
        $this->assertSame('50', $this->qbo->last('Invoice')['CustomerRef']['value']);
    }

    public function test_each_yard_is_a_location_when_there_are_several(): void
    {
        Location::factory()->create(['name' => 'Stoney Creek']);
        $this->order(['mortar' => 1]);

        $invoice = $this->qbo->last('Invoice');
        $this->assertSame('Waterdown', $this->qbo->records['Department'][$invoice['DepartmentRef']['value']]['Name']);
    }
}
