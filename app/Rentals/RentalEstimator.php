<?php

namespace App\Rentals;

use App\Models\DeliveryZone;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Product;
use App\Models\RentalUnit;
use App\Support\Money;
use App\Tax\TaxAddress;
use App\Tax\TaxCalculationFailed;
use App\Tax\TaxCalculator;
use App\Tax\TaxLine;
use App\Tax\TaxRequest;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;

/**
 * Prices a rental that hasn't been saved: the live summary on the New
 * rental screen and the public booking page.
 */
class RentalEstimator
{
    public function __construct(
        private readonly Availability $availability,
        private readonly TaxCalculator $tax,
    ) {}

    /**
     * @param  list<array{product_id: int, rental_unit_id?: int|null}>  $items
     * @param  array{line1?: string|null, city?: string|null, region?: string|null, postal_code?: string|null, country?: string|null}|null  $deliveryAddress
     * @return array<string, mixed>
     */
    public function estimate(
        array $items,
        CarbonImmutable $start,
        CarbonImmutable $end,
        ?int $locationId,
        bool $delivery,
        ?array $deliveryAddress,
        ?int $deliveryZoneId,
        bool $damageWaiver,
        ?int $organizationId = null,
        ?int $ignoreRentalId = null,
        ?float $waiverPercent = null,
    ): array {
        $settings = RentalSettings::current();
        $pricer = new RentalPricer($settings);
        $waiverPercent ??= $settings->damageWaiverPercent;
        $lines = [];
        $problems = [];
        $notes = [];
        $taken = [];

        if ($end->lessThanOrEqualTo($start)) {
            return ['valid' => false, 'problems' => ['The return has to be after the start.']];
        }

        foreach ($items as $item) {
            $product = Product::query()->find((int) $item['product_id']);

            if ($product === null) {
                continue;
            }

            $quote = $pricer->quote($product, $start, $end);
            $unit = ! empty($item['rental_unit_id'])
                ? RentalUnit::query()->with('product')->find((int) $item['rental_unit_id'])
                : $this->availability->freeUnits($product, $start, $end, $locationId, $ignoreRentalId)
                    ->first(fn (RentalUnit $unit) => ! in_array($unit->id, $taken, true));

            $problem = $unit === null
                ? "No {$product->name} is free for these dates."
                : $this->availability->problem($unit, $start, $end, $ignoreRentalId);

            if ($unit !== null) {
                $taken[] = $unit->id;
            }

            if ($problem !== null) {
                $problems[] = $problem;
            }

            if ($quote->beatsDailyRate()) {
                $notes[] = "{$quote->summary()} rate applied to {$product->name}: it’s cheaper than {$quote->billableDays} daily rates.";
            }

            if ($quote->freeSundayApplied) {
                $notes[] = 'Sunday is free when you rent Saturday.';
            }

            $lines[] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'rental_unit_id' => $unit?->id,
                'serial' => $unit?->serial,
                'rate_summary' => $quote->summary(),
                'billable_days' => $quote->billableDays,
                'charge_cents' => $quote->cents,
                'day_rate_cents' => $product->price_cents,
                'day_rate_equivalent_cents' => $quote->dayRateEquivalentCents,
                'deposit_cents' => $product->deposit_cents,
            ];
        }

        $rental = array_sum(array_column($lines, 'charge_cents'));
        $waiver = $damageWaiver ? (int) round($rental * $waiverPercent / 100) : 0;

        $zone = null;
        if ($delivery) {
            $zone = $deliveryZoneId ? DeliveryZone::query()->find($deliveryZoneId) : DeliveryZone::forPostalCode($deliveryAddress['postal_code'] ?? null);
        }
        $deliveryFee = $zone->fee_cents ?? 0;
        $subtotal = $rental + $waiver + $deliveryFee;

        $location = $locationId ? Location::query()->find($locationId) : Location::query()->where('is_default', true)->first();
        $address = $delivery && filled($deliveryAddress['postal_code'] ?? null)
            ? TaxAddress::fromArray($deliveryAddress)
            : TaxAddress::fromArray($location?->addressForTax());

        $tax = 0;
        $taxNote = null;
        try {
            $tax = $this->tax->calculate(new TaxRequest(
                CurrentTenant::get()->currency ?? 'CAD',
                $address,
                [new TaxLine('rental', $rental), new TaxLine('waiver', $waiver), new TaxLine('delivery', $deliveryFee)],
                (bool) ($organizationId ? Organization::query()->whereKey($organizationId)->value('tax_exempt') : false),
            ))->totalCents;
        } catch (TaxCalculationFailed $e) {
            $taxNote = $e->getMessage();
        }

        return [
            'valid' => true,
            'available' => $problems === [] && $lines !== [],
            'problems' => array_values(array_unique($problems)),
            'notes' => array_values(array_unique($notes)),
            'lines' => $lines,
            'rental_cents' => $rental,
            'waiver_percent' => $waiverPercent,
            'waiver_cents' => $waiver,
            'delivery_zone' => $zone ? ['id' => $zone->id, 'name' => $zone->name] : null,
            'delivery_fee_cents' => $deliveryFee,
            'subtotal_cents' => $subtotal,
            'tax_cents' => $tax,
            'tax_note' => $taxNote,
            'total_cents' => $subtotal + $tax,
            'deposit_cents' => array_sum(array_column($lines, 'deposit_cents')),
            'total' => Money::format($subtotal + $tax),
        ];
    }
}
