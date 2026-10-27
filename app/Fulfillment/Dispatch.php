<?php

namespace App\Fulfillment;

use App\Enums\ShipmentStatus;
use App\Enums\StopStatus;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Shipment;
use App\Models\Stop;
use App\Models\Truck;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\EtaMessage;
use App\Orders\OrderRules;
use App\Support\Demo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * The dispatch board's moves: put a stop on a truck at a time, send it on
 * its way, and text customers their ETA.
 */
class Dispatch
{
    public function schedule(Stop $stop, ?Truck $truck, ?CarbonImmutable $date, ?string $time, ?int $minutes = null): Stop
    {
        if (in_array($stop->status, [StopStatus::Done, StopStatus::Cancelled], true)) {
            OrderRules::fail('That stop is finished.');
        }

        $scheduled = $truck !== null && $date !== null && $time !== null;

        $stop->forceFill([
            'truck_id' => $truck?->id,
            'date' => $date?->toDateString() ?? $stop->date?->toDateString(),
            'starts_at' => $scheduled ? $time : null,
            'duration_minutes' => $minutes ?? $stop->duration_minutes,
            'status' => $stop->status === StopStatus::EnRoute ? StopStatus::EnRoute : ($scheduled ? StopStatus::Scheduled : StopStatus::Unscheduled),
        ])->save();

        return $stop;
    }

    public function enRoute(Stop $stop, ?CarbonImmutable $eta = null): Stop
    {
        if ($stop->status !== StopStatus::Scheduled) {
            OrderRules::fail('Schedule the stop on a truck first.');
        }

        if ($stop->stoppable instanceof Order) {
            OrderRules::fail('Load the order before the truck leaves.');
        }

        $stop->forceFill([
            'status' => StopStatus::EnRoute,
            'eta_at' => $eta ?? $this->plannedStart($stop),
        ])->save();

        // Out for delivery is a stage the business can switch off.
        if ($stop->stoppable instanceof Shipment
            && in_array($stop->stoppable->status, [ShipmentStatus::Loaded, ShipmentStatus::Ready], true)
            && PickingSettings::current()->stageOn('out')) {
            $stop->stoppable->forceFill(['status' => ShipmentStatus::Out])->save();
        }

        return $stop;
    }

    /**
     * Texts each customer with a stop on the day their window, once.
     *
     * @return int how many were sent
     */
    public function sendEtas(CarbonImmutable $date): int
    {
        $sent = 0;

        $stops = Stop::query()
            ->with('stoppable')
            ->whereDate('date', $date->toDateString())
            ->whereIn('status', [StopStatus::Scheduled->value, StopStatus::EnRoute->value])
            ->whereNull('eta_sent_at')
            ->get();

        foreach ($stops as $stop) {
            $customer = match (true) {
                $stop->stoppable instanceof Order => $stop->stoppable->customer,
                $stop->stoppable instanceof Shipment => $stop->stoppable->order->customer,
                $stop->stoppable instanceof Organization => null,
                default => $stop->stoppable->customer,
            };

            if ($customer === null || blank($customer->phone)) {
                continue;
            }

            try {
                if (! Demo::isCurrentTenant()) {
                    Notification::sendNow($customer, new EtaMessage($stop), [SmsChannel::class]);
                }
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            $stop->forceFill(['eta_sent_at' => now()])->save();
            $sent++;
        }

        return $sent;
    }

    public function plannedStart(Stop $stop): ?CarbonImmutable
    {
        if ($stop->date === null || $stop->starts_at === null) {
            return null;
        }

        $tz = $stop->tenant->timezone ?? 'America/Toronto';

        return CarbonImmutable::parse($stop->date->toDateString().' '.$stop->starts_at, $tz);
    }
}
