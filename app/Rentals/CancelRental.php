<?php

namespace App\Rentals;

use App\Enums\RentalStatus;
use App\Fulfillment\RentalStops;
use App\Models\Rental;
use App\Models\User;
use App\Rentals\Payments\RentalPayments;
use Illuminate\Support\Facades\DB;

class CancelRental
{
    public function __construct(private readonly RentalPayments $payments) {}

    public function handle(Rental $rental, User $user): Rental
    {
        if (! $rental->isEditable()) {
            RentalRules::fail('Only quotes and reservations can be cancelled. Check equipment that’s out back in instead.');
        }

        return DB::transaction(function () use ($rental, $user) {
            $this->payments->refundAll($rental, $user);

            $rental->forceFill(['status' => RentalStatus::Cancelled, 'cancelled_at' => now()])->save();
            app(RentalStops::class)->sync($rental);

            return $rental;
        });
    }
}
