<?php

namespace App\Tax;

use App\Enums\OrderStatus;
use App\Models\CreditNote;
use App\Models\CreditNoteLine;
use App\Models\Order;

/**
 * Gives tax back to the tax provider when a sale is undone, so its reports
 * match the tax the yard kept: the whole sale when an order is cancelled,
 * the credited part of each line when a credit note is issued. Each
 * reversal's reference is stored, so it's only ever made once. Flat-rate
 * tax records no sales, so there's nothing to reverse.
 */
class ReverseSalesTax
{
    public function __construct(private readonly TaxCalculator $tax) {}

    public function order(Order $order): void
    {
        if ($order->status !== OrderStatus::Cancelled || $order->tax_transaction_ref === null || $order->tax_reversal_ref !== null) {
            return;
        }

        $reference = $this->tax->reverse($order->tax_transaction_ref, "{$order->number}-cancelled");

        if ($reference !== null) {
            $order->forceFill(['tax_reversal_ref' => $reference])->saveQuietly();
        }
    }

    public function creditNote(CreditNote $credit): void
    {
        $order = $credit->order;

        if ($order === null || $order->tax_transaction_ref === null || $credit->tax_reversal_ref !== null || $credit->tax_cents <= 0) {
            return;
        }

        $lines = self::lines($credit);

        if ($lines === []) {
            return;
        }

        $reference = $this->tax->reverse($order->tax_transaction_ref, $credit->number, $lines);

        if ($reference !== null) {
            $credit->forceFill(['tax_reversal_ref' => $reference])->saveQuietly();
        }
    }

    /**
     * What a return credits on each line of the original sale: the goods,
     * less their share of the restocking fee and its tax, and any pallet
     * usage fee credited with them. Adds up to the credit's tax exactly.
     *
     * @return list<TaxReversalLine>
     */
    public static function lines(CreditNote $credit): array
    {
        $goods = $credit->lines->filter(fn (CreditNoteLine $l) => $l->order_line_id !== null)->values();

        if ($goods->isEmpty()) {
            return [];
        }

        // The restocking fee and its tax, spread over the restocked lines.
        $restocked = $goods->filter(fn (CreditNoteLine $l) => $l->restock);
        $feeTax = $credit->subtotal_cents > 0 ? (int) round((int) $goods->sum('tax_cents') * $credit->restocking_fee_cents / $credit->subtotal_cents) : 0;
        $fees = self::spread($credit->restocking_fee_cents, $restocked->mapWithKeys(fn (CreditNoteLine $l) => [$l->id => $l->line_total_cents])->all());
        $feeTaxes = self::spread($feeTax, $fees);

        $lines = [];

        foreach ($goods as $line) {
            $lines[] = new TaxReversalLine(
                "line_{$line->order_line_id}",
                $line->line_total_cents - ($fees[$line->id] ?? 0),
                $line->tax_cents - ($feeTaxes[$line->id] ?? 0),
                "{$credit->number}-{$line->id}",
            );

            if ($line->pallet_fee_cents > 0) {
                $lines[] = new TaxReversalLine(
                    "pallet_fee_{$line->order_line_id}",
                    $line->pallet_fee_cents,
                    $line->pallet_fee_tax_cents,
                    "{$credit->number}-{$line->id}-pallet-fee",
                );
            }
        }

        return $lines;
    }

    /**
     * Splits an amount across weights, rounding so the parts add up to it.
     *
     * @param  array<int, int>  $weights
     * @return array<int, int>
     */
    private static function spread(int $cents, array $weights): array
    {
        $total = array_sum($weights);

        if ($cents === 0 || $total <= 0) {
            return array_map(fn () => 0, $weights);
        }

        $parts = [];
        $remainders = [];

        foreach ($weights as $key => $weight) {
            $exact = $cents * $weight / $total;
            $parts[$key] = (int) floor($exact);
            $remainders[$key] = $exact - $parts[$key];
        }

        arsort($remainders);

        foreach (array_keys($remainders) as $key) {
            if (array_sum($parts) >= $cents) {
                break;
            }

            $parts[$key]++;
        }

        return $parts;
    }
}
