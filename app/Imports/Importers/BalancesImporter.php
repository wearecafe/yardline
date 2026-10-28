<?php

namespace App\Imports\Importers;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Settlement;
use App\Imports\ImportContext;
use App\Imports\Importer;
use App\Imports\Row;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\Order;
use App\Models\Organization;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;

/**
 * What each account owed in the old system, invoice by invoice. Each
 * becomes an opening-balance order with its invoice (OB- plus the old
 * number), dated and due as it was, so aging, statements, credit limits
 * and payments carry on. A negative amount is a credit on the account
 * (OC- plus the old number). They aren't sales: the profit report and
 * QuickBooks leave them out, since QuickBooks already has them. Loading
 * the same invoice number again skips it.
 */
class BalancesImporter implements Importer
{
    public function check(Row $row, ImportContext $context): void
    {
        $account = $row->text('account', 255, required: true);
        $number = $row->text('invoice_number', 27, required: true, label: 'invoice number');
        $issued = $row->date('issued_on', required: true);
        $due = $row->date('due_on');
        $amount = $row->money('amount', required: true, allowNegative: true);
        $org = $account === null ? null : $context->organization($account);

        if ($account !== null && $org === null) {
            $row->error('account', "There’s no account {$account}. Import accounts first.");
        }

        if ($amount === 0) {
            $row->error('amount', 'Leave out invoices with nothing owing.');
        }

        if ($issued !== null && $due !== null && $due->lessThan($issued)) {
            $row->error('due_on', 'Due on has to be on or after the invoice date.');
        }

        $yardCell = $row->text('yard', 120);
        $yard = $yardCell === null ? $context->defaultYard() : $context->yard($yardCell);

        if ($yardCell !== null && $yard === null) {
            $row->error('yard', "There’s no yard called {$yardCell}.");
        }

        if ($number === null || $org === null || $issued === null || $amount === null) {
            return;
        }

        $credit = $amount < 0;
        $reference = ($credit ? 'OC-' : 'OB-').$number;

        if ($context->planned('balance', $reference)) {
            $row->error('invoice_number', "{$number} is already on a row above.");

            return;
        }

        $context->plan('balance', $reference);

        $row->data = [
            'organization_id' => $org->id,
            'location_id' => $yard?->id,
            'reference' => $reference,
            'number' => $number,
            'credit' => $credit,
            'amount_cents' => abs($amount),
            'issued_on' => $issued->toDateString(),
            'due_on' => ($due ?? $issued->addDays($org->payment_terms->days()))->toDateString(),
            'po_number' => $row->text('po_number', 60),
        ];

        if ($this->exists($reference, $credit)) {
            $row->action = Row::SKIP;
            $row->note = "{$number} was imported before.";
        }
    }

    public function apply(Row $row, ImportContext $context): string
    {
        $data = $row->data;

        if ($this->exists($data['reference'], $data['credit'])) {
            return Row::SKIP;
        }

        $org = Organization::query()->findOrFail((int) $data['organization_id']);
        $yard = ($data['location_id'] !== null ? Location::query()->find((int) $data['location_id']) : null) ?? $context->defaultYard();

        if ($data['credit']) {
            CreditNote::query()->create([
                'number' => $data['reference'],
                'kind' => 'opening',
                'organization_id' => $org->id,
                'location_id' => $yard?->id,
                'reason' => "Opening credit, {$data['number']} in the old system",
                'subtotal_cents' => $data['amount_cents'],
                'total_cents' => $data['amount_cents'],
                'issued_on' => $data['issued_on'],
                'created_by' => $context->user?->id,
            ]);

            return Row::CREATE;
        }

        $tz = CurrentTenant::get()->timezone ?? 'America/Toronto';
        $at = CarbonImmutable::parse($data['issued_on'], $tz)->startOfDay();

        $order = new Order([
            'number' => $data['reference'],
            'type' => OrderType::OpeningBalance,
            'source' => 'import',
            'organization_id' => $org->id,
            'location_id' => $yard?->id,
            'po_number' => $data['po_number'],
            'notes' => "Opening balance: invoice {$data['number']} in the old system, from {$context->import->filename}.",
            'created_by' => $context->user?->id,
        ]);
        $order->forceFill([
            'status' => OrderStatus::Fulfilled,
            'settlement' => Settlement::Account,
            'payment_status' => PaymentStatus::Unpaid,
            'subtotal_cents' => $data['amount_cents'],
            'total_cents' => $data['amount_cents'],
            'confirmed_at' => $at,
            'fulfilled_at' => $at,
        ])->save();

        $order->lines()->create([
            'description' => "Opening balance, invoice {$data['number']}",
            'quantity' => 1,
            'unit' => 'each',
            'unit_price_cents' => $data['amount_cents'],
            'line_total_cents' => $data['amount_cents'],
        ]);

        Invoice::query()->create([
            'number' => $data['reference'],
            'order_id' => $order->id,
            'organization_id' => $org->id,
            'terms' => $org->payment_terms,
            'issued_on' => $data['issued_on'],
            'due_on' => $data['due_on'],
            'status' => 'open',
        ]);

        return Row::CREATE;
    }

    private function exists(string $reference, bool $credit): bool
    {
        return $credit
            ? CreditNote::query()->where('number', $reference)->exists()
            : Invoice::query()->where('number', $reference)->exists() || Order::query()->where('number', $reference)->exists();
    }
}
