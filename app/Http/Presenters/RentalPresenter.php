<?php

namespace App\Http\Presenters;

use App\Enums\DocumentKind;
use App\Models\Customer;
use App\Models\Rental;
use App\Models\RentalUnit;
use App\Support\Files;

final class RentalPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(Rental $rental): array
    {
        return [
            'id' => $rental->id,
            'number' => $rental->number,
            'status' => $rental->displayStatus(),
            'customer' => $rental->customerName(),
            'equipment' => $rental->items->map(fn ($item) => trim($item->description.($item->unit ? " · {$item->unit->serial}" : '')))->implode(', '),
            'starts_at' => $rental->starts_at->toIso8601String(),
            'ends_at' => $rental->ends_at->toIso8601String(),
            'total_cents' => $rental->total_cents,
            'balance_cents' => $rental->balanceCents(),
            'signed' => $rental->isSigned(),
            'source' => $rental->source,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Rental $rental): array
    {
        $rental->loadMissing([
            'items.unit', 'items.product', 'customer.documents', 'organization', 'location',
            'deliveryZone', 'agreement', 'charges', 'payments.recordedBy', 'inspections.photos', 'inspections.unit',
        ]);

        return [
            ...$rental->only([
                'id', 'number', 'source', 'organization_id', 'customer_id', 'location_id',
                'fulfillment', 'delivery_address', 'delivery_zone_id', 'damage_waiver',
                'damage_waiver_percent', 'bill_fuel_cleaning', 'text_reminders',
                'rental_cents', 'waiver_cents', 'delivery_fee_cents', 'charges_cents',
                'subtotal_cents', 'tax_cents', 'total_cents', 'amount_paid_cents',
                'deposit_cents', 'deposit_status', 'deposit_captured_cents', 'payment_method', 'notes',
            ]),
            'status' => $rental->displayStatus(),
            'editable' => $rental->isEditable(),
            'starts_at' => $rental->starts_at->toIso8601String(),
            'ends_at' => $rental->ends_at->toIso8601String(),
            'checked_out_at' => $rental->checked_out_at?->toIso8601String(),
            'returned_at' => $rental->returned_at?->toIso8601String(),
            'balance_cents' => $rental->balanceCents(),
            'customer' => $rental->customer ? self::customer($rental->customer) : null,
            'organization' => $rental->organization?->only('id', 'name', 'tax_exempt'),
            'location' => $rental->location->only('id', 'name'),
            'delivery_zone' => $rental->deliveryZone?->only('id', 'name', 'fee_cents'),
            'items' => $rental->items->sortBy('position')->values()->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'rental_unit_id' => $item->rental_unit_id,
                'description' => $item->description,
                'serial' => $item->unit?->serial,
                'rate_summary' => $item->rate_summary,
                'charge_cents' => $item->charge_cents,
                'day_rate_equivalent_cents' => $item->day_rate_equivalent_cents,
                'deposit_cents' => $item->deposit_cents,
                'hours_out' => $item->hours_out,
                'hours_in' => $item->hours_in,
                'hour_meter' => $item->unit?->hour_meter,
                'fuel_charge_cents' => $item->product->fuel_charge_cents,
                'cleaning_fee_cents' => $item->product->cleaning_fee_cents,
            ]),
            'charges' => $rental->charges->map(fn ($charge) => [
                ...$charge->only('id', 'kind', 'description', 'amount_cents', 'covered_by_waiver'),
            ]),
            'payments' => $rental->payments->sortByDesc('id')->values()->map(fn ($payment) => [
                ...$payment->only('id', 'kind', 'tender', 'status', 'amount_cents', 'reference', 'checkout_url'),
                'succeeded_at' => $payment->succeeded_at?->toIso8601String(),
                'created_at' => $payment->created_at->toIso8601String(),
                'recorded_by' => $payment->recordedBy?->name,
            ]),
            'agreement' => $rental->agreement ? [
                'signed_at' => $rental->agreement->signed_at?->toIso8601String(),
                'signer_name' => $rental->agreement->signer_name,
                'signed_via' => $rental->agreement->signed_via,
                'pdf_url' => Files::url($rental->agreement->pdf_path),
            ] : null,
            'inspections' => $rental->inspections->sortBy('id')->values()->map(fn ($inspection) => [
                'id' => $inspection->id,
                'kind' => $inspection->kind,
                'serial' => $inspection->unit->serial,
                'hour_meter' => $inspection->hour_meter,
                'fuel' => $inspection->fuel,
                'clean' => $inspection->clean,
                'notes' => $inspection->notes,
                'created_at' => $inspection->created_at?->toIso8601String(),
                'photos' => $inspection->photos->map(fn ($photo) => [
                    'id' => $photo->id,
                    'url' => Files::url($photo->path),
                    'label' => $photo->label,
                    'new_damage' => $photo->new_damage,
                ]),
            ]),
            'signing_url' => $rental->signing_token ? route('rentals.public-sign', $rental->signing_token) : null,
        ];
    }

    /**
     * A customer with the chips shown on the New rental screen: licence and
     * insurance on file, card on file.
     *
     * @return array<string, mixed>
     */
    public static function customer(Customer $customer): array
    {
        $customer->loadMissing('documents');
        $licence = $customer->documents
            ->where('kind', DocumentKind::DriversLicence)
            ->filter->isCurrent()
            ->sortByDesc('expires_on')
            ->first();
        $insurance = $customer->documents
            ->where('kind', DocumentKind::InsuranceCertificate)
            ->filter->isCurrent()
            ->sortByDesc('expires_on')
            ->first();

        return [
            'id' => $customer->id,
            'organization_id' => $customer->organization_id,
            'name' => $customer->full_name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'card' => $customer->cardLabel(),
            'licence' => $licence ? ['id' => $licence->id, 'expires_on' => $licence->expires_on?->toDateString()] : null,
            'insurance_until' => $insurance?->expires_on?->toDateString() ?? ($insurance ? 'on file' : null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function unit(RentalUnit $unit): array
    {
        return [
            'id' => $unit->id,
            'product_id' => $unit->product_id,
            'serial' => $unit->serial,
            'name' => $unit->product->name,
            'status' => $unit->status,
            'rentable' => $unit->rentable(),
            'service_due' => $unit->serviceDue(),
        ];
    }
}
