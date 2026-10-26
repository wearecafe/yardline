<?php

namespace App\Rentals;

use App\Enums\FuelLevel;
use App\Enums\InspectionKind;
use App\Enums\RentalChargeKind;
use App\Enums\RentalPaymentMethod;
use App\Enums\RentalStatus;
use App\Models\Rental;
use App\Models\RentalItem;
use App\Models\User;
use App\Rentals\Notifications\RentalNotifier;
use App\Rentals\Payments\CardDeclined;
use App\Rentals\Payments\RentalPayments;
use App\Tax\TaxCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Equipment comes back: inspect each unit, add fuel, cleaning, damage and
 * late charges, settle against the deposit (capture what's owed, release
 * the rest), and send the receipt. A unit whose service is now due is
 * locked out of new rentals until the service is logged.
 */
class CheckInRental
{
    public function __construct(
        private readonly RecordInspection $inspections,
        private readonly RecalculateRental $recalculate,
        private readonly RentalPayments $payments,
        private readonly RentalNotifier $notifier,
        private readonly TaxCalculator $tax,
    ) {}

    /**
     * @param  list<InspectionData>  $inspections
     */
    public function handle(Rental $rental, array $inspections, User $user, ?CarbonImmutable $returnedAt = null): Rental
    {
        if ($rental->status !== RentalStatus::Out) {
            RentalRules::fail('Only rentals that are out can be checked in.');
        }

        $returnedAt ??= CarbonImmutable::now();

        DB::transaction(function () use ($rental, $inspections, $user, $returnedAt) {
            $items = $rental->items()->with(['unit', 'product'])->get()->keyBy('id');

            foreach ($inspections as $data) {
                $item = $items->get($data->rentalItemId) ?? RentalRules::fail('That unit isn’t on this rental.');

                $this->inspections->handle($rental, $item, InspectionKind::Return, $data, $user);
                $item->forceFill(['hours_in' => $data->hourMeter])->save();

                $this->chargesFor($rental, $item, $data, $user);
            }

            $this->lateCharge($rental, $returnedAt, $user);

            $rental->forceFill(['status' => RentalStatus::Returned, 'returned_at' => $returnedAt])->save();
            $this->recalculate->handle($rental);

            // The rental is a completed sale now: record it for tax reporting.
            if ($rental->tax_calculation_ref !== null && $rental->tax_transaction_ref === null) {
                $rental->forceFill([
                    'tax_transaction_ref' => $this->tax->commit($rental->tax_calculation_ref, $rental->number),
                ])->save();
            }

            // Extra charges come off the deposit first, then the card on file.
            $this->payments->settleDeposit($rental, $user);
            $rental->refresh();

            if ($rental->balanceCents() > 0 && $rental->payment_method === RentalPaymentMethod::CardOnFile) {
                try {
                    $this->payments->chargeCardOnFile($rental, $user);
                } catch (ValidationException|CardDeclined) {
                    // Leave it owing; it shows on the rental and on Rental payments.
                }
            }
        });

        $rental->refresh();
        $this->notifier->send($rental, 'receipt');

        return $rental;
    }

    private function chargesFor(Rental $rental, RentalItem $item, InspectionData $data, User $user): void
    {
        $product = $item->product;
        $unit = $item->unit;

        if ($rental->bill_fuel_cleaning && $data->fuel !== null && $data->fuel !== FuelLevel::Full && $product->fuel_charge_cents > 0) {
            $cents = $data->fuel === FuelLevel::Half ? intdiv($product->fuel_charge_cents, 2) : $product->fuel_charge_cents;
            $this->charge($rental, $item, RentalChargeKind::Fuel, "Refuel to full, {$unit?->serial}", $cents, false, $user);
        }

        if ($rental->bill_fuel_cleaning && ! $data->clean && $product->cleaning_fee_cents > 0) {
            $this->charge($rental, $item, RentalChargeKind::Cleaning, "Cleaning, {$unit?->serial}", $product->cleaning_fee_cents, false, $user);
        }

        if ($data->damageDescription !== null && $data->damageDescription !== '') {
            $covered = $rental->damage_waiver && $data->damageCoveredByWaiver;
            $label = $data->damageDescription.($covered ? ' · covered by waiver' : '');
            $this->charge($rental, $item, RentalChargeKind::Damage, $label, $data->damageCents, $covered, $user);
        }
    }

    /**
     * Late returns are charged for the extra time at the best rate: the
     * price for the actual time out, less what was already charged.
     */
    private function lateCharge(Rental $rental, CarbonImmutable $returnedAt, User $user): void
    {
        $settings = RentalSettings::for($rental->tenant);
        $due = CarbonImmutable::instance($rental->ends_at)->addMinutes($settings->lateGraceMinutes);

        if ($returnedAt->lessThanOrEqualTo($due)) {
            return;
        }

        $pricer = new RentalPricer($settings);

        foreach ($rental->items()->with('product')->get() as $item) {
            $actual = $pricer->quote($item->product, $rental->starts_at, $returnedAt)->cents;
            $extra = $actual - $item->charge_cents;

            if ($extra > 0) {
                $this->charge($rental, $item, RentalChargeKind::Late, "Late return, {$item->description}", $extra, false, $user);
            }
        }
    }

    private function charge(Rental $rental, RentalItem $item, RentalChargeKind $kind, string $description, int $cents, bool $covered, User $user): void
    {
        $rental->charges()->create([
            'rental_unit_id' => $item->rental_unit_id,
            'kind' => $kind,
            'description' => $description,
            'amount_cents' => max(0, $cents),
            'covered_by_waiver' => $covered,
            'created_by' => $user->id,
        ]);
    }
}
