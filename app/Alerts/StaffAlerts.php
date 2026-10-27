<?php

namespace App\Alerts;

use App\Enums\FulfillmentMethod;
use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Enums\StopStatus;
use App\Enums\UserRole;
use App\Fulfillment\DriverRun;
use App\Fulfillment\PickingFlow;
use App\Fulfillment\PickingSettings;
use App\Models\Order;
use App\Models\Rental;
use App\Models\Shipment;
use App\Models\Stop;
use App\Models\Truck;
use App\Models\User;
use App\Notifications\Channels\WebPushMessage;
use App\Notifications\StaffAlert;
use App\Support\Demo;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Alerts on staff phones and tablets (web push), for the moments someone
 * should look up from what they're doing:
 *
 * - Yard crew: a new order lands in their yard's pick queue.
 * - Whoever may sign off: a load is waiting for a quality check. The
 *   picker hears back if it's rejected.
 * - Drivers: they're put on a truck for a day, or a stop is added to
 *   today's run.
 * - Rental desk: a rental at their yard is overdue (once a day).
 *
 * Each goes only to people whose roles and yards cover it, and who turned
 * alerts on (Settings → Alerts). They're worked out after the change
 * commits, so a counter sale that's picked up on the spot never reaches
 * the queue. Never prices: Yard crew and Drivers don't see them.
 */
class StaffAlerts
{
    /**
     * What this person's roles get alerts for, in words (Settings → Alerts).
     *
     * @return list<string>
     */
    public static function for(User $user): array
    {
        $alerts = [];

        if ($user->hasRole(UserRole::YardCrew)) {
            $alerts[] = 'New orders in the pick queue at your yards';
            $alerts[] = 'A quality check on your work is rejected';
        }

        $settings = PickingSettings::current();
        $checks = array_filter(PickingSettings::CHECKED, fn (string $stage) => $settings->requiresCheck($stage)
            && ($user->hasRole(...$settings->checkRoles($stage)) || $user->hasPermission(Permission::QualityCheckSignOff)));

        if ($checks !== []) {
            $alerts[] = 'Loads waiting for a quality check you can sign off';
        }

        if ($user->hasRole(UserRole::Driver)) {
            $alerts[] = 'You’re put on a truck for a day';
            $alerts[] = 'A stop is added to today’s run';
        }

        if ($user->hasRole(UserRole::RentalDesk) && CurrentTenant::get()?->rentals_enabled) {
            $alerts[] = 'A rental at your yards is overdue';
        }

        return $alerts;
    }

    /**
     * A confirmed order that's waiting in the yard's pick queue.
     */
    public function orderPlaced(Order $order): void
    {
        $this->afterCommit(function () use ($order) {
            $order->refresh()->loadMissing(['lines', 'organization:id,name', 'customer:id,first_name,last_name']);

            $lines = $order->lines->filter(fn ($line) => $line->remainingQuantity() > 0)->count();

            if ($order->status !== OrderStatus::Confirmed || $lines === 0 || $order->shipments()->inYard()->exists()) {
                return;
            }

            $how = $order->fulfillment === FulfillmentMethod::Delivery ? 'Delivery' : 'Pickup';
            $when = $order->requested_on !== null ? ' '.$this->day($order->requested_on->toDateString()) : '';

            $this->send(
                $this->staff(fn (User $user) => $user->hasRole(UserRole::YardCrew) && $user->worksAt($order->location_id)),
                'order_to_pick',
                new WebPushMessage(
                    title: 'New order to pick',
                    body: "{$order->number} · {$this->who($order)} · ".($lines === 1 ? '1 line' : "{$lines} lines")." · {$how}{$when}",
                    url: route('orders.queue', absolute: false),
                    tag: "order-{$order->id}",
                ),
            );
        });
    }

    /**
     * A load marked Picked or Loaded that has to be signed off before it
     * moves on.
     */
    public function checkNeeded(Shipment $shipment, User $by): void
    {
        $this->afterCommit(function () use ($shipment, $by) {
            $shipment->refresh()->loadMissing(['order.organization:id,name', 'order.customer:id,first_name,last_name', 'lines']);

            if (! $shipment->awaitingCheck()) {
                return;
            }

            $flow = app(PickingFlow::class);
            $stage = $shipment->check_stage === 'loaded' ? 'Loaded' : 'Picked';
            $lines = $shipment->lines->count();

            $this->send(
                $this->staff(fn (User $user) => $user->id !== $by->id && $flow->cantCheckReason($shipment, $user) === null),
                'check_needed',
                new WebPushMessage(
                    title: "Check needed at {$stage}",
                    body: "{$shipment->order->number} · {$this->who($shipment->order)} · ".($lines === 1 ? '1 line' : "{$lines} lines")." · marked by {$by->name}",
                    url: route('orders.queue', ['checks' => 1], absolute: false),
                    tag: "check-{$shipment->id}",
                ),
            );
        });
    }

    /**
     * A check was rejected: the person whose work it was sees why.
     */
    public function checkRejected(Shipment $shipment, ?int $pickerId, User $by, string $reason): void
    {
        if ($pickerId === null || $pickerId === $by->id) {
            return;
        }

        $this->afterCommit(function () use ($shipment, $pickerId, $by, $reason) {
            $shipment->loadMissing('order');

            $this->send(
                $this->staff(fn (User $user) => $user->id === $pickerId),
                'check_rejected',
                new WebPushMessage(
                    title: 'Check rejected',
                    body: "{$shipment->order->number}: {$reason} ({$by->name})",
                    url: route('shipments.pick', $shipment, absolute: false),
                    tag: "check-{$shipment->id}",
                    urgency: 'high',
                ),
            );
        });
    }

