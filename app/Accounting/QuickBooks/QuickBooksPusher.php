<?php

namespace App\Accounting\QuickBooks;

use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentState;
use App\Enums\PaymentTender;
use App\Enums\RentalPaymentKind;
use App\Enums\RentalStatus;
use App\Enums\RentalTender;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Integration;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Product;
use App\Models\QuickBooksSync;
use App\Models\Rental;
use App\Models\RentalPayment;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Turns Yardline records into QuickBooks records, following the sync map:
 * customers and items on demand, invoices, payments, credit memos,
 * refunds and rental invoices. Every send is idempotent: a record with a
 * QuickBooks id is updated (or left alone), never created twice.
 */
class QuickBooksPusher
{
    /**
     * Fixed items: key => [name, account mapping key].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const SERVICE_ITEMS = [
        'delivery' => ['Delivery', 'delivery'],
        'deposits' => ['Deposits (pallets and containers)', 'pallet_deposits'],
        'restocking' => ['Restocking fee', 'restocking'],
        'adjustment' => ['Adjustment', 'adjustments'],
        'waiver' => ['Damage waiver', 'damage_waiver'],
        'fuel' => ['Fuel charge', 'rental'],
        'cleaning' => ['Cleaning fee', 'rental'],
        'damage' => ['Rental damage', 'rental'],
        'late' => ['Late return', 'rental'],
        'other' => ['Other rental charge', 'rental'],
    ];

    /** @var array<string, string|null> */
    private array $accounts;

    /** @var array<string, list<array{id: string, rate: float}>> */
    private array $rates = [];

    public function __construct(private Integration $integration, private QuickBooksClient $qbo)
    {
        $this->accounts = AccountMapping::for($integration);
    }

    public static function for(Integration $integration): self
    {
        return new self($integration, QuickBooksClient::for($integration));
    }

    public function send(Model $model): void
    {
        match (true) {
            $model instanceof Invoice => $this->invoice($model),
            $model instanceof Payment => $this->payment($model),
            $model instanceof CreditNote => $this->creditNote($model),
            $model instanceof Order => $this->order($model),
            $model instanceof Rental => $this->rental($model),
            $model instanceof RentalPayment => $this->rentalPayment($model),
            $model instanceof Organization => $this->organizationChanged($model),
            $model instanceof Product => $this->productChanged($model),
            default => null,
        };
    }

    // ------------------------------------------------------------ documents

    /**
     * A goods invoice, sent once when the order is confirmed.
     */
    public function invoice(Invoice $invoice): ?string
    {
        if (($id = $this->syncedId($invoice)) !== null) {
            return $id;
        }

        $order = $invoice->order()->with(['lines.product', 'lines.productUnit', 'organization', 'customer', 'location'])->firstOrFail();

        if ($order->status === OrderStatus::Cancelled || $invoice->status === 'void') {
            $this->mark($invoice, 'skipped', 'Order cancelled before it was sent.');

            return null;
        }

        if ($this->beforeStart($invoice->issued_on->toDateString())) {
            $this->mark($invoice, 'skipped', 'Dated before the QuickBooks start date.');

            return null;
        }

        $region = $this->region($order->fulfillment === FulfillmentMethod::Delivery ? $order->delivery_address : null, $order->location);
        $exempt = (bool) $order->organization?->tax_exempt;
        $taxCode = $this->taxCode($region, $exempt);
        $exemptCode = $this->taxCode($region, true);
        $lines = [];
        $groups = [];
        $lineTax = 0;

        foreach ($order->lines->sortBy('position') as $line) {
            $taxable = $line->product === null || $line->product->taxable;
            $code = $line->tax_cents > 0 || ($taxable && ! $exempt) ? $taxCode : $exemptCode;
            $lines[] = $this->line($this->productItem($line->product, $line->description), $this->describe($line), $line->line_total_cents, (float) $line->quantity, $code);
            $this->addTax($groups, $code, $line->line_total_cents, $line->tax_cents);
            $lineTax += $line->tax_cents;
        }

        if ($order->delivery_fee_cents > 0) {
            $deliveryTax = max(0, $order->tax_cents - $lineTax);
            $code = $deliveryTax > 0 || ! $exempt ? $taxCode : $exemptCode;
            $lines[] = $this->line($this->serviceItem('delivery'), 'Delivery', $order->delivery_fee_cents, 1, $code);
            $this->addTax($groups, $code, $order->delivery_fee_cents, $deliveryTax);
        }

        $pallets = (int) $order->lines->sum('pallets');

        if ($order->deposit_cents > 0) {
            $lines[] = $this->line($this->serviceItem('deposits'), $pallets > 0 ? "Deposit on {$pallets} pallets and containers" : 'Container deposits', $order->deposit_cents, 1, $exemptCode);
        }

        $payload = [
            'DocNumber' => $this->docNumber($invoice->number),
            'TxnDate' => $invoice->issued_on->toDateString(),
            'DueDate' => $invoice->due_on->toDateString(),
            'CustomerRef' => ['value' => $this->customerFor($order->organization, $order->customer)],
            'GlobalTaxCalculation' => 'TaxExcluded',
            'Line' => $lines,
            'TxnTaxDetail' => $this->taxDetail($groups),
            'PrivateNote' => $this->note("Yardline order {$order->number}", $order->organization === null ? $order->customer : null),
            ...$this->department($order->location),
            ...($order->po_number ? ['CustomerMemo' => ['value' => "PO {$order->po_number}"]] : []),
            ...($order->organization?->email ? ['BillEmail' => ['Address' => $order->organization->email]] : []),
            ...($order->fulfillment === FulfillmentMethod::Delivery && $order->delivery_address ? ['ShipAddr' => $this->address($order->delivery_address)] : []),
        ];

        $created = $this->qbo->post('invoice', $payload)['Invoice'] ?? [];

        return $this->remember($invoice, 'Invoice', $created, $order->amountDueCents());
    }

