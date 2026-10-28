<?php

namespace App\Tax;

use App\Payments\Connect\PaymentsNotSetUp;
use App\Support\StripeGateway;
use Stripe\Exception\ApiErrorException;

/**
 * Stripe Tax: Ontario HST, Quebec GST + QST and the rest, based on where the
 * sale happens. A calculation at confirmation, a transaction when committed.
 * Runs on the yard's connected account, with the yard's own registrations.
 */
class StripeTaxCalculator implements TaxCalculator
{
    /** Tax runs on the yard's own connected account, set up with payments. */
    private const NOT_SET_UP = 'Sales tax isn’t set up for this business yet. An owner or admin can set up payments and sales tax under Settings, Payments.';

    public function __construct(private readonly StripeGateway $stripe) {}

    public function calculate(TaxRequest $request): TaxResult
    {
        if (! $request->address->isComplete()) {
            throw new TaxCalculationFailed('Add a postal code to the address so tax can be calculated.');
        }

        $lineItems = [];

        foreach ($request->lines as $line) {
            if ($line->amountCents <= 0) {
                continue;
            }

            $item = [
                'amount' => $line->amountCents,
                'reference' => $line->reference,
                'tax_behavior' => 'exclusive',
            ];

            if (filled($line->taxCode)) {
                $item['tax_code'] = (string) $line->taxCode;
            }

            $lineItems[] = $item;
        }

        if ($lineItems === []) {
            return new TaxResult([], 0);
        }

        $customerDetails = [
            'address' => [
                'line1' => $request->address->line1 ?: null,
                'line2' => $request->address->line2 ?: null,
                'city' => $request->address->city ?: null,
                'state' => $request->address->region ?: null,
                'postal_code' => $request->address->postalCode,
                'country' => $request->address->country,
            ],
            'address_source' => 'shipping',
        ];

        if ($request->exempt) {
            $customerDetails['taxability_override'] = 'customer_exempt';
        }

        try {
            $calculation = $this->stripe->client()->tax->calculations->create([
                'currency' => strtolower($request->currency),
                'line_items' => $lineItems,
                'customer_details' => $customerDetails,
                'expand' => ['line_items'],
            ], $this->stripe->requestOptions());
        } catch (PaymentsNotSetUp $e) {
            throw new TaxCalculationFailed(self::NOT_SET_UP, previous: $e);
        } catch (ApiErrorException $e) {
            throw new TaxCalculationFailed('Stripe Tax could not calculate this sale: '.$e->getMessage(), previous: $e);
        }

        $lines = [];
        foreach ($calculation->line_items->data ?? [] as $item) {
            $lines[(string) $item->reference] = (int) $item->amount_tax;
        }

        return new TaxResult($lines, (int) $calculation->tax_amount_exclusive, $calculation->id);
    }

    public function commit(string $calculationReference, string $saleReference): ?string
    {
        try {
            $transaction = $this->stripe->client()->tax->transactions->createFromCalculation([
                'calculation' => $calculationReference,
                'reference' => $saleReference,
            ], $this->stripe->requestOptions());
        } catch (PaymentsNotSetUp $e) {
            throw new TaxCalculationFailed(self::NOT_SET_UP, previous: $e);
        } catch (ApiErrorException $e) {
            throw new TaxCalculationFailed('Stripe Tax could not record this sale: '.$e->getMessage(), previous: $e);
        }

        return $transaction->id;
    }

    public function reverse(string $transactionReference, string $reversalReference, ?array $lines = null): ?string
    {
        try {
            $client = $this->stripe->client();
            $options = $this->stripe->requestOptions();

            if ($lines === null) {
                $reversal = $client->tax->transactions->createReversal([
                    'mode' => 'full',
                    'original_transaction' => $transactionReference,
                    'reference' => $reversalReference,
                ], $options);

                return $reversal->id;
            }

            // The sale's own line item ids, by the references we gave them.
            $ids = [];
            foreach ($client->tax->transactions->allLineItems($transactionReference, ['limit' => 100], $options)->autoPagingIterator() as $item) {
                $ids[(string) $item->reference] = $item->id;
            }

            $items = [];
            foreach ($lines as $line) {
                if (! isset($ids[$line->lineReference])) {
                    throw new TaxCalculationFailed("Stripe Tax has no line {$line->lineReference} on {$transactionReference}.");
                }

                if ($line->amountCents <= 0 && $line->taxCents <= 0) {
                    continue;
                }

                $items[] = [
                    'original_line_item' => $ids[$line->lineReference],
                    'reference' => $line->reference,
                    'amount' => -$line->amountCents,
                    'amount_tax' => -$line->taxCents,
                ];
            }

            if ($items === []) {
                return null;
            }

            $reversal = $client->tax->transactions->createReversal([
                'mode' => 'partial',
                'original_transaction' => $transactionReference,
                'reference' => $reversalReference,
                'line_items' => $items,
            ], $options);
        } catch (PaymentsNotSetUp $e) {
            throw new TaxCalculationFailed(self::NOT_SET_UP, previous: $e);
        } catch (ApiErrorException $e) {
            throw new TaxCalculationFailed('Stripe Tax could not reverse this sale: '.$e->getMessage(), previous: $e);
        }

        return $reversal->id;
    }
}
