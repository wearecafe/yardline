<?php

namespace App\Rentals;

use App\Enums\InspectionKind;
use App\Enums\RentalPaymentMethod;
use App\Enums\RentalStatus;
use App\Enums\RentalTender;
use App\Models\Rental;
use App\Models\User;
use App\Rentals\Payments\RentalPayments;
use Illuminate\Support\Facades\DB;

/**
 * Equipment leaves the yard: the agreement is signed, the rental is paid in
 * full, the deposit is held, and each unit is inspected and photographed.
 */
class CheckOutRental
{
    public function __construct(
        private readonly ReserveRental $reserve,
        private readonly RentalPayments $payments,
        private readonly RecordInspection $inspections,
        private readonly RecalculateRental $recalculate,
    ) {}

    /**
     * @param  list<InspectionData>  $inspections
     */
    public function handle(Rental $rental, array $inspections, User $user, ?RentalTender $depositTender = null): Rental
    {
        if ($rental->status !== RentalStatus::Reserved) {
            RentalRules::fail('Only reserved rentals can go out.');
        }

        if (! $rental->isSigned()) {
            RentalRules::fail('The agreement has to be signed before the equipment goes out.');
        }

        $this->reserve->guardUnits($rental);

        return DB::transaction(function () use ($rental, $inspections, $user, $depositTender) {
            $this->recalculate->handle($rental, requireTax: true);

            if ($rental->balanceCents() > 0 && $rental->payment_method === RentalPaymentMethod::CardOnFile) {
                $this->payments->chargeCardOnFile($rental, $user);
            }

            if ($rental->balanceCents() > 0) {
                RentalRules::fail('Take payment before the equipment goes out. Every rental is prepaid.', 'payment');
            }

            $this->payments->holdDeposit($rental, $user, $depositTender);

            $items = $rental->items()->with('unit')->get()->keyBy('id');

            foreach ($inspections as $data) {
                $item = $items->get($data->rentalItemId) ?? RentalRules::fail('That unit isn’t on this rental.');
                $this->inspections->handle($rental, $item, InspectionKind::CheckOut, $data, $user);
                $item->forceFill(['hours_out' => $data->hourMeter])->save();
            }

            $rental->forceFill([
                'status' => RentalStatus::Out,
                'checked_out_at' => now(),
            ])->save();

            return $rental;
        });
    }
}