    /**
     * A cancelled order voids its invoice in QuickBooks.
     */
    public function order(Order $order): void
    {
        $invoice = $order->invoice;

        if ($order->status !== OrderStatus::Cancelled || $invoice === null) {
            return;
        }

        $this->void($invoice, 'invoice');
    }

    public function payment(Payment $payment): void
    {
        if ($payment->status !== PaymentState::Succeeded || $this->syncedId($payment) !== null) {
            return;
        }

        $order = $payment->order;
        $invoice = $order->invoice;
        $invoiceId = $invoice !== null ? $this->invoice($invoice) : null;

        if ($invoiceId === null) {
            $this->mark($payment, 'skipped', 'Its invoice isn’t in QuickBooks.');

            return;
        }

        $customer = $this->customerFor($order->organization, $order->customer);

        if ($payment->tender === PaymentTender::CreditNote) {
            $credit = CreditNote::query()->where('number', $payment->reference)->first();
            $memoId = $credit !== null ? $this->creditMemo($credit) : null;

            if ($memoId === null) {
                $this->mark($payment, 'skipped', 'The credit note isn’t in QuickBooks.');

                return;
            }

            $created = $this->qbo->post('payment', [
                'CustomerRef' => ['value' => $customer],
                'TotalAmt' => 0,
                'TxnDate' => $this->localDate($payment->succeeded_at),
                'PrivateNote' => "Yardline: {$payment->reference} applied to {$order->number}",
                'Line' => [
                    ['Amount' => $this->dollars($payment->amount_cents), 'LinkedTxn' => [['TxnId' => $invoiceId, 'TxnType' => 'Invoice']]],
                    ['Amount' => $this->dollars($payment->amount_cents), 'LinkedTxn' => [['TxnId' => $memoId, 'TxnType' => 'CreditMemo']]],
                ],
            ])['Payment'] ?? [];

            $this->remember($payment, 'Payment', $created);

            return;
        }

        $created = $this->qbo->post('payment', [
            'CustomerRef' => ['value' => $customer],
            'TotalAmt' => $this->dollars($payment->amount_cents),
            'TxnDate' => $this->localDate($payment->succeeded_at),
            'DepositToAccountRef' => ['value' => $this->depositAccount($payment->tender->value)],
            'PrivateNote' => "Yardline {$payment->tender->label()} on {$order->number}",
            ...($payment->reference ? ['PaymentRefNum' => mb_substr($payment->reference, 0, 21)] : []),
            'Line' => [['Amount' => $this->dollars($payment->amount_cents), 'LinkedTxn' => [['TxnId' => $invoiceId, 'TxnType' => 'Invoice']]]],
        ])['Payment'] ?? [];

        $this->remember($payment, 'Payment', $created);
    }

    public function creditNote(CreditNote $credit): void
    {
        $memoId = $this->creditMemo($credit);

        if ($memoId === null) {
            return;
        }

        // Money paid back out: a journal entry from the refund account to
        // the customer's balance, then linked against the credit memo.
        $sync = QuickBooksSync::for($credit);
        $sent = (int) ($sync->meta['refunded_cents'] ?? 0);
        $owed = $credit->refunded_cents - $sent;

        if ($owed <= 0 || $sync === null) {
            return;
        }

        $customer = $this->customerFor($credit->organization, $credit->customer);
        $entry = $this->refundEntry($customer, $owed, (string) $credit->refund_tender, "Refund of {$credit->number}", now()->toImmutable());

        $this->qbo->post('payment', [
            'CustomerRef' => ['value' => $customer],
            'TotalAmt' => 0,
            'PrivateNote' => "Yardline: refund of {$credit->number}",
            'Line' => [
                ['Amount' => $this->dollars($owed), 'LinkedTxn' => [['TxnId' => $entry, 'TxnType' => 'JournalEntry']]],
                ['Amount' => $this->dollars($owed), 'LinkedTxn' => [['TxnId' => $memoId, 'TxnType' => 'CreditMemo']]],
            ],
        ]);

        $sync->forceFill(['meta' => [...($sync->meta ?? []), 'refunded_cents' => $credit->refunded_cents]])->save();
    }