    /**
     * Dispatch put a driver on a truck for a day (today or later).
     */
    public function runAssigned(Truck $truck, string $day, User $driver): void
    {
        if ($day < $this->today()->toDateString()) {
            return;
        }

        $this->afterCommit(function () use ($truck, $day, $driver) {
            $stops = Stop::query()
                ->where('truck_id', $truck->id)
                ->whereDate('date', $day)
                ->whereNotIn('status', [StopStatus::Done->value, StopStatus::Cancelled->value])
                ->count();

            $this->send(
                $this->staff(fn (User $user) => $user->id === $driver->id),
                'run_assigned',
                new WebPushMessage(
                    title: "You’re on {$truck->name} ".$this->day($day),
                    body: match ($stops) {
                        0 => 'No stops on it yet.',
                        1 => '1 stop so far.',
                        default => "{$stops} stops so far.",
                    },
                    url: route('driver.today', absolute: false),
                    tag: "run-{$day}",
                ),
            );
        });
    }

    /**
     * A stop was put on a truck for today, after the day started: its
     * driver needs to know.
     */
    public function stopScheduled(Stop $stop, ?int $wasTruckId, ?string $wasDate): void
    {
        $day = $stop->date?->toDateString();

        if ($stop->truck_id === null || $day === null || $day !== $this->today()->toDateString()
            || ! in_array($stop->status, [StopStatus::Scheduled, StopStatus::EnRoute], true)
            || ($wasTruckId === $stop->truck_id && $wasDate === $day)) {
            return;
        }

        $this->afterCommit(function () use ($stop, $day) {
            $stop->loadMissing('truck');
            $driver = $stop->truck !== null ? app(DriverRun::class)->driverOn($stop->truck, $day) : null;

            if ($driver === null) {
                return;
            }

            $what = match ($stop->kind) {
                'rental_drop' => 'Rental drop-off',
                'rental_pickup' => 'Rental pickup',
                default => 'Delivery',
            };
            $time = $stop->starts_at !== null ? substr($stop->starts_at, 0, 5).' · ' : '';
            $where = implode(', ', array_filter([$stop->address['line1'] ?? null, $stop->address['city'] ?? null]));

            $this->send(
                $this->staff(fn (User $user) => $user->id === $driver->id),
                'stop_added',
                new WebPushMessage(
                    title: 'New stop on your run',
                    body: $time.$what.($where !== '' ? " · {$where}" : ''),
                    url: route('driver.stops.show', $stop, absolute: false),
                    tag: "stop-{$stop->id}",
                    urgency: 'high',
                ),
            );
        });
    }

    /**
     * A rental past its return time, for the rental desk at its yard. Once
     * a day per rental.
     *
     * @return bool whether anyone was alerted
     */
    public function rentalOverdue(Rental $rental): bool
    {
        $since = $this->today()->startOfDay()->utc();

        if ($rental->notificationsSent()->where('kind', 'staff_overdue')->where('sent_at', '>=', $since)->exists()) {
            return false;
        }

        $users = $this->staff(fn (User $user) => $user->hasRole(UserRole::RentalDesk) && $user->worksAt($rental->location_id));

        if ($users->isEmpty()) {
            return false;
        }

        $due = $rental->ends_at->setTimezone($this->timezone());

        $this->send($users, 'rental_overdue', new WebPushMessage(
            title: 'Rental overdue',
            body: "{$rental->number} · {$rental->customerName()} · due back ".$this->day($due->toDateString()).' at '.$due->format('g:i A'),
            url: route('rentals.show', $rental, absolute: false),
            tag: "rental-overdue-{$rental->id}",
        ));

        $rental->notificationsSent()->create(['kind' => 'staff_overdue', 'channel' => 'push', 'sent_at' => now()]);

        return true;
    }

    /**
     * People at this business with alerts on, narrowed by $who.
     *
     * @param  Closure(User): bool  $who
     * @return Collection<int, User>
     */
    private function staff(Closure $who): Collection
    {
        $tenantId = CurrentTenant::id();

        if ($tenantId === null) {
            return new Collection;
        }

        return User::query()
            ->where('tenant_id', $tenantId)
            ->whereHas('pushSubscriptions')
            ->get()
            ->filter($who)
            ->values();
    }

    /**
     * @param  Collection<int, User>  $users
     */
    private function send(Collection $users, string $kind, WebPushMessage $message): void
    {
        // The demo yard is shared by everyone trying it: no alerts there.
        if ($users->isEmpty() || Demo::isCurrentTenant()) {
            return;
        }

        Notification::send($users, new StaffAlert($kind, $message));
    }

    private function afterCommit(Closure $callback): void
    {
        DB::afterCommit($callback);
    }

    private function who(Order $order): string
    {
        return $order->organization->name ?? $order->customer->full_name ?? 'Walk-in';
    }

    /** "today", "tomorrow" or "Thu Oct 29". */
    private function day(string $date): string
    {
        $today = $this->today();

        return match ($date) {
            $today->toDateString() => 'today',
            $today->addDay()->toDateString() => 'tomorrow',
            default => CarbonImmutable::parse($date)->format('D M j'),
        };
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::today($this->timezone());
    }

    private function timezone(): string
    {
        return CurrentTenant::get()->timezone ?? 'America/Toronto';
    }
}
