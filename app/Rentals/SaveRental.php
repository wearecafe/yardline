<?php

namespace App\Rentals;

use App\Enums\ProductKind;
use App\Enums\RentalStatus;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\Product;
use App\Models\Rental;
use App\Models\RentalUnit;
use App\Models\Sequence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates or updates a rental quote or reservation: who, when, what, how it
 * gets there and what protection applies. Items are given as product +
 * (optional) unit; a missing unit is picked from what's free.
 */
class SaveRental
{
    public function __construct(
        private readonly RecalculateRental $recalculate,
        private readonly Availability $availability,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{product_id: int, rental_unit_id?: int|null}>  $items
     */
    public function handle(array $data, array $items, User $user, ?Rental $rental = null): Rental
    {
        if ($rental !== null && ! $rental->isEditable()) {
            RentalRules::fail('This rental has gone out and can no longer be changed.');
        }

        if ($items === []) {
            RentalRules::fail('Add at least one piece of equipment.', 'items');
        }

        return DB::transaction(function () use ($data, $items, $user, $rental) {
            $isNew = $rental === null;
            $rental ??= new Rental([
                'number' => 'R-'.Sequence::next('rentals'),
                'created_by' => $user->id,
                'damage_waiver_percent' => RentalSettings::current()->damageWaiverPercent,
            ]);

            // A contact implies their organization when none was picked.
            if (empty($data['organization_id']) && ! empty($data['customer_id'])) {
                $data['organization_id'] = Customer::query()->whereKey($data['customer_id'])->value('organization_id');
            }

            $rental->fill($data);

            if ($rental->fulfillment->value === 'delivery' && empty($data['delivery_zone_id'])) {
                $rental->delivery_zone_id = DeliveryZone::forPostalCode($rental->delivery_address['postal_code'] ?? null)?->id;
            }

            if ($rental->ends_at->lessThanOrEqualTo($rental->starts_at)) {
                RentalRules::fail('The return has to be after the start.', 'ends_at');
            }

            $rental->save();

            $this->syncItems($rental, $items);

            if ($isNew) {
                $rental->status = RentalStatus::Quote;
                $rental->signing_token = Str::random(48);
                $rental->save();
            }

            return $this->recalculate->handle($rental);
        });
    }

    /**
     * @param  list<array{product_id: int, rental_unit_id?: int|null}>  $items
     */
    private function syncItems(Rental $rental, array $items): void
    {
        $rental->items()->delete();

        $start = CarbonImmutable::instance($rental->starts_at);
        $end = CarbonImmutable::instance($rental->ends_at);
        $taken = [];

        foreach (array_values($items) as $position => $item) {
            $product = Product::query()->findOrFail((int) $item['product_id']);

            if ($product->kind !== ProductKind::Rental) {
                RentalRules::fail("{$product->name} isn't rental equipment.", 'items');
            }

            $unit = null;

            if (! empty($item['rental_unit_id'])) {
                $unit = RentalUnit::query()->where('product_id', $product->id)->findOrFail((int) $item['rental_unit_id']);
            } else {
                $unit = $this->availability
                    ->freeUnits($product, $start, $end, $rental->location_id, $rental->id)
                    ->first(fn (RentalUnit $unit) => ! in_array($unit->id, $taken, true));
            }

            if ($unit !== null) {
                $taken[] = $unit->id;
            }

            $rental->items()->create([
                'product_id' => $product->id,
                'rental_unit_id' => $unit?->id,
                'description' => $product->name,
                'position' => $position,
            ]);
        }
    }
}