    public function creditMemo(CreditNote $credit): ?string
    {
        if (($id = $this->syncedId($credit)) !== null) {
            return $id;
        }

        if ($credit->total_cents <= 0) {
            return null;
        }

        if ($this->beforeStart($credit->issued_on->toDateString())) {
            $this->mark($credit, 'skipped', 'Dated before the QuickBooks start date.');

            return null;
        }

        $credit->loadMissing(['lines.product', 'order', 'organization', 'customer', 'location']);
        $order = $credit->order;
        $location = $credit->location ?? ($order !== null ? $order->location : null) ?? Location::query()->orderByDesc('is_default')->firstOrFail();
        $region = $this->region($order !== null && $order->fulfillment === FulfillmentMethod::Delivery ? $order->delivery_address : null, $location);
        $exempt = (bool) $credit->organization?->tax_exempt;
        $code = $this->taxCode($region, $exempt || $credit->tax_cents === 0);
        $exemptCode = $this->taxCode($region, true);
        $lines = [];
        $net = 0;

        foreach ($credit->lines as $line) {
            $lines[] = $this->line($this->productItem($line->product, $line->description), $line->description, $line->line_total_cents, (float) $line->quantity, $line->tax_cents > 0 ? $code : $exemptCode);
            $net += $line->tax_cents > 0 ? $line->line_total_cents : 0;
        }

        if ($credit->restocking_fee_cents > 0) {
            $lines[] = $this->line($this->serviceItem('restocking'), 'Restocking fee', -$credit->restocking_fee_cents, 1, $code);
            $net -= $code === $exemptCode ? 0 : $credit->restocking_fee_cents;
        }

        if ($credit->kind === 'adjustment' && $credit->lines->isEmpty()) {
            $lines[] = $this->line($this->serviceItem('adjustment'), $credit->reason ?: 'Adjustment', $credit->subtotal_cents, 1, $code);
            $net += $credit->tax_cents > 0 ? $credit->subtotal_cents : 0;
        }

        if ($credit->deposit_cents > 0) {
            $lines[] = $this->line($this->serviceItem('deposits'), $credit->kind === 'pallets' ? 'Pallets returned' : 'Deposit returned', $credit->deposit_cents, 1, $exemptCode);
        }

        $groups = [];
        $this->addTax($groups, $code, $net, $credit->tax_cents);

        $created = $this->qbo->post('creditmemo', [
            'DocNumber' => $this->docNumber($credit->number),
            'TxnDate' => $credit->issued_on->toDateString(),
            'CustomerRef' => ['value' => $this->customerFor($credit->organization, $credit->customer)],
            'GlobalTaxCalculation' => 'TaxExcluded',
            'Line' => $lines,
            'TxnTaxDetail' => $this->taxDetail($groups),
            'PrivateNote' => $this->note(trim("Yardline {$credit->number}".($order ? " for {$order->number}" : '').($credit->reason ? ": {$credit->reason}" : '')), $credit->organization === null ? $credit->customer : null),
            ...$this->department($location),
        ])['CreditMemo'] ?? [];

        return $this->remember($credit, 'CreditMemo', $created, $credit->total_cents);
    }

