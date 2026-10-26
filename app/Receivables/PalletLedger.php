<?php

namespace App\Receivables;

use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Location;
use App\Models\OrderLine;
use App\Models\Organization;
use App\Models\PalletMovement;
use App\Models\Sequence;
use App\Models\Shipment;
use App\Models\User;
use App\Orders\OrderRules;
use Illuminate\Support\Facades\DB;

/**
 * Pallets out on deposit. They're counted out when a load leaves and
 * credited back, at the deposit rate, when the customer returns them.
 */
class PalletLedger
{
    public function shipped(Shipment $shipment, ?User $user): void
    {
        $order = $shipment->order;
        $pallets = 0;
        $deposit = 0;

        foreach ($shipment->lines as $line) {
            $orderLine = $line->orderLine;

            if ($orderLine->pallets <= 0 || (float) $orderLine->quantity <= 0) {
                continue;
            }

            // Pallets for what was loaded, at what was charged per pallet.
            $share = min(1.0, $line->loadedQuantity() / (float) $orderLine->quantity);
            $count = (int) ceil($orderLine->pallets * $share - 1e-9);
            $perPallet = intdiv($this->palletPart($orderLine), max(1, $orderLine->pallets));

            $pallets += $count;
            $deposit += $count * $perPallet;
        }

        if ($pallets > 0) {
            PalletMovement::query()->create([
                'organization_id' => $order->organization_id,
                'customer_id' => $order->customer_id,
                'order_id' => $order->id,
                'location_id' => $order->location_id,
                'quantity' => $pallets,
                'deposit_cents' => $deposit,
                'note' => "Shipped on {$order->number}",
                'user_id' => $user?->id,
            ]);
        }
    }

    public function outstanding(?Organization $organization = null, ?Customer $customer = null): int
    {
        return (int) PalletMovement::query()
            ->when($organization, fn ($q) => $q->where('organization_id', $organization?->id))
            ->when($customer && ! $organization, fn ($q) => $q->where('customer_id', $customer?->id))
            ->sum('quantity');
    }

    /**
     * Pallets brought back: a credit note for their deposit.
     */
    public function returned(?Organization $organization, ?Customer $customer, int $count, Location $location, User $user, ?int $depositCents = null): CreditNote
    {
        if ($count <= 0) {
            OrderRules::fail('Count at least one pallet.', 'count');
        }

        if ($organization === null && $customer === null) {
            OrderRules::fail('Pick who brought the pallets back.', 'organization_id');
        }

        $out = $this->outstanding($organization, $customer);

        if ($count > $out) {
            OrderRules::fail("Only {$out} ".($out === 1 ? 'pallet is' : 'pallets are').' out on deposit.', 'count');
        }

        $each = $depositCents ?? ReturnsSettings::current()->palletDepositCents;

        return DB::transaction(function () use ($organization, $customer, $count, $location, $user, $each) {
            $credit = CreditNote::query()->create([
                'number' => 'CN-'.Sequence::next('credit_notes'),
                'kind' => 'pallets',
                'organization_id' => $organization?->id,
                'customer_id' => $customer?->id,
                'location_id' => $location->id,
                'reason' => "{$count} ".($count === 1 ? 'pallet' : 'pallets').' returned',
                'deposit_cents' => $count * $each,
                'total_cents' => $count * $each,
                'issued_on' => now()->toDateString(),
                'created_by' => $user->id,
            ]);

            PalletMovement::query()->create([
                'organization_id' => $organization?->id,
                'customer_id' => $customer?->id,
                'credit_note_id' => $credit->id,
                'location_id' => $location->id,
                'quantity' => -$count,
                'deposit_cents' => -$count * $each,
                'note' => "Returned, {$credit->number}",
                'user_id' => $user->id,
            ]);

            return $credit;
        });
    }

    private function palletPart(OrderLine $line): int
    {
        $container = $line->productUnit !== null ? (int) round((float) $line->quantity * $line->productUnit->deposit_cents) : 0;

        return max(0, $line->deposit_cents - $container);
    }
}
