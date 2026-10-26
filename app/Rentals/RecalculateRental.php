<?php

namespace App\Rentals;

use App\Enums\FulfillmentMethod;
use App\Models\Rental;
use App\Tax\TaxAddress;
use App\Tax\TaxCalculationFailed;
use App\Tax\TaxCalculator;
use App\Tax\TaxLine;
use App\Tax\TaxRequest;

/**
 * Re-prices every item at the best rate for the rental's dates, then adds the
 * damage waiver, delivery and any return charges, and tax.
 */
class RecalculateRental
{
    public function __construct(private readonly TaxCalculator $tax) {}

    /**
     * @param  bool  $requireTax  fail (instead of leaving tax at zero) when tax can't be calculated
     */
    public function handle(Rental $rental, bool $requireTax = false): Rental
    {
        $rental->load(['items.product', 'deliveryZone', 'organization', 'location', 'tenant', 'charges']);
        $pricer = new RentalPricer(RentalSettings::for($rental->tenant));

        foreach ($rental->items as $item) {
            $quote = $pricer->quote($item->product, $rental->starts_at, $rental->ends_at);

            // Late returns are billed as a separate charge; the item keeps
            // the price that was agreed.
            if ($rental->isEditable()) {
                $item->charge_cents = $quote->cents;
                $item->rate_summary = $quote->summary();
                $item->day_rate_equivalent_cents = $quote->dayRateEquivalentCents;
                $item->deposit_cents = $item->product->deposit_cents;
                $item->save();
            }
        }

        $rental->rental_cents = (int) $rental->items->sum('charge_cents');
        $rental->waiver_cents = $rental->damage_waiver
            ? (int) round($rental->rental_cents * ((float) $rental->damage_waiver_percent) / 100)
            : 0;
        $rental->delivery_fee_cents = $rental->fulfillment === FulfillmentMethod::Delivery
            ? (int) ($rental->deliveryZone->fee_cents ?? 0)
            : 0;
        $rental->charges_cents = (int) $rental->charges->sum(fn ($charge) => $charge->billableCents());

        if ($rental->isEditable()) {
            $rental->deposit_cents = (int) $rental->items->sum('deposit_cents');
        }

        $rental->subtotal_cents = $rental->rental_cents + $rental->waiver_cents
            + $rental->delivery_fee_cents + $rental->charges_cents;

        $this->applyTax($rental, $requireTax);

        $rental->total_cents = $rental->subtotal_cents + $rental->tax_cents;
        $rental->save();

        return $rental;
    }

    private function applyTax(Rental $rental, bool $requireTax): void
    {
        $rental->tax_cents = 0;
        $rental->tax_calculation_ref = null;

        if ($rental->subtotal_cents === 0) {
            return;
        }

        $address = $rental->fulfillment === FulfillmentMethod::Delivery && filled($rental->delivery_address)
            ? TaxAddress::fromArray($rental->delivery_address)
            : TaxAddress::fromArray($rental->location->addressForTax());

        $lines = [
            new TaxLine('rental', $rental->rental_cents),
            new TaxLine('waiver', $rental->waiver_cents),
            new TaxLine('delivery', $rental->delivery_fee_cents),
        ];

        foreach ($rental->charges as $charge) {
            $lines[] = new TaxLine('charge_'.$charge->id, $charge->billableCents());
        }

        try {
            $result = $this->tax->calculate(new TaxRequest(
                $rental->tenant->currency,
                $address,
                $lines,
                (bool) $rental->organization?->tax_exempt,
            ));
        } catch (TaxCalculationFailed $e) {
            // A quote can be built before the address is known; reserving
            // and checking out require tax, so they stop here instead.
            if ($requireTax) {
                RentalRules::fail($e->getMessage());
            }

            report($e);

            return;
        }

        $rental->tax_cents = $result->totalCents;
        $rental->tax_calculation_ref = $result->reference;
    }
}