    /**
     * A rental invoice: created when the equipment goes out, updated with
     * extra charges when it comes back, voided if the rental is cancelled.
     */
    public function rental(Rental $rental): ?string
    {
        $sync = QuickBooksSync::for($rental);

        if ($rental->status === RentalStatus::Cancelled) {
            if ($sync?->qbo_id !== null) {
                $this->void($rental, 'invoice');
            }

            return null;
        }

        if ($rental->checked_out_at === null) {
            return null;
        }

        if ($sync?->qbo_id === null && $this->beforeStart($rental->checked_out_at)) {
            $this->mark($rental, 'skipped', 'Went out before the QuickBooks start date.');

            return null;
        }

        $rental->loadMissing(['items.product', 'items.unit', 'charges', 'organization', 'customer', 'location']);
        $region = $this->region($rental->fulfillment === FulfillmentMethod::Delivery ? $rental->delivery_address : null, $rental->location);
        $code = $this->taxCode($region, (bool) $rental->organization?->tax_exempt || $rental->tax_cents === 0);
        $tz = $this->timezone();
        $dates = $rental->starts_at->setTimezone($tz)->format('M j g:i a').' to '.($rental->returned_at ?? $rental->ends_at)->setTimezone($tz)->format('M j g:i a');
        $lines = [];

        foreach ($rental->items as $item) {
            $serial = $item->unit !== null ? " · unit {$item->unit->serial}" : '';
            $lines[] = $this->line($this->productItem($item->product, $item->description, rental: true), trim("{$item->description} · {$item->rate_summary}{$serial} · {$dates}", ' ·'), $item->charge_cents, 1, $code);
        }

        if ($rental->waiver_cents > 0) {
            $lines[] = $this->line($this->serviceItem('waiver'), "Damage waiver {$rental->damage_waiver_percent}%", $rental->waiver_cents, 1, $code);
        }

        if ($rental->delivery_fee_cents > 0) {
            $lines[] = $this->line($this->serviceItem('delivery'), 'Delivery and pickup', $rental->delivery_fee_cents, 1, $code);
        }

        foreach ($rental->charges as $charge) {
            if ($charge->billableCents() > 0) {
                $kind = (string) $charge->kind->value;
                $key = array_key_exists($kind, self::SERVICE_ITEMS) ? $kind : 'other';
                $lines[] = $this->line($this->serviceItem($key), $charge->description, $charge->billableCents(), 1, $code);
            }
        }

        $groups = [];
        $this->addTax($groups, $code, $rental->subtotal_cents, $rental->tax_cents);

        $payload = [
            'DocNumber' => $this->docNumber($rental->number),
            'TxnDate' => $this->localDate($rental->checked_out_at),
            'DueDate' => $this->localDate($rental->checked_out_at),
            'CustomerRef' => ['value' => $this->customerFor($rental->organization, $rental->customer)],
            'GlobalTaxCalculation' => 'TaxExcluded',
            'Line' => $lines,
            'TxnTaxDetail' => $this->taxDetail($groups),
            'PrivateNote' => $this->note("Yardline rental {$rental->number}", $rental->organization === null ? $rental->customer : null),
            ...$this->department($rental->location),
        ];

        $hash = hash('sha256', (string) json_encode($payload));

        if ($sync?->qbo_id !== null) {
            if (($sync->meta['hash'] ?? null) === $hash) {
                return $sync->qbo_id;
            }

            $current = $this->qbo->get("invoice/{$sync->qbo_id}")['Invoice'] ?? [];
            $payload = ['Id' => $sync->qbo_id, 'SyncToken' => (string) ($current['SyncToken'] ?? '0'), ...$payload];
        }

        $saved = $this->qbo->post('invoice', $payload)['Invoice'] ?? [];
        $id = $this->remember($rental, 'Invoice', $saved, $rental->total_cents, ['hash' => $hash]);

        // Payments taken before it went out were waiting for this invoice.
        RentalPayment::query()->where('rental_id', $rental->id)->where('status', PaymentState::Succeeded->value)
            ->whereIn('kind', [RentalPaymentKind::Charge->value, RentalPaymentKind::DepositCapture->value, RentalPaymentKind::Refund->value])
            ->get()
            ->each(fn (RentalPayment $p) => $this->syncedId($p) === null ? QuickBooks::queue($p) : null);

        return $id;
    }

    public function rentalPayment(RentalPayment $payment): void
    {
        if ($payment->status !== PaymentState::Succeeded || $this->syncedId($payment) !== null) {
            return;
        }

        if (in_array($payment->kind, [RentalPaymentKind::DepositHold, RentalPaymentKind::DepositRelease], true)) {
            return; // a card authorization; no money moved
        }

        $rental = $payment->rental;
        $invoiceId = $this->syncedId($rental);

        if ($invoiceId === null) {
            $this->mark($payment, 'waiting', 'Sent once the rental goes out and its invoice is in QuickBooks.');

            return;
        }

        $customer = $this->customerFor($rental->organization, $rental->customer);

        if ($payment->kind === RentalPaymentKind::Refund) {
            $entry = $this->refundEntry($customer, $payment->amount_cents, $payment->tender->value, "Refund on rental {$rental->number}", $payment->succeeded_at);
            $this->remember($payment, 'JournalEntry', ['Id' => $entry]);

            return;
        }

        $created = $this->qbo->post('payment', [
            'CustomerRef' => ['value' => $customer],
            'TotalAmt' => $this->dollars($payment->amount_cents),
            'TxnDate' => $this->localDate($payment->succeeded_at),
            'DepositToAccountRef' => ['value' => $this->depositAccount($payment->tender->value)],
            'PrivateNote' => 'Yardline '.($payment->kind === RentalPaymentKind::DepositCapture ? 'deposit kept' : 'payment')." on rental {$rental->number}",
            ...($payment->reference ? ['PaymentRefNum' => mb_substr($payment->reference, 0, 21)] : []),
            'Line' => [['Amount' => $this->dollars($payment->amount_cents), 'LinkedTxn' => [['TxnId' => $invoiceId, 'TxnType' => 'Invoice']]]],
        ])['Payment'] ?? [];

        $this->remember($payment, 'Payment', $created);
    }

    // ------------------------------------------------------ names and items

