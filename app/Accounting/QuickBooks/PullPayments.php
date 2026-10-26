<?php

namespace App\Accounting\QuickBooks;

use App\Enums\PaymentState;
use App\Enums\PaymentTender;
use App\Enums\RentalTender;
use App\Models\Integration;
use App\Models\Invoice;
use App\Models\QuickBooksSync;
use App\Models\Rental;
use App\Payments\ApplyPayments;
use App\Rentals\Payments\RentalPayments;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Payments the bookkeeper entered straight into QuickBooks (a cheque mailed
 * to the office) against invoices Yardline sent, so the order shows paid
 * and the account's credit frees up. Uses QuickBooks' change feed.
 */
class PullPayments
{
    public function __construct(private ApplyPayments $apply, private RentalPayments $rentals) {}

    /**
     * @return int payments recorded
     */
    public function handle(Integration $integration): int
    {
        $settings = $integration->settings ?? [];
        $since = is_string($settings['pulled_at'] ?? null)
            ? CarbonImmutable::parse($settings['pulled_at'])->subMinutes(5)
            : CarbonImmutable::now()->subDay();
        $startedAt = CarbonImmutable::now();

        $body = QuickBooksClient::for($integration)->get('cdc', ['entities' => 'Payment', 'changedSince' => $since->toIso8601String()]);
        $payments = $body['CDCResponse'][0]['QueryResponse'][0]['Payment'] ?? [];
        $recorded = 0;

        foreach (is_array($payments) ? $payments : [] as $payment) {
            if (! is_array($payment) || ($payment['status'] ?? null) === 'Deleted') {
                continue;
            }

            try {
                $recorded += $this->record($payment);
            } catch (Throwable $e) {
                report($e);
            }
        }

        $integration->forceFill(['settings' => [...($integration->refresh()->settings ?? []), 'pulled_at' => $startedAt->toIso8601String()]])->save();

        return $recorded;
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    private function record(array $payment): int
    {
        $qboId = (string) ($payment['Id'] ?? '');

        // One Yardline sent: nothing to bring back.
        if ($qboId === '' || QuickBooksSync::query()->where('qbo_type', 'Payment')->where('qbo_id', $qboId)->whereIn('syncable_type', ['payment', 'rental_payment'])->whereNull('meta->pulled')->exists()) {
            return 0;
        }

        $recorded = 0;
        $date = CarbonImmutable::parse((string) ($payment['TxnDate'] ?? 'today'));
        $reference = mb_substr(trim('QuickBooks '.($payment['PaymentRefNum'] ?? "payment {$qboId}")), 0, 255);
        $method = strtolower((string) ($payment['PaymentMethodRef']['name'] ?? ''));

        foreach ((array) ($payment['Line'] ?? []) as $index => $line) {
            foreach ((array) ($line['LinkedTxn'] ?? []) as $linked) {
                if (($linked['TxnType'] ?? null) !== 'Invoice') {
                    continue;
                }

                $key = "{$qboId}:{$index}:{$linked['TxnId']}";

                if (QuickBooksSync::query()->where('qbo_type', 'Payment')->where('meta->pulled', $key)->exists()) {
                    continue;
                }

                $target = QuickBooksSync::query()->where('qbo_type', 'Invoice')->where('qbo_id', (string) $linked['TxnId'])->first();
                $cents = (int) round((float) ($line['Amount'] ?? 0) * 100);

                if ($target === null || $cents <= 0) {
                    continue;
                }

                $created = QuickBooks::muted(fn () => DB::transaction(function () use ($target, $cents, $date, $reference, $method) {
                    if ($target->syncable_type === 'invoice') {
                        $order = Invoice::query()->find($target->syncable_id)?->order;

                        if ($order === null || $order->balanceCents() < $cents) {
                            return null;
                        }

                        $payment = $order->payments()->create([
                            'tender' => $this->tender($method),
                            'status' => PaymentState::Succeeded,
                            'amount_cents' => $cents,
                            'reference' => $reference,
                            'succeeded_at' => $date,
                        ]);
                        $this->apply->handle($order);

                        return $payment;
                    }

                    $rental = Rental::query()->find($target->syncable_id);

                    return $rental === null ? null : $this->rentals->recordCounterPayment($rental, $this->rentalTender($method), $cents, null, $reference);
                }));

                if ($created === null) {
                    continue;
                }

                QuickBooksSync::query()->create([
                    'syncable_type' => $created->getMorphClass(),
                    'syncable_id' => $created->getKey(),
                    'label' => 'Payment from QuickBooks: $'.number_format($cents / 100, 2),
                    'status' => 'synced',
                    'qbo_type' => 'Payment',
                    'qbo_id' => $qboId,
                    'message' => 'Entered in QuickBooks and brought into Yardline.',
                    'meta' => ['pulled' => $key],
                    'synced_at' => now(),
                ]);
                $recorded++;
            }
        }

        return $recorded;
    }

    private function tender(string $method): PaymentTender
    {
        return match (true) {
            str_contains($method, 'cash') => PaymentTender::Cash,
            str_contains($method, 'transfer') || str_contains($method, 'interac') => PaymentTender::ETransfer,
            str_contains($method, 'card') || str_contains($method, 'visa') || str_contains($method, 'master') => PaymentTender::CardTerminal,
            default => PaymentTender::Cheque,
        };
    }

    private function rentalTender(string $method): RentalTender
    {
        return match ($this->tender($method)) {
            PaymentTender::Cash => RentalTender::Cash,
            PaymentTender::ETransfer => RentalTender::ETransfer,
            PaymentTender::CardTerminal => RentalTender::CardTerminal,
            default => RentalTender::Cheque,
        };
    }
}
