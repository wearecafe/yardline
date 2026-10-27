<?php

namespace App\Fulfillment;

use App\Enums\ShipmentStatus;
use App\Enums\StopStatus;
use App\Models\Shipment;
use App\Orders\OrderRules;
use App\Support\Files;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * Proof of delivery or pickup: who took it, their signature, a photo of
 * the drop, and notes.
 */
class CompleteShipment
{
    public function handle(Shipment $shipment, string $name, ?string $signatureDataUrl = null, ?UploadedFile $photo = null, ?string $notes = null): Shipment
    {
        if ($shipment->status->isDone()) {
            OrderRules::fail('This load is already complete.');
        }

        if (! $shipment->isReleased()) {
            OrderRules::fail($shipment->awaitingCheck() ? 'This load is waiting for a quality check.' : 'Load it before it can be delivered.');
        }

        $signature = null;

        if (filled($signatureDataUrl)) {
            try {
                $signature = Files::storeDataUrl((string) $signatureDataUrl, 'pod');
            } catch (InvalidArgumentException) {
                OrderRules::fail('The signature didn’t come through. Try again.', 'signature');
            }
        }

        $shipment->forceFill([
            'status' => $shipment->isDelivery() ? ShipmentStatus::Delivered : ShipmentStatus::PickedUp,
            'delivered_at' => now(),
            'pod_name' => $name,
            'pod_signature_path' => $signature,
            'pod_photo_path' => $photo !== null ? Files::storeUpload($photo, 'pod') : null,
            'pod_notes' => $notes,
        ])->save();

        $shipment->stop?->forceFill(['status' => StopStatus::Done, 'completed_at' => now()])->save();

        $order = $shipment->order;
        $allDone = $order->lines()->get()->every(fn ($l) => $l->remainingQuantity() <= 0)
            && $order->shipments()->get()->every(fn (Shipment $s) => $s->status->isDone());

        if ($allDone) {
            $order->forceFill(['fulfillment_status' => 'delivered'])->save();
        }

        return $shipment;
    }
}