    /**
     * An organization that's already in QuickBooks is updated when one of
     * the fields sent changes. New ones go across with their first document.
     */
    public function organizationChanged(Organization $organization): void
    {
        if ($this->syncedId($organization) !== null) {
            $this->customer($organization);
        }
    }

    public function productChanged(Product $product): void
    {
        if ($this->syncedId($product) !== null) {
            $this->productItem($product, $product->name, rental: $product->kind->value === 'rental');
        }
    }

    public function customerFor(?Organization $organization, ?Customer $person): string
    {
        return $organization !== null ? $this->customer($organization) : $this->cashSalesCustomer();
    }

    public function customer(Organization $org): string
    {
        $payload = array_filter([
            'DisplayName' => $this->clean($org->name),
            'CompanyName' => $this->clean($org->name),
            'PrimaryEmailAddr' => $org->email ? ['Address' => $org->email] : null,
            'PrimaryPhone' => $org->phone ? ['FreeFormNumber' => $org->phone] : null,
            'BillAddr' => $org->address_line1 ? $this->address([
                'line1' => $org->address_line1, 'line2' => $org->address_line2, 'city' => $org->city,
                'region' => $org->region, 'postal_code' => $org->postal_code, 'country' => $org->country,
            ]) : null,
            'Notes' => trim("Yardline account {$org->account_number}. Terms: {$org->payment_terms->label()}.".($org->tax_exempt ? " Tax exempt: {$org->tax_exempt_number}." : '')),
        ]);

        return $this->upsertNamed($org, 'Customer', $payload, fallbackName: "{$this->clean($org->name)} ({$org->account_number})");
    }

    public function cashSalesCustomer(): string
    {
        return $this->cached('cash_customer', fn () => $this->findOrCreate('Customer', 'DisplayName', 'Cash sales', ['DisplayName' => 'Cash sales', 'Notes' => 'Walk-in and one-time customers from Yardline. The person’s name is in each document’s memo.']));
    }

    public function productItem(?Product $product, string $description, bool $rental = false): string
    {
        if ($product === null) {
            return $this->serviceItem($rental ? 'other' : 'adjustment');
        }

        $rental = $rental || $product->kind->value === 'rental';
        $payload = [
            'Name' => mb_substr($this->clean($product->name), 0, 100),
            'Sku' => mb_substr($product->sku, 0, 100),
            'Description' => mb_substr($product->description ?: $product->name, 0, 4000),
            'Type' => $rental ? 'Service' : 'NonInventory',
            'IncomeAccountRef' => ['value' => $this->account($rental ? 'rental' : 'sales')],
            'UnitPrice' => $this->dollars($product->price_cents),
        ];

        return $this->upsertNamed($product, 'Item', $payload, fallbackName: mb_substr("{$this->clean($product->name)} ({$product->sku})", 0, 100));
    }

    public function serviceItem(string $key): string
    {
        [$name, $accountKey] = self::SERVICE_ITEMS[$key];
        $account = $this->account($accountKey);

        return $this->cached("item:{$key}:{$account}", function () use ($name, $account) {
            $id = $this->findOrCreate('Item', 'Name', $name, ['Name' => $name, 'Type' => 'Service', 'IncomeAccountRef' => ['value' => $account]]);
            $item = $this->qbo->get("item/{$id}")['Item'] ?? [];

            if (($item['IncomeAccountRef']['value'] ?? null) !== $account) {
                $this->qbo->post('item', ['Id' => $id, 'SyncToken' => (string) ($item['SyncToken'] ?? '0'), 'sparse' => true, 'Name' => $name, 'IncomeAccountRef' => ['value' => $account]]);
            }

            return $id;
        });
    }

    // ------------------------------------------------------------- helpers

