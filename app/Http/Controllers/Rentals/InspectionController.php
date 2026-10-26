<?php

namespace App\Http\Controllers\Rentals;

use App\Enums\FuelLevel;
use App\Enums\InspectionKind;
use App\Enums\RentalTender;
use App\Http\Controllers\Controller;
use App\Http\Presenters\RentalPresenter;
use App\Models\Rental;
use App\Rentals\CheckInRental;
use App\Rentals\CheckOutRental;
use App\Rentals\InspectionData;
use App\Rentals\RentalSettings;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The mobile check-out and return inspection: photos, hour meter, fuel,
 * cleanliness, damage. Built for a phone in the yard.
 */
class InspectionController extends Controller
{
    public function show(Rental $rental, string $kind): Response
    {
        $inspectionKind = $kind === 'return' ? InspectionKind::Return : InspectionKind::CheckOut;
        $rental->load(['inspections.photos', 'inspections.unit', 'items.unit']);

        $previous = $rental->inspections
            ->where('kind', InspectionKind::CheckOut)
            ->keyBy('rental_unit_id');

        return Inertia::render('kiosk/inspect', [
            'kind' => $inspectionKind->value,
            'rental' => RentalPresenter::detail($rental),
            'checkOut' => $previous->map(fn ($inspection) => [
                'hour_meter' => $inspection->hour_meter,
                'fuel' => $inspection->fuel,
                'photo_count' => $inspection->photos->count(),
            ]),
            'lateGraceMinutes' => RentalSettings::current()->lateGraceMinutes,
            'service' => $rental->items->mapWithKeys(fn ($item) => [$item->id => $item->unit ? [
                'hours_until' => $item->unit->hoursUntilService(),
                'interval' => $item->unit->serviceIntervalHours(),
            ] : null]),
        ]);
    }

    public function store(Request $request, Rental $rental, string $kind, CheckOutRental $checkOut, CheckInRental $checkIn): RedirectResponse
    {
        $data = $request->validate([
            'units' => ['required', 'array', 'min:1'],
            'units.*.rental_item_id' => ['required', 'integer'],
            'units.*.hour_meter' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'units.*.fuel' => ['nullable', Rule::enum(FuelLevel::class)],
            'units.*.clean' => ['boolean'],
            'units.*.notes' => ['nullable', 'string', 'max:2000'],
            'units.*.photos' => ['array', 'max:12'],
            'units.*.photos.*.file' => ['required', 'image', 'max:12288'],
            'units.*.photos.*.label' => ['nullable', 'string', 'max:100'],
            'units.*.photos.*.new_damage' => ['boolean'],
            'units.*.damage_description' => ['nullable', 'string', 'max:255'],
            'units.*.damage_amount' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'units.*.damage_covered' => ['boolean'],
            'deposit_tender' => ['nullable', Rule::enum(RentalTender::class)->only([RentalTender::CardOnFile, RentalTender::Cash, RentalTender::Cheque, RentalTender::CardTerminal])],
        ]);

        $inspections = array_map(fn (array $unit) => new InspectionData(
            rentalItemId: (int) $unit['rental_item_id'],
            hourMeter: isset($unit['hour_meter']) ? (float) $unit['hour_meter'] : null,
            fuel: isset($unit['fuel']) ? FuelLevel::from($unit['fuel']) : null,
            clean: (bool) ($unit['clean'] ?? true),
            notes: $unit['notes'] ?? null,
            photos: array_values(array_map(fn (array $photo) => [
                'file' => $photo['file'] instanceof UploadedFile ? $photo['file'] : abort(422),
                'label' => $photo['label'] ?? null,
                'new_damage' => (bool) ($photo['new_damage'] ?? false),
            ], $unit['photos'] ?? [])),
            damageDescription: $unit['damage_description'] ?? null,
            damageCents: (int) (Money::parse($unit['damage_amount'] ?? null) ?? 0),
            damageCoveredByWaiver: (bool) ($unit['damage_covered'] ?? false),
        ), array_values($data['units']));

        if ($kind === 'return') {
            $checkIn->handle($rental, $inspections, $request->user());
            $message = "{$rental->number} checked in.";
        } else {
            $checkOut->handle($rental, $inspections, $request->user(), isset($data['deposit_tender']) ? RentalTender::from($data['deposit_tender']) : null);
            $message = "{$rental->number} is out on rent.";
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('rentals.show', $rental);
    }
}
