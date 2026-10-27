<?php

namespace App\Http\Controllers;

use App\Enums\ShipmentStatus;
use App\Fulfillment\CompleteShipment;
use App\Fulfillment\DriverRun;
use App\Http\Presenters\FulfillmentPresenter;
use App\Models\Shipment;
use App\Models\StockLevel;
use App\Orders\OrderRules;
use App\Support\Files;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The driver's (or counter's) hand-over screen: proof of delivery or
 * pickup with a name, signature and photo.
 */
class DeliveryController extends Controller
{
    public function show(Request $request, Shipment $shipment, DriverRun $run): Response
    {
        abort_unless($run->canDeliver($request->user(), $shipment), 404);

        $shipment->load(['order.organization:id,name', 'order.customer', 'order.organizationLocation', 'order.location', 'lines.orderLine.productUnit', 'lines.orderLine.product:id,weight_kg,track_lots', 'lines.lot', 'stop.truck', 'checks.user:id,name']);
        $order = $shipment->order;
        $levels = StockLevel::query()->where('location_id', $order->location_id)->get()->keyBy('product_id');

        return Inertia::render('kiosk/deliver', [
            'shipment' => [
                'id' => $shipment->id,
                'number' => $shipment->number,
                'status' => $shipment->status->value,
                'status_label' => $shipment->status->label(),
                'delivery' => $shipment->isDelivery(),
                'order_number' => $order->number,
                'order_id' => $order->id,
                'customer' => $order->organization->name ?? $order->customer->full_name ?? 'Walk-in',
                'contact' => $order->customer ? ['name' => $order->customer->full_name, 'phone' => $order->customer->phone] : null,
                'address' => $order->delivery_address,
                'site_notes' => $order->organizationLocation?->delivery_notes,
                'po_number' => $order->po_number,
                'truck' => $shipment->stop?->truck?->name,
                'delivered_at' => $shipment->delivered_at?->toIso8601String(),
                'pod_name' => $shipment->pod_name,
                'pod_signature_url' => Files::url($shipment->pod_signature_path),
                'pod_photo_url' => Files::url($shipment->pod_photo_path),
                'pod_notes' => $shipment->pod_notes,
                'released' => $shipment->isReleased(),
                'checked' => collect(FulfillmentPresenter::signOffs($shipment))
                    ->where('outcome', 'signed_off')
                    ->map(fn (array $c) => "{$c['stage']} checked by ".($c['by'] ?? 'someone'))
                    ->values(),
                // Drivers go back to the stop on their run.
                'stop_id' => $request->user()?->isDriver() ? $shipment->stop?->id : null,
            ],
            ...FulfillmentPresenter::pickList($order, $shipment, $levels),
        ]);
    }

    public function complete(Request $request, Shipment $shipment, CompleteShipment $complete, DriverRun $run): RedirectResponse
    {
        abort_unless($run->canDeliver($request->user(), $shipment), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'signature' => ['nullable', 'string', 'starts_with:data:image/png;base64,', 'max:2800000'],
            'photo' => ['nullable', 'image', 'max:12288'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($shipment->isDelivery() && blank($data['signature'] ?? null) && ! $request->hasFile('photo')) {
            OrderRules::fail('Get a signature, or a photo of the drop if nobody is there.', 'signature');
        }

        $complete->handle($shipment, $data['name'], $data['signature'] ?? null, $request->file('photo'), $data['notes'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => $shipment->status === ShipmentStatus::Delivered ? "{$shipment->number} delivered." : "{$shipment->number} picked up."]);

        return back();
    }
}