    /**
     * Creates the record, or adopts one already in QuickBooks with the same
     * name, or updates it when what Yardline sends has changed.
     *
     * @param  array<string, mixed>  $payload
     */
    private function upsertNamed(Model $model, string $entity, array $payload, string $fallbackName): string
    {
        $nameField = $entity === 'Customer' ? 'DisplayName' : 'Name';
        $sync = QuickBooksSync::for($model);
        $hash = hash('sha256', (string) json_encode($payload));

        if ($sync?->qbo_id !== null) {
            if (($sync->meta['hash'] ?? null) !== $hash) {
                $current = $this->qbo->get(strtolower($entity)."/{$sync->qbo_id}")[$entity] ?? [];
                $payload[$nameField] = $current[$nameField] ?? $payload[$nameField];
                $this->qbo->post(strtolower($entity), ['Id' => $sync->qbo_id, 'SyncToken' => (string) ($current['SyncToken'] ?? '0'), 'sparse' => true, ...$payload]);
                $sync->forceFill(['meta' => [...($sync->meta ?? []), 'hash' => $hash], 'synced_at' => now()])->save();
            }

            return $sync->qbo_id;
        }

        $existing = $this->qbo->query("select Id from {$entity} where {$nameField} = '".$this->quote((string) $payload[$nameField])."'")[0]['Id'] ?? null;

        if ($existing !== null) {
            $this->remember($model, $entity, ['Id' => $existing], null, ['hash' => null]);

            return $this->upsertNamed($model, $entity, $payload, $fallbackName);
        }

        try {
            $created = $this->qbo->post(strtolower($entity), $payload)[$entity] ?? [];
        } catch (QuickBooksException $e) {
            // The name is taken by another kind of record (a vendor, an
            // inactive item): try the account number or SKU alongside it.
            if ($e->qboCode !== '6240' || $payload[$nameField] === $fallbackName) {
                throw $e;
            }

            return $this->upsertNamed($model, $entity, [...$payload, $nameField => $fallbackName], $fallbackName);
        }

        return $this->remember($model, $entity, $created, null, ['hash' => $hash]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function findOrCreate(string $entity, string $field, string $value, array $payload): string
    {
        $existing = $this->qbo->query("select Id from {$entity} where {$field} = '".$this->quote($value)."'")[0]['Id'] ?? null;

        if ($existing !== null) {
            return (string) $existing;
        }

        return (string) ($this->qbo->post(strtolower($entity), $payload)[$entity]['Id'] ?? '');
    }

    /**
     * @param  callable(): string  $make
     */
    private function cached(string $key, callable $make): string
    {
        $settings = $this->integration->settings ?? [];
        $cache = is_array($settings['refs'] ?? null) ? $settings['refs'] : [];

        if (isset($cache[$key]) && is_string($cache[$key])) {
            return $cache[$key];
        }

        $id = $make();
        $cache[$key] = $id;
        $this->integration->forceFill(['settings' => [...$settings, 'refs' => $cache]])->save();

        return $id;
    }

    private function void(Model $model, string $entity): void
    {
        $sync = QuickBooksSync::for($model);

        if ($sync?->qbo_id === null || ($sync->meta['voided'] ?? false)) {
            return;
        }

        $current = $this->qbo->get("{$entity}/{$sync->qbo_id}");
        $record = $current[ucfirst($entity)] ?? [];
        $this->qbo->post($entity, ['Id' => $sync->qbo_id, 'SyncToken' => (string) ($record['SyncToken'] ?? '0')], 'void');
        $sync->forceFill(['meta' => [...($sync->meta ?? []), 'voided' => true], 'message' => 'Voided in QuickBooks.', 'synced_at' => now()])->save();
    }

    private function refundEntry(string $customer, int $cents, string $tender, string $note, ?CarbonInterface $on): string
    {
        $ar = $this->cached('ar_account', fn () => (string) ($this->qbo->query("select Id from Account where AccountType = 'Accounts Receivable'")[0]['Id'] ?? ''));

        $entry = $this->qbo->post('journalentry', [
            'TxnDate' => $this->localDate($on),
            'PrivateNote' => "Yardline: {$note}",
            'Line' => [
                [
                    'DetailType' => 'JournalEntryLineDetail',
                    'Amount' => $this->dollars($cents),
                    'Description' => $note,
                    'JournalEntryLineDetail' => ['PostingType' => 'Debit', 'AccountRef' => ['value' => $ar], 'Entity' => ['Type' => 'Customer', 'EntityRef' => ['value' => $customer]]],
                ],
                [
                    'DetailType' => 'JournalEntryLineDetail',
                    'Amount' => $this->dollars($cents),
                    'Description' => $note,
                    'JournalEntryLineDetail' => ['PostingType' => 'Credit', 'AccountRef' => ['value' => $this->depositAccount($tender)]],
                ],
            ],
        ])['JournalEntry'] ?? [];

        return (string) ($entry['Id'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    private function line(string $item, string $description, int $cents, float $quantity, string $taxCode): array
    {
        $quantity = $quantity > 0 ? $quantity : 1;

        return [
            'DetailType' => 'SalesItemLineDetail',
            'Amount' => $this->dollars($cents),
            'Description' => mb_substr($description, 0, 4000),
            'SalesItemLineDetail' => [
                'ItemRef' => ['value' => $item],
                'Qty' => round($quantity, 3),
                // Price backed out of the amount so Qty × price is the amount to the cent.
                'UnitPrice' => round($cents / 100 / $quantity, 7),
                'TaxCodeRef' => ['value' => $taxCode],
            ],
        ];
    }

    private function describe(OrderLine $line): string
    {
        $qty = rtrim(rtrim(number_format((float) $line->quantity, 3), '0'), '.');
        $text = "{$line->description} · {$qty} {$line->unit}";

        if ($line->productUnit !== null && ! $line->productUnit->is_base && $line->product !== null) {
            $base = rtrim(rtrim(number_format((float) $line->base_quantity, 3), '0'), '.');
            $text .= " = {$base} ".$line->product->unit->label();
        }

        return $text;
    }

    /**
     * @param  array<string, array{net: int, tax: int}>  $groups
     */
    private function addTax(array &$groups, string $code, int $net, int $tax): void
    {
        $groups[$code] ??= ['net' => 0, 'tax' => 0];
        $groups[$code]['net'] += $net;
        $groups[$code]['tax'] += $tax;
    }

    /**
     * The tax Yardline charged, split across the code's rates (GST and QST
     * in Quebec) so QuickBooks files it on the right lines of the return.
     *
     * @param  array<string, array{net: int, tax: int}>  $groups
     * @return array<string, mixed>
     */
    private function taxDetail(array $groups): array
    {
        $lines = [];
        $total = 0;

        foreach ($groups as $code => $group) {
            if ($group['tax'] === 0) {
                continue;
            }

            $rates = $this->ratesFor($code);
            $sum = array_sum(array_column($rates, 'rate')) ?: 1;
            $left = $group['tax'];

            foreach ($rates as $i => $rate) {
                $cents = $i === array_key_last($rates) ? $left : (int) round($group['tax'] * $rate['rate'] / $sum);
                $left -= $cents;
                $lines[] = [
                    'Amount' => $this->dollars($cents),
                    'DetailType' => 'TaxLineDetail',
                    'TaxLineDetail' => [
                        'TaxRateRef' => ['value' => $rate['id']],
                        'PercentBased' => true,
                        'TaxPercent' => $rate['rate'],
                        'NetAmountTaxable' => $this->dollars($group['net']),
                    ],
                ];
            }

            $total += $group['tax'];
        }

        return ['TotalTax' => $this->dollars($total), 'TaxLine' => $lines];
    }

    /**
     * @return list<array{id: string, rate: float}>
     */
    private function ratesFor(string $code): array
    {
        if (isset($this->rates[$code])) {
            return $this->rates[$code];
        }

        $taxCode = $this->qbo->get("taxcode/{$code}")['TaxCode'] ?? [];
        $details = $taxCode['SalesTaxRateList']['TaxRateDetail'] ?? [];
        $rates = [];

        foreach (is_array($details) ? $details : [] as $detail) {
            $id = (string) ($detail['TaxRateRef']['value'] ?? '');

            if ($id !== '') {
                $rate = $this->qbo->get("taxrate/{$id}")['TaxRate'] ?? [];
                $rates[] = ['id' => $id, 'rate' => (float) ($rate['RateValue'] ?? 0)];
            }
        }

        if ($rates === []) {
            throw new QuickBooksException("QuickBooks tax code {$code} has no sales tax rates. Pick another in Settings → QuickBooks.");
        }

        return $this->rates[$code] = $rates;
    }

    private function taxCode(string $region, bool $exempt): string
    {
        $codes = TaxCodes::for($this->integration);
        $code = $exempt ? $codes['exempt'] : ($codes[$region] ?? $codes['other']);

        if ($code === null) {
            throw new QuickBooksException('Choose the QuickBooks tax codes in Settings → QuickBooks.');
        }

        return $code;
    }

    /**
     * Province the sale was taxed in: the delivery address, else the yard.
     *
     * @param  array<string, string|null>|null  $address
     */
    private function region(?array $address, ?Location $yard): string
    {
        foreach ([$address, $yard?->addressForTax()] as $candidate) {
            $region = strtoupper(trim((string) ($candidate['region'] ?? $candidate['state'] ?? '')));

            if (in_array($region, ['ON', 'ONTARIO'], true)) {
                return 'ON';
            }

            if (in_array($region, ['QC', 'QUEBEC', 'QUÉBEC', 'PQ'], true)) {
                return 'QC';
            }

            if ($region !== '') {
                return 'other';
            }

            $postal = strtoupper(substr(trim((string) ($candidate['postal_code'] ?? '')), 0, 1));

            if ($postal !== '') {
                return match (true) {
                    in_array($postal, ['K', 'L', 'M', 'N', 'P'], true) => 'ON',
                    in_array($postal, ['G', 'H', 'J'], true) => 'QC',
                    default => 'other',
                };
            }
        }

        return 'ON';
    }

    /**
     * @return array<string, mixed>
     */
    private function department(?Location $yard): array
    {
        if ($yard === null || Location::query()->count() < 2) {
            return [];
        }

        $id = $this->cached("department:{$yard->id}", fn () => $this->findOrCreate('Department', 'Name', $this->clean($yard->name), ['Name' => $this->clean($yard->name)]));

        return ['DepartmentRef' => ['value' => $id]];
    }

    private function depositAccount(string $tender): string
    {
        $card = [
            PaymentTender::StripeCheckout->value, PaymentTender::CardTerminal->value,
            RentalTender::CardOnFile->value, RentalTender::PaymentLink->value, RentalTender::CardTerminal->value,
        ];

        return $this->account(in_array($tender, $card, true) ? 'stripe_clearing' : 'undeposited');
    }

    private function account(string $key): string
    {
        $id = $this->accounts[$key] ?? null;

        if ($id === null) {
            throw new QuickBooksException('Choose every account in Settings → QuickBooks.');
        }

        return $id;
    }

    /**
     * @param  array<string, string|null>  $address
     * @return array<string, string>
     */
    private function address(array $address): array
    {
        return array_filter([
            'Line1' => (string) ($address['line1'] ?? ''),
            'Line2' => (string) ($address['line2'] ?? ''),
            'City' => (string) ($address['city'] ?? ''),
            'CountrySubDivisionCode' => (string) ($address['region'] ?? $address['state'] ?? ''),
            'PostalCode' => (string) ($address['postal_code'] ?? ''),
            'Country' => (string) ($address['country'] ?? 'CA'),
        ]);
    }

    private function note(string $text, ?Customer $person): string
    {
        return mb_substr($person !== null ? "{$text} · {$person->full_name}" : $text, 0, 4000);
    }

    /**
     * @param  array<string, mixed>  $record  what QuickBooks returned
     * @param  array<string, mixed>  $meta
     */
    private function remember(Model $model, string $type, array $record, ?int $expectedCents = null, array $meta = []): string
    {
        $id = isset($record['Id']) ? (string) $record['Id'] : null;

        if ($id === null || $id === '') {
            throw new QuickBooksException("QuickBooks didn’t return the new {$type}.");
        }

        $message = null;

        if ($expectedCents !== null && isset($record['TotalAmt']) && (int) round((float) $record['TotalAmt'] * 100) !== $expectedCents) {
            $message = 'Total in QuickBooks is $'.number_format((float) $record['TotalAmt'], 2).', Yardline has $'.number_format($expectedCents / 100, 2).'. Check the tax codes.';
        }

        $sync = QuickBooksSync::for($model);

        QuickBooksSync::query()->updateOrCreate(
            ['syncable_type' => $model->getMorphClass(), 'syncable_id' => $model->getKey()],
            [
                'label' => $this->label($model),
                'status' => $message === null ? 'synced' : 'failed',
                'qbo_type' => $type,
                'qbo_id' => $id,
                'message' => $message,
                'meta' => [...($sync->meta ?? []), ...$meta, ...($expectedCents !== null ? ['total_cents' => $expectedCents] : [])],
                'synced_at' => now(),
            ],
        );

        return $id;
    }

    private function mark(Model $model, string $status, string $message): void
    {
        QuickBooksSync::query()->updateOrCreate(
            ['syncable_type' => $model->getMorphClass(), 'syncable_id' => $model->getKey()],
            ['label' => $this->label($model), 'status' => $status, 'message' => $message],
        );
    }

    private function syncedId(Model $model): ?string
    {
        return QuickBooksSync::for($model)?->qbo_id;
    }

    public function label(Model $model): string
    {
        return match (true) {
            $model instanceof Invoice => "Invoice {$model->number}",
            $model instanceof Payment => 'Payment of $'.number_format($model->amount_cents / 100, 2).' on '.($model->order->number ?? 'order'),
            $model instanceof CreditNote => "Credit note {$model->number}",
            $model instanceof Rental => "Rental {$model->number}",
            $model instanceof RentalPayment => 'Rental payment of $'.number_format($model->amount_cents / 100, 2).' on '.($model->rental->number ?? 'rental'),
            $model instanceof Organization => "Customer {$model->name}",
            $model instanceof Product => "Item {$model->name}",
            $model instanceof Order => "Order {$model->number}",
            default => class_basename($model).' '.$model->getKey(),
        };
    }

    /**
     * A moment is compared on the yard's calendar; a date-only value (an
     * invoice's issue date) is already a yard day and is compared as is,
     * since shifting its midnight into the yard's time zone would move it
     * to the day before.
     */
    private function beforeStart(CarbonInterface|string|null $date): bool
    {
        $start = $this->integration->settings['start_date'] ?? null;

        if (! is_string($start) || $date === null) {
            return false;
        }

        return (is_string($date) ? $date : $this->localDate($date)) < $start;
    }

    private function localDate(?CarbonInterface $at): string
    {
        return CarbonImmutable::instance($at ?? now())->setTimezone($this->timezone())->toDateString();
    }

    private function timezone(): string
    {
        return CurrentTenant::get()->timezone ?? 'America/Toronto';
    }

    private function docNumber(string $number): string
    {
        return mb_substr($number, 0, 21);
    }

    private function dollars(int $cents): float
    {
        return round($cents / 100, 2);
    }

    private function clean(string $name): string
    {
        // QuickBooks names can't contain colons or tabs or newlines.
        return trim((string) preg_replace('/[:\t\r\n]+/', ' ', $name));
    }

    private function quote(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
