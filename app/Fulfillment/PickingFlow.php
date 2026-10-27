<?php

namespace App\Fulfillment;

use App\Audit\Audit;
use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use App\Models\ShipmentCheck;
use App\Models\ShipmentLine;
use App\Models\User;
use App\Orders\OrderRules;
use App\Support\Files;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Moves a pick list through the yard's stages (Picking → Picked →
 * Loaded) and handles the quality checks a business can require at
 * Picked and Loaded.
 *
 * A stage that's switched off is skipped. A load waiting for a check
 * can't move on until someone allowed to check it signs off; a
 * rejection sends it back a stage with the reason.
 */
class PickingFlow
{
    public function __construct(private readonly LoadShipment $load) {}

    /**
     * Every line is pulled and staged.
     */
    public function markPicked(Shipment $shipment, User $user): Shipment
    {
        $settings = PickingSettings::current();

        if ($shipment->status !== ShipmentStatus::Picking) {
            OrderRules::fail('This pick list isn’t being picked.');
        }

        if (! $settings->stageOn('picked')) {
            OrderRules::fail('This yard skips the Picked stage. Mark it loaded instead.');
        }

        $left = $shipment->lines()->whereNull('picked_at')->count();

        if ($left > 0) {
            OrderRules::fail($left === 1 ? 'Confirm the last line first.' : "Confirm every line first ({$left} left).");
        }

        return DB::transaction(function () use ($shipment, $user, $settings) {
            $check = $settings->requiresCheck('picked');

            $shipment->forceFill([
                'status' => ShipmentStatus::Picked,
                'check_stage' => $check ? 'picked' : null,
                'picked_at' => now(),
                'picked_by' => $user->id,
                'picker_id' => $shipment->picker_id ?? $user->id,
            ])->save();

            Audit::record($shipment, 'picked', note: $check ? 'Waiting for a quality check.' : null);

            // With the Loaded stage off, a picked load goes straight out.
            if (! $check && ! $settings->stageOn('loaded')) {
                return $this->load->release($shipment, $user);
            }

            return $shipment;
        });
    }

    /**
     * Someone allowed to check this stage confirms the load matches the
     * pick list. The load then moves on to its next stage.
     */
    public function signOff(Shipment $shipment, User $user, ?string $notes = null, ?UploadedFile $photo = null): Shipment
    {
        $stage = $this->ensureCanCheck($shipment, $user);

        return DB::transaction(function () use ($shipment, $user, $stage, $notes, $photo) {
            $shipment->checks()->create([
                'stage' => $stage,
                'outcome' => ShipmentCheck::SIGNED_OFF,
                'user_id' => $user->id,
                'completed_by' => $this->completedBy($shipment, $stage),
                'quantities' => $shipment->lines()->get()
                    ->mapWithKeys(fn (ShipmentLine $line) => [$line->id => $line->picked_quantity ?? $line->quantity])
                    ->all(),
                'notes' => filled($notes) ? $notes : null,
                'photo_path' => $photo !== null ? Files::storeUpload($photo, 'checks') : null,
            ]);

            $shipment->forceFill(['check_stage' => null])->save();
            Audit::record($shipment, 'check signed off', note: ucfirst($stage).($notes ? ": {$notes}" : ''));

            // Checked at Loaded, or at Picked with nothing after it: it goes out.
            if ($stage === 'loaded' || ! PickingSettings::current()->stageOn('loaded')) {
                return $this->load->release($shipment, $user);
            }

            return $shipment;
        });
    }

    /**
     * The load doesn't match. It goes back a stage, and the picker sees why.
     */
    public function reject(Shipment $shipment, User $user, string $reason): Shipment
    {
        $stage = $this->ensureCanCheck($shipment, $user);

        return DB::transaction(function () use ($shipment, $user, $stage, $reason) {
            $shipment->checks()->create([
                'stage' => $stage,
                'outcome' => ShipmentCheck::REJECTED,
                'user_id' => $user->id,
                'completed_by' => $this->completedBy($shipment, $stage),
                'reason' => $reason,
            ]);

            $back = $stage === 'loaded' && PickingSettings::current()->stageOn('picked')
                ? ShipmentStatus::Picked
                : ShipmentStatus::Picking;

            $shipment->forceFill([
                'status' => $back,
                'check_stage' => null,
                'loaded_at' => null,
                'loaded_by' => null,
                ...($back === ShipmentStatus::Picking ? ['picked_at' => null, 'picked_by' => null] : []),
            ])->save();

            Audit::record($shipment, 'check rejected', note: ucfirst($stage).": {$reason}");

            return $shipment;
        });
    }

    /**
     * Why this user can't check this load, or null if they can.
     */
    public function cantCheckReason(Shipment $shipment, User $user): ?string
    {
        $stage = $shipment->check_stage;

        if ($stage === null) {
            return 'This load isn’t waiting for a check.';
        }

        $settings = PickingSettings::current();

        if (! in_array($user->role, $settings->checkRoles($stage), true)) {
            return 'Your role can’t sign off this check.';
        }

        if (! $user->canPickAt($shipment->order->location_id)) {
            return 'This load is at a yard you don’t work.';
        }

        if ($settings->checkByDifferentUser($stage) && $this->completedBy($shipment, $stage) === $user->id) {
            return 'Someone else has to check your own work.';
        }

        return null;
    }

    /**
     * What happens next to a load still at the yard, for the picker's
     * button: mark it picked, mark it loaded, or wait for a check.
     *
     * @return array{action: string, label: string}|null
     */
    public function nextStep(Shipment $shipment): ?array
    {
        if ($shipment->isReleased() || $shipment->status->isDone()) {
            return null;
        }

        if ($shipment->awaitingCheck()) {
            return ['action' => 'check', 'label' => 'Waiting for a check at '.($shipment->check_stage === 'loaded' ? 'Loaded' : 'Picked')];
        }

        $settings = PickingSettings::current();

        if ($shipment->status === ShipmentStatus::Picking && $settings->stageOn('picked')) {
            return ['action' => 'picked', 'label' => 'Mark picked'];
        }

        $label = match (true) {
            ! $shipment->isDelivery() => 'Ready for pickup',
            $settings->stageOn('loaded') => 'Mark loaded',
            default => 'Send to dispatch',
        };

        return ['action' => 'load', 'label' => $label];
    }

    private function ensureCanCheck(Shipment $shipment, User $user): string
    {
        $reason = $this->cantCheckReason($shipment, $user);

        if ($reason !== null) {
            OrderRules::fail($reason);
        }

        return (string) $shipment->check_stage;
    }

    private function completedBy(Shipment $shipment, string $stage): ?int
    {
        return $stage === 'loaded' ? $shipment->loaded_by : $shipment->picked_by;
    }
}
