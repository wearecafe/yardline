<?php

namespace App\Purchasing;

use App\Inventory\InventoryLedger;
use App\Models\CycleCount;
use App\Models\Location;
use App\Models\StockLevel;
use App\Models\User;
use App\Orders\OrderRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cycle counts: count a section of a yard, then post the differences.
 */
class CycleCounting
{
    public function __construct(private readonly InventoryLedger $ledger) {}

    public function start(Location $location, User $user, ?string $bin = null, ?string $category = null): CycleCount
    {
        $levels = StockLevel::query()
            ->with('product:id,name,category,track_inventory')
            ->where('location_id', $location->id)
            ->when($bin, fn ($q) => $q->where('bin', 'like', $bin.'%'))
            ->get()
            ->filter(fn (StockLevel $l) => $l->product->track_inventory && ($category === null || $l->product->category === $category))
            ->sortBy(fn (StockLevel $l) => ($l->bin ?? 'zzz').$l->product->name)
            ->values();

        if ($levels->isEmpty()) {
            OrderRules::fail('Nothing to count there.', 'bin');
        }

        return DB::transaction(function () use ($location, $user, $bin, $category, $levels) {
            $count = CycleCount::query()->create([
                'location_id' => $location->id,
                'name' => trim(implode(' · ', array_filter([$location->name, $bin ? "bins {$bin}" : null, $category]))),
                'started_by' => $user->id,
            ]);

            foreach ($levels as $level) {
                $count->lines()->create(['product_id' => $level->product_id, 'bin' => $level->bin, 'expected' => $level->on_hand]);
            }

            return $count;
        });
    }

    public function complete(CycleCount $count, User $user): CycleCount
    {
        if ($count->status !== 'open') {
            OrderRules::fail('This count is closed.');
        }

        return DB::transaction(function () use ($count, $user) {
            foreach ($count->lines()->with('product')->get() as $line) {
                if ($line->counted === null) {
                    continue;
                }

                $movement = $this->ledger->count($line->product, $count->location, $line->counted, $user, "Cycle count: {$count->name}");
                $movement?->forceFill(['reference_type' => $count->getMorphClass(), 'reference_id' => $count->id])->save();
            }

            $count->forceFill(['status' => 'completed', 'completed_by' => $user->id, 'completed_at' => now()])->save();

            return $count;
        });
    }

    /**
     * Net count difference as a share of what was expected.
     */
    /**
     * @param  list<int>|null  $yards  only counts at these yards; null for every yard
     */
    public static function varianceSince(CarbonImmutable $since, ?array $yards = null): ?float
    {
        $counts = CycleCount::query()->with('lines')->where('status', 'completed')->where('completed_at', '>=', $since)
            ->when($yards !== null, fn ($q) => $q->whereIn('location_id', $yards))
            ->get();
        $lines = $counts->flatMap->lines->whereNotNull('counted');
        $expected = (float) $lines->sum(fn ($l) => (float) $l->expected);

        if ($expected <= 0) {
            return null;
        }

        return round(((float) $lines->sum(fn ($l) => (float) $l->counted) - $expected) / $expected * 100, 1);
    }
}
